import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';

const website = new URL('../../', import.meta.url);
const read = name => readFileSync(new URL(name, website), 'utf8');

test('model4 MySQL proof is disposable, secret-free and not a deployment', () => {
  const workflow = read('../.github/workflows/model4-caller-mysql.yml');
  const shell = read('scripts/model4-caller-mysql-ci.sh');
  const php = read('scripts/model4-caller-mysql-ci.php');
  assert.match(workflow, /contents: read/);
  assert.match(workflow, /persist-credentials: false/);
  assert.match(workflow, /if: always\(\)/);
  assert.doesNotMatch(workflow, /secrets\.|environment:|ssh|sftp|deploy-/);
  assert.match(shell, /127\.0\.0\.1:13306:3306/);
  assert.match(shell, /require-secure-transport=ON/);
  assert.match(shell, /carmaja\.ci-owner/);
  assert.match(shell, /realpath "\$root"/);
  assert.match(shell, /trap cleanup EXIT/);
  assert.match(php, /loopback_only/);
  assert.match(php, /cmj_m4p_restore_proof\(\.\.\.\)/);
  assert.match(php, /cmj_m4p_quiesce\(/);
  assert.match(php, /both_databases_finally_empty/);
  assert.doesNotMatch(php, /carmaja-private-shop\/config|shop-cart-test\.json|carmaja-production-ionos/);
});

test('real CLI and integration suite share the actual restore and guard ports', () => {
  const caller = read('scripts/product-model4-production.php');
  assert.match(caller, /function cmj_m4p_restore_proof\(/);
  assert.match(caller, /function cmj_m4p_quiesce\(/);
  const main = caller.slice(caller.indexOf('function cmj_m4p_main('));
  assert.match(main, /cmj_m4p_restore_proof\(\.\.\.\)/);
  assert.match(main, /cmj_m4p_quiesce\(/);
  assert.match(main, /private_cli_path_required/);
  assert.doesNotMatch(main, /getenv|RUNNER_TEMP|GITHUB_ACTIONS/);
});
