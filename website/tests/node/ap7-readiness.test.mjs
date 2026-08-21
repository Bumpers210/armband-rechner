import assert from "node:assert/strict";
import { createHash } from "node:crypto";
import { readFile } from "node:fs/promises";
import path from "node:path";
import test from "node:test";

const projectRoot = process.cwd();
const repositoryRoot = path.dirname(projectRoot);

async function text(relativePath) {
  return readFile(path.join(repositoryRoot, relativePath), "utf8");
}

test("Produktionsdeployment bleibt trotz automatischer Produktprüfung manuell und fail-closed", async () => {
  const workflow = await text(".github/workflows/deploy-website.yml");
  const entry = await text("website/production-shop-api-public/index.php");
  const apache = await text("website/production-shop-api-public/.htaccess");

  assert.match(workflow, /push:\s*\n\s+branches:\s*\n\s+- main\s*\n\s+paths:/);
  assert.match(workflow, /website\/content\/products\.json/);
  assert.match(workflow, /website\/public\/images\/products\/\*\*/);
  assert.match(workflow, /workflow_dispatch:/);
  assert.match(workflow, /deploy-production-site:[\s\S]*if: \$\{\{ github\.event_name == 'workflow_dispatch'/);
  assert.match(workflow, /CARMAJA_PRODUCTION_DEPLOY_ENABLED/);
  assert.match(workflow, /DEPLOY-CARMAJA-PRODUCTION/);
  assert.match(workflow, /environment:\s*\n\s+name: carmaja-production/);
  assert.match(workflow, /CARMAJA_PRODUCTION_SSH_KNOWN_HOSTS/);
  assert.match(workflow, /StrictHostKeyChecking=yes/);
  assert.doesNotMatch(workflow, /ssh-keyscan/);
  assert.match(entry, /CARMAJA_BOOTSTRAP_FILE/);
  assert.doesNotMatch(`${entry}\n${apache}`, /carmaja-private-test|test-api/);
  assert.match(apache, /\/home\/www\/carmaja-private-shop\/program\/bootstrap\.php/);
});

test("Produktionsvertrag bindet Worker, Versand, Legal Bundle und drei Zahlungsarten identisch", async () => {
  const deployment = JSON.parse(await text("website/config/production-shop-deployment.json"));
  const cutover = JSON.parse(await text("website/config/production-collection-cutover-manifest.v2.json"));
  const runtime = await text("website/config/runtime-config.production.example.php");
  const bootstrap = await text("website/test-api-private/program/bootstrap.php");

  const methods = ["card", "klarna", "sepa_debit"];
  assert.deepEqual(deployment.shop.paymentMethodTypes, methods);
  assert.deepEqual(cutover.paymentMethodTypes, methods);
  for (const method of methods) {
    assert.match(runtime, new RegExp(`['\"]${method}['\"]`));
    assert.match(bootstrap, new RegExp(`['\"]${method}['\"]`));
  }
  assert.equal(deployment.shop.shippingAmountMinor, 270);
  assert.equal(deployment.shop.apiVersion, 2);
  assert.equal(deployment.shop.salesModel, "collection");
  assert.equal(cutover.shipping.amountMinor, 270);
  assert.match(runtime, /'shippingAmountMinor'\s*=>\s*270/);
  assert.equal(deployment.shop.legalBundleId, "cmj-production-legal-2026-08-16-v5");
  assert.equal(cutover.legalBundle.legalBundleId, "cmj-production-legal-2026-08-16-v5");
  assert.equal(cutover.legalBundle.status, "approved");
  assert.match(runtime, /cmj-production-legal-2026-08-16-v5/);
  assert.equal(deployment.paths.worker, "/home/www/carmaja-private-shop/worker.php");
  assert.equal(deployment.paths.backupCli, "/home/www/carmaja-private-shop/backup.php");
  assert.equal(deployment.paths.backupDirectory, "/home/www/carmaja-private-shop/backups");
  assert.equal(deployment.paths.websiteWebroot, "/home/www/carmaja");
  assert.equal(deployment.paths.apiWebroot, "/home/www/carmaja-production-api");
  assert.equal(deployment.paths.productPrivateRoot, "/home/www/carmaja-private-production");
  assert.equal(deployment.paths.shopPrivateRoot, "/home/www/carmaja-private-shop");
  assert.match(runtime, /'productPrivateDir'\s*=>\s*'\/home\/www\/carmaja-private-production'/);
  assert.match(runtime, /'productionApiWebroot'\s*=>\s*'\/home\/www\/carmaja-production-api'/);
  assert.match(runtime, /'productionWebsiteWebroot'\s*=>\s*'\/home\/www\/carmaja'/);
  assert.doesNotMatch(runtime, /carmaja-shop-api|carmaja-site|carmaja-private-test/);
  assert.match(runtime, /'githubAdapterEnabled'\s*=>\s*false/);
  assert.match(runtime, /'productionPublishEnabled'\s*=>\s*false/);
  assert.match(runtime, /'githubBranch'\s*=>\s*'main'/);
  assert.match(runtime, /'githubTokenFile'\s*=>\s*null/);
  assert.match(runtime, /'commerceRestoreRequireTls'\s*=>\s*true/);
  assert.match(runtime, /'backupDirectory'\s*=>\s*'\/home\/www\/carmaja-private-shop\/backups'/);
  assert.match(runtime, /'backupOffsiteTarget'\s*=>\s*'onedrive-pull:\/\/carmaja-production\/Carmaja-Perlen\/Backups'/);
  assert.equal(deployment.guards.publisherEnabled, false);
  assert.equal(deployment.guards.automaticApiDeployment, false);
  assert.equal(deployment.guards.automaticWebsiteDeployment, false);
  assert.equal(deployment.guards.productApiV4WritesEnabled, false);
  assert.equal(deployment.guards.collectionCommerceEnabled, false);
  assert.match(runtime, /'productApiV4WritesEnabled'\s*=>\s*false/);
  assert.match(runtime, /'collectionCommerceEnabled'\s*=>\s*false/);
  assert.equal(deployment.runtime.cron, "*/5 * * * *");
  assert.equal(deployment.runtime.backupCron, "17 * * * *");
  assert.equal(
    deployment.runtime.workerCommand,
    "/usr/bin/php8.4 /home/www/carmaja-private-shop/worker.php /home/www/carmaja-private-shop/config/runtime-config.php",
  );
});

test("Website und v2-Publisher besitzen keinen produktiven Legacy-Verkaufsweg", async () => {
  const products = await text("website/content/products.ts");
  const detail = await text("website/app/armbaender/[slug]/page.tsx");
  const publisher = await text("website/test-api-private/program/product-api-v2.php");
  const bootstrap = await text("website/test-api-private/program/bootstrap.php");
  const hosting = await Promise.all([
    text("website/hosting/click.php"),
    text("website/hosting/_internal/tracking.php"),
    text("website/hosting/statistik/index.php"),
  ]);

  assert.match(products, /loadPublicProductsV2/);
  assert.doesNotMatch(products, /loadPublicProducts,/);
  assert.match(products, /product\.salesEnabled/);
  assert.match(detail, /productId=\{product\.productId\}/);
  assert.match(publisher, /product_v2_published/);
  assert.match(publisher, /array_key_exists\('stock'/);
  assert.match(publisher, /array_key_exists\('vintedUrl'/);
  assert.match(bootstrap, /legacy_product_route_disabled/);
  assert.doesNotMatch(hosting.join("\n").toLowerCase(), /vinted|marketplace/);
  assert.doesNotMatch(hosting.join("\n"), /public-products\.json/);
});

test("Kollektionen-Cutover ist an Ares gebunden und fuer den finalen Planlauf freigegeben", async () => {
  const manifest = JSON.parse(await text("website/config/production-collection-cutover-manifest.v2.json"));
  assert.equal(manifest.manifestVersion, 2);
  assert.equal(manifest.status, "approved_for_cutover");
  assert.equal(manifest.salesModel, "collection");
  assert.equal(manifest.availabilitySource, "commerce_products.sales_enabled");
  assert.deepEqual(manifest.selectedCollections, [{
    productId: "3da76a24-3213-4e8f-b9aa-336ea95e4aa3",
    sku: "CP-2026-0002",
    expectedProductVersion: 4,
    expectedSourceHash: "04460e81f96a578813b165d37bcd231bbf10e3e6ceb4759aa64702dad28afe97",
    operationId: "production-collection-cutover-ares-20260821-v1",
  }]);
  assert.equal(manifest.cutoverGuards.requireNoInventoryCreation, true);
  assert.equal(manifest.cutoverGuards.rollbackMode, "close_new_checkouts_without_data_reset");

  for (const migration of manifest.schemaMigrations) {
    const bytes = await readFile(path.join(repositoryRoot, migration.path));
    const canonical = bytes.toString("utf8").replaceAll("\r\n", "\n");
    const fileHash = createHash("sha256").update(canonical).digest("hex");
    const journal = canonical.replace(/^\s*--.*$/gm, "");
    const journalHash = createHash("sha256").update(journal).digest("hex");
    assert.equal(fileHash, migration.fileSha256, migration.path);
    assert.equal(journalHash, migration.journalSha256, migration.path);
  }

  const adapter = await text("website/scripts/production-cutover.php");
  assert.match(adapter, /APPLY-CARMAJA-PRODUCTION-COLLECTION-CUTOVER/);
  assert.match(adapter, /sales_model/);
  assert.match(adapter, /commerce_tls_not_active/);
  assert.match(adapter, /product_selection_not_approved/);
  assert.match(adapter, /approved_for_plan/);
  assert.match(adapter, /readyForPlan/);
  assert.doesNotMatch(adapter, /INSERT INTO commerce_inventory|UPDATE commerce_inventory|target_on_hand/);
});
