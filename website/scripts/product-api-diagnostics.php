<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once dirname(__DIR__) . '/hosting/_internal/product-api.php';

try {
    $result = carmaja_api_diagnose_environment();
    echo json_encode(
        $result,
        JSON_PRETTY_PRINT
            | JSON_UNESCAPED_SLASHES
            | JSON_UNESCAPED_UNICODE
            | JSON_THROW_ON_ERROR
    ) . PHP_EOL;
    exit(0);
} catch (CarmajaApiException $error) {
    fwrite(
        STDERR,
        json_encode([
            'ok' => false,
            'error' => [
                'code' => $error->errorCode,
                'message' => $error->getMessage(),
                'fields' => (object) $error->fields,
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
