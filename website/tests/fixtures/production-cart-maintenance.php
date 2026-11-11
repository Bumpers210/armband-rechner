<?php

declare(strict_types=1);

$root = realpath($argv[1] ?? '') ?: '';
if (!str_starts_with(basename($root), 'cmj-current-package-')
    || dirname($root) !== realpath(sys_get_temp_dir())) {
    throw new RuntimeException('Fresh local package fixture required');
}
$program = $root . '/test-api-private/program';
define('CARMAJA_BOOTSTRAP_NO_RUN', true);
require $program . '/product-api.php';
require $program . '/bootstrap.php';
$directory = dirname($program) . '/maintenance';
if (file_exists($directory) || is_link($directory) || !mkdir($directory, 0750)) {
    throw new RuntimeException('Maintenance fixture must be new');
}
$marker = $directory . '/product-cleanup.lock';
$checks = 0;
try {
    file_put_contents($marker, "product-cleanup-v1\n");
    $before = hash_file('sha256', $marker);
    // No config has been loaded: guards must run before authentication, PDO,
    // provider calls or product writes, even for the newly bundled V5 route.
    foreach (['PUT' => ['/v5/products/artificial', '/v4/products/artificial', '/v2/products/artificial'],
        'POST' => ['/v5/products/artificial/publish', '/shop/v1/checkouts', '/shop/v2/checkouts', '/shop/v3/checkouts']] as $method => $uris) {
        foreach ($uris as $uri) {
            $_SERVER['REQUEST_METHOD'] = $method;
            $_SERVER['REQUEST_URI'] = $uri;
            try {
                carmaja_bootstrap_route_request();
            } catch (CarmajaApiException $error) {
                $expected = str_starts_with($uri, '/shop/') ? 'checkout_maintenance' : 'product_maintenance';
                if ($error->statusCode !== 503 || $error->errorCode !== $expected) throw $error;
                $checks++;
            }
        }
    }
    foreach ([['POST', '/stripe/webhook'], ['GET', '/shop/v3/checkouts/artificial/status'],
        ['POST', '/shop/v3/withdrawals/identify'], ['POST', '/shop/v3/withdrawals/submit'],
        ['PUT', '/shop/v3/cart'], ['GET', '/v5/products'], ['OPTIONS', '/shop/v3/checkouts']] as [$method, $uri]) {
        carmaja_product_maintenance_assert_http($method, $uri);
        $checks++;
    }
    if ($before !== hash_file('sha256', $marker) || is_dir(dirname($program) . '/config')) {
        throw new RuntimeException('Fixture marker or configuration changed');
    }
    echo $checks . " current package route checks passed; marker retained\n";
} finally {
    if (is_file($marker)) unlink($marker);
    rmdir($directory);
}
