<?php
declare(strict_types=1);

// Isolated, explicit test targets only. No discovery of existing runtimes.
if (!defined('CARMAJA_BOOTSTRAP_NO_RUN')) { define('CARMAJA_BOOTSTRAP_NO_RUN', true); }
require_once __DIR__ . '/cart-mysql-integration.php';
require_once dirname(__DIR__) . '/test-api-private/program/bootstrap.php';
require_once dirname(__DIR__) . '/test-api-private/program/ap3-worker.php';
require_once dirname(__DIR__) . '/test-api-private/program/cart-public.php';

const CART_SANDBOX_DB_CONFIG = '/home/www/carmaja-private-test/shop-cart-test.json';
const CART_SANDBOX_STRIPE_CONFIG = '/home/www/carmaja-private-test/shop-cart-stripe-test.json';
const CART_SANDBOX_LEGAL_ID = 'cmj-test-legal-2026-10-08-v1';
const CART_SANDBOX_PRODUCT_IDS = ['CART-SANDBOX-A', 'CART-SANDBOX-B'];

function cart_sandbox_root(): string
{
    $root = dirname(__DIR__, 2);
    cart_probe_assert(realpath($root) === $root && !is_link($root)
        && preg_match('#^/home/www/carmaja-private-test/cart-e2e-[A-Za-z0-9-]+$#D', $root) === 1,
        'isolated_stage_required');
    return $root;
}

function cart_sandbox_write(string $path, array $data): void
{
    cart_probe_assert(!file_exists($path) && !is_link($path), 'file_already_exists');
    $handle = fopen($path, 'x');
    cart_probe_assert(is_resource($handle), 'private_write_failed');
    try {
        cart_probe_assert(chmod($path, 0600), 'private_permissions_failed');
        $text = json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        cart_probe_assert(fwrite($handle, $text) === strlen($text) && fflush($handle), 'private_write_failed');
    } finally { fclose($handle); }
}

function cart_sandbox_read(string $path): array
{
    cart_probe_assert(realpath($path) === $path && !is_link($path)
        && is_file($path) && (fileperms($path) & 0777) === 0600, 'private_file_required');
    $data = json_decode((string) file_get_contents($path), true, 32, JSON_THROW_ON_ERROR);
    cart_probe_assert(is_array($data), 'private_file_invalid');
    return $data;
}

function cart_sandbox_targets(): array
{
    $config = cart_probe_config(CART_SANDBOX_DB_CONFIG);
    cart_probe_assert($config['source']['database'] === 'dbs15962244'
        && $config['restore']['database'] === 'dbs15962566', 'test_identity_mismatch');
    return $config;
}

function cart_sandbox_paths(): array
{
    $root = cart_sandbox_root();
    $name = basename($root);
    return ['root' => $root, 'public' => '/home/www/carmaja-test-api/' . $name,
        'baseUrl' => 'https://test-api.carmaja-perlen.de/' . $name . '/index.php',
        'runtime' => $root . '/website/test-api-private/config/runtime-config.php'];
}

function cart_sandbox_configuration(array $stripe): array
{
    $paths = cart_sandbox_paths();
    $database = cart_sandbox_targets();
    $source = $database['source'];
    cart_probe_assert(($stripe['testOnly'] ?? false) === true
        && preg_match('/^sk_test_[A-Za-z0-9]{16,4096}$/D', $stripe['stripeSecretKey'] ?? '') === 1,
        'test_stripe_key_required');
    return ['environment' => 'test', 'publishTarget' => 'test', 'productionPublishEnabled' => false,
        'productApiV4WritesEnabled' => false, 'productApiV5WritesEnabled' => false,
        'inventoryCartEnabled' => true, 'collectionCommerceEnabled' => false,
        'privateDir' => $paths['root'], 'productPrivateDir' => $paths['root'], 'testPrivateDir' => $paths['root'],
        'testApiWebroot' => '/home/www/carmaja-test-api', 'testWebsiteWebroot' => '/home/www/carmaja-test-site',
        'usersFile' => $paths['root'] . '/users.json', 'tokenPepper' => bin2hex(random_bytes(32)),
        'githubAdapterEnabled' => false,
        'commerceDsn' => sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $source['host'], $source['port'], $source['database']),
        'commerceUser' => $source['username'], 'commercePassword' => $source['password'],
        'commerceRequireTls' => true, 'commerceTlsCaPath' => $database['tlsCaPath'] ?: null,
        'stripeSecretKey' => $stripe['stripeSecretKey'], 'stripeWebhookSecret' => $stripe['webhookSecret'] ?? null,
        'stripeWebhookPayloadKey' => $stripe['stripeWebhookPayloadKey'],
        'stripeWebhookPayloadKeyId' => $stripe['stripeWebhookPayloadKeyId'],
        'stripeAutoload' => $paths['root'] . '/sdk/init.php', 'stripeSdkVersion' => CARMAJA_STRIPE_SDK_VERSION,
        'stripeApiVersion' => CARMAJA_STRIPE_API_VERSION, 'stripeWebhookApiVersion' => CARMAJA_STRIPE_WEBHOOK_API_VERSION,
        'stripePaymentMethodTypes' => CARMAJA_STRIPE_PAYMENT_METHOD_TYPES,
        'stripeSuccessUrl' => 'https://test.carmaja-perlen.de/shop/success?session_id={CHECKOUT_SESSION_ID}',
        'stripeCancelUrl' => 'https://test.carmaja-perlen.de/shop/cancel',
        'activeLegalBundleId' => CART_SANDBOX_LEGAL_ID, 'shippingMethodId' => 'maxibrief',
        'shippingPublicName' => 'Test: Maxibrief Deutschland', 'shippingAmountMinor' => 270,
        'shippingMinBusinessDays' => 2, 'shippingMaxBusinessDays' => 5,
        'shopWebsiteOrigin' => 'https://test.carmaja-perlen.de'];
}

function cart_sandbox_client(array $config): object
{
    require_once $config['stripeAutoload'];
    cart_probe_assert(\Stripe\Stripe::VERSION === CARMAJA_STRIPE_SDK_VERSION, 'sdk_pin_mismatch');
    return new \Stripe\StripeClient(['api_key' => $config['stripeSecretKey'], 'stripe_version' => CARMAJA_STRIPE_API_VERSION]);
}

function cart_sandbox_init(): array
{
    $paths = cart_sandbox_paths();
    cart_probe_assert(!file_exists($paths['root'] . '/owner.json') && !file_exists($paths['public'])
        && !is_link($paths['public']) && !file_exists($paths['runtime']), 'stage_already_initialized');
    foreach (['/home/www/carmaja-private-test', '/home/www/carmaja-test-api'] as $parent) {
        cart_probe_assert(realpath($parent) === $parent && !is_link($parent), 'unsafe_test_parent');
    }
    $targets = cart_sandbox_targets();
    $source = cart_probe_connect($targets['source'], $targets);
    $restore = cart_probe_connect($targets['restore'], $targets);
    cart_probe_assert(cart_probe_empty($source) && cart_probe_empty($restore), 'both_targets_must_be_empty');
    $config = cart_sandbox_configuration(cart_sandbox_read(CART_SANDBOX_STRIPE_CONFIG));
    $client = cart_sandbox_client($config);
    $balance = $client->balance->retrieve([])->toArray();
    cart_probe_assert(($balance['livemode'] ?? true) === false, 'stripe_test_mode_required');
    $baseline = [];
    foreach (['index.php', '.htaccess'] as $file) {
        $path = '/home/www/carmaja-test-api/' . $file;
        cart_probe_assert(is_file($path) && !is_link($path), 'existing_api_baseline_missing');
        $baseline[$file] = hash_file('sha256', $path);
    }
    // Write ownership before any schema/provider mutation; failures remain cleanable.
    cart_sandbox_write($paths['root'] . '/owner.json', ['testOnly' => true, 'baseline' => $baseline,
        'deadline' => time() + 86400, 'source' => 'dbs15962244', 'restore' => 'dbs15962566']);
    cart_sandbox_write($paths['root'] . '/users.json', []);
    cart_sandbox_write($paths['root'] . '/environment.json', ['environment' => 'test']);
    $commerce = new CarmajaCartCommerce($source);
    $commerce->migrate(dirname(__DIR__) . '/database/commerce-schema.sql');
    foreach (['commerce-v1-ap4-shop', 'commerce-v1-ap5-admin', 'commerce-v2-collections', 'commerce-v3-cart-inventory'] as $id) {
        $commerce->migrateForward($id, dirname(__DIR__) . '/database/migrations/' . $id . '.sql');
    }
    $source->prepare('INSERT INTO legal_bundles(legal_bundle_id,terms_version,privacy_version,withdrawal_version,shipping_version,merchant_version,bundle_hash,status) VALUES(?,?,?,?,?,?,?,?)')
        ->execute([CART_SANDBOX_LEGAL_ID, 'test-only', 'test-only', 'test-only', 'test-only', 'test-only', hash('sha256', 'artificial-cart-legal-only'), 'approved']);
    // approved here means usable by the artificial harness, never legal approval.
    foreach (CART_SANDBOX_PRODUCT_IDS as $index => $id) {
        $product = ['productId' => $id, 'productModelVersion' => 4, 'productVersion' => 1,
            'sourceHash' => hash('sha256', 'artificial-' . $id), 'title' => 'Kuenstlicher Warenkorbtest ' . ($index + 1),
            'description' => 'Nur Sandbox. Keine echte Ware.', 'materials' => ['Test'], 'metalElements' => [],
            'braceletSizeCm' => 18, 'pearlSizeMm' => 8, 'careInstructions' => [],
            'images' => [['src' => '/images/products/test/01.jpg']], 'priceMinor' => $index === 0 ? 2800 : 1900,
            'currency' => 'eur', 'noticePolicyId' => 'natural-stones-v1', 'notices' => CARMAJA_PRODUCT_NOTICES];
        $commerce->projectCollectionProduct($product, 'publish', 'sandbox-' . $id, hash('sha256', $id));
        $commerce->adjustInventory(['productId' => $id, 'targetOnHand' => 30, 'expectedInventoryVersion' => 0,
            'reason' => 'initial_count', 'correlationId' => 'sandbox-' . $id, 'idempotencyKey' => 'sandbox-' . $id], 'artificial-operator');
    }
    // Pin the endpoint version; never silently substitute the account default.
    $endpoint = $client->webhookEndpoints->create(['url' => $paths['baseUrl'] . '/stripe/webhook',
        'enabled_events' => CARMAJA_STRIPE_WEBHOOK_ALLOWLIST, 'api_version' => CARMAJA_STRIPE_WEBHOOK_API_VERSION,
        'description' => 'Temporary artificial cart test ' . basename($paths['root'])]);
    cart_sandbox_write($paths['root'] . '/endpoint.json', ['id' => $endpoint->id, 'secret' => $endpoint->secret]);
    cart_probe_assert($endpoint->livemode === false && $endpoint->api_version === CARMAJA_STRIPE_WEBHOOK_API_VERSION,
        'webhook_pin_mismatch');
    $config['stripeWebhookSecret'] = $endpoint->secret;
    $runtime = fopen($paths['runtime'], 'x');
    cart_probe_assert(is_resource($runtime), 'runtime_write_failed');
    chmod($paths['runtime'], 0600);
    $text = "<?php\nreturn " . var_export($config, true) . ";\n";
    cart_probe_assert(fwrite($runtime, $text) === strlen($text), 'runtime_write_failed');
    fclose($runtime);
    carmaja_bootstrap_prepare($paths['runtime']);
    cart_probe_assert(mkdir($paths['public'], 0755), 'public_endpoint_create_failed');
    cart_probe_assert(chmod($paths['public'], 0755), 'public_endpoint_permissions_failed');
    $entry = "<?php\nini_set('display_errors','0');\nrequire "
        . var_export(__FILE__, true) . ";\ncart_sandbox_http();\n";
    cart_probe_assert(file_put_contents($paths['public'] . '/index.php', $entry) === strlen($entry), 'endpoint_write_failed');
    chmod($paths['public'] . '/index.php', 0644);
    return ['status' => 'initialized', 'testOnly' => true, 'products' => 2, 'sourceTls' => true,
        'restoreTls' => true, 'sdk' => CARMAJA_STRIPE_SDK_VERSION, 'webhookVersion' => CARMAJA_STRIPE_WEBHOOK_API_VERSION];
}

function cart_sandbox_active(): array
{
    $paths = cart_sandbox_paths();
    $owner = cart_sandbox_read($paths['root'] . '/owner.json');
    cart_probe_assert(($owner['testOnly'] ?? false) === true && ($owner['deadline'] ?? 0) > time(), 'stage_expired');
    return carmaja_bootstrap_prepare($paths['runtime']);
}

function cart_sandbox_event_owned(array $event, PDO $pdo): bool
{
    $object = $event['data']['object'];
    if (str_starts_with($event['type'], 'checkout.session.')) {
        $statement = $pdo->prepare('SELECT COUNT(*) FROM payments WHERE checkout_id=? AND stripe_checkout_session_id=?');
        $statement->execute([$object['metadata']['checkoutId'] ?? '', $object['id'] ?? '']);
    } else {
        $statement = $pdo->prepare('SELECT COUNT(*) FROM payments WHERE stripe_payment_intent_id=?');
        $statement->execute([$object['payment_intent'] ?? '']);
    }
    return (int) $statement->fetchColumn() === 1;
}

function cart_sandbox_http(): never
{
    header('Content-Type: application/json');
    header('Cache-Control: no-store');
    header('X-Robots-Tag: noindex, nofollow');
    try {
        $config = cart_sandbox_active();
        $suffix = substr((string) parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH),
            strlen('/' . basename(cart_sandbox_root()) . '/index.php'));
        $method = $_SERVER['REQUEST_METHOD'] ?? '';
        if ($suffix === '/stripe/webhook' && $method === 'POST') {
            $raw = file_get_contents('php://input', false, null, 0, CARMAJA_STRIPE_WEBHOOK_MAX_BYTES + 1);
            $commerce = carmaja_bootstrap_commerce($config);
            $result = (new CarmajaStripeWebhookEndpoint())->receive((string) $raw,
                $_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '', $config['stripeWebhookSecret'], false,
                static function ($event, $body) use ($commerce, $config): void {
                    $targets = cart_sandbox_targets();
                    if (!cart_sandbox_event_owned($event['event'], cart_probe_connect($targets['source'], $targets))) { return; }
                    $encrypted = carmaja_stripe_encrypt_webhook_payload($body, $config['stripeWebhookPayloadKey']);
                    $commerce->persistWebhookEnvelope($event, $encrypted['ciphertext'], $config['stripeWebhookPayloadKeyId']);
                });
            http_response_code($result['status']);
            exit;
        }
        if (str_starts_with($suffix, '/shop/v3/')) {
            carmaja_cart_route($method, explode('/', substr($suffix, strlen('/shop/v3/'))));
        }
        http_response_code(404);
    } catch (CarmajaCommerceException|CarmajaStripeException $error) {
        http_response_code($error->httpStatus);
        echo json_encode(['ok' => false, 'error' => ['code' => $error->errorCode]]);
    } catch (Throwable) { http_response_code(503); echo '{"ok":false,"error":{"code":"sandbox_unavailable"}}'; }
    exit;
}

function cart_sandbox_status(): array
{
    $config = cart_sandbox_active();
    $targets = cart_sandbox_targets();
    $pdo = cart_probe_connect($targets['source'], $targets);
    $commerce = new CarmajaCartCommerce($pdo);
    return ['testOnly' => true, 'inventory' => array_map($commerce->inventory(...), CART_SANDBOX_PRODUCT_IDS),
        'checkouts' => $pdo->query('SELECT state, COUNT(*) AS count FROM checkout_sagas GROUP BY state')->fetchAll(PDO::FETCH_ASSOC),
        'payments' => $pdo->query('SELECT status,payment_method_type, COUNT(*) AS count FROM payments GROUP BY status,payment_method_type')->fetchAll(PDO::FETCH_ASSOC),
        'orders' => (int) $pdo->query('SELECT COUNT(*) FROM orders')->fetchColumn(),
        'orderItems' => (int) $pdo->query('SELECT COUNT(*) FROM order_items')->fetchColumn(),
        'inbox' => $pdo->query('SELECT status, COUNT(*) AS count FROM webhook_inbox GROUP BY status')->fetchAll(PDO::FETCH_ASSOC),
        'reviews' => (int) $pdo->query('SELECT COUNT(*) FROM review_cases')->fetchColumn()];
}

function cart_sandbox_worker(): array
{
    // Load bootstrap dependencies before resolving the commerce factory.
    $config = cart_sandbox_active();
    $commerce = carmaja_bootstrap_commerce($config);
    return (new CarmajaAp3Worker($commerce, new CarmajaStripeGateway($config), $config))->run();
}

function cart_sandbox_request(string $path, string $method = 'GET', ?array $body = null, array $headers = [],
    string $origin = 'https://test.carmaja-perlen.de'): array
{
    $paths = cart_sandbox_paths();
    $jar = $paths['root'] . '/http-cookies.txt';
    if (!file_exists($jar)) { cart_probe_assert(touch($jar) && chmod($jar, 0600), 'cookie_jar_failed'); }
    cart_probe_assert(!is_link($jar) && (fileperms($jar) & 0777) === 0600, 'cookie_jar_insecure');
    $received = [];
    $curl = curl_init($paths['baseUrl'] . $path);
    curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_TIMEOUT => 30, CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2, CURLOPT_COOKIEFILE => $jar, CURLOPT_COOKIEJAR => $jar,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => ['Origin: ' . $origin, 'Content-Type: application/json', ...$headers],
        CURLOPT_HEADERFUNCTION => static function ($handle, string $line) use (&$received): int {
            if (str_contains($line, ':')) { [$key, $value] = explode(':', $line, 2); $received[strtolower(trim($key))] = trim($value); }
            return strlen($line);
        }]);
    if ($body !== null) { curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($body, JSON_THROW_ON_ERROR)); }
    $raw = curl_exec($curl);
    $status = curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    curl_close($curl);
    cart_probe_assert(is_string($raw), 'http_transport_failed');
    return ['status' => $status, 'headers' => $received, 'json' => $raw === '' ? [] : json_decode($raw, true)];
}

function cart_sandbox_http_checkout(): array
{
    cart_sandbox_active();
    $paths = cart_sandbox_paths();
    cart_probe_assert(!file_exists($paths['root'] . '/checkout-card.json'), 'checkout_already_prepared');
    $preflight = cart_sandbox_request('/shop/v3/cart', 'OPTIONS', null,
        ['Access-Control-Request-Method: PUT', 'Access-Control-Request-Headers: Content-Type,X-CSRF-Token,Idempotency-Key']);
    cart_probe_assert($preflight['status'] === 204
        && ($preflight['headers']['access-control-allow-origin'] ?? '') === 'https://test.carmaja-perlen.de'
        && str_contains(strtolower($preflight['headers']['access-control-allow-headers'] ?? ''), 'idempotency-key'), 'http_cors_preflight_failed');
    $wrongOrigin = cart_sandbox_request('/shop/v3/context', 'GET', null, [], 'https://invalid.example');
    cart_probe_assert($wrongOrigin['status'] === 403, 'http_wrong_origin_accepted');
    $context = cart_sandbox_request('/shop/v3/context');
    cart_probe_assert($context['status'] === 200 && ($context['json']['ok'] ?? false) === true
        && str_contains($context['headers']['cache-control'] ?? '', 'no-store'), 'http_context_failed');
    $csrf = $context['json']['context']['csrfToken'] ?? '';
    cart_probe_assert(is_string($csrf) && strlen($csrf) >= 32, 'http_csrf_missing');
    $live = cart_sandbox_request('/shop/v3/products/' . CART_SANDBOX_PRODUCT_IDS[0]);
    cart_probe_assert($live['status'] === 200 && ($live['json']['product']['priceMinor'] ?? 0) === 2800
        && ($live['json']['product']['availableQuantity'] ?? 0) === 30, 'http_live_product_failed');
    $body = ['expectedCartVersion' => 0, 'items' => [
        ['productId' => CART_SANDBOX_PRODUCT_IDS[0], 'quantity' => 2],
        ['productId' => CART_SANDBOX_PRODUCT_IDS[1], 'quantity' => 1]]];
    $key = 'sandbox-cart-' . bin2hex(random_bytes(16));
    $denied = cart_sandbox_request('/shop/v3/cart', 'PUT', $body, ['X-CSRF-Token: invalid', 'Idempotency-Key: ' . $key]);
    cart_probe_assert($denied['status'] === 403, 'http_bad_csrf_accepted');
    $headers = ['X-CSRF-Token: ' . $csrf, 'Idempotency-Key: ' . $key];
    $cart = cart_sandbox_request('/shop/v3/cart', 'PUT', $body, $headers);
    cart_probe_assert($cart['status'] === 200 && ($cart['json']['cart']['totalMinor'] ?? 0) === 7770, 'http_cart_replace_failed');
    $replay = cart_sandbox_request('/shop/v3/cart', 'PUT', $body, $headers);
    cart_probe_assert($replay['json'] === $cart['json'], 'http_cart_replay_failed');
    $stale = cart_sandbox_request('/shop/v3/cart', 'PUT', $body,
        ['X-CSRF-Token: ' . $csrf, 'Idempotency-Key: stale-' . bin2hex(random_bytes(16))]);
    cart_probe_assert($stale['status'] === 409, 'http_stale_cart_accepted');
    $quote = cart_sandbox_request('/shop/v3/cart');
    cart_probe_assert($quote['status'] === 200 && ($quote['json']['cart']['totalMinor'] ?? 0) === 7770, 'http_quote_failed');
    $checkoutBody = ['expectedCartVersion' => $quote['json']['cart']['cartVersion'],
        'checkoutContextToken' => $quote['json']['cart']['checkoutContextToken']];
    $checkoutKey = 'sandbox-checkout-' . bin2hex(random_bytes(16));
    $checkout = cart_sandbox_request('/shop/v3/checkouts', 'POST', $checkoutBody,
        ['X-CSRF-Token: ' . $csrf, 'Idempotency-Key: ' . $checkoutKey]);
    cart_probe_assert($checkout['status'] === 201 && ($checkout['json']['ok'] ?? false) === true, 'http_checkout_failed');
    $data = $checkout['json']['checkout'];
    cart_probe_assert(str_starts_with($data['url'] ?? '', 'https://checkout.stripe.com/'), 'checkout_url_invalid');
    cart_sandbox_write($paths['root'] . '/checkout-card.json', ['checkoutId' => $data['checkoutId'], 'url' => $data['url']]);
    $duplicate = cart_sandbox_request('/shop/v3/checkouts', 'POST', $checkoutBody,
        ['X-CSRF-Token: ' . $csrf, 'Idempotency-Key: ' . $checkoutKey]);
    cart_probe_assert($duplicate['status'] === 201 && ($duplicate['json']['checkout']['checkoutId'] ?? '') === $data['checkoutId'], 'http_checkout_replay_failed');
    $status = cart_sandbox_request('/shop/v3/checkouts/' . $data['checkoutId'] . '/status');
    cart_probe_assert($status['status'] === 200, 'http_checkout_token_failed');
    $targets = cart_sandbox_targets();
    $commerce = new CarmajaCartCommerce(cart_probe_connect($targets['source'], $targets));
    cart_probe_assert($commerce->inventory(CART_SANDBOX_PRODUCT_IDS[0])['reserved'] === 2
        && $commerce->inventory(CART_SANDBOX_PRODUCT_IDS[1])['reserved'] === 1, 'http_reservations_failed');
    return ['status' => 'checkout_ready', 'testOnly' => true, 'positions' => 2, 'quantities' => [2, 1],
        'totalMinor' => 7770, 'shippingMinor' => 270, 'methods' => CARMAJA_STRIPE_PAYMENT_METHOD_TYPES,
        'httpGuards' => 'passed', 'noPaymentYet' => true];
}

function cart_sandbox_inventory_history(PDO $pdo, string $id): array
{
    cart_probe_assert(in_array($id, CART_SANDBOX_PRODUCT_IDS, true), 'fixture_product_invalid');
    $sold = $pdo->prepare('SELECT COALESCE(SUM(quantity),0) FROM order_items WHERE product_id=?');
    $sold->execute([$id]);
    $returned = $pdo->prepare('SELECT COALESCE(SUM(r.quantity),0) FROM restocked_items r JOIN order_items i ON i.order_id=r.order_id AND i.position_no=r.position_no WHERE i.product_id=?');
    $returned->execute([$id]);
    $audit = $pdo->prepare('SELECT COUNT(*) FROM inventory_adjustments WHERE product_id=?');
    $audit->execute([$id]);
    return ['onHand' => 30 - (int) $sold->fetchColumn() + (int) $returned->fetchColumn(),
        'inventoryVersion' => (int) $audit->fetchColumn()];
}

function cart_sandbox_verify_payment(string $label): array
{
    cart_probe_assert(in_array($label, ['card', 'klarna', 'sepa_debit'], true), 'payment_label_invalid');
    $config = cart_sandbox_active();
    $saved = cart_sandbox_read(cart_sandbox_root() . '/checkout-' . $label . '.json');
    $targets = cart_sandbox_targets();
    $pdo = cart_probe_connect($targets['source'], $targets);
    $commerce = new CarmajaCartCommerce($pdo);
    $query = $pdo->prepare('SELECT * FROM payments WHERE checkout_id=?');
    $query->execute([$saved['checkoutId']]);
    $payment = $query->fetch(PDO::FETCH_ASSOC);
    cart_probe_assert(is_array($payment) && $payment['status'] === 'succeeded'
        && $payment['verification_status'] === 'verified' && $payment['payment_method_type'] === $label,
        'payment_not_verified');
    $gateway = new CarmajaStripeGateway($config);
    $session = $gateway->retrieveCheckoutSession($payment['stripe_checkout_session_id']);
    cart_probe_assert(($session['livemode'] ?? true) === false && $session['payment_status'] === 'paid'
        && $session['amount_total'] === 7770 && ($session['consent']['terms_of_service'] ?? '') === 'accepted',
        'provider_payment_mismatch');
    $query = $pdo->prepare('SELECT * FROM order_items WHERE order_id=? ORDER BY product_id');
    $query->execute([$payment['order_id']]);
    $items = $query->fetchAll(PDO::FETCH_ASSOC);
    cart_probe_assert(count($items) === 2 && array_column($items, 'product_id') === CART_SANDBOX_PRODUCT_IDS
        && array_map('intval', array_column($items, 'quantity')) === [2, 1], 'order_items_mismatch');
    foreach ($items as $item) {
        $history = cart_sandbox_inventory_history($pdo, $item['product_id']);
        cart_probe_assert($commerce->inventory($item['product_id'])['onHand'] === $history['onHand'],
            'inventory_sale_mismatch');
    }
    $query = $pdo->prepare('SELECT state,blocks_stock FROM reservations WHERE checkout_id=?');
    $query->execute([$saved['checkoutId']]);
    $reservations = $query->fetchAll(PDO::FETCH_ASSOC);
    cart_probe_assert(count($reservations) === 2 && array_column($reservations, 'state') === ['converted', 'converted']
        && array_sum(array_column($reservations, 'blocks_stock')) === 0, 'reservations_not_converted');
    $query = $pdo->prepare("SELECT * FROM webhook_inbox WHERE stripe_object_id=? AND event_type='checkout.session.completed' AND status='processed'");
    $query->execute([$payment['stripe_checkout_session_id']]);
    $inbox = $query->fetch(PDO::FETCH_ASSOC);
    cart_probe_assert(is_array($inbox), 'actual_completed_webhook_missing');
    $raw = carmaja_stripe_decrypt_webhook_payload($inbox['payload_ciphertext'], $config['stripeWebhookPayloadKey']);
    $event = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
    cart_probe_assert($event['livemode'] === false && $event['api_version'] === CARMAJA_STRIPE_WEBHOOK_API_VERSION
        && $event['data']['object']['id'] === $payment['stripe_checkout_session_id'], 'actual_event_binding_failed');
    if ($label === 'sepa_debit') {
        $proof = cart_sandbox_read(cart_sandbox_root() . '/sepa-processing-proof.json');
        cart_probe_assert(($proof['status'] ?? '') === 'processing_verified'
            && ($proof['noOrder'] ?? false) === true && ($proof['noInventoryDebit'] ?? false) === true,
            'processing_proof_missing');
        $query = $pdo->prepare("SELECT * FROM webhook_inbox WHERE stripe_object_id=? AND event_type='checkout.session.async_payment_succeeded' AND status='processed'");
        $query->execute([$payment['stripe_checkout_session_id']]);
        $async = $query->fetch(PDO::FETCH_ASSOC);
        cart_probe_assert(is_array($async), 'actual_async_success_webhook_missing');
        $asyncEvent = json_decode(carmaja_stripe_decrypt_webhook_payload($async['payload_ciphertext'],
            $config['stripeWebhookPayloadKey']), true, 32, JSON_THROW_ON_ERROR);
        cart_probe_assert($asyncEvent['livemode'] === false
            && $asyncEvent['api_version'] === CARMAJA_STRIPE_WEBHOOK_API_VERSION
            && $asyncEvent['data']['object']['id'] === $payment['stripe_checkout_session_id']
            && $asyncEvent['data']['object']['payment_status'] === 'paid', 'async_success_binding_failed');
    }
    $before = cart_probe_fingerprint($pdo);
    $persisted = $commerce->persistWebhookEnvelope(['id' => $event['id'], 'type' => $event['type'],
        'objectId' => $event['data']['object']['id'], 'livemode' => false, 'payloadHash' => hash('sha256', $raw),
        'receivedAt' => gmdate('Y-m-d H:i:s.u')], $inbox['payload_ciphertext'], $inbox['payload_key_id']);
    cart_probe_assert($persisted === false, 'event_deduplication_failed');
    $worker = new CarmajaAp3Worker($commerce, $gateway, $config);
    $process = new ReflectionMethod($worker, 'processEvent');
    $process->invoke($worker, $event);
    $process->invoke($worker, $event);
    if (isset($asyncEvent)) {
        $process->invoke($worker, $asyncEvent);
        $process->invoke($worker, $asyncEvent);
    }
    cart_probe_assert($before === cart_probe_fingerprint($pdo), 'payment_replay_mutated_data');
    // Resolve only the artificial mapping-failure case, with the normal audit service.
    $query = $pdo->prepare("SELECT review_case_id,status FROM review_cases WHERE subject_type='payment' AND subject_id=? AND reason='cart_payment_verification_failed'");
    $query->execute([$payment['payment_id']]);
    $reviews = $query->fetchAll(PDO::FETCH_ASSOC);
    if ($reviews !== []) {
        $actor = $pdo->query("SELECT admin_id FROM admin_users WHERE username='sandbox-review-operator' AND enabled=0")->fetchColumn();
        if ($actor === false) {
            $actor = carmaja_commerce_new_id();
            $pdo->prepare("INSERT INTO admin_users(admin_id,username,password_hash,enabled,password_changed_at) VALUES (?,'sandbox-review-operator',?,0,UTC_TIMESTAMP(6))")
                ->execute([$actor, password_hash(bin2hex(random_bytes(32)), PASSWORD_ARGON2ID)]);
        }
        foreach ($reviews as $review) {
            if ($review['status'] === 'open') { $commerce->updateAdminReviewCase($review['review_case_id'], 'investigating', $actor, 'sandbox-mapping-investigation'); }
            if (in_array($review['status'], ['open', 'investigating'], true)) { $commerce->updateAdminReviewCase($review['review_case_id'], 'resolved', $actor, 'sandbox-mapping-verified'); }
            if ($review['status'] !== 'closed') { $commerce->updateAdminReviewCase($review['review_case_id'], 'closed', $actor, 'sandbox-mapping-closed'); }
        }
    }
    return ['status' => 'payment_verified', 'testOnly' => true, 'method' => $label, 'amountMinor' => 7770,
        'actualWebhook' => true, 'orderPositions' => 2, 'quantities' => [2, 1], 'inventoryDebitedOnce' => true,
        'replayUnchanged' => true, 'mailDeliveryNotYetTested' => true];
}

function cart_sandbox_verify_failed_payment(): array
{
    $config = cart_sandbox_active();
    $saved = cart_sandbox_read(cart_sandbox_root() . '/checkout-sepa_failed.json');
    $proof = cart_sandbox_read(cart_sandbox_root() . '/sepa-failed-processing-proof.json');
    cart_probe_assert(($proof['status'] ?? '') === 'processing_verified'
        && ($proof['noOrder'] ?? false) === true && ($proof['noInventoryDebit'] ?? false) === true,
        'failed_processing_proof_missing');
    $targets = cart_sandbox_targets();
    $pdo = cart_probe_connect($targets['source'], $targets);
    $commerce = new CarmajaCartCommerce($pdo);
    $query = $pdo->prepare('SELECT * FROM payments WHERE checkout_id=?');
    $query->execute([$saved['checkoutId']]);
    $payment = $query->fetch(PDO::FETCH_ASSOC);
    cart_probe_assert(is_array($payment) && $payment['status'] === 'failed'
        && $payment['verification_status'] === 'verified'
        && $payment['payment_method_type'] === 'sepa_debit' && $payment['order_id'] === null,
        'payment_failure_not_confirmed');
    $gateway = new CarmajaStripeGateway($config);
    $session = $gateway->retrieveCheckoutSession($payment['stripe_checkout_session_id']);
    $intent = $gateway->retrievePaymentIntent($payment['stripe_payment_intent_id']);
    cart_probe_assert($session['livemode'] === false && $session['payment_status'] === 'unpaid'
        && $session['amount_total'] === 7770 && $intent['status'] === 'requires_payment_method'
        && $intent['last_payment_error'] !== null, 'provider_failure_mismatch');
    $query = $pdo->prepare('SELECT state FROM checkout_sagas WHERE checkout_id=?');
    $query->execute([$saved['checkoutId']]);
    cart_probe_assert($query->fetchColumn() === 'failed', 'checkout_not_failed');
    $query = $pdo->prepare('SELECT COUNT(*) FROM orders WHERE checkout_id=?');
    $query->execute([$saved['checkoutId']]);
    cart_probe_assert((int) $query->fetchColumn() === 0, 'failed_payment_created_order');
    $query = $pdo->prepare('SELECT product_id,quantity,state,blocks_stock FROM reservations WHERE checkout_id=? ORDER BY product_id');
    $query->execute([$saved['checkoutId']]);
    $rows = $query->fetchAll(PDO::FETCH_ASSOC);
    cart_probe_assert(count($rows) === 2 && array_column($rows, 'product_id') === CART_SANDBOX_PRODUCT_IDS
        && array_map('intval', array_column($rows, 'quantity')) === [2, 1]
        && array_column($rows, 'state') === ['released', 'released']
        && array_sum(array_column($rows, 'blocks_stock')) === 0, 'failure_reservations_not_released');
    foreach (CART_SANDBOX_PRODUCT_IDS as $id) {
        $history = cart_sandbox_inventory_history($pdo, $id);
        cart_probe_assert($commerce->inventory($id)['onHand'] === $history['onHand'],
            'failed_payment_debited_inventory');
    }
    $query = $pdo->prepare("SELECT COUNT(*) FROM inventory_adjustments WHERE reason='shop_sale' AND correlation_id=?");
    $query->execute([$saved['checkoutId']]);
    cart_probe_assert((int) $query->fetchColumn() === 0, 'failed_payment_sale_audit');
    $query = $pdo->prepare("SELECT * FROM webhook_inbox WHERE stripe_object_id=? AND event_type IN ('checkout.session.completed','checkout.session.async_payment_failed') AND status='processed' ORDER BY event_type");
    $query->execute([$payment['stripe_checkout_session_id']]);
    $envelopes = $query->fetchAll(PDO::FETCH_ASSOC);
    cart_probe_assert(count($envelopes) === 2, 'actual_failure_webhooks_missing');
    $before = cart_probe_fingerprint($pdo);
    $worker = new CarmajaAp3Worker($commerce, $gateway, $config);
    $process = new ReflectionMethod($worker, 'processEvent');
    foreach ($envelopes as $inbox) {
        $raw = carmaja_stripe_decrypt_webhook_payload($inbox['payload_ciphertext'], $config['stripeWebhookPayloadKey']);
        $event = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
        cart_probe_assert($event['livemode'] === false && $event['api_version'] === CARMAJA_STRIPE_WEBHOOK_API_VERSION
            && $event['data']['object']['id'] === $payment['stripe_checkout_session_id'], 'failure_event_binding_failed');
        cart_probe_assert($commerce->persistWebhookEnvelope(['id' => $event['id'], 'type' => $event['type'],
            'objectId' => $event['data']['object']['id'], 'livemode' => false, 'payloadHash' => hash('sha256', $raw),
            'receivedAt' => gmdate('Y-m-d H:i:s.u')], $inbox['payload_ciphertext'], $inbox['payload_key_id']) === false,
            'failure_event_deduplication_failed');
        $process->invoke($worker, $event);
        $process->invoke($worker, $event);
    }
    cart_probe_assert($before === cart_probe_fingerprint($pdo), 'failure_replay_mutated_data');
    // Close only this artificial, now verified mapping issue through the audit service.
    $query = $pdo->prepare("SELECT review_case_id,status FROM review_cases WHERE subject_type='payment' AND subject_id=? AND reason='cart_failed_payment_mismatch'");
    $query->execute([$payment['payment_id']]);
    $reviews = $query->fetchAll(PDO::FETCH_ASSOC);
    if ($reviews !== []) {
        $actor = $pdo->query("SELECT admin_id FROM admin_users WHERE username='sandbox-review-operator' AND enabled=0")->fetchColumn();
        cart_probe_assert(is_string($actor), 'sandbox_review_actor_missing');
        foreach ($reviews as $review) {
            if ($review['status'] === 'open') { $commerce->updateAdminReviewCase($review['review_case_id'], 'investigating', $actor, 'sandbox-failure-mapping-investigation'); }
            if (in_array($review['status'], ['open', 'investigating'], true)) { $commerce->updateAdminReviewCase($review['review_case_id'], 'resolved', $actor, 'sandbox-failure-verified'); }
            if ($review['status'] !== 'closed') { $commerce->updateAdminReviewCase($review['review_case_id'], 'closed', $actor, 'sandbox-failure-mapping-closed'); }
        }
    }
    return ['status' => 'failure_verified', 'testOnly' => true, 'method' => 'sepa_debit',
        'actualAsyncWebhook' => true, 'noOrder' => true, 'noInventoryDebit' => true,
        'allReservationsReleased' => true, 'replayUnchanged' => true];
}

function cart_sandbox_verify_partial_refund(): array
{
    $config = cart_sandbox_active();
    $saved = cart_sandbox_read(cart_sandbox_root() . '/checkout-card.json');
    $targets = cart_sandbox_targets();
    $pdo = cart_probe_connect($targets['source'], $targets);
    $commerce = new CarmajaCartCommerce($pdo);
    $query = $pdo->prepare('SELECT * FROM payments WHERE checkout_id=?');
    $query->execute([$saved['checkoutId']]);
    $payment = $query->fetch(PDO::FETCH_ASSOC);
    cart_probe_assert(is_array($payment) && $payment['status'] === 'succeeded'
        && $payment['refund_status'] === 'partial' && (int) $payment['refunded_minor'] === 2800
        && (int) $payment['amount_minor'] === 7770 && is_string($payment['order_id']),
        'partial_refund_not_confirmed');
    $query = $pdo->prepare('SELECT * FROM refunds WHERE payment_id=?');
    $query->execute([$payment['payment_id']]);
    $refunds = $query->fetchAll(PDO::FETCH_ASSOC);
    cart_probe_assert(count($refunds) === 1 && $refunds[0]['status'] === 'succeeded'
        && (int) $refunds[0]['amount_minor'] === 2800 && $refunds[0]['currency'] === 'eur',
        'partial_refund_evidence_invalid');
    $gateway = new CarmajaStripeGateway($config);
    $refund = $gateway->retrieveRefund($refunds[0]['stripe_refund_id']);
    // Refund objects have no livemode field: bind to the verified test PaymentIntent.
    $intent = $gateway->retrievePaymentIntent($payment['stripe_payment_intent_id']);
    cart_probe_assert(($intent['livemode'] ?? true) === false && $refund['status'] === 'succeeded'
        && $refund['amount'] === 2800 && $refund['currency'] === 'eur'
        && $refund['payment_intent'] === $payment['stripe_payment_intent_id'], 'provider_refund_mismatch');
    $admin = $commerce->loadAdminOrder($payment['order_id']);
    cart_probe_assert(is_array($admin) && $admin['status'] === 'confirmed'
        && $admin['refundedMinor'] === 2800 && $admin['totalMinor'] === 7770
        && count($admin['items']) === 2 && count($admin['refunds']) === 1, 'admin_partial_refund_mismatch');
    foreach (CART_SANDBOX_PRODUCT_IDS as $id) {
        $inventory = $commerce->inventory($id);
        $history = cart_sandbox_inventory_history($pdo, $id);
        cart_probe_assert($inventory['onHand'] === $history['onHand']
            && $inventory['reserved'] === 0 && $inventory['inventoryVersion'] === $history['inventoryVersion'],
            'refund_mutated_inventory');
    }
    $envelopes = $pdo->query("SELECT * FROM webhook_inbox WHERE event_type IN ('charge.refunded','refund.updated') AND status='processed'")
        ->fetchAll(PDO::FETCH_ASSOC);
    $before = cart_probe_fingerprint($pdo);
    $worker = new CarmajaAp3Worker($commerce, $gateway, $config);
    $process = new ReflectionMethod($worker, 'processEvent');
    $types = [];
    foreach ($envelopes as $inbox) {
        $raw = carmaja_stripe_decrypt_webhook_payload($inbox['payload_ciphertext'], $config['stripeWebhookPayloadKey']);
        $event = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
        if (($event['data']['object']['payment_intent'] ?? '') !== $payment['stripe_payment_intent_id']) { continue; }
        cart_probe_assert($event['livemode'] === false && $event['api_version'] === CARMAJA_STRIPE_WEBHOOK_API_VERSION,
            'refund_event_binding_failed');
        $types[] = $event['type'];
        cart_probe_assert($commerce->persistWebhookEnvelope(['id' => $event['id'], 'type' => $event['type'],
            'objectId' => $event['data']['object']['id'], 'livemode' => false, 'payloadHash' => hash('sha256', $raw),
            'receivedAt' => gmdate('Y-m-d H:i:s.u')], $inbox['payload_ciphertext'], $inbox['payload_key_id']) === false,
            'refund_event_deduplication_failed');
        $process->invoke($worker, $event);
        $process->invoke($worker, $event);
    }
    cart_probe_assert(in_array('charge.refunded', $types, true) && in_array('refund.updated', $types, true),
        'actual_refund_webhooks_missing');
    cart_probe_assert($before === cart_probe_fingerprint($pdo), 'refund_replay_mutated_data');
    return ['status' => 'partial_refund_verified', 'testOnly' => true, 'refundedMinor' => 2800,
        'originalAmountMinor' => 7770, 'actualWebhookTypes' => array_values(array_unique($types)),
        'orderUnchanged' => true, 'noAutomaticRestock' => true, 'adminAmountsVerified' => true,
        'replayUnchanged' => true];
}

function cart_sandbox_verify_return(): array
{
    cart_sandbox_active();
    $proofPath = cart_sandbox_root() . '/return-proof.json';
    cart_probe_assert(!file_exists($proofPath), 'return_test_already_completed');
    $targets = cart_sandbox_targets();
    $pdo = cart_probe_connect($targets['source'], $targets);
    $commerce = new CarmajaCartCommerce($pdo);
    $saved = cart_sandbox_read(cart_sandbox_root() . '/checkout-card.json');
    $query = $pdo->prepare('SELECT o.* FROM orders o WHERE o.checkout_id=?');
    $query->execute([$saved['checkoutId']]);
    $order = $query->fetch(PDO::FETCH_ASSOC);
    cart_probe_assert(is_array($order) && $order['status'] === 'confirmed', 'return_order_missing');
    $positions = $commerce->orderPositions($order['order_id']);
    $position = array_values(array_filter($positions, static fn($line) => $line['productId'] === CART_SANDBOX_PRODUCT_IDS[0]));
    cart_probe_assert(count($position) === 1 && $position[0]['quantity'] === 2, 'return_position_missing');
    $positionNo = $position[0]['positionNo'];
    $beforeInventory = array_map($commerce->inventory(...), CART_SANDBOX_PRODUCT_IDS);
    $query = $pdo->prepare('SELECT COUNT(*) FROM withdrawal_requests WHERE order_id=?');
    $query->execute([$order['order_id']]);
    cart_probe_assert((int) $query->fetchColumn() === 0, 'return_test_already_started');
    $context = cart_sandbox_request('/shop/v3/context');
    cart_probe_assert($context['status'] === 200, 'return_context_failed');
    $headers = ['X-CSRF-Token: ' . $context['json']['context']['csrfToken']];
    $identification = ['orderNumber' => $order['order_number'], 'name' => $order['customer_name'], 'email' => $order['customer_email']];
    $preview = cart_sandbox_request('/shop/v3/withdrawals/preview', 'POST', $identification, $headers);
    cart_probe_assert($preview['status'] === 200 && $preview['json']['withdrawal']['matchStatus'] === 'matched'
        && count($preview['json']['withdrawal']['items']) === 2, 'return_preview_failed');
    $withdrawal = $preview['json']['withdrawal'];
    $query = $pdo->prepare('SELECT state FROM withdrawal_requests WHERE withdrawal_id=?');
    $query->execute([$withdrawal['withdrawalId']]);
    cart_probe_assert($query->fetchColumn() === 'awaiting_confirmation', 'preview_submitted_declaration');
    $mail = $pdo->prepare('SELECT COUNT(*) FROM mail_outbox WHERE dedupe_key=?');
    $mail->execute(['withdrawal-receipt:' . $withdrawal['withdrawalId']]);
    cart_probe_assert((int) $mail->fetchColumn() === 0, 'preview_enqueued_receipt');
    $body = ['withdrawalId' => $withdrawal['withdrawalId'], 'confirmationToken' => $withdrawal['confirmationToken'],
        'items' => [['positionNo' => $positionNo, 'quantity' => 1]], 'details' => 'Artificial partial return only.'];
    $confirm = cart_sandbox_request('/shop/v3/withdrawals/confirm', 'POST', $body, $headers);
    cart_probe_assert($confirm['status'] === 200 && $confirm['json']['withdrawal']['state'] === 'submitted', 'partial_return_not_submitted');
    $query = $pdo->prepare('SELECT position_no,quantity FROM withdrawal_items WHERE withdrawal_id=?');
    $query->execute([$withdrawal['withdrawalId']]);
    $items = $query->fetchAll(PDO::FETCH_ASSOC);
    cart_probe_assert(count($items) === 1 && (int) $items[0]['position_no'] === $positionNo
        && (int) $items[0]['quantity'] === 1, 'partial_return_items_invalid');
    $mail->execute(['withdrawal-receipt:' . $withdrawal['withdrawalId']]);
    cart_probe_assert((int) $mail->fetchColumn() === 1, 'partial_return_receipt_missing');
    $replay = cart_sandbox_request('/shop/v3/withdrawals/confirm', 'POST', $body, $headers);
    cart_probe_assert($replay['status'] === 409, 'partial_return_replay_accepted');
    $mail->execute(['withdrawal-receipt:' . $withdrawal['withdrawalId']]);
    cart_probe_assert((int) $mail->fetchColumn() === 1, 'partial_return_duplicate_receipt');
    cart_probe_assert($beforeInventory === array_map($commerce->inventory(...), CART_SANDBOX_PRODUCT_IDS), 'withdrawal_automatically_restocked');

    $overPreview = cart_sandbox_request('/shop/v3/withdrawals/preview', 'POST', $identification, $headers);
    cart_probe_assert($overPreview['status'] === 200, 'overclaim_preview_failed');
    $over = $overPreview['json']['withdrawal'];
    $overConfirm = cart_sandbox_request('/shop/v3/withdrawals/confirm', 'POST', [
        'withdrawalId' => $over['withdrawalId'], 'confirmationToken' => $over['confirmationToken'],
        'items' => [['positionNo' => $positionNo, 'quantity' => 2]], 'details' => 'Artificial overclaim test.'], $headers);
    cart_probe_assert($overConfirm['status'] === 200, 'overclaim_declaration_lost');
    $query = $pdo->prepare('SELECT state,match_status,submitted_content FROM withdrawal_requests WHERE withdrawal_id=?');
    $query->execute([$over['withdrawalId']]);
    $row = $query->fetch(PDO::FETCH_ASSOC);
    $content = json_decode($row['submitted_content'], true, 32, JSON_THROW_ON_ERROR);
    cart_probe_assert($row['state'] === 'submitted' && $row['match_status'] === 'manual_review'
        && $content['items'][0]['quantity'] === 2, 'overclaim_not_retained_for_review');
    $query = $pdo->prepare('SELECT COUNT(*) FROM withdrawal_items WHERE withdrawal_id=?');
    $query->execute([$over['withdrawalId']]);
    cart_probe_assert((int) $query->fetchColumn() === 0, 'overclaim_counted_as_return');
    $actor = $pdo->query("SELECT admin_id FROM admin_users WHERE username='sandbox-review-operator' AND enabled=0")->fetchColumn();
    cart_probe_assert(is_string($actor), 'sandbox_review_actor_missing');
    $query = $pdo->prepare("SELECT review_case_id FROM review_cases WHERE subject_type='withdrawal' AND subject_id=? AND reason='withdrawal_not_uniquely_matched' AND status='open'");
    $query->execute([$over['withdrawalId']]);
    $reviewId = $query->fetchColumn();
    cart_probe_assert(is_string($reviewId), 'overclaim_review_missing');
    foreach (['investigating', 'resolved', 'closed'] as $status) {
        $commerce->updateAdminReviewCase($reviewId, $status, $actor, 'artificial-overclaim-' . $status);
    }
    cart_probe_assert($beforeInventory === array_map($commerce->inventory(...), CART_SANDBOX_PRODUCT_IDS), 'overclaim_mutated_inventory');

    $key = 'sandbox-partial-restock-' . bin2hex(random_bytes(12));
    $restock = ['positionNo' => $positionNo, 'quantity' => 1,
        'expectedInventoryVersion' => $beforeInventory[0]['inventoryVersion'],
        'correlationId' => 'artificial-checked-return', 'receivedAndChecked' => true];
    $before = cart_probe_fingerprint($pdo);
    try { $commerce->restockPosition($order['order_id'], [...$restock, 'receivedAndChecked' => false], $actor, $key);
        throw new RuntimeException('unchecked_return_accepted'); }
    catch (CarmajaCommerceException $error) { cart_probe_assert($error->errorCode === 'return_verification_required', 'unexpected_unchecked_return_error'); }
    cart_probe_assert($before === cart_probe_fingerprint($pdo), 'unchecked_return_mutated_data');
    // Artificial receipt/inspection only; no claim about any real physical return.
    $result = $commerce->restockPosition($order['order_id'], $restock, $actor, $key);
    cart_probe_assert($result['onHand'] === $beforeInventory[0]['onHand'] + 1
        && $result['inventoryVersion'] === $beforeInventory[0]['inventoryVersion'] + 1, 'partial_restock_failed');
    $after = cart_probe_fingerprint($pdo);
    $commerce->restockPosition($order['order_id'], $restock, $actor, $key);
    cart_probe_assert($after === cart_probe_fingerprint($pdo), 'restock_replay_mutated_data');
    try { $commerce->restockPosition($order['order_id'], [...$restock, 'quantity' => 2,
            'expectedInventoryVersion' => $result['inventoryVersion']], $actor, $key . '-over');
        throw new RuntimeException('excess_restock_accepted'); }
    catch (CarmajaCommerceException $error) { cart_probe_assert($error->errorCode === 'return_quantity_conflict', 'unexpected_excess_restock_error'); }
    try { $commerce->restockPosition($order['order_id'], $restock, $actor, $key . '-stale');
        throw new RuntimeException('stale_restock_accepted'); }
    catch (CarmajaCommerceException $error) { cart_probe_assert($error->errorCode === 'inventory_version_conflict', 'unexpected_stale_restock_error'); }
    cart_probe_assert($after === cart_probe_fingerprint($pdo), 'rejected_restock_mutated_data');
    cart_probe_assert($commerce->inventory(CART_SANDBOX_PRODUCT_IDS[1]) === $beforeInventory[1], 'other_product_restocked');
    $admin = $commerce->loadAdminOrder($order['order_id']);
    cart_probe_assert($admin['status'] === 'confirmed' && $admin['refund_status'] === 'partial'
        && $admin['refundedMinor'] === 2800, 'return_changed_payment_or_order');
    $proof = ['status' => 'partial_return_verified', 'testOnly' => true, 'twoStepHttp' => true,
        'selectedPositions' => 1, 'selectedQuantity' => 1, 'confirmationReplayRejected' => true,
        'overclaimRetainedForReview' => true, 'noAutomaticRestock' => true, 'checkedArtificialRestock' => true,
        'restockReplayUnchanged' => true, 'excessAndStaleRestockRejected' => true, 'otherProductUnchanged' => true];
    cart_sandbox_write($proofPath, $proof);
    return $proof;
}

function cart_sandbox_next_checkout(string $label): array
{
    cart_probe_assert(in_array($label, ['klarna', 'sepa_debit', 'sepa_failed'], true), 'payment_label_invalid');
    cart_sandbox_active();
    $paths = cart_sandbox_paths();
    cart_probe_assert(!file_exists($paths['root'] . '/checkout-' . $label . '.json'), 'checkout_already_prepared');
    $context = cart_sandbox_request('/shop/v3/context');
    cart_probe_assert($context['status'] === 200, 'http_context_failed');
    $csrf = $context['json']['context']['csrfToken'];
    $cart = cart_sandbox_request('/shop/v3/cart');
    cart_probe_assert($cart['status'] === 200, 'http_cart_failed');
    $targets = cart_sandbox_targets();
    $commerce = new CarmajaCartCommerce(cart_probe_connect($targets['source'], $targets));
    $stock = [];
    foreach (CART_SANDBOX_PRODUCT_IDS as $id) { $stock[$id] = $commerce->inventory($id)['onHand']; }
    $replace = cart_sandbox_request('/shop/v3/cart', 'PUT', ['expectedCartVersion' => $cart['json']['cart']['cartVersion'],
        'items' => [['productId' => CART_SANDBOX_PRODUCT_IDS[0], 'quantity' => 2],
            ['productId' => CART_SANDBOX_PRODUCT_IDS[1], 'quantity' => 1]]],
        ['X-CSRF-Token: ' . $csrf, 'Idempotency-Key: sandbox-cart-' . bin2hex(random_bytes(16))]);
    cart_probe_assert($replace['status'] === 200, 'http_cart_replace_failed');
    $quote = cart_sandbox_request('/shop/v3/cart');
    cart_probe_assert($quote['status'] === 200 && $quote['json']['cart']['totalMinor'] === 7770, 'http_quote_failed');
    $checkout = cart_sandbox_request('/shop/v3/checkouts', 'POST',
        ['expectedCartVersion' => $quote['json']['cart']['cartVersion'], 'checkoutContextToken' => $quote['json']['cart']['checkoutContextToken']],
        ['X-CSRF-Token: ' . $csrf, 'Idempotency-Key: sandbox-checkout-' . bin2hex(random_bytes(16))]);
    cart_probe_assert($checkout['status'] === 201, 'http_checkout_failed');
    $data = $checkout['json']['checkout'];
    cart_probe_assert(str_starts_with($data['url'] ?? '', 'https://checkout.stripe.com/'), 'checkout_url_invalid');
    cart_sandbox_write($paths['root'] . '/checkout-' . $label . '.json',
        ['checkoutId' => $data['checkoutId'], 'url' => $data['url'], 'beforeStock' => $stock]);
    return ['status' => 'checkout_ready', 'selectMethod' => $label === 'sepa_failed' ? 'sepa_debit' : $label,
        'testOnly' => true, 'totalMinor' => 7770];
}

function cart_sandbox_cleanup(): array
{
    $paths = cart_sandbox_paths();
    $owner = cart_sandbox_read($paths['root'] . '/owner.json');
    cart_probe_assert(($owner['testOnly'] ?? false) === true && $owner['source'] === 'dbs15962244'
        && $owner['restore'] === 'dbs15962566', 'cleanup_owner_invalid');
    $stripe = cart_sandbox_configuration(cart_sandbox_read(CART_SANDBOX_STRIPE_CONFIG));
    $client = cart_sandbox_client($stripe);
    $targets = cart_sandbox_targets();
    $source = cart_probe_connect($targets['source'], $targets);
    if (in_array('payments', cart_probe_tables($source), true)) {
        foreach ($source->query('SELECT stripe_checkout_session_id FROM payments WHERE stripe_checkout_session_id IS NOT NULL')->fetchAll(PDO::FETCH_COLUMN) as $id) {
            cart_probe_assert(str_starts_with($id, 'cs_test_'), 'non_test_session_rejected');
            $session = $client->checkout->sessions->retrieve($id);
            if ($session->status === 'open') { $client->checkout->sessions->expire($id); }
        }
    }
    if (is_file($paths['root'] . '/endpoint.json')) {
        $endpoint = cart_sandbox_read($paths['root'] . '/endpoint.json');
        $remote = $client->webhookEndpoints->retrieve($endpoint['id']);
        cart_probe_assert($remote->livemode === false && $remote->url === $paths['baseUrl'] . '/stripe/webhook', 'endpoint_owner_mismatch');
        $client->webhookEndpoints->delete($endpoint['id']);
    }
    if (file_exists($paths['public'])) {
        cart_probe_assert(realpath($paths['public']) === $paths['public'] && !is_link($paths['public'])
            && scandir($paths['public']) === ['.', '..', 'index.php'], 'public_cleanup_scope_invalid');
        cart_probe_assert(unlink($paths['public'] . '/index.php') && rmdir($paths['public']), 'public_cleanup_failed');
    }
    $allowed = [];
    foreach ([dirname(__DIR__) . '/database/commerce-schema.sql', ...glob(dirname(__DIR__) . '/database/migrations/*.sql')] as $file) {
        preg_match_all('/CREATE TABLE(?: IF NOT EXISTS)?\s+([a-z_]+)/i', (string) file_get_contents($file), $matches);
        $allowed = [...$allowed, ...$matches[1]];
    }
    foreach (['source', 'restore'] as $label) {
        $pdo = cart_probe_connect($targets[$label], $targets);
        $tables = cart_probe_tables($pdo);
        cart_probe_assert(array_diff($tables, $allowed) === [], 'unknown_tables_cleanup_refused');
        $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
        foreach ($tables as $table) { $pdo->exec('DROP TABLE `' . $table . '`'); }
        $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
        cart_probe_assert(cart_probe_empty($pdo), 'database_cleanup_failed');
    }
    foreach ($owner['baseline'] as $file => $hash) {
        cart_probe_assert(hash_equals($hash, hash_file('sha256', '/home/www/carmaja-test-api/' . $file)), 'existing_api_changed');
    }
    return ['status' => 'cleaned', 'sourceEmpty' => true, 'restoreEmpty' => true, 'endpointRemoved' => true,
        'existingApiUnchanged' => true, 'privateStageRemovalStillRequired' => true];
}

if (PHP_SAPI === 'cli' && realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    ini_set('display_errors', '0');
    umask(0077);
    try {
        cart_probe_assert(PHP_VERSION_ID >= 80400 && PHP_VERSION_ID < 80500, 'php84_required');
        $result = match ($argv[1] ?? '') {
            'init' => cart_sandbox_init(),
            'status' => cart_sandbox_status(),
            'http-checkout' => cart_sandbox_http_checkout(),
            'verify-payment' => cart_sandbox_verify_payment($argv[2] ?? ''),
            'verify-failed-payment' => cart_sandbox_verify_failed_payment(),
            'verify-partial-refund' => cart_sandbox_verify_partial_refund(),
            'verify-return' => cart_sandbox_verify_return(),
            'next-checkout' => cart_sandbox_next_checkout($argv[2] ?? ''),
            'worker' => cart_sandbox_worker(),
            'cleanup' => cart_sandbox_cleanup(),
            default => throw new RuntimeException('unknown_test_action'),
        };
        echo json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . "\n";
    } catch (Throwable $error) { cart_probe_report($error); exit(1); }
}
