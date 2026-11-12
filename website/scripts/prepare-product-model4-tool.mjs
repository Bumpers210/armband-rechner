import { createHash } from 'node:crypto';
import { execFileSync } from 'node:child_process';
import { existsSync, lstatSync, mkdirSync, readFileSync, realpathSync, writeFileSync } from 'node:fs';
import path from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
export const toolSources = Object.freeze({
  'product-model4-production.php': 'scripts/product-model4-production.php',
  'product-model4-migration.php': 'scripts/product-model4-migration.php',
  'product-model4-preparation.php': 'scripts/product-model4-preparation.php',
  'product-api.php': 'test-api-private/program/product-api.php',
  'product-api-v2.php': 'test-api-private/program/product-api-v2.php',
  'product-api-v3.php': 'test-api-private/program/product-api-v3.php',
  'cart-contract.php': 'test-api-private/program/cart-contract.php',
  'production-backup.php': 'test-api-private/program/production-backup.php',
});

/** Local code artifact only. No staging/upload, runtime, plan, approval or backup. */
export function buildModel4Tool(output) {
  const target = path.resolve(output);
  if (existsSync(target)) throw new Error('New local target required');
  const files = new Map();
  for (const [name, relative] of Object.entries(toolSources)) {
    const source = path.join(root, relative);
    if (lstatSync(source).isSymbolicLink() || realpathSync(source) !== source) throw new Error('Regular source required');
    files.set(name, readFileSync(source));
  }
  const manifest = {
    format: 'model4-private-release-v1',
    sourceCommit: execFileSync('git', ['rev-parse', 'HEAD'], { cwd: root, encoding: 'utf8' }).trim(),
    workingTreeDirty: execFileSync('git', ['status', '--porcelain', '--untracked-files=normal'], { cwd: root, encoding: 'utf8' }).trim() !== '',
    deploymentAuthorized: false,
    toolFiles: Object.fromEntries([...files].map(([name, data]) => [name, createHash('sha256').update(data).digest('hex')])),
  };
  mkdirSync(target, { mode: 0o700 });
  for (const [name, data] of files) writeFileSync(path.join(target, name), data, { flag: 'wx', mode: 0o600 });
  writeFileSync(path.join(target, 'release.json'), JSON.stringify(manifest, null, 2) + '\n', { flag: 'wx', mode: 0o600 });
  return manifest;
}

if (process.argv[1] && import.meta.url === pathToFileURL(process.argv[1]).href) {
  try {
    if (process.argv.length !== 3) throw new Error('One new local output directory required');
    const result = buildModel4Tool(process.argv[2]);
    console.log(`MODEL4_TOOL_BUILT files=${Object.keys(result.toolFiles).length}; clean=${!result.workingTreeDirty}; execution not approved`);
  } catch {
    console.error('MODEL4_TOOL_REFUSED: no server or runtime accessed.');
    process.exitCode = 1;
  }
}
