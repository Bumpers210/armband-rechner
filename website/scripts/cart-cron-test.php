<?php
declare(strict_types=1);

// Temporary test-stage entry marker. No production configuration or secrets.
ini_set('display_errors', '0');
ini_set('log_errors', '0');
umask(0077);
try {
    // Record interpreter entry before loading the worker, to distinguish an
    // absent scheduler invocation from a later bootstrap/extension failure.
    if (PHP_SAPI !== 'cli' || PHP_VERSION_ID < 80400 || PHP_VERSION_ID >= 80500) { throw new RuntimeException('php84_cli_required'); }
    $root = dirname(__DIR__, 2);
    if (realpath($root) !== $root || is_link($root)
        || preg_match('#^/home/www/carmaja-private-test/cart-e2e-[A-Za-z0-9-]+$#D', $root) !== 1) { throw new RuntimeException('isolated_stage_required'); }
    $ownerPath = $root . '/site-owner.json';
    if (realpath($ownerPath) !== $ownerPath || is_link($ownerPath)
        || (fileperms($ownerPath) & 0777) !== 0600) { throw new RuntimeException('private_owner_required'); }
    $owner = json_decode((string) file_get_contents($ownerPath), true, 16, JSON_THROW_ON_ERROR);
    if (($owner['testOnly'] ?? false) !== true || ($owner['deadline'] ?? 0) <= time()) { throw new RuntimeException('site_stage_expired'); }
    $marker = $root . '/site-cron-entry.jsonl';
    if (is_link($marker)) { throw new RuntimeException('cron_marker_unsafe'); }
    $handle = fopen($marker, 'a');
    if (!is_resource($handle) || !chmod($marker, 0600)) { throw new RuntimeException('cron_marker_failed'); }
    try {
        $line = json_encode(['enteredUtc' => gmdate(DATE_ATOM), 'php84' => true], JSON_THROW_ON_ERROR) . "\n";
        if (fwrite($handle, $line) !== strlen($line) || !fflush($handle)) { throw new RuntimeException('cron_marker_failed'); }
    } finally { fclose($handle); }
    require_once __DIR__ . '/cart-site-e2e.php';
    echo json_encode(cart_site_worker(), JSON_THROW_ON_ERROR) . "\n";
} catch (Throwable $error) {
    // The reporter exposes only validated error categories, never messages/secrets.
    if (function_exists('cart_probe_report')) { cart_probe_report($error); }
    else { fwrite(STDERR, "FAIL cron_bootstrap_failure; no acceptance granted.\n"); }
    exit(1);
}
