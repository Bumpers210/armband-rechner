import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import vm from 'node:vm';
import test from 'node:test';
import ts from 'typescript';
import * as legal from '../../lib/legal-bundles.mjs';

function bundles(target = 'test') {
  const source = readFileSync(new URL('../../content/legal-bundles.ts', import.meta.url), 'utf8');
  const output = ts.transpileModule(source, {
    compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2022 },
  }).outputText;
  const exports = {};
  vm.runInNewContext(output, { exports, require(name) {
    if (name === '@/lib/legal-bundles.mjs') return legal;
    if (name === '@/config/site-target') return { siteTarget: { name: target } };
    throw new Error('Unexpected import');
  } });
  return exports;
}

test('Neue Testfassung bindet zehn insgesamt und einmalig 270 Cent', () => {
  const content = bundles();
  const bundle = content.getActiveLegalBundle();
  assert.equal(bundle.id, 'cmj-test-legal-2026-10-25-v4');
  assert.equal(bundle.status, 'test_only');
  assert.equal(bundle, content.cartTestLegalBundle);
  assert.doesNotThrow(() => legal.assertCheckoutLegalBundle(bundle, 'test'));
  assert.match(JSON.stringify(bundle.texts.terms), /insgesamt höchstens 10.*über alle Positionen und Mengen zusammen/);
  assert.match(JSON.stringify(bundle.texts.terms), /nicht automatisch gekürzt oder aufgeteilt/);
  assert.match(JSON.stringify(bundle.texts.shipping), /einmalig 2,70 € je Bestellung mit insgesamt höchstens 10/);
});

test('Historische Bundles und bisheriger Entwurf behalten ihren exakten Hash', () => {
  const content = bundles();
  // Public legal content hashes captured before adding the new limit version.
  const expected = {
    testLegalBundle: 'cfb95b9069c591902fda3f597b73f4ec4df2e87013dc58b6c2fade2bca79066b',
    productionLegalBundle: '8e746053e1f0032694ff331a420d93cc9d5f0727cf83ec299393a8764c77e04e',
    productionLegalBundleV4: '0bc420ad6dd574ae0005d63f9e6c494d6db18a71b4a9f294314209f0b4dea9f1',
    productionLegalBundleV5: 'dd37346635df45d48b9789484940942783606946ceec7ff3eed3e4173f1aef49',
    cartTestLegalBundleV3: 'e532de654730b2e4d1db9432c78f40f7b51a1c28517e3559d34dcb885fd05339',
    cartProductionLegalDraftV6: 'd4a6b48ee3c2ac3ad24809fd90772bafbd63bbff71b8dc70ddff4da892326a73',
  };
  for (const [name, hash] of Object.entries(expected)) {
    assert.equal(content[name].contentHash, hash, name);
    assert.doesNotThrow(() => legal.validateLegalBundle(content[name], content[name].environment));
  }
});

test('Testarchiv erhaelt v3 neben der neuen v4 ohne Produktionsfassungen', () => {
  const content = bundles();
  assert.deepEqual(Array.from(content.legalBundlesByTarget.test, b => b.version), ['v4', 'v3', 'v2']);
  for (const bundle of content.legalBundlesByTarget.test) {
    assert.equal(bundle.environment, 'test');
    assert.equal(bundle.archiveUrl, `/legal-archive/test/${bundle.id}/`);
  }
});

test('Produktionsentwurf v7 bleibt gesperrt und nicht im aktiven Produktionsbuild', () => {
  const content = bundles('production');
  const draft = content.cartProductionLegalDraft;
  assert.equal(draft.id, 'cmj-production-legal-2026-10-25-v7');
  assert.equal(draft.status, 'awaiting_external_approval');
  assert.throws(() => legal.assertCheckoutLegalBundle(draft, 'production'), /nicht freigegeben/);
  assert.equal(content.getActiveLegalBundle(), content.productionLegalBundleV5);
  assert.ok(!content.legalBundlesByTarget.production.includes(draft));
  assert.match(JSON.stringify(draft.texts.terms), /insgesamt höchstens 10/);
  assert.match(JSON.stringify(draft.texts.shipping), /einmalig 2,70 € je Bestellung mit insgesamt höchstens 10/);
});

test('Nur Mengengrenze aendert sich, Zahlungs-, Datenschutz- und Widerrufsvertrag bleiben', () => {
  const content = bundles();
  for (const [newBundle, oldBundle] of [
    [content.cartTestLegalBundle, content.cartTestLegalBundleV3],
    [content.cartProductionLegalDraft, content.cartProductionLegalDraftV6],
  ]) {
    assert.deepEqual(newBundle.texts.privacy, oldBundle.texts.privacy);
    assert.deepEqual(newBundle.texts.withdrawal, oldBundle.texts.withdrawal);
    const oldTerms = oldBundle.texts.terms.flatMap(s => s.paragraphs);
    const newTerms = newBundle.texts.terms.flatMap(s => s.paragraphs);
    assert.ok(oldTerms.every(text => newTerms.includes(text)));
    assert.equal(newTerms.length, oldTerms.length + 1);
    const paymentBefore = oldBundle.texts.shipping.find(s => s.heading === 'Zahlung');
    if (paymentBefore) assert.deepEqual(newBundle.texts.shipping.find(s => s.heading === 'Zahlung'), paymentBefore);
  }
});

test('Neue Legal-Snapshots sind deterministisch und nicht gegen v3 austauschbar', () => {
  const content = bundles();
  const current = content.cartTestLegalBundle;
  const snapshot = legal.legalBundleSnapshot(current);
  assert.equal(current.contentHash, bundles().cartTestLegalBundle.contentHash);
  assert.doesNotThrow(() => legal.assertLegalSnapshotMatchesBundle(snapshot, current, 'test'));
  assert.throws(() => legal.assertLegalSnapshotMatchesBundle(snapshot, content.cartTestLegalBundleV3, 'test'), /Snapshot/);
});

test('Privater MySQL-Grenznachweis ist restore-only und an dieselbe neue Testfassung gebunden', () => {
  const source = readFileSync(new URL('../../scripts/cart-limit-mysql-test.php', import.meta.url), 'utf8');
  assert.ok(source.includes(bundles().cartTestLegalBundle.id));
  assert.match(source, /cart_probe_connect\(\$config\['restore'\], \$config\)/);
  assert.match(source, /cart_probe_empty\(\$pdo\)/);
  assert.match(source, /finally[\s\S]*cart_ui_cleanup\(\)/);
  assert.match(source, /cart_ui_protected\(\) === \$protected/);
  assert.doesNotMatch(source, /cart_ui_init\(|cart_probe_main\(|new Stripe|curl_exec\(/);
});
