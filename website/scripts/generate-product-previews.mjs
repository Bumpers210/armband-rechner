import { lstat, mkdir, readFile, realpath, rename, rm, writeFile } from "node:fs/promises";
import path from "node:path";
import sharp from "sharp";
import { PREVIEW_EDGES, productPreviewPath } from "../lib/product-image-previews.mjs";

async function safeParents(root, relative, create = false) {
  let current = root;
  for (const part of relative.split("/").slice(0, -1)) {
    current = path.join(current, part);
    if (create) await mkdir(current, { recursive: false }).catch((error) => {
      if (error.code !== "EEXIST") throw error;
    });
    const stat = await lstat(current);
    if (!stat.isDirectory() || stat.isSymbolicLink()) throw new Error("Unsicherer Bildpfad.");
  }
}

export async function generateProductPreviews(products, outputDirectory) {
  const root = path.resolve(outputDirectory);
  if (await realpath(root) !== root || (await lstat(root)).isSymbolicLink()) {
    throw new Error("Unsicheres Exportziel.");
  }
  let count = 0;
  for (const product of products) {
    for (const image of product.images) {
      // Validate every URL before using it as a filesystem path.
      productPreviewPath(image.src, product.sourceHash, 240);
      const relative = image.src.slice(1);
      await safeParents(root, relative);
      const source = path.join(root, relative);
      const stat = await lstat(source);
      if (!stat.isFile() || stat.isSymbolicLink() || stat.size > 5 * 1048576) {
        throw new Error("Ungültige Produktbilddatei.");
      }
      const bytes = await readFile(source);
      const metadata = await sharp(bytes, { limitInputPixels: 3200 * 3200, failOn: "warning" }).metadata();
      if (metadata.format !== "jpeg" || metadata.width !== image.width || metadata.height !== image.height
          || metadata.width > 3200 || metadata.height > 3200) {
        throw new Error("Produktbild und Metadaten stimmen nicht überein.");
      }
      for (const edge of PREVIEW_EDGES) {
        const destination = productPreviewPath(image.src, product.sourceHash, edge).slice(1);
        await safeParents(root, destination, true);
        const target = path.join(root, destination);
        // No source changes, no enlargement, no EXIF/GPS metadata in derivatives.
        const preview = await sharp(bytes, { limitInputPixels: 3200 * 3200, failOn: "warning" })
          .resize(edge, edge, { fit: "inside", withoutEnlargement: true })
          .jpeg({ quality: 84 }).toBuffer();
        const temporary = `${target}.tmp`;
        let created = false;
        try {
          await writeFile(temporary, preview, { flag: "wx" });
          created = true;
          await rename(temporary, target);
        } finally { if (created) await rm(temporary, { force: true }); }
        count++;
      }
    }
  }
  return count;
}
