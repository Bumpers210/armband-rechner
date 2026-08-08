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
  assert.match(workflow, /updateRepoVariable/);
  assert.doesNotMatch(workflow, /website\/out|website\/hosting/);
  assert.doesNotMatch(workflow, /ssh-keyscan|StrictHostKeyChecking=no|scp -O|sshpass/);
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
