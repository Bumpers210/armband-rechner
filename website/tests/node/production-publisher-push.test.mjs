import assert from "node:assert/strict";
import test from "node:test";

import { validatePublisherPush } from "../../scripts/validate-production-publisher-push.mjs";

const beforeSha = "a".repeat(40);
const headSha = "b".repeat(40);
const requestHash = "c".repeat(64);
const sku = "CP-2026-0042";

function publishedProduct() {
  return {
    sku,
    salesEnabled: true,
    images: [
      {
        fileName: "01.jpg",
        src: `/images/products/${sku}/01.jpg`,
      },
    ],
  };
}

function fixture(overrides = {}) {
  return {
    repository: "Bumpers210/armband-rechner",
    eventName: "push",
    ref: "refs/heads/main",
    branch: "main",
    actor: "Bumpers210",
    forced: "false",
    deleted: "false",
    beforeSha,
    headSha,
    parentShas: [beforeSha],
    commitMessage:
      `Carmaja collection publish ${sku}\n\n` +
      "Carmaja-Publisher: collection-v4\n" +
      "Carmaja-Operation: production-collection-publish-0042\n" +
      `Carmaja-Request-SHA256: ${requestHash}\n`,
    changedFiles: [
      { status: "M", path: "website/content/products.json" },
      { status: "A", path: `website/public/images/products/${sku}/01.jpg` },
    ],
    productsDocument: { version: 3, products: [publishedProduct()] },
    ...overrides,
  };
}

test("akzeptiert einen streng gebundenen Collection-v4-Publish", () => {
  const result = validatePublisherPush(fixture());
  assert.deepEqual(result, {
    action: "publish",
    sku,
    operationId: "production-collection-publish-0042",
    requestHash,
  });
});

test("akzeptiert Archivieren nur bei entfernter öffentlicher Kollektion", () => {
  const result = validatePublisherPush(
    fixture({
      commitMessage:
        `Carmaja collection archive ${sku}\n\n` +
        "Carmaja-Publisher: collection-v4\n" +
        "Carmaja-Operation: production-collection-archive-0042\n" +
        `Carmaja-Request-SHA256: ${requestHash}`,
      changedFiles: [
        { status: "M", path: "website/content/products.json" },
        { status: "D", path: `website/public/images/products/${sku}/01.jpg` },
      ],
      productsDocument: { version: 3, products: [] },
    }),
  );
  assert.equal(result.action, "archive");
});

test("Projektion 4 bindet serverseitige Version, Hash und unveränderbare Hinweise", () => {
  const product = {...publishedProduct(), productModelVersion: 4, productVersion: 1,
    sourceHash: "a".repeat(64), priceMinor: 2800, currency: "eur", noticePolicyId: "natural-stones-v1", notices: [
      "Natursteine können in Farbe, Maserung und Form von der Abbildung abweichen.",
      "Die den Steinen zugeschriebenen Wirkungen sind wissenschaftlich nicht nachgewiesen.",
    ]};
  assert.equal(validatePublisherPush(fixture({productsDocument: {version: 4, products: [product]}})).action, "publish");
  for (const changed of [{...product, notices: []}, {...product, stock: 5}, {...product, sourceHash: "invalid"}]) {
    assert.throws(() => validatePublisherPush(fixture({productsDocument: {version: 4, products: [changed]}})), /Publisher-Push abgelehnt/);
  }
  assert.equal(validatePublisherPush(fixture({productsDocument: {version: 4, products: [{...product, noticePolicyId: null, notices: []}]}})).action, "publish");
});

for (const [name, overrides] of [
  ["falscher Akteur", { actor: "someone-else" }],
  ["Force-Push", { forced: "true" }],
  ["Merge-Commit", { parentShas: [beforeSha, "d".repeat(40)] }],
  ["falscher Vorgänger", { parentShas: ["d".repeat(40)] }],
  ["fehlende Kennzeichnung", { commitMessage: "Publish product" }],
  [
    "fremde Datei",
    { changedFiles: [{ status: "M", path: "website/content/products.json" }, { status: "M", path: "website/app/page.tsx" }] },
  ],
  [
    "Bild einer anderen SKU",
    {
      changedFiles: [
        { status: "M", path: "website/content/products.json" },
        { status: "A", path: "website/public/images/products/CP-2026-9999/01.jpg" },
      ],
    },
  ],
  ["fehlende Produktdatei", { changedFiles: [{ status: "A", path: `website/public/images/products/${sku}/01.jpg` }] }],
  ["zu viele Dateien", { changedFiles: Array.from({ length: 7 }, (_, index) => ({ status: "M", path: `file-${index}` })) }],
  ["Kollektion nicht verfügbar", { productsDocument: { version: 3, products: [{ ...publishedProduct(), salesEnabled: false }] } }],
  ["falsches Produktmodell", { productsDocument: { version: 2, products: [publishedProduct()] } }],
]) {
  test(`lehnt ${name} ab`, () => {
    assert.throws(() => validatePublisherPush(fixture(overrides)), /Publisher-Push abgelehnt/);
  });
}
