import assert from 'node:assert/strict';
import { createHash } from 'node:crypto';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const read = name => readFileSync(new URL(name, import.meta.url), 'utf8').replaceAll('\r\n', '\n');
const normalize = sql => sql.replace(/^\s*--.*$/gm, '');
const digest = sql => createHash('sha256').update(normalize(sql)).digest('hex');
const historical = read('../fixtures/schema-history/ap6-commerce-schema.sql');
const current = read('../../database/commerce-schema.sql');
const forward = read('../../database/migrations/commerce-v2-collections.sql');
const program = read('../../test-api-private/program/production-cart-cutover.php');

test('Historical AP6 schema and exact replacement/forward hashes remain pinned', () => {
  for (const [sql, hash] of [
    [historical, '388e3d44299ae0890fb83d4881d87aa26d7e93d30ee3af8ac07033a4106ea27b'],
    [current, 'fed1e46e6635c3f3fd60ec703cea5f14e841fcbe43629eec2ce9032b2bac52f8'],
    [forward, '33bc5be8e76781b73ff6c23950bb99ac4d9bea67e4632c917f3002b37550d00d'],
  ]) {
    assert.equal(digest(sql), hash);
    assert.ok(program.includes(hash), 'runtime accepts only the reviewed hash pair');
  }
});

test('Only separately migrated collections additions distinguish both base schemas', () => {
  const withoutCollections = normalize(current)
    .replace(/^    sales_model ENUM\('unique', 'collection'\) NOT NULL DEFAULT 'unique',\n/gm, '')
    .replace(/CREATE TABLE IF NOT EXISTS product_projection_operations \([\s\S]*?\) ENGINE=InnoDB;/, '');
  const compact = sql => sql.replace(/\n\s*\n/g, '\n').trim();
  assert.equal(compact(withoutCollections), compact(normalize(historical)));
  assert.match(forward, /ALTER TABLE commerce_products ADD COLUMN sales_model/);
  assert.match(forward, /ALTER TABLE checkout_sagas ADD COLUMN sales_model/);
  assert.match(forward, /CREATE TABLE IF NOT EXISTS product_projection_operations/);
});

test('Historical acceptance performs no SQL mutation or base-schema rerun', () => {
  const method = program.split('public static function assertJournal(')[1].split('public static function importCounts(')[0];
  assert.match(method, /SELECT migration_id,checksum FROM schema_migrations/);
  assert.doesNotMatch(method, /->(?:exec|prepare|migrate|migrateForward)\(|\b(?:UPDATE|INSERT|DELETE|ALTER|DROP|CREATE)\b/);
});
