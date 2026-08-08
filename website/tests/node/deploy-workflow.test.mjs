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

test("Testwebsite-Workflow besitzt nur Push-Trigger und feste Zielguards", async () => {
  const workflow = await readRepositoryFile(".github/workflows/deploy-test-website.yml");

  assert.doesNotMatch(workflow, /workflow_dispatch/);
  assert.match(workflow, /branches:\s*\n\s+- test\/product-management-beta/);
  assert.match(workflow, /website\/content\/products\.json/);
  assert.match(workflow, /website\/public\/images\/products\/\*\*/);
  assert.match(workflow, /website\/hosting-test\/\*\*/);
  assert.doesNotMatch(workflow, /website\/hosting\/\*\*/);
  assert.doesNotMatch(workflow, /www\.carmaja-perlen\.de/);
  assert.match(workflow, /CARMAJA_TEST_WEBROOT: \/home\/www\/carmaja-test-site/);
  assert.match(workflow, /CARMAJA_TEST_DEPLOY_WORKSPACE: \/home\/www\/carmaja-test-deploy/);
  assert.match(workflow, /CARMAJA_PRODUCTION_PUBLISH_ENABLED: "false"/);
  assert.match(workflow, /CARMAJA_PRODUCTION_DEPLOY_ENABLED: "false"/);
  assert.match(workflow, /run: npm run lint:test/);
  assert.match(workflow, /if: \$\{\{ vars\.CARMAJA_TEST_DEPLOY_ENABLED == 'true' \}\}/);
  assert.match(workflow, /website\/scripts\/run-test-deployment\.sh/);
  assert.match(workflow, /website\/tests\/shell\/\*\*/);
  assert.ok(
    workflow.indexOf("Guard every deployment target before server access") <
      workflow.indexOf("Verify remote test paths and required tools without writes"),
  );
  assert.match(workflow, /tar -C out-test -czf \.test-deploy-package\/site\.tar\.gz \./);
  assert.match(
    workflow,
    /\$4 == "images\/bracelets\/hero-dunkelrot-braun-holz\.jpg"/,
  );
});

test("SFTP-Uploads verwenden ausschliesslich den relativen IONOS-Namensraum", async () => {
  const workflow = await readRepositoryFile(".github/workflows/deploy-test-website.yml");
  const uploadStart = workflow.indexOf(
    "- name: Upload test export into isolated incoming directory",
  );
  const activationStart = workflow.indexOf(
    "- name: Activate, smoke-test and mark verified",
  );
  const upload = workflow.slice(uploadStart, activationStart);
  const remoteTargets = [...upload.matchAll(/"\$REMOTE:([^"]+)"/g)].map(
    (match) => match[1],
  );

  assert.ok(uploadStart >= 0);
  assert.ok(activationStart > uploadStart);
  assert.deepEqual(remoteTargets, [
    "carmaja-test-deploy/incoming/${CARMAJA_RELEASE_ID}.tar.gz",
    "carmaja-test-deploy/incoming/${CARMAJA_RELEASE_ID}.tar.gz.sha256",
    "carmaja-test-deploy/incoming/${CARMAJA_RELEASE_ID}.manifest.tsv",
  ]);
  assert.doesNotMatch(upload, /\$REMOTE:\/home\/www/);
  assert.doesNotMatch(upload, /(?:^|\s)-O(?:\s|$)/m);
  assert.doesNotMatch(upload, /sshpass|PasswordAuthentication=yes|StrictHostKeyChecking=no/);
  assert.match(upload, /-o BatchMode=yes/);
  assert.match(upload, /-o IdentitiesOnly=yes/);
  assert.match(upload, /-o StrictHostKeyChecking=yes/);
});

test("SSH-Dateisystem, Aktivierung und Rollback bleiben absolut gebunden", async () => {
  const workflow = await readRepositoryFile(".github/workflows/deploy-test-website.yml");
  const activationStart = workflow.indexOf(
    "- name: Activate, smoke-test and mark verified",
  );
  const activation = workflow.slice(activationStart);
  const orchestrator = await readRepositoryFile("website/scripts/run-test-deployment.sh");

  for (const directory of ["incoming", "releases", "backups", "state", "locks"]) {
    assert.match(workflow, new RegExp(`/home/www/carmaja-test-deploy/${directory}`));
  }

  assert.match(workflow, /CARMAJA_TEST_WEBROOT: \/home\/www\/carmaja-test-site/);
  assert.match(workflow, /CARMAJA_TEST_DEPLOY_WORKSPACE: \/home\/www\/carmaja-test-deploy/);
  assert.match(activation, /run: bash scripts\/run-test-deployment\.sh/);
  assert.match(orchestrator, /run_remote_script deploy/);
  assert.match(orchestrator, /run_remote_script rollback/);
  assert.match(orchestrator, /run_remote_script mark_verified/);
  assert.match(orchestrator, /sh -s" < scripts\/deploy-test-site\.sh/);
  assert.match(orchestrator, /EXPECTED_WEBROOT='\/home\/www\/carmaja-test-site'/);
  assert.match(orchestrator, /EXPECTED_WORKSPACE='\/home\/www\/carmaja-test-deploy'/);
});

test("Smoke-Orchestrierung trennt Phasen und behandelt Authentifizierung geheim", async () => {
  const script = await readRepositoryFile("website/scripts/run-test-deployment.sh");

  for (const message of [
    "DEPLOY_ACTIVATION_START",
    "DEPLOY_ACTIVATION_OK",
    "SMOKE_TEST_START",
    "SMOKE_TEST_OK",
    "MARK_VERIFIED_START",
    "MARK_VERIFIED_OK",
    "ROLLBACK_OK",
    "ROLLBACK_FAILED",
  ]) {
    assert.match(script, new RegExp(message));
  }

  assert.match(script, /301\|302/);
  assert.doesNotMatch(script, /--user "\$CARMAJA_TEST_BASIC_AUTH_USER/);
  assert.match(script, /curl \\\n\s+--disable/);
  assert.match(script, /--config "\$auth_config"/);
  assert.match(script, /chmod 0600 "\$auth_config"/);
  assert.match(script, /trap cleanup EXIT/);
  assert.doesNotMatch(script, /set -x/);
  assert.doesNotMatch(script, /Authorization:/i);
  assert.match(script, /https:\/\/test\.carmaja-perlen\.de\/armbaender\//);
  assert.match(
    script,
    /https:\/\/test\.carmaja-perlen\.de\/images\/bracelets\/hero-dunkelrot-braun-holz\.jpg/,
  );
});

test("Rollback meldet nur sicheren vorherigen Zustand und keine privaten Inhalte", async () => {
  const remoteScript = await readRepositoryFile("website/scripts/deploy-test-site.sh");

  assert.match(remoteScript, /ROLLBACK_STATE restored=%s release=%s commit=%s verified_new_sha=no/);
  assert.match(remoteScript, /initial_empty/);
  assert.match(remoteScript, /previous_active/);
  assert.match(remoteScript, /ROLLBACK_OK phase=activation/);
  assert.match(remoteScript, /ROLLBACK_FAILED phase=activation/);
  assert.doesNotMatch(remoteScript, /cat "\$STATE/);
});

test("Android-Testworkflow reagiert nicht auf Produkt- oder Websiteaenderungen", async () => {
  const workflow = await readRepositoryFile(".github/workflows/android-test-apk.yml");
  const pushSection = workflow.slice(workflow.indexOf("push:"), workflow.indexOf("workflow_dispatch:"));

  assert.match(pushSection, /app\/\*\*/);
  assert.match(pushSection, /gradle\/\*\*/);
  assert.match(pushSection, /gradlew/);
  assert.doesNotMatch(pushSection, /website/);
  assert.doesNotMatch(pushSection, /products\.json/);
});

test("Remote-Deploy nutzt Manifest, getrennten Workspace und kein pauschales Loeschen", async () => {
  const script = await readRepositoryFile("website/scripts/deploy-test-site.sh");

  for (const directory of ["incoming", "releases", "backups", "state", "locks"]) {
    assert.ok(script.includes(`$WORKSPACE/${directory}`));
  }

  assert.match(script, /WEBROOT='\/home\/www\/carmaja-test-site'/);
  assert.match(script, /WORKSPACE='\/home\/www\/carmaja-test-deploy'/);
  assert.match(script, /validate_manifest/);
  assert.match(script, /rollback_files/);
  assert.match(script, /deploy\|rollback\|mark_verified/);
  assert.doesNotMatch(script, /rm -rf/);
  assert.doesNotMatch(script, /rsync[\s\S]*--delete/);
  assert.doesNotMatch(script, /carmaja-private-test/);
  assert.doesNotMatch(script, /carmaja-test-api/);
});

test("Test-Lint schliesst produktive Hostingdateien aus", async () => {
  const packageJson = JSON.parse(await readRepositoryFile("website/package.json"));
  const lintCommand = packageJson.scripts["lint:test"];

  assert.match(lintCommand, /^eslint /);
  assert.doesNotMatch(lintCommand, /hosting/);
});
