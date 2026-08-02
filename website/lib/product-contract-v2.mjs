export const MINIMUM_APP_VERSION_CODE = 2;

export const V2_PRODUCT_WRITE_FIELDS = Object.freeze([
  "expectedProductVersion",
  "name",
  "description",
  "materials",
  "metalElements",
  "braceletSize",
  "careInstructions",
  "images",
  "priceMinor",
  "currency",
  "salesEnabled",
]);

export const INVENTORY_ADJUSTMENT_REASONS = Object.freeze([
  "activate_new_unique",
  "shop_sale",
  "mark_unsellable",
  "release_return",
]);

export function clientVersionAccepted(versionCode) {
  return Number.isInteger(versionCode) && versionCode >= MINIMUM_APP_VERSION_CODE;
}

export function hasLegacyProductWriteFields(payload) {
  return Object.hasOwn(payload, "stock") ||
    Object.hasOwn(payload, "vintedUrl");
}

export function hasServerManagedProductWriteFields(payload) {
  return Object.hasOwn(payload, "productVersion") ||
    Object.hasOwn(payload, "sourceHash");
}
