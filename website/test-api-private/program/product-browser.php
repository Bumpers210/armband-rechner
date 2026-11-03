<?php
declare(strict_types=1);
require_once __DIR__ . '/product-price-list.php';

/** Test-only browser boundary. Product mutations use the very same V5 services as Android. */
final class CarmajaProductBrowser
{
    public const ORIGIN = 'https://test.carmaja-perlen.de';
    public const COOKIE = '__Secure-cmj_product_test';
    public const COOKIE_PATH = '/product-browser/';
    public const SESSION_SECONDS = 1800;

    public function __construct(private readonly array $config, private readonly Closure $guard)
    {
        if (($config['environment'] ?? null) !== 'test' || ($config['publishTarget'] ?? null) !== 'test'
            || ($config['productionPublishEnabled'] ?? true) !== false
            || ($config['githubAdapterEnabled'] ?? true) !== false) {
            throw new CarmajaApiException(503, 'Artikelverwaltung nur in der Testumgebung verfügbar.', [], 'test_only');
        }
    }

    public static function origin(string $origin): void
    {
        if ($origin !== self::ORIGIN) {
            throw new CarmajaApiException(403, 'Anfrage nicht erlaubt.', [], 'origin_invalid');
        }
    }

    private function directory(): string
    {
        $root = realpath($this->config['privateDir']);
        if (!is_string($root) || is_link($this->config['privateDir'])) { throw new RuntimeException('private_path_invalid'); }
        $path = $root . '/auth/browser-sessions';
        if (is_link($path)) { throw new RuntimeException('session_path_invalid'); }
        if (!is_dir($path) && !mkdir($path, 0700)) { throw new RuntimeException('session_directory_failed'); }
        if (str_replace('\\', '/', (string) realpath($path)) !== str_replace('\\', '/', $path)) { throw new RuntimeException('session_path_invalid'); }
        return (string) realpath($path);
    }

    private function path(string $cookie): string
    {
        if (preg_match('/^[a-f0-9]{64}$/D', $cookie) !== 1) {
            throw new CarmajaApiException(401, 'Bitte anmelden.', [], 'authentication_required');
        }
        return $this->directory() . '/' . hash('sha256', $cookie) . '.json';
    }

    private function write(string $path, array $value): void
    {
        if (is_link($path)) { throw new RuntimeException('session_link_rejected'); }
        $tmp = tempnam(dirname($path), '.session-');
        if (!is_string($tmp)) { throw new RuntimeException('session_write_failed'); }
        try {
            $json = json_encode($value, JSON_THROW_ON_ERROR);
            if (!chmod($tmp, 0600) || file_put_contents($tmp, $json, LOCK_EX) !== strlen($json) || !rename($tmp, $path)) {
                throw new RuntimeException('session_write_failed');
            }
        } finally { if (is_file($tmp)) { unlink($tmp); } }
    }

    private function user(string $username): ?array
    {
        foreach (carmaja_api_load_users()['users'] ?? [] as $user) {
            if (($user['username'] ?? null) === $username && ($user['active'] ?? true) === true) { return $user; }
        }
        return null;
    }

    private function credentialStamp(array $user): string
    {
        return hash_hmac('sha256', (string) $user['passwordHash'], carmaja_api_token_pepper());
    }

    /** Cookie is returned ONLY to the HTTP boundary, never in a JSON response or log. */
    public function login(array $body, int $now): array
    {
        carmaja_api_reject_unknown_fields($body, ['username', 'password']);
        if (!is_string($body['username'] ?? null) || !is_string($body['password'] ?? null)
            || strlen($body['username']) > 160 || strlen($body['password']) > 1024) {
            throw new CarmajaApiException(400, 'Anmeldedaten prüfen.', [], 'login_request_invalid');
        }
        $username = carmaja_api_normalize_username($body['username']);
        carmaja_api_assert_login_allowed($username);
        $user = $this->user($username);
        if ($user === null || !password_verify($body['password'], $user['passwordHash'])) {
            carmaja_api_record_login_failure($username);
            throw new CarmajaApiException(401, 'Anmeldung fehlgeschlagen.', [], 'invalid_credentials');
        }
        carmaja_api_clear_login_failures($username);
        // Expired sessions are confined to this new, private browser directory.
        foreach (glob($this->directory() . '/*.json') ?: [] as $file) {
            if (!is_link($file) && preg_match('/^[a-f0-9]{64}\.json$/D', basename($file)) === 1) {
                $old = json_decode((string) file_get_contents($file), true);
                if (is_array($old) && ($old['expires'] ?? PHP_INT_MAX) <= $now) { unlink($file); }
            }
        }
        $cookie = bin2hex(random_bytes(32));
        $session = ['username' => $username, 'credentialStamp' => $this->credentialStamp($user),
            'csrf' => bin2hex(random_bytes(32)), 'expires' => $now + self::SESSION_SECONDS,
            'minute' => intdiv($now, 60), 'requests' => 0];
        $this->write($this->path($cookie), $session);
        carmaja_api_audit_best_effort('product_browser_login', ['result' => 'success']);
        return ['cookie' => $cookie, 'csrfToken' => $session['csrf'], 'expiresAt' => gmdate('c', $session['expires'])];
    }

    public function authenticate(string $cookie, ?string $csrf, bool $write, int $now): array
    {
        $path = $this->path($cookie);
        return carmaja_api_with_lock('browser-' . hash('sha256', $cookie), function () use ($path, $cookie, $csrf, $write, $now): array {
            $session = is_file($path) && !is_link($path) ? json_decode((string) file_get_contents($path), true) : null;
            $user = is_array($session) ? $this->user((string) ($session['username'] ?? '')) : null;
            if (!is_array($session) || ($session['expires'] ?? 0) <= $now || $user === null
                || !hash_equals($this->credentialStamp($user), (string) ($session['credentialStamp'] ?? ''))) {
                throw new CarmajaApiException(401, 'Sitzung beendet. Bitte erneut anmelden.', [], 'session_expired');
            }
            if ($write && (!is_string($csrf) || !hash_equals($session['csrf'], $csrf))) {
                throw new CarmajaApiException(403, 'Sicherheitsprüfung fehlgeschlagen. Bitte neu laden.', [], 'csrf_invalid');
            }
            $count = ($session['minute'] ?? -1) === intdiv($now, 60) ? (int) $session['requests'] : 0;
            if ($count >= 120) { throw new CarmajaApiException(429, 'Bitte kurz warten.', [], 'rate_limited'); }
            $session['minute'] = intdiv($now, 60); $session['requests'] = $count + 1;
            $this->write($path, $session);
            return ['tokenId' => 'browser-' . substr(hash('sha256', $cookie), 0, 32), 'username' => $session['username'],
                'csrfToken' => $session['csrf'], 'expiresAt' => gmdate('c', $session['expires'])];
        });
    }

    public function logout(string $cookie): void
    {
        $path = $this->path($cookie);
        if (is_file($path) && !is_link($path)) { unlink($path); }
    }

    public static function product(array $draft): array
    {
        $product = carmaja_api_v5_product_response_from_draft($draft);
        $uploaded = array_column($product['images'], 'imageId');
        $product['images'] = array_map(static fn (array $image): array => [
            'imageId' => $image['imageId'], 'fileName' => $image['fileName'], 'alt' => $image['alt'],
            'width' => $image['width'], 'height' => $image['height'], 'isMain' => $image['isMain'],
            'uploaded' => in_array($image['imageId'], $uploaded, true),
        ], $draft['imageManifest'] ?? $product['images']);
        return $product;
    }

    /** No separate product store, inventory writer or publisher. */
    public function dispatch(string $method, array $parts, array $body, array $actor, ?string $key): array
    {
        if ($method === 'GET' && $parts === ['price-list']) { return carmaja_product_price_list_load(); }
        if ($method !== 'GET') {
            if (($this->config['productApiV5WritesEnabled'] ?? false) !== true) {
                throw new CarmajaApiException(503, 'Produktbearbeitung derzeit gesperrt.', [], 'product_v5_not_enabled');
            }
            $key = carmaja_api_v2_validate_idempotency_key($key);
        }
        if ($parts === ['products'] && $method === 'GET') {
            $products = [];
            foreach (glob(carmaja_api_path('drafts/*.json')) ?: [] as $path) {
                $draft = carmaja_api_read_target_json($path, [], 'Produkt');
                $products[] = self::product($draft);
            }
            usort($products, static fn ($a, $b) => strcmp($a['name'], $b['name']));
            return ['products' => $products];
        }
        if (($parts[0] ?? '') !== 'products' || !isset($parts[1])) { throw new CarmajaApiException(404, 'Endpunkt fehlt.', [], 'endpoint_not_found'); }
        $id = $parts[1]; carmaja_api_validate_draft_id($id);
        ($this->guard)($id, $method === 'PUT' ? $body : null);
        if ($method === 'PUT' && count($parts) === 2) {
            carmaja_api_v5_put($id, $body, $actor, $key);
            return ['product' => self::product(carmaja_api_load_draft($id))];
        }
        $draft = carmaja_api_load_draft($id);
        if ($draft === null) { throw new CarmajaApiException(404, 'Artikel nicht gefunden.', [], 'product_not_found'); }
        if ($method === 'GET' && count($parts) === 2) { return ['product' => self::product($draft)]; }
        if (($parts[2] ?? '') === 'images') {
            if ($method === 'GET' && count($parts) === 4) { return ['imageFile' => carmaja_api_v4_product_image_file($id, $parts[3])]; }
            if ($method === 'POST' && count($parts) === 3) {
                return ['product' => self::product(carmaja_api_upload_images($id, $body, $actor))];
            }
        }
        if ($method === 'POST' && count($parts) === 3 && in_array($parts[2], ['publish', 'archive', 'restore'], true)) {
            $result = carmaja_api_v4_transition_product($id, $body, $actor, $parts[2]);
            return ['product' => self::product(carmaja_api_load_draft($id)), 'publication' => $result['publication']];
        }
        if (count($parts) === 3 && in_array($parts[2], ['inventory', 'initial-inventory'], true)) {
            $commerce = carmaja_bootstrap_commerce($this->config);
            if (!$commerce instanceof CarmajaCartCommerce) { throw new CarmajaApiException(503, 'Bestand nicht verfügbar.', [], 'inventory_not_enabled'); }
            if ($parts[2] === 'inventory' && $method === 'GET') { return ['inventory' => $commerce->loadInventoryForManagement($id)]; }
            if ($parts[2] === 'inventory' && $method === 'POST') {
                carmaja_api_reject_unknown_fields($body, ['targetOnHand', 'expectedInventoryVersion', 'reason', 'correlationId']);
                return ['inventory' => $commerce->adjustInventory([...$body, 'productId' => $id, 'idempotencyKey' => $key], $actor['tokenId'])];
            }
            if ($parts[2] === 'initial-inventory' && $method === 'POST') {
                if (array_keys($body) !== ['targetOnHand'] || !is_int($body['targetOnHand'])) {
                    throw new CarmajaApiException(422, 'Anfangsbestand ungültig.', [], 'validation_failed');
                }
                return ['initialInventory' => $commerce->stageInitialInventory($id, $body['targetOnHand'], $actor['tokenId'], $key)];
            }
        }
        throw new CarmajaApiException(404, 'Endpunkt fehlt.', [], 'endpoint_not_found');
    }
}
