<?php
declare(strict_types=1);

// Private, disposable test-stage observer. Never used by a production worker.
require_once __DIR__ . '/cart-stripe-sandbox.php';

function cart_sepa_deadline_window(int $created, int $expires, ?int $localCreated = null, ?int $localExpires = null): array
{
    // Stripe creates its object after the local transaction/network request.
    // Bind its deadline to the persisted 30-minute checkout, not its later creation second.
    $localBound = $localCreated !== null || $localExpires !== null;
    cart_probe_assert($created > 0 && ($localBound
        ? $localCreated !== null && $localExpires !== null && $localCreated > 0
            && $localExpires - $localCreated === 1800 && $expires === $localExpires
            && $created >= $localCreated && $created < $expires
        : $expires - $created === 1800), 'real_thirty_minute_session_required');
    return ['createdEpoch' => $created, 'expiresEpoch' => $expires,
        'promptEpoch' => $expires - 120, 'stopEpoch' => $expires + 900];
}

function cart_sepa_deadline_held(array $sample): bool
{
    return is_int($sample['observedEpoch'] ?? null) && is_int($sample['expiresEpoch'] ?? null)
        && $sample['observedEpoch'] > $sample['expiresEpoch']
        && ($sample['stripeSession'] ?? '') === 'complete'
        && ($sample['stripePayment'] ?? '') === 'unpaid'
        && ($sample['paymentIntent'] ?? '') === 'processing'
        && ($sample['localPayment'] ?? '') === 'processing'
        && ($sample['onHand'] ?? null) === [30, 30]
        && ($sample['reserved'] ?? null) === [2, 1]
        && ($sample['noOrder'] ?? false) === true && ($sample['noMail'] ?? false) === true;
}

function cart_sepa_deadline_failed(array $sample, int $eventCreated): bool
{
    return is_int($sample['observedEpoch'] ?? null) && is_int($sample['expiresEpoch'] ?? null)
        && $eventCreated > $sample['expiresEpoch'] && $sample['observedEpoch'] >= $eventCreated
        && ($sample['stripeSession'] ?? '') === 'complete'
        && ($sample['stripePayment'] ?? '') === 'unpaid'
        && ($sample['paymentIntent'] ?? '') === 'requires_payment_method'
        && ($sample['localPayment'] ?? '') === 'failed'
        && ($sample['verificationStatus'] ?? '') === 'verified'
        && ($sample['failurePresent'] ?? false) === true
        && ($sample['onHand'] ?? null) === [30, 30] && ($sample['reserved'] ?? null) === [0, 0]
        && ($sample['noOrder'] ?? false) === true && ($sample['noMail'] ?? false) === true;
}

function cart_sepa_deadline_observe(string $expectedOutcome = 'success'): array
{
    cart_probe_assert(in_array($expectedOutcome, ['success', 'failure'], true), 'observer_outcome_invalid');
    $failureMode = $expectedOutcome === 'failure';
    $label = $failureMode ? 'sepa_failed' : 'sepa_debit';
    $processingFile = $failureMode ? 'sepa-failed-processing-proof.json' : 'sepa-processing-proof.json';
    $config = cart_sandbox_active(); $root = cart_sandbox_root();
    $owner = cart_sandbox_read($root . '/setup-owner.json');
    cart_probe_assert(($owner['testOnly'] ?? false) === true && $owner['stage'] === basename($root), 'observer_owner_invalid');
    foreach ($owner['retained'] as $retained) {
        cart_probe_assert((fileperms($retained['path']) & 0777) === 0600
            && hash_equals($retained['sha256'], (string) hash_file('sha256', $retained['path'])), 'retained_config_changed');
    }
    $saved = cart_sandbox_read($root . '/checkout-' . $label . '.json');
    $targets = cart_sandbox_targets(); $pdo = cart_probe_connect($targets['source'], $targets);
    cart_probe_assert($pdo->query('SELECT product_id FROM commerce_products ORDER BY product_id')->fetchAll(PDO::FETCH_COLUMN)
        === CART_SANDBOX_PRODUCT_IDS, 'observer_only_artificial_products');
    $commerce = new CarmajaCartCommerce($pdo); $gateway = new CarmajaStripeGateway($config);
    $query = $pdo->prepare('SELECT * FROM payments WHERE checkout_id=?');
    $query->execute([$saved['checkoutId']]); $payment = $query->fetch(PDO::FETCH_ASSOC);
    cart_probe_assert(is_array($payment) && str_starts_with($payment['stripe_checkout_session_id'], 'cs_test_'), 'observer_test_session_required');
    $session = $gateway->retrieveCheckoutSession($payment['stripe_checkout_session_id']);
    cart_probe_assert($session['livemode'] === false, 'observer_sandbox_required');
    $checkoutQuery = $pdo->prepare('SELECT created_at,expires_at FROM checkout_sagas WHERE checkout_id=?');
    $checkoutQuery->execute([$saved['checkoutId']]); $checkout = $checkoutQuery->fetch(PDO::FETCH_ASSOC);
    cart_probe_assert(is_array($checkout), 'observer_local_checkout_required');
    $window = cart_sepa_deadline_window($session['created'], $session['expires_at'],
        strtotime($checkout['created_at'] . ' UTC'), strtotime($checkout['expires_at'] . ' UTC'));
    cart_probe_assert(time() < $window['stopEpoch'], 'observer_window_expired');
    $lockPath = $root . '/deadline-observer.lock';
    cart_probe_assert(!is_link($lockPath), 'observer_lock_unsafe');
    $lock = fopen($lockPath, 'c');
    cart_probe_assert(is_resource($lock) && chmod($lockPath, 0600), 'observer_lock_failed');
    if (!flock($lock, LOCK_EX | LOCK_NB)) { fclose($lock); return ['status' => 'observer_locked']; }
    try {
        cart_sandbox_write($root . '/deadline-observer-start.json', ['testOnly' => true,
            'expectedOutcome' => $expectedOutcome, 'pid' => getmypid(), ...$window]);
        $worker = new CarmajaAp3Worker($commerce, $gateway, $config);
        while (time() <= $window['stopEpoch']) {
            $session = $gateway->retrieveCheckoutSession($payment['stripe_checkout_session_id']);
            cart_probe_assert($session['livemode'] === false && $session['expires_at'] === $window['expiresEpoch'], 'observer_session_changed');
            if ($session['status'] !== 'open') {
                $run = $worker->run();
                cart_probe_assert($run['durationMs'] < 40000, 'observer_worker_duration_exceeded');
            }
            $query->execute([$saved['checkoutId']]); $current = $query->fetch(PDO::FETCH_ASSOC);
            $stock = array_map($commerce->inventory(...), CART_SANDBOX_PRODUCT_IDS);
            $intent = is_string($session['payment_intent'] ?? null)
                ? $gateway->retrievePaymentIntent($session['payment_intent']) : [];
            $sample = ['testOnly' => true, 'observedEpoch' => time(), 'expiresEpoch' => $window['expiresEpoch'],
                'stripeSession' => $session['status'], 'stripePayment' => $session['payment_status'],
                'paymentIntent' => $intent['status'] ?? null, 'localPayment' => $current['status'],
                'verificationStatus' => $current['verification_status'],
                'failurePresent' => ($intent['last_payment_error'] ?? null) !== null,
                'onHand' => array_column($stock, 'onHand'), 'reserved' => array_column($stock, 'reserved'),
                'noOrder' => (int) $pdo->query('SELECT COUNT(*) FROM orders')->fetchColumn() === 0,
                'noMail' => (int) $pdo->query('SELECT COUNT(*) FROM mail_outbox')->fetchColumn() === 0];
            if ($sample['paymentIntent'] === 'processing' && $sample['localPayment'] === 'processing'
                && !is_file($root . '/' . $processingFile)) {
                cart_probe_assert($sample['noOrder'] && $sample['noMail'] && $sample['onHand'] === [30, 30]
                    && $sample['reserved'] === [2, 1], 'observer_processing_invariant_failed');
                cart_sandbox_write($root . '/' . $processingFile, [...$sample,
                    'status' => 'processing_verified', 'noInventoryDebit' => true]);
            }
            if (cart_sepa_deadline_held($sample) && !is_file($root . '/post-deadline-processing-proof.json')) {
                cart_sandbox_write($root . '/post-deadline-processing-proof.json', [...$sample, 'status' => 'held_after_deadline']);
            }
            if ($current['status'] === 'succeeded') {
                cart_probe_assert(!$failureMode, 'observer_unexpected_success');
                $events = $pdo->prepare("SELECT * FROM webhook_inbox WHERE stripe_object_id=? AND event_type='checkout.session.async_payment_succeeded' AND status='processed'");
                $events->execute([$payment['stripe_checkout_session_id']]); $row = $events->fetch(PDO::FETCH_ASSOC);
                if ($row !== false) {
                    $event = json_decode(carmaja_stripe_decrypt_webhook_payload($row['payload_ciphertext'], $config['stripeWebhookPayloadKey']), true, 32, JSON_THROW_ON_ERROR);
                    cart_probe_assert($event['livemode'] === false && $event['api_version'] === CARMAJA_STRIPE_WEBHOOK_API_VERSION
                        && $event['data']['object']['id'] === $payment['stripe_checkout_session_id'], 'observer_real_async_binding_failed');
                    $passed = $event['created'] > $window['expiresEpoch'] && is_file($root . '/post-deadline-processing-proof.json');
                    cart_probe_assert((int) $pdo->query("SELECT COUNT(*) FROM review_cases WHERE status IN ('open','investigating')")->fetchColumn() === 0, 'observer_review_open');
                    $verification = is_file($root . '/sepa-processing-proof.json') ? cart_sandbox_verify_payment('sepa_debit') : null;
                    return ['testOnly' => true, 'status' => $passed ? 'deadline_passed' : 'deadline_not_proven',
                        'postDeadlineProcessingObserved' => is_file($root . '/post-deadline-processing-proof.json'),
                        'realAsyncSuccessEpoch' => $event['created'], 'expiresEpoch' => $window['expiresEpoch'],
                        'orderVerified' => $verification !== null, 'orderCount' => (int) $pdo->query('SELECT COUNT(*) FROM orders')->fetchColumn(),
                        'mailWorkerNotStarted' => true];
                }
            }
            if ($current['status'] === 'failed') {
                cart_probe_assert($failureMode, 'observer_unexpected_failure');
                $events = $pdo->prepare("SELECT * FROM webhook_inbox WHERE stripe_object_id=? AND event_type='checkout.session.async_payment_failed' AND status='processed'");
                $events->execute([$payment['stripe_checkout_session_id']]); $row = $events->fetch(PDO::FETCH_ASSOC);
                if ($row !== false) {
                    $event = json_decode(carmaja_stripe_decrypt_webhook_payload($row['payload_ciphertext'], $config['stripeWebhookPayloadKey']), true, 32, JSON_THROW_ON_ERROR);
                    cart_probe_assert($event['livemode'] === false && $event['api_version'] === CARMAJA_STRIPE_WEBHOOK_API_VERSION
                        && $event['data']['object']['id'] === $payment['stripe_checkout_session_id'], 'observer_real_failure_binding_failed');
                    $passed = cart_sepa_deadline_failed($sample, $event['created'])
                        && is_file($root . '/post-deadline-processing-proof.json');
                    cart_probe_assert((int) $pdo->query("SELECT COUNT(*) FROM review_cases WHERE status IN ('open','investigating')")->fetchColumn() === 0, 'observer_review_open');
                    $verification = is_file($root . '/' . $processingFile) ? cart_sandbox_verify_failed_payment() : null;
                    return ['testOnly' => true, 'status' => $passed && $verification !== null ? 'failure_deadline_passed' : 'failure_deadline_not_proven',
                        'postDeadlineProcessingObserved' => is_file($root . '/post-deadline-processing-proof.json'),
                        'realAsyncFailureEpoch' => $event['created'], 'expiresEpoch' => $window['expiresEpoch'],
                        'failureVerified' => $verification !== null, 'noOrder' => $sample['noOrder'], 'noMail' => $sample['noMail'],
                        'onHand' => $sample['onHand'], 'reserved' => $sample['reserved'], 'mailWorkerNotStarted' => true];
                }
            }
            if ($session['status'] === 'expired') {
                return ['testOnly' => true, 'status' => 'unpaid_expired', 'noOrder' => $sample['noOrder'],
                    'onHand' => $sample['onHand'], 'reserved' => $sample['reserved']];
            }
            sleep(5);
        }
        return ['testOnly' => true, 'status' => 'observer_timeout', 'noAcceptanceGranted' => true];
    } finally { flock($lock, LOCK_UN); fclose($lock); }
}

if (PHP_SAPI === 'cli' && realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    ini_set('display_errors', '0'); ini_set('log_errors', '0'); umask(0077);
    try {
        $result = cart_sepa_deadline_observe($argv[1] ?? 'success');
        cart_sandbox_write(cart_sandbox_root() . '/deadline-result.json', $result);
        echo json_encode($result, JSON_THROW_ON_ERROR) . "\n";
    } catch (Throwable $error) { cart_probe_report($error); exit(1); }
}
