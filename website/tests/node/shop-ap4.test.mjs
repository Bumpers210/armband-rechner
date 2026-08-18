import assert from "node:assert/strict";
import { readFile } from "node:fs/promises";
import test from "node:test";

const root = new URL("../../", import.meta.url);

async function source(relative) {
  return readFile(new URL(relative, root), "utf8");
}

test("AP4-Public-API definiert Cookies, CORS und No-Store", async () => {
  const api = await source("test-api-private/program/shop-public.php");
  const bootstrap = await source("test-api-private/program/bootstrap.php");
  for (const value of [
    "__Host-cmj_shop_session",
    "__Host-cmj_checkout",
    "Access-Control-Allow-Credentials: true",
    "Access-Control-Max-Age: 600",
    "Cache-Control: no-store",
    "X-CSRF-Token",
    "X-Live-Context",
  ]) {
    assert.match(`${api}\n${bootstrap}`, new RegExp(value.replace(/[.*+?^${}()|[\]\\]/g, "\\$&")));
  }
});

test("AP4-Bootstrap interpretiert MySQL-DATETIME verbindlich als UTC", async () => {
  const bootstrap = await source("test-api-private/program/bootstrap.php");
  assert.match(bootstrap, /date_default_timezone_set\('UTC'\)/);
  assert.match(bootstrap, /timezone_configuration_failed/);
});

test("Shop-v2-Checkout verwendet nur Live-Verfügbarkeit und keine Bestandsmenge", async () => {
  const page = await source("app/armbaender/[slug]/page.tsx");
  const buyNow = await source("components/shop-buy-now.tsx");
  const styles = await source("app/site.css");
  assert.match(page, /ShopBuyNow/);
  assert.doesNotMatch(page, /product\.stock/);
  assert.match(buyNow, /cache: "no-store"/);
  assert.match(buyNow, /shop\/v2\/products/);
  assert.match(buyNow, /liveProduct\.available/);
  assert.doesNotMatch(buyNow, /availableQuantity|buyable/);
  assert.match(buyNow, /Jetzt kaufen/);
  assert.doesNotMatch(buyNow, /Jetzt sicher kaufen/);
  assert.match(buyNow, /message \? <p className="shop-message">/);
  assert.match(styles, /\.shop-price[\s\S]*font-size: clamp\(2\.15rem, 8vw, 2\.8rem\)/);
  assert.match(styles, /\.shop-buy-button[\s\S]*min-height: 4\.35rem/);
});

test("AP4-Widerruf verlangt Vorschau und ausdrückliche Bestätigung", async () => {
  const form = await source("components/withdrawal-form.tsx");
  const page = await source("app/widerruf/page.tsx");
  const bootstrap = await source("test-api-private/program/bootstrap.php");
  const commerce = await source("test-api-private/program/commerce-core.php");
  assert.match(`${form}\n${page}\n${bootstrap}`, /withdrawals\/preview/);
  assert.match(`${form}\n${page}\n${bootstrap}`, /Widerruf bestätigen/);
  assert.match(bootstrap, /withdrawals', 'confirm/);
  assert.match(commerce, /withdrawal_receipt/);
});

test("Vinted/Marktplatz ist aus den sichtbaren Kaufseiten entfernt", async () => {
  const home = await source("app/page.tsx");
  const listing = await source("app/armbaender/page.tsx");
  const detail = await source("app/armbaender/[slug]/page.tsx");
  const content = await source("content/site-content.ts");
  assert.doesNotMatch(`${home}\n${listing}\n${detail}\n${content}`, /Vinted|vinted|Marktplatz/);
});

test("Erfolgs-, Abbruch- und Fehlerseite existieren", async () => {
  for (const path of [
    "app/shop/success/page.tsx",
    "app/shop/cancel/page.tsx",
    "app/shop/error/page.tsx",
  ]) {
    const page = await source(path);
    assert.match(page, /Zurück zu den Armbändern/);
  }
});

test("AP3b-Erfolgsseite zeigt asynchrone SEPA-Bearbeitung ohne verfrühte Bestellung", async () => {
  const page = await source("app/shop/success/page.tsx");
  const status = await source("components/checkout-status.tsx");
  assert.match(page, /CheckoutStatusPanel/);
  assert.match(status, /payment_status === "processing"/);
  assert.match(status, /SEPA-Lastschrift/);
  assert.match(status, /erst nach der Zahlungsbestätigung/);
  assert.match(status, /cache: "no-store"/);
});
