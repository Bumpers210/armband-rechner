import assert from "node:assert/strict";
import { createHash } from "node:crypto";
import { readFile, mkdtemp, rm, writeFile } from "node:fs/promises";
import os from "node:os";
import path from "node:path";
import { spawnSync } from "node:child_process";
import test from "node:test";

import {
  formatMigrationDryRunText,
  runMigrationDryRun,
  stableStringify,
} from "../../scripts/product-migration-dry-run.mjs";

const scriptPath = path.resolve("scripts/product-migration-dry-run.mjs");

function sourceProduct(overrides = {}) {
  const product = {
    productModelVersion: 2,
    productId: "11111111-1111-4111-8111-111111111111",
    productVersion: 1,
    sourceHash: "",
    sku: "CP-2026-0001",
    slug: "cp-2026-0001-testarmband",
    name: "Testarmband",
    description: "Ein Testarmband.",
    materials: ["Rosenquarz"],
    metalElements: [],
    braceletSize: "18 cm",
    careInstructions: [],
    images: [
      {
        imageId: "22222222-2222-4222-8222-222222222222",
        fileName: "01.jpg",
        alt: "Testarmband",
        width: 1200,
        height: 900,
        isMain: true,
      },
    ],
    priceMinor: 2490,
    currency: "eur",
    salesEnabled: true,
    stock: 1,
    updatedAt: "2026-08-02T10:00:00.000Z",
    ...overrides,
  };
  const canonical = {
    braceletSize: product.braceletSize,
    careInstructions: product.careInstructions,
    currency: product.currency,
    description: product.description,
    images: product.images,
    materials: product.materials,
    metalElements: product.metalElements,
    productModelVersion: 2,
    name: product.name,
    priceMinor: product.priceMinor,
    productId: product.productId,
    productVersion: product.productVersion,
    salesEnabled: product.salesEnabled,
  };
  product.sourceHash = createHash("sha256")
    .update(stableStringify(canonical), "utf8")
    .digest("hex");
  return product;
}

function publicProductFromSource(source, overrides = {}) {
  const product = sourceProduct(source);
  return {
    productModelVersion: 2,
    productId: product.productId,
    productVersion: product.productVersion,
    sourceHash: product.sourceHash,
    sku: product.sku,
    slug: product.slug,
    title: product.name,
    description: product.description,
    materials: product.materials,
    metalElements: product.metalElements,
    size: product.braceletSize,
    priceMinor: product.priceMinor,
    currency: product.currency,
    salesEnabled: product.salesEnabled,
    images: product.images.map((image) => ({
      ...image,
      src: `/images/products/${product.sku}/${image.fileName}`,
    })),
    updatedAt: product.updatedAt,
    ...overrides,
  };
}

test("Dry-Run ist bei konsistenten v2-Daten erfolgreich und deterministisch", () => {
  const source = { version: 2, products: [sourceProduct()] };
  const publicProjection = {
    version: 2,
    products: [publicProductFromSource()],
  };

  const first = runMigrationDryRun({ source, publicProjection });
  const second = runMigrationDryRun({ source, publicProjection });

  assert.equal(first.result, "passed");
  assert.deepEqual(first, second);
  assert.equal(first.findings.length, 0);
  assert.match(first.overallHash, /^[0-9a-f]{64}$/);
  assert.match(first.datasets.source.records[0].recordHash, /^[0-9a-f]{64}$/);
  assert.match(formatMigrationDryRunText(first), /Keine Abweichungen festgestellt/);
});
test("Dry-Run erkennt Bestands-, Preis-, ID-, Versions-, Vinted- und Bildfehler", () => {
  const first = sourceProduct({
    stock: 2,
    priceMinor: 0,
    currency: "usd",
    sourceHash: "a".repeat(64),
    images: [],
    vintedUrl: "https://www.vinted.de/items/1",
  });
  const duplicate = { ...first, productVersion: 2, sourceHash: "b".repeat(64) };
  const report = runMigrationDryRun({
    source: { version: 2, products: [first, duplicate] },
    publicProjection: {
      version: 2,
      products: [{
        ...publicProductFromSource(first),
        productVersion: 2,
        sourceHash: "c".repeat(64),
        stock: 1,
        vintedUrl: "https://www.vinted.de/items/2",
      }],
    },
  });

  const codes = new Set(report.findings.map((finding) => finding.code));
  assert.equal(report.result, "failed");
  for (const code of [
    "stock_invalid",
    "price_invalid",
    "currency_invalid",
    "duplicate_product_id",
    "product_version_conflict",
    "source_hash_conflict",
    "images_missing_or_invalid",
    "vinted_data_remaining",
    "legacy_public_field",
    "public_projection_mismatch",
  ]) {
    assert.equal(codes.has(code), true, `Finding ${code} fehlt.`);
  }
});

test("CLI liefert maschinenlesbaren Bericht und verändert Eingabedateien nicht", async () => {
  const root = await mkdtemp(path.join(os.tmpdir(), "carmaja-migration-dry-run-"));
  const sourcePath = path.join(root, "source.json");
  const publicPath = path.join(root, "public.json");
  const source = { version: 2, products: [sourceProduct()] };
  const publicProjection = { version: 2, products: [publicProductFromSource()] };

  try {
    await writeFile(sourcePath, `${JSON.stringify(source, null, 2)}\n`, "utf8");
    await writeFile(publicPath, `${JSON.stringify(publicProjection, null, 2)}\n`, "utf8");
    const sourceBefore = await readFile(sourcePath);
    const publicBefore = await readFile(publicPath);
    const result = spawnSync(
      process.execPath,
      [scriptPath, "--source", sourcePath, "--public", publicPath, "--format", "json"],
      { encoding: "utf8" },
    );

    assert.equal(result.status, 0, result.stderr);
    const report = JSON.parse(result.stdout);
    assert.equal(report.result, "passed");
    assert.deepEqual(await readFile(sourcePath), sourceBefore);
    assert.deepEqual(await readFile(publicPath), publicBefore);
  } finally {
    await rm(root, { recursive: true, force: true });
  }
});
