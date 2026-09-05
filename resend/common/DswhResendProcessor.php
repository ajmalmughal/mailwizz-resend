<?php declare(strict_types=1);
if (!defined('MW_PATH')) {
    exit('No direct script access allowed');
}

/**
 * DswhResendProcessor
 *
 * Handles incoming Resend event webhooks and maps them onto MailWizz bounce
 * and complaint records.
 *
 * Payload shape:
 *
 *   {
 *     "type": "email.bounced",
 *     "created_at": "2026-11-22T23:41:12.126Z",
 *     "data": {
 *       "email_id": "56761188-7520-42d8-8898-ff6fc54ce618",
 *       "message_id": "<111-222-333@email.example.com>",
 *       "from": "Acme <onboarding@resend.dev>",
 *       "to": ["user@example.com"],
 *       "subject": "Sending this example",
 *       "bounce": {
 *         "message": "The recipient's email address is on the suppression list ...",
 *         "subType": "Suppressed",
 *         "type": "Permanent"
 *       },
 *       "tags": { "campaign_uid": "...", "subscriber_uid": "..." }
 *     }
 *   }
 *
 * "email_id" is the id returned by the send endpoint, stored by MailWizz as
 * campaign_delivery_log.email_message_id. Note that "message_id" is the RFC
 * 5322 Message-ID and is NOT what we match on.
 *
 * Since January 2026 Resend emits one event per recipient rather than one per
 * message. "to" is still an array for backwards compatibility but carries a
 * single address.
 *
 * SIGNATURE VERIFICATION
 *
 * Resend signs webhooks using the Svix scheme. Three headers arrive:
 * svix-id, svix-timestamp and svix-signature. The signed content is the
 * concatenation "{id}.{timestamp}.{raw body}". The key is the signing secret
 * with its "whsec_" prefix removed and the remainder base64 decoded, NOT the
 * literal string. svix-signature can carry several space separated
 * signatures, each prefixed with a version tag such as "v1,", so a match
 * against any one of them is accepted.
 *
 * Requests that cannot be verified are dropped. An unverified endpoint would
 * let anyone who guessed the url unsubscribe and blacklist subscribers.
 *
 * @copyright 2026 M. Ajmal Mughal
 * @license   MIT
 * @link      https://github.com/ajmalmughal/mailwizz-resend
 */
class DswhResendProcessor
{
    /**
     * Events we act on. Everything else is ignored, and the webhook in the
     * Resend dashboard should not be subscribed to anything else either.
     *
     * email.delivered and email.sent are informational, MailWizz has no state
     * for them. email.opened and email.clicked are deliberately excluded
     * because MailWizz does its own tracking. email.delivery_delayed is a
     * transient condition that usually resolves, and recording it as a bounce
     * would inflate the count for mail that arrived fine.
     */
    public const EVENT_BOUNCED    = 'email.bounced';
    public const EVENT_COMPLAINED = 'email.complained';
    public const EVENT_FAILED     = 'email.failed';
    public const EVENT_SUPPRESSED = 'email.suppressed';

    /**
     * How far out of date a signed timestamp may be, in seconds. Guards
     * against a captured request being replayed later.
     */
    public const TIMESTAMP_TOLERANCE = 300;

    /**
     * Entry point, registered through the dswh_process_map filter.
     *
     * @param DeliveryServer $server
     * @param Controller $controller
     *
     * @return void
     * @throws CException
     */
    public function process($server, $controller = null): void
    {
        $rawBody = (string)file_get_contents('php://input');

        if ($rawBody === '') {
            app()->end();
            return;
        }

        // The signing secret lives in the username column, see
        // DeliveryServerResendWebApi::attributeLabels().
        $secret = isset($server->username) ? (string)$server->username : '';

        if (!$this->verifySignature($rawBody, $secret, (int)$server->server_id)) {
            app()->end();
            return;
        }

        $payload = json_decode($rawBody, true);

        if (!is_array($payload)) {
            app()->end();
            return;
        }

        // Resend posts a single event, but accept a list defensively.
        $events = isset($payload['type']) || isset($payload['data']) ? [$payload] : $payload;

        foreach ($events as $event) {
            if (is_array($event)) {
                $this->processEvent($event);
            }
        }

        app()->end();
    }

    /**
     * Verify the Svix signature on the request.
     *
     * @param string $rawBody
     * @param string $secret
     * @param int $serverId
     *
     * @return bool
     */
    protected function verifySignature(string $rawBody, string $secret, int $serverId): bool
    {
        if ($secret === '') {
            Yii::log(sprintf(
                'Resend delivery server #%d received a webhook but has no signing secret configured, event dropped.',
                $serverId
            ), CLogger::LEVEL_WARNING);

            return false;
        }

        $id        = $this->header('SVIX_ID', 'WEBHOOK_ID');
        $timestamp = $this->header('SVIX_TIMESTAMP', 'WEBHOOK_TIMESTAMP');
        $signature = $this->header('SVIX_SIGNATURE', 'WEBHOOK_SIGNATURE');

        if ($id === '' || $timestamp === '' || $signature === '') {
            return false;
        }

        if (!ctype_digit($timestamp)) {
            return false;
        }

        if (abs(time() - (int)$timestamp) > self::TIMESTAMP_TOLERANCE) {
            Yii::log(sprintf(
                'Resend delivery server #%d rejected a webhook with a stale timestamp.',
                $serverId
            ), CLogger::LEVEL_WARNING);

            return false;
        }

        // whsec_ prefix stripped, remainder base64 decoded. Using the literal
        // string as the key never matches.
        if (strpos($secret, 'whsec_') === 0) {
            $secret = substr($secret, 6);
        }

        $key = base64_decode($secret, true);

        if ($key === false || $key === '') {
            return false;
        }

        $signedContent = $id . '.' . $timestamp . '.' . $rawBody;
        $expected      = base64_encode(hash_hmac('sha256', $signedContent, $key, true));

        // "v1,<sig> v1,<othersig>"
        foreach (explode(' ', $signature) as $candidate) {
            $candidate = trim($candidate);

            if ($candidate === '') {
                continue;
            }

            $parts = explode(',', $candidate, 2);
            $value = count($parts) === 2 ? $parts[1] : $parts[0];

            if (hash_equals($expected, $value)) {
                return true;
            }
        }

        Yii::log(sprintf(
            'Resend delivery server #%d rejected a webhook with an invalid signature.',
            $serverId
        ), CLogger::LEVEL_WARNING);

        return false;
    }

    /**
     * Read a request header from $_SERVER, trying each given name in turn.
     *
     * @param string ...$names
     *
     * @return string
     */
    protected function header(string ...$names): string
    {
        foreach ($names as $name) {
            $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));

            if (!empty($_SERVER[$key])) {
                return trim((string)$_SERVER[$key]);
            }
        }

        return '';
    }

    /**
     * @param array $event
     *
     * @return void
     * @throws CException
     */
    protected function processEvent(array $event): void
    {
        $type = strtolower((string)($event['type'] ?? ''));
        $data = isset($event['data']) && is_array($event['data']) ? $event['data'] : [];

        $emailId = (string)($data['email_id'] ?? '');

        if ($type === '' || $emailId === '') {
            return;
        }

        if (!in_array($type, [
            self::EVENT_BOUNCED,
            self::EVENT_COMPLAINED,
            self::EVENT_FAILED,
            self::EVENT_SUPPRESSED,
        ], true)) {
            return;
        }

        $criteria = new CDbCriteria();
        $criteria->addCondition('`email_message_id` = :email_message_id AND `status` = :status');
        $criteria->params = [
            'email_message_id' => $emailId,
            'status'           => CampaignDeliveryLog::STATUS_SUCCESS,
        ];

        $deliveryLog = CampaignDeliveryLogHelper::findByCriteria($criteria);
        if (empty($deliveryLog)) {
            return;
        }

        /** @var Campaign|null $campaign */
        $campaign = Campaign::model()->findByPk((int)$deliveryLog->campaign_id);
        if (empty($campaign)) {
            return;
        }

        /** @var ListSubscriber|null $subscriber */
        $subscriber = ListSubscriber::model()->findByAttributes([
            'list_id'       => (int)$campaign->list_id,
            'subscriber_id' => (int)$deliveryLog->subscriber_id,
            'status'        => ListSubscriber::STATUS_CONFIRMED,
        ]);

        if (empty($subscriber)) {
            return;
        }

        // The recipient pressed the spam button at their mailbox provider, so
        // this is a genuine feedback loop complaint rather than a filtering
        // decision made by a mail server.
        if ($type === self::EVENT_COMPLAINED) {
            /** @var OptionCronProcessFeedbackLoopServers $fbl */
            $fbl = container()->get(OptionCronProcessFeedbackLoopServers::class);
            $fbl->takeActionAgainstSubscriberWithCampaign($subscriber, $campaign);
            return;
        }

        // Do not record the same subscriber twice for the same campaign.
        $count = CampaignBounceLog::model()->countByAttributes([
            'campaign_id'   => (int)$campaign->campaign_id,
            'subscriber_id' => (int)$subscriber->subscriber_id,
        ]);

        if (!empty($count)) {
            return;
        }

        $message    = $this->extractMessage($type, $data);
        $bounceType = $this->classifyBounce($type, $data);

        $bounceLog = new CampaignBounceLog();
        $bounceLog->campaign_id   = (int)$campaign->campaign_id;
        $bounceLog->subscriber_id = (int)$subscriber->subscriber_id;
        $bounceLog->message       = $message;
        $bounceLog->bounce_type   = (string)$bounceType;
        $bounceLog->save();

        if ($bounceLog->bounce_type === CampaignBounceLog::BOUNCE_HARD) {
            $subscriber->addToBlacklist((string)$bounceLog->message);
        }
    }

    /**
     * Decide how hard a failure this was.
     *
     * Unlike providers that only supply an SMTP transcript, Resend states the
     * bounce class outright, so there is no reply code parsing to do.
     *
     *  - email.bounced with bounce.type Permanent is a hard bounce.
     *  - email.bounced with bounce.type Temporary is a soft bounce.
     *  - email.suppressed means the address was already on the account
     *    suppression list and nothing was ever sent. That list is built from
     *    prior hard bounces and complaints, so the address is dead.
     *  - email.failed means Resend accepted the message then could not send
     *    it. That is about the message or the account, not the recipient, so
     *    it is recorded as internal and never blacklists anyone.
     *
     * Anything unrecognised falls through to soft, which is the safe
     * direction since a soft bounce takes no action against the subscriber.
     *
     * @param string $type
     * @param array $data
     *
     * @return string
     */
    protected function classifyBounce(string $type, array $data): string
    {
        if ($type === self::EVENT_SUPPRESSED) {
            return CampaignBounceLog::BOUNCE_HARD;
        }

        if ($type === self::EVENT_FAILED) {
            return CampaignBounceLog::BOUNCE_INTERNAL;
        }

        $bounce      = isset($data['bounce']) && is_array($data['bounce']) ? $data['bounce'] : [];
        $bounceClass = strtolower((string)($bounce['type'] ?? ''));

        if ($bounceClass === 'permanent') {
            return CampaignBounceLog::BOUNCE_HARD;
        }

        return CampaignBounceLog::BOUNCE_SOFT;
    }

    /**
     * Pull a readable reason out of the event.
     *
     * The detail object is named after the event, NOT always "bounce":
     *
     *   email.bounced    -> data.bounce    {message, subType, type, diagnosticCode}
     *   email.suppressed -> data.suppressed {message, reason, type, diagnosticCode}
     *   email.failed     -> data.failed
     *
     * Confirmed against live payloads for bounced and suppressed. The failed
     * shape has not been seen yet, so it is read defensively.
     *
     * "message" is the human readable explanation and is preferred.
     * "diagnosticCode" carries the raw SMTP transcript and is used only when
     * there is no message, and it can arrive as [null], so entries are
     * filtered before use. Multi line transcripts are flattened and capped to
     * fit the column.
     *
     * @param string $type
     * @param array $data
     *
     * @return string
     */
    protected function extractMessage(string $type, array $data): string
    {
        $detail = $this->extractDetail($data);

        $reason = trim((string)($detail['message'] ?? ''));

        if ($reason === '' && !empty($detail['diagnosticCode'])) {
            $diagnostic = $detail['diagnosticCode'];

            if (!is_array($diagnostic)) {
                $diagnostic = [$diagnostic];
            }

            // Can arrive as [null], which is non empty but carries nothing.
            $diagnostic = array_filter(array_map(static function ($line) {
                return trim((string)$line);
            }, $diagnostic), static function ($line) {
                return $line !== '';
            });

            $reason = implode(' ', $diagnostic);
        }

        if ($reason === '') {
            $reason = trim((string)($detail['reason'] ?? ''));
        }

        // subType on a bounce, reason on a suppression. Either makes a useful
        // prefix, so whichever is present is used.
        $label = trim((string)($detail['subType'] ?? ''));

        if ($label === '') {
            $label = trim((string)($detail['reason'] ?? ''));
        }

        if ($reason !== '' && $label !== '' && stripos($reason, $label) !== 0) {
            $reason = $label . ': ' . $reason;
        }

        $message = trim((string)preg_replace('/\s+/', ' ', $reason));

        if ($message === '') {
            $message = strtoupper(str_replace('email.', '', $type));
        }

        if (strlen($message) > 250) {
            $message = substr($message, 0, 250);
        }

        return $message;
    }

    /**
     * Find the event specific detail object, whatever it happens to be named.
     *
     * @param array $data
     *
     * @return array
     */
    protected function extractDetail(array $data): array
    {
        foreach (['bounce', 'suppressed', 'failed'] as $key) {
            if (!empty($data[$key]) && is_array($data[$key])) {
                return $data[$key];
            }
        }

        return [];
    }
}
