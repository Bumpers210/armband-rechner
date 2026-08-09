import assert from "node:assert/strict";
import test from "node:test";

import {
  INVENTORY_ADJUSTMENT_REASONS,
  MINIMUM_APP_VERSION_CODE,
  V2_PRODUCT_WRITE_FIELDS,
  clientVersionAccepted,
  hasLegacyProductWriteFields,
  hasServerManagedProductWriteFields,
} from "../../lib/product-contract-v2.mjs";

test("alte Produktclients werden unterhalb der Mindestversion abgewiesen", () => {
  assert.equal(MINIMUM_APP_VERSION_CODE, 2);
  assert.equal(clientVersionAccepted(1), false);
  assert.equal(clientVersionAccepted(2), true);
  assert.equal(clientVersionAccepted(4), true);
  assert.equal(clientVersionAccepted(undefined), false);
});

test("neue v2-Produktpayloads enthalten keine Legacy- oder Serverfelder", () => {
  const payload = Object.fromEntries(V2_PRODUCT_WRITE_FIELDS.map((field) => [field, null]));

  assert.equal(hasLegacyProductWriteFields(payload), false);
  assert.equal(hasServerManagedProductWriteFields(payload), false);
  assert.equal(hasLegacyProductWriteFields({ ...payload, stock: 1 }), true);
  assert.equal(hasLegacyProductWriteFields({ ...payload, vintedUrl: "https://example.invalid" }), true);
  assert.equal(hasServerManagedProductWriteFields({ ...payload, productVersion: 3 }), true);
  assert.equal(hasServerManagedProductWriteFields({ ...payload, sourceHash: "a".repeat(64) }), true);
});

test("Inventory-Gründe sind auf den AP1-Vertrag begrenzt", () => {
  assert.deepEqual(INVENTORY_ADJUSTMENT_REASONS, [
    "activate_new_unique",
    "shop_sale",
    "mark_unsellable",
    "release_return",
  ]);
});
