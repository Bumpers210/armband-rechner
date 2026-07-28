import assert from "node:assert/strict";
import { mkdtemp, mkdir, readFile, rm, writeFile } from "node:fs/promises";
import os from "node:os";
import path from "node:path";
import test from "node:test";
import { fileURLToPath } from "node:url";

import {
  createDeployManifest,
  TEST_DEPLOY_CONSTANTS,
} from "../../scripts/prepare-test-deploy.mjs";

const websiteRoot = path.resolve(path.dirname(fileURLToPath(import.meta.url)), "../..");
const repositoryRoot = path.resolve(websiteRoot, "..");
const roots = [];

test.afterEach(async () => {
  await Promise.all(roots.splice(0).map((root) => rm(root, { recursive: true, force: true })));
});

async function fixture() {
  const root = await mkdtemp(path.join(os.tmpdir(), "carmaja-deploy-manifest-"));
  roots.push(root);
  const outputDirectory = path.join(root, "out-test");
  const packageDirectory = path.join(root, "package");
  await mkdir(path.join(outputDirectory, "armbaender"), { recursive: true });
  await writeFile(path.join(outputDirectory, ".htaccess"), "Require valid-user\n");
  await writeFile(path.join(outputDirectory, "index.html"), "<html>test</html>\n");
  await writeFile(path.join(outputDirectory, "chunk$hash.js"), "export {};\n");
  await writeFile(path.join(outputDirectory, "chunk~hash.js"), "export {};\n");
  await writeFile(
    path.join(outputDirectory, "armbaender", "index.html"),
    "<html>products</html>\n",
  );
  return { outputDirectory, packageDirectory };
}

test("Deploymentmanifest bindet Ziel, Commit und alle Exportdateien", async () => {
  const { outputDirectory, packageDirectory } = await fixture();
  const commitSha = "a".repeat(40);
  const releaseId = `${commitSha}-12345-1`;
  const result = createDeployManifest({
    outputDirectory,
    packageDirectory,
    repositoryRoot,
    commitSha,
    releaseId,
  });
  const manifest = await readFile(result.manifestPath, "utf8");

  assert.equal(result.fileCount, 5);
  assert.match(manifest, /^manifest\t1$/m);
  assert.match(manifest, new RegExp(`^meta\\trepository\\t${TEST_DEPLOY_CONSTANTS.repository}$`, "m"));
  assert.match(manifest, new RegExp(`^meta\\tbranch\\t${TEST_DEPLOY_CONSTANTS.branch}$`, "m"));
  assert.match(manifest, new RegExp(`^meta\\tcommit\\t${commitSha}$`, "m"));
  assert.match(manifest, new RegExp(`^meta\\trelease\\t${releaseId}$`, "m"));
  assert.match(manifest, /^file\t[0-9a-f]{64}\t\d+\t\.htaccess$/m);
  assert.deepEqual(result.files, [
    ".htaccess",
    "armbaender/index.html",
    "chunk~hash.js",
    "chunk$hash.js",
    "index.html",
  ]);
});

test("Deploymentmanifest lehnt private Dateien und ungebundene Releases ab", async () => {
  const { outputDirectory, packageDirectory } = await fixture();
  const commitSha = "b".repeat(40);
  await writeFile(path.join(outputDirectory, "products.json"), "{}\n");

  assert.throws(
    () =>
      createDeployManifest({
        outputDirectory,
        packageDirectory,
        repositoryRoot,
        commitSha,
        releaseId: `${commitSha}-12345-1`,
      }),
    /Private oder unerlaubte Datei/,
  );

  await rm(path.join(outputDirectory, "products.json"));
  assert.throws(
    () =>
      createDeployManifest({
        outputDirectory,
        packageDirectory,
        repositoryRoot,
        commitSha,
        releaseId: `${"c".repeat(40)}-12345-1`,
      }),
    /nicht an Commit/,
  );
});
