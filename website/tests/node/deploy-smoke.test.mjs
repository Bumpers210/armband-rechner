import assert from "node:assert/strict";
import { execFileSync } from "node:child_process";
import path from "node:path";
import test from "node:test";
import { fileURLToPath } from "node:url";

const websiteRoot = path.resolve(path.dirname(fileURLToPath(import.meta.url)), "../..");

test("Smoke-Orchestrierung diagnostiziert 27 sichere Erfolgs- und Fehlerpfade", () => {
  const output = execFileSync(
    "bash",
    ["tests/shell/run-test-deployment-test.sh", "scripts/run-test-deployment.sh"],
    {
      cwd: websiteRoot,
      encoding: "utf8",
      stdio: ["ignore", "pipe", "pipe"],
    },
  );

  assert.match(output, /Smoke-Orchestrierungs-Test erfolgreich: 27 Szenarien\./);
});
