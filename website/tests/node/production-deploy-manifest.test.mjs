import assert from "node:assert/strict";
import { mkdtemp, mkdir, readFile, rm, writeFile } from "node:fs/promises";
import os from "node:os";
import path from "node:path";
import test from "node:test";
import { fileURLToPath } from "node:url";

import {
  assertSafeDeployPath,
  createDeployManifest,
  PRODUCTION_DEPLOY_CONSTANTS,
} from "../../scripts/prepare-production-deploy.mjs";

const websiteRoot = path.resolve(path.dirname(fileURLToPath(import.meta.url)), "../..");
const repositoryRoot = path.resolve(websiteRoot, "..");
const roots = [];

test.afterEach(async () => {
  await Promise.all(roots.splice(0).map((root) => rm(root, { recursive: true, force: true })));
});

async function fixture() {
  const root = await mkdtemp(path.join(os.tmpdir(), "carmaja-production-manifest-"));
  roots.push(root);
  const outputDirectory = path.join(root, "out");
  const packageDirectory = path.join(root, "package");
  await mkdir(path.join(outputDirectory, "armbaender"), { recursive: true });
  await writeFile(path.join(outputDirectory, "index.html"), "<html>production</html>\n");
  await writeFile(path.join(outputDirectory, "robots.txt"), "User-agent: *\n");
  await writeFile(path.join(outputDirectory, "sitemap.xml"), "<urlset/>\n");
  await writeFile(path.join(outputDirectory, "armbaender", "index.html"), "<html>products</html>\n");
  await writeFile(path.join(outputDirectory, "chunk$hash.js"), "export {};\n");
  return { outputDirectory, packageDirectory };
}

test("Produktionsmanifest bindet Export, Ziel, Commit und Release", async () => {
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
  assert.match(manifest, new RegExp(`^meta\\trepository\\t${PRODUCTION_DEPLOY_CONSTANTS.repository}$`, "m"));
  assert.match(manifest, /^meta\tbranch\tmain$/m);
  assert.match(manifest, /^meta\ttarget\tproduction$/m);
  assert.match(manifest, new RegExp(`^meta\\tcommit\\t${commitSha}$`, "m"));
  assert.match(manifest, new RegExp(`^meta\\trelease\\t${releaseId}$`, "m"));
  assert.deepEqual(result.files, [
    "armbaender/index.html",
    "chunk$hash.js",
    "index.html",
    "robots.txt",
    "sitemap.xml",
  ]);
});

test("Produktionsmanifest lehnt geschuetzte und private Serverpfade ab", () => {
  for (const candidate of [
    ".htaccess",
    "click.php",
    "pageview.php",
    "_internal/tracking.php",
    "statistik/index.html",
    "private-data/clicks.json",
    "products.json",
    "nested/runtime-config.php",
    "nested/.htpasswd",
  ]) {
    assert.throws(() => assertSafeDeployPath(candidate), /Private oder unerlaubte Datei/);
  }
});

test("Produktionsmanifest verweigert unvollstaendige oder ungebundene Exporte", async () => {
  const { outputDirectory, packageDirectory } = await fixture();
  const commitSha = "b".repeat(40);
  await rm(path.join(outputDirectory, "robots.txt"));

  assert.throws(
    () =>
      createDeployManifest({
        outputDirectory,
        packageDirectory,
        repositoryRoot,
        commitSha,
        releaseId: `${commitSha}-12345-1`,
      }),
    /erwarteten statischen Dateien/,
  );

  await writeFile(path.join(outputDirectory, "robots.txt"), "User-agent: *\n");
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

test("Release-Kandidaten sind als Manifestquelle gebunden und nicht mit beliebigen Branches austauschbar", async () => {
  const { outputDirectory, packageDirectory } = await fixture();
  const commitSha = "d".repeat(40);
  const releaseId = `${commitSha}-12345-1`;
  const candidate = createDeployManifest({
    outputDirectory,
    packageDirectory,
    repositoryRoot,
    commitSha,
    releaseId,
    sourceBranch: PRODUCTION_DEPLOY_CONSTANTS.candidateBranch,
  });
  const manifest = await readFile(candidate.manifestPath, "utf8");

  assert.match(
    manifest,
    /^meta\tbranch\trelease\/production-product-management$/m,
  );
  assert.throws(
    () =>
      createDeployManifest({
        outputDirectory,
        packageDirectory,
        repositoryRoot,
        commitSha,
        releaseId,
        sourceBranch: "test/product-management-beta",
      }),
    /nicht als Produktionsquelle freigegeben/,
  );
});
