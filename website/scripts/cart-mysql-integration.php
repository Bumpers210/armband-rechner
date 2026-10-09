<?php
declare(strict_types=1);

// Explicit private test configuration only. Never discovers Runtime/secrets.
require_once dirname(__DIR__) . '/test-api-private/program/cart-commerce.php';
require_once dirname(__DIR__) . '/test-api-private/program/shop-public.php';
require_once __DIR__ . '/commerce-backup.php';

function cart_probe_assert(bool $value, string $code): void
{
    if (!$value) { throw new RuntimeException($code); }
}

function cart_probe_report(Throwable $error): void
{
    $code = $error instanceof PDOException ? 'pdo_failure'
        : ($error instanceof CarmajaCommerceException ? $error->errorCode : $error->getMessage());
    if (preg_match('/^[a-z_]{1,80}$/', $code) !== 1) { $code = 'integration_failure'; }
    fwrite(STDERR, 'FAIL ' . $code . "; no acceptance granted.\n");
    if ($error instanceof PDOException) {
        $state = (string) ($error->errorInfo[0] ?? '');
        if (preg_match('/^[A-Z0-9]{5}$/', $state) === 1) {
            fwrite(STDERR, 'SQLSTATE ' . $state . '; driver code ' . (int) ($error->errorInfo[1] ?? 0)
                . '; file ' . basename($error->getFile()) . '; line ' . $error->getLine() . "\n");
        }
    }
}

function cart_probe_config(string $path): array
{
    $real = realpath($path);
    cart_probe_assert(is_string($real) && str_starts_with($real, '/home/www/carmaja-private-test/')
        && !is_link($path) && (fileperms($real) & 0777) === 0600, 'private_test_configuration_required');
    $config = json_decode((string) file_get_contents($real), true, 32, JSON_THROW_ON_ERROR);
    cart_probe_assert(($config['testOnly'] ?? null) === true && ($config['requireTls'] ?? null) === true, 'test_authorization_required');
    foreach (['source','restore'] as $label) {
        foreach (['host','database','username','password'] as $field) {
            cart_probe_assert(is_string($config[$label][$field] ?? null) && $config[$label][$field] !== ''
                && strpbrk($config[$label][$field], "\r\n\0") === false
                && ($field === 'password' || !str_contains($config[$label][$field], ';')), 'invalid_private_configuration');
        }
        cart_probe_assert(!preg_match('/prod|production/i', $config[$label]['database']), 'production_target_rejected');
        cart_probe_assert(is_int($config[$label]['port'] ?? null) && $config[$label]['port'] > 0 && $config[$label]['port'] <= 65535, 'invalid_private_port');
    }
    cart_probe_assert($config['source']['database'] !== $config['restore']['database']
        && $config['source']['username'] !== $config['restore']['username'], 'targets_not_separate');
    return $config;
}

function cart_probe_connect(array $target, array $config): PDO
{
    $ca = $config['tlsCaPath'] ?? '';
    $options = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false, PDO::ATTR_TIMEOUT => 10,
        PDO::MYSQL_ATTR_SSL_CIPHER => 'HIGH', PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT => $ca !== ''];
    if ($ca !== '') { cart_probe_assert(is_file($ca), 'tls_ca_missing'); $options[PDO::MYSQL_ATTR_SSL_CA] = $ca; }
    else { cart_probe_assert(($config['acceptUnverifiedTls'] ?? null) === true, 'tls_residual_risk_not_accepted'); }
    $pdo = new PDO(sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $target['host'], $target['port'], $target['database']), $target['username'], $target['password'], $options);
    $pdo->exec("SET time_zone='+00:00', sql_mode='STRICT_ALL_TABLES', innodb_lock_wait_timeout=3");
    $tls = $pdo->query("SHOW SESSION STATUS WHERE Variable_name IN ('Ssl_cipher','Ssl_version')")->fetchAll(PDO::FETCH_KEY_PAIR);
    cart_probe_assert(($tls['Ssl_cipher'] ?? '') !== '' && ($tls['Ssl_version'] ?? '') !== '', 'tls_required');
    cart_probe_assert(str_starts_with((string) $pdo->query('SELECT VERSION()')->fetchColumn(), '8.'), 'mysql8_required');
    cart_probe_assert((int) $pdo->query("SELECT COUNT(*) FROM information_schema.ENGINES WHERE ENGINE='InnoDB' AND SUPPORT IN ('YES','DEFAULT')")->fetchColumn() === 1, 'innodb_required');
    return $pdo;
}

function cart_probe_tables(PDO $pdo): array
{
    return $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
}

function cart_probe_empty(PDO $pdo): bool
{
    return cart_probe_tables($pdo) === []
        && (int) $pdo->query('SELECT COUNT(*) FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA=DATABASE()')->fetchColumn() === 0
        && (int) $pdo->query('SELECT COUNT(*) FROM information_schema.EVENTS WHERE EVENT_SCHEMA=DATABASE()')->fetchColumn() === 0;
}

function cart_probe_schema_text(string $ddl): string
{
    $ddl = preg_replace('/AUTO_INCREMENT=\d+ /', '', $ddl) ?? $ddl;
    // A collation determines its charset. MySQL versions differ in whether
    // SHOW CREATE explicitly repeats CHARACTER SET for the same collation.
    return preg_replace('/\bCHARACTER SET ([a-zA-Z0-9]+) COLLATE (\1_[a-zA-Z0-9_]+)/', 'COLLATE $2', $ddl) ?? $ddl;
}

function cart_probe_fingerprint(PDO $pdo): array
{
    $result = [];
    foreach (cart_probe_tables($pdo) as $table) {
        cart_probe_assert(preg_match('/^[a-z0-9_]+$/', $table) === 1, 'unsafe_table');
        $ddl = $pdo->query('SHOW CREATE TABLE `' . $table . '`')->fetch(PDO::FETCH_NUM)[1];
        $ddl = cart_probe_schema_text($ddl);
        $rows = $pdo->query('SELECT * FROM `' . $table . '`')->fetchAll(PDO::FETCH_ASSOC);
        $hashes = array_map(cart_probe_row_hash(...), $rows);
        sort($hashes, SORT_STRING);
        $result[$table] = ['schema' => hash('sha256', $ddl), 'contents' => hash('sha256', implode('\n', $hashes)), 'rows' => count($rows)];
    }
    ksort($result, SORT_STRING);
    return $result;
}

function cart_probe_row_hash(array $row): string
{
    // SQL BLOBs (encrypted webhook payloads) need lossless byte encoding,
    // not JSON UTF-8 substitution. Preserve NULL/numeric/string distinctions.
    $encoded = [];
    foreach ($row as $key => $value) {
        cart_probe_assert($value === null || is_scalar($value), 'unsupported_sql_value');
        $encoded[$key] = is_string($value) ? ['stringBytesBase64' => base64_encode($value)] : $value;
    }
    return carmaja_commerce_request_hash($encoded);
}

function cart_probe_backup(?string $directory): CarmajaCommerceBackup
{
    if ($directory !== null) {
        $real = realpath($directory);
        cart_probe_assert(is_string($real) && $real === $directory && !is_link($directory)
            && preg_match('#^/home/www/carmaja-private-test/cart-e2e-[A-Za-z0-9-]+/mysql-client/usr/bin$#', $real) === 1,
            'private_mysql_client_directory_required');
    }
    $directory ??= '/usr/bin';
    foreach (['mysql','mysqldump'] as $binary) {
        $path = $directory . '/' . $binary;
        cart_probe_assert(is_file($path) && is_executable($path) && !is_link($path), 'mysql8_client_missing');
        $process = proc_open([$path, '--no-defaults', '--version'], [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']], $pipes);
        cart_probe_assert(is_resource($process), 'mysql8_client_start_failed');
        fclose($pipes[0]);
        $version = stream_get_contents($pipes[1]);
        stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]);
        cart_probe_assert(proc_close($process) === 0 && preg_match('/(?:Ver|Distrib)\s+8\./', $version) === 1
            && !str_contains($version, 'MariaDB'), 'mysql8_tls_required_client_missing');
    }
    return new CarmajaCommerceBackup($directory . '/mysqldump', $directory . '/mysql');
}

function cart_probe_session(CarmajaCartCommerce $commerce, string $label): string
{
    $owner = hash('sha256', 'artificial-cart-' . $label);
    $commerce->upsertShopSession($owner, str_repeat('c', 64), gmdate('Y-m-d H:i:s', time()+3600),
        str_repeat('d', 64), gmdate('Y-m-d H:i:s', time()+600), gmdate('Y-m-d H:i:s', time()+86400));
    return $owner;
}

function cart_probe_reserve(CarmajaCartCommerce $commerce, string $owner, array $items, string $key): array
{
    $cart = $commerce->readCart($owner);
    $commerce->replaceCart($owner, ['items' => $items, 'expectedCartVersion' => $cart['cartVersion']], 'cart-' . $key);
    $quote = $commerce->quoteCart($owner, ['shippingMethodId'=>'maxibrief','amountMinor'=>270,'currency'=>'eur','country'=>'DE'], 'cart-e2e-legal');
    return $commerce->reserveCart($owner, ['expectedCartVersion'=>$quote['cartVersion'], 'checkoutContextToken'=>$quote['checkoutContextToken']], $key);
}

function cart_probe_payment(array $reservation, string $method, string $status): array
{
    $snap = $reservation['snapshot'];
    return ['paymentStatus'=>$status,'paymentIntentStatus'=>$status,'paymentMethodType'=>$method,
        'stripeCheckoutSessionId'=>'cs_test_' . $snap['checkoutId'], 'stripePaymentIntentId'=>'pi_artificial_' . $snap['checkoutId'],
        'amountMinor'=>$snap['totalMinor'],'currency'=>'eur','legalBundleId'=>$snap['legalBundleId'],'termsAccepted'=>true,
        'metadata'=>['checkoutId'=>$snap['checkoutId'],'snapshotHash'=>$snap['snapshotHash']],
        'customerEmail'=>'cart-artificial@example.com','customerName'=>'Künstlicher Test',
        'shippingAddress'=>['line1'=>'Teststraße 1','city'=>'Berlin','postal_code'=>'10115','country'=>'DE']];
}

function cart_probe_client(string $path, string $gate, string $label): void
{
    $config = cart_probe_config($path);
    $real = realpath($gate);
    cart_probe_assert(is_string($real) && str_starts_with($real, '/home/www/carmaja-private-test/cart-probe-')
        && !is_link($gate) && (fileperms($gate) & 0777) === 0600, 'controller_missing');
    $controller = json_decode((string) file_get_contents($gate), true, 8, JSON_THROW_ON_ERROR);
    cart_probe_assert(($controller['configuration'] ?? '') === realpath($path)
        && ($controller['deadline'] ?? 0) > time() && preg_match('/^client-[0-9]{1,2}$/', $label) === 1, 'controller_invalid');
    $pdo = cart_probe_connect($config['source'], $config);
    $commerce = new CarmajaCartCommerce($pdo);
    try {
        cart_probe_reserve($commerce, cart_probe_session($commerce, $label), [['productId'=>'CART-E2E-LAST','quantity'=>1]], 'last-' . $label);
        echo "winner\n";
    } catch (CarmajaCommerceException $error) {
        cart_probe_assert(in_array($error->errorCode, ['cart_not_buyable','cart_snapshot_stale'], true), 'parallel_unexpected_error');
        echo "unavailable\n";
    }
}

function cart_probe_parallel(string $path, string $gate): void
{
    $jobs = [];
    try {
        for ($index=1; $index<=10; $index++) {
            $process = proc_open([PHP_BINARY, __FILE__, '--client', $path, $gate, 'client-' . $index],
                [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']], $pipes);
            cart_probe_assert(is_resource($process), 'parallel_start_failed');
            fclose($pipes[0]);
            $jobs[] = [$process, $pipes];
        }
        $winners = 0;
        foreach ($jobs as [$process,$pipes]) {
            $output = stream_get_contents($pipes[1]);
            $diagnostic = stream_get_contents($pipes[2]);
            fclose($pipes[1]); fclose($pipes[2]);
            $exit = proc_close($process);
            if ($exit !== 0) {
                // Only our fixed code-only diagnostics, never raw provider output.
                preg_match_all('/^(?:FAIL [a-z_]+; no acceptance granted\.|SQLSTATE [A-Z0-9]{5}; driver code [0-9]+; file [a-z0-9-]+\.php; line [0-9]+)$/m',
                    $diagnostic, $safeLines);
                foreach ($safeLines[0] as $safeLine) { fwrite(STDERR, $safeLine . "\n"); }
            }
            cart_probe_assert($exit === 0 && in_array(trim($output), ['winner','unavailable'], true), 'parallel_client_failed');
            if (trim($output) === 'winner') { $winners++; }
        }
        cart_probe_assert($winners === 1, 'last_stock_oversold');
    } finally {
        foreach ($jobs as [$process,$pipes]) {
            if (is_resource($process)) { proc_terminate($process); proc_close($process); }
            foreach ($pipes as $pipe) { if (is_resource($pipe)) { fclose($pipe); } }
        }
    }
}

function cart_probe_main(string $path, bool $includeBackup = true, ?string $clientDirectory = null, ?callable $additionalTests = null): void
{
    $config = cart_probe_config($path);
    cart_probe_assert($config['source']['database'] === 'dbs15962244'
        && $config['restore']['database'] === 'dbs15962566', 'test_identity_mismatch');
    $source = cart_probe_connect($config['source'], $config);
    $restore = cart_probe_connect($config['restore'], $config);
    cart_probe_assert(cart_probe_empty($source) && cart_probe_empty($restore), 'both_targets_must_be_empty');
    $backup = $includeBackup ? cart_probe_backup($clientDirectory) : null;
    echo "PASS preflight: fixed separate empty MySQL 8 targets, InnoDB, PHP 8.4 and active TLS\n";
    $temporary = tempnam('/home/www/carmaja-private-test', 'cart-probe-');
    cart_probe_assert(is_string($temporary), 'private_temp_failed');
    chmod($temporary, 0600);
    $gate = $temporary . '-controller';
    $allowedTables = [];
    foreach ([dirname(__DIR__) . '/database/commerce-schema.sql', ...glob(dirname(__DIR__) . '/database/migrations/*.sql')] as $file) {
        preg_match_all('/CREATE TABLE(?: IF NOT EXISTS)?\s+([a-z_]+)/i', (string) file_get_contents($file), $matches);
        $allowedTables = [...$allowedTables, ...$matches[1]];
    }
    try {
        $commerce = new CarmajaCartCommerce($source);
        $commerce->migrate(dirname(__DIR__) . '/database/commerce-schema.sql');
        foreach (['commerce-v1-ap4-shop','commerce-v1-ap5-admin','commerce-v2-collections','commerce-v3-cart-inventory'] as $id) {
            $file = dirname(__DIR__) . '/database/migrations/' . $id . '.sql';
            $commerce->migrateForward($id, $file);
            $commerce->migrateForward($id, $file);
        }
        echo "PASS forward migrations and journal idempotency\n";
        $source->exec("INSERT INTO legal_bundles(legal_bundle_id,terms_version,privacy_version,withdrawal_version,shipping_version,merchant_version,bundle_hash,status)
            VALUES ('cart-e2e-legal','test','test','test','test','test',REPEAT('a',64),'approved')");
        foreach (['CART-E2E-A','CART-E2E-B','CART-E2E-LAST'] as $id) {
            $product = ['productId'=>$id,'productModelVersion'=>4,'productVersion'=>1,'sourceHash'=>str_repeat('b',64),
                'title'=>'Künstliches Testarmband','description'=>'Test','materials'=>['Test'],'metalElements'=>[],
                'braceletSizeCm'=>18,'pearlSizeMm'=>8,'careInstructions'=>[],'images'=>[['src'=>'/images/products/test/01.jpg']],
                'priceMinor'=>2800,'currency'=>'eur','noticePolicyId'=>'natural-stones-v1','notices'=>CARMAJA_PRODUCT_NOTICES];
            $commerce->projectCollectionProduct($product, 'publish', 'publish-' . $id, hash('sha256', $id));
            $commerce->adjustInventory(['productId'=>$id,'targetOnHand'=>$id === 'CART-E2E-LAST' ? 1 : 4,'expectedInventoryVersion'=>0,'reason'=>'initial_count',
                'correlationId'=>'initial-' . $id,'idempotencyKey'=>'initial-' . $id], 'test-operator');
        }
        $first = cart_probe_reserve($commerce, cart_probe_session($commerce, 'first'), [['productId'=>'CART-E2E-B','quantity'=>2],['productId'=>'CART-E2E-A','quantity'=>3]], 'reserve-first');
        cart_probe_assert($commerce->inventory('CART-E2E-A')['reserved'] === 3 && $commerce->inventory('CART-E2E-B')['reserved'] === 2, 'quantity_reservation_failed');
        $before = cart_probe_fingerprint($source);
        try {
            cart_probe_reserve($commerce, cart_probe_session($commerce, 'second'), [['productId'=>'CART-E2E-A','quantity'=>2],['productId'=>'CART-E2E-B','quantity'=>1]], 'reserve-second');
            throw new RuntimeException('overlapping_cart_was_accepted');
        } catch (CarmajaCommerceException $error) { cart_probe_assert(in_array($error->errorCode, ['cart_not_buyable','cart_snapshot_stale'], true), 'unexpected_cart_error'); }
        cart_probe_assert($before['reservations'] === cart_probe_fingerprint($source)['reservations'], 'partial_reservation_created');
        echo "PASS quantity reservation and overlapping cart: no partial reservation\n";
        $checkout = $first['snapshot']['checkoutId'];
        $commerce->recordStripeCreationOutcome($checkout, 'created', 'cs_test_' . $checkout);
        $commerce->markPaymentProcessing($checkout, cart_probe_payment($first, 'sepa_debit', 'processing'));
        $commerce->releaseExpiredReservation($checkout);
        cart_probe_assert($commerce->inventory('CART-E2E-A')['reserved'] === 3, 'processing_reservation_released');
        $result = $commerce->finalizePayment($checkout, cart_probe_payment($first, 'sepa_debit', 'succeeded'));
        $after = cart_probe_fingerprint($source);
        $commerce->finalizePayment($checkout, cart_probe_payment($first, 'sepa_debit', 'succeeded'));
        cart_probe_assert($after === cart_probe_fingerprint($source), 'duplicate_payment_changed_data');
        cart_probe_assert($commerce->inventory('CART-E2E-A')['onHand'] === 1 && $commerce->inventory('CART-E2E-B')['onHand'] === 2, 'multi_finalization_failed');
        echo "PASS SEPA hold, atomic multi-position finalization and payment replay\n";
        $order = $commerce->loadAdminOrder($result['orderId']);
        $paymentId = $source->query("SELECT payment_id FROM payments WHERE checkout_id='" . $checkout . "'")->fetchColumn();
        $commerce->applyRefund($paymentId, 're_artificial_partial', 'succeeded', 2800);
        cart_probe_assert($source->query("SELECT refund_status FROM payments WHERE checkout_id='" . $checkout . "'")->fetchColumn() === 'partial', 'partial_refund_failed');
        $return = ['positionNo'=>1,'quantity'=>1,'expectedInventoryVersion'=>$commerce->inventory('CART-E2E-A')['inventoryVersion'],
            'correlationId'=>'return-test-0001','receivedAndChecked'=>true];
        $commerce->restockPosition($result['orderId'], $return, 'test-operator', 'restock-test-0001');
        $commerce->restockPosition($result['orderId'], $return, 'test-operator', 'restock-test-0001');
        cart_probe_assert($commerce->inventory('CART-E2E-A')['onHand'] === 2 && count($order['items']) === 2, 'restock_idempotency_failed');
        echo "PASS partial refund, checked return and restock idempotency\n";
        cart_probe_assert(file_put_contents($gate, json_encode(['configuration'=>realpath($path),'deadline'=>time()+60], JSON_THROW_ON_ERROR), LOCK_EX) !== false, 'controller_write_failed');
        chmod($gate, 0600);
        cart_probe_parallel($path, $gate);
        cart_probe_assert($commerce->inventory('CART-E2E-LAST')['reserved'] === 1, 'parallel_last_stock_failed');
        echo "PASS ten parallel clients: exactly one reservation for the last piece\n";
        if ($additionalTests !== null) { $additionalTests($commerce, $source, $config, $path); }
        if ($includeBackup) {
            $targets = [];
            foreach (['source','restore'] as $label) { $targets[$label] = [...$config[$label], 'user'=>$config[$label]['username'],'requireTls'=>true,'tlsCaPath'=>$config['tlsCaPath'] ?? '']; }
            $backup->backupRestoreCompare($targets, $temporary);
            $sourceFingerprint = cart_probe_fingerprint($source);
            $restoreFingerprint = cart_probe_fingerprint($restore);
            foreach ($sourceFingerprint as $table => $fingerprint) {
                $restored = $restoreFingerprint[$table] ?? [];
                if ($fingerprint !== $restored) {
                    echo 'MISMATCH ' . $table . ': schema=' . (($fingerprint['schema'] === ($restored['schema'] ?? null)) ? 'same' : 'different')
                        . '; contents=' . (($fingerprint['contents'] === ($restored['contents'] ?? null)) ? 'same' : 'different')
                        . '; rowCount=' . (($fingerprint['rows'] === ($restored['rows'] ?? null)) ? 'same' : 'different') . "\n";
                }
            }
            cart_probe_assert($sourceFingerprint === $restoreFingerprint, 'restore_comparison_failed');
            echo "PASS backup/restore schema and content comparison\n";
        } else {
            echo "NOT RUN backup/restore: explicit functional-only run; no complete acceptance granted\n";
        }
    } catch (Throwable $error) {
        cart_probe_report($error);
        throw $error;
    } finally {
        // Only these initially-empty explicitly authorized test databases were touched.
        // Discard failed transactions/result sets before using fresh cleanup connections.
        $commerce = null;
        $source = null;
        $restore = null;
        $clean = true;
        foreach (['source','restore'] as $label) {
            try {
                $pdo = cart_probe_connect($config[$label], $config);
                $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
                foreach (cart_probe_tables($pdo) as $table) {
                    cart_probe_assert(preg_match('/^[a-z0-9_]+$/', $table) === 1 && in_array($table, $allowedTables, true), 'unsafe_cleanup_identifier');
                    $pdo->exec('DROP TABLE `' . $table . '`');
                }
                $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
                $clean = $clean && cart_probe_empty($pdo);
            } catch (Throwable $error) { cart_probe_report($error); $clean = false; }
        }
        @unlink($temporary);
        @unlink($gate);
        cart_probe_assert($clean && !file_exists($temporary) && !file_exists($gate), 'cleanup_failed');
        echo "PASS cleanup: both authorized test targets empty; temporary dump removed\n";
    }
}

if (PHP_SAPI === 'cli' && realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    try {
        cart_probe_assert(PHP_VERSION_ID >= 80400 && PHP_VERSION_ID < 80500, 'php84_required');
        if (($argv[1] ?? '') === '--client') { cart_probe_client($argv[2] ?? '', $argv[3] ?? '', $argv[4] ?? ''); }
        elseif (($argv[1] ?? '') === '--functional-only') {
            cart_probe_main($argv[2] ?? '/home/www/carmaja-private-test/shop-cart-test.json', false);
        } elseif (($argv[1] ?? '') === '--mysql-client-directory') {
            cart_probe_main($argv[3] ?? '/home/www/carmaja-private-test/shop-cart-test.json', true, $argv[2] ?? '');
        } else { cart_probe_main($argv[1] ?? '/home/www/carmaja-private-test/shop-cart-test.json'); }
    } catch (Throwable $error) {
        // Database/provider errors may contain credentials: never echo their message.
        cart_probe_report($error);
        exit(1);
    }
}
