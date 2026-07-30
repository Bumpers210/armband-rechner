import { readFileSync, statSync } from "node:fs";
import path from "node:path";

const ROOT_KEYS = ["products", "version"];
const PRODUCT_KEYS = [
  "careInstructions",
  "description",
  "images",
  "materials",
  "metalElements",
  "sku",
  "slug",
  "size",
  "status",
  "stock",
  "title",
  "updatedAt",
  "vintedUrl",
];
const REQUIRED_PRODUCT_KEYS = PRODUCT_KEYS.filter(
  (key) => !["careInstructions", "vintedUrl"].includes(key),
);
const IMAGE_KEYS = ["alt", "height", "isMain", "src", "width"];
const PRODUCT_STATUSES = new Set([
  "draft",
  "ready",
  "published",
  "sold",
  "disabled",
]);
const SKU_PATTERN = /^CP-\d{4}-\d{4}$/;
const SLUG_PATTERN = /^[a-z0-9]+(?:-[a-z0-9]+)*$/;
const IMAGE_PATTERN =
  /^\/images\/products\/(CP-\d{4}-\d{4})\/(0[1-5]\.jpg)$/;
const REDIRECT_PARAMETERS = new Set([
  "url",
  "redirect",
  "redirect_url",
  "redirect_uri",
  "next",
  "target",
]);

export const publicProductName = "Carmaja-Perlen Armband";

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
    fail(location, `Nichtleere Zeichenkette bis ${maximumLength} Zeichen erwartet.`);
  }

  return value;
}

function requireStringList(value, location, { allowEmpty = true } = {}) {
  if (!Array.isArray(value) || (!allowEmpty && value.length === 0)) {
    fail(location, allowEmpty ? "Zeichenkettenliste erwartet." : "Nichtleere Zeichenkettenliste erwartet.");
  }

  const normalized = value.map((entry, index) =>
    requireString(entry, `${location}[${index}]`, 160),
  );

  if (new Set(normalized).size !== normalized.length) {
    fail(location, "Doppelte Einträge sind nicht erlaubt.");
  }

  return normalized;
}

export function validateVintedUrl(value, location = "vintedUrl") {
  if (typeof value !== "string" || value.trim() !== value || value === "") {
    fail(location, "Nichtleere URL erwartet.");
  }

  let parsed;

  try {
    parsed = new URL(value);
  } catch {
    fail(location, "Ungültige URL.");
  }

  if (
    parsed.protocol !== "https:" ||
    !["vinted.de", "www.vinted.de"].includes(parsed.hostname.toLowerCase()) ||
    parsed.port !== "" ||
    parsed.username !== "" ||
    parsed.password !== ""
  ) {
    fail(location, "Nur direkte HTTPS-URLs auf vinted.de sind erlaubt.");
  }

  for (const key of parsed.searchParams.keys()) {
    if (REDIRECT_PARAMETERS.has(key.toLowerCase())) {
      fail(location, "Weiterleitungsparameter sind nicht erlaubt.");
    }
  }

  return value;
}

export function readJpegDimensions(filePath) {
  const contents = readFileSync(filePath);

  if (
    contents.length < 4 ||
    contents[0] !== 0xff ||
    contents[1] !== 0xd8 ||
    contents.at(-2) !== 0xff ||
    contents.at(-1) !== 0xd9
  ) {
    throw new Error("Datei ist kein vollständiges JPEG.");
  }

  const startOfFrameMarkers = new Set([
    0xc0, 0xc1, 0xc2, 0xc3, 0xc5, 0xc6, 0xc7, 0xc9, 0xca, 0xcb, 0xcd, 0xce,
    0xcf,
  ]);
  let offset = 2;

  while (offset < contents.length - 1) {
    if (contents[offset] !== 0xff) {
      offset += 1;
      continue;
    }

    while (contents[offset] === 0xff) {
      offset += 1;
    }

    const marker = contents[offset];
    offset += 1;

    if (marker === 0xd9 || marker === 0xda) {
      break;
    }

    if (marker === 0x01 || (marker >= 0xd0 && marker <= 0xd8)) {
      continue;
    }

    if (offset + 1 >= contents.length) {
      break;
    }

    const segmentLength = contents.readUInt16BE(offset);

    if (segmentLength < 2 || offset + segmentLength > contents.length) {
      break;
    }

    if (startOfFrameMarkers.has(marker)) {
      if (segmentLength < 7) {
        break;
      }

      const height = contents.readUInt16BE(offset + 3);
      const width = contents.readUInt16BE(offset + 5);

      if (width <= 0 || height <= 0) {
        break;
      }

      return { width, height };
    }

    offset += segmentLength;
  }

  throw new Error("JPEG enthält keine gültigen Bildabmessungen.");
}

export function formatProductSize(value, location = "size") {
  const source = requireString(value, location, 60);
  const match = source.match(/^(\d+(?:[.,]\d+)?)\s*(cm|mm)$/i);

  if (!match) {
    fail(location, "Größe muss einen eindeutigen Wert in cm oder mm enthalten.");
  }

  const numericValue = Number(match[1].replace(",", "."));

  if (!Number.isFinite(numericValue) || numericValue <= 0) {
    fail(location, "Größe muss größer als null sein.");
  }

  const centimeters = match[2].toLowerCase() === "mm"
    ? numericValue / 10
    : numericValue;
  const rounded = Math.round((centimeters + Number.EPSILON) * 1_000) / 1_000;
  const formatted = new Intl.NumberFormat("de-DE", {
    maximumFractionDigits: 3,
    minimumFractionDigits: 0,
    useGrouping: false,
  }).format(rounded);

  return `${formatted} cm`;
}

function validateImage(value, product, index, imageCount, imageRoot) {
  const location = `products[${product.sku}].images[${index}]`;
  const image = requireObject(value, location);
  requireExactKeys(image, IMAGE_KEYS, IMAGE_KEYS, location);
  const match =
    typeof image.src === "string" ? image.src.match(IMAGE_PATTERN) : null;

  if (!match || match[1] !== product.sku) {
    fail(
      `${location}.src`,
      "Bildpfad muss zur SKU und zu 01.jpg bis 05.jpg gehören.",
    );
  }

  const expectedFileName = `${String(index + 1).padStart(2, "0")}.jpg`;

  if (match[2] !== expectedFileName) {
    fail(`${location}.src`, "Bilddateien müssen lückenlos sortiert sein.");
  }

  if (
    !Number.isInteger(image.width) ||
    image.width <= 0 ||
    !Number.isInteger(image.height) ||
    image.height <= 0
  ) {
    fail(location, "Positive ganzzahlige Bildabmessungen erwartet.");
  }

  if (typeof image.isMain !== "boolean" || image.isMain !== (index === 0)) {
    fail(location, "Genau das erste Bild muss Hauptbild sein.");
  }

  requireString(image.alt, `${location}.alt`, 160);

  const root = path.resolve(imageRoot);
  const filePath = path.resolve(root, match[1], match[2]);
  const relativePath = path.relative(root, filePath);

  if (
    relativePath.startsWith("..") ||
    path.isAbsolute(relativePath) ||
    !statSync(filePath, { throwIfNoEntry: false })?.isFile()
  ) {
    fail(`${location}.src`, "Referenzierte Bilddatei fehlt.");
  }

  let actualDimensions;

  try {
    actualDimensions = readJpegDimensions(filePath);
  } catch (error) {
    fail(
      `${location}.src`,
      error instanceof Error ? error.message : "Ungültiges JPEG.",
    );
  }

  if (
    actualDimensions.width !== image.width ||
    actualDimensions.height !== image.height
  ) {
    fail(`${location}.src`, "Deklarierte und tatsächliche Bildgröße weichen ab.");
  }

  return {
    src: image.src,
    alt: `${publicProductName}, Bild ${index + 1} von ${imageCount}`,
    width: image.width,
    height: image.height,
    isMain: image.isMain,
  };
}

function validateProduct(value, index, imageRoot) {
  const location = `products[${index}]`;
  const product = requireObject(value, location);
  requireExactKeys(product, PRODUCT_KEYS, REQUIRED_PRODUCT_KEYS, location);
  const sku = requireString(product.sku, `${location}.sku`, 20);
  const sourceSlug = requireString(product.slug, `${location}.slug`, 180);

  if (!SKU_PATTERN.test(sku)) {
    fail(`${location}.sku`, "Format CP-YYYY-NNNN erwartet.");
  }

  if (!SLUG_PATTERN.test(sourceSlug)) {
    fail(`${location}.slug`, "Ungültiger URL-Slug.");
  }

  if (!PRODUCT_STATUSES.has(product.status)) {
    fail(`${location}.status`, "Unbekannter Produktstatus.");
  }

  if (!Number.isInteger(product.stock) || product.stock < 0 || product.stock > 99) {
    fail(`${location}.stock`, "Ganzzahl zwischen 0 und 99 erwartet.");
  }

  if (!Array.isArray(product.images) || product.images.length < 1 || product.images.length > 5) {
    fail(`${location}.images`, "Ein bis fünf Bilder erwartet.");
  }

  const updatedAt = requireString(product.updatedAt, `${location}.updatedAt`, 40);

  if (!Number.isFinite(Date.parse(updatedAt))) {
    fail(`${location}.updatedAt`, "ISO-Zeitstempel erwartet.");
  }

  requireString(product.title, `${location}.title`, 120);
  const size = requireString(product.size, `${location}.size`, 60);

  if ("careInstructions" in product) {
    requireStringList(
      product.careInstructions,
      `${location}.careInstructions`,
    );
  }

  const validated = {
    sku,
    slug: sku.toLowerCase(),
    publicTitle: publicProductName,
    description: requireString(
      product.description,
      `${location}.description`,
      500,
    ),
    materials: requireStringList(product.materials, `${location}.materials`, {
      allowEmpty: false,
    }),
    metalElements: requireStringList(
      product.metalElements,
      `${location}.metalElements`,
    ),
    size,
    displaySize: formatProductSize(size, `${location}.size`),
    stock: product.stock,
    status: product.status,
    images: product.images.map((image, imageIndex) =>
      validateImage(
        image,
        { sku },
        imageIndex,
        product.images.length,
        imageRoot,
      ),
    ),
    updatedAt,
  };

  if ("vintedUrl" in product) {
    validated.vintedUrl = validateVintedUrl(
      product.vintedUrl,
      `${location}.vintedUrl`,
    );
  }

  return validated;
}

export function loadPublicProducts(productsFile, imageRoot) {
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

  if (root.version !== 1 || !Array.isArray(root.products)) {
    fail("root", "Version 1 mit Produktliste erwartet.");
  }

  const products = root.products.map((product, index) =>
    validateProduct(product, index, imageRoot),
  );
  const skus = products.map((product) => product.sku);
  const slugs = products.map((product) => product.slug);

  if (new Set(skus).size !== skus.length) {
    fail("products", "Doppelte SKU.");
  }

  if (new Set(slugs).size !== slugs.length) {
    fail("products", "Doppelter Slug.");
  }

  return { version: 1, products };
}

export const publicProductPatterns = {
  image: IMAGE_PATTERN,
  sku: SKU_PATTERN,
  slug: SLUG_PATTERN,
};
