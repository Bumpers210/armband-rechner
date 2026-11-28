<?php
declare(strict_types=1);

// CLI-only, deploy privately and separately; never included in the public export.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
try {
    $webroot = getenv('CARMAJA_DAILY_VISITORS_WEBROOT') ?: '/home/www/carmaja';
    if (!getenv('CARMAJA_DAILY_VISITORS_CONFIG')) {
        putenv('CARMAJA_DAILY_VISITORS_CONFIG=/home/www/carmaja-production-stats-maintenance/config/daily-visitors.php');
    }
    require $webroot . '/_internal/daily-visitors.php';
    $config = cdv_config($webroot, false); // Cleanup keeps working even after collection is disabled.
    if ($config === null) { throw new RuntimeException('Missing configuration'); }
    $action = $argv[1] ?? 'prune';
    if ($action === 'prune') {
        cdv_maintain($config['dataFile']);
        echo "Daily visitor retention: OK\n";
    } elseif ($action === 'status') {
        if (!cdv_retention_healthy($config['dataFile'])) {
            throw new RuntimeException('Retention overdue');
        }
        echo "Daily visitor retention: OK\n";
    } elseif ($action === 'export-aggregates') {
        // Only this identifier-free projection may be backed up, never the runtime file.
        $data = cdv_read($config['dataFile']);
        echo json_encode(['version' => 1, 'kind' => 'daily-visitor-counts', 'days' => $data['days']], JSON_THROW_ON_ERROR) . "\n";
    } else { throw new InvalidArgumentException('Unknown action'); }
} catch (Throwable) {
    fwrite(STDERR, "Daily visitor retention: unavailable; check private configuration and scheduled cleanup.\n");
    exit(1);
}
