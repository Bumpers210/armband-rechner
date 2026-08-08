import { createHash } from "node:crypto";
import { readFileSync } from "node:fs";
import { fileURLToPath } from "node:url";

export const MIGRATION_DRY_RUN_VERSION = 1;
const PRODUCT_MODEL_VERSION = 2;
const MIN_PRICE_MINOR = 50;
const PRODUCT_ID_PATTERN = /^(?:[0-9a-fA-F]{8}-[0-9a-fA-F-]{27}|[0-9A-HJKMNP-TV-Z]{26})$/;
const SKU_PATTERN = /^CP-\d{4}-\d{4}$/;
const SLUG_PATTERN = /^[a-z0-9]+(?:-[a-z0-9]+)*$/;
const HASH_PATTERN = /^[0-9a-f]{64}$/;
const IMAGE_ID_PATTERN = /^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[1-5][0-9a-fA-F]{3}-[89abAB][0-9a-fA-F]{3}-[0-9a-fA-F]{12}$/;

const SOURCE_REQUIRED_FIELDS = [
  "productModelVersion",
  "productId",
  "productVersion",
  "sourceHash",
  "sku",
  "slug",
  "name",
  "description",
  "materials",
  "metalElements",
  "braceletSize",
  "careInstructions",
  "images",
  "priceMinor",
  "currency",
  "salesEnabled",
  "stock",
  "updatedAt",
];

const PUBLIC_REQUIRED_FIELDS = [
  "productModelVersion",
  "productId",
  "productVersion",
  "sourceHash",
  "sku",
  "slug",
  "title",
  "description",
  "materials",
  "metalElements",
  "size",
  "priceMinor",
  "currency",
  "salesEnabled",
  "images",
  "updatedAt",
];

function sha256(value) {
  return createHash("sha256").update(value, "utf8").digest("hex");
}

export function canonicalize(value) {
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
  const encoded = JSON.stringify(canonicalize(value));
  return encoded === undefined ? "null" : encoded;
}

function hashJson(value) {
  return sha256(stableStringify(value));
}

function isObject(value) {
  return value !== null && typeof value === "object" && !Array.isArray(value);
}

function isNonEmptyString(value) {
  return typeof value === "string" && value.trim() !== "" && value === value.trim();
}

function isIsoDate(value) {
  return isNonEmptyString(value) && Number.isFinite(Date.parse(value));
}

function createFinding(code, dataset, productId, path, message, details = {}) {
  return {
    code,
    dataset,
    productId: productId ?? null,
    path: path ?? null,
    message,
    details,
  };
}

function findingSortKey(finding) {
  return [
    finding.code,
    finding.dataset,
    finding.productId ?? "",
    finding.path ?? "",
    finding.message,
    stableStringify(finding.details),
  ].join("\u0000");
}

function sortFindings(findings) {
  return [...findings].sort((left, right) =>
    findingSortKey(left).localeCompare(findingSortKey(right), "en"),
  );
}

function extractProducts(document, dataset, findings) {
  if (Array.isArray(document)) {
    return { version: null, products: document };
  }

  if (!isObject(document) || !Array.isArray(document.products)) {
    findings.push(
      createFinding(
        "document_products_missing",
        dataset,
        null,
        "products",
        "Produktliste fehlt oder ist keine Liste.",
      ),
    );
    return { version: isObject(document) ? document.version ?? null : null, products: [] };
  }

  return { version: document.version ?? null, products: document.products };
}

function addMissingFields(record, requiredFields, dataset, index, productId, findings) {
  for (const field of requiredFields) {
    if (!Object.prototype.hasOwnProperty.call(record, field)) {
      findings.push(
        createFinding(
          "missing_required_field",
          dataset,
          productId,
          `products[${index}].${field}`,
          `Pflichtfeld ${field} fehlt.`,
          { field },
        ),
      );
    }
  }
}

function scanForVinted(value, dataset, path, productId, findings, seen = new Set()) {
  if (value === null || typeof value !== "object") {
    return;
  }

  if (seen.has(value)) {
    return;
  }

  seen.add(value);

  if (Array.isArray(value)) {
    value.forEach((entry, index) =>
      scanForVinted(entry, dataset, `${path}[${index}]`, productId, findings, seen),
    );
    return;
  }

  for (const [key, entry] of Object.entries(value)) {
    const entryPath = path ? `${path}.${key}` : key;
    const keyLooksLikeVinted = key.toLowerCase().includes("vinted");
    const valueLooksLikeVinted = typeof entry === "string" && /vinted\.de/i.test(entry);

    if (keyLooksLikeVinted || valueLooksLikeVinted) {
      findings.push(
        createFinding(
          "vinted_data_remaining",
          dataset,
          productId,
          entryPath,
          "Vinted-Daten sind noch vorhanden.",
          { field: key },
        ),
      );
    }

    scanForVinted(entry, dataset, entryPath, productId, findings, seen);
  }
}

function validateImages(images, dataset, productId, path, findings, { publicProjection = false } = {}) {
  if (!Array.isArray(images) || images.length < 1 || images.length > 5) {
    findings.push(
      createFinding(
        "images_missing_or_invalid",
        dataset,
        productId,
        path,
        "Es werden ein bis fünf Bilder benötigt.",
      ),
    );
    return;
  }

  let mainCount = 0;

  images.forEach((image, index) => {
    const imagePath = `${path}[${index}]`;

    if (!isObject(image)) {
      findings.push(
        createFinding(
          "image_invalid",
          dataset,
          productId,
          imagePath,
          "Bildobjekt erwartet.",
        ),
      );
      return;
    }

    const required = publicProjection
      ? ["imageId", "fileName", "src", "alt", "width", "height", "isMain"]
      : ["imageId", "fileName", "alt", "width", "height", "isMain"];

    for (const field of required) {
      if (!Object.prototype.hasOwnProperty.call(image, field)) {
        findings.push(
          createFinding(
            "missing_required_field",
            dataset,
            productId,
            `${imagePath}.${field}`,
            `Bildpflichtfeld ${field} fehlt.`,
            { field },
          ),
        );
      }
    }

    if (!isNonEmptyString(image.imageId) || !IMAGE_ID_PATTERN.test(image.imageId)) {
      findings.push(
        createFinding(
          "image_invalid",
          dataset,
          productId,
          `${imagePath}.imageId`,
          "Ungültige Bild-ID.",
        ),
      );
    }

    const expectedFileName = `${String(index + 1).padStart(2, "0")}.jpg`;
    if (image.fileName !== expectedFileName) {
      findings.push(
        createFinding(
          "image_invalid",
          dataset,
          productId,
          `${imagePath}.fileName`,
          "Bilddateien müssen fortlaufend 01.jpg bis 05.jpg heißen.",
          { expected: expectedFileName },
        ),
      );
    }

    if (publicProjection && !isNonEmptyString(image.src)) {
      findings.push(
        createFinding(
          "image_invalid",
          dataset,
          productId,
          `${imagePath}.src`,
          "Öffentlicher Bildpfad fehlt.",
        ),
      );
    }

    if (!isNonEmptyString(image.alt)
      || !Number.isInteger(image.width)
      || image.width <= 0
      || !Number.isInteger(image.height)
      || image.height <= 0
      || typeof image.isMain !== "boolean") {
      findings.push(
        createFinding(
          "image_invalid",
          dataset,
          productId,
          imagePath,
          "Bildmetadaten sind ungültig.",
        ),
      );
    }

    if (image.isMain === true) {
      mainCount += 1;
    }
  });

  if (mainCount !== 1 || images[0]?.isMain !== true) {
    findings.push(
      createFinding(
        "image_invalid",
        dataset,
        productId,
        path,
        "Genau das erste Bild muss als Hauptbild markiert sein.",
      ),
    );
  }
}

function sourceCanonicalProduct(product) {
  const images = Array.isArray(product.images)
    ? product.images.map((image) => ({
      imageId: image?.imageId ?? "",
      fileName: image?.fileName ?? "",
      alt: image?.alt ?? "",
      width: image?.width ?? 0,
      height: image?.height ?? 0,
      isMain: image?.isMain ?? false,
    }))
    : [];

  return {
    braceletSize: product.braceletSize ?? "",
    careInstructions: Array.isArray(product.careInstructions) ? product.careInstructions : [],
    currency: product.currency ?? "",
    description: product.description ?? product.shortDescription ?? "",
    images,
    materials: Array.isArray(product.materials) ? product.materials : [],
    metalElements: Array.isArray(product.metalElements) ? product.metalElements : [],
    productModelVersion: PRODUCT_MODEL_VERSION,
    name: product.name ?? product.title ?? "",
    priceMinor: Number.isInteger(product.priceMinor) ? product.priceMinor : 0,
    productId: product.productId ?? "",
    productVersion: Number.isInteger(product.productVersion) ? product.productVersion : 0,
    salesEnabled: typeof product.salesEnabled === "boolean" ? product.salesEnabled : false,
  };
}

function expectedPublicProjection(product) {
  const images = Array.isArray(product.images) ? product.images : [];
  const sku = product.sku ?? "";

  return {
    productModelVersion: PRODUCT_MODEL_VERSION,
    productId: product.productId ?? null,
    productVersion: product.productVersion ?? null,
    sourceHash: product.sourceHash ?? null,
    sku,
    slug: product.slug ?? "",
    title: product.name ?? product.title ?? "",
    description: product.description ?? product.shortDescription ?? "",
    materials: Array.isArray(product.materials) ? product.materials : [],
    metalElements: Array.isArray(product.metalElements) ? product.metalElements : [],
    size: product.braceletSize ?? product.size ?? "",
    priceMinor: product.priceMinor ?? null,
    currency: product.currency ?? null,
    salesEnabled: product.salesEnabled ?? null,
    images: images.map((image, index) => ({
      imageId: image.imageId ?? null,
      fileName: image.fileName ?? `${String(index + 1).padStart(2, "0")}.jpg`,
      src: image.src ?? `/images/products/${sku}/${image.fileName ?? `${String(index + 1).padStart(2, "0")}.jpg`}`,
      alt: image.alt ?? "",
      width: image.width ?? null,
      height: image.height ?? null,
      isMain: image.isMain ?? false,
    })),
    updatedAt: product.updatedAt ?? "",
  };
}

function compareValues(expected, actual, path, differences) {
  if (stableStringify(expected) === stableStringify(actual)) {
    return;
  }

  if (Array.isArray(expected) && Array.isArray(actual)) {
    const length = Math.max(expected.length, actual.length);
    for (let index = 0; index < length; index += 1) {
      compareValues(expected[index], actual[index], `${path}[${index}]`, differences);
    }
    return;
  }

  if (isObject(expected) && isObject(actual)) {
    const keys = new Set([...Object.keys(expected), ...Object.keys(actual)]);
    [...keys].sort().forEach((key) =>
      compareValues(expected[key], actual[key], path ? `${path}.${key}` : key, differences),
    );
    return;
  }

  differences.push({ path, expected: expected ?? null, actual: actual ?? null });
}

function validateSourceRecord(product, index, findings) {
  const dataset = "source";
  const productId = isObject(product) ? product.productId ?? null : null;
  const path = `products[${index}]`;

  if (!isObject(product)) {
    findings.push(createFinding("record_invalid", dataset, null, path, "Produktobjekt erwartet."));
    return { productId: null, recordHash: hashJson(product), product: null };
  }

  addMissingFields(product, SOURCE_REQUIRED_FIELDS, dataset, index, productId, findings);

  if (product.productModelVersion !== PRODUCT_MODEL_VERSION) {
    findings.push(
      createFinding(
        "product_model_version_invalid",
        dataset,
        productId,
        `${path}.productModelVersion`,
        "Produktmodellversion 2 erwartet.",
        { expected: PRODUCT_MODEL_VERSION, actual: product.productModelVersion ?? null },
      ),
    );
  }

  if (!isNonEmptyString(product.productId) || !PRODUCT_ID_PATTERN.test(product.productId)) {
    findings.push(createFinding("product_id_invalid", dataset, productId, `${path}.productId`, "Ungültige Produkt-ID."));
  }

  if (!Number.isInteger(product.productVersion) || product.productVersion < 1) {
    findings.push(createFinding("product_version_invalid", dataset, productId, `${path}.productVersion`, "Positive Produktversion erwartet."));
  }

  if (!HASH_PATTERN.test(product.sourceHash ?? "")) {
    findings.push(createFinding("source_hash_invalid", dataset, productId, `${path}.sourceHash`, "Kleingeschriebener SHA-256-Hash erwartet."));
  }

  if (!SKU_PATTERN.test(product.sku ?? "")) {
    findings.push(createFinding("identifier_invalid", dataset, productId, `${path}.sku`, "Ungültige SKU."));
  }

  if (!SLUG_PATTERN.test(product.slug ?? "")) {
    findings.push(createFinding("identifier_invalid", dataset, productId, `${path}.slug`, "Ungültiger Slug."));
  }

  if (!Number.isInteger(product.stock) || product.stock < 0 || product.stock > 1) {
    findings.push(createFinding("stock_invalid", dataset, productId, `${path}.stock`, "Unikatbestand muss 0 oder 1 sein."));
  }

  if (!Number.isInteger(product.priceMinor) || product.priceMinor < MIN_PRICE_MINOR) {
    findings.push(createFinding("price_invalid", dataset, productId, `${path}.priceMinor`, "Preis muss mindestens 50 Cent betragen."));
  }

  if (product.currency !== "eur") {
    findings.push(createFinding("currency_invalid", dataset, productId, `${path}.currency`, "Nur EUR ist zulässig."));
  }

  if (typeof product.salesEnabled !== "boolean") {
    findings.push(createFinding("sales_enabled_invalid", dataset, productId, `${path}.salesEnabled`, "Boolean erwartet."));
  }

  if (!isIsoDate(product.updatedAt)) {
    findings.push(createFinding("updated_at_invalid", dataset, productId, `${path}.updatedAt`, "ISO-Zeitstempel erwartet."));
  }

  validateImages(product.images, dataset, productId, `${path}.images`, findings);
  scanForVinted(product, dataset, path, productId, findings);

  const expectedHash = hashJson(sourceCanonicalProduct(product));
  if (HASH_PATTERN.test(product.sourceHash ?? "") && product.sourceHash !== expectedHash) {
    findings.push(
      createFinding(
        "source_hash_conflict",
        dataset,
        productId,
        `${path}.sourceHash`,
        "sourceHash stimmt nicht mit der kanonischen Produktdarstellung überein.",
        { expected: expectedHash, actual: product.sourceHash },
      ),
    );
  }

  return {
    productId: isNonEmptyString(product.productId) ? product.productId : null,
    productVersion: product.productVersion ?? null,
    sourceHash: product.sourceHash ?? null,
    recordHash: hashJson(product),
    expectedHash,
    expectedPublic: expectedPublicProjection(product),
    product,
  };
}

function validatePublicRecord(product, index, findings) {
  const dataset = "public";
  const productId = isObject(product) ? product.productId ?? null : null;
  const path = `products[${index}]`;

  if (!isObject(product)) {
    findings.push(createFinding("record_invalid", dataset, null, path, "Produktobjekt erwartet."));
    return { productId: null, recordHash: hashJson(product), product: null };
  }

  addMissingFields(product, PUBLIC_REQUIRED_FIELDS, dataset, index, productId, findings);

  for (const field of ["stock", "vintedUrl"]) {
    if (Object.prototype.hasOwnProperty.call(product, field)) {
      findings.push(
        createFinding(
          "legacy_public_field",
          dataset,
          productId,
          `${path}.${field}`,
          `Öffentliche Projektion darf ${field} nicht enthalten.`,
          { field },
        ),
      );
    }
  }

  if (product.productModelVersion !== PRODUCT_MODEL_VERSION) {
    findings.push(createFinding("product_model_version_invalid", dataset, productId, `${path}.productModelVersion`, "Produktmodellversion 2 erwartet."));
  }

  if (!isNonEmptyString(product.productId) || !PRODUCT_ID_PATTERN.test(product.productId)) {
    findings.push(createFinding("product_id_invalid", dataset, productId, `${path}.productId`, "Ungültige Produkt-ID."));
  }

  if (!Number.isInteger(product.productVersion) || product.productVersion < 1) {
    findings.push(createFinding("product_version_invalid", dataset, productId, `${path}.productVersion`, "Positive Produktversion erwartet."));
  }

  if (!HASH_PATTERN.test(product.sourceHash ?? "")) {
    findings.push(createFinding("source_hash_invalid", dataset, productId, `${path}.sourceHash`, "Kleingeschriebener SHA-256-Hash erwartet."));
  }

  if (!SKU_PATTERN.test(product.sku ?? "") || !SLUG_PATTERN.test(product.slug ?? "")) {
    findings.push(createFinding("identifier_invalid", dataset, productId, path, "SKU oder Slug ist ungültig."));
  }

  if (!Number.isInteger(product.priceMinor) || product.priceMinor < MIN_PRICE_MINOR) {
    findings.push(createFinding("price_invalid", dataset, productId, `${path}.priceMinor`, "Preis muss mindestens 50 Cent betragen."));
  }

  if (product.currency !== "eur") {
    findings.push(createFinding("currency_invalid", dataset, productId, `${path}.currency`, "Nur EUR ist zulässig."));
  }

  if (typeof product.salesEnabled !== "boolean") {
    findings.push(createFinding("sales_enabled_invalid", dataset, productId, `${path}.salesEnabled`, "Boolean erwartet."));
  }

  if (!isIsoDate(product.updatedAt)) {
    findings.push(createFinding("updated_at_invalid", dataset, productId, `${path}.updatedAt`, "ISO-Zeitstempel erwartet."));
  }

  validateImages(product.images, dataset, productId, `${path}.images`, findings, { publicProjection: true });
  scanForVinted(product, dataset, path, productId, findings);

  return {
    productId: isNonEmptyString(product.productId) ? product.productId : null,
    productVersion: product.productVersion ?? null,
    sourceHash: product.sourceHash ?? null,
    recordHash: hashJson(product),
    product,
  };
}

function checkDuplicateRecords(records, dataset, findings) {
  const byId = new Map();

  records.forEach((record, index) => {
    if (!record.productId) {
      return;
    }

    const existing = byId.get(record.productId);
    if (existing) {
      findings.push(
        createFinding(
          "duplicate_product_id",
          dataset,
          record.productId,
          `products[${index}].productId`,
          "Produkt-ID ist mehrfach vorhanden.",
          { firstIndex: existing.index, duplicateIndex: index },
        ),
      );

      if (existing.productVersion !== record.productVersion) {
        findings.push(
          createFinding(
            "product_version_conflict",
            dataset,
            record.productId,
            `products[${index}].productVersion`,
            "Doppelte Produkt-ID besitzt abweichende Produktversionen.",
            { first: existing.productVersion, second: record.productVersion },
          ),
        );
      }

      if (existing.sourceHash !== record.sourceHash) {
        findings.push(
          createFinding(
            "source_hash_conflict",
            dataset,
            record.productId,
            `products[${index}].sourceHash`,
            "Doppelte Produkt-ID besitzt abweichende sourceHash-Werte.",
            { first: existing.sourceHash, second: record.sourceHash },
          ),
        );
      }
      return;
    }

    byId.set(record.productId, { ...record, index });
  });

  return byId;
}

function buildRecordSummaries(records) {
  return records
    .map((record) => ({
      productId: record.productId,
      productVersion: record.productVersion,
      sourceHash: record.sourceHash,
      recordHash: record.recordHash,
    }))
    .sort((left, right) =>
      stableStringify(left).localeCompare(stableStringify(right), "en"),
    );
}

function compareProjection(sourceMap, publicMap, findings) {
  for (const [productId, source] of sourceMap) {
    const publicRecord = publicMap.get(productId);
    if (!publicRecord) {
      findings.push(
        createFinding(
          "public_projection_missing",
          "comparison",
          productId,
          null,
          "Öffentliche Projektion fehlt.",
        ),
      );
      continue;
    }

    if (source.productVersion !== publicRecord.productVersion) {
      findings.push(
        createFinding(
          "product_version_conflict",
          "comparison",
          productId,
          "productVersion",
          "Produktversion von Quelle und öffentlicher Projektion weicht ab.",
          { source: source.productVersion, public: publicRecord.productVersion },
        ),
      );
    }

    if (source.sourceHash !== publicRecord.sourceHash) {
      findings.push(
        createFinding(
          "source_hash_conflict",
          "comparison",
          productId,
          "sourceHash",
          "sourceHash von Quelle und öffentlicher Projektion weicht ab.",
          { source: source.sourceHash, public: publicRecord.sourceHash },
        ),
      );
    }

    const differences = [];
    compareValues(source.expectedPublic, publicRecord.product, "product", differences);
    if (differences.length > 0) {
      findings.push(
        createFinding(
          "public_projection_mismatch",
          "comparison",
          productId,
          "product",
          "Öffentliche Projektion weicht von der erwarteten Abbildung ab.",
          { differences },
        ),
      );
    }
  }

  for (const productId of publicMap.keys()) {
    if (!sourceMap.has(productId)) {
      findings.push(
        createFinding(
          "public_projection_orphan",
          "comparison",
          productId,
          null,
          "Öffentliche Projektion besitzt keinen Quellendatensatz.",
        ),
      );
    }
  }
}

export function runMigrationDryRun({ source, publicProjection }) {
  const findings = [];
  const sourceDocument = extractProducts(source, "source", findings);
  const publicDocument = extractProducts(publicProjection, "public", findings);

  if (sourceDocument.version !== null && sourceDocument.version !== 2) {
    findings.push(
      createFinding(
        "source_document_version",
        "source",
        null,
        "version",
        "Die Quelle ist noch nicht als Produktmodell v2 gekennzeichnet.",
        { expected: 2, actual: sourceDocument.version },
      ),
    );
  }

  if (publicDocument.version !== 2) {
    findings.push(
      createFinding(
        "public_document_version",
        "public",
        null,
        "version",
        "Die öffentliche Projektion muss Dokumentversion 2 besitzen.",
        { expected: 2, actual: publicDocument.version },
      ),
    );
  }

  const sourceRecords = sourceDocument.products.map((product, index) =>
    validateSourceRecord(product, index, findings),
  );
  const publicRecords = publicDocument.products.map((product, index) =>
    validatePublicRecord(product, index, findings),
  );
  const sourceMap = checkDuplicateRecords(sourceRecords, "source", findings);
  const publicMap = checkDuplicateRecords(publicRecords, "public", findings);

  compareProjection(sourceMap, publicMap, findings);

  const sourceSummaries = buildRecordSummaries(sourceRecords);
  const publicSummaries = buildRecordSummaries(publicRecords);
  const datasets = {
    source: {
      documentVersion: sourceDocument.version,
      recordCount: sourceRecords.length,
      records: sourceSummaries,
      recordsHash: hashJson(sourceSummaries),
    },
    public: {
      documentVersion: publicDocument.version,
      recordCount: publicRecords.length,
      records: publicSummaries,
      recordsHash: hashJson(publicSummaries),
    },
  };
  const sortedFindings = sortFindings(findings);
  const overallHash = hashJson({
    datasets,
    findings: sortedFindings,
    toolVersion: MIGRATION_DRY_RUN_VERSION,
  });

  return {
    reportSchemaVersion: 1,
    toolVersion: MIGRATION_DRY_RUN_VERSION,
    result: sortedFindings.length === 0 ? "passed" : "failed",
    summary: {
      sourceRecords: sourceRecords.length,
      publicRecords: publicRecords.length,
      findings: sortedFindings.length,
      errors: sortedFindings.length,
    },
    datasets,
    findings: sortedFindings,
    overallHash,
  };
}

export function formatMigrationDryRunText(report) {
  const lines = [
    "AP1.4 Produktmigrations-Dry-Run",
    `Ergebnis: ${report.result}`,
    `Quelle: ${report.summary.sourceRecords} Datensätze, Hash ${report.datasets.source.recordsHash}`,
    `Öffentliche Projektion: ${report.summary.publicRecords} Datensätze, Hash ${report.datasets.public.recordsHash}`,
    `Findings: ${report.summary.findings}`,
    `Gesamthash: ${report.overallHash}`,
  ];

  if (report.findings.length === 0) {
    lines.push("Keine Abweichungen festgestellt.");
  } else {
    lines.push("Findings:");
    for (const finding of report.findings) {
      const product = finding.productId ? ` product=${finding.productId}` : "";
      const path = finding.path ? ` path=${finding.path}` : "";
      lines.push(`- [ERROR] ${finding.code}${product}${path}: ${finding.message}`);
    }
  }

  return `${lines.join("\n")}\n`;
}

function parseArguments(argumentsList) {
  const options = { format: "text", source: null, publicProjection: null };

  for (let index = 0; index < argumentsList.length; index += 1) {
    const argument = argumentsList[index];
    if (argument === "--source" || argument === "--public") {
      const value = argumentsList[index + 1];
      if (!value) {
        throw new Error(`${argument} benötigt einen Pfad.`);
      }
      options[argument === "--source" ? "source" : "publicProjection"] = value;
      index += 1;
    } else if (argument === "--format") {
      const value = argumentsList[index + 1];
      if (!value || !["json", "text"].includes(value)) {
        throw new Error("--format muss json oder text sein.");
      }
      options.format = value;
      index += 1;
    } else if (argument === "--help" || argument === "-h") {
      return { help: true };
    } else {
      throw new Error(`Unbekanntes Argument: ${argument}`);
    }
  }

  if (!options.source || !options.publicProjection) {
    throw new Error("--source und --public sind erforderlich.");
  }

  return options;
}

function readJsonFile(filePath) {
  return JSON.parse(readFileSync(filePath, "utf8"));
}

function printHelp() {
  return [
    "Verwendung:",
    "  node scripts/product-migration-dry-run.mjs --source <source.json> --public <public.json> [--format json|text]",
    "",
    "Das Werkzeug liest beide Dateien ausschließlich und schreibt keine Daten.",
  ].join("\n");
}

export function runCli(argumentsList) {
  const options = parseArguments(argumentsList);
  if (options.help) {
    return { exitCode: 0, output: `${printHelp()}\n` };
  }

  const report = runMigrationDryRun({
    source: readJsonFile(options.source),
    publicProjection: readJsonFile(options.publicProjection),
  });
  return {
    exitCode: report.result === "passed" ? 0 : 1,
    output: options.format === "json"
      ? `${JSON.stringify(report, null, 2)}\n`
      : formatMigrationDryRunText(report),
  };
}

if (process.argv[1] === fileURLToPath(import.meta.url)) {
  try {
    const result = runCli(process.argv.slice(2));
    process.stdout.write(result.output);
    process.exitCode = result.exitCode;
  } catch (error) {
    process.stderr.write(`${error instanceof Error ? error.message : "Unbekannter Fehler"}\n`);
    process.exitCode = 2;
  }
}
