<?php
declare(strict_types=1);
require_once __DIR__ . '/cart-stripe-sandbox.php';
require_once dirname(__DIR__) . '/test-api-private/program/ap5-worker.php';
define('CARMAJA_CART_BREVO_SETUP_NO_RUN', true);
require_once __DIR__ . '/install-cart-brevo-test.php';

function cart_brevo_sandbox_run(): array
{
    $config = cart_sandbox_active(); $paths = cart_sandbox_paths();
    $proof = $paths['root'] . '/brevo-proof.json';
    cart_probe_assert(!file_exists($proof), 'brevo_test_already_completed');
    $private = cart_sandbox_read('/home/www/carmaja-private-test/shop-cart-brevo-test.json');
    cart_probe_assert(($private['testOnly'] ?? null) === true, 'brevo_test_only_required');
    unset($private['testOnly']); $private = cart_brevo_setup_values($private);
    $config = [...$config, ...$private]; // Memory only; no runtime replacement.
    $targets = cart_sandbox_targets(); $pdo = cart_probe_connect($targets['source'], $targets);
    $commerce = new CarmajaCartCommerce($pdo);
    cart_probe_assert((int) $pdo->query("SELECT COUNT(*) FROM order_items WHERE product_id NOT IN ('CART-SANDBOX-A','CART-SANDBOX-B')")->fetchColumn() === 0, 'foreign_order_items_rejected');
    cart_probe_assert((int) $pdo->query("SELECT COUNT(*) FROM mail_outbox WHERE dedupe_key LIKE 'cart-brevo:%'")->fetchColumn() === 0, 'brevo_fixture_already_created');
    $inventory = array_map($commerce->inventory(...), CART_SANDBOX_PRODUCT_IDS);
    $rows = $pdo->query('SELECT * FROM mail_outbox ORDER BY mail_id')->fetchAll(PDO::FETCH_ASSOC);
    cart_probe_assert(count($rows) === 5, 'unexpected_mail_fixture_count');
    $order = null; $invalid = 0;
    foreach ($rows as $row) {
        cart_probe_assert($row['status'] === 'queued' && (int) $row['attempt_count'] === 0, 'mail_fixture_not_pristine');
        if ($row['message_type'] === 'order_confirmation') {
            $order ??= $row; $calls = 0;
            $probe = new CarmajaBrevoClient($config, static function () use (&$calls): array {
                $calls++; return ['status'=>201,'body'=>'{"messageId":"synthetic"}'];
            });
            $result = $probe->send($row);
            cart_probe_assert(($result['error'] ?? '') === 'mail_content_missing' && $calls === 0, 'invalid_legal_fixture_not_rejected'); $invalid++;
        } else { cart_probe_assert($row['message_type'] === 'withdrawal_receipt', 'foreign_mail_rejected'); }
    }
    cart_probe_assert($invalid === 3 && is_array($order), 'artificial_order_fixture_missing');
    // Preserve original snapshots. These dedicated rendering fixtures are NOT
    // proof of the paid-order-mail chain, whose artificial legal ID is invalid.
    $payload = json_decode($order['payload'], true, 32, JSON_THROW_ON_ERROR);
    cart_probe_assert(count($payload['items'] ?? []) === 2 && $payload['totalMinor'] === 7770 && $payload['shipping']['amountMinor'] === 270, 'cart_mail_totals_invalid');
    $payload['legal']['legalBundleId'] = 'cmj-test-legal-2026-10-08-v1';
    $renderer = new CarmajaBrevoClient($config, static function (array $request) use ($private): array {
        cart_probe_assert($request['to'] === [['email'=>$private['testRecipientEmail']]], 'unauthorized_recipient');
        $html = $request['htmlContent'];
        cart_probe_assert(str_contains($html,'77,70') && str_contains($html,'2 × 28,00') && str_contains($html,'1 × 19,00') && substr_count($html,'Versand einmalig: 2,70') === 1, 'multi_item_render_invalid');
        foreach (CARMAJA_PRODUCT_NOTICES as $notice) { cart_probe_assert(str_contains($html,htmlspecialchars($notice,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8')), 'notice_missing'); }
        return ['status'=>201,'body'=>'{"messageId":"synthetic-render"}'];
    });
    foreach (['order_confirmation','operator_order_notification'] as $type) {
        $fixture = [...$order,'message_type'=>$type,'recipient'=>$private['testRecipientEmail'],'payload'=>json_encode($payload,JSON_THROW_ON_ERROR)];
        cart_probe_assert($renderer->send($fixture)['outcome'] === 'sent', 'render_probe_failed');
    }
    $insert = $pdo->prepare("INSERT INTO mail_outbox(dedupe_key,message_type,order_id,recipient,payload,status,next_attempt_at) VALUES(?,?,?,?,?,'queued',UTC_TIMESTAMP(6))");
    foreach (['order'=>'order_confirmation','operator'=>'operator_order_notification','shipping'=>'shipping_confirmation'] as $label=>$type) {
        $body = $payload; if ($label === 'shipping') { $body['shippingName'] = 'Test: Maxibrief Deutschland'; }
        $insert->execute(['cart-brevo:'.$label,$type,$order['order_id'],$private['testRecipientEmail'],json_encode($body,JSON_THROW_ON_ERROR)]);
    }
    $recipient = $pdo->prepare("UPDATE mail_outbox SET recipient=? WHERE mail_id=? AND message_type='withdrawal_receipt'");
    foreach ($rows as $row) { if ($row['message_type'] === 'withdrawal_receipt') { $recipient->execute([$private['testRecipientEmail'],$row['mail_id']]); } }
    return cart_brevo_sandbox_delivery($pdo,$commerce,$config,$private,$payload,$order,$insert,$inventory,$proof);
}

function cart_brevo_sandbox_delivery(PDO $pdo, CarmajaCartCommerce $commerce, array $config, array $private,
    array $payload, array $order, PDOStatement $insert, array $inventory, string $proof): array
{
    $worker = new CarmajaAp5Worker($commerce, $config);
    $sent = $worker->run();
    cart_probe_assert($sent['status'] === 'completed' && $sent['processed'] === 5 && $sent['durationMs'] < 40000, 'real_brevo_delivery_incomplete');
    $sentQuery = "SELECT mail_id,status,attempt_count,brevo_message_id,sent_at FROM mail_outbox WHERE status='sent' ORDER BY mail_id";
    $sentRows = $pdo->query($sentQuery)->fetchAll(PDO::FETCH_ASSOC);
    cart_probe_assert(count($sentRows) === 5, 'provider_acceptance_count_invalid');
    foreach ($sentRows as $row) { cart_probe_assert(is_string($row['brevo_message_id']) && $row['brevo_message_id'] !== '', 'provider_id_missing'); }
    cart_probe_assert($worker->run()['processed'] === 0 && $sentRows === $pdo->query($sentQuery)->fetchAll(PDO::FETCH_ASSOC), 'sent_mail_replayed');
    $ids = [];
    foreach (['retry','unknown'] as $label) {
        $insert->execute(['cart-brevo:'.$label,'order_confirmation',$order['order_id'],$private['testRecipientEmail'],json_encode($payload,JSON_THROW_ON_ERROR)]);
        $ids[$label] = (int) $pdo->lastInsertId();
    }
    $calls = 0;
    $fault = new CarmajaAp5Worker($commerce, $config, static function (array $request, string $key) use (&$calls,$pdo): array {
        cart_probe_assert(!$pdo->inTransaction(), 'provider_call_in_transaction'); $calls++;
        if ($key === 'cart-brevo:retry') { return ['status'=>429,'body'=>'{}']; }
        cart_probe_assert($key === 'cart-brevo:unknown', 'unexpected_fault_mail');
        throw new RuntimeException('synthetic_transport_uncertainty');
    });
    $fault->run();
    $select = $pdo->prepare('SELECT status,attempt_count,lease_until,next_attempt_at>UTC_TIMESTAMP(6) AS backoff FROM mail_outbox WHERE mail_id=?');
    $select->execute([$ids['retry']]); $retry = $select->fetch(PDO::FETCH_ASSOC);
    $select->execute([$ids['unknown']]); $unknown = $select->fetch(PDO::FETCH_ASSOC);
    cart_probe_assert($calls === 2 && $retry['status'] === 'queued' && (int)$retry['backoff'] === 1 && $unknown['status'] === 'delivery_unknown' && $unknown['lease_until'] === null, 'fault_outcome_invalid');
    $fault->run(); cart_probe_assert($calls === 2, 'uncertain_mail_automatically_retried');
    $pdo->prepare('UPDATE mail_outbox SET next_attempt_at=UTC_TIMESTAMP(6) WHERE mail_id=?')->execute([$ids['retry']]);
    $retryCalls = 0;
    (new CarmajaAp5Worker($commerce,$config,static function(array $request,string $key) use (&$retryCalls,$pdo): array {
        cart_probe_assert(!$pdo->inTransaction() && $key === 'cart-brevo:retry', 'retry_scope_invalid');
        $retryCalls++; return ['status'=>201,'body'=>'{"messageId":"synthetic-retry-success"}'];
    }))->run();
    cart_probe_assert($retryCalls === 1, 'retry_recovery_missing');
    $actor = $pdo->query("SELECT admin_id FROM admin_users WHERE username='sandbox-review-operator' AND enabled=0")->fetchColumn();
    cart_probe_assert(is_string($actor), 'synthetic_actor_missing');
    $resend = $commerce->queueAdminMailResend($ids['unknown'],$actor,'cart-brevo-manual-resend');
    $new = $pdo->prepare('SELECT dedupe_key FROM mail_outbox WHERE mail_id=?'); $new->execute([$resend['mailId']]);
    cart_probe_assert($new->fetchColumn() !== 'cart-brevo:unknown', 'manual_resend_not_distinct');
    $manual = $worker->run();
    cart_probe_assert($manual['processed'] === 1 && $manual['durationMs'] < 40000, 'manual_resend_not_accepted');
    $audit = $pdo->prepare("SELECT COUNT(*) FROM admin_audit_events WHERE action='mail_manual_resend_queued' AND correlation_id=?");
    $audit->execute(['cart-brevo-manual-resend']); cart_probe_assert((int)$audit->fetchColumn() === 1, 'manual_resend_audit_missing');
    $select->execute([$ids['unknown']]); $old = $select->fetch(PDO::FETCH_ASSOC);
    cart_probe_assert($old['status'] === 'delivery_unknown' && (int)$old['attempt_count'] === 1, 'uncertainty_evidence_overwritten');
    cart_probe_assert($inventory === array_map($commerce->inventory(...),CART_SANDBOX_PRODUCT_IDS), 'mail_mutated_inventory');
    $result = ['testOnly'=>true,'providerAcceptedMails'=>6,'inboxDeliveryNotYetConfirmed'=>true,
        'dedicatedCartRenderingFixtures'=>true,'originalPaidOrderMailE2eStillOpen'=>true,
        'historicalInvalidLegalFixturesRejected'=>3,'sentReplayUnchanged'=>true,
        'injectedRetryRecovered'=>true,'injectedUncertaintyNoAutomaticRetry'=>true,
        'auditedManualResendAccepted'=>true,'inventoryUnchanged'=>true,
        'firstRunDurationMs'=>$sent['durationMs'],'manualRunDurationMs'=>$manual['durationMs']];
    cart_sandbox_write($proof,$result); return $result;
}

if (PHP_SAPI === 'cli' && realpath((string)($_SERVER['SCRIPT_FILENAME']??'')) === __FILE__) {
    ini_set('display_errors','0'); ini_set('log_errors','0');
    try { echo json_encode(cart_brevo_sandbox_run(),JSON_THROW_ON_ERROR)."\n"; }
    catch (Throwable) { fwrite(STDERR,"CART_BREVO_TEST_FAILED_NO_SECRET_OUTPUT\n"); exit(1); }
}
