import assert from "node:assert/strict";
import { readFile } from "node:fs/promises";
import path from "node:path";
import test from "node:test";
import { fileURLToPath } from "node:url";

const testDirectory = path.dirname(fileURLToPath(import.meta.url));
const websiteDirectory = path.resolve(testDirectory, "..", "..");

async function source(relativePath) {
  return readFile(path.join(websiteDirectory, relativePath), "utf8");
}

test("oeffentliche Routen behandeln nur freigegebene Produktstatus", async () => {
  const products = await source("content/products.ts");
  const overview = await source("app/armbaender/page.tsx");
  const detail = await source("app/armbaender/[slug]/page.tsx");
  const sitemap = await source("app/sitemap.ts");

  assert.match(products, /product\.status === "published"/);
  assert.match(products, /product\.status === "published" \|\| product\.status === "sold"/);
  assert.match(overview, /visibleProducts/);
  assert.match(detail, /detailProducts/);
  assert.match(detail, /notFound\(\)/);
  assert.match(detail, /const isSold = product\.status === "sold"/);
  assert.match(detail, /index: false/);
  assert.match(sitemap, /visibleProducts/);
});

test("der Produktoutput nutzt nur oeffentliche, websitegenerierte Werte", async () => {
  const publicProducts = await source("lib/public-products.mjs");
  const detail = await source("app/armbaender/[slug]/page.tsx");
  const gallery = await source("components/product-image-gallery.tsx");
  const careContent = await source("content/site-content.ts");

  assert.match(publicProducts, /formatProductSize/);
  assert.match(publicProducts, /formatPearlSizeMm/);
  assert.match(publicProducts, /const ROOT_KEYS = \["products", "version"\]/);
  assert.match(publicProducts, /const PRODUCT_KEYS = \[/);
  assert.match(publicProducts, /publicTitle: publicProductName/);
  assert.match(publicProducts, /alt: `\$\{publicProductName\}, Bild/);
  assert.match(detail, /siteContent\.care\.items/);
  assert.match(detail, /<dt>Umfang:<\/dt>/);
  assert.match(detail, /<dt>Perlendurchmesser:<\/dt>/);
  assert.match(detail, /product\.displayPearlSizeMm/);
  assert.doesNotMatch(detail, /product\.careInstructions/);
  assert.doesNotMatch(detail, /product\.title/);
  assert.match(careContent, /care:/);
  assert.match(gallery, /role="dialog"/);
  assert.match(gallery, /const handleKeyDown/);
  assert.match(gallery, /document\.addEventListener\("keydown", handleKeyDown\)/);
  assert.match(gallery, /aria-label="Vorheriges Bild"/);
  assert.match(gallery, /aria-label="Nächstes Bild"/);
  assert.doesNotMatch(gallery, /product-lightbox-navigation/);
});

test("Produktion verwendet die neutrale Canonical-URL ohne Testziel", async () => {
  const target = await source("config/site-target.ts");
  const content = await source("content/site-content.ts");
  const layout = await source("app/layout.tsx");
  const detail = await source("app/armbaender/[slug]/page.tsx");
  const imprint = await source("app/impressum/page.tsx");
  const privacy = await source("app/datenschutz/page.tsx");

  assert.match(target, /https:\/\/www\.carmaja-perlen\.de\//);
  assert.doesNotMatch(target, /test\.carmaja-perlen\.de|test-api\.carmaja-perlen\.de/);
  assert.match(content, /siteUrl: siteTarget\.baseUrl/);
  assert.match(layout, /metadataBase: new URL\(siteContent\.metadata\.siteUrl\)/);
  assert.match(layout, /index: true/);
  assert.match(detail, /alternates:/);
  assert.match(detail, /canonical:/);
  assert.match(imprint, /canonical: "\/impressum\/"/);
  assert.match(privacy, /canonical: "\/datenschutz\/"/);
});
