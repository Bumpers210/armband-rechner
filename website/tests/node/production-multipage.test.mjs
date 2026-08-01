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

test("die öffentliche Website hat die vorgesehenen eigenständigen Seiten", async () => {
  const routes = [
    ["app/page.tsx", 'canonical: "/"'],
    ["app/armbaender/page.tsx", 'canonical: "/armbaender/"'],
    ["app/ueber-mich/page.tsx", 'canonical: "/ueber-mich/"'],
    ["app/material-pflege/page.tsx", 'canonical: "/material-pflege/"'],
    ["app/kontakt/page.tsx", 'canonical: "/kontakt/"'],
  ];

  for (const [route, canonical] of routes) {
    const page = await source(route);
    assert.match(page, /export const metadata: Metadata/);
    assert.ok(page.includes(canonical), `${route} hat keine eigene Canonical-URL`);
    assert.match(page, /<h1>/);
  }
});

test("Navigation, Sitemap und Produktseiten bleiben erreichbar", async () => {
  const navigation = await source("content/site-content.ts");
  const header = await source("components/site-header.tsx");
  const sitemap = await source("app/sitemap.ts");
  const productList = await source("components/product-list.tsx");
  const detail = await source("app/armbaender/[slug]/page.tsx");

  for (const href of [
    'href: "/armbaender/"',
    'href: "/ueber-mich/"',
    'href: "/material-pflege/"',
    'href: "/kontakt/"',
  ]) {
    assert.ok(navigation.includes(href), `Navigationseintrag fehlt: ${href}`);
  }

  assert.match(header, /aria-current=/);
  assert.match(header, /data-active=/);
  assert.match(header, /pathname\.startsWith\("\/armbaender\/"\)/);
  assert.match(header, /event\.key === "Escape"/);
  assert.match(sitemap, /ueber-mich/);
  assert.match(sitemap, /material-pflege/);
  assert.match(sitemap, /kontakt/);
  assert.match(productList, /ProductImageGallery/);
  assert.match(detail, /ProductImageGallery/);
});

test("das gerenderte Frontend enthält keine Vinted-Verweise mehr", async () => {
  const frontendFiles = [
    "app/page.tsx",
    "app/armbaender/page.tsx",
    "app/armbaender/[slug]/page.tsx",
    "app/ueber-mich/page.tsx",
    "app/material-pflege/page.tsx",
    "app/kontakt/page.tsx",
    "app/layout.tsx",
    "app/site.css",
    "components/site-header.tsx",
    "components/site-footer.tsx",
    "components/product-list.tsx",
    "content/site-content.ts",
  ];

  for (const file of frontendFiles) {
    const text = await source(file);
    assert.doesNotMatch(text, /vinted/i, `${file} enthält noch einen Vinted-Verweis`);
  }
});
