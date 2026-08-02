<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/test-api-private/program/stripe-webhook.php';
require_once dirname(__DIR__, 2) . '/test-api-private/program/shop-checkout.php';

final class CarmajaStripeAp3TestFailure extends RuntimeException
{
}

function stripe_ap3_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new CarmajaStripeAp3TestFailure($message);
    }
}

final class StripeAp3FakeSessions
{
    public function create(array $params, array $options): object
    {
        return (object) ['id' => 'cs_test_ap3_0001', 'url' => 'https://checkout.stripe.test/cs_test_ap3_0001'];
    }

    public function retrieve(string $id): object
    {
        return (object) ['id' => $id, 'url' => 'https://checkout.stripe.test/' . $id, 'status' => 'open'];
    }
}

final class StripeAp3FakeCheckout
{
    public function __construct(public readonly StripeAp3FakeSessions $sessions)
    {
    }
}

final class StripeAp3FakePaymentIntents
{
    public function update(string $id, array $params): object
    {
        return (object) ['id' => $id, 'metadata' => $params['metadata']];
    }
}

final class StripeAp3FakeClient
{
    public readonly StripeAp3FakeCheckout $checkout;
    public readonly StripeAp3FakePaymentIntents $paymentIntents;

    public function __construct()
    {
        $this->checkout = new StripeAp3FakeCheckout(new StripeAp3FakeSessions());
        $this->paymentIntents = new StripeAp3FakePaymentIntents();
    }
}

$config = [
    'stripeSdkVersion' => CARMAJA_STRIPE_SDK_VERSION,
    'stripeApiVersion' => CARMAJA_STRIPE_API_VERSION,
    'stripeWebhookApiVersion' => CARMAJA_STRIPE_WEBHOOK_API_VERSION,
    'stripeSecretKey' => 'sk_test_artificial_only',
];

$snapshot = [
    'checkoutId' => '11111111-1111-4111-8111-111111111111',
    'productId' => 'CP-2026-0001',
    'productVersion' => 1,
    'sourceHash' => str_repeat('a', 64),
    'productName' => 'Künstliches Testarmband',
    'priceMinor' => 4200,
    'currency' => 'eur',
    'legalBundleId' => 'cmj-test-legal-2026-08-02-v1',
    'shippingSnapshot' => [
        'shippingMethodId' => 'de-standard',
        'publicName' => 'Testversand Deutschland',
        'amountMinor' => 490,
        'currency' => 'eur',
        'minBusinessDays' => 2,
        'maxBusinessDays' => 5,
    ],
];

$tests = [
    'Stripe-Parameter sind V1-festgelegt' => static function () use ($snapshot): void {
        $parameters = carmaja_stripe_checkout_parameters(
            $snapshot,
            'https://test.carmaja-perlen.de/checkout/success',
            'https://test.carmaja-perlen.de/checkout/cancel',
            time() + CARMAJA_STRIPE_CHECKOUT_LIFETIME_SECONDS
        );
        stripe_ap3_assert($parameters['line_items'][0]['quantity'] === 1, 'Menge muss 1 sein.');
        stripe_ap3_assert($parameters['line_items'][0]['price_data']['unit_amount'] === 4200, 'Preis fehlt.');
        stripe_ap3_assert($parameters['shipping_options'][0]['shipping_rate_data']['fixed_amount']['amount'] === 490, 'Versand fehlt.');
        stripe_ap3_assert($parameters['payment_method_types'] === ['card'], 'Zahlungsarten sind nicht begrenzt.');
        stripe_ap3_assert($parameters['consent_collection']['terms_of_service'] === 'required', 'AGB-Zustimmung fehlt.');
        stripe_ap3_assert($parameters['wallet_options']['link']['display'] === 'never', 'Link ist nicht deaktiviert.');
        stripe_ap3_assert($parameters['allow_promotion_codes'] === false, 'Promotion-Codes müssen deaktiviert sein.');
        stripe_ap3_assert($parameters['after_expiration']['recovery']['enabled'] === false, 'Recovery muss deaktiviert sein.');
    },
    'Stripe-Gateway verwendet den Fake-Client und Idempotenz' => static function () use ($snapshot, $config): void {
        $gateway = new CarmajaStripeGateway($config, new StripeAp3FakeClient());
        $session = $gateway->createCheckoutSession(
            $snapshot,
            'https://test.carmaja-perlen.de/success',
            'https://test.carmaja-perlen.de/cancel',
            'ap3-idempotency-0001',
            time() + CARMAJA_STRIPE_CHECKOUT_LIFETIME_SECONDS
        );
        stripe_ap3_assert($session['id'] === 'cs_test_ap3_0001', 'Stripe-Session fehlt.');
    },
    'Webhook-Signatur, Allowlist und Inbox-Reihenfolge' => static function (): void {
        $secret = 'whsec_artificial_ap3';
        $timestamp = time();
        $unknown = json_encode([
            'id' => 'evt_ap3_unknown', 'type' => 'customer.created', 'livemode' => false,
            'data' => ['object' => ['id' => 'cus_ap3_0001']],
        ], JSON_THROW_ON_ERROR);
        $unknownHeader = 't=' . $timestamp . ',v1=' . hash_hmac('sha256', $timestamp . '.' . $unknown, $secret);
        $calls = 0;
        $result = (new CarmajaStripeWebhookEndpoint())->receive(
            $unknown,
            $unknownHeader,
            $secret,
            false,
            static function () use (&$calls): void { $calls++; }
        );
        stripe_ap3_assert($result['status'] === 204 && $result['ignored'] === true, 'Unbekanntes Event muss 204 erhalten.');
        stripe_ap3_assert($calls === 0, 'Unbekanntes Event darf nicht persistiert werden.');

        $allowed = json_encode([
            'id' => 'evt_ap3_allowed', 'type' => 'checkout.session.expired', 'livemode' => false,
            'data' => ['object' => ['id' => 'cs_ap3_0001']],
        ], JSON_THROW_ON_ERROR);
        $allowedHeader = 't=' . $timestamp . ',v1=' . hash_hmac('sha256', $timestamp . '.' . $allowed, $secret);
        $result = (new CarmajaStripeWebhookEndpoint())->receive(
            $allowed,
            $allowedHeader,
            $secret,
            false,
            static function (array $envelope, string $raw) use (&$calls): void {
                $calls++;
                stripe_ap3_assert($envelope['payloadHash'] === hash('sha256', $raw), 'Payload-Hash fehlt.');
            }
        );
        stripe_ap3_assert($result['persisted'] === true && $calls === 1, 'Erlaubtes Event wurde nicht persistiert.');
    },
    'Webhook-Payload-Verschlüsselung ist reversibel' => static function (): void {
        $key = base64_encode(random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES));
        $encrypted = carmaja_stripe_encrypt_webhook_payload('{"artificial":true}', $key);
        stripe_ap3_assert(
            carmaja_stripe_decrypt_webhook_payload($encrypted['ciphertext'], $key) === '{"artificial":true}',
            'Payload konnte nicht wiederhergestellt werden.'
        );
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

echo $passed . ' AP3-Stripe-Tests erfolgreich.' . PHP_EOL;
