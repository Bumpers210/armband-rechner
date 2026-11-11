<?php
declare(strict_types=1);
require_once __DIR__ . '/production-cart-import-test.php';

// Artificial, in-memory journal/DML regression, not a MySQL DDL/TLS test.
$historyRoot = dirname(__DIR__, 2);
function history_checksum(string $file): string {
    return hash('sha256', preg_replace('/^\s*--.*$/m', '', str_replace("\r\n", "\n", file_get_contents($file))));
}
function history_journal(PDO $pdo): array {
    return $pdo->query('SELECT * FROM schema_migrations ORDER BY migration_id')->fetchAll(PDO::FETCH_ASSOC);
}
function history_reject(callable $action, string $code): void {
    try { $action(); } catch (RuntimeException $error) {
        release_check($error->getMessage() === $code, 'exact rejection: ' . $code); return;
    }
    throw new RuntimeException('Expected rejection: ' . $code);
}
$historyRelease = $release;
$historyRelease['migrations'] = array_map(static function ($id) use ($historyRoot): array {
    $path = $id === 'commerce-v1-schema' ? '/database/commerce-schema.sql' : '/database/migrations/' . $id . '.sql';
    return ['migrationId'=>$id, 'journalSha256'=>history_checksum($historyRoot . $path)];
}, CarmajaCartCutover::MIGRATIONS);
$historyChecksum = history_checksum(__DIR__ . '/../fixtures/schema-history/ap6-commerce-schema.sql');
release_check($historyChecksum === '388e3d44299ae0890fb83d4881d87aa26d7e93d30ee3af8ac07033a4106ea27b', 'original AP6 schema fixture bound');

foreach ([false, true] as $afterMigration) {
    foreach ([false, true] as $historical) {
        $db = local_import_database($historyRelease);
        // Synthetic timestamp proves that acceptance never rewrites journal metadata.
        $db->exec("ALTER TABLE schema_migrations ADD applied_at TEXT NOT NULL DEFAULT '2026-08-07 12:00:00'");
        if (!$afterMigration) { $db->exec("DELETE FROM schema_migrations WHERE migration_id='commerce-v3-cart-inventory'"); }
        if ($historical) { $db->prepare("UPDATE schema_migrations SET checksum=? WHERE migration_id='commerce-v1-schema'")->execute([$historyChecksum]); }
        $before = history_journal($db);
        // Database-level write denial, not merely a comparison after the fact.
        $db->exec('PRAGMA query_only=ON');
        for ($i=0; $i<2; $i++) { CarmajaCartCutover::assertJournal($db, $historyRelease, $afterMigration); }
        release_check(history_journal($db) === $before, 'current/AP6 journal accepted read-only before/after cart, repeat identical');
        $db->exec('PRAGMA query_only=OFF');
        $db->exec("INSERT INTO schema_migrations(migration_id,checksum) VALUES ('unexpected-ap3b','" . str_repeat('f',64) . "')");
        history_reject(fn () => CarmajaCartCutover::assertJournal($db,$historyRelease,$afterMigration), 'schema_journal_unexpected');
        $db->exec("DELETE FROM schema_migrations WHERE migration_id='unexpected-ap3b'");
        foreach (array_keys($before) as $index) {
            $id = $before[$index]['migration_id']; $checksum = $before[$index]['checksum'];
            $db->prepare('UPDATE schema_migrations SET checksum=? WHERE migration_id=?')->execute([str_repeat('0',64), $id]);
            $badBefore = history_journal($db);
            history_reject(fn () => CarmajaCartCutover::assertJournal($db,$historyRelease,$afterMigration), 'schema_journal_conflict');
            release_check(history_journal($db) === $badBefore, 'rejected journal not repaired');
            $db->prepare('UPDATE schema_migrations SET checksum=? WHERE migration_id=?')->execute([$checksum,$id]);
        }
        $db->exec("DELETE FROM schema_migrations WHERE migration_id='commerce-v2-collections'");
        history_reject(fn () => CarmajaCartCutover::assertJournal($db,$historyRelease,$afterMigration), 'schema_journal_unexpected');
        unset($db);
    }
}
$db = local_import_database($historyRelease);
$db->prepare("UPDATE schema_migrations SET checksum=? WHERE migration_id='commerce-v1-schema'")->execute([$historyChecksum]);
foreach ([[], array_slice($historyRelease['migrations'],1), [...$historyRelease['migrations'],$historyRelease['migrations'][0]]] as $invalid) {
    history_reject(fn () => CarmajaCartCutover::assertJournal($db,[...$historyRelease,'migrations'=>$invalid],true), 'release_contract_invalid');
}
foreach ([null, '', 123, str_repeat('x',64)] as $invalidHash) {
    $invalidRelease = $historyRelease;
    $invalidRelease['migrations'][0]['journalSha256'] = $invalidHash;
    history_reject(fn () => CarmajaCartCutover::assertJournal($db,$invalidRelease,true), 'release_contract_invalid');
}
foreach ([0,3] as $index) {
    $differentRelease = $historyRelease;
    $differentRelease['migrations'][$index]['journalSha256'] = str_repeat('e',64);
    if ($index === 3) { $db->exec("UPDATE schema_migrations SET checksum='" . str_repeat('e',64) . "' WHERE migration_id='commerce-v2-collections'"); }
    history_reject(fn () => CarmajaCartCutover::assertJournal($db,$differentRelease,true), 'schema_journal_conflict');
}
unset($db);

// Existing collection data plus historical journal, then counted import/replay.
$db = local_import_database($historyRelease);
$db->prepare("UPDATE schema_migrations SET checksum=? WHERE migration_id='commerce-v1-schema'")->execute([$historyChecksum]);
$db->exec('CREATE TABLE historical_orders(id TEXT PRIMARY KEY,snapshot TEXT);');
$db->exec("INSERT INTO historical_orders VALUES ('synthetic-old-order','unchanged old price/legal/payment evidence')");
$before = history_journal($db);
$oldOrders = $db->query('SELECT * FROM historical_orders')->fetchAll(PDO::FETCH_ASSOC);
$db->failSecondAdjustment = true;
history_reject(fn () => CarmajaCartCutover::importCounts($db,$maintenance,$two,$source,$historyRelease), 'injected_second_adjustment_failure');
release_check((int)$db->query('SELECT COUNT(*) FROM inventory_adjustments')->fetchColumn() === 0, 'historical import failure fully rolled back');
$db->failSecondAdjustment = false;
for ($i=0; $i<2; $i++) { CarmajaCartCutover::importCounts($db,$maintenance,$two,$source,$historyRelease); }
release_check((int)$db->query('SELECT SUM(on_hand) FROM commerce_inventory')->fetchColumn() === 6, 'historical import repeated without doubled quantities');
release_check((int)$db->query('SELECT COUNT(*) FROM inventory_adjustments')->fetchColumn() === 2, 'historical import audit exactly once');
release_check(history_journal($db) === $before, 'historical journal preserved through import and retry');
release_check($db->query('SELECT * FROM historical_orders')->fetchAll(PDO::FETCH_ASSOC) === $oldOrders, 'artificial old order evidence preserved');
unset($db);
echo "Production cart history: {$checks} local checks passed; all in-memory fixtures discarded.\n";
