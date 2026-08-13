<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/test-api-private/program/production-monitor.php';

final class CarmajaProductionMonitorTestFailure extends RuntimeException
{
}

function monitor_test_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new CarmajaProductionMonitorTestFailure($message);
    }
}

function monitor_test_remove_tree(string $path): void
{
    if (!file_exists($path)) {
        return;
    }
    if (is_file($path) || is_link($path)) {
        @unlink($path);
        return;
    }
    foreach (scandir($path) ?: [] as $entry) {
        if ($entry !== '.' && $entry !== '..') {
            monitor_test_remove_tree($path . DIRECTORY_SEPARATOR . $entry);
        }
    }
    @rmdir($path);
}

function monitor_test_snapshot(array $overrides = []): array
{
    return array_replace([
        'workers' => [
            [
                'worker_name' => 'commerce-v1',
                'last_success_at' => '2026-08-13 18:00:00',
                'success_age_seconds' => 0,
                'last_error' => null,
            ],
            [
                'worker_name' => 'commerce-v1-brevo',
                'last_success_at' => '2026-08-13 18:00:00',
                'success_age_seconds' => 0,
                'last_error' => null,
            ],
        ],
        'webhookDue' => 0,
        'webhookTerminal' => 0,
        'mailDue' => 0,
        'mailTerminal' => 0,
        'metadataDue' => 0,
        'metadataTerminal' => 0,
        'reviewOpen' => 0,
    ], $overrides);
}

$root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'carmaja-production-monitor-' . bin2hex(random_bytes(6));
mkdir($root, 0700, true);
$now = 1786644000;
$sent = [];
$config = [
    'environment' => 'production',
    'privateDir' => $root,
    'monitorEnabled' => true,
    'monitorAlertEmail' => 'operator@example.invalid',
];
$sender = static function (array $notification) use (&$sent): array {
    $sent[] = $notification;
    return ['outcome' => 'sent'];
};
$backup = static fn (): array => [
    'serverBackupOverdue' => false,
    'offsiteDownloadOverdue' => false,
];
$disk = static fn (): array => [
    'totalBytes' => 100 * 1024 * 1024 * 1024,
    'freeBytes' => 80 * 1024 * 1024 * 1024,
];
$clock = static function () use (&$now): int {
    return $now;
};

try {
    $monitor = new CarmajaProductionMonitor(
        $config,
        $sender,
        $backup,
        $disk,
        $clock,
        $root . '/monitor/state.json'
    );

    $healthy = $monitor->run(monitor_test_snapshot());
    monitor_test_assert($healthy['status'] === 'ok', 'Gesunder Zustand wurde nicht erkannt.');
    monitor_test_assert(count($sent) === 0, 'Gesunder Erstlauf darf keine E-Mail senden.');

    $testAlert = $monitor->sendTestAlert();
    monitor_test_assert($testAlert['status'] === 'test_sent', 'Kontrollierte Testwarnung fehlt.');
    monitor_test_assert(count($sent) === 1, 'Testwarnung muss genau eine E-Mail senden.');
    monitor_test_assert(str_contains($sent[0]['subject'], 'Testwarnung'), 'Testwarnungsbetreff fehlt.');

    $warningSnapshot = monitor_test_snapshot(['webhookDue' => 2, 'reviewOpen' => 1]);
    $alert = $monitor->run($warningSnapshot);
    monitor_test_assert($alert['status'] === 'alerted', 'Neue Warnung wurde nicht versendet.');
    monitor_test_assert(count($sent) === 2, 'Neue Warnung muss genau eine E-Mail senden.');
    monitor_test_assert(str_contains($sent[1]['subject'], 'Handlungsbedarf'), 'Warnbetreff fehlt.');

    $unchanged = $monitor->run($warningSnapshot);
    monitor_test_assert($unchanged['status'] === 'warning', 'Unveränderte Warnung wurde nicht gehalten.');
    monitor_test_assert(count($sent) === 2, 'Unveränderte Warnung darf nicht wiederholt werden.');

    $now += 21600;
    $reminder = $monitor->run($warningSnapshot);
    monitor_test_assert($reminder['status'] === 'alerted', 'Sechsstündige Erinnerung fehlt.');
    monitor_test_assert(count($sent) === 3, 'Erinnerung muss genau eine weitere E-Mail senden.');
    monitor_test_assert(str_starts_with($sent[2]['subject'], 'Erinnerung:'), 'Erinnerungsbetreff fehlt.');

    $recovery = $monitor->run(monitor_test_snapshot());
    monitor_test_assert($recovery['status'] === 'recovered', 'Entwarnung wurde nicht versendet.');
    monitor_test_assert(count($sent) === 4, 'Entwarnung muss genau eine E-Mail senden.');
    monitor_test_assert(str_contains($sent[3]['subject'], 'Entwarnung'), 'Entwarnungsbetreff fehlt.');

    $issues = CarmajaProductionMonitor::evaluate(monitor_test_snapshot([
        'workers' => [[
            'worker_name' => 'commerce-v1',
            'last_success_at' => '2026-08-13 17:00:00',
            'success_age_seconds' => 901,
            'last_error' => 'synthetic',
        ]],
        'mailTerminal' => 3,
        'metadataDue' => 2,
    ]), ['worker_execution_failed']);
    foreach ([
        'worker_execution_failed',
        'worker_stripe_stale',
        'worker_stripe_error',
        'worker_mail_missing',
        'mail_terminal',
        'stripe_metadata_backlog',
    ] as $expected) {
        monitor_test_assert(isset($issues[$expected]), 'Erwartete Warnung fehlt: ' . $expected);
    }

    $disabledConfig = $config;
    $disabledConfig['monitorEnabled'] = false;
    $disabled = (new CarmajaProductionMonitor($disabledConfig))->run(null);
    monitor_test_assert($disabled['status'] === 'disabled', 'Deaktiviertes Monitoring ist nicht fail-closed.');

    fwrite(STDOUT, "production-monitor-test: ok\n");
} finally {
    monitor_test_remove_tree($root);
}
