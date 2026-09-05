<?php declare(strict_types=1);
if (!defined('MW_PATH')) {
    exit('No direct script access allowed');
}

/**
 * Resend API extension
 *
 * Adds Resend as a delivery server type, together with its webhook handler
 * for bounce and complaint processing.
 *
 * Registers four filters, no core files are modified:
 *   delivery_servers_get_types_mapping                     -> register the server type
 *   delivery_servers_form_view_file                        -> point at our form view
 *   dswh_process_map                                       -> register the webhook handler
 *   delivery_servers_get_bounce_server_not_supported_types -> hide the bounce server field
 *
 * Directory layout (install under apps/extensions/resend/):
 *
 *   resend/
 *   |-- ResendExt.php
 *   |-- common/
 *   |   |-- models/DeliveryServerResendWebApi.php
 *   |   `-- DswhResendProcessor.php
 *   |-- backend/views/delivery_servers/form-resend-web-api.php
 *   `-- customer/views/delivery_servers/form-resend-web-api.php
 *
 * The directory name determines the path alias: "resend" -> "resend-ext".
 *
 * @copyright 2026 M. Ajmal Mughal
 * @license   MIT
 * @link      https://github.com/ajmalmughal/mailwizz-resend
 */
class ResendExt extends ExtensionInit
{
    /**
     * The server type string, used everywhere.
     */
    public const SERVER_TYPE = 'resend-web-api';

    /**
     * @var string
     */
    public $name = 'Resend Email API';

    /**
     * @var string
     */
    public $description = 'Adds Resend as a delivery server type, with webhook based bounce and complaint handling.';

    /**
     * @var string
     */
    public $version = '1.0.0';

    /**
     * @var string
     */
    public $minAppVersion = '2.7.0';

    /**
     * @var string
     */
    public $author = 'M. Ajmal Mughal';

    /**
     * @var string
     */
    public $website = 'https://github.com/ajmalmughal/mailwizz-resend';

    /**
     * @var string
     */
    public $email = 'ajmal.uniqtech@gmail.com';

    /**
     * Must be an explicit list. An empty array does NOT mean "all apps".
     *
     * The delivery server type mapping has to be registered in every app,
     * console included, because campaigns are actually sent by the CLI cron
     * process. If the console app cannot resolve the type mapping it cannot
     * instantiate the server class and sending silently does nothing.
     *
     * @var array
     */
    public $allowedApps = ['backend', 'customer', 'frontend', 'console', 'api'];

    /**
     * Required. Campaign sending happens through the CLI.
     *
     * @var bool
     */
    public $cliEnabled = true;

    /**
     * @inheritDoc
     */
    public function run()
    {
        $this->importClasses('common.models.*');
        $this->importClasses('common.*');

        // Registered in ALL apps, console included, see $allowedApps above.
        hooks()->addFilter('delivery_servers_get_types_mapping', [$this, '_registerServerType']);

        // Bounces arrive by webhook, so no POP3/IMAP bounce server applies.
        hooks()->addFilter('delivery_servers_get_bounce_server_not_supported_types', [$this, '_registerBounceServerNotSupported']);

        // Form rendering only happens in the backend and customer apps.
        if ($this->isAppName('backend') || $this->isAppName('customer')) {
            hooks()->addFilter('delivery_servers_form_view_file', [$this, '_registerFormViewFile']);
        }

        // Registered unconditionally. The DSWH url has no app segment on some
        // installs, so gating this on isAppName('frontend') can silently
        // prevent the processor from ever registering.
        hooks()->addFilter('dswh_process_map', [$this, '_registerDswhProcessor']);
    }

    /**
     * @param array $mapping
     *
     * @return array
     */
    public function _registerServerType(array $mapping): array
    {
        $mapping[self::SERVER_TYPE] = 'DeliveryServerResendWebApi';

        return $mapping;
    }

    /**
     * @param array $types
     *
     * @return array
     */
    public function _registerBounceServerNotSupported(array $types): array
    {
        $types[] = self::SERVER_TYPE;

        return $types;
    }

    /**
     * Point MailWizz at the form view that lives inside this extension.
     *
     * Yii 1 treats a view name containing a dot as a path alias, so returning
     * "resend-ext.customer.views.delivery_servers.form-resend-web-api"
     * resolves without touching the core views directories.
     *
     * @param string $viewFile
     * @param DeliveryServer $server
     *
     * @return string
     */
    public function _registerFormViewFile($viewFile, $server)
    {
        if (empty($server) || $server->type !== self::SERVER_TYPE) {
            return $viewFile;
        }

        $appName = $this->isAppName('backend') ? 'backend' : 'customer';

        return $this->getPathAlias($appName . '.views.delivery_servers.form-resend-web-api');
    }

    /**
     * @param array $map
     *
     * @return array
     */
    public function _registerDswhProcessor(array $map): array
    {
        $this->importClasses('common.DswhResendProcessor', true);

        $map[self::SERVER_TYPE] = [new DswhResendProcessor(), 'process'];

        return $map;
    }
}
