import assert from "node:assert/strict";
import { readFile, stat } from "node:fs/promises";
import path from "node:path";
import test from "node:test";
import { fileURLToPath } from "node:url";

const testDirectory = path.dirname(fileURLToPath(import.meta.url));
const websiteDirectory = path.resolve(testDirectory, "..", "..");

async function source(relativePath) {
  return readFile(path.join(websiteDirectory, relativePath), "utf8");
}

test("die Testwebsite besitzt eigenständige Inhaltsseiten", async () => {
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

test("Navigation, Sitemap und Produktseiten verwenden das Mehrseitenlayout", async () => {
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
  assert.match(header, /pathname\.startsWith\("\/armbaender\/"\)/);
  assert.match(header, /event\.key === "Escape"/);
  assert.match(sitemap, /ueber-mich/);
  assert.match(sitemap, /material-pflege/);
  assert.match(sitemap, /kontakt/);
  assert.match(productList, /ProductImageGallery/);
  assert.match(
    productList,
    /<Heading>[\s\S]*<Link href=\{`\/armbaender\/\$\{product\.slug\}\/`\}>[\s\S]*\{product\.publicTitle\}/,
  );
  assert.doesNotMatch(productList, /Details ansehen/);
  assert.match(detail, /ProductImageGallery/);
  assert.match(detail, /href="\/material-pflege\/"/);
  assert.doesNotMatch(detail, /siteContent\.care|product-care|care-heading/);
});

test("Produktkarten sind als eigenständige Bereiche klar abgegrenzt", async () => {
  const styles = await source("app/site.css");

  assert.match(
    styles,
    /\.v2-page \.product-card \{[\s\S]*?overflow: hidden;[\s\S]*?border: 1px solid var\(--v2-line\);[\s\S]*?background: var\(--v2-surface\);[\s\S]*?box-shadow:/,
  );
  assert.match(
    styles,
    /\.v2-page \.product-card-copy \{[\s\S]*?padding: 1\.35rem 1\.35rem 1\.5rem;/,
  );
});

test("das transparente Logo ist in Header und Footer eingebunden", async () => {
  const header = await source("components/site-header.tsx");
  const footer = await source("components/site-footer.tsx");
  const logoPath = "/images/brand/carmaja-logo-transparent.png";
  const logoFile = path.join(
    websiteDirectory,
    "public",
    "images",
    "brand",
    "carmaja-logo-transparent.png",
  );
  const [logo, logoData] = await Promise.all([stat(logoFile), readFile(logoFile)]);

  assert.ok(logo.size > 0);
  assert.equal(logoData[25], 6, "Logo-PNG muss einen echten Alpha-Kanal besitzen");
  assert.ok(header.includes(logoPath));
  assert.ok(footer.includes(logoPath));
  assert.match(header, /brandPrimary\.slice\(1\)/);
  assert.match(footer, />armaja<\/span>/);
  assert.doesNotMatch(footer, />Carmaja<\/span>/);
});

test("Testziel bleibt ohne Produktionstracking und fest an die Test-API gebunden", async () => {
  const layout = await source("app/layout.tsx");
  const nextConfig = await source("next.config.ts");
  const buyNow = await source("components/shop-buy-now.tsx");
  const withdrawal = await source("components/withdrawal-form.tsx");

  assert.doesNotMatch(layout, /PageViewTracker/);
  assert.match(layout, /persistent-bracelet-flag/);
  assert.match(nextConfig, /https:\/\/test-api\.carmaja-perlen\.de/);
  assert.match(nextConfig, /configuredShopApiOrigin !== expectedShopApiOrigin/);
  assert.match(buyNow, /CARMAJA_SITE_TARGET === "test"/);
  assert.match(withdrawal, /CARMAJA_SITE_TARGET === "test"/);
});

test("nicht verfügbare Produkte starten keinen Checkout", async () => {
  const detail = await source("app/armbaender/[slug]/page.tsx");
  assert.match(
    detail,
    /product\.salesEnabled \? \([\s\S]*<ShopBuyNow productId=\{product\.productId\} \/>[\s\S]*\) : null/,
  );
  assert.doesNotMatch(detail, /product\.stock/);
});
