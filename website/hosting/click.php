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

$isValid = $unexpectedParameters === []
    && is_string($target)
    && isset($targetUrls[$target])
    && is_string($position)
    && in_array($position, CARMAJA_POSITIONS, true);

if ($isValid && $position === 'product') {
    $isValid = $target === 'vinted'
        && is_string($product)
        && preg_match(CARMAJA_PRODUCT_PATTERN, $product) === 1
        && carmaja_product_target_url($product) !== null;
} elseif ($product !== null) {
    $isValid = false;
}

if (!$isValid) {
    http_response_code(400);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Ungültiges Linkziel.';
    exit;
}

try {
    carmaja_record_click(
        $target,
        $position,
        is_string($product) ? $product : null
    );
} catch (Throwable $error) {
    // Die freigegebene Weiterleitung darf bei einem Zählfehler nicht ausfallen.
}

$redirectUrl = is_string($product)
    ? carmaja_product_target_url($product)
    : $targetUrls[$target];

header('Location: ' . $redirectUrl, true, 302);
exit;
