import assert from "node:assert/strict";
import { cp, mkdir, mkdtemp, rm, writeFile } from "node:fs/promises";
import os from "node:os";
import path from "node:path";
import test from "node:test";

import { loadPublicProductsV2 } from "../../lib/public-products-v2.mjs";

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
    productModelVersion: 2,
    productId: "11111111-1111-4111-8111-111111111111",
    productVersion: 1,
    sourceHash: "a".repeat(64),
    sku: "CP-2026-0001",
    slug: "cp-2026-0001-v2-testarmband",
    title: "Testarmband",
    description: "Ein Produkt für die isolierte v2-Prüfung.",
    materials: ["Rosenquarz"],
    metalElements: [],
    braceletSizeCm: 17,
    pearlSizeMm: 6,
    priceMinor: 2490,
    currency: "eur",
    salesEnabled: true,
    images: [
      {
        imageId: "22222222-2222-4222-8222-222222222222",
        fileName: "01.jpg",
        src: "/images/products/CP-2026-0001/01.jpg",
        alt: "Testarmband",
        width: 2048,
        height: 1536,
        isMain: true,
      },
    ],
    updatedAt: "2026-08-02T10:00:00.000Z",
    ...overrides,
  };
}

async function fixture(value, version = 2) {
  const root = await mkdtemp(path.join(os.tmpdir(), "carmaja-products-v2-test-"));
  const imageRoot = path.join(root, "images", "products");
  const imagePath = path.join(imageRoot, "CP-2026-0001", "01.jpg");
  const productsFile = path.join(root, "products-v2.json");
  await mkdir(path.dirname(imagePath), { recursive: true });
  await cp(sourceImage, imagePath);
  await writeFile(
    productsFile,
    `${JSON.stringify({ version, products: [value] }, null, 2)}\n`,
    "utf8",
  );

  return {
    root,
    load: () => loadPublicProductsV2(productsFile, imageRoot),
  };
}

test("öffentlicher v2-Vertrag akzeptiert Preis, Währung und Verkaufsfreigabe", async () => {
  const current = await fixture(product());

  try {
    const loaded = current.load();
    assert.equal(loaded.version, 2);
    assert.equal(loaded.products[0].priceMinor, 2490);
    assert.equal(loaded.products[0].currency, "eur");
    assert.equal(loaded.products[0].salesEnabled, true);
    assert.equal(loaded.products[0].braceletSizeCm, 17);
    assert.equal(loaded.products[0].pearlSizeMm, 6);
    assert.equal(loaded.products[0].publicTitle, "Testarmband");
    assert.equal("stock" in loaded.products[0], false);
    assert.equal("vintedUrl" in loaded.products[0], false);
  } finally {
    await rm(current.root, { recursive: true, force: true });
  }
});

test("öffentlicher v2-Vertrag lehnt stock und Vinted ab", async () => {
  for (const field of ["stock", "vintedUrl"]) {
    const current = await fixture(
      product({
        [field]: field === "stock" ? 1 : "https://www.vinted.de/items/1",
      }),
    );

    try {
      assert.throws(current.load, new RegExp(`Unbekannte Felder: ${field}`));
    } finally {
      await rm(current.root, { recursive: true, force: true });
    }
  }
});

test("öffentlicher v2-Vertrag prüft Hash, Version und Mindestpreis", async () => {
  for (const [field, value, pattern] of [
    ["productVersion", 0, /Produktversion erwartet/],
    ["sourceHash", "A".repeat(64), /Kleingeschriebener SHA-256/],
    ["priceMinor", 0, /mindestens 50 Cent/],
    ["currency", "usd", /Nur eur/],
  ]) {
    const current = await fixture(product({ [field]: value }));

    try {
      assert.throws(current.load, pattern);
    } finally {
      await rm(current.root, { recursive: true, force: true });
    }
  }
});

test("öffentlicher v3-Vertrag akzeptiert sichere Formatkombinationen", async () => {
  const description = '<script>alert("x")</script> bleibt sichtbarer Text.';
  const descriptionDocument = {
    version: 1,
    blocks: [
      {
        type: "paragraph",
        spans: [
          {
            text: '<script>alert("x")</script>',
            bold: true,
            italic: true,
            font: "elegant",
            size: "large",
          },
          {
            text: " bleibt sichtbarer Text.",
            bold: false,
            italic: false,
            font: "standard",
            size: "normal",
          },
        ],
      },
    ],
  };
  const current = await fixture(
    product({
      productModelVersion: 3,
      description,
      descriptionDocument,
    }),
    3,
  );

  try {
    const loaded = current.load();
    assert.equal(loaded.version, 3);
    assert.deepEqual(loaded.products[0].descriptionDocument, descriptionDocument);
    assert.equal(loaded.products[0].description, description);
  } finally {
    await rm(current.root, { recursive: true, force: true });
  }
});

test("öffentlicher v3-Vertrag lehnt unbekannte Stile und abweichenden Klartext ab", async () => {
  const baseDocument = {
    version: 1,
    blocks: [
      {
        type: "paragraph",
        spans: [
          {
            text: "Formatierter Text",
            bold: false,
            italic: false,
            font: "standard",
            size: "normal",
          },
        ],
      },
    ],
  };
  const cases = [
    [
      { ...baseDocument, blocks: [{ ...baseDocument.blocks[0], spans: [{ ...baseDocument.blocks[0].spans[0], font: "extern" }] }] },
      "Formatierter Text",
      /Textbereich ist ungültig/,
    ],
    [baseDocument, "Anderer Klartext", /stimmen nicht überein/],
  ];

  for (const [descriptionDocument, description, pattern] of cases) {
    const current = await fixture(
      product({ productModelVersion: 3, description, descriptionDocument }),
      3,
    );
    try {
      assert.throws(current.load, pattern);
    } finally {
      await rm(current.root, { recursive: true, force: true });
    }
  }
});

test("Wurzelversion 2 akzeptiert keine Produkte des Modells 3", async () => {
  const current = await fixture(product({ productModelVersion: 3 }));

  try {
    assert.throws(current.load, /Version 2 darf nur Produkte des Modells 2 enthalten/);
  } finally {
    await rm(current.root, { recursive: true, force: true });
  }
});
