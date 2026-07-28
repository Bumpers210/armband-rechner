import assert from "node:assert/strict";
import { execFileSync } from "node:child_process";
import path from "node:path";
import test from "node:test";
import { fileURLToPath } from "node:url";

const websiteRoot = path.resolve(path.dirname(fileURLToPath(import.meta.url)), "../..");

test("Manifestdeployment sichert, bereinigt und rollt atomar zurueck", () => {
  const output = execFileSync(
    "bash",
    ["tests/shell/deploy-test-site-test.sh", "scripts/deploy-test-site.sh"],
    {
      cwd: websiteRoot,
      encoding: "utf8",
      stdio: ["ignore", "pipe", "pipe"],
    },
  );

  assert.match(output, /Deployment-Shell-Test erfolgreich\./);
});
