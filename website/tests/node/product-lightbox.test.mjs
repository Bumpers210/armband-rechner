import assert from "node:assert/strict";
import { readFile } from "node:fs/promises";
import path from "node:path";
import test from "node:test";

const projectRoot = process.cwd();

async function source(relativePath) {
  return readFile(path.join(projectRoot, relativePath), "utf8");
}

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
  ]) {
    assert.ok(component.includes(required), `Lightbox-Vertrag fehlt: ${required}`);
  }

  assert.ok(!component.includes("http://"));
  assert.ok(!component.includes("https://"));
  assert.ok(!component.toLowerCase().includes("tracking"));
});

test("Übersicht und Detailseite behalten ihre Produktnavigation", async () => {
  const overview = await source("app/armbaender/page.tsx");
  const productList = await source("components/product-list.tsx");
  const detail = await source("app/armbaender/[slug]/page.tsx");

  assert.ok(overview.includes("<ProductList"));
  assert.ok(productList.includes("<ProductImageGallery"));
  assert.ok(productList.includes('variant="card"'));
  assert.ok(productList.includes("href={`/armbaender/${product.slug}/`}"));
  assert.ok(!productList.includes("product.description"));
  assert.ok(detail.includes("<ProductImageGallery"));
  assert.ok(detail.includes('variant="detail"'));
  assert.ok(detail.includes('href="/armbaender/"'));
});
