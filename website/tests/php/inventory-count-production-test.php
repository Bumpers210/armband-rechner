<?php
declare(strict_types=1);
// Artificial, file-backed SQLite DML oracle. No real MySQL/TLS/restore claim.
define('CMJ_MODEL4_TEST_HELPERS_ONLY', true);
require __DIR__ . '/product-model4-migration-test.php';
require __DIR__ . '/production-cart-import-test.php';
require_once dirname(__DIR__, 2) . '/test-api-private/program/production-backup.php';
require_once dirname(__DIR__, 2) . '/scripts/inventory-count-production.php';

final class IcpPdo extends PDO
{
    public bool $failSecondAdjustment = false;
    public bool $deadlock = false;
    private int $adjustments = 0;
    public function __construct(string $path) { parent::__construct('sqlite:' . $path, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]); }
    private function sql(string $sql): string {
        $sql = str_replace([' FOR UPDATE', 'UTC_TIMESTAMP(6)'], ['', "'2026-11-13 12:00:00'"], $sql);
        $sql = str_replace('ON DUPLICATE KEY UPDATE', 'ON CONFLICT(product_id) DO UPDATE SET', $sql);
        return preg_replace('/VALUES\(([a-z_]+)\)/', 'excluded.$1', $sql);
    }
    public function exec(string $statement): int|false { return str_starts_with($statement, 'SET ') ? 0 : parent::exec($this->sql($statement)); }
    public function prepare(string $query, array $options = []): PDOStatement|false {
        if (str_starts_with($query, 'UPDATE commerce_inventory') && ++$this->adjustments === 2 && ($this->failSecondAdjustment || $this->deadlock)) {
            if ($this->deadlock) { $this->deadlock = false; $e = new PDOException('artificial_deadlock'); $e->errorInfo = ['40001',1213,'artificial']; throw $e; }
            throw new RuntimeException('artificial_crash');
        }
        return parent::prepare($this->sql($query), $options);
    }
    public function query(string $query, ?int $fetchMode = null, mixed ...$args): PDOStatement|false {
        return $fetchMode === null ? parent::query($this->sql($query)) : parent::query($this->sql($query), $fetchMode, ...$args);
    }
}

function icp_write(string $path, mixed $value): string {
    file_put_contents($path, is_string($value) ? $value : cmj_model4_json($value)); chmod($path, 0600);
    return hash_file('sha256', $path);
}

function icp_fixture(string $root, string $name): array
{
    global $release;
    $f = m4t_fixture($root, $name);
    unset($f['pdo']);
    $db = new IcpPdo($f['private'] . '/fixture.sqlite');
    $reference = local_import_database($release);
    foreach (['commerce_inventory','legal_bundles','checkout_sagas','schema_migrations'] as $table) $db->exec('DROP TABLE ' . $table);
    foreach ($reference->query("SELECT name,sql FROM sqlite_master WHERE type='table'")->fetchAll(PDO::FETCH_ASSOC) as $table) {
        if ($table['name'] === 'commerce_products') continue;
        $db->exec($table['sql']);
        foreach ($reference->query('SELECT * FROM ' . $table['name'])->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $db->prepare('INSERT INTO ' . $table['name'] . ' VALUES (' . implode(',', array_fill(0, count($row), '?')) . ')')->execute(array_values($row));
        }
    }
    unset($reference);
    $db->exec('ALTER TABLE payments ADD COLUMN verification_status TEXT');
    $db->exec('ALTER TABLE payments ADD COLUMN order_id TEXT');
    foreach (['worker_leases'=>'lease_token TEXT,lease_until TEXT,last_success_at TEXT', 'stripe_metadata_outbox'=>'status TEXT','mail_outbox'=>'status TEXT'] as $table=>$columns) {
        $db->exec('CREATE TABLE ' . $table . '(' . $columns . ')');
    }
    $sources = []; $selected = []; $public = ['version'=>4,'products'=>[]];
    foreach (glob($f['private'] . '/drafts/*.json') as $file) {
        $draft = json_decode(file_get_contents($file), true);
        if (in_array($draft['productModelVersion'] ?? null, [3,4], true)) {
            $draft = cmj_model4_candidate($draft, '2026-11-13T11:00:00Z');
            $p = carmaja_api_v3_product_from_draft($draft);
            $db->prepare('UPDATE commerce_products SET product_version=?,source_hash=? WHERE product_id=?')->execute([$p['productVersion'],$p['sourceHash'],$p['productId']]);
            if ($draft['status'] === 'published') {
                $selected[] = ['productId'=>$p['productId'], 'expectedProductVersion'=>$p['productVersion'], 'expectedSourceHash'=>$p['sourceHash'],
                    'targetOnHand'=>4,'expectedInventoryVersion'=>0,'operationId'=>'count-' . $p['productId']];
                $public['products'][] = cmj_model4_projection($draft);
                icp_write($f['private'] . '/locks/draft-' . $p['productId'] . '.lock', '');
            }
            icp_write($file, $draft);
        }
        $sources['drafts/' . basename($file)] = hash_file('sha256', $file);
    }
    $sources['uploads/01.jpg'] = hash_file('sha256', $f['private'] . '/uploads/01.jpg');
    $shop = $f['private'] . '/shop'; $api = $f['private'] . '/api'; $job = $f['private'] . '/audit/inventory-artificial-20261113';
    foreach ([$shop,$api,$job,$shop.'/program',$shop.'/config',$shop.'/maintenance',$shop.'/inventory-tool',
        $shop.'/backups',$shop.'/backups/locks',$shop.'/backups/ready'] as $dir) mkdir($dir,0700);
    $paths = ['product'=>$f['private'],'shop'=>$shop,'api'=>$api,'job'=>$job,'tool'=>$shop.'/inventory-tool','runtime'=>$shop.'/config/runtime-config.php'];
    icp_write($job.'/import.lock','');
    $config = ['environment'=>'production','publishTarget'=>'production','privateDir'=>$shop,'productionPrivateDir'=>$shop,
        'productPrivateDir'=>$f['private'],'productionApiWebroot'=>$api,'backupDirectory'=>$shop.'/backups',
        'backupOffsiteTarget'=>'onedrive-pull://carmaja-production/Carmaja-Perlen/Backups',
        'backupEncryptionKeyFile'=>$shop.'/config/backup-key.php', 'backupEncryptionKey'=>base64_encode(str_repeat('x',32)), 'backupEncryptionKeyId'=>'artificial-key',
        'commerceDsn'=>'mysql:host=artificial.invalid;dbname=source;charset=utf8mb4','commerceRestoreDsn'=>'mysql:host=artificial.invalid;dbname=restore;charset=utf8mb4',
        'commerceUser'=>'source','commerceRestoreUser'=>'restore','commercePassword'=>'ARTIFICIAL_DO_NOT_PRINT','commerceRestorePassword'=>'ARTIFICIAL_DO_NOT_PRINT',
        'commerceRequireTls'=>true,'commerceRestoreRequireTls'=>true];
    foreach ([...CMJ_M4P_CLOSED_FLAGS,'productBrowserProductionEnabled'] as $key) $config[$key] = false;
    icp_write($paths['runtime'], '<?php return ' . var_export($config,true) . ';');
    icp_write($shop.'/config/backup-key.php', '<?php // artificial key binding only');
    icp_write($shop.'/maintenance/product-cleanup.lock', 'closed'); icp_write($shop.'/backups/locks/backup.lock','');
    $tool = ['format'=>'inventory-private-release-v1','sourceCommit'=>str_repeat('1',40),'workingTreeDirty'=>false,'toolFiles'=>[]];
    foreach (CMJ_ICP_TOOL_FILES as $file) $tool['toolFiles'][$file] = icp_write($paths['tool'].'/'.$file,'<?php // artificial bound program');
    $active = [...$release,'environment'=>'production','files'=>[]];
    foreach (['bootstrap.php','product-api.php','product-api-v2.php','product-api-v3.php','product-api-v4.php','product-api-v5.php',
        'product-maintenance-guard.php','product-browser.php','product-browser-http.php','commerce-core.php','cart-commerce.php','shop-admin.php','ap3-worker.php'] as $file) {
        $active['files'][] = ['path'=>'private/program/'.$file,'sha256'=>icp_write($shop.'/program/'.$file,'<?php // artificial active program')];
    }
    foreach (['public/index.php'=>$api.'/index.php','public/.htaccess'=>$api.'/.htaccess','private/worker.php'=>$shop.'/worker.php'] as $relative=>$file) {
        $active['files'][] = ['path'=>$relative,'sha256'=>icp_write($file,'artificial entry')];
    }
    $backupId = '20261113T120000Z-123456789abc'; $backup = $shop.'/backups/ready/'.$backupId; mkdir($backup,0700);
    $bm = ['formatVersion'=>1,'backupId'=>$backupId,'createdAt'=>'2026-11-13T12:00:00+00:00','keyId'=>'artificial-key','artifacts'=>[]];
    foreach (['commerce'=>'commerce.sql.gz.cmjbkp','productData'=>'product-data.tar.gz.cmjbkp'] as $kind=>$file) {
        $hash = icp_write($backup.'/'.$file,'ARTIFICIAL_ENCRYPTED_FIXTURE');
        $bm['artifacts'][$kind] = ['file'=>$file,'bytes'=>filesize($backup.'/'.$file),'sha256'=>$hash];
    }
    $bm['manifestHmac'] = hash_hmac('sha256',cmj_model4_json($bm),hash_hkdf('sha256',str_repeat('x',32),32,'carmaja-backup-manifest-v1'));
    $bh = icp_write($backup.'/manifest.json',$bm); icp_write($backup.'/ready',$bh);
    icp_write($backup.'/download-ack.json',['backupId'=>$backupId,'manifestSha256'=>$bh,'downloadedAt'=>'2026-11-13T12:01:00Z']);
    $counts = json_decode(file_get_contents(dirname(__DIR__,2).'/config/production-cart-cutover-manifest.v3.json'),true);
    $counts = [...$counts,'status'=>'not_approved','selectedProducts'=>array_reverse($selected),'countConfirmedAt'=>'2026-11-13T12:00:00Z',
        'actorId'=>'artificial-operator','releaseSha'=>$active['sourceCommit'],'legalBundleHash'=>$active['legalBundleHash'],'backupId'=>$backupId,'restoreProofSha256'=>null];
    $snapshot = cmj_icp_snapshot($db);
    $approval = ['format'=>'inventory-import-approval-v1','approved'=>true,'environment'=>'production','inventoryImportAllowed'=>true,
        'checkoutActivation'=>false,'publicationAllowed'=>false,'operationId'=>basename($job),'releaseSha'=>$tool['sourceCommit'],
        'issuedAt'=>'2026-11-13T12:01:00Z','expiresAt'=>'2026-11-13T13:00:00Z','actions'=>['preflight','apply','inspect'],
        'targetHash'=>cmj_m4_target($f['private'],$db), 'backupId'=>$backupId,'backupManifestSha256'=>$bh,
        'runtimeSha256'=>hash_file('sha256',$paths['runtime']),'keyFileSha256'=>hash_file('sha256',$shop.'/config/backup-key.php'),
        'maintenanceSha256'=>hash_file('sha256',$shop.'/maintenance/product-cleanup.lock'),
        'countsSha256'=>icp_write($job.'/counts.json',$counts),'databaseSha256'=>icp_write($job.'/before-database.json',$snapshot),
        'preservedSha256'=>icp_write($job.'/preserved-rows.json',cmj_icp_preserved($db,$counts['selectedProducts'])),
        'sourcesSha256'=>icp_write($job.'/source-files.json',$sources),'publicReferenceSha256'=>icp_write($job.'/public-reference.json',$public),
        'releaseManifestSha256'=>icp_write($job.'/release.json',$tool),'activePackageSha256'=>icp_write($job.'/active-package.json',$active)];
    $approvalHash = icp_write($job.'/approval.json',$approval);
    return compact('paths','db','config','approval','approvalHash','snapshot','sources','counts','backup');
}

function icp_caller(array $f, ?Closure $point = null, ?Closure $restore = null, ?Closure $clock = null): CarmajaInventoryProductionCaller
{
    return new CarmajaInventoryProductionCaller($f['paths'],$f['db'],$f['config'],$f['approvalHash'],
        $restore ?? static function ($config,$id,$snapshot,$files) use ($f): void {
            m4t_check(!$f['db']->inTransaction(),'restore_outside_transaction');
            m4t_check(cmj_icp_snapshot($f['db']) === $snapshot,'artificial_restore_comparison');
            foreach ($files as $relative=>$hash) m4t_check(hash_file('sha256',$f['paths']['product'].'/'.$relative) === $hash,'artificial_file_comparison');
        }, $point, $clock ?? static fn () => strtotime('2026-11-13T12:02:00Z'));
}

$root = str_replace('\\','/',sys_get_temp_dir()).'/cmj-inventory-test-'.bin2hex(random_bytes(6)); mkdir($root,0700);
try {
    $f = icp_fixture($root,'success');
    $before = $f['snapshot'];
    m4t_check(icp_caller($f)->run('preflight')['status'] === 'preflight_passed','preflight');
    m4t_check(cmj_icp_snapshot($f['db']) === $before,'preflight_read_only');
    $phases = [];
    $result = icp_caller($f,function ($phase) use (&$phases,$f) { $phases[]=$phase; m4t_check($f['db']->inTransaction() === ($phase !== 'after_commit'),'same_transaction'); })->run('apply');
    m4t_check($result['status'] === 'inventory_imported_and_compared' && $result['importedProducts'] === 2 && !$result['checkoutActivation'],'complete');
    m4t_check($phases === ['before_import','before_commit','after_commit'],'all_boundaries');
    m4t_check((int)$f['db']->query('SELECT SUM(on_hand) FROM commerce_inventory')->fetchColumn() === 8,'quantities');
    $after = cmj_icp_snapshot($f['db']);
    $receipts=glob($f['paths']['job'].'/restore-receipt-*.json');
    m4t_check(count($receipts)===1,'restore_receipt_recorded');
    $receipt=json_decode(file_get_contents($receipts[0]),true); $mac=$receipt['hmac']; unset($receipt['hmac']);
    m4t_check(hash_equals($mac,hash_hmac('sha256',cmj_model4_json($receipt),hash_hkdf('sha256',str_repeat('x',32),32,'carmaja-inventory-restore-v1'))),'receipt_authenticated');
    foreach (['apply','inspect'] as $action) m4t_check(icp_caller($f,null,static function (): never { throw new RuntimeException('unexpected_restore'); })->run($action)['status'] === 'already_imported','read_only_replay');
    m4t_check(cmj_icp_snapshot($f['db']) === $after,'replay_unchanged');
    $f['db']->exec('UPDATE commerce_inventory SET on_hand=3,inventory_version=2');
    m4t_reject(fn () => icp_caller($f)->run('apply'),'inventory_changed_after_import');
    m4t_check((int)$f['db']->query('SELECT SUM(on_hand) FROM commerce_inventory')->fetchColumn() === 6,'no_reset_on_replay');
    unset($f);

    foreach (['before_import','before_commit','after_commit','second_adjustment','deadlock'] as $phase) {
        $f = icp_fixture($root,$phase); $f['db']->failSecondAdjustment = $phase === 'second_adjustment'; $f['db']->deadlock = $phase === 'deadlock';
        $point = static function ($at) use ($phase): void { if ($at === $phase) throw new RuntimeException('artificial_crash'); };
        if ($phase === 'deadlock') m4t_check(icp_caller($f,$point)->run('apply')['status'] === 'inventory_imported_and_compared','deadlock_retry');
        else m4t_reject(fn () => icp_caller($f,$point)->run('apply'),'artificial_crash');
        m4t_check(!$f['db']->inTransaction(),'transaction_closed');
        if (!in_array($phase,['after_commit','deadlock'],true)) {
            m4t_check(cmj_icp_snapshot($f['db']) === $f['snapshot'],'all_or_nothing');
            $f['db']->failSecondAdjustment=false;
            m4t_check(icp_caller($f)->run('apply')['status'] === 'inventory_imported_and_compared','retry');
        } else m4t_check(icp_caller($f)->run('inspect')['status'] === 'already_imported','committed_inspection');
        unset($f);
    }
    foreach ([...CMJ_M4P_CLOSED_FLAGS,'productBrowserProductionEnabled'] as $flag) {
        $f=icp_fixture($root,'gate-'.$flag); $f['config'][$flag]=true;
        m4t_reject(fn () => icp_caller($f),'runtime_gate_open'); m4t_check(cmj_icp_snapshot($f['db'])===$f['snapshot'],'closed_flag'); unset($f);
    }
    foreach (['approved'=>false,'inventoryImportAllowed'=>false,'checkoutActivation'=>true,'publicationAllowed'=>true] as $key=>$value) {
        $f=icp_fixture($root,'approval-'.$key); $f['approval'][$key]=$value; $f['approvalHash']=icp_write($f['paths']['job'].'/approval.json',$f['approval']);
        m4t_reject(fn () => icp_caller($f),'execution_not_approved'); unset($f);
    }
    foreach (['runtime','source','image','marker','tool','active','counts','snapshot'] as $kind) {
        $f=icp_fixture($root,'tamper-'.$kind); $caller=icp_caller($f);
        $file=match($kind) {
            'runtime'=>$f['paths']['runtime'], 'source'=>$f['paths']['product'].'/drafts/'.$f['counts']['selectedProducts'][0]['productId'].'.json',
            'image'=>$f['paths']['product'].'/uploads/01.jpg','marker'=>$f['paths']['shop'].'/maintenance/product-cleanup.lock',
            'tool'=>$f['paths']['tool'].'/cart-commerce.php','active'=>$f['paths']['shop'].'/program/bootstrap.php',
            'counts'=>$f['paths']['job'].'/counts.json','snapshot'=>$f['paths']['job'].'/before-database.json',
        };
        file_put_contents($file,"\ntampered",FILE_APPEND);
        m4t_reject(fn () => $caller->run('apply'),'bound_file_changed');
        m4t_check(cmj_icp_snapshot($f['db'])===$f['snapshot'],'no_write_on_tamper'); unset($caller,$f);
    }
    foreach (['payments'=>"INSERT INTO payments(status) VALUES ('processing')",'mail'=>"INSERT INTO mail_outbox VALUES ('delivery_unknown')",
        'lease'=>"INSERT INTO worker_leases VALUES ('artificial','2099-01-01',NULL)",'reservation'=>"INSERT INTO reservations VALUES ('artificial',1,'expired',1)"] as $key=>$sql) {
        $f=icp_fixture($root,'drain-'.$key); $f['db']->exec($sql);
        m4t_reject(fn () => icp_caller($f)->run('apply'),'business_not_drained'); unset($f);
    }
    $f=icp_fixture($root,'restore-failure');
    m4t_reject(fn () => icp_caller($f,null,static function (): never { throw new RuntimeException('artificial_restore_failure'); })->run('apply'),'artificial_restore_failure');
    m4t_check(cmj_icp_snapshot($f['db'])===$f['snapshot'],'failed_restore_no_import'); unset($f);
    $f=icp_fixture($root,'restore-race');
    m4t_reject(fn () => icp_caller($f,null,static function () use ($f): void { $f['db']->exec("INSERT INTO orders VALUES ('artificial concurrent write')"); })->run('apply'),'database_changed');
    m4t_check((int)$f['db']->query('SELECT COUNT(*) FROM inventory_adjustments')->fetchColumn()===0,'race_no_import'); unset($f);
    $f=icp_fixture($root,'unrelated-write');
    m4t_reject(fn () => icp_caller($f,static function ($phase) use ($f): void { if($phase==='before_commit') $f['db']->exec("UPDATE orders SET historical='bad'"); })->run('apply'),'unrelated_data_changed');
    m4t_check(cmj_icp_snapshot($f['db'])===$f['snapshot'],'unrelated_rollback'); unset($f);
    $f=icp_fixture($root,'unselected-write');
    m4t_reject(fn () => icp_caller($f,static function ($phase) use ($f): void { if($phase==='before_commit') $f['db']->exec("UPDATE commerce_products SET price_minor=1 WHERE product_id='10000000-0000-4000-8000-000000000009'"); })->run('apply'),'unselected_data_changed');
    m4t_check(cmj_icp_snapshot($f['db'])===$f['snapshot'],'unselected_rollback'); unset($f);
    $f=icp_fixture($root,'expired'); m4t_reject(fn () => icp_caller($f,null,null,static fn()=>strtotime('2026-11-13T13:00:00Z')),'approval_expired'); unset($f);
    $f=icp_fixture($root,'private-product');
    $public=['version'=>4,'products'=>[]]; $f['approval']['publicReferenceSha256']=icp_write($f['paths']['job'].'/public-reference.json',$public);
    $f['approvalHash']=icp_write($f['paths']['job'].'/approval.json',$f['approval']);
    m4t_reject(fn()=>icp_caller($f),'selection_not_public'); unset($f);
    $f=icp_fixture($root,'backup-lock'); $h=fopen($f['paths']['shop'].'/backups/locks/backup.lock','r'); flock($h,LOCK_EX);
    m4t_reject(fn()=>icp_caller($f)->run('apply'),'backup_busy'); flock($h,LOCK_UN); fclose($h); unset($f);
    $f=icp_fixture($root,'offsite'); unlink($f['backup'].'/download-ack.json');
    m4t_reject(fn()=>icp_caller($f)->run('apply'),'unsafe_path'); unset($f);
    $f=icp_fixture($root,'deadline'); $caller=null;
    $caller=icp_caller($f,static function ($phase) use (&$caller): void {
        if($phase==='before_commit') (new ReflectionProperty($caller,'deadline'))->setValue($caller,microtime(true)-1);
    });
    m4t_reject(fn()=>$caller->run('apply'),'inventory_timeout');
    m4t_check(cmj_icp_snapshot($f['db'])===$f['snapshot'],'timeout_rolls_back'); unset($caller,$f);
    $f=icp_fixture($root,'backup-hmac');
    $bm=json_decode(file_get_contents($f['backup'].'/manifest.json'),true); $bm['manifestHmac']=str_repeat('a',64);
    $f['approval']['backupManifestSha256']=icp_write($f['backup'].'/manifest.json',$bm); icp_write($f['backup'].'/ready',$f['approval']['backupManifestSha256']);
    $f['approvalHash']=icp_write($f['paths']['job'].'/approval.json',$f['approval']);
    m4t_reject(fn()=>icp_caller($f)->run('apply'),'backup_authentication'); unset($f);
    $f=icp_fixture($root,'backup-artifact'); file_put_contents($f['backup'].'/commerce.sql.gz.cmjbkp','tamper');
    m4t_reject(fn()=>icp_caller($f)->run('apply'),'backup_artifact'); unset($f);
    $f=icp_fixture($root,'version'); $f['counts']['selectedProducts'][0]['expectedProductVersion']++;
    $f['approval']['countsSha256']=icp_write($f['paths']['job'].'/counts.json',$f['counts']); $f['approvalHash']=icp_write($f['paths']['job'].'/approval.json',$f['approval']);
    m4t_reject(fn()=>icp_caller($f),'product_version_or_hash_conflict'); unset($f);
    $f=icp_fixture($root,'initial-conflict');
    $f['db']->prepare('INSERT INTO inventory_initializations VALUES (?,NULL)')->execute([$f['counts']['selectedProducts'][0]['productId']]);
    $f['snapshot']=cmj_icp_snapshot($f['db']); $f['approval']['databaseSha256']=icp_write($f['paths']['job'].'/before-database.json',$f['snapshot']);
    $f['approvalHash']=icp_write($f['paths']['job'].'/approval.json',$f['approval']);
    m4t_reject(fn()=>icp_caller($f)->run('apply'),'pending_initial_stock_conflict');
    m4t_check(cmj_icp_snapshot($f['db'])===$f['snapshot'],'staged_count_not_applied'); unset($f);
    $f=icp_fixture($root,'journal'); $f['db']->exec("UPDATE schema_migrations SET checksum='changed'");
    m4t_reject(fn()=>icp_caller($f)->run('apply'),'schema_journal_conflict'); unset($f);
    $f=icp_fixture($root,'same-restore'); $f['config']['commerceRestoreDsn']=$f['config']['commerceDsn'];
    m4t_reject(fn()=>icp_caller($f),'restore_target_not_separate'); unset($f);
    $f=icp_fixture($root,'tls-disabled'); $f['config']['commerceRequireTls']=false;
    m4t_reject(fn()=>icp_caller($f),'tls_required'); unset($f);
} finally {
    unset($f,$caller);
    gc_collect_cycles(); m4t_cleanup($root,$root);
}
m4t_check(!file_exists($root),'cleanup_complete');
echo "Inventory production caller: {$m4Checks} local checks passed; no real MySQL/restore or production access.\n";
