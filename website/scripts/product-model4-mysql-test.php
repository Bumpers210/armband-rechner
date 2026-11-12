<?php

declare(strict_types=1);

// Explicit, restore-only Linux proof. No web route, source connection or secret discovery.
ini_set('display_errors', '0');
ini_set('log_errors', '0');
ini_set('zend.exception_ignore_args', '1');
define('CMJ_MODEL4_TEST_HELPERS_ONLY', true);
require_once dirname(__DIR__) . '/tests/php/product-model4-migration-test.php';

const M4X_CONFIG = '/home/www/carmaja-private-test/shop-cart-test.json';
const M4X_TABLES = ['checkout_sagas', 'commerce_inventory', 'commerce_products', 'legal_bundles', 'orders', 'schema_migrations', 'm4_probe_deadlock'];

function m4x_config(): array
{
    cmj_model4_require(PHP_OS_FAMILY === 'Linux' && PHP_MAJOR_VERSION === 8 && PHP_MINOR_VERSION === 4, 'linux_php84_required');
    cmj_m4_path(M4X_CONFIG);
    cmj_model4_require((fileperms(M4X_CONFIG) & 0777) === 0600, 'private_config_mode');
    $c = json_decode(file_get_contents(M4X_CONFIG), true, 32, JSON_THROW_ON_ERROR);
    cmj_model4_require(($c['testOnly'] ?? null) === true && ($c['requireTls'] ?? null) === true
        && ($c['source']['database'] ?? '') === 'dbs15962244' && ($c['restore']['database'] ?? '') === 'dbs15962566'
        && $c['source']['username'] !== $c['restore']['username'], 'test_identity_mismatch');
    foreach (['host', 'database', 'username', 'password'] as $key) {
        $v = $c['restore'][$key] ?? null;
        cmj_model4_require(is_string($v) && $v !== '' && strpbrk($v, "\r\n\0") === false
            && ($key === 'password' || !str_contains($v, ';')), 'invalid_restore_field');
    }
    cmj_model4_require(is_int($c['restore']['port'] ?? null) && $c['restore']['port'] > 0 && $c['restore']['port'] < 65536, 'invalid_port');
    return $c;
}

final class M4ProbePDO extends PDO
{
    public ?string $beforeRowLockMarker = null;
    public bool $loseCommitReply = false;
    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false
    {
        if ($this->beforeRowLockMarker !== null && str_contains($query, 'FOR UPDATE')) {
            m4x_mark($this->beforeRowLockMarker, 'ready'); $this->beforeRowLockMarker = null;
        }
        return $fetchMode === null ? parent::query($query) : parent::query($query, $fetchMode, ...$fetchModeArgs);
    }
    public function commit(): bool
    {
        $result = parent::commit();
        if ($this->loseCommitReply) { $this->loseCommitReply = false; throw new RuntimeException('commit_reply_lost'); }
        return $result;
    }
}

function m4x_connect(): M4ProbePDO
{
    $c = m4x_config(); $t = $c['restore']; $ca = $c['tlsCaPath'] ?? '';
    $options = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false, PDO::ATTR_TIMEOUT => 10,
        PDO::MYSQL_ATTR_SSL_CIPHER => 'HIGH', PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT => $ca !== ''];
    if ($ca !== '') { cmj_model4_require(is_file($ca), 'ca_missing'); $options[PDO::MYSQL_ATTR_SSL_CA] = $ca; }
    else cmj_model4_require(($c['acceptUnverifiedTls'] ?? null) === true, 'tls_risk_not_accepted');
    $pdo = new M4ProbePDO(sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $t['host'], $t['port'], $t['database']), $t['username'], $t['password'], $options);
    $tls = $pdo->query("SHOW SESSION STATUS WHERE Variable_name IN ('Ssl_cipher','Ssl_version')")->fetchAll(PDO::FETCH_KEY_PAIR);
    cmj_model4_require(($tls['Ssl_cipher'] ?? '') !== '' && ($tls['Ssl_version'] ?? '') !== '', 'tls_required');
    cmj_model4_require(str_starts_with((string) $pdo->query('SELECT VERSION()')->fetchColumn(), '8.'), 'mysql8_required');
    cmj_model4_require((int) $pdo->query("SELECT COUNT(*) FROM information_schema.ENGINES WHERE ENGINE='InnoDB' AND SUPPORT IN ('YES','DEFAULT')")->fetchColumn() === 1, 'innodb_required');
    $pdo->exec("SET time_zone='+00:00', sql_mode='STRICT_ALL_TABLES', innodb_lock_wait_timeout=3, lock_wait_timeout=3");
    return $pdo;
}

function m4x_no_auxiliary_objects(PDO $pdo): void
{
    foreach (['ROUTINES' => 'ROUTINE_SCHEMA', 'EVENTS' => 'EVENT_SCHEMA', 'TRIGGERS' => 'TRIGGER_SCHEMA'] as $table => $column) {
        cmj_model4_require((int) $pdo->query("SELECT COUNT(*) FROM information_schema.$table WHERE $column=DATABASE()")->fetchColumn() === 0, 'unexpected_database_object');
    }
}

function m4x_owner(PDO $pdo, string $root): void
{
    cmj_model4_require($pdo->query('SELECT owner FROM m4_probe_owner')->fetchAll(PDO::FETCH_COLUMN) === [basename($root)], 'database_owner_mismatch');
}

function m4x_drop(PDO $pdo, string $root, bool $owner = false): void
{
    m4x_owner($pdo, $root); m4x_no_auxiliary_objects($pdo);
    $tables = $pdo->query('SELECT TABLE_NAME,TABLE_COMMENT FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE()')->fetchAll(PDO::FETCH_KEY_PAIR);
    foreach ($tables as $table => $tag) cmj_model4_require(in_array($table, [...M4X_TABLES, 'm4_probe_owner'], true)
        && $tag === CMJ_MODEL4_TEST_TABLE_TAG, 'foreign_table_preserved');
    foreach (M4X_TABLES as $table) if (isset($tables[$table])) $pdo->exec('DROP TABLE `' . $table . '`');
    if ($owner) $pdo->exec('DROP TABLE m4_probe_owner');
}

function m4x_fixture(string $root, string $name, PDO $pdo): array
{
    m4x_drop($pdo, $root);
    return m4t_fixture($root . '/cases', $name, $pdo);
}

function m4x_mark(string $file, string $value): void
{
    $h = fopen($file, 'x'); cmj_model4_require(is_resource($h), 'marker_exists');
    chmod($file, 0600); fwrite($h, $value); fflush($h); fsync($h); fclose($h);
}

function m4x_wait(string $file, int $seconds = 12): void
{
    $end = microtime(true) + $seconds;
    do { clearstatcache(true, $file); if (is_file($file) && !is_link($file)) return; usleep(20000); } while (microtime(true) < $end);
    throw new RuntimeException('child_marker_timeout');
}

function m4x_spawn(array $f, array $job, array &$children): int
{
    $prefix = $f['private'] . '/client-' . bin2hex(random_bytes(4));
    $job = [...$job, 'private' => $f['private'], 'marker' => $prefix . '.ready', 'release' => $prefix . '.go', 'result' => $prefix . '.result'];
    m4x_mark($prefix . '.json', cmj_model4_json($job));
    $process = proc_open([PHP_BINARY, '-d', 'display_errors=0', '-d', 'log_errors=0', __FILE__, '--child', $prefix . '.json'],
        [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
    cmj_model4_require(is_resource($process), 'child_start_failed');
    $children[] = ['process' => $process, 'job' => $job];
    return array_key_last($children);
}

function m4x_join(array &$child, bool $kill = false): array
{
    if ($kill) cmj_model4_require(proc_terminate($child['process'], 9), 'child_kill_failed');
    $end = microtime(true) + 20;
    do {
        $state = proc_get_status($child['process']);
        if (!$state['running']) break;
        usleep(20000);
    } while (microtime(true) < $end);
    cmj_model4_require(!$state['running'], 'child_exit_timeout');
    proc_close($child['process']); $child['process'] = null;
    if ($kill) { m4t_check($state['signaled'] && $state['termsig'] === 9, 'real_sigkill'); return ['status' => 'killed']; }
    cmj_model4_require(is_file($child['job']['result']), 'child_result_missing');
    return json_decode(file_get_contents($child['job']['result']), true, 16, JSON_THROW_ON_ERROR);
}

function m4x_child(string $root, string $file): void
{
    cmj_m4_path($file);
    cmj_model4_require(str_starts_with($file, $root . '/cases/') && preg_match('/\/client-[a-f0-9]{8}\.json$/', $file) === 1
        && (fileperms($file) & 0777) === 0600, 'invalid_child_job');
    $job = json_decode(file_get_contents($file), true, 16, JSON_THROW_ON_ERROR);
    cmj_model4_require(dirname($file) === $job['private'], 'invalid_child_path');
    foreach (['marker', 'release', 'result'] as $key) cmj_model4_require(dirname($job[$key]) === $job['private'], 'invalid_child_path');
    $pdo = m4x_connect(); m4x_owner($pdo, $root);
    $plan = json_decode(file_get_contents($job['private'] . '/audit/model4-artificial-20261112/manifest.json'), true, 128, JSON_THROW_ON_ERROR);
    $f = ['private' => $job['private'], 'pdo' => $pdo, 'digest' => cmj_model4_digest($plan)];
    if (($job['mode'] ?? '') === 'deadlock') $pdo->beforeRowLockMarker = $job['marker'];
    try {
        m4t_adapter($f, static function (string $point) use ($job, $pdo): void {
            if ($point !== ($job['point'] ?? null)) return;
            m4x_mark($job['marker'], (string) $pdo->query('SELECT CONNECTION_ID()')->fetchColumn());
            m4x_wait($job['release'], 15);
        })->run();
        $result = ['status' => 'complete'];
    } catch (Throwable $e) {
        $result = ['status' => 'failed', 'reason' => $e instanceof PDOException ? 'pdo_failure'
            : (preg_match('/^[a-z_]+$/', $e->getMessage()) ? $e->getMessage() : 'test_failure'),
            'driverCode' => $e instanceof PDOException ? (int) ($e->errorInfo[1] ?? 0) : null];
    }
    m4x_mark($job['result'], cmj_model4_json($result));
}

if (!in_array($argv[1] ?? '', ['--run', '--child'], true)) {
    echo "NOT RUN: explicit isolated Linux/restore fixture required.\n"; exit;
}

$pdo = null; $peer = null; $owned = false; $children = []; $report = []; $failed = false;
try {
    $root = cmj_m4_path(dirname(__DIR__, 2), true);
    cmj_model4_require(preg_match('#^/home/www/carmaja-private-test/model4-proof-[0-9]{8}-[a-f0-9]{8}$#', $root) === 1
        && file_get_contents(cmj_m4_path($root . '/.owner')) === basename($root)
        && (fileperms($root) & 0777) === 0700, 'isolated_root_required');
    define('CMJ_MODEL4_TEST_TABLE_TAG', 'cmj_model4_' . substr(hash('sha256', basename($root)), 0, 16));
    if ($argv[1] === '--child') { m4x_child($root, $argv[2] ?? ''); exit; }
    $configHash = hash_file('sha256', M4X_CONFIG);
    $pdo = m4x_connect();
    cmj_model4_require((int) $pdo->query("SELECT GET_LOCK('cmj_model4_restore_test',0)")->fetchColumn() === 1, 'restore_target_busy');
    cmj_model4_require($pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) === [], 'restore_not_empty');
    m4x_no_auxiliary_objects($pdo);
    $report = ['php' => PHP_VERSION, 'os' => PHP_OS_FAMILY, 'mysql' => $pdo->query('SELECT VERSION()')->fetchColumn(),
        'tlsActive' => true, 'caIdentityVerified' => (m4x_config()['tlsCaPath'] ?? '') !== '',
        'sourceConnected' => false, 'productionAccess' => false, 'cases' => []];
    $pdo->exec("CREATE TABLE m4_probe_owner (owner VARCHAR(100) NOT NULL) ENGINE=InnoDB COMMENT='" . CMJ_MODEL4_TEST_TABLE_TAG . "'");
    $s = $pdo->prepare('INSERT INTO m4_probe_owner VALUES (?)'); $s->execute([basename($root)]); $s = null; $owned = true;
    mkdir($root . '/cases', 0700);
    $id = '10000000-0000-4000-8000-000000000002'; $second = '10000000-0000-4000-8000-000000000008';
    $f = m4x_fixture($root, 'normal', $pdo); m4t_adapter($f)->run(); m4t_complete($f);
    m4t_adapter($f)->run(); m4t_complete($f);
    m4t_reject(fn () => m4t_adapter($f)->run(true), 'rollback_after_commit_forbidden');
    $report['cases'][] = 'real_schema_commit_idempotency_and_preservation';

    $events = ['next_written:state', 'before_rename:source:' . $id, 'after_rename:source:' . $id,
        'sql:' . $id, 'before_commit', 'after_commit', 'next_written:audit'];
    foreach ($events as $index => $event) {
        $f = m4x_fixture($root, 'sigkill-' . $index, $pdo);
        $n = m4x_spawn($f, ['mode' => 'pause', 'point' => $event], $children);
        m4x_wait($children[$n]['job']['marker']); m4x_join($children[$n], true);
        // The server releases the killed process's connection/transaction asynchronously.
        $end = microtime(true) + 5;
        do { $actual = cmj_m4_rows($pdo); if ($actual === $f['plan']['beforeRows'] || in_array($event, ['after_commit', 'next_written:audit'], true)) break; usleep(20000); } while (microtime(true) < $end);
        m4t_check($actual === $f['plan'][in_array($event, ['after_commit', 'next_written:audit'], true) ? 'afterRows' : 'beforeRows'], 'sigkill_atomic_database');
        m4t_adapter($f)->run(); m4t_complete($f);
    }
    $report['cases'][] = 'seven_sigkill_points_and_resume';

    $f = m4x_fixture($root, 'row-timeout', $pdo); $peer = m4x_connect(); $peer->beginTransaction();
    $peer->query("SELECT product_id FROM commerce_products WHERE product_id='$id' FOR UPDATE")->fetchAll();
    $start = microtime(true);
    try { m4t_adapter($f)->run(); throw new RuntimeException('expected_lock_timeout'); }
    catch (PDOException $e) { m4t_check((int) ($e->errorInfo[1] ?? 0) === 1205, 'innodb_lock_timeout'); }
    m4t_check(microtime(true) - $start < 10 && !$pdo->inTransaction(), 'bounded_lock_wait_and_rollback');
    $peer->rollBack(); $peer = null;
    m4t_check(cmj_m4_rows($pdo) === $f['plan']['beforeRows'], 'timeout_no_partial_sql');
    m4t_adapter($f)->run(); m4t_complete($f); $report['cases'][] = 'innodb_row_lock_timeout_1205';

    $f = m4x_fixture($root, 'deadlock', $pdo);
    $pdo->exec("CREATE TABLE m4_probe_deadlock (id INT PRIMARY KEY, value INT NOT NULL) ENGINE=InnoDB COMMENT='" . CMJ_MODEL4_TEST_TABLE_TAG . "'");
    for ($i = 0; $i < 100; $i++) $pdo->exec("INSERT INTO m4_probe_deadlock VALUES ($i,0)");
    $peer = m4x_connect(); $peer->beginTransaction();
    $peer->exec('UPDATE m4_probe_deadlock SET value=1');
    $peer->query("SELECT product_id FROM commerce_products WHERE product_id='$second' FOR UPDATE")->fetchAll();
    $n = m4x_spawn($f, ['mode' => 'deadlock'], $children); m4x_wait($children[$n]['job']['marker']); usleep(200000);
    $parentDeadlock = false;
    try { $peer->query("SELECT product_id FROM commerce_products WHERE product_id='$id' FOR UPDATE")->fetchAll(); }
    catch (PDOException $e) { m4t_check((int) ($e->errorInfo[1] ?? 0) === 1213, 'parent_deadlock'); $parentDeadlock = true; }
    if ($peer->inTransaction()) $peer->rollBack(); $peer = null;
    $result = m4x_join($children[$n]);
    m4t_check($parentDeadlock || ($result['driverCode'] ?? 0) === 1213, 'real_deadlock_1213');
    if (!$parentDeadlock) m4t_check(cmj_m4_rows($pdo) === $f['plan']['beforeRows'], 'deadlock_atomic_rollback');
    m4t_adapter($f)->run(); m4t_complete($f); $report['cases'][] = 'innodb_deadlock_1213_and_resume';

    $f = m4x_fixture($root, 'disconnect', $pdo);
    $n = m4x_spawn($f, ['mode' => 'pause', 'point' => 'before_commit'], $children); m4x_wait($children[$n]['job']['marker']);
    $connection = file_get_contents($children[$n]['job']['marker']);
    cmj_model4_require(ctype_digit($connection) && (int) $connection > 0, 'invalid_own_connection');
    $pdo->exec('KILL CONNECTION ' . (int) $connection);
    m4x_mark($children[$n]['job']['release'], 'continue'); $result = m4x_join($children[$n]);
    m4t_check(($result['reason'] ?? '') === 'pdo_failure' && in_array($result['driverCode'] ?? 0, [2006, 2013], true), 'connection_loss_detected');
    m4t_check(cmj_m4_rows($pdo) === $f['plan']['beforeRows'], 'connection_loss_rollback');
    m4t_adapter($f)->run(); m4t_complete($f); $report['cases'][] = 'own_connection_killed_before_commit';

    $f = m4x_fixture($root, 'lost-reply', $pdo); $pdo->loseCommitReply = true;
    m4t_reject(fn () => m4t_adapter($f)->run(), 'commit_reply_lost');
    m4t_check(cmj_m4_rows($pdo) === $f['plan']['afterRows'], 'commit_durable_without_reply');
    m4t_reject(fn () => m4t_adapter($f)->run(true), 'rollback_after_commit_forbidden');
    m4t_adapter($f)->run(); m4t_complete($f); $report['cases'][] = 'injected_reply_loss_after_real_commit';

    $f = m4x_fixture($root, 'parallel', $pdo); $batch = [];
    for ($i = 0; $i < 10; $i++) $batch[] = m4x_spawn($f, ['mode' => 'normal'], $children);
    $success = 0;
    foreach ($batch as $n) { $result = m4x_join($children[$n]); if ($result['status'] === 'complete') $success++;
        else m4t_check(($result['reason'] ?? '') === 'migration_busy', 'parallel_conflict_safe'); }
    m4t_check($success > 0, 'parallel_completed'); m4t_complete($f); $report['cases'][] = 'ten_parallel_clients_once_only';

    $f = m4x_fixture($root, 'posix', $pdo); $file = $f['private'] . '/drafts/' . $id . '.json'; $raw = file_get_contents($file);
    rename($file, $file . '.original'); symlink($file . '.original', $file);
    m4t_reject(fn () => m4t_adapter($f)->run(), 'unsafe_path'); unlink($file); rename($file . '.original', $file);
    chmod($f['job'] . '/manifest.json', 0644); clearstatcache();
    m4t_reject(fn () => m4t_adapter($f), 'private_permissions'); chmod($f['job'] . '/manifest.json', 0600); clearstatcache();
    chmod($f['private'] . '/drafts', 0770); clearstatcache();
    m4t_reject(fn () => m4t_adapter($f), 'private_permissions'); chmod($f['private'] . '/drafts', 0700); clearstatcache();
    try { m4t_reject(fn () => m4t_adapter($f, static function ($point) use ($f, $id): void {
        if ($point === 'before_rename:source:' . $id) chmod($f['private'] . '/drafts', 0500);
    })->run(), 'rename_failed'); } finally { chmod($f['private'] . '/drafts', 0700); clearstatcache(); }
    m4t_check(file_get_contents($file) === $raw && cmj_m4_rows($pdo) === $f['plan']['beforeRows'], 'failed_rename_preserved_source');
    m4t_adapter($f)->run(); m4t_complete($f); $report['cases'][] = 'posix_modes_symlinks_rename_failure';

    $f = m4x_fixture($root, 'rollback', $pdo);
    m4t_reject(fn () => m4t_adapter($f, static function ($point): void { if ($point === 'before_commit') throw new RuntimeException('stop_for_rollback'); })->run(), 'stop_for_rollback');
    m4t_adapter($f)->run(true); m4t_original($f); m4t_adapter($f)->run(true); m4t_original($f);
    m4t_reject(fn () => m4t_adapter($f)->run(), 'rollback_requires_new_plan');
    $report['cases'][] = 'exact_precommit_file_rollback_and_replay';
    $report['checks'] = $m4Checks;
} catch (Throwable $e) {
    $failed = true;
    $report['failure'] = ['reason' => $e instanceof PDOException ? 'pdo_failure' : (preg_match('/^[a-z_0-9]+$/', $e->getMessage()) ? $e->getMessage() : 'test_failure'),
        'driverCode' => $e instanceof PDOException ? (int) ($e->errorInfo[1] ?? 0) : null, 'line' => $e->getLine(), 'file' => basename($e->getFile())];
} finally {
    foreach ($children as &$child) if (is_resource($child['process'])) { proc_terminate($child['process'], 9); proc_close($child['process']); $child['process'] = null; }
    if ($peer instanceof PDO && $peer->inTransaction()) $peer->rollBack(); $peer = null;
    if ($owned) {
        try {
            if ($pdo->inTransaction()) $pdo->rollBack();
            m4x_drop($pdo, $root, true);
            $report['restoreEmpty'] = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) === [];
            m4x_no_auxiliary_objects($pdo);
            $report['configUnchanged'] = hash_equals($configHash, hash_file('sha256', M4X_CONFIG)) && (fileperms(M4X_CONFIG) & 0777) === 0600;
            $f = null; m4t_cleanup($root . '/cases', $root . '/cases');
            $report['artificialFilesRemoved'] = !file_exists($root . '/cases');
            cmj_model4_require($report['restoreEmpty'] && $report['configUnchanged'] && $report['artificialFilesRemoved'], 'cleanup_incomplete');
        } catch (Throwable) { $report['cleanupFailed'] = true; $failed = true; }
    }
    $pdo = null;
}
$report['status'] = $failed ? 'failed' : 'passed';
echo json_encode($report, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), "\n";
exit($failed ? 1 : 0);
