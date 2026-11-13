<?php
declare(strict_types=1);

// Included ONLY after the existing runner has verified its owned, disposable
// GitHub container and both loopback TLS targets. No IONOS or stored secrets.
if (($authorized ?? false) !== true || getenv('CMJ_CI_SCENARIO') !== 'inventory'
    || !isset($root, $base, $source, $restore, $mode, $report)
    || !($source instanceof PDO) || !($restore instanceof PDO)) {
    throw new LogicException('isolated_inventory_runner_required');
}
require_once __DIR__ . '/inventory-count-production.php';
require_once dirname(__DIR__) . '/test-api-private/program/production-cart-cutover.php';

function ip_fixture(string $root, string $name, array $base, PDO $db): array
{
    $f = cp_fixture($root, $name, $base, $db);
    $paths = $f['paths'];
    $paths['job'] = $paths['product'] . '/audit/inventory-artificial-' . $name;
    $paths['tool'] = $paths['shop'] . '/inventory-tool';
    mkdir($paths['job'], 0700); mkdir($paths['tool'], 0700);
    cp_write($paths['job'] . '/import.lock', '');
    $tool = getenv('RUNNER_TEMP') . '/inventory-tool';
    $release = json_decode(file_get_contents($tool . '/release.json'), true, 128, JSON_THROW_ON_ERROR);
    cp_need($release['sourceCommit'] === getenv('GITHUB_SHA') && $release['workingTreeDirty'] === false, 'clean_inventory_tool');
    foreach (CMJ_ICP_TOOL_FILES as $n) cp_write($paths['tool'] . '/' . $n, file_get_contents($tool . '/' . $n));
    $active = json_decode(file_get_contents(getenv('RUNNER_TEMP') . '/cart-package/manifest.json'), true, 128, JSON_THROW_ON_ERROR);
    $selected = []; $files = []; $public = ['version'=>4, 'products'=>[]];
    foreach (glob($paths['product'] . '/drafts/*.json') as $file) {
        $draft = json_decode(file_get_contents($file), true, 128, JSON_THROW_ON_ERROR);
        if (in_array($draft['productModelVersion'] ?? null, [3,4], true)) {
            $draft = cmj_model4_candidate($draft, gmdate('Y-m-d\TH:i:s\Z'));
            cp_json($file, $draft);
            $p = carmaja_api_v3_product_from_draft($draft);
            $db->prepare('UPDATE commerce_products SET product_version=?,source_hash=? WHERE product_id=?')
                ->execute([$p['productVersion'],$p['sourceHash'],$p['productId']]);
            if ($draft['status'] === 'published') {
                $selected[] = ['productId'=>$p['productId'],'expectedProductVersion'=>$p['productVersion'],
                    'expectedSourceHash'=>$p['sourceHash'],'targetOnHand'=>4,'expectedInventoryVersion'=>0,
                    'operationId'=>'count-' . $p['productId']];
                // Only synthetic inventory of this owned container is removed.
                $db->prepare('DELETE FROM commerce_inventory WHERE product_id=?')->execute([$p['productId']]);
                $public['products'][] = cmj_model4_projection($draft);
                cp_write($paths['product'] . '/locks/draft-' . $p['productId'] . '.lock', '');
            }
        }
        $files['drafts/' . basename($file)] = hash_file('sha256', $file);
    }
    $files['uploads/01.jpg'] = hash_file('sha256', $paths['product'] . '/uploads/01.jpg');
    $db->prepare('INSERT INTO legal_bundles(legal_bundle_id,terms_version,privacy_version,withdrawal_version,shipping_version,merchant_version,bundle_hash,status) VALUES (?,?,?,?,?,?,?,?)')
        ->execute([CarmajaCartCutover::LEGAL_ID,'v7','v7','v7','v7','v7',$active['legalBundleHash'],'approved']);
    $config = $f['config']; $config['productBrowserProductionEnabled'] = false;
    $config['backupEncryptionKeyFile'] = $paths['shop'] . '/config/backup-key.php';
    cp_write($config['backupEncryptionKeyFile'], '<?php return ' . var_export([
        'backupEncryptionKey'=>$config['backupEncryptionKey'],'backupEncryptionKeyId'=>$config['backupEncryptionKeyId']], true) . ';');
    $raw = $config; unset($raw['backupEncryptionKey']);
    cp_write($paths['runtime'], '<?php return ' . var_export($raw, true) . ';');
    cp_need(CarmajaProductionBackup::loadRuntime($paths['runtime'])['backupEncryptionKey'] === $config['backupEncryptionKey'], 'private_key_file_loaded');
    $backupService = new CarmajaProductionBackup($config);
    $backupId = $backupService->create()['backupId'];
    $backupDir = $config['backupDirectory'] . '/ready/' . $backupId;
    mkdir($f['case'] . '/inventory-offsite',0700);
    foreach (['commerce.sql.gz.cmjbkp','product-data.tar.gz.cmjbkp','manifest.json','ready'] as $n) {
        cp_write($f['case'] . '/inventory-offsite/' . $n, file_get_contents($backupDir . '/' . $n));
        cp_need(hash_file('sha256',$f['case'] . '/inventory-offsite/' . $n) === hash_file('sha256',$backupDir . '/' . $n), 'offsite_ciphertext_comparison');
    }
    $bh = hash_file('sha256', $backupDir . '/manifest.json'); $backupService->acknowledge($backupId, $bh);
    $counts = json_decode(file_get_contents(dirname(__DIR__) . '/config/production-cart-cutover-manifest.v3.json'), true, 128, JSON_THROW_ON_ERROR);
    $counts = [...$counts,'status'=>'not_approved','selectedProducts'=>array_reverse($selected),
        'countConfirmedAt'=>gmdate('Y-m-d\TH:i:s\Z',time()-2),'actorId'=>'artificial-ci-operator',
        'releaseSha'=>$active['sourceCommit'],'legalBundleHash'=>$active['legalBundleHash'],'backupId'=>$backupId,'restoreProofSha256'=>null];
    $snapshot = cmj_icp_snapshot($db);
    $approval = ['format'=>'inventory-import-approval-v1','approved'=>true,'environment'=>'production','inventoryImportAllowed'=>true,
        'checkoutActivation'=>false,'publicationAllowed'=>false,'operationId'=>basename($paths['job']),
        'releaseSha'=>$release['sourceCommit'],'actions'=>['preflight','apply','inspect'],
        'issuedAt'=>gmdate('Y-m-d\TH:i:s\Z',time()-1),'expiresAt'=>gmdate('Y-m-d\TH:i:s\Z',time()+3600),
        'targetHash'=>cmj_m4_target($paths['product'],$db),'backupId'=>$backupId,'backupManifestSha256'=>$bh,
        'runtimeSha256'=>hash_file('sha256',$paths['runtime']),'keyFileSha256'=>hash_file('sha256',$config['backupEncryptionKeyFile']),
        'maintenanceSha256'=>hash_file('sha256',$paths['shop'].'/maintenance/product-cleanup.lock'),
        'countsSha256'=>cp_json($paths['job'].'/counts.json',$counts),
        'databaseSha256'=>cp_json($paths['job'].'/before-database.json',$snapshot),
        'preservedSha256'=>cp_json($paths['job'].'/preserved-rows.json',cmj_icp_preserved($db,$selected)),
        'sourcesSha256'=>cp_json($paths['job'].'/source-files.json',$files),
        'publicReferenceSha256'=>cp_json($paths['job'].'/public-reference.json',$public),
        'releaseManifestSha256'=>cp_json($paths['job'].'/release.json',$release),
        'activePackageSha256'=>cp_json($paths['job'].'/active-package.json',$active)];
    $approvalHash = cp_json($paths['job'].'/approval.json',$approval);
    cp_json($f['case'].'/inventory-fixture.json',compact('paths','approvalHash'));
    return ['paths'=>$paths,'config'=>$config,'db'=>$db,'approvalHash'=>$approvalHash,'snapshot'=>$snapshot,
        'sources'=>$files,'counts'=>$counts,'backupDir'=>$backupDir,'case'=>$f['case'],'approval'=>$approval];
}

function ip_caller(array $f, ?Closure $point = null, ?Closure $restore = null): CarmajaInventoryProductionCaller
{
    return new CarmajaInventoryProductionCaller($f['paths'],$f['db'],$f['config'],$f['approvalHash'], $restore ?? cmj_icp_restore(...), $point);
}
function ip_child(string $name, string $point): array
{
    global $root;
    $process = proc_open([PHP_BINARY,__DIR__.'/model4-caller-mysql-ci.php','inventory-child',$name,$point],
        [0=>['pipe','r'],1=>['file',$root.'/'.$name.'/child-output','w'],2=>['file',$root.'/'.$name.'/child-error','w']],$pipes);
    cp_need(is_resource($process),'inventory_child_start'); fclose($pipes[0]);
    return [$process,microtime(true)+50];
}
function ip_wait(array $child): array
{
    [$process,$deadline]=$child;
    do { $s=proc_get_status($process); if(!$s['running']) break; usleep(50000); } while(microtime(true)<$deadline);
    if($s['running'])proc_terminate($process,9); proc_close($process);
    cp_need(!$s['running'],'inventory_child_deadline'); return $s;
}

if ($mode === 'inventory-child') {
    cp_need(preg_match('/^(crash-[0-9]+|deadlock)$/D',$argv[2] ?? '') === 1,'child_case');
    $case=$root.'/'.$argv[2];
    $f=json_decode(file_get_contents($case.'/inventory-fixture.json'),true,128,JSON_THROW_ON_ERROR);
    $f['db']=$source; $f['config']=CarmajaProductionBackup::loadRuntime($f['paths']['runtime']);
    cp_need(str_starts_with($f['paths']['product'],$case.'/') && $f['config']['commerceDsn']===$base['commerceDsn'],'child_bound_target');
    if($argv[3]==='deadlock') {
        $source->exec('SET SESSION innodb_lock_wait_timeout=15'); $source->beginTransaction();
        try {
            $source->query('SELECT * FROM worker_leases FOR UPDATE'); cp_write($case.'/child-ready','ready');
            $until=microtime(true)+10;
            while(!is_file($case.'/parent-ready') && microtime(true)<$until)usleep(20000);
            cp_need(is_file($case.'/parent-ready'),'deadlock_barrier');
            $source->query('SELECT * FROM commerce_products FOR UPDATE');
            cp_write($case.'/child-result','lock-acquired');
        } catch(PDOException $e) { cp_need(($e->errorInfo[1]??null)===1213,'native_deadlock_expected'); cp_write($case.'/child-result','1213'); }
        finally { if($source->inTransaction())$source->rollBack(); }
        return;
    }
    ip_caller($f,static function(string $point) use($argv):void { if($point===$argv[3])posix_kill(getmypid(),SIGKILL); })->run('apply');
    throw new LogicException('crash_not_reached');
}
cp_need($mode==='test','inventory_test_mode');
$phase='inventory_preflight';
cmj_m4p_empty_restore($source); cmj_m4p_empty_restore($restore);
cp_check((int)$source->query('SELECT @@require_secure_transport')->fetchColumn()===1,'inventory_tls_required');
cp_check($source->query('SELECT @@global.time_zone')->fetchColumn()==='+02:00'
    && $source->query('SELECT @@session.time_zone')->fetchColumn()==='+00:00','inventory_utc_session_on_nonutc_server');
cp_reject(fn()=>new PDO($base['commerceDsn'],$base['commerceUser'],$base['commercePassword'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]),'inventory_plaintext_rejected');
cp_check(true,'inventory_mysql8_php84_linux_both_tls_ca_verified');
$phase='inventory_normal'; $f=ip_fixture($root,'normal',$base,$source);
cp_check(count(cp_tables($source))===38,'inventory_full_38_table_schema');
cp_check(ip_caller($f)->run('preflight')['status']==='preflight_passed','inventory_readonly_preflight');
cp_check(cmj_icp_snapshot($source)===$f['snapshot'],'inventory_preflight_unchanged');
$bad=$f['snapshot']; $bad['commerce_products']['content']=str_repeat('0',64);
cp_reject(fn()=>cmj_icp_restore($f['config'],$f['approval']['backupId'],$bad,$f['sources']),'inventory_restore_mismatch_rejected','backup_source_mismatch');
cp_restore_empty($f,$restore);
$cipher=$f['backupDir'].'/commerce.sql.gz.cmjbkp'; $bytes=file_get_contents($cipher); cp_write($cipher,substr($bytes,0,-1));
cp_reject(fn()=>ip_caller($f)->run('apply'),'inventory_corrupt_archive_rejected'); cp_write($cipher,$bytes); unset($bytes);
$other=cmj_m4p_connect($base); $other->beginTransaction(); $other->query('SELECT * FROM worker_leases FOR UPDATE');
$start=microtime(true);
try { ip_caller($f)->run('apply'); throw new LogicException('inventory_timeout_not_rejected'); }
catch(PDOException $e) { cp_check(($e->errorInfo[1]??null)===1205 && microtime(true)-$start<30,'inventory_native_lock_timeout'); }
finally { $other->rollBack(); }
cp_check(cmj_icp_snapshot($source)===$f['snapshot'],'inventory_timeout_atomic_rollback');
$locked=false;
$result=ip_caller($f,static function(string $point) use($other,&$locked):void {
    if($point!=='before_commit')return; $locked=true;
    cp_timeout(fn()=>$other->exec('UPDATE commerce_inventory SET on_hand=99'),'inventory_admin_write_blocked');
    cp_timeout(fn()=>$other->exec("INSERT INTO worker_leases(worker_name) VALUES ('concurrent-worker')"),'inventory_worker_range_blocked');
})->run('apply');
cp_check($locked && $result['status']==='inventory_imported_and_compared' && $result['importedProducts']===2 && !$result['checkoutActivation'],'inventory_atomic_all_positions');
cp_restore_empty($f,$restore);
$after=cmj_icp_snapshot($source);
foreach(['apply','inspect'] as $action)cp_check(ip_caller($f,null,static function():never{throw new LogicException('replay_restore_forbidden');})->run($action)['status']==='already_imported','inventory_readonly_replay_'.$action);
cp_check(cmj_icp_snapshot($source)===$after,'inventory_replay_no_change');
$source->prepare('UPDATE commerce_inventory SET on_hand=3,inventory_version=2 WHERE product_id=?')->execute([$f['counts']['selectedProducts'][0]['productId']]);
cp_reject(fn()=>ip_caller($f)->run('apply'),'inventory_no_reset_after_later_adjustment','inventory_changed_after_import');
foreach($f['sources'] as $path=>$hash)cp_need(hash_file('sha256',$f['paths']['product'].'/'.$path)===$hash,'source_untouched');
cp_check(hash_file('sha256',$f['paths']['runtime'])===$f['approval']['runtimeSha256'],'inventory_runtime_and_sources_preserved');
$other=null; cp_clear($source); m4t_cleanup($f['case'],$root); unset($f);

$phase='inventory_deadlock'; $f=ip_fixture($root,'deadlock',$base,$source); $attempts=0; $child=null;
$result=ip_caller($f,static function(string $point) use(&$attempts,&$child,$source,$f):void {
    if($point!=='before_import')return; $attempts++;
    if($attempts!==1)return;
    $source->query('SELECT * FROM commerce_products FOR UPDATE');
    $child=ip_child('deadlock','deadlock'); $until=microtime(true)+10;
    while(!is_file($f['case'].'/child-ready') && microtime(true)<$until)usleep(20000);
    cp_need(is_file($f['case'].'/child-ready'),'child_ready'); cp_write($f['case'].'/parent-ready','ready');
})->run('apply');
ip_wait($child);
cp_check($result['status']==='inventory_imported_and_compared' && ($attempts>1 || file_get_contents($f['case'].'/child-result')==='1213'),'inventory_native_deadlock_and_recovery');
cp_check(ip_caller($f)->run('inspect')['status']==='already_imported','inventory_deadlock_no_duplicate');
cp_clear($source); m4t_cleanup($f['case'],$root); unset($f);

foreach(['before_import','before_commit','after_commit'] as $i=>$point) {
    $phase='inventory_crash_'.$point; $f=ip_fixture($root,'crash-'.$i,$base,$source);
    $s=ip_wait(ip_child('crash-'.$i,$point));
    cp_check($s['signaled'] && $s['termsig']===9,'inventory_sigkill_'.$point);
    if($point==='after_commit')cp_check(ip_caller($f)->run('inspect')['status']==='already_imported','inventory_commit_survives_crash');
    else {
        cp_check(cmj_icp_snapshot($source)===$f['snapshot'],'inventory_no_partial_rows_'.$point);
        cp_check(ip_caller($f)->run('apply')['status']==='inventory_imported_and_compared','inventory_restart_'.$point);
    }
    cp_restore_empty($f,$restore); cp_clear($source); m4t_cleanup($f['case'],$root); unset($f);
}
cmj_m4p_empty_restore($source); cmj_m4p_empty_restore($restore); cp_check(true,'inventory_both_databases_finally_empty');
cp_write($report,json_encode(['status'=>'passed','scenario'=>'inventory','sourceSha'=>getenv('GITHUB_SHA'),'php'=>PHP_VERSION,
    'mysql'=>$source->query('SELECT VERSION()')->fetchColumn(),'image'=>trim(file_get_contents($root.'/image-digest')),
    'checks'=>$checks,'databaseCleanup'=>'passed','productionAccess'=>false,'existingTestAccess'=>false,
    'offsiteProof'=>'owned_local_surrogate_not_cloud'],JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR));
echo 'INVENTORY_CALLER_MYSQL_PASSED checks='.count($checks)."\n";
