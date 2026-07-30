import assert from "node:assert/strict";
import { cp, mkdir, mkdtemp, rm, writeFile } from "node:fs/promises";
import os from "node:os";
import path from "node:path";
import test from "node:test";

import {
  formatProductSize,
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
    size: "17 cm",
    stock: 1,
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

test("erlaubte Statuswerte und optionale Vinted-URL werden akzeptiert", async () => {
  for (const status of ["published", "sold", "disabled", "draft", "ready"]) {
    const current = await fixture(
      product({
        status,
        ...(status === "published"
          ? { vintedUrl: "https://www.vinted.de/items/123-test" }
          : {}),
      }),
    );

    try {
      const loaded = current.load();
      assert.equal(loaded.products[0].status, status);
    } finally {
      await rm(current.root, { recursive: true, force: true });
    }
  }

  const withoutLink = await fixture(product());

  try {
    assert.equal("vintedUrl" in withoutLink.load().products[0], false);
  } finally {
    await rm(withoutLink.root, { recursive: true, force: true });
  }
});

test("interne Bezeichnungen und Payload-Pflegetexte verlassen die Quelle nicht", async () => {
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
      size: "175 mm",
    }),
  );

  try {
    const loaded = current.load().products[0];

    assert.equal(loaded.publicTitle, publicProductName);
    assert.equal(loaded.slug, "cp-2026-0001");
    assert.equal(loaded.size, "175 mm");
    assert.equal(loaded.displaySize, "17,5 cm");
    assert.equal(loaded.images[0].alt, `${publicProductName}, Bild 1 von 1`);
    assert.equal("title" in loaded, false);
    assert.equal("careInstructions" in loaded, false);
  } finally {
    await rm(current.root, { recursive: true, force: true });
  }
});

test("Pflegehinweise aus älteren Payloads sind optional und werden ignoriert", async () => {
  const value = product();
  delete value.careInstructions;
  const current = await fixture(value);

  try {
    assert.equal("careInstructions" in current.load().products[0], false);
  } finally {
    await rm(current.root, { recursive: true, force: true });
  }
});

test("Größen werden eindeutig und genau einmal in Zentimetern formatiert", () => {
  assert.equal(formatProductSize("18 cm"), "18 cm");
  assert.equal(formatProductSize("18cm"), "18 cm");
  assert.equal(formatProductSize("17,50 cm"), "17,5 cm");
  assert.equal(formatProductSize("180 mm"), "18 cm");
  assert.equal(formatProductSize("175 mm"), "17,5 cm");
  assert.throws(() => formatProductSize("18"), /eindeutigen Wert in cm oder mm/);
  assert.throws(() => formatProductSize("18 cm cm"), /eindeutigen Wert/);
  assert.throws(() => formatProductSize("unbekannt"), /eindeutigen Wert/);
});

test("unbekannte und interne Produktfelder werden abgelehnt", async () => {
  await rejectsProduct(product({ draftId: "intern" }), /Unbekannte Felder: draftId/);
  await rejectsProduct(product({ salePrice: "99.00" }), /Unbekannte Felder: salePrice/);
  await rejectsProduct(
    product({ internalCalculation: { materialCosts: "10.00" } }),
    /Unbekannte Felder: internalCalculation/,
  );
});

test("ungültiger Status und ungültige Vinted-URLs werden abgelehnt", async () => {
  await rejectsProduct(product({ status: "archived" }), /Unbekannter Produktstatus/);
  await rejectsProduct(
    product({ vintedUrl: "javascript:alert(1)" }),
    /direkte HTTPS-URLs/,
  );
  await rejectsProduct(
    product({ vintedUrl: "https://vinted.de.fremd.example/items/1" }),
    /direkte HTTPS-URLs/,
  );
  await rejectsProduct(
    product({ vintedUrl: "https://vinted.de/items/1?redirect=https://example.org" }),
    /Weiterleitungsparameter/,
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
