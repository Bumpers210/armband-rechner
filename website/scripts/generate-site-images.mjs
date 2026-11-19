import { createHash } from "node:crypto";
import { readFile, writeFile, mkdir } from "node:fs/promises";
import path from "node:path";
import { fileURLToPath } from "node:url";
import sharp from "sharp";

const recipes = {
  logo: { source: "/images/brand/carmaja-logo-transparent.png", widths: [64, 128, 192], formats: ["png", "webp"], png: { compressionLevel: 9 }, webp: { lossless: true, effort: 6 } },
  hero: { source: "/images/bracelets/hero-dunkelrot-braun-holz.jpg", widths: [480, 960, 1440, 1920], formats: ["webp"], webp: { quality: 90, effort: 5 } },
};
const version = "v1-webp90-lossless-logo";
const digest = bytes => createHash("sha256").update(bytes).digest("hex");

export async function generateSiteImages(projectRoot) {
  const manifest = { version: 1 };
  for (const [name, recipe] of Object.entries(recipes)) {
    const bytes = await readFile(path.join(projectRoot, "public", recipe.source));
    const meta = await sharp(bytes).metadata();
    const hash = digest(Buffer.concat([Buffer.from(version + JSON.stringify(recipe)), bytes])).slice(0, 16);
    const variants = [];
    for (const width of [...new Set(recipe.widths.map(w => Math.min(w, meta.width)))]) {
      for (const format of recipe.formats) {
        const src = "/images/site-previews/v1/" + hash + "/" + name + "-" + width + "." + format;
        const filename = path.join(projectRoot, "public", src);
        const encoder = sharp(bytes).resize({ width, withoutEnlargement: true });
        const output = await encoder[format](recipe[format]).toBuffer();
        await mkdir(path.dirname(filename), { recursive: true });
        await writeFile(filename, output);
        variants.push({ src, width, format, bytes: output.length });
      }
    }
    const srcSet = format => variants.filter(v => v.format === format).map(v => v.src + " " + v.width + "w").join(", ");
    manifest[name] = { source: recipe.source, sourceHash: digest(bytes), variants, webpSrcSet: srcSet("webp"),
      ...(name === "logo" ? { fallback: variants.filter(v => v.format === "png").at(-1).src, pngSrcSet: srcSet("png") }
        : { preloadSrc: variants[Math.min(2, variants.length - 1)].src }) };
  }
  await writeFile(path.join(projectRoot, "content/site-image-previews.json"), JSON.stringify(manifest, null, 2) + "\n");
  await writeFile(path.join(projectRoot, "content/brand-image-sources.json"), JSON.stringify({
    fallback: manifest.logo.fallback, pngSrcSet: manifest.logo.pngSrcSet, webpSrcSet: manifest.logo.webpSrcSet,
  }, null, 2) + "\n");
  return manifest;
}

export async function verifySiteImages(projectRoot) {
  const manifest = JSON.parse(await readFile(path.join(projectRoot, "content/site-image-previews.json"), "utf8"));
  const brand = JSON.parse(await readFile(path.join(projectRoot, "content/brand-image-sources.json"), "utf8"));
  for (const key of ["fallback", "pngSrcSet", "webpSrcSet"]) {
    if (brand[key] !== manifest.logo[key]) throw new Error("Logoquellen und Manifest stimmen nicht überein.");
  }
  if (manifest.version !== 1) throw new Error("Unbekannte Bildvorschauversion.");
  for (const [name, recipe] of Object.entries(recipes)) {
    const data = manifest[name];
    const source = await readFile(path.join(projectRoot, "public", recipe.source));
    const hash = digest(Buffer.concat([Buffer.from(version + JSON.stringify(recipe)), source])).slice(0, 16);
    if (data.source !== recipe.source || data.sourceHash !== digest(source)) throw new Error("Statische Bildquelle geändert: npm run images:site ausführen.");
    const sourceMeta = await sharp(source).metadata();
    const widths = [...new Set(recipe.widths.map(width => Math.min(width, sourceMeta.width)))];
    const expectedVariants = widths.flatMap(width => recipe.formats.map(format => ({ width, format })));
    if (JSON.stringify(data.variants.map(({ width, format }) => ({ width, format }))) !== JSON.stringify(expectedVariants)) throw new Error("Bildvarianten fehlen im Manifest.");
    const srcSet = format => data.variants.filter(v => v.format === format).map(v => v.src + " " + v.width + "w").join(", ");
    if (data.webpSrcSet !== srcSet("webp") || (name === "logo" && data.pngSrcSet !== srcSet("png"))) throw new Error("Bildauswahl und Manifest stimmen nicht überein.");
    if ((name === "logo" && data.fallback !== data.variants.filter(v => v.format === "png").at(-1).src)
        || (name === "hero" && data.preloadSrc !== data.variants[Math.min(2, data.variants.length - 1)].src)) throw new Error("Bildfallback stimmt nicht überein.");
    for (const variant of data.variants) {
      const expected = "/images/site-previews/v1/" + hash + "/" + name + "-" + variant.width + "." + variant.format;
      if (variant.src !== expected || !recipe.formats.includes(variant.format)) throw new Error("Ungültiger Bildvorschaupfad.");
      const bytes = await readFile(path.join(projectRoot, "public", expected));
      const meta = await sharp(bytes).metadata();
      if (meta.width !== variant.width || meta.format !== variant.format || bytes.length !== variant.bytes || meta.exif) throw new Error("Bildvorschau und Manifest stimmen nicht überein.");
    }
  }
  return manifest;
}

if (process.argv[1] && fileURLToPath(import.meta.url) === path.resolve(process.argv[1])) {
  const manifest = await generateSiteImages(process.cwd());
  console.log(Object.values(manifest).filter(v => v?.variants).reduce((sum, v) => sum + v.variants.length, 0) + " statische Bildvarianten erzeugt.");
}
