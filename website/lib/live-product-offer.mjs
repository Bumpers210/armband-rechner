// A response projection, never a second price or inventory store.
export function validatedLiveProduct(value, productId) {
  if (!value || typeof value !== "object" || Array.isArray(value)
      || value.productId !== productId || value.currency !== "eur"
      || !Number.isSafeInteger(value.priceMinor) || value.priceMinor <= 0
      || typeof value.available !== "boolean"
      || !Number.isSafeInteger(value.availableQuantity) || value.availableQuantity < 0
      || (value.available && value.availableQuantity === 0)) return null;
  return { productId, priceMinor: value.priceMinor, currency: "eur",
    available: value.available, availableQuantity: value.availableQuantity };
}
export const offerSeedId = id => "cmj-offer-seed-" + id;
export const offerSchemaId = id => "cmj-offer-schema-" + id;
export const offerBoxId = id => "cmj-offer-box-" + id;
export function parseOfferBootstrap(raw, productId) {
  if (typeof raw !== "string" || raw.length > 1024) return null;
  try {
    const value = JSON.parse(raw);
    if (value?.version !== 1 || typeof value.failed !== "boolean") return null;
    if (value.failed && value.product === null) return { version: 1, product: null, failed: true };
    const product = validatedLiveProduct(value.product, productId);
    return product && !value.failed ? { version: 1, product, failed: false } : null;
  } catch { return null; }
}
export function readOfferBootstrap(productId) {
  if (typeof document === "undefined") return null;
  return parseOfferBootstrap(document.getElementById(offerSeedId(productId))?.textContent, productId);
}
export function minorPrice(amount) {
  return Math.floor(amount / 100) + "." + String(amount % 100).padStart(2, "0");
}
export function withLiveOffer(structuredProduct, policies, product) {
  return structuredProduct && product ? { ...structuredProduct, offers: {
    ...policies, "@type": "Offer", url: structuredProduct.url,
    price: minorPrice(product.priceMinor), priceCurrency: "EUR",
    availability: product.available ? "https://schema.org/InStock" : "https://schema.org/OutOfStock",
  } } : structuredProduct;
}
