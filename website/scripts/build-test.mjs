import { cp, mkdir, mkdtemp, rm, writeFile } from "node:fs/promises";
import os from "node:os";
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
let generatedFixtureRoot = null;

if (environment.CARMAJA_PRODUCTS_FILE === undefined) {
  generatedFixtureRoot = await mkdtemp(
    path.join(os.tmpdir(), "carmaja-build-test-"),
  );
  const generatedImageDirectory = path.join(
    generatedFixtureRoot,
    "images",
    "products",
  );
  const generatedProductDirectory = path.join(
    generatedImageDirectory,
    "CP-2026-0001",
  );
  await mkdir(generatedProductDirectory, { recursive: true });
  await cp(
    path.join(
      projectRoot,
      "public",
      "images",
      "bracelets",
      "hero-dunkelrot-braun-holz.jpg",
    ),
    path.join(generatedProductDirectory, "01.jpg"),
  );
  environment.CARMAJA_TEST_FIXTURES = "true";
  environment.CARMAJA_PRODUCTS_FILE = path.join(
    projectRoot,
    "tests",
    "public-products-v2.fixture.json",
  );
  environment.CARMAJA_PRODUCT_IMAGES_DIR = generatedImageDirectory;
}

function run(command, argumentsList) {
  const result = spawnSync(command, argumentsList, {
    cwd: projectRoot,
    env: environment,
    stdio: "inherit",
  });

  if (result.status !== 0) {
    throw new Error(
      `Testbuild-Schritt fehlgeschlagen (${result.status ?? "unbekannt"}).`,
    );
  }
}

try {
  run(process.execPath, [
    path.join(projectRoot, "node_modules", "next", "dist", "bin", "next"),
    "build",
  ]);
  await writeFile(
    path.join(outputDirectory, "robots.txt"),
    "User-agent: *\nDisallow: /\n",
    "utf8",
  );
  run(process.execPath, [path.join(projectRoot, "scripts", "copy-hosting-files.mjs")]);
  run(process.execPath, [path.join(projectRoot, "scripts", "verify-test-export.mjs")]);
} finally {
  if (generatedFixtureRoot !== null) {
    await rm(generatedFixtureRoot, { recursive: true, force: true });
  }
}
