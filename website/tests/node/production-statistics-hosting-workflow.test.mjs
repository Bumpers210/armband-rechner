import assert from "node:assert/strict";
import { readFileSync } from "node:fs";
import { test } from "node:test";
import { resolve } from "node:path";

const root = resolve(import.meta.dirname, "../..");
const read = (path) => readFileSync(resolve(root, "..", path), "utf8");

test("Statistikhosting bleibt ein getrennt freizugebender Produktionswartungsschritt", () => {
  const workflow = read(".github/workflows/deploy-statistics-hosting.yml");
  const installer = read("website/scripts/install-production-statistics-hosting.sh");

  assert.match(workflow, /CARMAJA_PRODUCTION_STATISTICS_HOSTING_ENABLED/);
  assert.match(workflow, /INSTALL_PRODUCTION_STATISTICS/);
  assert.match(workflow, /CARMAJA_PRODUCTION_VARIABLES_TOKEN/);
  assert.match(workflow, /CARMAJA_PRODUCTION_SSH_PRIVATE_KEY/);
  assert.match(workflow, /private_key_payload_length/);
  assert.match(workflow, /Production SSH key secret is not canonical Base64/);
  assert.doesNotMatch(workflow, /website\/out/);
  assert.match(installer, /\/usr\/bin\/php8\.4/);
  assert.match(installer, /STATISTICS_HOSTING_ROLLBACK_OK/);
  assert.match(installer, /direct-unknown/);
  assert.doesNotMatch(installer, /clicks\.json/);
});
