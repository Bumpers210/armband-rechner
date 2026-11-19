<?php

declare(strict_types=1);

if (!defined('CARMAJA_STATISTICS_CONTEXT')) {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/commerce-projection.php';

const CS_LIVE_WEBROOT = '/home/www/carmaja';
const CS_LIVE_COMMERCE_CACHE = '/home/www/carmaja-production-stats-maintenance/dashboard-cache/commerce.json';

function cs_read_live_commerce(): array
{
    set_error_handler(static function (): never {
        throw new RuntimeException('statistics_source_read_failed');
    });
    try {
        $config = require '/home/www/carmaja-private-shop/config/runtime-config.php';
        $pdo = carmaja_statistics_live_connection($config);
        // These are the two historical purchases the operator confirmed as tests.
        [, $options] = carmaja_statistics_live_options($pdo, ['2026-08-22', '2026-11-13'], true);
        $commerce = carmaja_statistics_projection($pdo, $options);
        [$commerce] = carmaja_statistics_published_catalog($commerce, CS_LIVE_WEBROOT);
        $commerce['operatorTestExcludedCount'] = count($options['excludedCheckoutIds']);
        return $commerce;
    } finally {
        restore_error_handler();
    }
}

function cs_refresh_commerce(string $cache, string $webroot, callable $read): array
{
    // Serialize refreshes without blocking additional dashboard requests on the database.
    $lock = @fopen($cache . '.lock', 'c');
    try {
        if ($lock === false) {
            return ['status' => 'error', 'data' => cs_snapshot_file($cache, 'commerce')['data']];
        }
        if (!@flock($lock, LOCK_EX | LOCK_NB)) {
            $data = cs_snapshot_file($cache, 'commerce')['data'];
            return ['status' => $data === null ? 'error' : 'partial', 'data' => $data];
        }
        try {
            $commerce = $read();
        } catch (Throwable) {
            error_log('Carmaja statistics: commerce read failed.');
            return ['status' => 'error', 'data' => cs_snapshot_file($cache, 'commerce')['data']];
        }
        try {
            carmaja_statistics_write($cache, $webroot, $commerce);
        } catch (Throwable) {
            error_log('Carmaja statistics: private cache could not be updated.');
        }
        return ['status' => 'ok', 'data' => $commerce];
    } finally {
        if (is_resource($lock)) {
            fclose($lock);
        }
    }
}
