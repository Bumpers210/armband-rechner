import assert from "node:assert/strict";
import { cp, mkdtemp, mkdir, readFile, rm, writeFile } from "node:fs/promises";
import os from "node:os";
import path from "node:path";
import test from "node:test";

import { verifyProductionExport } from "../../scripts/verify-production-export.mjs";

const websiteDirectory = process.cwd();

async function fixture() {
  const root = await mkdtemp(path.join(os.tmpdir(), "carmaja-production-export-"));
  const outputDirectory = path.join(root, "out");
  const sourceProductsPath = path.join(root, "products.json");
  const imageRoot = path.join(root, "images", "products");

  await mkdir(outputDirectory, { recursive: true });
  await writeFile(path.join(outputDirectory, "index.html"), "<main>Carmaja-Perlen</main>", "utf8");
  await cp(
    path.join(websiteDirectory, "content", "products.json"),
    sourceProductsPath,
  );
  await cp(
    path.join(websiteDirectory, "public", "images", "products"),
    imageRoot,
    { recursive: true },
  );
  return { root, outputDirectory, sourceProductsPath, imageRoot };
}

test("Produktionsverifikation akzeptiert valide freigegebene Produktdaten", async () => {
  const current = await fixture();

  try {
    await verifyProductionExport(current);
  } finally {
    await rm(current.root, { recursive: true, force: true });
  }
});

test("Produktionsverifikation blockiert Test- und Abnahmetexte", async () => {
  const current = await fixture();

  try {
    const sourceText = await readFile(current.sourceProductsPath, "utf8");
    const source = JSON.parse(sourceText);
    source.note = "Testprodukt nur fuer die Abnahme";
    await writeFile(
      current.sourceProductsPath,
      `${JSON.stringify(source, null, 2)}\n`,
      "utf8",
    );
    await assert.rejects(
      verifyProductionExport(current),
      /Test- oder Abnahmedaten/,
    );
  } finally {
    await rm(current.root, { recursive: true, force: true });
  }
});
