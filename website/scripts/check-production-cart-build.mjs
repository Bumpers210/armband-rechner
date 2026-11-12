// Local regression only; this fixture export must never be deployed.
import assert from 'node:assert/strict';
import { spawnSync } from 'node:child_process';
import { cpSync, existsSync, mkdtempSync, mkdirSync, readFileSync, rmSync, writeFileSync } from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { readJpegDimensions } from '../lib/public-products.mjs';
import { PREVIEW_EDGES, productPreviewPath } from '../lib/product-image-previews.mjs';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const output = path.join(root, 'out');
// Do not overwrite an existing export, even a local one.
if (existsSync(output)) throw new Error('Existing production export must be preserved; use a clean worktree.');
const temporary = mkdtempSync(path.join(os.tmpdir(), 'cmj-production-fixture-'));
const imageRoot = path.join(temporary, 'images/products');
const image = path.join(root, 'public/images/bracelets/hero-dunkelrot-braun-holz.jpg');
const source = JSON.parse(readFileSync(path.join(root, 'tests/public-products-v2.fixture.json')));
const product = source.products[0];
product.productModelVersion = 4;
product.descriptionDocument = { version: 1, blocks: [{ type: 'paragraph', spans: [{
  text: product.description, bold: false, italic: false, font: 'standard', size: 'normal',
}] }] };
product.noticePolicyId = null;
product.notices = [];
product.salesEnabled = false;
Object.assign(product.images[0], readJpegDimensions(image));
const env = { ...process.env, CARMAJA_SITE_TARGET: 'production',
  CARMAJA_SITE_URL: 'https://www.carmaja-perlen.de', CARMAJA_SHOP_API_ORIGIN: 'https://api.carmaja-perlen.de',
  CARMAJA_TEST_FIXTURES: 'true', CARMAJA_PRODUCTS_FILE: path.join(temporary, 'products.json'),
  CARMAJA_PRODUCT_IMAGES_DIR: imageRoot, CARMAJA_PRODUCTION_PUBLISH_ENABLED: 'false',
  CARMAJA_PRODUCTION_DEPLOY_ENABLED: 'false', NEXT_TELEMETRY_DISABLED: '1' };
function run(args) {
  const result = spawnSync(process.execPath, args, { cwd: root, env, stdio: 'inherit' });
  if (result.status !== 0) throw new Error('Local production fixture build failed');
}
try {
  mkdirSync(path.join(imageRoot, product.sku), { recursive: true });
  cpSync(image, path.join(imageRoot, product.sku, '01.jpg'));
  writeFileSync(env.CARMAJA_PRODUCTS_FILE, JSON.stringify({ version: 4, products: [product] }));
  run([path.join(root, 'node_modules/next/dist/bin/next'), 'build']);
  run([path.join(root, 'scripts/copy-hosting-files.mjs')]);
  run([path.join(root, 'scripts/verify-export.mjs')]);
  const detail = readFileSync(path.join(output, 'armbaender', product.slug, 'index.html'), 'utf8');
  for (const edge of PREVIEW_EDGES) {
    const preview = productPreviewPath(product.images[0].src, product.sourceHash, edge);
    assert.ok(existsSync(path.join(output, preview.slice(1))));
    assert.ok(detail.includes(preview));
  }
  assert.deepEqual(readFileSync(path.join(output, product.images[0].src.slice(1))), readFileSync(image));
  for (const page of ['warenkorb', 'shopbedingungen', 'datenschutz', 'widerruf', 'versand-und-zahlung']) {
    const html = readFileSync(path.join(output, page, 'index.html'), 'utf8');
    assert.doesNotMatch(html, /test-api\.carmaja|test\.carmaja|vinted\./i);
  }
  const conditions = readFileSync(path.join(output, 'shopbedingungen/index.html'), 'utf8');
  assert.match(conditions, /10 Armbänder/);
  // Public shell has no data; the private production browser API remains opt-in.
  const manager = readFileSync(path.join(output, 'artikelverwaltung/index.html'), 'utf8');
  assert.match(manager, /Mit App-Zugang anmelden/);
  assert.match(manager, /name="password"/);
  assert.match(manager, /noindex/);
  assert.doesNotMatch(manager, /test-api\.carmaja|test\.carmaja|HANDY-TEST|Testumgebung/);
  console.log('CART_PRODUCTION_FIXTURE_BUILD_OK; artificial export removed, no deployment');
} finally {
  // Exact fixed worktree output created by this invocation; no server paths.
  rmSync(output, { recursive: true, force: true });
  rmSync(temporary, { recursive: true, force: true });
}
