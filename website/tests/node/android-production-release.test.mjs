import assert from "node:assert/strict";
import fs from "node:fs";
import path from "node:path";
import test from "node:test";

const root = path.resolve(import.meta.dirname, "..", "..", "..");
const workflow = fs.readFileSync(
  path.join(root, ".github", "workflows", "android-production-release.yml"),
  "utf8",
);
const validationWorkflow = fs.readFileSync(
  path.join(root, ".github", "workflows", "android-production-build.yml"),
  "utf8",
);

test("signierter Produktionsbuild ist manuell, main-gepinnt und nicht veröffentlichend", () => {
  assert.match(workflow, /workflow_dispatch:/);
  assert.doesNotMatch(workflow, /^\s{2}(push|pull_request):/m);
  assert.match(workflow, /expected_commit_sha/);
  assert.match(workflow, /BUILD-SIGNED-CARMAJA-APP/);
  assert.match(workflow, /refs\/heads\/main/);
  assert.match(workflow, /GITHUB_SHA.*EXPECTED_COMMIT_SHA/);
  assert.match(workflow, /permissions:\s*\n\s+contents: read/);
  assert.doesNotMatch(workflow, /contents: write|gh release|create.*release/i);
});

test("Signierung bleibt geschützt, gepinnt und wird wieder entfernt", () => {
  assert.match(workflow, /environment: carma-production-app/);
  assert.match(workflow, /CARMAJA_ANDROID_KEYSTORE_BASE64/);
  assert.match(workflow, /CARMAJA_ANDROID_KEY_ALIAS/);
  assert.match(workflow, /carmaja-product-management-production/);
  assert.match(workflow, /-storepass:env CARMAJA_ANDROID_KEYSTORE_PASSWORD/);
  assert.match(workflow, /expected_certificate_sha256/);
  assert.match(workflow, /apksigner.*verify --verbose/);
  assert.match(workflow, /Remove production signing material/);
  assert.match(workflow, /if: always\(\)/);
  assert.match(workflow, /retention-days: 7/);
});

test("Produktions-APK bleibt von Test-App und Test-API getrennt", () => {
  assert.match(workflow, /package: name='de\.carmajaperlen\.armbandrechner'/);
  assert.match(workflow, /versionCode='5'/);
  assert.match(workflow, /versionName='1\.3\.0'/);
  assert.match(workflow, /https:\/\/api\.carmaja-perlen\.de\//);
  assert.match(workflow, /! grep -Fq 'https:\/\/test-api\.carmaja-perlen\.de\/'/);
  assert.match(workflow, /installation requires separate approval/);
});

test("unsignierte Produktionsvalidierung läuft auch für relevante main-PRs", () => {
  assert.match(validationWorkflow, /pull_request:/);
  assert.match(validationWorkflow, /- main/);
  assert.match(validationWorkflow, /android-production-release\.yml/);
  assert.match(validationWorkflow, /connectedDebugAndroidTest/);
  assert.match(validationWorkflow, /allowUnsignedProductionValidation=true/);
  assert.match(validationWorkflow, /versionCode='5'/);
  assert.match(validationWorkflow, /versionName='1\.3\.0'/);
});
