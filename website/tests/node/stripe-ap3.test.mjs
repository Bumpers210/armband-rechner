import assert from "node:assert/strict";
import { readFile } from "node:fs/promises";
import test from "node:test";

test("AP3b pins Stripe SDK, API-Version and the nine-event-Allowlist", async () => {
  const composer = JSON.parse(await readFile(new URL("../../composer.json", import.meta.url), "utf8"));
  const lock = JSON.parse(await readFile(new URL("../../composer.lock", import.meta.url), "utf8"));
  assert.equal(composer.require["stripe/stripe-php"], "20.3.0");
  assert.equal(lock.packages.find((pkg) => pkg.name === "stripe/stripe-php").version, "20.3.0");

  const contract = await readFile(new URL("../../test-api-private/program/stripe-contract.php", import.meta.url), "utf8");
  assert.match(contract, /CARMAJA_STRIPE_API_VERSION = '2026-06-24\.dahlia'/);
  assert.match(contract, /CARMAJA_STRIPE_WEBHOOK_API_VERSION = '2026-07-29\.dahlia'/);
  assert.match(contract, /CARMAJA_STRIPE_CHECKOUT_LIFETIME_SECONDS = 1800/);
  for (const event of [
    "checkout.session.completed",
    "checkout.session.async_payment_succeeded",
    "checkout.session.async_payment_failed",
    "checkout.session.expired",
    "charge.refunded",
    "refund.updated",
    "charge.dispute.created",
    "charge.dispute.updated",
    "charge.dispute.closed",
  ]) {
    assert.match(contract, new RegExp(event.replaceAll(".", "\\.")));
  }
});

test("Stripe Checkout verwendet ausschließlich serverseitige Preis-/Versanddaten", async () => {
  const contract = await readFile(new URL("../../test-api-private/program/stripe-contract.php", import.meta.url), "utf8");
  for (const required of [
    "price_data",
    "shipping_rate_data",
    "payment_method_types",
    "allow_promotion_codes",
    "wallet_options",
    "consent_collection",
    "terms_of_service",
    "payment_intent_data",
    "billing_address_collection",
    "shipping_address_collection",
    "automatic_tax",
  ]) {
    assert.match(contract, new RegExp(required));
  }
  assert.match(contract, /CARMAJA_STRIPE_PAYMENT_METHOD_TYPES = \[\s*'card',\s*'paypal',\s*'klarna',\s*'sepa_debit'/s);
  assert.match(contract, /payment_method_types' => CARMAJA_STRIPE_PAYMENT_METHOD_TYPES/);
  assert.match(contract, /allow_promotion_codes' => false/);
  assert.match(contract, /'display' => 'never'/);
  assert.match(contract, /'terms_of_service' => 'required'/);
  assert.match(contract, /'enabled' => false/);
  assert.doesNotMatch(contract, /promotion_codes' => true/);
});

test("Webhook-Vertrag persistiert vor 2xx und quittiert unbekannte signierte Events mit 204", async () => {
  const webhook = await readFile(new URL("../../test-api-private/program/stripe-webhook.php", import.meta.url), "utf8");
  const contract = await readFile(new URL("../../test-api-private/program/stripe-contract.php", import.meta.url), "utf8");
  assert.match(contract, /CARMAJA_STRIPE_WEBHOOK_MAX_BYTES = 262144/);
  assert.match(webhook, /carmaja_stripe_verify_signature/);
  assert.match(webhook, /'status' => 204/);
  assert.match(webhook, /'ignored' => true/);
  assert.match(webhook, /\$persist\(\$envelope, \$rawBody\)/);
  assert.match(webhook, /CARMAJA_STRIPE_WEBHOOK_ALLOWLIST/);
  assert.match(webhook, /CARMAJA_STRIPE_WEBHOOK_API_VERSION/);
});

test("Worker führt externe Stripe-Aktionen außerhalb lokaler Transaktionen aus", async () => {
  const worker = await readFile(new URL("../../test-api-private/program/ap3-worker.php", import.meta.url), "utf8");
  assert.match(worker, /claimWebhookBatch/);
  assert.match(worker, /completeWebhook/);
  assert.match(worker, /claimMetadataOutbox/);
  assert.match(worker, /updatePaymentIntentMetadata/);
  assert.match(worker, /retrieveCheckoutSession/);
  assert.match(worker, /retrievePaymentIntent/);
  assert.match(worker, /status === 'complete'/);
  assert.match(worker, /finalizePayment/);
  assert.match(worker, /markPaymentProcessing/);
  assert.match(worker, /failAsyncPayment/);
  assert.match(worker, /LEASE_SECONDS = 600/);
});

test("Worker reconciles asynchronous Checkout events before local state mutation", async () => {
  const worker = await readFile(new URL("../../test-api-private/program/ap3-worker.php", import.meta.url), "utf8");
  assert.match(worker, /checkout\.session\.completed[\s\S]*retrieveCurrentCheckoutSession\(\$object\)/);
  assert.match(worker, /checkout\.session\.async_payment_succeeded[\s\S]*retrieveCurrentCheckoutSession\(\$object\)/);
  assert.match(worker, /checkout\.session\.async_payment_failed[\s\S]*retrieveCurrentCheckoutSession\(\$object\)/);
  assert.match(worker, /return \$this->stripe->retrieveCheckoutSession\(\$sessionId\)/);
});

test("AP3-Datenbankpfade sichern Retry, Payment-Intent und Dispute-Ordnung", async () => {
  const worker = await readFile(new URL("../../test-api-private/program/ap3-worker.php", import.meta.url), "utf8");
  const commerce = await readFile(new URL("../../test-api-private/program/commerce-core.php", import.meta.url), "utf8");
  assert.match(worker, /recordDispute/);
  assert.match(commerce, /attempt_count <= 5/);
  assert.match(commerce, /stripe_payment_intent_id = \? WHERE payment_id/);
  assert.match(commerce, /status = 'processing'/);
  assert.match(commerce, /payment_method_type/);
  assert.match(commerce, /last_event_at <= VALUES\(last_event_at\)/);
});

test("Shop-Routen trennen Checkout und Stripe-Webhook", async () => {
  const bootstrap = await readFile(new URL("../../test-api-private/program/bootstrap.php", import.meta.url), "utf8");
  assert.match(bootstrap, /\['shop', 'v1', 'checkouts'\]/);
  assert.match(bootstrap, /\['stripe', 'webhook'\]/);
  assert.match(bootstrap, /persistWebhookEnvelope/);
});
