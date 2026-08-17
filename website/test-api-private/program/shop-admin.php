<?php

declare(strict_types=1);

/** AP5: separate shop-admin authentication and daily operator actions. */

const CARMAJA_SHOP_ADMIN_SESSION_TTL_SECONDS = 1800;
const CARMAJA_SHOP_ADMIN_CSRF_TTL_SECONDS = 1800;
const CARMAJA_SHOP_ADMIN_LOGIN_WINDOW_SECONDS = 900;
const CARMAJA_SHOP_ADMIN_MAX_FAILED_LOGINS = 5;

final class CarmajaShopAdminException extends RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $httpStatus = 401
    ) {
        parent::__construct($message);
    }
}

function carmaja_shop_admin_token(): string
{
    return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
}

function carmaja_shop_admin_token_hash(string $token): string
{
    if ($token === '' || strlen($token) < 32 || strlen($token) > 128) {
        throw new CarmajaShopAdminException('admin_session_invalid', 'Sitzung ist ungültig.', 401);
    }

    return hash('sha256', $token);
}

function carmaja_shop_admin_username(string $username): string
{
    $normalized = strtolower(trim($username));
    if (preg_match('/^[a-z0-9][a-z0-9._-]{2,79}$/', $normalized) !== 1) {
        throw new CarmajaShopAdminException('admin_username_invalid', 'Anmeldung ist ungültig.', 422);
    }

    return $normalized;
}

function carmaja_shop_admin_validate_password(string $password): void
{
    if (strlen($password) < 12 || strlen($password) > 200 || preg_match('/[\x00-\x1F\x7F]/', $password) === 1) {
        throw new CarmajaShopAdminException('admin_password_invalid', 'Passwort erfüllt die Mindestanforderungen nicht.', 422);
    }
}

function carmaja_shop_admin_hash_password(string $password): string
{
    carmaja_shop_admin_validate_password($password);
    if (!defined('PASSWORD_ARGON2ID')) {
        throw new CarmajaShopAdminException('admin_argon2id_unavailable', 'Argon2id ist in dieser PHP-Umgebung nicht verfügbar.', 500);
    }

    $hash = password_hash($password, PASSWORD_ARGON2ID);
    if (!is_string($hash) || !str_starts_with($hash, '$argon2id$')) {
        throw new CarmajaShopAdminException('admin_password_hash_failed', 'Passwort konnte nicht sicher gespeichert werden.', 500);
    }

    return $hash;
}

function carmaja_shop_admin_set_session_cookie(string $token, int $expiresAt): void
{
    setcookie('__Host-cmj_admin', $token, [
        'expires' => $expiresAt,
        'path' => '/',
        'secure' => true,
        'httponly' => true,
        'samesite' => 'Strict',
    ]);
}

function carmaja_shop_admin_clear_session_cookie(): void
{
    carmaja_shop_admin_set_session_cookie('', time() - 3600);
}

function carmaja_shop_admin_login(
    CarmajaCommercePdo $commerce,
    string $username,
    string $password,
    string $ipAddress
): array {
    $normalized = carmaja_shop_admin_username($username);
    $attemptKey = hash('sha256', $normalized . '|' . ($ipAddress !== '' ? $ipAddress : 'unknown'));
    $state = $commerce->loadAdminLoginAttempt($attemptKey);
    if (is_array($state) && !empty($state['locked_until']) && strtotime((string) $state['locked_until']) > time()) {
        throw new CarmajaShopAdminException('admin_login_locked', 'Anmeldung ist vorübergehend gesperrt.', 429);
    }

    $user = $commerce->loadAdminUser($normalized);
    $valid = is_array($user)
        && (int) ($user['enabled'] ?? 0) === 1
        && is_string($user['password_hash'] ?? null)
        && password_verify($password, (string) $user['password_hash'])
        && str_starts_with((string) $user['password_hash'], '$argon2id$');
    if (!$valid) {
        $commerce->recordAdminLoginFailure($attemptKey, CARMAJA_SHOP_ADMIN_LOGIN_WINDOW_SECONDS, CARMAJA_SHOP_ADMIN_MAX_FAILED_LOGINS);
        throw new CarmajaShopAdminException('admin_auth_failed', 'Benutzername oder Passwort ist ungültig.', 401);
    }

    $commerce->clearAdminLoginFailures($attemptKey);
    $sessionToken = carmaja_shop_admin_token();
    $csrfToken = carmaja_shop_admin_token();
    $now = time();
    $expiresAt = gmdate('Y-m-d H:i:s', $now + CARMAJA_SHOP_ADMIN_SESSION_TTL_SECONDS);
    $commerce->createAdminSession(
        carmaja_shop_admin_token_hash($sessionToken),
        (string) $user['admin_id'],
        carmaja_shop_admin_token_hash($csrfToken),
        $expiresAt
    );

    return [
        'adminId' => (string) $user['admin_id'],
        'username' => (string) $user['username'],
        'sessionToken' => $sessionToken,
        'csrfToken' => $csrfToken,
        'csrfExpiresAt' => gmdate(DATE_ATOM, $now + CARMAJA_SHOP_ADMIN_CSRF_TTL_SECONDS),
        'expiresAt' => gmdate(DATE_ATOM, $now + CARMAJA_SHOP_ADMIN_SESSION_TTL_SECONDS),
    ];
}

function carmaja_shop_admin_authenticate(CarmajaCommercePdo $commerce, string $sessionToken): array
{
    $session = $commerce->loadAdminSession(carmaja_shop_admin_token_hash($sessionToken));
    if (!is_array($session)
        || $session['revoked_at'] !== null
        || strtotime((string) $session['expires_at']) <= time()) {
        throw new CarmajaShopAdminException('admin_session_invalid', 'Sitzung ist ungültig oder abgelaufen.', 401);
    }

    $commerce->touchAdminSession((string) $session['session_hash']);
    return $session;
}

function carmaja_shop_admin_require_csrf(array $session, string $csrfToken): void
{
    if ($csrfToken === '') {
        throw new CarmajaShopAdminException('admin_csrf_invalid', 'CSRF-Prüfung fehlgeschlagen.', 403);
    }
    try {
        $csrfHash = carmaja_shop_admin_token_hash($csrfToken);
    } catch (CarmajaShopAdminException) {
        throw new CarmajaShopAdminException('admin_csrf_invalid', 'CSRF-Prüfung fehlgeschlagen.', 403);
    }
    if (!hash_equals((string) $session['csrf_hash'], $csrfHash)) {
        throw new CarmajaShopAdminException('admin_csrf_invalid', 'CSRF-Prüfung fehlgeschlagen.', 403);
    }
}

function carmaja_shop_admin_request_json(): array
{
    $raw = file_get_contents('php://input');
    $body = is_string($raw) && $raw !== '' ? json_decode($raw, true, 32) : null;
    if (!is_array($body)) {
        throw new CarmajaShopAdminException('admin_request_invalid', 'JSON-Anfrage ist ungültig.', 422);
    }

    return $body;
}

function carmaja_shop_admin_correlation(array $body): string
{
    $value = $body['correlationId'] ?? '';
    if (!is_string($value) || preg_match('/^[A-Za-z0-9._:-]{8,100}$/', $value) !== 1) {
        throw new CarmajaShopAdminException('admin_correlation_required', 'Korrelations-ID ist erforderlich.', 422);
    }

    return $value;
}

/** Deterministic auth oracle used by AP5 tests without a database or runtime account. */
final class CarmajaShopAdminMemory
{
    public array $users = [];
    public array $sessions = [];
    public array $attempts = [];
    public array $audit = [];

    public function addUser(string $username, string $password): void
    {
        $normalized = carmaja_shop_admin_username($username);
        $this->users[$normalized] = [
            'admin_id' => carmaja_commerce_new_id(),
            'username' => $normalized,
            'password_hash' => carmaja_shop_admin_hash_password($password),
            'enabled' => 1,
        ];
    }

    public function login(string $username, string $password, int $now = 1000): array
    {
        $normalized = carmaja_shop_admin_username($username);
        $attempt = $this->attempts[$normalized] ?? ['count' => 0, 'lockedUntil' => 0];
        if ($attempt['lockedUntil'] > $now) {
            throw new CarmajaShopAdminException('admin_login_locked', 'Anmeldung ist vorübergehend gesperrt.', 429);
        }
        $user = $this->users[$normalized] ?? null;
        if (!is_array($user) || !password_verify($password, (string) $user['password_hash'])) {
            $attempt['count']++;
            if ($attempt['count'] >= CARMAJA_SHOP_ADMIN_MAX_FAILED_LOGINS) {
                $attempt['lockedUntil'] = $now + CARMAJA_SHOP_ADMIN_LOGIN_WINDOW_SECONDS;
            }
            $this->attempts[$normalized] = $attempt;
            throw new CarmajaShopAdminException('admin_auth_failed', 'Benutzername oder Passwort ist ungültig.', 401);
        }
        $this->attempts[$normalized] = ['count' => 0, 'lockedUntil' => 0];
        $token = carmaja_shop_admin_token();
        $csrf = carmaja_shop_admin_token();
        $this->sessions[hash('sha256', $token)] = [
            'admin_id' => $user['admin_id'],
            'csrf_hash' => hash('sha256', $csrf),
            'expires_at' => $now + CARMAJA_SHOP_ADMIN_SESSION_TTL_SECONDS,
            'revoked_at' => null,
        ];
        return ['sessionToken' => $token, 'csrfToken' => $csrf, 'adminId' => $user['admin_id']];
    }

    public function revoke(string $token): void
    {
        $hash = carmaja_shop_admin_token_hash($token);
        if (isset($this->sessions[$hash])) {
            $this->sessions[$hash]['revoked_at'] = 1;
        }
    }
}
