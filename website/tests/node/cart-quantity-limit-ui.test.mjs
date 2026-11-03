import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';
import ts from 'typescript';

// Execute the actual client/components with artificial replies, without a
// browser login, network, Stripe, test product or database access.
function harness(quantities = [6, 4], rejectPut = false, productPreviews = []) {
  const states = []; let cursor = 0; const calls = []; const redirects = [];
  let cart = { cartVersion: 1, items: quantities.map((quantity, i) => ({ productId: `product-000${i + 1}`, quantity, name: `Armband ${i + 1}`, priceMinor: 2800, buyable: true })),
    totalMinor: 2800 * quantities.reduce((a, b) => a + b, 0) + 270, shippingMinor: 270, canCheckout: true, checkoutContextToken: 'artificial-quote' };
  const react = {
    useState(initial) { const index = cursor++; if (!(index in states)) states[index] = initial; return [states[index], value => { states[index] = typeof value === 'function' ? value(states[index]) : value; }]; },
    useRef(initial) { const index = cursor++; if (!(index in states)) states[index] = { current: initial }; return states[index]; },
    useCallback(fn) { return fn; }, useEffect() {},
  };
  const fetch = async (url, options = {}) => {
    calls.push({ url, ...options });
    const response = (data, status = 200) => ({ ok: status < 400, status, json: async () => data });
    if (url.endsWith('/context')) return response({ context: { csrfToken: 'artificial-csrf' } });
    if (url.endsWith('/cart') && options.method === 'PUT') {
      if (rejectPut) return response({ error: { code: 'cart_quantity_limit', message: 'PRIVATE-do-not-display' } }, 422);
      cart = { ...cart, items: JSON.parse(options.body).items, cartVersion: cart.cartVersion + 1 };
    }
    if (url.endsWith('/cart')) return response({ cart });
    if (url.includes('/products/')) return response({ product: { priceMinor: 2800, available: true, availableQuantity: 50 } });
    throw new Error('Unexpected network action');
  };
  let client;
  function load(file) {
    const testModule = { exports: {} };
    const code = ts.transpileModule(readFileSync(new URL(`../../${file}`, import.meta.url), 'utf8'), {
      compilerOptions: { jsx: ts.JsxEmit.ReactJSX, module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2022 },
    }).outputText;
    const jsx = (type, props) => ({ type, props });
    vm.runInNewContext(code, { module: testModule, exports: testModule.exports, fetch, process: { env: { CARMAJA_SITE_TARGET: 'test' } },
      window: { dispatchEvent() {}, location: { assign: url => redirects.push(url) } },
      crypto: { randomUUID: () => 'artificial-idempotency' }, Event: class {},
      require(name) {
        if (name === 'react') return react;
        if (name === 'react/jsx-runtime') return { jsx, jsxs: jsx };
        if (name === 'next/link') return { default: 'a' };
        if (name === 'next/image') return { default: 'img' };
        if (name === '@/lib/cart-client') return client;
        throw new Error('Unexpected import');
      },
    });
    return testModule.exports;
  }
  client = load('lib/cart-client.ts');
  const component = load('components/shop-cart.tsx');
  return { client, calls, redirects, states, get cart() { return cart; },
    render() { cursor = 0; return component.ShopCart({ productPreviews }); },
  };
}
function nodes(node, predicate) {
  if (Array.isArray(node)) return node.flatMap(child => nodes(child, predicate));
  if (!node || typeof node !== 'object') return [];
  return [...(predicate(node) ? [node] : []), ...nodes(node.props?.children, predicate)];
}
function text(node) {
  if (Array.isArray(node)) return node.map(text).join('');
  if (!node || typeof node !== 'object') return String(node ?? '');
  return text(node.props?.children);
}
const button = (tree, label) => nodes(tree, node => node.type === 'button' && text(node) === label)[0];
const tick = () => new Promise(resolve => setImmediate(resolve));
const loadCart = async h => { button(h.render(), 'Neu laden').props.onClick(); await tick(); return h.render(); };

test('Browser und Server verwenden dieselbe Gesamtmengengrenze von zehn', () => {
  const h = harness();
  const php = readFileSync(new URL('../../test-api-private/program/cart-contract.php', import.meta.url), 'utf8');
  assert.equal(h.client.CART_MAX_QUANTITY, 10);
  assert.match(php, new RegExp(`CARMAJA_CART_MAX_QUANTITY = ${h.client.CART_MAX_QUANTITY};`));
  assert.match(php, /CARMAJA_CART_MAX_POSITIONS = 100;/);
});

test('Gemischter Warenkorb 6+5 wird im echten Client vor jeder Anfrage abgewiesen', async () => {
  const h = harness();
  await assert.rejects(h.client.replaceCart(h.cart, [{ productId: 'A', quantity: 6 }, { productId: 'B', quantity: 5 }]), /Maximal 10/);
  assert.equal(h.calls.length, 0);
  assert.equal(h.cart.cartVersion, 1);
});

test('Gemischter Warenkorb 6+4 wird unveraendert, versioniert und CSRF-geschuetzt gesendet', async () => {
  const h = harness();
  const items = [{ productId: 'A', quantity: 6 }, { productId: 'B', quantity: 4 }];
  await h.client.replaceCart(h.cart, items);
  const put = h.calls.find(call => call.method === 'PUT');
  assert.deepEqual(JSON.parse(put.body), { expectedCartVersion: 1, items });
  assert.equal(put.cache, 'no-store'); assert.equal(put.credentials, 'include');
  assert.equal(put.headers['X-CSRF-Token'], 'artificial-csrf');
  assert.equal(put.headers['Idempotency-Key'], 'artificial-idempotency');
});

test('Serverseitiger Mengenfehler wird verstaendlich ohne fremde Fehlerdetails angezeigt', async () => {
  const h = harness([6, 4], true);
  await assert.rejects(h.client.replaceCart(h.cart, [{ productId: 'A', quantity: 1 }]), error => {
    assert.match(error.message, /Maximal 10/); assert.doesNotMatch(error.message, /PRIVATE/); return true;
  });
});

test('Mengenfelder beruecksichtigen andere Positionen; Ueberschreitung sperrt den alten Checkout', async () => {
  const h = harness(); const tree = await loadCart(h);
  const inputs = nodes(tree, node => node.type === 'input');
  assert.deepEqual(inputs.map(node => node.props.max), [6, 4]);
  assert.equal(button(tree, 'Weiter zur Zahlung').props.disabled, false);
  inputs[0].props.onChange({ target: { value: '7' } }); await tick();
  const updated = h.render();
  assert.match(text(updated), /Maximal 10/);
  assert.equal(button(updated, 'Weiter zur Zahlung').props.disabled, true);
  assert.equal(h.calls.filter(call => call.method === 'PUT').length, 0);
  assert.equal(h.cart.items[0].quantity, 6);
});

test('Aelterer uebergrosser Warenkorb bleibt sichtbar, kann nicht bezahlen und ist leerbar', async () => {
  const h = harness([6, 5]); let tree = await loadCart(h);
  assert.match(text(tree), /Maximal 10/);
  const checkout = button(tree, 'Weiter zur Zahlung');
  assert.equal(checkout.props.disabled, true);
  checkout.props.onClick(); await tick();
  assert.equal(h.calls.filter(call => call.url.endsWith('/checkouts')).length, 0);
  assert.equal(h.redirects.length, 0);
  assert.deepEqual(nodes(tree, node => node.type === 'input').map(node => node.props.value), [6, 5]);
  button(tree, 'Warenkorb leeren').props.onClick(); await tick(); tree = h.render();
  assert.equal(h.cart.items.length, 0);
  assert.match(text(tree), /Ihr Warenkorb ist leer/);
  assert.equal(button(tree, 'Weiter zur Zahlung'), undefined);
});

test('Produktseite verwendet die gemeinsame Mengenpruefung und begrenzt das einzelne Eingabefeld', () => {
  const source = readFileSync(new URL('../../components/shop-buy-now.tsx', import.meta.url), 'utf8');
  assert.match(source, /Math\.min\(CART_MAX_QUANTITY, product\?\.availableQuantity/);
  assert.match(source, /quantity > CART_MAX_QUANTITY/);
  assert.match(source, /await replaceCart\(cart, items\)/);
  assert.match(source, /Armbänder insgesamt je Bestellung/);
});

test('Warenkorb zeigt das kleine Hauptbild zum passenden Artikel und verlinkt die Detailseite', async () => {
  const previews = [{ productId: 'product-0001', href: '/armbaender/test-eins/', image: {
    src: '/images/product-previews/CP-2026-0001/aaaaaaaaaaaaaaaa/01-240.jpg', alt: 'Rosenquarz-Armband',
  } }];
  const h = harness([2, 1], false, previews); const tree = await loadCart(h);
  const images = nodes(tree, node => node.type === 'img');
  assert.equal(images.length, 1);
  assert.equal(images[0].props.src, previews[0].image.src);
  assert.equal(images[0].props.alt, 'Rosenquarz-Armband');
  assert.equal(images[0].props.width, 112);
  assert.equal(nodes(tree, node => node.props?.href === '/armbaender/test-eins/').length, 2);
  assert.match(text(tree), /Bild nicht verfügbar/);
  assert.match(text(tree), /56,00\s*€/);
  assert.equal(h.calls.filter(call => call.method === 'PUT').length, 0);
});

test('Fehlendes oder nicht ladbares Artikelbild aendert weder Warenkorb noch Kaufberechtigung', async () => {
  const h = harness([1], false, [{ productId: 'product-0001', href: '/armbaender/test/',
    image: { src: '/images/products/CP-2026-0001/01.jpg', alt: 'Armband' } }]);
  let tree = await loadCart(h); const before = h.cart;
  nodes(tree, node => node.type === 'img')[0].props.onError(); tree = h.render();
  assert.equal(nodes(tree, node => node.type === 'img').length, 0);
  assert.match(text(tree), /Bild nicht verfügbar/);
  assert.equal(button(tree, 'Weiter zur Zahlung').props.disabled, false);
  assert.equal(h.cart, before);
  assert.equal(h.calls.filter(call => call.method === 'PUT').length, 0);
});

test('Schoenere Zusammenfassung behaelt serverseitige Summen, Preiswarnungen und Fail-closed bei', async () => {
  const h = harness([2]);
  Object.assign(h.cart.items[0], { priceChanged: true, previousPriceMinor: 2500, buyable: false });
  Object.assign(h.cart, { totalMinor: null, canCheckout: false });
  const tree = await loadCart(h);
  assert.match(text(tree), /Preis geändert: vorher/);
  assert.match(text(tree), /Gewünschte Menge momentan nicht verfügbar/);
  assert.match(text(tree), /Gesamtnicht verfügbar/);
  assert.equal(button(tree, 'Weiter zur Zahlung').props.disabled, true);
});

test('Warenkorbseite uebergibt nur oeffentliche Bild- und Linkdaten, niemals statischen Bestand oder Preise', () => {
  const source = readFileSync(new URL('../../app/warenkorb/page.tsx', import.meta.url), 'utf8');
  assert.match(source, /publicProducts\.map/);
  assert.match(source, /mainProductImage\(product\)/);
  assert.match(source, /image\.previews\?\.\[240\] \?\? image\.src/);
  assert.doesNotMatch(source, /priceMinor|salesEnabled|stock|availableQuantity|sourceHash|productVersion/);
});
