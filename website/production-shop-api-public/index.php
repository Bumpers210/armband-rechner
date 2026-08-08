<?php

declare(strict_types=1);

function carmaja_production_shop_api_is_absolute_path(string $path): bool
{
    return str_starts_with($path, '/')
        || preg_match('/^[A-Za-z]:[\\\\\/]/', $path) === 1;
}

function carmaja_production_shop_api_normalize_path(string $path): string
{
    $normalized = str_replace('\\', '/', rtrim($path, "\\/"));

    return DIRECTORY_SEPARATOR === '\\' ? strtolower($normalized) : $normalized;
}

function carmaja_production_shop_api_path_is_inside(string $path, string $directory): bool
{
    $normalizedPath = carmaja_production_shop_api_normalize_path($path);
    $normalizedDirectory = carmaja_production_shop_api_normalize_path($directory);

    return $normalizedPath === $normalizedDirectory
        || str_starts_with($normalizedPath, $normalizedDirectory . '/');
}

function carmaja_production_shop_api_resolve_bootstrap(
    ?string $configuredPath,
    string $publicWebroot = __DIR__
): ?string {
    if (!is_string($configuredPath) || trim($configuredPath) === '') {
        return null;
    }

    $candidate = trim($configuredPath);
    if (!carmaja_production_shop_api_is_absolute_path($candidate)) {
        return null;
    }

    $realBootstrap = realpath($candidate);
    $realWebroot = realpath($publicWebroot);
    if (!is_string($realBootstrap)
        || !is_file($realBootstrap)
        || !is_readable($realBootstrap)
        || basename($realBootstrap) !== 'bootstrap.php'
        || !is_string($realWebroot)
        || carmaja_production_shop_api_path_is_inside($realBootstrap, $realWebroot)) {
        return null;
    }

    return $realBootstrap;
}

function carmaja_production_shop_api_unavailable(): never
{
    http_response_code(503);
    header('Cache-Control: no-store, max-age=0');
    header('Content-Type: application/json; charset=utf-8');
    header('X-Robots-Tag: noindex, nofollow, noimageindex', true);
    echo '{"ok":false,"error":{"code":"service_unavailable",'
        . '"message":"Shop-API ist nicht sicher konfiguriert.","fields":{}}}';
    exit;
}

function carmaja_production_shop_api_main(): never
{
    $bootstrap = carmaja_production_shop_api_resolve_bootstrap(
        getenv('CARMAJA_BOOTSTRAP_FILE') ?: null
    );
    if ($bootstrap === null) {
        carmaja_production_shop_api_unavailable();
    }

    try {
        require $bootstrap;
    } catch (Throwable) {
        carmaja_production_shop_api_unavailable();
    }

    carmaja_production_shop_api_unavailable();
}

if (!defined('CARMAJA_PRODUCTION_SHOP_API_NO_RUN')) {
    carmaja_production_shop_api_main();
}
