import { createHash } from 'node:crypto';
import { execFileSync } from 'node:child_process';
import { existsSync, lstatSync, mkdirSync, readFileSync, readdirSync, realpathSync, writeFileSync } from 'node:fs';
import path from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';
import vm from 'node:vm';
import ts from 'typescript';
import * as legal from '../lib/legal-bundles.mjs';
import { hotfix } from './build-product-maintenance-hotfix.mjs';

export const websiteRoot = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
export const programs = Object.freeze([
  'ap3-worker.php', 'ap5-worker-cli.php', 'ap5-worker.php', 'bootstrap.php',
  'cart-commerce.php', 'cart-contract.php', 'cart-public.php', 'commerce-bootstrap.php',
  'commerce-core.php', 'commerce-worker.php', 'product-admin.php', 'product-api-diagnostics.php',
  'product-api-v2.php', 'product-api-v3.php', 'product-api-v4.php', 'product-api-v5.php',
  'product-api.php', 'production-backup.php', 'production-monitor.php',
  'shop-admin-cli.php', 'shop-admin.php', 'shop-checkout.php', 'shop-public.php',
  'stripe-contract.php', 'stripe-webhook.php', 'production-cart-cutover.php',
  'product-maintenance-guard.php',
  'product-browser.php', 'product-browser-http.php', 'product-price-list.php',
]);
// Preserve the already installed private maintenance marker when replacing the
// full program directory. Reuse only the guard transformation, never the
// historical V4 hash/version patch (already present in current main).
const guardedPrograms = new Set(['bootstrap.php', 'product-api.php', 'product-api-v2.php', 'product-api-v3.php']);
export const migrationIds = Object.freeze([
  'commerce-v1-schema', 'commerce-v1-ap4-shop', 'commerce-v1-ap5-admin',
  'commerce-v2-collections', 'commerce-v3-cart-inventory',
]);
export const digest = data => createHash('sha256').update(data).digest('hex');

export function approvedLegalBundle() {
  const source = readFileSync(path.join(websiteRoot, 'content/legal-bundles.ts'), 'utf8');
  const exports = {};
  vm.runInNewContext(ts.transpileModule(source, { compilerOptions: {
    module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2022,
  } }).outputText, { exports, require(name) {
    if (name === '@/lib/legal-bundles.mjs') return legal;
    if (name === '@/config/site-target') return { siteTarget: { name: 'production' } };
    throw new Error('Unexpected legal dependency');
  } });
  const draft = exports.cartProductionLegalDraft;
  const bundle = exports.getActiveLegalBundle();
  if (draft.contentHash !== '55b56a713077bf4697341392d1676c57667a198d371ab8e6f39b03e75a6c6501'
    || JSON.stringify(bundle.texts) !== JSON.stringify(draft.texts)
    || bundle.id !== draft.id || bundle.contentHash === draft.contentHash) {
    throw new Error('Approved review text or promotion binding changed');
  }
  legal.assertCheckoutLegalBundle(bundle, 'production');
  return bundle;
}

function sourceFile(relative) {
  const absolute = path.join(websiteRoot, relative);
  if (lstatSync(absolute).isSymbolicLink() || realpathSync(absolute) !== absolute) {
    throw new Error('Package source must be a regular repository file');
  }
  return readFileSync(absolute);
}

/** Only repository programs and public legal content; never reads runtime data. */
export function packageContents() {
  const files = new Map();
  for (const name of programs) {
    const source = name === 'product-maintenance-guard.php' ? `scripts/${name}` : `test-api-private/program/${name}`;
    const data = sourceFile(source);
    files.set(`private/program/${name}`, guardedPrograms.has(name)
      ? Buffer.from(hotfix(name, data.toString('utf8').replaceAll('\r\n', '\n'))) : data);
  }
  for (const name of ['worker.php', 'monitor.php', 'backup.php', 'cart-cutover.php']) {
    files.set(`private/${name}`, sourceFile(`test-api-private/${name}`));
  }
  for (const name of ['index.php', '.htaccess']) files.set(`public/${name}`, sourceFile(`production-shop-api-public/${name}`));
  const migrations = migrationIds.map(id => {
    const source = id === 'commerce-v1-schema' ? 'database/commerce-schema.sql' : `database/migrations/${id}.sql`;
    const sql = sourceFile(source).toString('utf8').replaceAll('\r\n', '\n');
    const target = `private/database/${id}.sql`;
    files.set(target, Buffer.from(sql));
    return { migrationId: id, path: target, fileSha256: digest(sql),
      journalSha256: digest(sql.replace(/^\s*--.*$/gm, '')) };
  });
  const bundle = approvedLegalBundle();
  files.set('private/legal/approved-cart.json', Buffer.from(JSON.stringify(bundle, null, 2) + '\n'));
  files.set('contract.json', sourceFile('config/production-shop-deployment.json'));
  files.set('cutover-template.json', sourceFile('config/production-cart-cutover-manifest.v3.json'));
  // No runtime template is an installable configuration. Installers must preserve
  // config/, vendor/, products, auth, backups, maintenance/ and all other private data.
  const manifest = {
    formatVersion: 1, releaseKind: 'cart-production', environment: 'production',
    sourceCommit: execFileSync('git', ['rev-parse', 'HEAD'], { cwd: websiteRoot, encoding: 'utf8' }).trim(),
    workingTreeDirty: execFileSync('git', ['status', '--porcelain', '--untracked-files=normal'], { cwd: websiteRoot, encoding: 'utf8' }).trim() !== '',
    deployAuthorized: false, migrationAuthorized: false, checkoutAuthorized: false,
    legalBundleId: bundle.id, legalBundleHash: bundle.contentHash,
    migrations,
    files: [...files].sort(([a], [b]) => a.localeCompare(b, 'en')).map(([name, data]) => ({
      path: name, bytes: data.length, sha256: digest(data),
    })),
  };
  files.set('manifest.json', Buffer.from(JSON.stringify(manifest, null, 2) + '\n'));
  return { files, manifest };
}

function walk(root, relative = '') {
  return readdirSync(path.join(root, relative), { withFileTypes: true }).flatMap(entry => {
    if (entry.isSymbolicLink()) throw new Error('Symlink in package');
    const name = relative ? `${relative}/${entry.name}` : entry.name;
    return entry.isDirectory() ? walk(root, name) : [name];
  });
}

export function verifyPackage(directory) {
  if (lstatSync(directory).isSymbolicLink()) throw new Error('Symlink package root');
  const expected = packageContents();
  if (JSON.stringify(walk(directory).sort()) !== JSON.stringify([...expected.files.keys()].sort())) {
    throw new Error('Unexpected or missing package file');
  }
  for (const [name, data] of expected.files) {
    if (!readFileSync(path.join(directory, name)).equals(data)) throw new Error('Package integrity mismatch');
  }
  return expected.manifest;
}

export function buildPackage(directory) {
  const target = path.resolve(directory);
  if (existsSync(target)) throw new Error('Package target already exists; never overwrite');
  const { files } = packageContents();
  mkdirSync(target, { recursive: true });
  for (const [name, data] of files) {
    mkdirSync(path.dirname(path.join(target, name)), { recursive: true });
    writeFileSync(path.join(target, name), data, { flag: 'wx', mode: 0o640 });
  }
  return verifyPackage(target);
}

if (process.argv[1] && import.meta.url === pathToFileURL(process.argv[1]).href) {
  try {
    const args = process.argv.slice(2);
    if (args.length !== 2 || !['build', 'verify'].includes(args[0])) throw new Error('Use build|verify <local-directory>');
    const manifest = args[0] === 'build' ? buildPackage(args[1]) : verifyPackage(args[1]);
    console.log(`PRODUCTION_CART_PACKAGE_OK files=${manifest.files.length}; deployment and cutover remain blocked`);
  } catch {
    console.error('Production package rejected: check local inputs and package integrity; no runtime accessed.');
    process.exitCode = 1;
  }
}
