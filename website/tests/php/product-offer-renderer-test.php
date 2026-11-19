<?php
declare(strict_types=1);
require_once __DIR__ . '/../../product-offer-hosting/_internal/product-offers.php';
use function Carmaja\ProductOffers\{render, json, validatedProduct};

$count = 0;
function offer_assert(bool $condition, string $message): void {
    global $count; $count++;
    if (!$condition) throw new RuntimeException($message);
}
$id = '11111111-1111-4111-8111-111111111111';
$slug = 'cp-2026-9999-renderer-test';
$url = 'https://www.carmaja-perlen.de/armbaender/' . $slug . '/';
$schema = (object) ['@context' => 'https://schema.org', '@type' => 'Product', '@id' => $url . '#product',
    'name' => 'Isolierte Testfixture', 'description' => 'Sicher </script><script>alert(1)</script>',
    'sku' => 'CP-2026-9999', 'url' => $url];
$policy = (object) ['@type' => 'MerchantReturnPolicy', '@id' => 'https://www.carmaja-perlen.de/#return-policy',
    'merchantReturnDays' => 14, 'returnFees' => 'https://schema.org/FreeReturn'];
$org = (object) ['@type' => 'Organization', '@id' => 'https://www.carmaja-perlen.de/#organization',
    'hasMerchantReturnPolicy' => $policy,
    'hasShippingService' => (object) ['@id' => 'https://www.carmaja-perlen.de/#shipping-service']];
$html = '<!doctype html><html><head></head><body><article data-product-id="' . $id . '">'
    . '<script type="application/ld+json">' . json($org) . '</script>'
    . '<script id="cmj-offer-seed-' . $id . '" type="application/json">null</script>'
    . '<script type="application/ld+json" id="cmj-offer-schema-' . $id . '">' . json($schema) . '</script>'
    . '<div class="shop-live-offer" id="cmj-offer-box-' . $id . '"><p>Platzhalter</p></div>'
    . '<button disabled="">In den Warenkorb</button></article></body></html>';
$root = sys_get_temp_dir() . '/cmj-offer-test-' . bin2hex(random_bytes(6));
mkdir($root . '/armbaender/' . $slug, 0700, true);
$file = $root . '/armbaender/' . $slug . '/index.html';
file_put_contents($file, $html);
$live = (object) ['productId' => $id, 'priceMinor' => 1234, 'currency' => 'eur', 'available' => true,
    'availableQuantity' => 4, 'privateField' => 'PRIVATE_SENTINEL'];
$extract = static function (string $body, string $tagId): object {
    preg_match('~<script\b(?=[^>]*id="' . preg_quote($tagId, '~') . '")[^>]*>(.*?)</script>~s', $body, $m);
    return json_decode($m[1], false, 32, JSON_THROW_ON_ERROR);
};
try {
    $packet = ['headers'=>['HTTP/1.1 200 OK'],'body'=>json_encode(['product'=>$live]),'timedOut'=>false];
    $captured = [];
    $transport = static function(string $endpoint,array $options) use(&$captured,$packet):array { $captured=[$endpoint,$options]; return $packet; };
    $networkProduct = \Carmaja\ProductOffers\fetchLiveProduct($id,$transport);
    offer_assert($networkProduct == validatedProduct($live,$id), 'Validate public transport response.');
    offer_assert($captured[0] === 'https://api.carmaja-perlen.de/shop/v3/products/'.$id, 'Fixed existing API host and UUID.');
    offer_assert($captured[1]['http']['method']==='GET' && $captured[1]['http']['timeout']===3 && $captured[1]['http']['follow_location']===0, 'Read-only bounded transport; no redirects.');
    offer_assert($captured[1]['ssl']===['verify_peer'=>true,'verify_peer_name'=>true,'allow_self_signed'=>false], 'TLS validation never disabled.');
    offer_assert(!str_contains(strtolower($captured[1]['http']['header']),'cookie:') && !str_contains(strtolower($captured[1]['http']['header']),'authorization:'), 'No visitor credentials forwarded.');
    foreach ([['headers'=>['HTTP/1.1 302 Found']],['body'=>str_repeat('x',32769)],['body'=>'bad JSON'],['timedOut'=>true],['body'=>json_encode(['product'=>['productId'=>'wrong']])]] as $mutation) {
        $rejected=false;
        try { \Carmaja\ProductOffers\fetchLiveProduct($id,static fn()=>array_replace($packet,$mutation)); } catch(Throwable) { $rejected=true; }
        offer_assert($rejected,'Malformed/redirected/timed-out transport must fail.');
    }
    $attempts = 0;
    $recovered = \Carmaja\ProductOffers\fetchLiveProduct($id, static function () use (&$attempts, $packet): array {
        if (++$attempts === 1) throw new RuntimeException('Transient transport timeout');
        return $packet;
    });
    offer_assert($attempts === 2 && $recovered->priceMinor === 1234, 'One retry recovers a transient transport failure.');
    foreach ([['headers'=>['HTTP/1.1 503 Service Unavailable']], ['timedOut'=>true]] as $transient) {
        $attempts = 0;
        $recovered = \Carmaja\ProductOffers\fetchLiveProduct($id, static function () use (&$attempts, $packet, $transient): array {
            return ++$attempts === 1 ? array_replace($packet, $transient) : $packet;
        });
        offer_assert($attempts === 2 && $recovered->productId === $id, 'One retry recovers timeout/5xx without stale data.');
    }
    foreach ([['headers'=>['HTTP/1.1 429 Too Many Requests']], ['headers'=>['HTTP/1.1 403 Forbidden']], ['body'=>'invalid JSON']] as $permanent) {
        $attempts = 0; $rejected = false;
        try { \Carmaja\ProductOffers\fetchLiveProduct($id, static function () use (&$attempts, $packet, $permanent): array {
            $attempts++; return array_replace($packet, $permanent);
        }); } catch (Throwable) { $rejected = true; }
        offer_assert($rejected && $attempts === 1, 'Rate limits, access denial and invalid data are never retried.');
    }
    $attempts = 0; $rejected = false;
    try { \Carmaja\ProductOffers\fetchLiveProduct($id, static function () use (&$attempts): never { $attempts++; throw new RuntimeException('Still offline'); }); }
    catch (Throwable) { $rejected = true; }
    offer_assert($rejected && $attempts === 2, 'An outage stays bounded at two attempts.');
    $calls = 0;
    $load = static function (string $requested) use (&$calls, $id, $live): object {
        offer_assert($requested === $id, 'Only template UUID may be requested.'); $calls++; return $live;
    };
    $reply = render($root, '/armbaender/' . $slug . '/?api=https://invalid.example&productId=invalid', 'GET', $load);
    offer_assert($reply['status'] === 200 && $calls === 1, 'Exactly one live read.');
    offer_assert($reply['headers']['Cache-Control'] === 'no-store', 'No-store required.');
    $product = $extract($reply['body'], 'cmj-offer-schema-' . $id);
    $seed = $extract($reply['body'], 'cmj-offer-seed-' . $id);
    offer_assert($product->offers->price === '12.34' && $product->offers->priceCurrency === 'EUR', 'Correct cents and EUR.');
    offer_assert($product->offers->availability === 'https://schema.org/InStock', 'Truthful in-stock status.');
    offer_assert($product->offers->hasMerchantReturnPolicy == $policy, 'Reuse published policy.');
    offer_assert(str_contains($reply['body'], "12,34\u{00a0}€"), 'Visible and structured prices match.');
    offer_assert(!str_contains($reply['body'], 'PRIVATE_SENTINEL'), 'Private fields discarded.');
    offer_assert(!str_contains($reply['body'], '<script>alert'), 'Script termination escaped.');
    offer_assert($seed->product == validatedProduct($live, $id), 'Shared public projection.');
    offer_assert(str_contains($reply['body'], '<button disabled="">'), 'Buy remains disabled until browser validation.');
    $live->priceMinor = 4567; $live->available = false; $live->availableQuantity = 0;
    $changed = render($root, '/armbaender/' . $slug . '/', 'GET', $load);
    $product = $extract($changed['body'], 'cmj-offer-schema-' . $id);
    offer_assert($product->offers->price === '45.67' && $product->offers->availability === 'https://schema.org/OutOfStock', 'Next request reads new price/stock without rebuilding.');
    offer_assert($calls === 2, 'No application cache.');
    $bad = [null, (object) [], (object) array_merge((array) $live, ['productId' => 'wrong']),
        (object) array_merge((array) $live, ['currency' => 'usd']),
        (object) array_merge((array) $live, ['priceMinor' => 0]),
        (object) array_merge((array) $live, ['priceMinor' => 12.34]),
        (object) array_merge((array) $live, ['available' => true, 'availableQuantity' => 0]),
        (object) array_merge((array) $live, ['availableQuantity' => -1])];
    foreach ($bad as $value) {
        $failed = render($root, '/armbaender/' . $slug . '/', 'GET', static fn() => $value);
        offer_assert($failed['status'] === 503 && $failed['headers']['Retry-After'] === '60', 'Invalid data must be retryable 503.');
        offer_assert(!isset($extract($failed['body'], 'cmj-offer-schema-' . $id)->offers), 'No fabricated offer.');
        offer_assert($extract($failed['body'], 'cmj-offer-seed-' . $id)->failed === true, 'Hydration receives failure state.');
    }
    $failed = render($root, '/armbaender/' . $slug . '/', 'GET', static function (): never { throw new RuntimeException('SECRET_INTERNAL_PATH'); });
    offer_assert($failed['status'] === 503 && !str_contains($failed['body'], 'SECRET_INTERNAL_PATH'), 'No exception details leak.');
    foreach (['/product-offer.php?slug=' . $slug, '/armbaender/../../private-data/', '/armbaender/%2e%2e/', '/armbaender/cp-2026-0001-missing/'] as $path) {
        offer_assert(render($root, $path, 'GET', $load)['status'] === 404, 'Reject unknown/traversal paths.');
    }
    offer_assert(render($root, '/armbaender/' . $slug . '/', 'POST', $load)['status'] === 405, 'Read-only endpoint.');
    offer_assert(render($root, '/armbaender/' . $slug . '/index.html', 'GET', $load)['status'] === 301, 'Direct HTML canonical redirect.');
    file_put_contents($file, str_replace('type="application/json">null</script>', 'type="application/json">null</script><script id="cmj-offer-seed-' . $id . '" type="application/json">null</script>', $html));
    offer_assert(render($root, '/armbaender/' . $slug . '/', 'GET', $load)['status'] === 503, 'Ambiguous template fails closed.');
    echo 'PRODUCT_OFFERS_PHP_OK: ' . $count . " assertions\n";
} finally {
    $real = realpath($root); $temp = realpath(sys_get_temp_dir());
    if ($real === false || $temp === false || !str_starts_with($real, $temp . DIRECTORY_SEPARATOR . 'cmj-offer-test-')) throw new RuntimeException('Unsafe cleanup.');
    unlink($file); rmdir(dirname($file)); rmdir($root . '/armbaender'); rmdir($root);
}
