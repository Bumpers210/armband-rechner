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

test("Produktions-API-Workflow ist manuell, gegated und hält Runtime-Daten aus dem Artefakt", async () => {
  const workflow = await readRepositoryFile(".github/workflows/deploy-production-api.yml");

  assert.match(workflow, /workflow_dispatch:/);
  assert.doesNotMatch(workflow, /\bpush:|\bpull_request:/);
  assert.match(workflow, /DEPLOY_PRODUCTION_API/);
  assert.match(workflow, /CARMAJA_PRODUCTION_API_DEPLOY_ENABLED/);
  assert.match(workflow, /CARMAJA_PRODUCTION_PUBLISH_ENABLED: "false"/);
  assert.match(workflow, /CARMAJA_GITHUB_ADAPTER_ENABLED: "false"/);
  assert.match(workflow, /environment:\s*\n\s+name: carmaja-production/);
  assert.match(workflow, /CARMAJA_PRODUCTION_API_WEBROOT: \$\{\{ vars\.CARMAJA_PRODUCTION_API_WEBROOT \}\}/);
  assert.match(workflow, /CARMAJA_PRODUCTION_PRIVATE_DIR: \$\{\{ vars\.CARMAJA_PRODUCTION_PRIVATE_DIR \}\}/);
  assert.match(workflow, /CARMAJA_PRODUCTION_API_DEPLOY_WORKSPACE: \$\{\{ vars\.CARMAJA_PRODUCTION_API_DEPLOY_WORKSPACE \}\}/);
  assert.match(workflow, /CARMAJA_PRODUCTION_API_RUNTIME_CONFIG: \$\{\{ vars\.CARMAJA_PRODUCTION_API_RUNTIME_CONFIG \}\}/);
  assert.match(workflow, /runtime-config\.php' -o -name 'github-token' -o -name '\*\.secret'/);
  assert.doesNotMatch(workflow, /production-api-public\/\.htaccess|\$api_webroot\/\.htaccess/);
  assert.match(workflow, /product-api-diagnostics\.php/);
  assert.match(workflow, /PRODUCTION_API_HTTP_SMOKE_OK=unauthenticated-router/);
  assert.match(workflow, /https:\/\/api\.carmaja-perlen\.de\//);
  assert.match(workflow, /reset-production-api-deploy-gate:/);
  assert.match(workflow, /updateEnvironmentVariable/);
  assert.match(workflow, /github\.rest\.repos\.get\(/);
  assert.match(workflow, /repository_id: repository\.data\.id/);
  assert.doesNotMatch(workflow, /updateEnvironmentVariable\(\{\s*owner: context\.repo\.owner/s);
  assert.match(workflow, /environment_name: "carmaja-production"/);
  assert.doesNotMatch(workflow, /if: .*vars\.CARMAJA_PRODUCTION_API_DEPLOY_ENABLED/m);
  assert.match(workflow, /reset-production-api-deploy-gate:[\s\S]*?if: \$\{\{ github\.event_name == 'workflow_dispatch' && always\(\) \}\}/);
  assert.match(workflow, /SFTP_WORKSPACE_PATH="\$\{CARMAJA_PRODUCTION_API_DEPLOY_WORKSPACE#\/home\/www\/\}"/);
  assert.match(workflow, /SFTP_INCOMING_PATH="\$SFTP_WORKSPACE_PATH\/incoming"/);
  assert.doesNotMatch(workflow, /\$REMOTE:\$\{CARMAJA_PRODUCTION_API_DEPLOY_WORKSPACE\}/);
  assert.match(workflow, /checksum_sha256="\$\(awk 'NR == 1 \{ print \$1 \}' "\$checksum"\)"/);
  assert.match(workflow, /test "\$checksum_name" = "product-api\.tar\.gz" \|\| fail/);
  assert.match(workflow, /test "\$checksum_sha256" = "\$archive_sha256" \|\| fail/);
  assert.match(workflow, /test "\$\(sha256sum "\$archive" \| awk '\{print \$1\}'\)" = "\$checksum_sha256" \|\| fail/);
  assert.doesNotMatch(workflow, /sha256sum -c "\$release_id\.tar\.gz\.sha256"/);
  assert.match(workflow, /cp -a "\$stage\/private\/program" "\$next_program"/);
  assert.match(workflow, /cp -a "\$private_dir\/program" "\$backup\/program"/);
  assert.match(workflow, /mv "\$private_dir\/program" "\$previous_program"/);
  assert.match(workflow, /mv "\$next_program" "\$private_dir\/program"/);
  assert.doesNotMatch(workflow, /mv "\$private_dir\/program" "\$backup\/program"/);
  assert.match(workflow, /activation_failure backup_private_program/);
  assert.match(workflow, /activation_failure backup_public_entry/);
  assert.match(workflow, /activation_failure stage_private_program/);
  assert.match(workflow, /activation_failure stage_previous_private_program/);
  assert.match(workflow, /activation_failure activate_private_program/);
  assert.match(workflow, /activation_failure stage_public_entry/);
  assert.match(workflow, /activation_failure activate_public_entry/);
  assert.match(workflow, /activation_failure private_diagnostics/);
  assert.match(workflow, /index_replaced=false/);
  assert.match(workflow, /elif test "\$index_replaced" = true; then/);
  assert.doesNotMatch(workflow, /website\/out|website\/hosting/);
  assert.doesNotMatch(workflow, /ssh-keyscan|StrictHostKeyChecking=no|scp -O|sshpass/);
  assert.doesNotMatch(workflow, /^(REMOTE_CHECK|REMOTE_PREPARE|REMOTE_ACTIVATE)$/m);
  assert.match(workflow, /^ {10}REMOTE_CHECK$/m);
  assert.match(workflow, /^ {10}REMOTE_PREPARE$/m);
  assert.match(workflow, /^ {10}REMOTE_ACTIVATE$/m);
});

test("Interaktiver Einrichter speichert nur geschützte Pfadvariablen und hält das Gate geschlossen", async () => {
  const script = await readRepositoryFile("website/scripts/configure-production-api-environment.ps1");

  for (const variable of [
    "CARMAJA_PRODUCTION_API_WEBROOT",
    "CARMAJA_PRODUCTION_PRIVATE_DIR",
    "CARMAJA_PRODUCTION_API_DEPLOY_WORKSPACE",
    "CARMAJA_PRODUCTION_API_RUNTIME_CONFIG",
  ]) {
    assert.match(script, new RegExp(`Read-CanonicalAbsoluteUnixPath '${variable}'`));
  }
  assert.match(script, /gh variable set \$entry\.Key --env carmaja-production/);
  assert.match(script, /CARMAJA_PRODUCTION_API_DEPLOY_ENABLED --env carmaja-production --body false/);
  assert.doesNotMatch(script, /secret set|runtime-config\.php.*WriteAllText/i);
});
