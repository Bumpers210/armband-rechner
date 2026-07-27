import { access, cp, mkdir, readdir, rm } from "node:fs/promises";
import path from "node:path";

const projectRoot = process.cwd();
const hostingDirectory = path.join(projectRoot, "hosting");
const outputDirectory = path.join(projectRoot, "out");
const productsFile = path.join(projectRoot, "content", "products.json");

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

await mkdir(path.join(outputDirectory, "_internal"), { recursive: true });
await cp(productsFile, path.join(outputDirectory, "_internal", "public-products.json"), {
  force: true,
});
await rm(path.join(outputDirectory, "armbaender", "__empty"), {
  force: true,
  recursive: true,
});

console.log("IONOS hosting files copied to out/.");
