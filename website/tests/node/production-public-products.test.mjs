import assert from "node:assert/strict";
import { cp, mkdir, mkdtemp, rm, writeFile } from "node:fs/promises";
import os from "node:os";
import path from "node:path";
import test from "node:test";

import {
  formatMeasurement,
  loadPublicProducts,
  publicProductName,
} from "../../lib/public-products.mjs";

const projectRoot = process.cwd();
const sourceImage = path.join(
  projectRoot,
  "public",
  "images",
  "bracelets",
  "hero-dunkelrot-braun-holz.jpg",
);

function product(overrides = {}) {
  return {
    sku: "CP-2026-0001",
    slug: "cp-2026-0001-testarmband",
    title: "Testarmband",
    description: "Ein Produkt für die isolierte Websiteprüfung.",
    materials: ["Rosenquarz"],
    metalElements: [],
    braceletSizeCm: 17,
    pearlSizeMm: 6,
    status: "published",
    images: [
      {
        src: "/images/products/CP-2026-0001/01.jpg",
        alt: "Testarmband aus Rosenquarz",
        width: 2048,
        height: 1536,
        isMain: true,
      },
    ],
    careInstructions: ["Vor Wasser schützen"],
    updatedAt: "2026-07-28T10:00:00.000Z",
    ...overrides,
  };
}

function legacyProduct(overrides = {}) {
  const current = product();
  delete current.braceletSizeCm;
  delete current.pearlSizeMm;

  return {
    ...current,
    size: "17,5 cm",
    stock: 1,
    vintedUrl: "https://legacy.invalid/items/123",
    ...overrides,
  };
}

async function fixture(
  value,
  { copyImage = true, invalidImage = false } = {},
) {
  const root = await mkdtemp(path.join(os.tmpdir(), "carmaja-products-test-"));
  const imageRoot = path.join(root, "images", "products");
  const imagePath = path.join(imageRoot, "CP-2026-0001", "01.jpg");
  const productsFile = path.join(root, "products.json");
  await mkdir(path.dirname(imagePath), { recursive: true });

  if (invalidImage) {
    await writeFile(imagePath, "kein jpeg", "utf8");
  } else if (copyImage) {
    await cp(sourceImage, imagePath);
  }

  await writeFile(
    productsFile,
    `${JSON.stringify({ version: 1, products: [value] }, null, 2)}\n`,
    "utf8",
  );

  return {
    root,
    load: () => loadPublicProducts(productsFile, imageRoot),
  };
}

async function rejectsProduct(value, pattern, options) {
  const current = await fixture(value, options);

  try {
    assert.throws(current.load, pattern);
  } finally {
    await rm(current.root, { recursive: true, force: true });
  }
}

test("V2-Produkte akzeptieren nur freigegebene Statuswerte", async () => {
  for (const status of ["published", "sold", "disabled", "draft", "ready"]) {
    const current = await fixture(product({ status }));

    try {
      assert.equal(current.load().products[0].status, status);
    } finally {
      await rm(current.root, { recursive: true, force: true });
    }
  }
});

test("der öffentliche V2-Output enthält nur freigegebene Metadaten", async () => {
  const current = await fixture(
    product({
      slug: "nur-interne-zuordnung",
      title: "INTERNE-ARTIKELBEZEICHNUNG",
      careInstructions: ["INTERNER-PFLEGEHINWEIS"],
      images: [
        {
          ...product().images[0],
          alt: "INTERNER-BILDTEXT",
        },
      ],
      braceletSizeCm: 17.5,
      pearlSizeMm: 8,
    }),
  );

  try {
    const loaded = current.load().products[0];

    assert.equal(loaded.publicTitle, publicProductName);
    assert.equal(loaded.slug, "cp-2026-0001");
    assert.equal(loaded.braceletSizeCm, 17.5);
    assert.equal(loaded.displayBraceletSize, "17,5 cm");
    assert.equal(loaded.pearlSizeMm, 8);
    assert.equal(loaded.displayPearlSize, "8 mm");
    assert.equal(loaded.images[0].alt, `${publicProductName}, Bild 1 von 1`);

    for (const privateField of [
      "title",
      "careInstructions",
      "size",
      "stock",
      "vintedUrl",
      "commerceInventory",
    ]) {
      assert.equal(privateField in loaded, false, `${privateField} wurde veröffentlicht.`);
    }
  } finally {
    await rm(current.root, { recursive: true, force: true });
  }
});

test("Legacy-V1-Daten werden kontrolliert nach V2 überführt", async () => {
  const current = await fixture(
    legacyProduct({
      size: "175 mm",
      stock: 0,
      vintedUrl: "javascript:legacy-only",
    }),
  );

  try {
    const loaded = current.load().products[0];

    assert.equal(loaded.braceletSizeCm, 17.5);
    assert.equal(loaded.displayBraceletSize, "17,5 cm");
    assert.equal(loaded.pearlSizeMm, null);
    assert.equal(loaded.displayPearlSize, null);
    assert.equal("stock" in loaded, false);
    assert.equal("vintedUrl" in loaded, false);
  } finally {
    await rm(current.root, { recursive: true, force: true });
  }
});

test("Größen werden mit kanonischen Einheiten formatiert", () => {
  assert.equal(formatMeasurement(18, "cm", "braceletSizeCm"), "18 cm");
  assert.equal(formatMeasurement(17.5, "cm", "braceletSizeCm"), "17,5 cm");
  assert.equal(formatMeasurement(6, "mm", "pearlSizeMm"), "6 mm");
  assert.throws(() => formatMeasurement(0, "mm", "pearlSizeMm"), /Positive Zahl/);
});

test("interne und Commerce-Felder werden im öffentlichen Schema abgelehnt", async () => {
  await rejectsProduct(product({ draftId: "intern" }), /Unbekannte Felder: draftId/);
  await rejectsProduct(product({ salePrice: "99.00" }), /Unbekannte Felder: salePrice/);
  await rejectsProduct(
    product({ commerceInventory: { onHand: 1 } }),
    /Unbekannte Felder: commerceInventory/,
  );
  await rejectsProduct(
    product({ internalCalculation: { materialCosts: "10.00" } }),
    /Unbekannte Felder: internalCalculation/,
  );
});

test("V2 lehnt Legacy-Felder und unvollständige Größen strikt ab", async () => {
  await rejectsProduct(product({ status: "archived" }), /Unbekannter Produktstatus/);
  await rejectsProduct(product({ stock: 1 }), /Unbekannte Felder: stock/);
  await rejectsProduct(
    product({ vintedUrl: "https://legacy.invalid/items/123" }),
    /Unbekannte Felder: vintedUrl/,
  );
  await rejectsProduct(product({ size: "17 cm" }), /Unbekannte Felder: size/);
  await rejectsProduct(product({ pearlSizeMm: 0 }), /Positive Zahl/);
  await rejectsProduct(
    product({ braceletSizeCm: 17, pearlSizeMm: undefined }),
    /Fehlende Felder: pearlSizeMm/,
  );
});

test("SKU und Bildpfade werden strikt validiert", async () => {
  await rejectsProduct(product({ sku: "TEST-1" }), /Format CP-YYYY-NNNN/);
  await rejectsProduct(
    product({
      images: [
        {
          ...product().images[0],
          src: "/images/products/CP-2026-0001/../01.jpg",
        },
      ],
    }),
    /Bildpfad muss/,
  );
  await rejectsProduct(
    product({
      images: [
        {
          ...product().images[0],
          src: "https://example.org/image.jpg",
        },
      ],
    }),
    /Bildpfad muss/,
  );
});

test("fehlende und ungültige JPEG-Dateien werden abgelehnt", async () => {
  await rejectsProduct(product(), /Bilddatei fehlt/, { copyImage: false });
  await rejectsProduct(product(), /kein vollständiges JPEG/, {
    copyImage: false,
    invalidImage: true,
  });
});

test("Bildanzahl muss zwischen eins und fünf liegen", async () => {
  await rejectsProduct(product({ images: [] }), /Ein bis fünf Bilder/);
  await rejectsProduct(
    product({
      images: Array.from({ length: 6 }, (_, index) => ({
        ...product().images[0],
        src: `/images/products/CP-2026-0001/${String(index + 1).padStart(2, "0")}.jpg`,
        isMain: index === 0,
      })),
    }),
    /Ein bis fünf Bilder/,
  );
});
