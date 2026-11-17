import editorial from "../content/product-editorial.json" with { type: "json" };

export function withProductEditorial(product) {
  const entry = editorial[product.sku];
  // New app publications take precedence over copy reviewed against older data and photos.
  if (!entry || entry.sourceHash !== product.sourceHash) return product;

  return {
    ...product,
    publicTitle: entry.title,
    description: entry.description,
    descriptionDocument: null,
    materials: entry.materials ?? product.materials,
    metalElements: entry.metalElements ?? product.metalElements,
    displaySize: entry.displaySize ?? product.displaySize,
    displayPearlSize: entry.displayPearlSize ?? product.displayPearlSize,
    custom: entry.custom,
    images: product.images.map((image, index) => ({
      ...image,
      alt: entry.alts[index] ?? image.alt,
    })),
  };
}
