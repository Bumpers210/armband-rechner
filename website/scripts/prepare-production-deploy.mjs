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

export const PRODUCTION_DEPLOY_CONSTANTS = Object.freeze({
  repository: "Bumpers210/armband-rechner",
  branch: "main",
  candidateBranch: "release/production-product-management",
  target: "production",
  domain: "www.carmaja-perlen.de",
  webroot: "/home/www/carmaja",
  workspace: "/home/www/carmaja-production-deploy",
});

const forbiddenRuntimeNames = new Set([
  "runtime-config.php",
  "environment.json",
  "api-users.json",
  "device-tokens.json",
  "login-attempts.json",
  ".htpasswd",
]);
const forbiddenExportPatterns = [
  /(?:^|\/)(?:\.htaccess|\.htpasswd)$/i,
  /(?:^|\/)products\.json$/i,
  /(?:^|\/)public-products\.json$/i,
  /(?:^|\/)runtime-config(?:\.example)?\.php$/i,
  /(?:^|\/)click\.php$/i,
  /(?:^|\/)statistik(?:\/|$)/i,
  /(?:^|\/)_internal(?:\/|$)/i,
  /(?:^|\/)(?:private-data|hosting|hosting-test)(?:\/|$)/i,
  /(?:^|\/)test-api-(?:private|public)(?:\/|$)/i,
  /(?:^|\/)(?:api-users|device-tokens|login-attempts)\.json$/i,
  /(?:^|\/)(?:audit|backups?|drafts?|idempotency)(?:\/|$)/i,
  /\.php$/i,
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
    throw new Error(`Private oder unerlaubte Datei im Produktionsexport: ${relativePath}`);
  }
}

function listFiles(directory, prefix = "") {
  const files = [];

  for (const entry of readdirSync(directory, { withFileTypes: true })) {
    const absolutePath = path.join(directory, entry.name);
    const relativePath = normalizeRelativePath(path.join(prefix, entry.name));
    const metadata = lstatSync(absolutePath);

    if (metadata.isSymbolicLink()) {
      throw new Error(`Symlink im Produktionsexport ist nicht erlaubt: ${relativePath}`);
    }

    if (entry.isDirectory()) {
      files.push(...listFiles(absolutePath, relativePath));
    } else if (entry.isFile()) {
      assertSafeDeployPath(relativePath);
      files.push({ absolutePath, relativePath });
    } else {
      throw new Error(`Unerlaubter Dateityp im Produktionsexport: ${relativePath}`);
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
  sourceBranch = PRODUCTION_DEPLOY_CONSTANTS.branch,
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

  if (
    sourceBranch !== PRODUCTION_DEPLOY_CONSTANTS.branch &&
    sourceBranch !== PRODUCTION_DEPLOY_CONSTANTS.candidateBranch
  ) {
    throw new Error("Manifest-Branch ist nicht als Produktionsquelle freigegeben.");
  }

  scanTrackedSources(repositoryRoot);

  const files = listFiles(outputDirectory).sort((left, right) =>
    left.relativePath.localeCompare(right.relativePath, "en"),
  );

  if (
    files.length === 0 ||
    !files.some((file) => file.relativePath === "index.html") ||
    !files.some((file) => file.relativePath === "robots.txt") ||
    !files.some((file) => file.relativePath === "sitemap.xml") ||
    !files.some((file) => file.relativePath === "armbaender/index.html")
  ) {
    throw new Error("Produktionsexport enthaelt nicht alle erwarteten statischen Dateien.");
  }

  const lines = [
    "manifest\t1",
    `meta\trepository\t${PRODUCTION_DEPLOY_CONSTANTS.repository}`,
    `meta\tbranch\t${sourceBranch}`,
    `meta\ttarget\t${PRODUCTION_DEPLOY_CONSTANTS.target}`,
    `meta\tdomain\t${PRODUCTION_DEPLOY_CONSTANTS.domain}`,
    `meta\twebroot\t${PRODUCTION_DEPLOY_CONSTANTS.webroot}`,
    `meta\tworkspace\t${PRODUCTION_DEPLOY_CONSTANTS.workspace}`,
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
  const outputDirectory = path.resolve(projectRoot, "out");
  const packageDirectory = path.resolve(projectRoot, ".production-deploy-package");

  if (
    path.dirname(packageDirectory) !== projectRoot ||
    path.basename(packageDirectory) !== ".production-deploy-package"
  ) {
    throw new Error("Unsicheres lokales Deployment-Paketverzeichnis.");
  }

  const expectedEnvironment = {
    CARMAJA_SITE_TARGET: PRODUCTION_DEPLOY_CONSTANTS.target,
    CARMAJA_SITE_DOMAIN: PRODUCTION_DEPLOY_CONSTANTS.domain,
    CARMAJA_PRODUCTION_WEBROOT: PRODUCTION_DEPLOY_CONSTANTS.webroot,
    CARMAJA_PRODUCTION_DEPLOY_WORKSPACE: PRODUCTION_DEPLOY_CONSTANTS.workspace,
    CARMAJA_PRODUCTION_PUBLISH_ENABLED: "false",
    CARMAJA_PRODUCTION_DEPLOY_ENABLED: "false",
    GITHUB_REPOSITORY: PRODUCTION_DEPLOY_CONSTANTS.repository,
  };

  for (const [name, expected] of Object.entries(expectedEnvironment)) {
    if (process.env[name] !== expected) {
      throw new Error(`${name} muss exakt ${expected} sein.`);
    }
  }

  const sourceBranch = process.env.GITHUB_REF_NAME ?? "";
  const result = createDeployManifest({
    outputDirectory,
    packageDirectory,
    repositoryRoot,
    commitSha: process.env.GITHUB_SHA ?? "",
    releaseId: process.env.CARMAJA_RELEASE_ID ?? "",
    sourceBranch,
  });
  console.log(`Produktionsdeployment-Manifest erstellt: ${result.fileCount} Dateien.`);
}

const isCli =
  process.argv[1] !== undefined &&
  path.resolve(process.argv[1]) === fileURLToPath(import.meta.url);

if (isCli) {
  runCli();
}
