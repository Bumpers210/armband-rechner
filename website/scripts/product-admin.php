<?php

declare(strict_types=1);

function carmaja_admin_sapi_allowed(string $sapi): bool
{
    return $sapi === 'cli';
}

if (!carmaja_admin_sapi_allowed(PHP_SAPI)) {
    http_response_code(404);
    exit;
}

const CARMAJA_ADMIN_EXIT_SUCCESS = 0;
const CARMAJA_ADMIN_EXIT_USAGE = 2;
const CARMAJA_ADMIN_EXIT_INPUT = 3;
const CARMAJA_ADMIN_EXIT_CONFLICT = 4;
const CARMAJA_ADMIN_EXIT_IO = 5;
const CARMAJA_ADMIN_USERNAME_PATTERN = '/^[a-z0-9][a-z0-9._-]{1,62}[a-z0-9]$/';
const CARMAJA_ADMIN_DEVICE_ID_PATTERN = '/^[a-f0-9]{32}$/';

final class CarmajaAdminException extends RuntimeException
{
    public function __construct(
        public readonly int $exitCode,
        string $message,
        ?Throwable $previous = null
    ) {
        parent::__construct($message, 0, $previous);
    }
}

function carmaja_admin_now(): string
{
    return gmdate('c');
}

function carmaja_admin_normalize_path(string $path): string
{
    $normalized = str_replace('\\', '/', rtrim($path, "\\/"));

    return DIRECTORY_SEPARATOR === '\\' ? strtolower($normalized) : $normalized;
}

function carmaja_admin_path_is_inside(string $path, string $directory): bool
{
    $normalizedPath = carmaja_admin_normalize_path($path);
    $normalizedDirectory = carmaja_admin_normalize_path($directory);

    return $normalizedPath === $normalizedDirectory
        || str_starts_with($normalizedPath, $normalizedDirectory . '/');
}

function carmaja_admin_require_absolute_path(string $path, string $variable): void
{
    $isAbsolute = str_starts_with($path, '/')
        || preg_match('/^[A-Za-z]:[\\\\\/]/', $path) === 1;

    if (!$isAbsolute) {
        throw new CarmajaAdminException(
            CARMAJA_ADMIN_EXIT_IO,
            $variable . ' muss ein absoluter Pfad sein.'
        );
    }
}

function carmaja_admin_environment_value(string $name): string
{
    $value = getenv($name);

    if (!is_string($value) || trim($value) === '') {
        throw new CarmajaAdminException(
            CARMAJA_ADMIN_EXIT_IO,
            $name . ' ist nicht konfiguriert.'
        );
    }

    return trim($value);
}

function carmaja_admin_read_json_unlocked(
    string $path,
    ?array $fallback = null
): array {
    if (!is_file($path)) {
        if ($fallback !== null) {
            return $fallback;
        }

        throw new CarmajaAdminException(
            CARMAJA_ADMIN_EXIT_IO,
            'Erforderliche Datendatei ist nicht vorhanden.'
        );
    }

    $contents = file_get_contents($path);

    if (!is_string($contents) || trim($contents) === '') {
        throw new CarmajaAdminException(
            CARMAJA_ADMIN_EXIT_IO,
            'Datendatei ist leer oder nicht lesbar.'
        );
    }

    try {
        $decoded = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException $error) {
        throw new CarmajaAdminException(
            CARMAJA_ADMIN_EXIT_IO,
            'Datendatei enthaelt ungueltiges JSON.',
            $error
        );
    }

    if (!is_array($decoded) || array_is_list($decoded)) {
        throw new CarmajaAdminException(
            CARMAJA_ADMIN_EXIT_IO,
            'Datendatei muss ein JSON-Objekt enthalten.'
        );
    }

    return $decoded;
}

function carmaja_admin_with_lock(
    string $dataPath,
    int $lockMode,
    callable $callback,
    ?string $configuredLockPath = null
): mixed {
    $lockPath = $configuredLockPath ?? $dataPath . '.lock';
    $parent = dirname($lockPath);

    if (!is_dir($parent) || !is_writable($parent)) {
        throw new CarmajaAdminException(
            CARMAJA_ADMIN_EXIT_IO,
            'Lock-Verzeichnis ist nicht schreibbar.'
        );
    }

    $handle = fopen($lockPath, 'c');

    if ($handle === false) {
        throw new CarmajaAdminException(
            CARMAJA_ADMIN_EXIT_IO,
            'Lock-Datei konnte nicht geoeffnet werden.'
        );
    }

    @chmod($lockPath, 0640);

    try {
        if (!flock($handle, $lockMode)) {
            throw new CarmajaAdminException(
                CARMAJA_ADMIN_EXIT_IO,
                'Datendatei konnte nicht gesperrt werden.'
            );
        }

        return $callback();
    } finally {
        flock($handle, LOCK_UN);
        fclose($handle);
    }
}

function carmaja_admin_write_json_atomic(
    string $path,
    array $data,
    ?callable $beforeRename = null
): void {
    $parent = dirname($path);

    if (!is_dir($parent) || !is_writable($parent)) {
        throw new CarmajaAdminException(
            CARMAJA_ADMIN_EXIT_IO,
            'Zielverzeichnis ist nicht schreibbar.'
        );
    }

    try {
        $encoded = json_encode(
            $data,
            JSON_PRETTY_PRINT
                | JSON_UNESCAPED_SLASHES
                | JSON_UNESCAPED_UNICODE
                | JSON_THROW_ON_ERROR
        ) . PHP_EOL;
    } catch (JsonException $error) {
        throw new CarmajaAdminException(
            CARMAJA_ADMIN_EXIT_IO,
            'Daten konnten nicht als JSON serialisiert werden.',
            $error
        );
    }

    try {
        $temporaryPath = $path . '.tmp.' . bin2hex(random_bytes(8));
    } catch (Throwable $error) {
        throw new CarmajaAdminException(
            CARMAJA_ADMIN_EXIT_IO,
            'Temporärer Dateiname konnte nicht erzeugt werden.',
            $error
        );
    }

    $handle = null;

    try {
        $handle = fopen($temporaryPath, 'xb');

        if ($handle === false) {
            throw new CarmajaAdminException(
                CARMAJA_ADMIN_EXIT_IO,
                'Temporäre Datei konnte nicht angelegt werden.'
            );
        }

        $remaining = $encoded;

        while ($remaining !== '') {
            $written = fwrite($handle, $remaining);

            if ($written === false || $written === 0) {
                throw new CarmajaAdminException(
                    CARMAJA_ADMIN_EXIT_IO,
                    'Temporäre Datei konnte nicht vollständig geschrieben werden.'
                );
            }

            $remaining = substr($remaining, $written);
        }

        if (!fflush($handle)) {
            throw new CarmajaAdminException(
                CARMAJA_ADMIN_EXIT_IO,
                'Temporäre Datei konnte nicht synchronisiert werden.'
            );
        }

        if (function_exists('fsync')) {
            @fsync($handle);
        }

        fclose($handle);
        $handle = null;
        @chmod($temporaryPath, 0640);

        carmaja_admin_read_json_unlocked($temporaryPath);

        if ($beforeRename !== null) {
            $beforeRename($temporaryPath, $path);
        }

        if (!rename($temporaryPath, $path)) {
            throw new CarmajaAdminException(
                CARMAJA_ADMIN_EXIT_IO,
                'Datendatei konnte nicht atomar übernommen werden.'
            );
        }

        @chmod($path, 0640);
    } catch (CarmajaAdminException $error) {
        throw $error;
    } catch (Throwable $error) {
        throw new CarmajaAdminException(
            CARMAJA_ADMIN_EXIT_IO,
            'Atomarer Schreibvorgang ist fehlgeschlagen.',
            $error
        );
    } finally {
        if (is_resource($handle)) {
            fclose($handle);
        }

        if (isset($temporaryPath) && is_file($temporaryPath)) {
            @unlink($temporaryPath);
        }
    }
}

function carmaja_admin_build_config(): array
{
    $usersFile = carmaja_admin_environment_value('CARMAJA_API_USERS_FILE');
    $target = carmaja_admin_environment_value('CARMAJA_PUBLISH_TARGET');
    $privatePath = carmaja_admin_environment_value('CARMAJA_PRIVATE_DIR');
    $webrootPath = carmaja_admin_environment_value('CARMAJA_PUBLIC_WEBROOT');

    if ($target !== 'test') {
        throw new CarmajaAdminException(
            CARMAJA_ADMIN_EXIT_IO,
            'Dieses Beta-CLI darf ausschließlich mit CARMAJA_PUBLISH_TARGET=test arbeiten.'
        );
    }

    carmaja_admin_require_absolute_path($usersFile, 'CARMAJA_API_USERS_FILE');
    carmaja_admin_require_absolute_path($privatePath, 'CARMAJA_PRIVATE_DIR');
    carmaja_admin_require_absolute_path($webrootPath, 'CARMAJA_PUBLIC_WEBROOT');

    $privateRealPath = realpath($privatePath);
    $webrootRealPath = realpath($webrootPath);

    if (!is_string($privateRealPath)
        || !is_dir($privateRealPath)
        || !is_readable($privateRealPath)
        || !is_writable($privateRealPath)) {
        throw new CarmajaAdminException(
            CARMAJA_ADMIN_EXIT_IO,
            'CARMAJA_PRIVATE_DIR ist nicht sicher les- und schreibbar.'
        );
    }

    if (!is_string($webrootRealPath) || !is_dir($webrootRealPath)) {
        throw new CarmajaAdminException(
            CARMAJA_ADMIN_EXIT_IO,
            'CARMAJA_PUBLIC_WEBROOT ist nicht erreichbar.'
        );
    }

    if (carmaja_admin_path_is_inside($privateRealPath, $webrootRealPath)
        || carmaja_admin_path_is_inside($webrootRealPath, $privateRealPath)) {
        throw new CarmajaAdminException(
            CARMAJA_ADMIN_EXIT_IO,
            'Privater Datenbereich und öffentlicher Webroot sind nicht sicher getrennt.'
        );
    }

    $usersParent = realpath(dirname($usersFile));

    if (!is_string($usersParent)
        || !is_dir($usersParent)
        || !is_readable($usersParent)
        || !is_writable($usersParent)) {
        throw new CarmajaAdminException(
            CARMAJA_ADMIN_EXIT_IO,
            'Elternverzeichnis der Benutzerdatei ist nicht sicher zugreifbar.'
        );
    }

    $resolvedUsersFile = $usersParent . DIRECTORY_SEPARATOR . basename($usersFile);

    if (is_file($usersFile)) {
        $existingUsersFile = realpath($usersFile);

        if (!is_string($existingUsersFile)) {
            throw new CarmajaAdminException(
                CARMAJA_ADMIN_EXIT_IO,
                'Benutzerdatei ist nicht sicher erreichbar.'
            );
        }

        $resolvedUsersFile = $existingUsersFile;
    }

    if (!carmaja_admin_path_is_inside($resolvedUsersFile, $privateRealPath)
        || carmaja_admin_path_is_inside($resolvedUsersFile, $webrootRealPath)) {
        throw new CarmajaAdminException(
            CARMAJA_ADMIN_EXIT_IO,
            'Benutzerdatei muss im privaten Datenbereich außerhalb des Webroots liegen.'
        );
    }

    $environmentFile = $privateRealPath . DIRECTORY_SEPARATOR . 'environment.json';
    $environmentData = carmaja_admin_read_json_unlocked($environmentFile);

    if (($environmentData['environment'] ?? null) !== $target) {
        throw new CarmajaAdminException(
            CARMAJA_ADMIN_EXIT_IO,
            'Umgebungsmarkierung des privaten Datenbereichs stimmt nicht überein.'
        );
    }

    if (is_file($resolvedUsersFile)) {
        carmaja_admin_with_lock(
            $resolvedUsersFile,
            LOCK_SH,
            function () use ($resolvedUsersFile, $target): void {
                $data = carmaja_admin_read_json_unlocked($resolvedUsersFile);

                if (($data['environment'] ?? null) !== $target) {
                    throw new CarmajaAdminException(
                        CARMAJA_ADMIN_EXIT_IO,
                        'Umgebungsmarkierung der Benutzerdatei stimmt nicht überein.'
                    );
                }
            }
        );
    }

    $authDirectory = $privateRealPath . DIRECTORY_SEPARATOR . 'auth';
    $auditDirectory = $privateRealPath . DIRECTORY_SEPARATOR . 'audit';
    $locksDirectory = $privateRealPath . DIRECTORY_SEPARATOR . 'locks';

    if (!is_dir($authDirectory)
        || !is_writable($authDirectory)
        || !is_dir($auditDirectory)
        || !is_writable($auditDirectory)
        || !is_dir($locksDirectory)
        || !is_writable($locksDirectory)) {
        throw new CarmajaAdminException(
            CARMAJA_ADMIN_EXIT_IO,
            'Private auth-, audit- und locks-Verzeichnisse müssen vorhanden und schreibbar sein.'
        );
    }

    return [
        'environment' => $target,
        'privateDir' => $privateRealPath,
        'webroot' => $webrootRealPath,
        'usersFile' => $resolvedUsersFile,
        'devicesFile' => $authDirectory . DIRECTORY_SEPARATOR . 'device-tokens.json',
        'devicesLock' => $locksDirectory . DIRECTORY_SEPARATOR . 'device-tokens.lock',
        'auditDir' => $auditDirectory,
    ];
}

function carmaja_admin_normalize_username(string $username): string
{
    return strtolower(trim($username));
}

function carmaja_admin_validate_username(string $username): string
{
    $normalized = carmaja_admin_normalize_username($username);

    if (preg_match(CARMAJA_ADMIN_USERNAME_PATTERN, $normalized) !== 1) {
        throw new CarmajaAdminException(
            CARMAJA_ADMIN_EXIT_INPUT,
            'Benutzername muss 3 bis 64 Zeichen lang sein, mit Buchstabe oder Zahl beginnen und enden und darf nur a-z, 0-9, Punkt, Bindestrich und Unterstrich enthalten.'
        );
    }

    return $normalized;
}

function carmaja_admin_validate_password(string $password, string $username): void
{
    if (strlen($password) < 14) {
        throw new CarmajaAdminException(
            CARMAJA_ADMIN_EXIT_INPUT,
            'Passwort muss mindestens 14 Zeichen lang sein.'
        );
    }

    if (stripos($password, $username) !== false) {
        throw new CarmajaAdminException(
            CARMAJA_ADMIN_EXIT_INPUT,
            'Passwort darf den vollständigen Benutzernamen nicht enthalten.'
        );
    }

    $lower = strtolower($password);
    $collapsed = preg_replace('/[^a-z0-9]/', '', $lower) ?? '';
    $weakTerms = [
        'password',
        'passwort',
        'administrator',
        'carmaja',
        '123456',
        'qwertz',
        'qwerty',
    ];

    foreach ($weakTerms as $term) {
        if (str_contains($collapsed, $term)) {
            throw new CarmajaAdminException(
                CARMAJA_ADMIN_EXIT_INPUT,
                'Passwort ist offensichtlich zu schwach.'
            );
        }
    }

    if (preg_match('/^(.)\1+$/s', $password) === 1
        || preg_match('/^(.{1,7})\1+$/s', $password) === 1
        || count(array_unique(str_split($password))) < 6
        || str_contains('abcdefghijklmnopqrstuvwxyz', $lower)
        || str_contains('01234567890123456789', $lower)) {
        throw new CarmajaAdminException(
            CARMAJA_ADMIN_EXIT_INPUT,
            'Passwort ist offensichtlich zu schwach.'
        );
    }
}

function carmaja_admin_hash_password(string $password, string $username): string
{
    carmaja_admin_validate_password($password, $username);
    $hash = password_hash($password, PASSWORD_DEFAULT);

    if (!is_string($hash) || $hash === '') {
        throw new CarmajaAdminException(
            CARMAJA_ADMIN_EXIT_IO,
            'Passworthash konnte nicht erzeugt werden.'
        );
    }

    return $hash;
}

function carmaja_admin_collect_password(
    string $username,
    callable $passwordPrompt
): string {
    $first = (string) $passwordPrompt('Neues Passwort: ');
    $second = (string) $passwordPrompt('Neues Passwort wiederholen: ');

    try {
        if (!hash_equals($first, $second)) {
            throw new CarmajaAdminException(
                CARMAJA_ADMIN_EXIT_INPUT,
                'Passworteingaben stimmen nicht überein.'
            );
        }

        return carmaja_admin_hash_password($first, $username);
    } finally {
        if (function_exists('sodium_memzero')) {
            sodium_memzero($first);
            sodium_memzero($second);
        }
    }
}

function carmaja_admin_validate_users_data(array $data, string $environment): array
{
    if (($data['environment'] ?? null) !== $environment) {
        throw new CarmajaAdminException(
            CARMAJA_ADMIN_EXIT_IO,
            'Umgebungsmarkierung der Benutzerdatei stimmt nicht überein.'
        );
    }

    $users = $data['users'] ?? null;

    if (!is_array($users) || !array_is_list($users)) {
        throw new CarmajaAdminException(
            CARMAJA_ADMIN_EXIT_IO,
            'Benutzerdatei enthält keine gültige Benutzerliste.'
        );
    }

    $seen = [];

    foreach ($users as $user) {
        if (!is_array($user)
            || !is_string($user['username'] ?? null)
            || !is_string($user['passwordHash'] ?? null)
            || ($user['passwordHash'] ?? '') === '') {
            throw new CarmajaAdminException(
                CARMAJA_ADMIN_EXIT_IO,
                'Benutzerdatei enthält einen ungültigen Datensatz.'
            );
        }

        try {
            $normalized = carmaja_admin_validate_username($user['username']);
        } catch (CarmajaAdminException $error) {
            throw new CarmajaAdminException(
                CARMAJA_ADMIN_EXIT_IO,
                'Benutzerdatei enthält einen ungültigen Benutzernamen.',
                $error
            );
        }

        if ($normalized !== $user['username'] || isset($seen[$normalized])) {
            throw new CarmajaAdminException(
                CARMAJA_ADMIN_EXIT_IO,
                'Benutzerdatei enthält nicht normalisierte oder doppelte Benutzernamen.'
            );
        }

        $seen[$normalized] = true;
    }

    return $data;
}

function carmaja_admin_load_users_unlocked(array $config): array
{
    $fallback = [
        'environment' => $config['environment'],
        'users' => [],
    ];
    $data = carmaja_admin_read_json_unlocked($config['usersFile'], $fallback);

    return carmaja_admin_validate_users_data($data, $config['environment']);
}

function carmaja_admin_find_user_index(array $users, string $username): ?int
{
    foreach ($users as $index => $user) {
        if (is_array($user) && ($user['username'] ?? null) === $username) {
            return $index;
        }
    }

    return null;
}

function carmaja_admin_require_user_missing(array $config, string $username): void
{
    carmaja_admin_with_lock(
        $config['usersFile'],
        LOCK_SH,
        function () use ($config, $username): void {
            $data = carmaja_admin_load_users_unlocked($config);

            if (carmaja_admin_find_user_index($data['users'], $username) !== null) {
                throw new CarmajaAdminException(
                    CARMAJA_ADMIN_EXIT_CONFLICT,
                    'Benutzer existiert bereits.'
                );
            }
        }
    );
}

function carmaja_admin_validate_devices_data(array $data, string $environment): array
{
    if (isset($data['environment']) && $data['environment'] !== $environment) {
        throw new CarmajaAdminException(
            CARMAJA_ADMIN_EXIT_IO,
            'Umgebungsmarkierung der Gerätedatei stimmt nicht überein.'
        );
    }

    $tokens = $data['tokens'] ?? null;

    if (!is_array($tokens)) {
        throw new CarmajaAdminException(
            CARMAJA_ADMIN_EXIT_IO,
            'Gerätedatei enthält keine gültige Tokenliste.'
        );
    }

    foreach ($tokens as $tokenId => $token) {
        if (!is_string($tokenId)
            || preg_match(CARMAJA_ADMIN_DEVICE_ID_PATTERN, $tokenId) !== 1
            || !is_array($token)
            || ($token['tokenId'] ?? null) !== $tokenId
            || !is_string($token['username'] ?? null)) {
            throw new CarmajaAdminException(
                CARMAJA_ADMIN_EXIT_IO,
                'Gerätedatei enthält einen ungültigen Datensatz.'
            );
        }
    }

    $data['environment'] = $environment;

    return $data;
}

function carmaja_admin_load_devices_unlocked(array $config): array
{
    $fallback = [
        'environment' => $config['environment'],
        'tokens' => [],
    ];
    $data = carmaja_admin_read_json_unlocked($config['devicesFile'], $fallback);

    return carmaja_admin_validate_devices_data($data, $config['environment']);
}

function carmaja_admin_audit(
    array $config,
    string $action,
    ?string $username,
    ?string $deviceId,
    string $result
): void {
    $path = $config['auditDir']
        . DIRECTORY_SEPARATOR
        . 'admin-actions-'
        . gmdate('Y-m')
        . '.jsonl';
    $entry = [
        'at' => carmaja_admin_now(),
        'action' => $action,
        'username' => $username,
        'deviceId' => $deviceId,
        'result' => $result,
    ];

    try {
        $line = json_encode(
            $entry,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
        ) . PHP_EOL;
    } catch (JsonException $error) {
        throw new CarmajaAdminException(
            CARMAJA_ADMIN_EXIT_IO,
            'Audit-Eintrag konnte nicht serialisiert werden.',
            $error
        );
    }

    carmaja_admin_with_lock($path, LOCK_EX, function () use ($path, $line): void {
        $handle = fopen($path, 'ab');

        if ($handle === false) {
            throw new CarmajaAdminException(
                CARMAJA_ADMIN_EXIT_IO,
                'Auditlog konnte nicht geöffnet werden.'
            );
        }

        try {
            $written = fwrite($handle, $line);

            if ($written !== strlen($line) || !fflush($handle)) {
                throw new CarmajaAdminException(
                    CARMAJA_ADMIN_EXIT_IO,
                    'Auditlog konnte nicht vollständig geschrieben werden.'
                );
            }

            @chmod($path, 0640);
        } finally {
            fclose($handle);
        }
    });
}

function carmaja_admin_create_user(
    array $config,
    string $username,
    string $passwordHash
): void {
    carmaja_admin_with_lock(
        $config['usersFile'],
        LOCK_EX,
        function () use ($config, $username, $passwordHash): void {
            $data = carmaja_admin_load_users_unlocked($config);

            if (carmaja_admin_find_user_index($data['users'], $username) !== null) {
                throw new CarmajaAdminException(
                    CARMAJA_ADMIN_EXIT_CONFLICT,
                    'Benutzer existiert bereits.'
                );
            }

            $now = carmaja_admin_now();
            $data['users'][] = [
                'username' => $username,
                'passwordHash' => $passwordHash,
                'active' => true,
                'createdAt' => $now,
                'updatedAt' => $now,
            ];
            carmaja_admin_write_json_atomic($config['usersFile'], $data);
        }
    );

    carmaja_admin_audit($config, 'user_create', $username, null, 'success');
}

function carmaja_admin_change_password(
    array $config,
    string $username,
    string $passwordHash
): void {
    carmaja_admin_with_lock(
        $config['usersFile'],
        LOCK_EX,
        function () use ($config, $username, $passwordHash): void {
            $data = carmaja_admin_load_users_unlocked($config);
            $index = carmaja_admin_find_user_index($data['users'], $username);

            if ($index === null) {
                throw new CarmajaAdminException(
                    CARMAJA_ADMIN_EXIT_CONFLICT,
                    'Benutzer wurde nicht gefunden.'
                );
            }

            $data['users'][$index]['passwordHash'] = $passwordHash;
            $data['users'][$index]['updatedAt'] = carmaja_admin_now();
            carmaja_admin_write_json_atomic($config['usersFile'], $data);
        }
    );

    carmaja_admin_audit($config, 'user_password_change', $username, null, 'success');
}

function carmaja_admin_list_devices(
    array $config,
    ?string $username = null
): array {
    $rows = carmaja_admin_with_lock(
        $config['devicesFile'],
        LOCK_SH,
        function () use ($config, $username): array {
            $data = carmaja_admin_load_devices_unlocked($config);
            $rows = [];

            foreach ($data['tokens'] as $tokenId => $token) {
                if ($username !== null && ($token['username'] ?? null) !== $username) {
                    continue;
                }

                $revokedAt = is_string($token['revokedAt'] ?? null)
                    ? $token['revokedAt']
                    : null;
                $rows[] = [
                    'deviceId' => $tokenId,
                    'username' => (string) $token['username'],
                    'createdAt' => is_string($token['createdAt'] ?? null)
                        ? $token['createdAt']
                        : null,
                    'lastUsedAt' => is_string($token['lastUsedAt'] ?? null)
                        ? $token['lastUsedAt']
                        : null,
                    'revokedAt' => $revokedAt,
                    'status' => $revokedAt === null ? 'aktiv' : 'widerrufen',
                ];
            }

            usort(
                $rows,
                static fn (array $left, array $right): int =>
                    strcmp($left['deviceId'], $right['deviceId'])
            );

            return $rows;
        },
        $config['devicesLock']
    );

    carmaja_admin_audit($config, 'device_list', $username, null, 'success');

    return $rows;
}

function carmaja_admin_revoke_device(
    array $config,
    string $deviceId
): array {
    $result = carmaja_admin_with_lock(
        $config['devicesFile'],
        LOCK_EX,
        function () use ($config, $deviceId): array {
            $data = carmaja_admin_load_devices_unlocked($config);
            $token = $data['tokens'][$deviceId] ?? null;

            if (!is_array($token)) {
                throw new CarmajaAdminException(
                    CARMAJA_ADMIN_EXIT_CONFLICT,
                    'Gerät wurde nicht gefunden.'
                );
            }

            $username = (string) $token['username'];

            if (($token['revokedAt'] ?? null) !== null) {
                return [
                    'username' => $username,
                    'result' => 'already_revoked',
                ];
            }

            $data['tokens'][$deviceId]['revokedAt'] = carmaja_admin_now();
            carmaja_admin_write_json_atomic($config['devicesFile'], $data);

            return [
                'username' => $username,
                'result' => 'success',
            ];
        },
        $config['devicesLock']
    );

    carmaja_admin_audit(
        $config,
        'device_revoke',
        $result['username'],
        $deviceId,
        $result['result']
    );

    return $result;
}

function carmaja_admin_require_user(array $config, string $username): void
{
    carmaja_admin_with_lock(
        $config['usersFile'],
        LOCK_SH,
        function () use ($config, $username): void {
            $data = carmaja_admin_load_users_unlocked($config);

            if (carmaja_admin_find_user_index($data['users'], $username) === null) {
                throw new CarmajaAdminException(
                    CARMAJA_ADMIN_EXIT_CONFLICT,
                    'Benutzer wurde nicht gefunden.'
                );
            }
        }
    );
}

function carmaja_admin_count_active_devices(array $config, string $username): int
{
    return carmaja_admin_with_lock(
        $config['devicesFile'],
        LOCK_SH,
        function () use ($config, $username): int {
            $data = carmaja_admin_load_devices_unlocked($config);
            $count = 0;

            foreach ($data['tokens'] as $token) {
                if (($token['username'] ?? null) === $username
                    && ($token['revokedAt'] ?? null) === null) {
                    $count++;
                }
            }

            return $count;
        },
        $config['devicesLock']
    );
}

function carmaja_admin_revoke_user_devices(
    array $config,
    string $username
): int {
    $count = carmaja_admin_with_lock(
        $config['devicesFile'],
        LOCK_EX,
        function () use ($config, $username): int {
            $data = carmaja_admin_load_devices_unlocked($config);
            $count = 0;
            $revokedAt = carmaja_admin_now();

            foreach ($data['tokens'] as $tokenId => $token) {
                if (($token['username'] ?? null) !== $username
                    || ($token['revokedAt'] ?? null) !== null) {
                    continue;
                }

                $data['tokens'][$tokenId]['revokedAt'] = $revokedAt;
                $count++;
            }

            if ($count > 0) {
                carmaja_admin_write_json_atomic($config['devicesFile'], $data);
            }

            return $count;
        },
        $config['devicesLock']
    );

    carmaja_admin_audit(
        $config,
        'device_revoke_user',
        $username,
        null,
        $count > 0 ? 'success' : 'no_active_devices'
    );

    return $count;
}

function carmaja_admin_parse_options(array $arguments, array $allowed): array
{
    $options = [];

    for ($index = 0; $index < count($arguments); $index++) {
        $argument = $arguments[$index];

        if (!is_string($argument) || !str_starts_with($argument, '--')) {
            throw new CarmajaAdminException(
                CARMAJA_ADMIN_EXIT_USAGE,
                'Unzulässiges Kommandozeilenargument.'
            );
        }

        if (str_starts_with(strtolower($argument), '--password')) {
            throw new CarmajaAdminException(
                CARMAJA_ADMIN_EXIT_USAGE,
                'Passwörter werden niemals als Kommandozeilenargument akzeptiert.'
            );
        }

        $name = substr($argument, 2);
        $value = null;

        if (str_contains($name, '=')) {
            [$name, $value] = explode('=', $name, 2);
        } elseif (($arguments[$index + 1] ?? null) !== null
            && is_string($arguments[$index + 1])
            && !str_starts_with($arguments[$index + 1], '--')) {
            $value = $arguments[++$index];
        }

        if (!in_array($name, $allowed, true) || $value === null || $value === '') {
            throw new CarmajaAdminException(
                CARMAJA_ADMIN_EXIT_USAGE,
                'Unbekannte oder unvollständige Option: --' . $name
            );
        }

        if (array_key_exists($name, $options)) {
            throw new CarmajaAdminException(
                CARMAJA_ADMIN_EXIT_USAGE,
                'Option wurde mehrfach angegeben: --' . $name
            );
        }

        $options[$name] = $value;
    }

    return $options;
}

function carmaja_admin_prompt_line(string $prompt): string
{
    fwrite(STDERR, $prompt);
    $line = fgets(STDIN);

    if (!is_string($line)) {
        throw new CarmajaAdminException(
            CARMAJA_ADMIN_EXIT_USAGE,
            'Eingabe wurde abgebrochen.'
        );
    }

    return rtrim($line, "\r\n");
}

function carmaja_admin_prompt_hidden(string $prompt): string
{
    if (PHP_OS_FAMILY !== 'Linux'
        || !is_readable('/dev/tty')
        || !function_exists('shell_exec')
        || !function_exists('exec')) {
        throw new CarmajaAdminException(
            CARMAJA_ADMIN_EXIT_IO,
            'Verdeckte Passworteingabe wird nur in einer interaktiven Linux-/SSH-Sitzung unterstützt.'
        );
    }

    $tty = fopen('/dev/tty', 'r+');

    if ($tty === false) {
        throw new CarmajaAdminException(
            CARMAJA_ADMIN_EXIT_IO,
            'Sichere Terminaleingabe ist nicht verfügbar.'
        );
    }

    $settings = trim((string) shell_exec('stty -g < /dev/tty'));

    if ($settings === '') {
        fclose($tty);
        throw new CarmajaAdminException(
            CARMAJA_ADMIN_EXIT_IO,
            'Terminalstatus konnte nicht gelesen werden.'
        );
    }

    $status = 1;
    exec('stty -echo < /dev/tty', $unused, $status);

    if ($status !== 0) {
        fclose($tty);
        throw new CarmajaAdminException(
            CARMAJA_ADMIN_EXIT_IO,
            'Terminalecho konnte nicht sicher deaktiviert werden.'
        );
    }

    try {
        fwrite($tty, $prompt);
        $value = fgets($tty, 8193);

        if (!is_string($value)) {
            throw new CarmajaAdminException(
                CARMAJA_ADMIN_EXIT_USAGE,
                'Passworteingabe wurde abgebrochen.'
            );
        }

        return rtrim($value, "\r\n");
    } finally {
        exec('stty ' . escapeshellarg($settings) . ' < /dev/tty');
        fwrite($tty, PHP_EOL);
        fclose($tty);
    }
}

function carmaja_admin_print_device_rows(array $rows, callable $output): void
{
    $output("Geräte-ID\tBenutzer\tErstellt\tLetzte Nutzung\tWiderrufen\tStatus\n");

    foreach ($rows as $row) {
        $output(implode("\t", [
            $row['deviceId'],
            $row['username'],
            $row['createdAt'] ?? '-',
            $row['lastUsedAt'] ?? '-',
            $row['revokedAt'] ?? '-',
            $row['status'],
        ]) . PHP_EOL);
    }

    $output('Anzahl: ' . count($rows) . PHP_EOL);
}

function carmaja_admin_usage(): string
{
    return implode(PHP_EOL, [
        'Verwendung:',
        '  php product-admin.php user:create [--username NAME]',
        '  php product-admin.php user:password [--username NAME]',
        '  php product-admin.php device:list [--username NAME]',
        '  php product-admin.php device:revoke [--device-id ID]',
        '  php product-admin.php device:revoke-user [--username NAME]',
    ]) . PHP_EOL;
}

function carmaja_admin_execute(
    array $argv,
    callable $linePrompt,
    callable $passwordPrompt,
    callable $output
): void {
    $command = $argv[1] ?? null;

    if (!is_string($command) || $command === '') {
        throw new CarmajaAdminException(
            CARMAJA_ADMIN_EXIT_USAGE,
            carmaja_admin_usage()
        );
    }

    if ($command === '--help' || $command === 'help') {
        $output(carmaja_admin_usage());
        return;
    }

    $allowedCommands = [
        'user:create',
        'user:password',
        'device:list',
        'device:revoke',
        'device:revoke-user',
    ];

    if (!in_array($command, $allowedCommands, true)) {
        throw new CarmajaAdminException(
            CARMAJA_ADMIN_EXIT_USAGE,
            'Unbekannter Befehl.' . PHP_EOL . carmaja_admin_usage()
        );
    }

    $allowedOptions = match ($command) {
        'device:revoke' => ['device-id'],
        default => ['username'],
    };
    $options = carmaja_admin_parse_options(array_slice($argv, 2), $allowedOptions);
    $config = carmaja_admin_build_config();

    if ($command === 'user:create') {
        $rawUsername = $options['username']
            ?? $linePrompt('Benutzername: ');
        $username = carmaja_admin_validate_username($rawUsername);
        carmaja_admin_require_user_missing($config, $username);
        $passwordHash = carmaja_admin_collect_password($username, $passwordPrompt);
        carmaja_admin_create_user($config, $username, $passwordHash);
        $output('Benutzer "' . $username . '" wurde angelegt.' . PHP_EOL);
        return;
    }

    if ($command === 'user:password') {
        $rawUsername = $options['username']
            ?? $linePrompt('Benutzername: ');
        $username = carmaja_admin_validate_username($rawUsername);
        carmaja_admin_require_user($config, $username);
        $passwordHash = carmaja_admin_collect_password($username, $passwordPrompt);
        carmaja_admin_change_password($config, $username, $passwordHash);
        $answer = strtolower(trim($linePrompt(
            'Alle Geräte dieses Benutzers widerrufen? [j/N]: '
        )));

        if (in_array($answer, ['j', 'ja', 'y', 'yes'], true)) {
            $revoked = carmaja_admin_revoke_user_devices($config, $username);
            $output('Passwort geändert; widerrufene aktive Geräte: ' . $revoked . PHP_EOL);
            return;
        }

        $output('Passwort geändert; bestehende Geräte bleiben aktiv.' . PHP_EOL);
        return;
    }

    if ($command === 'device:list') {
        $username = isset($options['username'])
            ? carmaja_admin_validate_username($options['username'])
            : null;
        $rows = carmaja_admin_list_devices($config, $username);
        carmaja_admin_print_device_rows($rows, $output);
        return;
    }

    if ($command === 'device:revoke') {
        $deviceId = strtolower(trim(
            $options['device-id'] ?? $linePrompt('Geräte-ID: ')
        ));

        if (preg_match(CARMAJA_ADMIN_DEVICE_ID_PATTERN, $deviceId) !== 1) {
            throw new CarmajaAdminException(
                CARMAJA_ADMIN_EXIT_INPUT,
                'Geräte-ID ist ungültig.'
            );
        }

        $result = carmaja_admin_revoke_device($config, $deviceId);
        $output(
            $result['result'] === 'already_revoked'
                ? 'Gerät war bereits widerrufen.' . PHP_EOL
                : 'Gerät wurde widerrufen.' . PHP_EOL
        );
        return;
    }

    $rawUsername = $options['username']
        ?? $linePrompt('Benutzername: ');
    $username = carmaja_admin_validate_username($rawUsername);
    carmaja_admin_require_user($config, $username);
    $activeCount = carmaja_admin_count_active_devices($config, $username);
    $output('Aktive Geräte für "' . $username . '": ' . $activeCount . PHP_EOL);

    if ($activeCount === 0) {
        carmaja_admin_audit(
            $config,
            'device_revoke_user',
            $username,
            null,
            'no_active_devices'
        );
        $output('Keine aktiven Geräte vorhanden.' . PHP_EOL);
        return;
    }

    $confirmation = trim($linePrompt(
        'Zum Widerruf aller aktiven Geräte WIDERRUFEN eingeben: '
    ));

    if ($confirmation !== 'WIDERRUFEN') {
        carmaja_admin_audit(
            $config,
            'device_revoke_user',
            $username,
            null,
            'cancelled'
        );
        throw new CarmajaAdminException(
            CARMAJA_ADMIN_EXIT_USAGE,
            'Widerruf wurde nicht bestätigt.'
        );
    }

    $revoked = carmaja_admin_revoke_user_devices($config, $username);
    $output('Widerrufene aktive Geräte: ' . $revoked . PHP_EOL);
}

function carmaja_admin_main(array $argv, array $io = []): int
{
    $linePrompt = $io['linePrompt'] ?? 'carmaja_admin_prompt_line';
    $passwordPrompt = $io['passwordPrompt'] ?? 'carmaja_admin_prompt_hidden';
    $output = $io['output'] ?? static function (string $message): void {
        fwrite(STDOUT, $message);
    };
    $errorOutput = $io['error'] ?? static function (string $message): void {
        fwrite(STDERR, $message);
    };

    try {
        carmaja_admin_execute($argv, $linePrompt, $passwordPrompt, $output);
        return CARMAJA_ADMIN_EXIT_SUCCESS;
    } catch (CarmajaAdminException $error) {
        $errorOutput(rtrim($error->getMessage()) . PHP_EOL);
        return $error->exitCode;
    } catch (Throwable) {
        $errorOutput('Unerwarteter interner Fehler.' . PHP_EOL);
        return CARMAJA_ADMIN_EXIT_IO;
    }
}

$scriptFilename = $_SERVER['SCRIPT_FILENAME'] ?? '';

if (is_string($scriptFilename)
    && realpath($scriptFilename) === __FILE__) {
    exit(carmaja_admin_main($argv));
}
