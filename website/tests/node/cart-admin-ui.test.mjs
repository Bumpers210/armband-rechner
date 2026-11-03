import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';
import ts from 'typescript';

// Execute the real component handlers with synthetic React state and HTTP replies.
// No credentials, browser session, network request or database is used here.
function harness(file, fetch) {
  const states = []; let cursor = 0; const effects = []; const timers = [];
  const InventoryAdmin = () => null; const OrderPositionsAdmin = () => null;
  const testModule = { exports: {} };
  const react = {
    useState(initial) {
      const index = cursor++;
      if (!(index in states)) states[index] = initial;
      return [states[index], value => { states[index] = typeof value === 'function' ? value(states[index]) : value; }];
    },
    useEffect(effect, deps) { effects.push({ effect, deps }); },
  };
  const jsx = (type, props) => ({ type, props });
  const code = ts.transpileModule(readFileSync(new URL(`../../components/${file}`, import.meta.url), 'utf8'), {
    compilerOptions: { jsx: ts.JsxEmit.ReactJSX, module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2022 },
  }).outputText;
  vm.runInNewContext(code, {
    module: testModule, exports: testModule.exports, fetch, process: { env: { CARMAJA_SITE_TARGET: 'test' } },
    window: { setTimeout(fn) { timers.push(fn); return timers.length; }, clearTimeout() {} },
    crypto: { randomUUID: () => 'artificial-idempotency' },
    FormData: class { constructor(values) { this.values = values; } get(key) { return this.values[key]; } },
    require(name) {
      if (name === 'react') return react;
      if (name === 'react/jsx-runtime') return { jsx, jsxs: jsx };
      if (name === '@/components/inventory-admin') return { InventoryAdmin, OrderPositionsAdmin };
      if (name === '@/lib/cart-client') return { money: value => `${value}`, shopApiOrigin: 'https://test.invalid' };
      throw new Error('Unexpected component dependency');
    },
  });
  return {
    states, effects, timers, InventoryAdmin, OrderPositionsAdmin,
    render(name, props = {}) { cursor = 0; return testModule.exports[name](props); },
  };
}
function nodes(node, predicate) {
  if (Array.isArray(node)) return node.flatMap(child => nodes(child, predicate));
  if (!node || typeof node !== 'object') return [];
  return [...(predicate(node) ? [node] : []), ...nodes(node.props?.children, predicate)];
}
const one = (tree, type) => nodes(tree, node => node.type === type)[0];
const status = tree => nodes(tree, node => node.props?.role === 'status')[0].props.children;
const response = (body, ok = true) => ({ ok, json: async () => body });
const event = { preventDefault() {} };
const tick = () => new Promise(resolve => setImmediate(resolve));

test('Admin-Netzwerkfehler beendet die Ladeanzeige ohne technische Fehlerdetails', async () => {
  const h = harness('admin-console.tsx', async () => { throw new Error('PRIVATE-error-detail'); });
  await one(h.render('AdminConsole'), 'form').props.onSubmit(event);
  const tree = h.render('AdminConsole');
  assert.match(status(tree), /nicht erreichbar/);
  assert.doesNotMatch(status(tree), /PRIVATE|wird geprüft/);
  assert.equal(nodes(tree, node => node.type === h.InventoryAdmin).length, 0);
});

test('Admin-Anmeldung ohne gültigen CSRF-Vertrag bleibt fail-closed', async () => {
  for (const result of [{ ok: false }, { ok: true }, { ok: true, admin: { csrfToken: '' } }]) {
    const h = harness('admin-console.tsx', async () => response(result));
    await one(h.render('AdminConsole'), 'form').props.onSubmit(event);
    const tree = h.render('AdminConsole');
    assert.equal(status(tree), 'Anmeldung nicht möglich.');
    assert.equal(nodes(tree, node => node.type === h.InventoryAdmin).length, 0);
  }
});

test('Angemeldeter Admin unterscheidet Dashboard-Ausfall vom Loginfehler', async () => {
  const h = harness('admin-console.tsx', async (url, options) => {
    assert.equal(options.cache, 'no-store');
    if (url.endsWith('/login')) return response({ ok: true, admin: { csrfToken: 'artificial-csrf' } });
    throw new Error('offline');
  });
  await one(h.render('AdminConsole'), 'form').props.onSubmit(event);
  assert.match(status(h.render('AdminConsole')), /Angemeldet, aber die Übersicht/);
});

test('Wiedereinlagerung löst die Aktualisierung der Bestandsanzeige aus', async () => {
  const h = harness('admin-console.tsx', async url => {
    if (url.endsWith('/login')) return response({ ok: true, admin: { csrfToken: 'artificial-csrf' } });
    if (url.endsWith('/orders')) return response({ orders: [{ order_id: 'test-order', order_number: 'TEST-1' }] });
    return response({ payments: [], mails: [], reviews: [] });
  });
  await one(h.render('AdminConsole'), 'form').props.onSubmit(event);
  let tree = h.render('AdminConsole');
  assert.equal(status(tree), 'Angemeldet.');
  assert.equal(one(tree, h.InventoryAdmin).props.refreshVersion, 0);
  one(tree, h.OrderPositionsAdmin).props.onInventoryChanged();
  tree = h.render('AdminConsole');
  assert.equal(one(tree, h.InventoryAdmin).props.refreshVersion, 1);
});

test('Fehlgeschlagene Bestandsaktualisierung entfernt veraltete Mengen aus der Anzeige', async () => {
  let offline = false;
  const h = harness('inventory-admin.tsx', async () => {
    if (offline) throw new Error('PRIVATE-error-detail');
    return response({ inventory: [{ productId: 'A', name: 'A', onHand: 3 }] });
  });
  h.render('InventoryAdmin', { csrf: 'test', refreshVersion: 0 });
  h.effects.at(-1).effect(); h.timers.at(-1)(); await tick();
  assert.equal(h.states[0].length, 1);
  offline = true;
  h.render('InventoryAdmin', { csrf: 'test', refreshVersion: 1 });
  assert.equal(h.effects.at(-1).deps[0], 1);
  h.effects.at(-1).effect(); h.timers.at(-1)(); await tick();
  assert.equal(h.states[0].length, 0);
  assert.match(status(h.render('InventoryAdmin')), /Bestand nicht erreichbar/);
});

for (const uncertain of [false, true]) {
  test(`Wiedereinlagerung: ${uncertain ? 'unklarer' : 'bestätigter'} Ausgang sperrt Wiederholung und aktualisiert Bestand`, async () => {
    let refreshed = 0; let mutations = 0;
    const h = harness('inventory-admin.tsx', async (url, options) => {
      if (url.endsWith('/restock-position')) {
        mutations++;
        const body = JSON.parse(options.body);
        assert.equal(body.quantity, 1); assert.equal(body.expectedInventoryVersion, 4);
        assert.equal(body.receivedAndChecked, true);
        if (uncertain) throw new Error('network failure');
        return response({ ok: true });
      }
      if (url.includes('/inventory/')) return response({ inventory: { inventoryVersion: 4 } });
      return response({ order: { items: [{ productId: 'A', positionNo: 1, name: 'A', quantity: 2, priceMinor: 2800 }] } });
    });
    const props = { orderId: 'test', csrf: 'test', onInventoryChanged: () => refreshed++ };
    h.render('OrderPositionsAdmin', props).props.onToggle({ currentTarget: { open: true } }); await tick();
    const submit = one(h.render('OrderPositionsAdmin', props), 'form').props.onSubmit;
    submit({ ...event, currentTarget: { quantity: '1', checked: 'on' } }); await tick();
    let tree = h.render('OrderPositionsAdmin', props);
    assert.equal(refreshed, 1); assert.equal(mutations, 1);
    assert.equal(one(tree, 'button').props.disabled, true);
    one(tree, 'form').props.onSubmit({ ...event, currentTarget: { quantity: '1', checked: 'on' } }); await tick();
    tree = h.render('OrderPositionsAdmin', props);
    assert.equal(mutations, 1); assert.equal(one(tree, 'button').props.disabled, true);
  });
}
