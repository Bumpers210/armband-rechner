<?php
declare(strict_types=1);
namespace Carmaja\ProductOffers;

const SITE = 'https://www.carmaja-perlen.de';
const API = 'https://api.carmaja-perlen.de';
const MAX_JSON = 32768;

function validatedProduct(mixed $value, string $id): ?object
{
    if (!is_object($value) || ($value->productId ?? null) !== $id
        || ($value->currency ?? null) !== 'eur'
        || !is_int($value->priceMinor ?? null) || $value->priceMinor <= 0 || $value->priceMinor > 9007199254740991
        || !is_bool($value->available ?? null)
        || !is_int($value->availableQuantity ?? null) || $value->availableQuantity < 0 || $value->availableQuantity > 9007199254740991
        || ($value->available && $value->availableQuantity === 0)) return null;
    // Copy only the same public fields consumed by the browser, never API internals.
    return (object) ['productId' => $id, 'priceMinor' => $value->priceMinor, 'currency' => 'eur',
        'available' => $value->available, 'availableQuantity' => $value->availableQuantity];
}

function readPublicApi(string $url, array $options): array
{
    $stream = @fopen($url, 'rb', false, stream_context_create($options));
    if ($stream === false) throw new \RuntimeException('Offer unavailable.');
    try {
        $headers = $http_response_header ?? [];
        $body = stream_get_contents($stream, MAX_JSON + 1);
        $meta = stream_get_meta_data($stream);
        return ['headers' => $headers, 'body' => $body, 'timedOut' => $meta['timed_out'] ?? false];
    } finally { fclose($stream); }
}
function fetchLiveProduct(string $id, ?callable $transport = null): object
{
    if (!preg_match('/^[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}$/D', $id)) throw new \RuntimeException('Invalid product.');
    $options = [
        'http' => ['method' => 'GET', 'timeout' => 3, 'follow_location' => 0, 'max_redirects' => 0,
            'ignore_errors' => true, 'header' => "Origin: " . SITE . "\r\nAccept: application/json\r\nCache-Control: no-store\r\nConnection: close\r\n"],
        'ssl' => ['verify_peer' => true, 'verify_peer_name' => true, 'allow_self_signed' => false],
    ];
    // The optional transport is only a code-level test seam, never an HTTP/env parameter.
    $reply = ($transport ?? readPublicApi(...))(API . '/shop/v3/products/' . $id, $options);
    $body = $reply['body'] ?? false;
    if (!preg_match('~^HTTP/\S+ 200(?: |$)~', $reply['headers'][0] ?? '')
        || !is_string($body) || strlen($body) > MAX_JSON || ($reply['timedOut'] ?? false)) throw new \RuntimeException('Offer unavailable.');
    $response = json_decode($body, false, 16, JSON_THROW_ON_ERROR);
    $product = validatedProduct($response->product ?? null, $id);
    if ($product === null) throw new \RuntimeException('Invalid offer.');
    return $product;
}

function json(object|null $value): string
{
    return str_replace('<', '\u003c', json_encode($value,
        JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_LINE_TERMINATORS));
}
function headers(): array
{
    return ['Content-Type' => 'text/html; charset=utf-8', 'Cache-Control' => 'no-store',
        'X-Content-Type-Options' => 'nosniff'];
}
function failure(int $status): array
{
    return ['status' => $status, 'headers' => headers() + ($status === 503 ? ['Retry-After' => '60'] : []),
        'body' => '<!doctype html><html lang="de"><title>Produktseite</title><p>'
            . ($status === 404 ? 'Diese Seite wurde nicht gefunden.' : 'Die Produktseite ist momentan nicht erreichbar. Bitte später erneut versuchen.') . '</p></html>'];
}
function scriptPattern(string $id, string $type): string
{
    return '~(<script\b(?=[^>]*\bid="' . preg_quote($id, '~') . '")(?=[^>]*\btype="'
        . preg_quote($type, '~') . '")[^>]*>)(.*?)(</script>)~s';
}
function replaceScript(string $html, string $id, string $type, string $body): string
{
    $result = preg_replace_callback(scriptPattern($id, $type), static fn(array $m): string => $m[1] . $body . $m[3], $html, -1, $count);
    if ($result === null || $count !== 1) throw new \RuntimeException('Invalid template.');
    return $result;
}

/** Reads only the active export and the public live endpoint; writes no files or commerce data. */
function render(string $root, string $requestUri, string $method, callable $loadProduct): array
{
    if (!in_array($method, ['GET', 'HEAD'], true)) return ['status' => 405, 'headers' => headers() + ['Allow' => 'GET, HEAD'], 'body' => ''];
    $path = parse_url($requestUri, PHP_URL_PATH);
    if (!is_string($path) || strlen($requestUri) > 4096
        || !preg_match('~^/armbaender/(cp-[0-9]{4}-[0-9]{4}-[a-z0-9-]{1,140})/(index\.html)?$~D', $path, $route)) return failure(404);
    $realRoot = realpath($root);
    $directory = $realRoot . DIRECTORY_SEPARATOR . 'armbaender' . DIRECTORY_SEPARATOR . $route[1];
    $file = $directory . DIRECTORY_SEPARATOR . 'index.html';
    $realFile = realpath($file);
    if ($realRoot === false || $realFile === false || is_link($realRoot . DIRECTORY_SEPARATOR . 'armbaender')
        || is_link($directory) || is_link($file) || !str_starts_with($realFile, $realRoot . DIRECTORY_SEPARATOR)) return failure(404);
    $canonicalPath = '/armbaender/' . $route[1] . '/';
    if (!empty($route[2])) return ['status' => 301, 'headers' => headers() + ['Location' => $canonicalPath], 'body' => ''];
    try {
        $html = @file_get_contents($realFile, false, null, 0, 2000001);
        if ($html === false || strlen($html) > 2000000) throw new \RuntimeException('Invalid template.');
        if (!preg_match('/\bdata-product-id="([a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12})"/', $html, $match)) throw new \RuntimeException('Invalid template.');
        $id = $match[1];
        $seedId = 'cmj-offer-seed-' . $id;
        $schemaId = 'cmj-offer-schema-' . $id;
        // Informational pages without a buying section keep their existing static behavior.
        if (!str_contains($html, 'id="' . $seedId . '"')) return ['status' => 200, 'headers' => headers(), 'body' => $html];
        if (!preg_match(scriptPattern($seedId, 'application/json'), $html, $seedMatch)
            || trim($seedMatch[2]) !== 'null'
            || !preg_match(scriptPattern($schemaId, 'application/ld+json'), $html, $schemaMatch)) throw new \RuntimeException('Invalid template.');
        $schema = json_decode($schemaMatch[2], false, 32, JSON_THROW_ON_ERROR);
        if (($schema->{'@type'} ?? '') !== 'Product' || ($schema->url ?? '') !== SITE . $canonicalPath
            || strtolower($schema->sku ?? '') !== substr($route[1], 0, 12)) throw new \RuntimeException('Invalid product template.');
        preg_match_all('~<script\b(?=[^>]*\btype="application/ld\+json")[^>]*>(.*?)</script>~s', $html, $scripts);
        $organization = null;
        foreach ($scripts[1] as $raw) {
            $value = json_decode($raw, false, 32, JSON_THROW_ON_ERROR);
            if (($value->{'@type'} ?? '') === 'Organization') $organization = $value;
        }
        if (($organization->{'@id'} ?? '') !== SITE . '/#organization'
            || !is_object($organization->hasMerchantReturnPolicy ?? null)
            || !is_string($organization->hasShippingService->{'@id'} ?? null)) throw new \RuntimeException('Published policies unavailable.');
        $live = null;
        try { $live = validatedProduct($loadProduct($id), $id); } catch (\Throwable) { /* No stale/fabricated fallback. */ }
        $seed = (object) ['version' => 1, 'product' => $live, 'failed' => $live === null];
        if ($live !== null) {
            $schema->offers = (object) [
                'seller' => (object) ['@id' => $organization->{'@id'}],
                'hasMerchantReturnPolicy' => $organization->hasMerchantReturnPolicy,
                'shippingDetails' => (object) ['@type' => 'OfferShippingDetails',
                    'hasShippingService' => (object) ['@id' => $organization->hasShippingService->{'@id'}]],
                '@type' => 'Offer', 'url' => $schema->url,
                'price' => intdiv($live->priceMinor, 100) . '.' . str_pad((string) ($live->priceMinor % 100), 2, '0', STR_PAD_LEFT),
                'priceCurrency' => 'EUR', 'availability' => 'https://schema.org/' . ($live->available ? 'InStock' : 'OutOfStock'),
            ];
            $price = number_format($live->priceMinor / 100, 2, ',', '.') . "\u{00a0}€";
            $box = '<p class="shop-price">' . $price . '</p><p class="shop-message">'
                . ($live->available ? 'Verfügbar' : 'Vorübergehend nicht verfügbar') . '</p>';
        } else {
            unset($schema->offers);
            $box = '<div><p class="shop-message">Preis und Verfügbarkeit sind momentan nicht erreichbar.</p>'
                . '<button class="v2-text-link" type="button">Erneut prüfen</button></div>';
        }
        $html = replaceScript($html, $seedId, 'application/json', json($seed));
        $html = replaceScript($html, $schemaId, 'application/ld+json', json($schema));
        $boxPattern = '~(<div\b(?=[^>]*\bid="cmj-offer-box-' . preg_quote($id, '~') . '")[^>]*>).*?(</div>)~s';
        $html = preg_replace_callback($boxPattern, static fn(array $m): string => $m[1] . $box . $m[2], $html, -1, $count);
        if ($html === null || $count !== 1) throw new \RuntimeException('Invalid offer placeholder.');
        return ['status' => $live === null ? 503 : 200,
            'headers' => headers() + ($live === null ? ['Retry-After' => '60'] : []), 'body' => $html];
    } catch (\Throwable) { return failure(503); }
}
