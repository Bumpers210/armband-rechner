import editorial from "../content/product-editorial.json" with { type: "json" };

export function withProductEditorial(product) {
  const entry = editorial[product.sku];
  // New app publications take precedence over copy reviewed against older data and photos.
  if (!entry || entry.sourceHash !== product.sourceHash) return product;

  // Keep the author's original copy and rich formatting from this publication.
  const correctSpelling = (text) => (entry.spelling ?? []).reduce(
    (result, [from, to]) => result.replaceAll(from, to),
    text.replaceAll("bei jedem Armband und ein", "bei jedem Armband um ein"),
  );
  const document = product.descriptionDocument;

  return {
    ...product,
    publicTitle: entry.title,
    descriptionTitle: correctSpelling(product.publicTitle),
    description: correctSpelling(product.description),
    descriptionDocument: document && {
      ...document,
      blocks: document.blocks.map((block) => ({
        ...block,
        spans: block.spans.map((span) => ({ ...span, text: correctSpelling(span.text) })),
      })),
    },
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
