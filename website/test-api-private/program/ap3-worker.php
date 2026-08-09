<?php

declare(strict_types=1);

require_once __DIR__ . '/commerce-worker.php';
require_once __DIR__ . '/stripe-webhook.php';

final class CarmajaAp3Worker
{
    private const BATCH_SIZE = 10;
    private const LEASE_SECONDS = 600;

    public function __construct(
        private readonly CarmajaCommercePdo $commerce,
        private readonly CarmajaStripeGateway $stripe,
        private readonly array $config
    ) {
    }

    public function run(): array
    {
        $worker = new CarmajaCommerceWorker($this->commerce, 'commerce-v1');
        return $worker->run(function (): int {
            $processed = $this->processWebhookInbox();
            $processed += $this->processMetadataOutbox();
            $processed += $this->reconcileOpenSessions();
            return $processed;
        }, self::BATCH_SIZE, self::LEASE_SECONDS);
    }

    private function processWebhookInbox(): int
    {
        $rows = $this->commerce->claimWebhookBatch(self::BATCH_SIZE, self::LEASE_SECONDS);
        $processed = 0;
        foreach ($rows as $row) {
            $inboxId = (int) $row['inbox_id'];
            try {
                $raw = carmaja_stripe_decrypt_webhook_payload(
                    (string) $row['payload_ciphertext'],
                    $this->requiredConfig('stripeWebhookPayloadKey')
                );
                $event = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
                if (!is_array($event)) {
                    throw new CarmajaStripeException('webhook_payload_invalid', 'Webhook-Payload ist ungültig.', 500);
                }
                $this->processEvent($event);
                $this->commerce->completeWebhook($inboxId, true);
                $processed++;
            } catch (CarmajaCommerceException $error) {
                if ($error->errorCode === 'manual_review') {
                    $this->commerce->completeWebhook($inboxId, true);
                    $processed++;
                    continue;
                }
                $this->commerce->completeWebhook($inboxId, false, mb_substr($error->getMessage(), 0, 500));
            } catch (Throwable $error) {
                $this->commerce->completeWebhook($inboxId, false, mb_substr($error->getMessage(), 0, 500));
            }
        }
        return $processed;
    }

    private function processEvent(array $event): void
    {
        $type = (string) ($event['type'] ?? '');
        $object = $event['data']['object'] ?? null;
        if (!is_array($object)) {
            throw new CarmajaStripeException('webhook_payload_invalid', 'Stripe-Objekt fehlt.', 500);
        }

        if ($type === 'checkout.session.completed') {
            $currentSession = $this->retrieveCurrentCheckoutSession($object);
            $eventData = $this->paymentEvent($currentSession);
            if (($currentSession['payment_status'] ?? null) === 'paid') {
                $eventData['paymentStatus'] = 'succeeded';
                $this->commerce->finalizePayment($eventData['checkoutId'], $eventData);
            } else {
                $eventData['paymentStatus'] = 'processing';
                $this->commerce->markPaymentProcessing($eventData['checkoutId'], $eventData);
            }
            return;
        }

        if ($type === 'checkout.session.async_payment_succeeded') {
            $eventData = $this->paymentEvent($this->retrieveCurrentCheckoutSession($object));
            $eventData['paymentStatus'] = 'succeeded';
            $this->commerce->finalizePayment($eventData['checkoutId'], $eventData);
            return;
        }

        if ($type === 'checkout.session.async_payment_failed') {
            $eventData = $this->paymentEvent($this->retrieveCurrentCheckoutSession($object));
            $eventData['paymentStatus'] = 'failed';
            $this->commerce->failAsyncPayment($eventData['checkoutId'], $eventData);
            return;
        }

        if ($type === 'checkout.session.expired') {
            $metadata = is_array($object['metadata'] ?? null) ? $object['metadata'] : [];
            $this->commerce->releaseExpiredReservation((string) ($metadata['checkoutId'] ?? ''));
            return;
        }

        if (in_array($type, ['charge.refunded', 'refund.updated'], true)) {
            $paymentIntent = $object['payment_intent'] ?? $object['id'] ?? null;
            $payment = is_string($paymentIntent) ? $this->commerce->findPaymentByStripeObject($paymentIntent) : null;
            if (!is_array($payment)) {
                throw new CarmajaStripeException('payment_not_found', 'Stripe-Zahlung ist lokal nicht zugeordnet.', 409);
            }
            $status = $type === 'charge.refunded' ? 'succeeded' : (string) ($object['status'] ?? 'manual_review');
            if (!in_array($status, ['pending', 'succeeded', 'failed', 'manual_review'], true)) {
                $status = 'manual_review';
            }
            $this->commerce->applyRefund(
                $payment['payment_id'],
                (string) ($object['id'] ?? ''),
                $status,
                (int) ($object['amount'] ?? $object['amount_refunded'] ?? 0)
            );
            return;
        }

        if (in_array($type, ['charge.dispute.created', 'charge.dispute.updated', 'charge.dispute.closed'], true)) {
            $payment = $this->commerce->findPaymentByStripeObject((string) ($object['payment_intent'] ?? $object['charge'] ?? ''));
            if (!is_array($payment)) {
                throw new CarmajaStripeException('payment_not_found', 'Streitfall ist lokal nicht zugeordnet.', 409);
            }
            $this->commerce->recordDispute(
                $payment['payment_id'],
                (string) ($object['id'] ?? ''),
                (string) ($object['status'] ?? 'manual_review'),
                isset($event['created']) && is_numeric($event['created'])
                    ? gmdate('Y-m-d H:i:s', (int) $event['created'])
                    : gmdate('Y-m-d H:i:s')
            );
        }
    }

    private function retrieveCurrentCheckoutSession(array $eventObject): array
    {
        $sessionId = $eventObject['id'] ?? null;
        if (!is_string($sessionId) || !str_starts_with($sessionId, 'cs_')) {
            throw new CarmajaStripeException(
                'stripe_checkout_session_missing',
                'Stripe-Checkout-Session fehlt.',
                409
            );
        }

        return $this->stripe->retrieveCheckoutSession($sessionId);
    }

    private function processMetadataOutbox(): int
    {
        $rows = $this->commerce->claimMetadataOutbox(self::BATCH_SIZE, self::LEASE_SECONDS);
        $processed = 0;
        foreach ($rows as $row) {
            try {
                $metadata = json_decode((string) $row['metadata_payload'], true, 16, JSON_THROW_ON_ERROR);
                if (!is_array($metadata)) {
                    throw new CarmajaStripeException('metadata_invalid', 'Stripe-Metadaten sind ungültig.', 500);
                }
                $this->stripe->updatePaymentIntentMetadata((string) $row['stripe_payment_intent_id'], $metadata);
                $this->commerce->completeMetadataOutbox((int) $row['metadata_id'], true);
                $processed++;
            } catch (Throwable $error) {
                $this->commerce->completeMetadataOutbox((int) $row['metadata_id'], false, mb_substr($error->getMessage(), 0, 500));
            }
        }
        return $processed;
    }

    private function reconcileOpenSessions(): int
    {
        $processed = 0;
        foreach ($this->commerce->findOpenStripeSessions(self::BATCH_SIZE) as $row) {
            try {
                $session = $this->stripe->retrieveCheckoutSession((string) $row['stripe_checkout_session_id']);
                $status = $session['status'] ?? null;
                if ($status === 'expired') {
                    $this->commerce->releaseExpiredReservation((string) $row['checkout_id']);
                    $processed++;
                } elseif ($status === 'complete') {
                    $eventData = $this->paymentEvent($session, (string) $row['checkout_id']);
                    if (($session['payment_status'] ?? null) === 'paid') {
                        $eventData['paymentStatus'] = 'succeeded';
                        $this->commerce->finalizePayment($eventData['checkoutId'], $eventData);
                    } elseif (in_array(
                        $eventData['paymentIntentStatus'],
                        ['requires_payment_method', 'canceled'],
                        true
                    )) {
                        $eventData['paymentStatus'] = 'failed';
                        $this->commerce->failAsyncPayment($eventData['checkoutId'], $eventData);
                    } else {
                        $eventData['paymentStatus'] = 'processing';
                        $this->commerce->markPaymentProcessing($eventData['checkoutId'], $eventData);
                    }
                    $processed++;
                }
            } catch (Throwable) {
                // Reconciliation is retried on the next five-minute worker run.
            }
        }
        return $processed;
    }

    private function paymentEvent(array $session, ?string $fallbackCheckoutId = null): array
    {
        $metadata = is_array($session['metadata'] ?? null) ? $session['metadata'] : [];
        $paymentIntent = $session['payment_intent'] ?? null;
        if (is_array($paymentIntent)) {
            $intent = $paymentIntent;
        } elseif (is_string($paymentIntent) && $paymentIntent !== '') {
            $intent = $this->stripe->retrievePaymentIntent($paymentIntent);
        } else {
            throw new CarmajaStripeException(
                'stripe_payment_intent_missing',
                'Stripe-Zahlungseinheit fehlt.',
                409
            );
        }
        $paymentIntentId = is_string($intent['id'] ?? null)
            ? $intent['id']
            : (is_string($paymentIntent) ? $paymentIntent : '');
        $paymentMethod = $intent['payment_method'] ?? null;
        $paymentMethodType = is_array($paymentMethod) && is_string($paymentMethod['type'] ?? null)
            ? $paymentMethod['type']
            : null;

        return [
            'checkoutId' => (string) ($metadata['checkoutId'] ?? $fallbackCheckoutId ?? ''),
            'paymentIntentStatus' => $intent['status'] ?? null,
            'paymentMethodType' => $paymentMethodType,
            'amountMinor' => (int) ($session['amount_total'] ?? 0),
            'currency' => $session['currency'] ?? null,
            'productId' => $metadata['productId'] ?? null,
            'legalBundleId' => $metadata['legalBundleId'] ?? null,
            'termsAccepted' => ($session['consent']['terms_of_service'] ?? null) === 'accepted',
            'customerEmail' => $session['customer_details']['email'] ?? null,
            'customerName' => $session['customer_details']['name'] ?? null,
            'shippingAddress' => $session['shipping_details']['address'] ?? [],
            'billingAddress' => $session['customer_details']['address'] ?? null,
            'stripePaymentIntentId' => $paymentIntentId,
        ];
    }

    private function requiredConfig(string $key): string
    {
        $value = $this->config[$key] ?? null;
        if (!is_string($value) || $value === '') {
            throw new CarmajaStripeException('worker_configuration_invalid', 'Worker-Konfiguration ist unvollständig.', 500);
        }
        return $value;
    }
}
