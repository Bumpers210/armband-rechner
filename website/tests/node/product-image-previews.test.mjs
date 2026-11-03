import assert from "node:assert/strict";
import { test } from "node:test";
import { mkdtemp, mkdir, readFile, readdir, rm, writeFile } from "node:fs/promises";
import path from "node:path";
import os from "node:os";
import sharp from "sharp";
import { PREVIEW_EDGES, productPreviewPath, withProductPreviews } from "../../lib/product-image-previews.mjs";
import { generateProductPreviews } from "../../scripts/generate-product-previews.mjs";

const sourceHash = "a".repeat(64);
const image = { src: "/images/products/CP-2026-0001/01.jpg", width: 3200, height: 2400 };
test("Preview paths are bounded, versioned and test-only; public fields are not mutated", () => {
  const product = { sourceHash, images: [image] };
  const copy = structuredClone(product);
  assert.equal(withProductPreviews(product, "production"), product);
  const decorated = withProductPreviews(product, "test");
  assert.equal(decorated.images[0].src, image.src);
  assert.match(decorated.images[0].previews[640], /01-640\.jpg$/);
  assert.deepEqual(product, copy);
  for (const bad of ["../01.jpg", "//evil/01.jpg", "/images/products/CP-2026-0001/06.jpg", image.src + "?x=1"]) {
    assert.throws(() => productPreviewPath(bad, sourceHash, 640));
  }
  assert.throws(() => productPreviewPath(image.src, "../x", 640));
  assert.throws(() => productPreviewPath(image.src, sourceHash, 3200));
});

test("3200px and legacy photos produce small deterministic JPEGs without modifying originals", async () => {
  const root = await mkdtemp(path.join(os.tmpdir(), "carmaja-image-preview-"));
  try {
    await mkdir(path.join(root, "images/products/CP-2026-0001"), { recursive: true });
    for (const [width, height] of [[3200, 2400], [1506, 1600], [100, 80]]) {
      const bytes = await sharp({ create: { width, height, channels: 3, background: "#b58271" } })
        .withExif({ IFD0: { Artist: "private synthetic metadata" } }).jpeg().toBuffer();
      await writeFile(path.join(root, image.src.slice(1)), bytes);
      const product = { sourceHash, images: [{ ...image, width, height }] };
      assert.equal(await generateProductPreviews([product], root), 3);
      const first = [];
      for (const edge of PREVIEW_EDGES) {
        const output = await readFile(path.join(root, productPreviewPath(image.src, sourceHash, edge).slice(1)));
        first.push(output);
        const meta = await sharp(output).metadata();
        assert.equal(meta.format, "jpeg");
        assert.equal(meta.exif, undefined);
        assert.equal(Math.max(meta.width, meta.height), Math.min(edge, Math.max(width, height)));
        assert.ok(meta.width <= width && meta.height <= height);
        assert.ok(output.length < bytes.length || width < 240);
      }
      await generateProductPreviews([product], root);
      for (const [i, edge] of PREVIEW_EDGES.entries()) {
        const file = path.join(root, productPreviewPath(image.src, sourceHash, edge).slice(1));
        assert.deepEqual(await readFile(file), first[i]);
      }
      assert.deepEqual(await readFile(path.join(root, image.src.slice(1))), bytes);
    }
    assert.ok((await readdir(path.join(root, "images/product-previews/CP-2026-0001", sourceHash.slice(0, 16))))
      .every((file) => file.endsWith(".jpg")));
    await assert.rejects(generateProductPreviews([{ sourceHash, images: [image] }], root)); // metadata mismatch
    await writeFile(path.join(root, image.src.slice(1)), "not an image");
    await assert.rejects(generateProductPreviews([{ sourceHash, images: [image] }], root));
  } finally { await rm(root, { recursive: true, force: true }); }
});

test("Gallery fetches the full original only inside the opened lightbox", async () => {
  const text = await readFile(new URL("../../components/product-image-gallery.tsx", import.meta.url), "utf8");
  assert.match(text, /activeImage && typeof document/);
  assert.match(text, /src=\{activeImage\.src\}/);
  for (const edge of [240, 640, 1200]) assert.ok(text.includes(`previews?.[${edge}]`));
  assert.doesNotMatch(text, /src=\{mainImage\.src\}|src=\{image\.src\}/);
});
