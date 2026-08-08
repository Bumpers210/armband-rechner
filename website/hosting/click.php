<?php

declare(strict_types=1);

require_once __DIR__ . '/_internal/tracking.php';

header('Cache-Control: no-store, max-age=0');
header('X-Robots-Tag: noindex, nofollow, noimageindex', true);

$targetUrls = [
    'instagram' => 'https://www.instagram.com/carmaja_perlen/',
];
$allowedParameters = ['target', 'position'];
$unexpectedParameters = array_diff(array_keys($_GET), $allowedParameters);
$target = $_GET['target'] ?? null;
$position = $_GET['position'] ?? null;

$isValid = $unexpectedParameters === []
    && is_string($target)
    && isset($targetUrls[$target])
    && is_string($position)
    && in_array($position, CARMAJA_POSITIONS, true);

if (!$isValid) {
    http_response_code(400);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Ungültiges Linkziel.';
    exit;
}

try {
    carmaja_record_click($target, $position);
} catch (Throwable $error) {
    // Die freigegebene Weiterleitung darf bei einem Zählfehler nicht ausfallen.
}

header('Location: ' . $targetUrls[$target], true, 302);
exit;
