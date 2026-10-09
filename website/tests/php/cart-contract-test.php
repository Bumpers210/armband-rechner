<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/test-api-private/program/cart-contract.php';
require_once dirname(__DIR__, 2) . '/test-api-private/program/stripe-contract.php';
require_once dirname(__DIR__, 2) . '/test-api-private/program/cart-public.php';

function cart_assert(bool $condition, string $name): void {
    if (!$condition) { throw new RuntimeException($name); }
    echo '[OK] ' . $name . PHP_EOL;
}
function cart_reject(callable $test, string $name): void {
    try { $test(); } catch (CarmajaCommerceException|CarmajaStripeException) { cart_assert(true, $name); return; }
    throw new RuntimeException('Expected rejection: ' . $name);
}

$a = 'product-0001'; $b = 'product-0002';
cart_assert(strlen(carmaja_cart_rate_owner('', '192.0.2.1')) === 64, 'Erstbesuch ohne Cookie erreicht Shop-Kontext');
cart_assert(carmaja_cart_rate_owner('', '192.0.2.1') !== carmaja_cart_rate_owner('', '192.0.2.2'), 'Anonyme Ratelimitidentität nach IP getrennt');
cart_assert(carmaja_cart_rate_owner(str_repeat('a', 43), '192.0.2.1') === hash('sha256', str_repeat('a', 43)), 'Bestehende Sitzung bleibt Ratelimitidentität');
cart_assert(carmaja_cart_rate_owner(str_repeat('a', 129), '192.0.2.1') === carmaja_cart_rate_owner('', '192.0.2.1'), 'Ungültiges Cookie blockiert Kontext-Neuanlage nicht');
$items = carmaja_cart_items([['productId' => $b, 'quantity' => 2], ['productId' => $a, 'quantity' => 1], ['productId' => $b, 'quantity' => 3]]);
cart_assert($items === [['productId' => $a, 'quantity' => 1], ['productId' => $b, 'quantity' => 5]], 'Duplikate zusammengeführt; stabile Sperrreihenfolge');
foreach ([0,-1,1.5,'2',true,4294967296] as $quantity) {
    cart_reject(fn () => carmaja_cart_items([['productId' => $a, 'quantity' => $quantity]]), 'Ungültige Menge abgelehnt (' . get_debug_type($quantity) . ')');
}
cart_reject(fn () => carmaja_cart_items([['productId' => $a, 'quantity' => 1, 'priceMinor' => 1]]), 'Preismanipulation abgelehnt');
$hundred = array_map(fn ($i) => ['productId' => 'product-' . str_pad((string) $i, 4, '0', STR_PAD_LEFT), 'quantity' => 1], range(1,100));
cart_assert(count(carmaja_cart_items($hundred)) === 100, '100 Positionen erlaubt');
cart_reject(fn () => carmaja_cart_items([...$hundred, ['productId' => 'product-0101', 'quantity' => 1]]), '101 Positionen abgelehnt');
$lines = array_map(fn ($line) => [...$line, 'priceMinor' => 2800, 'currency' => 'eur', 'name' => 'Künstliches Armband',
    'productVersion' => 1, 'sourceHash' => str_repeat('a',64), 'noticePolicyId' => 'natural-stones-v1', 'notices' => CARMAJA_PRODUCT_NOTICES], $items);
cart_assert(carmaja_cart_total($lines) === 17070, 'Versand genau einmal 270 Cent');
cart_reject(fn () => carmaja_cart_total($lines, 540), 'Abweichender Versand abgelehnt');
$body = ['targetOnHand' => 8, 'expectedInventoryVersion' => 2, 'reason' => 'receive_stock', 'correlationId' => 'operation-0001'];
cart_assert(carmaja_inventory_adjustment($body) === $body, 'Separater Mengenbestandsvertrag');
cart_reject(fn () => carmaja_inventory_adjustment([...$body, 'reason' => 'shop_sale']), 'Manueller Shopverkauf abgelehnt');
cart_reject(fn () => carmaja_inventory_adjustment([...$body, 'targetOnHand' => -1]), 'Negativer Bestand abgelehnt');
$snapshot = ['items' => $lines, 'shippingSnapshot' => ['shippingMethodId' => 'maxibrief', 'publicName' => 'Maxibrief', 'amountMinor' => 270, 'currency' => 'eur'],
    'checkoutId' => 'checkout-0001', 'legalBundleId' => 'cmj-test-legal-2026-10-06-v1', 'totalMinor' => 17070, 'snapshotHash' => str_repeat('c',64)];
$params = carmaja_stripe_checkout_parameters($snapshot, 'https://test.carmaja-perlen.de/shop/success/', 'https://test.carmaja-perlen.de/shop/cancel/', time()+1800);
cart_assert(count($params['line_items']) === 2 && $params['line_items'][1]['quantity'] === 5, 'Stripe erhält mehrere serverseitige Positionen und Mengen');
cart_assert($params['consent_collection']['terms_of_service'] === 'required' && $params['payment_method_types'] === ['card','klarna','sepa_debit'], 'AGB-Zustimmung und Zahlungsarten erhalten; kein PayPal');
cart_assert($params['metadata']['snapshotHash'] === $snapshot['snapshotHash'] && !isset($params['metadata']['productId']), 'Gesamtsnapshot statt Einzelprodukt-Metadaten');
$checkout = ['checkout_id' => 'checkout-0001', 'legal_bundle_id' => $snapshot['legalBundleId'], 'snapshot_hash' => $snapshot['snapshotHash']];
$payment = ['amount_minor' => 17070, 'stripe_checkout_session_id' => 'cs_test_artificial'];
$event = ['paymentIntentStatus' => 'processing', 'paymentStatus' => 'processing', 'paymentMethodType' => 'sepa_debit', 'amountMinor' => 17070,
    'currency' => 'eur', 'legalBundleId' => $snapshot['legalBundleId'], 'termsAccepted' => true, 'metadata' => $params['metadata'],
    'stripeCheckoutSessionId' => 'cs_test_artificial', 'stripePaymentIntentId' => 'pi_artificial'];
cart_assert(carmaja_cart_payment_valid($checkout, $payment, $event, 'processing'), 'SEPA processing prüfbar');
cart_assert(!carmaja_cart_payment_valid($checkout, $payment, $event, 'succeeded'), 'Processing ist kein Zahlungserfolg');
foreach (['amountMinor' => 1, 'termsAccepted' => false, 'stripeCheckoutSessionId' => 'cs_other', 'currency' => 'usd'] as $field => $value) {
    cart_assert(!carmaja_cart_payment_valid($checkout, $payment, [...$event, $field => $value], 'processing'), 'Zahlungsabweichung abgelehnt: ' . $field);
}
cart_assert(!carmaja_cart_payment_valid($checkout, [...$payment, 'stripe_payment_intent_id' => 'pi_other'], $event, 'processing'), 'Bestehender PaymentIntent darf nicht ersetzt werden');
echo 'Warenkorb-Vertragstests erfolgreich.' . PHP_EOL;
