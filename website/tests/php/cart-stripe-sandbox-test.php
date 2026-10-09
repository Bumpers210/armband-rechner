<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/scripts/cart-stripe-sandbox.php';
$checks = 0;
$assert = static function (bool $condition) use (&$checks): void {
    if (!$condition) { throw new RuntimeException('sandbox_contract_failed'); }
    $checks++;
};
$assert(defined('CARMAJA_BOOTSTRAP_NO_RUN')); // Inclusion never starts HTTP/CLI work.
try { cart_sandbox_root(); throw new RuntimeException('local_stage_accepted'); }
catch (RuntimeException $error) { $assert($error->getMessage() === 'isolated_stage_required'); }
try { cart_sandbox_read(__FILE__); throw new RuntimeException('repository_file_accepted'); }
catch (RuntimeException $error) { $assert($error->getMessage() === 'private_file_required'); }
$text = (string) file_get_contents(dirname(__DIR__, 2) . '/scripts/cart-stripe-sandbox.php');
$assert(str_contains($text, "cart_probe_empty(\$source) && cart_probe_empty(\$restore)"));
$assert(str_contains($text, "'stripePaymentMethodTypes' => CARMAJA_STRIPE_PAYMENT_METHOD_TYPES"));
$assert(str_contains($text, "'api_version' => CARMAJA_STRIPE_WEBHOOK_API_VERSION"));
$assert(str_contains($text, "'githubAdapterEnabled' => false"));
$assert(str_contains($text, "'productionPublishEnabled' => false"));
$assert(str_contains($text, 'cart_sandbox_event_owned'));
$assert(str_contains($text, "array_diff(\$tables, \$allowed) === []"));
$assert(str_contains($text, "'existing_api_changed'"));
$assert(str_contains($text, "chmod(\$paths['runtime'], 0600)"));
$assert(CARMAJA_STRIPE_PAYMENT_METHOD_TYPES === ['card', 'klarna', 'sepa_debit']);
$assert(str_contains($text, "'actual_async_success_webhook_missing'"));
$assert(str_contains($text, "'processing_proof_missing'"));
$assert(str_contains($text, "\$process->invoke(\$worker, \$asyncEvent)"));
$assert(str_contains($text, "'failure_reservations_not_released'"));
$assert(str_contains($text, "'actual_failure_webhooks_missing'"));
$assert(str_contains($text, "'failure_replay_mutated_data'"));
$assert(str_contains($text, "'provider_refund_mismatch'"));
$assert(str_contains($text, "'actual_refund_webhooks_missing'"));
$assert(str_contains($text, "'refund_replay_mutated_data'"));
$assert(str_contains($text, "'refund_mutated_inventory'"));
$assert(str_contains($text, "'admin_partial_refund_mismatch'"));
$assert(str_contains($text, "'preview_submitted_declaration'"));
$assert(str_contains($text, "'overclaim_not_retained_for_review'"));
$assert(str_contains($text, "'withdrawal_automatically_restocked'"));
$assert(str_contains($text, "'restock_replay_mutated_data'"));
$assert(str_contains($text, "'rejected_restock_mutated_data'"));
echo 'PASS cart sandbox guards: ' . $checks . " assertions\n";
