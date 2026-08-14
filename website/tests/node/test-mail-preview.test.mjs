import assert from "node:assert/strict";
import fs from "node:fs";
import path from "node:path";
import test from "node:test";

const root = path.resolve(import.meta.dirname, "..", "..");
const page = fs.readFileSync(
  path.join(root, "app", "admin", "mail-vorschau", "page.tsx"),
  "utf8",
);

test("Testvorschau zeigt alle vier E-Mails und kann nichts versenden", () => {
  for (const label of [
    "Bestellbestätigung",
    "Betreiberhinweis",
    "Versandbestätigung",
    "Widerrufsbestätigung",
  ]) {
    assert.match(page, new RegExp(label));
  }

  assert.match(page, /Nur Vorschau – kein Versand/);
  assert.match(page, /künstlich/);
  assert.match(page, /if \(!siteTarget\.isTest\)/);
  assert.doesNotMatch(page, /fetch\(|XMLHttpRequest|method=["']post|Brevo|Stripe/);
  assert.doesNotMatch(page, /jbuchner89@googlemail\.com/i);
});

test("Betreiberhinweis enthält nur freigegebene Bestellangaben", () => {
  const start = page.indexOf('id="mail-preview-operator"');
  const end = page.indexOf('id="mail-preview-shipping"');
  assert.ok(start >= 0 && end > start);
  const operator = page.slice(start, end);

  for (const allowed of [
    "orderNumber",
    "productName",
    "productId",
    "46,90 €",
  ]) {
    assert.match(operator, new RegExp(allowed));
  }

  for (const forbidden of [
    "Erika Muster",
    "erika.muster",
    "Adresse",
    "Versandart",
    "Zahlungsart",
    "Sendungsreferenz",
  ]) {
    assert.doesNotMatch(operator, new RegExp(forbidden, "i"));
  }
});
