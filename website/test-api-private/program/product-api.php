<?php

declare(strict_types=1);

const CARMAJA_API_STATUSES = ['draft', 'ready', 'published', 'sold', 'disabled'];
const CARMAJA_DRAFT_PATTERN = '/^[0-9a-fA-F-]{36}$|^[0-9A-HJKMNP-TV-Z]{26}$/';
const CARMAJA_IMAGE_PATTERN =
    '/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[1-5][0-9a-fA-F]{3}-[89abAB][0-9a-fA-F]{3}-[0-9a-fA-F]{12}$/';
const CARMAJA_OPERATION_PATTERN = '/^[A-Za-z0-9._:-]{8,100}$/';
const CARMAJA_SLUG_PATTERN = '/^[a-z0-9]+(?:-[a-z0-9]+)*$/';
const CARMAJA_MAX_IMAGES = 5;
const CARMAJA_MAX_IMAGE_BYTES = 1048576;
const CARMAJA_MAX_IMAGE_EDGE = 1600;
const CARMAJA_BACKUP_KEEP = 30;
const CARMAJA_LOGIN_LIMIT = 5;
const CARMAJA_LOGIN_WINDOW_SECONDS = 900;
const CARMAJA_OPERATION_LEASE_SECONDS = 900;
const CARMAJA_OPERATION_RETENTION_SECONDS = 2592000;
const CARMAJA_TEST_REPOSITORY = 'Bumpers210/armband-rechner';
const CARMAJA_TEST_BRANCH = 'test/product-management-beta';
const CARMAJA_TEST_DEPLOY_WORKFLOW = 'deploy-test-website.yml';

class CarmajaApiException extends RuntimeException
{
    public readonly array $details;
    public readonly array $fields;
    public readonly string $errorCode;

    public function __construct(
        public readonly int $statusCode,
        string $message,
        array $fields = [],
        ?string $errorCode = null
    ) {
        parent::__construct($message);
        $this->details = $fields;
        $this->fields = $fields;
        $this->errorCode = $errorCode ?? carmaja_api_default_error_code($statusCode);
    }
}

function carmaja_api_default_error_code(int $statusCode): string
{
    return match ($statusCode) {
        400 => 'invalid_request',
        401 => 'authentication_required',
        403 => 'forbidden',
        404 => 'not_found',
        409 => 'conflict',
        413 => 'payload_too_large',
        422 => 'validation_failed',
        429 => 'rate_limited',
        502 => 'upstream_error',
        503 => 'service_unavailable',
        default => 'internal_error',
    };
}

function carmaja_api_success_response(array $data): array
{
    return [
        'ok' => true,
        'data' => $data,
    ];
}

function carmaja_api_error_response(CarmajaApiException $error): array
{
    return [
        'ok' => false,
        'error' => [
            'code' => $error->errorCode,
            'message' => $error->getMessage(),
            'fields' => (object) $error->fields,
        ],
    ];
}

function carmaja_api_now(): string
{
    return gmdate('c');
}

function carmaja_api_timestamp(int $secondsFromNow = 0): string
{
    return gmdate('c', time() + $secondsFromNow);
}

function carmaja_api_publish_target(): string
{
    $target = getenv('CARMAJA_PUBLISH_TARGET');

    if (!is_string($target) || !in_array(trim($target), ['test', 'production'], true)) {
        throw new CarmajaApiException(
            503,
            'Veröffentlichungsziel ist nicht sicher konfiguriert.',
            [],
            'publish_target_not_configured'
        );
    }

    return trim($target);
}

function carmaja_api_path_is_absolute(string $path): bool
{
    $normalized = str_replace('\\', '/', $path);

    return !str_contains($path, "\0")
        && !in_array('..', explode('/', $normalized), true)
        && (str_starts_with($normalized, '/')
            || preg_match('/^[A-Za-z]:\//', $normalized) === 1);
}

function carmaja_api_normalize_path(string $path): string
{
    $normalized = str_replace('\\', '/', rtrim($path, "\\/"));

    return DIRECTORY_SEPARATOR === '\\' ? strtolower($normalized) : $normalized;
}

function carmaja_api_path_is_inside(string $path, string $directory): bool
{
    $normalizedPath = carmaja_api_normalize_path($path);
    $normalizedDirectory = carmaja_api_normalize_path($directory);

    return $normalizedPath === $normalizedDirectory
        || str_starts_with($normalizedPath, $normalizedDirectory . '/');
}

function carmaja_api_required_path_setting(string $name): string
{
    $path = getenv($name);

    if (!is_string($path) || trim($path) === '' || !carmaja_api_path_is_absolute(trim($path))) {
        throw new CarmajaApiException(
            503,
            $name . ' ist nicht sicher konfiguriert.',
            [],
            'environment_configuration_invalid'
        );
    }

    return rtrim(trim($path), "\\/");
}

function carmaja_api_optional_path_setting(string $name): ?string
{
    $path = getenv($name);

    if ($path === false || (is_string($path) && trim($path) === '')) {
        return null;
    }

    if (!is_string($path) || !carmaja_api_path_is_absolute(trim($path))) {
        throw new CarmajaApiException(
            503,
            $name . ' ist nicht sicher konfiguriert.',
            [],
            'environment_configuration_invalid'
        );
    }

    return rtrim(trim($path), "\\/");
}

function carmaja_api_required_directory_setting(string $name): string
{
    $path = carmaja_api_required_path_setting($name);
    $realPath = realpath($path);

    if (!is_string($realPath) || !is_dir($realPath)) {
        throw new CarmajaApiException(
            503,
            $name . ' ist nicht als Verzeichnis erreichbar.',
            [],
            'environment_directory_unavailable'
        );
    }

    return $realPath;
}

function carmaja_api_read_environment_marker(string $directory): string
{
    $markerPath = $directory . DIRECTORY_SEPARATOR . 'environment.json';
    $raw = is_file($markerPath) ? file_get_contents($markerPath) : false;

    if (!is_string($raw) || trim($raw) === '') {
        throw new CarmajaApiException(
            503,
            'Umgebungsmarkierung des privaten Datenbereichs fehlt.',
            [],
            'environment_marker_missing'
        );
    }

    try {
        $marker = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException $error) {
        throw new CarmajaApiException(
            503,
            'Umgebungsmarkierung ist ungültig.',
            [],
            'environment_marker_invalid'
        );
    }

    if (!is_array($marker) || !is_string($marker['environment'] ?? null)) {
        throw new CarmajaApiException(
            503,
            'Umgebungsmarkierung ist ungültig.',
            [],
            'environment_marker_invalid'
        );
    }

    return $marker['environment'];
}

function carmaja_api_private_dir(): string
{
    $target = carmaja_api_publish_target();
    $configuredPath = carmaja_api_required_path_setting('CARMAJA_PRIVATE_DIR');
    $testPath = $target === 'test'
        ? carmaja_api_required_path_setting('CARMAJA_TEST_PRIVATE_DIR')
        : carmaja_api_optional_path_setting('CARMAJA_TEST_PRIVATE_DIR');
    $productionPath = $target === 'production'
        ? carmaja_api_required_path_setting('CARMAJA_PRODUCTION_PRIVATE_DIR')
        : carmaja_api_optional_path_setting('CARMAJA_PRODUCTION_PRIVATE_DIR');
    $publicWebroot = carmaja_api_required_path_setting('CARMAJA_PUBLIC_WEBROOT');

    if ($testPath !== null
        && $productionPath !== null
        && carmaja_api_normalize_path($testPath)
            === carmaja_api_normalize_path($productionPath)) {
        throw new CarmajaApiException(
            503,
            'Test- und Produktionsdatenpfad dürfen nicht identisch sein.',
            [],
            'environment_paths_not_separated'
        );
    }

    $expectedPath = $target === 'test' ? $testPath : (string) $productionPath;

    if (carmaja_api_normalize_path($configuredPath) !== carmaja_api_normalize_path($expectedPath)) {
        throw new CarmajaApiException(
            503,
            'Privater Datenpfad passt nicht zum Veröffentlichungsziel.',
            [],
            'publish_target_path_mismatch'
        );
    }

    $privateRealPath = realpath($configuredPath);
    $webrootRealPath = realpath($publicWebroot);

    if (!is_string($privateRealPath)
        || !is_dir($privateRealPath)
        || !is_readable($privateRealPath)
        || !is_writable($privateRealPath)) {
        throw new CarmajaApiException(
            503,
            'Privater Datenpfad ist nicht sicher erreichbar.',
            [],
            'private_path_unavailable'
        );
    }

    if (!is_string($webrootRealPath)
        || !is_dir($webrootRealPath)
        || carmaja_api_path_is_inside($privateRealPath, $webrootRealPath)
        || carmaja_api_path_is_inside($webrootRealPath, $privateRealPath)) {
        throw new CarmajaApiException(
            503,
            'Privater Datenpfad und öffentlicher Webroot sind nicht sicher getrennt.',
            [],
            'private_path_exposed'
        );
    }

    if (carmaja_api_read_environment_marker($privateRealPath) !== $target) {
        throw new CarmajaApiException(
            503,
            'Umgebungsmarkierung stimmt nicht mit dem Veröffentlichungsziel überein.',
            [],
            'environment_marker_mismatch'
        );
    }

    return $privateRealPath;
}

function carmaja_api_path(string $relativePath): string
{
    $normalized = str_replace('\\', '/', $relativePath);

    if ($normalized === ''
        || str_contains($normalized, '..')
        || str_starts_with($normalized, '/')) {
        throw new CarmajaApiException(500, 'Ungueltiger interner Pfad.');
    }

    return carmaja_api_private_dir() . DIRECTORY_SEPARATOR
        . str_replace('/', DIRECTORY_SEPARATOR, $normalized);
}

function carmaja_api_ensure_directory(string $path): void
{
    if (!is_dir($path)
        && !mkdir($path, 0750, true)
        && !is_dir($path)) {
        throw new CarmajaApiException(500, 'Verzeichnis konnte nicht erstellt werden.');
    }
}

function carmaja_api_read_json(string $path, array $fallback = []): array
{
    if (!is_file($path)) {
        return $fallback;
    }

    $handle = fopen($path, 'r');

    if ($handle === false) {
        throw new CarmajaApiException(500, 'Datei ist nicht lesbar.');
    }

    try {
        if (!flock($handle, LOCK_SH)) {
            throw new CarmajaApiException(500, 'Datei konnte nicht gesperrt werden.');
        }

        $contents = stream_get_contents($handle);
        flock($handle, LOCK_UN);
    } finally {
        fclose($handle);
    }

    if (!is_string($contents) || trim($contents) === '') {
        return $fallback;
    }

    $decoded = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);

    if (!is_array($decoded)) {
        throw new CarmajaApiException(500, 'JSON-Datei enthaelt kein Objekt.');
    }

    return $decoded;
}

function carmaja_api_write_json_atomic(string $path, array $data): void
{
    carmaja_api_ensure_directory(dirname($path));

    $encoded = json_encode(
        $data,
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
    ) . PHP_EOL;
    $temporaryPath = $path . '.tmp.' . bin2hex(random_bytes(6));

    try {
        if (file_put_contents($temporaryPath, $encoded, LOCK_EX) === false) {
            throw new CarmajaApiException(500, 'Datei konnte nicht geschrieben werden.');
        }

        @chmod($temporaryPath, 0640);
        $validated = carmaja_api_read_json($temporaryPath);

        if ($validated !== $data) {
            throw new CarmajaApiException(500, 'Geschriebene JSON-Datei ist nicht konsistent.');
        }

        if (!rename($temporaryPath, $path)) {
            throw new CarmajaApiException(500, 'Datei konnte nicht atomar übernommen werden.');
        }

        @chmod($path, 0640);
    } finally {
        @unlink($temporaryPath);
    }
}

function carmaja_api_target_document(array $data): array
{
    $data['environment'] = carmaja_api_publish_target();
    return $data;
}

function carmaja_api_validate_target_document(array $data, string $label): array
{
    if (($data['environment'] ?? null) !== carmaja_api_publish_target()) {
        throw new CarmajaApiException(
            503,
            $label . ' gehört zu einer anderen Umgebung.',
            [],
            'data_environment_mismatch'
        );
    }

    return $data;
}

function carmaja_api_read_target_json(
    string $path,
    array $fallback,
    string $label
): array {
    $data = carmaja_api_read_json($path, carmaja_api_target_document($fallback));

    return carmaja_api_validate_target_document($data, $label);
}

function carmaja_api_lock_path(string $name): string
{
    return carmaja_api_path('locks/' . preg_replace('/[^A-Za-z0-9._-]/', '_', $name) . '.lock');
}

function carmaja_api_with_lock(string $name, callable $callback): mixed
{
    $path = carmaja_api_lock_path($name);
    carmaja_api_ensure_directory(dirname($path));
    $handle = fopen($path, 'c');

    if ($handle === false) {
        throw new CarmajaApiException(500, 'Lock konnte nicht geoeffnet werden.');
    }

    try {
        if (!flock($handle, LOCK_EX)) {
            throw new CarmajaApiException(500, 'Lock konnte nicht gesetzt werden.');
        }

        return $callback();
    } finally {
        flock($handle, LOCK_UN);
        fclose($handle);
    }
}

function carmaja_api_json_body(): array
{
    $raw = file_get_contents('php://input');

    if (!is_string($raw) || trim($raw) === '') {
        return [];
    }

    $decoded = json_decode($raw, true);

    if (!is_array($decoded)) {
        throw new CarmajaApiException(400, 'Ungueltiger JSON-Body.');
    }

    return $decoded;
}

function carmaja_api_validate_draft_id(string $draftId): void
{
    if (preg_match(CARMAJA_DRAFT_PATTERN, $draftId) !== 1) {
        throw new CarmajaApiException(400, 'Ungueltige draftId.');
    }
}

function carmaja_api_validate_operation_id(string $operationId): void
{
    if (preg_match(CARMAJA_OPERATION_PATTERN, $operationId) !== 1) {
        throw new CarmajaApiException(400, 'Ungueltige operationId.');
    }
}

function carmaja_api_draft_path(string $draftId): string
{
    carmaja_api_validate_draft_id($draftId);
    return carmaja_api_path('drafts/' . $draftId . '.json');
}

function carmaja_api_load_draft(string $draftId): ?array
{
    $path = carmaja_api_draft_path($draftId);

    if (!is_file($path)) {
        return null;
    }

    return carmaja_api_read_target_json($path, [], 'Produktentwurf');
}

function carmaja_api_save_draft(array $draft): array
{
    $draft = carmaja_api_target_document($draft);
    carmaja_api_write_json_atomic(carmaja_api_draft_path((string) $draft['draftId']), $draft);
    return $draft;
}

function carmaja_api_tokens_path(): string
{
    return carmaja_api_path('auth/device-tokens.json');
}

function carmaja_api_configured_users_file(): string
{
    $path = carmaja_api_required_path_setting('CARMAJA_API_USERS_FILE');
    $parentPath = realpath(dirname($path));
    $privatePath = carmaja_api_private_dir();

    if (!is_string($parentPath)
        || !is_dir($parentPath)
        || !is_readable($parentPath)
        || !is_writable($parentPath)) {
        throw new CarmajaApiException(
            503,
            'Benutzerdatei ist nicht sicher erreichbar.',
            [],
            'users_file_unavailable'
        );
    }

    $realPath = $parentPath . DIRECTORY_SEPARATOR . basename($path);

    if (!carmaja_api_path_is_inside($realPath, $privatePath)) {
        throw new CarmajaApiException(
            503,
            'Benutzerdatei liegt nicht im privaten Datenbereich.',
            [],
            'users_file_exposed'
        );
    }

    return $realPath;
}

function carmaja_api_users_file(): string
{
    $path = carmaja_api_configured_users_file();
    $realPath = realpath($path);

    if (!is_string($realPath) || !is_file($realPath) || !is_readable($realPath)) {
        throw new CarmajaApiException(
            503,
            'Benutzerdatei ist nicht sicher erreichbar.',
            [],
            'users_file_unavailable'
        );
    }

    return $realPath;
}

function carmaja_api_normalize_username(string $username): string
{
    return strtolower(trim($username));
}

function carmaja_api_username_is_valid(string $username): bool
{
    return preg_match('/^[a-z0-9][a-z0-9._-]{1,62}[a-z0-9]$/', $username) === 1;
}

function carmaja_api_load_users(): array
{
    $users = carmaja_api_read_target_json(
        carmaja_api_users_file(),
        ['users' => []],
        'Benutzerdatei'
    );

    if (!is_array($users['users'] ?? null) || !array_is_list($users['users'])) {
        throw new CarmajaApiException(
            503,
            'Benutzerdatei ist ungültig.',
            [],
            'users_file_invalid'
        );
    }

    $seen = [];

    foreach ($users['users'] as $user) {
        if (!is_array($user)
            || !is_string($user['username'] ?? null)
            || !is_string($user['passwordHash'] ?? null)
            || !carmaja_api_username_is_valid($user['username'])
            || $user['username'] !== carmaja_api_normalize_username($user['username'])
            || isset($seen[$user['username']])) {
            throw new CarmajaApiException(
                503,
                'Benutzerdatei ist ungültig.',
                [],
                'users_file_invalid'
            );
        }

        $seen[$user['username']] = true;
    }

    return $users;
}

function carmaja_api_token_pepper(): string
{
    $pepper = getenv('CARMAJA_TOKEN_PEPPER');

    if (!is_string($pepper) || strlen($pepper) < 32) {
        throw new CarmajaApiException(503, 'CARMAJA_TOKEN_PEPPER ist nicht sicher konfiguriert.');
    }

    return $pepper;
}

function carmaja_api_hash_token_secret(string $secret): string
{
    return hash_hmac('sha256', $secret, carmaja_api_token_pepper());
}

function carmaja_api_load_tokens(): array
{
    $data = carmaja_api_read_target_json(
        carmaja_api_tokens_path(),
        ['tokens' => []],
        'Gerätedatei'
    );

    if (!is_array($data['tokens'] ?? null)) {
        throw new CarmajaApiException(
            503,
            'Gerätedatei ist ungültig.',
            [],
            'device_tokens_file_invalid'
        );
    }

    return $data;
}

function carmaja_api_store_tokens(array $tokens): void
{
    carmaja_api_write_json_atomic(
        carmaja_api_tokens_path(),
        carmaja_api_target_document($tokens)
    );
}

function carmaja_api_login_attempts_path(): string
{
    return carmaja_api_path('auth/login-attempts.json');
}

function carmaja_api_load_login_attempts(): array
{
    $attempts = carmaja_api_read_target_json(
        carmaja_api_login_attempts_path(),
        ['attempts' => []],
        'Loginbegrenzung'
    );
    $attempts['attempts'] = is_array($attempts['attempts'] ?? null)
        ? $attempts['attempts']
        : [];

    return $attempts;
}

function carmaja_api_login_attempt_key(string $username): string
{
    return hash('sha256', carmaja_api_publish_target() . "\0" . $username);
}

function carmaja_api_assert_login_allowed(string $username): void
{
    carmaja_api_with_lock('login-attempts', function () use ($username): void {
        $attempts = carmaja_api_load_login_attempts();
        $entry = $attempts['attempts'][carmaja_api_login_attempt_key($username)] ?? null;
        $lockedUntil = is_array($entry) ? strtotime((string) ($entry['lockedUntil'] ?? '')) : false;

        if (is_int($lockedUntil) && $lockedUntil > time()) {
            throw new CarmajaApiException(
                429,
                'Anmeldung ist vorübergehend gesperrt.',
                [],
                'login_rate_limited'
            );
        }
    });
}

function carmaja_api_record_login_failure(string $username): void
{
    carmaja_api_with_lock('login-attempts', function () use ($username): void {
        $attempts = carmaja_api_load_login_attempts();
        $key = carmaja_api_login_attempt_key($username);
        $entry = is_array($attempts['attempts'][$key] ?? null)
            ? $attempts['attempts'][$key]
            : [];
        $windowStartedAt = strtotime((string) ($entry['windowStartedAt'] ?? ''));

        if (!is_int($windowStartedAt)
            || $windowStartedAt <= time() - CARMAJA_LOGIN_WINDOW_SECONDS) {
            $entry = [
                'windowStartedAt' => carmaja_api_now(),
                'count' => 0,
                'lockedUntil' => null,
            ];
        }

        $entry['count'] = ((int) ($entry['count'] ?? 0)) + 1;
        $entry['updatedAt'] = carmaja_api_now();

        if ($entry['count'] >= CARMAJA_LOGIN_LIMIT) {
            $entry['lockedUntil'] = carmaja_api_timestamp(CARMAJA_LOGIN_WINDOW_SECONDS);
        }

        $attempts['attempts'][$key] = $entry;
        carmaja_api_write_json_atomic(
            carmaja_api_login_attempts_path(),
            carmaja_api_target_document($attempts)
        );
    });
}

function carmaja_api_clear_login_failures(string $username): void
{
    carmaja_api_with_lock('login-attempts', function () use ($username): void {
        $attempts = carmaja_api_load_login_attempts();
        $key = carmaja_api_login_attempt_key($username);

        if (array_key_exists($key, $attempts['attempts'])) {
            unset($attempts['attempts'][$key]);
            carmaja_api_write_json_atomic(
                carmaja_api_login_attempts_path(),
                carmaja_api_target_document($attempts)
            );
        }
    });
}

function carmaja_api_login(array $body): array
{
    carmaja_api_reject_unknown_fields(
        $body,
        ['username', 'password', 'deviceName', 'publishTarget']
    );
    $rawUsername = $body['username'] ?? null;
    $password = $body['password'] ?? null;
    $deviceName = $body['deviceName'] ?? 'Android';
    $requestedTarget = $body['publishTarget'] ?? null;

    if (!is_string($rawUsername)
        || !is_string($password)
        || !is_string($deviceName)
        || !is_string($requestedTarget)
        || trim($rawUsername) === ''
        || trim($password) === '') {
        throw new CarmajaApiException(
            400,
            'Benutzername, Passwort, Gerät und Veröffentlichungsziel sind erforderlich.',
            [],
            'login_request_invalid'
        );
    }

    $target = carmaja_api_publish_target();

    if ($requestedTarget !== $target) {
        carmaja_api_audit_best_effort('login_failed', [
            'username' => carmaja_api_normalize_username($rawUsername),
            'result' => 'publish_target_mismatch',
        ]);
        throw new CarmajaApiException(
            409,
            'App und API verwenden unterschiedliche Veröffentlichungsziele.',
            ['publishTarget' => $target],
            'publish_target_mismatch'
        );
    }

    $username = carmaja_api_normalize_username($rawUsername);
    carmaja_api_assert_login_allowed($username);
    $users = carmaja_api_load_users();
    $matchedUser = null;

    foreach (($users['users'] ?? []) as $user) {
        if (is_array($user)
            && ($user['username'] ?? null) === $username
            && ($user['active'] ?? true) === true) {
            $matchedUser = $user;
            break;
        }
    }

    if (!is_array($matchedUser)
        || !is_string($matchedUser['passwordHash'] ?? null)
        || !password_verify($password, $matchedUser['passwordHash'])) {
        carmaja_api_record_login_failure($username);
        carmaja_api_audit_best_effort('login_failed', [
            'username' => $username,
            'result' => 'invalid_credentials',
        ]);
        throw new CarmajaApiException(
            401,
            'Anmeldung fehlgeschlagen.',
            [],
            'invalid_credentials'
        );
    }

    carmaja_api_clear_login_failures($username);
    $tokenId = bin2hex(random_bytes(16));
    $secret = bin2hex(random_bytes(32));
    $token = 'ct_' . $tokenId . '_' . $secret;

    carmaja_api_with_lock('device-tokens', function () use (
        $tokenId,
        $secret,
        $username,
        $deviceName
    ): void {
        $tokens = carmaja_api_load_tokens();
        $tokens['tokens'][$tokenId] = [
            'tokenId' => $tokenId,
            'secretHash' => carmaja_api_hash_token_secret($secret),
            'username' => $username,
            'deviceName' => mb_substr(trim($deviceName), 0, 80),
            'createdAt' => carmaja_api_now(),
            'lastUsedAt' => null,
            'revokedAt' => null,
        ];
        carmaja_api_store_tokens($tokens);
    });

    carmaja_api_audit_best_effort('login_success', [
        'username' => $username,
        'deviceId' => $tokenId,
        'result' => 'success',
    ]);
    carmaja_api_audit_best_effort('device_token_created', [
        'username' => $username,
        'deviceId' => $tokenId,
        'result' => 'success',
    ]);

    return [
        'token' => $token,
        'tokenId' => $tokenId,
        'username' => $username,
        'publishTarget' => $target,
    ];
}

function carmaja_api_authorize_token(string $header): array
{
    if (!preg_match('/^Bearer\s+ct_([0-9a-f]{32})_([0-9a-f]{64})$/', $header, $matches)) {
        throw new CarmajaApiException(
            401,
            'Authentifizierung erforderlich.',
            [],
            'authentication_required'
        );
    }

    $tokenId = $matches[1];
    $secret = $matches[2];

    return carmaja_api_with_lock('device-tokens', function () use ($tokenId, $secret): array {
        $tokens = carmaja_api_load_tokens();
        $token = $tokens['tokens'][$tokenId] ?? null;

        $validSecret = is_array($token)
            && is_string($token['secretHash'] ?? null)
            && hash_equals($token['secretHash'], carmaja_api_hash_token_secret($secret));

        if (!$validSecret) {
            throw new CarmajaApiException(
                401,
                'Authentifizierung fehlgeschlagen.',
                [],
                'device_token_invalid'
            );
        }

        if (($token['revokedAt'] ?? null) !== null) {
            carmaja_api_audit_best_effort('revoked_device_rejected', [
                'username' => (string) ($token['username'] ?? ''),
                'deviceId' => $tokenId,
                'result' => 'rejected',
            ]);
            throw new CarmajaApiException(
                401,
                'Authentifizierung fehlgeschlagen.',
                [],
                'device_token_revoked'
            );
        }

        $token['lastUsedAt'] = carmaja_api_now();
        $tokens['tokens'][$tokenId] = $token;
        carmaja_api_store_tokens($tokens);

        return [
            'tokenId' => $tokenId,
            'username' => (string) ($token['username'] ?? 'admin'),
            'deviceName' => (string) ($token['deviceName'] ?? 'Android'),
        ];
    });
}

function carmaja_api_authorize(): array
{
    $header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';

    return carmaja_api_authorize_token(is_string($header) ? $header : '');
}

function carmaja_api_sanitize_audit_context(array $context): array
{
    $safe = [];

    foreach ($context as $key => $value) {
        $normalizedKey = strtolower((string) $key);
        $compactKey = preg_replace('/[^a-z0-9]/', '', $normalizedKey) ?? '';
        $blockedFragments = [
            'password',
            'secret',
            'hash',
            'ipaddress',
            'remoteaddr',
            'useragent',
            'referrer',
            'internalcalculation',
        ];
        $blocked = $compactKey === 'ip' || $compactKey === 'token';

        foreach ($blockedFragments as $fragment) {
            $blocked = $blocked || str_contains($compactKey, $fragment);
        }

        if ($blocked) {
            continue;
        }

        if (is_scalar($value) || $value === null) {
            $safe[(string) $key] = $value;
        }
    }

    return $safe;
}

function carmaja_api_audit(string $action, array $context = []): void
{
    $path = carmaja_api_path('audit/actions-' . gmdate('Y-m') . '.jsonl');
    carmaja_api_ensure_directory(dirname($path));
    $entry = [
        'at' => carmaja_api_now(),
        'environment' => carmaja_api_publish_target(),
        'action' => $action,
        'context' => carmaja_api_sanitize_audit_context($context),
    ];
    $line = json_encode(
        $entry,
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
    ) . PHP_EOL;

    carmaja_api_with_lock('audit-actions-' . gmdate('Y-m'), function () use ($path, $line): void {
        $handle = fopen($path, 'ab');

        if ($handle === false) {
            throw new CarmajaApiException(500, 'Auditlog konnte nicht geöffnet werden.');
        }

        try {
            $remaining = $line;

            while ($remaining !== '') {
                $written = fwrite($handle, $remaining);

                if ($written === false || $written === 0) {
                    throw new CarmajaApiException(500, 'Auditlog konnte nicht geschrieben werden.');
                }

                $remaining = substr($remaining, $written);
            }

            if (!fflush($handle)) {
                throw new CarmajaApiException(500, 'Auditlog konnte nicht synchronisiert werden.');
            }

            @chmod($path, 0640);
        } finally {
            fclose($handle);
        }
    });
}

function carmaja_api_audit_best_effort(string $action, array $context = []): void
{
    try {
        carmaja_api_audit($action, $context);
    } catch (Throwable) {
        // The public fallback response must never expose audit or filesystem details.
    }
}

function carmaja_api_slugify(string $value): string
{
    $normalized = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
    $slug = strtolower((string) $normalized);
    $slug = preg_replace('/[^a-z0-9]+/', '-', $slug) ?? '';
    $slug = trim($slug, '-');

    return $slug !== '' ? $slug : 'armband';
}

function carmaja_api_validate_vinted_url(
    mixed $value,
    bool $required
): ?string {
    if ($value === null || (is_string($value) && trim($value) === '')) {
        if ($required) {
            throw new CarmajaApiException(
                422,
                'Für Produktionsveröffentlichungen ist ein Vinted-Link erforderlich.',
                ['vintedUrl' => 'Vinted-Link ist erforderlich.'],
                'vinted_url_required'
            );
        }

        return null;
    }

    if (!is_string($value)) {
        throw new CarmajaApiException(
            422,
            'Vinted-Link ist ungültig.',
            ['vintedUrl' => 'Vinted-Link muss eine URL sein.'],
            'vinted_url_invalid'
        );
    }

    $url = trim($value);
    $parts = parse_url($url);
    $host = is_array($parts) && is_string($parts['host'] ?? null)
        ? strtolower($parts['host'])
        : null;
    $hasCredentials = is_array($parts)
        && (array_key_exists('user', $parts) || array_key_exists('pass', $parts));
    $hasPort = is_array($parts) && array_key_exists('port', $parts);
    $redirectKeys = ['url', 'redirect', 'redirect_url', 'redirect_uri', 'next', 'target'];
    $query = [];

    if (is_array($parts) && is_string($parts['query'] ?? null)) {
        parse_str($parts['query'], $query);
    }

    $hasRedirect = array_intersect(
        $redirectKeys,
        array_map(static fn (mixed $key): string => strtolower((string) $key), array_keys($query))
    ) !== [];

    if (filter_var($url, FILTER_VALIDATE_URL) === false
        || !is_array($parts)
        || ($parts['scheme'] ?? null) !== 'https'
        || !in_array($host, ['vinted.de', 'www.vinted.de'], true)
        || $hasCredentials
        || $hasPort
        || $hasRedirect) {
        throw new CarmajaApiException(
            422,
            'Vinted-Link ist ungültig.',
            ['vintedUrl' => 'Erlaubt ist nur eine direkte HTTPS-URL auf vinted.de.'],
            'vinted_url_invalid'
        );
    }

    return $url;
}

function carmaja_api_reject_unknown_fields(
    array $payload,
    array $allowedFields
): void {
    $unknown = array_values(array_diff(array_keys($payload), $allowedFields));

    if ($unknown !== []) {
        $fields = [];

        foreach ($unknown as $field) {
            $fields[(string) $field] = 'Unbekanntes Feld.';
        }

        throw new CarmajaApiException(
            422,
            'Anfrage enthält unbekannte Felder.',
            $fields,
            'unknown_fields'
        );
    }
}

function carmaja_api_validate_string(
    mixed $value,
    string $field,
    int $maxLength,
    bool $required = false
): string {
    if (!is_string($value)) {
        throw new CarmajaApiException(
            422,
            $field . ' ist ungültig.',
            [$field => 'Textwert erwartet.'],
            'validation_failed'
        );
    }

    $trimmed = trim($value);

    if (($required && $trimmed === '') || mb_strlen($trimmed) > $maxLength) {
        throw new CarmajaApiException(
            422,
            $field . ' ist ungültig.',
            [
                $field => $trimmed === ''
                    ? 'Pflichtfeld.'
                    : 'Maximal ' . $maxLength . ' Zeichen erlaubt.',
            ],
            'validation_failed'
        );
    }

    return $trimmed;
}

function carmaja_api_normalize_string_list(
    mixed $value,
    string $field,
    int $maxItems = 30,
    int $maxLength = 120
): array
{
    if (!is_array($value) || !array_is_list($value) || count($value) > $maxItems) {
        throw new CarmajaApiException(
            422,
            $field . ' ist ungültig.',
            [$field => 'Liste mit maximal ' . $maxItems . ' Einträgen erwartet.'],
            'validation_failed'
        );
    }

    $items = [];

    foreach ($value as $item) {
        if (!is_string($item)) {
            throw new CarmajaApiException(
                422,
                $field . ' ist ungültig.',
                [$field => 'Nur Textwerte sind erlaubt.'],
                'validation_failed'
            );
        }

        $trimmed = trim($item);

        if ($trimmed !== '') {
            if (mb_strlen($trimmed) > $maxLength) {
                throw new CarmajaApiException(
                    422,
                    $field . ' ist ungültig.',
                    [$field => 'Eintrag überschreitet ' . $maxLength . ' Zeichen.'],
                    'validation_failed'
                );
            }

            $items[] = $trimmed;
        }
    }

    return array_values(array_unique($items));
}

function carmaja_api_validate_internal_calculation(mixed $value): array
{
    if ($value === null) {
        return [];
    }

    if (!is_array($value) || ($value !== [] && array_is_list($value))) {
        throw new CarmajaApiException(
            422,
            'Interne Kalkulation ist ungültig.',
            ['internalCalculation' => 'JSON-Objekt erwartet.'],
            'validation_failed'
        );
    }

    $allowed = [
        'quantities',
        'workMinutes',
        'hourlyRate',
        'otherCosts',
        'markupPercent',
        'materialCosts',
        'laborCosts',
        'totalCosts',
        'recommendedSalePrice',
        'createdAtMillis',
    ];
    $unknown = array_diff(array_keys($value), $allowed);
    $encoded = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

    if ($unknown !== [] || strlen($encoded) > 65536) {
        throw new CarmajaApiException(
            422,
            'Interne Kalkulation ist ungültig.',
            ['internalCalculation' => 'Struktur oder Größe ist nicht erlaubt.'],
            'validation_failed'
        );
    }

    return $value;
}

function carmaja_api_validate_draft_payload(
    array $payload,
    string $draftId,
    ?array $existing
): array {
    carmaja_api_reject_unknown_fields($payload, [
        'draftId',
        'expectedVersion',
        'status',
        'name',
        'materials',
        'metalElements',
        'braceletSize',
        'stock',
        'shortDescription',
        'careInstructions',
        'vintedUrl',
        'internalCalculation',
    ]);

    if (isset($payload['draftId'])
        && (!is_string($payload['draftId']) || $payload['draftId'] !== $draftId)) {
        throw new CarmajaApiException(
            422,
            'draftId stimmt nicht mit dem API-Pfad überein.',
            ['draftId' => 'Abweichende draftId.'],
            'draft_id_mismatch'
        );
    }

    $status = is_string($payload['status'] ?? null) ? $payload['status'] : 'draft';

    if (!in_array($status, ['draft', 'ready'], true)) {
        throw new CarmajaApiException(
            422,
            'Statusübergang ist nicht erlaubt.',
            ['status' => 'Beim Speichern sind nur draft und ready erlaubt.'],
            'invalid_status_transition'
        );
    }

    $name = carmaja_api_validate_string(
        $payload['name'] ?? '',
        'name',
        120,
        $status === 'ready'
    );
    $materials = carmaja_api_normalize_string_list(
        $payload['materials'] ?? [],
        'materials'
    );

    if ($status === 'ready' && $materials === []) {
        throw new CarmajaApiException(
            422,
            'Materialien sind erforderlich.',
            ['materials' => 'Mindestens ein Material ist erforderlich.'],
            'validation_failed'
        );
    }

    $stock = $payload['stock'] ?? 1;

    if (!is_int($stock) || $stock < 0 || $stock > 99) {
        throw new CarmajaApiException(
            422,
            'Bestand ist ungültig.',
            ['stock' => 'Ganzzahl zwischen 0 und 99 erwartet.'],
            'validation_failed'
        );
    }

    $draft = $existing ?? [
        'environment' => carmaja_api_publish_target(),
        'draftId' => $draftId,
        'sku' => null,
        'slug' => null,
        'version' => 0,
        'createdAt' => carmaja_api_now(),
        'images' => [],
    ];

    $vintedUrl = carmaja_api_validate_vinted_url($payload['vintedUrl'] ?? null, false);

    $draft['draftId'] = $draftId;
    $draft['status'] = $status;
    $draft['name'] = $name;
    $draft['materials'] = $materials;
    $draft['metalElements'] = carmaja_api_normalize_string_list(
        $payload['metalElements'] ?? [],
        'metalElements'
    );
    $draft['braceletSize'] = carmaja_api_validate_string(
        $payload['braceletSize'] ?? '',
        'braceletSize',
        60,
        $status === 'ready'
    );
    $draft['stock'] = $stock;
    $draft['shortDescription'] = carmaja_api_validate_string(
        $payload['shortDescription'] ?? '',
        'shortDescription',
        500,
        $status === 'ready'
    );
    $draft['careInstructions'] = carmaja_api_normalize_string_list(
        $payload['careInstructions'] ?? [],
        'careInstructions'
    );
    $draft['vintedUrl'] = $vintedUrl;
    $draft['internalCalculation'] = carmaja_api_validate_internal_calculation(
        $payload['internalCalculation'] ?? []
    );
    $draft['updatedAt'] = carmaja_api_now();

    return $draft;
}

function carmaja_api_save_product(string $draftId, array $body, array $actor): array
{
    carmaja_api_validate_draft_id($draftId);
    $expectedVersion = $body['expectedVersion'] ?? null;

    if (!is_int($expectedVersion) || $expectedVersion < 0) {
        throw new CarmajaApiException(
            422,
            'expectedVersion ist erforderlich.',
            ['expectedVersion' => 'Nichtnegative Ganzzahl erwartet.'],
            'validation_failed'
        );
    }

    return carmaja_api_with_lock('draft-' . $draftId, function () use (
        $draftId,
        $body,
        $expectedVersion,
        $actor
    ): array {
        $existing = carmaja_api_load_draft($draftId);
        $currentVersion = is_array($existing) ? (int) ($existing['version'] ?? 0) : 0;

        if ($currentVersion !== $expectedVersion) {
            carmaja_api_audit_best_effort('version_conflict', [
                'draftId' => $draftId,
                'currentVersion' => $currentVersion,
                'result' => 'rejected',
            ]);
            throw new CarmajaApiException(
                409,
                'Der Entwurf wurde bereits geändert.',
                [
                    'currentVersion' => $currentVersion,
                    'updatedAt' => $existing['updatedAt'] ?? null,
                ],
                'version_conflict'
            );
        }

        $draft = carmaja_api_validate_draft_payload($body, $draftId, $existing);
        $draft['version'] = $currentVersion + 1;
        carmaja_api_save_draft($draft);
        carmaja_api_audit_best_effort('product_saved', [
            'draftId' => $draftId,
            'version' => $draft['version'],
            'deviceId' => $actor['tokenId'],
            'result' => 'success',
        ]);

        return $draft;
    });
}

function carmaja_api_require_publishable(array $draft, string $target): void
{
    foreach (['name', 'braceletSize', 'shortDescription'] as $field) {
        if (!is_string($draft[$field] ?? null) || trim($draft[$field]) === '') {
            throw new CarmajaApiException(
                422,
                'Produkt ist noch nicht veröffentlichbar.',
                [$field => 'Pflichtfeld.'],
                'product_not_publishable'
            );
        }
    }

    if (($draft['materials'] ?? []) === []) {
        throw new CarmajaApiException(
            422,
            'Produkt ist noch nicht veröffentlichbar.',
            ['materials' => 'Mindestens ein Material ist erforderlich.'],
            'product_not_publishable'
        );
    }

    $images = $draft['images'] ?? null;

    if (!is_array($images)
        || count($images) < 1
        || count($images) > CARMAJA_MAX_IMAGES
        || ($images[0]['isMain'] ?? false) !== true) {
        throw new CarmajaApiException(
            422,
            'Produkt ist noch nicht veröffentlichbar.',
            ['images' => 'Ein vollständig geprüftes Hauptbild ist erforderlich.'],
            'product_images_incomplete'
        );
    }

    foreach ($images as $image) {
        if (!is_array($image)
            || !is_string($image['path'] ?? null)
            || !is_file($image['path'])
            || !is_int($image['width'] ?? null)
            || !is_int($image['height'] ?? null)) {
            throw new CarmajaApiException(
                422,
                'Produktbilder sind unvollständig.',
                ['images' => 'Upload muss vollständig wiederholt werden.'],
                'product_images_incomplete'
            );
        }
    }

    carmaja_api_validate_vinted_url(
        $draft['vintedUrl'] ?? null,
        $target === 'production'
    );
}

function carmaja_api_allocate_sku(string $operationId): string
{
    return carmaja_api_with_lock('sku-counter', function () use ($operationId): string {
        $year = gmdate('Y');
        $path = carmaja_api_path('sku-counter/counter.json');
        $counter = carmaja_api_read_target_json(
            $path,
            ['years' => [], 'reservations' => []],
            'SKU-Zähler'
        );
        $reservationKey = hash('sha256', $operationId);
        $reservations = is_array($counter['reservations'] ?? null)
            ? $counter['reservations']
            : [];

        if (is_string($reservations[$reservationKey] ?? null)) {
            return $reservations[$reservationKey];
        }

        $years = is_array($counter['years'] ?? null) ? $counter['years'] : [];
        $next = ((int) ($years[$year] ?? 0)) + 1;
        $years[$year] = $next;
        $counter['years'] = $years;
        $sku = sprintf('CP-%s-%04d', $year, $next);
        $counter['reservations'][$reservationKey] = $sku;
        carmaja_api_write_json_atomic($path, carmaja_api_target_document($counter));

        return $sku;
    });
}

function carmaja_api_public_product_from_draft(array $draft): array
{
    $sku = (string) $draft['sku'];
    $slug = (string) $draft['slug'];

    if (preg_match('/^CP-\d{4}-\d{4}$/', $sku) !== 1
        || preg_match(CARMAJA_SLUG_PATTERN, $slug) !== 1) {
        throw new CarmajaApiException(
            500,
            'Öffentliche Produktkennung ist ungültig.',
            [],
            'public_product_identifier_invalid'
        );
    }

    $publicImages = [];
    $index = 1;

    foreach (($draft['images'] ?? []) as $image) {
        if (!is_array($image) || !is_file((string) ($image['path'] ?? ''))) {
            continue;
        }

        $fileName = sprintf('%02d.jpg', $index);
        $publicImages[] = [
            'src' => '/images/products/' . $sku . '/' . $fileName,
            'alt' => (string) ($image['alt'] ?? $draft['name']),
            'width' => (int) ($image['width'] ?? 1600),
            'height' => (int) ($image['height'] ?? 1200),
            'isMain' => $index === 1,
            '_sourcePath' => (string) $image['path'],
            '_repoPath' => 'website/public/images/products/' . $sku . '/' . $fileName,
        ];
        $index++;
    }

    $publicProduct = [
        'sku' => $sku,
        'slug' => $slug,
        'status' => (string) $draft['status'],
        'title' => (string) $draft['name'],
        'description' => (string) $draft['shortDescription'],
        'materials' => array_values($draft['materials'] ?? []),
        'metalElements' => array_values($draft['metalElements'] ?? []),
        'size' => (string) $draft['braceletSize'],
        'stock' => (int) ($draft['stock'] ?? 1),
        'careInstructions' => array_values($draft['careInstructions'] ?? []),
        'images' => array_map(
            static fn (array $image): array => array_diff_key($image, [
                '_sourcePath' => true,
                '_repoPath' => true,
            ]),
            $publicImages
        ),
        'updatedAt' => (string) $draft['updatedAt'],
        '_imageBlobs' => $publicImages,
    ];

    $vintedUrl = carmaja_api_validate_vinted_url($draft['vintedUrl'] ?? null, false);

    if ($vintedUrl !== null) {
        $publicProduct['vintedUrl'] = $vintedUrl;
    }

    return $publicProduct;
}

function carmaja_api_idempotency_path(string $operationId): string
{
    carmaja_api_validate_operation_id($operationId);
    return carmaja_api_path('idempotency/' . hash('sha256', $operationId) . '.json');
}

function carmaja_api_canonicalize(mixed $value): mixed
{
    if (!is_array($value)) {
        return $value;
    }

    if (array_is_list($value)) {
        return array_map('carmaja_api_canonicalize', $value);
    }

    ksort($value);

    foreach ($value as $key => $item) {
        $value[$key] = carmaja_api_canonicalize($item);
    }

    return $value;
}

function carmaja_api_request_hash(array $body): string
{
    return hash(
        'sha256',
        json_encode(
            carmaja_api_canonicalize($body),
            JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        )
    );
}

function carmaja_api_production_publish_enabled(): bool
{
    $value = getenv('CARMAJA_PRODUCTION_PUBLISH_ENABLED');

    return is_string($value) && strtolower(trim($value)) === 'true';
}

function carmaja_api_operation_error(array $operation): CarmajaApiException
{
    $error = is_array($operation['error'] ?? null) ? $operation['error'] : [];

    return new CarmajaApiException(
        is_int($error['httpStatus'] ?? null) ? $error['httpStatus'] : 409,
        is_string($error['message'] ?? null)
            ? $error['message']
            : 'Operation ist endgültig fehlgeschlagen.',
        is_array($error['fields'] ?? null) ? $error['fields'] : [],
        is_string($error['code'] ?? null) ? $error['code'] : 'operation_failed_final'
    );
}

function carmaja_api_operation_is_stale(array $operation): bool
{
    $expiresAt = strtotime((string) ($operation['expiresAt'] ?? ''));

    return !is_int($expiresAt) || $expiresAt <= time();
}

function carmaja_api_validate_status_transition(
    array $draft,
    string $newStatus,
    string $target
): void {
    $currentStatus = (string) ($draft['status'] ?? 'draft');

    if ($newStatus === 'published') {
        $allowedCurrentStatuses = $target === 'test'
            ? ['draft', 'ready', 'published']
            : ['ready', 'published'];

        if (!in_array($currentStatus, $allowedCurrentStatuses, true)) {
            throw new CarmajaApiException(
                422,
                'Statusübergang zu published ist nicht erlaubt.',
                [
                    'status' => $target === 'test'
                        ? 'Testprodukt muss draft, ready oder bereits published sein.'
                        : 'Produkt muss ready oder bereits published sein.',
                ],
                'invalid_status_transition'
            );
        }

        carmaja_api_require_publishable($draft, $target);
        return;
    }

    if ($newStatus === 'sold') {
        if ($currentStatus !== 'published' || !is_string($draft['sku'] ?? null)) {
            throw new CarmajaApiException(
                422,
                'Nur veröffentlichte Produkte können als verkauft markiert werden.',
                ['status' => 'Produkt muss published sein.'],
                'invalid_status_transition'
            );
        }

        return;
    }

    if ($newStatus === 'disabled') {
        if (!in_array($currentStatus, ['published', 'sold'], true)
            || !is_string($draft['sku'] ?? null)) {
            throw new CarmajaApiException(
                422,
                'Nur veröffentlichte Produkte können deaktiviert werden.',
                ['status' => 'Produkt muss published oder sold sein.'],
                'invalid_status_transition'
            );
        }

        return;
    }

    throw new CarmajaApiException(
        422,
        'Unbekannter Zielstatus.',
        ['status' => 'Nicht erlaubter Status.'],
        'invalid_status_transition'
    );
}

function carmaja_api_validate_publish_draft(
    string $draftId,
    int $expectedVersion,
    string $operationId,
    string $newStatus,
    string $target
): array {
    $draft = carmaja_api_load_draft($draftId);

    if (!is_array($draft)) {
        throw new CarmajaApiException(
            404,
            'Entwurf wurde nicht gefunden.',
            [],
            'product_not_found'
        );
    }

    $currentVersion = (int) ($draft['version'] ?? 0);
    $alreadyApplied = $currentVersion === $expectedVersion + 1
        && ($draft['lastOperationId'] ?? null) === $operationId
        && ($draft['status'] ?? null) === $newStatus;

    if ($currentVersion !== $expectedVersion && !$alreadyApplied) {
        throw new CarmajaApiException(
            409,
            'Der Entwurf wurde bereits geändert.',
            [
                'currentVersion' => $currentVersion,
                'updatedAt' => $draft['updatedAt'] ?? null,
            ],
            'version_conflict'
        );
    }

    if (!$alreadyApplied) {
        carmaja_api_validate_status_transition($draft, $newStatus, $target);
    }

    return $draft;
}

function carmaja_api_local_publish_adapter(
    array $publicProduct,
    array $operation
): array {
    $operationId = (string) $operation['operationId'];
    $adapterPath = carmaja_api_path(
        'products/operations/' . hash('sha256', $operationId) . '.json'
    );

    return carmaja_api_with_lock(
        'publish-adapter-' . hash('sha256', $operationId),
        function () use ($adapterPath, $publicProduct, $operation): array {
            if (is_file($adapterPath)) {
                $stored = carmaja_api_read_target_json(
                    $adapterPath,
                    [],
                    'Publish-Adapterstatus'
                );

                if (($stored['requestHash'] ?? null) !== ($operation['requestHash'] ?? null)) {
                    throw new CarmajaApiException(
                        409,
                        'Publish-Adapter wurde mit anderem Inhalt verwendet.',
                        [],
                        'publish_adapter_conflict'
                    );
                }

                return is_array($stored['result'] ?? null)
                    ? $stored['result']
                    : [
                        'commitSha' => null,
                        'deploymentStatus' => 'not_started',
                    ];
            }

            $publicPath = carmaja_api_path('products/public-products.json');
            $publicData = carmaja_api_read_target_json(
                $publicPath,
                ['version' => 1, 'products' => []],
                'Öffentliche Testproduktdaten'
            );
            $products = is_array($publicData['products'] ?? null)
                ? $publicData['products']
                : [];
            $cleanProduct = array_diff_key($publicProduct, ['_imageBlobs' => true]);
            $replaced = false;

            foreach ($products as $index => $product) {
                if (is_array($product)
                    && ($product['sku'] ?? null) === $cleanProduct['sku']) {
                    if (($cleanProduct['status'] ?? null) === 'disabled') {
                        unset($products[$index]);
                    } else {
                        $products[$index] = $cleanProduct;
                    }
                    $replaced = true;
                    break;
                }
            }

            if (!$replaced && ($cleanProduct['status'] ?? null) !== 'disabled') {
                $products[] = $cleanProduct;
            }

            carmaja_api_write_json_atomic(
                $publicPath,
                carmaja_api_target_document([
                    'version' => 1,
                    'products' => array_values($products),
                ])
            );

            $result = [
                'commitSha' => null,
                'deploymentStatus' => 'not_started',
            ];
            carmaja_api_write_json_atomic(
                $adapterPath,
                carmaja_api_target_document([
                    'operationId' => $operation['operationId'],
                    'requestHash' => $operation['requestHash'],
                    'createdAt' => carmaja_api_now(),
                    'result' => $result,
                ])
            );

            return $result;
        }
    );
}

function carmaja_api_run_publish_adapter(
    array $publicProduct,
    array $operation
): array {
    $adapter = $GLOBALS['CARMAJA_API_PUBLISH_ADAPTER'] ?? null;
    $result = is_callable($adapter)
        ? $adapter($publicProduct, $operation)
        : carmaja_api_local_publish_adapter($publicProduct, $operation);

    if (!is_array($result)
        || !array_key_exists('commitSha', $result)
        || !is_string($result['deploymentStatus'] ?? null)) {
        throw new CarmajaApiException(
            500,
            'Publish-Adapter hat ein ungültiges Ergebnis geliefert.',
            [],
            'publish_adapter_invalid'
        );
    }

    return [
        'commitSha' => is_string($result['commitSha']) && $result['commitSha'] !== ''
            ? $result['commitSha']
            : null,
        'deploymentStatus' => $result['deploymentStatus'],
    ];
}

function carmaja_api_store_operation(string $path, array $operation): void
{
    $operation['updatedAt'] = carmaja_api_now();
    carmaja_api_write_json_atomic(
        $path,
        carmaja_api_target_document($operation)
    );
}

function carmaja_api_publish(string $draftId, array $body, array $actor, string $newStatus): array
{
    carmaja_api_private_dir();
    carmaja_api_validate_draft_id($draftId);
    carmaja_api_reject_unknown_fields($body, ['expectedVersion', 'operationId']);
    $expectedVersion = $body['expectedVersion'] ?? null;
    $operationId = $body['operationId'] ?? null;

    if (!is_int($expectedVersion)
        || $expectedVersion < 0
        || !is_string($operationId)) {
        throw new CarmajaApiException(
            422,
            'expectedVersion und operationId sind erforderlich.',
            [
                'expectedVersion' => 'Nichtnegative Ganzzahl erwartet.',
                'operationId' => 'Stabile operationId erwartet.',
            ],
            'validation_failed'
        );
    }

    carmaja_api_validate_operation_id($operationId);
    $target = carmaja_api_publish_target();

    if ($target === 'production' && !carmaja_api_production_publish_enabled()) {
        carmaja_api_audit_best_effort('production_publish_blocked', [
            'draftId' => $draftId,
            'operationId' => $operationId,
            'result' => 'blocked',
        ]);
        throw new CarmajaApiException(
            403,
            'Produktionsveröffentlichung ist deaktiviert.',
            [],
            'production_publish_disabled'
        );
    }

    $requestHash = carmaja_api_request_hash([
        'draftId' => $draftId,
        'expectedVersion' => $expectedVersion,
        'operationId' => $operationId,
        'status' => $newStatus,
        'publishTarget' => $target,
    ]);

    return carmaja_api_with_lock('operation-' . hash('sha256', $operationId), function () use (
        $draftId,
        $expectedVersion,
        $operationId,
        $requestHash,
        $actor,
        $newStatus,
        $target
    ): array {
        $idempotencyPath = carmaja_api_idempotency_path($operationId);
        $existingOperation = is_file($idempotencyPath)
            ? carmaja_api_read_target_json($idempotencyPath, [], 'Idempotency-Datensatz')
            : [];

        if ($existingOperation !== []) {
            if (($existingOperation['requestHash'] ?? null) !== $requestHash) {
                throw new CarmajaApiException(
                    409,
                    'operationId wurde mit anderem Inhalt verwendet.',
                    [],
                    'idempotency_key_reused'
                );
            }

            if (($existingOperation['status'] ?? null) === 'succeeded') {
                return $existingOperation['savedResponse'];
            }

            if (($existingOperation['status'] ?? null) === 'failed_final') {
                throw carmaja_api_operation_error($existingOperation);
            }

            if (($existingOperation['status'] ?? null) === 'in_progress'
                && !carmaja_api_operation_is_stale($existingOperation)) {
                throw new CarmajaApiException(
                    409,
                    'Operation wird bereits verarbeitet.',
                    [
                        'phase' => $existingOperation['phase'] ?? 'unknown',
                        'updatedAt' => $existingOperation['updatedAt'] ?? null,
                    ],
                    'operation_in_progress'
                );
            }
        }

        $preflightDraft = carmaja_api_with_lock('draft-' . $draftId, function () use (
            $draftId,
            $expectedVersion,
            $operationId,
            $newStatus,
            $target
        ): array {
            return carmaja_api_validate_publish_draft(
                $draftId,
                $expectedVersion,
                $operationId,
                $newStatus,
                $target
            );
        });

        $operation = $existingOperation !== [] ? $existingOperation : [
            'operationId' => $operationId,
            'requestHash' => $requestHash,
            'publishTarget' => $target,
            'productId' => $draftId,
            'phase' => 'validated',
            'status' => 'in_progress',
            'createdAt' => carmaja_api_now(),
            'updatedAt' => carmaja_api_now(),
            'expiresAt' => carmaja_api_timestamp(CARMAJA_OPERATION_LEASE_SECONDS),
            'reservedSku' => is_string($preflightDraft['sku'] ?? null)
                ? $preflightDraft['sku']
                : null,
            'commitSha' => null,
            'savedResponse' => null,
        ];
        $operation['status'] = 'in_progress';
        $operation['expiresAt'] = carmaja_api_timestamp(CARMAJA_OPERATION_LEASE_SECONDS);
        carmaja_api_store_operation($idempotencyPath, $operation);

        try {
            carmaja_api_audit_best_effort(
                $newStatus === 'published'
                    ? ($target === 'test'
                        ? 'test_publish_started'
                        : 'production_publish_started')
                    : 'product_status_change_started',
                [
                    'draftId' => $draftId,
                    'operationId' => $operationId,
                    'status' => $newStatus,
                    'deviceId' => $actor['tokenId'],
                    'result' => 'started',
                ]
            );
            $draft = carmaja_api_with_lock('draft-' . $draftId, function () use (
                $draftId,
                $expectedVersion,
                $operationId,
                $newStatus,
                $target,
                &$operation,
                $idempotencyPath
            ): array {
                $draft = carmaja_api_validate_publish_draft(
                    $draftId,
                    $expectedVersion,
                    $operationId,
                    $newStatus,
                    $target
                );

                if (($draft['lastOperationId'] ?? null) === $operationId
                    && (int) ($draft['version'] ?? 0) === $expectedVersion + 1) {
                    return $draft;
                }

                if ($newStatus === 'published') {
                    if (!is_string($draft['sku'] ?? null) || $draft['sku'] === '') {
                        $operation['reservedSku'] = is_string($operation['reservedSku'] ?? null)
                            ? $operation['reservedSku']
                            : carmaja_api_allocate_sku($operationId);
                        $operation['phase'] = 'sku_reserved';
                        carmaja_api_store_operation($idempotencyPath, $operation);
                        $draft['sku'] = $operation['reservedSku'];
                    }

                    if (!is_string($draft['slug'] ?? null) || $draft['slug'] === '') {
                        $draft['slug'] = strtolower((string) $draft['sku'])
                            . '-' . carmaja_api_slugify((string) $draft['name']);
                    }

                    $draft['publishedAt'] = $draft['publishedAt'] ?? carmaja_api_now();
                } elseif ($newStatus === 'sold') {
                    $draft['soldAt'] = carmaja_api_now();
                }

                $draft['status'] = $newStatus;
                $draft['version'] = $expectedVersion + 1;
                $draft['updatedAt'] = carmaja_api_now();
                $draft['lastOperationId'] = $operationId;

                return carmaja_api_save_draft($draft);
            });

            $operation['phase'] = 'draft_saved';
            $operation['reservedSku'] = $draft['sku'] ?? $operation['reservedSku'];
            carmaja_api_store_operation($idempotencyPath, $operation);

            $publicProduct = carmaja_api_public_product_from_draft($draft);
            $adapterResult = is_array($operation['adapterResult'] ?? null)
                ? $operation['adapterResult']
                : null;

            if ($adapterResult === null) {
                $operation['phase'] = 'side_effect_started';
                carmaja_api_store_operation($idempotencyPath, $operation);
                $adapterResult = carmaja_api_run_publish_adapter($publicProduct, $operation);
                $operation['adapterResult'] = $adapterResult;
                $operation['phase'] = 'side_effect_succeeded';
                $operation['commitSha'] = $adapterResult['commitSha'];
                carmaja_api_store_operation($idempotencyPath, $operation);
            }

            $response = [
                'draftId' => $draftId,
                'sku' => $draft['sku'],
                'slug' => $draft['slug'],
                'version' => $draft['version'],
                'operationId' => $operationId,
                'publishTarget' => $target,
                'commitSha' => $adapterResult['commitSha'],
                'deploymentStatus' => $adapterResult['deploymentStatus'],
                'status' => $newStatus,
            ];

            carmaja_api_audit(
                $newStatus === 'published'
                    ? ($target === 'test'
                        ? 'test_publish_succeeded'
                        : 'production_publish_succeeded')
                    : 'product_status_changed',
                [
                    'draftId' => $draftId,
                    'sku' => $draft['sku'],
                    'operationId' => $operationId,
                    'deviceId' => $actor['tokenId'],
                    'status' => $newStatus,
                    'result' => 'success',
                ]
            );

            $operation['phase'] = 'completed';
            $operation['status'] = 'succeeded';
            $operation['expiresAt'] = carmaja_api_timestamp(
                CARMAJA_OPERATION_RETENTION_SECONDS
            );
            $operation['savedResponse'] = $response;
            unset($operation['error']);
            carmaja_api_store_operation($idempotencyPath, $operation);

            return $response;
        } catch (Throwable $error) {
            $apiError = $error instanceof CarmajaApiException
                ? $error
                : new CarmajaApiException(
                    500,
                    'Veröffentlichung ist vorübergehend fehlgeschlagen.',
                    [],
                    'publish_failed'
                );
            $retryable = $apiError->statusCode >= 500;
            $operation['status'] = $retryable ? 'failed_retryable' : 'failed_final';
            $operation['phase'] = 'failed';
            $operation['expiresAt'] = carmaja_api_timestamp(
                $retryable
                    ? CARMAJA_OPERATION_LEASE_SECONDS
                    : CARMAJA_OPERATION_RETENTION_SECONDS
            );
            $operation['error'] = [
                'httpStatus' => $apiError->statusCode,
                'code' => $apiError->errorCode,
                'message' => $apiError->getMessage(),
                'fields' => $apiError->fields,
            ];
            carmaja_api_store_operation($idempotencyPath, $operation);
            carmaja_api_audit_best_effort(
                $target === 'test' ? 'test_publish_failed' : 'production_publish_failed',
                [
                'draftId' => $draftId,
                'operationId' => $operationId,
                'result' => $operation['status'],
                ]
            );

            throw $apiError;
        }
    });
}

function carmaja_api_operation_status(string $operationId): array
{
    $path = carmaja_api_idempotency_path($operationId);

    if (!is_file($path)) {
        throw new CarmajaApiException(
            404,
            'Operation wurde nicht gefunden.',
            [],
            'operation_not_found'
        );
    }

    $operation = carmaja_api_read_target_json($path, [], 'Idempotency-Datensatz');

    $storedResponse = is_array($operation['savedResponse'] ?? null)
        ? $operation['savedResponse']
        : [];
    $deployment = [
        'deploymentStatus' => $storedResponse['deploymentStatus'] ?? null,
        'workflowRunId' => null,
        'workflowRunUrl' => null,
        'workflowConclusion' => null,
        'deploymentError' => null,
    ];
    $commitSha = is_string($operation['commitSha'] ?? null)
        ? $operation['commitSha']
        : null;

    if (($operation['status'] ?? null) === 'succeeded'
        && ($operation['publishTarget'] ?? null) === 'test'
        && $commitSha !== null
        && carmaja_api_github_adapter_enabled()) {
        try {
            $deployment = array_merge(
                $deployment,
                carmaja_api_github_deployment_status($commitSha)
            );
        } catch (CarmajaApiException $error) {
            $deployment['deploymentStatus'] = 'status_unavailable';
            $deployment['deploymentError'] = $error->errorCode;
        }
    }

    return [
        'operationId' => $operation['operationId'] ?? $operationId,
        'productId' => $operation['productId'] ?? null,
        'publishTarget' => $operation['publishTarget'] ?? null,
        'phase' => $operation['phase'] ?? null,
        'status' => $operation['status'] ?? null,
        'updatedAt' => $operation['updatedAt'] ?? null,
        'expiresAt' => $operation['expiresAt'] ?? null,
        'commitSha' => $commitSha,
        'deploymentStatus' => $deployment['deploymentStatus'],
        'workflowRunId' => $deployment['workflowRunId'],
        'workflowRunUrl' => $deployment['workflowRunUrl'],
        'workflowConclusion' => $deployment['workflowConclusion'],
        'deploymentError' => $deployment['deploymentError'],
        'response' => $operation['status'] === 'succeeded'
            ? ($operation['savedResponse'] ?? null)
            : null,
        'error' => in_array(
            $operation['status'] ?? null,
            ['failed_retryable', 'failed_final'],
            true
        ) ? ($operation['error'] ?? null) : null,
    ];
}

function carmaja_api_github_adapter_enabled(): bool
{
    return getenv('CARMAJA_GITHUB_ADAPTER_ENABLED') === 'true';
}

function carmaja_api_require_github_test_configuration(
    bool $requireEnabled = true
): void
{
    if (($requireEnabled && !carmaja_api_github_adapter_enabled())
        || carmaja_api_publish_target() !== 'test'
        || carmaja_api_production_publish_enabled()) {
        throw new CarmajaApiException(
            503,
            'GitHub-Testadapter ist deaktiviert.',
            [],
            'github_adapter_disabled'
        );
    }
}

function carmaja_api_require_github_adapter_enabled(): void
{
    carmaja_api_require_github_test_configuration(true);
}

function carmaja_api_github_token(bool $requireEnabled = true): string
{
    carmaja_api_require_github_test_configuration($requireEnabled);

    if (!$requireEnabled) {
        $readonlyToken = $GLOBALS['CARMAJA_API_GITHUB_READONLY_TOKEN'] ?? null;

        if (is_string($readonlyToken)) {
            return carmaja_api_validate_github_token($readonlyToken);
        }
    }

    $tokenFile = getenv('CARMAJA_GITHUB_TOKEN_FILE');

    if (!is_string($tokenFile) || trim($tokenFile) === '') {
        throw new CarmajaApiException(503, 'CARMAJA_GITHUB_TOKEN_FILE ist nicht konfiguriert.');
    }

    $realPath = realpath(trim($tokenFile));

    if ($realPath === false
        || !is_file($realPath)
        || !carmaja_api_path_is_inside($realPath, carmaja_api_private_dir())) {
        throw new CarmajaApiException(503, 'GitHub-Token-Datei ist nicht erreichbar.');
    }

    $token = trim((string) file_get_contents($realPath));

    if ($token === '') {
        throw new CarmajaApiException(503, 'GitHub-Token ist leer.');
    }

    return $token;
}

function carmaja_api_validate_github_token(string $token): string
{
    if ($token === ''
        || strlen($token) > 512
        || preg_match('/\s/', $token) === 1
        || !str_starts_with($token, 'github_pat_')
        || substr_count($token, 'github_pat_') !== 1
        || preg_match('/^github_pat_[A-Za-z0-9_]+$/D', $token) !== 1) {
        throw new CarmajaApiException(
            400,
            'GitHub-Token ist ungÃ¼ltig.',
            [],
            'github_token_invalid'
        );
    }

    return $token;
}

function carmaja_api_github_repository(bool $requireEnabled = true): string
{
    carmaja_api_require_github_test_configuration($requireEnabled);
    $repository = getenv('CARMAJA_GITHUB_REPOSITORY');
    $repository = is_string($repository) ? trim($repository) : '';

    if ($repository !== CARMAJA_TEST_REPOSITORY) {
        throw new CarmajaApiException(503, 'CARMAJA_GITHUB_REPOSITORY ist nicht korrekt konfiguriert.');
    }

    return $repository;
}

function carmaja_api_github_branch(bool $requireEnabled = true): string
{
    carmaja_api_require_github_test_configuration($requireEnabled);
    $branch = getenv('CARMAJA_GITHUB_BRANCH');
    $branch = is_string($branch) ? trim($branch) : '';

    if ($branch !== CARMAJA_TEST_BRANCH) {
        throw new CarmajaApiException(
            503,
            'GitHub-Zielbranch ist für den Testadapter nicht erlaubt.',
            [],
            'github_branch_mismatch'
        );
    }

    return $branch;
}

function carmaja_api_github_request(
    string $method,
    string $path,
    ?array $body = null,
    bool $requireEnabled = true
): array
{
    carmaja_api_require_github_test_configuration($requireEnabled);
    $mock = $GLOBALS['CARMAJA_API_GITHUB_REQUEST_ADAPTER'] ?? null;

    if (is_callable($mock)) {
        $result = $mock($method, $path, $body);

        if (!is_array($result)) {
            throw new CarmajaApiException(
                500,
                'GitHub-Testadapter hat ein ungültiges Mock-Ergebnis geliefert.',
                [],
                'github_mock_invalid'
            );
        }

        return $result;
    }

    $url = 'https://api.github.com' . $path;
    $headers = [
        'Accept: application/vnd.github+json',
        'Authorization: Bearer ' . carmaja_api_github_token($requireEnabled),
        'User-Agent: Carmaja-Perlen-Product-API',
        'X-GitHub-Api-Version: 2022-11-28',
    ];

    $payload = null;

    if ($body !== null) {
        $payload = json_encode($body, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $headers[] = 'Content-Type: application/json';
    }

    $context = stream_context_create([
        'http' => [
            'method' => $method,
            'header' => implode("\r\n", $headers),
            'content' => $payload ?? '',
            'ignore_errors' => true,
            'timeout' => 20,
        ],
    ]);
    $response = file_get_contents($url, false, $context);
    $statusLine = $http_response_header[0] ?? 'HTTP/1.1 500';
    preg_match('/\s(\d{3})\s/', $statusLine, $matches);
    $statusCode = (int) ($matches[1] ?? 500);
    $decoded = is_string($response) && $response !== ''
        ? json_decode($response, true)
        : [];

    if ($statusCode < 200 || $statusCode >= 300 || !is_array($decoded)) {
        throw new CarmajaApiException(502, 'GitHub-Anfrage ist fehlgeschlagen.', [
            'statusCode' => $statusCode,
            'path' => $path,
        ]);
    }

    return $decoded;
}

function carmaja_api_github_readonly_diagnostic(): array
{
    $repository = carmaja_api_github_repository(false);
    $branch = carmaja_api_github_branch(false);
    $prefix = '/repos/' . $repository;
    $ref = carmaja_api_github_request(
        'GET',
        $prefix . '/git/ref/heads/' . rawurlencode($branch),
        null,
        false
    );
    $headSha = (string) ($ref['object']['sha'] ?? '');

    if (preg_match('/^[0-9a-f]{40}$/', $headSha) !== 1) {
        throw new CarmajaApiException(
            502,
            'GitHub-Remote-HEAD ist ungültig.',
            [],
            'github_head_invalid'
        );
    }

    $content = carmaja_api_github_request(
        'GET',
        $prefix . '/contents/website/content/products.json?ref='
            . rawurlencode($branch),
        null,
        false
    );

    if (!is_string($content['content'] ?? null)) {
        throw new CarmajaApiException(
            502,
            'GitHub-Produktdatei ist nicht lesbar.',
            [],
            'github_products_unreadable'
        );
    }

    return [
        'repository' => $repository,
        'branch' => $branch,
        'headSha' => $headSha,
        'productsReadable' => true,
        'writePerformed' => false,
    ];
}

function carmaja_api_github_deployment_status(string $commitSha): array
{
    if (preg_match('/^[0-9a-f]{40}$/', $commitSha) !== 1) {
        throw new CarmajaApiException(
            500,
            'Commit-SHA für Deploymentstatus ist ungültig.',
            [],
            'github_commit_sha_invalid'
        );
    }

    $repository = carmaja_api_github_repository();
    $branch = carmaja_api_github_branch();
    $path = '/repos/' . $repository
        . '/actions/workflows/' . rawurlencode(CARMAJA_TEST_DEPLOY_WORKFLOW)
        . '/runs?branch=' . rawurlencode($branch)
        . '&event=push&head_sha=' . rawurlencode($commitSha)
        . '&per_page=10';
    $response = carmaja_api_github_request('GET', $path);
    $runs = is_array($response['workflow_runs'] ?? null)
        ? $response['workflow_runs']
        : [];
    $matchingRuns = array_values(array_filter(
        $runs,
        static fn (mixed $run): bool =>
            is_array($run)
            && ($run['head_sha'] ?? null) === $commitSha
            && ($run['head_branch'] ?? null) === CARMAJA_TEST_BRANCH
            && ($run['event'] ?? null) === 'push'
    ));

    usort(
        $matchingRuns,
        static fn (array $left, array $right): int =>
            (int) ($right['id'] ?? 0) <=> (int) ($left['id'] ?? 0)
    );
    $run = $matchingRuns[0] ?? null;

    if (!is_array($run)) {
        return [
            'deploymentStatus' => 'queued',
            'workflowRunId' => null,
            'workflowRunUrl' => null,
            'workflowConclusion' => null,
        ];
    }

    $status = (string) ($run['status'] ?? '');
    $conclusion = is_string($run['conclusion'] ?? null)
        ? $run['conclusion']
        : null;
    $deploymentStatus = match ($status) {
        'queued', 'requested', 'waiting', 'pending' => 'queued',
        'in_progress' => 'in_progress',
        'completed' => $conclusion === 'success' ? 'succeeded' : 'failed',
        default => 'status_unknown',
    };
    $runUrl = is_string($run['html_url'] ?? null) ? $run['html_url'] : null;

    if ($runUrl !== null
        && preg_match(
            '#^https://github\.com/Bumpers210/armband-rechner/actions/runs/\d+$#',
            $runUrl
        ) !== 1) {
        $runUrl = null;
    }

    return [
        'deploymentStatus' => $deploymentStatus,
        'workflowRunId' => is_int($run['id'] ?? null) ? $run['id'] : null,
        'workflowRunUrl' => $runUrl,
        'workflowConclusion' => $conclusion,
    ];
}

function carmaja_api_assert_repo_path_allowed(string $path): void
{
    $normalized = str_replace('\\', '/', $path);
    $isAllowed = $normalized === 'website/content/products.json'
        || preg_match('/^website\/public\/images\/products\/CP-\d{4}-\d{4}\/0[1-5]\.jpg$/', $normalized) === 1;

    if (!$isAllowed
        || str_contains($normalized, '..')
        || str_starts_with($normalized, '.github/')
        || str_contains($normalized, '/.github/')) {
        throw new CarmajaApiException(500, 'GitHub-Pfad ist nicht erlaubt.');
    }
}

function carmaja_api_github_publish_adapter(
    array $publicProduct,
    array $operation
): array {
    carmaja_api_require_github_adapter_enabled();
    $operationId = (string) ($operation['operationId'] ?? '');
    carmaja_api_validate_operation_id($operationId);
    $adapterPath = carmaja_api_path(
        'products/operations/github-' . hash('sha256', $operationId) . '.json'
    );

    return carmaja_api_with_lock(
        'github-publish-' . hash('sha256', $operationId),
        function () use ($adapterPath, $publicProduct, $operation): array {
            $stored = [];

            if (is_file($adapterPath)) {
                $stored = carmaja_api_read_target_json(
                    $adapterPath,
                    [],
                    'GitHub-Publishstatus'
                );

                if (($stored['requestHash'] ?? null)
                    !== ($operation['requestHash'] ?? null)) {
                    throw new CarmajaApiException(
                        409,
                        'GitHub-Publish wurde mit anderem Inhalt wiederholt.',
                        [],
                        'publish_adapter_conflict'
                    );
                }

                if (is_array($stored['result'] ?? null)
                    && is_string($stored['result']['commitSha'] ?? null)) {
                    return $stored['result'];
                }
            }

            $persistPrepared = static function (
                string $baseHeadSha,
                string $preparedCommitSha
            ) use ($adapterPath, $operation, &$stored): void {
                $stored = carmaja_api_target_document([
                    'operationId' => $operation['operationId'],
                    'requestHash' => $operation['requestHash'],
                    'createdAt' => $stored['createdAt'] ?? carmaja_api_now(),
                    'phase' => 'commit_prepared',
                    'baseHeadSha' => $baseHeadSha,
                    'preparedCommitSha' => $preparedCommitSha,
                    'result' => null,
                ]);
                carmaja_api_write_json_atomic($adapterPath, $stored);
            };
            $commitSha = carmaja_api_commit_public_product(
                $publicProduct,
                $operation,
                $stored,
                $persistPrepared
            );
            $result = [
                'commitSha' => $commitSha,
                'deploymentStatus' => 'queued',
            ];
            carmaja_api_write_json_atomic(
                $adapterPath,
                carmaja_api_target_document([
                    'operationId' => $operation['operationId'],
                    'requestHash' => $operation['requestHash'],
                    'createdAt' => $stored['createdAt'] ?? carmaja_api_now(),
                    'phase' => 'completed',
                    'baseHeadSha' => $stored['baseHeadSha'] ?? null,
                    'preparedCommitSha' => $commitSha,
                    'result' => $result,
                ])
            );

            return $result;
        }
    );
}

function carmaja_api_github_ref_head(string $repoPathPrefix, string $branch): string
{
    $ref = carmaja_api_github_request(
        'GET',
        $repoPathPrefix . '/git/ref/heads/' . rawurlencode($branch)
    );
    $headSha = (string) ($ref['object']['sha'] ?? '');

    if (preg_match('/^[0-9a-f]{40}$/', $headSha) !== 1) {
        throw new CarmajaApiException(
            502,
            'GitHub-Remote-HEAD ist ungültig.',
            [],
            'github_head_invalid'
        );
    }

    return $headSha;
}

function carmaja_api_complete_prepared_github_commit(
    string $repoPathPrefix,
    string $branch,
    string $baseHeadSha,
    string $preparedCommitSha
): string {
    foreach ([$baseHeadSha, $preparedCommitSha] as $sha) {
        if (preg_match('/^[0-9a-f]{40}$/', $sha) !== 1) {
            throw new CarmajaApiException(
                500,
                'Gespeicherter GitHub-Commitstatus ist ungültig.',
                [],
                'github_prepared_commit_invalid'
            );
        }
    }

    $currentHeadSha = carmaja_api_github_ref_head($repoPathPrefix, $branch);

    if ($currentHeadSha === $preparedCommitSha) {
        return $preparedCommitSha;
    }

    if ($currentHeadSha === $baseHeadSha) {
        carmaja_api_github_request(
            'PATCH',
            $repoPathPrefix . '/git/refs/heads/' . rawurlencode($branch),
            [
                'sha' => $preparedCommitSha,
                'force' => false,
            ]
        );

        return $preparedCommitSha;
    }

    $comparison = carmaja_api_github_request(
        'GET',
        $repoPathPrefix . '/compare/'
            . rawurlencode($preparedCommitSha)
            . '...'
            . rawurlencode($currentHeadSha)
    );
    $mergeBaseSha = (string) ($comparison['merge_base_commit']['sha'] ?? '');
    $comparisonStatus = (string) ($comparison['status'] ?? '');

    if ($mergeBaseSha === $preparedCommitSha
        && in_array($comparisonStatus, ['ahead', 'identical'], true)) {
        return $preparedCommitSha;
    }

    throw new CarmajaApiException(
        409,
        'GitHub-Remote-HEAD wurde zwischenzeitlich geändert.',
        [],
        'github_head_changed'
    );
}

function carmaja_api_commit_public_product(
    array $publicProduct,
    array $operation,
    array $adapterState,
    callable $persistPrepared
): string
{
    $repository = carmaja_api_github_repository();
    $branch = carmaja_api_github_branch();
    $repoPathPrefix = '/repos/' . $repository;
    $sku = is_string($publicProduct['sku'] ?? null)
        ? $publicProduct['sku']
        : '';
    $ownedImagePattern = '/^website\/public\/images\/products\/'
        . preg_quote($sku, '/')
        . '\/0[1-5]\.jpg$/';

    if (preg_match('/^CP-\d{4}-\d{4}$/', $sku) !== 1) {
        throw new CarmajaApiException(
            500,
            'Öffentliche Produkt-SKU ist für GitHub ungültig.',
            [],
            'github_product_sku_invalid'
        );
    }

    $preparedCommitSha = is_string($adapterState['preparedCommitSha'] ?? null)
        ? $adapterState['preparedCommitSha']
        : '';
    $preparedBaseHeadSha = is_string($adapterState['baseHeadSha'] ?? null)
        ? $adapterState['baseHeadSha']
        : '';

    if ($preparedCommitSha !== '' || $preparedBaseHeadSha !== '') {
        return carmaja_api_complete_prepared_github_commit(
            $repoPathPrefix,
            $branch,
            $preparedBaseHeadSha,
            $preparedCommitSha
        );
    }

    $headSha = carmaja_api_github_ref_head($repoPathPrefix, $branch);
    $headCommit = carmaja_api_github_request('GET', $repoPathPrefix . '/git/commits/' . $headSha);
    $baseTree = (string) ($headCommit['tree']['sha'] ?? '');
    $content = carmaja_api_github_request(
        'GET',
        $repoPathPrefix . '/contents/website/content/products.json?ref=' . rawurlencode($branch)
    );
    $isV2 = ($publicProduct['productModelVersion'] ?? null) === 2;
    $targetDocumentVersion = $isV2 ? 2 : 1;
    $currentProducts = ['version' => $targetDocumentVersion, 'products' => []];

    if (is_string($content['content'] ?? null)) {
        $decoded = json_decode(base64_decode((string) $content['content']), true);
        $currentProducts = is_array($decoded)
            ? $decoded
            : ['version' => $targetDocumentVersion, 'products' => []];
    }

    $products = is_array($currentProducts['products'] ?? null)
        ? $currentProducts['products']
        : [];
    if (($currentProducts['version'] ?? null) !== $targetDocumentVersion
        && $products !== []) {
        throw new CarmajaApiException(
            409,
            'Öffentliche Produktprojektion verwendet ein anderes Produktmodell.',
            [],
            'public_product_model_conflict'
        );
    }
    $publicProductForJson = array_diff_key($publicProduct, ['_imageBlobs' => true]);
    $replaced = false;
    $existingPublicProduct = null;
    $isRemoval = !$isV2 && ($publicProductForJson['status'] ?? null) === 'disabled';

    foreach ($products as $index => $product) {
        $sameProduct = is_array($product) && (
            $isV2
                ? ($product['productId'] ?? null) === ($publicProduct['productId'] ?? null)
                : ($product['sku'] ?? null) === ($publicProduct['sku'] ?? null)
        );
        if ($sameProduct) {
            $existingPublicProduct = $product;
            if ($isRemoval) {
                unset($products[$index]);
            } else {
                $products[$index] = $publicProductForJson;
            }
            $replaced = true;
            break;
        }
    }

    if (!$replaced && !$isRemoval) {
        $products[] = $publicProductForJson;
    }

    $publicProductsFile = [
        'version' => $targetDocumentVersion,
        'products' => array_values($products),
    ];
    $tree = [];
    $productsJson = json_encode(
        $publicProductsFile,
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
    ) . PHP_EOL;
    $productsPath = 'website/content/products.json';
    carmaja_api_assert_repo_path_allowed($productsPath);
    $tree[] = [
        'path' => $productsPath,
        'mode' => '100644',
        'type' => 'blob',
        'content' => $productsJson,
    ];

    $newImagePaths = [];

    foreach (($publicProduct['_imageBlobs'] ?? []) as $imageBlob) {
        if (is_array($imageBlob) && is_string($imageBlob['_repoPath'] ?? null)) {
            carmaja_api_assert_repo_path_allowed($imageBlob['_repoPath']);

            if (preg_match($ownedImagePattern, $imageBlob['_repoPath']) !== 1) {
                throw new CarmajaApiException(
                    500,
                    'Produktbild gehört nicht zum verwalteten SKU-Verzeichnis.',
                    [],
                    'github_image_ownership_invalid'
                );
            }

            $newImagePaths[] = $imageBlob['_repoPath'];
        }
    }

    foreach (($existingPublicProduct['images'] ?? []) as $existingImage) {
        if (!is_array($existingImage) || !is_string($existingImage['src'] ?? null)) {
            continue;
        }

        $repoPath = 'website/public' . $existingImage['src'];
        carmaja_api_assert_repo_path_allowed($repoPath);

        if (preg_match($ownedImagePattern, $repoPath) !== 1) {
            throw new CarmajaApiException(
                500,
                'Bestehendes Produktbild gehört nicht zum verwalteten SKU-Verzeichnis.',
                [],
                'github_image_ownership_invalid'
            );
        }

        if ($isRemoval
            || !in_array($repoPath, $newImagePaths, true)) {
            $tree[] = [
                'path' => $repoPath,
                'mode' => '100644',
                'type' => 'blob',
                'sha' => null,
            ];
        }
    }

    if (!$isRemoval) {
        foreach (($publicProduct['_imageBlobs'] ?? []) as $imageBlob) {
            if (!is_array($imageBlob)) {
                continue;
            }

            $repoPath = (string) $imageBlob['_repoPath'];
            $sourcePath = (string) $imageBlob['_sourcePath'];
            carmaja_api_assert_repo_path_allowed($repoPath);

            if (!is_file($sourcePath)) {
                throw new CarmajaApiException(500, 'Produktbild fehlt fuer GitHub-Commit.');
            }

            $blob = carmaja_api_github_request('POST', $repoPathPrefix . '/git/blobs', [
                'content' => base64_encode((string) file_get_contents($sourcePath)),
                'encoding' => 'base64',
            ]);
            $tree[] = [
                'path' => $repoPath,
                'mode' => '100644',
                'type' => 'blob',
                'sha' => (string) $blob['sha'],
            ];
        }
    }

    $newTree = carmaja_api_github_request('POST', $repoPathPrefix . '/git/trees', [
        'base_tree' => $baseTree,
        'tree' => $tree,
    ]);
    $operationId = (string) ($operation['operationId'] ?? '');
    $requestHash = (string) ($operation['requestHash'] ?? '');
    carmaja_api_validate_operation_id($operationId);

    if (preg_match('/^[0-9a-f]{64}$/', $requestHash) !== 1) {
        throw new CarmajaApiException(
            500,
            'GitHub-Publish-Hash ist ungültig.',
            [],
            'github_request_hash_invalid'
        );
    }

    $commit = carmaja_api_github_request('POST', $repoPathPrefix . '/git/commits', [
        'message' => 'Publish product ' . $publicProduct['sku']
            . "\n\nCarmaja-Operation: " . $operationId
            . "\nCarmaja-Request-SHA256: " . $requestHash,
        'tree' => (string) $newTree['sha'],
        'parents' => [$headSha],
    ]);
    $commitSha = (string) $commit['sha'];

    if (preg_match('/^[0-9a-f]{40}$/', $commitSha) !== 1) {
        throw new CarmajaApiException(
            502,
            'GitHub hat keinen gültigen Commit geliefert.',
            [],
            'github_commit_invalid'
        );
    }

    $persistPrepared($headSha, $commitSha);

    return carmaja_api_complete_prepared_github_commit(
        $repoPathPrefix,
        $branch,
        $headSha,
        $commitSha
    );
}

function carmaja_api_image_orientation(string $path): int
{
    if (!function_exists('exif_read_data')) {
        return 1;
    }

    $exif = @exif_read_data($path);

    if (!is_array($exif)) {
        return 1;
    }

    return (int) ($exif['Orientation'] ?? 1);
}

function carmaja_api_apply_orientation(GdImage $image, int $orientation): GdImage
{
    if ($orientation === 2) {
        imageflip($image, IMG_FLIP_HORIZONTAL);
        return $image;
    }

    if ($orientation === 4) {
        imageflip($image, IMG_FLIP_VERTICAL);
        return $image;
    }

    if ($orientation === 5) {
        imageflip($image, IMG_FLIP_HORIZONTAL);
        $rotated = imagerotate($image, -90, 0);
        return $rotated instanceof GdImage ? $rotated : $image;
    }

    if ($orientation === 7) {
        imageflip($image, IMG_FLIP_HORIZONTAL);
        $rotated = imagerotate($image, 90, 0);
        return $rotated instanceof GdImage ? $rotated : $image;
    }

    $angle = match ($orientation) {
        3 => 180,
        6 => -90,
        8 => 90,
        default => 0,
    };

    if ($angle === 0) {
        return $image;
    }

    $rotated = imagerotate($image, $angle, 0);
    return $rotated instanceof GdImage ? $rotated : $image;
}

function carmaja_api_process_jpeg(
    string $sourcePath,
    string $originalName,
    int $declaredSize,
    string $destinationPath,
    bool $requireHttpUpload
): array {
    $extension = strtolower((string) pathinfo($originalName, PATHINFO_EXTENSION));
    $actualSize = is_file($sourcePath) ? filesize($sourcePath) : false;

    if (!in_array($extension, ['jpg', 'jpeg'], true)) {
        throw new CarmajaApiException(
            422,
            'Bilddatei muss die Endung .jpg oder .jpeg verwenden.',
            ['images' => 'Nur JPEG-Dateien sind erlaubt.'],
            'image_extension_invalid'
        );
    }

    if ($requireHttpUpload && !is_uploaded_file($sourcePath)) {
        throw new CarmajaApiException(
            422,
            'Bild-Upload ist ungültig.',
            ['images' => 'Datei wurde nicht als HTTP-Upload empfangen.'],
            'image_upload_invalid'
        );
    }

    if (!is_int($actualSize)
        || $actualSize <= 0
        || $declaredSize !== $actualSize
        || $actualSize > CARMAJA_MAX_IMAGE_BYTES) {
        throw new CarmajaApiException(
            $actualSize > CARMAJA_MAX_IMAGE_BYTES ? 413 : 422,
            'Bilddatei hat eine ungültige Größe.',
            ['images' => 'Maximal 1 MB pro Bild erlaubt.'],
            $actualSize > CARMAJA_MAX_IMAGE_BYTES
                ? 'image_too_large'
                : 'image_size_invalid'
        );
    }

    $info = @getimagesize($sourcePath);

    if ($info === false || ($info[2] ?? null) !== IMAGETYPE_JPEG) {
        throw new CarmajaApiException(
            422,
            'Dateiinhalt ist kein gültiges JPEG.',
            ['images' => 'Nur tatsächlich decodierbare JPEG-Dateien sind erlaubt.'],
            'image_content_invalid'
        );
    }

    $raw = file_get_contents($sourcePath);
    $decoded = is_string($raw) ? @imagecreatefromstring($raw) : false;

    if (!$decoded instanceof GdImage) {
        throw new CarmajaApiException(
            422,
            'JPEG konnte nicht decodiert werden.',
            ['images' => 'Beschädigte Bilddatei.'],
            'image_decode_failed'
        );
    }

    $image = $decoded;

    try {
        $image = carmaja_api_apply_orientation(
            $decoded,
            carmaja_api_image_orientation($sourcePath)
        );
        $width = imagesx($image);
        $height = imagesy($image);

        if ($width < 1
            || $height < 1
            || max($width, $height) > CARMAJA_MAX_IMAGE_EDGE) {
            throw new CarmajaApiException(
                422,
                'Bildabmessungen sind ungültig.',
                ['images' => 'Maximale Kantenlänge ist 1600 Pixel.'],
                'image_dimensions_invalid'
            );
        }

        if (!imagejpeg($image, $destinationPath, 86)) {
            throw new CarmajaApiException(
                500,
                'Bild konnte nicht sicher neu gespeichert werden.',
                [],
                'image_reencode_failed'
            );
        }

        @chmod($destinationPath, 0640);
        $cleanSize = filesize($destinationPath);
        $cleanInfo = @getimagesize($destinationPath);

        if (!is_int($cleanSize)
            || $cleanSize <= 0
            || $cleanSize > CARMAJA_MAX_IMAGE_BYTES
            || $cleanInfo === false
            || ($cleanInfo[2] ?? null) !== IMAGETYPE_JPEG) {
            @unlink($destinationPath);
            throw new CarmajaApiException(
                422,
                'Neu gespeichertes Bild überschreitet die erlaubten Grenzen.',
                ['images' => 'Bild muss nach Bereinigung maximal 1 MB groß sein.'],
                'image_output_invalid'
            );
        }

        return [
            'width' => $width,
            'height' => $height,
            'size' => $cleanSize,
        ];
    } finally {
        unset($image, $decoded);
    }
}

function carmaja_api_upload_images(string $draftId, array $body, array $actor): array
{
    $rawImageId = $_POST['imageId'] ?? null;
    $imageId = is_string($rawImageId)
        && preg_match(CARMAJA_IMAGE_PATTERN, trim($rawImageId)) === 1
            ? strtolower(trim($rawImageId))
            : 'invalid';
    carmaja_api_audit_best_effort('image_upload_started', [
        'draftId' => $draftId,
        'imageId' => $imageId,
        'result' => 'started',
    ]);

    try {
        $draft = carmaja_api_upload_image_transaction($draftId, $body, $actor);
        carmaja_api_audit_best_effort('image_upload_succeeded', [
            'draftId' => $draftId,
            'imageId' => $imageId,
            'result' => 'success',
        ]);
        return $draft;
    } catch (CarmajaApiException $error) {
        carmaja_api_audit_best_effort('image_upload_rejected', [
            'draftId' => $draftId,
            'imageId' => $imageId,
            'result' => 'rejected',
            'errorCode' => $error->errorCode,
        ]);
        throw $error;
    } catch (Throwable $error) {
        carmaja_api_audit_best_effort('image_upload_failed', [
            'draftId' => $draftId,
            'imageId' => $imageId,
            'result' => 'failed',
            'errorCode' => 'image_upload_failed',
        ]);
        throw $error;
    }
}

function carmaja_api_upload_image_transaction(
    string $draftId,
    array $body,
    array $actor
): array
{
    carmaja_api_validate_draft_id($draftId);
    $rawExpectedVersion = $_POST['expectedVersion'] ?? null;
    $expectedVersion = is_string($rawExpectedVersion)
        && preg_match('/^\d+$/', $rawExpectedVersion) === 1
            ? (int) $rawExpectedVersion
            : null;

    if (!is_int($expectedVersion)) {
        throw new CarmajaApiException(
            422,
            'expectedVersion ist erforderlich.',
            ['expectedVersion' => 'Nichtnegative Ganzzahl erwartet.'],
            'validation_failed'
        );
    }

    if (!extension_loaded('gd')) {
        throw new CarmajaApiException(
            503,
            'Bildverarbeitung ist nicht verfügbar.',
            [],
            'image_processing_unavailable'
        );
    }

    $rawImageId = $_POST['imageId'] ?? null;
    $imageId = is_string($rawImageId) ? strtolower(trim($rawImageId)) : null;

    if (!is_string($imageId)
        || preg_match(CARMAJA_IMAGE_PATTERN, $imageId) !== 1) {
        throw new CarmajaApiException(
            422,
            'imageId ist ungültig.',
            ['imageId' => 'UUID erwartet.'],
            'image_id_invalid'
        );
    }

    $rawDesiredImageIds = $_POST['desiredImageIds'] ?? null;
    $desiredImageIds = is_string($rawDesiredImageIds)
        ? json_decode($rawDesiredImageIds, true)
        : null;

    if (!is_array($desiredImageIds)
        || count($desiredImageIds) < 1
        || count($desiredImageIds) > CARMAJA_MAX_IMAGES
        || count(array_unique($desiredImageIds)) !== count($desiredImageIds)) {
        throw new CarmajaApiException(
            422,
            'Die Bildliste ist ungültig.',
            ['desiredImageIds' => 'Ein bis fünf eindeutige Bild-IDs erwartet.'],
            'image_manifest_invalid'
        );
    }

    foreach ($desiredImageIds as $desiredImageId) {
        if (!is_string($desiredImageId)
            || preg_match(CARMAJA_IMAGE_PATTERN, $desiredImageId) !== 1) {
            throw new CarmajaApiException(
                422,
                'Die Bildliste ist ungültig.',
                ['desiredImageIds' => 'UUIDs erwartet.'],
                'image_manifest_invalid'
            );
        }
    }

    $desiredImageIds = array_map('strtolower', $desiredImageIds);

    if (count(array_unique($desiredImageIds)) !== count($desiredImageIds)) {
        throw new CarmajaApiException(
            422,
            'Die Bildliste ist ungültig.',
            ['desiredImageIds' => 'Eindeutige UUIDs erwartet.'],
            'image_manifest_invalid'
        );
    }

    $imageIndex = array_search($imageId, $desiredImageIds, true);

    if (!is_int($imageIndex)) {
        throw new CarmajaApiException(
            422,
            'imageId fehlt in der Bildliste.',
            ['imageId' => 'Bild muss Teil der Bildliste sein.'],
            'image_manifest_invalid'
        );
    }

    $file = $_FILES['image'] ?? null;

    if (!is_array($file)
        || !is_string($file['tmp_name'] ?? null)
        || !is_string($file['name'] ?? null)) {
        throw new CarmajaApiException(
            422,
            'Kein vollständiges Bild übertragen.',
            ['image' => 'Ein JPEG ist erforderlich.'],
            'image_missing'
        );
    }

    $tmpName = $file['tmp_name'];
    $originalName = $file['name'];
    $uploadError = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
    $size = (int) ($file['size'] ?? 0);

    if ($uploadError !== UPLOAD_ERR_OK) {
        throw new CarmajaApiException(
            422,
            'Bild-Upload ist ungültig.',
            ['image' => 'Upload wurde nicht vollständig übertragen.'],
            'image_upload_invalid'
        );
    }

    $temporaryDirectory = carmaja_api_path(
        'uploads-temp/' . $draftId . '/' . bin2hex(random_bytes(8))
    );
    carmaja_api_ensure_directory($temporaryDirectory);

    try {
        $fileName = sprintf('%02d.jpg', $imageIndex + 1);
        $temporaryFile = $temporaryDirectory . DIRECTORY_SEPARATOR . $fileName;
        $imageInfo = carmaja_api_process_jpeg(
            $tmpName,
            $originalName,
            $size,
            $temporaryFile,
            !($GLOBALS['CARMAJA_API_ALLOW_LOCAL_UPLOADS_FOR_TESTS'] ?? false)
        );
        $alt = is_string($_POST['alt'] ?? null) ? trim($_POST['alt']) : '';

        if (mb_strlen($alt) > 160) {
            throw new CarmajaApiException(
                422,
                'Bildbeschreibung ist zu lang.',
                ['image' => 'Bildbeschreibung darf maximal 160 Zeichen enthalten.'],
                'image_alt_invalid'
            );
        }

        $processed = [
            'path' => $temporaryFile,
            'fileName' => $fileName,
            'imageId' => $imageId,
            'imageIndex' => $imageIndex,
            'width' => $imageInfo['width'],
            'height' => $imageInfo['height'],
            'size' => $imageInfo['size'],
            'sha256' => hash_file('sha256', $temporaryFile),
            'alt' => $alt,
            'isMain' => $imageIndex === 0,
        ];

        return carmaja_api_with_lock('draft-' . $draftId, function () use (
            $draftId,
            $expectedVersion,
            $processed,
            $desiredImageIds,
            $temporaryDirectory,
            $actor
        ): array {
            $draft = carmaja_api_load_draft($draftId);

            if (!is_array($draft)) {
                throw new CarmajaApiException(404, 'Entwurf wurde nicht gefunden.');
            }

            $currentVersion = (int) ($draft['version'] ?? 0);

            if ($currentVersion !== $expectedVersion) {
                carmaja_api_audit_best_effort('version_conflict', [
                    'draftId' => $draftId,
                    'currentVersion' => $currentVersion,
                    'result' => 'rejected',
                ]);
                throw new CarmajaApiException(
                    409,
                    'Der Entwurf wurde bereits geändert.',
                    [
                        'currentVersion' => $currentVersion,
                        'updatedAt' => $draft['updatedAt'] ?? null,
                    ],
                    'version_conflict'
                );
            }

            $imageDirectory = carmaja_api_path('uploads/' . $draftId);
            $imageParent = dirname($imageDirectory);
            carmaja_api_ensure_directory($imageParent);
            $backupDirectory = $imageDirectory . '.backup.' . bin2hex(random_bytes(6));
            $existingImages = is_array($draft['images'] ?? null) ? $draft['images'] : [];
            $existingById = [];

            foreach ($existingImages as $existingImage) {
                $existingId = is_array($existingImage)
                    && is_string($existingImage['imageId'] ?? null)
                        ? strtolower($existingImage['imageId'])
                        : null;

                if (is_string($existingId)
                    && in_array($existingId, $desiredImageIds, true)) {
                    $existingById[$existingId] = $existingImage;
                }
            }

            $processed['alt'] = $processed['alt'] !== ''
                ? $processed['alt']
                : (string) ($draft['name'] ?? 'Carmaja-Perlen Armband');
            $sameImage = $existingById[$processed['imageId']] ?? null;
            $hasRemovedImages = count($existingById) !== count($existingImages);
            $existingManifest = array_values(array_filter(
                array_map(
                    static fn (mixed $image): ?string =>
                        is_array($image) && is_string($image['imageId'] ?? null)
                            ? strtolower($image['imageId'])
                            : null,
                    $existingImages
                ),
                'is_string'
            ));
            $confirmedDesiredManifest = array_values(array_filter(
                $desiredImageIds,
                static fn (string $id): bool => isset($existingById[$id])
            ));

            if (!$hasRemovedImages
                && $existingManifest === $confirmedDesiredManifest
                && is_array($sameImage)
                && hash_equals(
                    (string) ($sameImage['sha256'] ?? ''),
                    (string) $processed['sha256']
                )
                && ($sameImage['alt'] ?? null) === $processed['alt']
                && ($sameImage['fileName'] ?? null) === $processed['fileName']
                && ($sameImage['isMain'] ?? null) === $processed['isMain']) {
                return $draft;
            }

            $finalById = $existingById;
            $finalById[$processed['imageId']] = $processed;
            $finalImages = [];

            foreach ($desiredImageIds as $index => $desiredImageId) {
                $image = $finalById[$desiredImageId] ?? null;

                if (!is_array($image)) {
                    continue;
                }

                $fileName = sprintf('%02d.jpg', $index + 1);

                if ($desiredImageId !== $processed['imageId']) {
                    $sourcePath = $image['path'] ?? null;

                    if (!is_string($sourcePath)
                        || !carmaja_api_path_is_inside($sourcePath, $imageDirectory)
                        || !is_file($sourcePath)
                        || !copy(
                            $sourcePath,
                            $temporaryDirectory . DIRECTORY_SEPARATOR . $fileName
                        )) {
                        throw new CarmajaApiException(
                            409,
                            'Bestätigter Bildstand ist unvollständig.',
                            [],
                            'image_state_incomplete'
                        );
                    }
                }

                $image['imageId'] = $desiredImageId;
                $image['imageIndex'] = $index;
                $image['fileName'] = $fileName;
                $image['isMain'] = $index === 0;
                $image['path'] = $temporaryDirectory . DIRECTORY_SEPARATOR . $fileName;
                $finalImages[] = $image;
            }

            $oldDirectoryMoved = false;
            $newDirectoryMoved = false;

            try {
                if (is_dir($imageDirectory)) {
                    if (!rename($imageDirectory, $backupDirectory)) {
                        throw new CarmajaApiException(
                            500,
                            'Vorheriger Bildstand konnte nicht gesichert werden.',
                            [],
                            'image_promote_failed'
                        );
                    }

                    $oldDirectoryMoved = true;
                }

                if (!rename($temporaryDirectory, $imageDirectory)) {
                    throw new CarmajaApiException(
                        500,
                        'Geprüfter Bildsatz konnte nicht atomar übernommen werden.',
                        [],
                        'image_promote_failed'
                    );
                }

                $newDirectoryMoved = true;

                foreach ($finalImages as $index => &$image) {
                    $image['path'] = $imageDirectory
                        . DIRECTORY_SEPARATOR
                        . $image['fileName'];
                }
                unset($image);

                $draft['images'] = $finalImages;
                $draft['version'] = $currentVersion + 1;
                $draft['updatedAt'] = carmaja_api_now();
                $draft = carmaja_api_save_draft($draft);

                if ($oldDirectoryMoved) {
                    carmaja_api_remove_tree($backupDirectory);
                }

                carmaja_api_audit_best_effort('product_images_uploaded', [
                    'draftId' => $draftId,
                    'version' => $draft['version'],
                    'count' => count($finalImages),
                    'deviceId' => $actor['tokenId'],
                    'result' => 'success',
                ]);

                return $draft;
            } catch (Throwable $error) {
                if ($newDirectoryMoved && is_dir($imageDirectory)) {
                    carmaja_api_remove_tree($imageDirectory);
                }

                if ($oldDirectoryMoved && is_dir($backupDirectory)) {
                    @rename($backupDirectory, $imageDirectory);
                }

                throw $error;
            }
        });
    } finally {
        if (is_dir($temporaryDirectory)) {
            carmaja_api_remove_tree($temporaryDirectory);
        }
    }
}

function carmaja_api_list_products(): array
{
    $directory = carmaja_api_path('drafts');

    if (!is_dir($directory)) {
        return ['products' => []];
    }

    $products = [];

    foreach (glob($directory . DIRECTORY_SEPARATOR . '*.json') ?: [] as $path) {
        $draft = carmaja_api_read_target_json($path, [], 'Produktentwurf');
        $products[] = [
            'draftId' => $draft['draftId'] ?? null,
            'sku' => $draft['sku'] ?? null,
            'slug' => $draft['slug'] ?? null,
            'version' => $draft['version'] ?? 0,
            'status' => $draft['status'] ?? 'draft',
            'name' => $draft['name'] ?? '',
            'updatedAt' => $draft['updatedAt'] ?? null,
        ];
    }

    usort(
        $products,
        static fn (array $left, array $right): int =>
            (string) ($right['updatedAt'] ?? '') <=> (string) ($left['updatedAt'] ?? '')
    );

    return ['products' => $products];
}

function carmaja_api_diagnose_environment(): array
{
    $target = carmaja_api_publish_target();
    $private = carmaja_api_private_dir();
    $publicWebroot = carmaja_api_required_directory_setting('CARMAJA_PUBLIC_WEBROOT');
    $testPrivateDir = carmaja_api_required_directory_setting('CARMAJA_TEST_PRIVATE_DIR');
    $testApiWebroot = carmaja_api_required_directory_setting('CARMAJA_TEST_API_WEBROOT');
    $testWebsiteWebroot = carmaja_api_required_directory_setting(
        'CARMAJA_TEST_WEBSITE_WEBROOT'
    );
    $productionPrivateDir = $target === 'production'
        ? carmaja_api_required_directory_setting('CARMAJA_PRODUCTION_PRIVATE_DIR')
        : carmaja_api_optional_path_setting('CARMAJA_PRODUCTION_PRIVATE_DIR');
    $productionApiWebroot = $target === 'production'
        ? carmaja_api_required_directory_setting('CARMAJA_PRODUCTION_API_WEBROOT')
        : carmaja_api_optional_path_setting('CARMAJA_PRODUCTION_API_WEBROOT');
    $productionWebsiteWebroot = $target === 'production'
        ? carmaja_api_required_directory_setting('CARMAJA_PRODUCTION_WEBSITE_WEBROOT')
        : carmaja_api_optional_path_setting('CARMAJA_PRODUCTION_WEBSITE_WEBROOT');
    $expectedApiWebroot = $target === 'test' ? $testApiWebroot : $productionApiWebroot;
    $configuredRoots = array_values(array_filter(
        [
            $testPrivateDir,
            $testApiWebroot,
            $testWebsiteWebroot,
            $productionPrivateDir,
            $productionApiWebroot,
            $productionWebsiteWebroot,
        ],
        static fn (?string $path): bool => $path !== null
    ));
    $normalizedRoots = array_map(
        'carmaja_api_normalize_path',
        $configuredRoots
    );

    if (carmaja_api_normalize_path($publicWebroot)
            !== carmaja_api_normalize_path((string) $expectedApiWebroot)
        || count(array_unique($normalizedRoots)) !== count($normalizedRoots)) {
        throw new CarmajaApiException(
            503,
            'API- und Website-Webroots sind nicht sicher getrennt.',
            [],
            'webroot_separation_failed'
        );
    }

    foreach ($configuredRoots as $leftIndex => $leftPath) {
        foreach ($configuredRoots as $rightIndex => $rightPath) {
            if ($leftIndex >= $rightIndex) {
                continue;
            }

            if (carmaja_api_path_is_inside($leftPath, $rightPath)
                || carmaja_api_path_is_inside($rightPath, $leftPath)) {
                throw new CarmajaApiException(
                    503,
                    'Test- und Produktionspfade sind nicht sicher getrennt.',
                    [],
                    'environment_paths_not_separated'
                );
            }
        }
    }

    $requiredDirectories = [
        'auth',
        'audit',
        'locks',
        'products',
        'drafts',
        'idempotency',
        'uploads',
        'uploads-temp',
        'backups',
        'sku-counter',
    ];

    foreach ($requiredDirectories as $relative) {
        $directory = $private . DIRECTORY_SEPARATOR . $relative;

        if (!is_dir($directory)
            || !is_readable($directory)
            || !is_writable($directory)) {
            throw new CarmajaApiException(
                503,
                'Benötigtes privates Verzeichnis ist nicht sicher zugreifbar.',
                ['check' => $relative],
                'private_directory_check_failed'
            );
        }
    }

    foreach (['json', 'mbstring', 'gd', 'exif'] as $extension) {
        if (!extension_loaded($extension)) {
            throw new CarmajaApiException(
                503,
                'Benötigte PHP-Erweiterung ist nicht verfügbar.',
                ['check' => $extension],
                'php_extension_missing'
            );
        }
    }

    $usersFile = carmaja_api_configured_users_file();
    $allWebroots = array_values(array_filter([
        $testApiWebroot,
        $testWebsiteWebroot,
        $productionApiWebroot,
        $productionWebsiteWebroot,
    ], static fn (?string $path): bool => $path !== null));
    $sensitiveFiles = [
        $usersFile,
        $private . DIRECTORY_SEPARATOR . 'environment.json',
        __FILE__,
        __DIR__ . DIRECTORY_SEPARATOR . 'bootstrap.php',
        __DIR__ . DIRECTORY_SEPARATOR . 'product-admin.php',
        __DIR__ . DIRECTORY_SEPARATOR . 'product-api-diagnostics.php',
    ];
    $configFile = getenv('CARMAJA_CONFIG_FILE');
    $githubTokenFile = getenv('CARMAJA_GITHUB_TOKEN_FILE');

    if (is_string($configFile) && trim($configFile) !== '') {
        $sensitiveFiles[] = trim($configFile);
    }

    if (is_string($githubTokenFile) && trim($githubTokenFile) !== '') {
        $sensitiveFiles[] = trim($githubTokenFile);
    }

    foreach ($sensitiveFiles as $file) {
        $realFile = realpath($file);
        $isPendingUsersFile = $file === $usersFile && !file_exists($usersFile);

        if ($isPendingUsersFile) {
            $realFile = $usersFile;
        }

        if (!is_string($realFile)
            || (!$isPendingUsersFile && !is_file($realFile))) {
            throw new CarmajaApiException(
                503,
                'Private Konfigurationsdatei ist nicht sicher erreichbar.',
                [],
                'private_configuration_unavailable'
            );
        }

        foreach ($allWebroots as $webroot) {
            if (carmaja_api_path_is_inside($realFile, $webroot)) {
                throw new CarmajaApiException(
                    503,
                    'Private Konfigurationsdatei liegt in einem öffentlichen Webroot.',
                    [],
                    'private_configuration_exposed'
                );
            }
        }
    }

    $probeBase = $private . DIRECTORY_SEPARATOR . '.diagnostic-' . bin2hex(random_bytes(6));
    $probeSource = $probeBase . '.tmp';
    $probeTarget = $probeBase . '.renamed';
    $lockPath = $private . DIRECTORY_SEPARATOR . 'locks' . DIRECTORY_SEPARATOR . '.diagnostic.lock';
    $lockHandle = null;

    try {
        if (file_put_contents($probeSource, 'probe', LOCK_EX) !== 5
            || !rename($probeSource, $probeTarget)
            || file_get_contents($probeTarget) !== 'probe') {
            throw new CarmajaApiException(
                503,
                'Atomare Dateiumbenennung ist nicht verfügbar.',
                [],
                'atomic_rename_check_failed'
            );
        }

        $lockHandle = fopen($lockPath, 'c');

        if ($lockHandle === false || !flock($lockHandle, LOCK_EX | LOCK_NB)) {
            throw new CarmajaApiException(
                503,
                'Dateisperren sind nicht verfügbar.',
                [],
                'flock_check_failed'
            );
        }
    } finally {
        if (is_resource($lockHandle)) {
            flock($lockHandle, LOCK_UN);
            fclose($lockHandle);
        }

        @unlink($probeSource);
        @unlink($probeTarget);
        @unlink($lockPath);
    }

    return [
        'ok' => true,
        'publishTarget' => $target,
        'checks' => [
            'privatePath' => 'ok',
            'environmentMarker' => 'ok',
            'directoryPermissions' => 'ok',
            'phpExtensions' => 'ok',
            'atomicRename' => 'ok',
            'flock' => 'ok',
            'webrootSeparation' => 'ok',
            'environmentPathSeparation' => 'ok',
            'privateConfigurationExposure' => 'ok',
        ],
    ];
}

function carmaja_api_create_backup(): array
{
    return carmaja_api_with_lock('backup', function (): array {
        $source = carmaja_api_private_dir();
        $backupRoot = carmaja_api_path('backups');
        carmaja_api_ensure_directory($backupRoot);
        $name = gmdate('Ymd-His');
        $target = $backupRoot . DIRECTORY_SEPARATOR . $name;
        carmaja_api_ensure_directory($target);
        copy(
            $source . DIRECTORY_SEPARATOR . 'environment.json',
            $target . DIRECTORY_SEPARATOR . 'environment.json'
        );
        @chmod($target . DIRECTORY_SEPARATOR . 'environment.json', 0640);
        carmaja_api_copy_tree($source . DIRECTORY_SEPARATOR . 'products', $target . DIRECTORY_SEPARATOR . 'products');
        carmaja_api_copy_tree($source . DIRECTORY_SEPARATOR . 'drafts', $target . DIRECTORY_SEPARATOR . 'drafts');
        carmaja_api_copy_tree($source . DIRECTORY_SEPARATOR . 'uploads', $target . DIRECTORY_SEPARATOR . 'uploads');
        carmaja_api_copy_tree($source . DIRECTORY_SEPARATOR . 'sku-counter', $target . DIRECTORY_SEPARATOR . 'sku-counter');
        carmaja_api_copy_tree($source . DIRECTORY_SEPARATOR . 'auth', $target . DIRECTORY_SEPARATOR . 'auth');
        carmaja_api_copy_tree($source . DIRECTORY_SEPARATOR . 'audit', $target . DIRECTORY_SEPARATOR . 'audit');
        carmaja_api_copy_tree($source . DIRECTORY_SEPARATOR . 'idempotency', $target . DIRECTORY_SEPARATOR . 'idempotency');
        carmaja_api_prune_backups($backupRoot);
        carmaja_api_audit('backup_created', ['backup' => $name]);

        return ['backup' => $name, 'status' => 'created'];
    });
}

function carmaja_api_copy_tree(string $source, string $target): void
{
    if (!is_dir($source)) {
        return;
    }

    carmaja_api_ensure_directory($target);

    foreach (new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    ) as $entry) {
        $relative = substr($entry->getPathname(), strlen($source) + 1);
        $destination = $target . DIRECTORY_SEPARATOR . $relative;

        if ($entry->isDir()) {
            carmaja_api_ensure_directory($destination);
        } else {
            carmaja_api_ensure_directory(dirname($destination));
            copy($entry->getPathname(), $destination);
            chmod($destination, 0640);
        }
    }
}

function carmaja_api_prune_backups(string $backupRoot): void
{
    $directories = array_filter(
        glob($backupRoot . DIRECTORY_SEPARATOR . '*') ?: [],
        'is_dir'
    );
    rsort($directories);

    foreach (array_slice($directories, CARMAJA_BACKUP_KEEP) as $directory) {
        carmaja_api_remove_tree($directory);
    }
}

function carmaja_api_remove_tree(string $directory): void
{
    if (!is_dir($directory)) {
        return;
    }

    foreach (new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    ) as $entry) {
        $entry->isDir() ? @rmdir($entry->getPathname()) : @unlink($entry->getPathname());
    }

    @rmdir($directory);
}
