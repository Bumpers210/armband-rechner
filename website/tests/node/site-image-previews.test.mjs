import assert from "node:assert/strict";
import { createHash } from "node:crypto";
import { cp, mkdir, mkdtemp, readFile, rm, writeFile } from "node:fs/promises";
import path from "node:path";
import os from "node:os";
import test from "node:test";
import sharp from "sharp";
import { verifySiteImages } from "../../scripts/generate-site-images.mjs";

const root = path.resolve(import.meta.dirname, "../..");

test("Logo bleibt transparent und scharf, Hero und Logo liefern vollständige versionierte Varianten", async () => {
  const manifest = await verifySiteImages(root);
  for (const [name, data] of Object.entries(manifest)) {
    if (name === "version") continue;
    const original = await readFile(path.join(root, "public", data.source));
    assert.equal(createHash("sha256").update(original).digest("hex"), data.sourceHash);
    const source = await sharp(original).metadata();
    for (const variant of data.variants) {
      const file = await readFile(path.join(root, "public", variant.src));
      const image = await sharp(file).metadata();
      assert.ok(image.width <= source.width && image.height <= source.height);
      assert.ok(Math.abs(image.width / image.height - source.width / source.height) < 0.01);
      if (name === "logo") {
        assert.equal(image.hasAlpha, true);
        assert.ok(file.length < original.length / 10);
      }
      assert.equal(image.exif, undefined);
    }
  }
  assert.match(manifest.hero.webpSrcSet, /480w/);
  assert.match(manifest.logo.pngSrcSet, /192w/);
});

test("Veränderte Originale oder manipulierte Variantenauswahl werden vor dem Export abgewiesen", async () => {
  const temp = await mkdtemp(path.join(os.tmpdir(), "carmaja-site-image-check-"));
  try {
    await mkdir(path.join(temp, "content"));
    await cp(path.join(root, "public/images"), path.join(temp, "public/images"), { recursive: true });
    await cp(path.join(root, "content/brand-image-sources.json"), path.join(temp, "content/brand-image-sources.json"));
    const file = path.join(temp, "content/site-image-previews.json");
    const original = JSON.parse(await readFile(path.join(root, "content/site-image-previews.json"), "utf8"));
    for (const mutation of [data => data.logo.variants.pop(), data => data.hero.webpSrcSet = "https://invalid.example/image.webp"]) {
      const changed = structuredClone(original);
      mutation(changed);
      await writeFile(file, JSON.stringify(changed));
      await assert.rejects(verifySiteImages(temp), /Manifest/);
    }
    await writeFile(file, JSON.stringify(original));
    await writeFile(path.join(temp, "public", original.logo.source), "changed source");
    await assert.rejects(verifySiteImages(temp), /Statische Bildquelle geändert/);
  } finally {
    await rm(temp, { recursive: true, force: true });
  }
});
