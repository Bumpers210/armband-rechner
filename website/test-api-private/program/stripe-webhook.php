<?php

declare(strict_types=1);

require_once __DIR__ . '/stripe-contract.php';

function carmaja_stripe_signature_parts(string $header): array
{
    $parts = [];
    foreach (explode(',', $header) as $item) {
        [$key, $value] = array_pad(explode('=', trim($item), 2), 2, null);
        if (is_string($key) && is_string($value) && $key !== '') {
            $parts[$key][] = $value;
        }
    }
    return $parts;
}

function carmaja_stripe_verify_signature(
    string $rawBody,
    string $signatureHeader,
    string $secret,
    ?int $now = null
): bool {
    if ($rawBody === '' || $signatureHeader === '' || $secret === '') {
        return false;
    }

    $parts = carmaja_stripe_signature_parts($signatureHeader);
    $timestamp = isset($parts['t'][0]) && ctype_digit($parts['t'][0])
        ? (int) $parts['t'][0]
        : null;
    if ($timestamp === null || abs(($now ?? time()) - $timestamp) > CARMAJA_STRIPE_WEBHOOK_TOLERANCE_SECONDS) {
        return false;
    }

    $signedPayload = $timestamp . '.' . $rawBody;
    $expected = hash_hmac('sha256', $signedPayload, $secret);
    foreach ($parts['v1'] ?? [] as $candidate) {
        if (is_string($candidate) && hash_equals($expected, $candidate)) {
            return true;
        }
    }

    return false;
}

function carmaja_stripe_encrypt_webhook_payload(string $rawBody, string $base64Key): array
{
    $key = base64_decode($base64Key, true);
    if (!is_string($key) || strlen($key) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
        throw new CarmajaStripeException(
            'webhook_payload_key_invalid',
            'Webhook-Aufbewahrungsschlüssel ist ungültig.',
            500
        );
    }

    $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
    return [
        'ciphertext' => $nonce . sodium_crypto_secretbox($rawBody, $nonce, $key),
        'algorithm' => 'sodium_secretbox',
    ];
}

function carmaja_stripe_decrypt_webhook_payload(string $ciphertext, string $base64Key): string
{
    $key = base64_decode($base64Key, true);
    if (!is_string($key) || strlen($key) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES
        || strlen($ciphertext) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
        throw new CarmajaStripeException(
            'webhook_payload_key_invalid',
            'Webhook-Aufbewahrungsschlüssel ist ungültig.',
            500
        );
    }

    $nonce = substr($ciphertext, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
    $sealed = substr($ciphertext, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
    $plain = sodium_crypto_secretbox_open($sealed, $nonce, $key);
    if (!is_string($plain)) {
        throw new CarmajaStripeException(
            'webhook_payload_unreadable',
            'Webhook-Payload kann nicht entschlüsselt werden.',
            500
        );
    }
    return $plain;
}

function carmaja_stripe_webhook_event_is_allowed(string $eventType): bool
{
    return in_array($eventType, CARMAJA_STRIPE_WEBHOOK_ALLOWLIST, true);
}

final class CarmajaStripeWebhookEndpoint
{
    public function receive(
        string $rawBody,
        string $signatureHeader,
        string $secret,
        bool $expectedLivemode,
        callable $persist
    ): array {
        if (strlen($rawBody) > CARMAJA_STRIPE_WEBHOOK_MAX_BYTES) {
            throw new CarmajaStripeException('webhook_payload_too_large', 'Webhook-Payload ist zu groß.', 413);
        }
        if (!carmaja_stripe_verify_signature($rawBody, $signatureHeader, $secret)) {
            throw new CarmajaStripeException('webhook_signature_invalid', 'Webhook-Signatur ist ungültig.', 400);
        }

        try {
            $event = json_decode($rawBody, true, 32, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            throw new CarmajaStripeException('webhook_payload_invalid', 'Webhook-Payload ist ungültig.', 400);
        }

        if (!is_array($event)
            || !is_string($event['id'] ?? null)
            || !is_string($event['type'] ?? null)
            || !is_array($event['data']['object'] ?? null)) {
            throw new CarmajaStripeException('webhook_payload_invalid', 'Webhook-Ereignis ist unvollständig.', 400);
        }

        if ((bool) ($event['livemode'] ?? false) !== $expectedLivemode) {
            throw new CarmajaStripeException('webhook_environment_mismatch', 'Webhook-Umgebung stimmt nicht überein.', 400);
        }

        if (!carmaja_stripe_webhook_event_is_allowed($event['type'])) {
            return [
                'status' => 204,
                'persisted' => false,
                'ignored' => true,
                'eventId' => $event['id'],
                'eventType' => $event['type'],
            ];
        }

        if (isset($event['api_version'])
            && $event['api_version'] !== CARMAJA_STRIPE_WEBHOOK_API_VERSION) {
            throw new CarmajaStripeException(
                'webhook_api_version_mismatch',
                'Stripe-Webhook-Version ist nicht fuer V1 freigegeben.',
                400
            );
        }

        $envelope = [
            'id' => $event['id'],
            'type' => $event['type'],
            'objectId' => $event['data']['object']['id'] ?? null,
            'livemode' => (bool) ($event['livemode'] ?? false),
            'payloadHash' => hash('sha256', $rawBody),
            'receivedAt' => gmdate('Y-m-d H:i:s.u'),
            'event' => $event,
        ];

        try {
            $persist($envelope, $rawBody);
        } catch (Throwable $error) {
            throw new CarmajaStripeException('webhook_inbox_unavailable', 'Webhook-Inbox ist nicht verfügbar.', 503);
        }

        return [
            'status' => 204,
            'persisted' => true,
            'ignored' => false,
            'eventId' => $event['id'],
            'eventType' => $event['type'],
        ];
    }
}
