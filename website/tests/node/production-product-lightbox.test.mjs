import assert from "node:assert/strict";
import { readFile } from "node:fs/promises";
import path from "node:path";
import test from "node:test";

const projectRoot = process.cwd();

async function source(relativePath) {
  return readFile(path.join(projectRoot, relativePath), "utf8");
}

test("Karten bieten höhere Hochformatvorschauen ohne feste Pixelhöhe", async () => {
  const css = await source("app/site.css");
  const mediaRules = [...css.matchAll(/([^{}]+)\{([^{}]*)\}/g)]
    .filter(([, selectors]) => selectors.split(",").some((selector) => selector.trim() === ".v2-page .product-card-media"))
    .map(([, , body]) => body);
  assert.equal(mediaRules.length, 1, "Vorschauformat bleibt an allen Breakpoints konsistent");
  assert.match(mediaRules[0], /aspect-ratio:\s*3\s*\/\s*4\s*;/);
  assert.match(mediaRules[0], /width:\s*100%\s*;/);
  assert.doesNotMatch(mediaRules[0], /(?:^|;)\s*(?:(?:min|max)-)?height\s*:/);
});

test("Produktvorschauen zeigen das vollständige Foto zentriert statt es zuzuschneiden", async () => {
  const css = await source("app/site.css");
  for (const className of ["product-card-image", "product-detail-image", "product-detail-thumb"]) {
    const declarations = [...css.matchAll(/([^{}]+)\{([^{}]*)\}/g)]
      .filter(([, selectors]) => selectors.split(",").some((selector) => selector.trim() === `.v2-page .${className}`))
      .map(([, , body]) => body).join("\n");
    assert.match(declarations, /object-fit:\s*contain\s*;/, className);
    assert.match(declarations, /object-position:\s*center\s*;/, className);
    assert.doesNotMatch(declarations, /object-fit:\s*(?:cover|fill)\s*;/, className);
  }
});

test("Lightbox unterstützt Maus, Touch, Tastatur und Fokusführung", async () => {
  const component = await source("components/product-image-gallery.tsx");

  for (const required of [
    '"use client"',
    'role="dialog"',
    'aria-modal="true"',
    'event.key === "Escape"',
    'event.key === "ArrowLeft"',
    'event.key === "ArrowRight"',
    'event.key !== "Tab"',
    "closeButtonRef.current?.focus()",
    "lastTriggerIndex.current",
    "event.target === event.currentTarget",
    "data-lightbox-open",
    "data-lightbox-previous",
    "data-lightbox-next",
    "product-lightbox-arrow--previous",
    "product-lightbox-arrow--next",
  ]) {
    assert.ok(component.includes(required), `Lightbox-Vertrag fehlt: ${required}`);
  }

  assert.ok(!component.includes("http://"));
  assert.ok(!component.includes("https://"));
  assert.ok(!component.toLowerCase().includes("tracking"));
  assert.ok(!component.includes("product-lightbox-navigation"));
});

test("Übersicht und Detailseite behalten ihre Produktnavigation", async () => {
  const overview = await source("app/armbaender/page.tsx");
  const productList = await source("components/product-list.tsx");
  const detail = await source("app/armbaender/[slug]/page.tsx");

  assert.ok(overview.includes("<ProductList"));
  assert.ok(productList.includes("<ProductImageGallery"));
  assert.ok(productList.includes('variant="card"'));
  assert.ok(productList.includes("product.slug"));
  assert.ok(detail.includes("<ProductImageGallery"));
  assert.ok(detail.includes('variant="detail"'));
  assert.match(detail, /product-breadcrumbs/);
  assert.match(detail, /<Link className="product-back-link" href=\{href\}/);
});
