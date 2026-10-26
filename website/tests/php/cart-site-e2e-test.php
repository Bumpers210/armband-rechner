<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/scripts/cart-site-e2e.php';
$original = "Options -Indexes\nRewriteEngine On\nRewriteRule ^ index.php [QSA,L]\n";
$bound = cart_site_rewrite($original, 'cart-e2e-artificial');
if (!str_contains($bound, 'RewriteRule ^shop/v3(?:/.*)?$ cart-e2e-artificial/index.php [QSA,L]')
    || !str_ends_with($bound, 'RewriteRule ^ index.php [QSA,L]' . "\n")) { throw new RuntimeException('routing_contract'); }
foreach (['../production', 'cart-e2e-x/../../production', 'production'] as $bad) {
    $rejected = false; try { cart_site_rewrite($original, $bad); } catch (RuntimeException) { $rejected = true; }
    if (!$rejected) { throw new RuntimeException('unsafe_route_accepted'); }
}
foreach (['', $bound, "RewriteEngine On\nRewriteEngine On"] as $bad) {
    $rejected = false; try { cart_site_rewrite($bad, 'cart-e2e-artificial'); } catch (RuntimeException) { $rejected = true; }
    if (!$rejected) { throw new RuntimeException('ambiguous_route_accepted'); }
}
$source = (string) file_get_contents(dirname(__DIR__, 2) . '/scripts/cart-site-e2e.php');
foreach (['cart_site_fingerprint() ===', 'routing_changed_cleanup_refused', 'LOCK_EX | LOCK_NB',
    'mail_recipient_not_authorized', '40000', 'site-worker-runlog.jsonl', 'test_only_not_legal_approval'] as $guard) {
    if (!str_contains($source, $guard)) { throw new RuntimeException('guard_missing'); }
}
foreach ([1, 2] as $index) {
    $product = cart_site_fixture_product($index, 2048, 1536);
    if ($product['productModelVersion'] !== 4 || $product['currency'] !== 'eur'
        || $product['braceletSizeCm'] !== 18 || $product['pearlSizeMm'] !== 8
        || $product['notices'] !== CARMAJA_PRODUCT_NOTICES
        || $product['descriptionDocument']['blocks'][0]['spans'][0]['font'] !== 'standard'
        || isset($product['stock']) || isset($product['vintedUrl'])
        || !preg_match('/^[a-f0-9-]{36}$/D', $product['productId'])) { throw new RuntimeException('fixture_contract'); }
}
$rejected = false; try { cart_site_fixture_product(3, 2048, 1536); } catch (RuntimeException) { $rejected = true; }
if (!$rejected) { throw new RuntimeException('fixture_index_accepted'); }
foreach (['_next/static/chunks/own.js', '_next/static/media/icon.15g66t0p2syop.svg',
    'armbaender/warenkorb-e2e-336033b5-1/__next.armbaender/$d$slug/__PAGE__.txt',
    'armbaender/warenkorb-e2e-336033b5-1/index.html', 'images/products/CP-2099-9002/01.jpg'] as $path) {
    if (!cart_site_fixture_path($path)) { throw new RuntimeException('fixture_path_rejected'); }
}
foreach (['../production/a.js', '_next/static/../../index.php', 'products.json', '.htaccess',
    'armbaender/real-product/index.html', '_next/static/media/own.php',
    '_next/static/media/unknown.svg', '/images/products/CP-2099-9002/01.jpg'] as $path) {
    if (cart_site_fixture_path($path)) { throw new RuntimeException('fixture_unsafe_path_accepted'); }
}
foreach (['shop/success/index.html', 'warenkorb/index.html', 'widerruf/__next.widerruf.txt', '_next/static/chunks/own.js'] as $path) {
    if (!cart_site_flow_path($path)) { throw new RuntimeException('flow_path_rejected'); }
}
foreach (['armbaender/real/index.html', 'images/products/real/01.jpg', '.htaccess', 'shop/../admin/index.html', '_next/static/own.php'] as $path) {
    if (cart_site_flow_path($path)) { throw new RuntimeException('flow_unsafe_path_accepted'); }
}
foreach (['flow_changed_cleanup_refused', 'unrelated_site_changed', 'old_tab_token_rejected', 'preview_submitted_declaration'] as $guard) {
    if (!str_contains($source, $guard)) { throw new RuntimeException('flow_guard_missing'); }
}
$one = ['ok' => true, 'cart' => ['cartVersion' => 1, 'items' => [['quantity' => 2, 'priceMinor' => 2800, 'responseAt' => 'first']]]];
$two = $one; $two['cart']['items'][0]['responseAt'] = 'second';
if (cart_site_replay_response($one) !== cart_site_replay_response($two)) { throw new RuntimeException('volatile_time_not_excluded'); }
foreach (['quantity', 'priceMinor'] as $field) {
    $changed = $two; $changed['cart']['items'][0][$field]++;
    if (cart_site_replay_response($one) === cart_site_replay_response($changed)) { throw new RuntimeException('business_replay_difference_hidden'); }
}
$changed = $two; $changed['cart']['cartVersion']++;
if (cart_site_replay_response($one) === cart_site_replay_response($changed)) { throw new RuntimeException('replay_version_difference_hidden'); }
$changed = $two; $changed['unexpected'] = true;
if (cart_site_replay_response($one) === cart_site_replay_response($changed)) { throw new RuntimeException('unexpected_replay_field_hidden'); }
$cron = (string) file_get_contents(dirname(__DIR__, 2) . '/scripts/cart-cron-test.php');
foreach (['PHP_SAPI !== \'cli\'', 'PHP_VERSION_ID < 80400', 'isolated_stage_required',
    'private_owner_required', 'site_stage_expired', 'cron_marker_unsafe', '0600',
    'cron_bootstrap_failure'] as $guard) {
    if (!str_contains($cron, $guard)) { throw new RuntimeException('cron_guard_missing'); }
}
if (strpos($cron, 'fwrite($handle, $line)') > strpos($cron, "require_once __DIR__ . '/cart-site-e2e.php'")) {
    throw new RuntimeException('cron_marker_after_bootstrap');
}
echo "PASS cart test-site routing, preservation, recipient, runtime, worker, fixture, flow, replay and cron guards: 57 assertions\n";
