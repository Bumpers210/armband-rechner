<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/production-api-private/program/product-admin.php';

final class ProductionAdminTestFailure extends RuntimeException
{
}

function production_admin_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new ProductionAdminTestFailure($message);
    }
}

function production_admin_expect(int $exitCode, callable $callback): void
{
    try {
        $callback();
    } catch (CarmajaAdminException $error) {
        production_admin_assert($error->exitCode === $exitCode, 'Falscher CLI-Fehlercode.');
        return;
    }

    throw new ProductionAdminTestFailure('Erwartete CLI-Ausnahme fehlt.');
}

$hash = password_hash('Ruhige7Farbe!Wolke', PASSWORD_DEFAULT);

if (!is_string($hash)) {
    throw new ProductionAdminTestFailure('Passworthash konnte nicht erzeugt werden.');
}

production_admin_assert(
    carmaja_admin_validate_username('  Admin.Team  ') === 'admin.team',
    'Benutzername wurde nicht eindeutig normalisiert.'
);
production_admin_expect(CARMAJA_ADMIN_EXIT_INPUT, static function (): void {
    carmaja_admin_validate_username('admin team');
});
production_admin_expect(CARMAJA_ADMIN_EXIT_INPUT, static function (): void {
    carmaja_admin_validate_password('kurz', 'admin');
});
production_admin_expect(CARMAJA_ADMIN_EXIT_INPUT, static function (): void {
    carmaja_admin_validate_password('Admin-Produktverwaltung-2026!', 'admin');
});
carmaja_admin_validate_password('Ruhige7Farbe!Wolke', 'admin');
production_admin_expect(CARMAJA_ADMIN_EXIT_IO, static function () use ($hash): void {
    carmaja_admin_validate_users_data([
        'environment' => 'production',
        'users' => [
            ['username' => 'admin', 'passwordHash' => $hash],
            ['username' => 'Admin', 'passwordHash' => $hash],
        ],
    ], 'production');
});
production_admin_expect(CARMAJA_ADMIN_EXIT_IO, static function (): void {
    carmaja_admin_validate_devices_data([
        'environment' => 'test',
        'tokens' => [],
    ], 'production');
});

$root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'carmaja-production-admin-'
    . bin2hex(random_bytes(6));
$private = $root . DIRECTORY_SEPARATOR . 'private';
$auth = $private . DIRECTORY_SEPARATOR . 'auth';
$audit = $private . DIRECTORY_SEPARATOR . 'audit';
$locks = $private . DIRECTORY_SEPARATOR . 'locks';
$webroot = $root . DIRECTORY_SEPARATOR . 'api';

foreach ([$auth, $audit, $locks, $webroot] as $directory) {
    if (!mkdir($directory, 0750, true) && !is_dir($directory)) {
        throw new ProductionAdminTestFailure('Admin-Fixture konnte nicht erstellt werden.');
    }
}

$deviceId = str_repeat('a', 32);
$devicesFile = $auth . DIRECTORY_SEPARATOR . 'device-tokens.json';
file_put_contents($devicesFile, json_encode([
    'environment' => 'production',
    'tokens' => [$deviceId => [
        'tokenId' => $deviceId,
        'username' => 'admin',
        'deviceName' => 'Android',
        'createdAt' => gmdate('c'),
        'lastUsedAt' => null,
        'revokedAt' => null,
        'secretHash' => 'stored-hash-only',
    ]],
], JSON_THROW_ON_ERROR));
$config = [
    'environment' => 'production',
    'privateDir' => $private,
    'webroot' => $webroot,
    'usersFile' => $auth . DIRECTORY_SEPARATOR . 'api-users.json',
    'devicesFile' => $devicesFile,
    'devicesLock' => $locks . DIRECTORY_SEPARATOR . 'device-tokens.lock',
    'auditDir' => $audit,
];
$revoked = carmaja_admin_revoke_device($config, $deviceId);
production_admin_assert($revoked['result'] === 'success', 'Geraet wurde nicht widerrufen.');
$stored = json_decode((string) file_get_contents($devicesFile), true, 512, JSON_THROW_ON_ERROR);
production_admin_assert(is_string($stored['tokens'][$deviceId]['revokedAt'] ?? null), 'Widerruf wurde nicht gespeichert.');
production_admin_assert(
    carmaja_admin_revoke_device($config, $deviceId)['result'] === 'already_revoked',
    'Wiederholter Widerruf ist nicht idempotent.'
);
foreach (scandir($root) ?: [] as $entry) {
    if ($entry !== '.' && $entry !== '..') {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(
                $root . DIRECTORY_SEPARATOR . $entry,
                FilesystemIterator::SKIP_DOTS
            ),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $item) {
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
        @rmdir($root . DIRECTORY_SEPARATOR . $entry);
    }
}
@rmdir($root);
production_admin_assert(
    !str_contains(carmaja_admin_usage(), '--password'),
    'CLI darf kein Passwortargument anbieten.'
);
echo "production-admin: OK\n";
