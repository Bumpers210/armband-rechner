<?php

declare(strict_types=1);
header('Cache-Control: no-store, max-age=0');
header('X-Robots-Tag: noindex, nofollow');
header('X-Content-Type-Options: nosniff');
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/_internal/daily-visitors.php';
require_once __DIR__ . '/_internal/tracking.php';

try {
    $config = cdv_config(carmaja_page_root());
    if ($config === null) { http_response_code(204); exit; }
    $method = $_SERVER['REQUEST_METHOD'] ?? '';
    if (!in_array($method, ['GET', 'POST'], true)) { header('Allow: GET, POST'); http_response_code(405); exit; }
    $consent = $_COOKIE['carmaja_analytics_consent'] ?? '';
    if (!is_string($consent) || !cdv_has_consent($consent)) { http_response_code(204); exit; }
    if (!cdv_maintenance_fresh(cdv_read($config['dataFile']))) { http_response_code(503); exit; }
    if ($method === 'GET') {
        $now = new DateTimeImmutable();
        echo json_encode(['day' => cdv_day($now), 'expiresAt' => cdv_expires_at($now), 'consentVersion' => CDV_CONSENT], JSON_THROW_ON_ERROR);
        exit;
    }
    if (($_SERVER['HTTP_ORIGIN'] ?? '') !== $config['origin']) { http_response_code(403); exit; }
    if ((int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 1024
        || !str_starts_with($_SERVER['CONTENT_TYPE'] ?? '', 'application/x-www-form-urlencoded')) { http_response_code(400); exit; }
    if (array_diff(array_keys($_POST), ['day', 'token', 'path']) !== []
        || !is_string($_POST['day'] ?? null) || !is_string($_POST['token'] ?? null) || !is_string($_POST['path'] ?? null)
        || !carmaja_is_published_page_path($_POST['path'])
        || preg_match('#^/(?:admin|artikelverwaltung|statistik)(?:/|$)#', $_POST['path'])) { http_response_code(400); exit; }
    cdv_record($config['dataFile'], $_POST['day'], $_POST['token']);
    http_response_code(204);
} catch (InvalidArgumentException) {
    http_response_code(409); // Midnight rollover: next page/visibility event retries with the server day.
} catch (Throwable) {
    http_response_code(503); // Never log identifiers, configuration or filesystem paths.
}
