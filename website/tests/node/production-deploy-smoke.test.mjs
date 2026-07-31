import assert from "node:assert/strict";
import { execFileSync } from "node:child_process";
import path from "node:path";
import test from "node:test";
import { fileURLToPath } from "node:url";

const websiteRoot = path.resolve(path.dirname(fileURLToPath(import.meta.url)), "../..");

test("Produktions-Smoke-Orchestrierung prueft Erfolg, Fehler und Rollback lokal", () => {
  const output = execFileSync(
    "bash",
    ["tests/shell/run-production-deployment-test.sh", "scripts/run-production-deployment.sh"],
    {
      cwd: websiteRoot,
      encoding: "utf8",
      stdio: ["ignore", "pipe", "pipe"],
    },
  );

  assert.match(output, /Produktions-Smoke-Orchestrierungs-Test erfolgreich: 7 Szenarien\./);
});
