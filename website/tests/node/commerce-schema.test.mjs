import assert from "node:assert/strict";
import { readFile } from "node:fs/promises";
import { test } from "node:test";

const schemaPath = new URL("../../database/commerce-schema.sql", import.meta.url);

test("AP2-Schema nutzt MySQL-InnoDB und trennt Bestands-/Zahlungsachsen", async () => {
  const schema = await readFile(schemaPath, "utf8");
  assert.match(schema, /commerce_products[\s\S]*ENGINE=InnoDB/);
  assert.match(schema, /commerce_inventory[\s\S]*on_hand/);
  assert.match(schema, /checkout_sagas[\s\S]*reservations/);
  assert.match(schema, /payments[\s\S]*verification_status[\s\S]*refund_status/);
  assert.match(schema, /payments[\s\S]*payment_method_type[\s\S]*'processing'/);
  for (const method of ["card", "paypal", "klarna", "sepa_debit"]) {
    assert.match(schema, new RegExp(method));
  }
  assert.match(schema, /orders[\s\S]*CHECK \(status IN \('confirmed', 'canceled'\)\)/);
  assert.match(schema, /shipments[\s\S]*'not_ready'[\s\S]*'ready'[\s\S]*'on_hold'[\s\S]*'shipped'[\s\S]*'delivery_issue'[\s\S]*'returned'/);
  assert.match(schema, /webhook_inbox[\s\S]*UNIQUE KEY uq_webhook_event/);
});

test("AP3b-Vorwärtsmigration ergänzt Zahlungsart und processing", async () => {
  const migration = await readFile(
    new URL("../../database/migrations/commerce-v1-ap3b-async-payments.sql", import.meta.url),
    "utf8",
  );
  assert.match(migration, /ADD COLUMN payment_method_type/);
  assert.match(migration, /DROP CHECK chk_payment_status/);
  assert.match(migration, /'processing'/);
  assert.match(migration, /'card', 'paypal', 'klarna', 'sepa_debit'/);
});

test("Schema behält historische Unikatnachweise und ergänzt Kollektionen", async () => {
  const schema = await readFile(schemaPath, "utf8");
  for (const reason of [
    "activate_new_unique",
    "shop_sale",
    "mark_unsellable",
    "release_return",
  ]) {
    assert.match(schema, new RegExp(reason));
  }
  assert.match(schema, /CHECK \(on_hand IN \(0, 1\)\)/);
  assert.match(schema, /sales_model ENUM\('unique', 'collection'\)/);
  assert.match(schema, /product_projection_operations/);
});

test("Kollektionen-Migration ist vorwärtsgerichtet und wiederholbar", async () => {
  const migration = await readFile(
    new URL("../../database/migrations/commerce-v2-collections.sql", import.meta.url),
    "utf8",
  );
  assert.match(migration, /information_schema\.COLUMNS/);
  assert.match(migration, /sales_model/);
  assert.match(migration, /product_projection_operations/);
  assert.doesNotMatch(migration, /targetOnHand|on_hand\s*=/);
});
