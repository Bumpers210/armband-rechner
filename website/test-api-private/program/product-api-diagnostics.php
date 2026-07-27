<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

define('CARMAJA_BOOTSTRAP_NO_RUN', true);
require_once __DIR__ . '/bootstrap.php';

try {
    $configFile = getenv('CARMAJA_CONFIG_FILE');

    if (!is_string($configFile) || trim($configFile) === '') {
        throw new CarmajaBootstrapException(
            'config_path_missing',
            'CARMAJA_CONFIG_FILE ist nicht konfiguriert.'
        );
    }

    carmaja_bootstrap_prepare(trim($configFile));
    $result = carmaja_api_diagnose_environment();
    echo json_encode(
        $result,
        JSON_PRETTY_PRINT
            | JSON_UNESCAPED_SLASHES
            | JSON_UNESCAPED_UNICODE
            | JSON_THROW_ON_ERROR
    ) . PHP_EOL;
    exit(0);
} catch (CarmajaApiException|CarmajaBootstrapException $error) {
    $errorCode = $error->errorCode;
    $fields = $error instanceof CarmajaApiException ? $error->fields : [];
    fwrite(
        STDERR,
        json_encode([
            'ok' => false,
            'error' => [
                'code' => $errorCode,
                'message' => $error->getMessage(),
                'fields' => (object) $fields,
            ],
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL
    );
    exit(5);
} catch (Throwable) {
    fwrite(
        STDERR,
        '{"ok":false,"error":{"code":"diagnostic_failed","message":"Diagnose fehlgeschlagen.","fields":{}}}'
            . PHP_EOL
    );
    exit(5);
}
