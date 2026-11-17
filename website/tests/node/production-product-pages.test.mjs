import assert from "node:assert/strict";
import { readFile } from "node:fs/promises";
import path from "node:path";
import test from "node:test";
import { withProductEditorial } from "../../lib/product-editorial.mjs";
import { fileURLToPath } from "node:url";
import vm from "node:vm";
import ts from "typescript";
import {orderCatalog} from '../../lib/product-catalog-order.mjs';

const testDirectory = path.dirname(fileURLToPath(import.meta.url));
const websiteDirectory = path.resolve(testDirectory, "..", "..");

async function source(relativePath) {
  return readFile(path.join(websiteDirectory, relativePath), "utf8");
}

test("oeffentliche Routen trennen Veroeffentlichung von Verkaufsfreigabe", async () => {
  const products = await source("content/products.ts");
  const overview = await source("app/armbaender/page.tsx");
  const detail = await source("app/armbaender/[slug]/page.tsx");
  const sitemap = await source("app/sitemap.ts");

  assert.match(products, /loadPublicProductsV2/);
  assert.doesNotMatch(products, /product\.salesEnabled/);
  assert.doesNotMatch(products, /product\.status/);
  assert.match(overview, /visibleProducts/);
  assert.match(detail, /detailProducts/);
  assert.match(detail, /notFound\(\)/);
  assert.match(detail, /!product\.salesEnabled/);
  assert.match(detail, /product\.salesEnabled\s*\?\s*\(\s*<ShopBuyNow/);
  assert.match(detail, /index: false/);
  assert.match(sitemap, /visibleProducts/);
});

async function evaluateModule(relativePath, dependencies, environment = {}) {
  const { outputText } = ts.transpileModule(await source(relativePath), {
    compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2020, esModuleInterop: true },
  });
  const exports = {};
  vm.runInNewContext(outputText, {
    exports,
    URL,
    process: { cwd: () => websiteDirectory, env: environment },
    require(name) {
      assert.ok(name in dependencies, `Unerwartete Abhängigkeit: ${name}`);
      return dependencies[name];
    },
  });
  return exports;
}

for (const target of ["production", "test"]) {
  test(`${target}: manuelle Reihenfolge erreicht Übersicht und Sitemap ohne Verkaufsaktivierung`, async () => {
    const a = '99999999-9999-4999-8999-999999999991';
    const b = '99999999-9999-4999-8999-999999999992';
    const projected = [
      {productId:a, slug:'first', salesEnabled:false, updatedAt:'2026-11-01T00:00:00Z'},
      {productId:b, slug:'second', salesEnabled:false, updatedAt:'2026-11-16T00:00:00Z'},
    ];
    const products = await evaluateModule('content/products.ts', {
      'node:path':path,
      '@/lib/product-editorial.mjs':{withProductEditorial},
      '@/lib/product-catalog-order.mjs':{orderCatalog, loadCatalogOrder:()=>({version:1,productIds:[a,b]})},
      '@/lib/public-products-v2.mjs':{loadPublicProductsV2:()=>({products:projected})},
      '@/lib/product-image-previews.mjs':{withProductPreviews:p=>p},
    }, {CARMAJA_SITE_TARGET:target});
    assert.deepEqual(Array.from(products.visibleProducts, p=>p.productId),[a,b]);
    assert.equal(products.visibleProducts.every(p=>p.salesEnabled===false),true);
    const sitemap=await evaluateModule('app/sitemap.ts',{
      '@/content/products':products,
      '@/content/site-content':{siteContent:{metadata:{siteUrl:'https://example.invalid'}}},
    });
    assert.deepEqual(Array.from(sitemap.default().filter(e=>e.lastModified),e=>e.url),
      ['https://example.invalid/armbaender/first/','https://example.invalid/armbaender/second/']);
  });
  test(`${target}: veröffentlichte Produkte ohne Verkaufsfreigabe bleiben in Übersicht und Sitemap`, async () => {
    const projected = Object.freeze([
      Object.freeze({ productId: "published-enabled", slug: "enabled", salesEnabled: true, updatedAt: "2026-11-01T00:00:00Z" }),
      Object.freeze({ productId: "published-disabled", slug: "disabled", salesEnabled: false, updatedAt: "2026-11-15T00:00:00Z" }),
    ]);
    const before = JSON.stringify(projected);
    const products = await evaluateModule("content/products.ts", {
      "node:path": path,
      "@/lib/product-editorial.mjs": { withProductEditorial },
      "@/lib/product-catalog-order.mjs": {orderCatalog, loadCatalogOrder: () => ({version: 1, productIds: null})},
      "@/lib/public-products-v2.mjs": { loadPublicProductsV2: () => ({ products: projected }) },
      "@/lib/product-image-previews.mjs": { withProductPreviews: (product) => product },
    }, { CARMAJA_SITE_TARGET: target });
    assert.deepEqual(Array.from(products.visibleProducts, (product) => product.productId),
      ["published-disabled", "published-enabled"]);
    assert.equal(products.findDetailProduct("disabled").salesEnabled, false);
    assert.equal(products.findDetailProduct("unpublished"), undefined);
    assert.equal(products.findDetailProduct("archived"), undefined);
    assert.equal(JSON.stringify(projected), before);

    const baseUrl = target === "test" ? "https://test.carmaja-perlen.de" : "https://www.carmaja-perlen.de";
    const sitemap = await evaluateModule("app/sitemap.ts", {
      "@/content/products": products,
      "@/content/site-content": { siteContent: { metadata: { siteUrl: baseUrl } } },
    });
    const entries = sitemap.default();
    assert.deepEqual(Array.from(entries.filter((entry) => entry.lastModified), (entry) => entry.url),
      [`${baseUrl}/armbaender/disabled/`, `${baseUrl}/armbaender/enabled/`]);
  });
}

test("der Produktoutput nutzt nur oeffentliche, websitegenerierte Werte", async () => {
  const publicProducts = await source("lib/public-products-v2.mjs");
  const detail = await source("app/armbaender/[slug]/page.tsx");
  const overview = await source("components/product-list.tsx");
  const gallery = await source("components/product-image-gallery.tsx");

  assert.match(publicProducts, /formatMeasurement/);
  assert.match(publicProducts, /productVersion/);
  assert.match(publicProducts, /sourceHash/);
  assert.match(publicProducts, /priceMinor/);
  assert.match(publicProducts, /salesEnabled/);
  assert.match(publicProducts, /const ROOT_KEYS = \["products", "version"\]/);
  assert.match(publicProducts, /const PRODUCT_KEYS_V2 = \[/);
  assert.match(publicProducts, /const PRODUCT_KEYS_V3 = \[\.\.\.PRODUCT_KEYS_V2/);
  assert.match(publicProducts, /validateDescriptionDocument/);
  assert.match(publicProducts, /const publicTitle = requireString\(product\.title/);
  assert.match(publicProducts, /alt: `\$\{publicProductName\}, Bild/);
  assert.match(detail, /href="\/material-pflege\/"/);
  assert.doesNotMatch(detail, /siteContent\.care\.items/);
  assert.doesNotMatch(detail, /product\.careInstructions/);
  assert.doesNotMatch(detail, /product\.title/);
  assert.doesNotMatch(detail, /product\.stock/);
  assert.match(detail, /displaySize/);
  assert.match(detail, /displayPearlSize/);
  assert.match(overview, /displaySize/);
  assert.match(overview, /displayPearlSize/);
  assert.match(overview, /<Heading>[\s\S]*?<Link href=\{`\/armbaender\/\$\{product\.slug\}\/`\}>/);
  assert.doesNotMatch(overview, /Details ansehen/);
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

  assert.match(target, /target === "production"/);
  assert.match(target, /https:\/\/www\.carmaja-perlen\.de/);
  assert.match(target, /target === "test"/);
  assert.match(target, /https:\/\/test\.carmaja-perlen\.de/);
  assert.match(content, /siteUrl: siteTarget\.baseUrl/);
  assert.match(layout, /metadataBase: new URL\(siteContent\.metadata\.siteUrl\)/);
  assert.match(layout, /index: !siteTarget\.isTest/);
  assert.match(detail, /alternates:/);
  assert.match(detail, /canonical:/);
  assert.match(imprint, /canonical: "\/impressum\/"/);
  assert.match(privacy, /canonical: "\/datenschutz\/"/);
});
