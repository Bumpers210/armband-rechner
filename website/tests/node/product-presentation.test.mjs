import assert from 'node:assert/strict';
import { readFileSync, existsSync } from 'node:fs';
import { createRequire } from 'node:module';
import path from 'node:path';
import vm from 'node:vm';
import test from 'node:test';
import React from 'react';
import { renderToStaticMarkup } from 'react-dom/server';
import ts from 'typescript';

const require = createRequire(import.meta.url);
const root = path.resolve(import.meta.dirname, '../..');
const fixture = {
  productId: '11111111-1111-4111-8111-111111111111', productVersion: 7,
  sku: 'CP-2099-0001', slug: 'cp-2099-0001-testarmband', publicTitle: 'Künstliches Armband',
  description: 'Unveränderter Originaltext.\n\nZweiter Absatz.', descriptionDocument: null,
  materials: ['Teststein'], metalElements: [], displaySize: '18 cm', displayPearlSize: '8 mm',
  priceMinor: 2850, currency: 'eur', salesEnabled: true, notices: [],
  images: [{ src: '/images/products/CP-2099-0001/01.jpg', alt: 'Originalfoto', width: 3200, height: 3200 }],
};

// Actual React pages/components and policy code, with synthetic products only.
// No API calls, authentication, writes or production state.
function harness(products, target = 'production') {
  const cache = new Map();
  function load(name) {
    if (name === 'next/link') return { __esModule: true, default: props => React.createElement('a', props) };
    if (name === 'next/navigation') return { notFound() { throw new Error('not found'); } };
    if (name === '@/content/products') return {
      detailProducts: products, visibleProducts: products,
      findDetailProduct: slug => products.find(product => product.slug === slug),
      mainProductImage: product => product.images[0],
    };
    if (name === '@/components/site-header') return { SiteHeader: () => null };
    if (name === '@/components/site-footer') return { SiteFooter: () => null };
    if (name === '@/components/product-image-gallery') return { ProductImageGallery: ({ images, variant }) =>
      React.createElement('figure', { 'data-variant': variant }, images.map(image =>
        React.createElement('img', { key: image.src, src: image.src, alt: image.alt }))) };
    if (!name.startsWith('@/')) return require(name);
    const file = path.join(root, name.slice(2));
    if (/\.(?:mjs|json)$/.test(file)) return require(file);
    const filename = existsSync(file + '.ts') ? file + '.ts' : file + '.tsx';
    if (cache.has(filename)) return cache.get(filename);
    const exports = {};
    cache.set(filename, exports);
    const code = ts.transpileModule(readFileSync(filename, 'utf8'), {
      compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2022,
        jsx: ts.JsxEmit.ReactJSX, esModuleInterop: true },
      transformers: { before: [() => source => ts.factory.updateSourceFile(source, [
        ...source.statements.filter(ts.isImportDeclaration),
        ...source.statements.filter(statement => !ts.isImportDeclaration(statement)),
      ])] },
    }).outputText;
    vm.runInNewContext(code, { exports, require: load, URL, process: { env: {
      CARMAJA_SITE_TARGET: target,
      CARMAJA_SITE_URL: target === 'test' ? 'https://test.carmaja-perlen.de' : 'https://www.carmaja-perlen.de',
    } } }, { filename });
    return exports;
  }
  return {
    load,
    list: () => renderToStaticMarkup(React.createElement(load('@/components/product-list').ProductList, { products })),
    detail: async product => renderToStaticMarkup(await load('@/app/armbaender/[slug]/page').default({ params: Promise.resolve({ slug: product.slug }) })),
  };
}
const visible = html => html.replace(/<script\b[^>]*>[\s\S]*?<\/script>/g, '').replace(/<[^>]+>/g, ' ').replace(/\s+/g, ' ');

test('Anfrageartikel hat nur Anfrage und Absprachepreis, unabhängig von der unveränderten Verkaufsfreigabe', async () => {
  for (const salesEnabled of [true, false]) {
    const product = structuredClone({ ...fixture, custom: true, salesEnabled });
    const before = JSON.stringify(product);
    const h = harness([product]);
    const html = await h.detail(product);
    assert.match(html, /Preis nach Absprache/);
    assert.equal((html.match(/Wunscharmband anfragen/g) ?? []).length, 1);
    assert.match(html, /href="\/kontakt\/"/);
    assert.doesNotMatch(html, /Nicht verfügbar|Vorübergehend|In den Warenkorb|shop-buy-now|cmj-offer-seed|"offers"|type="number"/);
    assert.doesNotMatch(visible(html), /28,50/);
    assert.match(visible(html), /Unveränderter Originaltext\./);
    assert.match(visible(html), /Zweiter Absatz\./);
    assert.equal(JSON.stringify(product), before);
    assert.doesNotMatch(h.list(), /Nicht verfügbar|28,50/);
  }
});

test('Karten zeigen öffentliche EUR-Preise und behalten Reihenfolge, Bilder und Daten bei', () => {
  const products = [
    { ...structuredClone(fixture), sku: 'CP-2099-0003', slug: 'third', priceMinor: 3250 },
    { ...structuredClone(fixture), sku: 'CP-2099-0001', slug: 'first', priceMinor: 1, salesEnabled: false },
    { ...structuredClone(fixture), sku: 'CP-2099-0002', slug: 'second', custom: true, priceMinor: 4000 },
  ];
  const before = JSON.stringify(products);
  const html = harness(products).list();
  const cards = [...html.matchAll(/<article\b[\s\S]*?<\/article>/g)].map(match => match[0]);
  assert.equal(cards.length, 3);
  assert.match(visible(cards[0]), /32,50 €/);
  assert.match(visible(cards[1]), /0,01 €.*Nicht verfügbar/);
  assert.match(visible(cards[2]), /Preis nach Absprache/);
  assert.doesNotMatch(visible(cards[2]), /40,00/);
  for (const [index, product] of products.entries()) {
    assert.ok(cards[index].includes(`/armbaender/${product.slug}/`));
    assert.ok(cards[index].includes('data-variant="card"'));
    assert.ok(cards[index].includes(product.images[0].src));
  }
  assert.equal(JSON.stringify(products), before);
});

test('Normale Produkte behalten Live-Prüfung und fail-closed Kaufbereich statt statischem Preisfallback', async () => {
  const product = structuredClone(fixture);
  const html = await harness([product]).detail(product);
  assert.match(html, /Preis und Verfügbarkeit werden geprüft/);
  assert.match(html, /<button[^>]*disabled=""[^>]*>In den Warenkorb/);
  assert.doesNotMatch(visible(html), /28,50|Preis nach Absprache|Wunscharmband anfragen/);
  assert.match(html, /cmj-offer-seed-/);
  const unavailable = { ...product, salesEnabled: false };
  const closed = await harness([unavailable]).detail(unavailable);
  assert.match(closed, /Nicht verfügbar/);
  assert.doesNotMatch(closed, /shop-buy-now|In den Warenkorb/);
});

test('Versand steht direkt nach dem Kaufbutton und stimmt mit Vertrag und freigegebenem Legal Bundle überein', async () => {
  const h = harness([fixture]);
  const html = await h.detail(fixture);
  assert.match(html, /In den Warenkorb<\/button><p class="product-shipping-summary">/);
  assert.match(visible(html), /einmalig 2,70 € je Bestellung/);
  assert.match(visible(html), /voraussichtlich 2–4 Werktage nach endgültiger Zahlungsbestätigung/);
  assert.match(html, /href="\/versand-und-zahlung\/"/);
  const bundle = h.load('@/content/legal-bundles').getActiveLegalBundle();
  const shipping = JSON.stringify(bundle.texts.shipping);
  assert.equal(bundle.status, 'approved');
  assert.match(shipping, /einmalig 2,70 € je Bestellung/);
  assert.match(shipping, /2 bis 4 Werktage nach endgültiger Zahlungsbestätigung/);
  const config = require('../../config/production-shop-deployment.json');
  assert.equal(config.shop.shippingAmountMinor, 270);
  assert.equal(config.shop.shippingMinBusinessDays, 2);
  assert.equal(config.shop.shippingMaxBusinessDays, 4);
});

test('Testdarstellung bleibt ohne Produktions-URLs oder private Deploymentkonfiguration', async () => {
  const html = await harness([fixture], 'test').detail(fixture);
  assert.match(html, /product-shipping-summary/);
  assert.doesNotMatch(html, /www\.carmaja-perlen\.de|\/home\/www\/|runtime-config|production-shop-deployment/);
});
