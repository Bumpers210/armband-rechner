import assert from "node:assert/strict";
import { execFileSync } from "node:child_process";
import { readFile } from "node:fs/promises";
import path from "node:path";
import test from "node:test";
import { fileURLToPath } from "node:url";

const websiteRoot = path.resolve(path.dirname(fileURLToPath(import.meta.url)), "../..");
const repositoryRoot = path.resolve(websiteRoot, "..");

async function readRepositoryFile(relativePath) {
  return readFile(path.join(repositoryRoot, ...relativePath.split("/")), "utf8");
}

test("Basic-Auth-Diagnose prueft sicher und klassifiziert ohne Geheimwertausgabe", () => {
  const output = execFileSync(
    "bash",
    [
      "tests/shell/diagnose-test-basic-auth-test.sh",
      "scripts/diagnose-test-basic-auth.sh",
    ],
    {
      cwd: websiteRoot,
      encoding: "utf8",
      stdio: ["ignore", "pipe", "pipe"],
    },
  );

  assert.match(output, /Basic-Auth-Diagnose-Shell-Test erfolgreich\./);
});

test("Diagnoseskript behaelt Passwort und Benutzer aus Argumenten und Umgebung fern", async () => {
  const script = await readRepositoryFile(
    "website/scripts/diagnose-test-basic-auth.sh",
  );

  assert.match(script, /^#!\/usr\/bin\/env bash/m);
  assert.match(script, /^set -eu$/m);
  assert.doesNotMatch(script, /set -x/);
  assert.doesNotMatch(script, /htpasswd\s+-(?:[A-Za-z]*b|[A-Za-z]*i)/);
  assert.doesNotMatch(script, /(?:PASSWORD|PASSWORT)=/);
  assert.doesNotMatch(script, /export\s+.*(?:PASSWORD|PASSWORT)/);
  assert.doesNotMatch(script, /(?:echo|printf)[^\n]*\|\s*htpasswd/);
  assert.match(script, /htpasswd -v "\$AUTH_FILE" "\$AUTH_USER"/);
  assert.match(script, /htpasswd -B "\$AUTH_FILE" "\$AUTH_USER"/);
  assert.doesNotMatch(script, /htpasswd\s+-[^ \n]*c/);
  assert.match(script, /unset AUTH_USER/g);
  assert.match(
    script,
    /Passwort für bestehenden Benutzer interaktiv neu setzen\? \[y\/N\]/,
  );
  assert.match(script, /\[ "\$#" -eq 1 \]/);
});

test("Diagnose bleibt ausserhalb Workflow, Artefakt und Deploymentlogik", async () => {
  const workflow = await readRepositoryFile(
    ".github/workflows/deploy-test-website.yml",
  );
  const manifestScript = await readRepositoryFile(
    "website/scripts/prepare-test-deploy.mjs",
  );
  const deployScript = await readRepositoryFile(
    "website/scripts/deploy-test-site.sh",
  );

  assert.doesNotMatch(workflow, /diagnose-test-basic-auth/);
  assert.match(manifestScript, /"test-website\.htpasswd"/);
  assert.match(manifestScript, /"\.htpasswd"/);
  assert.match(deployScript, /\.htpasswd\|\*\/\.htpasswd/);
  assert.match(deployScript, /rollback_files/);
  assert.doesNotMatch(deployScript, /diagnose-test-basic-auth/);
});

test("Dokumentation trennt Diagnose, interaktive Pruefung und spaetere Korrektur", async () => {
  const documentation = await readRepositoryFile(
    "website/docs/product-management-setup.md",
  );

  assert.match(documentation, /diagnose-test-basic-auth\.sh --diagnose/);
  assert.match(documentation, /diagnose-test-basic-auth\.sh --verify/);
  assert.match(documentation, /diagnose-test-basic-auth\.sh --reset/);
  assert.match(documentation, /htpasswd -v "\$AUTH_FILE" "\$AUTH_USER"/);
  assert.match(documentation, /htpasswd -B "\$AUTH_FILE" "\$AUTH_USER"/);
  assert.match(documentation, /if \[ ! -e "\$AUTH_FILE" \]; then/);
  assert.match(documentation, /htpasswd -Bc "\$AUTH_FILE" "\$AUTH_USER"/);
  assert.match(documentation, /curl --disable -I -u "\$AUTH_USER"/);
  assert.match(documentation, /unset AUTH_USER/);
  assert.doesNotMatch(documentation, /curl[^\n]*-u\s+\S+:\S+/);
  assert.match(
    documentation,
    /Verboten bleiben insbesondere `chmod 777`, `chmod -R`, `chown -R`/,
  );
});
