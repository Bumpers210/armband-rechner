<?php
declare(strict_types=1);

// Test harness only: no runtime lookup, provider calls, or remote credentials.
ini_set('display_errors', '0');
ini_set('log_errors', '0');
umask(0077);
$checks = [];
$reportAuthorized = false;
function ensure(bool $condition, string $code): void {
    if (!$condition) { throw new LogicException($code); }
}
function check(bool $condition, string $code): void {
    global $checks;
    ensure($condition, $code); $checks[] = $code;
    echo "PASS $code\n";
}
function reject(callable $call, string $code, ?string $errorCode = null): void {
    try { $call(); }
    catch (Throwable $error) {
        if ($errorCode !== null) { ensure(($error->errorCode ?? null) === $errorCode, $code . '_wrong_error'); }
        check(true, $code); return;
    }
    throw new LogicException($code . '_not_rejected');
}
function privateFile(string $path, string $bytes): void {
    ensure(file_put_contents($path, $bytes, LOCK_EX) === strlen($bytes), 'fixture_write_failed');
    ensure(chmod($path, 0600), 'fixture_mode_failed');
}
function jsonFile(string $path): array {
    return json_decode((string) file_get_contents($path), true, 128, JSON_THROW_ON_ERROR);
}
function connect(array $config, bool $restore = false): PDO {
    $prefix = $restore ? 'commerceRestore' : 'commerce';
    $pdo = new PDO($config[$prefix . 'Dsn'], $config[$prefix . 'User'], $config[$prefix . 'Password'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false,
        PDO::MYSQL_ATTR_SSL_CA => $config[$prefix . 'TlsCaPath'], PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT => true,
    ]);
    $pdo->exec("SET time_zone='+00:00'");
    $tls = $pdo->query("SHOW SESSION STATUS WHERE Variable_name IN ('Ssl_cipher','Ssl_version')")->fetchAll(PDO::FETCH_KEY_PAIR);
    ensure(!empty($tls['Ssl_cipher']) && !empty($tls['Ssl_version']), 'tls_required');
    return $pdo;
}
function tables(PDO $pdo): array {
    return $pdo->query('SHOW FULL TABLES WHERE Table_type=\'BASE TABLE\'')->fetchAll(PDO::FETCH_COLUMN);
}
function clearOwned(PDO $pdo): void {
    ensure(in_array($pdo->query('SELECT DATABASE()')->fetchColumn(), ['cmj_package_source', 'cmj_package_restore'], true), 'cleanup_target_rejected');
    if ($pdo->inTransaction()) { $pdo->rollBack(); }
    $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
    try { foreach (tables($pdo) as $table) { $pdo->exec('DROP TABLE `' . str_replace('`', '``', $table) . '`'); } }
    finally { $pdo->exec('SET FOREIGN_KEY_CHECKS=1'); }
}
function rows(PDO $pdo, string $table): array {
    $rows = $pdo->query('SELECT * FROM `' . $table . '`')->fetchAll(PDO::FETCH_ASSOC);
    $encoded = array_map(static fn ($row) => serialize($row), $rows); sort($encoded, SORT_STRING);
    return $encoded;
}
function fingerprint(PDO $pdo): array {
    $result = [];
    foreach (tables($pdo) as $table) {
        $ddl = $pdo->query('SHOW CREATE TABLE `' . $table . '`')->fetch(PDO::FETCH_NUM)[1];
        // MySQL legitimately consumes AUTO_INCREMENT numbers on rolled-back inserts.
        $ddl = preg_replace('/ AUTO_INCREMENT=[0-9]+/', '', $ddl);
        $result[$table] = [hash('sha256', $ddl), hash('sha256', serialize(rows($pdo, $table)))];
    }
    ksort($result); return $result;
}
function backup(array $config): CarmajaProductionBackup {
    return new CarmajaProductionBackup($config, '/usr/bin/mariadb-dump', '/usr/bin/mariadb', '/usr/bin/tar');
}
function migrate(PDO $pdo, string $package, array $release, int $count = 5): void {
    $commerce = new CarmajaCommercePdo($pdo);
    foreach (array_slice($release['migrations'], 0, $count) as $index => $migration) {
        if ($index === 0) {
            if (!in_array('schema_migrations', tables($pdo), true)) { $commerce->migrate($package . '/' . $migration['path']); }
        } else { $commerce->migrateForward($migration['migrationId'], $package . '/' . $migration['path']); }
    }
}
function seedLegal(PDO $pdo, array $release): void {
    $pdo->prepare("INSERT INTO legal_bundles (legal_bundle_id,terms_version,privacy_version,withdrawal_version,shipping_version,merchant_version,bundle_hash,status) VALUES (?,'fixture','fixture','fixture','fixture','fixture',?,'approved')")
        ->execute([$release['legalBundleId'], $release['legalBundleHash']]);
}
function product(string $id): array {
    $product = ['productModelVersion'=>4, 'productId'=>$id, 'productVersion'=>2,
        'title'=>'Künstlich – ÄÖÜ 💎', 'name'=>'Künstlich – ÄÖÜ 💎', 'description'=>'Nur isolierte CI-Testdaten',
        'descriptionDocument'=>['paragraphs'=>[]], 'priceMinor'=>2800, 'currency'=>'eur', 'salesEnabled'=>false,
        'braceletSizeCm'=>18, 'pearlSizeMm'=>8, 'images'=>[['fileName'=>'01.jpg']], 'noticePolicyId'=>null, 'notices'=>[]];
    $product['sourceHash'] = carmaja_api_v3_source_hash($product); return $product;
}
function maintenance(): array {
    // Simulates the adapter's production contract on loopback-only artificial DBs.
    return ['environment'=>'production', 'shopCheckoutEnabled'=>false, 'productApiV5WritesEnabled'=>false,
        'productApiV4WritesEnabled'=>false, 'productionPublishEnabled'=>false, 'githubAdapterEnabled'=>false, 'commerceRequireTls'=>true];
}
function historicalOrder(PDO $pdo, array $release): string {
    $commerce = new CarmajaCommercePdo($pdo);
    $p = product('ci-historical-0001'); $p['salesEnabled'] = true;
    $p['sourceHash'] = carmaja_api_v3_source_hash($p);
    $commerce->projectCollectionProduct($p, 'publish', 'ci-historical-publish', hash('sha256', 'historical-publish'));
    $id = carmaja_commerce_new_id();
    $commerce->createCheckout(['checkoutId'=>$id, 'idempotencyKey'=>'ci-historical-checkout', 'requestHash'=>hash('sha256', 'historical-checkout'),
        'productId'=>$p['productId'], 'productVersion'=>2, 'sourceHash'=>$p['sourceHash'], 'priceMinor'=>2800, 'currency'=>'eur',
        'salesModel'=>'collection', 'shippingSnapshot'=>['shippingMethodId'=>'maxibrief','priceMinor'=>270,'currency'=>'eur','country'=>'DE'],
        'legalBundleId'=>$release['legalBundleId'], 'expiresAt'=>gmdate('Y-m-d H:i:s', time()+1800)]);
    $amount = (int) $pdo->query('SELECT amount_minor FROM payments')->fetchColumn();
    $order = $commerce->finalizePayment($id, ['paymentStatus'=>'succeeded', 'paymentIntentStatus'=>'succeeded', 'paymentMethodType'=>'card',
        'stripePaymentIntentId'=>'pi_artificial_history', 'amountMinor'=>$amount, 'currency'=>'eur', 'productId'=>$p['productId'],
        'legalBundleId'=>$release['legalBundleId'], 'termsAccepted'=>true, 'customerEmail'=>'artificial@example.invalid',
        'customerName'=>'Künstliche Kundin 💎', 'shippingAddress'=>['line1'=>'Teststraße 1','city'=>'Berlin','postal_code'=>'10115','country'=>'DE']]);
    ensure(($order['status'] ?? null) === 'confirmed', 'historical_order_fixture_failed');
    return $id;
}

try {
    ensure(getenv('GITHUB_ACTIONS') === 'true' && getenv('RUNNER_ENVIRONMENT') === 'github-hosted'
        && getenv('RUNNER_OS') === 'Linux' && getenv('GITHUB_REPOSITORY') === 'Bumpers210/armband-rechner'
        && getenv('GITHUB_REF') === 'refs/heads/codex/cart-package-mysql-test', 'github_fixture_only');
    $mode = $argv[1] ?? '';
    ensure(is_string(getenv('RUNNER_TEMP')) && realpath(getenv('RUNNER_TEMP')) === getenv('RUNNER_TEMP'), 'report_root_rejected');
    $report = getenv('RUNNER_TEMP') . '/cart-package-mysql-report.json';
    $reportAuthorized = true;
    if ($mode === 'cleanup-report') {
        ensure(($argv[2] ?? '') === $report, 'report_path_rejected');
        $data = is_file($report) ? jsonFile($report) : ['status'=>'failed_before_tests'];
        $data['resourceCleanup'] = 'passed';
        privateFile($report, json_encode($data, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n"); exit;
    }
    $root = '/home/www/carmaja-private-test/cart-package-ci-' . getenv('GITHUB_RUN_ID') . '-' . getenv('GITHUB_RUN_ATTEMPT');
    ensure(($argv[2] ?? '') === $root && realpath($root) === $root && !is_link($root), 'fixture_path_rejected');
    ensure(file_get_contents($root . '/.owner') === getenv('GITHUB_RUN_ID') . '-' . getenv('GITHUB_RUN_ATTEMPT'), 'fixture_owner_rejected');
    ensure(PHP_VERSION_ID >= 80400 && PHP_VERSION_ID < 80500, 'php84_required');
    if ($mode === 'setup') {
        mkdir($root . '/setup', 0700); mkdir($root . '/product-source', 0700);
        $rootPassword = bin2hex(random_bytes(24)); $sourcePassword = bin2hex(random_bytes(24)); $restorePassword = bin2hex(random_bytes(24));
        privateFile($root . '/setup/root-password', $rootPassword);
        privateFile($root . '/setup/root.cnf', "[client]\nuser=root\npassword=$rootPassword\nprotocol=socket\n");
        privateFile($root . '/setup/init.sql', "CREATE DATABASE cmj_package_source CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;\nCREATE DATABASE cmj_package_restore CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;\nCREATE USER 'ci_source'@'%' IDENTIFIED BY '$sourcePassword' REQUIRE SSL;\nCREATE USER 'ci_restore'@'%' IDENTIFIED BY '$restorePassword' REQUIRE SSL;\nGRANT ALL PRIVILEGES ON cmj_package_source.* TO 'ci_source'@'%';\nGRANT ALL PRIVILEGES ON cmj_package_restore.* TO 'ci_restore'@'%';\n");
        $config = ['environment'=>'test','publishTarget'=>'test','backupTestMode'=>true,'privateDir'=>$root,
            'runtimeFile'=>$root . '/runtime-config.php','productPrivateDir'=>$root . '/product-source',
            'commerceDsn'=>'mysql:host=127.0.0.1;port=13306;dbname=cmj_package_source;charset=utf8mb4',
            'commerceUser'=>'ci_source','commercePassword'=>$sourcePassword,'commerceRequireTls'=>true,'commerceTlsCaPath'=>$root . '/tls/ca.pem',
            'commerceRestoreDsn'=>'mysql:host=127.0.0.1;port=13306;dbname=cmj_package_restore;charset=utf8mb4',
            'commerceRestoreUser'=>'ci_restore','commerceRestorePassword'=>$restorePassword,'commerceRestoreRequireTls'=>true,'commerceRestoreTlsCaPath'=>$root . '/tls/ca.pem',
            'backupDirectory'=>$root . '/backups','backupOffsiteTarget'=>'test-sink://ap7-backup-e2e',
            'backupEncryptionKey'=>base64_encode(random_bytes(32)),'backupEncryptionKeyId'=>'isolated-ci-only',
            'productionPublishEnabled'=>false,'githubAdapterEnabled'=>false];
        privateFile($config['runtimeFile'], "<?php\nreturn " . var_export($config, true) . ";\n"); exit;
    }
    $package = getenv('GITHUB_WORKSPACE') . '/package-source/website/.production-cart-package';
    ensure(($argv[3] ?? '') === $package && realpath($package) === $package, 'package_path_rejected');
    require_once $package . '/private/program/production-cart-cutover.php';
    require_once $package . '/private/program/production-backup.php';
    $config = CarmajaProductionBackup::loadRuntime($root . '/runtime-config.php');
    $release = jsonFile($package . '/manifest.json');
    ensure($release['sourceCommit'] === getenv('PACKAGE_SOURCE_SHA') && $release['workingTreeDirty'] === false, 'pinned_clean_package_required');
    ensure($release['deployAuthorized'] === false && $release['migrationAuthorized'] === false && $release['checkoutAuthorized'] === false, 'release_gates_required');
    foreach ($release['files'] as $file) {
        ensure(filesize($package . '/' . $file['path']) === $file['bytes'] && hash_file('sha256', $package . '/' . $file['path']) === $file['sha256'], 'package_integrity_failed');
    }
    if (in_array($mode, ['crash', 'parallel'], true)) {
        $fixture = jsonFile($root . '/import-fixture.json');
        if ($mode === 'crash') {
            class CrashPdo extends PDO {
                private int $updates = 0;
                public function prepare(string $query, array $options = []): PDOStatement|false {
                    if (str_starts_with($query, 'UPDATE commerce_inventory') && ++$this->updates === 2) { posix_kill(getmypid(), SIGKILL); }
                    return parent::prepare($query, $options);
                }
            }
            $pdo = new CrashPdo($config['commerceDsn'], $config['commerceUser'], $config['commercePassword'], [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
                PDO::MYSQL_ATTR_SSL_CA=>$config['commerceTlsCaPath'], PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT=>true]);
        } else { $pdo = connect($config); }
        for ($attempt = 0; $attempt < 4; $attempt++) {
            try { CarmajaCartCutover::importCounts($pdo, maintenance(), $fixture['manifest'], $fixture['products'], $release); exit($mode === 'crash' ? 21 : 0); }
            catch (PDOException $error) {
                if ($mode !== 'parallel' || !in_array($error->errorInfo[1] ?? 0, [1205,1213], true)) { throw $error; }
                if ($pdo->inTransaction()) { $pdo->rollBack(); } usleep(100000 * ($attempt + 1));
            }
        }
        exit(22);
    }
    ensure($mode === 'test' && ($argv[4] ?? '') === $report, 'test_mode_required');
    $source = connect($config); $restore = connect($config, true);
    $version = (string) $source->query('SELECT VERSION()')->fetchColumn();
    check(str_starts_with($version, '8.0.') && !str_contains($version, 'MariaDB'), 'mysql8_server');
    check($source->query('SELECT DATABASE()')->fetchColumn() !== $restore->query('SELECT DATABASE()')->fetchColumn(), 'separate_databases');
    check(tables($source) === [] && tables($restore) === [], 'both_targets_initially_empty');
    $cleanupAuthorized = true;
    check((int) $source->query('SELECT @@require_secure_transport')->fetchColumn() === 1, 'server_requires_tls');
    check(true, 'pdo_tls_with_ca_and_identity_verification_both_targets');
    reject(fn () => new PDO($config['commerceDsn'], $config['commerceUser'], $config['commercePassword'], [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]), 'plaintext_connection_rejected');
    $restore->exec('CREATE TABLE ci_access_guard(id INT PRIMARY KEY) ENGINE=InnoDB');
    try {
        try { $source->query('SELECT COUNT(*) FROM cmj_package_restore.ci_access_guard'); throw new LogicException('cross_database_access_allowed'); }
        catch (PDOException $error) { check(in_array($error->errorInfo[1] ?? 0, [1044,1142], true), 'source_cannot_read_restore'); }
    } finally { $restore->exec('DROP TABLE ci_access_guard'); }
    $runtimeBefore = hash_file('sha256', $config['runtimeFile']);
    $phase = 'empty_migration';
    migrate($source, $package, $release);
    CarmajaCartCutover::assertJournal($source, $release, true);
    check(count(tables($source)) === 38, 'empty_schema_all_five_migrations');
    check((int) $source->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND engine<>'InnoDB'")->fetchColumn() === 0, 'all_tables_innodb');
    $before = fingerprint($source); migrate($source, $package, $release);
    check($before === fingerprint($source), 'migration_journal_idempotent');
    $source->exec("UPDATE schema_migrations SET checksum=REPEAT('0',64) WHERE migration_id='commerce-v3-cart-inventory'");
    reject(fn () => migrate($source, $package, $release), 'migration_checksum_conflict', 'migration_checksum_conflict');
    clearOwned($source);

    $phase = 'historical_migration_and_backup';
    migrate($source, $package, $release, 4); seedLegal($source, $release);
    $historyCheckout = historicalOrder($source, $release);
    $history = [];
    foreach (['orders','order_items','legal_bundles','shipments','mail_outbox','stripe_metadata_outbox'] as $table) { $history[$table] = rows($source, $table); }
    foreach (['products','drafts','uploads/CI-ARTIFICIAL','auth','audit','idempotency'] as $dir) { mkdir($config['productPrivateDir'] . '/' . $dir, 0700, true); }
    $products = [product('ci-counted-0002'), product('ci-counted-0001')];
    foreach ($products as $p) { privateFile($config['productPrivateDir'] . '/products/' . $p['productId'] . '.json', json_encode($p, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)); }
    privateFile($config['productPrivateDir'] . '/drafts/artificial.json', '{"description":"ÄÖÜ ß 💎"}');
    privateFile($config['productPrivateDir'] . '/uploads/CI-ARTIFICIAL/01.jpg', random_bytes(4096));
    privateFile($config['productPrivateDir'] . '/sku-counter', "3\n");
    foreach (['auth','audit','idempotency'] as $dir) { privateFile($config['productPrivateDir'] . '/' . $dir . '/artificial.json', '{"ciOnly":true}'); }
    $productBefore = []; // Digests only, without logging file contents.
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($config['productPrivateDir'], FilesystemIterator::SKIP_DOTS)) as $file) {
        if ($file->isFile()) { $productBefore[$file->getPathname()] = hash_file('sha256', $file->getPathname()); }
    }
    $backup = backup($config);
    $beforeBackup = fingerprint($source);
    $created = $backup->create();
    $restored = $backup->restoreDryRun($created['backupId']);
    check($restored['status'] === 'restored_and_compared' && tables($restore) === [], 'pre_migration_backup_restore_all_structures_contents_files');
    check($beforeBackup === fingerprint($source), 'backup_restore_does_not_mutate_source');
    migrate($source, $package, $release);
    foreach ($history as $table => $values) { check(rows($source, $table) === $values, 'history_preserved_' . $table); }
    check((int) $source->query('SELECT quantity FROM order_items')->fetchColumn() === 1, 'historical_single_item_quantity_one');
    $payment = $source->query('SELECT * FROM payments')->fetch(PDO::FETCH_ASSOC);
    check($payment['status'] === 'succeeded' && (int)$payment['refunded_minor'] === 0, 'historical_payment_preserved');
    CarmajaCartCutover::assertJournal($source, $release, true);
    check((int)$source->query('SELECT COUNT(*) FROM commerce_inventory')->fetchColumn() === 0, 'migration_does_not_infer_collection_quantities');

    $phase = 'atomic_import';
    $template = jsonFile($package . '/cutover-template.json');
    check(!CarmajaCartCutover::plan($template, [], $release)['readyForApply'], 'unapproved_package_template_stays_closed');
    $manifest = [...$template,'status'=>'approved_for_cutover','releaseSha'=>$release['sourceCommit'],
        'legalBundleHash'=>$release['legalBundleHash'],'backupId'=>$created['backupId'],
        'restoreProofSha256'=>hash('sha256', json_encode($restored, JSON_THROW_ON_ERROR)),
        'countConfirmedAt'=>gmdate('Y-m-d\TH:i:s\Z'),'actorId'=>'isolated-ci-operator',
        'selectedProducts'=>array_map(static fn ($p) => ['productId'=>$p['productId'],'expectedProductVersion'=>2,
            'expectedSourceHash'=>$p['sourceHash'],'targetOnHand'=>3,'expectedInventoryVersion'=>0,'operationId'=>'ci-count-' . $p['productId']], $products)];
    privateFile($root . '/import-fixture.json', json_encode(['manifest'=>$manifest,'products'=>$products], JSON_THROW_ON_ERROR));
    $beforeImport = fingerprint($source);
    $child = proc_open([PHP_BINARY,__FILE__,'crash',$root,$package], [0=>['file','/dev/null','r'],1=>['file','/dev/null','w'],2=>['file','/dev/null','w']], $pipes);
    ensure(is_resource($child), 'crash_child_start_failed');
    do { usleep(50000); $childStatus = proc_get_status($child); } while ($childStatus['running']);
    proc_close($child);
    check($childStatus['signaled'] && $childStatus['termsig'] === SIGKILL, 'real_process_crash_during_second_inventory_adjustment');
    check(fingerprint($source) === $beforeImport, 'crash_rolls_back_all_products_quantities_and_audits');
    $processes = [];
    for ($i=0; $i<10; $i++) {
        $processes[] = proc_open([PHP_BINARY,__FILE__,'parallel',$root,$package], [0=>['file','/dev/null','r'],1=>['file','/dev/null','w'],2=>['file','/dev/null','w']], $pipes);
    }
    foreach ($processes as $process) { ensure(is_resource($process) && proc_close($process) === 0, 'parallel_import_failed'); }
    check(true, 'ten_concurrent_idempotent_imports');
    check((int)$source->query('SELECT SUM(on_hand) FROM commerce_inventory')->fetchColumn() === 6, 'counted_quantities_imported_once');
    check((int)$source->query('SELECT COUNT(*) FROM inventory_adjustments')->fetchColumn() === 2, 'exactly_two_audit_entries');
    check((int)$source->query("SELECT SUM(sales_enabled) FROM commerce_products WHERE product_id LIKE 'ci-counted-%'")->fetchColumn() === 0, 'no_sales_activation');
    $afterImport = fingerprint($source);
    CarmajaCartCutover::importCounts($source, maintenance(), $manifest, $products, $release);
    check($afterImport === fingerprint($source), 'repeat_has_no_mutation');
    $source->exec("UPDATE commerce_inventory SET on_hand=2,inventory_version=inventory_version+1 WHERE product_id='ci-counted-0001'");
    CarmajaCartCutover::importCounts($source, maintenance(), $manifest, $products, $release);
    check((int)$source->query('SELECT SUM(on_hand) FROM commerce_inventory')->fetchColumn() === 5, 'replay_preserves_later_inventory_adjustment');
    $changed = $manifest; $changed['selectedProducts'][0]['targetOnHand']=4;
    reject(fn () => CarmajaCartCutover::importCounts($source, maintenance(), $changed, $products, $release), 'changed_idempotent_operation_rejected');
    $source->exec("UPDATE payments SET status='processing'");
    reject(fn () => CarmajaCartCutover::assertDrained($source), 'processing_payment_blocks_import');
    $source->exec("UPDATE payments SET status='succeeded'");
    $source->exec("UPDATE reservations SET state='expired',blocks_stock=1");
    reject(fn () => CarmajaCartCutover::assertDrained($source), 'expired_blocking_reservation_blocks_import');
    $source->exec("UPDATE reservations SET state='converted',blocks_stock=0");
    $locker = connect($config); $locker->beginTransaction();
    $locker->query("SELECT * FROM commerce_inventory WHERE product_id='ci-counted-0001' FOR UPDATE")->fetchAll();
    $waiter = connect($config); $waiter->exec('SET innodb_lock_wait_timeout=1');
    try {
        $waiter->beginTransaction();
        try { $waiter->query("SELECT * FROM commerce_inventory WHERE product_id='ci-counted-0001' FOR UPDATE"); throw new LogicException('row_lock_not_enforced'); }
        catch (PDOException $error) { check(($error->errorInfo[1] ?? null) === 1205, 'real_row_lock_timeout'); }
    } finally { if ($waiter->inTransaction()) { $waiter->rollBack(); } $locker->rollBack(); }

    $phase = 'post_migration_backup_restore';
    // Binary, Unicode and timestamps must survive the actual dump/restore clients.
    $source->exec('CREATE TABLE ci_binary_fixture(id INT PRIMARY KEY, bytes LONGBLOB, label VARCHAR(100), at_time DATETIME(6)) ENGINE=InnoDB');
    $source->prepare('INSERT INTO ci_binary_fixture VALUES (1,?,?,?)')->execute([random_bytes(4096), 'ÄÖÜ ß 💎', '2026-11-10 12:34:56.123456']);
    $sourceBefore = fingerprint($source);
    $orphan = $config['backupDirectory'] . '/.staging/20000101T000000Z-aaaaaaaaaaaa';
    mkdir($orphan, 0700, true); privateFile($orphan . '/mysql-source.cnf', 'artificial orphan');
    $post = $backup->create();
    check(!file_exists($orphan), 'orphan_crash_staging_cleaned');
    $lock = fopen($config['backupDirectory'] . '/locks/backup.lock', 'c');
    ensure(flock($lock, LOCK_EX | LOCK_NB), 'lock_fixture_failed');
    try { reject(fn () => $backup->create(), 'parallel_backup_rejected', 'backup_already_running'); }
    finally { flock($lock, LOCK_UN); fclose($lock); }
    for ($i=0; $i<2; $i++) {
        check($backup->restoreDryRun($post['backupId'])['status'] === 'restored_and_compared', 'post_restore_compare_repeat_' . ($i+1));
        check(tables($restore) === [], 'restore_database_cleanup_repeat_' . ($i+1));
    }
    $backup->acknowledge($post['backupId'], $post['manifestSha256']);
    check($backup->acknowledge($post['backupId'], $post['manifestSha256'])['idempotent'] === true, 'acknowledgement_idempotent');
    check($backup->status()['status'] === 'ok', 'backup_status_healthy');
    $restore->exec('CREATE TABLE ci_do_not_delete(id INT PRIMARY KEY) ENGINE=InnoDB');
    reject(fn () => $backup->restoreDryRun($post['backupId']), 'nonempty_restore_refused');
    check(tables($restore) === ['ci_do_not_delete'], 'nonempty_restore_sentinel_preserved');
    $restore->exec('DROP TABLE ci_do_not_delete');
    reject(fn () => backup([...$config,'backupEncryptionKey'=>base64_encode(random_bytes(32))])->restoreDryRun($post['backupId']), 'wrong_key_rejected');
    $ready = $config['backupDirectory'] . '/ready/' . $post['backupId'];
    $manifestPath = $ready . '/manifest.json'; $manifestBytes = file_get_contents($manifestPath);
    privateFile($manifestPath, str_replace('isolated-ci-only', 'tampered-key-id', $manifestBytes));
    reject(fn () => $backup->restoreDryRun($post['backupId']), 'manifest_hmac_tamper_rejected');
    privateFile($manifestPath, $manifestBytes);
    foreach (['commerce.sql.gz.cmjbkp','product-data.tar.gz.cmjbkp'] as $name) {
        $path = $ready . '/' . $name; $bytes = file_get_contents($path);
        check(!str_contains($bytes, $config['commercePassword']) && !str_contains($bytes, $config['backupEncryptionKey']) && !str_contains($bytes, 'Künstlich'), 'no_plaintext_' . str_replace('.', '_', $name));
        $bad = $bytes; $bad[intdiv(strlen($bad),2)] = chr(ord($bad[intdiv(strlen($bad),2)]) ^ 1);
        privateFile($path, $bad); reject(fn () => $backup->restoreDryRun($post['backupId']), 'tamper_rejected_' . str_replace('.', '_', $name));
        privateFile($path, substr($bytes,0,-24)); reject(fn () => $backup->restoreDryRun($post['backupId']), 'truncation_rejected_' . str_replace('.', '_', $name));
        privateFile($path, $bytes);
        check(tables($restore) === [], 'failed_restore_cleaned_' . str_replace('.', '_', $name));
    }
    reject(fn () => $backup->validateTarEntries(['../escape']), 'path_traversal_rejected');
    reject(fn () => $backup->validateTarEntries(['/absolute']), 'absolute_archive_path_rejected');
    check($backup->restoreDryRun($post['backupId'])['status'] === 'restored_and_compared', 'restore_after_errors_recovers');
    check($sourceBefore === fingerprint($source), 'all_restores_leave_source_unchanged');
    foreach ($productBefore as $path => $hash) { ensure(hash_file('sha256', $path) === $hash, 'product_fixture_changed'); }
    check(true, 'authoritative_product_files_preserved');
    check(hash_file('sha256', $config['runtimeFile']) === $runtimeBefore, 'runtime_unchanged');
    check((glob($config['backupDirectory'] . '/.restore/*') ?: []) === [] && (glob($config['backupDirectory'] . '/.staging/*') ?: []) === [], 'private_plaintext_and_staging_absent');
    $result = ['status'=>'passed','packageSourceSha'=>$release['sourceCommit'],'harnessSha'=>getenv('GITHUB_SHA'),
        'mysqlVersion'=>$version,'mysqlImage'=>trim((string)file_get_contents($root . '/image-digest')),
        'phpVersion'=>PHP_VERSION,'tls'=>'required; CA and host identity verified; source and restore',
        'checks'=>$checks,'checkCount'=>count($checks),'productionAccess'=>false,'ionosAccess'=>false];
} catch (Throwable $error) {
    // Never emit exception messages from PDO, command output, config, or traces.
    $safeCode = $error instanceof LogicException ? $error->getMessage() : ($error->errorCode ?? 'technical_test_failure');
    if (!preg_match('/^[a-zA-Z0-9_]+$/', $safeCode)) { $safeCode = 'technical_test_failure'; }
    $result = ['status'=>'failed','phase'=>$phase ?? 'setup','code'=>$safeCode,'exceptionClass'=>get_class($error),
        'originFile'=>basename($error->getFile()),'originLine'=>$error->getLine(),
        'sqlState'=>$error instanceof PDOException ? ($error->errorInfo[0] ?? null) : null,
        'sqlDriverCode'=>$error instanceof PDOException ? ($error->errorInfo[1] ?? null) : null,
        'checks'=>$checks,'productionAccess'=>false,'ionosAccess'=>false];
    if (in_array($mode ?? '', ['crash','parallel'], true)) { exit(23); }
} finally {
    if (isset($cleanupAuthorized) && $cleanupAuthorized) {
        try {
            clearOwned($source); clearOwned($restore);
            ensure(tables($source) === [] && tables($restore) === [], 'database_cleanup_failed');
            $result['databaseCleanup'] = 'both_empty';
        } catch (Throwable) { $result['status']='failed'; $result['databaseCleanup']='failed'; }
    }
}
if (isset($result)) {
    if ($reportAuthorized) { privateFile($report, json_encode($result, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n"); }
    echo json_encode($result, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
    exit($result['status'] === 'passed' ? 0 : 1);
}
