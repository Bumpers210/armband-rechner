import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const readJson = name => JSON.parse(readFileSync(new URL(`../../${name}`, import.meta.url), 'utf8'));
const manifest = readJson('package.json');
const lock = readJson('package-lock.json');

function atLeast(actual, minimum) {
  assert.match(actual, /^\d+\.\d+\.\d+$/, 'Stable exact version required');
  const left = actual.split('.').map(Number);
  const right = minimum.split('.').map(Number);
  for (let index = 0; index < 3; index++) {
    if (left[index] !== right[index]) return left[index] > right[index];
  }
  return true;
}

// Regression floor for the advisories verified on 2026-10-25.
// This is intentionally offline and does not replace a fresh npm audit.
test('Next, ESLint-Konfiguration und sharp bleiben stabil gepinnt und sicherheitsaktualisiert', () => {
  const packages = { ...manifest.dependencies, ...manifest.devDependencies };
  assert.equal(packages.next, packages['eslint-config-next']);
  for (const [name, minimum] of Object.entries({ next: '16.4.3', 'eslint-config-next': '16.4.3', sharp: '0.35.5' })) {
    assert.ok(atLeast(packages[name], minimum), `${name}: security floor`);
    assert.equal(lock.packages[`node_modules/${name}`].version, packages[name]);
    const pinned = lock.packages[''].dependencies?.[name] ?? lock.packages[''].devDependencies?.[name];
    assert.equal(pinned, packages[name]);
  }
});

test('Alle betroffenen indirekten Lockdateieintraege vermeiden die bekannten verwundbaren Versionen', () => {
  const floors = { 'js-yaml': '4.3.2', 'source-map-js': '1.2.2', zod: '4.6.6' };
  const seen = new Set();
  for (const [path, entry] of Object.entries(lock.packages)) {
    const name = path.split('node_modules/').at(-1);
    if (name === 'brace-expansion') {
      const major = Number(entry.version.split('.')[0]);
      assert.ok(major === 1 || major === 5, 'Review any new brace-expansion major');
      assert.ok(atLeast(entry.version, major === 1 ? '1.1.21' : '5.0.12'), path);
      seen.add(name);
    } else if (floors[name]) {
      assert.ok(atLeast(entry.version, floors[name]), path);
      seen.add(name);
    }
  }
  assert.deepEqual([...seen].sort(), ['brace-expansion', 'js-yaml', 'source-map-js', 'zod']);
});

test('Native Next-/sharp-Artefakte sind mit den aktualisierten Hauptpaketen konsistent', () => {
  for (const [path, entry] of Object.entries(lock.packages)) {
    if (path.startsWith('node_modules/@next/swc-') || path === 'node_modules/@next/env') {
      assert.equal(entry.version, manifest.dependencies.next, path);
    }
    if (path.startsWith('node_modules/@img/sharp-libvips-')) {
      assert.ok(atLeast(entry.version, '1.3.4'), path);
    } else if (path.startsWith('node_modules/@img/sharp-')) {
      assert.equal(entry.version, manifest.dependencies.sharp, path);
    }
  }
});
