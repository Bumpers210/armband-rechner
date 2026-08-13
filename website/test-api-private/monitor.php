<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

define('CARMAJA_BOOTSTRAP_NO_RUN', true);
require_once __DIR__ . '/program/bootstrap.php';
require_once __DIR__ . '/program/production-monitor.php';

try {
    $command = (string) ($argv[1] ?? '');
    $configPath = $argv[2] ?? null;
    $confirmation = (string) ($argv[3] ?? '');
    if ($command !== 'test-alert'
        || $confirmation !== 'SEND-CARMAJA-PRODUCTION-MONITOR-TEST') {
        throw new CarmajaProductionMonitorException('monitor_command_invalid');
    }
    $config = carmaja_bootstrap_prepare(is_string($configPath) ? $configPath : null);
    $result = (new CarmajaProductionMonitor($config))->sendTestAlert();
    fwrite(STDOUT, json_encode($result, JSON_THROW_ON_ERROR) . PHP_EOL);
    exit(0);
} catch (Throwable) {
    fwrite(STDERR, "Production monitor test failed safely.\n");
    exit(1);
}
