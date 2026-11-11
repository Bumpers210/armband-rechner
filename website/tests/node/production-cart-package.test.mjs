import assert from 'node:assert/strict';
import { mkdtempSync, readFileSync, rmSync, writeFileSync } from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import test from 'node:test';
import { approvedLegalBundle, buildPackage, packageContents, programs, verifyPackage, websiteRoot } from '../../scripts/prepare-production-cart-package.mjs';

test('Complete deterministic cart production package excludes runtime and test-only administration', () => {
  const a = packageContents(); const b = packageContents();
  assert.deepEqual(a.manifest, b.manifest);
  assert.equal(a.manifest.deployAuthorized, false);
  assert.equal(a.manifest.migrationAuthorized, false);
  assert.equal(a.manifest.checkoutAuthorized, false);
  for (const name of ['bootstrap.php','product-api-v5.php','cart-commerce.php','cart-public.php','production-backup.php','production-cart-cutover.php']) {
    assert.ok(a.files.has(`private/program/${name}`));
  }
  for (const name of a.files.keys()) {
    assert.doesNotMatch(name, /runtime-config|\.htpasswd|\.env|vendor\/|uploads\/|auth\/|backups\/|maintenance\/|product-browser|test-runtime|ap3b-async/);
  }
  for (const name of ['public/index.php', 'public/.htaccess', 'contract.json']) {
    assert.doesNotMatch(a.files.get(name).toString(), /carmaja-private-test|test-api\.carmaja/);
  }
  // Shared programs may contain explicit test branches, never an active test
  // configuration. Every static private include must be available in the package.
  for (const name of programs) {
    const source = a.files.get(`private/program/${name}`).toString();
    for (const match of source.matchAll(/require_once __DIR__ \. '\/([^']+\.php)'/g)) {
      assert.ok(programs.includes(match[1]), `${name} -> ${match[1]}`);
    }
  }
});

test('Full private package retains maintenance guards without reapplying the historical V4 patch', () => {
  const { files } = packageContents();
  const program = name => files.get(`private/program/${name}`).toString();
  for (const name of ['bootstrap.php', 'product-api.php']) {
    assert.match(program(name), /require_once __DIR__ \. '\/product-maintenance-guard\.php'/);
  }
  assert.match(program('bootstrap.php'), /function carmaja_bootstrap_route_request\(\): never\s*\{\s*carmaja_product_maintenance_assert_http/);
  assert.match(program('product-api.php'), /carmaja_product_maintenance_assert_lock\(\$name\)/);
  for (const name of ['product-api.php', 'product-api-v2.php', 'product-api-v3.php']) {
    assert.match(program(name), /carmaja_product_maintenance_assert\('publisher'\)/);
  }
  assert.match(program('product-maintenance-guard.php'), /dirname\(__DIR__\).*\/maintenance/);
  for (const name of ['product-api-v4.php', 'product-api-v5.php', 'ap3-worker.php', 'production-backup.php']) {
    assert.deepEqual(files.get(`private/program/${name}`), readFileSync(path.join(websiteRoot, 'test-api-private/program', name)));
  }
});

test('Package verification rejects modified, extra, missing files and overwrite', () => {
  const root = mkdtempSync(path.join(os.tmpdir(), 'cmj-package-'));
  const target = path.join(root, 'candidate');
  try {
    buildPackage(target); verifyPackage(target);
    assert.throws(() => buildPackage(target), /already exists/);
    const entry = path.join(target, 'public/index.php'); const original = readFileSync(entry);
    writeFileSync(entry, 'tampered');
    assert.throws(() => verifyPackage(target), /integrity/);
    writeFileSync(entry, original);
    writeFileSync(path.join(target, 'private/runtime-config.php'), '<?php return [];');
    assert.throws(() => verifyPackage(target), /Unexpected/);
    rmSync(path.join(target, 'private/runtime-config.php'));
    rmSync(entry);
    assert.throws(() => verifyPackage(target), /missing/);
  } finally { rmSync(root, { recursive: true, force: true }); }
});

test('Production runtime, release contract and reviewed legal texts agree while all gates remain closed', () => {
  const contract = JSON.parse(readFileSync(path.join(websiteRoot, 'config/production-shop-deployment.json')));
  const template = JSON.parse(readFileSync(path.join(websiteRoot, 'config/production-cart-cutover-manifest.v3.json')));
  const legal = approvedLegalBundle();
  assert.equal(legal.id, contract.shop.legalBundleId);
  assert.equal(legal.id, template.legalBundleId);
  assert.equal(legal.contentHash, 'ed7713ce8d6c9f16b320f1376303df7f38c306177793a9c5beeb548b9455d752');
  assert.equal(template.status, 'not_approved');
  assert.deepEqual(template.selectedProducts, []);
  assert.ok(Object.values(contract.guards).every(flag => flag === false));
  assert.equal(contract.shop.apiVersion, 3);
  assert.equal(contract.shop.productApiVersion, 5);
  assert.equal(contract.shop.maxOrderQuantity, 10);
});

test('CI only builds and verifies; old API deployment and automatic publisher path are blocked', () => {
  const workflows = path.resolve(websiteRoot, '../.github/workflows');
  const build = readFileSync(path.join(workflows, 'production-cart-package.yml'), 'utf8');
  assert.match(build, /workflow_dispatch:/);
  assert.match(build, /pull_request:/);
  assert.match(build, /actions\/upload-artifact/);
  assert.match(build, /persist-credentials: false\s+# [^\n]+\s+fetch-depth: 0/);
  assert.match(build, /product-maintenance-activation-test\.php\|\*\/product-maintenance-guard-test\.php\) continue/);
  assert.match(build, /CARMAJA_TEST_PHP="\$\(command -v php\)"[\s\S]*node website\/scripts\/test-product-maintenance-hotfix\.mjs/);
  assert.match(build, /node website\/scripts\/test-production-cart-maintenance\.mjs/);
  assert.doesNotMatch(build, /secrets\.|environment:|\bssh\b|\bscp\b|git push/);
  assert.match(build, /test "\$RUNNER_ENVIRONMENT" = github-hosted/);
  assert.match(build, /test ! -e "\$fixture_root"/);
  assert.match(build, /test ! -L "\$fixture_root"/);
  assert.match(build, /install -d -m 0700/);
  assert.match(build, /trap 'sudo rmdir "\$fixture_root"' EXIT/);
  assert.match(readFileSync(path.join(workflows, 'deploy-production-api.yml'), 'utf8'), /Block obsolete seven-file[\s\S]*?exit 1/);
  assert.match(readFileSync(path.join(workflows, 'deploy-website.yml'), 'utf8'), /guards\.automaticWebsiteDeployment!==true/);
});
