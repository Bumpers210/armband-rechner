<?php
// Private, read-only CLI probe. Never place this script in the public webroot.
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
ini_set('display_errors', '0');
try {
    if (PHP_MAJOR_VERSION !== 8 || PHP_MINOR_VERSION !== 4) throw new RuntimeException('PHP_84_REQUIRED');
    if (!filter_var(ini_get('allow_url_fopen'), FILTER_VALIDATE_BOOLEAN)
        || !extension_loaded('openssl') || !in_array('https', stream_get_wrappers(), true)) {
        throw new RuntimeException('HTTPS_STREAM_REQUIRED');
    }
    $options = getopt('', ['webroot:', 'verify-render']);
    $root = realpath($options['webroot'] ?? '');
    if ($root === false || !is_dir($root) || !isset($options['webroot'])) throw new RuntimeException('WEBROOT_REQUIRED');
    $read = static function (string $file, int $limit): string {
        $body = @file_get_contents($file, false, null, 0, $limit + 1);
        if (!is_string($body) || strlen($body) > $limit) throw new RuntimeException('EXPORT_UNREADABLE');
        return $body;
    };
    // This checks the file, not Apache's effective configuration. HTTP checks remain required.
    $internalRules = $read($root . '/_internal/.htaccess', 16384);
    if (!preg_match('/^\s*Require\s+all\s+denied\s*$/mi', $internalRules)) throw new RuntimeException('INTERNAL_GUARD_MISSING');
    $sitemap = $read($root . '/sitemap.xml', 1048576);
    preg_match_all('~<loc>(https://www\.carmaja-perlen\.de/armbaender/cp-[0-9]{4}-[0-9]{4}-[a-z0-9-]+/)</loc>~', $sitemap, $matches);
    $urls = $matches[1];
    if (!$urls || count($urls) !== count(array_unique($urls))) throw new RuntimeException('PRODUCT_SITEMAP_INVALID');
    require __DIR__ . '/../product-offer-hosting/_internal/product-offers.php';
    $checked = []; $durations = [];
    foreach ($urls as $url) {
        $uri = substr($url, strlen(\Carmaja\ProductOffers\SITE));
        $file = realpath($root . $uri . 'index.html');
        if ($file === false || !str_starts_with($file, $root . DIRECTORY_SEPARATOR)) throw new RuntimeException('PRODUCT_FILE_INVALID');
        $html = $read($file, 2000000);
        if (!preg_match('/\bdata-product-id="([a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12})"/', $html, $id)) throw new RuntimeException('PRODUCT_ID_MISSING');
        $start = hrtime(true);
        $live = \Carmaja\ProductOffers\fetchLiveProduct($id[1]);
        $durations[] = round((hrtime(true) - $start) / 1000000, 1);
        if (isset($options['verify-render'])) {
            $reply = \Carmaja\ProductOffers\render($root, $uri, 'GET', static fn() => $live);
            if ($reply['status'] !== 200 || $reply['headers']['Cache-Control'] !== 'no-store') throw new RuntimeException('RENDER_FAILED');
            preg_match_all('~<script\b(?=[^>]*\btype="application/ld\+json")[^>]*>(.*?)</script>~s', $reply['body'], $scripts);
            $products = [];
            foreach ($scripts[1] as $raw) {
                $data = json_decode($raw, false, 32, JSON_THROW_ON_ERROR);
                if (($data->{'@type'} ?? '') === 'Product') $products[] = $data;
            }
            if (count($products) !== 1) throw new RuntimeException('PRODUCT_SCHEMA_INVALID');
            $offer = $products[0]->offers ?? null;
            $price = intdiv($live->priceMinor, 100) . '.' . str_pad((string) ($live->priceMinor % 100), 2, '0', STR_PAD_LEFT);
            if (($offer->price ?? '') !== $price || ($offer->priceCurrency ?? '') !== 'EUR'
                || ($offer->url ?? '') !== $url
                || ($offer->availability ?? '') !== 'https://schema.org/' . ($live->available ? 'InStock' : 'OutOfStock')
                || !is_object($offer->hasMerchantReturnPolicy ?? null) || !is_object($offer->shippingDetails ?? null)
                || !str_contains($reply['body'], number_format($live->priceMinor / 100, 2, ',', '.') . "\u{00a0}€")) {
                throw new RuntimeException('OFFER_MISMATCH');
            }
        }
        $checked[] = $url;
    }
    echo json_encode(['result' => 'PRODUCT_OFFER_PREFLIGHT_OK', 'php' => PHP_VERSION, 'products' => count($checked),
        'renderVerified' => isset($options['verify-render']), 'apiMinMs' => min($durations), 'apiMaxMs' => max($durations),
        'scope' => 'CLI and read-only product GETs only; web PHP/Apache configuration not verified'], JSON_THROW_ON_ERROR) . "\n";
} catch (Throwable $error) {
    $known = ['PHP_84_REQUIRED', 'HTTPS_STREAM_REQUIRED', 'WEBROOT_REQUIRED', 'EXPORT_UNREADABLE', 'INTERNAL_GUARD_MISSING',
        'PRODUCT_SITEMAP_INVALID', 'PRODUCT_FILE_INVALID', 'PRODUCT_ID_MISSING', 'RENDER_FAILED', 'PRODUCT_SCHEMA_INVALID', 'OFFER_MISMATCH'];
    $code = in_array($error->getMessage(), $known, true) ? $error->getMessage() : 'LIVE_API_OR_TEMPLATE_UNAVAILABLE';
    fwrite(STDERR, "PRODUCT_OFFER_PREFLIGHT_FAILED: " . $code . "\n");
    exit(1);
}
