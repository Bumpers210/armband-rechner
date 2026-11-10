<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/test-api-private/program/production-cart-cutover.php';
require_once dirname(__DIR__, 2) . '/test-api-private/program/shop-checkout.php';
require_once dirname(__DIR__, 2) . '/test-api-private/program/cart-public.php';

$checks = 0;
function release_check(bool $condition, string $name): void {
    global $checks;
    if (!$condition) { throw new RuntimeException($name); }
    $checks++;
}
function release_reject(callable $call, string $name): void {
    try { $call(); } catch (RuntimeException) { release_check(true, $name); return; }
    throw new RuntimeException('Expected rejection: ' . $name);
}
$template = json_decode(file_get_contents(dirname(__DIR__, 2) . '/config/production-cart-cutover-manifest.v3.json'), true);
$release = ['formatVersion'=>1, 'releaseKind'=>'cart-production', 'legalBundleId'=>CarmajaCartCutover::LEGAL_ID,
    'sourceCommit'=>str_repeat('a',40), 'workingTreeDirty'=>false, 'legalBundleHash'=>str_repeat('b',64),
    'migrations'=>array_map(fn ($id) => ['migrationId'=>$id, 'journalSha256'=>str_repeat('c',64)], CarmajaCartCutover::MIGRATIONS)];
release_check(!CarmajaCartCutover::plan($template, [], $release)['readyForApply'], 'empty template fail closed');
$product = ['productModelVersion'=>4, 'productId'=>'artificial-0001', 'productVersion'=>2,
    'name'=>'Kuenstlich', 'title'=>'Kuenstlich', 'description'=>'Only local tests', 'descriptionDocument'=>['paragraphs'=>[]],
    'priceMinor'=>2800, 'currency'=>'eur', 'salesEnabled'=>false, 'braceletSizeCm'=>18, 'pearlSizeMm'=>8,
    'images'=>[['fileName'=>'01.jpg']], 'noticePolicyId'=>null, 'notices'=>[]];
$product['sourceHash'] = carmaja_api_v3_source_hash($product);
$selection = ['productId'=>$product['productId'], 'expectedProductVersion'=>2, 'expectedSourceHash'=>$product['sourceHash'],
    'targetOnHand'=>3, 'expectedInventoryVersion'=>0, 'operationId'=>'artificial-count-0001'];
$manifest = [...$template, 'status'=>'approved_for_cutover', 'releaseSha'=>str_repeat('a',40),
    'legalBundleHash'=>str_repeat('b',64), 'backupId'=>'20261110T120000Z-abcdef123456',
    'restoreProofSha256'=>str_repeat('d',64), 'countConfirmedAt'=>'2026-11-10T12:00:00Z',
    'actorId'=>'local-test-operator', 'selectedProducts'=>[$selection]];
$plan = CarmajaCartCutover::plan($manifest, [$product], $release);
release_check($plan['readyForApply'] && !$plan['checkoutActivation'], 'separate approval never enables checkout');
release_check(!$plan['operations'][0]['product']['salesEnabled'], 'sales permission unchanged');
release_check($plan['operations'][0]['product']['notices'] === [], 'legacy product gets no new notices');
release_check($plan === CarmajaCartCutover::plan($manifest, [$product], $release), 'deterministic plan');
foreach (['not_approved','approved_for_plan',null] as $status) {
    release_check(!CarmajaCartCutover::plan([...$manifest, 'status'=>$status], [$product], $release)['readyForApply'], 'no implicit cutover approval');
}
release_check(!CarmajaCartCutover::plan($manifest, [$product], [...$release, 'workingTreeDirty'=>true])['readyForApply'], 'dirty source cannot be released');
release_check(!CarmajaCartCutover::plan([...$manifest, 'backupId'=>null], [$product], $release)['readyForApply'], 'backup required');
release_check(!CarmajaCartCutover::plan([...$manifest, 'restoreProofSha256'=>null], [$product], $release)['readyForApply'], 'restore proof required');
foreach ([-1,1.5,'3',4294967296] as $amount) {
    release_reject(fn () => CarmajaCartCutover::plan([...$manifest, 'selectedProducts'=>[[...$selection,'targetOnHand'=>$amount]]], [$product], $release), 'invalid inventory amount');
}
release_check(CarmajaCartCutover::plan([...$manifest, 'selectedProducts'=>[[...$selection,'targetOnHand'=>0]]], [$product], $release)['readyForApply'], 'confirmed zero permitted');
release_reject(fn () => CarmajaCartCutover::plan([...$manifest, 'selectedProducts'=>[$selection,$selection]], [$product], $release), 'duplicate selection');
release_reject(fn () => CarmajaCartCutover::plan($manifest, [$product,$product], $release), 'duplicate source');
release_reject(fn () => CarmajaCartCutover::plan($manifest, [[...$product,'priceMinor'=>1]], $release), 'recomputed source hash required');
release_reject(fn () => CarmajaCartCutover::plan($manifest, [[...$product,'stock'=>1]], $release), 'legacy stock rejected');
release_reject(fn () => CarmajaCartCutover::plan([...$manifest, 'manifestVersion'=>2], [$product], $release), 'historical manifest rejected');
$maintenance = ['environment'=>'production','shopCheckoutEnabled'=>false,'productApiV5WritesEnabled'=>false,
    'productApiV4WritesEnabled'=>false,'productionPublishEnabled'=>false,'githubAdapterEnabled'=>false,'commerceRequireTls'=>true];
CarmajaCartCutover::requireMaintenance($maintenance);
foreach (['shopCheckoutEnabled','productApiV5WritesEnabled','productApiV4WritesEnabled','productionPublishEnabled','githubAdapterEnabled'] as $key) {
    release_reject(fn () => CarmajaCartCutover::requireMaintenance([...$maintenance,$key=>true]), 'maintenance gate required');
}
foreach ([[], ['environment'=>'production'], ['environment'=>'production','shopCheckoutEnabled'=>false],
    ['environment'=>'production','shopCheckoutEnabled'=>'true']] as $config) {
    release_reject(fn () => carmaja_shop_require_checkout_enabled($config), 'production checkout fail closed');
}
carmaja_shop_require_checkout_enabled(['environment'=>'test']);
carmaja_shop_require_checkout_enabled(['environment'=>'production','shopCheckoutEnabled'=>true]);
release_check(true, 'test compatibility and explicit production gate');
$quoted = ['canCheckout'=>true, 'checkoutContextToken'=>'artificial-quote', 'items'=>[['quantity'=>2]]];
$closed = carmaja_cart_checkout_view($quoted, ['environment'=>'production']);
release_check($closed['canCheckout'] === false && $closed['checkoutClosed'] === true
    && !isset($closed['checkoutContextToken']) && $closed['items'] === $quoted['items'], 'GET/PUT view closes checkout without losing cart');
release_check(carmaja_cart_checkout_view($quoted, ['environment'=>'test']) === $quoted, 'test cart response stays compatible');

// Deliberately uninitialized collaborators: touching PDO or Stripe would fail.
$unusedCommerce = (new ReflectionClass(CarmajaCartCommerce::class))->newInstanceWithoutConstructor();
$unusedStripe = (new ReflectionClass(CarmajaStripeGateway::class))->newInstanceWithoutConstructor();
foreach ([
    fn () => (new CarmajaCheckoutService($unusedCommerce, $unusedStripe, ['environment'=>'production']))->start([], ''),
    fn () => carmaja_cart_start($unusedCommerce, $unusedStripe, ['environment'=>'production'], '', [], ''),
] as $start) {
    try { $start(); throw new RuntimeException('closed checkout was accepted'); }
    catch (CarmajaCommerceException $error) {
        release_check($error->errorCode === 'checkout_temporarily_closed' && $error->httpStatus === 503,
            'closed legacy/cart checkout touches neither database nor provider');
    }
}
echo "Production cart cutover: {$checks} checks passed (offline, no MySQL claim).\n";
