<?php

declare(strict_types=1);

require_once __DIR__ . '/cart-contract.php';

/** Enabled only by the separate inventoryCartEnabled runtime gate. */
final class CarmajaCartCommerce extends CarmajaCommercePdo
{
    private bool $verifiedReturn = false;
    public function transaction(callable $callback): mixed
    {
        if ($this->pdo->inTransaction()) { return $callback($this->pdo); }
        // Retry only complete rolled-back database transactions; never a partial
        // mutation or a provider call. Nested work belongs to the outer retry.
        for ($attempt = 0; ; $attempt++) {
            try { return parent::transaction($callback); }
            catch (PDOException $error) {
                $driverCode = (int) ($error->errorInfo[1] ?? 0);
                if ($attempt >= 3 || !in_array($driverCode, [1205, 1213], true)) { throw $error; }
                usleep(random_int(10000, 50000) * (1 << $attempt));
            }
        }
    }
    private function row(string $sql, array $args = []): ?array
    {
        $statement = $this->pdo->prepare($sql);
        $statement->execute($args);
        return $statement->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    private function execute(string $sql, array $args = []): void
    {
        $this->pdo->prepare($sql)->execute($args);
    }

    private function rows(string $sql, array $args = []): array
    {
        $statement = $this->pdo->prepare($sql);
        $statement->execute($args);
        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    private function assertSession(string $owner): void
    {
        if ($this->row('SELECT session_hash FROM shop_sessions WHERE session_hash = ? AND session_expires_at > UTC_TIMESTAMP(6)', [$owner]) === null) {
            throw new CarmajaCommerceException('shop_session_expired', 'Shop-Sitzung ist abgelaufen.', 403);
        }
    }

    public function allowCartRequest(string $owner, string $ip): bool
    {
        return $this->transaction(function () use ($owner, $ip): bool {
            $allowed = true;
            foreach (['session|' . $owner => 60, 'ip|' . $ip => 120] as $identity => $limit) {
                $bucket = hash('sha256', $identity);
                $slot = intdiv(time(), 60);
                $this->execute('INSERT INTO cart_rate_limits VALUES (?,?,1) ON DUPLICATE KEY UPDATE attempts=attempts+1', [$bucket, $slot]);
                $count = $this->row('SELECT attempts FROM cart_rate_limits WHERE bucket_hash=? AND minute_slot=? FOR UPDATE', [$bucket, $slot]);
                $allowed = $allowed && (int) $count['attempts'] <= $limit;
            }
            return $allowed;
        });
    }

    public function cleanupExpiredCarts(): void
    {
        // Never remove reservations, payments, or order evidence through session cleanup.
        $this->execute('DELETE FROM cart_quotes WHERE expires_at < UTC_TIMESTAMP(6) LIMIT 500');
        $this->execute('DELETE FROM cart_operations WHERE created_at < UTC_TIMESTAMP(6) - INTERVAL 24 HOUR LIMIT 500');
        $this->execute('DELETE FROM cart_rate_limits WHERE minute_slot < ? LIMIT 500', [intdiv(time(), 60) - 5]);
        $this->execute('DELETE c FROM shop_carts c JOIN shop_sessions s ON s.session_hash=c.session_hash WHERE s.session_expires_at < UTC_TIMESTAMP(6)');
    }

    public function inventory(string $productId, bool $lock = false): array
    {
        $row = $this->row('SELECT * FROM commerce_inventory WHERE product_id = ?' . ($lock ? ' FOR UPDATE' : ''), [$productId]);
        if ($row === null) {
            throw new CarmajaCommerceException('inventory_unconfirmed', 'Bestand wurde noch nicht bestätigt.', 409);
        }
        // A current locking read is essential under InnoDB REPEATABLE READ:
        // an earlier snapshot must not hide another checkout's committed reservation.
        $reservations = $this->rows("SELECT quantity FROM reservations
            WHERE product_id = ? AND blocks_stock = 1 AND state IN ('creating','active','expired','manual_review')"
            . ($lock ? ' FOR UPDATE' : ''), [$productId]);
        $reserved = array_sum(array_map(static fn ($item) => (int) $item['quantity'], $reservations));
        return ['productId' => $productId, 'onHand' => (int) $row['on_hand'],
            'reserved' => $reserved, 'available' => (bool) $row['quantity_confirmed'] ? (int) $row['on_hand'] - $reserved : 0,
            'quantityConfirmed' => (bool) $row['quantity_confirmed'],
            'inventoryVersion' => (int) $row['inventory_version']];
    }

    public function loadLiveProduct(string $productId): array
    {
        $product = $this->row('SELECT * FROM commerce_products WHERE product_id = ?', [$productId]);
        if ($product === null) {
            throw new CarmajaCommerceException('product_not_found', 'Produkt nicht gefunden.', 404);
        }
        try { $inventory = $this->inventory($productId); }
        catch (CarmajaCommerceException $error) {
            if ($error->errorCode !== 'inventory_unconfirmed') { throw $error; }
            $inventory = ['available' => 0, 'inventoryVersion' => 0];
        }
        return ['productId' => $productId, 'name' => $product['name'],
            'priceMinor' => (int) $product['price_minor'], 'currency' => $product['currency'],
            'productVersion' => (int) $product['product_version'],
            'available' => (int) $product['sales_enabled'] === 1 && $product['sales_model'] === 'stocked_collection' && $inventory['available'] > 0,
            'availableQuantity' => max(0, $inventory['available']),
            'inventoryVersion' => $inventory['inventoryVersion'], 'responseAt' => gmdate(DATE_ATOM)];
    }

    public function loadInventoryForManagement(string $id): array
    {
        if ($this->row('SELECT product_id FROM commerce_products WHERE product_id=?', [$id]) === null) {
            throw new CarmajaCommerceException('inventory_product_invalid', 'Produkt zuerst veröffentlichen.', 409);
        }
        try { return $this->inventory($id); }
        catch (CarmajaCommerceException $error) {
            if ($error->errorCode !== 'inventory_unconfirmed') { throw $error; }
            return ['productId' => $id, 'onHand' => 0, 'reserved' => 0, 'available' => 0, 'inventoryVersion' => 0, 'quantityConfirmed' => false];
        }
    }

    public function listInventories(): array
    {
        return array_map(fn ($row) => [...$this->loadInventoryForManagement($row['product_id']), 'name' => $row['name']],
            $this->rows("SELECT product_id,name FROM commerce_products WHERE sales_model='stocked_collection' ORDER BY product_id"));
    }

    public function readCart(string $owner): array
    {
        $this->assertSession($owner);
        $cart = $this->row('SELECT * FROM shop_carts WHERE session_hash = ?', [$owner]);
        $items = $cart === null ? [] : json_decode($cart['items'], true, 32, JSON_THROW_ON_ERROR);
        $prices = ($cart['price_reference'] ?? null) === null ? [] : json_decode($cart['price_reference'], true, 32, JSON_THROW_ON_ERROR);
        $live = [];
        $canCheckout = $items !== [];
        foreach ($items as $item) {
            try {
                $product = $this->loadLiveProduct($item['productId']);
                $valid = $product['available'] && $item['quantity'] <= $product['availableQuantity'];
                $previousPrice = $prices[$item['productId']] ?? null;
                $live[] = [...$product, 'quantity' => $item['quantity'], 'buyable' => $valid,
                    'previousPriceMinor' => $previousPrice,
                    'priceChanged' => is_int($previousPrice) && $previousPrice !== $product['priceMinor']];
                $canCheckout = $canCheckout && $valid;
            } catch (CarmajaCommerceException $error) {
                if ($error->httpStatus !== 404) { throw $error; }
                $live[] = [...$item, 'buyable' => false, 'error' => 'product_not_found'];
                $canCheckout = false;
            }
        }
        $priced = count(array_filter($live, static fn ($item) => isset($item['priceMinor']))) === count($live);
        return ['cartVersion' => (int) ($cart['cart_version'] ?? 0), 'items' => $live,
            'shippingMinor' => $items === [] ? 0 : 270,
            'totalMinor' => $items === [] ? 0 : ($priced ? carmaja_cart_total($live) : null),
            'currency' => 'eur', 'canCheckout' => $canCheckout];
    }

    public function replaceCart(string $owner, array $body, string $key): array
    {
        if (count($body) !== 2 || !isset($body['items'], $body['expectedCartVersion'])
            || !is_int($body['expectedCartVersion']) || $body['expectedCartVersion'] < 0) {
            throw new CarmajaCommerceException('cart_invalid', 'Warenkorbversion und Positionen erforderlich.', 422);
        }
        $items = carmaja_cart_items($body['items']);
        carmaja_commerce_assert_id($key, 'Idempotency-Key');
        $this->assertSession($owner);
        $this->transaction(function () use ($owner, $body, $items, $key): void {
            $this->execute("INSERT IGNORE INTO shop_carts(session_hash,items,updated_at) VALUES (?,'[]',UTC_TIMESTAMP(6))", [$owner]);
            $cart = $this->row('SELECT * FROM shop_carts WHERE session_hash = ? FOR UPDATE', [$owner]);
            $hash = carmaja_commerce_request_hash($body);
            $operation = $this->row('SELECT * FROM cart_operations WHERE session_hash = ? AND idempotency_key = ?', [$owner, $key]);
            if ($operation !== null) {
                if (!hash_equals($operation['request_hash'], $hash)) {
                    throw new CarmajaCommerceException('idempotency_conflict', 'Anfrage wurde verändert.', 409);
                }
                return;
            }
            if ((int) $cart['cart_version'] !== $body['expectedCartVersion']) {
                throw new CarmajaCommerceException('cart_version_conflict', 'Warenkorb bitte neu laden.', 409);
            }
            $prices = [];
            foreach ($items as $item) {
                $price = $this->row('SELECT price_minor FROM commerce_products WHERE product_id=?', [$item['productId']]);
                $prices[$item['productId']] = $price === null ? null : (int) $price['price_minor'];
            }
            $this->execute('UPDATE shop_carts SET items = ?, price_reference = ?, cart_version = cart_version + 1, updated_at = UTC_TIMESTAMP(6) WHERE session_hash = ?',
                [carmaja_commerce_json($items), carmaja_commerce_json($prices), $owner]);
            $this->execute('INSERT INTO cart_operations VALUES (?,?,?,?,UTC_TIMESTAMP(6))', [$owner, $key, $hash, carmaja_commerce_json(['cartVersion' => $body['expectedCartVersion'] + 1])]);
        });
        return $this->readCart($owner);
    }

    public function quoteCart(string $owner, array $shipping, string $legalBundleId): array
    {
        $cart = $this->readCart($owner);
        if (!$cart['canCheckout']) {
            throw new CarmajaCommerceException('cart_not_buyable', 'Bitte Mengen und Verfügbarkeit prüfen.', 409);
        }
        $legal = $this->loadApprovedLegalBundle($legalBundleId);
        $items = [];
        foreach ($cart['items'] as $line) {
            $product = $this->row('SELECT * FROM commerce_products WHERE product_id = ?', [$line['productId']]);
            if ($product === null || (int) $product['price_minor'] !== $line['priceMinor']
                || (int) $product['product_version'] !== $line['productVersion']) {
                throw new CarmajaCommerceException('cart_snapshot_stale', 'Preis wurde geändert. Bitte Warenkorb neu laden.', 409);
            }
            $notice = $product['notice_snapshot'] === null ? [] : json_decode($product['notice_snapshot'], true, 16, JSON_THROW_ON_ERROR);
            $display = $product['public_snapshot'] === null ? [] : json_decode($product['public_snapshot'], true, 32, JSON_THROW_ON_ERROR);
            $items[] = [...$display, 'productId' => $line['productId'], 'quantity' => $line['quantity'],
                'productVersion' => (int) $product['product_version'], 'sourceHash' => $product['source_hash'],
                'name' => $product['name'], 'priceMinor' => (int) $product['price_minor'], 'currency' => $product['currency'],
                'noticePolicyId' => $notice['noticePolicyId'] ?? null, 'notices' => $notice['notices'] ?? []];
        }
        $snapshot = ['items' => $items, 'shippingSnapshot' => $shipping,
            'legalBundleId' => $legalBundleId, 'legalBundleHash' => $legal['bundle_hash'],
            'totalMinor' => carmaja_cart_total($items, (int) $shipping['amountMinor'])];
        $token = carmaja_shop_token();
        $this->execute('INSERT INTO cart_quotes VALUES (?,?,?,?,?)', [carmaja_shop_token_hash($token), $owner,
            $cart['cartVersion'], carmaja_commerce_json($snapshot), gmdate('Y-m-d H:i:s', time() + 600)]);
        return [...$cart, 'checkoutContextToken' => $token, 'snapshot' => $snapshot];
    }

    public function reserveCart(string $owner, array $body, string $key): array
    {
        if (count($body) !== 2 || !is_int($body['expectedCartVersion'] ?? null)
            || !is_string($body['checkoutContextToken'] ?? null)) {
            throw new CarmajaCommerceException('checkout_request_invalid', 'Warenkorbkontext ist erforderlich.', 422);
        }
        carmaja_commerce_assert_id($key, 'Idempotency-Key');
        $this->assertSession($owner);
        return $this->transaction(function () use ($owner, $body, $key): array {
            $cart = $this->row('SELECT * FROM shop_carts WHERE session_hash = ? FOR UPDATE', [$owner]);
            $requestHash = carmaja_commerce_request_hash(['owner' => $owner, 'body' => $body]);
            $existing = $this->findCheckoutByIdempotency($key);
            if ($existing !== null) {
                if (($existing['session_hash'] ?? null) !== $owner || !hash_equals($existing['request_hash'], $requestHash)) {
                    throw new CarmajaCommerceException('idempotency_conflict', 'Checkout-Anfrage wurde verändert.', 409);
                }
                return ['checkout' => $existing, 'reused' => true];
            }
            if ($cart === null || (int) $cart['cart_version'] !== $body['expectedCartVersion']) {
                throw new CarmajaCommerceException('cart_version_conflict', 'Warenkorb bitte neu laden.', 409);
            }
            // Only one unresolved checkout per cart revision, even with another key.
            $open = $this->row("SELECT checkout_id FROM checkout_sagas WHERE session_hash = ? AND cart_version = ?
                AND state IN ('creating','created','stripe_open','payment_pending','manual_review') FOR UPDATE", [$owner, $body['expectedCartVersion']]);
            if ($open !== null) {
                throw new CarmajaCommerceException('checkout_in_progress', 'Für diesen Warenkorb läuft bereits eine Zahlung.', 409);
            }
            $quote = $this->row('SELECT * FROM cart_quotes WHERE token_hash = ? AND session_hash = ? AND expires_at > UTC_TIMESTAMP(6)',
                [carmaja_shop_token_hash($body['checkoutContextToken']), $owner]);
            if ($quote === null || (int) $quote['cart_version'] !== $body['expectedCartVersion']) {
                throw new CarmajaCommerceException('context_invalid', 'Bitte aktuelle Preise erneut prüfen.', 409);
            }
            $snapshot = json_decode($quote['snapshot'], true, 32, JSON_THROW_ON_ERROR);
            $legal = $this->loadApprovedLegalBundle($snapshot['legalBundleId']);
            if (!hash_equals($legal['bundle_hash'], $snapshot['legalBundleHash'])) {
                throw new CarmajaCommerceException('legal_snapshot_stale', 'Rechtstextfassung bitte erneut prüfen.', 409);
            }
            foreach ($snapshot['items'] as $line) {
                $product = $this->row('SELECT * FROM commerce_products WHERE product_id = ? FOR UPDATE', [$line['productId']]);
                $inventory = $this->inventory($line['productId'], true);
                if ($product === null || (int) $product['sales_enabled'] !== 1 || $product['sales_model'] !== 'stocked_collection'
                    || (int) $product['product_version'] !== $line['productVersion'] || $product['source_hash'] !== $line['sourceHash']
                    || (int) $product['price_minor'] !== $line['priceMinor'] || $inventory['available'] < $line['quantity']) {
                    throw new CarmajaCommerceException('cart_snapshot_stale', 'Preis oder verfügbare Menge hat sich geändert.', 409);
                }
            }
            $id = carmaja_commerce_new_id();
            $snapshot['checkoutId'] = $id;
            $snapshot['snapshotHash'] = carmaja_commerce_request_hash($snapshot);
            $expires = gmdate('Y-m-d H:i:s', time() + 1800);
            $this->execute("INSERT INTO checkout_sagas(checkout_id,idempotency_key,request_hash,price_minor,currency,shipping_snapshot,
                legal_bundle_id,sales_model,state,expires_at,checkout_kind,session_hash,cart_version,snapshot_hash,legal_bundle_hash)
                VALUES (?,?,?,?,'eur',?,?,'stocked_collection','creating',?,'cart',?,?,?,?)",
                [$id, $key, $requestHash, $snapshot['totalMinor'] - 270, carmaja_commerce_json($snapshot['shippingSnapshot']),
                 $snapshot['legalBundleId'], $expires, $owner, $body['expectedCartVersion'], $snapshot['snapshotHash'], $snapshot['legalBundleHash']]);
            foreach ($snapshot['items'] as $position => $line) {
                $this->execute('INSERT INTO checkout_items VALUES (?,?,?,?,?)', [$id, $position + 1, $line['productId'], $line['quantity'], carmaja_commerce_json($line)]);
                $this->execute("INSERT INTO reservations(reservation_id,checkout_id,product_id,quantity,state,blocks_stock,expires_at)
                    VALUES (?,?,?,?,'creating',1,?)", [carmaja_commerce_new_id(), $id, $line['productId'], $line['quantity'], $expires]);
            }
            $this->execute("INSERT INTO payments(payment_id,checkout_id,status,amount_minor,currency,verification_status,refund_status)
                VALUES (?,?,'created',?,'eur','unverified','none')", [carmaja_commerce_new_id(), $id, $snapshot['totalMinor']]);
            return ['checkout' => $this->row('SELECT * FROM checkout_sagas WHERE checkout_id = ?', [$id]), 'snapshot' => $snapshot, 'reused' => false];
        });
    }

    private function isCart(string $id): bool
    {
        return ($this->row('SELECT checkout_kind FROM checkout_sagas WHERE checkout_id = ?', [$id])['checkout_kind'] ?? '') === 'cart';
    }

    private function review(string $checkoutId, string $paymentId, string $reason): array
    {
        $this->execute("UPDATE checkout_sagas SET state = 'manual_review' WHERE checkout_id = ?", [$checkoutId]);
        $this->execute("UPDATE payments SET status = 'manual_review',verification_status = 'manual_review' WHERE payment_id = ?", [$paymentId]);
        $this->execute("UPDATE reservations SET state = 'manual_review',blocks_stock = 1 WHERE checkout_id = ?", [$checkoutId]);
        $this->openReviewCase($this->pdo, 'payment', $paymentId, $reason);
        return ['status' => 'manual_review', 'paymentId' => $paymentId];
    }

    public function recordStripeCreationOutcome(string $checkoutId, string $outcome, ?string $sessionId = null): void
    {
        if (!$this->isCart($checkoutId)) { parent::recordStripeCreationOutcome($checkoutId, $outcome, $sessionId); return; }
        $this->transaction(function () use ($checkoutId, $outcome, $sessionId): void {
            $checkout = $this->row('SELECT * FROM checkout_sagas WHERE checkout_id = ? FOR UPDATE', [$checkoutId]);
            $payment = $this->row('SELECT * FROM payments WHERE checkout_id=? FOR UPDATE', [$checkoutId]);
            if ($payment['order_id'] !== null || in_array($payment['status'], ['processing','succeeded'], true)) { return; }
            if ($outcome === 'created' && is_string($sessionId) && str_starts_with($sessionId, 'cs_')) {
                if ($payment['stripe_checkout_session_id'] !== null && $payment['stripe_checkout_session_id'] !== $sessionId) {
                    $this->review($checkoutId, $payment['payment_id'], 'stripe_session_binding_conflict'); return;
                }
                $this->execute('UPDATE payments SET stripe_checkout_session_id=? WHERE checkout_id=?', [$sessionId, $checkoutId]);
                if ($checkout['state'] === 'manual_review') { return; }
                if ($checkout['state'] !== 'creating') { return; }
                $this->execute("UPDATE payments SET status='pending' WHERE checkout_id=?", [$checkoutId]);
                $this->execute("UPDATE checkout_sagas SET state='stripe_open' WHERE checkout_id=?", [$checkoutId]);
                $this->execute("UPDATE reservations SET state='active',stripe_session_id=? WHERE checkout_id=?", [$sessionId, $checkoutId]);
            } elseif ($outcome === 'failed') {
                if ($checkout['state'] !== 'creating' || $payment['stripe_checkout_session_id'] !== null) {
                    $this->review($checkoutId, $payment['payment_id'], 'stripe_creation_outcome_conflict'); return;
                }
                $this->execute("UPDATE checkout_sagas SET state='failed' WHERE checkout_id=?", [$checkoutId]);
                $this->execute("UPDATE payments SET status='failed' WHERE checkout_id=?", [$checkoutId]);
                $this->execute("UPDATE reservations SET state='released',blocks_stock=0,released_at=UTC_TIMESTAMP(6) WHERE checkout_id=?", [$checkoutId]);
            } else {
                $payment = $this->row('SELECT payment_id FROM payments WHERE checkout_id=?', [$checkoutId]);
                $this->review($checkoutId, $payment['payment_id'], 'stripe_session_outcome_unknown');
            }
        });
    }

    public function markPaymentProcessing(string $checkoutId, array $event): array
    {
        if (!$this->isCart($checkoutId)) { return parent::markPaymentProcessing($checkoutId, $event); }
        return $this->transaction(function () use ($checkoutId, $event): array {
            $checkout = $this->row('SELECT * FROM checkout_sagas WHERE checkout_id=? FOR UPDATE', [$checkoutId]);
            $payment = $this->row('SELECT * FROM payments WHERE checkout_id=? FOR UPDATE', [$checkoutId]);
            if ($payment['order_id'] !== null) { return ['status' => 'succeeded']; }
            if (!carmaja_cart_payment_valid($checkout, $payment, $event, 'processing')) {
                return $this->review($checkoutId, $payment['payment_id'], 'cart_processing_verification_failed');
            }
            $this->execute("UPDATE payments SET status='processing',verification_status='verified',stripe_payment_intent_id=?,payment_method_type=? WHERE checkout_id=?",
                [$event['stripePaymentIntentId'], $event['paymentMethodType'], $checkoutId]);
            $this->execute("UPDATE checkout_sagas SET state='payment_pending' WHERE checkout_id=?", [$checkoutId]);
            return ['paymentId' => $payment['payment_id'], 'status' => 'processing'];
        });
    }

    public function applyRefund(string $paymentId, string $stripeRefundId, string $status, int $amountMinor): void
    {
        if (!str_starts_with($stripeRefundId, 're_') || $amountMinor < 1
            || !in_array($status, ['pending','succeeded','failed','canceled','requires_action','manual_review'], true)) {
            throw new CarmajaCommerceException('refund_invalid', 'Erstattung ungültig.', 422);
        }
        $status = in_array($status, ['canceled','requires_action'], true) ? 'manual_review' : $status;
        $this->transaction(function () use ($paymentId, $stripeRefundId, $status, $amountMinor): void {
            $payment = $this->row('SELECT * FROM payments WHERE payment_id=? FOR UPDATE', [$paymentId]);
            if ($payment === null) { throw new CarmajaCommerceException('payment_not_found', 'Zahlung fehlt.', 404); }
            $previous = $this->row('SELECT * FROM refunds WHERE stripe_refund_id=? FOR UPDATE', [$stripeRefundId]);
            if ($amountMinor > (int) $payment['amount_minor'] || ($previous !== null
                && ($previous['payment_id'] !== $paymentId || (int) $previous['amount_minor'] !== $amountMinor))) {
                $this->openReviewCase($this->pdo, 'payment', $paymentId, 'refund_amount_conflict');
                $this->execute("UPDATE payments SET refund_status='manual_review' WHERE payment_id=?", [$paymentId]);
                return;
            }
            // Terminal success cannot be downgraded by a duplicate older event.
            if (($previous['status'] ?? '') === 'succeeded') { $status = 'succeeded'; }
            $this->execute("INSERT INTO refunds(refund_id,payment_id,stripe_refund_id,status,amount_minor,currency)
                VALUES (?,?,?,?,?,'eur') ON DUPLICATE KEY UPDATE status=VALUES(status)",
                [carmaja_commerce_new_id(), $paymentId, $stripeRefundId, $status, $amountMinor]);
            $refunds = $this->rows("SELECT status,amount_minor FROM refunds WHERE payment_id=? AND LEFT(stripe_refund_id,3)='re_'", [$paymentId]);
            $sum = array_sum(array_map(static fn ($row) => $row['status'] === 'succeeded' ? (int) $row['amount_minor'] : 0, $refunds));
            $aggregate = $sum > 0 ? ($sum === (int) $payment['amount_minor'] ? 'succeeded' : 'partial') : 'none';
            foreach ($refunds as $refund) {
                if ($refund['status'] === 'pending' && $sum === 0) { $aggregate = 'pending'; }
                if ($refund['status'] === 'failed' && $aggregate === 'none') { $aggregate = 'failed'; }
                if ($refund['status'] === 'manual_review') { $aggregate = 'manual_review'; break; }
            }
            if ($sum > (int) $payment['amount_minor']) {
                $aggregate = 'manual_review';
                $this->openReviewCase($this->pdo, 'payment', $paymentId, 'refund_total_conflict');
            }
            $this->execute('UPDATE payments SET refund_status=?,refunded_minor=? WHERE payment_id=?', [$aggregate, $sum, $paymentId]);
            // Refund evidence is the audit record. Never restock or cancel orders automatically.
        });
    }

    public function releaseExpiredReservation(string $checkoutId): void
    {
        if (!$this->isCart($checkoutId)) { parent::releaseExpiredReservation($checkoutId); return; }
        $this->transaction(function () use ($checkoutId): void {
            $checkout = $this->row('SELECT * FROM checkout_sagas WHERE checkout_id=? FOR UPDATE', [$checkoutId]);
            $payment = $this->row('SELECT * FROM payments WHERE checkout_id=? FOR UPDATE', [$checkoutId]);
            // Caller must have reconciled Stripe. Never release processing or paid cases by age.
            if ($payment['order_id'] !== null || in_array($payment['status'], ['processing','succeeded','manual_review'], true)) { return; }
            if (!in_array($checkout['state'], ['stripe_open','created'], true)) { return; }
            $this->execute("UPDATE checkout_sagas SET state='expired' WHERE checkout_id=?", [$checkoutId]);
            $this->execute("UPDATE payments SET status='canceled' WHERE checkout_id=? AND status IN ('created','pending')", [$checkoutId]);
            $this->execute("UPDATE reservations SET state='released',blocks_stock=0,released_at=UTC_TIMESTAMP(6) WHERE checkout_id=?", [$checkoutId]);
        });
    }

    public function failAsyncPayment(string $checkoutId, array $event): array
    {
        if (!$this->isCart($checkoutId)) { return parent::failAsyncPayment($checkoutId, $event); }
        return $this->transaction(function () use ($checkoutId, $event): array {
            $checkout = $this->row('SELECT * FROM checkout_sagas WHERE checkout_id=? FOR UPDATE', [$checkoutId]);
            $payment = $this->row('SELECT * FROM payments WHERE checkout_id=? FOR UPDATE', [$checkoutId]);
            if ($payment['order_id'] !== null) { return ['status' => 'succeeded']; }
            if (!carmaja_cart_payment_valid($checkout, $payment, $event, 'failed')) {
                return $this->review($checkoutId, $payment['payment_id'], 'cart_failed_payment_mismatch');
            }
            $this->execute("UPDATE payments SET status='failed',verification_status='verified',stripe_payment_intent_id=?,payment_method_type=? WHERE checkout_id=?",
                [$event['stripePaymentIntentId'], $event['paymentMethodType'], $checkoutId]);
            $this->execute("UPDATE checkout_sagas SET state='failed',failure_code='async_payment_failed' WHERE checkout_id=?", [$checkoutId]);
            $this->execute("UPDATE reservations SET state='released',blocks_stock=0,released_at=UTC_TIMESTAMP(6) WHERE checkout_id=? AND (state<>'released' OR blocks_stock<>0)", [$checkoutId]);
            return ['paymentId' => $payment['payment_id'], 'status' => 'failed'];
        });
    }

    public function adjustInventory(array $input, string $actorId, bool $manualOperator = true): array
    {
        $id = $input['productId'] ?? '';
        carmaja_commerce_assert_id($id, 'productId');
        $key = $input['idempotencyKey'] ?? '';
        carmaja_commerce_assert_id($key, 'Idempotency-Key');
        $body = array_diff_key($input, ['productId' => true, 'idempotencyKey' => true]);
        carmaja_inventory_adjustment($body);
        if ($body['reason'] === 'release_return' && !$this->verifiedReturn) {
            throw new CarmajaCommerceException('return_verification_required', 'Rückgabe muss je Bestellposition geprüft werden.', 409);
        }
        return $this->transaction(function () use ($id, $key, $body, $actorId): array {
            $product = $this->row('SELECT sales_model FROM commerce_products WHERE product_id=? FOR UPDATE', [$id]);
            if ($product === null || $product['sales_model'] !== 'stocked_collection') {
                throw new CarmajaCommerceException('inventory_product_invalid', 'Bestandsprodukt fehlt.', 409);
            }
            $old = $this->row('SELECT * FROM inventory_adjustments WHERE idempotency_key=?', [$key]);
            if ($old !== null) {
                if ($old['product_id'] !== $id || $old['actor_id'] !== $actorId || $old['reason'] !== $body['reason']
                    || (int) $old['target_on_hand'] !== $body['targetOnHand'] || $old['correlation_id'] !== $body['correlationId']
                    || (int) $old['inventory_version'] !== $body['expectedInventoryVersion'] + 1) {
                    throw new CarmajaCommerceException('idempotency_conflict', 'Bestandsauftrag wurde verändert.', 409);
                }
                return $this->inventory($id);
            }
            $exists = $this->row('SELECT * FROM commerce_inventory WHERE product_id=? FOR UPDATE', [$id]);
            if ($exists === null) {
                if ($body['reason'] !== 'initial_count' || $body['expectedInventoryVersion'] !== 0) {
                    throw new CarmajaCommerceException('inventory_unconfirmed', 'Erstzählung erforderlich.', 409);
                }
                $this->execute('INSERT INTO commerce_inventory(product_id,on_hand,inventory_version,quantity_confirmed) VALUES (?,0,0,0)', [$id]);
            } elseif ($body['reason'] === 'initial_count' && (bool) $exists['quantity_confirmed']) {
                throw new CarmajaCommerceException('inventory_already_initialized', 'Bestand wurde bereits initialisiert.', 409);
            } elseif ($body['reason'] !== 'initial_count' && !(bool) $exists['quantity_confirmed']) {
                throw new CarmajaCommerceException('inventory_unconfirmed', 'Erstzählung erforderlich.', 409);
            }
            $inventory = $this->inventory($id, true);
            if ($inventory['inventoryVersion'] !== $body['expectedInventoryVersion']) {
                throw new CarmajaCommerceException('inventory_version_conflict', 'Bestand bitte neu laden.', 409);
            }
            if ($body['targetOnHand'] < $inventory['reserved']) {
                throw new CarmajaCommerceException('inventory_reserved', 'Reservierte Stücke dürfen nicht entfernt werden.', 409);
            }
            if (($body['reason'] === 'mark_unsellable' && $body['targetOnHand'] > $inventory['onHand'])
                || (in_array($body['reason'], ['receive_stock','release_return'], true) && $body['targetOnHand'] < $inventory['onHand'])) {
                throw new CarmajaCommerceException('inventory_reason_invalid', 'Grund passt nicht zur Mengenänderung.', 422);
            }
            $this->execute('UPDATE commerce_inventory SET on_hand=?,inventory_version=inventory_version+1,quantity_confirmed=1 WHERE product_id=?', [$body['targetOnHand'], $id]);
            $this->execute('INSERT INTO inventory_adjustments(product_id,target_on_hand,previous_on_hand,inventory_version,reason,correlation_id,idempotency_key,actor_id)
                VALUES (?,?,?,?,?,?,?,?)', [$id, $body['targetOnHand'], $inventory['onHand'], $inventory['inventoryVersion'] + 1, $body['reason'], $body['correlationId'], $key, $actorId]);
            return $this->inventory($id);
        });
    }

    public function finalizePayment(string $checkoutId, array $event): array
    {
        if (!$this->isCart($checkoutId)) { return parent::finalizePayment($checkoutId, $event); }
        $result = $this->transaction(function () use ($checkoutId, $event): array {
            $checkout = $this->row('SELECT * FROM checkout_sagas WHERE checkout_id=? FOR UPDATE', [$checkoutId]);
            $payment = $this->row('SELECT * FROM payments WHERE checkout_id=? FOR UPDATE', [$checkoutId]);
            if ($payment['order_id'] !== null) {
                $order = $this->row('SELECT order_number FROM orders WHERE order_id=?', [$payment['order_id']]);
                return ['status' => 'confirmed', 'orderId' => $payment['order_id'], 'orderNumber' => $order['order_number']];
            }
            if (!carmaja_cart_payment_valid($checkout, $payment, $event, 'succeeded')
                || filter_var($event['customerEmail'] ?? '', FILTER_VALIDATE_EMAIL) === false
                || !is_string($event['customerName'] ?? null) || trim($event['customerName']) === ''
                || !is_string($event['shippingAddress']['line1'] ?? null) || trim($event['shippingAddress']['line1']) === ''
                || !is_string($event['shippingAddress']['city'] ?? null) || trim($event['shippingAddress']['city']) === ''
                || preg_match('/^[0-9]{5}$/', (string) ($event['shippingAddress']['postal_code'] ?? '')) !== 1
                || ($event['shippingAddress']['country'] ?? null) !== 'DE') {
                return $this->review($checkoutId, $payment['payment_id'], 'cart_payment_verification_failed');
            }
            $lines = $this->rows('SELECT * FROM checkout_items WHERE checkout_id=? ORDER BY product_id', [$checkoutId]);
            $snapshots = [];
            foreach ($lines as $line) {
                $this->row('SELECT product_id FROM commerce_products WHERE product_id=? FOR UPDATE', [$line['product_id']]);
                $inventory = $this->inventory($line['product_id'], true);
                $reservation = $this->row('SELECT * FROM reservations WHERE checkout_id=? AND product_id=? FOR UPDATE', [$checkoutId, $line['product_id']]);
                if ($reservation === null || !in_array($reservation['state'], ['creating','active','expired','manual_review'], true)
                    || (int) $reservation['blocks_stock'] !== 1 || (int) $reservation['quantity'] !== (int) $line['quantity']
                    || $inventory['onHand'] < (int) $line['quantity'] || $inventory['available'] < 0) {
                    return $this->review($checkoutId, $payment['payment_id'], 'cart_inventory_conflict');
                }
                $snapshots[] = json_decode($line['product_snapshot'], true, 32, JSON_THROW_ON_ERROR);
            }
            if ($lines === [] || carmaja_cart_total($snapshots) !== (int) $payment['amount_minor']) {
                return $this->review($checkoutId, $payment['payment_id'], 'cart_snapshot_amount_mismatch');
            }
            $proof = ['items' => $snapshots, 'shippingSnapshot' => json_decode($checkout['shipping_snapshot'], true, 16, JSON_THROW_ON_ERROR),
                'legalBundleId' => $checkout['legal_bundle_id'], 'legalBundleHash' => $checkout['legal_bundle_hash'],
                'totalMinor' => (int) $payment['amount_minor'], 'checkoutId' => $checkoutId];
            if (!hash_equals($checkout['snapshot_hash'], carmaja_commerce_request_hash($proof))) {
                return $this->review($checkoutId, $payment['payment_id'], 'cart_snapshot_hash_mismatch');
            }
            $legal = $this->row('SELECT * FROM legal_bundles WHERE legal_bundle_id=?', [$checkout['legal_bundle_id']]);
            if ($legal === null || !hash_equals($checkout['legal_bundle_hash'], $legal['bundle_hash'])) {
                return $this->review($checkoutId, $payment['payment_id'], 'cart_legal_hash_conflict');
            }
            $sequence = $this->row("SELECT next_value FROM order_sequences WHERE sequence_name='carmaja-v1' FOR UPDATE");
            if ($sequence === null) { throw new CarmajaCommerceException('order_sequence_missing', 'Bestellnummer fehlt.', 503); }
            $number = 'CMJ-' . str_pad((string) $sequence['next_value'], 8, '0', STR_PAD_LEFT);
            $this->execute("UPDATE order_sequences SET next_value=next_value+1 WHERE sequence_name='carmaja-v1'");
            $orderId = carmaja_commerce_new_id();
            $this->execute("INSERT INTO orders(order_id,order_number,checkout_id,payment_id,status,customer_email,customer_name,
                shipping_address,billing_address,product_snapshot,shipping_snapshot,legal_bundle_id,confirmed_at)
                VALUES (?,?,?,?,'confirmed',?,?,?,?,?,?,?,UTC_TIMESTAMP(6))",
                [$orderId, $number, $checkoutId, $payment['payment_id'], $event['customerEmail'], $event['customerName'],
                 carmaja_commerce_json($event['shippingAddress']), isset($event['billingAddress']) ? carmaja_commerce_json($event['billingAddress']) : null,
                 carmaja_commerce_json(['items' => $snapshots, 'legalBundleHash' => $checkout['legal_bundle_hash']]), $checkout['shipping_snapshot'], $checkout['legal_bundle_id']]);
            foreach ($lines as $line) {
                $inventory = $this->inventory($line['product_id'], true);
                $target = $inventory['onHand'] - (int) $line['quantity'];
                $this->execute("INSERT INTO order_items VALUES (?,?,?,?,?,'eur',?)", [$orderId, $line['position_no'], $line['product_id'], $line['quantity'],
                    json_decode($line['product_snapshot'], true, 32, JSON_THROW_ON_ERROR)['priceMinor'], $line['product_snapshot']]);
                $this->execute('UPDATE commerce_inventory SET on_hand=?,inventory_version=inventory_version+1 WHERE product_id=?', [$target, $line['product_id']]);
                $this->execute("INSERT INTO inventory_adjustments(product_id,target_on_hand,previous_on_hand,inventory_version,reason,correlation_id,idempotency_key,actor_id)
                    VALUES (?,?,?,?,'shop_sale',?,?,'commerce')", [$line['product_id'], $target, $inventory['onHand'], $inventory['inventoryVersion'] + 1,
                        $checkoutId, 'sale:' . $checkoutId . ':' . $line['position_no']]);
            }
            $shipping = json_decode($checkout['shipping_snapshot'], true, 16, JSON_THROW_ON_ERROR);
            $this->execute("INSERT INTO shipments(shipment_id,order_id,status,shipping_method_id) VALUES (?,?,'ready',?)", [carmaja_commerce_new_id(), $orderId, $shipping['shippingMethodId']]);
            $this->execute("UPDATE reservations SET state='converted',blocks_stock=0,converted_at=UTC_TIMESTAMP(6) WHERE checkout_id=?", [$checkoutId]);
            $this->execute("UPDATE checkout_sagas SET state='completed' WHERE checkout_id=?", [$checkoutId]);
            $this->execute("UPDATE payments SET order_id=?,status='succeeded',verification_status='verified',stripe_payment_intent_id=?,payment_method_type=? WHERE checkout_id=?",
                [$orderId, $event['stripePaymentIntentId'], $event['paymentMethodType'], $checkoutId]);
            $legal = $this->row('SELECT * FROM legal_bundles WHERE legal_bundle_id=?', [$checkout['legal_bundle_id']]);
            $payload = ['orderNumber' => $number, 'customerName' => $event['customerName'], 'items' => $snapshots,
                'shipping' => $shipping, 'legal' => ['legalBundleId' => $legal['legal_bundle_id'], 'bundleHash' => $legal['bundle_hash']],
                'totalMinor' => (int) $payment['amount_minor'], 'currency' => 'eur', 'paymentMethodType' => $event['paymentMethodType']];
            foreach (['order_confirmation' => $event['customerEmail'], 'operator_order_notification' => $this->operatorEmail] as $type => $recipient) {
                if ($recipient === null) { continue; }
                $this->execute("INSERT INTO mail_outbox(dedupe_key,message_type,order_id,recipient,payload,status,next_attempt_at)
                    VALUES (?,?,?,?,?,'queued',UTC_TIMESTAMP(6))", [$type . ':' . $orderId, $type, $orderId, $recipient, carmaja_commerce_json($payload)]);
            }
            $this->execute("INSERT INTO stripe_metadata_outbox(dedupe_key,payment_id,stripe_payment_intent_id,metadata_payload,status,next_attempt_at)
                VALUES (?,?,?,?,'queued',UTC_TIMESTAMP(6))", ['payment-order:' . $payment['payment_id'], $payment['payment_id'],
                    $event['stripePaymentIntentId'], carmaja_commerce_json(['orderNumber' => $number])]);
            foreach (['rate_bucket_hash','ip_bucket_hash'] as $bucket) {
                $this->execute('UPDATE shop_rate_limits r JOIN checkout_tokens t ON t.' . $bucket . '=r.bucket_hash
                    SET r.successful_attempts=r.successful_attempts+1 WHERE t.checkout_id=?', [$checkoutId]);
            }
            return ['status' => 'confirmed', 'orderId' => $orderId, 'orderNumber' => $number];
        });
        if ($result['status'] === 'manual_review') {
            throw new CarmajaCommerceException('manual_review', 'Zahlung muss geprüft werden.', 409);
        }
        // Separate transaction prevents a cart->inventory / inventory->cart lock cycle.
        $checkout = $this->row('SELECT session_hash,cart_version FROM checkout_sagas WHERE checkout_id=?', [$checkoutId]);
        $this->execute("UPDATE shop_carts SET items='[]',cart_version=cart_version+1,updated_at=UTC_TIMESTAMP(6)
            WHERE session_hash=? AND cart_version=?", [$checkout['session_hash'], $checkout['cart_version']]);
        return $result;
    }

    public function projectCollectionProduct(array $product, string $action, string $operationId, string $requestHash): array
    {
        return $this->transaction(function () use ($product, $action, $operationId, $requestHash): array {
            $previous = $this->row('SELECT * FROM commerce_products WHERE product_id=? FOR UPDATE', [$product['productId']]);
            if ($previous !== null && ((int) $previous['product_version'] > $product['productVersion']
                || ((int) $previous['product_version'] === $product['productVersion'] && $previous['source_hash'] !== $product['sourceHash']))) {
                throw new CarmajaCommerceException('projection_version_conflict', 'Produktversion und Quellhash widersprechen sich.', 409);
            }
            // Parent projection and the stocked model change share this transaction.
            $result = parent::projectCollectionProduct($product, $action, $operationId, $requestHash);
            $publicFields = ['productId','productModelVersion','productVersion','sourceHash','sku','slug','title','description','descriptionDocument',
                'materials','metalElements','braceletSizeCm','pearlSizeMm','careInstructions','images','priceMinor','currency','noticePolicyId','notices'];
            $publicSnapshot = array_intersect_key($product, array_fill_keys($publicFields, true));
            $this->execute("UPDATE commerce_products SET sales_model='stocked_collection',notice_snapshot=?,public_snapshot=? WHERE product_id=?",
                [carmaja_commerce_json(['noticePolicyId' => $product['noticePolicyId'] ?? null, 'notices' => $product['notices'] ?? []]), carmaja_commerce_json($publicSnapshot), $product['productId']]);
            $initial = $this->row('SELECT * FROM inventory_initializations WHERE product_id=? FOR UPDATE', [$product['productId']]);
            if ($initial !== null && $initial['applied_at'] === null) {
                $this->adjustInventory(['productId' => $product['productId'], 'targetOnHand' => (int) $initial['target_on_hand'],
                    'expectedInventoryVersion' => 0, 'reason' => 'initial_count', 'correlationId' => $initial['operation_id'],
                    'idempotencyKey' => $initial['operation_id']], $initial['actor_id']);
                $this->execute('UPDATE inventory_initializations SET applied_at=UTC_TIMESTAMP(6) WHERE product_id=?', [$product['productId']]);
            }
            return [...$result, 'salesModel' => 'stocked_collection', 'available' => $this->loadLiveProduct($product['productId'])['available']];
        });
    }

    public function stageInitialInventory(string $productId, int $quantity, string $actor, string $key): array
    {
        carmaja_commerce_assert_id($productId, 'productId');
        carmaja_commerce_assert_id($key, 'Idempotency-Key');
        if ($quantity < 0 || $quantity > 4294967295) { throw new CarmajaCommerceException('inventory_value_invalid', 'Menge ungültig.', 422); }
        return $this->transaction(function () use ($productId, $quantity, $actor, $key): array {
            $this->execute('INSERT IGNORE INTO inventory_initializations(product_id,target_on_hand,actor_id,operation_id) VALUES (?,?,?,?)', [$productId, $quantity, $actor, $key]);
            $row = $this->row('SELECT * FROM inventory_initializations WHERE product_id=? FOR UPDATE', [$productId]);
            if ($row['operation_id'] !== $key || (int) $row['target_on_hand'] !== $quantity || $row['actor_id'] !== $actor) {
                throw new CarmajaCommerceException('idempotency_conflict', 'Erstzählung bereits vorgemerkt.', 409);
            }
            return ['status' => $row['applied_at'] === null ? 'staged' : 'applied', 'targetOnHand' => $quantity];
        });
    }

    public function orderPositions(string $orderId): array
    {
        return array_map(static function ($item): array {
            $snapshot = json_decode($item['product_snapshot'], true, 32, JSON_THROW_ON_ERROR);
            return ['positionNo' => (int) $item['position_no'], 'productId' => $item['product_id'],
                'name' => $snapshot['name'] ?? $item['product_id'], 'quantity' => (int) $item['quantity'],
                'priceMinor' => (int) $item['price_minor']];
        }, $this->rows('SELECT * FROM order_items WHERE order_id=? ORDER BY position_no', [$orderId]));
    }

    public function issueCartToken(string $id, string $tokenHash, string $owner, string $bucket, string $ipBucket): void
    {
        $checkout = $this->row('SELECT request_hash,session_hash FROM checkout_sagas WHERE checkout_id=?', [$id]);
        if ($checkout === null || $checkout['session_hash'] !== $owner) {
            throw new CarmajaCommerceException('checkout_token_invalid', 'Checkout-Zuordnung ungültig.', 403);
        }
        $this->execute('INSERT INTO checkout_tokens(checkout_id,token_hash,session_hash,product_id,product_version,request_hash,rate_bucket_hash,ip_bucket_hash,expires_at)
            VALUES (?,?,?,NULL,NULL,?,?,?,?) ON DUPLICATE KEY UPDATE token_hash=VALUES(token_hash),expires_at=VALUES(expires_at)',
            [$id, $tokenHash, $owner, $checkout['request_hash'], $bucket, $ipBucket,
                gmdate('Y-m-d H:i:s', time() + CARMAJA_SHOP_CHECKOUT_TOKEN_TTL_SECONDS)]);
    }

    public function loadAdminOrder(string $orderId): ?array
    {
        $order = parent::loadAdminOrder($orderId);
        if ($order === null) { return null; }
        $payment = $this->row('SELECT refunded_minor,amount_minor FROM payments WHERE order_id=?', [$orderId]);
        return [...$order, 'items' => $this->orderPositions($orderId), 'refundedMinor' => (int) $payment['refunded_minor'],
            'totalMinor' => (int) $payment['amount_minor'],
            'refunds' => $this->rows('SELECT stripe_refund_id,status,amount_minor,currency FROM refunds WHERE payment_id=? ORDER BY created_at', [$order['payment_id']])];
    }

    public function confirmWithdrawal(string $withdrawalId, string $tokenHash): array
    {
        return $this->transaction(function () use ($withdrawalId, $tokenHash): array {
            $request = $this->row('SELECT * FROM withdrawal_requests WHERE withdrawal_id=? AND token_hash=? FOR UPDATE', [$withdrawalId, $tokenHash]);
            if ($request === null) { throw new CarmajaCommerceException('withdrawal_confirmation_invalid', 'Bestätigung ungültig.', 409); }
            $content = json_decode($request['submitted_content'], true, 32, JSON_THROW_ON_ERROR);
            if ($request['order_id'] !== null && array_key_exists('items', $content) && $request['state'] === 'awaiting_confirmation') {
                // Serialize declarations for this order; overclaims remain declarations, not silently discarded.
                $this->row('SELECT order_id FROM orders WHERE order_id=? FOR UPDATE', [$request['order_id']]);
                $positions = $this->orderPositions($request['order_id']);
                $valid = is_array($content['items']) && array_is_list($content['items']) && $content['items'] !== [];
                $selected = [];
                foreach (is_array($content['items'] ?? null) ? $content['items'] : [] as $item) {
                    if (!is_array($item) || count($item) !== 2 || !is_int($item['positionNo'] ?? null) || !is_int($item['quantity'] ?? null) || $item['quantity'] < 1
                        || isset($selected[$item['positionNo']])) { $valid = false; continue; }
                    $selected[$item['positionNo']] = $item['quantity'];
                }
                foreach ($selected as $position => $quantity) {
                    $ordered = array_values(array_filter($positions, static fn ($line) => $line['positionNo'] === $position));
                    $previous = $this->rows('SELECT quantity FROM withdrawal_items WHERE order_id=? AND position_no=? FOR UPDATE', [$request['order_id'], $position]);
                    $used = array_sum(array_column($previous, 'quantity'));
                    $legacy = $this->rows("SELECT submitted_content FROM withdrawal_requests WHERE order_id=? AND state='submitted' AND match_status='matched' FOR UPDATE", [$request['order_id']]);
                    foreach ($legacy as $declaration) {
                        $oldContent = json_decode($declaration['submitted_content'], true, 32, JSON_THROW_ON_ERROR);
                        if (!array_key_exists('items', $oldContent) && $ordered !== []) { $used += $ordered[0]['quantity']; }
                    }
                    if ($ordered === [] || $quantity + $used > $ordered[0]['quantity']) { $valid = false; }
                }
                if ($valid) {
                    foreach ($selected as $position => $quantity) {
                        $this->execute('INSERT INTO withdrawal_items VALUES (?,?,?,?)', [$withdrawalId, $request['order_id'], $position, $quantity]);
                    }
                } else {
                    $this->execute("UPDATE withdrawal_requests SET match_status='manual_review' WHERE withdrawal_id=?", [$withdrawalId]);
                }
            }
            return parent::confirmWithdrawal($withdrawalId, $tokenHash);
        });
    }

    public function restockPosition(string $orderId, array $body, string $actor, string $key): array
    {
        if (array_diff(array_keys($body), ['positionNo','quantity','expectedInventoryVersion','correlationId','receivedAndChecked']) !== []
            || count($body) !== 5 || !is_int($body['positionNo'] ?? null) || !is_int($body['quantity'] ?? null) || $body['quantity'] < 1
            || !is_int($body['expectedInventoryVersion'] ?? null) || ($body['receivedAndChecked'] ?? null) !== true) {
            throw new CarmajaCommerceException('return_verification_required', 'Eingang und Prüfung der Rückgabe ausdrücklich bestätigen.', 422);
        }
        carmaja_commerce_assert_id($key, 'Idempotency-Key');
        return $this->transaction(function () use ($orderId, $body, $actor, $key): array {
            $this->row('SELECT order_id FROM orders WHERE order_id=? FOR UPDATE', [$orderId]);
            $line = $this->row('SELECT * FROM order_items WHERE order_id=? AND position_no=?', [$orderId, $body['positionNo']]);
            if ($line === null) { throw new CarmajaCommerceException('order_item_not_found', 'Position fehlt.', 404); }
            $hash = carmaja_commerce_request_hash(['orderId' => $orderId, 'body' => $body, 'actor' => $actor]);
            $existing = $this->row('SELECT * FROM restocked_items WHERE operation_id=?', [$key]);
            if ($existing !== null) {
                if ($existing['request_hash'] !== $hash) { throw new CarmajaCommerceException('idempotency_conflict', 'Rückgabeauftrag wurde verändert.', 409); }
                return $this->inventory($line['product_id']);
            }
            $returns = $this->rows('SELECT quantity FROM restocked_items WHERE order_id=? AND position_no=? FOR UPDATE', [$orderId, $body['positionNo']]);
            $legacy = $this->row("SELECT restock_id FROM restocks WHERE order_id=? AND product_id=? AND state='completed'", [$orderId, $line['product_id']]);
            if (array_sum(array_column($returns, 'quantity')) + ($legacy !== null ? 1 : 0) + $body['quantity'] > (int) $line['quantity']) {
                throw new CarmajaCommerceException('return_quantity_conflict', 'Rückgabemenge überschreitet die Bestellung.', 409);
            }
            $this->row('SELECT product_id FROM commerce_products WHERE product_id=? FOR UPDATE', [$line['product_id']]);
            $inventory = $this->inventory($line['product_id'], true);
            $this->verifiedReturn = true;
            try {
                $result = $this->adjustInventory(['productId' => $line['product_id'], 'targetOnHand' => $inventory['onHand'] + $body['quantity'],
                    'expectedInventoryVersion' => $body['expectedInventoryVersion'], 'reason' => 'release_return',
                    'correlationId' => $body['correlationId'], 'idempotencyKey' => $key], $actor);
            } finally { $this->verifiedReturn = false; }
            $this->execute('INSERT INTO restocked_items(operation_id,request_hash,order_id,position_no,quantity,actor_id) VALUES (?,?,?,?,?,?)',
                [$key, $hash, $orderId, $body['positionNo'], $body['quantity'], $actor]);
            return $result;
        });
    }

    public function confirmSelectedWithdrawal(string $id, string $tokenHash, array $items, string $details, string $owner): array
    {
        return $this->transaction(function () use ($id, $tokenHash, $items, $details, $owner): array {
            $row = $this->row('SELECT * FROM withdrawal_requests WHERE withdrawal_id=? AND token_hash=? FOR UPDATE', [$id, $tokenHash]);
            if ($row === null || $row['state'] !== 'awaiting_confirmation') {
                throw new CarmajaCommerceException('withdrawal_confirmation_invalid', 'Bestätigung ungültig.', 409);
            }
            $content = json_decode($row['submitted_content'], true, 32, JSON_THROW_ON_ERROR);
            if (($content['sessionHash'] ?? '') !== $owner) {
                throw new CarmajaCommerceException('withdrawal_confirmation_invalid', 'Sitzungsbindung ungültig.', 403);
            }
            unset($content['sessionHash']);
            $content['items'] = $items;
            $content['details'] = $details;
            $this->execute('UPDATE withdrawal_requests SET submitted_content=? WHERE withdrawal_id=?', [carmaja_commerce_json($content), $id]);
            return $this->confirmWithdrawal($id, $tokenHash);
        });
    }
}
