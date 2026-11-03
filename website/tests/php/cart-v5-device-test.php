<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/scripts/cart-v5-device-test.php';
$checks = 0;
$assert = static function(bool $condition) use (&$checks): void { if (!$condition) { throw new RuntimeException('v5_guard_test_failed'); } $checks++; };
$base = "Options -Indexes\nRewriteEngine On\nRewriteRule ^ index.php [QSA,L]\n";
$bound = cart_v5_rewrite($base, 'cart-e2e-artificial-v5');
$assert(str_contains($bound, '^v5(?:/.*)?$ cart-e2e-artificial-v5/index.php'));
$assert(str_ends_with($bound, "RewriteRule ^ index.php [QSA,L]\n"));
foreach (['../production','cart-e2e-x/v5','cart-e2e-x'] as $stage) {
    $rejected = false; try { cart_v5_rewrite($base, $stage); } catch(RuntimeException) { $rejected = true; } $assert($rejected);
}
foreach ([$bound, '', "RewriteEngine On\nRewriteRule ^v5/ elsewhere.php"] as $text) {
    $rejected = false; try { cart_v5_rewrite($text, 'cart-e2e-artificial-v5'); } catch(RuntimeException) { $rejected = true; } $assert($rejected);
}
$id = '99000000-0000-4000-8000-000000000001';
foreach ([['blockedIds'=>[$id]], ['blockedIds'=>[]]] as $index => $owner) {
    $rejected = false; try { cart_v5_guard_product($id, $owner, ['name'=>'HANDY-TEST']); } catch(CarmajaApiException) { $rejected = true; } $assert($rejected === ($index === 0));
}
foreach (['Echtes Armband', '', '../HANDY-TEST'] as $name) {
    $rejected = false; try { cart_v5_guard_product($id, ['blockedIds'=>[]], ['name'=>$name]); } catch(CarmajaApiException) { $rejected = true; } $assert($rejected);
}
foreach (['HANDY-TEST','HANDY-TEST 2','V5-PROBE Backend'] as $name) { cart_v5_guard_product($id, ['blockedIds'=>[]], ['name'=>$name]); $assert(true); }
$source = file_get_contents(dirname(__DIR__, 2) . '/scripts/cart-v5-device-test.php');
foreach (['v5_targets_must_be_empty','v5_test_identity_invalid','v5_stage_expired','existing_product_protected',
    'cart_v5_protected() ===','PASSWORD_ARGON2ID','86400','no-store','private_local_only','productApiV5WritesEnabled'] as $guard) { $assert(str_contains($source, $guard)); }
$assert(str_contains($source, 'carmaja_bootstrap_send($e->httpStatus'));
echo "PASS isolated V5 device-test guards: $checks assertions\n";
