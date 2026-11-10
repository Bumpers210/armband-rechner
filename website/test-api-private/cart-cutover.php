<?php

declare(strict_types=1);

// Offline plan is the default. All mutations need a later explicit release.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
define('CARMAJA_BOOTSTRAP_NO_RUN', true);
require_once __DIR__ . '/program/bootstrap.php';
require_once __DIR__ . '/program/production-cart-cutover.php';

function cart_cutover_json(string $path): array
{
    if (!is_file($path) || is_link($path)) { throw new RuntimeException('input_missing'); }
    $value = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($value)) { throw new RuntimeException('input_invalid'); }
    return $value;
}

try {
    if (count($argv) !== 5 || ($argv[1] ?? '') !== 'plan') {
        // No public or generic --apply route: production execution is deliberately
        // a separately approved operator action using the tested service adapter.
        throw new RuntimeException('use_plan_manifest_products_release_manifest');
    }
    $plan = CarmajaCartCutover::plan(cart_cutover_json($argv[2]), cart_cutover_json($argv[3])['products'] ?? [], cart_cutover_json($argv[4]));
    unset($plan['operations']);
    echo json_encode($plan, JSON_THROW_ON_ERROR) . PHP_EOL;
} catch (Throwable) {
    fwrite(STDERR, "Cart cutover rejected. Check local input contract; no credentials or data changed.\n");
    exit(1);
}
