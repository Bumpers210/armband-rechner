<?php

declare(strict_types=1);

$root = $argv[1] ?? '';
if (!is_dir($root . '/test-api-private/program')) {
    throw new RuntimeException('Synthetic fixture required');
}
$program = $root . '/test-api-private/program';
require $program . '/product-api.php';
require $program . '/product-api-v2.php';
require $program . '/product-api-v3.php';
require $program . '/product-api-v4.php';
$directory = dirname($program) . '/maintenance';
mkdir($directory, 0750);
$marker = $directory . '/product-cleanup.lock';
$checks = 0;
$blocked = static function (callable $call, string $code) use (&$checks): void {
    try {
        $call();
    } catch (CarmajaApiException $error) {
        if ($error->statusCode !== 503 || $error->errorCode !== $code) {
            throw new RuntimeException('Unexpected maintenance error');
        }
        $checks++;
        return;
    }
    throw new RuntimeException('Write was not blocked');
};
try {
    if (carmaja_product_maintenance_active()) throw new RuntimeException('Unexpected marker');
    carmaja_product_maintenance_assert_http('POST', '/shop/v2/checkouts');
    $checks++;
    file_put_contents($marker, "product-cleanup-v1\n");
    foreach (['/v4/products/x', '/v2/products/x/images', '/api/v3/products/x/publish',
        '/products/x', '/%76%34/products/x', '//api//v4//products/x'] as $uri) {
        $blocked(fn () => carmaja_product_maintenance_assert_http('PUT', $uri), 'product_maintenance');
    }
    foreach (['/shop/v1/checkouts', '/shop/v2/checkouts', '/api/shop/v2/checkouts?x=1',
        '/shop/v3/checkouts', '/shop/v2/%63heckouts'] as $uri) {
        $blocked(fn () => carmaja_product_maintenance_assert_http('POST', $uri), 'checkout_maintenance');
    }
    foreach ([['GET', '/v4/products'], ['HEAD', '/products/x'], ['OPTIONS', '/shop/v2/checkouts'],
        ['POST', '/stripe/webhook'], ['POST', '/api/stripe/webhook'], ['POST', '/login'],
        ['POST', '/logout'], ['POST', '/admin/v1/login'], ['POST', '/shop/v2/withdrawals/submit'],
        ['GET', '/shop/v2/checkouts/x/status']] as [$method, $uri]) {
        carmaja_product_maintenance_assert_http($method, $uri);
        $checks++;
    }
    foreach (['draft-example', 'sku-counter', 'operation-example', 'publish-adapter-example', 'github-publish-example'] as $lock) {
        $blocked(fn () => carmaja_api_with_lock($lock, fn () => throw new RuntimeException('Callback reached')), 'product_maintenance');
    }
    foreach (['login-attempts', 'device-tokens', 'audit-actions-2026-11', 'backup'] as $lock) {
        carmaja_product_maintenance_assert_lock($lock);
        $checks++;
    }
    $blocked(fn () => carmaja_api_save_draft([]), 'product_maintenance');
    $blocked(fn () => carmaja_api_upload_images('invalid', [], []), 'product_maintenance');
    $blocked(fn () => carmaja_api_run_publish_adapter([], []), 'publisher_maintenance');
    $blocked(fn () => carmaja_api_run_publish_adapter_v2([], []), 'publisher_maintenance');
    $blocked(fn () => carmaja_api_run_publish_adapter_v3([], []), 'publisher_maintenance');
    $blocked(fn () => carmaja_api_github_request('PATCH', '/never-called', []), 'publisher_maintenance');
    file_put_contents($marker, 'invalid marker stays closed');
    $blocked(fn () => carmaja_product_maintenance_assert('product'), 'product_maintenance');
    unlink($marker);
    carmaja_product_maintenance_assert('product');
    $checks++;
    echo $checks . " maintenance checks passed\n";
} finally {
    if (is_file($marker)) unlink($marker);
    rmdir($directory);
}
