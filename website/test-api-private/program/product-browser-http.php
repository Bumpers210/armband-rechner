<?php
declare(strict_types=1);
require_once __DIR__ . '/product-browser.php';

/** Pure request gate; no user files, sessions, database or provider access. */
function carmaja_product_browser_production_request(array $config, array $server, array $query): array
{
    if (($config['environment'] ?? null) !== 'production' || ($config['publishTarget'] ?? null) !== 'production'
        || ($config['productBrowserProductionEnabled'] ?? null) !== true) {
        throw new CarmajaApiException(503, 'Artikelverwaltung nicht freigegeben.', [], 'product_browser_disabled');
    }
    if (($server['HTTPS'] ?? '') !== 'on' && (int) ($server['SERVER_PORT'] ?? 0) !== 443) {
        throw new CarmajaApiException(403, 'HTTPS erforderlich.', [], 'https_required');
    }
    // Do not trust forwarded host/proto headers or reflect an arbitrary Origin.
    if (($server['HTTP_HOST'] ?? '') !== 'api.carmaja-perlen.de'
        || ($server['HTTP_ORIGIN'] ?? '') !== 'https://www.carmaja-perlen.de'
        || isset($server['HTTP_AUTHORIZATION'])) {
        throw new CarmajaApiException(403, 'Anfrage nicht erlaubt.', [], 'origin_invalid');
    }
    $method = $server['REQUEST_METHOD'] ?? '';
    if (!in_array($method, ['GET', 'POST', 'PUT', 'OPTIONS'], true)) {
        throw new CarmajaApiException(405, 'Methode nicht erlaubt.', [], 'method_invalid');
    }
    if ($method === 'OPTIONS') {
        $headers = array_filter(array_map('trim', explode(',', strtolower((string) ($server['HTTP_ACCESS_CONTROL_REQUEST_HEADERS'] ?? '')))));
        if (!in_array($server['HTTP_ACCESS_CONTROL_REQUEST_METHOD'] ?? '', ['GET','POST','PUT'], true)
            || array_diff($headers, ['content-type','x-csrf-token','idempotency-key']) !== []) {
            throw new CarmajaApiException(403, 'Vorabpruefung abgelehnt.', [], 'preflight_invalid');
        }
    }
    $route = $query['route'] ?? '';
    if (!is_string($route) || preg_match('#^/(?:session|login|logout|price-list|products(?:/[A-Za-z0-9-]+(?:/(?:images(?:/[A-Za-z0-9-]+)?|publish|archive|restore|inventory|initial-inventory))?)?)$#D', $route) !== 1) {
        throw new CarmajaApiException(404, 'Endpunkt fehlt.', [], 'endpoint_not_found');
    }
    $multipart = $method === 'POST' && str_ends_with($route, '/images');
    if (in_array($method, ['POST','PUT'], true)) {
        $length = $server['CONTENT_LENGTH'] ?? '0';
        if (!is_scalar($length) || !ctype_digit((string) $length) || (int) $length > ($multipart ? 6 * 1048576 : 65536)) {
            throw new CarmajaApiException(413, 'Anfrage zu gross.', [], 'request_too_large');
        }
        $type = strtolower(trim(explode(';', (string) ($server['CONTENT_TYPE'] ?? ''))[0]));
        if ($type !== ($multipart ? 'multipart/form-data' : 'application/json')) {
            throw new CarmajaApiException(415, 'Inhaltstyp nicht erlaubt.', [], 'content_type_invalid');
        }
    }
    return ['method'=>$method, 'route'=>$route, 'multipart'=>$multipart];
}

function carmaja_product_browser_json(string $raw): array
{
    if (strlen($raw) > 65536) throw new CarmajaApiException(413, 'Anfrage zu gross.', [], 'request_too_large');
    try { $body = json_decode($raw, true, 32, JSON_THROW_ON_ERROR); }
    catch (Throwable) { throw new CarmajaApiException(400, 'JSON ungueltig.', [], 'json_invalid'); }
    if (!is_array($body) || ($body !== [] && array_is_list($body)) || !str_starts_with(ltrim($raw), '{')) {
        throw new CarmajaApiException(400, 'Objekt erwartet.', [], 'json_invalid');
    }
    return $body;
}

function carmaja_product_browser_production_http(array $config): never
{
    ini_set('display_errors', '0'); ini_set('log_errors', '0');
    header('Cache-Control: no-store'); header('Vary: Origin');
    header('Content-Type: application/json; charset=utf-8');
    header('X-Content-Type-Options: nosniff'); header('X-Robots-Tag: noindex, nofollow');
    try {
        $request = carmaja_product_browser_production_request($config, $_SERVER, $_GET);
        header('Access-Control-Allow-Origin: https://www.carmaja-perlen.de');
        header('Access-Control-Allow-Credentials: true');
        if ($request['method'] === 'OPTIONS') {
            header('Access-Control-Allow-Methods: GET, POST, PUT');
            header('Access-Control-Allow-Headers: Content-Type, X-CSRF-Token, Idempotency-Key');
            http_response_code(204); exit;
        }
        $browser = new CarmajaProductBrowser($config, static function (string $id, ?array $body): void {
            // The shared V5 service owns ID, version, field and publication validation.
            carmaja_api_validate_draft_id($id);
        });
        $method = $request['method']; $route = $request['route']; $body = [];
        if ($method !== 'GET') {
            // The packaged bootstrap and low-level product locks also enforce maintenance.
            // Fail closed if an incomplete/private package omitted the guard.
            if (!in_array($route, ['/login','/logout'], true)) {
                if (!function_exists('carmaja_product_maintenance_assert')) throw new RuntimeException('maintenance_guard_missing');
                carmaja_product_maintenance_assert('product');
            }
            $body = $request['multipart'] ? $_POST : carmaja_product_browser_json((string) file_get_contents('php://input', false, null, 0, 65537));
        }
        $cookie = $_COOKIE[$browser->cookieName()] ?? '';
        if (!is_string($cookie)) throw new CarmajaApiException(401, 'Bitte anmelden.', [], 'authentication_required');
        $options = ['expires'=>time() + CarmajaProductBrowser::SESSION_SECONDS,
            'path'=>CarmajaProductBrowser::COOKIE_PATH, 'secure'=>true, 'httponly'=>true, 'samesite'=>'Strict'];
        if ($route === '/login' && $method === 'POST') {
            $login = $browser->login($body, time());
            if (preg_match('/^[a-f0-9]{64}$/D', $cookie) === 1) $browser->logout($cookie);
            setcookie($browser->cookieName(), $login['cookie'], $options); unset($login['cookie']);
            carmaja_bootstrap_send(200, ['ok'=>true, 'data'=>$login]);
        }
        $actor = $browser->authenticate($cookie, $_SERVER['HTTP_X_CSRF_TOKEN'] ?? null, $method !== 'GET', time());
        if ($route === '/session' && $method === 'GET') {
            carmaja_bootstrap_send(200, ['ok'=>true, 'data'=>['csrfToken'=>$actor['csrfToken'], 'expiresAt'=>$actor['expiresAt']]]);
        }
        if ($route === '/logout' && $method === 'POST') {
            $browser->logout($cookie); setcookie($browser->cookieName(), '', [...$options,'expires'=>1]);
            carmaja_bootstrap_send(200, ['ok'=>true, 'data'=>['loggedOut'=>true]]);
        }
        $result = $browser->dispatch($method, explode('/', trim($route, '/')), $body, $actor, $_SERVER['HTTP_IDEMPOTENCY_KEY'] ?? null);
        if (isset($result['imageFile'])) carmaja_bootstrap_send_product_image($result['imageFile']);
        carmaja_bootstrap_send(200, ['ok'=>true, 'data'=>$result]);
    } catch (CarmajaApiException $e) {
        carmaja_bootstrap_send($e->statusCode, ['ok'=>false, 'error'=>['code'=>$e->errorCode,
            'message'=>$e->statusCode >= 500 ? 'Artikelverwaltung derzeit gesperrt oder nicht verfuegbar.' : $e->getMessage()]]);
    } catch (CarmajaCommerceException $e) {
        carmaja_bootstrap_send($e->httpStatus, ['ok'=>false, 'error'=>['code'=>$e->errorCode, 'message'=>'Bestandsvorgang abgelehnt. Bitte aktuellen Stand laden.']]);
    } catch (Throwable) {
        carmaja_bootstrap_send(503, ['ok'=>false, 'error'=>['code'=>'product_browser_unavailable', 'message'=>'Artikelverwaltung derzeit nicht verfuegbar.']]);
    }
}
