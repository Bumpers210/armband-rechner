import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import test from 'node:test';

const root = path.resolve(import.meta.dirname, '..', '..');
const read = (relative) => fs.readFileSync(path.join(root, relative), 'utf8');

test('AP5-Migration enthält Admin- und delivery_unknown-Vertrag', () => {
  const migration = read('database/migrations/commerce-v1-ap5-admin.sql');
  const schema = read('database/commerce-schema.sql');
  assert.match(migration, /CREATE TABLE IF NOT EXISTS admin_users/);
  assert.match(migration, /CREATE TABLE IF NOT EXISTS admin_sessions/);
  assert.match(migration, /CREATE TABLE IF NOT EXISTS admin_login_attempts/);
  assert.match(migration, /delivery_unknown/);
  assert.match(schema, /delivery_unknown/);
});

test('Adminbereich führt keine Stripe-Erstattung aus', () => {
  const bootstrap = read('test-api-private/program/bootstrap.php');
  assert.match(bootstrap, /\['refunds'\]/);
  assert.match(bootstrap, /\['payments'\]/);
  assert.doesNotMatch(bootstrap, /stripe.*refund|createRefund/i);
});

test('Admin-Sitzung verwendet getrennte sichere Cookie- und CSRF-Verträge', () => {
  const admin = read('test-api-private/program/shop-admin.php');
  assert.match(admin, /__Host-cmj_admin/);
  assert.match(admin, /samesite.*Strict/i);
  assert.match(admin, /PASSWORD_ARGON2ID/);
  assert.match(admin, /admin_csrf_invalid/);
});

test('Brevo-Outbox verwendet Provider-Idempotenz und unbekannten Ausgang', () => {
  const worker = read('test-api-private/program/ap5-worker.php');
  assert.match(worker, /['"]idempotencyKey['"]\s*=>/);
  assert.match(worker, /brevo_idempotency_duplicate/);
  assert.doesNotMatch(worker, /'Idempotency-Key:\s*'/);
  assert.match(worker, /delivery_unknown/);
  assert.match(worker, /CURLOPT_SSL_VERIFYPEER.*true/si);
});

test('Privater AP5-Worker ist CLI-only und geheimnisfrei verdrahtet', () => {
  const cli = read('test-api-private/program/ap5-worker-cli.php');
  assert.match(cli, /PHP_SAPI !== 'cli'/);
  assert.match(cli, /CarmajaAp5Worker/);
  assert.doesNotMatch(cli, /commercePassword|stripeSecretKey|brevoApiKey\s*=/);
});

test('Admin-UI zeigt Refunds nur an', () => {
  const page = read('components/admin-console.tsx');
  assert.match(page, /Erstattungen werden hier nur angezeigt/);
  assert.match(page, /payment_method_type/);
  assert.match(page, /noch keine Bestellung/);
  assert.doesNotMatch(page, /refund.*POST|createRefund/i);
});
