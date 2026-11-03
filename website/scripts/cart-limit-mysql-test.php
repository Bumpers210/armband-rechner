<?php
declare(strict_types=1);

// CLI-only boundary proof in the explicitly configured EMPTY restore target.
// Reuses preservation/ownership guards, never initializes the UI or a provider.
require_once __DIR__ . '/cart-ui-acceptance.php';

function cart_limit_reject(PDO $pdo, callable $action): void
{
    $before = cart_probe_fingerprint($pdo);
    $rejected = false;
    try { $action(); }
    catch (CarmajaCommerceException $e) {
        $rejected = $e->errorCode === 'cart_quantity_limit' && $e->httpStatus === 422;
    }
    cart_probe_assert($rejected && !$pdo->inTransaction()
        && cart_probe_fingerprint($pdo) === $before, 'limit_rejection_mutated_data');
}

function cart_limit_mysql(): array
{
    cart_probe_assert(PHP_SAPI === 'cli' && PHP_VERSION_ID >= 80400, 'php84_cli_required');
    $root = cart_ui_root();
    cart_probe_assert(!file_exists($root . '/owner.json'), 'limit_owner_exists');
    $config = cart_ui_targets();
    $pdo = cart_probe_connect($config['restore'], $config);
    cart_probe_assert(cart_probe_empty($pdo), 'limit_restore_not_empty');
    $protected = cart_ui_protected();
    $bundle = cart_ui_read('legal.json');
    cart_probe_assert($bundle['id'] === 'cmj-test-legal-2026-10-25-v4'
        && $bundle['status'] === 'test_only' && $bundle['environment'] === 'test', 'limit_legal_invalid');
    $hashInput = $bundle; unset($hashInput['contentHash']);
    cart_probe_assert(hash_equals($bundle['contentHash'], carmaja_commerce_request_hash($hashInput)), 'limit_legal_hash_invalid');
    // Persist ownership before DDL; finally cleans even a partial migration.
    cart_ui_write($root . '/owner.json', ['testOnly'=>true, 'restoreWasEmpty'=>true,
        'stage'=>basename($root), 'expires'=>time()+1800, 'protected'=>$protected]);
    try {
        $c = new CarmajaCartCommerce($pdo);
        $c->migrate(dirname(__DIR__) . '/database/commerce-schema.sql');
        foreach (['commerce-v1-ap4-shop','commerce-v1-ap5-admin','commerce-v2-collections','commerce-v3-cart-inventory'] as $id) {
            $c->migrateForward($id, dirname(__DIR__) . '/database/migrations/' . $id . '.sql');
        }
        // Internal SQL approval is solely an artificial test fixture, never a legal release.
        $pdo->prepare('INSERT INTO legal_bundles(legal_bundle_id,terms_version,privacy_version,withdrawal_version,shipping_version,merchant_version,bundle_hash,status) VALUES (?,?,?,?,?,?,?,?)')
            ->execute([$bundle['id'],'v4','v4','v4','v4','v4',$bundle['contentHash'],'approved']);
        foreach (['A','B'] as $label) {
            $id = 'CART-LIMIT-' . $label;
            $product = ['productId'=>$id,'productModelVersion'=>4,'productVersion'=>1,'sourceHash'=>hash('sha256',$id),
                'title'=>'Artificial limit test '.$label,'description'=>'No real goods','materials'=>['Test'],'metalElements'=>[],
                'braceletSizeCm'=>18,'pearlSizeMm'=>8,'careInstructions'=>[],'images'=>[['src'=>'/images/products/test/01.jpg']],
                'priceMinor'=>2800,'currency'=>'eur','salesEnabled'=>true,
                'noticePolicyId'=>'natural-stones-v1','notices'=>CARMAJA_PRODUCT_NOTICES];
            $c->projectCollectionProduct($product,'publish','limit-publish-'.$label,hash('sha256',$id));
            $c->adjustInventory(['productId'=>$id,'targetOnHand'=>50,'expectedInventoryVersion'=>0,
                'reason'=>'initial_count','correlationId'=>'limit-initial-'.$label,'idempotencyKey'=>'limit-initial-'.$label], 'artificial-limit-operator');
        }
        $owner = cart_probe_session($c, 'limit-ten');
        $items = [['productId'=>'CART-LIMIT-A','quantity'=>6],['productId'=>'CART-LIMIT-B','quantity'=>4]];
        $cart = $c->replaceCart($owner,['items'=>$items,'expectedCartVersion'=>0],'limit-cart-ten');
        $shipping = ['shippingMethodId'=>'maxibrief','amountMinor'=>270,'currency'=>'eur','country'=>'DE'];
        $quote = $c->quoteCart($owner,$shipping,$bundle['id']);
        cart_probe_assert($quote['snapshot']['legalBundleHash'] === $bundle['contentHash'], 'limit_snapshot_legal_mismatch');
        $body = ['expectedCartVersion'=>$cart['cartVersion'],'checkoutContextToken'=>$quote['checkoutContextToken']];
        $reservation = $c->reserveCart($owner,$body,'limit-checkout-ten');
        cart_probe_assert($reservation['snapshot']['totalMinor'] === 28270
            && $c->inventory('CART-LIMIT-A')['reserved'] === 6
            && $c->inventory('CART-LIMIT-B')['reserved'] === 4, 'limit_ten_not_atomic');
        $beforeReplay = cart_probe_fingerprint($pdo);
        cart_probe_assert($c->reserveCart($owner,$body,'limit-checkout-ten')['reused'] === true
            && cart_probe_fingerprint($pdo) === $beforeReplay, 'limit_replay_mutated');
        $c->recordStripeCreationOutcome($reservation['snapshot']['checkoutId'],'failed');
        cart_probe_assert($c->inventory('CART-LIMIT-A')['reserved'] === 0
            && $c->inventory('CART-LIMIT-B')['reserved'] === 0, 'limit_release_incomplete');
        $tooMany = [['productId'=>'CART-LIMIT-A','quantity'=>6],['productId'=>'CART-LIMIT-B','quantity'=>5]];
        cart_limit_reject($pdo,fn()=>$c->replaceCart($owner,['items'=>$tooMany,'expectedCartVersion'=>$cart['cartVersion']],'limit-eleven'));
        // Simulate a cart persisted before the new limit, not an application write path.
        $pdo->prepare('UPDATE shop_carts SET items=? WHERE session_hash=?')->execute([json_encode($tooMany,JSON_THROW_ON_ERROR),$owner]);
        $old = $c->readCart($owner);
        cart_probe_assert(!$old['canCheckout'] && $old['quantityLimitExceeded']
            && array_sum(array_column($old['items'],'quantity')) === 11, 'limit_old_cart_changed');
        cart_limit_reject($pdo,fn()=>$c->quoteCart($owner,$shipping,$bundle['id']));
        cart_limit_reject($pdo,fn()=>$c->reserveCart($owner,$body,'limit-old-cart'));
        // Existing idempotency binding still wins over the changed cart.
        $beforeReplay = cart_probe_fingerprint($pdo);
        cart_probe_assert($c->reserveCart($owner,$body,'limit-checkout-ten')['reused'] === true
            && cart_probe_fingerprint($pdo) === $beforeReplay, 'limit_existing_binding_lost');
        $pdo->prepare('UPDATE shop_carts SET items=? WHERE session_hash=?')->execute([json_encode($items,JSON_THROW_ON_ERROR),$owner]);
        $oversized = $quote['snapshot']; $oversized['items'][1]['quantity'] = 5;
        $pdo->prepare('UPDATE cart_quotes SET snapshot=? WHERE token_hash=?')->execute([
            json_encode($oversized,JSON_THROW_ON_ERROR),carmaja_shop_token_hash($body['checkoutContextToken'])]);
        cart_limit_reject($pdo,fn()=>$c->reserveCart($owner,$body,'limit-old-quote'));
        $c->replaceCart($owner,['items'=>[],'expectedCartVersion'=>$cart['cartVersion']],'limit-clear');
        cart_probe_assert($c->readCart($owner)['items'] === []
            && (int)$pdo->query('SELECT COUNT(*) FROM orders')->fetchColumn() === 0
            && (int)$pdo->query('SELECT COUNT(*) FROM mail_outbox')->fetchColumn() === 0, 'limit_unexpected_side_effect');
        cart_probe_assert(cart_ui_protected() === $protected, 'limit_protected_changed');
        $result = ['status'=>'passed','mysql8Tls'=>true,'tenReservedAtomically'=>true,'shippingOnce270'=>true,
            'legalV4Bound'=>true,'elevenRejectedWithoutWrites'=>true,'oldCartAndQuoteRejected'=>true,
            'idempotentBindingPreserved'=>true,'cartClearable'=>true,'providerAccess'=>false];
    } finally {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        $cleanup = cart_ui_cleanup();
    }
    return [...$result,'cleanup'=>$cleanup];
}

if (PHP_SAPI === 'cli' && realpath((string)($_SERVER['SCRIPT_FILENAME']??'')) === __FILE__) {
    ini_set('display_errors','0'); ini_set('log_errors','0'); umask(0077);
    try { echo json_encode(cart_limit_mysql(),JSON_THROW_ON_ERROR)."\n"; }
    catch (Throwable $e) { cart_probe_report($e); exit(1); }
}
