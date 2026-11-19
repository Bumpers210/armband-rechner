<?php
declare(strict_types=1);

// Install separately; no credentials, runtime configuration or database writes.
ini_set('display_errors', '0');
try {
    $library = __DIR__ . '/_internal/product-offers.php';
    if (!is_file($library)) throw new RuntimeException('Renderer unavailable.');
    require_once $library;
    $reply = \Carmaja\ProductOffers\render(
        __DIR__, (string) ($_SERVER['REQUEST_URI'] ?? ''),
        (string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'),
        \Carmaja\ProductOffers\fetchLiveProduct(...)
    );
} catch (Throwable) {
    $reply = ['status' => 503, 'headers' => [
        'Content-Type' => 'text/html; charset=utf-8', 'Cache-Control' => 'no-store',
        'Retry-After' => '60', 'X-Content-Type-Options' => 'nosniff',
    ], 'body' => '<!doctype html><html lang="de"><title>Vorübergehend nicht erreichbar</title><p>Die Produktseite ist momentan nicht erreichbar. Bitte später erneut versuchen.</p></html>'];
}
http_response_code($reply['status']);
foreach ($reply['headers'] as $name => $value) header($name . ': ' . $value);
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'HEAD') echo $reply['body'];
