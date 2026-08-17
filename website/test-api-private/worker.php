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
require_once __DIR__ . '/program/production-monitor.php';

$config = null;
$commerce = null;
$workerFailed = false;
$monitorFailed = false;
$stripeResult = [];
$mailResult = [];
try {
    $config = carmaja_bootstrap_prepare($argv[1] ?? null);
    $commerce = carmaja_bootstrap_commerce($config);
    $stripe = carmaja_bootstrap_stripe($config);
    $stripeResult = (new CarmajaAp3Worker($commerce, $stripe, $config))->run();
    $mailResult = (new CarmajaAp5Worker($commerce, $config))->run();
} catch (Throwable) {
    $workerFailed = true;
}

$monitorResult = ['status' => 'unavailable', 'issueCodes' => []];
if (is_array($config)) {
    $snapshot = null;
    $runtimeIssues = $workerFailed ? ['worker_execution_failed'] : [];
    if ($commerce instanceof CarmajaCommercePdo) {
        try {
            $snapshot = $commerce->productionMonitorSnapshot();
        } catch (Throwable) {
            $runtimeIssues[] = 'monitor_snapshot_failed';
        }
    }
    $result = [
        'status' => 'completed',
        'processed' => (int) ($stripeResult['processed'] ?? 0)
            + (int) ($mailResult['processed'] ?? 0),
        'stripeProcessed' => (int) ($stripeResult['processed'] ?? 0),
        'mailProcessed' => (int) ($mailResult['processed'] ?? 0),
    ];
    try {
        $monitorResult = (new CarmajaProductionMonitor($config))->run($snapshot, $runtimeIssues);
    } catch (Throwable) {
        $monitorFailed = true;
    }
}

if ($workerFailed || $monitorFailed) {
    fwrite(STDERR, "Commerce worker or production monitor failed safely.\n");
    exit(1);
}

$result['monitorStatus'] = $monitorResult['status'];
fwrite(STDOUT, json_encode($result, JSON_THROW_ON_ERROR) . PHP_EOL);
exit(0);
