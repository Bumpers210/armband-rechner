import { createHash } from 'node:crypto';
import { execFileSync } from 'node:child_process';
import { existsSync, lstatSync, mkdirSync, readFileSync, realpathSync, writeFileSync } from 'node:fs';
import path from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';
import { toolSources as model4Sources } from './prepare-product-model4-tool.mjs';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
export const toolSources = Object.freeze({ ...model4Sources,
  'inventory-count-production.php': 'scripts/inventory-count-production.php',
  'cart-commerce.php': 'test-api-private/program/cart-commerce.php',
  'production-cart-cutover.php': 'test-api-private/program/production-cart-cutover.php',
});

/** Code-only local artifact; never reads a runtime, source data or secrets. */
export function buildInventoryTool(output) {
  const target = path.resolve(output);
  if (existsSync(target)) throw new Error('New local target required');
  const files = new Map();
  for (const [name, relative] of Object.entries(toolSources)) {
    const source = path.join(root, relative);
    if (lstatSync(source).isSymbolicLink() || realpathSync(source) !== source) throw new Error('Regular source required');
    files.set(name, readFileSync(source));
  }
  const manifest = {
    format: 'inventory-private-release-v1',
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
    const result = buildInventoryTool(process.argv[2]);
    console.log(`INVENTORY_TOOL_BUILT files=${Object.keys(result.toolFiles).length}; clean=${!result.workingTreeDirty}; execution not approved`);
  } catch {
    console.error('INVENTORY_TOOL_REFUSED: no server or runtime accessed.');
    process.exitCode = 1;
  }
}
