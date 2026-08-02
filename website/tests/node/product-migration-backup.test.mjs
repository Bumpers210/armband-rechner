import assert from "node:assert/strict";
import { stat } from "node:fs/promises";
import test from "node:test";

import {
  compareRestoredTarget,
  createMigrationBackup,
  createMySqlTestTarget,
  createProductSourceSnapshot,
  evaluateStockRollback,
  restoreMigrationBackup,
  runIsolatedBackupScenario,
  serializeMigrationBackup,
} from "../../scripts/product-migration-backup.mjs";

function fixture() {
  const product = {
    productId: "11111111-1111-4111-8111-111111111111",
    productVersion: 1,
    sourceHash: "a".repeat(64),
    priceMinor: 2490,
    currency: "eur",
    salesEnabled: true,
    stock: 1,
  };
  const source = createProductSourceSnapshot({
    sourceName: "artificial-product-source",
    products: [product],
    publicProjection: [{
      productId: product.productId,
      productVersion: product.productVersion,
      sourceHash: product.sourceHash,
      priceMinor: product.priceMinor,
      currency: product.currency,
      salesEnabled: product.salesEnabled,
    }],
  });
  const target = createMySqlTestTarget({
    databaseName: "ap16_test_fixture",
    schema: [{
      table: "ap16_products",
      engine: "InnoDB",
      columns: ["product_id", "product_version", "stock"],
    }],
    rows: [{ product_id: product.productId, product_version: 1, stock: 1 }],
  });

  return { source, target };
}

test("AP1.6 sichert und restauriert das künstliche MySQL-8/InnoDB-Ziel", () => {
  const { source, target } = fixture();
  const backup = createMigrationBackup({ source, target });
  const restored = restoreMigrationBackup(backup, { databaseName: target.databaseName });
  const comparison = compareRestoredTarget(target, restored);

  assert.equal(comparison.passed, true);
  assert.equal(backup.environment, "test");
  assert.equal(backup.target.engine, "mysql8-innodb");
  assert.match(backup.artifactHash, /^[0-9a-f]{64}$/);
});

test("AP1.6-Backup ist deterministisch und erkennt Manipulation", () => {
  const { source, target } = fixture();
  const first = createMigrationBackup({ source, target });
  const second = createMigrationBackup({ source, target });

  assert.equal(serializeMigrationBackup(first), serializeMigrationBackup(second));
  assert.throws(
    () => restoreMigrationBackup({ ...first, target: { ...first.target, rows: [] } }, {
      databaseName: target.databaseName,
    }),
    /backup_checksum_mismatch/,
  );
});

test("AP1.6 schützt das Ziel gegen Verwechslung und Produktionsnamen", () => {
  const { source, target } = fixture();
  assert.throws(
    () => createMySqlTestTarget({ databaseName: "production", schema: [], rows: [] }),
    /künstliche Testdatenbanknamen/,
  );
  const backup = createMigrationBackup({ source, target });
  assert.throws(
    () => restoreMigrationBackup(backup, { databaseName: "ap16_test_other" }),
    /restore_database_mismatch/,
  );
});

test("AP1.6 sperrt stock-Rollback nach dem ersten Commerce-Checkout", () => {
  assert.equal(evaluateStockRollback({ commerceCheckoutCount: 0 }).allowed, true);
  assert.deepEqual(evaluateStockRollback({ commerceCheckoutCount: 1 }), {
    allowed: false,
    code: "stock_rollback_locked",
    reason: "Nach dem ersten Commerce-Checkout darf keine Rückkehr zu stock erfolgen.",
  });
});

test("AP1.6 isolierter Nachweis bereinigt seine temporären Artefakte", async () => {
  const report = await runIsolatedBackupScenario();

  assert.equal(report.repeatedBackupIdentical, true);
  assert.equal(report.comparison.passed, true);
  assert.equal(report.rollbackBeforeCheckout.allowed, true);
  assert.equal(report.rollbackAfterCheckout.allowed, false);
  assert.equal(report.cleanupVerified, true);
  await assert.rejects(stat(report.temporaryRoot), { code: "ENOENT" });
});
