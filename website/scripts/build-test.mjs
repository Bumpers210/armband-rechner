import { rm } from "node:fs/promises";
import path from "node:path";
import { spawnSync } from "node:child_process";

const projectRoot = process.cwd();
const outputDirectory = path.resolve(projectRoot, "out-test");

if (
  path.dirname(outputDirectory) !== path.resolve(projectRoot) ||
  path.basename(outputDirectory) !== "out-test"
) {
  throw new Error("Unsicheres Test-Ausgabeverzeichnis.");
}

if (
  process.env.CARMAJA_SITE_TARGET !== undefined &&
  process.env.CARMAJA_SITE_TARGET !== "test"
) {
  throw new Error("build:test akzeptiert ausschließlich CARMAJA_SITE_TARGET=test.");
}

if (
  process.env.CARMAJA_SITE_URL !== undefined &&
  process.env.CARMAJA_SITE_URL !== "https://test.carmaja-perlen.de"
) {
  throw new Error(
    "build:test akzeptiert ausschließlich https://test.carmaja-perlen.de.",
  );
}

if (
  process.env.CARMAJA_PRODUCTION_PUBLISH_ENABLED === "true" ||
  process.env.CARMAJA_PRODUCTION_DEPLOY_ENABLED === "true"
) {
  throw new Error("Produktionsfunktionen sind für build:test gesperrt.");
}

await rm(outputDirectory, { recursive: true, force: true });

const environment = {
  ...process.env,
  CARMAJA_SITE_TARGET: "test",
  CARMAJA_SITE_URL: "https://test.carmaja-perlen.de",
  CARMAJA_PRODUCTION_PUBLISH_ENABLED: "false",
  CARMAJA_PRODUCTION_DEPLOY_ENABLED: "false",
  NEXT_TELEMETRY_DISABLED: "1",
};

function run(command, argumentsList) {
  const result = spawnSync(command, argumentsList, {
    cwd: projectRoot,
    env: environment,
    stdio: "inherit",
  });

  if (result.status !== 0) {
    process.exit(result.status ?? 1);
  }
}

run(process.execPath, [
  path.join(projectRoot, "node_modules", "next", "dist", "bin", "next"),
  "build",
]);
run(process.execPath, [path.join(projectRoot, "scripts", "copy-hosting-files.mjs")]);
run(process.execPath, [path.join(projectRoot, "scripts", "verify-test-export.mjs")]);
