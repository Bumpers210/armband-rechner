import assert from "node:assert/strict";
import { access, readFile } from "node:fs/promises";
import path from "node:path";
import test from "node:test";

const websiteDirectory = process.cwd();

test("der entfernte Alt-Produktdatensatz und seine Bilder sind nicht mehr vorhanden", async () => {
  const productsPath = path.join(websiteDirectory, "content", "products.json");
  const products = JSON.parse(await readFile(productsPath, "utf8"));

  assert.equal(products.version, 2);
  assert.equal(Array.isArray(products.products), true);
  assert.equal(JSON.stringify(products).includes("CP-2026-0001"), false);

  for (const fileName of ["01.jpg", "02.jpg"]) {
    await assert.rejects(
      access(
        path.join(
          websiteDirectory,
          "public",
          "images",
          "products",
          "CP-2026-0001",
          fileName,
        ),
      ),
    );
  }
});
