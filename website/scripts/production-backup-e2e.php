<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

final class CarmajaBackupE2eException extends RuntimeException
{
}

function e2e_assert(bool $condition, string $code): void
{
    if (!$condition) {
        throw new CarmajaBackupE2eException($code);
    }
}

function e2e_pdo(array $config, bool $restore): PDO
{
    $prefix = $restore ? 'commerceRestore' : 'commerce';
    $options = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false];
    $ca = $config[$prefix . 'TlsCaPath'] ?? null;
    if (is_string($ca) && $ca !== '') {
        $options[PDO::MYSQL_ATTR_SSL_CA] = $ca;
        $options[PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT] = true;
    } else {
        if (defined('PDO::MYSQL_ATTR_SSL_CIPHER')) {
            $options[PDO::MYSQL_ATTR_SSL_CIPHER] = 'HIGH';
        }
        $options[PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT] = false;
    }
    $pdo = new PDO($config[$prefix . 'Dsn'], $config[$prefix . 'User'], $config[$prefix . 'Password'], $options);
    $tls = $pdo->query("SHOW SESSION STATUS LIKE 'Ssl_cipher'")->fetch(PDO::FETCH_ASSOC);
    e2e_assert(is_array($tls) && trim((string) ($tls['Value'] ?? '')) !== '', 'tls_inactive');
    return $pdo;
}

function e2e_tables(PDO $pdo): array
{
    return $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
}

function e2e_clear(PDO $pdo): void
{
    $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
    try {
        foreach (e2e_tables($pdo) as $table) {
            e2e_assert(is_string($table) && preg_match('/^[A-Za-z0-9_]+$/', $table) === 1, 'unsafe_table');
            $pdo->exec('DROP TABLE `' . $table . '`');
        }
    } finally {
        $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
    }
}

function e2e_remove_tree(string $path): void
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
            e2e_remove_tree($path . '/' . $entry);
        }
    }
    @rmdir($path);
}

$runtimePath = $argv[1] ?? '';
$programPath = $argv[2] ?? '';
$config = [];
$source = null;
$restore = null;
$cleanupAuthorized = false;
$runtimeAuthorized = false;
$dataOwned = false;
$privateRoot = '/home/www/carmaja-private-test/ap77-backup-e2e-data';
$result = ['status' => 'failed'];

try {
    e2e_assert($runtimePath === '/home/www/carmaja-private-test/ap77-backup-e2e.php', 'runtime_path_invalid');
    $runtimeAuthorized = true;
    e2e_assert($programPath === '/home/www/carmaja-private-test/ap77-backup-e2e-stage/program/production-backup.php', 'program_path_invalid');
    e2e_assert(is_file($programPath), 'program_missing');
    e2e_assert(!file_exists($privateRoot), 'private_root_not_clean');
    $dataOwned = true;
    require_once $programPath;
    $config = CarmajaProductionBackup::loadRuntime($runtimePath);
    e2e_assert(($config['environment'] ?? null) === 'test' && ($config['backupTestMode'] ?? null) === true, 'test_mode_missing');
    e2e_assert(($config['privateDir'] ?? null) === $privateRoot, 'private_root_invalid');
    $source = e2e_pdo($config, false);
    $restore = e2e_pdo($config, true);
    e2e_assert(e2e_tables($source) === [], 'source_not_empty');
    e2e_assert(e2e_tables($restore) === [], 'restore_not_empty');
    $cleanupAuthorized = true;

    $source->exec('CREATE TABLE schema_migrations (migration_id VARCHAR(100) PRIMARY KEY, checksum CHAR(64) NOT NULL) ENGINE=InnoDB');
    $source->exec("INSERT INTO schema_migrations (migration_id, checksum) VALUES ('ap77-backup-e2e-v1', REPEAT('a', 64))");
    $source->exec('CREATE TABLE ap77_backup_items (id BIGINT PRIMARY KEY, value_text VARCHAR(120) NOT NULL) ENGINE=InnoDB');
    $insert = $source->prepare('INSERT INTO ap77_backup_items (id, value_text) VALUES (?, ?)');
    for ($id = 1; $id <= 25; $id++) {
        $insert->execute([$id, 'artificial-backup-row-' . $id]);
    }

    $productRoot = $privateRoot . '/product-source';
    mkdir($productRoot . '/products', 0700, true);
    mkdir($productRoot . '/drafts', 0700, true);
    mkdir($productRoot . '/uploads/AP77-E2E', 0700, true);
    file_put_contents($productRoot . '/environment.json', "{\"environment\":\"test\"}\n");
    file_put_contents($productRoot . '/products/AP77-E2E.json', "{\"productId\":\"AP77-E2E\",\"value\":\"artificial\"}\n");
    file_put_contents($productRoot . '/drafts/AP77-E2E.json', "{\"draftId\":\"AP77-E2E\"}\n");
    file_put_contents($productRoot . '/uploads/AP77-E2E/01.jpg', random_bytes(2048));
    file_put_contents($productRoot . '/sku-counter', "1\n");

    $orphan = $privateRoot . '/backups/.staging/20000101T000000Z-aaaaaaaaaaaa';
    mkdir($orphan, 0700, true);
    file_put_contents($orphan . '/mysql-source.cnf', "artificial-orphan\n");
    $backup = new CarmajaProductionBackup($config);
    $created = $backup->create();
    e2e_assert(!file_exists($orphan), 'crash_cleanup_failed');
    $backupId = (string) ($created['backupId'] ?? '');
    e2e_assert(preg_match('/^[0-9]{8}T[0-9]{6}Z-[a-f0-9]{12}$/', $backupId) === 1, 'backup_id_invalid');
    $ready = $backup->listReady();
    e2e_assert(count($ready) === 1 && ($ready[0]['backupId'] ?? null) === $backupId, 'ready_list_invalid');

    $lockPath = $privateRoot . '/backups/locks/backup.lock';
    $lock = fopen($lockPath, 'c');
    e2e_assert(is_resource($lock) && flock($lock, LOCK_EX | LOCK_NB), 'test_lock_failed');
    try {
        try {
            $backup->create();
            throw new CarmajaBackupE2eException('parallel_lock_not_enforced');
        } catch (CarmajaProductionBackupException $error) {
            e2e_assert($error->errorCode === 'backup_already_running', 'parallel_lock_wrong_error');
        }
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }

    $restored = $backup->restoreDryRun($backupId);
    e2e_assert(($restored['status'] ?? null) === 'restored_and_compared', 'restore_compare_failed');
    e2e_assert(e2e_tables($restore) === [], 'restore_cleanup_failed');
    $backup->acknowledge($backupId, (string) $created['manifestSha256']);
    $secondAck = $backup->acknowledge($backupId, (string) $created['manifestSha256']);
    e2e_assert(($secondAck['idempotent'] ?? null) === true, 'ack_idempotency_failed');
    $status = $backup->status();
    e2e_assert(($status['status'] ?? null) === 'ok', 'status_not_ok');

    foreach (['product-data.tar.gz.cmjbkp', 'commerce.sql.gz.cmjbkp'] as $artifactName) {
        $artifact = file_get_contents($privateRoot . '/backups/ready/' . $backupId . '/' . $artifactName);
        e2e_assert(is_string($artifact)
            && !str_contains($artifact, 'artificial-backup-row')
            && !str_contains($artifact, 'AP77-E2E'), 'plaintext_leak_detected');
    }
    $result = ['status' => 'passed', 'backupId' => $backupId, 'tls' => 'active', 'cleanup' => 'pending'];
} finally {
    try {
        if ($cleanupAuthorized && $source instanceof PDO) {
            e2e_clear($source);
        }
    } finally {
        try {
            if ($cleanupAuthorized && $restore instanceof PDO) {
                e2e_clear($restore);
            }
        } finally {
            if ($dataOwned) {
                e2e_remove_tree($privateRoot);
            }
            if ($runtimeAuthorized) {
                @unlink($runtimePath);
            }
        }
    }
}

$result['cleanup'] = 'complete';
fwrite(STDOUT, json_encode($result, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL);
