<?php
declare(strict_types=1);

// Temporary test-site adapter only. Never loads an existing runtime or secrets.
require_once __DIR__ . '/cart-stripe-sandbox.php';
require_once dirname(__DIR__) . '/test-api-private/program/ap5-worker.php';
define('CARMAJA_CART_BREVO_SETUP_NO_RUN', true);
require_once __DIR__ . '/install-cart-brevo-test.php';

function cart_site_rewrite(string $original, string $stage): string
{
    cart_probe_assert(preg_match('/^cart-e2e-[A-Za-z0-9-]+$/D', $stage) === 1
        && substr_count($original, 'RewriteEngine On') === 1
        && !str_contains($original, '# CART-SITE-E2E'), 'routing_baseline_invalid');
    return str_replace('RewriteEngine On', "RewriteEngine On\n# CART-SITE-E2E\n"
        . 'RewriteRule ^shop/v3(?:/.*)?$ ' . $stage . "/index.php [QSA,L]\n# END CART-SITE-E2E", $original);
}

function cart_site_fingerprint(): array
{
    $root = '/home/www/carmaja-private-test'; $result = [];
    foreach (['products', 'drafts', 'uploads', 'sku-counter'] as $name) {
        $base = $root . '/' . $name;
        cart_probe_assert(is_dir($base) && realpath($base) === $base && !is_link($base), 'existing_products_path_unsafe');
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            cart_probe_assert(!$file->isLink(), 'existing_products_symlink');
            if ($file->isFile()) { $result[substr($file->getPathname(), strlen($root) + 1)] = hash_file('sha256', $file->getPathname()); }
        }
    }
    foreach (['config/runtime-config.php', 'program/bootstrap.php', '../carmaja-test-api/index.php'] as $name) {
        $path = $root . '/' . $name;
        cart_probe_assert(is_file($path) && !is_link($path), 'existing_api_baseline_missing');
        $result[$name] = hash_file('sha256', $path);
    }
    ksort($result, SORT_STRING); return $result;
}

function cart_site_atomic(string $path, string $contents, int $mode): void
{
    cart_probe_assert(!is_link($path) && realpath(dirname($path)) === dirname($path), 'atomic_path_unsafe');
    $temporary = tempnam(dirname($path), '.cart-site-');
    cart_probe_assert(is_string($temporary), 'atomic_temp_failed');
    try {
        cart_probe_assert(chmod($temporary, $mode)
            && file_put_contents($temporary, $contents, LOCK_EX) === strlen($contents)
            && rename($temporary, $path), 'atomic_write_failed');
    } finally { if (is_file($temporary)) { unlink($temporary); } }
}

function cart_site_init(): array
{
    $paths = cart_sandbox_paths();
    cart_probe_assert(!file_exists($paths['root'] . '/site-owner.json'), 'site_already_initialized');
    $brevo = cart_sandbox_read('/home/www/carmaja-private-test/shop-cart-brevo-test.json');
    cart_probe_assert(($brevo['testOnly'] ?? false) === true, 'brevo_test_only_required');
    unset($brevo['testOnly']); $brevo = cart_brevo_setup_values($brevo);
    $bundlePath = $paths['root'] . '/site-legal.json';
    cart_probe_assert(is_file($bundlePath) && !is_link($bundlePath), 'test_legal_missing');
    $bundle = json_decode((string) file_get_contents($bundlePath), true, 16, JSON_THROW_ON_ERROR);
    cart_probe_assert($bundle['id'] === 'cmj-test-legal-2026-10-25-v4' && $bundle['environment'] === 'test'
        && $bundle['status'] === 'test_only' && preg_match('/^[a-f0-9]{64}$/D', $bundle['contentHash']) === 1,
        'test_legal_invalid');
    $api = '/home/www/carmaja-test-api/.htaccess';
    cart_probe_assert(realpath($api) === $api && !is_link($api), 'routing_path_unsafe');
    $original = (string) file_get_contents($api);
    $bound = cart_site_rewrite($original, basename($paths['root']));
    $backup = $paths['root'] . '/api-routing-before.txt';
    cart_probe_assert(!file_exists($backup), 'routing_backup_exists');
    cart_site_atomic($backup, $original, 0600);
    cart_sandbox_write($paths['root'] . '/site-owner.json', ['testOnly' => true,
        'protected' => cart_site_fingerprint(), 'beforeRouting' => hash('sha256', $original),
        'boundRouting' => hash('sha256', $bound), 'deadline' => time() + 86400]);
    $initialized = cart_sandbox_init();
    $targets = cart_sandbox_targets(); $pdo = cart_probe_connect($targets['source'], $targets);
    $pdo->prepare('UPDATE legal_bundles SET legal_bundle_id=?,terms_version=?,privacy_version=?,withdrawal_version=?,shipping_version=?,merchant_version=?,bundle_hash=? WHERE legal_bundle_id=?')
        ->execute([$bundle['id'], $bundle['version'], $bundle['version'], $bundle['version'], $bundle['version'],
            $bundle['version'], $bundle['contentHash'], CART_SANDBOX_LEGAL_ID]);
    $config = require $paths['runtime'];
    $config['activeLegalBundleId'] = $bundle['id'];
    foreach (['brevoApiKey', 'brevoSenderEmail', 'brevoSenderName'] as $key) { $config[$key] = $brevo[$key]; }
    $config['brevoOperatorEmail'] = $brevo['testRecipientEmail'];
    cart_site_atomic($paths['runtime'], "<?php\nreturn " . var_export($config, true) . ";\n", 0600);
    cart_sandbox_active();
    $entry = "<?php\nini_set('display_errors','0');ini_set('log_errors','0');\n"
        . '$request=$_SERVER[\'REQUEST_URI\']??\'\';' . "\n"
        . 'if(str_starts_with($request,\'/shop/v3/\')){$_SERVER[\'REQUEST_URI\']='
        . var_export('/' . basename($paths['root']) . '/index.php', true) . '.$request;}' . "\n"
        . 'require ' . var_export(__DIR__ . '/cart-stripe-sandbox.php', true) . ";\ncart_sandbox_http();\n";
    cart_site_atomic($paths['public'] . '/index.php', $entry, 0644);
    cart_probe_assert(cart_site_fingerprint() === cart_sandbox_read($paths['root'] . '/site-owner.json')['protected'], 'protected_data_changed');
    cart_probe_assert(hash_equals(hash('sha256', $original), (string) hash_file('sha256', $api)), 'routing_changed_concurrently');
    cart_site_atomic($api, $bound, 0644);
    return [...$initialized, 'siteRouting' => 'shop_v3_only', 'legalSnapshot' => 'matches_test_site',
        'legalApproval' => 'test_only_not_legal_approval', 'protectedDataUnchanged' => true];
}

function cart_site_request(string $path, string $method = 'GET', ?array $body = null, array $headers = [],
    string $origin = 'https://test.carmaja-perlen.de'): array
{
    cart_probe_assert(str_starts_with($path, '/shop/v3/') || str_starts_with($path, '/admin/v1/'), 'test_request_path_invalid');
    $paths = cart_sandbox_paths(); $jar = $paths['root'] . '/site-http-cookies.txt';
    if (!file_exists($jar)) { cart_probe_assert(touch($jar) && chmod($jar, 0600), 'cookie_jar_failed'); }
    cart_probe_assert(!is_link($jar) && (fileperms($jar) & 0777) === 0600, 'cookie_jar_insecure');
    $received = []; $ch = curl_init(str_starts_with($path, '/admin/v1/') ? $paths['baseUrl'] . $path : 'https://test-api.carmaja-perlen.de' . $path);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_TIMEOUT => 20, CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2, CURLOPT_COOKIEFILE => $jar, CURLOPT_COOKIEJAR => $jar,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => ['Origin: ' . $origin, 'Content-Type: application/json', ...$headers],
        CURLOPT_HEADERFUNCTION => static function ($handle, string $line) use (&$received): int {
            if (str_contains($line, ':')) { [$key, $value] = explode(':', $line, 2); $received[strtolower(trim($key))] = trim($value); }
            return strlen($line);
        }]);
    if ($body !== null) { curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body, JSON_THROW_ON_ERROR)); }
    $raw = curl_exec($ch); $status = curl_getinfo($ch, CURLINFO_RESPONSE_CODE); curl_close($ch);
    cart_probe_assert(is_string($raw), 'http_transport_failed');
    return ['status' => $status, 'headers' => $received, 'json' => $raw === '' ? [] : json_decode($raw, true)];
}

function cart_site_security(): array
{
    cart_sandbox_active(); $paths = cart_sandbox_paths();
    $owner = cart_sandbox_read($paths['root'] . '/site-owner.json');
    cart_probe_assert(cart_site_fingerprint() === $owner['protected'], 'protected_data_changed');
    $first = cart_site_request('/shop/v3/context'); $second = cart_site_request('/shop/v3/context');
    cart_probe_assert($first['status'] === 200 && $second['status'] === 200, 'contexts_failed');
    $cart = cart_site_request('/shop/v3/cart')['json']['cart'];
    $body = ['expectedCartVersion' => $cart['cartVersion'], 'items' => [['productId' => CART_SANDBOX_PRODUCT_IDS[0], 'quantity' => 1]]];
    $headers = ['X-CSRF-Token: ' . $first['json']['context']['csrfToken'], 'Idempotency-Key: security-' . bin2hex(random_bytes(16))];
    $changed = cart_site_request('/shop/v3/cart', 'PUT', $body, $headers);
    cart_probe_assert($changed['status'] === 200, 'old_tab_token_rejected');
    cart_probe_assert(cart_site_request('/shop/v3/cart', 'PUT', $body, $headers)['json'] === $changed['json'], 'cart_replay_changed');
    $other = ['X-CSRF-Token: ' . $second['json']['context']['csrfToken'], 'Idempotency-Key: stale-' . bin2hex(random_bytes(16))];
    cart_probe_assert(cart_site_request('/shop/v3/cart', 'PUT', $body, $other)['status'] === 409, 'stale_tab_not_conflict');
    $body['expectedCartVersion'] = $changed['json']['cart']['cartVersion'];
    $body['items'][0]['quantity'] = 2;
    cart_probe_assert(cart_site_request('/shop/v3/cart', 'PUT', $body, $other)['status'] === 200, 'new_tab_token_rejected');
    $body['expectedCartVersion']++;
    $wrong = ['X-CSRF-Token: ' . carmaja_cart_csrf_token(carmaja_shop_token()), 'Idempotency-Key: wrong-' . bin2hex(random_bytes(16))];
    cart_probe_assert(cart_site_request('/shop/v3/cart', 'PUT', $body, $wrong)['status'] === 403, 'foreign_csrf_accepted');
    cart_probe_assert(cart_site_request('/shop/v3/cart', 'PUT', $body, [], 'https://invalid.example')['status'] === 403, 'foreign_origin_accepted');
    cart_probe_assert(cart_site_request('/shop/v3/cart', 'PUT', $body, $headers, '')['status'] === 403, 'missing_origin_accepted');
    $headers[1] = 'Idempotency-Key: invalid-' . bin2hex(random_bytes(16));
    foreach ([-1, 1.5] as $quantity) {
        $body['items'][0]['quantity'] = $quantity;
        cart_probe_assert(cart_site_request('/shop/v3/cart', 'PUT', $body, $headers)['status'] === 422, 'invalid_quantity_accepted');
    }
    $body['items'][0]['quantity'] = 1; $body['priceMinor'] = 1;
    cart_probe_assert(cart_site_request('/shop/v3/cart', 'PUT', $body, $headers)['status'] === 422, 'price_tampering_accepted');
    $noStore = cart_site_request('/shop/v3/cart');
    cart_probe_assert(str_contains($noStore['headers']['cache-control'] ?? '', 'no-store'), 'cart_cacheable');
    $cart = $noStore['json']['cart'];
    cart_probe_assert(cart_site_request('/shop/v3/checkouts', 'POST', ['expectedCartVersion' => $cart['cartVersion'],
        'checkoutContextToken' => carmaja_shop_token()], ['X-CSRF-Token: ' . $second['json']['context']['csrfToken'],
            'Idempotency-Key: quote-' . bin2hex(random_bytes(16))])['status'] === 409, 'foreign_quote_accepted');
    $empty = cart_site_request('/shop/v3/cart', 'PUT', ['expectedCartVersion' => $cart['cartVersion'], 'items' => []],
        ['X-CSRF-Token: ' . $first['json']['context']['csrfToken'], 'Idempotency-Key: clear-' . bin2hex(random_bytes(16))]);
    cart_probe_assert($empty['status'] === 200, 'security_cart_cleanup_failed');
    $targets = cart_sandbox_targets(); $pdo = cart_probe_connect($targets['source'], $targets);
    $cookie = ''; foreach (file($paths['root'] . '/site-http-cookies.txt') as $line) {
        $fields = explode("\t", trim($line));
        if (count($fields) === 7 && $fields[5] === '__Host-cmj_shop_session') { $cookie = $fields[6]; }
    }
    cart_probe_assert($cookie !== '', 'test_cookie_missing'); $session = carmaja_shop_token_hash($cookie);
    $expired = carmaja_cart_csrf_token($cookie, time() - 7201);
    cart_probe_assert(cart_site_request('/shop/v3/cart', 'PUT', ['expectedCartVersion' => $empty['json']['cart']['cartVersion'], 'items' => []],
        ['X-CSRF-Token: ' . $expired, 'Idempotency-Key: expired-' . bin2hex(random_bytes(16))])['status'] === 403, 'expired_csrf_accepted');
    $query = $pdo->prepare('SELECT session_expires_at FROM shop_sessions WHERE session_hash=?'); $query->execute([$session]); $expiry = $query->fetchColumn();
    try {
        $pdo->prepare('UPDATE shop_sessions SET session_expires_at=UTC_TIMESTAMP()-INTERVAL 1 SECOND WHERE session_hash=?')->execute([$session]);
        cart_probe_assert(cart_site_request('/shop/v3/cart')['status'] === 403, 'expired_session_accepted');
    } finally { $pdo->prepare('UPDATE shop_sessions SET session_expires_at=? WHERE session_hash=?')->execute([$expiry, $session]); }
    $limit = false;
    for ($i = 0; $i < 61; $i++) { if (cart_site_request('/shop/v3/cart')['status'] === 429) { $limit = true; break; } }
    cart_probe_assert($limit, 'rate_limit_missing');
    // Clear only this probe's rate bucket so the following isolated test is unaffected.
    $pdo->prepare('DELETE FROM cart_rate_limits WHERE bucket_hash=?')->execute([hash('sha256', 'session|' . $session)]);
    cart_probe_assert((int) $pdo->query('SELECT COUNT(*) FROM orders')->fetchColumn() === 1
        && (int) $pdo->query('SELECT COUNT(*) FROM reservations WHERE blocks_stock=1')->fetchColumn() === 0, 'security_created_payment');
    cart_probe_assert(cart_site_fingerprint() === $owner['protected'], 'protected_data_changed');
    return ['status' => 'passed', 'multiTab' => true, 'staleVersion409' => true, 'csrfBindingAndExpiry' => true,
        'origin' => true, 'invalidQuantities' => true, 'priceTampering' => true, 'quoteBinding' => true,
        'sessionExpiry' => true, 'rateLimit429' => true, 'noStore' => true, 'noNewPayment' => true];
}

function cart_site_withdrawal_admin(): array
{
    $config = cart_sandbox_active(); $paths = cart_sandbox_paths();
    $owner = cart_sandbox_read($paths['root'] . '/site-owner.json');
    cart_probe_assert(cart_site_fingerprint() === $owner['protected'], 'protected_data_changed');
    $targets = cart_sandbox_targets(); $pdo = cart_probe_connect($targets['source'], $targets);
    $order = $pdo->query('SELECT * FROM orders')->fetch(PDO::FETCH_ASSOC);
    cart_probe_assert(is_array($order) && (int) $pdo->query('SELECT COUNT(*) FROM orders')->fetchColumn() === 1, 'single_artificial_order_required');
    $recipient = cart_sandbox_read('/home/www/carmaja-private-test/shop-cart-brevo-test.json')['testRecipientEmail'];
    cart_probe_assert(hash_equals($recipient, $order['customer_email']), 'recipient_not_authorized');
    $context = cart_site_request('/shop/v3/context'); $csrf = ['X-CSRF-Token: ' . $context['json']['context']['csrfToken']];
    $mailCount = (int) $pdo->query('SELECT COUNT(*) FROM mail_outbox')->fetchColumn();
    $identity = ['name' => $order['customer_name'], 'email' => $recipient, 'orderNumber' => $order['order_number']];
    $preview = cart_site_request('/shop/v3/withdrawals/preview', 'POST', $identity, $csrf);
    cart_probe_assert($preview['status'] === 200 && $preview['json']['withdrawal']['matchStatus'] === 'matched'
        && count($preview['json']['withdrawal']['items']) === 2, 'withdrawal_preview_failed');
    cart_probe_assert((int) $pdo->query('SELECT COUNT(*) FROM mail_outbox')->fetchColumn() === $mailCount
        && (int) $pdo->query("SELECT COUNT(*) FROM withdrawal_requests WHERE state='submitted'")->fetchColumn() === 0, 'preview_submitted_declaration');
    $declaration = ['withdrawalId' => $preview['json']['withdrawal']['withdrawalId'],
        'confirmationToken' => $preview['json']['withdrawal']['confirmationToken'], 'items' => [['positionNo' => 1, 'quantity' => 1]], 'details' => 'Kuenstlicher Teilwiderruf.'];
    cart_probe_assert(cart_site_request('/shop/v3/withdrawals/confirm', 'POST', $declaration, $csrf)['status'] === 200, 'partial_withdrawal_failed');
    cart_probe_assert(cart_site_request('/shop/v3/withdrawals/confirm', 'POST', $declaration, $csrf)['status'] === 409, 'withdrawal_replay_accepted');
    $preview = cart_site_request('/shop/v3/withdrawals/preview', 'POST', $identity, $csrf);
    $declaration = ['withdrawalId' => $preview['json']['withdrawal']['withdrawalId'],
        'confirmationToken' => $preview['json']['withdrawal']['confirmationToken'], 'items' => [['positionNo' => 1, 'quantity' => 2]], 'details' => 'Kuenstliche Uebermenge.'];
    cart_probe_assert(cart_site_request('/shop/v3/withdrawals/confirm', 'POST', $declaration, $csrf)['status'] === 200, 'overclaim_discarded');
    $unmatched = cart_site_request('/shop/v3/withdrawals/preview', 'POST', [...$identity, 'orderNumber' => 'ARTIFICIAL-NOT-FOUND'], $csrf);
    cart_probe_assert($unmatched['status'] === 200 && $unmatched['json']['withdrawal']['matchStatus'] === 'manual_review', 'unmatched_preview_failed');
    $before = (int) $pdo->query('SELECT COUNT(*) FROM mail_outbox')->fetchColumn();
    cart_probe_assert(cart_site_request('/shop/v3/withdrawals/confirm', 'POST', ['withdrawalId' => $unmatched['json']['withdrawal']['withdrawalId'],
        'confirmationToken' => $unmatched['json']['withdrawal']['confirmationToken'], 'items' => [], 'details' => 'Kuenstliche nicht zuordenbare Erklaerung.'], $csrf)['status'] === 200, 'unmatched_confirm_failed');
    cart_probe_assert((int) $pdo->query('SELECT COUNT(*) FROM mail_outbox')->fetchColumn() === $before + 1
        && (int) $pdo->query('SELECT COUNT(*) FROM withdrawal_items')->fetchColumn() === 1
        && (int) $pdo->query("SELECT COUNT(*) FROM review_cases WHERE subject_type='withdrawal'")->fetchColumn() === 2, 'withdrawal_evidence_failed');
    $commerce = carmaja_bootstrap_commerce($config);
    cart_probe_assert($commerce->inventory('89000000-0000-4000-8000-000000000001')['onHand'] === 28
        && $commerce->inventory('89000000-0000-4000-8000-000000000002')['onHand'] === 29, 'withdrawal_restocked_inventory');
    cart_probe_assert(cart_site_request('/admin/v1/orders')['status'] === 401, 'admin_missing_auth_accepted');
    $username = 'cart-e2e-operator'; $password = bin2hex(random_bytes(24));
    $commerce->createAdminUser(carmaja_commerce_new_id(), $username, carmaja_shop_admin_hash_password($password));
    $login = cart_site_request('/admin/v1/login', 'POST', ['username' => $username, 'password' => $password]); unset($password);
    cart_probe_assert($login['status'] === 200, 'admin_login_failed');
    $detail = cart_site_request('/admin/v1/orders/' . $order['order_id']);
    cart_probe_assert($detail['status'] === 200 && count($detail['json']['order']['items']) === 2
        && $detail['json']['order']['totalMinor'] === 7770, 'admin_order_items_failed');
    cart_probe_assert(cart_site_request('/admin/v1/reviews')['status'] === 200, 'admin_reviews_failed');
    cart_probe_assert(cart_site_request('/admin/v1/logout', 'POST', [])['status'] === 403, 'admin_csrf_not_required');
    cart_probe_assert(cart_site_request('/admin/v1/logout', 'POST', [], ['X-CSRF-Token: ' . $login['json']['admin']['csrfToken']])['status'] === 200
        && cart_site_request('/admin/v1/orders')['status'] === 401, 'admin_logout_failed');
    cart_probe_assert(cart_site_fingerprint() === $owner['protected'], 'protected_data_changed');
    return ['status' => 'passed', 'twoStep' => true, 'partialQuantity' => true, 'replay409' => true,
        'overclaimAndUnmatchedReview' => true, 'noRestockOrRefund' => true, 'adminAuthAndCsrf' => true,
        'adminPositionsAndReviews' => true, 'logoutRevoked' => true, 'receiptMailsQueued' => 3];
}

/** Only the per-read live timestamp is volatile; all business fields stay compared. */
function cart_site_replay_response(array $response): array
{
    foreach ($response['cart']['items'] ?? [] as $index => $item) {
        if (is_array($item)) { unset($response['cart']['items'][$index]['responseAt']); }
    }
    return $response;
}

function cart_site_smoke(): array
{
    $config = cart_sandbox_active(); $paths = cart_sandbox_paths();
    $owner = cart_sandbox_read($paths['root'] . '/site-owner.json');
    cart_probe_assert(cart_site_fingerprint() === $owner['protected'], 'protected_data_changed');
    $preflight = cart_site_request('/shop/v3/cart', 'OPTIONS', null,
        ['Access-Control-Request-Method: PUT', 'Access-Control-Request-Headers: Content-Type,X-CSRF-Token,Idempotency-Key']);
    cart_probe_assert($preflight['status'] === 204
        && ($preflight['headers']['access-control-allow-origin'] ?? '') === $config['shopWebsiteOrigin']
        && ($preflight['headers']['access-control-allow-credentials'] ?? '') === 'true'
        && str_contains(strtolower($preflight['headers']['access-control-allow-headers'] ?? ''), 'idempotency-key'), 'root_cors_failed');
    cart_probe_assert(cart_site_request('/shop/v3/context', 'GET', null, [], 'https://invalid.example')['status'] === 403, 'root_wrong_origin_accepted');
    $context = cart_site_request('/shop/v3/context'); $cookie = strtolower($context['headers']['set-cookie'] ?? '');
    cart_probe_assert($context['status'] === 200 && ($context['json']['ok'] ?? false) === true
        && str_contains($context['headers']['cache-control'] ?? '', 'no-store')
        && str_starts_with($cookie, '__host-cmj_shop_session=') && str_contains($cookie, 'secure')
        && str_contains($cookie, 'httponly') && str_contains($cookie, 'samesite=lax') && str_contains($cookie, 'path=/'), 'root_context_failed');
    $csrf = $context['json']['context']['csrfToken'] ?? '';
    cart_probe_assert(is_string($csrf) && strlen($csrf) >= 32, 'root_csrf_missing');
    $live = cart_site_request('/shop/v3/products/' . CART_SANDBOX_PRODUCT_IDS[0]);
    cart_probe_assert($live['status'] === 200 && ($live['json']['product']['priceMinor'] ?? 0) === 2800
        && ($live['json']['product']['availableQuantity'] ?? 0) === 30
        && str_contains($live['headers']['cache-control'] ?? '', 'no-store'), 'root_live_failed');
    $current = cart_site_request('/shop/v3/cart');
    cart_probe_assert($current['status'] === 200 && empty($current['json']['cart']['items']), 'root_cart_not_empty');
    $body = ['expectedCartVersion' => $current['json']['cart']['cartVersion'], 'items' => [
        ['productId' => CART_SANDBOX_PRODUCT_IDS[0], 'quantity' => 2],
        ['productId' => CART_SANDBOX_PRODUCT_IDS[1], 'quantity' => 1]]];
    $key = 'site-smoke-' . bin2hex(random_bytes(16));
    cart_probe_assert(cart_site_request('/shop/v3/cart', 'PUT', $body,
        ['X-CSRF-Token: invalid', 'Idempotency-Key: ' . $key])['status'] === 403, 'root_bad_csrf_accepted');
    $headers = ['X-CSRF-Token: ' . $csrf, 'Idempotency-Key: ' . $key];
    $cart = cart_site_request('/shop/v3/cart', 'PUT', $body, $headers);
    if ($cart['status'] !== 200 || ($cart['json']['cart']['totalMinor'] ?? 0) !== 7770) {
        $code = $cart['json']['error']['code'] ?? null;
        echo json_encode(['probe' => 'root_cart', 'httpStatus' => $cart['status'],
            'errorCode' => is_string($code) && preg_match('/^[a-z_]{1,80}$/D', $code) ? $code : null,
            'expectedVersion' => $body['expectedCartVersion'],
            'totalMinor' => $cart['json']['cart']['totalMinor'] ?? null], JSON_THROW_ON_ERROR) . "\n";
    }
    cart_probe_assert($cart['status'] === 200 && ($cart['json']['cart']['totalMinor'] ?? 0) === 7770, 'root_cart_failed');
    $replay = cart_site_request('/shop/v3/cart', 'PUT', $body, $headers);
    cart_probe_assert($replay['status'] === 200
        && cart_site_replay_response($replay['json']) === cart_site_replay_response($cart['json']), 'root_replay_failed');
    cart_probe_assert(cart_site_request('/shop/v3/cart', 'PUT', $body,
        ['X-CSRF-Token: ' . $csrf, 'Idempotency-Key: stale-' . bin2hex(random_bytes(16))])['status'] === 409, 'root_stale_accepted');
    $quote = cart_site_request('/shop/v3/cart');
    cart_probe_assert($quote['status'] === 200 && ($quote['json']['cart']['totalMinor'] ?? 0) === 7770
        && is_string($quote['json']['cart']['checkoutContextToken'] ?? null), 'root_quote_failed');
    $empty = cart_site_request('/shop/v3/cart', 'PUT', ['expectedCartVersion' => $quote['json']['cart']['cartVersion'], 'items' => []],
        ['X-CSRF-Token: ' . $csrf, 'Idempotency-Key: empty-' . bin2hex(random_bytes(16))]);
    cart_probe_assert($empty['status'] === 200, 'root_cart_reset_failed');
    $commerce = carmaja_bootstrap_commerce($config);
    foreach (CART_SANDBOX_PRODUCT_IDS as $id) { cart_probe_assert($commerce->inventory($id)['reserved'] === 0, 'smoke_reserved_stock'); }
    return ['status' => 'passed', 'rootApi' => true, 'cors' => true, 'cookie' => true, 'csrf' => true,
        'noStore' => true, 'livePrice' => true, 'cartReplay' => true, 'staleVersion' => true,
        'shippingMinor' => 270, 'totalMinor' => 7770, 'noCheckoutOrPayment' => true, 'protectedDataUnchanged' => true];
}

function cart_site_fixture_product(int $index, int $width, int $height): array
{
    cart_probe_assert(in_array($index, [1, 2], true) && $width > 0 && $height > 0, 'fixture_input_invalid');
    $description = 'Kuenstliches Testprodukt. Keine echte Ware und kein Live-Verkauf.';
    $sku = 'CP-2099-900' . $index;
    return ['productModelVersion' => 4, 'productId' => '89000000-0000-4000-8000-00000000000' . $index,
        'productVersion' => 1, 'sku' => $sku, 'slug' => 'warenkorb-e2e-336033b5-' . $index,
        'title' => 'Kuenstlicher Warenkorb-E2E-Artikel ' . $index, 'description' => $description,
        'descriptionDocument' => ['version' => 1, 'blocks' => [['type' => 'paragraph', 'spans' => [[
            'text' => $description, 'bold' => false, 'italic' => false, 'font' => 'standard', 'size' => 'normal']]]]],
        'materials' => ['Testmaterial'], 'metalElements' => [], 'braceletSizeCm' => 18, 'pearlSizeMm' => 8,
        'priceMinor' => $index === 1 ? 2800 : 1900, 'currency' => 'eur', 'salesEnabled' => true,
        'noticePolicyId' => 'natural-stones-v1', 'notices' => CARMAJA_PRODUCT_NOTICES,
        'images' => [['imageId' => '89100000-0000-4000-8000-00000000000' . $index, 'fileName' => '01.jpg',
            'src' => '/images/products/' . $sku . '/01.jpg', 'alt' => 'Kuenstliches Testbild',
            'width' => $width, 'height' => $height, 'isMain' => true]], 'updatedAt' => '2026-10-11T08:00:00Z'];
}

function cart_site_fixtures(): array
{
    $config = cart_sandbox_active(); $paths = cart_sandbox_paths();
    $owner = cart_sandbox_read($paths['root'] . '/site-owner.json');
    cart_probe_assert(cart_site_fingerprint() === $owner['protected'], 'protected_data_changed');
    $image = $paths['root'] . '/fixture-image.jpg';
    cart_probe_assert(is_file($image) && !is_link($image), 'fixture_image_missing');
    $dimensions = getimagesize($image);
    cart_probe_assert(is_array($dimensions) && $dimensions[2] === IMAGETYPE_JPEG, 'fixture_image_invalid');
    $file = $paths['root'] . '/site-products.json';
    cart_probe_assert(!file_exists($file), 'fixtures_already_created');
    $commerce = carmaja_bootstrap_commerce($config); $products = [];
    for ($index = 1; $index <= 2; $index++) {
        $product = cart_site_fixture_product($index, $dimensions[0], $dimensions[1]);
        $product['sourceHash'] = carmaja_api_v3_source_hash([...$product, 'name' => $product['title'], 'careInstructions' => []]);
        $commerce->projectCollectionProduct([...$product, 'careInstructions' => []], 'publish',
            'site-fixture-' . $index, hash('sha256', json_encode($product, JSON_THROW_ON_ERROR)));
        $commerce->adjustInventory(['productId' => $product['productId'], 'targetOnHand' => 30,
            'expectedInventoryVersion' => 0, 'reason' => 'initial_count', 'correlationId' => 'site-fixture-' . $index,
            'idempotencyKey' => 'site-fixture-' . $index], 'artificial-operator');
        $products[] = $product;
    }
    cart_sandbox_write($file, ['version' => 4, 'products' => $products]);
    cart_probe_assert(cart_site_fingerprint() === $owner['protected'], 'protected_data_changed');
    return ['status' => 'fixtures_ready', 'testOnly' => true, 'products' => 2, 'model' => 4,
        'serverHash' => true, 'protectedDataUnchanged' => true];
}

function cart_site_fixture_path(string $relative): bool
{
    if ($relative === '' || str_contains($relative, '\\') || str_starts_with($relative, '/')
        || array_intersect(explode('/', $relative), ['', '.', '..']) !== []) { return false; }
    return preg_match('#^(?:_next/static/[A-Za-z0-9_.()\[\]/~-]+\.(?:js|css)|'
        . '_next/static/media/icon\.[A-Za-z0-9]+\.svg|'
        . 'armbaender/warenkorb-e2e-336033b5-[12]/[A-Za-z0-9_.$/-]+\.(?:html|txt)|'
        . 'images/products/CP-2099-900[12]/01\.jpg)$#D', $relative) === 1;
}

function cart_site_web_fingerprint(string $root): array
{
    cart_probe_assert($root === '/home/www/carmaja-test-site' && realpath($root) === $root && !is_link($root), 'test_site_path_unsafe');
    $result = []; $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $file) {
        cart_probe_assert(!$file->isLink(), 'test_site_symlink');
        if ($file->isFile()) { $result[substr($file->getPathname(), strlen($root) + 1)] = hash_file('sha256', $file->getPathname()); }
    }
    ksort($result, SORT_STRING); return $result;
}

function cart_site_install_fixtures(): array
{
    cart_sandbox_active(); $paths = cart_sandbox_paths(); $source = $paths['root'] . '/fixture-export';
    cart_probe_assert(is_dir($source) && realpath($source) === $source && !is_link($source), 'fixture_export_unsafe');
    $journal = $paths['root'] . '/site-fixture-files.json';
    cart_probe_assert(!file_exists($journal), 'fixture_install_already_started');
    $root = '/home/www/carmaja-test-site'; $before = cart_site_web_fingerprint($root); $files = [];
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $file) {
        cart_probe_assert(!$file->isLink(), 'fixture_export_symlink');
        if (!$file->isFile()) { continue; }
        $relative = substr($file->getPathname(), strlen($source) + 1);
        cart_probe_assert(cart_site_fixture_path($relative), 'fixture_file_not_allowed');
        $hash = (string) hash_file('sha256', $file->getPathname());
        if (array_key_exists($relative, $before)) {
            cart_probe_assert(hash_equals($before[$relative], $hash), 'existing_site_file_conflict');
        } else { $files[$relative] = $hash; }
    }
    cart_probe_assert(isset($files['armbaender/warenkorb-e2e-336033b5-1/index.html'])
        && isset($files['armbaender/warenkorb-e2e-336033b5-2/index.html']), 'fixture_pages_missing');
    ksort($files, SORT_STRING); $directories = [];
    foreach (array_keys($files) as $relative) {
        $parent = dirname($relative);
        while ($parent !== '.') {
            if (!file_exists($root . '/' . $parent)) { $directories[$parent] = true; }
            $parent = dirname($parent);
        }
    }
    cart_sandbox_write($journal, ['testOnly' => true, 'before' => $before, 'files' => $files,
        'directories' => array_keys($directories)]);
    foreach ($files as $relative => $hash) {
        $parent = $root;
        foreach (explode('/', dirname($relative)) as $segment) {
            if ($segment === '.') { continue; }
            $parent .= '/' . $segment;
            if (!file_exists($parent)) { cart_probe_assert(mkdir($parent, 0755) && chmod($parent, 0755), 'fixture_directory_failed'); }
            cart_probe_assert(is_dir($parent) && realpath($parent) === $parent && !is_link($parent), 'fixture_target_unsafe');
        }
        $target = $root . '/' . $relative;
        cart_probe_assert(!file_exists($target) && !is_link($target), 'fixture_target_exists');
        $temporary = tempnam($parent, '.cart-fixture-'); cart_probe_assert(is_string($temporary), 'fixture_temp_failed');
        try {
            cart_probe_assert(copy($source . '/' . $relative, $temporary) && chmod($temporary, 0644)
                && hash_equals($hash, (string) hash_file('sha256', $temporary)) && !file_exists($target)
                && rename($temporary, $target), 'fixture_activate_failed');
        } finally { if (is_file($temporary)) { unlink($temporary); } }
    }
    $after = cart_site_web_fingerprint($root);
    foreach ($files as $relative => $hash) { cart_probe_assert(($after[$relative] ?? '') === $hash, 'fixture_installed_hash_invalid'); unset($after[$relative]); }
    cart_probe_assert($before === $after, 'existing_test_site_changed');
    return ['status' => 'fixture_pages_ready', 'testOnly' => true, 'pages' => 2,
        'existingSiteFilesUnchanged' => true, 'cleanupJournalWritten' => true];
}

function cart_site_remove_fixtures(): array
{
    $paths = cart_sandbox_paths(); $journal = cart_sandbox_read($paths['root'] . '/site-fixture-files.json');
    cart_probe_assert(($journal['testOnly'] ?? false) === true, 'fixture_owner_invalid');
    $root = '/home/www/carmaja-test-site'; $current = cart_site_web_fingerprint($root);
    foreach ($journal['files'] as $relative => $hash) {
        cart_probe_assert(cart_site_fixture_path($relative), 'fixture_cleanup_path_invalid');
        if (isset($current[$relative])) { cart_probe_assert(hash_equals($hash, $current[$relative]), 'fixture_changed_cleanup_refused'); }
        unset($current[$relative]);
    }
    cart_probe_assert($current === $journal['before'], 'existing_site_changed_cleanup_refused');
    foreach ($journal['files'] as $relative => $hash) {
        $path = $root . '/' . $relative;
        if (file_exists($path)) { cart_probe_assert(!is_link($path) && is_file($path)
            && hash_equals($hash, (string) hash_file('sha256', $path)) && unlink($path), 'fixture_remove_failed'); }
    }
    $directories = $journal['directories']; usort($directories, static fn ($a, $b) => strlen($b) <=> strlen($a));
    foreach ($directories as $relative) {
        cart_probe_assert(cart_site_fixture_path($relative . '/cleanup.js')
            || cart_site_fixture_path($relative . '/cleanup.txt')
            || preg_match('#^images/products/CP-2099-900[12]$#D', $relative) === 1, 'fixture_cleanup_directory_invalid');
        $path = $root . '/' . $relative;
        if (is_dir($path)) { cart_probe_assert(realpath($path) === $path && !is_link($path)
            && count(scandir($path)) === 2 && rmdir($path), 'fixture_directory_remove_failed'); }
    }
    cart_probe_assert(cart_site_web_fingerprint($root) === $journal['before'], 'site_restore_mismatch');
    return ['status' => 'fixtures_removed', 'existingSiteFilesUnchanged' => true, 'temporaryPagesAbsent' => true];
}

function cart_site_worker(): array
{
    $paths = cart_sandbox_paths(); $config = cart_sandbox_active();
    $owner = cart_sandbox_read($paths['root'] . '/site-owner.json');
    cart_probe_assert(($owner['testOnly'] ?? false) === true && $owner['deadline'] > time(), 'site_stage_expired');
    $lock = fopen($paths['root'] . '/site-worker.lock', 'c');
    cart_probe_assert(is_resource($lock) && chmod($paths['root'] . '/site-worker.lock', 0600), 'worker_lock_failed');
    if (!flock($lock, LOCK_EX | LOCK_NB)) { fclose($lock); return ['status' => 'locked']; }
    $started = microtime(true);
    try {
        $commerce = carmaja_bootstrap_commerce($config);
        $payment = (new CarmajaAp3Worker($commerce, new CarmajaStripeGateway($config), $config))->run();
        $brevo = cart_sandbox_read('/home/www/carmaja-private-test/shop-cart-brevo-test.json');
        $guard = static function (array $request, string $key) use ($config, $brevo): array {
            cart_probe_assert($request['to'] === [['email' => $brevo['testRecipientEmail']]], 'mail_recipient_not_authorized');
            $ch = curl_init('https://api.brevo.com/v3/smtp/email');
            curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER => ['api-key: ' . $config['brevoApiKey'], 'Content-Type: application/json'],
                CURLOPT_POSTFIELDS => json_encode($request, JSON_THROW_ON_ERROR), CURLOPT_CONNECTTIMEOUT => 5,
                CURLOPT_TIMEOUT => 10, CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2]);
            $body = curl_exec($ch); $status = curl_getinfo($ch, CURLINFO_RESPONSE_CODE); $error = curl_errno($ch); curl_close($ch);
            if ($error !== 0 || !is_string($body)) { throw new RuntimeException('test_transport_unknown'); }
            return ['status' => $status, 'body' => $body];
        };
        $mail = (new CarmajaAp5Worker($commerce, $config, $guard))->run();
        $result = ['status' => 'completed', 'startedUtc' => gmdate(DATE_ATOM, (int) $started),
            'durationMs' => (int) round((microtime(true) - $started) * 1000), 'payment' => $payment, 'mail' => $mail];
        cart_probe_assert($result['durationMs'] < 40000, 'worker_duration_exceeded');
        $log = $paths['root'] . '/site-worker-runlog.jsonl';
        cart_probe_assert(!is_link($log), 'worker_log_path_unsafe');
        $handle = fopen($log, 'a'); cart_probe_assert(is_resource($handle) && chmod($log, 0600), 'worker_log_failed');
        try { cart_probe_assert(fwrite($handle, json_encode($result, JSON_THROW_ON_ERROR) . "\n") !== false && fflush($handle), 'worker_log_failed'); }
        finally { fclose($handle); }
        return $result;
    } finally { flock($lock, LOCK_UN); fclose($lock); }
}

function cart_site_flow_path(string $relative): bool
{
    if ($relative === '' || str_contains($relative, '\\') || str_starts_with($relative, '/')
        || array_intersect(explode('/', $relative), ['', '.', '..']) !== []) { return false; }
    return (str_starts_with($relative, '_next/') && cart_site_fixture_path($relative))
        || preg_match('#^(?:warenkorb|widerruf|shop/(?:success|cancel|error))/[A-Za-z0-9_.$/-]+\.(?:html|txt)$#D', $relative) === 1;
}

/** Temporary E2E pages only: no catalog, real details, images, auth or hosting changes. */
function cart_site_install_flow(): array
{
    cart_sandbox_active(); $paths = cart_sandbox_paths(); $source = $paths['root'] . '/flow-export';
    cart_probe_assert(realpath($source) === $source && !is_link($source), 'flow_export_unsafe');
    $journal = $paths['root'] . '/site-flow-files.json';
    cart_probe_assert(!file_exists($journal), 'flow_already_installed');
    $root = '/home/www/carmaja-test-site'; $before = cart_site_web_fingerprint($root);
    $files = []; $originals = []; $directories = [];
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $file) {
        cart_probe_assert(!$file->isLink(), 'flow_symlink');
        if (!$file->isFile()) { continue; }
        $relative = substr($file->getPathname(), strlen($source) + 1);
        cart_probe_assert(cart_site_flow_path($relative), 'flow_file_not_allowed');
        $hash = (string) hash_file('sha256', $file->getPathname());
        if (($before[$relative] ?? null) === $hash) { continue; }
        if (isset($before[$relative])) {
            cart_probe_assert(!str_starts_with($relative, '_next/'), 'asset_collision');
            $originals[$relative] = base64_encode((string) file_get_contents($root . '/' . $relative));
        }
        $files[$relative] = $hash; $parent = dirname($relative);
        while ($parent !== '.') {
            if (!file_exists($root . '/' . $parent)) { $directories[$parent] = true; }
            $parent = dirname($parent);
        }
    }
    cart_probe_assert(isset($files['shop/success/index.html']), 'status_page_missing');
    cart_sandbox_write($journal, ['testOnly' => true, 'before' => $before, 'files' => $files,
        'originals' => $originals, 'directories' => array_keys($directories)]);
    foreach ($files as $relative => $hash) {
        $parent = $root;
        foreach (explode('/', dirname($relative)) as $segment) {
            $parent .= '/' . $segment;
            if (!file_exists($parent)) { cart_probe_assert(mkdir($parent, 0755), 'flow_directory_failed'); }
            cart_probe_assert(realpath($parent) === $parent && !is_link($parent), 'flow_target_unsafe');
        }
        $target = $root . '/' . $relative;
        cart_probe_assert(!is_link($target) && (isset($before[$relative])
            ? hash_equals($before[$relative], (string) hash_file('sha256', $target)) : !file_exists($target)), 'flow_concurrent_change');
        cart_site_atomic($target, (string) file_get_contents($source . '/' . $relative), 0644);
        cart_probe_assert(hash_equals($hash, (string) hash_file('sha256', $target)), 'flow_hash_failed');
    }
    $after = cart_site_web_fingerprint($root);
    foreach ($files as $relative => $hash) {
        cart_probe_assert(($after[$relative] ?? null) === $hash, 'flow_install_failed');
        if (isset($before[$relative])) { $after[$relative] = $before[$relative]; } else { unset($after[$relative]); }
    }
    cart_probe_assert($after === $before, 'unrelated_site_changed');
    return ['status' => 'temporary_flow_ready', 'realCatalogAndProductsUnchanged' => true, 'rollbackJournal' => true];
}

function cart_site_remove_flow(): array
{
    $paths = cart_sandbox_paths(); $journal = cart_sandbox_read($paths['root'] . '/site-flow-files.json');
    cart_probe_assert(($journal['testOnly'] ?? false) === true, 'flow_owner_invalid');
    $root = '/home/www/carmaja-test-site'; $current = cart_site_web_fingerprint($root);
    foreach ($journal['files'] as $relative => $hash) {
        cart_probe_assert(cart_site_flow_path($relative) && ($current[$relative] ?? null) === $hash, 'flow_changed_cleanup_refused');
        if (isset($journal['originals'][$relative])) { $current[$relative] = $journal['before'][$relative]; }
        else { unset($current[$relative]); }
    }
    cart_probe_assert($current === $journal['before'], 'unrelated_site_changed');
    foreach ($journal['files'] as $relative => $hash) {
        if (isset($journal['originals'][$relative])) {
            $text = base64_decode($journal['originals'][$relative], true);
            cart_probe_assert(is_string($text) && hash_equals($journal['before'][$relative], hash('sha256', $text)), 'flow_backup_invalid');
            cart_site_atomic($root . '/' . $relative, $text, 0644);
        } else { cart_probe_assert(unlink($root . '/' . $relative), 'flow_remove_failed'); }
    }
    $directories = $journal['directories']; usort($directories, static fn ($a, $b) => strlen($b) <=> strlen($a));
    foreach ($directories as $relative) {
        cart_probe_assert(cart_site_flow_path($relative . '/cleanup.js') || cart_site_flow_path($relative . '/cleanup.txt'), 'flow_directory_unsafe');
        cart_probe_assert(realpath($root . '/' . $relative) === $root . '/' . $relative
            && count(scandir($root . '/' . $relative)) === 2 && rmdir($root . '/' . $relative), 'flow_directory_remove_failed');
    }
    cart_probe_assert(cart_site_web_fingerprint($root) === $journal['before'], 'flow_restore_failed');
    return ['status' => 'flow_restored', 'siteHashEqual' => true];
}

function cart_site_unbind(): array
{
    $paths = cart_sandbox_paths(); $owner = cart_sandbox_read($paths['root'] . '/site-owner.json');
    cart_probe_assert(cart_site_fingerprint() === $owner['protected'], 'protected_data_changed');
    $path = '/home/www/carmaja-test-api/.htaccess';
    $current = (string) hash_file('sha256', $path);
    if (!hash_equals($current, $owner['beforeRouting'])) {
        cart_probe_assert(hash_equals($current, $owner['boundRouting']), 'routing_changed_cleanup_refused');
        $backup = (string) file_get_contents($paths['root'] . '/api-routing-before.txt');
        cart_probe_assert(hash_equals($owner['beforeRouting'], hash('sha256', $backup)), 'routing_backup_invalid');
        cart_site_atomic($path, $backup, 0644);
    }
    return ['routingRestoredHashEqual' => hash_equals($owner['beforeRouting'], (string) hash_file('sha256', $path)),
        'protectedDataUnchanged' => true];
}

if (PHP_SAPI === 'cli' && realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    ini_set('display_errors', '0'); ini_set('log_errors', '0'); umask(0077);
    try {
        cart_probe_assert(PHP_VERSION_ID >= 80400 && PHP_VERSION_ID < 80500, 'php84_required');
        $result = match ($argv[1] ?? '') {
            'init' => cart_site_init(), 'smoke' => cart_site_smoke(), 'fixtures' => cart_site_fixtures(),
            'install-fixtures' => cart_site_install_fixtures(),
            'remove-fixtures' => cart_site_remove_fixtures(),
            'worker' => cart_site_worker(), 'unbind' => cart_site_unbind(),
            'security' => cart_site_security(), 'withdrawal-admin' => cart_site_withdrawal_admin(),
            'install-flow' => cart_site_install_flow(), 'remove-flow' => cart_site_remove_flow(),
            default => throw new RuntimeException('unknown_test_action'),
        };
        echo json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . "\n";
    } catch (Throwable $error) { cart_probe_report($error); exit(1); }
}
