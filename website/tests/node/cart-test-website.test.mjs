import assert from "node:assert/strict";
import {readFile} from "node:fs/promises";
import test from "node:test";
const source = (relative) => readFile(new URL(`../../${relative}`, import.meta.url), "utf8");

test("Testwebsite behält echten Katalog und Test-API-Trennung bei", async () => {
  const build = await source("scripts/build-test.mjs");
  const config = await source("next.config.ts");
  assert.doesNotMatch(build, /write.*products\.json|useFixture|createFixture/i);
  assert.match(config, /expectedShopApiOrigin/);
  assert.match(config, /test-api\.carmaja-perlen\.de/);
  const catalog = JSON.parse(await source("content/products.json"));
  assert.ok(catalog.products.length > 0);
  for (const item of catalog.products) {
    assert.equal("stock" in item, false);
    assert.equal("vintedUrl" in item, false);
  }
});

test("Warenkorb sperrt Kauf bei API-Ausfall und verwendet Versions-, CSRF- und Idempotenzvertrag", async () => {
  const client = await source("lib/cart-client.ts");
  const cart = await source("components/shop-cart.tsx");
  const buy = await source("components/shop-buy-now.tsx");
  const header = await source("components/site-header.tsx");
  for (const field of ["expectedCartVersion", "Idempotency-Key", "X-CSRF-Token", "no-store"]) assert.ok(client.includes(field));
  assert.match(cart, /setCart\(null\)/);
  assert.match(cart, /!cart\.canCheckout/);
  assert.match(buy, /!product\?\.available/);
  assert.doesNotMatch(`${cart}\n${buy}`, /localStorage|\.stock|vintedUrl/);
  assert.match(header, /ShopCartLink/);
});

test("Test-Legal-Bundle ist versioniert und nicht rechtlich freigegeben", async () => {
  const legal = await source("content/legal-bundles.ts");
  assert.match(legal, /test: \[cartTestLegalBundle, testLegalBundle\]/);
  const block = legal.slice(legal.indexOf("const cartTestTexts:"), legal.indexOf("export const legalBundlesByTarget"));
  assert.match(block, /status: "test_only"/);
  assert.match(block, /einmalig 2,70/);
  assert.match(block, /kein PayPal/);
  assert.match(block, /24 Stunden/);
});
