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
  assert.match(workflow, /php website\/tests\/php\/product-model4-package-start-test\.php "\$RUNNER_TEMP\/model4-tool"/);
  assert.match(workflow, /if: always\(\)/);
  assert.doesNotMatch(workflow, /secrets\.|environment:|ssh|sftp|deploy-/);
  assert.match(shell, /127\.0\.0\.1:13306:3306/);
  assert.match(shell, /require-secure-transport=ON/);
  assert.match(shell, /--default-time-zone=\+02:00/);
  assert.match(shell, /carmaja\.ci-owner/);
  assert.match(shell, /realpath "\$root"/);
  assert.match(shell, /trap cleanup EXIT/);
  assert.match(php, /loopback_only/);
  assert.match(php, /cmj_m4p_restore_proof\(\.\.\.\)/);
  assert.match(php, /cmj_m4p_quiesce\(/);
  assert.match(php, /both_databases_finally_empty/);
  assert.match(php, /fresh_callback_inherits_nonutc/);
  assert.match(php, /microsecond_mismatch_still_rejected/);
  assert.doesNotMatch(php, /carmaja-private-shop\/config|shop-cart-test\.json|carmaja-production-ionos/);
});

test('UTC regression branch is explicitly allowed at every isolated CI boundary', () => {
  for (const file of ['../.github/workflows/model4-caller-mysql.yml', 'scripts/model4-caller-mysql-ci.sh', 'scripts/model4-caller-mysql-ci.php']) {
    const content = read(file);
    assert.match(content, /refs\/heads\/codex\/model4-restore-utc/);
    assert.match(content, /codex\/cart-model4-preparation/);
    assert.match(content, /Bumpers210\/armband-rechner/);
  }
  assert.match(read('../.github/workflows/model4-caller-mysql.yml'), /php website\/tests\/php\/product-model4-restore-utc-test\.php/);
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

test('regular PR package validation also requires the isolated model4 cold start', () => {
  const workflow = read('../.github/workflows/production-cart-package.yml');
  assert.match(workflow, /node website\/scripts\/prepare-product-model4-tool\.mjs "\$RUNNER_TEMP\/model4-cold-package"/);
  assert.match(workflow, /php website\/tests\/php\/product-model4-package-start-test\.php "\$RUNNER_TEMP\/model4-cold-package"/);
  assert.match(workflow, /\*\/product-model4-package-start-test\.php\) continue/);
});
