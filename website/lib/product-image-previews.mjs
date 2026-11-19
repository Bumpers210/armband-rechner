// Presentation-only derivatives. Original URLs and legacy JPEGs remain stable.
export const PREVIEW_EDGES = Object.freeze([240, 640, 1200]);
export const WEBP_PREVIEW_EDGES = Object.freeze([240, 640, 960, 1200]);

export function productPreviewPath(src, sourceHash, edge, format = "jpeg") {
  const match = /^\/images\/products\/(CP-\d{4}-\d{4})\/(0[1-5])\.jpg$/.exec(src);
  const edges = format === "webp" ? WEBP_PREVIEW_EDGES : PREVIEW_EDGES;
  if (!match || !/^[a-f0-9]{64}$/.test(sourceHash) || !edges.includes(edge)
      || !["jpeg", "webp"].includes(format)) {
    throw new Error("Ungültiger Produkt-Vorschaubildpfad.");
  }
  const folder = format === "webp" ? "webp-v1/" : "";
  const extension = format === "webp" ? "webp" : "jpg";
  return "/images/product-previews/" + match[1] + "/" + sourceHash.slice(0, 16) + "/" + folder + match[2] + "-" + edge + "." + extension;
}

export function withProductPreviews(product, target) {
  if (!["test", "production"].includes(target)) throw new Error("Unbekanntes Bildziel.");
  return { ...product, images: product.images.map((image) => ({
    ...image,
    previews: Object.fromEntries(PREVIEW_EDGES.map((edge) =>
      [edge, productPreviewPath(image.src, product.sourceHash, edge)])),
    webpPreviews: Object.fromEntries(WEBP_PREVIEW_EDGES.map((edge) =>
      [edge, productPreviewPath(image.src, product.sourceHash, edge, "webp")])),
  })) };
}
