<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

define('CARMAJA_BOOTSTRAP_NO_RUN', true);
require_once __DIR__ . '/program/bootstrap.php';
require_once __DIR__ . '/program/ap3-worker.php';

try {
    $config = carmaja_bootstrap_prepare($argv[1] ?? null);
    $commerce = carmaja_bootstrap_commerce($config);
    $stripe = carmaja_bootstrap_stripe($config);
    $result = (new CarmajaAp3Worker($commerce, $stripe, $config))->run();
    fwrite(STDOUT, json_encode($result, JSON_THROW_ON_ERROR) . PHP_EOL);
    exit(0);
} catch (Throwable $error) {
    fwrite(STDERR, "AP3 worker failed safely.\n");
    exit(1);
}
