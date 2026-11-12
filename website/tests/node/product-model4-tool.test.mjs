import test from 'node:test';
import assert from 'node:assert/strict';
import { createHash } from 'node:crypto';
import { mkdtempSync, readFileSync, readdirSync, rmSync } from 'node:fs';
import { tmpdir } from 'node:os';
import path from 'node:path';
import { buildModel4Tool, toolSources } from '../../scripts/prepare-product-model4-tool.mjs';

test('private model4 tool is exact, deterministic, code-only and refuses overwrite', () => {
  const temporary = mkdtempSync(path.join(tmpdir(), 'cmj-model4-package-'));
  try {
    const a = buildModel4Tool(path.join(temporary, 'one'));
    const b = buildModel4Tool(path.join(temporary, 'two'));
    assert.deepEqual(a, b);
    assert.equal(a.deploymentAuthorized, false);
    assert.equal(typeof a.workingTreeDirty, 'boolean');
    assert.match(a.sourceCommit, /^[0-9a-f]{40}$/);
    assert.equal(Object.keys(a.toolFiles).length, 9);
    assert.equal(toolSources['commerce-core.php'], 'test-api-private/program/commerce-core.php');
    assert.deepEqual(readdirSync(path.join(temporary, 'one')).sort(), [...Object.keys(toolSources), 'release.json'].sort());
    for (const [name, hash] of Object.entries(a.toolFiles)) {
      assert.equal(createHash('sha256').update(readFileSync(path.join(temporary, 'one', name))).digest('hex'), hash);
      assert.ok(!/runtime-config|approval|manifest|proof|credential/.test(name));
    }
    assert.throws(() => buildModel4Tool(path.join(temporary, 'one')), /New local target/);
  } finally { rmSync(temporary, { recursive: true, force: true }); }
});

test('model4 caller stays outside active cart package, routes and workflows', () => {
  const root = new URL('../../', import.meta.url);
  for (const file of ['scripts/prepare-production-cart-package.mjs', 'test-api-private/program/bootstrap.php']) {
    assert.doesNotMatch(readFileSync(new URL(file, root), 'utf8'), /product-model4-production/);
  }
  const caller = readFileSync(new URL('scripts/product-model4-production.php', root), 'utf8');
  assert.match(caller, /private_cli_path_required/);
  assert.match(caller, /Ssl_cipher/); assert.match(caller, /Ssl_version/);
  assert.match(caller, /innodb_lock_wait_timeout = 3/);
  assert.match(caller, /MYSQL_ATTR_LOCAL_INFILE => false/);
  assert.doesNotMatch(caller, /shell_exec\(|proc_open\(|curl_|StripeClient|->create\(/);
  const main = caller.slice(caller.indexOf('function cmj_m4p_main('));
  assert.match(main, /cmj_m4p_load_dependencies\(\$paths\['tool'\], \$release\)/);
  assert.ok(main.indexOf('cmj_m4p_load_dependencies(') < main.indexOf('$rawConfig ='));
});
