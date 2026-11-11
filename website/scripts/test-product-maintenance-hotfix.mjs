import { execFileSync } from 'node:child_process';
import { mkdtempSync, mkdirSync, writeFileSync, readFileSync, rmSync, existsSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join, resolve } from 'node:path';
import { baseline, build, tracked } from './build-product-maintenance-hotfix.mjs';

const php = process.env.CARMAJA_TEST_PHP;
const extensionDir = process.env.CARMAJA_TEST_PHP_EXT;
if (!php || !extensionDir) throw new Error('Explicit test PHP required');
const args = ['-n', '-d', `extension_dir=${extensionDir}`];
const builtIn = JSON.parse(execFileSync(php, ['-n', '-r', 'echo json_encode(array_map("strtolower", get_loaded_extensions()));'], { encoding: 'utf8' }));
// Windows includes iconv/PDO in the binary; Linux may load them as shared modules.
const requiredExtensions = ['iconv', 'mbstring', 'gd', 'exif', 'sodium', 'pdo', 'pdo_sqlite'];
for (const extension of requiredExtensions) {
  if (!builtIn.includes(extension)) args.push('-d', `extension=${extension}`);
}
const active = JSON.parse(execFileSync(php, [...args, '-r', 'echo json_encode(array_map("strtolower", get_loaded_extensions()));'], { encoding: 'utf8' }));
if (requiredExtensions.some((extension) => !active.includes(extension))) throw new Error('Required synthetic-test PHP extension missing');
const root = mkdtempSync(join(tmpdir(), 'carmaja-product-maintenance-'));
const packageRoot = join(root, 'package');
const fixture = join(root, 'website');
const program = join(fixture, 'test-api-private/program');
const testDir = join(fixture, 'tests/php');
const baseCommit = 'ab3f2d30cd214d06a11ad96d3fe211cd9f282cd3';
try {
  build(packageRoot);
  mkdirSync(program, { recursive: true });
  mkdirSync(testDir, { recursive: true });
  if (!process.argv.includes('--bootstrap-only')) {
  for (const name of ['product-api.php', 'product-api-v2.php', 'product-api-v3.php', 'product-api-v4.php', 'product-maintenance-guard.php']) {
    const text = readFileSync(join(packageRoot, name), 'utf8');
    writeFileSync(join(program, name), text);
    execFileSync(php, [...args, '-l', join(program, name)], { stdio: 'inherit' });
  }
  execFileSync(php, [...args, '-l', join(packageRoot, 'bootstrap.php')], { stdio: 'inherit' });
  // Match the installed image/description contract, not the older V4 source date.
  let test = tracked(baseline['product-api-v3.php'], 'website/tests/php/product-api-test.php');
  for (const name of ['bootstrap.php', 'product-admin.php', 'product-api-diagnostics.php']) {
    writeFileSync(join(program, name), tracked(baseCommit, `website/test-api-private/program/${name}`));
  }
  const current = readFileSync(resolve('website/tests/php/product-api-test.php'), 'utf8').replaceAll('\r\n', '\n');
  const start = "carmaja_api_test('V4 veröffentlicht, archiviert und stellt dieselbe Kollektion idempotent wieder her'";
  const end = "carmaja_api_test('V2 kann ein auf V3 angehobenes Produkt nicht überschreiben'";
  const begin = test.indexOf(start), finish = test.indexOf(end, begin);
  const currentBegin = current.indexOf(start), currentEnd = current.indexOf(end, currentBegin);
  if ([begin, finish, currentBegin, currentEnd].some((i) => i < 0)) throw new Error('Test anchors missing');
  test = test.slice(0, begin) + current.slice(currentBegin, currentEnd) + test.slice(finish);
  writeFileSync(join(testDir, 'product-api-test.php'), test);
  // The same enhanced lifecycle checks must first fail against the uncorrected code.
  const fixed = readFileSync(join(program, 'product-api-v4.php'));
  writeFileSync(join(program, 'product-api-v4.php'), tracked(baseCommit, 'website/test-api-private/program/product-api-v4.php'));
  let reproduced = false;
  try {
    execFileSync(php, [...args, join(testDir, 'product-api-test.php')], { stdio: 'pipe', maxBuffer: 4 * 1024 * 1024 });
  } catch (error) {
    const output = String(error.stdout ?? '') + String(error.stderr ?? '');
    reproduced = output.includes('Gespeicherter Lebenszyklus-Hash ist veraltet');
  }
  if (!reproduced) throw new Error('Expected baseline regression not reproduced');
  console.log('Original installed lifecycle bug reproduced with artificial data');
  writeFileSync(join(program, 'product-api-v4.php'), fixed);
  execFileSync(php, [...args, join(testDir, 'product-api-test.php')], { stdio: 'inherit' });
  execFileSync(php, [...args, resolve('website/tests/php/product-maintenance-guard-test.php'), fixture], { stdio: 'inherit' });
  const original = join(root, 'original');
  mkdirSync(original);
  for (const [name, commit] of Object.entries(baseline)) {
    writeFileSync(join(original, name), tracked(commit, `website/test-api-private/program/${name}`));
  }
  execFileSync(php, [...args, '-l', resolve('website/scripts/activate-product-maintenance-hotfix.php')], { stdio: 'inherit' });
  execFileSync(php, [...args, resolve('website/tests/php/product-maintenance-activation-test.php'), root], { stdio: 'inherit' });
  }
  // Exercise the modified bootstrap with its own historical compatibility suite.
  const bootstrapBase = baseline['bootstrap.php'];
  const paths = execFileSync('git', ['ls-tree', '-r', '--name-only', bootstrapBase, 'website/test-api-private/program'], { encoding: 'utf8' }).trim().split('\n');
  for (const path of paths.filter((name) => name.endsWith('.php'))) {
    const destination = join(root, path);
    if (!existsSync(destination)) {
      mkdirSync(resolve(destination, '..'), { recursive: true });
      writeFileSync(destination, tracked(bootstrapBase, path));
    }
  }
  for (const name of [...Object.keys(baseline), 'product-maintenance-guard.php']) {
    writeFileSync(join(program, name), readFileSync(join(packageRoot, name)));
  }
  mkdirSync(join(fixture, 'test-api-public'), { recursive: true });
  writeFileSync(join(fixture, 'test-api-public/index.php'), tracked(bootstrapBase, 'website/test-api-public/index.php'));
  writeFileSync(join(fixture, 'test-api-public/.htaccess'), tracked(bootstrapBase, 'website/test-api-public/.htaccess'));
  writeFileSync(join(testDir, 'product-bootstrap-test.php'), tracked(bootstrapBase, 'website/tests/php/product-bootstrap-test.php'));
  execFileSync(php, [...args, join(testDir, 'product-bootstrap-test.php')], { stdio: 'inherit' });
  console.log('Pinned production compatibility, guard and cleanup passed');
} finally {
  if (!resolve(root).startsWith(resolve(tmpdir()) + '\\') && !resolve(root).startsWith(resolve(tmpdir()) + '/')) {
    throw new Error('Unexpected cleanup path');
  }
  rmSync(root, { recursive: true });
}
