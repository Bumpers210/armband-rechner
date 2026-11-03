import { execFileSync } from "node:child_process";
import { readFileSync } from "node:fs";
import { pathToFileURL } from "node:url";

const EXPECTED_REPOSITORY = "Bumpers210/armband-rechner";
const EXPECTED_REF = "refs/heads/main";
const EXPECTED_BRANCH = "main";
const EXPECTED_ACTOR = "Bumpers210";
const SHA_PATTERN = /^[0-9a-f]{40}$/;
const SKU_PATTERN = /^CP-\d{4}-\d{4}$/;
const OPERATION_PATTERN = /^[A-Za-z0-9._:-]{8,100}$/;
const REQUEST_HASH_PATTERN = /^[0-9a-f]{64}$/;
const IMAGE_PATH_PATTERN = /^website\/public\/images\/products\/(CP-\d{4}-\d{4})\/(0[1-5]\.jpg)$/;

function fail(message) {
  throw new Error(`Publisher-Push abgelehnt: ${message}`);
}

function parseCommitMessage(message) {
  const normalized = message.replaceAll("\r\n", "\n").replace(/\n+$/, "");
  const match = normalized.match(
    /^Carmaja collection (publish|archive|restore) (CP-\d{4}-\d{4})\n\nCarmaja-Publisher: collection-v4\nCarmaja-Operation: ([A-Za-z0-9._:-]{8,100})\nCarmaja-Request-SHA256: ([0-9a-f]{64})$/,
  );
  if (!match) {
    fail("Commitkennzeichnung entspricht nicht dem Collection-v4-Vertrag.");
  }
  const [, action, sku, operationId, requestHash] = match;
  if (!SKU_PATTERN.test(sku) || !OPERATION_PATTERN.test(operationId) || !REQUEST_HASH_PATTERN.test(requestHash)) {
    fail("Commitkennzeichnung enthält ungültige Werte.");
  }
  return { action, sku, operationId, requestHash };
}

function validateChangedFiles(changedFiles, sku) {
  if (!Array.isArray(changedFiles) || changedFiles.length < 1 || changedFiles.length > 6) {
    fail("Commit muss products.json und höchstens fünf Produktbilder ändern.");
  }
  const seen = new Set();
  let productsJsonCount = 0;
  for (const entry of changedFiles) {
    const status = entry?.status;
    const path = entry?.path;
    if (!["A", "M", "D"].includes(status) || typeof path !== "string" || seen.has(path)) {
      fail("Dateiliste enthält ungültige, umbenannte oder doppelte Einträge.");
    }
    seen.add(path);
    if (path === "website/content/products.json") {
      if (status !== "M") {
        fail("products.json muss als bestehende Datei geändert werden.");
      }
      productsJsonCount++;
      continue;
    }
    const imageMatch = path.match(IMAGE_PATH_PATTERN);
    if (!imageMatch || imageMatch[1] !== sku) {
      fail("Commit darf nur Bilder der gekennzeichneten Kollektion ändern.");
    }
  }
  if (productsJsonCount !== 1) {
    fail("products.json muss genau einmal enthalten sein.");
  }
}

function validateProductProjection(productsDocument, action, sku) {
  if (!productsDocument || ![3, 4].includes(productsDocument.version) || !Array.isArray(productsDocument.products)) {
    fail("Öffentliche Produktdatei muss Projektion 3 oder 4 verwenden.");
  }
  const matches = productsDocument.products.filter((product) => product?.sku === sku);
  if (action === "archive") {
    if (matches.length !== 0) {
      fail("Archivierte Kollektion ist weiterhin öffentlich enthalten.");
    }
    return;
  }
  if (matches.length !== 1 || (productsDocument.version === 3 && matches[0]?.salesEnabled !== true)) {
    fail("Veröffentlichte Kollektion fehlt oder ist nicht verfügbar.");
  }
  if (productsDocument.version === 4) {
    const product = matches[0];
    const notices = product.noticePolicyId === "natural-stones-v1" ? [
      "Natursteine können in Farbe, Maserung und Form von der Abbildung abweichen.",
      "Die den Steinen zugeschriebenen Wirkungen sind wissenschaftlich nicht nachgewiesen.",
    ] : [];
    if (product.productModelVersion !== 4 || typeof product.salesEnabled !== "boolean"
      || ![null, "natural-stones-v1"].includes(product.noticePolicyId)
      || JSON.stringify(product.notices) !== JSON.stringify(notices)
      || !Number.isSafeInteger(product.productVersion) || product.productVersion < 1
      || !REQUEST_HASH_PATTERN.test(product.sourceHash ?? "")
      || !Number.isSafeInteger(product.priceMinor) || product.priceMinor < 1 || product.currency !== "eur"
      || Object.hasOwn(product, "stock") || Object.hasOwn(product, "vintedUrl")) {
      fail("Mengenprodukt besitzt keinen gültigen Modell-4-Vertrag.");
    }
  }
  const images = matches[0]?.images;
  if (!Array.isArray(images) || images.length < 1 || images.length > 5) {
    fail("Veröffentlichte Kollektion benötigt ein bis fünf Bilder.");
  }
  const expectedPrefix = `/images/products/${sku}/`;
  for (const [index, image] of images.entries()) {
    const fileName = `${String(index + 1).padStart(2, "0")}.jpg`;
    if (image?.fileName !== fileName || image?.src !== `${expectedPrefix}${fileName}`) {
      fail("Öffentliche Bildreihenfolge entspricht nicht der Kollektion.");
    }
  }
}

export function validatePublisherPush({
  repository,
  eventName,
  ref,
  branch,
  actor,
  forced,
  deleted,
  beforeSha,
  headSha,
  parentShas,
  commitMessage,
  changedFiles,
  productsDocument,
}) {
  if (repository !== EXPECTED_REPOSITORY || eventName !== "push" || ref !== EXPECTED_REF || branch !== EXPECTED_BRANCH) {
    fail("Repository, Ereignis oder Branch ist nicht das feste Produktionsziel.");
  }
  if (actor !== EXPECTED_ACTOR || forced !== "false" || deleted !== "false") {
    fail("Akteur, Force-Status oder Löschstatus ist nicht zulässig.");
  }
  if (!SHA_PATTERN.test(beforeSha) || !SHA_PATTERN.test(headSha) || /^0+$/.test(beforeSha) || beforeSha === headSha) {
    fail("Vorheriger oder neuer Commit-SHA ist ungültig.");
  }
  if (!Array.isArray(parentShas) || parentShas.length !== 1 || parentShas[0] !== beforeSha) {
    fail("Es ist nur ein direkter, nicht zusammengeführter Folgecommit zulässig.");
  }
  const metadata = parseCommitMessage(commitMessage);
  validateChangedFiles(changedFiles, metadata.sku);
  validateProductProjection(productsDocument, metadata.action, metadata.sku);
  return metadata;
}

function git(...args) {
  return execFileSync("git", args, { encoding: "utf8" }).trimEnd();
}

function requiredEnvironment(name) {
  const value = process.env[name];
  if (!value) {
    fail(`Umgebungswert ${name} fehlt.`);
  }
  return value;
}

function readChangedFiles(beforeSha, headSha) {
  const output = git("diff", "--name-status", "--no-renames", beforeSha, headSha);
  if (!output) {
    return [];
  }
  return output.split("\n").map((line) => {
    const [status, path, unexpected] = line.split("\t");
    if (unexpected !== undefined) {
      fail("Dateiliste enthält eine unerwartete Struktur.");
    }
    return { status, path };
  });
}

export function runPublisherPushValidation() {
  const beforeSha = requiredEnvironment("CARMAJA_PUBLISH_BEFORE_SHA");
  const headSha = requiredEnvironment("GITHUB_SHA");
  const resolvedHead = git("rev-parse", "HEAD");
  if (resolvedHead !== headSha) {
    fail("Ausgecheckter Commit entspricht nicht dem Push-Commit.");
  }
  const parentShas = git("show", "-s", "--format=%P", headSha).split(/\s+/).filter(Boolean);
  const commitMessage = git("show", "-s", "--format=%B", headSha);
  const productsDocument = JSON.parse(readFileSync("website/content/products.json", "utf8"));
  const metadata = validatePublisherPush({
    repository: requiredEnvironment("GITHUB_REPOSITORY"),
    eventName: requiredEnvironment("GITHUB_EVENT_NAME"),
    ref: requiredEnvironment("GITHUB_REF"),
    branch: requiredEnvironment("GITHUB_REF_NAME"),
    actor: requiredEnvironment("CARMAJA_PUBLISH_ACTOR"),
    forced: requiredEnvironment("CARMAJA_PUBLISH_FORCED"),
    deleted: requiredEnvironment("CARMAJA_PUBLISH_DELETED"),
    beforeSha,
    headSha,
    parentShas,
    commitMessage,
    changedFiles: readChangedFiles(beforeSha, headSha),
    productsDocument,
  });
  process.stdout.write(`AUTOMATIC_COLLECTION_PUBLISH_VALID action=${metadata.action} sku=${metadata.sku}\n`);
}

if (process.argv[1] && import.meta.url === pathToFileURL(process.argv[1]).href) {
  try {
    runPublisherPushValidation();
  } catch (error) {
    process.stderr.write(`${error instanceof Error ? error.message : "Publisher-Push abgelehnt."}\n`);
    process.exitCode = 1;
  }
}
