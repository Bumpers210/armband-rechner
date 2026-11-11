<?php

declare(strict_types=1);

// Private operator verification; no product/database mutation and no secret output.
ini_set('display_errors', '0');
set_error_handler(static function (): never { throw new RuntimeException('verification_warning'); });
function cmj_verify_tree(string $root, ?array $extensions = null): array
{
    if (realpath($root) !== $root || is_link($root)) throw new RuntimeException('unsafe_root');
    $files = [];
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $entry) {
        if ($entry->isLink()) throw new RuntimeException('unexpected_link');
        if (!$entry->isFile()) continue;
        $relative = substr($entry->getPathname(), strlen($root) + 1);
        if ($extensions !== null && (str_starts_with($relative, '_internal/')
            || !in_array(strtolower($entry->getExtension()), $extensions, true))) continue;
        $files[$relative] = hash_file('sha256', $entry->getPathname());
    }
    ksort($files, SORT_STRING);
    return $files;
}
function cmj_verify_connection(array $config, string $prefix): PDO
{
    $options = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false, PDO::ATTR_TIMEOUT => 8];
    $ca = $config[$prefix . 'TlsCaPath'] ?? '';
    if ($ca !== '') {
        $options[PDO::MYSQL_ATTR_SSL_CA] = $ca;
        $options[PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT] = true;
    } else {
        // Explicitly accepted V1 risk: encrypted session, not verified host identity.
        $options[PDO::MYSQL_ATTR_SSL_CIPHER] = 'HIGH';
        $options[PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT] = false;
    }
    $pdo = new PDO($config[$prefix . 'Dsn'], $config[$prefix . 'User'], $config[$prefix . 'Password'], $options);
    foreach (['Ssl_cipher', 'Ssl_version'] as $setting) {
        if (trim((string) $pdo->query("SHOW SESSION STATUS LIKE '$setting'")->fetch(PDO::FETCH_NUM)[1]) === '') throw new RuntimeException('tls_required');
    }
    if (!str_starts_with((string) $pdo->query('SELECT VERSION()')->fetchColumn(), '8.')) throw new RuntimeException('mysql_version');
    return $pdo;
}
try {
    $base = '/home/www/carmaja-private-shop';
    $stage = dirname(__FILE__);
    if (preg_match('#^/home/www/carmaja-private-shop/\.hashfix-stage-[a-f0-9]{16}$#', $stage) !== 1 || realpath($stage) !== $stage) throw new RuntimeException('private_stage_required');
    $mode = $argv[1] ?? '';
    if (!in_array($mode, ['before', 'after'], true)) throw new RuntimeException('mode_invalid');
    $runtime = $base . '/config/runtime-config.php';
    if (is_link($runtime) || (fileperms($runtime) & 0777) !== 0600) throw new RuntimeException('runtime_mode');
    $config = require $runtime;
    if (($config['environment'] ?? '') !== 'production' || ($config['productPrivateDir'] ?? '') !== '/home/www/carmaja-private-production') throw new RuntimeException('environment');
    $source = cmj_verify_connection($config, 'commerce');
    $restore = cmj_verify_connection($config, 'commerceRestore');
    if ($source->query('SELECT DATABASE()')->fetchColumn() === $restore->query('SELECT DATABASE()')->fetchColumn()) throw new RuntimeException('targets_equal');
    if ((int) $restore->query('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE()')->fetchColumn() !== 0) throw new RuntimeException('restore_not_empty');
    $tables = $source->query('SELECT TABLE_NAME, ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() ORDER BY TABLE_NAME')->fetchAll(PDO::FETCH_ASSOC);
    $database = []; $counts = [];
    $source->exec('SET TRANSACTION READ ONLY');
    $source->beginTransaction();
    foreach ($tables as $entry) {
        $table = $entry['TABLE_NAME'];
        if (preg_match('/^[a-zA-Z0-9_]+$/', $table) !== 1 || $entry['ENGINE'] !== 'InnoDB') throw new RuntimeException('table_contract');
        if ($table === 'worker_leases') continue; // Existing worker must remain operational.
        $rows = $source->query('SELECT * FROM `' . $table . '`')->fetchAll(PDO::FETCH_ASSOC);
        $encoded = array_map(static function (array $row): string {
            ksort($row, SORT_STRING);
            foreach ($row as &$value) $value = $value === null ? null : base64_encode((string) $value);
            unset($value);
            return hash('sha256', json_encode($row, JSON_THROW_ON_ERROR));
        }, $rows);
        sort($encoded, SORT_STRING);
        $database[$table] = hash('sha256', json_encode($encoded, JSON_THROW_ON_ERROR));
        $counts[$table] = count($rows);
    }
    $source->commit();
    $snapshot = ['database' => $database, 'config' => cmj_verify_tree($base . '/config'), 'products' => [],
        'publicApi' => cmj_verify_tree('/home/www/carmaja-production-api'),
        'websiteAssets' => cmj_verify_tree('/home/www/carmaja', ['html', 'css', 'js', 'json', 'jpg', 'jpeg', 'png', 'webp', 'svg'])];
    foreach (['drafts', 'products', 'uploads', 'sku-counter', 'idempotency'] as $directory) {
        $snapshot['products'][$directory] = cmj_verify_tree('/home/www/carmaja-private-production/' . $directory);
    }
    $manifest = json_decode(file_get_contents($stage . '/manifest.json'), true, 16, JSON_THROW_ON_ERROR);
    $snapshot['otherPrograms'] = cmj_verify_tree($base . '/program');
    foreach (array_keys($manifest['files']) as $name) unset($snapshot['otherPrograms'][$name]);
    $binding = $stage . '/protected-before.json';
    if ($mode === 'before') {
        if (file_exists($binding)) throw new RuntimeException('snapshot_exists');
        foreach ($manifest['files'] as $name => $entry) {
            $path = $base . '/program/' . $name;
            $actual = file_exists($path) ? hash('sha256', str_replace("\r\n", "\n", file_get_contents($path))) : null;
            if ($actual !== $entry['before']) throw new RuntimeException('baseline_changed');
        }
        $handle = fopen($binding, 'x'); chmod($binding, 0600);
        fwrite($handle, json_encode($snapshot, JSON_THROW_ON_ERROR)); fclose($handle);
        echo json_encode(['status' => 'protected_baseline_bound', 'sourceTls' => true, 'restoreTls' => true, 'restoreEmpty' => true, 'innoDbTables' => count($tables), 'protectedTableCount' => count($counts)], JSON_THROW_ON_ERROR), "\n";
    } else {
        $before = json_decode(file_get_contents($binding), true, 64, JSON_THROW_ON_ERROR);
        $results = [];
        foreach ($snapshot as $name => $value) $results[$name . 'Unchanged'] = ($before[$name] ?? null) === $value;
        foreach ($manifest['files'] as $name => $entry) {
            $actual = hash('sha256', str_replace("\r\n", "\n", file_get_contents($base . '/program/' . $name)));
            if ($actual !== $entry['after']) throw new RuntimeException('artifact_mismatch');
        }
        $results['runtimeMode0600'] = (fileperms($runtime) & 0777) === 0600;
        $results['maintenanceActive'] = is_file($base . '/maintenance/product-cleanup.lock');
        $results['restoreEmpty'] = true;
        $results['sourceAndRestoreTls'] = true;
        $results['allPassed'] = !in_array(false, $results, true);
        echo json_encode($results, JSON_THROW_ON_ERROR), "\n";
        if (!$results['allPassed']) exit(2);
    }
} catch (Throwable $error) {
    $safeCodes = ['verification_warning', 'unsafe_root', 'unexpected_link', 'tls_required', 'mysql_version',
        'private_stage_required', 'mode_invalid', 'runtime_mode', 'environment', 'targets_equal',
        'restore_not_empty', 'table_contract', 'snapshot_exists', 'baseline_changed', 'artifact_mismatch'];
    fwrite(STDERR, json_encode(['status' => 'verification_failed_no_values_disclosed',
        'check' => in_array($error->getMessage(), $safeCodes, true) ? $error->getMessage() : 'runtime_or_database',
        'line' => $error->getLine()], JSON_THROW_ON_ERROR) . "\n");
    exit(1);
}
