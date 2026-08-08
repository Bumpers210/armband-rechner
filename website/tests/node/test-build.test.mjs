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

function product(sequence, salesEnabled) {
  const sku = `CP-2026-${String(sequence).padStart(4, "0")}`;
  const imageCount = sequence === 1 ? 3 : 1;

  return {
    productModelVersion: 2,
    productId: `00000000-0000-4000-8000-${String(sequence).padStart(12, "0")}`,
    productVersion: 1,
    sourceHash: String(sequence).repeat(64),
    sku,
    slug: `testarmband-${sequence}`,
    title: `INTERNER-ARTIKELNAME-${sequence}`,
    description: `Öffentliche Produktbeschreibung ${sequence}.`,
    materials: ["Rosenquarz"],
    metalElements: ["Spacer Blume Edelstahl"],
    braceletSizeCm: sequence === 1 ? 17.5 : 17.5,
    pearlSizeMm: sequence === 1 ? 6 : 8,
    priceMinor: 2000 + sequence * 100,
    currency: "eur",
    salesEnabled,
    images: Array.from({ length: imageCount }, (_, index) => ({
        imageId: `10000000-0000-4000-8000-${String(sequence * 10 + index).padStart(12, "0")}`,
        fileName: `${String(index + 1).padStart(2, "0")}.jpg`,
        src: `/images/products/${sku}/01.jpg`,
        alt: `INTERNER-BILDTEXT-${sequence}-${index + 1}`,
        width: 2048,
        height: 1536,
        isMain: index === 0,
      })).map((image, index) => ({
        ...image,
        src: `/images/products/${sku}/${String(index + 1).padStart(2, "0")}.jpg`,
      })),
    updatedAt: `2026-07-2${sequence}T10:00:00.000Z`,
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
      product(1, true),
      product(2, true),
      product(3, false),
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
        for (const image of current.images) {
          const target = path.join(
            imageRoot,
            current.sku,
            path.basename(image.src),
          );
          await mkdir(path.dirname(target), { recursive: true });
          await cp(sourceImage, target);
        }
      }

      await writeFile(
        productsFile,
        `${JSON.stringify({ version: 2, products }, null, 2)}\n`,
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

      const overviewHtml = await readFile(
        path.join(projectRoot, "out-test", "armbaender", "index.html"),
        "utf8",
      );
      const detailHtml = await readFile(
        path.join(
          projectRoot,
          "out-test",
          "armbaender",
          "testarmband-1",
          "index.html",
        ),
        "utf8",
      );

      assert.ok(overviewHtml.includes("Carmaja-Perlen Armband"));
      assert.ok(overviewHtml.includes("<dt>Materialien</dt>"));
      assert.ok(overviewHtml.includes("<dt>Metallelemente</dt>"));
      assert.ok(overviewHtml.includes("17,5 cm"));
      assert.ok(overviewHtml.includes("Öffentliche Produktbeschreibung 3."));
      assert.ok(overviewHtml.includes("Nicht verfügbar"));
      assert.ok(detailHtml.includes("17,5 cm"));
      assert.ok(!detailHtml.includes("cm cm"));
      assert.ok(detailHtml.includes("Vor dem Duschen und Baden ablegen"));
      assert.ok(detailHtml.includes("Kontakt mit Parfüm und Cremes vermeiden"));
      assert.ok(detailHtml.includes("Nicht stark auseinanderziehen"));
      assert.equal(
        [...detailHtml.matchAll(/data-lightbox-open="/g)].length,
        products[0].images.length,
      );

      for (let sequence = 1; sequence <= products.length; sequence += 1) {
        for (const internalValue of [
          `INTERNER-ARTIKELNAME-${sequence}`,
          `interne-artikelbezeichnung-${sequence}`,
          `INTERNER-BILDTEXT-${sequence}`,
        ]) {
          assert.ok(!overviewHtml.includes(internalValue));
          assert.ok(!detailHtml.includes(internalValue));
        }
      }

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
