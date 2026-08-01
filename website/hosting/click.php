<?php

declare(strict_types=1);

require_once __DIR__ . '/_internal/tracking.php';

header('Cache-Control: no-store, max-age=0');
header('X-Robots-Tag: noindex, nofollow, noimageindex', true);

$targetUrls = [
    'vinted' => 'https://www.vinted.de/member/314105735-carmaja0',
    'instagram' => 'https://www.instagram.com/carmaja_perlen/',
];
$allowedParameters = ['target', 'position', 'product'];
$unexpectedParameters = array_diff(array_keys($_GET), $allowedParameters);
$target = $_GET['target'] ?? null;
$position = $_GET['position'] ?? null;
$product = $_GET['product'] ?? null;
$hasProduct = array_key_exists('product', $_GET);

$isKnownTarget = is_string($target) && isset($targetUrls[$target]);
$isKnownPosition = is_string($position)
    && in_array($position, CARMAJA_POSITIONS, true);
$isProductLink = $target === 'vinted' && $position === 'product';

$isValid = $unexpectedParameters === []
    && $isKnownTarget
    && $isKnownPosition;

if ($isProductLink) {
    $isValid = $isValid
        && is_string($product)
        && carmaja_is_published_product_slug($product);
} elseif ($hasProduct || $position === 'product') {
    $isValid = false;
}

if (!$isValid) {
    http_response_code(400);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Ungueltiges Linkziel.';
    exit;
}

try {
    carmaja_record_click(
        $target,
        $position,
        $isProductLink ? $product : null,
    );
} catch (Throwable) {
    // Eine Zaehlerstoerung darf eine freigegebene Weiterleitung nicht verhindern.
}

header('Location: ' . $targetUrls[$target], true, 302);
exit;
