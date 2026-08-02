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
  assert.match(schema, /orders[\s\S]*CHECK \(status IN \('confirmed', 'canceled'\)\)/);
  assert.match(schema, /shipments[\s\S]*'not_ready'[\s\S]*'ready'[\s\S]*'on_hold'[\s\S]*'shipped'[\s\S]*'delivery_issue'[\s\S]*'returned'/);
  assert.match(schema, /webhook_inbox[\s\S]*UNIQUE KEY uq_webhook_event/);
});

test("AP2-Schema hält die Unikatgründe und Binärbestand fest", async () => {
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
});
