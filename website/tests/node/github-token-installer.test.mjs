import assert from "node:assert/strict";
import { execFileSync } from "node:child_process";
import { readFile } from "node:fs/promises";
import path from "node:path";
import test from "node:test";
import { fileURLToPath } from "node:url";

const websiteRoot = path.resolve(path.dirname(fileURLToPath(import.meta.url)), "../..");

async function readWebsiteFile(relativePath) {
  return readFile(path.join(websiteRoot, ...relativePath.split("/")), "utf8");
}

test("Token-Installer maskiert, bestaetigt und speichert erst nach Read-only-Diagnose", () => {
  const output = execFileSync(
    "bash",
    [
      "tests/shell/install-test-github-token-test.sh",
      "scripts/install-test-github-token.sh",
    ],
    {
      cwd: websiteRoot,
      encoding: "utf8",
      stdio: ["ignore", "pipe", "pipe"],
    },
  );

  assert.match(output, /Sicherer GitHub-Token-Installer-Shell-Test erfolgreich\./);
});

test("Token-Installer verwendet keine unsicheren Geheimwertkanaele", async () => {
  const script = await readWebsiteFile("scripts/install-test-github-token.sh");

  assert.match(script, /^#!\/usr\/bin\/env bash/m);
  assert.match(script, /^set \+x$/m);
  assert.match(script, /readonly MAX_ATTEMPTS=3/);
  assert.match(script, /read -r -s -n 1 character/);
  assert.match(script, /printf '\*'/);
  assert.match(script, /\$'\\177'\|\$'\\b'/);
  assert.match(script, /Token erkannt: %d Zeichen/);
  assert.match(script, /\[y\/N\]/);
  assert.match(script, /--github-readonly-token-stdin/);
  assert.match(script, /GitHub-Diagnose fehlgeschlagen \(HTTP 403\)/);
  assert.match(script, /GitHub-Diagnose fehlgeschlagen \(HTTP 404\)/);
  assert.match(script, /GitHub-Diagnose fehlgeschlagen \(HTTP 429\)/);
  assert.match(script, /GitHub-Diagnose fehlgeschlagen \(HTTP 500\)/);
  assert.match(script, /GitHub-Diagnose fehlgeschlagen \(HTTP 502\)/);
  assert.match(script, /GitHub-Diagnose fehlgeschlagen \(HTTP 503\)/);
  assert.match(script, /GitHub-Diagnose fehlgeschlagen \(Produktdatei nicht lesbar\)/);
  assert.match(script, /GitHub-Diagnose fehlgeschlagen \(interner Diagnosefehler\)/);
  assert.match(script, /GitHub-Diagnose lieferte unvollstaendige Erfolgsdaten/);
  assert.match(script, /chmod 0640 "\$\{TOKEN_TEMP\}"/);
  assert.match(script, /mv -f -- "\$\{TOKEN_TEMP\}" "\$\{TOKEN_FILE\}"/);
  assert.doesNotMatch(script, /export\s+TOKEN_INPUT/);
  assert.doesNotMatch(script, /githubAdapterEnabled=true/);
  assert.doesNotMatch(script, /scp|sftp|ssh|curl/);
  assert.doesNotMatch(script, /\s-O(?:\s|$)/m);

  const diagnosticPosition = script.indexOf("run_readonly_diagnostic");
  const storagePosition = script.indexOf("store_token_atomically", diagnosticPosition);
  assert.ok(diagnosticPosition >= 0 && storagePosition > diagnosticPosition);

  execFileSync("bash", ["-n", "scripts/install-test-github-token.sh"], {
    cwd: websiteRoot,
    stdio: ["ignore", "pipe", "pipe"],
  });
});

test("PHP-Diagnose nimmt den Kandidaten nur per stdin und nur bei deaktiviertem Adapter an", async () => {
  const diagnostic = await readWebsiteFile(
    "test-api-private/program/product-api-diagnostics.php",
  );
  const api = await readWebsiteFile("test-api-private/program/product-api.php");

  assert.match(diagnostic, /\['--github-readonly-token-stdin'\]/);
  assert.match(diagnostic, /stream_get_contents\(STDIN, 514\)/);
  assert.match(diagnostic, /\$config\['githubAdapterEnabled'\]/);
  assert.match(diagnostic, /\$config\['publishTarget'\] !== 'test'/);
  assert.match(diagnostic, /\$config\['productionPublishEnabled'\]/);
  assert.match(diagnostic, /unset\(\$GLOBALS\['CARMAJA_API_GITHUB_READONLY_TOKEN'\]\)/);
  assert.doesNotMatch(diagnostic, /getenv\([^)]*TOKEN/);
  assert.match(api, /if \(!\$requireEnabled\)/);
  assert.match(api, /carmaja_api_validate_github_token/);
});
