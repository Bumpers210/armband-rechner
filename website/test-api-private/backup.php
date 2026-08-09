<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

ini_set('display_errors', '0');
require_once __DIR__ . '/program/production-backup.php';

set_error_handler(static function (int $severity): bool {
    if ((error_reporting() & $severity) === 0) {
        return false;
    }
    throw new CarmajaProductionBackupException('backup_runtime_warning');
});

try {
    $command = $argv[1] ?? '';
    if ($command === 'create') {
        set_time_limit(40);
    }
    $runtimePath = $argv[2] ?? '';
    $config = CarmajaProductionBackup::loadRuntime($runtimePath);
    $backup = new CarmajaProductionBackup($config);
    $result = match ($command) {
        'create' => $backup->create(),
        'list-ready' => ['status' => 'ok', 'backups' => $backup->listReady()],
        'acknowledge' => $backup->acknowledge((string) ($argv[3] ?? ''), (string) ($argv[4] ?? '')),
        'restore-dry-run' => $backup->restoreDryRun((string) ($argv[3] ?? '')),
        'status' => $backup->status(),
        default => throw new CarmajaProductionBackupException('backup_command_invalid'),
    };
    fwrite(STDOUT, json_encode($result, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL);
    exit(0);
} catch (Throwable $error) {
    $code = $error instanceof CarmajaProductionBackupException ? $error->errorCode : 'backup_failed_safely';
    fwrite(STDERR, json_encode(['status' => 'failed', 'code' => $code], JSON_THROW_ON_ERROR) . PHP_EOL);
    exit(1);
}
