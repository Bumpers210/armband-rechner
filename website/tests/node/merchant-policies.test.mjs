import assert from "node:assert/strict";
import { createRequire } from "node:module";
import { existsSync, readFileSync } from "node:fs";
import path from "node:path";
import { fileURLToPath } from "node:url";
import vm from "node:vm";
import test from "node:test";
import ts from "typescript";

const root = fileURLToPath(new URL("../../", import.meta.url));
const require = createRequire(import.meta.url);

function loadPolicies(target = "production") {
  const cache = new Map();
  const env = {
    CARMAJA_SITE_TARGET: target,
    CARMAJA_SITE_URL: target === "test"
      ? "https://test.carmaja-perlen.de"
      : "https://www.carmaja-perlen.de",
  };
  function load(name) {
    if (!name.startsWith("@/")) return require(name);
    const relative = name.slice(2);
    const file = path.join(root, relative);
    if (/\.(?:json|mjs)$/.test(file)) return require(file);
    const filename = existsSync(file + ".ts") ? file + ".ts" : file + ".tsx";
    if (cache.has(filename)) return cache.get(filename);
    const exports = {};
    cache.set(filename, exports);
    const code = ts.transpileModule(readFileSync(filename, "utf8"), {
      compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2022, esModuleInterop: true },
      // ESM imports initialize before statements even when written at the end.
      transformers: { before: [() => source => ts.factory.updateSourceFile(source, [
        ...source.statements.filter(ts.isImportDeclaration),
        ...source.statements.filter(statement => !ts.isImportDeclaration(statement)),
      ])] },
    }).outputText;
    vm.runInNewContext(code, { exports, require: load, URL, process: { env } }, { filename });
    return exports;
  }
  return {
    policies: load("@/lib/merchant-policies"),
    bundle: load("@/content/legal-bundles").getActiveLegalBundle(),
    deployment: require("../../config/production-shop-deployment.json"),
  };
}

test("Versandkosten und Richtlinien stammen aus dem aktiven Produktionsvertrag", () => {
  const { policies, bundle, deployment } = loadPolicies();
  const data = JSON.parse(JSON.stringify(policies.merchantOrganizationStructuredData()));
  const service = data.hasShippingService;
  assert.equal(bundle.id, deployment.shop.legalBundleId);
  assert.equal(bundle.status, "approved");
  assert.equal(data["@type"], "Organization");
  assert.equal(data["@id"], "https://www.carmaja-perlen.de/#organization");
  assert.equal(service["@type"], "ShippingService");
  assert.equal(service.name, deployment.shop.shippingPublicName);
  assert.equal(service.description, bundle.texts.shipping.find(section => section.heading === "Versand").paragraphs.join("\n\n"));
  assert.match(service.description, /2,70/);
  assert.match(service.description, /2 bis 4 Werktage nach endgültiger Zahlungsbestätigung/);
  assert.equal(service.shippingConditions.shippingDestination.addressCountry, "DE");
  assert.equal(service.shippingConditions.shippingRate.value, deployment.shop.shippingAmountMinor / 100);
  assert.equal(service.shippingConditions.shippingRate.currency, "EUR");
  assert.equal(service.url, "https://www.carmaja-perlen.de/versand-und-zahlung/");
  assert.equal(data.hasMerchantReturnPolicy["@type"], "MerchantReturnPolicy");
  assert.equal(data.hasMerchantReturnPolicy.merchantReturnLink, "https://www.carmaja-perlen.de/widerruf/");

  // Published total delivery and withdrawal periods cannot be split or shortened.
  assert.equal(service.handlingTime, undefined);
  assert.equal(service.shippingConditions.transitTime, undefined);
  assert.equal(data.hasMerchantReturnPolicy.merchantReturnDays, undefined);
  const withdrawal = bundle.texts.withdrawal.flatMap(section => section.paragraphs).join(" ");
  assert.match(withdrawal, /binnen vierzehn Tagen/);
  assert.match(withdrawal, /spätestens binnen vierzehn Tagen nach Ihrem Widerruf/);
  assert.match(withdrawal, /Wir tragen die unmittelbaren Rücksendekosten innerhalb Deutschlands/);
});

test("Produktangebote verweisen auf dieselben vollständig definierten Richtlinien", () => {
  const { policies } = loadPolicies();
  const organization = policies.merchantOrganizationStructuredData();
  const offer = policies.merchantOfferPolicies();
  assert.equal(offer.seller["@id"], organization["@id"]);
  assert.equal(offer.shippingDetails["@type"], "OfferShippingDetails");
  assert.equal(offer.shippingDetails.hasShippingService["@id"], organization.hasShippingService["@id"]);
  assert.equal(offer.price, undefined);
  assert.equal(offer.availability, undefined);
});

test("Der Testexport erhält keine strukturierten Produktionsrichtlinien", () => {
  const organization = loadPolicies("test").policies.merchantOrganizationStructuredData();
  assert.equal(organization["@id"], "https://test.carmaja-perlen.de/#organization");
  assert.equal(organization.url, "https://test.carmaja-perlen.de/");
  assert.equal(organization.hasShippingService, undefined);
  assert.equal(organization.hasMerchantReturnPolicy, undefined);
  assert.ok(!JSON.stringify(organization).includes("https://www.carmaja-perlen.de"));
});
