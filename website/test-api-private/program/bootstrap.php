<?php

declare(strict_types=1);

// Load the admin exception before configuration errors are caught below.
require_once __DIR__ . '/shop-admin.php';

final class CarmajaBootstrapException extends RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        string $message
    ) {
        parent::__construct($message);
    }
}

function carmaja_bootstrap_is_absolute_path(string $path): bool
{
    $normalized = str_replace('\\', '/', $path);

    return !str_contains($path, "\0")
        && !in_array('..', explode('/', $normalized), true)
        && (str_starts_with($normalized, '/')
            || preg_match('/^[A-Za-z]:\//', $normalized) === 1);
}

function carmaja_bootstrap_normalize_path(string $path): string
{
    $normalized = str_replace('\\', '/', rtrim($path, "\\/"));

    return DIRECTORY_SEPARATOR === '\\' ? strtolower($normalized) : $normalized;
}

function carmaja_bootstrap_path_is_inside(string $path, string $directory): bool
{
    $normalizedPath = carmaja_bootstrap_normalize_path($path);
    $normalizedDirectory = carmaja_bootstrap_normalize_path($directory);

    return $normalizedPath === $normalizedDirectory
        || str_starts_with($normalizedPath, $normalizedDirectory . '/');
}

function carmaja_bootstrap_config_path(?string $explicitPath = null): string
{
    $configuredPath = $explicitPath;

    if ($configuredPath === null) {
        $configuredPath = dirname(__DIR__) . DIRECTORY_SEPARATOR
            . 'config' . DIRECTORY_SEPARATOR . 'runtime-config.php';
    }

    $configuredPath = trim($configuredPath);

    if ($configuredPath === '' || !carmaja_bootstrap_is_absolute_path($configuredPath)) {
        throw new CarmajaBootstrapException(
            'config_path_invalid',
            'Private Laufzeitkonfiguration ist nicht sicher konfiguriert.'
        );
    }

    $realPath = realpath($configuredPath);

    if (!is_string($realPath) || !is_file($realPath) || !is_readable($realPath)) {
        throw new CarmajaBootstrapException(
            'config_unavailable',
            'Private Laufzeitkonfiguration ist nicht verfügbar.'
        );
    }

    return $realPath;
}

function carmaja_bootstrap_required_string(array $config, string $key): string
{
    $value = $config[$key] ?? null;

    if (!is_string($value) || trim($value) === '') {
        throw new CarmajaBootstrapException(
            'config_value_invalid',
            'Private Laufzeitkonfiguration ist unvollständig.'
        );
    }

    return trim($value);
}

function carmaja_bootstrap_required_path(array $config, string $key): string
{
    $path = carmaja_bootstrap_required_string($config, $key);

    if (!carmaja_bootstrap_is_absolute_path($path)) {
        throw new CarmajaBootstrapException(
            'config_path_invalid',
            'Private Laufzeitkonfiguration enthält einen ungültigen Pfad.'
        );
    }

    return rtrim($path, "\\/");
}

function carmaja_bootstrap_optional_path(array $config, string $key): ?string
{
    $value = $config[$key] ?? null;

    if ($value === null || (is_string($value) && trim($value) === '')) {
        return null;
    }

    if (!is_string($value) || !carmaja_bootstrap_is_absolute_path(trim($value))) {
        throw new CarmajaBootstrapException(
            'config_path_invalid',
            'Private Laufzeitkonfiguration enthält einen ungültigen optionalen Pfad.'
        );
    }

    return rtrim(trim($value), "\\/");
}

function carmaja_bootstrap_optional_string(array $config, string $key): ?string
{
    $value = $config[$key] ?? null;

    if ($value === null || (is_string($value) && trim($value) === '')) {
        return null;
    }

    if (!is_string($value)) {
        throw new CarmajaBootstrapException(
            'config_value_invalid',
            'Private Laufzeitkonfiguration enthält einen ungültigen optionalen Wert.'
        );
    }

    return trim($value);
}

function carmaja_bootstrap_validate_config(array $config, string $configFile): array
{
    $allowedKeys = [
        'environment',
        'publishTarget',
        'productionPublishEnabled',
        'privateDir',
        'testPrivateDir',
        'testApiWebroot',
        'testWebsiteWebroot',
        'productionPrivateDir',
        'productionApiWebroot',
        'productionWebsiteWebroot',
        'usersFile',
        'tokenPepper',
        'githubAdapterEnabled',
        'githubRepository',
        'githubBranch',
        'githubTokenFile',
        'commerceDsn',
        'commerceUser',
        'commercePassword',
        'commerceTlsCaPath',
        'commerceRequireTls',
        'stripeSecretKey',
        'stripeWebhookSecret',
        'stripeWebhookPayloadKey',
        'stripeWebhookPayloadKeyId',
        'stripeAutoload',
        'stripeSdkVersion',
        'stripeApiVersion',
        'stripeWebhookApiVersion',
        'stripeSuccessUrl',
        'stripeCancelUrl',
        'activeLegalBundleId',
        'shippingMethodId',
        'shippingPublicName',
        'shippingAmountMinor',
        'shippingMinBusinessDays',
        'shippingMaxBusinessDays',
        'shopWebsiteOrigin',
        'brevoApiKey',
        'brevoSenderEmail',
        'brevoSenderName',
    ];
    $unknownKeys = array_diff(array_keys($config), $allowedKeys);

    if ($unknownKeys !== []) {
        throw new CarmajaBootstrapException(
            'config_keys_invalid',
            'Private Laufzeitkonfiguration enthält unbekannte Einträge.'
        );
    }

    $environment = carmaja_bootstrap_required_string($config, 'environment');
    $publishTarget = carmaja_bootstrap_required_string($config, 'publishTarget');
    $productionPublishEnabled = $config['productionPublishEnabled'] ?? null;

    if (!in_array($publishTarget, ['test', 'production'], true)
        || $environment !== $publishTarget
        || !is_bool($productionPublishEnabled)
        || ($publishTarget === 'test' && $productionPublishEnabled)) {
        throw new CarmajaBootstrapException(
            'config_environment_invalid',
            'Veröffentlichungsumgebung ist nicht sicher konfiguriert.'
        );
    }

    $privateDir = carmaja_bootstrap_required_path($config, 'privateDir');
    $testPrivateDir = carmaja_bootstrap_required_path($config, 'testPrivateDir');
    $testApiWebroot = carmaja_bootstrap_required_path($config, 'testApiWebroot');
    $testWebsiteWebroot = carmaja_bootstrap_required_path($config, 'testWebsiteWebroot');
    $productionPrivateDir = carmaja_bootstrap_optional_path(
        $config,
        'productionPrivateDir'
    );
    $productionApiWebroot = carmaja_bootstrap_optional_path(
        $config,
        'productionApiWebroot'
    );
    $productionWebsiteWebroot = carmaja_bootstrap_optional_path(
        $config,
        'productionWebsiteWebroot'
    );
    $usersFile = carmaja_bootstrap_required_path($config, 'usersFile');
    $tokenPepper = carmaja_bootstrap_required_string($config, 'tokenPepper');
    $githubAdapterEnabled = $config['githubAdapterEnabled'] ?? false;
    $githubRepository = carmaja_bootstrap_optional_string(
        $config,
        'githubRepository'
    );
    $githubBranch = carmaja_bootstrap_optional_string($config, 'githubBranch');
    $githubTokenFile = carmaja_bootstrap_optional_path($config, 'githubTokenFile');
    $commerceDsn = carmaja_bootstrap_optional_string($config, 'commerceDsn');
    $commerceUser = carmaja_bootstrap_optional_string($config, 'commerceUser');
    $commercePassword = carmaja_bootstrap_optional_string($config, 'commercePassword');
    $commerceTlsCaPath = carmaja_bootstrap_optional_path($config, 'commerceTlsCaPath');
    $commerceRequireTls = $config['commerceRequireTls'] ?? false;
    $stripeSecretKey = carmaja_bootstrap_optional_string($config, 'stripeSecretKey');
    $stripeWebhookSecret = carmaja_bootstrap_optional_string($config, 'stripeWebhookSecret');
    $stripeWebhookPayloadKey = carmaja_bootstrap_optional_string($config, 'stripeWebhookPayloadKey');
    $stripeWebhookPayloadKeyId = carmaja_bootstrap_optional_string($config, 'stripeWebhookPayloadKeyId');
    $stripeAutoload = carmaja_bootstrap_optional_path($config, 'stripeAutoload');
    $stripeSdkVersion = carmaja_bootstrap_optional_string($config, 'stripeSdkVersion');
    $stripeApiVersion = carmaja_bootstrap_optional_string($config, 'stripeApiVersion');
    $stripeWebhookApiVersion = carmaja_bootstrap_optional_string($config, 'stripeWebhookApiVersion');
    $stripeSuccessUrl = carmaja_bootstrap_optional_string($config, 'stripeSuccessUrl');
    $stripeCancelUrl = carmaja_bootstrap_optional_string($config, 'stripeCancelUrl');
    $activeLegalBundleId = carmaja_bootstrap_optional_string($config, 'activeLegalBundleId');
    $shippingMethodId = carmaja_bootstrap_optional_string($config, 'shippingMethodId');
    $shippingPublicName = carmaja_bootstrap_optional_string($config, 'shippingPublicName');
    $shippingAmountMinor = $config['shippingAmountMinor'] ?? null;
    $shippingMinBusinessDays = $config['shippingMinBusinessDays'] ?? null;
    $shippingMaxBusinessDays = $config['shippingMaxBusinessDays'] ?? null;
    $shopWebsiteOrigin = carmaja_bootstrap_optional_string($config, 'shopWebsiteOrigin');
    $brevoApiKey = carmaja_bootstrap_optional_string($config, 'brevoApiKey');
    $brevoSenderEmail = carmaja_bootstrap_optional_string($config, 'brevoSenderEmail');
    $brevoSenderName = carmaja_bootstrap_optional_string($config, 'brevoSenderName');

    if (strlen($tokenPepper) < 32 || !is_bool($githubAdapterEnabled)
        || !is_bool($commerceRequireTls)
        || ($shippingAmountMinor !== null && !is_int($shippingAmountMinor))
        || ($shippingMinBusinessDays !== null && !is_int($shippingMinBusinessDays))
        || ($shippingMaxBusinessDays !== null && !is_int($shippingMaxBusinessDays))) {
        throw new CarmajaBootstrapException(
            'config_secret_invalid',
            'Private Laufzeitkonfiguration ist nicht sicher konfiguriert.'
        );
    }

    if ($githubBranch !== null && $githubBranch !== 'test/product-management-beta') {
        throw new CarmajaBootstrapException(
            'github_branch_invalid',
            'GitHub-Testbranch ist nicht sicher konfiguriert.'
        );
    }

    if ($githubRepository !== null
        && $githubRepository !== 'Bumpers210/armband-rechner') {
        throw new CarmajaBootstrapException(
            'github_repository_invalid',
            'GitHub-Zielrepository ist nicht sicher konfiguriert.'
        );
    }

    if ($githubAdapterEnabled
        && ($publishTarget !== 'test'
            || $productionPublishEnabled
            || $githubRepository !== 'Bumpers210/armband-rechner'
            || $githubBranch !== 'test/product-management-beta'
            || $githubTokenFile === null)) {
        throw new CarmajaBootstrapException(
            'github_adapter_configuration_invalid',
            'GitHub-Testadapter ist nicht sicher konfiguriert.'
        );
    }

    if ($publishTarget === 'production'
        && ($productionPrivateDir === null
            || $productionApiWebroot === null
            || $productionWebsiteWebroot === null)) {
        throw new CarmajaBootstrapException(
            'production_paths_required',
            'Produktionspfade sind nicht vollständig konfiguriert.'
        );
    }

    $expectedPrivateDir = $publishTarget === 'test'
        ? $testPrivateDir
        : $productionPrivateDir;

    if (!is_string($expectedPrivateDir)
        || carmaja_bootstrap_normalize_path($privateDir)
            !== carmaja_bootstrap_normalize_path($expectedPrivateDir)) {
        throw new CarmajaBootstrapException(
            'config_private_target_mismatch',
            'Privater Datenpfad passt nicht zur Veröffentlichungsumgebung.'
        );
    }

    $rootPaths = array_filter([
        $testPrivateDir,
        $testApiWebroot,
        $testWebsiteWebroot,
        $productionPrivateDir,
        $productionApiWebroot,
        $productionWebsiteWebroot,
    ], static fn (?string $path): bool => $path !== null);
    $normalizedRoots = array_map('carmaja_bootstrap_normalize_path', $rootPaths);

    if (count(array_unique($normalizedRoots)) !== count($normalizedRoots)) {
        throw new CarmajaBootstrapException(
            'config_paths_not_separated',
            'Test- und Produktionspfade sind nicht vollständig getrennt.'
        );
    }

    $rootPaths = array_values($rootPaths);

    foreach ($rootPaths as $leftIndex => $leftPath) {
        foreach ($rootPaths as $rightIndex => $rightPath) {
            if ($leftIndex >= $rightIndex) {
                continue;
            }

            if (carmaja_bootstrap_path_is_inside($leftPath, $rightPath)
                || carmaja_bootstrap_path_is_inside($rightPath, $leftPath)) {
                throw new CarmajaBootstrapException(
                    'config_paths_not_separated',
                    'Test- und Produktionspfade sind nicht vollständig getrennt.'
                );
            }
        }
    }

    $webroots = array_filter([
        $testApiWebroot,
        $testWebsiteWebroot,
        $productionApiWebroot,
        $productionWebsiteWebroot,
    ], static fn (?string $path): bool => $path !== null);

    foreach ([$testPrivateDir, $productionPrivateDir] as $privatePath) {
        if ($privatePath === null) {
            continue;
        }

        foreach ($webroots as $webroot) {
            if (carmaja_bootstrap_path_is_inside($privatePath, $webroot)
                || carmaja_bootstrap_path_is_inside($webroot, $privatePath)) {
                throw new CarmajaBootstrapException(
                    'config_private_path_exposed',
                    'Privater Datenbereich und Webroots sind nicht sicher getrennt.'
                );
            }
        }
    }

    if (!carmaja_bootstrap_path_is_inside($usersFile, $privateDir)
        || !carmaja_bootstrap_path_is_inside($configFile, $privateDir)
        || ($githubTokenFile !== null
            && !carmaja_bootstrap_path_is_inside($githubTokenFile, $privateDir))) {
        throw new CarmajaBootstrapException(
            'config_private_file_exposed',
            'Private Konfigurationsdateien liegen nicht im privaten Datenbereich.'
        );
    }

    return [
        'environment' => $environment,
        'publishTarget' => $publishTarget,
        'productionPublishEnabled' => $productionPublishEnabled,
        'privateDir' => $privateDir,
        'testPrivateDir' => $testPrivateDir,
        'testApiWebroot' => $testApiWebroot,
        'testWebsiteWebroot' => $testWebsiteWebroot,
        'productionPrivateDir' => $productionPrivateDir,
        'productionApiWebroot' => $productionApiWebroot,
        'productionWebsiteWebroot' => $productionWebsiteWebroot,
        'usersFile' => $usersFile,
        'tokenPepper' => $tokenPepper,
        'githubAdapterEnabled' => $githubAdapterEnabled,
        'githubRepository' => $githubRepository,
        'githubBranch' => $githubBranch,
        'githubTokenFile' => $githubTokenFile,
        'commerceDsn' => $commerceDsn,
        'commerceUser' => $commerceUser,
        'commercePassword' => $commercePassword,
        'commerceTlsCaPath' => $commerceTlsCaPath,
        'commerceRequireTls' => $commerceRequireTls,
        'stripeSecretKey' => $stripeSecretKey,
        'stripeWebhookSecret' => $stripeWebhookSecret,
        'stripeWebhookPayloadKey' => $stripeWebhookPayloadKey,
        'stripeWebhookPayloadKeyId' => $stripeWebhookPayloadKeyId,
        'stripeAutoload' => $stripeAutoload,
        'stripeSdkVersion' => $stripeSdkVersion,
        'stripeApiVersion' => $stripeApiVersion,
        'stripeWebhookApiVersion' => $stripeWebhookApiVersion,
        'stripeSuccessUrl' => $stripeSuccessUrl,
        'stripeCancelUrl' => $stripeCancelUrl,
        'activeLegalBundleId' => $activeLegalBundleId,
        'shippingMethodId' => $shippingMethodId,
        'shippingPublicName' => $shippingPublicName,
        'shippingAmountMinor' => $shippingAmountMinor,
        'shippingMinBusinessDays' => $shippingMinBusinessDays,
        'shippingMaxBusinessDays' => $shippingMaxBusinessDays,
        'shopWebsiteOrigin' => $shopWebsiteOrigin,
        'brevoApiKey' => $brevoApiKey,
        'brevoSenderEmail' => $brevoSenderEmail,
        'brevoSenderName' => $brevoSenderName,
        'configFile' => $configFile,
    ];
}

function carmaja_bootstrap_load_config(?string $explicitPath = null): array
{
    $configFile = carmaja_bootstrap_config_path($explicitPath);
    ob_start();

    try {
        $config = (static function (string $path): mixed {
            return require $path;
        })($configFile);
        $unexpectedOutput = ob_get_clean();
    } catch (Throwable $error) {
        ob_end_clean();
        throw new CarmajaBootstrapException(
            'config_load_failed',
            'Private Laufzeitkonfiguration konnte nicht geladen werden.'
        );
    }

    if ($unexpectedOutput !== '' || !is_array($config) || array_is_list($config)) {
        throw new CarmajaBootstrapException(
            'config_format_invalid',
            'Private Laufzeitkonfiguration hat ein ungültiges Format.'
        );
    }

    return carmaja_bootstrap_validate_config($config, $configFile);
}

function carmaja_bootstrap_set_environment(string $name, ?string $value): void
{
    $result = $value === null
        ? putenv($name)
        : putenv($name . '=' . $value);

    if (!$result) {
        throw new CarmajaBootstrapException(
            'environment_apply_failed',
            'Laufzeitkonfiguration konnte nicht aktiviert werden.'
        );
    }
}

function carmaja_bootstrap_apply_config(array $config): void
{
    umask(0027);
    $environment = [
        'CARMAJA_CONFIG_FILE' => $config['configFile'],
        'CARMAJA_PUBLISH_TARGET' => $config['publishTarget'],
        'CARMAJA_PRODUCTION_PUBLISH_ENABLED' =>
            $config['productionPublishEnabled'] ? 'true' : 'false',
        'CARMAJA_PRIVATE_DIR' => $config['privateDir'],
        'CARMAJA_TEST_PRIVATE_DIR' => $config['testPrivateDir'],
        'CARMAJA_PRODUCTION_PRIVATE_DIR' => $config['productionPrivateDir'],
        'CARMAJA_PUBLIC_WEBROOT' => $config['publishTarget'] === 'test'
            ? $config['testApiWebroot']
            : $config['productionApiWebroot'],
        'CARMAJA_TEST_API_WEBROOT' => $config['testApiWebroot'],
        'CARMAJA_TEST_WEBSITE_WEBROOT' => $config['testWebsiteWebroot'],
        'CARMAJA_PRODUCTION_API_WEBROOT' => $config['productionApiWebroot'],
        'CARMAJA_PRODUCTION_WEBSITE_WEBROOT' => $config['productionWebsiteWebroot'],
        'CARMAJA_API_USERS_FILE' => $config['usersFile'],
        'CARMAJA_TOKEN_PEPPER' => $config['tokenPepper'],
        'CARMAJA_GITHUB_ADAPTER_ENABLED' =>
            $config['githubAdapterEnabled'] ? 'true' : 'false',
        'CARMAJA_GITHUB_REPOSITORY' => $config['githubRepository'],
        'CARMAJA_GITHUB_BRANCH' => $config['githubBranch'],
        'CARMAJA_GITHUB_TOKEN_FILE' => $config['githubTokenFile'],
    ];

    foreach ($environment as $name => $value) {
        carmaja_bootstrap_set_environment($name, $value);
    }
}

function carmaja_bootstrap_prepare(?string $configPath = null): array
{
    $config = carmaja_bootstrap_load_config($configPath);
    carmaja_bootstrap_apply_config($config);
    require_once __DIR__ . '/product-api.php';
    require_once __DIR__ . '/product-api-v2.php';
    require_once __DIR__ . '/commerce-bootstrap.php';
    require_once __DIR__ . '/shop-checkout.php';
    require_once __DIR__ . '/shop-public.php';
    require_once __DIR__ . '/shop-admin.php';
    require_once __DIR__ . '/ap5-worker.php';
    require_once __DIR__ . '/stripe-webhook.php';

    if ($config['githubAdapterEnabled']) {
        $GLOBALS['CARMAJA_API_PUBLISH_ADAPTER'] =
            'carmaja_api_github_publish_adapter';
    } else {
        unset($GLOBALS['CARMAJA_API_PUBLISH_ADAPTER']);
    }

    return $config;
}

function carmaja_bootstrap_send(int $statusCode, array $payload): never
{
    http_response_code($statusCode);
    echo json_encode(
        $payload,
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
    );
    exit;
}

function carmaja_bootstrap_send_unavailable(): never
{
    carmaja_bootstrap_send(503, [
        'ok' => false,
        'error' => [
            'code' => 'service_unavailable',
            'message' => 'Test-API ist nicht sicher konfiguriert.',
            'fields' => (object) [],
        ],
    ]);
}

function carmaja_bootstrap_route_request(): never
{
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $path = trim((string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH), '/');
    $segments = $path === '' ? [] : explode('/', $path);

    if (($segments[0] ?? null) === 'api') {
        array_shift($segments);
    }

    $isShopRoute = ($segments[0] ?? null) === 'shop' && ($segments[1] ?? null) === 'v1';
    $isAdminRoute = ($segments[0] ?? null) === 'admin' && ($segments[1] ?? null) === 'v1';
    if ($isAdminRoute) {
        $adminConfig = carmaja_bootstrap_load_config();
        carmaja_shop_apply_cors($adminConfig, $_SERVER['HTTP_ORIGIN'] ?? null);
        carmaja_shop_set_no_store();
        carmaja_shop_require_origin($adminConfig);
        if ($method === 'OPTIONS') {
            http_response_code(204);
            exit;
        }
        $commerce = carmaja_bootstrap_commerce($adminConfig);
        $adminSegments = array_slice($segments, 2);
        if ($method === 'POST' && $adminSegments === ['login']) {
            $body = carmaja_shop_admin_request_json();
            $login = carmaja_shop_admin_login(
                $commerce,
                (string) ($body['username'] ?? ''),
                (string) ($body['password'] ?? ''),
                (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown')
            );
            carmaja_shop_admin_set_session_cookie($login['sessionToken'], time() + CARMAJA_SHOP_ADMIN_SESSION_TTL_SECONDS);
            carmaja_bootstrap_send(200, [
                'ok' => true,
                'admin' => [
                    'adminId' => $login['adminId'],
                    'username' => $login['username'],
                    'csrfToken' => $login['csrfToken'],
                    'csrfExpiresAt' => $login['csrfExpiresAt'],
                    'expiresAt' => $login['expiresAt'],
                ],
            ]);
        }
        $sessionToken = $_COOKIE['__Host-cmj_admin'] ?? '';
        if (!is_string($sessionToken) || $sessionToken === '') {
            throw new CarmajaShopAdminException('admin_session_required', 'Admin-Sitzung ist erforderlich.', 401);
        }
        $session = carmaja_shop_admin_authenticate($commerce, $sessionToken);
        if ($method === 'GET' && $adminSegments === ['session']) {
            carmaja_bootstrap_send(200, [
                'ok' => true,
                'admin' => ['adminId' => $session['admin_id'], 'username' => $session['username']],
            ]);
        }
        if ($method === 'POST' && $adminSegments === ['logout']) {
            carmaja_shop_admin_require_csrf($session, (string) ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''));
            $commerce->revokeAdminSession((string) $session['session_hash']);
            carmaja_shop_admin_clear_session_cookie();
            carmaja_bootstrap_send(200, ['ok' => true]);
        }
        $csrfRequired = static function () use ($session): void {
            carmaja_shop_admin_require_csrf($session, (string) ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''));
        };
        if ($method === 'GET' && $adminSegments === ['orders']) {
            carmaja_bootstrap_send(200, ['ok' => true, 'orders' => $commerce->listAdminOrders()]);
        }
        if ($method === 'GET' && ($adminSegments[0] ?? null) === 'orders' && isset($adminSegments[1])
            && count($adminSegments) === 2) {
            $order = $commerce->loadAdminOrder((string) $adminSegments[1]);
            if (!is_array($order)) {
                throw new CarmajaCommerceException('order_not_found', 'Bestellung fehlt.', 404);
            }
            carmaja_bootstrap_send(200, ['ok' => true, 'order' => $order]);
        }
        if ($method === 'POST' && ($adminSegments[0] ?? null) === 'orders'
            && ($adminSegments[2] ?? null) === 'ship' && count($adminSegments) === 3) {
            $csrfRequired();
            $body = carmaja_shop_admin_request_json();
            $result = $commerce->markAdminShipmentShipped(
                (string) $adminSegments[1],
                is_string($body['trackingNumber'] ?? null) ? trim($body['trackingNumber']) : '',
                (string) $session['admin_id'],
                carmaja_shop_admin_correlation($body)
            );
            carmaja_bootstrap_send(200, ['ok' => true, 'shipment' => $result]);
        }
        if ($method === 'GET' && $adminSegments === ['refunds']) {
            carmaja_bootstrap_send(200, ['ok' => true, 'refunds' => $commerce->listAdminRefunds()]);
        }
        if ($method === 'GET' && $adminSegments === ['mails']) {
            carmaja_bootstrap_send(200, ['ok' => true, 'mails' => $commerce->listAdminMailOutbox()]);
        }
        if ($method === 'GET' && $adminSegments === ['reviews']) {
            carmaja_bootstrap_send(200, ['ok' => true, 'reviews' => $commerce->listAdminReviewCases()]);
        }
        if ($method === 'POST' && ($adminSegments[0] ?? null) === 'reviews'
            && ($adminSegments[2] ?? null) === 'status' && count($adminSegments) === 3) {
            $csrfRequired();
            $body = carmaja_shop_admin_request_json();
            carmaja_bootstrap_send(200, ['ok' => true, 'review' => $commerce->updateAdminReviewCase(
                (string) $adminSegments[1],
                is_string($body['status'] ?? null) ? $body['status'] : '',
                (string) $session['admin_id'],
                carmaja_shop_admin_correlation($body)
            )]);
        }
        if ($method === 'POST' && ($adminSegments[0] ?? null) === 'withdrawals'
            && ($adminSegments[2] ?? null) === 'review' && count($adminSegments) === 3) {
            $csrfRequired();
            $body = carmaja_shop_admin_request_json();
            carmaja_bootstrap_send(200, ['ok' => true, 'withdrawal' => $commerce->reviewAdminWithdrawal(
                (string) $adminSegments[1], (string) $session['admin_id'], carmaja_shop_admin_correlation($body)
            )]);
        }
        if ($method === 'POST' && ($adminSegments[0] ?? null) === 'restocks'
            && ($adminSegments[2] ?? null) === 'confirm' && count($adminSegments) === 3) {
            $csrfRequired();
            $body = carmaja_shop_admin_request_json();
            $idempotency = (string) ($_SERVER['HTTP_IDEMPOTENCY_KEY'] ?? '');
            if (preg_match('/^[A-Za-z0-9._:-]{8,100}$/', $idempotency) !== 1) {
                throw new CarmajaShopAdminException('admin_idempotency_required', 'Idempotenzschlüssel ist erforderlich.', 422);
            }
            carmaja_bootstrap_send(200, ['ok' => true, 'restock' => $commerce->confirmAdminRestock(
                (string) $adminSegments[1], (string) $session['admin_id'],
                carmaja_shop_admin_correlation($body), $idempotency
            )]);
        }
        if ($method === 'POST' && ($adminSegments[0] ?? null) === 'mail'
            && ($adminSegments[2] ?? null) === 'resend' && count($adminSegments) === 3) {
            $csrfRequired();
            $body = carmaja_shop_admin_request_json();
            $mailId = filter_var($adminSegments[1], FILTER_VALIDATE_INT);
            if ($mailId === false || $mailId < 1) {
                throw new CarmajaShopAdminException('admin_mail_invalid', 'Mail-ID ist ungültig.', 422);
            }
            carmaja_bootstrap_send(202, ['ok' => true, 'mail' => $commerce->queueAdminMailResend(
                (int) $mailId, (string) $session['admin_id'], carmaja_shop_admin_correlation($body)
            )]);
        }
        throw new CarmajaShopAdminException('admin_endpoint_not_found', 'Admin-Endpunkt wurde nicht gefunden.', 404);
    }
    if ($isShopRoute) {
        $shopConfig = carmaja_bootstrap_load_config();
        carmaja_shop_apply_cors($shopConfig, $_SERVER['HTTP_ORIGIN'] ?? null);
        carmaja_shop_set_no_store();
        carmaja_shop_require_origin($shopConfig);
        if ($method === 'OPTIONS') {
            http_response_code(204);
            exit;
        }

        $commerce = carmaja_bootstrap_commerce($shopConfig);
        $sessionCookie = $_COOKIE['__Host-cmj_shop_session'] ?? null;

        if ($method === 'GET' && $segments === ['shop', 'v1', 'context']) {
            $sessionRaw = is_string($sessionCookie) && strlen($sessionCookie) >= 32
                ? $sessionCookie
                : carmaja_shop_token();
            $context = carmaja_shop_context($commerce, $sessionRaw);
            carmaja_shop_cookie(
                '__Host-cmj_shop_session',
                $sessionRaw,
                time() + CARMAJA_SHOP_SESSION_TTL_SECONDS
            );
            carmaja_bootstrap_send(200, [
                'ok' => true,
                'context' => [
                    'csrfToken' => $context['csrfToken'],
                    'liveContextToken' => $context['liveContextToken'],
                    'csrfExpiresAt' => $context['csrfExpiresAt'],
                    'checkoutContextExpiresAt' => $context['checkoutContextExpiresAt'],
                ],
            ]);
        }

        if ($method === 'GET' && ($segments[2] ?? null) === 'products' && isset($segments[3])
            && count($segments) === 4) {
            $live = $commerce->loadLiveProduct((string) $segments[3]);
            carmaja_bootstrap_send(200, ['ok' => true, 'product' => $live]);
        }

        if ($sessionCookie === null || !is_string($sessionCookie)) {
            throw new CarmajaCommerceException('shop_session_required', 'Shop-Sitzung ist erforderlich.', 403);
        }
        $session = carmaja_shop_verify_context($commerce, $sessionCookie);

        if ($method === 'POST' && $segments === ['shop', 'v1', 'checkouts']) {
            carmaja_shop_verify_raw_context(
                carmaja_shop_require_header('X-CSRF-Token'),
                (string) $session['csrf_hash']
            );
            carmaja_shop_verify_raw_context(
                carmaja_shop_require_header('X-Live-Context'),
                (string) $session['live_context_hash']
            );
            $body = carmaja_shop_json_body();
            $productId = carmaja_shop_validate_checkout_request($body);
            $idempotencyKey = carmaja_shop_require_header('Idempotency-Key');
            $bucketHash = carmaja_shop_rate_key(
                carmaja_shop_token_hash($sessionCookie),
                (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown')
            );
            $ipBucketHash = hash(
                'sha256',
                'ip|' . (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown')
            );
            $existingCheckout = $commerce->findCheckoutByIdempotency($idempotencyKey);
            if (!is_array($existingCheckout)
                && (!$commerce->reserveShopAttempt($bucketHash)
                    || !$commerce->reserveShopAttempt($ipBucketHash))) {
                throw new CarmajaCommerceException('checkout_rate_limited', 'Zu viele unbezahlte Checkout-Versuche.', 429);
            }
            $service = new CarmajaCheckoutService($commerce, carmaja_bootstrap_stripe($shopConfig), $shopConfig);
            try {
                $result = $service->start(['productId' => $productId], $idempotencyKey);
            } catch (Throwable $error) {
                throw $error;
            }
            $checkout = $commerce->findCheckoutByIdempotency($idempotencyKey);
            if (!is_array($checkout)) {
                throw new CarmajaCommerceException('checkout_not_found', 'Checkout konnte nicht gelesen werden.', 500);
            }
            $token = carmaja_shop_token();
            $commerce->issueCheckoutToken(
                (string) $checkout['checkout_id'],
                carmaja_shop_token_hash($token),
                carmaja_shop_token_hash($sessionCookie),
                (string) $checkout['product_id'],
                (int) $checkout['product_version'],
                (string) $checkout['request_hash'],
                $bucketHash,
                $ipBucketHash,
                gmdate('Y-m-d H:i:s', time() + CARMAJA_SHOP_CHECKOUT_TOKEN_TTL_SECONDS)
            );
            carmaja_shop_cookie('__Host-cmj_checkout', $token, time() + CARMAJA_SHOP_CHECKOUT_TOKEN_TTL_SECONDS);
            carmaja_bootstrap_send(201, ['ok' => true, 'checkout' => $result]);
        }

        if ($method === 'GET' && ($segments[2] ?? null) === 'checkouts'
            && isset($segments[3]) && ($segments[4] ?? null) === 'status' && count($segments) === 5) {
            $checkoutToken = $_COOKIE['__Host-cmj_checkout'] ?? '';
            if (!is_string($checkoutToken)) {
                throw new CarmajaCommerceException('checkout_token_required', 'Checkout-Berechtigung ist erforderlich.', 403);
            }
            $tokenRow = $commerce->loadCheckoutToken(carmaja_shop_token_hash($checkoutToken));
            if (!is_array($tokenRow)
                || $tokenRow['checkout_id'] !== (string) $segments[3]
                || $tokenRow['session_hash'] !== carmaja_shop_token_hash($sessionCookie)
                || strtotime((string) $tokenRow['expires_at']) < time()) {
                throw new CarmajaCommerceException('checkout_token_invalid', 'Checkout-Berechtigung ist ungÃ¼ltig.', 403);
            }
            $status = $commerce->loadCheckoutStatus((string) $segments[3]);
            if (!is_array($status)) {
                throw new CarmajaCommerceException('checkout_not_found', 'Checkout wurde nicht gefunden.', 404);
            }
            carmaja_bootstrap_send(200, ['ok' => true, 'checkout' => $status]);
        }

        if ($method === 'POST' && $segments === ['shop', 'v1', 'withdrawals', 'preview']) {
            carmaja_shop_verify_raw_context(
                carmaja_shop_require_header('X-CSRF-Token'),
                (string) $session['csrf_hash']
            );
            $body = carmaja_shop_json_body();
            $orderNumber = is_string($body['orderNumber'] ?? null) ? trim($body['orderNumber']) : '';
            $name = is_string($body['name'] ?? null) ? trim($body['name']) : '';
            $email = is_string($body['email'] ?? null) ? trim($body['email']) : '';
            if ($orderNumber === '' || $name === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                throw new CarmajaCommerceException('withdrawal_identification_invalid', 'Identifikationsdaten sind unvollstÃ¤ndig.', 422);
            }
            $match = $commerce->findOrderForWithdrawal($orderNumber, $name, $email);
            $confirmationToken = carmaja_shop_token();
            $withdrawalId = carmaja_commerce_new_id();
            $commerce->createWithdrawalRequest(
                $withdrawalId,
                $match['orderId'],
                carmaja_shop_token_hash($confirmationToken),
                $match['matchStatus'],
                ['orderNumber' => $orderNumber, 'name' => $name, 'email' => $email]
            );
            carmaja_bootstrap_send(200, [
                'ok' => true,
                'withdrawal' => [
                    'withdrawalId' => $withdrawalId,
                    'matchStatus' => $match['matchStatus'],
                    'confirmationToken' => $confirmationToken,
                    'requiresConfirmation' => true,
                ],
            ]);
        }

        if ($method === 'POST' && $segments === ['shop', 'v1', 'withdrawals', 'confirm']) {
            carmaja_shop_verify_raw_context(
                carmaja_shop_require_header('X-CSRF-Token'),
                (string) $session['csrf_hash']
            );
            $body = carmaja_shop_json_body();
            if (!is_string($body['withdrawalId'] ?? null) || !is_string($body['confirmationToken'] ?? null)) {
                throw new CarmajaCommerceException('withdrawal_confirmation_invalid', 'BestÃ¤tigung ist ungÃ¼ltig.', 422);
            }
            $result = $commerce->confirmWithdrawal(
                $body['withdrawalId'],
                carmaja_shop_token_hash($body['confirmationToken'])
            );
            carmaja_bootstrap_send(200, [
                'ok' => true,
                'withdrawal' => [
                    'withdrawalId' => $result['withdrawal_id'],
                    'state' => $result['state'],
                    'receivedAt' => $result['confirmed_at'],
                    'confirmation' => 'electronic_receipt_queued',
                ],
            ]);
        }
    }

    if ($method === 'POST' && $segments === ['shop', 'v1', 'checkouts']) {
        $config = carmaja_bootstrap_load_config();
        $body = file_get_contents('php://input');
        $request = is_string($body) && $body !== ''
            ? json_decode($body, true, 16, JSON_THROW_ON_ERROR)
            : null;
        if (!is_array($request)) {
            throw new CarmajaCommerceException('checkout_request_invalid', 'Checkout-Anfrage ist ungültig.', 422);
        }
        $commerce = carmaja_bootstrap_commerce($config);
        $stripe = carmaja_bootstrap_stripe($config);
        $service = new CarmajaCheckoutService($commerce, $stripe, $config);
        $result = $service->start(
            $request,
            (string) ($_SERVER['HTTP_IDEMPOTENCY_KEY'] ?? '')
        );
        carmaja_bootstrap_send(201, ['ok' => true, 'checkout' => $result]);
    }

    if ($method === 'POST' && $segments === ['stripe', 'webhook']) {
        $config = carmaja_bootstrap_load_config();
        $rawBody = file_get_contents('php://input');
        if (!is_string($rawBody)) {
            throw new CarmajaStripeException('webhook_payload_invalid', 'Webhook-Payload ist ungültig.', 400);
        }
        $commerce = carmaja_bootstrap_commerce($config);
        $endpoint = new CarmajaStripeWebhookEndpoint();
        $result = $endpoint->receive(
            $rawBody,
            (string) ($_SERVER['HTTP_STRIPE_SIGNATURE'] ?? ''),
            (string) ($config['stripeWebhookSecret'] ?? ''),
            $config['environment'] === 'production',
            static function (array $envelope, string $raw) use ($commerce, $config): void {
                $encrypted = carmaja_stripe_encrypt_webhook_payload(
                    $raw,
                    (string) ($config['stripeWebhookPayloadKey'] ?? '')
                );
                $commerce->persistWebhookEnvelope(
                    $envelope,
                    $encrypted['ciphertext'],
                    (string) ($config['stripeWebhookPayloadKeyId'] ?? '')
                );
            }
        );
        http_response_code((int) $result['status']);
        exit;
    }

    if (($segments[0] ?? null) === 'v2') {
        array_shift($segments);

        if ($method === 'POST'
            && ($segments[0] ?? null) === 'login'
            && count($segments) === 1) {
            carmaja_bootstrap_send(
                200,
                carmaja_api_v2_success_response(carmaja_api_login(carmaja_api_json_body()))
            );
        }

        $actor = carmaja_api_authorize();

        if ($method === 'GET'
            && ($segments[0] ?? null) === 'products'
            && count($segments) === 1) {
            carmaja_bootstrap_send(
                200,
                carmaja_api_v2_success_response(carmaja_api_v2_list_products())
            );
        }

        if (($segments[0] ?? null) === 'products' && isset($segments[1])) {
            $productId = (string) $segments[1];

            if ($method === 'GET' && count($segments) === 2) {
                $draft = carmaja_api_load_draft($productId);

                if (!is_array($draft)
                    || ($draft['productModelVersion'] ?? null) !== CARMAJA_PRODUCT_MODEL_V2) {
                    throw new CarmajaApiException(
                        409,
                        'Produkt ist noch nicht auf das v2-Modell synchronisiert.',
                        [],
                        'product_model_migration_required'
                    );
                }

                carmaja_bootstrap_send(
                    200,
                    carmaja_api_v2_success_response([
                        'product' => carmaja_api_v2_product_from_draft($draft),
                    ])
                );
            }

            if ($method === 'PUT' && count($segments) === 2) {
                $idempotencyKey = $_SERVER['HTTP_IDEMPOTENCY_KEY'] ?? null;
                $body = carmaja_api_json_body();
                carmaja_api_validate_product_write_payload($body);
                carmaja_api_validate_client_version_code(
                    $_SERVER['HTTP_X_CARMAJA_APP_VERSION_CODE'] ?? null
                );

                carmaja_bootstrap_send(
                    200,
                    carmaja_api_v2_success_response([
                        'product' => carmaja_api_v2_put_product(
                            $productId,
                            $body,
                            $actor,
                            $idempotencyKey
                        ),
                    ])
                );
            }
        }

        throw new CarmajaApiException(
            404,
            'v2-API-Endpunkt wurde nicht gefunden.',
            [],
            'endpoint_not_found'
        );
    }

    if ($method === 'POST'
        && ($segments[0] ?? null) === 'login'
        && count($segments) === 1) {
        carmaja_bootstrap_send(
            200,
            carmaja_api_success_response(carmaja_api_login(carmaja_api_json_body()))
        );
    }

    $actor = carmaja_api_authorize();

    if ($method === 'GET' && ($segments[0] ?? null) === 'products' && count($segments) === 1) {
        carmaja_bootstrap_send(
            200,
            carmaja_api_success_response(carmaja_api_list_products())
        );
    }

    if (($segments[0] ?? null) === 'products' && isset($segments[1])) {
        $draftId = (string) $segments[1];

        if ($method === 'GET' && count($segments) === 2) {
            $draft = carmaja_api_load_draft($draftId);

            if (!is_array($draft)) {
                throw new CarmajaApiException(
                    404,
                    'Entwurf wurde nicht gefunden.',
                    [],
                    'product_not_found'
                );
            }

            carmaja_bootstrap_send(
                200,
                carmaja_api_success_response(['product' => $draft])
            );
        }

        if ($method === 'PUT' && count($segments) === 2) {
            $body = carmaja_api_json_body();
            carmaja_api_validate_product_write_payload($body);
            carmaja_api_validate_client_version_code(
                $_SERVER['HTTP_X_CARMAJA_APP_VERSION_CODE'] ?? null
            );

            carmaja_bootstrap_send(
                200,
                carmaja_api_success_response([
                    'product' => carmaja_api_save_product(
                        $draftId,
                        $body,
                        $actor
                    ),
                ])
            );
        }

        if ($method === 'POST'
            && ($segments[2] ?? null) === 'images'
            && count($segments) === 3) {
            carmaja_bootstrap_send(
                200,
                carmaja_api_success_response([
                    'product' => carmaja_api_upload_images($draftId, $_POST, $actor),
                ])
            );
        }

        foreach ([
            'publish' => 'published',
            'sold' => 'sold',
            'disable' => 'disabled',
        ] as $action => $status) {
            if ($method === 'POST'
                && ($segments[2] ?? null) === $action
                && count($segments) === 3) {
                carmaja_bootstrap_send(
                    200,
                    carmaja_api_success_response(
                        carmaja_api_publish(
                            $draftId,
                            carmaja_api_json_body(),
                            $actor,
                            $status
                        )
                    )
                );
            }
        }
    }

    if ($method === 'GET'
        && ($segments[0] ?? null) === 'operations'
        && isset($segments[1])
        && count($segments) === 2) {
        carmaja_bootstrap_send(
            200,
            carmaja_api_success_response([
                'operation' => carmaja_api_operation_status((string) $segments[1]),
            ])
        );
    }

    if ($method === 'POST'
        && ($segments[0] ?? null) === 'backups'
        && count($segments) === 1) {
        carmaja_bootstrap_send(
            200,
            carmaja_api_success_response(carmaja_api_create_backup())
        );
    }

    throw new CarmajaApiException(
        404,
        'API-Endpunkt wurde nicht gefunden.',
        [],
        'endpoint_not_found'
    );
}

function carmaja_bootstrap_main(): never
{
    header('Cache-Control: no-store, max-age=0');
    header('Content-Type: application/json; charset=utf-8');
    header('X-Robots-Tag: noindex, nofollow, noimageindex', true);

    try {
        carmaja_bootstrap_prepare();
        carmaja_api_private_dir();
        carmaja_bootstrap_route_request();
    } catch (CarmajaApiException $error) {
        if ($error->statusCode === 429) {
            header('Retry-After: ' . CARMAJA_LOGIN_WINDOW_SECONDS);
        }

        carmaja_bootstrap_send(
            $error->statusCode,
            carmaja_api_error_response($error)
        );
    } catch (CarmajaCommerceException|CarmajaStripeException|CarmajaShopAdminException $error) {
        carmaja_bootstrap_send($error->httpStatus, [
            'ok' => false,
            'error' => [
                'code' => $error->errorCode,
                'message' => $error->getMessage(),
                'fields' => (object) [],
            ],
        ]);
    } catch (Throwable) {
        carmaja_bootstrap_send_unavailable();
    }
}

if (!defined('CARMAJA_BOOTSTRAP_NO_RUN')) {
    carmaja_bootstrap_main();
}
