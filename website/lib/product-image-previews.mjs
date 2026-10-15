// Presentation-only derivatives. The authoritative public image contract is unchanged.
export const PREVIEW_EDGES = Object.freeze([240, 640, 1200]);

export function productPreviewPath(src, sourceHash, edge) {
  const match = /^\/images\/products\/(CP-\d{4}-\d{4})\/(0[1-5])\.jpg$/.exec(src);
  if (!match || !/^[a-f0-9]{64}$/.test(sourceHash) || !PREVIEW_EDGES.includes(edge)) {
    throw new Error("Ungültiger Produkt-Vorschaubildpfad.");
  }
  return `/images/product-previews/${match[1]}/${sourceHash.slice(0, 16)}/${match[2]}-${edge}.jpg`;
}

export function withProductPreviews(product, target) {
  if (target !== "test") return product;
  return { ...product, images: product.images.map((image) => ({
    ...image,
    previews: Object.fromEntries(PREVIEW_EDGES.map((edge) =>
      [edge, productPreviewPath(image.src, product.sourceHash, edge)])),
  })) };
}
