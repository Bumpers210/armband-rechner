<?php

declare(strict_types=1);

/**
 * Isolated AP3b Stripe sandbox harness.
 *
 * HTTP mode accepts only signed Stripe test webhooks. CLI mode prepares an
 * artificial Commerce schema, creates hosted test checkouts, runs the AP3
 * worker, reports normalized status, expires a test session, and cleans up.
 */

require_once dirname(__DIR__) . '/test-api-private/program/ap3-worker.php';

const AP3B_STRIPE_CONFIG = '/home/www/carmaja-private-test/ap3b-stripe-credentials.json';
const AP3B_COMMERCE_CONFIG = '/home/www/carmaja-private-test/ap3b-commerce-credentials.json';
const AP3B_STAGE_ROOT = '/home/www/carmaja-private-test/ap3b-stage-FJqtTO4b';
const AP3B_CRON_RUNLOG = AP3B_STAGE_ROOT . '/ap6-cron-runs.jsonl';
const AP3B_CRON_LOCK = AP3B_STAGE_ROOT . '/ap6-cron-wrapper.lock';

function ap3bs_fail(string $code): never
{
    throw new RuntimeException($code);
}

function ap3bs_json_file(string $path): array
{
    if (!is_file($path) || !is_readable($path)) {
        ap3bs_fail('private_configuration_missing');
    }
    $mode = fileperms($path);
    if ($mode === false || ($mode & 0777) !== 0600) {
        ap3bs_fail('private_configuration_permissions_invalid');
    }
    try {
        $data = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    } catch (Throwable) {
        ap3bs_fail('private_configuration_invalid');
    }
    if (!is_array($data)) {
        ap3bs_fail('private_configuration_invalid');
    }
    return $data;
}

function ap3bs_configuration(): array
{
    $stripe = ap3bs_json_file(AP3B_STRIPE_CONFIG);
    $commerce = ap3bs_json_file(AP3B_COMMERCE_CONFIG);
    $source = $commerce['source'] ?? null;
    if (!is_array($source)) {
        ap3bs_fail('commerce_source_missing');
    }
    foreach (['host', 'port', 'database', 'username', 'password'] as $field) {
        if (!isset($source[$field]) || $source[$field] === '') {
            ap3bs_fail('commerce_source_invalid');
        }
    }
    foreach (['secretKey', 'webhookSecret', 'payloadKey', 'payloadKeyId'] as $field) {
        if (!is_string($stripe[$field] ?? null) || $stripe[$field] === '') {
            ap3bs_fail('stripe_configuration_invalid');
        }
    }
    return [
        'commerceDsn' => sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
            $source['host'],
            $source['port'],
            $source['database']
        ),
        'commerceUser' => $source['username'],
        'commercePassword' => $source['password'],
        'commerceTlsCaPath' => is_string($commerce['tlsCaPath'] ?? null)
            ? $commerce['tlsCaPath'] : null,
        'commerceRequireTls' => true,
        'stripeSecretKey' => $stripe['secretKey'],
        'stripeWebhookSecret' => $stripe['webhookSecret'],
        'stripeWebhookPayloadKey' => $stripe['payloadKey'],
        'stripeWebhookPayloadKeyId' => $stripe['payloadKeyId'],
        'stripeAutoload' => AP3B_STAGE_ROOT . '/vendor/autoload.php',
        'stripeSdkVersion' => CARMAJA_STRIPE_SDK_VERSION,
        'stripeApiVersion' => CARMAJA_STRIPE_API_VERSION,
        'stripeWebhookApiVersion' => CARMAJA_STRIPE_WEBHOOK_API_VERSION,
        'stripePaymentMethodTypes' => CARMAJA_STRIPE_PAYMENT_METHOD_TYPES,
        'stripeSuccessUrl' => 'https://test.carmaja-perlen.de/shop/success?session_id={CHECKOUT_SESSION_ID}',
        'stripeCancelUrl' => 'https://test.carmaja-perlen.de/shop/cancel',
    ];
}

function ap3bs_commerce(array $config): CarmajaCommercePdo
{
    $options = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_EMULATE_PREPARES => false,
        PDO::ATTR_TIMEOUT => 15,
        PDO::MYSQL_ATTR_SSL_CIPHER => 'HIGH',
    ];
    if (is_string($config['commerceTlsCaPath']) && $config['commerceTlsCaPath'] !== '') {
        $options[PDO::MYSQL_ATTR_SSL_CA] = $config['commerceTlsCaPath'];
        $options[PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT] = true;
    } else {
        $options[PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT] = false;
    }
    $pdo = new PDO(
        $config['commerceDsn'],
        $config['commerceUser'],
        $config['commercePassword'],
        $options
    );
    $tls = $pdo->query(
        "SHOW SESSION STATUS WHERE Variable_name IN ('Ssl_cipher','Ssl_version')"
    )->fetchAll(PDO::FETCH_KEY_PAIR);
    if (trim((string) ($tls['Ssl_cipher'] ?? '')) === ''
        || trim((string) ($tls['Ssl_version'] ?? '')) === '') {
        ap3bs_fail('commerce_tls_not_active');
    }
    return new CarmajaCommercePdo($pdo);
}

function ap3bs_pdo(array $config): PDO
{
    $reflection = new ReflectionClass(CarmajaCommercePdo::class);
    $property = $reflection->getProperty('pdo');
    return $property->getValue(ap3bs_commerce($config));
}

function ap3bs_seed(PDO $pdo): void
{
    $pdo->exec(
        "INSERT INTO legal_bundles
            (legal_bundle_id, terms_version, privacy_version, withdrawal_version,
             shipping_version, merchant_version, bundle_hash, status)
         VALUES
            ('cmj-test-legal-2026-08-06-v2', 'ap3b-test-v2', 'ap3b-test-v2',
             'ap3b-test-v2', 'ap3b-test-v2', 'ap3b-test-v2', REPEAT('a', 64),
             'approved')
         ON DUPLICATE KEY UPDATE legal_bundle_id = VALUES(legal_bundle_id)"
    );
    $insertProduct = $pdo->prepare(
        'INSERT INTO commerce_products
            (product_id, product_version, source_hash, name, description,
             materials, metal_elements, bracelet_size, care_instructions,
             images, price_minor, currency, sales_enabled, synchronized_at)
         VALUES (?, 1, ?, ?, \'Kuenstlicher Stripe-Sandbox-Datensatz\',
                 JSON_ARRAY(\'test\'), JSON_ARRAY(), \'test\', \'test\',
                 JSON_ARRAY(\'/images/products/test/01.jpg\'),
                 4200, \'eur\', 1, UTC_TIMESTAMP(6))'
    );
    $insertInventory = $pdo->prepare(
        'INSERT INTO commerce_inventory (product_id, on_hand, inventory_version)
         VALUES (?, 1, 0)'
    );
    for ($index = 1; $index <= 8; $index++) {
        $productId = sprintf('AP3B-STRIPE-%02d', $index);
        $insertProduct->execute([$productId, hash('sha256', 'ap3b-product-' . $index), 'AP3b Testarmband ' . $index]);
        $insertInventory->execute([$productId]);
    }
}

function ap3bs_init(array $config): array
{
    $commerce = ap3bs_commerce($config);
    $pdo = ap3bs_pdo($config);
    $tables = $pdo->query('SHOW FULL TABLES')->fetchAll(PDO::FETCH_NUM);
    if ($tables !== []) {
        ap3bs_fail('commerce_test_database_not_empty');
    }
    $commerce->migrate(AP3B_STAGE_ROOT . '/website/database/commerce-schema.sql');
    $commerce->migrateForward(
        'commerce-v1-ap4-shop',
        AP3B_STAGE_ROOT . '/website/database/migrations/commerce-v1-ap4-shop.sql'
    );
    $commerce->migrateForward(
        'commerce-v1-ap5-admin',
        AP3B_STAGE_ROOT . '/website/database/migrations/commerce-v1-ap5-admin.sql'
    );
    ap3bs_seed($pdo);
    return ['status' => 'initialized', 'products' => 8, 'testOnly' => true];
}

function ap3bs_create(array $config, int $index): array
{
    if ($index < 1 || $index > 8) {
        ap3bs_fail('checkout_index_invalid');
    }
    $commerce = ap3bs_commerce($config);
    $gateway = new CarmajaStripeGateway($config);
    $productId = sprintf('AP3B-STRIPE-%02d', $index);
    $hash = hash('sha256', 'ap3b-product-' . $index);
    $checkoutId = carmaja_commerce_new_id();
    $expiresAt = time() + CARMAJA_STRIPE_CHECKOUT_LIFETIME_SECONDS;
    $checkout = $commerce->createCheckout([
        'checkoutId' => $checkoutId,
        'idempotencyKey' => 'ap3b-stripe-checkout-' . $checkoutId,
        'requestHash' => hash('sha256', 'ap3b-request-' . $checkoutId),
        'productId' => $productId,
        'productVersion' => 1,
        'sourceHash' => $hash,
        'priceMinor' => 4200,
        'currency' => 'eur',
        'shippingSnapshot' => [
            'shippingMethodId' => 'de-standard',
            'publicName' => 'Standardversand Deutschland',
            'amountMinor' => 490,
            'currency' => 'eur',
            'minBusinessDays' => 2,
            'maxBusinessDays' => 5,
        ],
        'legalBundleId' => 'cmj-test-legal-2026-08-06-v2',
        'expiresAt' => gmdate('Y-m-d H:i:s.u', $expiresAt),
    ]);
    $snapshot = [
        'checkoutId' => $checkoutId,
        'productId' => $productId,
        'productVersion' => 1,
        'sourceHash' => $hash,
        'productName' => 'AP3b Testarmband ' . $index,
        'priceMinor' => 4200,
        'currency' => 'eur',
        'legalBundleId' => 'cmj-test-legal-2026-08-06-v2',
        'shippingSnapshot' => [
            'shippingMethodId' => 'de-standard',
            'publicName' => 'Standardversand Deutschland',
            'amountMinor' => 490,
            'currency' => 'eur',
            'minBusinessDays' => 2,
            'maxBusinessDays' => 5,
        ],
    ];
    try {
        $session = $gateway->createCheckoutSession(
            $snapshot,
            $config['stripeSuccessUrl'],
            $config['stripeCancelUrl'],
            'stripe-session-' . $checkoutId,
            $expiresAt
        );
        $commerce->recordStripeCreationOutcome($checkoutId, 'created', $session['id']);
    } catch (CarmajaStripeException $error) {
        $commerce->recordStripeCreationOutcome(
            $checkoutId,
            $error->errorCode === 'stripe_session_create_failed' ? 'failed' : 'unknown'
        );
        throw $error;
    }
    return [
        'checkoutId' => $checkoutId,
        'paymentId' => $checkout['paymentId'],
        'sessionId' => $session['id'],
        'url' => $session['url'],
        'expiresAt' => $expiresAt,
        'testOnly' => true,
    ];
}

function ap3bs_status(array $config): array
{
    $pdo = ap3bs_pdo($config);
    return [
        'checkouts' => $pdo->query(
            'SELECT c.checkout_id, c.product_id, c.state AS checkout_state,
                    p.status AS payment_status, p.payment_method_type, p.refund_status,
                    r.state AS reservation_state, r.blocks_stock,
                    o.order_number, i.on_hand
             FROM checkout_sagas c
             JOIN payments p ON p.checkout_id = c.checkout_id
             JOIN reservations r ON r.checkout_id = c.checkout_id
             JOIN commerce_inventory i ON i.product_id = c.product_id
             LEFT JOIN orders o ON o.checkout_id = c.checkout_id
             ORDER BY c.created_at'
        )->fetchAll(PDO::FETCH_ASSOC),
        'webhooks' => $pdo->query(
            'SELECT event_type, status, COUNT(*) AS event_count
             FROM webhook_inbox GROUP BY event_type, status
             ORDER BY event_type, status'
        )->fetchAll(PDO::FETCH_ASSOC),
        'reviewCases' => $pdo->query(
            'SELECT reason, status, COUNT(*) AS case_count
             FROM review_cases GROUP BY reason, status ORDER BY reason, status'
        )->fetchAll(PDO::FETCH_ASSOC),
    ];
}

function ap3bs_expire(array $config, string $sessionId): array
{
    if (!str_starts_with($sessionId, 'cs_test_')) {
        ap3bs_fail('test_session_required');
    }
    require_once $config['stripeAutoload'];
    $client = new Stripe\StripeClient([
        'api_key' => $config['stripeSecretKey'],
        'stripe_version' => CARMAJA_STRIPE_API_VERSION,
    ]);
    $session = $client->checkout->sessions->expire($sessionId);
    return ['sessionId' => $sessionId, 'status' => $session->status, 'testOnly' => true];
}

function ap3bs_inspect(array $config, string $sessionId): array
{
    if (!str_starts_with($sessionId, 'cs_test_')) {
        ap3bs_fail('test_session_required');
    }
    require_once $config['stripeAutoload'];
    $gateway = new CarmajaStripeGateway($config);
    $session = $gateway->retrieveCheckoutSession($sessionId);
    $paymentIntentId = $session['payment_intent'] ?? null;
    $intent = is_string($paymentIntentId) && $paymentIntentId !== ''
        ? $gateway->retrievePaymentIntent($paymentIntentId)
        : [];
    $paymentMethod = is_array($intent['payment_method'] ?? null)
        ? $intent['payment_method'] : [];
    $metadata = is_array($session['metadata'] ?? null) ? $session['metadata'] : [];

    return [
        'sessionStatus' => $session['status'] ?? null,
        'paymentStatus' => $session['payment_status'] ?? null,
        'amountTotal' => $session['amount_total'] ?? null,
        'currency' => $session['currency'] ?? null,
        'checkoutIdPresent' => is_string($metadata['checkoutId'] ?? null),
        'productId' => $metadata['productId'] ?? null,
        'legalBundleId' => $metadata['legalBundleId'] ?? null,
        'termsAccepted' => ($session['consent']['terms_of_service'] ?? null) === 'accepted',
        'paymentIntentStatus' => $intent['status'] ?? null,
        'paymentMethodType' => $paymentMethod['type'] ?? null,
        'testOnly' => true,
    ];
}

function ap3bs_cron_worker(array $config): array
{
    $lock = fopen(AP3B_CRON_LOCK, 'c');
    if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
        if (is_resource($lock)) {
            fclose($lock);
        }
        return ['status' => 'locked', 'testOnly' => true];
    }
    @chmod(AP3B_CRON_LOCK, 0600);
    $startedAt = gmdate('Y-m-d\TH:i:s\Z');
    $started = hrtime(true);
    try {
        $result = (new CarmajaAp3Worker(
            ap3bs_commerce($config),
            new CarmajaStripeGateway($config),
            $config
        ))->run();
        $entry = [
            'startedAt' => $startedAt,
            'completedAt' => gmdate('Y-m-d\TH:i:s\Z'),
            'durationMs' => (int) ((hrtime(true) - $started) / 1_000_000),
            'status' => $result['status'] ?? 'unknown',
            'processed' => (int) ($result['processed'] ?? 0),
        ];
        file_put_contents(
            AP3B_CRON_RUNLOG,
            json_encode($entry, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL,
            FILE_APPEND | LOCK_EX
        );
        @chmod(AP3B_CRON_RUNLOG, 0600);
        return $entry + ['testOnly' => true];
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

function ap3bs_cron_status(array $config): array
{
    $runs = [];
    if (is_file(AP3B_CRON_RUNLOG)) {
        foreach (file(AP3B_CRON_RUNLOG, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $entry = json_decode($line, true, 16, JSON_THROW_ON_ERROR);
            if (is_array($entry)) {
                $runs[] = $entry;
            }
        }
    }
    $pdo = ap3bs_pdo($config);
    $lease = $pdo->query(
        "SELECT worker_name, lease_token IS NULL AS released, lease_until,
                last_started_at, last_finished_at, last_success_at, last_error
         FROM worker_leases WHERE worker_name = 'commerce-v1'"
    )->fetch(PDO::FETCH_ASSOC);
    return ['runs' => $runs, 'workerLease' => $lease ?: null, 'testOnly' => true];
}

function ap3bs_cron_cleanup(): array
{
    $removed = [];
    foreach ([AP3B_CRON_RUNLOG, AP3B_CRON_LOCK] as $path) {
        if (is_file($path)) {
            $removed[] = basename($path);
            unlink($path);
        }
    }
    return ['removed' => $removed, 'testOnly' => true];
}

function ap3bs_cleanup(array $config): array
{
    $pdo = ap3bs_pdo($config);
    $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
    foreach ($pdo->query('SHOW FULL TABLES')->fetchAll(PDO::FETCH_NUM) as $row) {
        $table = (string) $row[0];
        if (preg_match('/^[A-Za-z0-9_]{1,64}$/', $table) !== 1) {
            ap3bs_fail('unsafe_table_identifier');
        }
        $pdo->exec('DROP TABLE IF EXISTS `' . $table . '`');
    }
    $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
    return [
        'status' => 'cleaned',
        'empty' => $pdo->query('SHOW FULL TABLES')->fetchAll(PDO::FETCH_NUM) === [],
    ];
}

function ap3bs_webhook(array $config): never
{
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        http_response_code(405);
        exit;
    }
    $rawBody = (string) file_get_contents('php://input');
    $signature = (string) ($_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '');
    try {
        $commerce = ap3bs_commerce($config);
        $endpoint = new CarmajaStripeWebhookEndpoint();
        $result = $endpoint->receive(
            $rawBody,
            $signature,
            $config['stripeWebhookSecret'],
            false,
            static function (array $event, string $raw) use ($commerce, $config): void {
                $encrypted = carmaja_stripe_encrypt_webhook_payload(
                    $raw,
                    $config['stripeWebhookPayloadKey']
                );
                $commerce->persistWebhookEnvelope(
                    $event,
                    $encrypted['ciphertext'],
                    $config['stripeWebhookPayloadKeyId']
                );
            }
        );
        http_response_code((int) $result['status']);
    } catch (CarmajaStripeException $error) {
        http_response_code($error->httpStatus);
        header('Content-Type: application/json');
        echo json_encode(['error' => $error->errorCode], JSON_THROW_ON_ERROR);
    } catch (Throwable) {
        http_response_code(503);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'webhook_inbox_unavailable'], JSON_THROW_ON_ERROR);
    }
    exit;
}

function ap3bs_cli(array $argv, array $config): int
{
    $command = $argv[1] ?? '';
    $result = match ($command) {
        'init' => ap3bs_init($config),
        'create' => ap3bs_create($config, (int) ($argv[2] ?? 0)),
        'worker' => (new CarmajaAp3Worker(
            ap3bs_commerce($config),
            new CarmajaStripeGateway($config),
            $config
        ))->run(),
        'status' => ap3bs_status($config),
        'inspect' => ap3bs_inspect($config, (string) ($argv[2] ?? '')),
        'cron-worker' => ap3bs_cron_worker($config),
        'cron-status' => ap3bs_cron_status($config),
        'cron-cleanup' => ap3bs_cron_cleanup(),
        'expire' => ap3bs_expire($config, (string) ($argv[2] ?? '')),
        'cleanup' => ap3bs_cleanup($config),
        default => throw new RuntimeException('command_invalid'),
    };
    echo json_encode($result, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
    return 0;
}

try {
    $configuration = ap3bs_configuration();
    if (PHP_SAPI !== 'cli') {
        ap3bs_webhook($configuration);
    }
    exit(ap3bs_cli($argv, $configuration));
} catch (Throwable $error) {
    if (PHP_SAPI !== 'cli') {
        http_response_code(503);
        exit;
    }
    fwrite(STDERR, $error->getMessage() . PHP_EOL);
    exit(1);
}
