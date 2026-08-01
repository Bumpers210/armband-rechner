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

test("Produktionsworkflow trennt Push-Builds von einem expliziten manuellen Produktionsdeploy", async () => {
  const workflow = await readRepositoryFile(".github/workflows/deploy-website.yml");

  assert.match(workflow, /workflow_dispatch:\s*\n\s+inputs:\s*\n\s+expected_commit_sha:/);
  assert.match(workflow, /deployment_confirmation:/);
  assert.match(workflow, /pull_request:\s*\n\s+branches:\s*\n\s+- main/);
  assert.match(workflow, /release\/production-product-management/);
  assert.match(workflow, /- main/);
  assert.match(workflow, /build-production-site:/);
  assert.match(workflow, /validate-manual-production-deploy:/);
  assert.match(workflow, /deploy-production-site:/);
  const validationStart = workflow.indexOf("  validate-manual-production-deploy:");
  const buildSection = workflow.slice(
    workflow.indexOf("  build-production-site:"),
    validationStart,
  );
  assert.match(buildSection, /Scan sources and create production deployment manifest/);
  assert.match(buildSection, /Package only verified production export/);
  assert.match(buildSection, /Upload verified production deployment artifact/);
  assert.match(buildSection, /CARMAJA_PULL_REQUEST_BASE_REPOSITORY/);
  assert.match(buildSection, /CARMAJA_PULL_REQUEST_HEAD_REPOSITORY/);
  assert.match(buildSection, /CARMAJA_MANIFEST_SOURCE_BRANCH: \$\{\{ github\.event_name == 'pull_request' && github\.base_ref \|\| github\.ref_name \}\}/);
  assert.match(buildSection, /GITHUB_REF_NAME="\$CARMAJA_MANIFEST_SOURCE_BRANCH" npm run prepare:production-deploy/);
  assert.match(buildSection, /test "\$GITHUB_BASE_REF" = "\$CARMAJA_PRODUCTION_BRANCH"/);
  assert.match(buildSection, /test "\$CARMAJA_PULL_REQUEST_BASE_REPOSITORY" = "\$CARMAJA_REPOSITORY"/);
  assert.match(buildSection, /test "\$CARMAJA_PULL_REQUEST_HEAD_REPOSITORY" = "\$CARMAJA_REPOSITORY"/);
  assert.doesNotMatch(buildSection, /if: \$\{\{ github\.ref == 'refs\/heads\/main' \}\}/);
  assert.doesNotMatch(buildSection, /GITHUB_REF_NAME: main/);
  assert.doesNotMatch(buildSection, /secrets\.|environment:|\bssh\b|\bscp\b|github-script/i);
  assert.match(workflow, /github\.event_name == 'workflow_dispatch'/);
  assert.match(workflow, /github\.ref == 'refs\/heads\/main'/);
  assert.match(workflow, /github\.ref_name == 'main'/);
  assert.match(workflow, /inputs\.expected_commit_sha == github\.sha/);
  assert.match(workflow, /inputs\.deployment_confirmation == 'DEPLOY_PRODUCTION'/);
  assert.match(workflow, /needs\.validate-manual-production-deploy\.result == 'success'/);
  assert.match(workflow, /environment:\s*\n\s+name: carmaja-production/);
  assert.match(workflow, /CARMAJA_PRODUCTION_PUBLISH_ENABLED: "false"/);
  assert.match(workflow, /CARMAJA_PRODUCTION_DEPLOY_ENABLED: "false"/);
  assert.match(workflow, /CARMAJA_SITE_TARGET: production/);
  assert.match(workflow, /CARMAJA_SITE_DOMAIN: www\.carmaja-perlen\.de/);
  assert.doesNotMatch(workflow, /website\/hosting\/\*\*/);
  assert.doesNotMatch(workflow, /ssh-keyscan/);
  assert.match(workflow, /updateRepoVariable/);
});

test("Manuelle Produktionsdeploys werden vor dem Serverzugriff strikt validiert und stets zurueckgesetzt", async () => {
  const workflow = await readRepositoryFile(".github/workflows/deploy-website.yml");
  const validationStart = workflow.indexOf("  validate-manual-production-deploy:");
  const deployStart = workflow.indexOf("  deploy-production-site:");
  const resetStart = workflow.indexOf("  reset-production-deploy-gate:");
  const validation = workflow.slice(validationStart, deployStart);
  const deploy = workflow.slice(deployStart, resetStart);
  const reset = workflow.slice(resetStart);

  assert.ok(validationStart >= 0);
  assert.ok(deployStart > validationStart);
  assert.ok(resetStart > deployStart);
  assert.match(validation, /test "\$GITHUB_REF" = "refs\/heads\/main"/);
  assert.match(validation, /test "\$CARMAJA_EXPECTED_COMMIT_SHA" = "\$GITHUB_SHA"/);
  assert.match(validation, /test "\$CARMAJA_DEPLOYMENT_CONFIRMATION" = "DEPLOY_PRODUCTION"/);
  assert.match(validation, /test "\$CARMAJA_PRODUCTION_DEPLOY_ENABLED" = "true"/);
  assert.match(validation, /test "\$CARMAJA_PRODUCTION_PUBLISH_ENABLED" = "false"/);
  assert.match(validation, /environment:\s*\n\s+name: carmaja-production/);
  assert.doesNotMatch(validation, /CARMAJA_PRODUCTION_VARIABLES_TOKEN|secrets\./);
  assert.match(deploy, /test "\$GITHUB_EVENT_NAME" = "workflow_dispatch"/);
  assert.match(deploy, /test "\$CARMAJA_EXPECTED_COMMIT_SHA" = "\$GITHUB_SHA"/);
  assert.match(deploy, /test "\$CARMAJA_DEPLOYMENT_CONFIRMATION" = "DEPLOY_PRODUCTION"/);
  assert.match(reset, /needs:\s*\n\s+- build-production-site\s*\n\s+- validate-manual-production-deploy\s*\n\s+- deploy-production-site/);
  assert.match(reset, /if: \$\{\{ github\.event_name == 'workflow_dispatch' && always\(\) && vars\.CARMAJA_PRODUCTION_DEPLOY_ENABLED == 'true' \}\}/);
  assert.match(reset, /environment:\s*\n\s+name: carmaja-production/);
  assert.match(reset, /github-token: \$\{\{ secrets\.CARMAJA_PRODUCTION_VARIABLES_TOKEN \}\}/);
  assert.match(reset, /permissions: \{\}/);
  assert.doesNotMatch(reset, /actions: write/);
  assert.match(deploy, /grep -Fx "\$\(printf 'meta\\trepository\\tBumpers210\/armband-rechner'\)"/);
  assert.match(deploy, /grep -Fx "\$\(printf 'meta\\tworkspace\\t\/home\/www\/carmaja-production-deploy'\)"/);
  assert.doesNotMatch(deploy, /grep -Fx "meta\\trepository\\tBumpers210\/armband-rechner"/);
  assert.equal((workflow.match(/secrets\.CARMAJA_PRODUCTION_VARIABLES_TOKEN/g) ?? []).length, 1);
  assert.match(deploy, /prohibited = 1/);
  assert.match(deploy, /END \{ exit prohibited \? 0 : 1 \}/);
  assert.match(deploy, /private_key_payload_length=\$\{#CARMAJA_PRODUCTION_SSH_PRIVATE_KEY\}/);
  assert.match(deploy, /SSH_PRIVATE_KEY_BASE64_LENGTH=%s/);
  assert.match(deploy, /Production SSH key secret is not canonical Base64\./);
  assert.match(deploy, /\[\[ ! "\$CARMAJA_PRODUCTION_SSH_PRIVATE_KEY" =~ \^\[A-Za-z0-9\+\/\]\+=\{0,2\}\$ \]\]/);
  assert.match(deploy, /printf '%s' "\$CARMAJA_PRODUCTION_SSH_PRIVATE_KEY" \| base64 --decode > "\$HOME\/\.ssh\/carmaja_production_deploy"/);
  assert.doesNotMatch(deploy, /printf '%s\\n' "\$CARMAJA_PRODUCTION_SSH_PRIVATE_KEY"/);
  for (const marker of [
    "identity",
    "ssh_secrets_present",
    "ssh_secret_shapes",
    "artifact_integrity",
    "manifest_metadata",
    "manifest_paths",
  ]) {
    assert.match(deploy, new RegExp(`DEPLOY_GUARD_OK=${marker}`));
  }
});

test("Interne Pull Requests nach main validieren nur den Produktionsbuild", async () => {
  const workflow = await readRepositoryFile(".github/workflows/deploy-website.yml");
  const buildStart = workflow.indexOf("  build-production-site:");
  const validationStart = workflow.indexOf("  validate-manual-production-deploy:");
  const deployStart = workflow.indexOf("  deploy-production-site:");
  const resetStart = workflow.indexOf("  reset-production-deploy-gate:");
  const build = workflow.slice(buildStart, validationStart);
  const validation = workflow.slice(validationStart, deployStart);
  const deploy = workflow.slice(deployStart, resetStart);
  const reset = workflow.slice(resetStart);

  assert.ok(buildStart >= 0);
  assert.ok(validationStart > buildStart);
  assert.match(workflow, /pull_request:\s*\n\s+branches:\s*\n\s+- main\s*\n\s+paths:/);
  assert.match(build, /pull_request\)/);
  assert.match(build, /test "\$GITHUB_BASE_REF" = "\$CARMAJA_PRODUCTION_BRANCH"/);
  assert.match(build, /test "\$CARMAJA_PULL_REQUEST_BASE_REPOSITORY" = "\$CARMAJA_REPOSITORY"/);
  assert.match(build, /test "\$CARMAJA_PULL_REQUEST_HEAD_REPOSITORY" = "\$CARMAJA_REPOSITORY"/);
  assert.match(build, /CARMAJA_MANIFEST_SOURCE_BRANCH: \$\{\{ github\.event_name == 'pull_request' && github\.base_ref \|\| github\.ref_name \}\}/);
  assert.match(build, /GITHUB_REF_NAME="\$CARMAJA_MANIFEST_SOURCE_BRANCH" npm run prepare:production-deploy/);
  assert.doesNotMatch(build, /secrets\.|environment:|\bssh\b|\bscp\b|github-script/i);
  assert.match(validation, /if: \$\{\{ github\.event_name == 'workflow_dispatch' \}\}/);
  assert.match(deploy, /if: \$\{\{ github\.event_name == 'workflow_dispatch' && github\.ref == 'refs\/heads\/main'/);
  assert.match(reset, /if: \$\{\{ github\.event_name == 'workflow_dispatch' && always\(\)/);
  assert.doesNotMatch(workflow, /- fix\/production-bootstrap-rollback-records/);
  assert.match(workflow, /push:\s*\n\s+branches:\s*\n\s+- release\/production-product-management\s*\n\s+- main/);
});

test("Erstdeploy-Bootstrap ist auf den bestaetigten Kandidaten beschraenkt und fasst den Webroot nicht an", async () => {
  const script = await readRepositoryFile("website/scripts/bootstrap-production-first-deploy.sh");
  const inventory = await readRepositoryFile("website/scripts/production-first-deploy-inventory.v1");
  const workflow = await readRepositoryFile(".github/workflows/deploy-website.yml");

  assert.match(script, /EXPECTED_CANDIDATE_COMMIT='d68dae76df53e5aa554f0139ce7c85301d63c81c'/);
  assert.match(script, /EXPECTED_CANDIDATE_ARCHIVE_SHA256='568c5ce7a67248e803f029d707854946f5ff284e6722fdf5361cf0aab1c9b043'/);
  assert.match(script, /EXPECTED_EXISTING_PATH_COUNT='50'/);
  assert.match(script, /EXPECTED_MISSING_PATH_COUNT='19'/);
  assert.match(script, /verify_live_inventory/);
  assert.match(script, /bootstrap-provenance/);
  assert.match(script, /no-repository-commit/);
  assert.doesNotMatch(script, /chmod 0755 "\$WEBROOT"/);
  assert.doesNotMatch(script, /rm -f "\$WEBROOT/);
  assert.match(script, /write_missing_rollback_records/);
  assert.match(script, /validate_missing_rollback_records/);
  assert.match(script, /printf 'previously-missing\|%s\\n'/);
  assert.doesNotMatch(script, /printf "previously-missing\|%s\\\\n"/);
  assert.match(inventory, /^inventory\|1$/m);
  assert.equal((inventory.match(/^existing\|/gm) ?? []).length, 50);
  assert.equal((inventory.match(/^missing\|/gm) ?? []).length, 19);
  assert.match(workflow, /website\/scripts\/bootstrap-production-first-deploy\.sh/);
  assert.match(workflow, /website\/scripts\/production-first-deploy-inventory\.v1/);
});

test("Bootstrap-Reparatur akzeptiert nur den bekannten privaten Fehlzustand und bleibt vom Webroot getrennt", async () => {
  const repair = await readRepositoryFile(
    "website/scripts/repair-production-bootstrap-rollback-records.sh",
  );

  assert.match(repair, /--repair-bootstrap-rollback-records/);
  assert.match(repair, /EXPECTED_CURRENT_MANIFEST_SHA256='09919cd198475b8c6d0ff47a7ca3ed39242a45db654731633d81612b881f696b'/);
  assert.match(repair, /EXPECTED_BACKUP_ID='bootstrap-unmanaged-d68dae76df53e5aa554f0139ce7c85301d63c81c'/);
  assert.match(repair, /CARMAJA_PRODUCTION_DEPLOY_ENABLED:-/);
  assert.match(repair, /CARMAJA_PRODUCTION_PUBLISH_ENABLED:-/);
  assert.match(repair, /write_expected_legacy_broken_records/);
  assert.match(repair, /validate_correct_rollback_records/);
  assert.match(repair, /simulate_rollback/);
  assert.match(repair, /QUARANTINE_DIRECTORY/);
  assert.match(repair, /webroot_snapshot/);
  assert.doesNotMatch(repair, /chmod 0755 "\$WEBROOT"/);
  assert.doesNotMatch(repair, /rm -f "\$WEBROOT/);
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
