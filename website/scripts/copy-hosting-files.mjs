import { access, cp, mkdir, readdir, rm } from "node:fs/promises";
import path from "node:path";

const projectRoot = process.cwd();
const siteTarget = process.env.CARMAJA_SITE_TARGET ?? "production";
const isTest = siteTarget === "test";
const hostingDirectory = path.join(
  projectRoot,
  isTest ? "hosting-test" : "hosting",
);
const outputDirectory = path.join(projectRoot, isTest ? "out-test" : "out");

if (siteTarget !== "production" && siteTarget !== "test") {
  throw new Error("Unbekanntes Websiteziel.");
}

await access(outputDirectory);

const entries = await readdir(hostingDirectory, { withFileTypes: true });

for (const entry of entries) {
  if (entry.name === ".gitignore") {
    continue;
  }

  await cp(
    path.join(hostingDirectory, entry.name),
    path.join(outputDirectory, entry.name),
    {
      recursive: true,
      force: true,
    },
  );
}

if (isTest) {
  const fixtureImages = process.env.CARMAJA_PRODUCT_IMAGES_DIR;

  if (fixtureImages) {
    if (process.env.CARMAJA_TEST_FIXTURES !== "true") {
      throw new Error(
        "Alternative Produktbilder sind nur für isolierte Testfixtures erlaubt.",
      );
    }

    await mkdir(path.join(outputDirectory, "images", "products"), {
      recursive: true,
    });
    await cp(
      path.resolve(fixtureImages),
      path.join(outputDirectory, "images", "products"),
      {
        recursive: true,
        force: true,
      },
    );
  }
}

await rm(path.join(outputDirectory, "armbaender", "__empty"), {
  force: true,
  recursive: true,
});

console.log(
  `${isTest ? "Test" : "Produktions"}-Hostingdateien nach ${
    isTest ? "out-test/" : "out/"
  } kopiert.`,
);
