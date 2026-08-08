<?php

declare(strict_types=1);

require_once __DIR__ . '/commerce-core.php';

const CARMAJA_STRIPE_SDK_VERSION = '20.3.0';
const CARMAJA_STRIPE_API_VERSION = '2026-06-24.dahlia';
const CARMAJA_STRIPE_WEBHOOK_API_VERSION = '2026-07-29.dahlia';
const CARMAJA_STRIPE_CHECKOUT_LIFETIME_SECONDS = 1800;
const CARMAJA_STRIPE_WEBHOOK_TOLERANCE_SECONDS = 300;
const CARMAJA_STRIPE_WEBHOOK_MAX_BYTES = 262144;

const CARMAJA_STRIPE_WEBHOOK_ALLOWLIST = [
    'checkout.session.completed',
    'checkout.session.async_payment_succeeded',
    'checkout.session.async_payment_failed',
    'checkout.session.expired',
    'charge.refunded',
    'refund.updated',
    'charge.dispute.created',
    'charge.dispute.updated',
    'charge.dispute.closed',
];
const CARMAJA_STRIPE_PAYMENT_METHOD_TYPES = [
    'card',
    'paypal',
    'klarna',
    'sepa_debit',
];

final class CarmajaStripeException extends RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $httpStatus = 502
    ) {
        parent::__construct($message);
    }
}

function carmaja_stripe_assert_configuration(array $config): void
{
    if (($config['stripeSdkVersion'] ?? null) !== CARMAJA_STRIPE_SDK_VERSION
        || ($config['stripeApiVersion'] ?? null) !== CARMAJA_STRIPE_API_VERSION
        || ($config['stripeWebhookApiVersion'] ?? null) !== CARMAJA_STRIPE_WEBHOOK_API_VERSION
        || ($config['stripePaymentMethodTypes'] ?? null) !== CARMAJA_STRIPE_PAYMENT_METHOD_TYPES) {
        throw new CarmajaStripeException(
            'stripe_version_mismatch',
            'Stripe-Version ist nicht für V1 freigegeben.',
            500
        );
    }

    $secret = $config['stripeSecretKey'] ?? null;
    if (!is_string($secret) || trim($secret) === '') {
        throw new CarmajaStripeException(
            'stripe_configuration_missing',
            'Stripe-Testkonfiguration ist nicht verfügbar.',
            503
        );
    }
}

function carmaja_stripe_checkout_parameters(
    array $snapshot,
    string $successUrl,
    string $cancelUrl,
    int $expiresAt
): array {
    $required = [
        'checkoutId', 'productId', 'productVersion', 'sourceHash',
        'productName', 'priceMinor', 'currency', 'legalBundleId',
        'shippingSnapshot',
    ];
    foreach ($required as $field) {
        if (!array_key_exists($field, $snapshot)) {
            throw new CarmajaStripeException(
                'stripe_snapshot_invalid',
                'Checkout-Snapshot ist unvollständig.',
                422
            );
        }
    }

    if ($snapshot['currency'] !== 'eur'
        || !is_int($snapshot['priceMinor'])
        || $snapshot['priceMinor'] < CARMAJA_COMMERCE_MINIMUM_AMOUNT_MINOR
        || !is_int($expiresAt)
        || $expiresAt < time() + CARMAJA_STRIPE_CHECKOUT_LIFETIME_SECONDS - 5
        || $expiresAt > time() + CARMAJA_STRIPE_CHECKOUT_LIFETIME_SECONDS + 5) {
        throw new CarmajaStripeException(
            'stripe_snapshot_invalid',
            'Preis, Währung oder Ablaufzeitpunkt ist ungültig.',
            422
        );
    }

    $shipping = $snapshot['shippingSnapshot'];
    if (!is_array($shipping)
        || ($shipping['shippingMethodId'] ?? null) === null
        || !is_int($shipping['amountMinor'] ?? null)
        || ($shipping['amountMinor'] ?? -1) < 0
        || ($shipping['currency'] ?? null) !== 'eur') {
        throw new CarmajaStripeException(
            'stripe_shipping_invalid',
            'Versand-Snapshot ist ungültig.',
            422
        );
    }

    $metadata = [
        'checkoutId' => (string) $snapshot['checkoutId'],
        'productId' => (string) $snapshot['productId'],
        'productVersion' => (string) $snapshot['productVersion'],
        'sourceHash' => (string) $snapshot['sourceHash'],
        'legalBundleId' => (string) $snapshot['legalBundleId'],
    ];

    return [
        'mode' => 'payment',
        'line_items' => [[
            'price_data' => [
                'currency' => 'eur',
                'unit_amount' => $snapshot['priceMinor'],
                'product_data' => [
                    'name' => (string) $snapshot['productName'],
                    'metadata' => [
                        'productId' => (string) $snapshot['productId'],
                    ],
                ],
            ],
            'quantity' => 1,
        ]],
        'shipping_options' => [[
            'shipping_rate_data' => [
                'type' => 'fixed_amount',
                'fixed_amount' => [
                    'amount' => $shipping['amountMinor'],
                    'currency' => 'eur',
                ],
                'display_name' => (string) ($shipping['publicName'] ?? 'Versand innerhalb Deutschlands'),
                'delivery_estimate' => [
                    'minimum' => [
                        'unit' => 'business_day',
                        'value' => (int) ($shipping['minBusinessDays'] ?? 2),
                    ],
                    'maximum' => [
                        'unit' => 'business_day',
                        'value' => (int) ($shipping['maxBusinessDays'] ?? 5),
                    ],
                ],
            ],
        ]],
        'payment_method_types' => CARMAJA_STRIPE_PAYMENT_METHOD_TYPES,
        'customer_creation' => 'if_required',
        'billing_address_collection' => 'auto',
        'shipping_address_collection' => [
            'allowed_countries' => ['DE'],
        ],
        'automatic_tax' => ['enabled' => false],
        'allow_promotion_codes' => false,
        'after_expiration' => [
            'recovery' => ['enabled' => false],
        ],
        'wallet_options' => [
            'link' => ['display' => 'never'],
        ],
        'consent_collection' => [
            'terms_of_service' => 'required',
        ],
        'custom_text' => [
            'submit' => [
                'message' => 'Es gelten die im Checkout verlinkten Shopbedingungen und Widerrufsinformationen.',
            ],
        ],
        'client_reference_id' => (string) $snapshot['checkoutId'],
        'metadata' => $metadata,
        'payment_intent_data' => [
            'metadata' => $metadata,
        ],
        'success_url' => $successUrl,
        'cancel_url' => $cancelUrl,
        'expires_at' => $expiresAt,
    ];
}

final class CarmajaStripeGateway
{
    private object $client;

    public function __construct(array $config, ?object $client = null)
    {
        carmaja_stripe_assert_configuration($config);

        if ($client !== null) {
            $this->client = $client;
            return;
        }

        $autoload = $config['stripeAutoload'] ?? null;
        if (is_string($autoload) && is_file($autoload)) {
            require_once $autoload;
        }

        if (!class_exists('Stripe\\StripeClient')) {
            throw new CarmajaStripeException(
                'stripe_sdk_unavailable',
                'Die gepinnte Stripe-PHP-SDK ist nicht verfügbar.',
                503
            );
        }

        $this->client = new \Stripe\StripeClient([
            'api_key' => $config['stripeSecretKey'],
            'stripe_version' => CARMAJA_STRIPE_API_VERSION,
        ]);
    }

    public function createCheckoutSession(
        array $snapshot,
        string $successUrl,
        string $cancelUrl,
        string $idempotencyKey,
        int $expiresAt
    ): array {
        $params = carmaja_stripe_checkout_parameters($snapshot, $successUrl, $cancelUrl, $expiresAt);
        try {
            $session = $this->client->checkout->sessions->create(
                $params,
                ['idempotency_key' => $idempotencyKey]
            );
        } catch (Throwable $error) {
            $definitelyNotCreated = in_array(
                get_class($error),
                [
                    'Stripe\\Exception\\InvalidRequestException',
                    'Stripe\\Exception\\AuthenticationException',
                    'Stripe\\Exception\\PermissionException',
                ],
                true
            );
            throw new CarmajaStripeException(
                $definitelyNotCreated ? 'stripe_session_create_failed' : 'stripe_session_outcome_unknown',
                $definitelyNotCreated
                    ? 'Stripe-Checkout wurde von Stripe abgelehnt.'
                    : 'Stripe-Checkout-Ausgang muss abgeglichen werden.',
                502
            );
        }

        $result = $this->resourceArray($session);
        if (!is_string($result['id'] ?? null) || !is_string($result['url'] ?? null)) {
            throw new CarmajaStripeException(
                'stripe_session_response_invalid',
                'Stripe-Checkout lieferte keine gültige Session.',
                502
            );
        }

        return $result;
    }

    public function retrieveCheckoutSession(string $sessionId): array
    {
        try {
            $session = $this->client->checkout->sessions->retrieve($sessionId);
        } catch (Throwable $error) {
            throw new CarmajaStripeException(
                'stripe_reconciliation_failed',
                'Stripe-Checkout konnte nicht abgeglichen werden.',
                502
            );
        }

        return $this->resourceArray($session);
    }

    public function retrievePaymentIntent(string $paymentIntentId): array
    {
        try {
            $intent = $this->client->paymentIntents->retrieve(
                $paymentIntentId,
                ['expand' => ['payment_method']]
            );
        } catch (Throwable $error) {
            throw new CarmajaStripeException(
                'stripe_payment_reconciliation_failed',
                'Stripe-Zahlung konnte nicht abgeglichen werden.',
                502
            );
        }

        return $this->resourceArray($intent);
    }

    public function updatePaymentIntentMetadata(string $paymentIntentId, array $metadata): void
    {
        try {
            $this->client->paymentIntents->update($paymentIntentId, ['metadata' => $metadata]);
        } catch (Throwable $error) {
            throw new CarmajaStripeException(
                'stripe_metadata_update_failed',
                'Stripe-Metadaten konnten nicht ergänzt werden.',
                502
            );
        }
    }

    private function resourceArray(mixed $resource): array
    {
        if (is_object($resource) && method_exists($resource, 'toArray')) {
            $resource = $resource->toArray();
        }
        return is_array($resource) ? $resource : (is_object($resource) ? get_object_vars($resource) : []);
    }
}
