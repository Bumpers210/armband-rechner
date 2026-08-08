import assert from "node:assert/strict";
import { access, readdir, readFile, rm } from "node:fs/promises";
import path from "node:path";
import { fileURLToPath } from "node:url";

import { loadPublicProducts } from "../lib/public-products.mjs";

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

export async function verifyProductionExport({
  outputDirectory: verifiedOutputDirectory = outputDirectory,
  sourceProductsPath: verifiedSourceProductsPath = sourceProductsPath,
  imageRoot = path.join(websiteDirectory, "public", "images", "products"),
} = {}) {
  assert.equal(await fileExists(verifiedOutputDirectory), true, "Produktionsausgabe fehlt.");

  // Der leere Platzhalter erzeugt keine oeffentliche Produktseite.
  await rm(path.join(verifiedOutputDirectory, "armbaender", "__empty"), {
    force: true,
    recursive: true,
  });
  await rm(path.join(verifiedOutputDirectory, "armbaender", "__empty.html"), { force: true });

  const sourceText = await readFile(verifiedSourceProductsPath, "utf8");
  assert.equal(
    /\b(?:testprodukt|testarmband|testbeschreibung|abnahme)\b/iu.test(sourceText),
    false,
    "Test- oder Abnahmedaten duerfen nicht im Produktionsinhalt stehen.",
  );
  assert.equal(
    /test(?:-api)?\.carmaja-perlen\.de/i.test(sourceText),
    false,
    "Testdomains duerfen nicht im Produktionsinhalt stehen.",
  );

  const sourceProducts = loadPublicProducts(verifiedSourceProductsPath, imageRoot);
  assert.equal(sourceProducts.version, 1, "Produktquelldatei hat keine unterstuetzte Version.");

  const files = await collectFiles(verifiedOutputDirectory);
  for (const route of [
    ["steinwissen", "index.html"],
    ["steinwissen", "spirituelle-bedeutung", "index.html"],
    ["steinwissen", "natursteinkunde", "index.html"],
  ]) {
    assert.equal(
      await fileExists(path.join(verifiedOutputDirectory, ...route)),
      true,
      `Steinwissen-Route fehlt im Produktionsexport: /${route.slice(0, -1).join("/")}/`,
    );
  }
  for (const image of [
    "natural-stones-texture.jpg",
    "polished-gemstones-colours.jpg",
  ]) {
    assert.equal(
      await fileExists(
        path.join(
          verifiedOutputDirectory,
          "images",
          "stone-knowledge",
          image,
        ),
      ),
      true,
      `Steinwissen-Bild fehlt im Produktionsexport: ${image}`,
    );
  }
  assert.equal(
    files.some((filePath) => path.basename(filePath) === "products.json"),
    false,
    "Die Produktquelldatei darf nicht exportiert werden.",
  );
  if (sourceProducts.products.length === 0) {
    assert.equal(
      files.some((filePath) =>
        path.relative(verifiedOutputDirectory, filePath)
          .split(path.sep)
          .join("/")
          .startsWith("images/products/"),
      ),
      false,
      "Ohne Produktdaten duerfen keine Produktbilder exportiert werden.",
    );
  }

  const textFiles = files.filter((filePath) => /\.(?:html|json|txt|xml)$/i.test(filePath));
  const exportText = (await Promise.all(textFiles.map((filePath) => readFile(filePath, "utf8")))).join("\n");

  for (const forbiddenValue of [
    "test.carmaja-perlen.de",
    "test-api.carmaja-perlen.de",
    "vinted.de",
    "vinted",
    "draftId",
    "internalArticleName",
    "internalCalculation",
    "salePrice",
    "careInstructions",
    "deviceToken",
    "expectedVersion",
    "stoneKnowledgeInventory",
    "app-active-materials",
    "Black Stone",
    "images.pexels.com",
  ]) {
    assert.equal(
      exportText.includes(forbiddenValue),
      false,
      `Unzulaessiger Wert im Produktionsexport: ${forbiddenValue}`,
    );
  }

  console.log("PRODUCTION_EXPORT_VERIFIED_OK");
}

if (process.argv[1] === fileURLToPath(import.meta.url)) {
  await verifyProductionExport();
}
