import assert from "node:assert/strict";
import { execFileSync } from "node:child_process";
import { readFile } from "node:fs/promises";
import path from "node:path";
import test from "node:test";
import { fileURLToPath } from "node:url";

const websiteRoot = path.resolve(path.dirname(fileURLToPath(import.meta.url)), "../..");

test("Manifestdeployment sichert, behaelt unbekannte Dateien und rollt atomar zurueck", () => {
  const output = execFileSync(
    "bash",
    ["tests/shell/deploy-production-site-test.sh", "scripts/deploy-production-site.sh"],
    {
      cwd: websiteRoot,
      encoding: "utf8",
      stdio: ["ignore", "pipe", "pipe"],
    },
  );

  assert.match(output, /Produktionsdeployment-Shell-Test erfolgreich\./);
});

test("Webroot und privater Deploymentworkspace behalten getrennte Rechte", async () => {
  const script = await readFile(path.join(websiteRoot, "scripts/deploy-production-site.sh"), "utf8");

  assert.match(script, /ensure_public_directory/);
  assert.match(script, /chmod 0755 "\$WEBROOT"/);
  assert.match(script, /chmod 0755 "\$current_directory"/);
  assert.match(script, /chmod 0644 "\$temporary_file"/);
  assert.match(script, /find "\$release_directory" -type d -exec chmod 0750/);
  assert.match(script, /find "\$backup_directory" -type d -exec chmod 0750/);
  assert.match(script, /prune_directories "\$RELEASES" 4/);
  assert.match(script, /prune_directories "\$BACKUPS" 3/);
  assert.doesNotMatch(script, /find\s+-L/);
  assert.doesNotMatch(script, /rm -rf/);
  assert.doesNotMatch(script, /rsync[\s\S]*--delete/);
});
