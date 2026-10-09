<?php

declare(strict_types=1);

require_once __DIR__ . '/shop-public.php';
require_once __DIR__ . '/shop-checkout.php';
require_once __DIR__ . '/cart-commerce.php';

function carmaja_cart_rate_owner(mixed $raw, string $ip): string
{
    // A first visit has no session cookie yet. Rate limiting must not reject
    // that visit before GET /context can create the anonymous session.
    return is_string($raw) && strlen($raw) >= 32 && strlen($raw) <= 128
        ? carmaja_shop_token_hash($raw) : hash('sha256', 'anonymous|' . $ip);
}

function carmaja_cart_json_body(): array
{
    if (strtolower(trim(explode(';', (string) ($_SERVER['CONTENT_TYPE'] ?? ''))[0])) !== 'application/json') {
        throw new CarmajaCommerceException('content_type_invalid', 'JSON erforderlich.', 415);
    }
    $raw = file_get_contents('php://input', false, null, 0, 65537);
    if (!is_string($raw) || strlen($raw) > 65536) {
        throw new CarmajaCommerceException('request_too_large', 'Anfrage zu groß.', 413);
    }
    $body = json_decode($raw, true, 16);
    if (!is_array($body) || array_is_list($body)) {
        throw new CarmajaCommerceException('request_invalid', 'JSON-Objekt erforderlich.', 422);
    }
    return $body;
}

function carmaja_cart_shipping(array $config): array
{
    if ((int) ($config['shippingAmountMinor'] ?? -1) !== 270) {
        throw new CarmajaCommerceException('shipping_contract_invalid', 'Versandkonfiguration unvollständig.', 503);
    }
    return ['shippingMethodId' => $config['shippingMethodId'], 'publicName' => $config['shippingPublicName'],
        'amountMinor' => 270, 'currency' => 'eur', 'country' => 'DE',
        'minBusinessDays' => (int) $config['shippingMinBusinessDays'], 'maxBusinessDays' => (int) $config['shippingMaxBusinessDays']];
}

/** Network calls occur only after reserveCart has committed all positions. */
function carmaja_cart_start(CarmajaCartCommerce $commerce, CarmajaStripeGateway $stripe, array $config,
    string $owner, array $body, string $key): array
{
    $reservation = $commerce->reserveCart($owner, $body, $key);
    $checkout = $reservation['checkout'];
    $id = $checkout['checkout_id'];
    if ($reservation['reused']) {
        $existing = $commerce->findCheckoutByIdempotency($key);
        if (!is_string($existing['stripe_checkout_session_id'] ?? null)) {
            throw new CarmajaCommerceException('checkout_in_progress', 'Zahlung wird bereits geprüft.', 409);
        }
        $session = $stripe->retrieveCheckoutSession($existing['stripe_checkout_session_id']);
        if (($session['status'] ?? '') !== 'open' || !is_string($session['url'] ?? null)) {
            throw new CarmajaCommerceException('checkout_not_open', 'Bitte den Zahlungsstatus prüfen.', 409);
        }
        return ['checkoutId' => $id, 'url' => $session['url'], 'reused' => true];
    }
    try {
        $session = $stripe->createCheckoutSession($reservation['snapshot'], $config['stripeSuccessUrl'],
            $config['stripeCancelUrl'], $key, strtotime($checkout['expires_at'] . ' UTC'));
        $commerce->recordStripeCreationOutcome($id, 'created', $session['id']);
        return ['checkoutId' => $id, 'url' => $session['url'], 'reused' => false];
    } catch (Throwable $error) {
        $definite = $error instanceof CarmajaStripeException && $error->errorCode === 'stripe_session_create_failed';
        $commerce->recordStripeCreationOutcome($id, $definite ? 'failed' : 'unknown');
        throw new CarmajaCommerceException($definite ? 'checkout_failed' : 'checkout_manual_review',
            $definite ? 'Zahlung konnte nicht gestartet werden.' : 'Zahlung wird geprüft. Bitte nicht erneut bestellen.', 503);
    }
}

function carmaja_cart_route(string $method, array $segments): never
{
    $config = carmaja_bootstrap_load_config();
    carmaja_shop_apply_cors($config, $_SERVER['HTTP_ORIGIN'] ?? null);
    if (carmaja_shop_origin($_SERVER['HTTP_ORIGIN'] ?? null) === carmaja_shop_origin($config['shopWebsiteOrigin'] ?? null)) {
        header('Access-Control-Allow-Methods: GET, POST, PUT, OPTIONS');
    }
    carmaja_shop_set_no_store();
    carmaja_shop_require_origin($config);
    if ($method === 'OPTIONS') { http_response_code(204); exit; }
    if (($config['inventoryCartEnabled'] ?? false) !== true) {
        throw new CarmajaCommerceException('cart_not_enabled', 'Der Warenkorb ist noch nicht freigegeben.', 503);
    }
    $commerce = carmaja_bootstrap_commerce($config);
    if (!$commerce instanceof CarmajaCartCommerce) { throw new LogicException('Cart adapter missing'); }
    $raw = $_COOKIE['__Host-cmj_shop_session'] ?? '';
    $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
    if (!$commerce->allowCartRequest(carmaja_cart_rate_owner($raw, $ip), $ip)) {
        throw new CarmajaCommerceException('shop_rate_limited', 'Bitte kurz warten und erneut versuchen.', 429);
    }
    if ($method === 'GET' && $segments === ['context']) {
        if (!is_string($raw) || strlen($raw) < 32 || strlen($raw) > 128) { $raw = carmaja_shop_token(); }
        $context = carmaja_shop_context($commerce, $raw);
        unset($context['sessionHash']);
        carmaja_shop_cookie('__Host-cmj_shop_session', $raw, time() + CARMAJA_SHOP_SESSION_TTL_SECONDS);
        carmaja_bootstrap_send(200, ['ok' => true, 'context' => $context]);
    }
    if ($method === 'GET' && count($segments) === 2 && $segments[0] === 'products') {
        carmaja_bootstrap_send(200, ['ok' => true, 'product' => $commerce->loadLiveProduct($segments[1])]);
    }
    if (!is_string($raw) || strlen($raw) < 32 || strlen($raw) > 128) { throw new CarmajaCommerceException('shop_session_required', 'Shop-Sitzung erforderlich.', 403); }
    $owner = carmaja_shop_token_hash($raw);
    $session = $commerce->loadShopSession($owner);
    if ($session === null || strtotime($session['session_expires_at'] . ' UTC') < time()) {
        throw new CarmajaCommerceException('shop_session_expired', 'Shop-Sitzung ist abgelaufen.', 403);
    }
    if (in_array($method, ['POST','PUT'], true)) {
        if (strtotime($session['csrf_expires_at'] . ' UTC') < time()) {
            throw new CarmajaCommerceException('shop_session_expired', 'Bitte Shop-Kontext neu laden.', 403);
        }
        if (!isset($_SERVER['HTTP_ORIGIN'])) { throw new CarmajaCommerceException('cors_origin_required', 'Origin erforderlich.', 403); }
        carmaja_shop_verify_raw_context(carmaja_shop_require_header('X-CSRF-Token'), $session['csrf_hash']);
    }
    if ($segments === ['cart']) {
        if ($method === 'PUT') {
            carmaja_bootstrap_send(200, ['ok' => true, 'cart' => $commerce->replaceCart($owner, carmaja_cart_json_body(), carmaja_shop_require_header('Idempotency-Key'))]);
        }
        if ($method === 'GET') {
            $cart = $commerce->readCart($owner);
            if ($cart['canCheckout']) { $cart = $commerce->quoteCart($owner, carmaja_cart_shipping($config), $config['activeLegalBundleId']); }
            carmaja_bootstrap_send(200, ['ok' => true, 'cart' => $cart]);
        }
    }
    if ($method === 'POST' && $segments === ['checkouts']) {
        $key = carmaja_shop_require_header('Idempotency-Key');
        $body = carmaja_cart_json_body();
        $bucket = carmaja_shop_rate_key($owner, (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
        $ipBucket = hash('sha256', 'ip|' . (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
        if ($commerce->findCheckoutByIdempotency($key) === null
            && (!$commerce->reserveShopAttempt($bucket) || !$commerce->reserveShopAttempt($ipBucket))) {
            throw new CarmajaCommerceException('checkout_rate_limited', 'Zu viele unbezahlte Versuche.', 429);
        }
        $result = carmaja_cart_start($commerce, carmaja_bootstrap_stripe($config), $config, $owner, $body, $key);
        $token = carmaja_shop_token();
        $commerce->issueCartToken($result['checkoutId'], carmaja_shop_token_hash($token), $owner, $bucket, $ipBucket);
        carmaja_shop_cookie('__Host-cmj_checkout', $token, time() + CARMAJA_SHOP_CHECKOUT_TOKEN_TTL_SECONDS);
        carmaja_bootstrap_send(201, ['ok' => true, 'checkout' => $result]);
    }
    if ($method === 'GET' && count($segments) === 3 && $segments[0] === 'checkouts' && $segments[2] === 'status') {
        $token = $commerce->loadCheckoutToken(carmaja_shop_token_hash((string) ($_COOKIE['__Host-cmj_checkout'] ?? '')));
        if ($token === null || $token['checkout_id'] !== $segments[1] || $token['session_hash'] !== $owner
            || strtotime($token['expires_at'] . ' UTC') < time()) {
            throw new CarmajaCommerceException('checkout_token_invalid', 'Checkout-Berechtigung ungültig.', 403);
        }
        carmaja_bootstrap_send(200, ['ok' => true, 'checkout' => $commerce->loadCheckoutStatus($segments[1])]);
    }
    if ($method === 'POST' && $segments === ['withdrawals','preview']) {
        $body = carmaja_cart_json_body();
        $name = trim((string) ($body['name'] ?? ''));
        $number = trim((string) ($body['orderNumber'] ?? ''));
        $email = trim((string) ($body['email'] ?? ''));
        if ($name === '' || $number === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false
            || strlen($name) > 200 || strlen($number) > 64 || strlen($email) > 254) {
            throw new CarmajaCommerceException('withdrawal_identification_invalid', 'Identifikation unvollständig.', 422);
        }
        $match = $commerce->findOrderForWithdrawal($number, $name, $email);
        $id = carmaja_commerce_new_id(); $token = carmaja_shop_token();
        $commerce->createWithdrawalRequest($id, $match['orderId'], carmaja_shop_token_hash($token), $match['matchStatus'],
            ['name' => $name, 'orderNumber' => $number, 'email' => $email, 'sessionHash' => $owner]);
        carmaja_bootstrap_send(200, ['ok' => true, 'withdrawal' => ['withdrawalId' => $id, 'confirmationToken' => $token,
            'matchStatus' => $match['matchStatus'], 'items' => $match['orderId'] === null ? [] : $commerce->orderPositions($match['orderId'])]]);
    }
    if ($method === 'POST' && $segments === ['withdrawals','confirm']) {
        $body = carmaja_cart_json_body();
        if (array_diff(array_keys($body), ['withdrawalId','confirmationToken','items','details']) !== []
            || !is_array($body['items'] ?? null) || count($body['items']) > 100 || !is_string($body['details'] ?? null)
            || strlen($body['details']) > 4000 || !is_string($body['withdrawalId'] ?? null) || !is_string($body['confirmationToken'] ?? null)) {
            throw new CarmajaCommerceException('withdrawal_confirmation_invalid', 'Erklärung ungültig.', 422);
        }
        $result = $commerce->confirmSelectedWithdrawal($body['withdrawalId'], carmaja_shop_token_hash($body['confirmationToken']), $body['items'], $body['details'], $owner);
        carmaja_bootstrap_send(200, ['ok' => true, 'withdrawal' => ['state' => $result['state'], 'receivedAt' => gmdate(DATE_ATOM)]]);
    }
    throw new CarmajaCommerceException('endpoint_not_found', 'Endpunkt fehlt.', 404);
}
