import { createHash } from "node:crypto";
import { mkdtemp, readFile, rm, writeFile } from "node:fs/promises";
import os from "node:os";
import path from "node:path";
import { fileURLToPath } from "node:url";

export const MIGRATION_BACKUP_CONTRACT_VERSION = 1;
export const MIGRATION_TARGET_ENGINE = "mysql8-innodb";
export const STOCK_ROLLBACK_LOCKED_CODE = "stock_rollback_locked";

const ARTIFICIAL_DATABASE_PATTERN = /^ap16_test_[a-z0-9_]+$/;

function canonicalize(value) {
  if (Array.isArray(value)) {
    return value.map((entry) => canonicalize(entry));
  }

  if (value !== null && typeof value === "object") {
    return Object.fromEntries(
      Object.keys(value)
        .sort()
        .map((key) => [key, canonicalize(value[key])]),
    );
  }

  return value;
}

export function stableStringify(value) {
  return JSON.stringify(canonicalize(value));
}

export function sha256Json(value) {
  return createHash("sha256").update(stableStringify(value), "utf8").digest("hex");
}

export function sha256Text(value) {
  return createHash("sha256").update(value, "utf8").digest("hex");
}

function requireArtificialDatabase(databaseName) {
  if (typeof databaseName !== "string" || !ARTIFICIAL_DATABASE_PATTERN.test(databaseName)) {
    throw new Error("AP1.6 erlaubt ausschließlich künstliche Testdatenbanknamen.");
  }
}

function requireList(value, label) {
  if (!Array.isArray(value)) {
    throw new Error(`${label} muss eine Liste sein.`);
  }

  return value;
}

export function createMySqlTestTarget({ databaseName, schema, rows }) {
  requireArtificialDatabase(databaseName);
  requireList(schema, "schema");
  requireList(rows, "rows");

  const schemaHash = sha256Json(schema);
  const rowsHash = sha256Json(rows);
  const dump = [
    "-- AP1.6 artificial target; execution is intentionally delegated to AP2",
    `-- engine=${MIGRATION_TARGET_ENGINE}`,
    `-- database=${databaseName}`,
    `-- schemaHash=${schemaHash}`,
    `-- rowsHash=${rowsHash}`,
    stableStringify({ schema, rows }),
    "",
  ].join("\n");

  return {
    databaseName,
    engine: MIGRATION_TARGET_ENGINE,
    schema,
    rows,
    schemaHash,
    rowsHash,
    dump,
    dumpHash: sha256Text(dump),
    checksum: sha256Json({ databaseName, schemaHash, rowsHash, dumpHash: sha256Text(dump) }),
  };
}

export function createProductSourceSnapshot({ sourceName, products, publicProjection }) {
  if (sourceName !== "artificial-product-source") {
    throw new Error("AP1.6 akzeptiert nur die künstliche Produktquelle.");
  }

  requireList(products, "products");
  requireList(publicProjection, "publicProjection");

  const recordHashes = products.map((product) => ({
    productId: product.productId ?? null,
    hash: sha256Json(product),
  }));

  return {
    sourceName,
    products,
    publicProjection,
    recordsHash: sha256Json(recordHashes),
    projectionHash: sha256Json(publicProjection),
    recordHashes,
  };
}

export function createMigrationBackup({ source, target, commerceCheckoutCount = 0 }) {
  if (commerceCheckoutCount !== 0) {
    throw new Error("AP1.6-Backup muss vor dem ersten Commerce-Checkout erstellt werden.");
  }

  const unsigned = {
    contractVersion: MIGRATION_BACKUP_CONTRACT_VERSION,
    kind: "ap1-product-migration-backup",
    environment: "test",
    phase: "before_first_commerce_checkout",
    source,
    target: {
      databaseName: target.databaseName,
      engine: target.engine,
      schema: target.schema,
      rows: target.rows,
      dump: target.dump,
      schemaHash: target.schemaHash,
      rowsHash: target.rowsHash,
      dumpHash: target.dumpHash,
      checksum: target.checksum,
    },
    commerceCheckoutCount,
  };

  return {
    ...unsigned,
    artifactHash: sha256Json(unsigned),
  };
}

export function serializeMigrationBackup(backup) {
  return `${JSON.stringify(canonicalize(backup), null, 2)}\n`;
}

function verifyBackup(backup) {
  if (backup?.contractVersion !== MIGRATION_BACKUP_CONTRACT_VERSION
    || backup.kind !== "ap1-product-migration-backup"
    || backup.environment !== "test"
    || backup.phase !== "before_first_commerce_checkout") {
    throw new Error("backup_contract_invalid");
  }

  const { artifactHash, ...unsigned } = backup;
  if (artifactHash !== sha256Json(unsigned)) {
    throw new Error("backup_checksum_mismatch");
  }

  const target = backup.target;
  requireArtificialDatabase(target.databaseName);
  if (target.engine !== MIGRATION_TARGET_ENGINE
    || target.schemaHash !== sha256Json(target.schema)
    || target.rowsHash !== sha256Json(target.rows)
    || target.dumpHash !== sha256Text(target.dump)
    || target.checksum !== sha256Json({
      databaseName: target.databaseName,
      schemaHash: target.schemaHash,
      rowsHash: target.rowsHash,
      dumpHash: target.dumpHash,
    })) {
    throw new Error("target_checksum_mismatch");
  }
}

export function restoreMigrationBackup(backup, { databaseName }) {
  verifyBackup(backup);
  requireArtificialDatabase(databaseName);

  if (databaseName !== backup.target.databaseName) {
    throw new Error("restore_database_mismatch");
  }

  return createMySqlTestTarget({
    databaseName,
    schema: backup.target.schema,
    rows: backup.target.rows,
  });
}

export function compareRestoredTarget(expected, restored) {
  const checks = {
    databaseName: expected.databaseName === restored.databaseName,
    engine: expected.engine === restored.engine,
    schema: expected.schemaHash === restored.schemaHash,
    rows: expected.rowsHash === restored.rowsHash,
    dump: expected.dumpHash === restored.dumpHash,
    checksum: expected.checksum === restored.checksum,
  };

  return {
    passed: Object.values(checks).every(Boolean),
    checks,
    expected: {
      schemaHash: expected.schemaHash,
      rowsHash: expected.rowsHash,
      dumpHash: expected.dumpHash,
      checksum: expected.checksum,
    },
    restored: {
      schemaHash: restored.schemaHash,
      rowsHash: restored.rowsHash,
      dumpHash: restored.dumpHash,
      checksum: restored.checksum,
    },
  };
}

export function evaluateStockRollback({ commerceCheckoutCount }) {
  if (!Number.isInteger(commerceCheckoutCount) || commerceCheckoutCount < 0) {
    throw new Error("commerceCheckoutCount muss eine nichtnegative Ganzzahl sein.");
  }

  if (commerceCheckoutCount > 0) {
    return {
      allowed: false,
      code: STOCK_ROLLBACK_LOCKED_CODE,
      reason: "Nach dem ersten Commerce-Checkout darf keine Rückkehr zu stock erfolgen.",
    };
  }

  return {
    allowed: true,
    code: "stock_rollback_allowed_before_commerce_checkout",
    reason: "Rollback zu stock ist nur vor dem ersten Commerce-Checkout zulässig.",
  };
}

function artificialScenario() {
  const products = [
    {
      productId: "11111111-1111-4111-8111-111111111111",
      productVersion: 1,
      sourceHash: "a".repeat(64),
      priceMinor: 2490,
      currency: "eur",
      salesEnabled: true,
      stock: 1,
    },
  ];
  const publicProjection = [{
    productId: products[0].productId,
    productVersion: 1,
    sourceHash: products[0].sourceHash,
    priceMinor: products[0].priceMinor,
    currency: products[0].currency,
    salesEnabled: true,
  }];
  const source = createProductSourceSnapshot({
    sourceName: "artificial-product-source",
    products,
    publicProjection,
  });
  const target = createMySqlTestTarget({
    databaseName: "ap16_test_migration",
    schema: [
      {
        table: "ap16_products",
        engine: "InnoDB",
        columns: ["product_id", "product_version", "stock"],
      },
    ],
    rows: [{
      product_id: products[0].productId,
      product_version: 1,
      stock: 1,
    }],
  });

  return { source, target };
}

export async function runIsolatedBackupScenario() {
  const temporaryRoot = await mkdtemp(path.join(os.tmpdir(), "carmaja-ap16-backup-"));
  let report;

  try {
    const { source, target } = artificialScenario();
    const backup = createMigrationBackup({ source, target });
    const backupPath = path.join(temporaryRoot, "migration-backup.json");
    await writeFile(backupPath, serializeMigrationBackup(backup), "utf8");
    const loaded = JSON.parse(await readFile(backupPath, "utf8"));
    const restored = restoreMigrationBackup(loaded, { databaseName: target.databaseName });
    const comparison = compareRestoredTarget(target, restored);
    const repeated = createMigrationBackup({ source, target });

    report = {
      backupHash: backup.artifactHash,
      repeatedBackupIdentical: serializeMigrationBackup(backup) === serializeMigrationBackup(repeated),
      comparison,
      rollbackBeforeCheckout: evaluateStockRollback({ commerceCheckoutCount: 0 }),
      rollbackAfterCheckout: evaluateStockRollback({ commerceCheckoutCount: 1 }),
      temporaryRoot,
    };
  } finally {
    await rm(temporaryRoot, { recursive: true, force: true });
  }

  return {
    ...report,
    cleanupVerified: true,
  };
}

export function formatBackupReport(report) {
  const lines = [
    "AP1.6 Backup-/Restore-Nachweis",
    `Backup-Hash: ${report.backupHash}`,
    `Wiederholbarkeit: ${report.repeatedBackupIdentical ? "bestanden" : "fehlgeschlagen"}`,
    `Struktur/Inhalt/Prüfsummen: ${report.comparison.passed ? "bestanden" : "fehlgeschlagen"}`,
    `Rollback vor Checkout: ${report.rollbackBeforeCheckout.allowed ? "zulässig" : "gesperrt"}`,
    `Rollback nach Checkout: ${report.rollbackAfterCheckout.code}`,
    `Cleanup: ${report.cleanupVerified ? "bestanden" : "ausstehend"}`,
  ];

  return `${lines.join("\n")}\n`;
}

if (process.argv[1] === fileURLToPath(import.meta.url)) {
  if (process.argv.includes("--self-test")) {
    runIsolatedBackupScenario()
      .then((report) => process.stdout.write(`${JSON.stringify(report, null, 2)}\n`))
      .catch((error) => {
        process.stderr.write(`${error instanceof Error ? error.message : "Unbekannter Fehler"}\n`);
        process.exitCode = 1;
      });
  } else {
    process.stderr.write("Verwendung: node scripts/product-migration-backup.mjs --self-test\n");
    process.exitCode = 2;
  }
}
