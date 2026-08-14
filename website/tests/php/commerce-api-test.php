<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/test-api-private/program/commerce-core.php';
require_once dirname(__DIR__, 2) . '/test-api-private/program/commerce-worker.php';
require_once dirname(__DIR__, 2) . '/scripts/commerce-backup.php';

final class CarmajaCommerceTestFailure extends RuntimeException
{
}

function commerce_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new CarmajaCommerceTestFailure($message);
    }
}

function commerce_exception(callable $callback, string $code): void
{
    try {
        $callback();
    } catch (CarmajaCommerceException $error) {
        commerce_assert($error->errorCode === $code, 'Unerwarteter Fehlercode: ' . $error->errorCode);
        return;
    }
    throw new CarmajaCommerceTestFailure('Erwartete Commerce-Ausnahme fehlt: ' . $code);
}

function commerce_fixture(?string $operatorEmail = null): CarmajaCommerceMemory
{
    $commerce = new CarmajaCommerceMemory($operatorEmail);
    $commerce->addLegalBundle('legal-v1');
    $commerce->seedProduct([
        'productId' => 'CP-2026-0001',
        'name' => 'Carmaja Testarmband',
        'productVersion' => 4,
        'sourceHash' => str_repeat('a', 64),
        'priceMinor' => 4200,
        'currency' => 'eur',
        'salesEnabled' => true,
    ]);
    return $commerce;
}

function checkout_input(string $id, string $key = 'ap2-checkout-0001'): array
{
    return [
        'checkoutId' => $id,
        'idempotencyKey' => $key,
        'requestHash' => str_repeat('b', 64),
        'productId' => 'CP-2026-0001',
        'productVersion' => 4,
        'sourceHash' => str_repeat('a', 64),
        'priceMinor' => 4200,
        'currency' => 'eur',
        'shippingSnapshot' => [
            'shippingMethodId' => 'de-standard',
            'amountMinor' => 490,
            'currency' => 'eur',
        ],
        'legalBundleId' => 'legal-v1',
        'expiresAt' => '2026-08-02 12:30:00.000000',
    ];
}

$tests = [
    'Checkout, Reservierung und Payment sind getrennte Objekte' => static function (): void {
        $commerce = commerce_fixture();
        $checkout = $commerce->createCheckout(checkout_input('ap2-checkout-0001'));
        commerce_assert($checkout['state'] === 'created', 'Checkout muss created sein.');
        commerce_assert(count($commerce->reservations) === 1, 'Reservierung fehlt.');
        commerce_assert(count($commerce->payments) === 1, 'Payment darf vor Order existieren.');
        commerce_assert(count($commerce->orders) === 0, 'Vor Zahlung darf keine Order existieren.');
    },
    'Idempotenz verhindert zweite Reservierung und Konflikt wird abgelehnt' => static function (): void {
        $commerce = commerce_fixture();
        $input = checkout_input('ap2-checkout-0002', 'ap2-idem-0001');
        $first = $commerce->createCheckout($input);
        $second = $commerce->createCheckout($input);
        commerce_assert($first === $second, 'Idempotente Wiederholung muss dasselbe Ergebnis liefern.');
        commerce_assert(count($commerce->reservations) === 1, 'Wiederholung erzeugte zweite Reservierung.');
        $input['requestHash'] = str_repeat('c', 64);
        commerce_exception(static fn (): array => $commerce->createCheckout($input), 'idempotency_conflict');
    },
    'Reservierung blockiert Bestand und bestätigtes Fehlschlagen gibt ihn frei' => static function (): void {
        $commerce = commerce_fixture();
        $commerce->createCheckout(checkout_input('ap2-checkout-0003'));
        commerce_exception(
            static fn (): array => $commerce->createCheckout(checkout_input('ap2-checkout-0004', 'ap2-idem-0004')),
            'sold_out_or_reserved'
        );
        $commerce->recordStripeCreationOutcome('ap2-checkout-0003', 'failed');
        commerce_assert($commerce->reservations['ap2-checkout-0003-reservation']['state'] === 'released', 'Reservierung nicht freigegeben.');
        $commerce->createCheckout(checkout_input('ap2-checkout-0004', 'ap2-idem-0004'));
    },
    'Unklarer Stripe-Ausgang hält Bestand in manual_review' => static function (): void {
        $commerce = commerce_fixture();
        $commerce->createCheckout(checkout_input('ap2-checkout-0005'));
        $commerce->recordStripeCreationOutcome('ap2-checkout-0005', 'unknown');
        commerce_assert($commerce->reservations['ap2-checkout-0005-reservation']['blocksStock'] === true, 'Unklarer Ausgang darf Bestand nicht freigeben.');
        commerce_assert(count($commerce->reviewCases) === 1, 'Reviewcase fehlt.');
    },
    'Zehn parallele Kundenversuche bleiben je Unikat konsistent' => static function (): void {
        $commerce = new CarmajaCommerceMemory();
        $commerce->addLegalBundle('legal-v1');
        for ($index = 1; $index <= 10; $index++) {
            $id = 'CP-2026-' . str_pad((string) $index, 4, '0', STR_PAD_LEFT);
            $commerce->seedProduct([
                'productId' => $id,
                'productVersion' => 1,
                'sourceHash' => str_repeat(dechex($index), 64),
                'priceMinor' => 1000 + $index,
                'currency' => 'eur',
                'salesEnabled' => true,
            ]);
            $input = checkout_input('ap2-parallel-' . str_pad((string) $index, 4, '0', STR_PAD_LEFT), 'ap2-parallel-key-' . str_pad((string) $index, 4, '0', STR_PAD_LEFT));
            $input['productId'] = $id;
            $input['productVersion'] = 1;
            $input['sourceHash'] = str_repeat(dechex($index), 64);
            $input['priceMinor'] = 1000 + $index;
            $commerce->createCheckout($input);
        }
        commerce_assert(count($commerce->reservations) === 10, 'Parallele Unikatsitzungen sind nicht vollständig.');
    },
    'Crash-/Lease-Fälle bleiben prüfbar und geben unsicheren Bestand nicht frei' => static function (): void {
        $commerce = commerce_fixture();
        $commerce->createCheckout(checkout_input('ap2-checkout-crash'));
        $commerce->releaseExpiredReservation('ap2-checkout-crash', false);
        commerce_assert($commerce->reservations['ap2-checkout-crash-reservation']['state'] === 'manual_review', 'Unbestätigter Ablauf wurde freigegeben.');
        $commerce->releaseExpiredReservation('ap2-checkout-crash', true);
        commerce_assert($commerce->reservations['ap2-checkout-crash-reservation']['state'] === 'released', 'Bestätigter Ablauf wurde nicht freigegeben.');
    },
    'Zahlungsfinalisierung ist atomar und wiederholbar' => static function (): void {
        $commerce = commerce_fixture();
        $commerce->createCheckout(checkout_input('ap2-checkout-0006'));
        $event = [
            'paymentStatus' => 'succeeded',
            'paymentIntentStatus' => 'succeeded',
            'paymentMethodType' => 'card',
            'stripePaymentIntentId' => 'pi_ap2_0006',
            'amountMinor' => 4690,
            'currency' => 'eur',
            'productId' => 'CP-2026-0001',
            'legalBundleId' => 'legal-v1',
            'termsAccepted' => true,
        ];
        $order = $commerce->finalizePayment('ap2-checkout-0006', $event);
        $sameOrder = $commerce->finalizePayment('ap2-checkout-0006', $event);
        commerce_assert($order === $sameOrder, 'Doppeltes Event erzeugte eine zweite Order.');
        commerce_assert(count($commerce->orders) === 1, 'Orderanzahl ist nicht eins.');
        commerce_assert($commerce->inventory['CP-2026-0001']['onHand'] === 0, 'Bestand wurde nicht reduziert.');
        commerce_assert($commerce->reservations['ap2-checkout-0006-reservation']['state'] === 'converted', 'Reservierung nicht converted.');
        commerce_assert($commerce->shipments[$order['orderId']]['status'] === 'ready', 'Versandstatus fehlt.');
        commerce_assert($commerce->orders[$order['orderId']]['status'] === 'confirmed', 'Orderstatus ist nicht confirmed.');
    },
    'Betreiberhinweis ist getrennt dedupliziert und datensparsam' => static function (): void {
        $commerce = commerce_fixture('operator@example.invalid');
        $commerce->createCheckout(checkout_input('ap2-checkout-mail'));
        $order = $commerce->finalizePayment('ap2-checkout-mail', [
            'paymentStatus' => 'succeeded',
            'paymentIntentStatus' => 'succeeded',
            'paymentMethodType' => 'card',
            'stripePaymentIntentId' => 'pi_ap2_mail',
            'amountMinor' => 4690,
            'currency' => 'eur',
            'productId' => 'CP-2026-0001',
            'legalBundleId' => 'legal-v1',
            'termsAccepted' => true,
            'customerName' => 'Kundin Geheim',
            'customerEmail' => 'kundin@example.invalid',
        ]);

        $customerKey = 'order-confirmation:' . $order['orderId'];
        $operatorKey = 'operator-order-notification:' . $order['orderId'];
        commerce_assert(count($commerce->mailOutbox) === 2, 'Bestell- und Betreiber-Mail müssen getrennt vorliegen.');
        commerce_assert(isset($commerce->mailOutbox[$customerKey]), 'Bestellmail fehlt.');
        commerce_assert(isset($commerce->mailOutbox[$operatorKey]), 'Betreiberhinweis fehlt.');
        $operator = $commerce->mailOutbox[$operatorKey];
        commerce_assert($operator['recipient'] === 'operator@example.invalid', 'Betreiberadresse stimmt nicht.');
        commerce_assert($operator['messageType'] === 'operator_order_notification', 'Betreiber-Mailtyp stimmt nicht.');
        $payload = $operator['payload'];
        commerce_assert(
            array_keys($payload) === ['orderNumber', 'product', 'totalMinor', 'currency'],
            'Betreiberpayload enthält unerlaubte Hauptfelder.'
        );
        commerce_assert(
            array_keys($payload['product']) === ['productId', 'name', 'quantity'],
            'Betreiberpayload enthält unerlaubte Produktfelder.'
        );
        $encoded = json_encode($payload, JSON_THROW_ON_ERROR);
        foreach (['Kundin Geheim', 'kundin@example.invalid', 'customerName', 'customerEmail', 'paymentMethodType'] as $forbidden) {
            commerce_assert(!str_contains($encoded, $forbidden), 'Betreiberpayload enthält Kundendaten oder Zahlungsdetails.');
        }
        commerce_assert($payload['product']['name'] === 'Carmaja Testarmband', 'Produktname fehlt im Betreiberhinweis.');
        commerce_assert($payload['totalMinor'] === 4690, 'Gesamtbetrag fehlt im Betreiberhinweis.');
    },
    'Fehlende Rechtstextzustimmung erzeugt Review und keine Order' => static function (): void {
        $commerce = commerce_fixture();
        $commerce->createCheckout(checkout_input('ap2-checkout-0007'));
        commerce_exception(static fn (): array => $commerce->finalizePayment('ap2-checkout-0007', [
            'paymentStatus' => 'succeeded', 'paymentIntentStatus' => 'succeeded',
            'paymentMethodType' => 'paypal', 'stripePaymentIntentId' => 'pi_ap2_0007',
            'amountMinor' => 4690, 'currency' => 'eur',
            'productId' => 'CP-2026-0001', 'legalBundleId' => 'legal-v1', 'termsAccepted' => false,
        ]), 'manual_review');
        commerce_assert(count($commerce->orders) === 0, 'Reviewzahlung darf keine Order erzeugen.');
    },
    'Erstattung ändert Bestand nicht' => static function (): void {
        $commerce = commerce_fixture();
        $commerce->createCheckout(checkout_input('ap2-checkout-0008'));
        $order = $commerce->finalizePayment('ap2-checkout-0008', [
            'paymentStatus' => 'succeeded', 'paymentIntentStatus' => 'succeeded',
            'paymentMethodType' => 'klarna', 'stripePaymentIntentId' => 'pi_ap2_0008',
            'amountMinor' => 4690, 'currency' => 'eur',
            'productId' => 'CP-2026-0001', 'legalBundleId' => 'legal-v1', 'termsAccepted' => true,
        ]);
        $commerce->applyRefund('ap2-checkout-0008-payment', 're_test_0001', 'succeeded');
        commerce_assert($commerce->inventory['CP-2026-0001']['onHand'] === 0, 'Erstattung hat unerlaubt wiedereingelagert.');
        commerce_assert($commerce->orders[$order['orderId']]['status'] === 'confirmed', 'Erstattung darf Orderstatus nicht doppelt führen.');
    },
    'SEPA-processing blockiert Bestand und erzeugt keine Bestellung' => static function (): void {
        $commerce = commerce_fixture();
        $commerce->createCheckout(checkout_input('ap3b-checkout-processing'));
        $result = $commerce->markPaymentProcessing('ap3b-checkout-processing', [
            'paymentStatus' => 'processing', 'paymentIntentStatus' => 'processing',
            'paymentMethodType' => 'sepa_debit', 'stripePaymentIntentId' => 'pi_ap3b_processing',
            'amountMinor' => 4690, 'currency' => 'eur', 'productId' => 'CP-2026-0001',
            'legalBundleId' => 'legal-v1', 'termsAccepted' => true,
        ]);
        commerce_assert($result['status'] === 'processing', 'SEPA-Zahlung ist nicht processing.');
        commerce_assert(count($commerce->orders) === 0, 'Processing darf keine Bestellung erzeugen.');
        commerce_assert(count($commerce->shipments) === 0, 'Processing darf keinen Versand erzeugen.');
        commerce_assert($commerce->reservations['ap3b-checkout-processing-reservation']['blocksStock'] === true, 'Processing muss Bestand blockieren.');
    },
    'Asynchroner Erfolg finalisiert genau einmal' => static function (): void {
        $commerce = commerce_fixture();
        $commerce->createCheckout(checkout_input('ap3b-checkout-success'));
        $processing = [
            'paymentStatus' => 'processing', 'paymentIntentStatus' => 'processing',
            'paymentMethodType' => 'sepa_debit', 'stripePaymentIntentId' => 'pi_ap3b_success',
            'amountMinor' => 4690, 'currency' => 'eur', 'productId' => 'CP-2026-0001',
            'legalBundleId' => 'legal-v1', 'termsAccepted' => true,
        ];
        $commerce->markPaymentProcessing('ap3b-checkout-success', $processing);
        $success = $processing;
        $success['paymentStatus'] = 'succeeded';
        $success['paymentIntentStatus'] = 'succeeded';
        $first = $commerce->finalizePayment('ap3b-checkout-success', $success);
        $second = $commerce->finalizePayment('ap3b-checkout-success', $success);
        commerce_assert($first === $second && count($commerce->orders) === 1, 'Async-Erfolg ist nicht idempotent.');
        commerce_assert($commerce->reservations['ap3b-checkout-success-reservation']['state'] === 'converted', 'Async-Erfolg konvertiert Reservierung nicht.');
    },
    'Asynchroner Fehler gibt Reservierung atomar frei' => static function (): void {
        $commerce = commerce_fixture();
        $commerce->createCheckout(checkout_input('ap3b-checkout-failed'));
        $processing = [
            'paymentStatus' => 'processing', 'paymentIntentStatus' => 'processing',
            'paymentMethodType' => 'sepa_debit', 'stripePaymentIntentId' => 'pi_ap3b_failed',
            'amountMinor' => 4690, 'currency' => 'eur', 'productId' => 'CP-2026-0001',
            'legalBundleId' => 'legal-v1', 'termsAccepted' => true,
        ];
        $commerce->markPaymentProcessing('ap3b-checkout-failed', $processing);
        $failure = $processing;
        $failure['paymentStatus'] = 'failed';
        $failure['paymentIntentStatus'] = 'requires_payment_method';
        $commerce->failAsyncPayment('ap3b-checkout-failed', $failure);
        commerce_assert(count($commerce->orders) === 0, 'Async-Fehler darf keine Bestellung erzeugen.');
        commerce_assert($commerce->reservations['ap3b-checkout-failed-reservation']['state'] === 'released', 'Async-Fehler gibt Reservierung nicht frei.');
        commerce_assert($commerce->reservations['ap3b-checkout-failed-reservation']['blocksStock'] === false, 'Async-Fehler blockiert Bestand weiter.');
    },
    'Inventory Adjustment prüft Version, Reservierung und erlaubte Gründe' => static function (): void {
        $commerce = commerce_fixture();
        $input = [
            'productId' => 'CP-2026-0001', 'targetOnHand' => 0, 'expectedInventoryVersion' => 0,
            'reason' => 'mark_unsellable', 'correlationId' => 'ap2-correlation-0001',
            'idempotencyKey' => 'ap2-adjust-0001',
        ];
        $result = $commerce->adjustInventory($input, 'admin-1');
        commerce_assert($result['inventoryVersion'] === 1, 'Inventarversion wurde nicht erhöht.');
        $same = $commerce->adjustInventory($input, 'admin-1');
        commerce_assert($same === $result, 'Adjustment ist nicht idempotent.');
        $commerce->adjustInventory([
            'productId' => 'CP-2026-0001', 'targetOnHand' => 1, 'expectedInventoryVersion' => 1,
            'reason' => 'activate_new_unique', 'correlationId' => 'ap2-correlation-restore',
            'idempotencyKey' => 'ap2-adjust-restore',
        ], 'admin-1');
        $commerce->createCheckout(checkout_input('ap2-checkout-0009', 'ap2-idem-0009'));
        commerce_exception(static fn (): array => $commerce->adjustInventory([
            'productId' => 'CP-2026-0001', 'targetOnHand' => 0, 'expectedInventoryVersion' => 1,
            'reason' => 'shop_sale', 'correlationId' => 'ap2-correlation-0002',
            'idempotencyKey' => 'ap2-adjust-0002',
        ], 'admin-1'), 'invalid_inventory_reason');
    },
    'Webhook-Inbox dedupliziert dauerhaft' => static function (): void {
        $commerce = commerce_fixture();
        commerce_assert($commerce->persistWebhook(['id' => 'evt_ap2_0001', 'type' => 'checkout.session.completed']) === true, 'Erstes Event fehlt.');
        commerce_assert($commerce->persistWebhook(['id' => 'evt_ap2_0001', 'type' => 'checkout.session.completed']) === false, 'Doppeltes Event wurde nicht dedupliziert.');
    },
    'Retry-Staffel entspricht dem Fünf-Minuten-Worker' => static function (): void {
        commerce_assert(carmaja_commerce_retry_schedule(0) === 300, 'Retry 1 muss nach 5 Minuten erfolgen.');
        commerce_assert(carmaja_commerce_retry_schedule(1) === 900, 'Retry 2 muss nach 15 Minuten erfolgen.');
        commerce_assert(carmaja_commerce_retry_schedule(2) === 3600, 'Retry 3 muss nach 1 Stunde erfolgen.');
        commerce_assert(carmaja_commerce_retry_schedule(3) === 14400, 'Retry 4 muss nach 4 Stunden erfolgen.');
        commerce_assert(carmaja_commerce_retry_schedule(4) === 43200, 'Retry 5 muss nach 12 Stunden erfolgen.');
        commerce_assert(carmaja_commerce_retry_schedule(5) === null, 'Nach dem fünften Retry muss manual_review/failed folgen.');
    },
    'Schema enthält die getrennten Statusachsen und InnoDB' => static function (): void {
        $schema = file_get_contents(dirname(__DIR__, 2) . '/database/commerce-schema.sql');
        commerce_assert(is_string($schema), 'Schema fehlt.');
        foreach (['ENGINE=InnoDB', 'commerce_products', 'commerce_inventory', 'checkout_sagas', 'reservations', 'payments', 'orders', 'shipments', 'webhook_inbox', 'mail_outbox', 'review_cases', 'target_on_hand', 'inventory_version'] as $needle) {
            commerce_assert(str_contains($schema, $needle), 'Schemaelement fehlt: ' . $needle);
        }
    },
];

$passed = 0;
foreach ($tests as $name => $test) {
    try {
        $test();
        $passed++;
        echo '[OK] ' . $name . PHP_EOL;
    } catch (Throwable $error) {
        fwrite(STDERR, '[FAIL] ' . $name . ': ' . $error->getMessage() . PHP_EOL);
        exit(1);
    }
}

echo $passed . ' Commerce-AP2-Tests erfolgreich.' . PHP_EOL;
