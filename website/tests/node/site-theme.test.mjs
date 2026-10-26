import assert from "node:assert/strict";
import { readFile } from "node:fs/promises";
import { test } from "node:test";

const source = (name) => readFile(new URL(`../../${name}`, import.meta.url), "utf8");

test("Startseitenfoto bleibt ohne Mond- und Kreisornamente; andere Bereiche bleiben dekoriert", async () => {
  const page = await source("app/page.tsx");
  const hero = page.match(/<section className="v2-hero">([\s\S]*?)<\/section>/)?.[1];
  assert.ok(hero, "Startseitenbild fehlt");
  assert.match(hero, /src=\{siteContent\.hero\.image\.src\}/);
  assert.match(hero, /className="v2-hero-overlay"/);
  assert.doesNotMatch(hero, /CelestialOrnament|v2-hero-orbit/);
  assert.match(page, /className="v2-section-ornament"><CelestialOrnament/);
  assert.doesNotMatch(await source("app/site.css"), /\.v2-hero-orbit/);
});

test("Öffentliche Rückkehr- und Fehlerseiten behalten den gemeinsamen Seitenrahmen", async () => {
  for (const name of ["shop/success/page.tsx", "shop/cancel/page.tsx", "shop/error/page.tsx", "warenkorb/page.tsx", "not-found.tsx"]) {
    const page = await source(`app/${name}`);
    for (const contract of [/className="v2-page"/, /<SiteHeader\s*\/>/, /<SiteFooter\s*\/>/, /<CelestialOrnament\s*\/>/, /id="main-content"/]) {
      assert.match(page, contract, name);
    }
  }
});

test("Design bleibt dekorativ; Produkthinweise bleiben kompakt und der neue Einstieg ohne Ritualspruch", async () => {
  const ornament = await source("components/celestial-ornament.tsx");
  assert.match(ornament, /aria-hidden="true"/);
  assert.match(ornament, /focusable="false"/);
  const copy = await source("content/site-content.ts");
  assert.match(copy, /Edelsteinarmbänder mit Charakter/);
  assert.doesNotMatch(copy, /Ein kleines Ritual|Handarbeit · Herzensstücke/);
  const css = await source("app/site.css");
  assert.match(css, /--background: var\(--v2-canvas\)/);
  assert.match(css, /\.product-notices h2\s*\{[^}]*font-size: 0\.75rem/s);
  const cart = await source("components/shop-cart.tsx");
  assert.match(cart, /className="shop-cart" aria-live="polite"/);
  assert.match(cart, /disabled=\{busy \|\| !cart\.canCheckout \|\| quantityLimitExceeded\}/);
});
