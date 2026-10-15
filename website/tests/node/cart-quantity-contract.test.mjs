import assert from "node:assert/strict";
import { readFile } from "node:fs/promises";
import { test } from "node:test";
const source = (path) => readFile(new URL(`../../${path}`, import.meta.url), "utf8");

test("Mengenmigration erhält historische Snapshots und bestätigt keinen unbekannten Bestand", async () => {
  const sql = await source("database/migrations/commerce-v3-cart-inventory.sql");
  for (const table of ["shop_carts", "cart_quotes", "cart_operations", "checkout_items", "withdrawal_items", "restocked_items", "inventory_initializations"]) {
    assert.match(sql, new RegExp(`CREATE TABLE ${table}`));
  }
  assert.match(sql, /quantity_confirmed BOOLEAN NOT NULL DEFAULT FALSE/);
  assert.match(sql, /uq_reservation_checkout_product \(checkout_id, product_id\)/);
  assert.match(sql, /'partial'/);
  assert.doesNotMatch(sql, /UPDATE\s+(?:orders|order_items|commerce_inventory)|DROP TABLE/i);
});

test("V5 trennt ausdrueckliche Verkaufsfreigabe von Veroeffentlichung und Bestand", async () => {
  const api = await source("test-api-private/program/product-api-v5.php");
  assert.match(api, /carmaja_api_v3_allowed_put_fields\(\)/);
  assert.match(api, /carmaja_api_v5_product_response_from_draft/);
  assert.doesNotMatch(api, /unset\(\$saved\['salesEnabled'\]\)|\$body\['salesEnabled'\] = false/);
  const core = await source("test-api-private/program/commerce-core.php");
  assert.equal((core.match(/carmaja_commerce_projection_sales_enabled\(\$product, \$action\)/g) ?? []).length, 2);
  assert.match(core, /is_bool\(\$product\['salesEnabled'\]/);
  const cart = await source("test-api-private/program/cart-commerce.php");
  assert.match(cart, /\(int\) \$product\['sales_enabled'\] === 1.*\$inventory\['available'\] > 0/);
});

test("Warenkorb bleibt fail-closed, sitzungsgebunden und prüft Mengen atomar", async () => {
  const router = await source("test-api-private/program/cart-public.php");
  const core = await source("test-api-private/program/cart-commerce.php");
  assert.match(router, /inventoryCartEnabled/);
  assert.match(router, /Access-Control-Allow-Methods: GET, POST, PUT, OPTIONS/);
  assert.match(router, /carmaja_shop_set_no_store/);
  assert.match(router, /65536/);
  assert.match(router, /allowCartRequest/);
  assert.match(core, /inventory_reserved/);
  assert.match(core, /SELECT quantity FROM reservations[\s\S]*FOR UPDATE/);
  assert.match(core, /checkout_version_conflict|cart_version_conflict/);
  assert.match(core, /processing','succeeded','manual_review/);
  assert.match(core, /quantityConfirmed/);
});

test("Testfassungen bleiben getrennt von historischen und neu freizugebenden Rechtstexten", async () => {
  const legal = await source("content/legal-bundles.ts");
  assert.match(legal, /cmj-test-legal-2026-10-06-v3/);
  assert.match(legal, /cartProductionLegalDraft[\s\S]*awaiting_external_approval/);
  assert.match(legal, /test: \[cartTestLegalBundle, testLegalBundle\]/);
  assert.doesNotMatch(legal, /production: \[[^\]]*cartProductionLegalDraft/);
});
