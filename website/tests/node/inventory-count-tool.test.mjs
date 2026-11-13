import test from 'node:test';
import assert from 'node:assert/strict';
import { createHash } from 'node:crypto';
import { mkdtempSync, readFileSync, readdirSync, rmSync } from 'node:fs';
import { tmpdir } from 'node:os';
import path from 'node:path';
import { buildInventoryTool, toolSources } from '../../scripts/prepare-inventory-count-tool.mjs';

test('inventory tool is exact, deterministic, code-only and refuses overwrite', () => {
  const temporary = mkdtempSync(path.join(tmpdir(), 'cmj-inventory-package-'));
  try {
    const a = buildInventoryTool(path.join(temporary, 'one'));
    assert.deepEqual(a, buildInventoryTool(path.join(temporary, 'two')));
    assert.equal(a.deploymentAuthorized, false);
    assert.equal(typeof a.workingTreeDirty, 'boolean');
    assert.equal(Object.keys(a.toolFiles).length, 12);
    assert.deepEqual(readdirSync(path.join(temporary, 'one')).sort(), [...Object.keys(toolSources), 'release.json'].sort());
    for (const [name, hash] of Object.entries(a.toolFiles)) {
      assert.equal(createHash('sha256').update(readFileSync(path.join(temporary, 'one', name))).digest('hex'), hash);
      assert.ok(!/runtime-config|approval|manifest|proof|credential/.test(name));
    }
    assert.throws(() => buildInventoryTool(path.join(temporary, 'one')), /New local target/);
  } finally { rmSync(temporary, { recursive: true, force: true }); }
});

test('inventory caller has no public entry, runtime override or activation', () => {
  const root = new URL('../../', import.meta.url);
  for (const file of ['scripts/prepare-production-cart-package.mjs', 'test-api-private/program/bootstrap.php']) {
    assert.doesNotMatch(readFileSync(new URL(file, root), 'utf8'), /inventory-count-production/);
  }
  const caller = readFileSync(new URL('scripts/inventory-count-production.php', root), 'utf8');
  assert.match(caller, /private_cli_path_required/);
  assert.match(caller, /cmj_m4p_connect\(\$config/);
  assert.match(caller, /REPEATABLE READ/);
  assert.match(caller, /microtime\(true\) \+ 40/);
  assert.match(caller, /SET SESSION time_zone = '\+00:00'/);
  assert.doesNotMatch(caller, /shell_exec\(|proc_open\(|curl_|StripeClient|->create\(/);
  const main = caller.slice(caller.indexOf('function cmj_icp_main('));
  assert.ok(main.indexOf('cmj_icp_load_dependencies(') < main.indexOf('$raw ='));
  assert.doesNotMatch(main, /getenv\(|\$_ENV|\$_GET|\$_POST/);
});
