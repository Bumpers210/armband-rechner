import { readFileSync, statSync } from "node:fs";
import path from "node:path";

import {
  formatMeasurement,
  publicProductName,
  readJpegDimensions,
} from "./public-products.mjs";

const ROOT_KEYS = ["products", "version"];
const PRODUCT_KEYS_V2 = [
  "braceletSizeCm",
  "currency",
  "description",
  "images",
  "materials",
  "metalElements",
  "pearlSizeMm",
  "priceMinor",
  "productId",
  "productModelVersion",
  "productVersion",
  "salesEnabled",
  "slug",
  "sku",
  "sourceHash",
  "title",
  "updatedAt",
];
const PRODUCT_KEYS_V3 = [...PRODUCT_KEYS_V2, "descriptionDocument"];
const DESCRIPTION_DOCUMENT_KEYS = ["blocks", "version"];
const DESCRIPTION_BLOCK_KEYS = ["spans", "type"];
const DESCRIPTION_SPAN_KEYS = ["bold", "font", "italic", "size", "text"];
const IMAGE_KEYS = [
  "alt",
  "fileName",
  "height",
  "imageId",
  "isMain",
  "src",
  "width",
];
const SKU_PATTERN = /^CP-\d{4}-\d{4}$/;
const SLUG_PATTERN = /^[a-z0-9]+(?:-[a-z0-9]+)*$/;
const PRODUCT_ID_PATTERN =
  /^(?:[0-9a-fA-F]{8}-[0-9a-fA-F-]{27}|[0-9A-HJKMNP-TV-Z]{26})$/;
const IMAGE_ID_PATTERN = /^[0-9a-fA-F-]{36}$/;
const HASH_PATTERN = /^[0-9a-f]{64}$/;

function fail(location, message) {
  throw new Error(`${location}: ${message}`);
}

function requireObject(value, location) {
  if (value === null || typeof value !== "object" || Array.isArray(value)) {
    fail(location, "JSON-Objekt erwartet.");
  }

  return value;
}

function requireExactKeys(value, allowedKeys, requiredKeys, location) {
  const keys = Object.keys(value);
  const unknown = keys.filter((key) => !allowedKeys.includes(key));
  const missing = requiredKeys.filter((key) => !keys.includes(key));

  if (unknown.length > 0) {
    fail(location, `Unbekannte Felder: ${unknown.sort().join(", ")}.`);
  }

  if (missing.length > 0) {
    fail(location, `Fehlende Felder: ${missing.sort().join(", ")}.`);
  }
}

function requireString(value, location, maximumLength) {
  if (
    typeof value !== "string" ||
    value.trim() === "" ||
    value !== value.trim() ||
    value.length > maximumLength
  ) {
    fail(
      location,
      `Nichtleere Zeichenkette bis ${maximumLength} Zeichen erwartet.`,
    );
  }

  return value;
}

function requireStringList(value, location, { allowEmpty = true } = {}) {
  if (!Array.isArray(value) || (!allowEmpty && value.length === 0)) {
    fail(
      location,
      allowEmpty
        ? "Zeichenkettenliste erwartet."
        : "Nichtleere Zeichenkettenliste erwartet.",
    );
  }

  const normalized = value.map((entry, index) =>
    requireString(entry, `${location}[${index}]`, 160),
  );

  if (new Set(normalized).size !== normalized.length) {
    fail(location, "Doppelte Einträge sind nicht erlaubt.");
  }

  return normalized;
}

function requirePositiveNumber(value, location) {
  if (
    typeof value !== "number" ||
    !Number.isFinite(value) ||
    value <= 0 ||
    value > 1000
  ) {
    fail(location, "Positive Zahl bis 1000 erwartet.");
  }

  return value;
}

function validateDescriptionDocument(value, expectedPlainText, location) {
  const document = requireObject(value, location);
  requireExactKeys(document, DESCRIPTION_DOCUMENT_KEYS, DESCRIPTION_DOCUMENT_KEYS, location);
  if (document.version !== 1 || !Array.isArray(document.blocks)) {
    fail(location, "Formatierte Beschreibung Version 1 erwartet.");
  }
  if (document.blocks.length < 1 || document.blocks.length > 25) {
    fail(`${location}.blocks`, "Ein bis 25 Absätze erwartet.");
  }

  let spanCount = 0;
  const blocks = document.blocks.map((blockValue, blockIndex) => {
    const blockLocation = `${location}.blocks[${blockIndex}]`;
    const block = requireObject(blockValue, blockLocation);
    requireExactKeys(block, DESCRIPTION_BLOCK_KEYS, DESCRIPTION_BLOCK_KEYS, blockLocation);
    if (block.type !== "paragraph" || !Array.isArray(block.spans) || block.spans.length < 1) {
      fail(blockLocation, "Nichtleerer Absatz erwartet.");
    }
    spanCount += block.spans.length;
    return {
      type: "paragraph",
      spans: block.spans.map((spanValue, spanIndex) => {
        const spanLocation = `${blockLocation}.spans[${spanIndex}]`;
        const span = requireObject(spanValue, spanLocation);
        requireExactKeys(span, DESCRIPTION_SPAN_KEYS, DESCRIPTION_SPAN_KEYS, spanLocation);
        if (
          typeof span.text !== "string" ||
          span.text.length < 1 ||
          typeof span.bold !== "boolean" ||
          typeof span.italic !== "boolean" ||
          !["standard", "elegant"].includes(span.font) ||
          !["small", "normal", "large"].includes(span.size)
        ) {
          fail(spanLocation, "Formatierter Textbereich ist ungültig.");
        }
        return {
          text: span.text,
          bold: span.bold,
          italic: span.italic,
          font: span.font,
          size: span.size,
        };
      }),
    };
  });

  if (spanCount > 100) {
    fail(location, "Höchstens 100 Formatbereiche erlaubt.");
  }
  if (Buffer.byteLength(JSON.stringify(document), "utf8") > 16 * 1024) {
    fail(location, "Formatierte Beschreibung ist zu groß.");
  }
  const plainText = blocks
    .map((block) => block.spans.map((span) => span.text).join(""))
    .join("\n\n");
  if (plainText !== expectedPlainText) {
    fail(location, "Reiner Text und formatierte Beschreibung stimmen nicht überein.");
  }
  return { version: 1, blocks };
}

function validateImage(value, product, index, imageCount, imageRoot) {
  const location = `products[${product.productId}].images[${index}]`;
  const image = requireObject(value, location);
  requireExactKeys(image, IMAGE_KEYS, IMAGE_KEYS, location);
  const src = requireString(image.src, `${location}.src`, 240);
  const expectedFileName = `${String(index + 1).padStart(2, "0")}.jpg`;

  if (src !== `/images/products/${product.sku}/${expectedFileName}`) {
    fail(`${location}.src`, "Bildpfad muss zur SKU und Reihenfolge gehören.");
  }

  if (
    !PRODUCT_ID_PATTERN.test(product.productId) ||
    !SKU_PATTERN.test(product.sku) ||
    !IMAGE_ID_PATTERN.test(image.imageId) ||
    image.fileName !== expectedFileName ||
    !Number.isInteger(image.width) ||
    image.width <= 0 ||
    !Number.isInteger(image.height) ||
    image.height <= 0 ||
    typeof image.isMain !== "boolean" ||
    image.isMain !== (index === 0)
  ) {
    fail(location, "Bildmetadaten sind ungültig.");
  }

  requireString(image.alt, `${location}.alt`, 160);
  const root = path.resolve(imageRoot);
  const filePath = path.resolve(root, product.sku, expectedFileName);
  const relativePath = path.relative(root, filePath);

  if (
    relativePath.startsWith("..") ||
    path.isAbsolute(relativePath) ||
    !statSync(filePath, { throwIfNoEntry: false })?.isFile()
  ) {
    fail(`${location}.src`, "Referenzierte Bilddatei fehlt.");
  }

  const actualDimensions = readJpegDimensions(filePath);

  if (
    actualDimensions.width !== image.width ||
    actualDimensions.height !== image.height
  ) {
    fail(
      `${location}.src`,
      "Deklarierte und tatsächliche Bildgröße weichen ab.",
    );
  }

  return {
    imageId: image.imageId,
    fileName: image.fileName,
    src,
    alt: `${publicProductName}, Bild ${index + 1} von ${imageCount}`,
    width: image.width,
    height: image.height,
    isMain: image.isMain,
  };
}

function validateProduct(value, index, imageRoot) {
  const location = `products[${index}]`;
  const product = requireObject(value, location);
  if (![2, 3].includes(product.productModelVersion)) {
    fail(location, "Produktmodell 2 oder 3 erwartet.");
  }
  const productKeys = product.productModelVersion === 3 ? PRODUCT_KEYS_V3 : PRODUCT_KEYS_V2;
  requireExactKeys(product, productKeys, productKeys, location);
  const productId = requireString(product.productId, `${location}.productId`, 40);
  const sku = requireString(product.sku, `${location}.sku`, 20);
  const slug = requireString(product.slug, `${location}.slug`, 180);

  if (!PRODUCT_ID_PATTERN.test(productId)) {
    fail(`${location}.productId`, "UUID oder ULID erwartet.");
  }

  if (!SKU_PATTERN.test(sku)) {
    fail(`${location}.sku`, "Format CP-YYYY-NNNN erwartet.");
  }

  if (!SLUG_PATTERN.test(slug)) {
    fail(`${location}.slug`, "Ungültiger URL-Slug.");
  }

  if (!Number.isInteger(product.productVersion) || product.productVersion < 1) {
    fail(location, "Positive Produktversion erwartet.");
  }

  if (!Number.isInteger(product.priceMinor) || product.priceMinor < 50) {
    fail(`${location}.priceMinor`, "Ganzzahl von mindestens 50 Cent erwartet.");
  }

  if (product.currency !== "eur") {
    fail(`${location}.currency`, "Nur eur wird unterstützt.");
  }

  if (typeof product.salesEnabled !== "boolean") {
    fail(`${location}.salesEnabled`, "Boolean erwartet.");
  }

  if (!HASH_PATTERN.test(product.sourceHash)) {
    fail(`${location}.sourceHash`, "Kleingeschriebener SHA-256-Hash erwartet.");
  }

  const updatedAt = requireString(product.updatedAt, `${location}.updatedAt`, 40);

  if (!Number.isFinite(Date.parse(updatedAt))) {
    fail(`${location}.updatedAt`, "ISO-Zeitstempel erwartet.");
  }

  const publicTitle = requireString(product.title, `${location}.title`, 120);
  const braceletSizeCm = requirePositiveNumber(
    product.braceletSizeCm,
    `${location}.braceletSizeCm`,
  );
  const pearlSizeMm = requirePositiveNumber(
    product.pearlSizeMm,
    `${location}.pearlSizeMm`,
  );

  if (
    !Array.isArray(product.images) ||
    product.images.length < 1 ||
    product.images.length > 5
  ) {
    fail(`${location}.images`, "Ein bis fünf Bilder erwartet.");
  }

  const description = requireString(product.description, `${location}.description`, 500);
  const descriptionDocument = product.productModelVersion === 3
    ? validateDescriptionDocument(
        product.descriptionDocument,
        description,
        `${location}.descriptionDocument`,
      )
    : null;

  return {
    productModelVersion: product.productModelVersion,
    productId,
    productVersion: product.productVersion,
    sourceHash: product.sourceHash,
    sku,
    slug,
    publicTitle,
    description,
    descriptionDocument,
    materials: requireStringList(product.materials, `${location}.materials`, {
      allowEmpty: false,
    }),
    metalElements: requireStringList(
      product.metalElements,
      `${location}.metalElements`,
    ),
    braceletSizeCm,
    displaySize: formatMeasurement(
      braceletSizeCm,
      "cm",
      `${location}.braceletSizeCm`,
    ),
    pearlSizeMm,
    displayPearlSize: formatMeasurement(
      pearlSizeMm,
      "mm",
      `${location}.pearlSizeMm`,
    ),
    priceMinor: product.priceMinor,
    currency: product.currency,
    salesEnabled: product.salesEnabled,
    images: product.images.map((image, imageIndex) =>
      validateImage(
        image,
        { productId, sku },
        imageIndex,
        product.images.length,
        imageRoot,
      ),
    ),
    updatedAt,
  };
}

export function loadPublicProductsV2(productsFile, imageRoot) {
  let decoded;

  try {
    decoded = JSON.parse(readFileSync(productsFile, "utf8"));
  } catch (error) {
    throw new Error(
      `Produktquelldatei ist nicht lesbar: ${
        error instanceof Error ? error.message : "unbekannter Fehler"
      }`,
    );
  }

  const root = requireObject(decoded, "root");
  requireExactKeys(root, ROOT_KEYS, ROOT_KEYS, "root");

  if (![2, 3].includes(root.version) || !Array.isArray(root.products)) {
    fail("root", "Version 2 oder 3 mit Produktliste erwartet.");
  }
  if (root.version === 2 && root.products.some((product) => product?.productModelVersion !== 2)) {
    fail("root", "Version 2 darf nur Produkte des Modells 2 enthalten.");
  }

  const products = root.products.map((product, index) =>
    validateProduct(product, index, imageRoot),
  );
  const ids = products.map((product) => product.productId);
  const skus = products.map((product) => product.sku);
  const slugs = products.map((product) => product.slug);

  if (new Set(ids).size !== ids.length) {
    fail("products", "Doppelte Produkt-ID.");
  }

  if (new Set(skus).size !== skus.length) {
    fail("products", "Doppelte SKU.");
  }

  if (new Set(slugs).size !== slugs.length) {
    fail("products", "Doppelter Slug.");
  }

  return { version: root.version, products };
}
