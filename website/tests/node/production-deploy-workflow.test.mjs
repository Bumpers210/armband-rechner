import assert from "node:assert/strict";
import { readFile } from "node:fs/promises";
import path from "node:path";
import test from "node:test";
import { fileURLToPath } from "node:url";

const websiteRoot = path.resolve(path.dirname(fileURLToPath(import.meta.url)), "../..");
const repositoryRoot = path.resolve(websiteRoot, "..");

async function readRepositoryFile(relativePath) {
  return readFile(path.join(repositoryRoot, ...relativePath.split("/")), "utf8");
}

test("Produktionsworkflow trennt Build und ausschliesslich main-gebundenes Deployment", async () => {
  const workflow = await readRepositoryFile(".github/workflows/deploy-website.yml");

  assert.doesNotMatch(workflow, /workflow_dispatch/);
  assert.match(workflow, /release\/production-product-management/);
  assert.match(workflow, /- main/);
  assert.match(workflow, /build-production-site:/);
  assert.match(workflow, /deploy-production-site:/);
  const buildSection = workflow.slice(
    workflow.indexOf("  build-production-site:"),
    workflow.indexOf("  deploy-production-site:"),
  );
  assert.match(buildSection, /Scan sources and create production deployment manifest/);
  assert.match(buildSection, /Package only verified production export/);
  assert.match(buildSection, /Upload verified production deployment artifact/);
  assert.doesNotMatch(buildSection, /if: \$\{\{ github\.ref == 'refs\/heads\/main' \}\}/);
  assert.doesNotMatch(buildSection, /GITHUB_REF_NAME: main/);
  assert.match(workflow, /github\.ref == 'refs\/heads\/main' && vars\.CARMAJA_PRODUCTION_DEPLOY_ENABLED == 'true'/);
  assert.match(workflow, /environment:\s*\n\s+name: carmaja-production/);
  assert.match(workflow, /CARMAJA_PRODUCTION_PUBLISH_ENABLED: "false"/);
  assert.match(workflow, /CARMAJA_PRODUCTION_DEPLOY_ENABLED: "false"/);
  assert.match(workflow, /CARMAJA_SITE_TARGET: production/);
  assert.match(workflow, /CARMAJA_SITE_DOMAIN: www\.carmaja-perlen\.de/);
  assert.doesNotMatch(workflow, /website\/hosting\/\*\*/);
  assert.doesNotMatch(workflow, /ssh-keyscan/);
  assert.match(workflow, /updateRepoVariable/);
});

test("SFTP-Ziele sind relativ und SSH-Pfade bleiben absolut gebunden", async () => {
  const workflow = await readRepositoryFile(".github/workflows/deploy-website.yml");
  const uploadStart = workflow.indexOf("- name: Upload production export into isolated incoming directory");
  const activationStart = workflow.indexOf("- name: Activate, smoke-test and mark verified");
  const upload = workflow.slice(uploadStart, activationStart);

  assert.ok(uploadStart >= 0);
  assert.ok(activationStart > uploadStart);
  assert.deepEqual([...upload.matchAll(/"\$REMOTE:([^"]+)"/g)].map((match) => match[1]), [
    "carmaja-production-deploy/incoming/${CARMAJA_RELEASE_ID}.tar.gz",
    "carmaja-production-deploy/incoming/${CARMAJA_RELEASE_ID}.tar.gz.sha256",
    "carmaja-production-deploy/incoming/${CARMAJA_RELEASE_ID}.manifest.tsv",
  ]);
  assert.doesNotMatch(upload, /\$REMOTE:\/home\/www/);
  assert.doesNotMatch(upload, /(?:^|\s)-O(?:\s|$)/m);
  assert.doesNotMatch(upload, /sshpass|PasswordAuthentication=yes|StrictHostKeyChecking=no/);
  assert.match(upload, /-o BatchMode=yes/);
  assert.match(upload, /-o IdentitiesOnly=yes/);
  assert.match(upload, /-o StrictHostKeyChecking=yes/);
  for (const directory of ["incoming", "releases", "backups", "state", "locks"]) {
    assert.match(workflow, new RegExp(`/home/www/carmaja-production-deploy/${directory}`));
  }
});

test("Aktivierer laesst geschuetzte Webroot-Bereiche und unbekannte Dateien unangetastet", async () => {
  const script = await readRepositoryFile("website/scripts/deploy-production-site.sh");

  assert.match(script, /EXPECTED_BRANCH='main'/);
  assert.match(script, /EXPECTED_TARGET='production'/);
  assert.match(script, /WEBROOT='\/home\/www\/carmaja'/);
  assert.match(script, /WORKSPACE='\/home\/www\/carmaja-production-deploy'/);
  assert.match(script, /\.htaccess/);
  assert.match(script, /click\.php/);
  assert.match(script, /_internal/);
  assert.match(script, /statistik/);
  assert.match(script, /private-data/);
  assert.match(script, /nicht verwaltete Datei ueberschreiben/);
  assert.match(script, /rollback_files/);
  assert.match(script, /ROLLBACK_OK phase=activation/);
  assert.doesNotMatch(script, /rm -rf/);
  assert.doesNotMatch(script, /rsync[\s\S]*--delete/);
});

test("Smoke-Orchestrator hat keine Basic-Auth oder Testziel-Konfiguration", async () => {
  const script = await readRepositoryFile("website/scripts/run-production-deployment.sh");

  for (const marker of [
    "DEPLOY_ACTIVATION_START",
    "SMOKE_TEST_OK",
    "MARK_VERIFIED_OK",
    "ROLLBACK_OK",
    "ROLLBACK_FAILED",
  ]) {
    assert.match(script, new RegExp(marker));
  }
  assert.match(script, /301\|302/);
  assert.match(script, /X-Content-Type-Options/);
  assert.match(script, /run_remote_script rollback/);
  assert.doesNotMatch(script, /BASIC_AUTH/);
  assert.doesNotMatch(script, /Authorization:/i);
  assert.doesNotMatch(script, /set -x/);
  assert.doesNotMatch(script, /test\.carmaja-perlen\.de/);
});

test("Produktions-Lint und Paketierung schliessen Hosting-Dateien aus", async () => {
  const packageJson = JSON.parse(await readRepositoryFile("website/package.json"));
  const lintCommand = packageJson.scripts.lint;

  assert.match(lintCommand, /^eslint /);
  assert.doesNotMatch(lintCommand, /hosting/);
  assert.match(packageJson.scripts.test, /test:deployment-shell/);
  assert.match(packageJson.scripts["prepare:production-deploy"], /prepare-production-deploy/);
});
