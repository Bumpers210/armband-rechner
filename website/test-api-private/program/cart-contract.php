<?php

declare(strict_types=1);

require_once __DIR__ . '/commerce-core.php';

const CARMAJA_CART_MAX_POSITIONS = 100;
const CARMAJA_CART_MAX_AMOUNT = 99999999;
const CARMAJA_PRODUCT_NOTICES = [
    'Natursteine können in Farbe, Maserung und Form von der Abbildung abweichen.',
    'Die den Steinen zugeschriebenen Wirkungen sind wissenschaftlich nicht nachgewiesen.',
];

function carmaja_cart_items(mixed $items): array
{
    if (!is_array($items) || !array_is_list($items)) {
        throw new CarmajaCommerceException('cart_invalid', 'Höchstens 100 Positionen sind erlaubt.', 422);
    }
    $merged = [];
    foreach ($items as $item) {
        if (!is_array($item) || count($item) !== 2 || !isset($item['productId'], $item['quantity'])
            || !is_string($item['productId']) || !is_int($item['quantity'])
            || $item['quantity'] < 1 || $item['quantity'] > 4294967295) {
            throw new CarmajaCommerceException('cart_invalid', 'Produkt und positive ganze Menge sind erforderlich.', 422);
        }
        carmaja_commerce_assert_id($item['productId'], 'productId');
        $merged[$item['productId']] = ($merged[$item['productId']] ?? 0) + $item['quantity'];
        if (count($merged) > CARMAJA_CART_MAX_POSITIONS) {
            throw new CarmajaCommerceException('cart_invalid', 'Höchstens 100 unterschiedliche Positionen sind erlaubt.', 422);
        }
        if ($merged[$item['productId']] > 4294967295) {
            throw new CarmajaCommerceException('cart_invalid', 'Menge ist zu groß.', 422);
        }
    }
    ksort($merged, SORT_STRING);
    $result = [];
    foreach ($merged as $id => $quantity) {
        $result[] = ['productId' => $id, 'quantity' => $quantity];
    }
    return $result;
}

function carmaja_inventory_adjustment(array $body): array
{
    $fields = ['targetOnHand','expectedInventoryVersion','reason','correlationId'];
    if (array_diff(array_keys($body), $fields) !== [] || count($body) !== 4
        || !is_int($body['targetOnHand'] ?? null) || $body['targetOnHand'] < 0
        || $body['targetOnHand'] > 4294967295
        || !is_int($body['expectedInventoryVersion'] ?? null) || $body['expectedInventoryVersion'] < 0
        || !in_array($body['reason'] ?? null, ['initial_count','receive_stock','mark_unsellable','release_return','correction'], true)
        || !is_string($body['correlationId'] ?? null)) {
        throw new CarmajaCommerceException('inventory_adjustment_invalid', 'Bestandsänderung ist ungültig.', 422);
    }
    carmaja_commerce_assert_id($body['correlationId'], 'correlationId');
    return $body;
}

function carmaja_cart_total(array $items, int $shipping = 270): int
{
    if ($shipping !== 270) {
        throw new CarmajaCommerceException('shipping_contract_invalid', 'Versandvertrag stimmt nicht überein.', 503);
    }
    $total = $shipping;
    foreach ($items as $item) {
        if (!is_int($item['priceMinor'] ?? null) || $item['priceMinor'] < 1
            || ($item['currency'] ?? null) !== 'eur' || !is_int($item['quantity'] ?? null)
            || $item['quantity'] < 1) {
            throw new CarmajaCommerceException('cart_snapshot_invalid', 'Preis oder Menge ist ungültig.', 422);
        }
        $total += $item['priceMinor'] * $item['quantity'];
        if ($total > CARMAJA_CART_MAX_AMOUNT) {
            throw new CarmajaCommerceException('cart_amount_invalid', 'Gesamtbetrag ist zu groß.', 422);
        }
    }
    return $total;
}

function carmaja_cart_payment_valid(array $checkout, array $payment, array $event, string $status): bool
{
    $metadata = $event['metadata'] ?? [];
    $intentMatches = $status === 'failed'
        ? in_array($event['paymentIntentStatus'] ?? '', ['requires_payment_method','canceled'], true)
        : ($event['paymentIntentStatus'] ?? '') === $status;
    return $intentMatches
        && ($event['paymentStatus'] ?? '') === $status
        && in_array($event['paymentMethodType'] ?? '', ['card','klarna','sepa_debit'], true)
        && ($event['amountMinor'] ?? -1) === (int) $payment['amount_minor']
        && ($event['currency'] ?? '') === 'eur'
        && ($event['legalBundleId'] ?? '') === $checkout['legal_bundle_id']
        && ($event['termsAccepted'] ?? false) === true
        && ($metadata['checkoutId'] ?? '') === $checkout['checkout_id']
        && is_string($metadata['snapshotHash'] ?? null)
        && hash_equals($checkout['snapshot_hash'], $metadata['snapshotHash'])
        && ($event['stripeCheckoutSessionId'] ?? '') === $payment['stripe_checkout_session_id']
        && is_string($event['stripePaymentIntentId'] ?? null)
        && str_starts_with($event['stripePaymentIntentId'], 'pi_')
        && (($payment['stripe_payment_intent_id'] ?? null) === null
            || $payment['stripe_payment_intent_id'] === $event['stripePaymentIntentId']);
}
