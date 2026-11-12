<?php

declare(strict_types=1);
ini_set('zend.exception_ignore_args', '1');
foreach (['product-api.php', 'product-api-v2.php', 'product-api-v3.php', 'cart-contract.php'] as $name) {
    require_once dirname(__DIR__, 2) . '/test-api-private/program/' . $name;
}
require_once dirname(__DIR__, 2) . '/scripts/product-model4-migration.php';

$m4Checks = 0;
function m4t_check(bool $ok, string $label): void
{
    global $m4Checks;
    if (!$ok) throw new RuntimeException('assertion_' . $label);
    $m4Checks++;
}
function m4t_reject(callable $action, string $message): void
{
    try { $action(); } catch (Throwable $error) { m4t_check($error->getMessage() === $message, 'expected_' . $message . '_got_' . $error->getMessage()); return; }
    throw new RuntimeException('missing_refusal_' . $message);
}
function m4t_pdo(string $private): PDO
{
    $pdo = new PDO('sqlite:' . $private . '/fixture.sqlite', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $pdo->exec('PRAGMA busy_timeout=50');
    return $pdo;
}
function m4t_safety(): array
{
    return array_fill_keys(['executionApproved', 'maintenancePresent', 'productAndAdminWritersStopped', 'checkoutsClosed',
        'paymentsDrained', 'backupRestoreVerified', 'privatePathsVerified'], true);
}
function m4t_adapter(array $fixture, ?Closure $checkpoint = null, ?Closure $safety = null, int $budget = 40000): CarmajaModel4Migration
{
    return new CarmajaModel4Migration($fixture['private'], $fixture['pdo'], 'model4-artificial-20261112',
        $fixture['digest'], $safety ?? m4t_safety(...), $checkpoint, $budget);
}
function m4t_protected(array $fixture): string
{
    $values = [];
    foreach (['config/runtime-config.php', 'maintenance.lock', 'public.json', 'uploads/01.jpg'] as $name) {
        $values[$name] = hash_file('sha256', $fixture['private'] . '/' . $name);
    }
    foreach (['commerce_inventory', 'orders', 'legal_bundles', 'checkout_sagas', 'schema_migrations'] as $table) {
        $values[$table] = $fixture['pdo']->query('SELECT * FROM ' . $table)->fetchAll(PDO::FETCH_ASSOC);
    }
    return cmj_model4_digest($values);
}
function m4t_fixture(string $root, string $name, ?PDO $mysql = null): array
{
    $private = $root . '/' . $name; mkdir($private, 0700);
    foreach (['drafts', 'uploads', 'locks', 'audit', 'config'] as $dir) mkdir($private . '/' . $dir, 0700);
    $job = $private . '/audit/model4-artificial-20261112'; mkdir($job, 0700);
    file_put_contents($private . '/config/runtime-config.php', "<?php return ['environment'=>'artificial'];\n");
    file_put_contents($private . '/maintenance.lock', 'closed');
    $image = imagecreatetruecolor(8, 6); imagejpeg($image, $private . '/uploads/01.jpg');
    $records = []; $rows = []; $public = ['version' => 3, 'products' => []];
    foreach ([2 => 'published', 8 => 'published', 9 => 'disabled', 10 => 'draft'] as $number => $status) {
        $draft = ['draftId' => sprintf('10000000-0000-4000-8000-%012d', $number), 'version' => 4,
            'productVersion' => 3, 'productModelVersion' => 3, 'environment' => 'test',
            'name' => 'Artificial bracelet ' . $number, 'shortDescription' => 'Artificial text',
            'descriptionDocument' => ['version' => 1, 'blocks' => [['type' => 'paragraph', 'spans' => [
                ['text' => 'Artificial text', 'bold' => false, 'italic' => false, 'font' => 'standard', 'size' => 'normal'],
            ]]]], 'materials' => ['Natural stone'], 'metalElements' => [], 'braceletSizeCm' => 18,
            'pearlSizeMm' => 8, 'careInstructions' => ['Keep dry'], 'priceMinor' => 3250, 'currency' => 'eur',
            'salesEnabled' => $status === 'published', 'status' => $status, 'sku' => sprintf('CP-2026-%04d', $number),
            'slug' => 'artificial-' . $number, 'privateCalculation' => ['unchanged' => 12], 'updatedAt' => '2026-11-11T00:00:00Z',
            'imageManifest' => [['imageId' => '20000000-0000-4000-8000-000000000001', 'fileName' => '01.jpg',
                'width' => 8, 'height' => 6, 'alt' => 'Artificial', 'isMain' => true]],
            'images' => [['imageId' => '20000000-0000-4000-8000-000000000001', 'fileName' => '01.jpg',
                'path' => $private . '/uploads/01.jpg', 'isMain' => true]]];
        $p = carmaja_api_v3_product_from_draft($draft); $draft['sourceHash'] = $p['sourceHash'];
        $records[$draft['draftId']] = $draft;
        if ($number < 10) $rows[] = ['product_id' => $draft['draftId'], 'product_version' => '3', 'source_hash' => $p['sourceHash'],
            'name' => $p['name'], 'description' => $p['description'], 'materials' => json_encode($p['materials']),
            'metal_elements' => '[]', 'bracelet_size' => '18', 'care_instructions' => 'Keep dry', 'price_minor' => '3250',
            'currency' => 'eur', 'sales_enabled' => (string) (int) $draft['salesEnabled'], 'sales_model' => 'collection',
            'images' => json_encode(cmj_model4_projection($draft)['images']), 'notice_snapshot' => null, 'public_snapshot' => null,
            'synchronized_at' => '2026-11-11 00:00:00.000000', 'updated_at' => '2026-11-11 00:00:00.000000'];
        if ($number === 2) $public['products'][] = cmj_model4_projection($draft);
    }
    $legacyId = '10000000-0000-4000-8000-000000000099';
    $already = $draft;
    $already['draftId'] = '10000000-0000-4000-8000-000000000011';
    $already['sku'] = 'CP-2026-0011'; $already['slug'] = 'artificial-11';
    $already['productModelVersion'] = 4; $already['noticePolicyId'] = 'natural-stones-v1';
    $already['sourceHash'] = carmaja_api_v3_product_from_draft($already)['sourceHash'];
    $records[$already['draftId']] = $already;
    $records[$legacyId] = ['draftId' => $legacyId, 'stock' => 1, 'status' => 'draft', 'privateCalculation' => 'preserve legacy'];
    foreach ($records as $id => $draft) {
        $file = $private . '/drafts/' . $id . '.json'; file_put_contents($file, json_encode($draft, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n"); chmod($file, 0640);
    }
    file_put_contents($private . '/public.json', json_encode($public, JSON_THROW_ON_ERROR));
    $pdo = $mysql ?? m4t_pdo($private);
    $columns = array_keys($rows[0]);
    $suffix = '';
    if ($mysql !== null) {
        cmj_model4_require(defined('CMJ_MODEL4_TEST_TABLE_TAG') && preg_match('/^cmj_model4_[a-f0-9]{16}$/', CMJ_MODEL4_TEST_TABLE_TAG) === 1, 'artificial_table_tag_required');
        cmj_model4_require($pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) === ['m4_probe_owner'], 'empty_owned_fixture_required');
        $suffix = " ENGINE=InnoDB COMMENT='" . CMJ_MODEL4_TEST_TABLE_TAG . "'";
        $schema = file_get_contents(dirname(__DIR__, 2) . '/database/commerce-schema.sql');
        $cart = file_get_contents(dirname(__DIR__, 2) . '/database/migrations/commerce-v3-cart-inventory.sql');
        cmj_model4_require(preg_match('/CREATE TABLE IF NOT EXISTS commerce_products \(.*?\) ENGINE=InnoDB;/s', $schema, $ddl) === 1
            && preg_match('/ALTER TABLE commerce_products .*?;/s', $cart, $extension) === 1, 'product_schema_missing');
        $pdo->exec(str_replace(['IF NOT EXISTS ', ' ENGINE=InnoDB;'], ['', $suffix . ';'], $ddl[0]));
        $pdo->exec($extension[0]);
    } else $pdo->exec('CREATE TABLE commerce_products (' . implode(',', array_map(static fn ($c) => $c . ' TEXT', $columns)) . ', PRIMARY KEY(product_id))');
    $insert = $pdo->prepare('INSERT INTO commerce_products (' . implode(',', $columns) . ') VALUES (' . implode(',', array_fill(0, count($columns), '?')) . ')');
    foreach ($rows as $row) $insert->execute(array_values($row));
    foreach (['commerce_inventory', 'orders', 'legal_bundles', 'checkout_sagas', 'schema_migrations'] as $table) {
        $pdo->exec('CREATE TABLE ' . $table . ' (historical TEXT)' . $suffix);
        $pdo->exec("INSERT INTO $table VALUES ('ARTIFICIAL_UNCHANGED')");
    }
    $plan = cmj_m4_capture($private, $pdo, $public, '2026-11-12T12:00:00Z', 'model4-artificial-20261112');
    file_put_contents($job . '/manifest.json', cmj_model4_json($plan)); chmod($job . '/manifest.json', 0600);
    $digest = cmj_model4_digest($plan);
    $fixture = compact('private', 'job', 'pdo', 'plan', 'digest', 'legacyId', 'public');
    $fixture['protected'] = m4t_protected($fixture);
    return $fixture;
}
function m4t_complete(array $f): void
{
    m4t_check(cmj_m4_rows($f['pdo']) === $f['plan']['afterRows'], 'rows_after');
    foreach ($f['plan']['sources'] as $s) {
        m4t_check(file_get_contents($f['private'] . '/drafts/' . $s['id'] . '.json') === base64_decode($s['after'], true), 'exact_source_after');
    }
    m4t_check(m4t_protected($f) === $f['protected'], 'protected_unchanged');
    m4t_check(glob($f['job'] . '/next-*') === [], 'no_temporary');
    m4t_check(json_decode(file_get_contents($f['job'] . '/state.json'), true)['phase'] === 'complete', 'completed');
}
function m4t_original(array $f): void
{
    m4t_check(cmj_m4_rows($f['pdo']) === $f['plan']['beforeRows'], 'rows_original');
    foreach ($f['plan']['sources'] as $s) m4t_check(hash_file('sha256', $f['private'] . '/drafts/' . $s['id'] . '.json') === $s['beforeHash'], 'original_source');
}
function m4t_cleanup(string $path, string $root): void
{
    cmj_model4_require($path === $root || str_starts_with($path, $root . '/'), 'unsafe_cleanup');
    foreach (scandir($path) as $name) if (!in_array($name, ['.', '..'], true)) {
        $file = $path . '/' . $name;
        if (is_dir($file) && !is_link($file)) m4t_cleanup($file, $root); else unlink($file);
    }
    rmdir($path);
}

// Shared artificial fixture builder; never an application/runtime entry point.
if (defined('CMJ_MODEL4_TEST_HELPERS_ONLY') && CMJ_MODEL4_TEST_HELPERS_ONLY === true) return;

if (($argv[1] ?? '') === '--crash-child') {
    $private = str_replace('\\', '/', realpath($argv[2]) ?: '');
    $temporaryRoot = str_replace('\\', '/', realpath(sys_get_temp_dir()));
    if (!str_starts_with($private, $temporaryRoot . '/cmj-model4-test-')) exit(90);
    $job = $private . '/audit/model4-artificial-20261112';
    $plan = json_decode(file_get_contents($job . '/manifest.json'), true, 128, JSON_THROW_ON_ERROR);
    $f = ['private' => $private, 'pdo' => m4t_pdo($private), 'digest' => cmj_model4_digest($plan)];
    m4t_adapter($f, static function (string $point) use ($argv): void { if ($point === $argv[3]) exit(86); })->run();
    exit(91);
}

$root = str_replace('\\', '/', realpath(sys_get_temp_dir())) . '/cmj-model4-test-' . bin2hex(random_bytes(8));
mkdir($root, 0700);
try {
    $f = m4t_fixture($root, 'normal');
    m4t_check($f['plan'] === cmj_m4_capture($f['private'], $f['pdo'], $f['public'], $f['plan']['at'], $f['plan']['operationId']), 'capture_repeat');
    $f['pdo']->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_SILENT);
    m4t_reject(static fn () => m4t_adapter($f), 'pdo_exceptions_required');
    $f['pdo']->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $f['pdo']->beginTransaction();
    m4t_reject(static fn () => m4t_adapter($f), 'existing_transaction');
    $f['pdo']->rollBack();
    m4t_check(!file_exists($f['job'] . '/state.json'), 'invalid_pdo_no_mutation');
    m4t_original($f);
    $result = m4t_adapter($f)->run();
    m4t_check($result === ['status' => 'model4_complete', 'products' => 4, 'inventoryChanged' => false, 'published' => false], 'result');
    m4t_complete($f); $audit = file_get_contents($f['job'] . '/audit.json');
    m4t_adapter($f)->run(); m4t_complete($f);
    m4t_check(file_get_contents($f['job'] . '/audit.json') === $audit, 'audit_deduplicated');
    m4t_reject(fn () => m4t_adapter($f)->run(true), 'rollback_after_commit_forbidden');
    m4t_check(!str_contains($audit, 'privateCalculation') && !str_contains($audit, '/uploads/'), 'audit_minimal');

    $id = '10000000-0000-4000-8000-000000000002';
    $events = ['next_written:state', 'before_rename:source:' . $id, 'after_rename:source:' . $id,
        'sql:' . $id, 'before_commit', 'after_commit', 'next_written:audit'];
    foreach ($events as $i => $event) {
        $f = m4t_fixture($root, 'exception-' . $i);
        m4t_reject(fn () => m4t_adapter($f, static function ($point) use ($event): void {
            if ($point === $event) throw new RuntimeException('injected_failure');
        })->run(), 'injected_failure');
        m4t_check(!$f['pdo']->inTransaction(), 'exception_released_transaction');
        m4t_check(glob($f['job'] . '/next-*') === [], 'exception_cleanup');
        m4t_adapter($f)->run(); m4t_complete($f);
    }
    foreach ($events as $i => $event) {
        $f = m4t_fixture($root, 'process-crash-' . $i); $f['pdo'] = null;
        $command = [PHP_BINARY, '-n', '-d', 'extension_dir=' . ini_get('extension_dir')];
        foreach (['mbstring', 'gd', 'pdo_sqlite'] as $extension) array_push($command, '-d', 'extension=' . $extension);
        array_push($command, __FILE__, '--crash-child', $f['private'], $event);
        $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        fclose($pipes[0]); $output = stream_get_contents($pipes[1]); $error = stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]);
        m4t_check(proc_close($process) === 86 && $output === '' && $error === '', 'hard_process_exit_' . $i);
        $f['pdo'] = m4t_pdo($f['private']);
        if (!in_array($event, ['after_commit', 'next_written:audit'], true)) m4t_check(cmj_m4_rows($f['pdo']) === $f['plan']['beforeRows'], 'crash_transaction_rolled_back');
        m4t_adapter($f)->run(); m4t_complete($f);
    }
    $f = m4t_fixture($root, 'rollback');
    m4t_reject(fn () => m4t_adapter($f, static function ($point) use ($id): void {
        if ($point === 'after_rename:source:' . $id) throw new RuntimeException('stopped');
    })->run(), 'stopped');
    m4t_adapter($f)->run(true); m4t_original($f);
    m4t_adapter($f)->run(true); m4t_original($f);
    m4t_reject(fn () => m4t_adapter($f)->run(), 'rollback_requires_new_plan');

    $f = m4t_fixture($root, 'rollback-interrupted');
    m4t_reject(fn () => m4t_adapter($f, static function ($point): void {
        if ($point === 'before_commit') throw new RuntimeException('stopped');
    })->run(), 'stopped');
    m4t_reject(fn () => m4t_adapter($f, static function ($point) use ($id): void {
        if ($point === 'after_rename:source:' . $id) throw new RuntimeException('rollback_stopped');
    })->run(true), 'rollback_stopped');
    m4t_reject(fn () => m4t_adapter($f)->run(), 'rollback_requires_new_plan');
    m4t_adapter($f)->run(true); m4t_original($f);

    $f = m4t_fixture($root, 'safety');
    foreach (array_keys(m4t_safety()) as $key) {
        m4t_reject(fn () => m4t_adapter($f, null, static fn () => [...m4t_safety(), $key => false])->run(), 'safety_gate_failed');
        m4t_original($f);
    }
    m4t_check(!file_exists($f['job'] . '/state.json'), 'gate_no_journal');
    $handle = fopen($f['private'] . '/locks/draft-' . $id . '.lock', 'c'); flock($handle, LOCK_EX);
    m4t_reject(fn () => m4t_adapter($f)->run(), 'migration_busy'); flock($handle, LOCK_UN); fclose($handle);
    m4t_original($f);
    $global = fopen($f['private'] . '/locks/model4-migration.lock', 'c'); flock($global, LOCK_EX);
    m4t_reject(fn () => m4t_adapter($f)->run(), 'migration_busy'); flock($global, LOCK_UN); fclose($global);
    $allowed = true;
    m4t_reject(fn () => m4t_adapter($f, static function ($point) use (&$allowed, $id): void {
        if ($point === 'after_rename:source:' . $id) $allowed = false;
    }, static function () use (&$allowed): array { return [...m4t_safety(), 'maintenancePresent' => $allowed]; })->run(), 'safety_gate_failed');
    m4t_adapter($f)->run(); m4t_complete($f);

    $f = m4t_fixture($root, 'safety-before-commit'); $allowed = true;
    m4t_reject(fn () => m4t_adapter($f, static function ($point) use (&$allowed): void {
        if ($point === 'before_commit') $allowed = false;
    }, static function () use (&$allowed): array { return [...m4t_safety(), 'maintenancePresent' => $allowed]; })->run(), 'safety_gate_failed');
    m4t_check(cmj_m4_rows($f['pdo']) === $f['plan']['beforeRows'], 'late_guard_rolled_back');
    m4t_adapter($f)->run(); m4t_complete($f);

    $f = m4t_fixture($root, 'timeout');
    m4t_reject(fn () => m4t_adapter($f, static function ($point) use ($id): void { if ($point === 'after_rename:source:' . $id) usleep(50000); }, null, 30)->run(), 'migration_timeout');
    m4t_adapter($f)->run(); m4t_complete($f);

    $f = m4t_fixture($root, 'sql-failure');
    $f['pdo']->exec("CREATE TRIGGER fail_second BEFORE UPDATE ON commerce_products WHEN NEW.product_id='10000000-0000-4000-8000-000000000008' BEGIN SELECT RAISE(ABORT,'artificial_sql_failure'); END");
    try { m4t_adapter($f)->run(); throw new RuntimeException('sql_should_fail'); } catch (PDOException) { m4t_check(true, 'sql_failure_seen'); }
    m4t_check(!$f['pdo']->inTransaction() && cmj_m4_rows($f['pdo']) === $f['plan']['beforeRows'], 'all_sql_rolled_back');
    $f['pdo']->exec('DROP TRIGGER fail_second'); m4t_adapter($f)->run(); m4t_complete($f);

    $f = m4t_fixture($root, 'database-busy');
    $other = m4t_pdo($f['private']); $other->beginTransaction(); $other->exec("UPDATE commerce_products SET name='uncommitted artificial edit' WHERE product_id='$id'");
    try { m4t_adapter($f)->run(); throw new RuntimeException('busy_should_fail'); } catch (PDOException) { m4t_check(!$f['pdo']->inTransaction(), 'busy_transaction_released'); }
    $other->rollBack(); $other = null;
    m4t_adapter($f)->run(); m4t_complete($f);

    $f = m4t_fixture($root, 'tampering');
    m4t_reject(fn () => new CarmajaModel4Migration($f['private'], $f['pdo'], $f['plan']['operationId'], str_repeat('0', 64), m4t_safety(...)), 'manifest_binding');
    $bad = $f['plan']; $after = json_decode(base64_decode($bad['sources'][0]['after']), true); $after['priceMinor'] = 1;
    $bad['sources'][0]['after'] = base64_encode(json_encode($after)); file_put_contents($f['job'] . '/manifest.json', cmj_model4_json($bad));
    m4t_reject(fn () => new CarmajaModel4Migration($f['private'], $f['pdo'], $bad['operationId'], cmj_model4_digest($bad), m4t_safety(...)), 'manifest_target_tampered');
    file_put_contents($f['job'] . '/manifest.json', cmj_model4_json($f['plan']));
    $file = $f['private'] . '/drafts/' . $id . '.json'; $raw = file_get_contents($file);
    file_put_contents($file, $raw . ' '); m4t_reject(fn () => m4t_adapter($f)->run(), 'source_changed'); file_put_contents($file, $raw);
    $f['pdo']->exec("UPDATE commerce_products SET price_minor='1' WHERE product_id='$id'");
    m4t_reject(fn () => m4t_adapter($f)->run(), 'commerce_changed');
    $f['pdo']->exec("UPDATE commerce_products SET price_minor='3250' WHERE product_id='$id'");
    file_put_contents($f['private'] . '/drafts/extra.json', '{}'); m4t_reject(fn () => m4t_adapter($f)->run(), 'source_set_changed'); unlink($f['private'] . '/drafts/extra.json');
    $image = file_get_contents($f['private'] . '/uploads/01.jpg'); file_put_contents($f['private'] . '/uploads/01.jpg', $image . 'x');
    m4t_reject(fn () => m4t_adapter($f)->run(), 'images_changed'); file_put_contents($f['private'] . '/uploads/01.jpg', $image);
    file_put_contents($f['job'] . '/state.json', cmj_model4_json(['manifestDigest' => str_repeat('0', 64), 'phase' => 'applying']));
    m4t_reject(fn () => m4t_adapter($f)->run(), 'journal_conflict'); unlink($f['job'] . '/state.json');
    file_put_contents($file, base64_decode($f['plan']['sources'][0]['after']));
    m4t_reject(fn () => m4t_adapter($f)->run(), 'unrecorded_recovery'); file_put_contents($file, $raw);
    m4t_original($f);
    m4t_reject(fn () => new CarmajaModel4Migration($f['private'] . '/..', $f['pdo'], $f['plan']['operationId'], $f['digest'], m4t_safety(...)), 'unsafe_path');
    m4t_reject(fn () => new CarmajaModel4Migration($f['private'], $f['pdo'], '../outside', $f['digest'], m4t_safety(...)), 'manifest_identity');
    $second = new PDO('sqlite:' . $f['private'] . '/other.sqlite');
    m4t_reject(fn () => new CarmajaModel4Migration($f['private'], $second, $f['plan']['operationId'], $f['digest'], m4t_safety(...)), 'target_changed');
    $second = null;
    m4t_adapter($f)->run(); m4t_complete($f);
    file_put_contents($file, $raw); m4t_reject(fn () => m4t_adapter($f)->run(), 'completed_state_changed');

    $f = m4t_fixture($root, 'partial-database');
    $row = $f['plan']['afterRows'][0];
    $stmt = $f['pdo']->prepare('UPDATE commerce_products SET product_version=?,source_hash=?,synchronized_at=?,updated_at=? WHERE product_id=?');
    $stmt->execute([$row['product_version'],$row['source_hash'],$row['synchronized_at'],$row['updated_at'],$row['product_id']]); $stmt = null;
    m4t_reject(fn () => m4t_adapter($f)->run(), 'commerce_changed');
    foreach ($f['plan']['sources'] as $s) m4t_check(hash_file('sha256', $f['private'] . '/drafts/' . $s['id'] . '.json') === $s['beforeHash'], 'partial_db_no_file_write');

    $f = m4t_fixture($root, 'database-ahead');
    m4t_adapter($f)->run();
    foreach ($f['plan']['sources'] as $s) file_put_contents($f['private'] . '/drafts/' . $s['id'] . '.json', base64_decode($s['before']));
    file_put_contents($f['job'] . '/state.json', cmj_model4_json(['manifestDigest'=>$f['digest'],'phase'=>'applying']));
    m4t_reject(fn () => m4t_adapter($f)->run(), 'invalid_recovery_state');

    $f = m4t_fixture($root, 'journal-truncated');
    file_put_contents($f['job'] . '/state.json', '{');
    try { m4t_adapter($f)->run(); throw new RuntimeException('journal_should_fail'); } catch (JsonException) { m4t_check(true, 'truncated_journal_refused'); }
    m4t_original($f);
    unlink($f['job'] . '/state.json');
    m4t_adapter($f)->run(); file_put_contents($f['job'] . '/audit.json', '{}');
    m4t_reject(fn () => m4t_adapter($f)->run(), 'audit_conflict');

    $f = m4t_fixture($root, 'orphan-next');
    file_put_contents($f['job'] . '/next-state', '{partial write');
    file_put_contents($f['job'] . '/unrelated-private.txt', 'PRESERVE');
    m4t_adapter($f)->run(); m4t_complete($f);
    m4t_check(file_get_contents($f['job'] . '/unrelated-private.txt') === 'PRESERVE', 'narrow_cleanup');

    $f = m4t_fixture($root, 'symlink');
    $original = $f['private'] . '/drafts/' . $id . '.json'; $target = $f['private'] . '/outside.json';
    copy($original, $target); unlink($original);
    $symlinkChecked = @symlink($target, $original);
    if ($symlinkChecked) { m4t_reject(fn () => m4t_adapter($f)->run(), 'unsafe_path'); unlink($original); }
    else echo "Symlink creation unavailable locally; POSIX symlink evidence remains required.\n";
    rename($target, $original); chmod($original, 0640);
    m4t_original($f);

    echo "Model4 migration: $m4Checks checks passed; real local process exits and SQLite transactions, no MySQL/production claim.\n";
} finally {
    $f = null; $other = null;
    m4t_cleanup($root, $root);
}
m4t_check(!file_exists($root), 'complete_fixture_cleanup');
echo "Artificial fixture cleanup passed.\n";
