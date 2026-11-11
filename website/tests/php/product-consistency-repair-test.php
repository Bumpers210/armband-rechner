<?php

declare(strict_types=1);
$mysql = ($argv[1] ?? '') === '--mysql-restore';
$testDatabase = null;
if ($mysql) {
    ini_set('display_errors', '0');
    set_exception_handler(static function (): never { fwrite(STDERR, "artificial_mysql_test_failed\n"); exit(1); });
    if (PHP_SAPI !== 'cli' || preg_match('#^/home/www/carmaja-private-shop/\.repair-stage-[a-f0-9]{16}$#', __DIR__) !== 1) throw new RuntimeException('private_stage_required');
}
$program = $mysql ? '/home/www/carmaja-private-shop/program' : dirname(__DIR__, 2) . '/test-api-private/program';
foreach (['product-api.php', 'product-api-v2.php', 'product-api-v3.php'] as $name) require_once $program . '/' . $name;
require_once $mysql ? __DIR__ . '/product-consistency-repair.php' : dirname(__DIR__, 2) . '/scripts/product-consistency-repair.php';
if ($mysql) {
    $runtime = '/home/www/carmaja-private-shop/config/runtime-config.php';
    if (is_link($runtime) || (fileperms($runtime) & 0777) !== 0600) throw new RuntimeException('runtime_mode');
    $runtimeHash = hash_file('sha256', $runtime);
    $config = require $runtime;
    $names = [];
    foreach (['commerceDsn', 'commerceRestoreDsn'] as $key) {
        if (!preg_match('/(?:^|;)dbname=([^;]+)(?:;|$)/', substr($config[$key], 6), $match)) throw new RuntimeException('dsn_invalid');
        $names[] = $match[1];
    }
    if ($names[0] === $names[1]) throw new RuntimeException('distinct_restore_required');
    $options = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false,
        PDO::MYSQL_ATTR_SSL_CIPHER => 'HIGH', PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT => false];
    if (!empty($config['commerceRestoreTlsCaPath'])) { $options[PDO::MYSQL_ATTR_SSL_CA] = $config['commerceRestoreTlsCaPath']; $options[PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT] = true; }
    $testDatabase = new PDO($config['commerceRestoreDsn'], $config['commerceRestoreUser'], $config['commerceRestorePassword'], $options);
    foreach (['Ssl_cipher', 'Ssl_version'] as $name) if (trim((string) $testDatabase->query("SHOW SESSION STATUS LIKE '$name'")->fetch(PDO::FETCH_NUM)[1]) === '') throw new RuntimeException('tls_required');
    if (!str_starts_with($testDatabase->query('SELECT VERSION()')->fetchColumn(), '8.')
        || (int) $testDatabase->query('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE()')->fetchColumn() !== 0) throw new RuntimeException('empty_mysql_restore_required');
    $testDatabase->exec("SET time_zone='+00:00'");
    $testDatabase->exec('SET SESSION innodb_lock_wait_timeout=2');
}
$root = ($mysql ? __DIR__ : str_replace('\\', '/', sys_get_temp_dir())) . '/carmaja-repair-test-' . bin2hex(random_bytes(8));
mkdir($root, 0700); $checks = 0;
function check_repair(bool $value): void { global $checks; if (!$value) throw new RuntimeException('test_assertion'); $checks++; }
function rejects_repair(callable $fn, string $code): void {
    try { $fn(); } catch (Throwable $error) { check_repair($error->getMessage() === $code); return; }
    throw new RuntimeException('expected_refusal');
}
function repair_fixture(string $root, string $name): array {
    global $testDatabase;
    $private = $root . '/' . $name; mkdir($private); mkdir($private . '/drafts'); mkdir($private . '/locks'); mkdir($private . '/audit');
    $job = $private . '/audit/product-repair-artificial-20261111'; mkdir($job);
    $records = []; $public = ['version' => 3, 'products' => []];
    $ids = ['10000000-0000-4000-8000-000000000001', '10000000-0000-4000-8000-000000000008', '10000000-0000-4000-8000-000000000003'];
    foreach (['CP-2026-0001', 'CP-2026-0008', 'CP-2026-0003'] as $i => $sku) {
        $text = 'Kuenstlicher aktueller Text';
        $draft = ['draftId' => $ids[$i], 'environment' => 'test', 'productModelVersion' => 3, 'version' => 5, 'productVersion' => 3,
            'name' => 'Kuenstliches Armband', 'shortDescription' => $text, 'descriptionDocument' => ['version' => 1, 'blocks' => [['type' => 'paragraph',
                'spans' => [['text' => $text, 'bold' => false, 'italic' => false, 'font' => 'standard', 'size' => 'normal']]]]],
            'materials' => ['Naturstein'], 'metalElements' => [], 'braceletSizeCm' => 18, 'pearlSizeMm' => 8, 'careInstructions' => ['Trocken lagern'],
            'imageManifest' => [['imageId' => '20000000-0000-4000-8000-000000000001', 'fileName' => '01.jpg', 'alt' => 'Kuenstlich', 'width' => 800, 'height' => 600, 'isMain' => true]],
            'images' => [], 'priceMinor' => 3250, 'currency' => 'eur', 'status' => 'published', 'salesEnabled' => true,
            'sku' => $sku, 'slug' => 'kuenstlich-' . $i, 'updatedAt' => '2026-11-10T00:00:00Z', 'privateField' => 'PRESERVE'];
        $product = carmaja_api_v3_product_from_draft($draft);
        $draft['sourceHash'] = $product['sourceHash'];
        $row = cmj_repair_metadata($draft) + ['product_id' => $ids[$i], 'product_version' => '3', 'source_hash' => $product['sourceHash'],
            'images' => carmaja_api_v2_public_product_from_draft($draft)['images'], 'sales_model' => 'collection',
            'synchronized_at' => '2026-11-10 00:00:00.000000', 'updated_at' => '2026-11-10 00:00:00.000000'];
        if ($i === 0) { $wrong = $draft; $wrong['salesEnabled'] = false; $draft['sourceHash'] = carmaja_api_v3_product_from_draft($wrong)['sourceHash']; }
        if ($i === 1) { $row['description'] = 'Kuenstlicher alter Text'; $row['price_minor'] = '2950'; $row['product_version'] = '2'; $row['source_hash'] = str_repeat('a', 64); }
        $raw = json_encode($draft, JSON_THROW_ON_ERROR) . "\n";
        file_put_contents($private . '/drafts/' . $ids[$i] . '.json', $raw);
        $records[] = ['raw' => $raw, 'row' => $row];
        if ($i !== 1) $public['products'][] = carmaja_api_v2_public_product_from_draft($draft);
    }
    $pdo = $testDatabase ?? new PDO('sqlite:' . $private . '/artificial.sqlite', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $columns = array_keys($records[0]['row']);
    if ($testDatabase !== null) {
        foreach (['commerce_products', 'inventory', 'historical_order'] as $table) $pdo->exec('DROP TABLE IF EXISTS ' . $table);
        $definitions = [];
        foreach ($columns as $c) {
            $type = match ($c) {
                'product_id' => 'VARCHAR(64)', 'product_version' => 'BIGINT UNSIGNED', 'price_minor' => 'INT UNSIGNED',
                'sales_enabled' => 'TINYINT(1)', 'synchronized_at', 'updated_at' => 'TIMESTAMP(6)',
                'materials', 'metal_elements', 'images' => 'JSON', default => 'TEXT',
            };
            $definitions[] = $c . ' ' . $type . ' NOT NULL';
        }
        $pdo->exec('CREATE TABLE commerce_products (' . implode(',', $definitions) . ', PRIMARY KEY(product_id)) ENGINE=InnoDB');
    } else $pdo->exec('CREATE TABLE commerce_products (' . implode(', ', array_map(static fn ($c) => $c . ' TEXT', $columns)) . ', PRIMARY KEY(product_id))');
    $insert = $pdo->prepare('INSERT INTO commerce_products (' . implode(',', $columns) . ') VALUES (' . implode(',', array_fill(0, count($columns), '?')) . ')');
    foreach ($records as $record) $insert->execute(array_map(static fn ($v) => is_array($v) ? json_encode($v, JSON_THROW_ON_ERROR) : $v, array_values($record['row'])));
    $pdo->exec('CREATE TABLE inventory (quantity INT)'); $pdo->exec('INSERT INTO inventory VALUES (3)');
    $pdo->exec('CREATE TABLE historical_order (snapshot TEXT)'); $pdo->exec("INSERT INTO historical_order VALUES ('unchanged')");
    $plan = cmj_repair_plan($records, $public, 'product-repair-artificial-20261111', '2026-11-11T14:00:00Z');
    return compact('private', 'job', 'pdo', 'plan', 'records', 'public');
}
function cleanup_repair(string $path): void {
    foreach (scandir($path) as $entry) if ($entry !== '.' && $entry !== '..') {
        $file = $path . '/' . $entry;
        if (is_dir($file) && !is_link($file)) cleanup_repair($file); else unlink($file);
    }
    rmdir($path);
}
try {
    $f = repair_fixture($root, 'success');
    check_repair(count($f['plan']['entries']) === 2 && $f['plan']['unchanged'] === ['CP-2026-0003']);
    check_repair($f['plan'] === cmj_repair_plan($f['records'], $f['public'], 'product-repair-artificial-20261111', '2026-11-11T14:00:00Z'));
    $protected = hash_file('sha256', $f['private'] . '/drafts/10000000-0000-4000-8000-000000000003.json');
    $guard = static function (): void {};
    cmj_repair_apply($f['private'], $f['job'], $f['pdo'], $f['plan'], $guard);
    foreach ($f['plan']['entries'] as $entry) {
        $draft = json_decode(file_get_contents($f['private'] . '/drafts/' . $entry['productId'] . '.json'), true);
        check_repair($draft['productVersion'] === 4 && $draft['version'] === 6 && $draft['sourceHash'] === carmaja_api_v3_product_from_draft($draft)['sourceHash']);
        $before = json_decode(base64_decode($entry['beforeRaw']), true); $after = $draft;
        foreach (['productVersion', 'version', 'sourceHash', 'updatedAt'] as $key) { unset($before[$key], $after[$key]); }
        check_repair($before === $after);
    }
    $audit = file_get_contents($f['job'] . '/audit.json');
    cmj_repair_apply($f['private'], $f['job'], $f['pdo'], $f['plan'], $guard);
    check_repair(file_get_contents($f['job'] . '/audit.json') === $audit);
    check_repair(hash_file('sha256', $f['private'] . '/drafts/10000000-0000-4000-8000-000000000003.json') === $protected);
    check_repair((int) $f['pdo']->query('SELECT quantity FROM inventory')->fetchColumn() === 3);
    check_repair($f['pdo']->query('SELECT snapshot FROM historical_order')->fetchColumn() === 'unchanged');
    $events = ['source:10000000-0000-4000-8000-000000000001', 'source:10000000-0000-4000-8000-000000000008',
        'sql:10000000-0000-4000-8000-000000000001', 'sql:10000000-0000-4000-8000-000000000008', 'before_commit', 'after_commit'];
    foreach ($events as $i => $event) {
        $f = repair_fixture($root, 'crash-' . $i);
        rejects_repair(static fn () => cmj_repair_apply($f['private'], $f['job'], $f['pdo'], $f['plan'], $guard, static function ($point) use ($event): void {
            if ($point === $event) throw new RuntimeException('simulated_crash');
        }), 'simulated_crash');
        check_repair(!$f['pdo']->inTransaction());
        if ($event !== 'after_commit') check_repair(!in_array('after', cmj_repair_db_states($f['pdo'], $f['plan'], false), true));
        cmj_repair_apply($f['private'], $f['job'], $f['pdo'], $f['plan'], $guard);
        check_repair(!in_array('before', cmj_repair_db_states($f['pdo'], $f['plan'], false), true));
        check_repair(!in_array('before', cmj_repair_file_states($f['private'] . '/drafts', $f['plan']), true));
    }
    $f = repair_fixture($root, 'conflicts');
    $tampered = $f['plan']; $tampered['entries'][0]['afterRow']['sales_enabled'] = '0';
    rejects_repair(static fn () => cmj_repair_validate_manifest($tampered), 'manifest_target_tampered');
    $tampered = $f['plan']; $draft = json_decode(base64_decode($tampered['entries'][0]['afterRaw']), true); $draft['priceMinor'] = 1;
    $tampered['entries'][0]['afterRaw'] = base64_encode(json_encode($draft));
    rejects_repair(static fn () => cmj_repair_validate_manifest($tampered), 'manifest_target_tampered');
    $wrong = $f['records']; $draft = json_decode($wrong[0]['raw'], true); $draft['sourceHash'] = str_repeat('b', 64); $wrong[0]['raw'] = json_encode($draft);
    rejects_repair(static fn () => cmj_repair_plan($wrong, $f['public'], 'product-repair-artificial-20261111', '2026-11-11T14:00:00Z'), 'unknown_hash_conflict');
    $wrong = $f['records']; $wrong[0]['row']['price_minor'] = '12';
    rejects_repair(static fn () => cmj_repair_plan($wrong, $f['public'], 'product-repair-artificial-20261111', '2026-11-11T14:00:00Z'), 'unapproved_content_conflict');
    $h = fopen($f['job'] . '/repair.lock', 'c'); flock($h, LOCK_EX);
    rejects_repair(static fn () => cmj_repair_apply($f['private'], $f['job'], $f['pdo'], $f['plan'], $guard), 'repair_busy');
    flock($h, LOCK_UN); fclose($h);
    rejects_repair(static fn () => cmj_repair_apply($f['private'], $f['job'], $f['pdo'], $f['plan'], static fn () => throw new RuntimeException('maintenance_missing')), 'maintenance_missing');
    $path = $f['private'] . '/drafts/' . $f['plan']['entries'][0]['productId'] . '.json';
    $raw = file_get_contents($path); file_put_contents($path, $raw . ' ');
    rejects_repair(static fn () => cmj_repair_apply($f['private'], $f['job'], $f['pdo'], $f['plan'], $guard), 'source_changed');
    file_put_contents($path, $raw);
    $f['pdo']->exec("UPDATE commerce_products SET description='external edit' WHERE product_id='10000000-0000-4000-8000-000000000001'");
    rejects_repair(static fn () => cmj_repair_apply($f['private'], $f['job'], $f['pdo'], $f['plan'], $guard), 'commerce_changed');
    check_repair(hash_file('sha256', $path) === $f['plan']['entries'][0]['beforeHash']);
    if ($testDatabase !== null) {
        $f = repair_fixture($root, 'mysql-row-timeout');
        $other = new PDO($config['commerceRestoreDsn'], $config['commerceRestoreUser'], $config['commerceRestorePassword'], $options);
        $other->beginTransaction();
        $locked = $other->prepare('SELECT * FROM commerce_products WHERE product_id=? FOR UPDATE');
        $locked->execute([$f['plan']['entries'][0]['productId']]); $locked->fetchAll();
        try {
            $timedOut = false;
            try { cmj_repair_apply($f['private'], $f['job'], $f['pdo'], $f['plan'], $guard); }
            catch (PDOException $error) { $timedOut = ($error->errorInfo[1] ?? null) === 1205; }
            check_repair($timedOut && !$f['pdo']->inTransaction());
            check_repair(!in_array('after', cmj_repair_db_states($f['pdo'], $f['plan'], false), true));
        } finally { $other->rollBack(); }
        cmj_repair_apply($f['private'], $f['job'], $f['pdo'], $f['plan'], $guard);
        check_repair(!in_array('before', cmj_repair_db_states($f['pdo'], $f['plan'], false), true));
        $other = null;
    }
    $f['pdo'] = null;
    echo "$checks artificial repair checks passed\n";
} finally {
    unset($f);
    if ($testDatabase !== null) {
        if ($testDatabase->inTransaction()) $testDatabase->rollBack();
        foreach (['commerce_products', 'inventory', 'historical_order'] as $table) $testDatabase->exec('DROP TABLE IF EXISTS ' . $table);
        check_repair((int) $testDatabase->query('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE()')->fetchColumn() === 0);
        check_repair(hash_file('sha256', $runtime) === $runtimeHash);
        echo "MySQL 8 TLS restore target empty; private runtime unchanged\n";
    }
    cleanup_repair($root);
}
