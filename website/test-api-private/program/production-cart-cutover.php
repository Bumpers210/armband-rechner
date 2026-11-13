<?php

declare(strict_types=1);

require_once __DIR__ . '/cart-commerce.php';
require_once __DIR__ . '/product-api.php';
require_once __DIR__ . '/product-api-v2.php';
require_once __DIR__ . '/product-api-v3.php';

/** No provider calls, product writes, publication or checkout activation. */
final class CarmajaCartCutover
{
    public const LEGAL_ID = 'cmj-production-legal-2026-10-25-v7';
    public const MIGRATIONS = ['commerce-v1-schema', 'commerce-v1-ap4-shop',
        'commerce-v1-ap5-admin', 'commerce-v2-collections', 'commerce-v3-cart-inventory'];

    // Exact AP6 baseline from bb4345fbfb26fede5bdf61be0ef6191746a98ef0.
    // b4c447a later inlined the separately journaled collections additions
    // into the base SQL. This is one verified upgrade path, not a wildcard.
    // SHA-256 normalization matches migrate(): LF, then strip SQL comments.
    private const AP6_BASELINE = '388e3d44299ae0890fb83d4881d87aa26d7e93d30ee3af8ac07033a4106ea27b';
    private const COLLECTIONS_BASELINE = 'fed1e46e6635c3f3fd60ec703cea5f14e841fcbe43629eec2ce9032b2bac52f8';
    private const COLLECTIONS_FORWARD = '33bc5be8e76781b73ff6c23950bb99ac4d9bea67e4632c917f3002b37550d00d';

    private static function require(bool $condition, string $code): void
    {
        if (!$condition) { throw new RuntimeException($code); }
    }

    public static function plan(array $manifest, array $products, array $release): array
    {
        self::require(($manifest['manifestVersion'] ?? null) === 3
            && ($manifest['environment'] ?? null) === 'production'
            && ($manifest['productModelVersion'] ?? null) === 4
            && ($manifest['productApiVersion'] ?? null) === 5
            && ($manifest['shopApiVersion'] ?? null) === 3
            && ($manifest['salesModel'] ?? null) === 'stocked_collection'
            && ($manifest['inventorySource'] ?? null) === 'commerce_inventory'
            && ($manifest['paymentMethodTypes'] ?? null) === ['card', 'klarna', 'sepa_debit']
            && ($manifest['shippingAmountMinor'] ?? null) === 270
            && ($manifest['maxOrderQuantity'] ?? null) === 10
            && ($manifest['legalBundleId'] ?? null) === self::LEGAL_ID, 'cutover_contract_invalid');
        self::require(($release['formatVersion'] ?? null) === 1
            && ($release['releaseKind'] ?? null) === 'cart-production'
            && ($release['legalBundleId'] ?? null) === self::LEGAL_ID
            && array_column($release['migrations'] ?? [], 'migrationId') === self::MIGRATIONS,
            'release_contract_invalid');
        $selected = $manifest['selectedProducts'] ?? null;
        self::require(is_array($selected) && array_is_list($selected), 'selection_invalid');
        $byId = [];
        foreach ($products as $product) {
            self::require(is_array($product) && is_string($product['productId'] ?? null)
                && !isset($byId[$product['productId']]), 'source_duplicate_or_invalid');
            $byId[$product['productId']] = $product;
        }
        $operations = []; $ids = []; $keys = [];
        foreach ($selected as $item) {
            self::require(is_array($item) && count($item) === 6
                && is_string($item['productId'] ?? null) && is_string($item['operationId'] ?? null), 'selection_invalid');
            $id = $item['productId']; $key = $item['operationId'];
            carmaja_commerce_assert_id($id, 'productId');
            carmaja_commerce_assert_id($key, 'operationId');
            self::require(!isset($ids[$id]) && !isset($keys[$key]), 'selection_duplicate');
            $ids[$id] = true; $keys[$key] = true;
            $body = ['targetOnHand' => $item['targetOnHand'] ?? null,
                'expectedInventoryVersion' => $item['expectedInventoryVersion'] ?? null,
                'reason' => 'initial_count', 'correlationId' => $key];
            carmaja_inventory_adjustment($body);
            $product = $byId[$id] ?? null;
            self::require(is_array($product) && ($product['productModelVersion'] ?? null) === 4
                && is_int($product['productVersion'] ?? null) && $product['productVersion'] > 0
                && $product['productVersion'] === ($item['expectedProductVersion'] ?? null)
                && is_string($product['sourceHash'] ?? null)
                && $product['sourceHash'] === ($item['expectedSourceHash'] ?? null)
                && hash_equals(carmaja_api_v3_source_hash($product), $product['sourceHash'])
                && is_int($product['priceMinor'] ?? null) && $product['priceMinor'] > 0
                && ($product['currency'] ?? null) === 'eur'
                && is_bool($product['salesEnabled'] ?? null)
                && is_array($product['images'] ?? null) && count($product['images']) > 0,
                'product_version_or_hash_conflict');
            self::require(!array_key_exists('stock', $product) && !array_key_exists('vintedUrl', $product), 'legacy_product_field');
            $operations[] = ['product' => $product, 'adjustment' => [
                'productId' => $id, ...$body, 'idempotencyKey' => $key,
            ]];
        }
        usort($operations, static fn ($a, $b) => strcmp($a['product']['productId'], $b['product']['productId']));
        $approved = ($manifest['status'] ?? null) === 'approved_for_cutover'
            && preg_match('/^[a-f0-9]{40}$/D', (string) ($manifest['releaseSha'] ?? '')) === 1
            && ($manifest['releaseSha'] ?? null) === ($release['sourceCommit'] ?? null)
            && ($release['workingTreeDirty'] ?? true) === false
            && ($manifest['legalBundleHash'] ?? null) === ($release['legalBundleHash'] ?? null)
            && preg_match('/^[a-f0-9]{64}$/D', (string) ($manifest['restoreProofSha256'] ?? '')) === 1
            && preg_match('/^[0-9]{8}T[0-9]{6}Z-[a-f0-9]{12}$/D', (string) ($manifest['backupId'] ?? '')) === 1
            && preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}Z$/D', (string) ($manifest['countConfirmedAt'] ?? '')) === 1
            && is_string($manifest['actorId'] ?? null) && preg_match('/^[A-Za-z0-9._:-]{8,100}$/D', $manifest['actorId']) === 1
            && count($operations) > 0;
        return ['readyForApply' => $approved, 'selectedProductCount' => count($operations),
            'operations' => $operations, 'checkoutActivation' => false];
    }

    public static function requireMaintenance(array $config): void
    {
        self::require(($config['environment'] ?? null) === 'production'
            && ($config['shopCheckoutEnabled'] ?? null) === false
            && ($config['productApiV5WritesEnabled'] ?? null) === false
            && ($config['productApiV4WritesEnabled'] ?? null) === false
            && ($config['productionPublishEnabled'] ?? null) === false
            && ($config['githubAdapterEnabled'] ?? null) === false
            && ($config['commerceRequireTls'] ?? null) === true, 'maintenance_gates_required');
    }

    public static function assertDrained(PDO $pdo): void
    {
        foreach ([
            "SELECT COUNT(*) FROM checkout_sagas WHERE state NOT IN ('completed','failed','expired','canceled')",
            "SELECT COUNT(*) FROM payments WHERE status NOT IN ('succeeded','failed','canceled')",
            "SELECT COUNT(*) FROM reservations WHERE blocks_stock=1",
            "SELECT COUNT(*) FROM webhook_inbox WHERE status <> 'processed'",
        ] as $sql) {
            self::require((int) $pdo->query($sql)->fetchColumn() === 0, 'old_payments_or_events_not_drained');
        }
    }

    public static function assertJournal(PDO $pdo, array $release, bool $afterMigration): void
    {
        $migrations = $release['migrations'] ?? null;
        self::require(is_array($migrations) && array_is_list($migrations)
            && count($migrations) === count(self::MIGRATIONS), 'release_contract_invalid');
        foreach ($migrations as $index => $migration) {
            self::require(is_array($migration)
                && ($migration['migrationId'] ?? null) === self::MIGRATIONS[$index]
                && is_string($migration['journalSha256'] ?? null)
                && preg_match('/^[a-f0-9]{64}$/D', $migration['journalSha256']) === 1,
                'release_contract_invalid');
        }
        $journal = $pdo->query('SELECT migration_id,checksum FROM schema_migrations')->fetchAll(PDO::FETCH_KEY_PAIR);
        $expected = $afterMigration ? self::MIGRATIONS : array_slice(self::MIGRATIONS, 0, 4);
        $actual = array_keys($journal); sort($actual); $ids = $expected; sort($ids);
        self::require($actual === $ids, 'schema_journal_unexpected');
        foreach ($migrations as $migration) {
            if (!in_array($migration['migrationId'], $expected, true)) { continue; }
            $stored = $journal[$migration['migrationId']];
            $historicalUpgrade = $migration['migrationId'] === 'commerce-v1-schema'
                && $stored === self::AP6_BASELINE
                && $migration['journalSha256'] === self::COLLECTIONS_BASELINE
                && $journal['commerce-v2-collections'] === self::COLLECTIONS_FORWARD
                && $migrations[3]['journalSha256'] === self::COLLECTIONS_FORWARD;
            self::require(is_string($stored)
                && (hash_equals($migration['journalSha256'], $stored) || $historicalUpgrade),
                'schema_journal_conflict');
        }
        // Read-only acceptance: retain original checksums and applied_at values.
        // Never re-run the base schema to "repair" a historical journal.
    }

    /** Caller holds the private maintenance lock, with app/publisher writes off. */
    public static function importCounts(PDO $pdo, array $config, array $manifest, array $products, array $release, ?Closure $guard = null): array
    {
        self::require(!$pdo->inTransaction(), 'unexpected_transaction');
        self::requireMaintenance($config);
        $plan = self::plan($manifest, $products, $release);
        self::require($plan['readyForApply'], 'cutover_not_approved');
        self::assertJournal($pdo, $release, true);
        self::assertDrained($pdo);
        $commerce = new CarmajaCartCommerce($pdo);
        return $commerce->transaction(static function () use ($pdo, $commerce, $plan, $manifest, $guard): array {
            // Optional private caller guard uses THIS transaction/connection. No
            // provider/backup I/O, independent inventory lock connection or commit.
            // It is repeated in full after a deadlock/timeout rollback.
            if ($guard !== null) $guard('before_import', $pdo);
            $legal = $pdo->prepare('SELECT status,bundle_hash FROM legal_bundles WHERE legal_bundle_id=? FOR UPDATE');
            $legal->execute([self::LEGAL_ID]); $bundle = $legal->fetch(PDO::FETCH_ASSOC);
            self::require(is_array($bundle) && $bundle['status'] === 'approved'
                && hash_equals($manifest['legalBundleHash'], $bundle['bundle_hash']), 'approved_legal_bundle_missing');
            $result = [];
            foreach ($plan['operations'] as $operation) {
                $product = $operation['product']; $adjustment = $operation['adjustment'];
                $initial = $pdo->prepare('SELECT COUNT(*) FROM inventory_initializations WHERE product_id=? AND applied_at IS NULL');
                $initial->execute([$product['productId']]);
                self::require((int) $initial->fetchColumn() === 0, 'pending_initial_stock_conflict');
                $projectionKey = 'cart-cutover-' . hash('sha256', $adjustment['idempotencyKey']);
                $commerce->projectCollectionProduct($product, 'publish', $projectionKey,
                    hash('sha256', carmaja_commerce_json($product)));
                // Preserves the authoritative sales permission. No publish adapter,
                // authoritative data writes or automatic checkout activation.
                $result[] = $commerce->adjustInventory($adjustment, $manifest['actorId']);
            }
            if ($guard !== null) $guard('before_commit', $pdo);
            return ['importedProducts' => count($result), 'checkoutActivation' => false];
        });
    }
}
