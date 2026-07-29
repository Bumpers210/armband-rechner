import assert from "node:assert/strict";
import {
  access,
  cp,
  mkdir,
  mkdtemp,
  readFile,
  rm,
  writeFile,
} from "node:fs/promises";
import os from "node:os";
import path from "node:path";
import { spawnSync } from "node:child_process";
import test from "node:test";

const projectRoot = process.cwd();
const testAuthPath = ["/home", "www", "carmaja-test-auth", "test-website.htpasswd"].join(
  "/",
);
const previousAuthPath = [
  "/home",
  "www",
  "carmaja-private-test",
  "auth",
  "test-website.htpasswd",
].join("/");
const sourceImage = path.join(
  projectRoot,
  "public",
  "images",
  "bracelets",
  "hero-dunkelrot-braun-holz.jpg",
);

function product(sequence, status, vintedUrl) {
  const sku = `CP-2026-${String(sequence).padStart(4, "0")}`;
  const slug = `${sku.toLowerCase()}-${status}`;

  return {
    sku,
    slug,
    title: `${status} Testarmband ${sequence}`,
    description: `Statische Prüfung für den Status ${status}.`,
    materials: ["Rosenquarz"],
    metalElements: [],
    size: "17 cm",
    stock: status === "sold" ? 0 : 1,
    status,
    images: [
      {
        src: `/images/products/${sku}/01.jpg`,
        alt: `${status} Testarmband`,
        width: 2048,
        height: 1536,
        isMain: true,
      },
    ],
    careInstructions: ["Vor Wasser schützen"],
    updatedAt: `2026-07-2${sequence}T10:00:00.000Z`,
    ...(vintedUrl ? { vintedUrl } : {}),
  };
}

test(
  "frischer Testexport erfüllt Status-, Metadaten- und Schutzregeln",
  { timeout: 180_000 },
  async () => {
    const fixtureRoot = await mkdtemp(
      path.join(os.tmpdir(), "carmaja-test-build-"),
    );
    const imageRoot = path.join(fixtureRoot, "images", "products");
    const productsFile = path.join(fixtureRoot, "products.json");
    const products = [
      product(1, "published", "https://www.vinted.de/items/1001-test"),
      product(2, "published"),
      product(3, "sold", "https://vinted.de/items/1003-sold"),
      product(4, "disabled"),
      product(5, "draft"),
      product(6, "ready"),
    ];
    const stalePage = path.join(
      projectRoot,
      "out-test",
      "armbaender",
      "veraltet",
      "index.html",
    );

    try {
      for (const current of products) {
        const target = path.join(imageRoot, current.sku, "01.jpg");
        await mkdir(path.dirname(target), { recursive: true });
        await cp(sourceImage, target);
      }

      await writeFile(
        productsFile,
        `${JSON.stringify({ version: 1, products }, null, 2)}\n`,
        "utf8",
      );
      await mkdir(path.dirname(stalePage), { recursive: true });
      await writeFile(stalePage, "veraltet", "utf8");

      const result = spawnSync(
        process.execPath,
        [path.join(projectRoot, "scripts", "build-test.mjs")],
        {
          cwd: projectRoot,
          env: {
            ...process.env,
            CARMAJA_TEST_FIXTURES: "true",
            CARMAJA_PRODUCTS_FILE: productsFile,
            CARMAJA_PRODUCT_IMAGES_DIR: imageRoot,
          },
          encoding: "utf8",
        },
      );

      assert.equal(
        result.status,
        0,
        `${result.stdout ?? ""}\n${result.stderr ?? ""}`,
      );
      assert.equal(
        await readFile(path.join(projectRoot, "out-test", "robots.txt"), "utf8"),
        "User-agent: *\nDisallow: /\n",
      );
      const htaccess = await readFile(
        path.join(projectRoot, "out-test", ".htaccess"),
        "utf8",
      );
      assert.ok(htaccess.includes(`AuthUserFile "${testAuthPath}"`));
      assert.ok(!htaccess.includes(previousAuthPath));
      await assert.rejects(
        access(path.join(projectRoot, "out-test", ".htpasswd")),
      );
      await assert.rejects(access(stalePage));

      const forbiddenPasswordFile = path.join(
        projectRoot,
        "out-test",
        ".htpasswd",
      );
      await writeFile(forbiddenPasswordFile, "darf-nicht-exportiert-werden\n");
      const verification = spawnSync(
        process.execPath,
        [path.join(projectRoot, "scripts", "verify-test-export.mjs")],
        {
          cwd: projectRoot,
          env: {
            ...process.env,
            CARMAJA_SITE_TARGET: "test",
            CARMAJA_SITE_URL: "https://test.carmaja-perlen.de",
            CARMAJA_PRODUCTION_PUBLISH_ENABLED: "false",
            CARMAJA_PRODUCTION_DEPLOY_ENABLED: "false",
            CARMAJA_TEST_FIXTURES: "true",
            CARMAJA_PRODUCTS_FILE: productsFile,
            CARMAJA_PRODUCT_IMAGES_DIR: imageRoot,
          },
          encoding: "utf8",
        },
      );
      assert.notEqual(verification.status, 0);
      assert.match(
        `${verification.stdout ?? ""}\n${verification.stderr ?? ""}`,
        /Unerlaubte Datei im Testexport/,
      );
      await rm(forbiddenPasswordFile, { force: true });
    } finally {
      await rm(path.join(projectRoot, "out-test", ".htpasswd"), {
        force: true,
      });
      await rm(fixtureRoot, { recursive: true, force: true });
    }
  },
);
