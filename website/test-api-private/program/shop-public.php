<?php

declare(strict_types=1);

const CARMAJA_SHOP_SESSION_TTL_SECONDS = 86400;
const CARMAJA_SHOP_CSRF_TTL_SECONDS = 7200;
const CARMAJA_SHOP_CONTEXT_TTL_SECONDS = 600;
const CARMAJA_SHOP_CHECKOUT_TOKEN_TTL_SECONDS = 7200;
const CARMAJA_SHOP_MAX_CHECKOUT_ATTEMPTS = 3;

function carmaja_shop_token(): string
{
    return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
}

function carmaja_shop_token_hash(string $raw): string
{
    if ($raw === '' || strlen($raw) < 32 || strlen($raw) > 128) {
        throw new CarmajaCommerceException('token_invalid', 'Token ist ungÃ¼ltig.', 403);
    }

    return hash('sha256', $raw);
}

function carmaja_shop_cookie(string $name, string $value, int $expiresAt): void
{
    setcookie($name, $value, [
        'expires' => $expiresAt,
        'path' => '/',
        'secure' => true,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

function carmaja_shop_origin(?string $origin): ?string
{
    if ($origin === null || trim($origin) === '') {
        return null;
    }
    $parsed = parse_url($origin);
    if (!is_array($parsed) || !isset($parsed['scheme'], $parsed['host'])
        || !in_array(strtolower((string) $parsed['scheme']), ['https'], true)
        || isset($parsed['user'], $parsed['pass'], $parsed['query'], $parsed['fragment'])
        || (isset($parsed['path']) && $parsed['path'] !== '' && $parsed['path'] !== '/')) {
        return null;
    }
    $port = isset($parsed['port']) ? ':' . $parsed['port'] : '';
    return strtolower((string) $parsed['scheme']) . '://' . strtolower((string) $parsed['host']) . $port;
}

function carmaja_shop_apply_cors(array $config, ?string $requestOrigin): void
{
    $allowed = carmaja_shop_origin($config['shopWebsiteOrigin'] ?? null);
    $origin = carmaja_shop_origin($requestOrigin);
    header('Vary: Origin');
    if ($allowed !== null && $origin !== null && hash_equals($allowed, $origin)) {
        header('Access-Control-Allow-Origin: ' . $allowed);
        header('Access-Control-Allow-Credentials: true');
        header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type, X-CSRF-Token, X-Checkout-Request-Id, Idempotency-Key, X-Live-Context');
        header('Access-Control-Max-Age: 600');
    }
}

function carmaja_shop_require_origin(array $config): void
{
    $requestOrigin = $_SERVER['HTTP_ORIGIN'] ?? null;
    if ($requestOrigin === null) {
        return;
    }
    $allowed = carmaja_shop_origin($config['shopWebsiteOrigin'] ?? null);
    $origin = carmaja_shop_origin($requestOrigin);
    if ($allowed === null || $origin === null || !hash_equals($allowed, $origin)) {
        throw new CarmajaCommerceException('cors_origin_denied', 'Origin ist nicht freigegeben.', 403);
    }
}

function carmaja_shop_require_header(string $name): string
{
    $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
    $value = $_SERVER[$key] ?? '';
    if (!is_string($value) || trim($value) === '') {
        throw new CarmajaCommerceException('header_required', $name . ' ist erforderlich.', 422);
    }
    return trim($value);
}

function carmaja_shop_json_body(): array
{
    $raw = file_get_contents('php://input');
    $body = is_string($raw) && $raw !== '' ? json_decode($raw, true, 16) : null;
    if (!is_array($body)) {
        throw new CarmajaCommerceException('request_invalid', 'JSON-Anfrage ist ungÃ¼ltig.', 422);
    }
    return $body;
}

function carmaja_shop_set_no_store(): void
{
    header('Cache-Control: no-store, max-age=0');
    header('Pragma: no-cache');
    header('Expires: 0');
}

function carmaja_shop_context(CarmajaCommercePdo $commerce, string $sessionRaw): array
{
    $sessionHash = carmaja_shop_token_hash($sessionRaw);
    $csrfRaw = carmaja_shop_token();
    $liveRaw = carmaja_shop_token();
    $now = time();
    $commerce->upsertShopSession(
        $sessionHash,
        carmaja_shop_token_hash($csrfRaw),
        gmdate('Y-m-d H:i:s', $now + CARMAJA_SHOP_CSRF_TTL_SECONDS),
        carmaja_shop_token_hash($liveRaw),
        gmdate('Y-m-d H:i:s', $now + CARMAJA_SHOP_CONTEXT_TTL_SECONDS),
        gmdate('Y-m-d H:i:s', $now + CARMAJA_SHOP_SESSION_TTL_SECONDS)
    );
    return [
        'sessionHash' => $sessionHash,
        'csrfToken' => $csrfRaw,
        'liveContextToken' => $liveRaw,
        'csrfExpiresAt' => gmdate(DATE_ATOM, $now + CARMAJA_SHOP_CSRF_TTL_SECONDS),
        'checkoutContextExpiresAt' => gmdate(DATE_ATOM, $now + CARMAJA_SHOP_CONTEXT_TTL_SECONDS),
    ];
}

function carmaja_shop_verify_context(CarmajaCommercePdo $commerce, string $sessionRaw): array
{
    $row = $commerce->loadShopSession(carmaja_shop_token_hash($sessionRaw));
    if (!is_array($row)
        || strtotime((string) $row['session_expires_at']) < time()
        || strtotime((string) $row['csrf_expires_at']) < time()
        || strtotime((string) $row['live_context_expires_at']) < time()) {
        throw new CarmajaCommerceException('shop_session_expired', 'Shop-Sitzung ist abgelaufen.', 403);
    }
    return $row;
}

function carmaja_shop_verify_raw_context(string $raw, string $storedHash): void
{
    if (!hash_equals($storedHash, carmaja_shop_token_hash($raw))) {
        throw new CarmajaCommerceException('context_invalid', 'Checkout-Kontext ist ungÃ¼ltig oder abgelaufen.', 403);
    }
}

function carmaja_shop_rate_key(string $sessionHash, string $ip): string
{
    return hash('sha256', $sessionHash . '|' . $ip);
}

function carmaja_shop_validate_checkout_request(array $body): string
{
    if (array_keys($body) !== ['productId'] || !is_string($body['productId'])
        || preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{7,63}$/', $body['productId']) !== 1) {
        throw new CarmajaCommerceException('checkout_request_invalid', 'Nur eine gÃ¼ltige Produkt-ID ist erlaubt.', 422);
    }
    return $body['productId'];
}

/** Deterministic, side-effect-free oracle used by API security tests. */
final class CarmajaShopRateLimiter
{
    private array $buckets = [];

    public function allow(string $key, bool $successful = false, int $now = 0): bool
    {
        $now = $now ?: time();
        $bucket = $this->buckets[$key] ?? ['window' => $now, 'counted' => 0, 'successful' => 0];
        if ($now - $bucket['window'] >= 86400) {
            $bucket = ['window' => $now, 'counted' => 0, 'successful' => 0];
        }
        $effective = $bucket['counted'] - $bucket['successful'];
        if (!$successful && $effective >= CARMAJA_SHOP_MAX_CHECKOUT_ATTEMPTS) {
            $this->buckets[$key] = $bucket;
            return false;
        }
        $bucket['counted']++;
        if ($successful) {
            $bucket['successful']++;
        }
        $this->buckets[$key] = $bucket;
        return true;
    }

    public function releaseSuccessful(string $key): void
    {
        if (isset($this->buckets[$key])) {
            $this->buckets[$key]['successful'] = min(
                $this->buckets[$key]['counted'],
                $this->buckets[$key]['successful'] + 1
            );
        }
    }
}
