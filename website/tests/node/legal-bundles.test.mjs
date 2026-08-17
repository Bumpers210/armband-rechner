import assert from "node:assert/strict";
import { createHash } from "node:crypto";
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

test("Legal-Bundle-Archiv wird für das aktive Buildziel statisch erzeugt", async () => {
  const archivePage = await readFile(
    new URL("../../app/legal-archive/[environment]/[bundleId]/page.tsx", import.meta.url),
    "utf8",
  );
  assert.match(archivePage, /generateStaticParams/);
  assert.match(archivePage, /legalBundlesByTarget\[siteTarget\.name\]/);
  assert.match(archivePage, /LegalBundleArchive/);
  assert.match(archivePage, /dynamicParams = false/);
});

test("Technische Bundle-Daten erscheinen nur im unveränderlichen Archiv", async () => {
  const component = await readFile(
    new URL("../../components/legal-bundle-page.tsx", import.meta.url),
    "utf8",
  );
  const [publicPage, archivePage] = component.split("const archiveSections");

  assert.doesNotMatch(publicPage, /Legal-Bundle-ID|Inhalts-Hash|bundle\.archiveUrl/);
  assert.match(archivePage, /Legal-Bundle-ID/);
  assert.match(archivePage, /Inhalts-Hash/);
});

test("AP2-Schema kann Legal-Bundle-Snapshots an Checkout und Bestellung binden", async () => {
  const schema = await readFile(new URL("../../database/commerce-schema.sql", import.meta.url), "utf8");
  assert.match(schema, /checkout_sagas[\s\S]*legal_bundle_id/);
  assert.match(schema, /orders[\s\S]*legal_bundle_id/);
});

test("freigegebene AP6-Produktionsfassung ist dem aktuellen Shopvertrag zugeordnet", async () => {
  const source = await readFile(new URL("../../content/legal-bundles.ts", import.meta.url), "utf8");
  assert.match(source, /cmj-test-legal-2026-08-06-v2/);
  assert.match(source, /cmj-production-legal-2026-08-07-v3/);
  assert.match(source, /status: "approved"/);
  assert.match(source, /PayPal, Klarna und SEPA-Lastschrift/);
  assert.match(source, /Maxibrief der Deutschen Post bis 1\.000 g/);
  assert.match(source, /Versandkosten je Bestellung: 2,70 €/);
  assert.match(source, /Basis-Sendungsverfolgung enthält regelmäßig keinen Zustellnachweis/);
  assert.match(source, /zahlungspflichtig bestellen/);
});

test("freigegebene Produktionsfassung v4 entfernt PayPal und ist aktiv", async () => {
  const source = await readFile(new URL("../../content/legal-bundles.ts", import.meta.url), "utf8");
  const candidateStart = source.indexOf("const productionApprovedTextsV4");
  const candidateEnd = source.indexOf("export const testLegalBundle");
  const candidate = source.slice(candidateStart, candidateEnd);

  assert.ok(candidateStart >= 0 && candidateEnd > candidateStart);
  assert.doesNotMatch(candidate, /PayPal|paypal\.com/);
  assert.match(candidate, /Kredit- und Debitkarte, Apple Pay und Google Pay auf Kartenbasis, Klarna und SEPA-Lastschrift/);
  assert.match(source, /id: "cmj-production-legal-2026-08-11-v4"/);
  assert.match(source, /export const productionLegalBundleV4/);
  assert.match(source, /status: "approved"/);
  assert.match(source, /0bc420ad6dd574ae0005d63f9e6c494d6db18a71b4a9f294314209f0b4dea9f1/);
  assert.match(source, /production: \[productionLegalBundleV4, productionLegalBundle\]/);
});

test("Legal Bundle v5 beschreibt Kollektionen und bleibt bis zur Abnahme inaktiv", async () => {
  const source = await readFile(new URL("../../content/legal-bundles.ts", import.meta.url), "utf8");
  assert.match(source, /id: "cmj-production-legal-2026-08-16-v5"/);
  assert.match(source, /export const productionLegalBundleV5Draft/);
  assert.match(source, /status: "awaiting_external_approval"/);
  assert.match(source, /ce39a7b3dffca205c53ee30a7c0a08226595f1325c033c6788db634c6239ebef/);
  assert.match(source, /wiederholt bestellbaren Kollektion/);
  assert.match(source, /Andere Kundinnen und Kunden können dieselbe aktive Kollektion/);
  assert.doesNotMatch(
    source,
    /production: \[productionLegalBundleV5Draft/,
    "Ungeprüfter v5-Entwurf darf nicht aktiv sein.",
  );
});

test("v5-Prüfpaket ist vollständig gehasht und ausdrücklich nicht freigegeben", async () => {
  const packageBase = new URL("../../docs/legal-review/ap8-2026-08-16-v1/", import.meta.url);
  const manifest = JSON.parse(await readFile(new URL("manifest.json", packageBase), "utf8"));
  assert.equal(manifest.status, "entwurf_nicht_freigegeben");
  assert.equal(manifest.legalBundleStatus, "draft");
  assert.equal(
    manifest.legalBundleContentSha256,
    "ce39a7b3dffca205c53ee30a7c0a08226595f1325c033c6788db634c6239ebef",
  );

  for (const document of manifest.documents) {
    const bytes = await readFile(new URL(document.file, packageBase));
    const fileText = bytes.toString("utf8");
    const contentMatch = fileText.match(/<!-- hash-begin -->\r?\n([\s\S]*?)\r?\n<!-- hash-end -->/);
    assert.ok(contentMatch, `${document.file}: Hashbereich fehlt`);
    const contentHash = createHash("sha256")
      .update(contentMatch[1].replace(/\r\n?/g, "\n"), "utf8")
      .digest("hex");
    assert.equal(contentHash, document.contentSha256, `${document.file}: Inhalts-Hash`);
    assert.equal(createHash("sha256").update(bytes).digest("hex"), document.fileSha256, `${document.file}: Datei-Hash`);
  }
});

test("AP7-v4-Freigabepaket bindet die neue Fassung ohne aktive PayPal-Nennung", async () => {
  const packageBase = new URL("../../docs/legal-review/ap7-2026-08-11-v1/", import.meta.url);
  const manifest = JSON.parse(await readFile(new URL("manifest.json", packageBase), "utf8"));

  assert.equal(manifest.status, "freigegeben");
  assert.equal(manifest.legalBundleId, "cmj-production-legal-2026-08-11-v4");
  assert.equal(manifest.legalBundleStatus, "approved");
  assert.equal(
    manifest.legalBundleContentSha256,
    "0bc420ad6dd574ae0005d63f9e6c494d6db18a71b4a9f294314209f0b4dea9f1",
  );
  assert.equal(manifest.approvalEvidenceStored, false);

  for (const document of manifest.documents) {
    const bytes = await readFile(new URL(document.file, packageBase));
    const text = bytes.toString("utf8");
    const contentMatch = text.match(
      /<!-- hash-begin -->\r?\n([\s\S]*?)\r?\n<!-- hash-end -->/,
    );
    assert.ok(contentMatch, `${document.file}: Hashbereich fehlt`);

    const normalizedContent = contentMatch[1].replace(/\r\n?/g, "\n");
    const contentHash = createHash("sha256")
      .update(normalizedContent, "utf8")
      .digest("hex");
    const fileHash = createHash("sha256").update(bytes).digest("hex");

    assert.equal(contentHash, document.contentSha256, `${document.file}: Inhalts-Hash`);
    assert.equal(fileHash, document.fileSha256, `${document.file}: Datei-Hash`);
    assert.match(text, /Status: \*\*freigegeben\*\*/);
    if (document.file !== "09-schriftliche-freigabebestaetigung.md") {
      assert.doesNotMatch(text, /PayPal|paypal\.com/);
    }
  }
});

test("freigegebenes Produktions-Bundle ist für Checkout-Snapshots zulässig", () => {
  const bundle = makeBundle({
    id: "cmj-production-legal-2026-08-07-v3",
    environment: "production",
    version: "v3",
    status: "approved",
    archiveUrl: "/legal-archive/production/cmj-production-legal-2026-08-07-v3/",
  });
  assert.doesNotThrow(() => assertCheckoutLegalBundle(bundle, "production"));
});

test("AP6-Freigabemanifest bindet alle freigegebenen Dokumentfassungen per SHA-256", async () => {
  const packageBase = new URL("../../docs/legal-review/ap6-2026-08-07-v1/", import.meta.url);
  const manifest = JSON.parse(await readFile(new URL("manifest.json", packageBase), "utf8"));

  assert.equal(manifest.status, "freigegeben");
  assert.equal(manifest.legalBundleId, "cmj-production-legal-2026-08-07-v3");
  assert.equal(manifest.legalBundleStatus, "approved");
  assert.equal(manifest.approvalEvidenceStored, false);

  for (const document of manifest.documents) {
    const bytes = await readFile(new URL(document.file, packageBase));
    const text = bytes.toString("utf8");
    const contentMatch = text.match(
      /<!-- hash-begin -->\r?\n([\s\S]*?)\r?\n<!-- hash-end -->/,
    );
    assert.ok(contentMatch, `${document.file}: Hashbereich fehlt`);

    const normalizedContent = contentMatch[1].replace(/\r\n?/g, "\n");
    const contentHash = createHash("sha256")
      .update(normalizedContent, "utf8")
      .digest("hex");
    const fileHash = createHash("sha256").update(bytes).digest("hex");

    assert.equal(contentHash, document.contentSha256, `${document.file}: Inhalts-Hash`);
    assert.equal(fileHash, document.fileSha256, `${document.file}: Datei-Hash`);
    assert.match(text, /Status: \*\*freigegeben\*\*/);
    assert.doesNotMatch(text, /PENDING|externe Rechtsfreigabe ausstehend/);
  }
});
