<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

define('CARMAJA_BOOTSTRAP_NO_RUN', true);
require_once __DIR__ . '/program/bootstrap.php';
require_once __DIR__ . '/program/ap3-worker.php';
require_once __DIR__ . '/program/ap5-worker.php';

try {
    $config = carmaja_bootstrap_prepare($argv[1] ?? null);
    $commerce = carmaja_bootstrap_commerce($config);
    $stripe = carmaja_bootstrap_stripe($config);
    $stripeResult = (new CarmajaAp3Worker($commerce, $stripe, $config))->run();
    $mailResult = (new CarmajaAp5Worker($commerce, $config))->run();
    $result = [
        'status' => 'completed',
        'processed' => (int) ($stripeResult['processed'] ?? 0)
            + (int) ($mailResult['processed'] ?? 0),
        'stripeProcessed' => (int) ($stripeResult['processed'] ?? 0),
        'mailProcessed' => (int) ($mailResult['processed'] ?? 0),
    ];
    fwrite(STDOUT, json_encode($result, JSON_THROW_ON_ERROR) . PHP_EOL);
    exit(0);
} catch (Throwable $error) {
    fwrite(STDERR, "Commerce worker failed safely.\n");
    exit(1);
}
