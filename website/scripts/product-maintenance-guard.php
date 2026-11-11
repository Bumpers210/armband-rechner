<?php

declare(strict_types=1);

// Private, temporary guard. Runtime configuration and secrets are never rewritten.
function carmaja_product_maintenance_active(): bool
{
    $directory = dirname(__DIR__) . '/maintenance';
    $marker = $directory . '/product-cleanup.lock';
    clearstatcache(true, $directory);
    clearstatcache(true, $marker);
    // Any marker (including a malformed file or symlink) closes the write paths.
    return is_link($directory) || is_link($marker) || file_exists($marker);
}

function carmaja_product_maintenance_assert(string $area): void
{
    if (carmaja_product_maintenance_active()) {
        throw new CarmajaApiException(
            503,
            'Wartung: Diese Aktion ist voruebergehend gesperrt.',
            [],
            $area . '_maintenance'
        );
    }
}

function carmaja_product_maintenance_assert_lock(string $name): void
{
    foreach (['draft-', 'sku-counter', 'operation-', 'publish-adapter-', 'github-publish-'] as $prefix) {
        if (str_starts_with($name, $prefix)) {
            carmaja_product_maintenance_assert('product');
            return;
        }
    }
}

function carmaja_product_maintenance_assert_http(string $method, string $uri): void
{
    $method = strtoupper($method);
    if (in_array($method, ['GET', 'HEAD', 'OPTIONS'], true)) {
        return;
    }
    $path = parse_url($uri, PHP_URL_PATH);
    $path = is_string($path) ? trim(rawurldecode($path), '/') : '';
    $segments = preg_split('#/+#', $path) ?: [];
    if (($segments[0] ?? '') === 'api') {
        array_shift($segments);
    }
    if (($segments[0] ?? '') === 'shop') {
        if (($segments[2] ?? '') === 'checkouts') {
            carmaja_product_maintenance_assert('checkout');
        }
        // Existing payment status, withdrawal and other shop operations continue.
        return;
    }
    if ($segments === ['stripe', 'webhook'] || in_array($segments, [['login'], ['logout']], true)
        || array_slice($segments, 0, 2) === ['admin', 'v1']) {
        return;
    }
    carmaja_product_maintenance_assert('product');
}
