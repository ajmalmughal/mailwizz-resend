<?php declare(strict_types=1);
if (!defined('MW_PATH')) {
    exit('No direct script access allowed');
}

/**
 * DeliveryServerResendWebApi
 *
 * Delivery server implementation for the Resend API.
 *
 * Notes on the provider:
 *  - Single base url, https://api.resend.com, no regional variants.
 *  - Auth is a single bearer token, "Authorization: Bearer re_...". There is
 *    no key/secret pair.
 *  - A User-Agent header is MANDATORY. Requests without one are rejected with
 *    403 regardless of how valid the api key is.
 *  - "headers" is an unrestricted string map, so the whole MailWizz header set
 *    passes through.
 *  - "reply_to" is a first class field, unlike some providers where Reply-To
 *    has to be smuggled through custom headers.
 *  - "text" and "html" are separate, so a proper multipart message is sent.
 *    Omitting "text" makes Resend generate one from the html, which we avoid
 *    by always supplying MailWizz's own plain text part.
 *  - "tags" are name/value pairs restricted to ASCII letters, digits,
 *    underscore and dash, 256 chars each. They are echoed back in webhook
 *    events, giving a second attribution path alongside the message id.
 *  - The send response is {"id": "<uuid>"}, a string, which is what webhook
 *    events refer to as data.email_id.
 *  - Total message size, after base64 encoding of attachments, must stay
 *    under 40MB.
 *  - The "from" domain must be verified in Resend or every send fails 403.
 *  - Open and click tracking must be left OFF in the Resend domain settings,
 *    since MailWizz does its own and Resend's click tracking rewrites links
 *    MailWizz has already rewritten.
 *
 * @copyright 2026 M. Ajmal Mughal
 * @license   MIT
 * @link      https://github.com/ajmalmughal/mailwizz-resend
 */
class DeliveryServerResendWebApi extends DeliveryServer
{
    /**
     * The one and only api host.
     */
    public const API_HOST = 'api.resend.com';

    /**
     * Total message size cap enforced by the provider, in bytes, measured
     * after base64 encoding.
     */
    public const MAX_MESSAGE_SIZE = 41943040;

    /**
     * Sent on every request. Resend rejects requests without a User-Agent
     * with a 403, which looks exactly like an authentication failure and is
     * therefore worth never getting wrong.
     */
    public const USER_AGENT = 'mailwizz-resend-ext/1.0.0';

    /**
     * Error names that mean the account or key cannot send at all. These
     * deactivate the server rather than burn through the rest of the list.
     */
    public const FATAL_ERROR_NAMES = [
        'missing_api_key',
        'restricted_api_key',
        'suspended_api_key',
        'invalid_permission',
        'daily_quota_exceeded',
        'monthly_quota_exceeded',
    ];

    /**
     * Substrings in a 403 validation_error that mean the sending domain is
     * misconfigured. Every message from this server will fail the same way,
     * so there is no point continuing.
     */
    public const FATAL_MESSAGE_MARKERS = [
        'is not verified',
        'you can only send testing emails',
    ];

    /**
     * Error names that mean this one recipient or message is bad but the
     * server itself is fine.
     */
    public const RECIPIENT_ERROR_NAMES = [
        'validation_error',
        'missing_required_field',
        'missing_required_parameter',
        'invalid_parameter',
        'invalid_attachment',
    ];

    /**
     * @var string
     */
    protected $serverType = 'resend-web-api';

    /**
     * @var string
     */
    protected $_providerUrl = 'https://resend.com/';

    /**
     * Seconds Resend asked us to wait, taken from the retry-after header on a
     * 429 so the message can report something useful.
     *
     * @var int
     */
    protected $_retryAfter = 0;

    /**
     * @return array
     */
    public function rules()
    {
        $rules = [
            ['password', 'required'],
            ['password, username', 'length', 'max' => 255],
            ['username', 'match', 'pattern' => '/^whsec_[A-Za-z0-9+\/=]+$/', 'allowEmpty' => true,
                'message' => 'The webhook signing secret should start with whsec_ followed by the value Resend generated.', ],
        ];

        return CMap::mergeArray($rules, parent::rules());
    }

    /**
     * @return array
     */
    public function attributeLabels()
    {
        $labels = [
            'password' => t('servers', 'API key'),
            'username' => t('servers', 'Webhook signing secret'),
        ];

        return CMap::mergeArray(parent::attributeLabels(), $labels);
    }

    /**
     * @return array
     */
    public function attributeHelpTexts()
    {
        $texts = [
            'password' => t('servers', 'Your Resend API key, created at resend.com/api-keys. Sending access is enough, the extension never calls anything except the send endpoint. The key is shown only once, at creation time.'),
            'username' => t('servers', 'The signing secret of the Resend webhook that points at this server. Shown on the webhook page in the Resend dashboard. Leave empty until you have created the webhook, then come back and fill it in, otherwise bounces cannot be processed.'),
        ];

        return CMap::mergeArray(parent::attributeHelpTexts(), $texts);
    }

    /**
     * @return array
     */
    public function attributePlaceholders()
    {
        $placeholders = [
            'password' => 're_xxxxxxxxxxxxxxxxxxxxxxxx',
            // Underscores are deliberate. GitHub's secret scanner matches the
            // "whsec" prefix followed by a run of alphanumerics, because
            // Stripe uses the same prefix for its webhook signing secrets. A
            // placeholder of that shape raises a false positive alert, so the
            // underscores here exist purely to break the match.
            'username' => 'whsec_your_secret_here',
        ];

        return CMap::mergeArray(parent::attributePlaceholders(), $placeholders);
    }

    /**
     * @param string $className
     * @return DeliveryServer
     */
    public static function model($className = self::class)
    {
        /** @var DeliveryServer $model */
        $model = parent::model($className);

        return $model;
    }

    /**
     * @param array $params
     * @return array
     * @throws CException
     */
    public function send(array $params = []): array
    {
        /** @var array $params */
        $params = (array)hooks()->applyFilters('delivery_server_before_send_email', $this->getParamsArray($params), $this);

        if (!ArrayHelper::hasKeys($params, ['from', 'to', 'subject', 'body'])) {
            return [];
        }

        [$toEmail]              = $this->getMailer()->findEmailAndName($params['to']);
        [$fromEmail, $fromName] = $this->getMailer()->findEmailAndName($params['from']);

        if (!empty($params['fromName'])) {
            $fromName = $params['fromName'];
        }

        $replyToEmail = null;
        if (!empty($params['replyTo'])) {
            [$replyToEmail] = $this->getMailer()->findEmailAndName($params['replyTo']);
        }

        $sent = [];

        try {
            $onlyPlainText = !empty($params['onlyPlainText']) && $params['onlyPlainText'] === true;

            $plainText = !empty($params['plainText'])
                ? (string)$params['plainText']
                : (string)CampaignHelper::htmlToText((string)$params['body']);

            $sendParams = [
                // Resend accepts "Name <email>" as well as a bare address.
                'from'    => !empty($fromName)
                    ? sprintf('%s <%s>', $fromName, $fromEmail)
                    : (string)$fromEmail,
                'to'      => [$toEmail],
                'subject' => (string)$params['subject'],
                'text'    => $plainText,
            ];

            if (!$onlyPlainText) {
                $sendParams['html'] = (string)$params['body'];
            }

            if (!empty($replyToEmail)) {
                $sendParams['reply_to'] = $replyToEmail;
            }

            // headers is an unrestricted string map, so the full MailWizz set
            // goes through: List-Unsubscribe, List-Unsubscribe-Post, List-Id,
            // Feedback-ID, X-Report-Abuse and any user configured ones.
            if (!empty($params['headers'])) {
                $customHeaders = $this->sanitiseHeaders($this->parseHeadersIntoKeyValue($params['headers']));

                if (!empty($customHeaders)) {
                    $sendParams['headers'] = $customHeaders;
                }
            }

            $tags = $this->buildTags($params);
            if (!empty($tags)) {
                $sendParams['tags'] = $tags;
            }

            if (!$onlyPlainText && !empty($params['attachments']) && is_array($params['attachments'])) {
                $attachments  = [];
                $_attachments = array_unique($params['attachments']);

                foreach ($_attachments as $attachment) {
                    if (!is_file($attachment)) {
                        continue;
                    }
                    $attachments[] = [
                        'filename'     => basename($attachment),
                        'content'      => base64_encode((string)file_get_contents($attachment)),
                        'content_type' => (string)(function_exists('mime_content_type') ? mime_content_type($attachment) : 'application/octet-stream'),
                    ];
                }

                if (!empty($attachments)) {
                    $sendParams['attachments'] = $attachments;
                }
            }

            $result = $this->apiRequest('POST', $this->getSendUrl(), $sendParams);
            $data   = (array)$result['data'];

            if ($result['success'] && !empty($data['id'])) {
                $this->getMailer()->addLog('OK');
                $sent = ['message_id' => (string)$data['id']];
            } else {
                $this->handleApiError($data, (int)$result['httpCode'], (string)$result['error']);
            }
        } catch (Exception $e) {
            $this->getMailer()->addLog($e->getMessage());
        }

        if ($sent) {
            $this->logUsage();
        }

        hooks()->doAction('delivery_server_after_send_email', $params, $this, $sent);

        return (array)$sent;
    }

    /**
     * Build the tags array.
     *
     * Resend echoes tags back in webhook events, which gives the processor a
     * fallback attribution path when the delivery log lookup by message id
     * comes up empty.
     *
     * Tag names and values accept only ASCII letters, digits, underscore and
     * dash, up to 256 characters. A value that breaks those rules fails the
     * whole send with a validation error, so anything unexpected is dropped
     * rather than risked.
     *
     * @param array $params
     * @return array
     */
    protected function buildTags(array $params): array
    {
        $tags = [];

        $candidates = [
            'campaign_uid'   => (string)($params['campaignUid'] ?? ''),
            'subscriber_uid' => (string)($params['subscriberUid'] ?? ''),
            'server_id'      => (string)(int)$this->server_id,
        ];

        foreach ($candidates as $name => $value) {
            if ($value === '' || $value === '0') {
                continue;
            }

            if (!preg_match('/^[A-Za-z0-9_\-]{1,256}$/', $value)) {
                continue;
            }

            $tags[] = [
                'name'  => $name,
                'value' => $value,
            ];
        }

        return $tags;
    }

    /**
     * Strip CR/LF from header values to prevent header injection.
     *
     * @param array $headers
     * @return array
     */
    protected function sanitiseHeaders(array $headers): array
    {
        $out = [];

        foreach ($headers as $name => $value) {
            $name = trim((string)$name);

            if ($name === '' || $value === null || $value === '') {
                continue;
            }

            $clean = trim((string)preg_replace('/[\r\n]+/', ' ', (string)$value));

            if ($clean === '') {
                continue;
            }

            $out[$name] = $clean;
        }

        return $out;
    }

    /**
     * @return string
     */
    public function getSendUrl(): string
    {
        return sprintf('https://%s/emails', self::API_HOST);
    }

    /**
     * Decide what to do about a failed send, then throw so send() logs it.
     *
     * Resend error bodies carry a "name" identifying the error type and a
     * "message" describing it, for example:
     *   {"statusCode": 403, "name": "validation_error", "message": "The ..."}
     *
     * @param array $data
     * @param int $httpCode
     * @param string $transportError
     * @return void
     * @throws Exception
     */
    protected function handleApiError(array $data, int $httpCode, string $transportError = ''): void
    {
        if (!empty($transportError)) {
            throw new Exception(sprintf('Resend connection error: %s', $transportError));
        }

        $name    = strtolower((string)($data['name'] ?? ''));
        $message = (string)($data['message'] ?? '');
        $lower   = strtolower($message);

        if ($message === '') {
            $message = 'unknown error';
        }

        // Bad key, suspended key or exhausted quota. Nothing this server sends
        // will succeed until a human intervenes.
        if (in_array($name, self::FATAL_ERROR_NAMES, true)) {
            $this->deactivate($name . ': ' . $message);
            throw new Exception(sprintf('Resend rejected the account, server has been deactivated [%s]: %s', $name, $message));
        }

        // Unverified sending domain, or a key still restricted to the
        // onboarding sandbox. Same reasoning, every message fails identically.
        foreach (self::FATAL_MESSAGE_MARKERS as $marker) {
            if (strpos($lower, $marker) !== false) {
                $this->deactivate($message);
                throw new Exception(sprintf('Resend sender configuration is invalid, server has been deactivated: %s', $message));
            }
        }

        // A missing User-Agent also lands here as a 403. We always send one,
        // so this should be unreachable, but it is worth naming if it happens.
        if ($httpCode === 403 && $name === '') {
            throw new Exception(sprintf('Resend refused the request (HTTP 403): %s', $message));
        }

        if ($httpCode === 429) {
            $wait = $this->_retryAfter > 0 ? sprintf(', retry-after %ds', $this->_retryAfter) : '';
            throw new Exception(sprintf('Resend rate limit hit (HTTP 429%s), this message will be retried: %s', $wait, $message));
        }

        if (in_array($name, self::RECIPIENT_ERROR_NAMES, true)) {
            throw new Exception(sprintf('Resend rejected the message [%s]: %s', $name, $message));
        }

        if ($httpCode >= 500) {
            throw new Exception(sprintf('Resend server error [%d]: %s', $httpCode, $message));
        }

        throw new Exception(sprintf('Resend error [%d]: %s', $httpCode, $message));
    }

    /**
     * Set the server inactive and record why.
     *
     * @param string $reason
     * @return void
     */
    protected function deactivate(string $reason): void
    {
        if ($this->status === self::STATUS_INACTIVE) {
            return;
        }

        $this->status = self::STATUS_INACTIVE;
        $this->save(false);

        Yii::log(sprintf('Resend delivery server #%d deactivated: %s', (int)$this->server_id, $reason), CLogger::LEVEL_WARNING);
    }

    /**
     * Perform a request against the Resend API.
     *
     * @param string $method
     * @param string $url
     * @param array|null $body
     * @return array
     */
    protected function apiRequest(string $method, string $url, ?array $body = null): array
    {
        $this->_retryAfter = 0;

        $payload = null;
        $headers = [
            'Authorization: Bearer ' . (string)$this->password,
            'Accept: application/json',
            // Not optional. Resend answers 403 without it.
            'User-Agent: ' . self::USER_AGENT,
        ];

        if ($body !== null) {
            $payload   = (string)json_encode($body);
            $headers[] = 'Content-Type: application/json';
            $headers[] = 'Content-Length: ' . strlen($payload);
        }

        if ($payload !== null && strlen($payload) > self::MAX_MESSAGE_SIZE) {
            return [
                'success'  => false,
                'httpCode' => 0,
                'data'     => [],
                'error'    => sprintf('message is %d bytes, over the 40MB Resend limit', strlen($payload)),
            ];
        }

        // Base timeout, extended for large payloads since base64 attachments
        // inflate the body by roughly a third.
        $timeout = 30;
        if ($payload !== null) {
            $timeout += (int)floor(strlen($payload) / 1048576) * 20;
        }

        $responseHeaders = [];

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST  => strtoupper($method),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HEADERFUNCTION => function ($ch, $header) use (&$responseHeaders) {
                $parts = explode(':', $header, 2);
                if (count($parts) === 2) {
                    $responseHeaders[strtolower(trim($parts[0]))] = trim($parts[1]);
                }

                return strlen($header);
            },
        ]);

        if ($payload !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        }

        $response = curl_exec($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error    = (string)curl_error($ch);
        curl_close($ch);

        if (isset($responseHeaders['retry-after'])) {
            $this->_retryAfter = (int)$responseHeaders['retry-after'];
        }

        $data = [];
        if (is_string($response) && $response !== '') {
            $decoded = json_decode($response, true);
            if (is_array($decoded)) {
                $data = $decoded;
            } else {
                $data = ['message' => substr($response, 0, 500)];
            }
        }

        return [
            'success'  => empty($error) && $httpCode >= 200 && $httpCode < 300,
            'httpCode' => $httpCode,
            'data'     => $data,
            'headers'  => $responseHeaders,
            'error'    => $error,
        ];
    }

    /**
     * @inheritDoc
     */
    public function getParamsArray(array $params = []): array
    {
        $params['transport'] = $this->serverType;

        return parent::getParamsArray($params);
    }

    /**
     * The hostname column is hidden on the form because Resend has a single
     * api host and nothing to choose, but the column still has to carry a
     * value or the parent validation rejects the record with an error the
     * form cannot display, since the field is not rendered.
     *
     * @return void
     */
    protected function afterConstruct()
    {
        parent::afterConstruct();

        if (empty($this->hostname)) {
            $this->hostname = self::API_HOST;
        }
    }

    /**
     * @inheritDoc
     */
    public function getFormFieldsDefinition(array $fields = []): array
    {
        return parent::getFormFieldsDefinition(CMap::mergeArray([
            'hostname'                => null,
            'port'                    => null,
            'protocol'                => null,
            'timeout'                 => null,
            'signing_enabled'         => null,
            'max_connection_messages' => null,
            'bounce_server_id'        => null,
            'force_sender'            => null,
        ], $fields));
    }
}
