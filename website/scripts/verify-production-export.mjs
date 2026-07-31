import assert from "node:assert/strict";
import { access, readdir, readFile, rm } from "node:fs/promises";
import path from "node:path";
import { fileURLToPath } from "node:url";

const scriptDirectory = path.dirname(fileURLToPath(import.meta.url));
const websiteDirectory = path.resolve(scriptDirectory, "..");
const outputDirectory = path.join(websiteDirectory, "out");
const sourceProductsPath = path.join(websiteDirectory, "content", "products.json");

async function fileExists(filePath) {
  try {
    await access(filePath);
    return true;
  } catch {
    return false;
  }
}

async function collectFiles(directory) {
  const entries = await readdir(directory, { withFileTypes: true });
  const files = await Promise.all(
    entries.map(async (entry) => {
      const entryPath = path.join(directory, entry.name);
      return entry.isDirectory() ? collectFiles(entryPath) : [entryPath];
    }),
  );

  return files.flat();
}

async function main() {
  assert.equal(await fileExists(outputDirectory), true, "Produktionsausgabe fehlt.");

  // Der leere Platzhalter erzeugt keine oeffentliche Produktseite.
  await rm(path.join(outputDirectory, "armbaender", "__empty"), {
    force: true,
    recursive: true,
  });
  await rm(path.join(outputDirectory, "armbaender", "__empty.html"), { force: true });

  const sourceProducts = JSON.parse(await readFile(sourceProductsPath, "utf8"));
  assert.deepEqual(sourceProducts, { version: 1, products: [] });

  const files = await collectFiles(outputDirectory);
  assert.equal(
    files.some((filePath) => path.basename(filePath) === "products.json"),
    false,
    "Die Produktquelldatei darf nicht exportiert werden.",
  );

  const textFiles = files.filter((filePath) => /\.(?:html|json|txt|xml)$/i.test(filePath));
  const exportText = (await Promise.all(textFiles.map((filePath) => readFile(filePath, "utf8")))).join("\n");

  for (const forbiddenValue of [
    "CP-2026-0001",
    "CP-2026-0002",
    "CP-2026-0003",
    "CP-2026-0004",
    "test.carmaja-perlen.de",
    "test-api.carmaja-perlen.de",
    "draftId",
    "internalArticleName",
    "internalCalculation",
    "salePrice",
    "careInstructions",
    "deviceToken",
    "expectedVersion",
  ]) {
    assert.equal(
      exportText.includes(forbiddenValue),
      false,
      `Unzulaessiger Wert im Produktionsexport: ${forbiddenValue}`,
    );
  }

  console.log("PRODUCTION_EXPORT_VERIFIED_OK");
}

await main();
