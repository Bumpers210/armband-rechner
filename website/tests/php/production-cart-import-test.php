<?php
declare(strict_types=1);
require_once __DIR__ . '/production-cart-cutover-test.php';

// Transactional SQL adapter test only: SQLite executes the DML after a narrow
// MySQL syntax translation. This does NOT claim MySQL DDL/locking/TLS evidence.
final class LocalCartImportPdo extends PDO
{
    public bool $failSecondAdjustment = false;
    private int $adjustments = 0;
    public function __construct() { parent::__construct('sqlite::memory:'); }
    private function translate(string $sql): string {
        $sql = str_replace([' FOR UPDATE', 'UTC_TIMESTAMP(6)'], ['', "'2026-11-10 12:00:00'"], $sql);
        $sql = str_replace('ON DUPLICATE KEY UPDATE', 'ON CONFLICT(product_id) DO UPDATE SET', $sql);
        return preg_replace('/VALUES\(([a-z_]+)\)/', 'excluded.$1', $sql);
    }
    public function exec(string $statement): int|false {
        if (str_starts_with($statement, 'SET ')) { return 0; }
        return parent::exec($this->translate($statement));
    }
    public function prepare(string $query, array $options = []): PDOStatement|false {
        if ($this->failSecondAdjustment && str_starts_with($query, 'UPDATE commerce_inventory') && ++$this->adjustments === 2) {
            throw new RuntimeException('injected_second_adjustment_failure');
        }
        return parent::prepare($this->translate($query), $options);
    }
    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false {
        return $fetchMode === null ? parent::query($this->translate($query))
            : parent::query($this->translate($query), $fetchMode, ...$fetchModeArgs);
    }
}

function local_import_database(array $release): LocalCartImportPdo {
    $pdo = new LocalCartImportPdo();
    $pdo->exec('CREATE TABLE schema_migrations(migration_id TEXT PRIMARY KEY, checksum TEXT);
        CREATE TABLE legal_bundles(legal_bundle_id TEXT PRIMARY KEY, status TEXT, bundle_hash TEXT);
        CREATE TABLE checkout_sagas(state TEXT); CREATE TABLE payments(status TEXT);
        CREATE TABLE webhook_inbox(status TEXT);
        CREATE TABLE reservations(product_id TEXT,quantity INTEGER,state TEXT,blocks_stock INTEGER);
        CREATE TABLE commerce_products(product_id TEXT PRIMARY KEY,product_version INTEGER,source_hash TEXT,
            name TEXT,description TEXT,materials TEXT,metal_elements TEXT,bracelet_size TEXT,care_instructions TEXT,
            images TEXT,price_minor INTEGER,currency TEXT,sales_enabled INTEGER,sales_model TEXT,synchronized_at TEXT,
            notice_snapshot TEXT,public_snapshot TEXT);
        CREATE TABLE commerce_inventory(product_id TEXT PRIMARY KEY,on_hand INTEGER,inventory_version INTEGER,quantity_confirmed INTEGER);
        CREATE TABLE product_projection_operations(operation_id TEXT PRIMARY KEY,request_hash TEXT,product_id TEXT,action TEXT,result TEXT);
        CREATE TABLE inventory_initializations(product_id TEXT,applied_at TEXT);
        CREATE TABLE inventory_adjustments(product_id TEXT,target_on_hand INTEGER,previous_on_hand INTEGER,
            inventory_version INTEGER,reason TEXT,correlation_id TEXT,idempotency_key TEXT UNIQUE,actor_id TEXT);');
    foreach ($release['migrations'] as $row) { $pdo->prepare('INSERT INTO schema_migrations VALUES (?,?)')->execute([$row['migrationId'],$row['journalSha256']]); }
    $pdo->prepare('INSERT INTO legal_bundles VALUES (?,?,?)')->execute([CarmajaCartCutover::LEGAL_ID,'approved',$release['legalBundleHash']]);
    return $pdo;
}

$second = [...$product, 'productId'=>'artificial-0002'];
$second['sourceHash'] = carmaja_api_v3_source_hash($second);
$other = [...$selection, 'productId'=>$second['productId'], 'expectedSourceHash'=>$second['sourceHash'], 'operationId'=>'artificial-count-0002'];
$two = [...$manifest, 'selectedProducts'=>[$other,$selection]];
$source = [$second,$product]; $sourceBefore = json_encode($source);
$pdo = local_import_database($release);
// Existing collections have no quantity. Seed only a verified empty row here.
foreach ($source as $p) { $pdo->prepare('INSERT INTO commerce_inventory VALUES (?,0,0,0)')->execute([$p['productId']]); }
$pdo->failSecondAdjustment = true;
release_reject(fn () => CarmajaCartCutover::importCounts($pdo,$maintenance,$two,$source,$release), 'injected crash rolls back complete import');
release_check((int)$pdo->query('SELECT SUM(on_hand) FROM commerce_inventory')->fetchColumn() === 0, 'no partial quantity on failure');
release_check((int)$pdo->query('SELECT COUNT(*) FROM inventory_adjustments')->fetchColumn() === 0, 'no partial audit on failure');
release_check((int)$pdo->query('SELECT COUNT(*) FROM commerce_products')->fetchColumn() === 0, 'no partial projection on failure');
$pdo->failSecondAdjustment = false;
$imported = CarmajaCartCutover::importCounts($pdo,$maintenance,$two,$source,$release);
release_check($imported['importedProducts'] === 2, 'two counted products imported');
release_check((int)$pdo->query('SELECT SUM(on_hand) FROM commerce_inventory')->fetchColumn() === 6, 'only confirmed quantities imported');
release_check((int)$pdo->query('SELECT SUM(sales_enabled) FROM commerce_products')->fetchColumn() === 0, 'no sales activation');
CarmajaCartCutover::importCounts($pdo,$maintenance,$two,$source,$release);
release_check((int)$pdo->query('SELECT COUNT(*) FROM inventory_adjustments')->fetchColumn() === 2, 'repeat creates no duplicate audit');
$pdo->exec("UPDATE commerce_inventory SET on_hand=2,inventory_version=2 WHERE product_id='artificial-0001'");
CarmajaCartCutover::importCounts($pdo,$maintenance,$two,$source,$release);
release_check((int)$pdo->query('SELECT SUM(on_hand) FROM commerce_inventory')->fetchColumn() === 5, 'replay does not undo subsequent inventory change');
release_reject(fn () => CarmajaCartCutover::importCounts($pdo,$maintenance,
    [...$manifest,'selectedProducts'=>[[...$selection,'targetOnHand'=>4]]],[$product],$release), 'changed idempotent operation rejected');
$pdo->exec("INSERT INTO payments VALUES ('processing')");
release_reject(fn () => CarmajaCartCutover::assertDrained($pdo), 'SEPA processing blocks cutover');
$pdo->exec('DELETE FROM payments');
$pdo->exec("INSERT INTO reservations VALUES ('artificial-0001',1,'expired',1)");
release_reject(fn () => CarmajaCartCutover::assertDrained($pdo), 'expired but still blocking reservation retained');
$pdo->exec("UPDATE reservations SET state='converted'");
release_reject(fn () => CarmajaCartCutover::assertDrained($pdo), 'inconsistent blocking reservation also stops cutover');
release_check(json_encode($source) === $sourceBefore, 'authoritative products never changed');
unset($pdo);
echo "Production cart import: {$checks} local checks passed; in-memory database discarded.\n";
