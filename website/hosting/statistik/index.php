<?php

declare(strict_types=1);

header('Cache-Control: private, no-store, max-age=0');
header('X-Content-Type-Options: nosniff', true);
header('X-Robots-Tag: noindex, nofollow, noimageindex', true);
header('Content-Type: text/html; charset=utf-8');
header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; img-src 'self'; form-action 'self'; frame-ancestors 'none'; base-uri 'none'");
header('Referrer-Policy: no-referrer');

$authenticatedUser = $_SERVER['REMOTE_USER'] ?? $_SERVER['PHP_AUTH_USER'] ?? '';
if (!is_string($authenticatedUser) || $authenticatedUser === '') {
    http_response_code(403);
    echo 'Zugriff verweigert.';
    exit;
}

define('CARMAJA_STATISTICS_CONTEXT', true);
require_once dirname(__DIR__) . '/_internal/tracking.php';
require_once __DIR__ . '/report.php';
require __DIR__ . '/dashboard.php';
