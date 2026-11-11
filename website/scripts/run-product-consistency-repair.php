<?php

declare(strict_types=1);

// Temporary private CLI only. No request route and no publisher invocation.
ini_set('display_errors', '0');
set_error_handler(static function (): never { throw new CarmajaProductRepairError('runtime_warning'); });
require_once __DIR__ . '/product-consistency-repair.php';

function repair_tree(string $root, array $exclude = [], ?array $extensions = null): array
{
    cmj_repair_assert(realpath($root) === $root && !is_link($root), 'unsafe_tree');
    $out = [];
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)) as $entry) {
        $relative = substr($entry->getPathname(), strlen($root) + 1);
        foreach ($exclude as $prefix) if ($relative === $prefix || str_starts_with($relative, $prefix . '/')) continue 2;
        cmj_repair_assert(!$entry->isLink(), 'tree_link');
        if (!$entry->isFile()) continue;
        if ($extensions !== null && !in_array(strtolower($entry->getExtension()), $extensions, true)) continue;
        $out[$relative] = hash_file('sha256', $entry->getPathname());
    }
    ksort($out, SORT_STRING);
    return $out;
}
function repair_connect(array $config, string $prefix): PDO
{
    $options = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false, PDO::ATTR_TIMEOUT => 10];
    $ca = $config[$prefix . 'TlsCaPath'] ?? '';
    if (is_string($ca) && $ca !== '') {
        $options[PDO::MYSQL_ATTR_SSL_CA] = $ca; $options[PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT] = true;
    } else {
        $options[PDO::MYSQL_ATTR_SSL_CIPHER] = 'HIGH'; $options[PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT] = false;
    }
    $pdo = new PDO($config[$prefix . 'Dsn'], $config[$prefix . 'User'], $config[$prefix . 'Password'], $options);
    foreach (['Ssl_cipher', 'Ssl_version'] as $name) cmj_repair_assert(trim((string) $pdo->query("SHOW SESSION STATUS LIKE '$name'")->fetch(PDO::FETCH_NUM)[1]) !== '', 'tls_required');
    cmj_repair_assert(str_starts_with((string) $pdo->query('SELECT VERSION()')->fetchColumn(), '8.'), 'mysql_version');
    $pdo->exec("SET time_zone='+00:00'");
    $pdo->exec('SET SESSION innodb_lock_wait_timeout=5');
    return $pdo;
}
function repair_quiescent(PDO $pdo): void
{
    cmj_repair_assert((int) $pdo->query('SELECT COUNT(*) FROM reservations WHERE blocks_stock=1')->fetchColumn() === 0, 'reservations_pending');
    cmj_repair_assert((int) $pdo->query("SELECT COUNT(*) FROM checkout_sagas WHERE state NOT IN ('completed','expired','failed')")->fetchColumn() === 0, 'checkouts_pending');
    cmj_repair_assert((int) $pdo->query("SELECT COUNT(*) FROM payments WHERE status NOT IN ('succeeded','failed','canceled')")->fetchColumn() === 0, 'payments_pending');
}
function repair_protected(PDO $pdo, string $job, array $plan): array
{
    $private = '/home/www/carmaja-private-production'; $shop = '/home/www/carmaja-private-shop';
    $selected = array_column($plan['entries'], 'productId');
    $excludedDrafts = array_map(static fn ($id) => $id . '.json', $selected);
    $tables = $pdo->query('SELECT TABLE_NAME, ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() ORDER BY TABLE_NAME')->fetchAll(PDO::FETCH_ASSOC);
    $data = []; $schema = [];
    foreach ($tables as $entry) {
        $table = $entry['TABLE_NAME'];
        cmj_repair_assert(preg_match('/^[a-zA-Z0-9_]+$/', $table) === 1 && $entry['ENGINE'] === 'InnoDB', 'schema_contract');
        $schema[$table] = hash('sha256', $pdo->query('SHOW CREATE TABLE `' . $table . '`')->fetch(PDO::FETCH_NUM)[1]);
        if ($table === 'worker_leases') continue;
        $hashes = [];
        foreach ($pdo->query('SELECT * FROM `' . $table . '`')->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if ($table === 'commerce_products' && in_array($row['product_id'], $selected, true)) continue;
            foreach ($row as &$value) $value = $value === null ? null : base64_encode((string) $value);
            unset($value); $hashes[] = cmj_repair_digest($row);
        }
        sort($hashes, SORT_STRING); $data[$table] = cmj_repair_digest($hashes);
    }
    return ['tables' => $data, 'schema' => $schema,
        'runtime' => repair_tree($shop . '/config'), 'program' => repair_tree($shop . '/program'),
        'guard' => hash_file('sha256', $shop . '/maintenance/product-cleanup.lock'),
        'drafts' => repair_tree($private . '/drafts', $excludedDrafts),
        'products' => repair_tree($private . '/products'), 'images' => repair_tree($private . '/uploads'),
        'auth' => repair_tree($private . '/auth'), 'audit' => repair_tree($private . '/audit', [basename($job)]),
        'idempotency' => repair_tree($private . '/idempotency'), 'skuCounter' => repair_tree($private . '/sku-counter'),
        'publicApi' => repair_tree('/home/www/carmaja-production-api'),
        'website' => repair_tree('/home/www/carmaja', ['_internal'], ['html','css','js','json','jpg','jpeg','png','svg','webp'])];
}
function repair_output_summary(array $plan, string $status): void
{
    $products = [];
    foreach ($plan['entries'] as $e) $products[] = ['sku' => $e['sku'],
        'fromVersion' => json_decode(base64_decode($e['beforeRaw']), true)['productVersion'],
        'toVersion' => (int) $e['afterRow']['product_version'], 'hashRepair' => $e['hashRepair'],
        'commerceContentCorrection' => $e['businessDifferences']];
    echo json_encode(['status' => $status, 'products' => $products, 'unchanged' => $plan['unchanged'],
        'publication' => false, 'inventoryChanges' => false, 'maintenanceRemains' => true], JSON_THROW_ON_ERROR), "\n";
}
try {
    $stage = dirname(__FILE__); $shop = '/home/www/carmaja-private-shop'; $private = '/home/www/carmaja-private-production';
    cmj_repair_assert(PHP_SAPI === 'cli' && preg_match('#^/home/www/carmaja-private-shop/\.repair-stage-[a-f0-9]{16}$#', $stage) === 1
        && realpath($stage) === $stage && !is_link($stage), 'private_stage_required');
    foreach ([$shop, $private, $private . '/audit', $shop . '/config'] as $path) cmj_repair_assert(realpath($path) === $path && !is_link($path), 'unsafe_root');
    $runtime = $shop . '/config/runtime-config.php';
    cmj_repair_assert(!is_link($runtime) && (fileperms($runtime) & 0777) === 0600, 'runtime_mode');
    $config = require $runtime;
    cmj_repair_assert(($config['environment'] ?? '') === 'production' && ($config['productPrivateDir'] ?? '') === $private, 'environment');
    foreach (['product-api.php', 'product-api-v2.php', 'product-api-v3.php', 'product-api-v4.php'] as $file) require_once $shop . '/program/' . $file;
    cmj_repair_assert(carmaja_product_maintenance_active() && file_get_contents($shop . '/maintenance/product-cleanup.lock') === "product-cleanup-v1\n", 'maintenance_missing');
    $reference = json_decode(file_get_contents($stage . '/hotfix-reference.json'), true, 32, JSON_THROW_ON_ERROR);
    foreach ($reference['files'] as $name => $entry) cmj_repair_assert(hash('sha256', str_replace("\r\n", "\n", file_get_contents($shop . '/program/' . $name))) === $entry['after'], 'hotfix_changed');
    $pdo = repair_connect($config, 'commerce'); $restore = repair_connect($config, 'commerceRestore');
    cmj_repair_assert($pdo->query('SELECT DATABASE()')->fetchColumn() !== $restore->query('SELECT DATABASE()')->fetchColumn(), 'targets_equal');
    cmj_repair_assert((int) $restore->query('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE()')->fetchColumn() === 0, 'restore_not_empty');
    repair_quiescent($pdo);
    $command = $argv[1] ?? ''; $operationId = $argv[2] ?? '';
    cmj_repair_assert(preg_match('/^product-repair-[a-z0-9-]{12,70}$/', $operationId) === 1, 'operation_invalid');
    $job = $private . '/audit/' . $operationId;
    cmj_repair_assert(!is_link($job), 'unsafe_job');
    if ($command === 'prepare') {
        cmj_repair_assert(!file_exists($job), 'job_exists');
        $backupId = $argv[3] ?? '';
        cmj_repair_assert(preg_match('/^[0-9]{8}T[0-9]{6}Z-[a-f0-9]{12}$/', $backupId) === 1, 'backup_id');
        $backup = $shop . '/backups/ready/' . $backupId;
        cmj_repair_assert(is_file($backup . '/ready') && is_file($backup . '/download-ack.json'), 'backup_not_acknowledged');
        $rows = array_column($pdo->query('SELECT * FROM commerce_products')->fetchAll(PDO::FETCH_ASSOC), null, 'product_id');
        $records = []; $seen = []; $allHashErrors = 0; $legacy = 0;
        foreach (glob($private . '/drafts/*.json') as $file) {
            cmj_repair_assert(!is_link($file), 'source_link');
            $raw = file_get_contents($file); $draft = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
            cmj_repair_assert(($draft['environment'] ?? '') === 'production', 'source_environment');
            if (($draft['productModelVersion'] ?? null) !== 3) { $legacy++; continue; }
            if (($draft['sourceHash'] ?? '') !== carmaja_api_v3_product_from_draft($draft)['sourceHash']) $allHashErrors++;
            $sku = $draft['sku'] ?? ''; if ($sku === '') continue;
            cmj_repair_assert(preg_match('/^CP-2026-000[1-9]$/', $sku) === 1 && !isset($seen[$sku]), 'unexpected_sku');
            $seen[$sku] = true;
            cmj_repair_assert(isset($rows[$draft['draftId']]) && basename($file) === $draft['draftId'] . '.json', 'source_identity');
            foreach ($draft['images'] as $image) {
                $path = $image['path'] ?? ''; $root = $private . '/uploads/' . $draft['draftId'];
                cmj_repair_assert(is_string($path) && is_file($path) && !is_link($path) && realpath($path) === $path
                    && str_starts_with($path, $root . '/'), 'image_path');
            }
            $records[] = ['raw' => $raw, 'row' => $rows[$draft['draftId']]];
        }
        cmj_repair_assert(count($rows) === 9 && count($records) === 9 && $allHashErrors === 6 && $legacy === 1, 'candidate_set_changed');
        $public = json_decode(file_get_contents($stage . '/public-reference.json'), true, 64, JSON_THROW_ON_ERROR);
        cmj_repair_assert(($public['version'] ?? null) === 3 && count($public['products'] ?? []) === 6, 'public_reference');
        $plan = cmj_repair_plan($records, $public, $operationId, gmdate('Y-m-d\TH:i:s\Z'));
        cmj_repair_assert(count($plan['entries']) === 8 && $plan['unchanged'] === ['CP-2026-0003'], 'repair_scope');
        $plan['backupId'] = $backupId;
        $plan['protected'] = repair_protected($pdo, $job, $plan);
        cmj_repair_assert(mkdir($job, 0700), 'job_create');
        cmj_repair_atomic($job . '/manifest.json', cmj_repair_json($plan) . "\n", 0600);
        cmj_repair_atomic($job . '/seal.json', cmj_repair_json(['digest' => cmj_repair_digest($plan)]) . "\n", 0600);
        repair_output_summary($plan, 'prepared_no_product_mutation');
    } else {
        cmj_repair_assert(in_array($command, ['dry-run', 'apply', 'verify'], true) && realpath($job) === $job, 'command_or_job');
        foreach (['manifest.json', 'seal.json'] as $file) cmj_repair_assert(!is_link($job . '/' . $file) && (fileperms($job . '/' . $file) & 0777) === 0600, 'job_file_mode');
        $plan = json_decode(file_get_contents($job . '/manifest.json'), true, 128, JSON_THROW_ON_ERROR);
        $seal = json_decode(file_get_contents($job . '/seal.json'), true, 8, JSON_THROW_ON_ERROR);
        cmj_repair_assert(($seal['digest'] ?? '') === cmj_repair_digest($plan), 'manifest_seal');
        cmj_repair_validate_manifest($plan);
        $guard = static function () use ($pdo, $job, $plan, $runtime): void {
            clearstatcache();
            cmj_repair_assert(carmaja_product_maintenance_active() && (fileperms($runtime) & 0777) === 0600, 'maintenance_missing');
            repair_quiescent($pdo);
            cmj_repair_assert(cmj_repair_digest(repair_protected($pdo, $job, $plan)) === cmj_repair_digest($plan['protected']), 'protected_state_changed');
        };
        $guard();
        if ($command === 'apply') {
            $result = cmj_repair_apply($private, $job, $pdo, $plan, $guard);
            $guard(); repair_output_summary($plan, $result['status']);
        } else {
            $files = cmj_repair_file_states($private . '/drafts', $plan); $db = cmj_repair_db_states($pdo, $plan, false);
            if ($command === 'dry-run') cmj_repair_assert(!in_array('after', $files, true) && !in_array('after', $db, true), 'already_started');
            else {
                cmj_repair_assert(!in_array('before', $files, true) && !in_array('before', $db, true), 'repair_incomplete');
                cmj_repair_assert(is_file($job . '/audit.json') && json_decode(file_get_contents($job . '/journal.json'), true)['phase'] === 'complete', 'audit_incomplete');
            }
            repair_output_summary($plan, $command === 'verify' ? 'verified_protected_state_unchanged' : 'dry_run_passed');
        }
    }
} catch (Throwable $error) {
    $code = $error instanceof CarmajaProductRepairError && preg_match('/^[a-z_]+$/', $error->getMessage()) === 1 ? $error->getMessage() : 'runtime_or_database';
    fwrite(STDERR, json_encode(['status' => 'stopped_safely', 'check' => $code, 'line' => $error->getLine()], JSON_THROW_ON_ERROR) . "\n");
    exit(1);
}
