import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';

const read = name => readFileSync(new URL(name, import.meta.url), 'utf8').replaceAll('\r\n', '\n');
const workflow = read('../../../.github/workflows/cart-package-mysql-test.yml');
const shell = read('../../scripts/cart-package-mysql-ci.sh');
const php = read('../../scripts/cart-package-mysql-ci.php');

test('isolated workflow has no secrets, deployment permissions or production connection', () => {
  assert.match(workflow, /branches: \[codex\/cart-historical-schema\]/);
  assert.match(workflow, /contents: read/);
  assert.match(workflow, /runs-on: ubuntu-24\.04/);
  assert.doesNotMatch(workflow, /secrets\.|contents: write|id-token: write|environment:|ssh |sftp |scp |deploy-.*\.sh/);
  assert.match(workflow, /PACKAGE_SOURCE_SHA: \$\{\{ github.sha \}\}/);
  assert.match(workflow, /ref: \$\{\{ env.PACKAGE_SOURCE_SHA \}\}/);
  assert.match(workflow, /ref: \$\{\{ github.sha \}\}/);
  assert.match(workflow, /production-cart-history-test.php/);
  assert.match(workflow, /if: always\(\)/);
  assert.match(workflow, /path: \$\{\{ runner.temp \}\}\/cart-package-mysql-report.json/);
});
test('database fixture is loopback-only, ephemeral and TLS-required', () => {
  assert.match(shell, /RUNNER_ENVIRONMENT.*github-hosted/);
  assert.match(shell, /GITHUB_REF.*refs\/heads\/codex\/cart-historical-schema/);
  assert.match(shell, /PACKAGE_SOURCE_SHA.*GITHUB_SHA/);
  assert.match(php, /getenv\('PACKAGE_SOURCE_SHA'\) === getenv\('GITHUB_SHA'\)/);
  assert.match(shell, /--publish 127\.0\.0\.1:13306:3306/);
  assert.match(shell, /--tmpfs \/var\/lib\/mysql/);
  assert.match(shell, /--require-secure-transport=ON/);
  assert.match(shell, /MYSQL_ROOT_PASSWORD_FILE=/);
  assert.doesNotMatch(shell, /MYSQL_ROOT_PASSWORD=|--password=|set -x|docker logs/);
  assert.match(shell, /trap cleanup EXIT/);
  assert.match(shell, /carmaja.ci-owner/);
  assert.match(shell, /realpath "\$root"/);
  assert.doesNotMatch(php, /carmaja-test-ionos|hosting-data\.io|dbs15962244|dbs15962566/);
});
test('actual packaged programs, real migration and full restore are tested', () => {
  assert.match(php, /require_once \$package .*production-cart-cutover\.php/);
  assert.match(php, /require_once \$package .*production-backup\.php/);
  assert.match(php, /MYSQL_ATTR_SSL_VERIFY_SERVER_CERT => true/);
  assert.match(php, /mysql8_server/);
  assert.match(php, /SIGKILL/);
  assert.match(php, /ten_concurrent_idempotent_imports/);
  assert.match(php, /historical_single_item_quantity_one/);
  assert.match(php, /migrateHistoricalBaseline\(\$source, \$package, \$release\)/);
  assert.match(php, /ap6-commerce-schema\.sql/);
  assert.match(php, /original_journal_checksums_and_timestamps_preserved/);
  assert.match(php, /historical_upgrade_schema_equals_fresh_schema/);
  assert.match(php, /historical_journal_preserved_after_crash_and_ten_imports/);
  assert.match(php, /post_restore_compare_repeat_/);
  assert.match(php, /manifest_hmac_tamper_rejected/);
  assert.match(php, /both_empty/);
  assert.match(php, /Never emit exception messages from PDO/);
});
