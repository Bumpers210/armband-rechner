<?php

declare(strict_types=1);

function carmaja_public_is_absolute_path(string $path): bool
{
    return str_starts_with($path, '/')
        || preg_match('/^[A-Za-z]:[\\\\\/]/', $path) === 1;
}

function carmaja_public_normalize_path(string $path): string
{
    $normalized = str_replace('\\', '/', rtrim($path, "\\/"));

    return DIRECTORY_SEPARATOR === '\\' ? strtolower($normalized) : $normalized;
}

function carmaja_public_path_is_inside(string $path, string $directory): bool
{
    $normalizedPath = carmaja_public_normalize_path($path);
    $normalizedDirectory = carmaja_public_normalize_path($directory);

    return $normalizedPath === $normalizedDirectory
        || str_starts_with($normalizedPath, $normalizedDirectory . '/');
}

function carmaja_public_resolve_bootstrap(
    ?string $configuredPath,
    string $publicWebroot = __DIR__
): ?string {
    if (!is_string($configuredPath) || trim($configuredPath) === '') {
        return null;
    }

    $candidate = trim($configuredPath);

    if (!carmaja_public_is_absolute_path($candidate)) {
        return null;
    }

    $realBootstrap = realpath($candidate);
    $realWebroot = realpath($publicWebroot);

    if (!is_string($realBootstrap)
        || !is_file($realBootstrap)
        || !is_readable($realBootstrap)
        || basename($realBootstrap) !== 'bootstrap.php'
        || !is_string($realWebroot)
        || carmaja_public_path_is_inside($realBootstrap, $realWebroot)) {
        return null;
    }

    return $realBootstrap;
}

function carmaja_public_send_unavailable(): never
{
    http_response_code(503);
    header('Cache-Control: no-store, max-age=0');
    header('Content-Type: application/json; charset=utf-8');
    header('X-Robots-Tag: noindex, nofollow, noimageindex', true);
    echo '{"ok":false,"error":{"code":"service_unavailable",'
        . '"message":"Produktions-API ist nicht sicher konfiguriert.","fields":{}}}';
    exit;
}

function carmaja_public_main(): never
{
    $bootstrapFile = carmaja_public_resolve_bootstrap(
        getenv('CARMAJA_BOOTSTRAP_FILE') ?: null
    );

    if ($bootstrapFile === null) {
        carmaja_public_send_unavailable();
    }

    try {
        require $bootstrapFile;
    } catch (Throwable) {
        carmaja_public_send_unavailable();
    }

    carmaja_public_send_unavailable();
}

if (!defined('CARMAJA_PUBLIC_ENTRYPOINT_NO_RUN')) {
    carmaja_public_main();
}
