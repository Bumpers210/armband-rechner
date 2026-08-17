<?php

declare(strict_types=1);

require_once __DIR__ . '/stripe-contract.php';

final class CarmajaCheckoutService
{
    public function __construct(
        private readonly CarmajaCommercePdo $commerce,
        private readonly CarmajaStripeGateway $stripe,
        private readonly array $config
    ) {
    }

    public function start(array $request, string $idempotencyKey): array
    {
        $allowed = ['productId'];
        $unknown = array_diff(array_keys($request), $allowed);
        if ($unknown !== [] || !is_string($request['productId'] ?? null)
            || trim($request['productId']) === '') {
            throw new CarmajaCommerceException(
                'checkout_request_invalid',
                'Checkout-Anfrage enthält unzulässige Felder.',
                422
            );
        }
        carmaja_commerce_assert_id($idempotencyKey, 'Idempotency-Key');

        $product = $this->commerce->loadProductForCheckout($request['productId']);
        $legalBundleId = $this->requiredConfig('activeLegalBundleId');
        $this->commerce->loadApprovedLegalBundle($legalBundleId);
        $shipping = $this->shippingSnapshot();
        $requestHash = carmaja_commerce_request_hash([
            'productId' => $product['product_id'],
            'productVersion' => (int) $product['product_version'],
            'sourceHash' => $product['source_hash'],
            'priceMinor' => (int) $product['price_minor'],
            'currency' => $product['currency'],
            'salesModel' => $product['sales_model'],
            'shippingSnapshot' => $shipping,
            'legalBundleId' => $legalBundleId,
        ]);
        $existing = $this->commerce->findCheckoutByIdempotency($idempotencyKey);
        if (is_array($existing)) {
            if (!hash_equals((string) $existing['request_hash'], $requestHash)) {
                throw new CarmajaCommerceException(
                    'idempotency_conflict',
                    'Idempotency-Key gehört zu einer anderen Anfrage.',
                    409
                );
            }
            if (is_string($existing['stripe_checkout_session_id'] ?? null)
                && $existing['stripe_checkout_session_id'] !== '') {
                $session = $this->stripe->retrieveCheckoutSession($existing['stripe_checkout_session_id']);
                return $this->sessionResponse($existing, $session);
            }
            throw new CarmajaCommerceException(
                'checkout_in_progress',
                'Checkout wird bereits verarbeitet.',
                409
            );
        }

        $checkoutId = carmaja_commerce_new_id();
        $expiresAt = time() + CARMAJA_STRIPE_CHECKOUT_LIFETIME_SECONDS;
        $snapshot = [
            'checkoutId' => $checkoutId,
            'productId' => $product['product_id'],
            'productVersion' => (int) $product['product_version'],
            'sourceHash' => $product['source_hash'],
            'productName' => $product['name'],
            'priceMinor' => (int) $product['price_minor'],
            'currency' => $product['currency'],
            'salesModel' => $product['sales_model'],
            'shippingSnapshot' => $shipping,
            'legalBundleId' => $legalBundleId,
        ];
        $this->commerce->createCheckout([
            'checkoutId' => $checkoutId,
            'idempotencyKey' => $idempotencyKey,
            'requestHash' => $requestHash,
            'productId' => $product['product_id'],
            'productVersion' => (int) $product['product_version'],
            'sourceHash' => $product['source_hash'],
            'priceMinor' => (int) $product['price_minor'],
            'currency' => $product['currency'],
            'salesModel' => $product['sales_model'],
            'shippingSnapshot' => $shipping,
            'legalBundleId' => $legalBundleId,
            'expiresAt' => gmdate('Y-m-d H:i:s', $expiresAt),
        ]);

        try {
            $session = $this->stripe->createCheckoutSession(
                $snapshot,
                $this->requiredConfig('stripeSuccessUrl'),
                $this->requiredConfig('stripeCancelUrl'),
                $idempotencyKey,
                $expiresAt
            );
            $this->commerce->recordStripeCreationOutcome($checkoutId, 'created', $session['id']);
            return [
                'checkoutId' => $checkoutId,
                'stripeCheckoutSessionId' => $session['id'],
                'url' => $session['url'],
                'expiresAt' => gmdate(DATE_ATOM, $expiresAt),
            ];
        } catch (CarmajaStripeException $error) {
            if ($error->errorCode === 'stripe_session_create_failed') {
                $this->commerce->recordStripeCreationOutcome($checkoutId, 'failed');
            } else {
                $this->commerce->recordStripeCreationOutcome($checkoutId, 'unknown');
            }
            throw $error;
        } catch (Throwable $error) {
            $this->commerce->recordStripeCreationOutcome($checkoutId, 'unknown');
            throw new CarmajaStripeException(
                'stripe_session_outcome_unknown',
                'Stripe-Checkout-Ausgang muss geprüft werden.',
                503
            );
        }
    }

    private function shippingSnapshot(): array
    {
        return [
            'shippingMethodId' => $this->requiredConfig('shippingMethodId'),
            'publicName' => $this->requiredConfig('shippingPublicName'),
            'amountMinor' => (int) $this->requiredConfig('shippingAmountMinor'),
            'currency' => 'eur',
            'minBusinessDays' => (int) $this->requiredConfig('shippingMinBusinessDays'),
            'maxBusinessDays' => (int) $this->requiredConfig('shippingMaxBusinessDays'),
            'country' => 'DE',
        ];
    }

    private function requiredConfig(string $key): string
    {
        $value = $this->config[$key] ?? null;
        if (!is_scalar($value) || (string) $value === '') {
            throw new CarmajaCommerceException('checkout_configuration_invalid', 'Checkout-Konfiguration ist unvollständig.', 503);
        }
        return (string) $value;
    }

    private function sessionResponse(array $existing, array $session): array
    {
        if (!is_string($session['id'] ?? null) || !is_string($session['url'] ?? null)) {
            throw new CarmajaStripeException('stripe_session_response_invalid', 'Stripe-Session ist ungültig.', 502);
        }
        return [
            'checkoutId' => $existing['checkout_id'],
            'stripeCheckoutSessionId' => $session['id'],
            'url' => $session['url'],
            'expiresAt' => $session['expires_at'] ?? null,
            'reused' => true,
        ];
    }
}
