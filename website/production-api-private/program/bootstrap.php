<?php

declare(strict_types=1);

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
    $configuredPath = $explicitPath ?? dirname(__DIR__) . DIRECTORY_SEPARATOR
        . 'config' . DIRECTORY_SEPARATOR . 'runtime-config.php';
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
            'Private Laufzeitkonfiguration ist nicht verfuegbar.'
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
            'Private Laufzeitkonfiguration ist unvollstaendig.'
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
            'Private Laufzeitkonfiguration enthaelt einen ungueltigen Pfad.'
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
            'Private Laufzeitkonfiguration enthaelt einen ungueltigen optionalen Pfad.'
        );
    }

    return rtrim(trim($value), "\\/");
}

function carmaja_bootstrap_validate_config(array $config, string $configFile): array
{
    $allowedKeys = [
        'environment',
        'publishTarget',
        'productionPublishEnabled',
        'privateDir',
        'apiWebroot',
        'websiteWebroot',
        'usersFile',
        'tokenPepper',
        'githubAdapterEnabled',
        'githubRepository',
        'githubBranch',
        'githubTokenFile',
    ];
    $unknownKeys = array_diff(array_keys($config), $allowedKeys);

    if ($unknownKeys !== []) {
        throw new CarmajaBootstrapException(
            'config_keys_invalid',
            'Private Laufzeitkonfiguration enthaelt unbekannte Eintraege.'
        );
    }

    $environment = carmaja_bootstrap_required_string($config, 'environment');
    $publishTarget = carmaja_bootstrap_required_string($config, 'publishTarget');
    $productionPublishEnabled = $config['productionPublishEnabled'] ?? null;
    $githubAdapterEnabled = $config['githubAdapterEnabled'] ?? null;

    if ($environment !== 'production'
        || $publishTarget !== 'production'
        || !is_bool($productionPublishEnabled)
        || !is_bool($githubAdapterEnabled)) {
        throw new CarmajaBootstrapException(
            'config_environment_invalid',
            'Produktionsumgebung ist nicht sicher konfiguriert.'
        );
    }

    $privateDir = carmaja_bootstrap_required_path($config, 'privateDir');
    $apiWebroot = carmaja_bootstrap_required_path($config, 'apiWebroot');
    $websiteWebroot = carmaja_bootstrap_required_path($config, 'websiteWebroot');
    $usersFile = carmaja_bootstrap_required_path($config, 'usersFile');
    $tokenPepper = carmaja_bootstrap_required_string($config, 'tokenPepper');
    $githubTokenFile = carmaja_bootstrap_optional_path($config, 'githubTokenFile');
    $githubRepository = carmaja_bootstrap_required_string($config, 'githubRepository');
    $githubBranch = carmaja_bootstrap_required_string($config, 'githubBranch');

    if (strlen($tokenPepper) < 32 || str_starts_with($tokenPepper, 'REPLACE_')) {
        throw new CarmajaBootstrapException(
            'config_secret_invalid',
            'Token-Pepper ist nicht sicher konfiguriert.'
        );
    }

    if ($githubRepository !== 'Bumpers210/armband-rechner'
        || $githubBranch !== 'main') {
        throw new CarmajaBootstrapException(
            'github_target_invalid',
            'GitHub-Ziel ist nicht sicher konfiguriert.'
        );
    }

    if (($productionPublishEnabled && !$githubAdapterEnabled)
        || ($githubAdapterEnabled && $githubTokenFile === null)) {
        throw new CarmajaBootstrapException(
            'github_adapter_configuration_invalid',
            'Produktionspublisher ist nicht sicher konfiguriert.'
        );
    }

    $roots = [$privateDir, $apiWebroot, $websiteWebroot];

    foreach ($roots as $leftIndex => $leftPath) {
        foreach ($roots as $rightIndex => $rightPath) {
            if ($leftIndex >= $rightIndex) {
                continue;
            }

            if (carmaja_bootstrap_path_is_inside($leftPath, $rightPath)
                || carmaja_bootstrap_path_is_inside($rightPath, $leftPath)) {
                throw new CarmajaBootstrapException(
                    'config_paths_not_separated',
                    'Private Daten und oeffentliche Webroots sind nicht getrennt.'
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
        'apiWebroot' => $apiWebroot,
        'websiteWebroot' => $websiteWebroot,
        'usersFile' => $usersFile,
        'tokenPepper' => $tokenPepper,
        'githubAdapterEnabled' => $githubAdapterEnabled,
        'githubRepository' => $githubRepository,
        'githubBranch' => $githubBranch,
        'githubTokenFile' => $githubTokenFile,
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
            'Private Laufzeitkonfiguration hat ein ungueltiges Format.'
        );
    }

    return carmaja_bootstrap_validate_config($config, $configFile);
}

function carmaja_bootstrap_set_environment(string $name, ?string $value): void
{
    $result = $value === null ? putenv($name) : putenv($name . '=' . $value);

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
        'CARMAJA_PUBLISH_TARGET' => 'production',
        'CARMAJA_PRODUCTION_PUBLISH_ENABLED' =>
            $config['productionPublishEnabled'] ? 'true' : 'false',
        'CARMAJA_PRIVATE_DIR' => $config['privateDir'],
        'CARMAJA_PUBLIC_WEBROOT' => $config['apiWebroot'],
        'CARMAJA_PRODUCTION_PRIVATE_DIR' => $config['privateDir'],
        'CARMAJA_PRODUCTION_API_WEBROOT' => $config['apiWebroot'],
        'CARMAJA_PRODUCTION_WEBSITE_WEBROOT' => $config['websiteWebroot'],
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

    if ($config['githubAdapterEnabled']) {
        $GLOBALS['CARMAJA_API_PUBLISH_ADAPTER'] =
            'carmaja_api_github_publish_adapter';
    } else {
        unset($GLOBALS['CARMAJA_API_PUBLISH_ADAPTER']);
    }
    if ($config['githubAdapterEnabled'] && $config['productionPublishEnabled']) {
        $GLOBALS['CARMAJA_API_PUBLISH_ADAPTER_V2'] =
            'carmaja_api_github_publish_adapter_v2';
    } else {
        unset($GLOBALS['CARMAJA_API_PUBLISH_ADAPTER_V2']);
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
            'message' => 'Produktions-API ist nicht sicher konfiguriert.',
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
                        'product' => carmaja_api_v2_product_response_from_draft($draft),
                    ])
                );
            }

            if ($method === 'POST'
                && ($segments[2] ?? null) === 'images'
                && count($segments) === 3) {
                carmaja_api_validate_client_version_code(
                    $_SERVER['HTTP_X_CARMAJA_APP_VERSION_CODE'] ?? null
                );
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
                $savedDraft = carmaja_api_upload_images($productId, $_POST, $actor);
                carmaja_bootstrap_send(
                    200,
                    carmaja_api_v2_success_response([
                        'product' => carmaja_api_v2_product_response_from_draft($savedDraft),
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

            if ($method === 'POST'
                && ($segments[2] ?? null) === 'publish'
                && count($segments) === 3) {
                carmaja_api_validate_client_version_code(
                    $_SERVER['HTTP_X_CARMAJA_APP_VERSION_CODE'] ?? null
                );
                carmaja_bootstrap_send(
                    200,
                    carmaja_api_v2_success_response([
                        ...carmaja_api_v2_publish_product(
                            $productId,
                            carmaja_api_json_body(),
                            $actor
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
            carmaja_bootstrap_send(
                200,
                carmaja_api_success_response([
                    'product' => carmaja_api_save_product(
                        $draftId,
                        carmaja_api_json_body(),
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

        carmaja_bootstrap_send($error->statusCode, carmaja_api_error_response($error));
    } catch (Throwable) {
        carmaja_bootstrap_send_unavailable();
    }
}

if (!defined('CARMAJA_BOOTSTRAP_NO_RUN')) {
    carmaja_bootstrap_main();
}
