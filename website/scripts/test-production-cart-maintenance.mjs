// Exercise the actual current release bytes, never a runtime or server path.
import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import { mkdtempSync, mkdirSync, readFileSync, rmSync, writeFileSync, existsSync } from 'node:fs';
import { tmpdir } from 'node:os';
import path from 'node:path';
import { packageContents, websiteRoot } from './prepare-production-cart-package.mjs';

const php = process.env.CARMAJA_TEST_PHP;
const extensionDir = process.env.CARMAJA_TEST_PHP_EXT;
if (!php || !extensionDir) throw new Error('Explicit local test PHP required');
const args = ['-n', '-d', `extension_dir=${extensionDir}`];
const builtIn = JSON.parse(execFileSync(php, ['-n', '-r', 'echo json_encode(array_map("strtolower", get_loaded_extensions()));'], { encoding: 'utf8' }));
const required = ['iconv', 'mbstring', 'gd', 'exif', 'sodium', 'pdo', 'pdo_sqlite'];
for (const extension of required) if (!builtIn.includes(extension)) args.push('-d', `extension=${extension}`);
const active = JSON.parse(execFileSync(php, [...args, '-r', 'echo json_encode(array_map("strtolower", get_loaded_extensions()));'], { encoding: 'utf8' }));
if (required.some(extension => !active.includes(extension))) throw new Error('Required test extension missing');

const root = mkdtempSync(path.join(tmpdir(), 'cmj-current-package-'));
function write(relative, data) {
  const target = path.join(root, relative);
  mkdirSync(path.dirname(target), { recursive: true });
  writeFileSync(target, data, { flag: 'wx' });
}
function run(test, extra = []) {
  // The compatibility suite includes password hashing and high-resolution JPEG
  // checks; allow slow Windows runners without relaxing any business timeout.
  execFileSync(php, [...args, test, ...extra], { stdio: 'inherit', timeout: 600000 });
}
try {
  const { files } = packageContents();
  for (const [name, bytes] of files) {
    if (name.startsWith('private/program/')) write(name.replace('private/', 'test-api-private/'), bytes);
  }
  for (const name of ['product-api-test.php', 'product-bootstrap-test.php']) {
    write(`tests/php/${name}`, readFileSync(path.join(websiteRoot, 'tests/php', name)));
  }
  for (const name of ['index.php', '.htaccess']) {
    write(`test-api-public/${name}`, readFileSync(path.join(websiteRoot, 'test-api-public', name)));
  }
  // Marker absent: existing API, V5, hash and bootstrap compatibility retained.
  run(path.join(root, 'tests/php/product-api-test.php'));
  run(path.join(root, 'tests/php/product-bootstrap-test.php'));
  // Marker present: direct writes/publish and actual current HTTP entry blocked.
  run(path.join(websiteRoot, 'tests/php/product-maintenance-guard-test.php'), [root]);
  run(path.join(websiteRoot, 'tests/fixtures/production-cart-maintenance.php'), [root]);
  assert.equal(existsSync(path.join(root, 'test-api-private/maintenance')), false);
  console.log('CURRENT_PACKAGE_MAINTENANCE_OK; artificial fixture only');
} finally {
  const relative = path.relative(path.resolve(tmpdir()), root);
  if (relative.startsWith('..') || path.isAbsolute(relative) || !relative.startsWith('cmj-current-package-')) {
    throw new Error('Unsafe fixture cleanup path');
  }
  rmSync(root, { recursive: true });
}
assert.equal(existsSync(root), false);
console.log('CURRENT_PACKAGE_CLEANUP_OK');
