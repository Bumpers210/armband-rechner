<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/scripts/cart-sepa-deadline-test.php';
$checks = 0;
$assert = static function (bool $condition) use (&$checks): void {
    if (!$condition) { throw new RuntimeException('deadline_test_failed'); } $checks++;
};
$assert(cart_sepa_deadline_window(1000, 2800) === ['createdEpoch'=>1000,'expiresEpoch'=>2800,'promptEpoch'=>2680,'stopEpoch'=>3700]);
foreach ([[0,1800],[1000,2799],[1000,2801],[1000,1000]] as [$created,$expires]) {
    $rejected = false; try { cart_sepa_deadline_window($created,$expires); } catch (RuntimeException) { $rejected=true; } $assert($rejected);
}
$assert(cart_sepa_deadline_window(1001,2800,1000,2800)['expiresEpoch'] === 2800);
$assert(cart_sepa_deadline_window(1000,2800,1000,2800)['promptEpoch'] === 2680);
foreach ([[1001,2799,1000,2800],[1001,2800,1000,2799],[999,2800,1000,2800],
    [2800,2800,1000,2800],[1001,2800,0,2800],[1001,2800,null,2800],[1001,2800,1000,null]] as $args) {
    $rejected=false; try { cart_sepa_deadline_window(...$args); } catch(RuntimeException) { $rejected=true; } $assert($rejected);
}
$sample=['observedEpoch'=>2801,'expiresEpoch'=>2800,'stripeSession'=>'complete','stripePayment'=>'unpaid',
    'paymentIntent'=>'processing','localPayment'=>'processing','onHand'=>[30,30],'reserved'=>[2,1],'noOrder'=>true,'noMail'=>true];
$assert(cart_sepa_deadline_held($sample));
foreach (['observedEpoch'=>2800,'expiresEpoch'=>'2800','stripeSession'=>'expired','stripePayment'=>'paid',
    'paymentIntent'=>'succeeded','localPayment'=>'pending','onHand'=>[28,29],'reserved'=>[0,0],
    'noOrder'=>false,'noMail'=>false] as $field=>$value) { $assert(!cart_sepa_deadline_held([...$sample,$field=>$value])); }
foreach (array_keys($sample) as $field) { $missing=$sample; unset($missing[$field]); $assert(!cart_sepa_deadline_held($missing)); }
$text=(string) file_get_contents(dirname(__DIR__,2).'/scripts/cart-sepa-deadline-test.php');
foreach (['observer_only_artificial_products','observer_sandbox_required','observer_session_changed',
    'post-deadline-processing-proof.json','observer_real_async_binding_failed','retained_config_changed',
    'LOCK_EX | LOCK_NB','mailWorkerNotStarted'] as $guard) { $assert(str_contains($text,$guard)); }
$assert(!str_contains($text,'new CarmajaAp5Worker'));
$failed=[...$sample,'observedEpoch'=>2900,'paymentIntent'=>'requires_payment_method','localPayment'=>'failed',
    'verificationStatus'=>'verified','failurePresent'=>true,'reserved'=>[0,0]];
$assert(cart_sepa_deadline_failed($failed,2801));
foreach (['observedEpoch'=>2800,'expiresEpoch'=>'2800','stripeSession'=>'expired','stripePayment'=>'paid',
    'paymentIntent'=>'processing','localPayment'=>'processing','verificationStatus'=>'unverified',
    'failurePresent'=>false,'onHand'=>[28,29],'reserved'=>[2,1],'noOrder'=>false,'noMail'=>false] as $field=>$value) {
    $assert(!cart_sepa_deadline_failed([...$failed,$field=>$value],2801));
}
$assert(!cart_sepa_deadline_failed($failed,2800));
$assert(!cart_sepa_deadline_failed($failed,2901));
foreach(array_keys($failed) as $field){$missing=$failed;unset($missing[$field]);$assert(!cart_sepa_deadline_failed($missing,2801));}
foreach(['observer_outcome_invalid','observer_unexpected_success','observer_unexpected_failure',
    'observer_real_failure_binding_failed','failure_deadline_passed','sepa-failed-processing-proof.json',
    'cart_sandbox_verify_failed_payment()']as$guard){$assert(str_contains($text,$guard));}
try {cart_sepa_deadline_observe('invalid');$assert(false);}catch(RuntimeException $e){$assert($e->getMessage()==='observer_outcome_invalid');}
echo 'PASS private SEPA deadline observer: '.$checks." assertions\n";
