import { cp, mkdir, readFile, readdir, writeFile } from "node:fs/promises";
import { createHash } from "node:crypto";
import path from "node:path";
import os from "node:os";

// A temporary, public-only payload for the guarded existing test preview adapter.
const temporary = path.resolve(process.argv[2] ?? "");
if (path.dirname(temporary) !== path.resolve(os.tmpdir()) || !/^carmaja-image3200-[a-f0-9]{32}$/.test(path.basename(temporary))) {
  throw new Error("Isoliertes temporäres Ziel erforderlich.");
}
const root = path.resolve("out-test");
const destination = path.join(temporary, "preview-export");
await mkdir(destination); // never overwrite an existing payload
const products = JSON.parse(await readFile(path.join(temporary, "products.json"), "utf8"));
const product = products.products.find((p) => p.slug === "cp-2026-0001-handy-test");
if (!product || products.version !== 4) throw new Error("Testproduktbindung fehlt.");
const files = {};
async function copy(directory, prefix = "") {
  const entries = await readdir(directory, { withFileTypes: true });
  for (const entry of entries.sort((a, b) => a.name.localeCompare(b.name, "en"))) {
    const relative = prefix + entry.name;
    if (entry.isSymbolicLink()) throw new Error("Symlink im Testexport.");
    if (entry.isDirectory()) { await copy(path.join(directory, entry.name), relative + "/"); continue; }
    const allowed = /^_next\/static\/[A-Za-z0-9_.$()/~-]+\.(?:js|css|svg|woff2?)$/.test(relative)
      || /^(?!_next\/)[A-Za-z0-9_.$/~()-]+\.(?:html|txt)$/.test(relative)
      || /^images\/product-previews\/CP-\d{4}-\d{4}\/[a-f0-9]{16}\/0[1-5]-(?:240|640|1200)\.jpg$/.test(relative);
    if (!allowed || relative === "robots.txt") continue;
    const target = path.join(destination, relative);
    await mkdir(path.dirname(target), { recursive: true });
    await cp(path.join(directory, entry.name), target, { errorOnExist: true });
    files[relative] = createHash("sha256").update(await readFile(target)).digest("hex");
  }
}
await copy(root);
const sorted = Object.fromEntries(Object.entries(files).sort(([a], [b]) => a < b ? -1 : a > b ? 1 : 0));
await writeFile(path.join(temporary, "preview-manifest.json"), JSON.stringify({ testOnly: true,
  productVersion: product.productVersion, sourceHash: product.sourceHash, files: sorted }), { flag: "wx" });
console.log(`Test-only image preview payload: ${Object.keys(files).length} files. No original images, configuration or product data.`);
