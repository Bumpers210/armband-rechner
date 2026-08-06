<?php

declare(strict_types=1);

require_once __DIR__ . '/_internal/tracking.php';

header('Cache-Control: no-store, max-age=0');
header('X-Robots-Tag: noindex, nofollow, noimageindex', true);

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    exit;
}

$allowedParameters = ['path', 'source'];
$unexpectedParameters = array_diff(array_keys($_POST), $allowedParameters);
$path = $_POST['path'] ?? null;
$source = $_POST['source'] ?? null;
$hasSource = array_key_exists('source', $_POST);

$isValid = $unexpectedParameters === []
    && is_string($path)
    && carmaja_is_published_page_path($path)
    && (!$hasSource || (is_string($source) && in_array($source, CARMAJA_PAGEVIEW_SOURCES, true)));

if (!$isValid) {
    http_response_code(400);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Ungueltiger Seitenaufruf.';
    exit;
}

try {
    carmaja_record_pageview($path, $hasSource ? $source : null);
} catch (Throwable) {
    // Eine Statistikstoerung darf die angezeigte Seite nicht beeintraechtigen.
}

http_response_code(204);
exit;
