<?php

declare(strict_types=1);

/**
 * AP2 commerce core.
 *
 * The PDO repository is deliberately free of network calls. Stripe and Brevo
 * work is represented by inbox/outbox rows and is performed by a later worker
 * outside the transaction that owns the relevant row locks. The memory engine
 * is a deterministic test oracle for the same invariants.
 */

const CARMAJA_COMMERCE_MINIMUM_AMOUNT_MINOR = 50;
const CARMAJA_COMMERCE_CURRENCY = 'eur';
const CARMAJA_COMMERCE_INVENTORY_REASONS = [
    'activate_new_unique',
    'shop_sale',
    'mark_unsellable',
    'release_return',
];
const CARMAJA_COMMERCE_PAYMENT_METHOD_TYPES = [
    'card',
    'paypal',
    'klarna',
    'sepa_debit',
];

function carmaja_commerce_payment_method_type(mixed $value): string
{
    if (!is_string($value) || !in_array($value, CARMAJA_COMMERCE_PAYMENT_METHOD_TYPES, true)) {
        throw new CarmajaCommerceException(
            'payment_method_not_allowed',
            'Zahlungsart ist nicht für V1 freigegeben.',
            422
        );
    }

    return $value;
}

function carmaja_commerce_retry_schedule(int $attemptCount): ?int
{
    $delays = [300, 900, 3600, 14400, 43200];

    if ($attemptCount < 0) {
        throw new CarmajaCommerceException('retry_count_invalid', 'Versuchszähler ist ungültig.', 422);
    }

    return $delays[$attemptCount] ?? null;
}

final class CarmajaCommerceException extends RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $httpStatus = 409
    ) {
        parent::__construct($message);
    }
}

function carmaja_commerce_json(array $value): string
{
    $normalize = static function (mixed $item) use (&$normalize): mixed {
        if (!is_array($item)) {
            return $item;
        }

        if (array_is_list($item)) {
            return array_map($normalize, $item);
        }

        ksort($item, SORT_STRING);

        foreach ($item as $key => $child) {
            $item[$key] = $normalize($child);
        }

        return $item;
    };

    return json_encode(
        $normalize($value),
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
    );
}

function carmaja_commerce_request_hash(array $value): string
{
    return hash('sha256', carmaja_commerce_json($value));
}

function carmaja_commerce_assert_id(string $value, string $field): void
{
    if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{7,99}$/', $value) !== 1) {
        throw new CarmajaCommerceException(
            'validation_failed',
            $field . ' ist ungültig.',
            422
        );
    }
}

function carmaja_commerce_new_id(): string
{
    $hex = bin2hex(random_bytes(16));

    return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-4'
        . substr($hex, 13, 3) . '-'
        . dechex((hexdec(substr($hex, 16, 2)) & 0x3f) | 0x80)
        . substr($hex, 18, 2) . '-' . substr($hex, 20);
}

function carmaja_commerce_validate_checkout(array $input): array
{
    $required = [
        'checkoutId', 'idempotencyKey', 'requestHash', 'productId',
        'productVersion', 'sourceHash', 'priceMinor', 'currency',
        'shippingSnapshot', 'legalBundleId', 'expiresAt',
    ];

    foreach ($required as $field) {
        if (!array_key_exists($field, $input)) {
            throw new CarmajaCommerceException(
                'validation_failed',
                $field . ' ist erforderlich.',
                422
            );
        }
    }

    foreach (['checkoutId', 'idempotencyKey', 'requestHash', 'productId', 'sourceHash', 'legalBundleId'] as $field) {
        if (!is_string($input[$field]) || trim($input[$field]) === '') {
            throw new CarmajaCommerceException('validation_failed', $field . ' ist ungültig.', 422);
        }
    }

    if (!is_int($input['productVersion']) || $input['productVersion'] < 1
        || !is_int($input['priceMinor']) || $input['priceMinor'] < CARMAJA_COMMERCE_MINIMUM_AMOUNT_MINOR
        || $input['currency'] !== CARMAJA_COMMERCE_CURRENCY
        || !is_array($input['shippingSnapshot'])) {
        throw new CarmajaCommerceException('validation_failed', 'Checkout-Snapshot ist ungültig.', 422);
    }

    foreach (['checkoutId', 'idempotencyKey'] as $field) {
        carmaja_commerce_assert_id($input[$field], $field);
    }

    return $input;
}

function carmaja_commerce_active_reservation(array $reservation): bool
{
    return (bool) ($reservation['blocksStock'] ?? false)
        && in_array($reservation['state'] ?? null, ['creating', 'active', 'expired', 'manual_review'], true);
}

/**
 * Deterministic in-memory implementation used by AP2 unit/parallelity tests.
 * It mirrors the SQL repository's transaction boundaries and status axes.
 */
final class CarmajaCommerceMemory
{
    public array $products = [];
    public array $inventory = [];
    public array $legalBundles = [];
    public array $checkouts = [];
    public array $reservations = [];
    public array $payments = [];
    public array $orders = [];
    public array $shipments = [];
    public array $adjustments = [];
    public array $webhooks = [];
    public array $mailOutbox = [];
    public array $metadataOutbox = [];
    public array $reviewCases = [];
    public array $refunds = [];
    public array $disputes = [];
    public int $orderSequence = 1;

    public function seedProduct(array $product, int $onHand = 1): void
    {
        if ($onHand < 0 || $onHand > 1) {
            throw new CarmajaCommerceException('inventory_value_invalid', 'Bestand muss 0 oder 1 sein.', 422);
        }

        $id = (string) ($product['productId'] ?? '');
        $product['productId'] = $id;
        $this->products[$id] = $product;
        $this->inventory[$id] = [
            'productId' => $id,
            'onHand' => $onHand,
            'inventoryVersion' => 0,
        ];
    }

    public function addLegalBundle(string $id): void
    {
        $this->legalBundles[$id] = ['legalBundleId' => $id, 'status' => 'approved'];
    }

    public function createCheckout(array $raw): array
    {
        $input = carmaja_commerce_validate_checkout($raw);
        $key = $input['idempotencyKey'];

        foreach ($this->checkouts as $existing) {
            if ($existing['idempotencyKey'] === $key) {
                if ($existing['requestHash'] !== $input['requestHash']) {
                    throw new CarmajaCommerceException('idempotency_conflict', 'Idempotenzschlüssel gehört zu einer anderen Anfrage.', 409);
                }

                return $existing;
            }
        }

        $product = $this->products[$input['productId']] ?? null;
        $inventory = $this->inventory[$input['productId']] ?? null;
        if (!is_array($product) || !is_array($inventory)
            || ($product['productVersion'] ?? null) !== $input['productVersion']
            || ($product['sourceHash'] ?? null) !== $input['sourceHash']
            || ($product['priceMinor'] ?? null) !== $input['priceMinor']
            || ($product['currency'] ?? null) !== $input['currency']
            || !($product['salesEnabled'] ?? false)) {
            throw new CarmajaCommerceException('product_snapshot_stale', 'Produkt-Snapshot ist nicht mehr aktuell.', 409);
        }

        $blocked = 0;
        foreach ($this->reservations as $reservation) {
            if (($reservation['productId'] ?? null) === $input['productId']
                && carmaja_commerce_active_reservation($reservation)) {
                $blocked++;
            }
        }

        if ($inventory['onHand'] - $blocked < 1) {
            throw new CarmajaCommerceException('sold_out_or_reserved', 'Produkt ist nicht verfügbar.', 409);
        }

        $checkout = [
            'checkoutId' => $input['checkoutId'],
            'idempotencyKey' => $key,
            'requestHash' => $input['requestHash'],
            'productId' => $input['productId'],
            'productVersion' => $input['productVersion'],
            'sourceHash' => $input['sourceHash'],
            'priceMinor' => $input['priceMinor'],
            'currency' => $input['currency'],
            'shippingSnapshot' => $input['shippingSnapshot'],
            'legalBundleId' => $input['legalBundleId'],
            'state' => 'created',
            'expiresAt' => $input['expiresAt'],
        ];
        $this->checkouts[$checkout['checkoutId']] = $checkout;
        $reservationId = $input['checkoutId'] . '-reservation';
        $this->reservations[$reservationId] = [
            'reservationId' => $reservationId,
            'checkoutId' => $checkout['checkoutId'],
            'productId' => $input['productId'],
            'state' => 'active',
            'blocksStock' => true,
            'expiresAt' => $input['expiresAt'],
        ];
        $paymentId = $input['checkoutId'] . '-payment';
        $this->payments[$paymentId] = [
            'paymentId' => $paymentId,
            'checkoutId' => $checkout['checkoutId'],
            'orderId' => null,
            'amountMinor' => $input['priceMinor'] + (int) ($input['shippingSnapshot']['amountMinor'] ?? 0),
            'currency' => $input['currency'],
            'status' => 'created',
            'paymentMethodType' => null,
            'verificationStatus' => 'unverified',
            'refundStatus' => 'none',
            'disputeStatus' => 'none',
        ];

        return $checkout;
    }

    public function recordStripeCreationOutcome(string $checkoutId, string $outcome): void
    {
        $checkout = &$this->checkouts[$checkoutId];
        $reservationId = $checkoutId . '-reservation';
        $reservation = &$this->reservations[$reservationId];

        if ($outcome === 'created') {
            if (!in_array($checkout['state'], ['created', 'stripe_open'], true)) {
                return;
            }
            $checkout['state'] = 'stripe_open';
            return;
        }

        if ($outcome === 'failed') {
            $checkout['state'] = 'failed';
            $reservation['state'] = 'released';
            $reservation['blocksStock'] = false;
            return;
        }

        if ($outcome === 'unknown') {
            $checkout['state'] = 'manual_review';
            $reservation['state'] = 'manual_review';
            $reservation['blocksStock'] = true;
            $this->openReview('checkout', $checkoutId, 'stripe_creation_unknown');
            return;
        }

        throw new CarmajaCommerceException('invalid_stripe_outcome', 'Unbekannter Stripe-Erstellstatus.', 422);
    }

    public function finalizePayment(string $checkoutId, array $event): array
    {
        $checkout = &$this->checkouts[$checkoutId];
        $paymentId = $checkoutId . '-payment';
        $payment = &$this->payments[$paymentId];
        $reservationId = $checkoutId . '-reservation';
        $reservation = &$this->reservations[$reservationId];

        if ($payment['orderId'] !== null) {
            return $this->orders[$payment['orderId']];
        }

        $expected = $payment['amountMinor'];
        try {
            $paymentMethodType = carmaja_commerce_payment_method_type($event['paymentMethodType'] ?? null);
        } catch (CarmajaCommerceException) {
            $paymentMethodType = '';
        }
        if (($event['paymentStatus'] ?? null) !== 'succeeded'
            || ($event['paymentIntentStatus'] ?? null) !== 'succeeded'
            || ($event['amountMinor'] ?? null) !== $expected
            || ($event['currency'] ?? null) !== CARMAJA_COMMERCE_CURRENCY
            || ($event['productId'] ?? null) !== $checkout['productId']
            || ($event['legalBundleId'] ?? null) !== $checkout['legalBundleId']
            || ($event['termsAccepted'] ?? false) !== true
            || $paymentMethodType === ''
            || ($payment['paymentMethodType'] !== null
                && $payment['paymentMethodType'] !== $paymentMethodType)) {
            $payment['status'] = 'manual_review';
            $payment['verificationStatus'] = 'manual_review';
            $checkout['state'] = 'manual_review';
            $reservation['state'] = 'manual_review';
            $reservation['blocksStock'] = true;
            $this->openReview('payment', $paymentId, 'payment_verification_failed');
            throw new CarmajaCommerceException('manual_review', 'Zahlung konnte nicht sicher zugeordnet werden.', 409);
        }

        $inventory = &$this->inventory[$checkout['productId']];
        if ($inventory['onHand'] !== 1 || $reservation['state'] !== 'active') {
            $payment['status'] = 'manual_review';
            $payment['verificationStatus'] = 'manual_review';
            $checkout['state'] = 'manual_review';
            $reservation['state'] = 'manual_review';
            $reservation['blocksStock'] = true;
            $this->openReview('payment', $paymentId, 'inventory_or_reservation_conflict');
            throw new CarmajaCommerceException('manual_review', 'Bestand oder Reservierung ist widersprüchlich.', 409);
        }

        $inventory['onHand'] = 0;
        $inventory['inventoryVersion']++;
        $orderId = $checkoutId . '-order';
        $orderNumber = 'CMJ-' . str_pad((string) $this->orderSequence++, 8, '0', STR_PAD_LEFT);
        $this->orders[$orderId] = [
            'orderId' => $orderId,
            'orderNumber' => $orderNumber,
            'checkoutId' => $checkoutId,
            'paymentId' => $paymentId,
            'status' => 'confirmed',
            'legalBundleId' => $checkout['legalBundleId'],
            'productId' => $checkout['productId'],
        ];
        $this->shipments[$orderId] = ['orderId' => $orderId, 'status' => 'ready'];
        $reservation['state'] = 'converted';
        $reservation['blocksStock'] = false;
        $reservation['convertedAt'] = true;
        $payment['orderId'] = $orderId;
        $payment['status'] = 'succeeded';
        $payment['paymentMethodType'] = $paymentMethodType;
        $payment['verificationStatus'] = 'verified';
        $checkout['state'] = 'completed';
        $this->mailOutbox['order-confirmation:' . $orderId] = ['status' => 'queued', 'orderId' => $orderId];
        $this->metadataOutbox[$paymentId] = ['status' => 'queued', 'paymentId' => $paymentId];

        return $this->orders[$orderId];
    }

    public function markPaymentProcessing(string $checkoutId, array $event): array
    {
        $checkout = &$this->checkouts[$checkoutId];
        $paymentId = $checkoutId . '-payment';
        $payment = &$this->payments[$paymentId];
        $reservation = &$this->reservations[$checkoutId . '-reservation'];
        $paymentMethodType = carmaja_commerce_payment_method_type($event['paymentMethodType'] ?? null);
        $valid = ($event['paymentStatus'] ?? null) === 'processing'
            && ($event['paymentIntentStatus'] ?? null) === 'processing'
            && ($event['amountMinor'] ?? null) === $payment['amountMinor']
            && ($event['currency'] ?? null) === CARMAJA_COMMERCE_CURRENCY
            && ($event['productId'] ?? null) === $checkout['productId']
            && ($event['legalBundleId'] ?? null) === $checkout['legalBundleId']
            && ($event['termsAccepted'] ?? false) === true
            && is_string($event['stripePaymentIntentId'] ?? null)
            && trim((string) $event['stripePaymentIntentId']) !== ''
            && ($payment['paymentMethodType'] === null
                || $payment['paymentMethodType'] === $paymentMethodType)
            && $reservation['state'] === 'active'
            && $reservation['blocksStock'] === true
            && $payment['orderId'] === null;
        if (!$valid) {
            $payment['status'] = 'manual_review';
            $payment['verificationStatus'] = 'manual_review';
            $checkout['state'] = 'manual_review';
            $reservation['state'] = 'manual_review';
            $reservation['blocksStock'] = true;
            $this->openReview('payment', $paymentId, 'processing_payment_verification_failed');
            throw new CarmajaCommerceException('manual_review', 'Laufende Zahlung konnte nicht sicher geprüft werden.', 409);
        }
        $payment['status'] = 'processing';
        $payment['verificationStatus'] = 'verified';
        $payment['paymentMethodType'] = $paymentMethodType;
        $payment['stripePaymentIntentId'] = trim((string) $event['stripePaymentIntentId']);
        $checkout['state'] = 'payment_pending';

        return ['paymentId' => $paymentId, 'status' => 'processing'];
    }

    public function failAsyncPayment(string $checkoutId, array $event): array
    {
        $checkout = &$this->checkouts[$checkoutId];
        $paymentId = $checkoutId . '-payment';
        $payment = &$this->payments[$paymentId];
        $reservation = &$this->reservations[$checkoutId . '-reservation'];
        if ($payment['orderId'] !== null || $payment['status'] === 'succeeded') {
            $this->openReview('payment', $paymentId, 'async_failure_after_success');
            throw new CarmajaCommerceException('manual_review', 'Stripe meldet einen widersprüchlichen Zahlungszustand.', 409);
        }
        if ($payment['status'] === 'failed' && $reservation['state'] === 'released') {
            return ['paymentId' => $paymentId, 'status' => 'failed'];
        }
        $method = $event['paymentMethodType'] ?? $payment['paymentMethodType'];
        $paymentMethodType = carmaja_commerce_payment_method_type($method);
        $valid = ($event['paymentStatus'] ?? null) === 'failed'
            && in_array(
                $event['paymentIntentStatus'] ?? null,
                ['requires_payment_method', 'canceled'],
                true
            )
            && ($event['amountMinor'] ?? null) === $payment['amountMinor']
            && ($event['currency'] ?? null) === CARMAJA_COMMERCE_CURRENCY
            && ($event['productId'] ?? null) === $checkout['productId']
            && ($event['legalBundleId'] ?? null) === $checkout['legalBundleId'];
        if (!$valid) {
            $payment['status'] = 'manual_review';
            $checkout['state'] = 'manual_review';
            $reservation['state'] = 'manual_review';
            $reservation['blocksStock'] = true;
            $this->openReview('payment', $paymentId, 'async_failure_verification_failed');
            throw new CarmajaCommerceException('manual_review', 'Fehlgeschlagene Zahlung konnte nicht sicher geprüft werden.', 409);
        }
        $payment['status'] = 'failed';
        $payment['verificationStatus'] = 'verified';
        $payment['paymentMethodType'] = $paymentMethodType;
        $checkout['state'] = 'failed';
        $reservation['state'] = 'released';
        $reservation['blocksStock'] = false;

        return ['paymentId' => $paymentId, 'status' => 'failed'];
    }

    public function releaseExpiredReservation(string $checkoutId, bool $stripeEndStateConfirmed): void
    {
        $reservation = &$this->reservations[$checkoutId . '-reservation'];
        $payment = &$this->payments[$checkoutId . '-payment'];
        if (!$stripeEndStateConfirmed || $payment['status'] === 'processing') {
            $reservation['state'] = 'manual_review';
            $reservation['blocksStock'] = true;
            $this->openReview(
                'reservation',
                $reservation['reservationId'],
                $payment['status'] === 'processing' ? 'expiration_during_payment_processing' : 'stripe_end_state_missing'
            );
            return;
        }

        if (in_array($reservation['state'], ['released', 'converted'], true)) {
            return;
        }

        $reservation['state'] = 'released';
        $reservation['blocksStock'] = false;
        $this->checkouts[$checkoutId]['state'] = 'expired';
    }

    public function applyRefund(string $paymentId, string $stripeRefundId, string $status): void
    {
        if (isset($this->refunds[$stripeRefundId])) {
            return;
        }

        if (!in_array($status, ['pending', 'succeeded', 'failed', 'manual_review'], true)) {
            throw new CarmajaCommerceException('invalid_refund_status', 'Erstattungsstatus ungültig.', 422);
        }

        $this->refunds[$stripeRefundId] = [
            'paymentId' => $paymentId,
            'status' => $status,
        ];
        $this->payments[$paymentId]['refundStatus'] = $status;
        // Deliberately no inventory change: return and restock are separate.
    }

    public function adjustInventory(array $input, string $actorId, bool $manualOperator = true): array
    {
        foreach ($this->adjustments as $existing) {
            if ($existing['idempotencyKey'] === ($input['idempotencyKey'] ?? null)) {
                return $existing;
            }
        }

        $reason = $input['reason'] ?? null;
        if (!in_array($reason, CARMAJA_COMMERCE_INVENTORY_REASONS, true)
            || ($manualOperator && $reason === 'shop_sale')) {
            throw new CarmajaCommerceException('invalid_inventory_reason', 'Bestandsgrund ist nicht zulässig.', 422);
        }

        $productId = (string) ($input['productId'] ?? '');
        $inventory = &$this->inventory[$productId];
        $target = $input['targetOnHand'] ?? null;
        if (!is_int($target) || !in_array($target, [0, 1], true)) {
            throw new CarmajaCommerceException('validation_failed', 'targetOnHand muss 0 oder 1 sein.', 422);
        }
        if (($input['expectedInventoryVersion'] ?? null) !== $inventory['inventoryVersion']) {
            throw new CarmajaCommerceException('inventory_version_conflict', 'Bestandsversion ist veraltet.', 409);
        }
        if ($target < $inventory['onHand']) {
            foreach ($this->reservations as $reservation) {
                if ($reservation['productId'] === $productId && carmaja_commerce_active_reservation($reservation)) {
                    throw new CarmajaCommerceException('inventory_reserved', 'Bestand ist durch eine Reservierung blockiert.', 409);
                }
            }
        }

        $before = $inventory['onHand'];
        $inventory['onHand'] = $target;
        $inventory['inventoryVersion']++;
        $adjustment = [
            'productId' => $productId,
            'targetOnHand' => $target,
            'previousOnHand' => $before,
            'inventoryVersion' => $inventory['inventoryVersion'],
            'reason' => $reason,
            'correlationId' => $input['correlationId'],
            'idempotencyKey' => $input['idempotencyKey'],
            'actorId' => $actorId,
        ];
        $this->adjustments[] = $adjustment;

        return $adjustment;
    }

    public function persistWebhook(array $event): bool
    {
        $id = (string) ($event['id'] ?? '');
        if ($id === '') {
            throw new CarmajaCommerceException('validation_failed', 'Stripe-Event-ID fehlt.', 422);
        }
        if (isset($this->webhooks[$id])) {
            return false;
        }
        $this->webhooks[$id] = [
            'id' => $id,
            'type' => $event['type'] ?? '',
            'status' => 'queued',
            'attemptCount' => 0,
        ];
        return true;
    }

    public function openReview(string $subjectType, string $subjectId, string $reason): string
    {
        $id = $subjectType . ':' . $subjectId . ':' . $reason;
        $this->reviewCases[$id] ??= [
            'reviewCaseId' => $id,
            'subjectType' => $subjectType,
            'subjectId' => $subjectId,
            'reason' => $reason,
            'status' => 'open',
        ];
        return $id;
    }
}

/** MySQL 8/InnoDB repository used by AP2 server-side code and later workers. */
final class CarmajaCommercePdo
{
    public function __construct(private readonly PDO $pdo)
    {
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
    }

    /** Read the authoritative product and binary inventory without a static fallback. */
    public function loadLiveProduct(string $productId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT p.product_id, p.name, p.price_minor, p.currency,
                    p.product_version, p.sales_enabled, i.on_hand,
                    COALESCE((SELECT COUNT(*) FROM reservations r
                        WHERE r.product_id = p.product_id
                          AND r.blocks_stock = 1
                          AND r.state IN (\'creating\',\'active\',\'expired\',\'manual_review\')), 0) AS blocked
             FROM commerce_products p
             JOIN commerce_inventory i ON i.product_id = p.product_id
             WHERE p.product_id = ?'
        );
        $statement->execute([$productId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            throw new CarmajaCommerceException('product_not_found', 'Produkt ist nicht verfÃ¼gbar.', 404);
        }

        $available = max(0, (int) $row['on_hand'] - (int) $row['blocked']);

        return [
            'productId' => (string) $row['product_id'],
            'priceMinor' => (int) $row['price_minor'],
            'currency' => (string) $row['currency'],
            'buyable' => (int) $row['sales_enabled'] === 1 && $available > 0,
            'availableQuantity' => $available,
            'productVersion' => (int) $row['product_version'],
            'responseAt' => gmdate(DATE_ATOM),
        ];
    }

    public function upsertShopSession(
        string $sessionHash,
        string $csrfHash,
        string $csrfExpiresAt,
        string $liveContextHash,
        string $liveContextExpiresAt,
        string $sessionExpiresAt
    ): void {
        $statement = $this->pdo->prepare(
            'INSERT INTO shop_sessions
                (session_hash, csrf_hash, csrf_expires_at, live_context_hash,
                 live_context_expires_at, session_expires_at)
             VALUES (?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE csrf_hash = VALUES(csrf_hash),
                csrf_expires_at = VALUES(csrf_expires_at),
                live_context_hash = VALUES(live_context_hash),
                live_context_expires_at = VALUES(live_context_expires_at),
                session_expires_at = VALUES(session_expires_at)'
        );
        $statement->execute([
            $sessionHash, $csrfHash, $csrfExpiresAt, $liveContextHash,
            $liveContextExpiresAt, $sessionExpiresAt,
        ]);
    }

    public function loadShopSession(string $sessionHash): ?array
    {
        $statement = $this->pdo->prepare('SELECT * FROM shop_sessions WHERE session_hash = ?');
        $statement->execute([$sessionHash]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    public function issueCheckoutToken(
        string $checkoutId,
        string $tokenHash,
        string $sessionHash,
        string $productId,
        int $productVersion,
        string $requestHash,
        string $rateBucketHash,
        string $ipBucketHash,
        string $expiresAt
    ): void {
        $statement = $this->pdo->prepare(
            'INSERT INTO checkout_tokens
                (checkout_id, token_hash, session_hash, product_id,
                product_version, request_hash, rate_bucket_hash, ip_bucket_hash, expires_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE token_hash = token_hash'
        );
        $statement->execute([
            $checkoutId, $tokenHash, $sessionHash, $productId,
            $productVersion, $requestHash, $rateBucketHash, $ipBucketHash, $expiresAt,
        ]);
    }

    public function loadCheckoutToken(string $tokenHash): ?array
    {
        $statement = $this->pdo->prepare('SELECT * FROM checkout_tokens WHERE token_hash = ?');
        $statement->execute([$tokenHash]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    public function loadCheckoutStatus(string $checkoutId): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT c.checkout_id, c.state, c.expires_at,
                    p.status AS payment_status, p.payment_method_type,
                    p.refund_status,
                    p.order_id, o.order_number
             FROM checkout_sagas c
             JOIN payments p ON p.checkout_id = c.checkout_id
             LEFT JOIN orders o ON o.order_id = p.order_id
             WHERE c.checkout_id = ?'
        );
        $statement->execute([$checkoutId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    public function countShopAttempts(string $bucketHash): int
    {
        $statement = $this->pdo->prepare('SELECT counted_attempts, successful_attempts, window_started_at FROM shop_rate_limits WHERE bucket_hash = ?');
        $statement->execute([$bucketHash]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row) || strtotime((string) $row['window_started_at']) + 86400 <= time()) {
            $this->pdo->prepare(
                'INSERT INTO shop_rate_limits (bucket_hash, window_started_at, counted_attempts, successful_attempts)
                 VALUES (?, UTC_TIMESTAMP(6), 0, 0)
                 ON DUPLICATE KEY UPDATE window_started_at = UTC_TIMESTAMP(6), counted_attempts = 0, successful_attempts = 0'
            )->execute([$bucketHash]);
            return 0;
        }
        return max(0, (int) $row['counted_attempts'] - (int) $row['successful_attempts']);
    }

    public function recordShopAttempt(string $bucketHash): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO shop_rate_limits (bucket_hash, window_started_at, counted_attempts, successful_attempts)
             VALUES (?, UTC_TIMESTAMP(6), 1, 0)
             ON DUPLICATE KEY UPDATE counted_attempts = counted_attempts + 1'
        );
        $statement->execute([$bucketHash]);
    }

    public function reserveShopAttempt(string $bucketHash): bool
    {
        return $this->transaction(function (PDO $pdo) use ($bucketHash): bool {
            $statement = $pdo->prepare(
                'SELECT counted_attempts, successful_attempts, window_started_at
                 FROM shop_rate_limits WHERE bucket_hash = ? FOR UPDATE'
            );
            $statement->execute([$bucketHash]);
            $row = $statement->fetch(PDO::FETCH_ASSOC);
            if (!is_array($row) || strtotime((string) $row['window_started_at']) + 86400 <= time()) {
                $pdo->prepare(
                    'INSERT INTO shop_rate_limits
                        (bucket_hash, window_started_at, counted_attempts, successful_attempts)
                     VALUES (?, UTC_TIMESTAMP(6), 1, 0)
                     ON DUPLICATE KEY UPDATE window_started_at = UTC_TIMESTAMP(6),
                        counted_attempts = 1, successful_attempts = 0'
                )->execute([$bucketHash]);
                return true;
            }
            $effective = (int) $row['counted_attempts'] - (int) $row['successful_attempts'];
            if ($effective >= CARMAJA_SHOP_MAX_CHECKOUT_ATTEMPTS) {
                return false;
            }
            $pdo->prepare(
                'UPDATE shop_rate_limits SET counted_attempts = counted_attempts + 1 WHERE bucket_hash = ?'
            )->execute([$bucketHash]);
            return true;
        });
    }

    public function createWithdrawalRequest(
        string $withdrawalId,
        ?string $orderId,
        string $tokenHash,
        string $matchStatus,
        array $content
    ): void {
        $statement = $this->pdo->prepare(
            'INSERT INTO withdrawal_requests
                (withdrawal_id, order_id, token_hash, match_status, state, submitted_content)
             VALUES (?, ?, ?, ?, \'awaiting_confirmation\', ?)'
        );
        $statement->execute([
            $withdrawalId, $orderId, $tokenHash, $matchStatus,
            json_encode($content, JSON_THROW_ON_ERROR),
        ]);
    }

    public function confirmWithdrawal(string $withdrawalId, string $tokenHash): array
    {
        return $this->transaction(function (PDO $pdo) use ($withdrawalId, $tokenHash): array {
            $statement = $pdo->prepare(
                'SELECT * FROM withdrawal_requests
                 WHERE withdrawal_id = ? AND token_hash = ? FOR UPDATE'
            );
            $statement->execute([$withdrawalId, $tokenHash]);
            $row = $statement->fetch(PDO::FETCH_ASSOC);
            if (!is_array($row) || $row['state'] !== 'awaiting_confirmation') {
                throw new CarmajaCommerceException('withdrawal_confirmation_invalid', 'Widerruf kann nicht bestÃ¤tigt werden.', 409);
            }
            $state = $row['match_status'] === 'matched' ? 'submitted' : 'submitted';
            $pdo->prepare(
                "UPDATE withdrawal_requests
                 SET state = ?, received_at = UTC_TIMESTAMP(6), confirmed_at = UTC_TIMESTAMP(6)
                 WHERE withdrawal_id = ?"
            )->execute([$state, $withdrawalId]);
            $content = json_decode((string) $row['submitted_content'], true, 16, JSON_THROW_ON_ERROR);
            $pdo->prepare(
                'INSERT INTO mail_outbox
                    (dedupe_key, message_type, order_id, recipient, payload, status, next_attempt_at)
                 VALUES (?, \'withdrawal_receipt\', ?, ?, ?, \'queued\', UTC_TIMESTAMP(6))'
            )->execute([
                'withdrawal-receipt:' . $withdrawalId,
                $row['order_id'],
                (string) ($content['email'] ?? ''),
                json_encode([
                    'withdrawalId' => $withdrawalId,
                    'receivedAt' => gmdate(DATE_ATOM),
                    'content' => $content,
                ], JSON_THROW_ON_ERROR),
            ]);
            if ($row['match_status'] !== 'matched') {
                $case = $pdo->prepare(
                    "INSERT INTO review_cases
                        (review_case_id, subject_type, subject_id, reason, status, details, opened_at)
                     VALUES (?, 'withdrawal', ?, 'withdrawal_not_uniquely_matched', 'open', ?, UTC_TIMESTAMP(6))"
                );
                $case->execute([
                    carmaja_commerce_new_id(), $withdrawalId,
                    json_encode(['withdrawalId' => $withdrawalId], JSON_THROW_ON_ERROR),
                ]);
            }
            $row['state'] = $state;
            $row['confirmed_at'] = gmdate('Y-m-d H:i:s.u');
            return $row;
        });
    }

    public function findOrderForWithdrawal(string $orderNumber, string $name, string $email): array
    {
        $statement = $this->pdo->prepare(
            'SELECT order_id, order_number, customer_name, customer_email
             FROM orders WHERE order_number = ?'
        );
        $statement->execute([$orderNumber]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return ['matchStatus' => 'manual_review', 'orderId' => null];
        }
        $matched = hash_equals(mb_strtolower((string) $row['customer_name']), mb_strtolower($name))
            && hash_equals(mb_strtolower((string) $row['customer_email']), mb_strtolower($email));
        return [
            'matchStatus' => $matched ? 'matched' : 'manual_review',
            'orderId' => $matched ? (string) $row['order_id'] : null,
        ];
    }

    public function migrate(string $schemaFile): void
    {
        $sql = file_get_contents($schemaFile);
        if (!is_string($sql) || trim($sql) === '') {
            throw new CarmajaCommerceException('schema_unavailable', 'Commerce-Schema fehlt.', 500);
        }

        $sql = str_replace("\r\n", "\n", $sql);
        $sql = preg_replace('/^\s*--.*$/m', '', $sql) ?? $sql;
        foreach (preg_split('/;\s*(?:\r?\n|$)/', $sql) ?: [] as $statement) {
            $statement = trim($statement);
            if ($statement !== '') {
                $this->pdo->exec($statement);
            }
        }

        $checksum = hash('sha256', $sql);
        $journal = $this->pdo->prepare(
            'INSERT INTO schema_migrations (migration_id, checksum)
             VALUES (?, ?)
             ON DUPLICATE KEY UPDATE checksum = VALUES(checksum)'
        );
        $journal->execute(['commerce-v1-schema', $checksum]);
    }

    public function migrateForward(string $migrationId, string $migrationFile): void
    {
        if (preg_match('/^[A-Za-z0-9._:-]{1,100}$/', $migrationId) !== 1) {
            throw new CarmajaCommerceException('migration_id_invalid', 'Migrations-ID ist ungÃ¼ltig.', 422);
        }
        $sql = file_get_contents($migrationFile);
        if (!is_string($sql) || trim($sql) === '') {
            throw new CarmajaCommerceException('migration_unavailable', 'VorwÃ¤rtsmigration fehlt.', 500);
        }
        $sql = str_replace("\r\n", "\n", $sql);
        $normalized = preg_replace('/^\s*--.*$/m', '', $sql) ?? $sql;
        $checksum = hash('sha256', $normalized);
        $existing = $this->pdo->prepare('SELECT checksum FROM schema_migrations WHERE migration_id = ?');
        $existing->execute([$migrationId]);
        $stored = $existing->fetchColumn();
        if (is_string($stored)) {
            if (!hash_equals($stored, $checksum)) {
                throw new CarmajaCommerceException('migration_checksum_conflict', 'Migrations-PrÃ¼fsumme weicht ab.', 409);
            }
            return;
        }
        foreach (preg_split('/;\s*(?:\r?\n|$)/', $normalized) ?: [] as $statement) {
            $statement = trim($statement);
            if ($statement !== '') {
                $this->pdo->exec($statement);
            }
        }
        $journal = $this->pdo->prepare(
            'INSERT INTO schema_migrations (migration_id, checksum) VALUES (?, ?)'
        );
        $journal->execute([$migrationId, $checksum]);
    }

    public function transaction(callable $callback): mixed
    {
        $this->pdo->beginTransaction();

        try {
            $result = $callback($this->pdo);
            $this->pdo->commit();
            return $result;
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
    }

    public function createCheckout(array $raw): array
    {
        $input = carmaja_commerce_validate_checkout($raw);

        return $this->transaction(function (PDO $pdo) use ($input): array {
            $existing = $pdo->prepare(
                'SELECT * FROM checkout_sagas WHERE idempotency_key = ? FOR UPDATE'
            );
            $existing->execute([$input['idempotencyKey']]);
            $row = $existing->fetch(PDO::FETCH_ASSOC);
            if (is_array($row)) {
                if ($row['request_hash'] !== $input['requestHash']) {
                    throw new CarmajaCommerceException('idempotency_conflict', 'Idempotenzschlüssel gehört zu einer anderen Anfrage.', 409);
                }
                return $row;
            }

            $productStatement = $pdo->prepare(
                'SELECT product_id, product_version, source_hash, price_minor,
                    currency, sales_enabled FROM commerce_products
                 WHERE product_id = ? FOR UPDATE'
            );
            $productStatement->execute([$input['productId']]);
            $product = $productStatement->fetch(PDO::FETCH_ASSOC);
            if (!is_array($product)) {
                throw new CarmajaCommerceException('product_not_found', 'Produkt ist nicht verfügbar.', 404);
            }

            if ((int) $product['product_version'] !== $input['productVersion']
                || $product['source_hash'] !== $input['sourceHash']
                || (int) $product['price_minor'] !== $input['priceMinor']
                || $product['currency'] !== $input['currency']
                || (int) $product['sales_enabled'] !== 1) {
                throw new CarmajaCommerceException('product_snapshot_stale', 'Produkt-Snapshot ist nicht mehr aktuell.', 409);
            }

            $inventoryStatement = $pdo->prepare(
                'SELECT on_hand FROM commerce_inventory WHERE product_id = ? FOR UPDATE'
            );
            $inventoryStatement->execute([$input['productId']]);
            $inventory = $inventoryStatement->fetch(PDO::FETCH_ASSOC);
            if (!is_array($inventory)) {
                throw new CarmajaCommerceException('inventory_missing', 'Bestand ist nicht verfügbar.', 409);
            }

            $blockingStatement = $pdo->prepare(
                "SELECT COUNT(*) FROM reservations
                 WHERE product_id = ? AND blocks_stock = 1
                   AND state IN ('creating','active','expired','manual_review')
                 FOR UPDATE"
            );
            $blockingStatement->execute([$input['productId']]);
            if ((int) $inventory['on_hand'] - (int) $blockingStatement->fetchColumn() < 1) {
                throw new CarmajaCommerceException('sold_out_or_reserved', 'Produkt ist nicht verfügbar.', 409);
            }

            $legal = $pdo->prepare(
                "SELECT legal_bundle_id FROM legal_bundles
                 WHERE legal_bundle_id = ? AND status = 'approved' FOR UPDATE"
            );
            $legal->execute([$input['legalBundleId']]);
            if ($legal->fetchColumn() === false) {
                throw new CarmajaCommerceException('legal_bundle_unavailable', 'Rechtstextversion ist nicht freigegeben.', 409);
            }

            $checkoutInsert = $pdo->prepare(
                'INSERT INTO checkout_sagas
                    (checkout_id, idempotency_key, request_hash, product_id,
                     product_version, source_hash, price_minor, currency,
                     shipping_snapshot, legal_bundle_id, state, expires_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, \'created\', ?)'
            );
            $checkoutInsert->execute([
                $input['checkoutId'], $input['idempotencyKey'], $input['requestHash'],
                $input['productId'], $input['productVersion'], $input['sourceHash'],
                $input['priceMinor'], $input['currency'],
                json_encode($input['shippingSnapshot'], JSON_THROW_ON_ERROR),
                $input['legalBundleId'], $input['expiresAt'],
            ]);

            $reservationId = carmaja_commerce_new_id();
            $reservationInsert = $pdo->prepare(
                'INSERT INTO reservations
                    (reservation_id, checkout_id, product_id, quantity, state,
                     blocks_stock, expires_at)
                 VALUES (?, ?, ?, 1, \'active\', 1, ?)'
            );
            $reservationInsert->execute([$reservationId, $input['checkoutId'], $input['productId'], $input['expiresAt']]);

            $paymentId = carmaja_commerce_new_id();
            $shippingAmount = (int) ($input['shippingSnapshot']['amountMinor'] ?? 0);
            $paymentInsert = $pdo->prepare(
                'INSERT INTO payments
                    (payment_id, checkout_id, amount_minor, currency, status,
                     verification_status, refund_status, dispute_status)
                 VALUES (?, ?, ?, ?, \'created\', \'unverified\', \'none\', \'none\')'
            );
            $paymentInsert->execute([
                $paymentId, $input['checkoutId'], $input['priceMinor'] + $shippingAmount, $input['currency'],
            ]);

            return [
                'checkoutId' => $input['checkoutId'],
                'reservationId' => $reservationId,
                'paymentId' => $paymentId,
                'state' => 'created',
            ];
        });
    }

    public function loadProductForCheckout(string $productId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT product_id, product_version, source_hash, name, price_minor,
                    currency, sales_enabled
             FROM commerce_products WHERE product_id = ?'
        );
        $statement->execute([$productId]);
        $product = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($product)) {
            throw new CarmajaCommerceException('product_not_found', 'Produkt ist nicht verfügbar.', 404);
        }
        return $product;
    }

    public function loadApprovedLegalBundle(string $legalBundleId): array
    {
        $statement = $this->pdo->prepare(
            "SELECT legal_bundle_id, bundle_hash, terms_version,
                    privacy_version, withdrawal_version, shipping_version,
                    merchant_version, status
             FROM legal_bundles WHERE legal_bundle_id = ? AND status = 'approved'"
        );
        $statement->execute([$legalBundleId]);
        $bundle = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($bundle)) {
            throw new CarmajaCommerceException('legal_bundle_unavailable', 'Rechtstextversion ist nicht freigegeben.', 409);
        }
        return $bundle;
    }

    public function findCheckoutByIdempotency(string $idempotencyKey): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT c.*, p.payment_id, p.stripe_checkout_session_id, p.status AS payment_status
             FROM checkout_sagas c JOIN payments p ON p.checkout_id = c.checkout_id
             WHERE c.idempotency_key = ?'
        );
        $statement->execute([$idempotencyKey]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    public function findPaymentByStripeObject(string $stripeObjectId): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT * FROM payments
             WHERE stripe_checkout_session_id = ? OR stripe_payment_intent_id = ?'
        );
        $statement->execute([$stripeObjectId, $stripeObjectId]);
        $payment = $statement->fetch(PDO::FETCH_ASSOC);
        return is_array($payment) ? $payment : null;
    }

    public function findOpenStripeSessions(int $limit = 10): array
    {
        $limit = max(1, min(20, $limit));
        $statement = $this->pdo->query(
            "SELECT c.checkout_id, c.state, p.stripe_checkout_session_id
             FROM checkout_sagas c JOIN payments p ON p.checkout_id = c.checkout_id
             WHERE p.stripe_checkout_session_id IS NOT NULL
               AND c.state IN ('stripe_open','payment_pending','manual_review')
             ORDER BY c.created_at LIMIT {$limit}"
        );
        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    public function recordStripeCreationOutcome(string $checkoutId, string $outcome, ?string $sessionId = null): void
    {
        $this->transaction(function (PDO $pdo) use ($checkoutId, $outcome, $sessionId): void {
            $checkout = $pdo->prepare('SELECT state FROM checkout_sagas WHERE checkout_id = ? FOR UPDATE');
            $checkout->execute([$checkoutId]);
            $checkoutState = $checkout->fetchColumn();
            if ($checkoutState === false) {
                throw new CarmajaCommerceException('checkout_not_found', 'Checkout fehlt.', 404);
            }
            $paymentLock = $pdo->prepare(
                'SELECT payment_id FROM payments WHERE checkout_id = ? FOR UPDATE'
            );
            $paymentLock->execute([$checkoutId]);
            if ($paymentLock->fetchColumn() === false) {
                throw new CarmajaCommerceException('payment_not_found', 'Zahlungseinheit fehlt.', 409);
            }
            $reservation = $pdo->prepare(
                'SELECT reservation_id, state FROM reservations WHERE checkout_id = ? FOR UPDATE'
            );
            $reservation->execute([$checkoutId]);
            $reservationRow = $reservation->fetch(PDO::FETCH_ASSOC);
            if (!is_array($reservationRow)) {
                throw new CarmajaCommerceException('reservation_not_found', 'Reservierung fehlt.', 409);
            }

            if ($outcome === 'created') {
                $update = $pdo->prepare(
                    "UPDATE checkout_sagas SET state = 'stripe_open'
                     WHERE checkout_id = ? AND state IN ('creating','created','payment_pending')");
                $update->execute([$checkoutId]);
                if ($sessionId !== null) {
                    $payment = $pdo->prepare(
                        'UPDATE payments SET stripe_checkout_session_id = ?, status = \'pending\'
                         WHERE checkout_id = ?'
                    );
                    $payment->execute([$sessionId, $checkoutId]);
                    $pdo->prepare(
                        'UPDATE reservations SET stripe_session_id = ? WHERE checkout_id = ?'
                    )->execute([$sessionId, $checkoutId]);
                }
                return;
            }

            if ($outcome === 'failed') {
                $pdo->prepare(
                    "UPDATE checkout_sagas SET state = 'failed', failure_code = 'stripe_session_not_created'
                     WHERE checkout_id = ? AND state IN ('creating','created')"
                )->execute([$checkoutId]);
                $pdo->prepare(
                    "UPDATE reservations SET state = 'released', blocks_stock = 0, released_at = UTC_TIMESTAMP(6)
                     WHERE checkout_id = ? AND state NOT IN ('converted','released')"
                )->execute([$checkoutId]);
                return;
            }

            if ($outcome === 'unknown') {
                if (in_array($checkoutState, ['completed', 'failed', 'expired', 'canceled'], true)) {
                    return;
                }
                $pdo->prepare("UPDATE checkout_sagas SET state = 'manual_review' WHERE checkout_id = ?")
                    ->execute([$checkoutId]);
                $pdo->prepare(
                    "UPDATE reservations SET state = 'manual_review', blocks_stock = 1
                     WHERE checkout_id = ? AND state NOT IN ('converted','released')"
                )->execute([$checkoutId]);
                $this->openReviewCase($pdo, 'checkout', $checkoutId, 'stripe_creation_unknown');
                return;
            }

            throw new CarmajaCommerceException('invalid_stripe_outcome', 'Unbekannter Stripe-Ausgang.', 422);
        });
    }

    public function releaseExpiredReservation(string $checkoutId): void
    {
        $this->transaction(function (PDO $pdo) use ($checkoutId): void {
            $checkout = $pdo->prepare(
                'SELECT state FROM checkout_sagas WHERE checkout_id = ? FOR UPDATE'
            );
            $checkout->execute([$checkoutId]);
            $checkoutState = $checkout->fetchColumn();
            if ($checkoutState === false) {
                throw new CarmajaCommerceException('checkout_not_found', 'Checkout fehlt.', 404);
            }
            $reservation = $pdo->prepare(
                'SELECT state FROM reservations WHERE checkout_id = ? FOR UPDATE'
            );
            $reservation->execute([$checkoutId]);
            $state = $reservation->fetchColumn();
            if ($state === false) {
                throw new CarmajaCommerceException('reservation_not_found', 'Reservierung fehlt.', 409);
            }
            $paymentStatement = $pdo->prepare(
                'SELECT payment_id, status FROM payments WHERE checkout_id = ? FOR UPDATE'
            );
            $paymentStatement->execute([$checkoutId]);
            $payment = $paymentStatement->fetch(PDO::FETCH_ASSOC);
            if (!is_array($payment)) {
                throw new CarmajaCommerceException('payment_not_found', 'Zahlungseinheit fehlt.', 409);
            }
            if ($state === 'converted') {
                return;
            }
            if ($state === 'released') {
                $pdo->prepare(
                    "UPDATE payments SET status = 'canceled'
                     WHERE checkout_id = ? AND status IN ('created','pending')"
                )->execute([$checkoutId]);
                return;
            }
            if ($payment['status'] === 'processing') {
                $pdo->prepare("UPDATE checkout_sagas SET state = 'manual_review' WHERE checkout_id = ?")
                    ->execute([$checkoutId]);
                $pdo->prepare(
                    "UPDATE reservations SET state = 'manual_review', blocks_stock = 1 WHERE checkout_id = ?"
                )->execute([$checkoutId]);
                if ($checkoutState !== 'manual_review') {
                    $this->openReviewCase(
                        $pdo,
                        'payment',
                        (string) $payment['payment_id'],
                        'expiration_during_payment_processing'
                    );
                }
                return;
            }
            $pdo->prepare(
                "UPDATE reservations SET state = 'released', blocks_stock = 0,
                        released_at = UTC_TIMESTAMP(6)
                 WHERE checkout_id = ?"
            )->execute([$checkoutId]);
            $pdo->prepare(
                "UPDATE payments SET status = 'canceled'
                 WHERE checkout_id = ? AND status IN ('created','pending')"
            )->execute([$checkoutId]);
            $pdo->prepare(
                "UPDATE checkout_sagas SET state = 'expired' WHERE checkout_id = ?
                 AND state NOT IN ('completed','failed','canceled')"
            )->execute([$checkoutId]);
        });
    }

    public function markPaymentProcessing(string $checkoutId, array $event): array
    {
        $result = $this->transaction(function (PDO $pdo) use ($checkoutId, $event): array {
            $checkoutStatement = $pdo->prepare('SELECT * FROM checkout_sagas WHERE checkout_id = ? FOR UPDATE');
            $checkoutStatement->execute([$checkoutId]);
            $checkout = $checkoutStatement->fetch(PDO::FETCH_ASSOC);
            $paymentStatement = $pdo->prepare('SELECT * FROM payments WHERE checkout_id = ? FOR UPDATE');
            $paymentStatement->execute([$checkoutId]);
            $payment = $paymentStatement->fetch(PDO::FETCH_ASSOC);
            $reservationStatement = $pdo->prepare('SELECT * FROM reservations WHERE checkout_id = ? FOR UPDATE');
            $reservationStatement->execute([$checkoutId]);
            $reservation = $reservationStatement->fetch(PDO::FETCH_ASSOC);
            if (!is_array($checkout) || !is_array($payment) || !is_array($reservation)) {
                throw new CarmajaCommerceException('commerce_relation_missing', 'Commerce-Zuordnung fehlt.', 409);
            }
            if ($payment['order_id'] !== null || $payment['status'] === 'succeeded') {
                return ['paymentId' => $payment['payment_id'], 'status' => 'succeeded'];
            }

            try {
                $paymentMethodType = carmaja_commerce_payment_method_type($event['paymentMethodType'] ?? null);
            } catch (CarmajaCommerceException) {
                $paymentMethodType = '';
            }
            $stripePaymentIntentId = is_string($event['stripePaymentIntentId'] ?? null)
                ? trim($event['stripePaymentIntentId'])
                : '';
            $valid = ($event['paymentStatus'] ?? null) === 'processing'
                && ($event['paymentIntentStatus'] ?? null) === 'processing'
                && (int) ($event['amountMinor'] ?? -1) === (int) $payment['amount_minor']
                && ($event['currency'] ?? null) === $payment['currency']
                && ($event['productId'] ?? null) === $checkout['product_id']
                && ($event['legalBundleId'] ?? null) === $checkout['legal_bundle_id']
                && ($event['termsAccepted'] ?? false) === true
                && $stripePaymentIntentId !== ''
                && $paymentMethodType !== ''
                && ($payment['stripe_payment_intent_id'] === null
                    || hash_equals((string) $payment['stripe_payment_intent_id'], $stripePaymentIntentId))
                && ($payment['payment_method_type'] === null
                    || $payment['payment_method_type'] === $paymentMethodType)
                && $reservation['state'] === 'active'
                && (int) $reservation['blocks_stock'] === 1;
            if (!$valid) {
                $pdo->prepare("UPDATE payments SET status = 'manual_review', verification_status = 'manual_review' WHERE payment_id = ?")
                    ->execute([$payment['payment_id']]);
                $pdo->prepare("UPDATE checkout_sagas SET state = 'manual_review' WHERE checkout_id = ?")
                    ->execute([$checkoutId]);
                $pdo->prepare("UPDATE reservations SET state = 'manual_review', blocks_stock = 1 WHERE checkout_id = ?")
                    ->execute([$checkoutId]);
                $this->openReviewCase($pdo, 'payment', $payment['payment_id'], 'processing_payment_verification_failed');
                return ['paymentId' => $payment['payment_id'], 'status' => 'manual_review'];
            }

            $pdo->prepare(
                "UPDATE payments SET status = 'processing', verification_status = 'verified',
                        stripe_payment_intent_id = ?, payment_method_type = ?
                 WHERE payment_id = ?"
            )->execute([$stripePaymentIntentId, $paymentMethodType, $payment['payment_id']]);
            $pdo->prepare(
                "UPDATE checkout_sagas SET state = 'payment_pending', failure_code = NULL WHERE checkout_id = ?"
            )->execute([$checkoutId]);

            return ['paymentId' => $payment['payment_id'], 'status' => 'processing'];
        });
        if ($result['status'] === 'manual_review') {
            throw new CarmajaCommerceException('manual_review', 'Laufende Zahlung konnte nicht sicher geprüft werden.', 409);
        }
        return $result;
    }

    public function failAsyncPayment(string $checkoutId, array $event): array
    {
        $result = $this->transaction(function (PDO $pdo) use ($checkoutId, $event): array {
            $checkoutStatement = $pdo->prepare('SELECT * FROM checkout_sagas WHERE checkout_id = ? FOR UPDATE');
            $checkoutStatement->execute([$checkoutId]);
            $checkout = $checkoutStatement->fetch(PDO::FETCH_ASSOC);
            $paymentStatement = $pdo->prepare('SELECT * FROM payments WHERE checkout_id = ? FOR UPDATE');
            $paymentStatement->execute([$checkoutId]);
            $payment = $paymentStatement->fetch(PDO::FETCH_ASSOC);
            $reservationStatement = $pdo->prepare('SELECT * FROM reservations WHERE checkout_id = ? FOR UPDATE');
            $reservationStatement->execute([$checkoutId]);
            $reservation = $reservationStatement->fetch(PDO::FETCH_ASSOC);
            if (!is_array($checkout) || !is_array($payment) || !is_array($reservation)) {
                throw new CarmajaCommerceException('commerce_relation_missing', 'Commerce-Zuordnung fehlt.', 409);
            }
            if ($payment['status'] === 'failed' && $reservation['state'] === 'released') {
                return ['paymentId' => $payment['payment_id'], 'status' => 'failed'];
            }
            if ($payment['order_id'] !== null || $payment['status'] === 'succeeded') {
                $this->openReviewCase($pdo, 'payment', $payment['payment_id'], 'async_failure_after_success');
                return ['paymentId' => $payment['payment_id'], 'status' => 'manual_review'];
            }

            $method = $event['paymentMethodType'] ?? $payment['payment_method_type'];
            try {
                $paymentMethodType = carmaja_commerce_payment_method_type($method);
            } catch (CarmajaCommerceException) {
                $paymentMethodType = '';
            }
            $stripePaymentIntentId = is_string($event['stripePaymentIntentId'] ?? null)
                ? trim($event['stripePaymentIntentId'])
                : (string) ($payment['stripe_payment_intent_id'] ?? '');
            $valid = ($event['paymentStatus'] ?? null) === 'failed'
                && in_array(
                    $event['paymentIntentStatus'] ?? null,
                    ['requires_payment_method', 'canceled'],
                    true
                )
                && (int) ($event['amountMinor'] ?? -1) === (int) $payment['amount_minor']
                && ($event['currency'] ?? null) === $payment['currency']
                && ($event['productId'] ?? null) === $checkout['product_id']
                && ($event['legalBundleId'] ?? null) === $checkout['legal_bundle_id']
                && $paymentMethodType !== ''
                && ($payment['stripe_payment_intent_id'] === null
                    || ($stripePaymentIntentId !== ''
                        && hash_equals((string) $payment['stripe_payment_intent_id'], $stripePaymentIntentId)))
                && ($payment['payment_method_type'] === null
                    || $payment['payment_method_type'] === $paymentMethodType);
            if (!$valid) {
                $pdo->prepare("UPDATE payments SET status = 'manual_review', verification_status = 'manual_review' WHERE payment_id = ?")
                    ->execute([$payment['payment_id']]);
                $pdo->prepare("UPDATE checkout_sagas SET state = 'manual_review' WHERE checkout_id = ?")
                    ->execute([$checkoutId]);
                $pdo->prepare("UPDATE reservations SET state = 'manual_review', blocks_stock = 1 WHERE checkout_id = ?")
                    ->execute([$checkoutId]);
                $this->openReviewCase($pdo, 'payment', $payment['payment_id'], 'async_failure_verification_failed');
                return ['paymentId' => $payment['payment_id'], 'status' => 'manual_review'];
            }

            $pdo->prepare(
                "UPDATE payments SET status = 'failed', verification_status = 'verified',
                        stripe_payment_intent_id = NULLIF(?, ''), payment_method_type = ?
                 WHERE payment_id = ?"
            )->execute([$stripePaymentIntentId, $paymentMethodType, $payment['payment_id']]);
            $pdo->prepare(
                "UPDATE checkout_sagas SET state = 'failed', failure_code = 'async_payment_failed' WHERE checkout_id = ?"
            )->execute([$checkoutId]);
            $pdo->prepare(
                "UPDATE reservations SET state = 'released', blocks_stock = 0,
                        released_at = UTC_TIMESTAMP(6) WHERE checkout_id = ?"
            )->execute([$checkoutId]);

            return ['paymentId' => $payment['payment_id'], 'status' => 'failed'];
        });
        if ($result['status'] === 'manual_review') {
            throw new CarmajaCommerceException('manual_review', 'Stripe meldet einen widersprüchlichen oder nicht sicher prüfbaren Zahlungszustand.', 409);
        }
        return $result;
    }

    public function finalizePayment(string $checkoutId, array $event): array
    {
        $result = $this->transaction(function (PDO $pdo) use ($checkoutId, $event): array {
            $checkoutStatement = $pdo->prepare('SELECT * FROM checkout_sagas WHERE checkout_id = ? FOR UPDATE');
            $checkoutStatement->execute([$checkoutId]);
            $checkout = $checkoutStatement->fetch(PDO::FETCH_ASSOC);
            if (!is_array($checkout)) {
                throw new CarmajaCommerceException('checkout_not_found', 'Checkout fehlt.', 404);
            }
            $inventoryStatement = $pdo->prepare('SELECT on_hand, inventory_version FROM commerce_inventory WHERE product_id = ? FOR UPDATE');
            $inventoryStatement->execute([$checkout['product_id']]);
            $inventory = $inventoryStatement->fetch(PDO::FETCH_ASSOC);
            $paymentStatement = $pdo->prepare('SELECT * FROM payments WHERE checkout_id = ? FOR UPDATE');
            $paymentStatement->execute([$checkoutId]);
            $payment = $paymentStatement->fetch(PDO::FETCH_ASSOC);
            $reservationStatement = $pdo->prepare('SELECT * FROM reservations WHERE checkout_id = ? FOR UPDATE');
            $reservationStatement->execute([$checkoutId]);
            $reservation = $reservationStatement->fetch(PDO::FETCH_ASSOC);
            if (!is_array($payment) || !is_array($reservation)) {
                throw new CarmajaCommerceException('commerce_relation_missing', 'Commerce-Zuordnung fehlt.', 409);
            }
            if ($payment['order_id'] !== null) {
                $order = $pdo->prepare('SELECT * FROM orders WHERE order_id = ?');
                $order->execute([$payment['order_id']]);
                return $order->fetch(PDO::FETCH_ASSOC) ?: [];
            }

            $stripePaymentIntentId = is_string($event['stripePaymentIntentId'] ?? null)
                ? trim($event['stripePaymentIntentId'])
                : '';
            try {
                $paymentMethodType = carmaja_commerce_payment_method_type($event['paymentMethodType'] ?? null);
            } catch (CarmajaCommerceException) {
                $paymentMethodType = '';
            }
            $valid = ($event['paymentStatus'] ?? null) === 'succeeded'
                && ($event['paymentIntentStatus'] ?? null) === 'succeeded'
                && (int) ($event['amountMinor'] ?? -1) === (int) $payment['amount_minor']
                && ($event['currency'] ?? null) === $payment['currency']
                && ($event['productId'] ?? null) === $checkout['product_id']
                && ($event['legalBundleId'] ?? null) === $checkout['legal_bundle_id']
                && ($event['termsAccepted'] ?? false) === true
                && $stripePaymentIntentId !== ''
                && $paymentMethodType !== ''
                && ($payment['stripe_payment_intent_id'] === null
                    || hash_equals((string) $payment['stripe_payment_intent_id'], $stripePaymentIntentId))
                && ($payment['payment_method_type'] === null
                    || $payment['payment_method_type'] === $paymentMethodType);
            if (!$valid || $reservation['state'] !== 'active') {
                $pdo->prepare("UPDATE payments SET status = 'manual_review', verification_status = 'manual_review' WHERE payment_id = ?")
                    ->execute([$payment['payment_id']]);
                $pdo->prepare("UPDATE checkout_sagas SET state = 'manual_review' WHERE checkout_id = ?")
                    ->execute([$checkoutId]);
                $pdo->prepare("UPDATE reservations SET state = 'manual_review', blocks_stock = 1 WHERE checkout_id = ?")
                    ->execute([$checkoutId]);
                $this->openReviewCase($pdo, 'payment', $payment['payment_id'], 'payment_verification_failed');
                return ['paymentId' => $payment['payment_id'], 'status' => 'manual_review'];
            }

            if (!is_array($inventory) || (int) $inventory['on_hand'] !== 1) {
                $pdo->prepare("UPDATE payments SET status = 'manual_review', verification_status = 'manual_review' WHERE payment_id = ?")
                    ->execute([$payment['payment_id']]);
                $pdo->prepare("UPDATE checkout_sagas SET state = 'manual_review' WHERE checkout_id = ?")
                    ->execute([$checkoutId]);
                $pdo->prepare("UPDATE reservations SET state = 'manual_review', blocks_stock = 1 WHERE checkout_id = ?")
                    ->execute([$checkoutId]);
                $this->openReviewCase($pdo, 'payment', $payment['payment_id'], 'inventory_conflict_after_payment');
                return ['paymentId' => $payment['payment_id'], 'status' => 'manual_review'];
            }

            $sequence = $pdo->query("SELECT next_value FROM order_sequences WHERE sequence_name = 'carmaja-v1' FOR UPDATE")->fetchColumn();
            if ($sequence === false) {
                throw new CarmajaCommerceException('order_sequence_missing', 'Bestellnummernsequenz fehlt.', 500);
            }
            $orderNumber = 'CMJ-' . str_pad((string) $sequence, 8, '0', STR_PAD_LEFT);
            $pdo->prepare("UPDATE order_sequences SET next_value = next_value + 1 WHERE sequence_name = 'carmaja-v1'")->execute();

            $orderId = carmaja_commerce_new_id();
            $productSnapshot = json_encode([
                'productId' => $checkout['product_id'],
                'productVersion' => (int) $checkout['product_version'],
                'sourceHash' => $checkout['source_hash'],
                'priceMinor' => (int) $checkout['price_minor'],
                'currency' => $checkout['currency'],
            ], JSON_THROW_ON_ERROR);
            $shippingSnapshot = $checkout['shipping_snapshot'];
            $pdo->prepare(
                'INSERT INTO orders
                    (order_id, order_number, checkout_id, payment_id, status,
                     customer_email, customer_name, shipping_address, billing_address,
                     product_snapshot, shipping_snapshot, legal_bundle_id, confirmed_at)
                 VALUES (?, ?, ?, ?, \'confirmed\', ?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP(6))'
            )->execute([
                $orderId, $orderNumber, $checkoutId, $payment['payment_id'],
                $event['customerEmail'] ?? '', $event['customerName'] ?? '',
                json_encode($event['shippingAddress'] ?? [], JSON_THROW_ON_ERROR),
                isset($event['billingAddress']) ? json_encode($event['billingAddress'], JSON_THROW_ON_ERROR) : null,
                $productSnapshot, $shippingSnapshot, $checkout['legal_bundle_id'],
            ]);
            $pdo->prepare(
                'INSERT INTO order_items
                    (order_id, position_no, product_id, quantity, price_minor, currency, product_snapshot)
                 VALUES (?, 1, ?, 1, ?, ?, ?)'
            )->execute([$orderId, $checkout['product_id'], $checkout['price_minor'], $checkout['currency'], $productSnapshot]);
            $pdo->prepare(
                'INSERT INTO shipments
                    (shipment_id, order_id, status, shipping_method_id)
                 VALUES (?, ?, \'ready\', ?)'
            )->execute([carmaja_commerce_new_id(), $orderId, json_decode($shippingSnapshot, true, 512, JSON_THROW_ON_ERROR)['shippingMethodId'] ?? 'de-standard']);
            $pdo->prepare(
                'UPDATE commerce_inventory SET on_hand = 0, inventory_version = inventory_version + 1 WHERE product_id = ?'
            )->execute([$checkout['product_id']]);
            $pdo->prepare(
                "UPDATE reservations SET state = 'converted', blocks_stock = 0, converted_at = UTC_TIMESTAMP(6) WHERE checkout_id = ?"
            )->execute([$checkoutId]);
            $pdo->prepare("UPDATE checkout_sagas SET state = 'completed' WHERE checkout_id = ?")->execute([$checkoutId]);
            $pdo->prepare(
                "UPDATE payments SET order_id = ?, status = 'succeeded',
                        verification_status = 'verified', payment_method_type = ?
                 WHERE payment_id = ?"
            )->execute([$orderId, $paymentMethodType, $payment['payment_id']]);
            $pdo->prepare(
                'UPDATE shop_rate_limits r
                 JOIN checkout_tokens t ON t.rate_bucket_hash = r.bucket_hash
                 SET r.successful_attempts = r.successful_attempts + 1
                 WHERE t.checkout_id = ?'
            )->execute([$checkoutId]);
            $pdo->prepare(
                'UPDATE shop_rate_limits r
                 JOIN checkout_tokens t ON t.ip_bucket_hash = r.bucket_hash
                 SET r.successful_attempts = r.successful_attempts + 1
                 WHERE t.checkout_id = ?'
            )->execute([$checkoutId]);
            if ($stripePaymentIntentId !== '') {
                $pdo->prepare(
                    'UPDATE payments SET stripe_payment_intent_id = ? WHERE payment_id = ?'
                )->execute([$stripePaymentIntentId, $payment['payment_id']]);
            }
            $pdo->prepare(
                'INSERT INTO mail_outbox
                    (dedupe_key, message_type, order_id, recipient, payload, status, next_attempt_at)
                 VALUES (?, \'order_confirmation\', ?, ?, ?, \'queued\', UTC_TIMESTAMP(6))'
            )->execute([
                'order-confirmation:' . $orderId, $orderId,
                $event['customerEmail'] ?? '', json_encode(['orderNumber' => $orderNumber], JSON_THROW_ON_ERROR),
            ]);
            $pdo->prepare(
                'INSERT INTO stripe_metadata_outbox
                    (dedupe_key, payment_id, stripe_payment_intent_id, metadata_payload, status, next_attempt_at)
                 VALUES (?, ?, ?, ?, \'queued\', UTC_TIMESTAMP(6))'
            )->execute([
                'payment-order:' . $payment['payment_id'], $payment['payment_id'],
                $event['stripePaymentIntentId'] ?? '', json_encode(['orderNumber' => $orderNumber], JSON_THROW_ON_ERROR),
            ]);

            return [
                'orderId' => $orderId,
                'orderNumber' => $orderNumber,
                'status' => 'confirmed',
            ];
        });
        if ($result['status'] === 'manual_review') {
            throw new CarmajaCommerceException('manual_review', 'Zahlung konnte nicht sicher finalisiert werden.', 409);
        }
        return $result;
    }

    public function applyRefund(string $paymentId, string $stripeRefundId, string $status, int $amountMinor): void
    {
        $this->transaction(function (PDO $pdo) use ($paymentId, $stripeRefundId, $status, $amountMinor): void {
            $statement = $pdo->prepare(
                'INSERT INTO refunds (refund_id, payment_id, stripe_refund_id, status, amount_minor, currency)
                 VALUES (?, ?, ?, ?, ?, \'eur\')
                 ON DUPLICATE KEY UPDATE status = VALUES(status)'
            );
            $statement->execute([carmaja_commerce_new_id(), $paymentId, $stripeRefundId, $status, $amountMinor]);
            $pdo->prepare('UPDATE payments SET refund_status = ? WHERE payment_id = ?')->execute([$status, $paymentId]);
            // No inventory update: release_return is an explicit later action.
        });
    }

    private function openReviewCase(PDO $pdo, string $subjectType, string $subjectId, string $reason): void
    {
        $existing = $pdo->prepare(
            "SELECT review_case_id FROM review_cases
             WHERE subject_type = ? AND subject_id = ? AND reason = ?
               AND status IN ('open', 'investigating')
             ORDER BY opened_at LIMIT 1 FOR UPDATE"
        );
        $existing->execute([$subjectType, $subjectId, $reason]);
        if ($existing->fetchColumn() !== false) {
            return;
        }
        $pdo->prepare(
            'INSERT INTO review_cases
                (review_case_id, subject_type, subject_id, reason, status, details, opened_at)
             VALUES (?, ?, ?, ?, \'open\', ?, UTC_TIMESTAMP(6))'
        )->execute([
            carmaja_commerce_new_id(), $subjectType, $subjectId, $reason,
            json_encode(['reason' => $reason], JSON_THROW_ON_ERROR),
        ]);
    }

    public function adjustInventory(array $input, string $actorId, bool $manualOperator = true): array
    {
        $reason = $input['reason'] ?? null;
        if (!in_array($reason, CARMAJA_COMMERCE_INVENTORY_REASONS, true)
            || ($manualOperator && $reason === 'shop_sale')) {
            throw new CarmajaCommerceException('invalid_inventory_reason', 'Bestandsgrund ist nicht zulässig.', 422);
        }

        return $this->transaction(function (PDO $pdo) use ($input, $actorId, $reason): array {
            $idempotency = $pdo->prepare(
                'SELECT adjustment_id, product_id, target_on_hand, previous_on_hand,
                    inventory_version, reason, correlation_id, idempotency_key, actor_id
                 FROM inventory_adjustments WHERE idempotency_key = ? FOR UPDATE'
            );
            $idempotency->execute([$input['idempotencyKey']]);
            $existing = $idempotency->fetch(PDO::FETCH_ASSOC);
            if (is_array($existing)) {
                return $existing;
            }

            $select = $pdo->prepare(
                'SELECT product_id, on_hand, inventory_version FROM commerce_inventory
                 WHERE product_id = ? FOR UPDATE'
            );
            $select->execute([$input['productId']]);
            $inventory = $select->fetch(PDO::FETCH_ASSOC);
            if (!is_array($inventory)) {
                throw new CarmajaCommerceException('product_not_found', 'Inventarprodukt fehlt.', 404);
            }

            if ((int) $input['expectedInventoryVersion'] !== (int) $inventory['inventory_version']) {
                throw new CarmajaCommerceException('inventory_version_conflict', 'Bestandsversion ist veraltet.', 409);
            }

            $target = (int) $input['targetOnHand'];
            if (!in_array($target, [0, 1], true)) {
                throw new CarmajaCommerceException('validation_failed', 'targetOnHand muss 0 oder 1 sein.', 422);
            }

            if ($target < (int) $inventory['on_hand']) {
                $blocking = $pdo->prepare(
                    "SELECT COUNT(*) FROM reservations
                     WHERE product_id = ? AND blocks_stock = 1
                       AND state IN ('creating','active','expired','manual_review')
                     FOR UPDATE"
                );
                $blocking->execute([$input['productId']]);
                if ((int) $blocking->fetchColumn() > 0) {
                    throw new CarmajaCommerceException('inventory_reserved', 'Bestand ist reserviert.', 409);
                }
            }

            $nextVersion = (int) $inventory['inventory_version'] + 1;
            $update = $pdo->prepare(
                'UPDATE commerce_inventory SET on_hand = ?, inventory_version = ?
                 WHERE product_id = ?'
            );
            $update->execute([$target, $nextVersion, $input['productId']]);
            $insert = $pdo->prepare(
                'INSERT INTO inventory_adjustments
                    (product_id, target_on_hand, previous_on_hand, inventory_version,
                     reason, correlation_id, idempotency_key, actor_id)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $insert->execute([
                $input['productId'], $target, (int) $inventory['on_hand'], $nextVersion,
                $reason, $input['correlationId'], $input['idempotencyKey'], $actorId,
            ]);

            return [
                'product_id' => $input['productId'],
                'target_on_hand' => $target,
                'previous_on_hand' => (int) $inventory['on_hand'],
                'inventory_version' => $nextVersion,
                'reason' => $reason,
                'correlation_id' => $input['correlationId'],
                'idempotency_key' => $input['idempotencyKey'],
                'actor_id' => $actorId,
            ];
        });
    }

    public function persistWebhook(array $event): bool
    {
        try {
            $statement = $this->pdo->prepare(
                'INSERT INTO webhook_inbox
                    (stripe_event_id, event_type, stripe_object_id, livemode,
                     payload_hash, received_at, status, next_attempt_at)
                 VALUES (?, ?, ?, ?, ?, UTC_TIMESTAMP(6), \'queued\', UTC_TIMESTAMP(6))'
            );
            $statement->execute([
                $event['id'], $event['type'], $event['objectId'] ?? null,
                $event['livemode'] ? 1 : 0, $event['payloadHash'],
            ]);
            return true;
        } catch (PDOException $error) {
            if ((string) $error->errorInfo[1] === '1062') {
                return false;
            }
            throw $error;
        }
    }

    public function persistWebhookEnvelope(
        array $event,
        string $payloadCiphertext,
        string $payloadKeyId
    ): bool {
        try {
            $statement = $this->pdo->prepare(
                'INSERT INTO webhook_inbox
                    (stripe_event_id, event_type, stripe_object_id, livemode,
                     payload_hash, payload_ciphertext, payload_key_id,
                     received_at, status, next_attempt_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, \'queued\', UTC_TIMESTAMP(6))'
            );
            $statement->execute([
                $event['id'], $event['type'], $event['objectId'] ?? null,
                $event['livemode'] ? 1 : 0, $event['payloadHash'],
                $payloadCiphertext, $payloadKeyId, $event['receivedAt'],
            ]);
            return true;
        } catch (PDOException $error) {
            if ((string) ($error->errorInfo[1] ?? '') === '1062') {
                return false;
            }
            throw $error;
        }
    }

    public function claimWebhookBatch(int $batchSize, int $leaseSeconds = 600): array
    {
        $batchSize = max(1, min(20, $batchSize));
        return $this->transaction(function (PDO $pdo) use ($batchSize, $leaseSeconds): array {
            $rows = $pdo->query(
                "SELECT inbox_id, stripe_event_id, event_type, payload_ciphertext,
                        payload_key_id, attempt_count
                 FROM webhook_inbox
                 WHERE next_attempt_at <= UTC_TIMESTAMP(6)
                   AND (status = 'queued' OR (status = 'processing' AND lease_until < UTC_TIMESTAMP(6)))
                   AND attempt_count <= 5
                 ORDER BY inbox_id
                 LIMIT {$batchSize} FOR UPDATE SKIP LOCKED"
            )->fetchAll(PDO::FETCH_ASSOC);
            if ($rows === []) {
                return [];
            }
            $update = $pdo->prepare(
                "UPDATE webhook_inbox SET status = 'processing', attempt_count = attempt_count + 1,
                        lease_until = DATE_ADD(UTC_TIMESTAMP(6), INTERVAL ? SECOND)
                 WHERE inbox_id = ?"
            );
            foreach ($rows as $row) {
                $update->execute([$leaseSeconds, $row['inbox_id']]);
            }
            return $rows;
        });
    }

    public function recordDispute(
        string $paymentId,
        string $stripeDisputeId,
        string $stripeStatus,
        string $lastEventAt
    ): void {
        $this->transaction(function (PDO $pdo) use ($paymentId, $stripeDisputeId, $stripeStatus, $lastEventAt): void {
            $pdo->prepare(
                'INSERT INTO disputes
                    (dispute_id, payment_id, stripe_dispute_id, stripe_status, last_event_at)
                 VALUES (?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE stripe_status = IF(last_event_at <= VALUES(last_event_at), VALUES(stripe_status), stripe_status),
                    last_event_at = GREATEST(last_event_at, VALUES(last_event_at))'
            )->execute([
                carmaja_commerce_new_id(), $paymentId, $stripeDisputeId, $stripeStatus, $lastEventAt,
            ]);
            $currentDispute = $pdo->prepare(
                'SELECT stripe_status FROM disputes WHERE stripe_dispute_id = ?'
            );
            $currentDispute->execute([$stripeDisputeId]);
            $currentStatus = $currentDispute->fetchColumn();
            $pdo->prepare('UPDATE payments SET dispute_status = ? WHERE payment_id = ?')
                ->execute([is_string($currentStatus) ? $currentStatus : $stripeStatus, $paymentId]);
            $existingReview = $pdo->prepare(
                "SELECT review_case_id FROM review_cases
                 WHERE subject_type = 'dispute' AND subject_id = ? AND reason = 'stripe_dispute'
                 LIMIT 1"
            );
            $existingReview->execute([$stripeDisputeId]);
            if ($existingReview->fetchColumn() === false) {
                $pdo->prepare(
                    'INSERT INTO review_cases
                        (review_case_id, subject_type, subject_id, reason, status, details, opened_at)
                     VALUES (?, \'dispute\', ?, \'stripe_dispute\', \'open\', ?, UTC_TIMESTAMP(6))'
                )->execute([
                    carmaja_commerce_new_id(), $stripeDisputeId,
                    json_encode(['stripeStatus' => $stripeStatus, 'paymentId' => $paymentId], JSON_THROW_ON_ERROR),
                ]);
            }
        });
    }

    public function completeWebhook(int $inboxId, bool $success, ?string $error = null): void
    {
        if ($success) {
            $statement = $this->pdo->prepare(
                "UPDATE webhook_inbox SET status = 'processed', processed_at = UTC_TIMESTAMP(6),
                        lease_until = NULL, last_error = NULL WHERE inbox_id = ?"
            );
            $statement->execute([$inboxId]);
            return;
        }

        $attempt = $this->pdo->prepare('SELECT attempt_count FROM webhook_inbox WHERE inbox_id = ?');
        $attempt->execute([$inboxId]);
        $count = (int) $attempt->fetchColumn();
        $delay = carmaja_commerce_retry_schedule($count - 1);
        if ($delay === null) {
            $statement = $this->pdo->prepare(
                "UPDATE webhook_inbox SET status = 'manual_review', lease_until = NULL,
                        last_error = ? WHERE inbox_id = ?"
            );
            $statement->execute([$error, $inboxId]);
            return;
        }
        $statement = $this->pdo->prepare(
            "UPDATE webhook_inbox SET status = 'queued', lease_until = NULL,
                    next_attempt_at = DATE_ADD(UTC_TIMESTAMP(6), INTERVAL ? SECOND),
                    last_error = ? WHERE inbox_id = ?"
        );
        $statement->execute([$delay, $error, $inboxId]);
    }

    public function claimMetadataOutbox(int $batchSize, int $leaseSeconds = 600): array
    {
        $batchSize = max(1, min(20, $batchSize));
        return $this->transaction(function (PDO $pdo) use ($batchSize, $leaseSeconds): array {
            $rows = $pdo->query(
                "SELECT metadata_id, payment_id, stripe_payment_intent_id,
                        metadata_payload, attempt_count
                 FROM stripe_metadata_outbox
                 WHERE next_attempt_at <= UTC_TIMESTAMP(6)
                   AND (status = 'queued' OR (status = 'processing' AND lease_until < UTC_TIMESTAMP(6)))
                   AND attempt_count <= 5
                 ORDER BY metadata_id
                 LIMIT {$batchSize} FOR UPDATE SKIP LOCKED"
            )->fetchAll(PDO::FETCH_ASSOC);
            $update = $pdo->prepare(
                "UPDATE stripe_metadata_outbox SET status = 'processing', attempt_count = attempt_count + 1,
                        lease_until = DATE_ADD(UTC_TIMESTAMP(6), INTERVAL ? SECOND)
                 WHERE metadata_id = ?"
            );
            foreach ($rows as $row) {
                $update->execute([$leaseSeconds, $row['metadata_id']]);
            }
            return $rows;
        });
    }

    public function completeMetadataOutbox(int $metadataId, bool $success, ?string $error = null): void
    {
        if ($success) {
            $statement = $this->pdo->prepare(
                "UPDATE stripe_metadata_outbox SET status = 'sent', lease_until = NULL,
                        last_error = NULL WHERE metadata_id = ?"
            );
            $statement->execute([$metadataId]);
            return;
        }
        $attempt = $this->pdo->prepare('SELECT attempt_count FROM stripe_metadata_outbox WHERE metadata_id = ?');
        $attempt->execute([$metadataId]);
        $count = (int) $attempt->fetchColumn();
        $delay = carmaja_commerce_retry_schedule($count - 1);
        $status = $delay === null ? 'manual_review' : 'queued';
        if ($delay === null) {
            $statement = $this->pdo->prepare(
                "UPDATE stripe_metadata_outbox SET status = 'manual_review', lease_until = NULL,
                        last_error = ? WHERE metadata_id = ?"
            );
            $statement->execute([$error, $metadataId]);
            return;
        }
        $statement = $this->pdo->prepare(
            "UPDATE stripe_metadata_outbox SET status = ?, lease_until = NULL,
                    next_attempt_at = DATE_ADD(UTC_TIMESTAMP(6), INTERVAL ? SECOND),
                    last_error = ? WHERE metadata_id = ?"
        );
        $statement->execute([$status, $delay, $error, $metadataId]);
    }

    public function claimMailOutbox(int $batchSize, int $leaseSeconds = 600): array
    {
        $batchSize = max(1, min(20, $batchSize));
        return $this->transaction(function (PDO $pdo) use ($batchSize, $leaseSeconds): array {
            $rows = $pdo->query(
                "SELECT mail_id, dedupe_key, message_type, order_id, recipient, payload, attempt_count
                 FROM mail_outbox
                 WHERE next_attempt_at <= UTC_TIMESTAMP(6)
                   AND (status = 'queued' OR (status = 'processing' AND lease_until < UTC_TIMESTAMP(6)))
                   AND attempt_count <= 5
                 ORDER BY mail_id
                 LIMIT {$batchSize} FOR UPDATE SKIP LOCKED"
            )->fetchAll(PDO::FETCH_ASSOC);
            $update = $pdo->prepare(
                "UPDATE mail_outbox SET status = 'processing', attempt_count = attempt_count + 1,
                        lease_until = DATE_ADD(UTC_TIMESTAMP(6), INTERVAL ? SECOND)
                 WHERE mail_id = ?"
            );
            foreach ($rows as $row) {
                $update->execute([$leaseSeconds, $row['mail_id']]);
            }
            return $rows;
        });
    }

    /**
     * Complete a Brevo attempt. `delivery_unknown` is deliberately terminal
     * for automatic retries: a manual operator action must resolve ambiguity.
     */
    public function completeMailOutbox(
        int $mailId,
        string $outcome,
        ?string $brevoMessageId = null,
        ?string $error = null
    ): void {
        if ($outcome === 'sent') {
            $this->pdo->prepare(
                "UPDATE mail_outbox SET status = 'sent', brevo_message_id = ?,
                        lease_until = NULL, last_error = NULL, sent_at = UTC_TIMESTAMP(6)
                 WHERE mail_id = ?"
            )->execute([$brevoMessageId, $mailId]);
            return;
        }
        if ($outcome === 'delivery_unknown') {
            $this->pdo->prepare(
                "UPDATE mail_outbox SET status = 'delivery_unknown', lease_until = NULL,
                        last_error = ? WHERE mail_id = ?"
            )->execute([$error, $mailId]);
            return;
        }
        $attempt = $this->pdo->prepare('SELECT attempt_count FROM mail_outbox WHERE mail_id = ?');
        $attempt->execute([$mailId]);
        $count = (int) $attempt->fetchColumn();
        $delay = carmaja_commerce_retry_schedule($count - 1);
        if ($delay === null) {
            $this->pdo->prepare(
                "UPDATE mail_outbox SET status = 'manual_review', lease_until = NULL,
                        last_error = ? WHERE mail_id = ?"
            )->execute([$error, $mailId]);
            return;
        }
        $this->pdo->prepare(
            "UPDATE mail_outbox SET status = 'queued', lease_until = NULL,
                    next_attempt_at = DATE_ADD(UTC_TIMESTAMP(6), INTERVAL ? SECOND),
                    last_error = ? WHERE mail_id = ?"
        )->execute([$delay, $error, $mailId]);
    }

    public function claimWorkerLease(string $workerName, string $leaseToken, int $leaseSeconds = 600): bool
    {
        return $this->transaction(function (PDO $pdo) use ($workerName, $leaseToken, $leaseSeconds): bool {
            $select = $pdo->prepare(
                'SELECT lease_until, (lease_until IS NOT NULL AND lease_until > UTC_TIMESTAMP(6)) AS lease_active
                 FROM worker_leases WHERE worker_name = ? FOR UPDATE'
            );
            $select->execute([$workerName]);
            $current = $select->fetch(PDO::FETCH_ASSOC);
            if (is_array($current) && (int) ($current['lease_active'] ?? 0) === 1) {
                return false;
            }
            $statement = $pdo->prepare(
                'INSERT INTO worker_leases (worker_name, lease_token, lease_until, last_started_at)
                 VALUES (?, ?, DATE_ADD(UTC_TIMESTAMP(6), INTERVAL ? SECOND), UTC_TIMESTAMP(6))
                 ON DUPLICATE KEY UPDATE lease_token = VALUES(lease_token),
                    lease_until = VALUES(lease_until), last_started_at = VALUES(last_started_at)'
            );
            $statement->execute([$workerName, $leaseToken, $leaseSeconds]);
            return true;
        });
    }

    public function releaseWorkerLease(string $workerName, string $leaseToken, bool $success, ?string $error = null): void
    {
        $statement = $this->pdo->prepare(
            'UPDATE worker_leases SET lease_token = NULL, lease_until = NULL,
                last_finished_at = UTC_TIMESTAMP(6),
                last_success_at = IF(? = 1, UTC_TIMESTAMP(6), last_success_at),
                last_error = ? WHERE worker_name = ? AND lease_token = ?'
        );
        $statement->execute([$success ? 1 : 0, $error, $workerName, $leaseToken]);
    }

    /** Read-only production health snapshot. No row or global lock is acquired. */
    public function productionMonitorSnapshot(): array
    {
        $workers = $this->pdo->query(
            "SELECT worker_name, last_success_at, last_error,
                    CASE WHEN last_success_at IS NULL THEN NULL
                         ELSE TIMESTAMPDIFF(SECOND, last_success_at, UTC_TIMESTAMP(6)) END
                         AS success_age_seconds
             FROM worker_leases
             WHERE worker_name IN ('commerce-v1', 'commerce-v1-brevo')"
        )->fetchAll(PDO::FETCH_ASSOC);
        $count = function (string $sql): int {
            return (int) $this->pdo->query($sql)->fetchColumn();
        };
        $overdue = "(
            (status = 'queued' AND next_attempt_at <= DATE_SUB(UTC_TIMESTAMP(6), INTERVAL 15 MINUTE))
            OR (status = 'processing' AND (lease_until IS NULL
                OR lease_until <= DATE_SUB(UTC_TIMESTAMP(6), INTERVAL 15 MINUTE)))
        )";

        return [
            'observedAt' => (string) $this->pdo->query('SELECT UTC_TIMESTAMP(6)')->fetchColumn(),
            'workers' => is_array($workers) ? $workers : [],
            'webhookDue' => $count("SELECT COUNT(*) FROM webhook_inbox WHERE {$overdue}"),
            'webhookTerminal' => $count(
                "SELECT COUNT(*) FROM webhook_inbox WHERE status IN ('manual_review', 'failed')"
            ),
            'mailDue' => $count("SELECT COUNT(*) FROM mail_outbox WHERE {$overdue}"),
            'mailTerminal' => $count(
                "SELECT COUNT(*) FROM mail_outbox
                 WHERE status IN ('delivery_unknown', 'manual_review', 'failed')"
            ),
            'metadataDue' => $count("SELECT COUNT(*) FROM stripe_metadata_outbox WHERE {$overdue}"),
            'metadataTerminal' => $count(
                "SELECT COUNT(*) FROM stripe_metadata_outbox WHERE status IN ('manual_review', 'failed')"
            ),
            'reviewOpen' => $count(
                "SELECT COUNT(*) FROM review_cases WHERE status IN ('open', 'investigating')"
            ),
        ];
    }

    /* AP5 shop-admin repository methods. They never perform network I/O. */
    public function loadAdminUser(string $username): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT admin_id, username, password_hash, enabled FROM admin_users WHERE username = ?'
        );
        $statement->execute([$username]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    public function createAdminUser(string $adminId, string $username, string $passwordHash): void
    {
        $this->pdo->prepare(
            'INSERT INTO admin_users (admin_id, username, password_hash, password_changed_at)
             VALUES (?, ?, ?, UTC_TIMESTAMP(6))'
        )->execute([$adminId, $username, $passwordHash]);
    }

    public function updateAdminPassword(string $username, string $passwordHash): void
    {
        $statement = $this->pdo->prepare(
            'UPDATE admin_users SET password_hash = ?, password_changed_at = UTC_TIMESTAMP(6)
             WHERE username = ?'
        );
        $statement->execute([$passwordHash, $username]);
        if ($statement->rowCount() !== 1) {
            throw new CarmajaCommerceException('admin_user_not_found', 'Admin-Konto fehlt.', 404);
        }
    }

    public function revokeAdminSessions(string $adminId): int
    {
        $statement = $this->pdo->prepare(
            'UPDATE admin_sessions SET revoked_at = UTC_TIMESTAMP(6)
             WHERE admin_id = ? AND revoked_at IS NULL'
        );
        $statement->execute([$adminId]);
        return $statement->rowCount();
    }

    public function loadAdminLoginAttempt(string $attemptKeyHash): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT attempt_key_hash, window_started_at, failed_count, locked_until
             FROM admin_login_attempts WHERE attempt_key_hash = ?'
        );
        $statement->execute([$attemptKeyHash]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    public function recordAdminLoginFailure(
        string $attemptKeyHash,
        int $windowSeconds,
        int $maxFailures
    ): void {
        $this->transaction(function (PDO $pdo) use ($attemptKeyHash, $windowSeconds, $maxFailures): void {
            $select = $pdo->prepare(
                'SELECT window_started_at, failed_count FROM admin_login_attempts
                 WHERE attempt_key_hash = ? FOR UPDATE'
            );
            $select->execute([$attemptKeyHash]);
            $row = $select->fetch(PDO::FETCH_ASSOC);
            $expired = !is_array($row)
                || strtotime((string) $row['window_started_at']) + $windowSeconds <= time();
            $count = $expired ? 1 : ((int) $row['failed_count'] + 1);
            $locked = $count >= $maxFailures ? gmdate('Y-m-d H:i:s', time() + $windowSeconds) : null;
            if ($expired) {
                $pdo->prepare(
                    'INSERT INTO admin_login_attempts
                         (attempt_key_hash, window_started_at, failed_count, locked_until)
                     VALUES (?, UTC_TIMESTAMP(6), ?, ?)'
                )->execute([$attemptKeyHash, $count, $locked]);
            } else {
                $pdo->prepare(
                    'UPDATE admin_login_attempts SET failed_count = ?, locked_until = ?
                     WHERE attempt_key_hash = ?'
                )->execute([$count, $locked, $attemptKeyHash]);
            }
        });
    }

    public function clearAdminLoginFailures(string $attemptKeyHash): void
    {
        $this->pdo->prepare('DELETE FROM admin_login_attempts WHERE attempt_key_hash = ?')
            ->execute([$attemptKeyHash]);
    }

    public function createAdminSession(
        string $sessionHash,
        string $adminId,
        string $csrfHash,
        string $expiresAt
    ): void {
        $this->pdo->prepare(
            'INSERT INTO admin_sessions
                (session_hash, admin_id, csrf_hash, expires_at, last_seen_at)
             VALUES (?, ?, ?, ?, UTC_TIMESTAMP(6))'
        )->execute([$sessionHash, $adminId, $csrfHash, $expiresAt]);
    }

    public function loadAdminSession(string $sessionHash): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT s.session_hash, s.admin_id, s.csrf_hash, s.expires_at,
                    s.last_seen_at, s.revoked_at, u.username, u.enabled
             FROM admin_sessions s JOIN admin_users u ON u.admin_id = s.admin_id
             WHERE s.session_hash = ?'
        );
        $statement->execute([$sessionHash]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        return is_array($row) && (int) ($row['enabled'] ?? 0) === 1 ? $row : null;
    }

    public function touchAdminSession(string $sessionHash): void
    {
        $this->pdo->prepare(
            'UPDATE admin_sessions SET last_seen_at = UTC_TIMESTAMP(6)
             WHERE session_hash = ? AND revoked_at IS NULL'
        )->execute([$sessionHash]);
    }

    public function revokeAdminSession(string $sessionHash): void
    {
        $this->pdo->prepare(
            'UPDATE admin_sessions SET revoked_at = UTC_TIMESTAMP(6)
             WHERE session_hash = ? AND revoked_at IS NULL'
        )->execute([$sessionHash]);
    }

    public function addAdminAudit(
        ?string $adminId,
        string $action,
        string $subjectType,
        string $subjectId,
        string $correlationId,
        array $details = []
    ): void {
        $this->pdo->prepare(
            'INSERT INTO admin_audit_events
                (admin_id, action, subject_type, subject_id, correlation_id, details)
             VALUES (?, ?, ?, ?, ?, ?)'
        )->execute([
            $adminId,
            $action,
            $subjectType,
            $subjectId,
            $correlationId,
            json_encode($details, JSON_THROW_ON_ERROR),
        ]);
    }

    public function listAdminOrders(int $limit = 50, int $offset = 0): array
    {
        $limit = max(1, min(100, $limit));
        $offset = max(0, $offset);
        $statement = $this->pdo->query(
            "SELECT o.order_id, o.order_number, o.status, o.customer_email,
                    o.customer_name, o.confirmed_at, p.status AS payment_status,
                    p.payment_method_type,
                    p.verification_status, p.refund_status, p.dispute_status,
                    s.status AS shipment_status, s.tracking_number
             FROM orders o
             JOIN payments p ON p.payment_id = o.payment_id
             LEFT JOIN shipments s ON s.order_id = o.order_id
             ORDER BY o.confirmed_at DESC LIMIT {$limit} OFFSET {$offset}"
        );
        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    public function loadAdminOrder(string $orderId): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT o.*, p.status AS payment_status, p.payment_method_type,
                    p.verification_status,
                    p.refund_status, p.dispute_status,
                    s.status AS shipment_status, s.tracking_number, s.shipped_at,
                    s.delivered_at
             FROM orders o JOIN payments p ON p.payment_id = o.payment_id
             LEFT JOIN shipments s ON s.order_id = o.order_id
             WHERE o.order_id = ?'
        );
        $statement->execute([$orderId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return null;
        }
        $items = $this->pdo->prepare('SELECT * FROM order_items WHERE order_id = ? ORDER BY position_no');
        $items->execute([$orderId]);
        $row['items'] = $items->fetchAll(PDO::FETCH_ASSOC);
        return $row;
    }

    public function listAdminPayments(int $limit = 50, int $offset = 0): array
    {
        $limit = max(1, min(100, $limit));
        $offset = max(0, $offset);
        $statement = $this->pdo->query(
            "SELECT p.payment_id, p.checkout_id, p.order_id, p.payment_method_type,
                    p.status AS payment_status, p.verification_status,
                    p.refund_status, p.dispute_status, p.amount_minor, p.currency,
                    c.state AS checkout_state, c.product_id, c.created_at
             FROM payments p JOIN checkout_sagas c ON c.checkout_id = p.checkout_id
             ORDER BY c.created_at DESC LIMIT {$limit} OFFSET {$offset}"
        );
        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    public function markAdminShipmentShipped(
        string $orderId,
        string $trackingNumber,
        string $adminId,
        string $correlationId
    ): array {
        return $this->transaction(function (PDO $pdo) use ($orderId, $trackingNumber, $adminId, $correlationId): array {
            $shipment = $pdo->prepare(
                'SELECT s.shipment_id, s.status, o.customer_email, o.order_number
                 FROM shipments s JOIN orders o ON o.order_id = s.order_id
                 WHERE s.order_id = ? FOR UPDATE'
            );
            $shipment->execute([$orderId]);
            $row = $shipment->fetch(PDO::FETCH_ASSOC);
            if (!is_array($row)) {
                throw new CarmajaCommerceException('shipment_not_found', 'Versanddatensatz fehlt.', 404);
            }
            if (!in_array((string) $row['status'], ['ready', 'on_hold'], true)) {
                throw new CarmajaCommerceException('shipment_transition_invalid', 'Versandstatus kann nicht geändert werden.', 409);
            }
            $pdo->prepare(
                "UPDATE shipments SET status = 'shipped', tracking_number = ?, shipped_at = UTC_TIMESTAMP(6)
                 WHERE shipment_id = ?"
            )->execute([$trackingNumber !== '' ? $trackingNumber : null, $row['shipment_id']]);
            $pdo->prepare(
                'INSERT INTO mail_outbox
                    (dedupe_key, message_type, order_id, recipient, payload, status, next_attempt_at)
                 VALUES (?, \'shipping_confirmation\', ?, ?, ?, \'queued\', UTC_TIMESTAMP(6))
                 ON DUPLICATE KEY UPDATE dedupe_key = dedupe_key'
            )->execute([
                'shipping-confirmation:' . $orderId,
                $orderId,
                $row['customer_email'],
                json_encode(['orderNumber' => $row['order_number'], 'trackingNumber' => $trackingNumber], JSON_THROW_ON_ERROR),
            ]);
            $this->addAdminAudit($adminId, 'shipment_marked_shipped', 'order', $orderId, $correlationId, [
                'trackingPresent' => $trackingNumber !== '',
            ]);
            return ['orderId' => $orderId, 'status' => 'shipped'];
        });
    }

    public function queueAdminMailResend(
        int $mailId,
        string $adminId,
        string $correlationId
    ): array {
        return $this->transaction(function (PDO $pdo) use ($mailId, $adminId, $correlationId): array {
            $select = $pdo->prepare('SELECT * FROM mail_outbox WHERE mail_id = ? FOR UPDATE');
            $select->execute([$mailId]);
            $row = $select->fetch(PDO::FETCH_ASSOC);
            if (!is_array($row)) {
                throw new CarmajaCommerceException('mail_not_found', 'Mail-Outboxeintrag fehlt.', 404);
            }
            $dedupeKey = 'manual-resend:' . $mailId . ':' . carmaja_commerce_new_id();
            $pdo->prepare(
                'INSERT INTO mail_outbox
                    (dedupe_key, message_type, order_id, recipient, payload, status, next_attempt_at)
                 VALUES (?, ?, ?, ?, ?, \'queued\', UTC_TIMESTAMP(6))'
            )->execute([$dedupeKey, $row['message_type'], $row['order_id'], $row['recipient'], $row['payload']]);
            $newMailId = (int) $pdo->lastInsertId();
            $this->addAdminAudit($adminId, 'mail_manual_resend_queued', 'mail', (string) $mailId, $correlationId, [
                'newDedupeKey' => $dedupeKey,
            ]);
            return ['mailId' => $newMailId, 'status' => 'queued'];
        });
    }

    public function listAdminRefunds(int $limit = 100): array
    {
        $limit = max(1, min(100, $limit));
        return $this->pdo->query(
            "SELECT r.refund_id, r.stripe_refund_id, r.status, r.amount_minor, r.currency,
                    r.updated_at, p.payment_id, p.order_id
             FROM refunds r JOIN payments p ON p.payment_id = r.payment_id
             ORDER BY r.updated_at DESC LIMIT {$limit}"
        )->fetchAll(PDO::FETCH_ASSOC);
    }

    public function listAdminMailOutbox(int $limit = 100): array
    {
        $limit = max(1, min(100, $limit));
        return $this->pdo->query(
            "SELECT mail_id, dedupe_key, message_type, order_id, recipient, status,
                    attempt_count, next_attempt_at, lease_until, brevo_message_id,
                    last_error, sent_at, updated_at
             FROM mail_outbox ORDER BY updated_at DESC LIMIT {$limit}"
        )->fetchAll(PDO::FETCH_ASSOC);
    }

    public function listAdminReviewCases(int $limit = 100): array
    {
        $limit = max(1, min(100, $limit));
        return $this->pdo->query(
            "SELECT review_case_id, subject_type, subject_id, reason, status,
                    details, opened_at, resolved_at, resolved_by
             FROM review_cases ORDER BY opened_at DESC LIMIT {$limit}"
        )->fetchAll(PDO::FETCH_ASSOC);
    }

    public function updateAdminReviewCase(
        string $reviewCaseId,
        string $status,
        string $adminId,
        string $correlationId
    ): array {
        if (!in_array($status, ['investigating', 'resolved', 'closed'], true)) {
            throw new CarmajaCommerceException('review_status_invalid', 'Reviewstatus ist ungültig.', 422);
        }
        return $this->transaction(function (PDO $pdo) use ($reviewCaseId, $status, $adminId, $correlationId): array {
            $select = $pdo->prepare('SELECT status FROM review_cases WHERE review_case_id = ? FOR UPDATE');
            $select->execute([$reviewCaseId]);
            $current = $select->fetchColumn();
            if (!is_string($current)) {
                throw new CarmajaCommerceException('review_not_found', 'Reviewcase fehlt.', 404);
            }
            $order = ['open' => 0, 'investigating' => 1, 'resolved' => 2, 'closed' => 3];
            if (($order[$status] ?? -1) < ($order[$current] ?? 99)) {
                throw new CarmajaCommerceException('review_transition_invalid', 'Reviewcase darf nicht zurückgesetzt werden.', 409);
            }
            $pdo->prepare(
                'UPDATE review_cases SET status = ?, resolved_at = IF(? IN (\'resolved\', \'closed\'), UTC_TIMESTAMP(6), resolved_at),
                        resolved_by = IF(? IN (\'resolved\', \'closed\'), ?, resolved_by)
                 WHERE review_case_id = ?'
            )->execute([$status, $status, $status, $adminId, $reviewCaseId]);
            $this->addAdminAudit($adminId, 'review_case_status_changed', 'review_case', $reviewCaseId, $correlationId, [
                'from' => $current,
                'to' => $status,
            ]);
            return ['reviewCaseId' => $reviewCaseId, 'status' => $status];
        });
    }

    public function reviewAdminWithdrawal(
        string $withdrawalId,
        string $adminId,
        string $correlationId
    ): array {
        return $this->transaction(function (PDO $pdo) use ($withdrawalId, $adminId, $correlationId): array {
            $select = $pdo->prepare('SELECT state, match_status FROM withdrawal_requests WHERE withdrawal_id = ? FOR UPDATE');
            $select->execute([$withdrawalId]);
            $row = $select->fetch(PDO::FETCH_ASSOC);
            if (!is_array($row)) {
                throw new CarmajaCommerceException('withdrawal_not_found', 'Widerruf fehlt.', 404);
            }
            if ((string) $row['state'] !== 'submitted') {
                throw new CarmajaCommerceException('withdrawal_transition_invalid', 'Widerruf ist nicht prüfbar.', 409);
            }
            $pdo->prepare("UPDATE withdrawal_requests SET state = 'reviewed' WHERE withdrawal_id = ?")
                ->execute([$withdrawalId]);
            $this->addAdminAudit($adminId, 'withdrawal_reviewed', 'withdrawal', $withdrawalId, $correlationId, [
                'matchStatus' => $row['match_status'],
            ]);
            return ['withdrawalId' => $withdrawalId, 'state' => 'reviewed'];
        });
    }

    public function confirmAdminRestock(
        string $orderId,
        string $adminId,
        string $correlationId,
        string $idempotencyKey
    ): array {
        return $this->transaction(function (PDO $pdo) use ($orderId, $adminId, $correlationId, $idempotencyKey): array {
            $existing = $pdo->prepare('SELECT * FROM restocks WHERE order_id = ? FOR UPDATE');
            $existing->execute([$orderId]);
            $restock = $existing->fetch(PDO::FETCH_ASSOC);
            if (is_array($restock) && $restock['state'] === 'completed') {
                return ['orderId' => $orderId, 'state' => 'completed'];
            }
            $query = $pdo->prepare(
                "SELECT o.order_id, oi.product_id, s.status AS shipment_status,
                        w.state AS withdrawal_state
                 FROM orders o JOIN order_items oi ON oi.order_id = o.order_id
                 JOIN shipments s ON s.order_id = o.order_id
                 LEFT JOIN withdrawal_requests w ON w.order_id = o.order_id
                 WHERE o.order_id = ? FOR UPDATE"
            );
            $query->execute([$orderId]);
            $row = $query->fetch(PDO::FETCH_ASSOC);
            if (!is_array($row) || $row['shipment_status'] !== 'returned'
                || !in_array((string) ($row['withdrawal_state'] ?? ''), ['reviewed', 'closed'], true)) {
                throw new CarmajaCommerceException('restock_not_ready', 'Rückgabe ist noch nicht für Wiedereinlagerung freigegeben.', 409);
            }
            $inventory = $pdo->prepare('SELECT on_hand, inventory_version FROM commerce_inventory WHERE product_id = ? FOR UPDATE');
            $inventory->execute([$row['product_id']]);
            $current = $inventory->fetch(PDO::FETCH_ASSOC);
            if (!is_array($current) || (int) $current['on_hand'] !== 0) {
                throw new CarmajaCommerceException('restock_inventory_conflict', 'Bestand ist nicht eindeutig wiedereinlagerbar.', 409);
            }
            $next = (int) $current['inventory_version'] + 1;
            $pdo->prepare('UPDATE commerce_inventory SET on_hand = 1, inventory_version = ? WHERE product_id = ?')
                ->execute([$next, $row['product_id']]);
            $pdo->prepare(
                'INSERT INTO inventory_adjustments
                    (product_id, target_on_hand, previous_on_hand, inventory_version, reason,
                     correlation_id, idempotency_key, actor_id)
                 VALUES (?, 1, 0, ?, \'release_return\', ?, ?, ?)'
            )->execute([$row['product_id'], $next, $correlationId, $idempotencyKey, $adminId]);
            if (is_array($restock)) {
                $pdo->prepare("UPDATE restocks SET state = 'completed', completed_at = UTC_TIMESTAMP(6) WHERE restock_id = ?")
                    ->execute([$restock['restock_id']]);
            } else {
                $pdo->prepare(
                    "INSERT INTO restocks
                        (restock_id, order_id, product_id, state, reason, audit_correlation_id, completed_at)
                     VALUES (?, ?, ?, 'completed', 'release_return', ?, UTC_TIMESTAMP(6))"
                )->execute([carmaja_commerce_new_id(), $orderId, $row['product_id'], $correlationId]);
            }
            $this->addAdminAudit($adminId, 'restock_confirmed', 'order', $orderId, $correlationId, [
                'productId' => $row['product_id'],
            ]);
            return ['orderId' => $orderId, 'state' => 'completed', 'inventoryVersion' => $next];
        });
    }
}
