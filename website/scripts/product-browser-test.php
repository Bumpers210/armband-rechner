<?php
declare(strict_types=1);

// Installed next to cart-v5-device-test.php in the existing private V5 stage.
require_once __DIR__ . '/cart-v5-device-test.php';
require_once dirname(__DIR__) . '/test-api-private/program/product-browser.php';

function carmaja_product_browser_http(): never
{
    ini_set('display_errors', '0'); ini_set('log_errors', '0');
    header('Cache-Control: no-store'); header('Vary: Origin');
    header('Content-Type: application/json; charset=utf-8');
    header('X-Content-Type-Options: nosniff'); header('X-Robots-Tag: noindex, nofollow');
    try {
        if (($_SERVER['HTTPS'] ?? '') !== 'on' && (int) ($_SERVER['SERVER_PORT'] ?? 0) !== 443) {
            throw new CarmajaApiException(403, 'HTTPS erforderlich.', [], 'https_required');
        }
        CarmajaProductBrowser::origin((string) ($_SERVER['HTTP_ORIGIN'] ?? ''));
        header('Access-Control-Allow-Origin: ' . CarmajaProductBrowser::ORIGIN);
        header('Access-Control-Allow-Credentials: true');
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        if ($method === 'OPTIONS') {
            if (!in_array($_SERVER['HTTP_ACCESS_CONTROL_REQUEST_METHOD'] ?? '', ['GET', 'POST', 'PUT'], true)) {
                throw new CarmajaApiException(405, 'Methode nicht erlaubt.', [], 'method_invalid');
            }
            $requested = array_filter(array_map('trim', explode(',', strtolower($_SERVER['HTTP_ACCESS_CONTROL_REQUEST_HEADERS'] ?? ''))));
            if (array_diff($requested, ['content-type', 'x-csrf-token', 'idempotency-key']) !== []) {
                throw new CarmajaApiException(403, 'Header nicht erlaubt.', [], 'headers_invalid');
            }
            header('Access-Control-Allow-Methods: GET, POST, PUT');
            header('Access-Control-Allow-Headers: Content-Type, X-CSRF-Token, Idempotency-Key');
            http_response_code(204); exit;
        }
        if (!in_array($method, ['GET', 'POST', 'PUT'], true)) { throw new CarmajaApiException(405, 'Methode nicht erlaubt.', [], 'method_invalid'); }
        $root = cart_v5_root();
        $lock = fopen($root . '/v5-stage.lock', 'c');
        cart_probe_assert(is_resource($lock) && flock($lock, LOCK_SH | LOCK_NB), 'v5_stage_busy');
        $config = cart_v5_active(); $owner = cart_v5_read($root . '/v5-owner.json');
        $browser = new CarmajaProductBrowser($config, static function ($id, $body) use ($owner, $root): void {
            cart_v5_guard_product($id, $owner, $body);
            if ($body !== null && !is_file($root . '/drafts/' . $id . '.json')) {
                cart_probe_assert(count(glob($root . '/drafts/*.json') ?: []) < 20, 'v5_fixture_limit');
            }
        });
        $route = $_GET['route'] ?? '';
        if (!is_string($route) || preg_match('#^/(?:session|login|logout|price-list|products(?:/[A-Za-z0-9-]+(?:/(?:images(?:/[A-Za-z0-9-]+)?|publish|archive|restore|inventory|initial-inventory))?)?)$#D', $route) !== 1) {
            throw new CarmajaApiException(404, 'Endpunkt fehlt.', [], 'endpoint_not_found');
        }
        $body = [];
        if ($method !== 'GET') {
            $multipart = str_ends_with($route, '/images') && $method === 'POST';
            $length = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
            $type = strtolower((string) ($_SERVER['CONTENT_TYPE'] ?? ''));
            if ($length > ($multipart ? 6 * 1048576 : 65536)) { throw new CarmajaApiException(413, 'Anfrage zu groß.', [], 'request_too_large'); }
            if ($multipart) {
                if (!str_starts_with($type, 'multipart/form-data;')) { throw new CarmajaApiException(415, 'JPEG-Upload erwartet.', [], 'content_type_invalid'); }
                $body = $_POST;
            } else {
                if (!str_starts_with($type, 'application/json')) { throw new CarmajaApiException(415, 'JSON erwartet.', [], 'content_type_invalid'); }
                $raw = file_get_contents('php://input', false, null, 0, 65537);
                if (!is_string($raw) || strlen($raw) > 65536) { throw new CarmajaApiException(413, 'Anfrage zu groß.', [], 'request_too_large'); }
                try { $body = json_decode($raw, true, 32, JSON_THROW_ON_ERROR); }
                catch (Throwable) { throw new CarmajaApiException(400, 'JSON ungültig.', [], 'json_invalid'); }
                if (!is_array($body) || ($body !== [] && array_is_list($body))) { throw new CarmajaApiException(400, 'Objekt erwartet.', [], 'json_invalid'); }
            }
        }
        $cookie = (string) ($_COOKIE[CarmajaProductBrowser::COOKIE] ?? '');
        $cookieOptions = ['expires' => time() + CarmajaProductBrowser::SESSION_SECONDS,
            'path' => CarmajaProductBrowser::COOKIE_PATH, 'secure' => true, 'httponly' => true, 'samesite' => 'Strict'];
        if ($route === '/login' && $method === 'POST') {
            $login = $browser->login($body, time());
            if (preg_match('/^[a-f0-9]{64}$/D', $cookie) === 1) { $browser->logout($cookie); }
            setcookie(CarmajaProductBrowser::COOKIE, $login['cookie'], $cookieOptions);
            unset($login['cookie']);
            carmaja_bootstrap_send(200, ['ok' => true, 'data' => $login]);
        }
        $actor = $browser->authenticate($cookie, $_SERVER['HTTP_X_CSRF_TOKEN'] ?? null, $method !== 'GET', time());
        if ($route === '/session' && $method === 'GET') {
            carmaja_bootstrap_send(200, ['ok' => true, 'data' => ['csrfToken' => $actor['csrfToken'], 'expiresAt' => $actor['expiresAt']]]);
        }
        if ($route === '/logout' && $method === 'POST') {
            $browser->logout($cookie); setcookie(CarmajaProductBrowser::COOKIE, '', [...$cookieOptions, 'expires' => 1]);
            carmaja_bootstrap_send(200, ['ok' => true, 'data' => ['loggedOut' => true]]);
        }
        $result = $browser->dispatch($method, explode('/', trim($route, '/')), $body, $actor, $_SERVER['HTTP_IDEMPOTENCY_KEY'] ?? null);
        if (isset($result['imageFile'])) { carmaja_bootstrap_send_product_image($result['imageFile']); }
        carmaja_bootstrap_send(200, ['ok' => true, 'data' => $result]);
    } catch (CarmajaApiException $e) {
        carmaja_bootstrap_send($e->statusCode, ['ok' => false, 'error' => ['code' => $e->errorCode,
            'message' => $e->statusCode >= 500 ? 'Test-Artikelverwaltung derzeit nicht verfügbar.' : $e->getMessage()]]);
    } catch (CarmajaCommerceException $e) {
        carmaja_bootstrap_send($e->httpStatus, ['ok' => false, 'error' => ['code' => $e->errorCode, 'message' => 'Bestandsvorgang abgelehnt. Bitte aktuellen Stand laden.']]);
    } catch (Throwable) {
        carmaja_bootstrap_send(503, ['ok' => false, 'error' => ['code' => 'product_browser_unavailable', 'message' => 'Test-Artikelverwaltung derzeit nicht verfügbar.']]);
    }
}
