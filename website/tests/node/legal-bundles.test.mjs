import assert from "node:assert/strict";
import { readFile } from "node:fs/promises";
import { test } from "node:test";

import {
  assertCheckoutLegalBundle,
  assertLegalSnapshotMatchesBundle,
  createLegalBundle,
  legalBundleSnapshot,
  validateLegalBundle,
} from "../../lib/legal-bundles.mjs";

const texts = {
  terms: [{ heading: "Test", paragraphs: ["Künstlicher Testinhalt."] }],
  privacy: [{ heading: "Test", paragraphs: ["Künstlicher Testinhalt."] }],
  withdrawal: [{ heading: "Test", paragraphs: ["Künstlicher Testinhalt."] }],
  shipping: [{ heading: "Test", paragraphs: ["Künstlicher Testinhalt."] }],
};

function makeBundle(overrides = {}) {
  return createLegalBundle({
    id: "cmj-test-legal-2026-08-02-v1",
    environment: "test",
    version: "v1",
    status: "test_only",
    archiveUrl: "/legal-archive/test/cmj-test-legal-2026-08-02-v1/",
    createdAt: "2026-08-02T00:00:00.000Z",
    texts,
    ...overrides,
  });
}

test("Legal-Bundle-Hash und Testziel werden validiert", () => {
  const bundle = makeBundle();
  assert.doesNotThrow(() => validateLegalBundle(bundle, "test"));
  assert.doesNotThrow(() => assertCheckoutLegalBundle(bundle, "test"));
  assert.match(bundle.contentHash, /^[0-9a-f]{64}$/);
  assert.equal(bundle.contentHash, makeBundle().contentHash);
});

test("Hash-Manipulation wird fail-closed erkannt", () => {
  const bundle = makeBundle();
  assert.throws(
    () => validateLegalBundle({ ...bundle, texts: { ...texts, terms: [] } }, "test"),
    /Hash stimmt/,
  );
});

test("Produktions-Bundle bleibt bis zur externen Freigabe checkoutgesperrt", () => {
  const bundle = makeBundle({
    id: "cmj-production-legal-2026-08-02-v1",
    environment: "production",
    status: "awaiting_external_approval",
    archiveUrl: "/legal-archive/production/cmj-production-legal-2026-08-02-v1/",
  });
  assert.doesNotThrow(() => validateLegalBundle(bundle, "production"));
  assert.throws(() => assertCheckoutLegalBundle(bundle, "production"), /nicht freigegeben/);
  assert.throws(() => validateLegalBundle(makeBundle(), "production"), /Umgebung/);
});

test("Checkout-Snapshot verweist unverändert auf das freigegebene Bundle", () => {
  const bundle = makeBundle();
  const snapshot = legalBundleSnapshot(bundle);
  assert.doesNotThrow(() => assertLegalSnapshotMatchesBundle(snapshot, bundle, "test"));
  assert.throws(
    () => assertLegalSnapshotMatchesBundle({ ...snapshot, legalBundleHash: "0".repeat(64) }, bundle, "test"),
    /Snapshot stimmt/,
  );
});

test("Technische Seiten und Footer bieten alle öffentlichen Legal-Einstiege an", async () => {
  const routes = ["shopbedingungen", "datenschutz", "widerruf", "versand-und-zahlung"];
  for (const route of routes) {
    const page = await readFile(new URL(`../../app/${route}/page.tsx`, import.meta.url), "utf8");
    assert.match(page, /LegalBundlePage/);
  }
  const footer = await readFile(new URL("../../components/site-footer.tsx", import.meta.url), "utf8");
  for (const route of routes) assert.match(footer, new RegExp(`/${route}`));
});

test("AP2-Schema kann Legal-Bundle-Snapshots an Checkout und Bestellung binden", async () => {
  const schema = await readFile(new URL("../../database/commerce-schema.sql", import.meta.url), "utf8");
  assert.match(schema, /checkout_sagas[\s\S]*legal_bundle_id/);
  assert.match(schema, /orders[\s\S]*legal_bundle_id/);
});
