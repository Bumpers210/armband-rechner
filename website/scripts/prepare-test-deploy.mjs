import { createHash } from "node:crypto";
import { execFileSync } from "node:child_process";
import {
  lstatSync,
  mkdirSync,
  readFileSync,
  readdirSync,
  rmSync,
  statSync,
  writeFileSync,
} from "node:fs";
import path from "node:path";
import { fileURLToPath } from "node:url";

export const TEST_DEPLOY_CONSTANTS = Object.freeze({
  repository: "Bumpers210/armband-rechner",
  branch: "test/product-management-beta",
  target: "test",
  domain: "test.carmaja-perlen.de",
  webroot: "/home/www/carmaja-test-site",
  workspace: "/home/www/carmaja-test-deploy",
});

const forbiddenRuntimeNames = new Set([
  "runtime-config.php",
  "environment.json",
  "api-users.json",
  "device-tokens.json",
  "login-attempts.json",
  "test-website.htpasswd",
  ".htpasswd",
]);
const forbiddenExportPatterns = [
  /(?:^|\/)(?:\.htpasswd|test-website\.htpasswd)$/i,
  /(?:^|\/)products\.json$/i,
  /(?:^|\/)public-products\.json$/i,
  /(?:^|\/)runtime-config(?:\.example)?\.php$/i,
  /(?:^|\/)click\.php$/i,
  /(?:^|\/)statistik(?:\/|$)/i,
  /(?:^|\/)_internal(?:\/|$)/i,
  /(?:^|\/)(?:api-users|device-tokens|login-attempts)\.json$/i,
  /(?:^|\/)(?:audit|backups?|drafts?|idempotency)(?:\/|$)/i,
];
const secretPatterns = [
  new RegExp("github_" + "pat_[A-Za-z0-9_]{20,}"),
  new RegExp("gh" + "[pousr]_[A-Za-z0-9]{20,}"),
  new RegExp("-----BEGIN " + "(?:RSA |EC |OPENSSH )?PRIVATE KEY-----"),
];

function normalizeRelativePath(value) {
  return value.split(path.sep).join("/");
}

export function assertSafeDeployPath(relativePath) {
  if (
    relativePath.length === 0 ||
    relativePath.startsWith("/") ||
    relativePath.includes("\\") ||
    relativePath.includes("//") ||
    !/^[A-Za-z0-9._~$/-]+$/.test(relativePath)
  ) {
    throw new Error(`Unsicherer Deploymentpfad: ${relativePath}`);
  }

  const segments = relativePath.split("/");

  if (segments.some((segment) => segment === "" || segment === "." || segment === "..")) {
    throw new Error(`Unsicherer Deploymentpfad: ${relativePath}`);
  }

  if (forbiddenExportPatterns.some((pattern) => pattern.test(relativePath))) {
    throw new Error(`Private oder unerlaubte Datei im Testexport: ${relativePath}`);
  }
}

function listFiles(directory, prefix = "") {
  const files = [];

  for (const entry of readdirSync(directory, { withFileTypes: true })) {
    const absolutePath = path.join(directory, entry.name);
    const relativePath = normalizeRelativePath(path.join(prefix, entry.name));
    const metadata = lstatSync(absolutePath);

    if (metadata.isSymbolicLink()) {
      throw new Error(`Symlink im Testexport ist nicht erlaubt: ${relativePath}`);
    }

    if (entry.isDirectory()) {
      files.push(...listFiles(absolutePath, relativePath));
    } else if (entry.isFile()) {
      assertSafeDeployPath(relativePath);
      files.push({ absolutePath, relativePath });
    } else {
      throw new Error(`Unerlaubter Dateityp im Testexport: ${relativePath}`);
    }
  }

  return files;
}

function sha256File(filePath) {
  return createHash("sha256").update(readFileSync(filePath)).digest("hex");
}

export function scanTrackedSources(repositoryRoot) {
  const raw = execFileSync("git", ["ls-files", "-z"], {
    cwd: repositoryRoot,
    encoding: "utf8",
  });
  const tracked = raw.split("\0").filter(Boolean);

  for (const relativePath of tracked) {
    const normalized = relativePath.replaceAll("\\", "/");
    const baseName = path.posix.basename(normalized);

    if (forbiddenRuntimeNames.has(baseName)) {
      throw new Error(`Private Laufzeitdatei ist versioniert: ${normalized}`);
    }

    if (
      /(?:^|\/)(?:private-uploads?|real-uploads?|backups?|auditlogs?)(?:\/|$)/i.test(
        normalized,
      )
    ) {
      throw new Error(`Privater Datenpfad ist versioniert: ${normalized}`);
    }

    if (normalized.startsWith("website/hosting/")) {
      continue;
    }

    const absolutePath = path.join(repositoryRoot, ...normalized.split("/"));
    const metadata = statSync(absolutePath);

    if (!metadata.isFile() || metadata.size > 2_000_000) {
      continue;
    }

    const contents = readFileSync(absolutePath);

    if (contents.includes(0)) {
      continue;
    }

    const text = contents.toString("utf8");

    if (secretPatterns.some((pattern) => pattern.test(text))) {
      throw new Error(`Moeglicher Geheimwert in versionierter Datei: ${normalized}`);
    }
  }
}

export function createDeployManifest({
  outputDirectory,
  packageDirectory,
  repositoryRoot,
  commitSha,
  releaseId,
}) {
  if (!/^[0-9a-f]{40}$/.test(commitSha)) {
    throw new Error("Deployment-Commit muss eine vollstaendige Git-SHA sein.");
  }

  if (
    !/^[0-9a-f]{40}-[0-9]+-[0-9]+$/.test(releaseId) ||
    !releaseId.startsWith(`${commitSha}-`)
  ) {
    throw new Error("Release-ID ist nicht an Commit und Workflowlauf gebunden.");
  }

  scanTrackedSources(repositoryRoot);

  const files = listFiles(outputDirectory).sort((left, right) =>
    left.relativePath.localeCompare(right.relativePath, "en"),
  );

  if (files.length === 0 || !files.some((file) => file.relativePath === ".htaccess")) {
    throw new Error("Testexport ist leer oder enthaelt keine .htaccess.");
  }

  const lines = [
    "manifest\t1",
    `meta\trepository\t${TEST_DEPLOY_CONSTANTS.repository}`,
    `meta\tbranch\t${TEST_DEPLOY_CONSTANTS.branch}`,
    `meta\ttarget\t${TEST_DEPLOY_CONSTANTS.target}`,
    `meta\tdomain\t${TEST_DEPLOY_CONSTANTS.domain}`,
    `meta\twebroot\t${TEST_DEPLOY_CONSTANTS.webroot}`,
    `meta\tworkspace\t${TEST_DEPLOY_CONSTANTS.workspace}`,
    `meta\tcommit\t${commitSha}`,
    `meta\trelease\t${releaseId}`,
  ];

  for (const file of files) {
    const size = statSync(file.absolutePath).size;
    lines.push(`file\t${sha256File(file.absolutePath)}\t${size}\t${file.relativePath}`);
  }

  rmSync(packageDirectory, { recursive: true, force: true });
  mkdirSync(packageDirectory, { recursive: true });
  const manifestPath = path.join(packageDirectory, "manifest.tsv");
  writeFileSync(manifestPath, `${lines.join("\n")}\n`, { encoding: "utf8", mode: 0o640 });

  return {
    manifestPath,
    fileCount: files.length,
    files: files.map((file) => file.relativePath),
  };
}

function runCli() {
  const projectRoot = process.cwd();
  const repositoryRoot = path.resolve(projectRoot, "..");
  const outputDirectory = path.resolve(projectRoot, "out-test");
  const packageDirectory = path.resolve(projectRoot, ".test-deploy-package");

  if (
    path.dirname(packageDirectory) !== projectRoot ||
    path.basename(packageDirectory) !== ".test-deploy-package"
  ) {
    throw new Error("Unsicheres lokales Deployment-Paketverzeichnis.");
  }

  const expectedEnvironment = {
    CARMAJA_SITE_TARGET: TEST_DEPLOY_CONSTANTS.target,
    CARMAJA_SITE_DOMAIN: TEST_DEPLOY_CONSTANTS.domain,
    CARMAJA_TEST_WEBROOT: TEST_DEPLOY_CONSTANTS.webroot,
    CARMAJA_TEST_DEPLOY_WORKSPACE: TEST_DEPLOY_CONSTANTS.workspace,
    CARMAJA_PRODUCTION_PUBLISH_ENABLED: "false",
    CARMAJA_PRODUCTION_DEPLOY_ENABLED: "false",
    GITHUB_REPOSITORY: TEST_DEPLOY_CONSTANTS.repository,
    GITHUB_REF_NAME: TEST_DEPLOY_CONSTANTS.branch,
  };

  for (const [name, expected] of Object.entries(expectedEnvironment)) {
    if (process.env[name] !== expected) {
      throw new Error(`${name} muss exakt ${expected} sein.`);
    }
  }

  const result = createDeployManifest({
    outputDirectory,
    packageDirectory,
    repositoryRoot,
    commitSha: process.env.GITHUB_SHA ?? "",
    releaseId: process.env.CARMAJA_RELEASE_ID ?? "",
  });
  console.log(`Testdeployment-Manifest erstellt: ${result.fileCount} Dateien.`);
}

const isCli =
  process.argv[1] !== undefined &&
  path.resolve(process.argv[1]) === fileURLToPath(import.meta.url);

if (isCli) {
  runCli();
}
