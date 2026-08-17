<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}

define('CARMAJA_BOOTSTRAP_NO_RUN', true);
require_once __DIR__ . '/bootstrap.php';

try {
    $config = carmaja_bootstrap_prepare();
    $commerce = carmaja_bootstrap_commerce($config);
    $result = (new CarmajaAp5Worker($commerce, $config))->run();
    fwrite(STDOUT, json_encode($result, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL);
    exit(0);
} catch (Throwable $error) {
    // Never print DSNs, credentials or provider payloads from a worker.
    fwrite(STDERR, 'AP5 worker failed: ' . $error->getMessage() . PHP_EOL);
    exit(1);
}
