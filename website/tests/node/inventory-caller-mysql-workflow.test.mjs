import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';

test('inventory proof is a read-only-permission, isolated CI workflow without live secrets or deployment', () => {
  const workflow = readFileSync(new URL('../../../.github/workflows/inventory-caller-mysql.yml', import.meta.url), 'utf8');
  assert.match(workflow, /contents: read/);
  assert.match(workflow, /persist-credentials: false/);
  assert.match(workflow, /CMJ_CI_SCENARIO: inventory/);
  assert.match(workflow, /codex\/cart-inventory-caller/);
  assert.match(workflow, /if: always\(\)/);
  assert.match(workflow, /model4-caller-report\.json/);
  assert.doesNotMatch(workflow, /secrets\.|ssh |sftp |gh pr merge|environment:/);
});

test('native inventory runner exercises real restore, native locking and SIGKILL on artificial targets', () => {
  const script = readFileSync(new URL('../../scripts/inventory-caller-mysql-ci.php', import.meta.url), 'utf8');
  for (const token of ['ip_fixture', 'cmj_icp_restore(...)', 'SIGKILL', '1213', '1205', 'inventory_both_databases_finally_empty',
    'inventory_no_reset_after_later_adjustment', 'inventory_commit_survives_crash', 'inventory_runtime_and_sources_preserved']) assert.ok(script.includes(token), token);
  assert.doesNotMatch(script, /carmaja-production-ionos|carmaja-test-ionos|\/home\/www/);
  const runner = readFileSync(new URL('../../scripts/model4-caller-mysql-ci.sh', import.meta.url), 'utf8');
  for (const token of ['127.0.0.1:13306:3306', 'require-secure-transport=ON', 'carmaja.ci-owner', 'trap cleanup EXIT']) assert.ok(runner.includes(token), token);
});
