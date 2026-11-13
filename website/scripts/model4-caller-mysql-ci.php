<?php
declare(strict_types=1);

// CI-only, loopback-only disposable databases. No existing secret store lookup.
ini_set('display_errors', '0'); ini_set('log_errors', '0'); ini_set('zend.exception_ignore_args', '1');
umask(0077);
$checks = []; $phase = 'isolation'; $authorized = false;
function cp_need(bool $value, string $code): void { if (!$value) throw new LogicException($code); }
function cp_check(bool $value, string $code): void { global $checks; cp_need($value, $code); $checks[] = $code; echo "PASS $code\n"; }
function cp_write(string $path, string $bytes): void { cp_need(file_put_contents($path, $bytes) === strlen($bytes) && chmod($path, 0600), 'fixture_write'); }
function cp_json(string $path, array $value): string { cp_write($path, cmj_model4_json($value)); return hash_file('sha256', $path); }
function cp_reject(callable $action, string $label, ?string $code = null): void
{
    try { $action(); }
    catch (Throwable $e) { cp_check($code === null || $e->getMessage() === $code || ($e->errorCode ?? null) === $code, $label); return; }
    throw new LogicException($label . '_not_rejected');
}
function cp_tables(PDO $db): array { return $db->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN); }
function cp_clear(PDO $db): void
{
    // Both schemas exist only in the owned disposable container, never on IONOS.
    cp_need(in_array($db->query('SELECT DATABASE()')->fetchColumn(), ['cmj_model4_source', 'cmj_model4_restore'], true), 'cleanup_target');
    if ($db->inTransaction()) $db->rollBack();
    $db->exec('SET FOREIGN_KEY_CHECKS=0');
    try { foreach (cp_tables($db) as $name) { cp_need(preg_match('/^[a-z0-9_]+$/D', $name) === 1, 'cleanup_table'); $db->exec("DROP TABLE `$name`"); } }
    finally { $db->exec('SET FOREIGN_KEY_CHECKS=1'); }
}
function cp_fingerprint(PDO $db): array
{
    $data = [];
    foreach (cp_tables($db) as $table) {
        $rows = array_map(cmj_model4_json(...), $db->query("SELECT * FROM `$table`")->fetchAll(PDO::FETCH_ASSOC));
        sort($rows); $data[$table] = $rows;
    }
    return $data;
}
function cp_caller(array $f, ?Closure $point = null, ?Closure $restore = null, ?Closure $guard = null): CarmajaModel4ProductionCaller
{
    return new CarmajaModel4ProductionCaller($f['paths'], $f['pdo'], $f['approvalDigest'],
        static fn ($path) => CarmajaProductionBackup::loadRuntime($path),
        $restore ?? cmj_m4p_restore_proof(...),
        $guard ?? static fn (float $deadline) => cmj_m4p_quiesce($f['config'], $f['pdo'], $f['private'], $deadline), $point);
}
function cp_fixture(string $root, string $name, array $base, PDO $source): array
{
    cp_need(cp_tables($source) === [], 'fixture_source_empty');
    $case = $root . '/' . $name; mkdir($case, 0700);
    $f = m4t_fixture($case, 'products'); // Builds artificial files, never reads real products.
    $seed = $f['pdo']->query('SELECT * FROM commerce_products')->fetchAll(PDO::FETCH_ASSOC);
    $f['pdo'] = null; unlink($f['private'] . '/fixture.sqlite');
    $package = getenv('RUNNER_TEMP') . '/cart-package';
    $release = json_decode(file_get_contents($package . '/manifest.json'), true, 128, JSON_THROW_ON_ERROR);
    cp_need($release['sourceCommit'] === getenv('GITHUB_SHA') && $release['workingTreeDirty'] === false, 'clean_active_package');
    $commerce = new CarmajaCommercePdo($source);
    foreach ($release['migrations'] as $index => $m) {
        if ($index === 0) $commerce->migrate($package . '/' . $m['path']);
        else $commerce->migrateForward($m['migrationId'], $package . '/' . $m['path']);
    }
    $columns = array_keys($seed[0]);
    $insert = $source->prepare('INSERT INTO commerce_products (' . implode(',', $columns) . ') VALUES (' . implode(',', array_fill(0, count($columns), '?')) . ')');
    foreach ($seed as $row) $insert->execute(array_values($row));
    // Fixed UTC instants cross day/year boundaries and retain microseconds.
    $source->exec("UPDATE commerce_products SET synchronized_at='2026-12-31 23:45:12.123456', updated_at='2026-01-01 00:15:43.654321'");
    $source->exec("INSERT INTO worker_leases(worker_name) VALUES ('ci-idle-worker')");
    $source->exec("INSERT INTO commerce_inventory(product_id,on_hand,inventory_version) SELECT product_id,3,7 FROM commerce_products");
    $source->exec("INSERT INTO legal_bundles(legal_bundle_id,terms_version,privacy_version,withdrawal_version,shipping_version,merchant_version,bundle_hash,status) VALUES ('artificial-historical','old','old','old','old','old',REPEAT('a',64),'retired')");
    $f['pdo'] = $source;
    $f['plan'] = cmj_m4_capture($f['private'], $source, $f['public'], gmdate('Y-m-d\TH:i:s\Z'), 'model4-artificial-20261112');
    $f['digest'] = cmj_model4_digest($f['plan']); cp_json($f['job'] . '/manifest.json', $f['plan']);
    foreach (['products', 'auth', 'idempotency'] as $dir) mkdir($f['private'] . '/' . $dir, 0700);
    cp_write($f['private'] . '/environment.json', '{"environment":"artificial"}');
    cp_write($f['private'] . '/sku-counter', "12\n");
    $shop = $case . '/shop'; $api = $case . '/api';
    foreach ([$shop, $api, $shop . '/program', $shop . '/config', $shop . '/maintenance', $shop . '/model4-tool'] as $dir) mkdir($dir, 0700);
    $paths = ['product'=>$f['private'], 'shop'=>$shop, 'api'=>$api, 'tool'=>$shop . '/model4-tool', 'job'=>$f['job'], 'runtime'=>$shop . '/config/runtime-config.php'];
    foreach ($release['files'] as $file) {
        $n = $file['path']; $target = null;
        if (str_starts_with($n, 'private/program/')) $target = $shop . '/' . substr($n, 8);
        elseif (in_array($n, ['public/index.php','public/.htaccess'], true)) $target = $api . '/' . substr($n, 7);
        elseif ($n === 'private/worker.php') $target = $shop . '/worker.php';
        if ($target !== null) {
            if (!is_dir(dirname($target))) mkdir(dirname($target), 0700, true);
            cp_write($target, file_get_contents($package . '/' . $n));
        }
    }
    $tool = getenv('RUNNER_TEMP') . '/model4-tool';
    $tr = json_decode(file_get_contents($tool . '/release.json'), true, 128, JSON_THROW_ON_ERROR);
    cp_need($tr['sourceCommit'] === getenv('GITHUB_SHA') && $tr['workingTreeDirty'] === false, 'clean_tool');
    foreach (CMJ_M4P_TOOL_FILES as $n) cp_write($paths['tool'] . '/' . $n, file_get_contents($tool . '/' . $n));
    $config = $base + ['environment'=>'production', 'publishTarget'=>'production',
        'privateDir'=>$shop, 'productionPrivateDir'=>$shop, 'productPrivateDir'=>$f['private'], 'productionApiWebroot'=>$api,
        'runtimeFile'=>$paths['runtime'], 'backupDirectory'=>$shop . '/backups',
        'backupOffsiteTarget'=>'onedrive-pull://carmaja-production/Carmaja-Perlen/Backups',
        'backupEncryptionKey'=>base64_encode(random_bytes(32)), 'backupEncryptionKeyId'=>'artificial-ci-only'];
    foreach (CMJ_M4P_CLOSED_FLAGS as $flag) $config[$flag] = false;
    cp_write($paths['runtime'], '<?php return ' . var_export($config, true) . ';');
    cp_write($shop . '/maintenance/product-cleanup.lock', "artificial closed\n");
    $backup = new CarmajaProductionBackup($config);
    $result = $backup->create(); $id = $result['backupId'];
    $backupDir = $shop . '/backups/ready/' . $id;
    $bh = hash_file('sha256', $backupDir . '/manifest.json');
    // Copy ciphertext to an owned local offsite surrogate and verify every byte.
    // This is NOT a new OneDrive/cloud delivery claim.
    mkdir($case . '/offsite', 0700);
    foreach (['commerce.sql.gz.cmjbkp','product-data.tar.gz.cmjbkp','manifest.json','ready'] as $n) {
        cp_write($case . '/offsite/' . $n, file_get_contents($backupDir . '/' . $n));
        cp_need(hash_file('sha256', $case . '/offsite/' . $n) === hash_file('sha256', $backupDir . '/' . $n), 'offsite_copy');
    }
    $backup->acknowledge($id, $bh);
    $approval = ['format'=>'model4-execution-approval-v1','approved'=>true,'environment'=>'production',
        'operationId'=>$f['plan']['operationId'],'publicationAllowed'=>false,'inventoryImportAllowed'=>false,'selectedProducts'=>[],
        'releaseSha'=>getenv('GITHUB_SHA'),'manifestDigest'=>$f['digest'],
        'actions'=>['preflight','verify-restore','apply','resume','rollback-files'],
        'issuedAt'=>gmdate('Y-m-d\TH:i:s\Z', time()-1),'expiresAt'=>gmdate('Y-m-d\TH:i:s\Z', time()+3600),
        'runtimeSha256'=>hash_file('sha256',$paths['runtime']),
        'releaseManifestSha256'=>cp_json($f['job'].'/release.json',$tr),
        'activePackageSha256'=>cp_json($f['job'].'/active-package.json',$release),
        'maintenanceSha256'=>hash_file('sha256',$shop.'/maintenance/product-cleanup.lock'),
        'publicReferenceSha256'=>cp_json($f['job'].'/public-reference.json',$f['public']),
        'schemaJournalDigest'=>cmj_model4_digest($source->query('SELECT migration_id,checksum FROM schema_migrations ORDER BY migration_id')->fetchAll(PDO::FETCH_ASSOC)),
        'backupId'=>$id,'backupManifestSha256'=>$bh];
    $approvalDigest = cp_json($f['job'].'/approval.json',$approval);
    $f['protected'] = m4t_protected($f);
    cp_json($case . '/fixture.json', ['paths'=>$paths,'approvalDigest'=>$approvalDigest]);
    return $f + compact('config','paths','approval','approvalDigest','backupDir','case');
}
function cp_restore_empty(array $f, PDO $restore): void
{
    cmj_m4p_empty_restore($restore);
    cp_check((glob($f['config']['backupDirectory'].'/.restore/*') ?: []) === [], 'restore_database_and_files_empty');
}
function cp_timeout(callable $action, string $label): void
{
    $start = microtime(true);
    try { $action(); } catch (PDOException $e) {
        cp_check(($e->errorInfo[1] ?? null) === 1205 && microtime(true)-$start < 7, $label); return;
    }
    throw new LogicException($label . '_not_blocked');
}

try {
    cp_need(getenv('GITHUB_ACTIONS') === 'true' && getenv('RUNNER_ENVIRONMENT') === 'github-hosted'
        && getenv('RUNNER_OS') === 'Linux' && getenv('GITHUB_REPOSITORY') === 'Bumpers210/armband-rechner'
        && (getenv('GITHUB_REF') === 'refs/heads/codex/cart-model4-preparation' || getenv('GITHUB_HEAD_REF') === 'codex/cart-model4-preparation'
            || getenv('GITHUB_REF') === 'refs/heads/codex/model4-restore-utc' || getenv('GITHUB_HEAD_REF') === 'codex/model4-restore-utc'
            || (getenv('CMJ_CI_SCENARIO') === 'inventory' && (getenv('GITHUB_REF') === 'refs/heads/codex/cart-inventory-caller'
                || getenv('GITHUB_HEAD_REF') === 'codex/cart-inventory-caller')))
        && preg_match('/^[a-f0-9]{40}$/D', (string)getenv('GITHUB_SHA')) === 1
        && preg_match('/^[0-9]+$/D', (string)getenv('GITHUB_RUN_ID')) === 1
        && preg_match('/^[0-9]+$/D', (string)getenv('GITHUB_RUN_ATTEMPT')) === 1, 'isolated_github_only');
    cp_need(PHP_MAJOR_VERSION === 8 && PHP_MINOR_VERSION === 4 && PHP_OS_FAMILY === 'Linux', 'linux_php84');
    $temporary = getenv('RUNNER_TEMP'); cp_need(realpath($temporary) === $temporary, 'runner_root');
    $report = $temporary . '/model4-caller-report.json';
    $authorized = true; $mode = $argv[1] ?? '';
    if ($mode === 'cleanup-report') {
        $data = is_file($report) ? json_decode(file_get_contents($report), true, 128, JSON_THROW_ON_ERROR) : ['status'=>'failed_before_test'];
        $data['resourceCleanup'] = 'passed'; cp_write($report, json_encode($data, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)); exit;
    }
    $root = $temporary . '/model4-caller-' . getenv('GITHUB_RUN_ID') . '-' . getenv('GITHUB_RUN_ATTEMPT');
    cp_need(realpath($root) === $root && !is_link($root) && file_get_contents($root . '/.owner') === getenv('GITHUB_RUN_ID').'-'.getenv('GITHUB_RUN_ATTEMPT'), 'fixture_owner');
    if ($mode === 'setup') {
        mkdir($root . '/setup', 0700);
        $rp=bin2hex(random_bytes(24)); $sp=bin2hex(random_bytes(24)); $dp=bin2hex(random_bytes(24));
        cp_write($root.'/setup/root-password',$rp);
        cp_write($root.'/setup/root.cnf',"[client]\nuser=root\npassword=$rp\nprotocol=socket\n");
        cp_write($root.'/setup/init.sql',"CREATE DATABASE cmj_model4_source CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;\nCREATE DATABASE cmj_model4_restore CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;\nCREATE USER 'ci_source'@'%' IDENTIFIED BY '$sp' REQUIRE SSL;\nCREATE USER 'ci_restore'@'%' IDENTIFIED BY '$dp' REQUIRE SSL;\nGRANT ALL PRIVILEGES ON cmj_model4_source.* TO 'ci_source'@'%';\nGRANT ALL PRIVILEGES ON cmj_model4_restore.* TO 'ci_restore'@'%';\n");
        $base = ['commerceDsn'=>'mysql:host=127.0.0.1;port=13306;dbname=cmj_model4_source;charset=utf8mb4',
            'commerceUser'=>'ci_source','commercePassword'=>$sp,'commerceRequireTls'=>true,'commerceTlsCaPath'=>$root.'/tls/ca.pem',
            'commerceRestoreDsn'=>'mysql:host=127.0.0.1;port=13306;dbname=cmj_model4_restore;charset=utf8mb4',
            'commerceRestoreUser'=>'ci_restore','commerceRestorePassword'=>$dp,'commerceRestoreRequireTls'=>true,'commerceRestoreTlsCaPath'=>$root.'/tls/ca.pem'];
        cp_write($root.'/setup/connections.php','<?php return '.var_export($base,true).';'); exit;
    }
    define('CMJ_MODEL4_TEST_HELPERS_ONLY', true);
    require dirname(__DIR__).'/tests/php/product-model4-migration-test.php';
    require_once __DIR__.'/product-model4-production.php';
    require_once dirname(__DIR__).'/test-api-private/program/production-backup.php';
    require_once dirname(__DIR__).'/test-api-private/program/commerce-core.php';
    $base = require $root.'/setup/connections.php';
    foreach (['commerce'=>'cmj_model4_source','commerceRestore'=>'cmj_model4_restore'] as $prefix=>$db) {
        cp_need($base[$prefix.'Dsn'] === "mysql:host=127.0.0.1;port=13306;dbname=$db;charset=utf8mb4", 'loopback_only');
    }
    $source = cmj_m4p_connect($base); $restore = cmj_m4p_connect($base,true);
    if (getenv('CMJ_CI_SCENARIO') === 'inventory') {
        require __DIR__ . '/inventory-caller-mysql-ci.php';
        exit;
    }
    if ($mode === 'crash-child') {
        cp_need(preg_match('/^crash-[0-9]+$/D', $argv[2] ?? '') === 1, 'child_case');
        $ff = json_decode(file_get_contents($root.'/'.$argv[2].'/fixture.json'), true,128,JSON_THROW_ON_ERROR);
        $config = CarmajaProductionBackup::loadRuntime($ff['paths']['runtime']);
        cp_need(str_starts_with($ff['paths']['product'], $root.'/'.$argv[2].'/') && $config['commerceDsn'] === $base['commerceDsn'], 'child_bound_target');
        $f = $ff + ['pdo'=>$source,'config'=>$config,'private'=>$ff['paths']['product']];
        cp_caller($f,static function(string $p) use($argv):void {
            if ($p === $argv[3] || ($argv[3] === 'after_rename' && str_starts_with($p,'after_rename:source:'))) posix_kill(getmypid(),SIGKILL);
        })->run('apply'); exit(91);
    }
    cp_need($mode === 'test', 'test_mode');
    $phase = 'preflight';
    cmj_m4p_empty_restore($source); cmj_m4p_empty_restore($restore);
    cp_check(true,'both_disposable_databases_initially_empty');
    cp_check((int)$source->query('SELECT @@require_secure_transport')->fetchColumn() === 1,'server_requires_tls');
    cp_check($source->query('SELECT @@global.time_zone')->fetchColumn() === '+02:00'
        && $source->query('SELECT @@session.time_zone')->fetchColumn() === '+00:00', 'nonutc_server_utc_source');
    cp_check(true,'mysql8_php84_linux_tls_both_sessions_verified_ca');
    cp_reject(fn()=>new PDO($base['commerceDsn'],$base['commerceUser'],$base['commercePassword'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]),'plaintext_rejected');
    $restore->exec('CREATE TABLE ci_guard(id INT PRIMARY KEY) ENGINE=InnoDB');
    cp_reject(fn()=>$source->query('SELECT * FROM cmj_model4_restore.ci_guard'),'source_cannot_access_restore');
    $restore->exec('DROP TABLE ci_guard');

    $phase='restore_and_guards'; $f=cp_fixture($root,'normal',$base,$source);
    cp_check(count(cp_tables($source)) === 38,'actual_full_38_table_schema_five_migrations');
    $before=cp_fingerprint($source); $backup=new CarmajaProductionBackup($f['config']);
    cp_reject(fn()=>cp_caller($f)->run('preflight'),'missing_restore_proof_rejected');
    // The real backup callback receives a new PDO with the server default, not
    // the independent lock session's UTC setting. Prove the original mismatch
    // and then compare the exact same restored rows through the corrected path.
    $backup->restoreDryRun($f['approval']['backupId'], static function(PDO $db, string $extract) use ($f): void {
        cp_check($db->query('SELECT @@session.time_zone')->fetchColumn() === '+02:00', 'fresh_callback_inherits_nonutc');
        foreach (['+02:00', '-07:00', '+05:30'] as $zone) {
            $db->exec("SET SESSION time_zone='$zone'");
            cp_check(cmj_m4_rows($db) !== $f['plan']['beforeRows'], 'raw_nonutc_snapshot_mismatch_' . $zone);
            cmj_m4p_restore_snapshot($db, $extract, $f['plan']);
            cp_check($db->query('SELECT @@session.time_zone')->fetchColumn() === '+00:00'
                && cmj_m4_rows($db) === $f['plan']['beforeRows'], 'callback_utc_exact_match_' . $zone);
        }
        $badTime = $f['plan']; $badTime['beforeRows'][0]['updated_at'] = '2026-01-01 00:15:43.654322';
        cp_reject(fn () => cmj_m4p_restore_snapshot($db, $extract, $badTime), 'microsecond_mismatch_still_rejected', 'backup_source_mismatch');
    });
    cp_restore_empty($f, $restore);
    // Actual restore callback mismatch: compare the restored DB, not the live source.
    $bad=$f['plan']; $bad['beforeRows'][0]['name']='WRONG ARTIFICIAL BEFORE IMAGE';
    cp_reject(fn()=>cp_caller($f,null,static fn($c,$id,$plan)=>cmj_m4p_restore_proof($c,$id,$bad))->run('verify-restore'),'restore_callback_mismatch','backup_source_mismatch');
    cp_restore_empty($f,$restore);
    cp_check(glob($f['job'].'/restore-proof-*') === [],'failed_restore_creates_no_receipt');
    cp_reject(fn()=>$backup->restoreDryRun($f['approval']['backupId'],static function():void {throw new LogicException('artificial_callback_failure');}),'callback_exception','artificial_callback_failure');
    cp_restore_empty($f,$restore);
    $restore->exec('CREATE TABLE ci_guard(id INT PRIMARY KEY) ENGINE=InnoDB');
    cp_reject(fn()=>cp_caller($f)->run('verify-restore'),'nonempty_restore_rejected','restore_not_empty');
    cp_check(cp_tables($restore) === ['ci_guard'],'nonempty_restore_preserved'); $restore->exec('DROP TABLE ci_guard');
    $restore->query("SELECT GET_LOCK('carmaja-model4-restore',0)");
    cp_reject(fn()=>cp_caller($f)->run('verify-restore'),'parallel_restore_lock','restore_busy');
    $restore->query("SELECT RELEASE_LOCK('carmaja-model4-restore')");
    $cipher=$f['backupDir'].'/commerce.sql.gz.cmjbkp'; $original=file_get_contents($cipher); cp_write($cipher,substr($original,0,-1));
    cp_reject(fn()=>cp_caller($f)->run('verify-restore'),'truncated_archive_rejected'); cp_write($cipher,$original); unset($original);
    cp_check(cp_caller($f)->run('verify-restore')['status']==='restore_proof_verified','real_encrypted_backup_restore_receipt'); cp_restore_empty($f,$restore);
    cp_check($before===cp_fingerprint($source),'restore_source_unchanged');
    cp_check(cp_caller($f)->run('preflight')['status']==='preflight_passed','read_only_caller_preflight');
    $lock=fopen($f['config']['backupDirectory'].'/locks/backup.lock','r'); flock($lock,LOCK_EX|LOCK_NB);
    cp_reject(fn()=>cp_caller($f)->run('apply'),'backup_lock_blocks_apply','backup_busy'); flock($lock,LOCK_UN); fclose($lock);
    $other=cmj_m4p_connect($base); $other->beginTransaction(); $other->query('SELECT * FROM worker_leases FOR UPDATE');
    cp_timeout(fn()=>cp_caller($f)->run('apply'),'busy_worker_blocks_guard_with_1205'); $other->rollBack();
    m4t_original($f);
    $did=false;
    $result=cp_caller($f,static function(string $point) use(&$did,$other,$f,$backup):void {
        if ($point!=='commit_ready' || $did) return; $did=true;
        cp_timeout(fn()=>$other->exec("UPDATE commerce_inventory SET on_hand=4"),'admin_inventory_blocked_under_guard');
        cp_timeout(fn()=>$other->exec("UPDATE worker_leases SET lease_token='11111111-1111-4111-8111-111111111111' WHERE worker_name='ci-idle-worker'"),'worker_lease_blocked_under_guard');
        cp_timeout(fn()=>$other->exec("INSERT INTO worker_leases(worker_name) VALUES ('ci-new-worker')"),'new_worker_range_locked');
        cp_reject(fn()=>$backup->create(),'backup_create_blocked_during_apply','backup_already_running');
    })->run('apply');
    cp_check($did && $result['status']==='model4_complete','two_connection_guard_and_apply'); m4t_complete($f);
    cp_check(cp_caller($f)->run('resume')['status']==='model4_complete','idempotent_resume');
    cp_reject(fn()=>cp_caller($f)->run('rollback-files'),'post_commit_rollback_rejected','rollback_after_commit_forbidden');
    $after=cp_fingerprint($source); unset($before['commerce_products'],$after['commerce_products']);
    cp_check($before===$after,'all_other_37_tables_unchanged');
    $other->exec("UPDATE worker_leases SET last_error='artificial probe' WHERE worker_name='ci-idle-worker'");
    cp_check(true,'guard_released_after_success'); $other=null; cp_clear($source);
    m4t_cleanup($f['case'],$root); unset($f);

    $phase='guard_connection_loss'; $f=cp_fixture($root,'guard-loss',$base,$source);
    cp_caller($f)->run('verify-restore'); $killer=cmj_m4p_connect($base); $guardId=null;
    $quiesce=static function(float $deadline) use($f,$killer,&$guardId):CarmajaModel4Guard {
        $old=$killer->query('SELECT ID FROM information_schema.PROCESSLIST')->fetchAll(PDO::FETCH_COLUMN);
        $g=cmj_m4p_quiesce($f['config'],$f['pdo'],$f['private'],$deadline);
        $new=$killer->query('SELECT ID FROM information_schema.PROCESSLIST')->fetchAll(PDO::FETCH_COLUMN);
        $ids=array_values(array_diff($new,$old)); cp_need(count($ids)===1,'own_guard_identified'); $guardId=(int)$ids[0]; return $g;
    };
    cp_reject(fn()=>cp_caller($f,static function(string $point) use($killer,&$guardId):void {
        if($point==='commit_ready'){cp_need(is_int($guardId)&&$guardId>0,'own_guard_only');$killer->exec('KILL CONNECTION '.$guardId);}
    },null,$quiesce)->run('apply'),'guard_loss_before_commit_stops_apply');
    cp_check(cmj_m4_rows($source)===$f['plan']['beforeRows'],'guard_loss_no_partial_sql_commit');
    cp_check(cp_caller($f)->run('resume')['status']==='model4_complete','guard_loss_resume'); m4t_complete($f);
    $killer=null; cp_clear($source); m4t_cleanup($f['case'],$root); unset($f);

    foreach (['after_rename','commit_ready','after_commit'] as $index=>$point) {
        $phase='crash_'.$point; $f=cp_fixture($root,'crash-'.$index,$base,$source); cp_caller($f)->run('verify-restore');
        $process=proc_open([PHP_BINARY,__FILE__,'crash-child','crash-'.$index,$point],
            [0=>['pipe','r'],1=>['file',$f['case'].'/child-output','w'],2=>['file',$f['case'].'/child-error','w']],$pipes);
        cp_need(is_resource($process),'child_start'); fclose($pipes[0]); $deadline=microtime(true)+45;
        do {$s=proc_get_status($process); if(!$s['running'])break;usleep(50000);} while(microtime(true)<$deadline);
        if($s['running'])proc_terminate($process,9); proc_close($process);
        cp_check(!$s['running'] && $s['signaled'] && $s['termsig']===9,'real_sigkill_'.$point);
        cp_check(cmj_m4_rows($source)===($point==='after_commit'?$f['plan']['afterRows']:$f['plan']['beforeRows']),'crash_sql_atomic_'.$point);
        cp_check(cp_caller($f)->run('resume')['status']==='model4_complete','crash_resume_'.$point); m4t_complete($f);
        cp_clear($source); m4t_cleanup($f['case'],$root); unset($f);
    }
    $phase='rollback'; $f=cp_fixture($root,'rollback',$base,$source); cp_caller($f)->run('verify-restore');
    cp_reject(fn()=>cp_caller($f,static function(string $point):void{if(str_starts_with($point,'after_rename:source:'))throw new LogicException('artificial_precommit_stop');})->run('apply'),'precommit_stop','artificial_precommit_stop');
    cp_caller($f)->run('rollback-files'); m4t_original($f);
    cp_check(cp_caller($f)->run('rollback-files')['status']==='files_rolled_back','file_rollback_idempotent');
    cp_clear($source); m4t_cleanup($f['case'],$root); unset($f);
    cmj_m4p_empty_restore($source); cmj_m4p_empty_restore($restore);
    cp_check(true,'both_databases_finally_empty');
    cp_write($report,json_encode(['status'=>'passed','sourceSha'=>getenv('GITHUB_SHA'),'php'=>PHP_VERSION,
        'mysql'=>$source->query('SELECT VERSION()')->fetchColumn(),'image'=>trim(file_get_contents($root.'/image-digest')),
        'checks'=>$checks,'adapterAssertions'=>$m4Checks,'databaseCleanup'=>'passed','productionAccess'=>false,
        'existingTestAccess'=>false,'offsiteProof'=>'owned_local_surrogate_not_cloud'],JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR));
    echo 'MODEL4_CALLER_MYSQL_PASSED checks='.count($checks)."\n";
} catch(Throwable $error) {
    // Fixed labels / numeric PDO codes only; never SQL, DSN, runtime or stack args.
    $safeCode=$error instanceof PDOException?'pdo_'.(int)($error->errorInfo[1]??0):($error->errorCode??$error->getMessage());
    if(!is_string($safeCode)||preg_match('/^[a-zA-Z0-9_:-]{1,120}$/D',$safeCode)!==1)$safeCode='redacted_error';
    if($authorized)cp_write($report,json_encode(['status'=>'failed','phase'=>$phase,'code'=>$safeCode,'line'=>$error->getLine(),'checks'=>$checks],JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR));
    fwrite(STDERR,"MODEL4_CALLER_MYSQL_FAILED phase=$phase code=$safeCode\n"); exit(1);
}
