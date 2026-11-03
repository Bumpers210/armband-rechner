<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/test-api-private/program/ap3-worker.php';
$worker = (new ReflectionClass(CarmajaAp3Worker::class))->newInstanceWithoutConstructor();
$map = new ReflectionMethod($worker, 'paymentEvent');
$address = ['line1' => 'Artificial Street 1', 'city' => 'Berlin', 'postal_code' => '10115', 'country' => 'DE'];
$legacy = [...$address, 'line1' => 'Artificial Legacy 2'];
$session = ['id' => 'cs_test_artificial', 'payment_intent' => ['id' => 'pi_artificial',
    'status' => 'succeeded', 'payment_method' => ['type' => 'card']], 'amount_total' => 7770,
    'currency' => 'eur', 'consent' => ['terms_of_service' => 'accepted'],
    'metadata' => ['checkoutId' => 'artificial', 'snapshotHash' => str_repeat('a', 64)]];
$checks = 0;
$assert = static function (bool $ok) use (&$checks): void {
    if (!$ok) { throw new RuntimeException('worker_mapping_failed'); }
    $checks++;
};
$current = $map->invoke($worker, [...$session, 'collected_information' => ['shipping_details' => ['address' => $address]]]);
$assert($current['shippingAddress'] === $address);
$assert($current['paymentMethodType'] === 'card' && $current['termsAccepted'] === true);
$both = $map->invoke($worker, [...$session, 'collected_information' => ['shipping_details' => ['address' => $address]],
    'shipping_details' => ['address' => $legacy]]);
$assert($both['shippingAddress'] === $address);
$old = $map->invoke($worker, [...$session, 'shipping_details' => ['address' => $legacy]]);
$assert($old['shippingAddress'] === $legacy);
$missing = $map->invoke($worker, [...$session, 'customer_details' => ['address' => $address]]);
$assert($missing['shippingAddress'] === []); // Billing never substitutes for shipping.
$invalid = $map->invoke($worker, [...$session, 'collected_information' => ['shipping_details' => ['address' => []]],
    'shipping_details' => ['address' => $legacy]]);
$assert($invalid['shippingAddress'] === []); // Do not mask invalid modern evidence.
$failedIntent = ['id' => 'pi_artificial', 'status' => 'requires_payment_method', 'payment_method' => null,
    'last_payment_error' => ['payment_method' => ['type' => 'sepa_debit']]];
$failed = $map->invoke($worker, [...$session, 'payment_intent' => $failedIntent]);
$assert($failed['paymentMethodType'] === 'sepa_debit');
foreach (['processing', 'succeeded', 'canceled'] as $status) {
    $mapped = $map->invoke($worker, [...$session, 'payment_intent' => [...$failedIntent, 'status' => $status]]);
    $assert($mapped['paymentMethodType'] === null);
}
foreach (['pm_unexpanded', [], ['type' => 123]] as $method) {
    $mapped = $map->invoke($worker, [...$session, 'payment_intent' => [...$failedIntent, 'payment_method' => $method]]);
    $assert($mapped['paymentMethodType'] === null);
}
$priority = $map->invoke($worker, [...$session, 'payment_intent' => [...$failedIntent, 'payment_method' => ['type' => 'card']]]);
$assert($priority['paymentMethodType'] === 'card');
$missingError = $map->invoke($worker, [...$session, 'payment_intent' => [...$failedIntent, 'last_payment_error' => null]]);
$assert($missingError['paymentMethodType'] === null);
// Earlier completed events are reconciled against the current provider state.
$commerce = new class extends CarmajaCommercePdo {
    public array $calls = [];
    public function __construct() {}
    public function finalizePayment(string $checkoutId, array $event): array { $this->calls[] = $event['paymentStatus']; return []; }
    public function markPaymentProcessing(string $checkoutId, array $event): array { $this->calls[] = $event['paymentStatus']; return []; }
    public function failAsyncPayment(string $checkoutId, array $event): array { $this->calls[] = $event['paymentStatus']; return []; }
};
$sessions = new class {
    public array $current = [];
    public function retrieve(string $id): object { return (object) $this->current; }
};
$gateway = new CarmajaStripeGateway([
    'stripeSdkVersion' => CARMAJA_STRIPE_SDK_VERSION, 'stripeApiVersion' => CARMAJA_STRIPE_API_VERSION,
    'stripeWebhookApiVersion' => CARMAJA_STRIPE_WEBHOOK_API_VERSION,
    'stripePaymentMethodTypes' => CARMAJA_STRIPE_PAYMENT_METHOD_TYPES, 'stripeSecretKey' => 'sk_test_artificial_only',
], (object) ['checkout' => (object) ['sessions' => $sessions]]);
$routingWorker = new CarmajaAp3Worker($commerce, $gateway, []);
$process = new ReflectionMethod($routingWorker, 'processEvent');
foreach ([['complete', 'unpaid', 'requires_payment_method', 'failed'],
    ['complete', 'unpaid', 'canceled', 'failed'], ['complete', 'unpaid', 'processing', 'processing'],
    ['complete', 'paid', 'succeeded', 'succeeded'], ['open', 'unpaid', 'requires_payment_method', 'processing']] as $case) {
    $sessions->current = [...$session, 'status' => $case[0], 'payment_status' => $case[1],
        'payment_intent' => [...$failedIntent, 'status' => $case[2]]];
    $process->invoke($routingWorker, ['type' => 'checkout.session.completed', 'data' => ['object' => ['id' => $session['id']]]]);
    $assert(end($commerce->calls) === $case[3]);
}
echo 'PASS worker shipping/failure mapping: ' . $checks . " assertions\n";
