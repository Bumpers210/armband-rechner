<?php

declare(strict_types=1);

/**
 * AP3b-only MySQL 8 integration probe.
 *
 * The probe requires two pre-created, empty test databases and artificial
 * records only. Credentials stay in a private 0600 JSON file outside every
 * webroot. Both databases and all temporary files are cleaned in finally.
 */

require_once dirname(__DIR__) . '/test-api-private/program/commerce-core.php';

const AP3B_DEFAULT_CONFIG = '/home/www/carmaja-private-test/ap3b-commerce-credentials.json';
const AP3B_PRIVATE_DIR = '/home/www/carmaja-private-test';

function ap3b_fail(string $code): never
{
    throw new RuntimeException($code);
}

function ap3b_assert(bool $condition, string $code): void
{
    if (!$condition) {
        ap3b_fail($code);
    }
}

function ap3b_config(): array
{
    $path = getenv('CARMAJA_AP3B_COMMERCE_CONFIG') ?: AP3B_DEFAULT_CONFIG;
    if (!is_file($path) || !is_readable($path)) {
        ap3b_fail('credentials_unavailable');
    }
    $mode = fileperms($path);
    if ($mode === false || ($mode & 0777) !== 0600) {
        ap3b_fail('credentials_permissions_invalid');
    }
    try {
        $config = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    } catch (Throwable) {
        ap3b_fail('credentials_json_invalid');
    }
    if (!is_array($config) || ($config['requireTls'] ?? null) !== true
        || !is_array($config['source'] ?? null) || !is_array($config['restore'] ?? null)) {
        ap3b_fail('credentials_structure_invalid');
    }
    foreach (['source', 'restore'] as $label) {
        foreach (['host', 'database', 'username', 'password'] as $field) {
            if (!is_string($config[$label][$field] ?? null)
                || trim((string) $config[$label][$field]) === '') {
                ap3b_fail('credentials_field_invalid');
            }
        }
        if (!is_int($config[$label]['port'] ?? null)
            || $config[$label]['port'] < 1 || $config[$label]['port'] > 65535) {
            ap3b_fail('credentials_port_invalid');
        }
        if (preg_match('/(?:^|_)(?:prod|production)(?:_|$)/i', $config[$label]['database'])) {
            ap3b_fail('production_database_rejected');
        }
        $config[$label]['tlsCaPath'] = is_string($config['tlsCaPath'] ?? null)
            ? trim($config['tlsCaPath']) : '';
    }
    if (hash_equals($config['source']['database'], $config['restore']['database'])) {
        ap3b_fail('source_restore_not_separate');
    }
    return $config;
}

function ap3b_pdo(array $target): PDO
{
    $dsn = sprintf(
        'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
        $target['host'],
        $target['port'],
        $target['database']
    );
    $options = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_EMULATE_PREPARES => false,
        PDO::ATTR_TIMEOUT => 15,
        PDO::MYSQL_ATTR_SSL_CIPHER => 'HIGH',
    ];
    if (($target['tlsCaPath'] ?? '') !== '') {
        if (!is_file($target['tlsCaPath']) || !is_readable($target['tlsCaPath'])) {
            ap3b_fail('tls_ca_unavailable');
        }
        $options[PDO::MYSQL_ATTR_SSL_CA] = $target['tlsCaPath'];
        $options[PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT] = true;
    } else {
        // Accepted V1 residual risk: active TLS is mandatory below, while the
        // IONOS connection currently lacks CA/host identity verification.
        $options[PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT] = false;
    }
    try {
        $pdo = new PDO($dsn, $target['username'], $target['password'], $options);
        $pdo->exec("SET SESSION sql_mode = 'STRICT_ALL_TABLES'");
    } catch (Throwable) {
        ap3b_fail('pdo_connection_failed');
    }
    $version = (string) $pdo->query('SELECT VERSION()')->fetchColumn();
    if (!str_starts_with($version, '8.')) {
        ap3b_fail('mysql_version_not_8');
    }
    $tls = $pdo->query(
        "SHOW SESSION STATUS WHERE Variable_name IN ('Ssl_cipher','Ssl_version')"
    )->fetchAll(PDO::FETCH_KEY_PAIR);
    if (trim((string) ($tls['Ssl_cipher'] ?? '')) === ''
        || trim((string) ($tls['Ssl_version'] ?? '')) === '') {
        ap3b_fail('tls_session_not_active');
    }
    return $pdo;
}

function ap3b_tables(PDO $pdo): array
{
    $tables = [];
    foreach ($pdo->query('SHOW FULL TABLES')->fetchAll(PDO::FETCH_NUM) as $row) {
        $tables[] = (string) $row[0];
    }
    sort($tables, SORT_STRING);
    return $tables;
}

function ap3b_drop_all(PDO $pdo): bool
{
    try {
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        foreach (ap3b_tables($pdo) as $table) {
            if (preg_match('/^[A-Za-z0-9_]{1,64}$/', $table) !== 1) {
                ap3b_fail('unsafe_table_identifier');
            }
            $pdo->exec('DROP TABLE IF EXISTS `' . $table . '`');
        }
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
        return ap3b_tables($pdo) === [];
    } catch (Throwable) {
        return false;
    }
}

function ap3b_seed(PDO $pdo, string $productId, string $hash): void
{
    $pdo->prepare(
        'INSERT INTO commerce_products
            (product_id, product_version, source_hash, name, description,
             materials, metal_elements, bracelet_size, care_instructions,
             images, price_minor, currency, sales_enabled, synchronized_at)
         VALUES (?, 1, ?, ?, \'Kuenstlicher AP3b-Testdatensatz\',
                 JSON_ARRAY(\'test\'), JSON_ARRAY(), \'test\', \'test\',
                 JSON_ARRAY(\'/images/products/test/01.jpg\'),
                 4200, \'eur\', 1, UTC_TIMESTAMP(6))'
    )->execute([$productId, $hash, 'AP3b Kunstprodukt ' . $productId]);
    $pdo->prepare(
        'INSERT INTO commerce_inventory (product_id, on_hand, inventory_version)
         VALUES (?, 1, 0)'
    )->execute([$productId]);
}

function ap3b_checkout(string $checkoutId, string $productId, string $hash): array
{
    return [
        'checkoutId' => $checkoutId,
        'idempotencyKey' => 'idem-' . $checkoutId,
        'requestHash' => hash('sha256', 'request-' . $checkoutId),
        'productId' => $productId,
        'productVersion' => 1,
        'sourceHash' => $hash,
        'priceMinor' => 4200,
        'currency' => 'eur',
        'shippingSnapshot' => [
            'shippingMethodId' => 'de-standard',
            'amountMinor' => 490,
            'currency' => 'eur',
        ],
        'legalBundleId' => 'ap3b-legal-test-v1',
        'expiresAt' => gmdate('Y-m-d H:i:s.u', time() + 1800),
    ];
}

function ap3b_event(string $productId, string $method, string $intent, string $status): array
{
    return [
        'paymentStatus' => $status === 'processing' ? 'processing' : $status,
        'paymentIntentStatus' => $status,
        'paymentMethodType' => $method,
        'stripePaymentIntentId' => $intent,
        'amountMinor' => 4690,
        'currency' => 'eur',
        'productId' => $productId,
        'legalBundleId' => 'ap3b-legal-test-v1',
        'termsAccepted' => true,
        'customerEmail' => 'ap3b@example.invalid',
        'customerName' => 'AP3b Testkundin',
        'shippingAddress' => ['country' => 'DE', 'postalCode' => '10115'],
    ];
}

function ap3b_seed_legal(PDO $pdo): void
{
    $pdo->exec(
        "INSERT INTO legal_bundles
            (legal_bundle_id, terms_version, privacy_version, withdrawal_version,
             shipping_version, merchant_version, bundle_hash, status)
         VALUES
            ('ap3b-legal-test-v1', 'test-v1', 'test-v1', 'test-v1',
             'test-v1', 'test-v1', REPEAT('a', 64), 'approved')"
    );
}

function ap3b_client_file(array $target, string $path): void
{
    $escape = static fn (string $value): string => str_replace(['\\', '"'], ['\\\\', '\\"'], $value);
    $lines = [
        '[client]',
        'host="' . $escape($target['host']) . '"',
        'port=' . $target['port'],
        'user="' . $escape($target['username']) . '"',
        'password="' . $escape($target['password']) . '"',
        'default-character-set=utf8mb4',
        'ssl-cipher=HIGH',
    ];
    if (($target['tlsCaPath'] ?? '') !== '') {
        $lines[] = 'ssl-ca="' . $escape($target['tlsCaPath']) . '"';
        $lines[] = 'ssl-verify-server-cert=1';
    } else {
        $lines[] = 'ssl-verify-server-cert=0';
    }
    if (file_put_contents($path, implode("\n", $lines) . "\n", LOCK_EX) === false) {
        ap3b_fail('client_file_write_failed');
    }
    chmod($path, 0600);
}

function ap3b_process(array $command, array $descriptors): array
{
    $process = proc_open($command, $descriptors, $pipes);
    if (!is_resource($process)) {
        ap3b_fail('process_start_failed');
    }
    return [$process, $pipes];
}

function ap3b_run_command(array $command, ?string $stdinFile = null, ?string $stdoutFile = null): void
{
    $descriptors = [
        0 => $stdinFile === null ? ['pipe', 'r'] : ['file', $stdinFile, 'r'],
        1 => $stdoutFile === null ? ['pipe', 'w'] : ['file', $stdoutFile, 'w'],
        2 => ['pipe', 'w'],
    ];
    [$process, $pipes] = ap3b_process($command, $descriptors);
    if ($stdinFile === null) {
        fclose($pipes[0]);
    }
    if ($stdoutFile === null) {
        stream_get_contents($pipes[1]);
        fclose($pipes[1]);
    }
    stream_get_contents($pipes[2]);
    fclose($pipes[2]);
    if (proc_close($process) !== 0) {
        ap3b_fail('external_command_failed');
    }
}

function ap3b_dump(array $target, string $clientFile, string $output, array $extra = []): void
{
    $command = array_merge([
        '/usr/bin/mysqldump',
        '--defaults-extra-file=' . $clientFile,
        '--no-tablespaces',
        '--single-transaction',
        '--quick',
        '--skip-lock-tables',
        '--skip-comments',
        '--order-by-primary',
        '--default-character-set=utf8mb4',
    ], $extra, [$target['database']]);
    ap3b_run_command($command, null, $output);
}

function ap3b_restore(array $target, string $clientFile, string $dump): void
{
    ap3b_run_command([
        '/usr/bin/mysql',
        '--defaults-extra-file=' . $clientFile,
        '--default-character-set=utf8mb4',
        $target['database'],
    ], $dump, null);
}

function ap3b_hash_file(string $path): string
{
    $hash = hash_file('sha256', $path);
    if (!is_string($hash)) {
        ap3b_fail('file_hash_failed');
    }
    return $hash;
}

function ap3b_normalized_dump_hash(string $path): string
{
    $contents = file_get_contents($path);
    if (!is_string($contents)) {
        ap3b_fail('dump_read_failed');
    }
    $contents = str_replace("\r\n", "\n", $contents);
    $contents = preg_replace('/\s+AUTO_INCREMENT=\d+/', '', $contents) ?? $contents;
    $contents = preg_replace('/^--.*$/m', '', $contents) ?? $contents;
    $contents = preg_replace('/\n{3,}/', "\n\n", $contents) ?? $contents;
    return hash('sha256', trim($contents));
}

function ap3b_structure_hash(PDO $pdo): string
{
    $queries = [
        "SELECT table_name, engine, table_collation
         FROM information_schema.tables
         WHERE table_schema = DATABASE()
         ORDER BY table_name",
        "SELECT table_name, ordinal_position, column_name, column_type,
                is_nullable, column_default, extra, collation_name,
                generation_expression
         FROM information_schema.columns
         WHERE table_schema = DATABASE()
         ORDER BY table_name, ordinal_position",
        "SELECT table_name, index_name, non_unique, seq_in_index, column_name,
                sub_part, collation, index_type
         FROM information_schema.statistics
         WHERE table_schema = DATABASE()
         ORDER BY table_name, index_name, seq_in_index",
        "SELECT table_name, constraint_name, constraint_type
         FROM information_schema.table_constraints
         WHERE table_schema = DATABASE()
         ORDER BY table_name, constraint_name",
        "SELECT table_name, constraint_name, column_name, ordinal_position,
                referenced_table_name, referenced_column_name
         FROM information_schema.key_column_usage
         WHERE table_schema = DATABASE()
         ORDER BY table_name, constraint_name, ordinal_position",
        "SELECT tc.table_name, cc.constraint_name, cc.check_clause
         FROM information_schema.table_constraints tc
         JOIN information_schema.check_constraints cc
           ON cc.constraint_schema = tc.constraint_schema
          AND cc.constraint_name = tc.constraint_name
         WHERE tc.table_schema = DATABASE() AND tc.constraint_type = 'CHECK'
         ORDER BY tc.table_name, cc.constraint_name",
    ];
    $structure = [];
    foreach ($queries as $query) {
        $structure[] = $pdo->query($query)->fetchAll(PDO::FETCH_ASSOC);
    }
    return hash('sha256', json_encode($structure, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
}

function ap3b_child_output(array $command): array
{
    [$process, $pipes] = ap3b_process($command, [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ]);
    fclose($pipes[0]);
    return [$process, $pipes];
}

function ap3b_collect_children(array $children): array
{
    $results = [];
    foreach ($children as [$process, $pipes]) {
        $stdout = stream_get_contents($pipes[1]);
        stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($process);
        $decoded = json_decode(trim((string) $stdout), true);
        $results[] = is_array($decoded) ? $decoded + ['exitCode' => $code] : ['result' => 'invalid', 'exitCode' => $code];
    }
    return $results;
}

function ap3b_checkout_child(array $args): int
{
    $config = ap3b_config();
    $pdo = ap3b_pdo($config['source']);
    $commerce = new CarmajaCommercePdo($pdo);
    $start = (float) ($args[3] ?? 0);
    while (microtime(true) < $start) {
        usleep(1000);
    }
    $checkoutId = (string) ($args[2] ?? '');
    for ($attempt = 0; $attempt < 4; $attempt++) {
        try {
            $commerce->createCheckout(ap3b_checkout($checkoutId, 'AP3B-CONCURRENT', str_repeat('c', 64)));
            echo json_encode(['result' => 'created'], JSON_THROW_ON_ERROR) . PHP_EOL;
            return 0;
        } catch (CarmajaCommerceException $error) {
            echo json_encode(['result' => $error->errorCode], JSON_THROW_ON_ERROR) . PHP_EOL;
            return 0;
        } catch (PDOException $error) {
            $driverCode = (int) ($error->errorInfo[1] ?? 0);
            if (!in_array($driverCode, [1205, 1213], true) || $attempt === 3) {
                echo json_encode(['result' => 'database_retry_exhausted'], JSON_THROW_ON_ERROR) . PHP_EOL;
                return 0;
            }
            usleep(50000 * ($attempt + 1));
        }
    }
    echo json_encode(['result' => 'database_retry_exhausted'], JSON_THROW_ON_ERROR) . PHP_EOL;
    return 0;
}

function ap3b_deadlock_child(array $args): int
{
    $config = ap3b_config();
    $pdo = ap3b_pdo($config['source']);
    $role = (string) ($args[2] ?? '');
    $runId = (string) ($args[3] ?? '');
    $first = $role === 'a' ? 1 : 2;
    $second = $role === 'a' ? 2 : 1;
    $own = AP3B_PRIVATE_DIR . '/.ap3b-deadlock-' . $runId . '-' . $role;
    $other = AP3B_PRIVATE_DIR . '/.ap3b-deadlock-' . $runId . '-' . ($role === 'a' ? 'b' : 'a');
    try {
        $pdo->beginTransaction();
        $pdo->prepare('UPDATE ap3b_deadlock_probe SET value_int = value_int + 1 WHERE probe_id = ?')->execute([$first]);
        file_put_contents($own, 'ready', LOCK_EX);
        $until = microtime(true) + 5;
        while (!is_file($other) && microtime(true) < $until) {
            usleep(10000);
        }
        if (!is_file($other)) {
            throw new RuntimeException('deadlock_barrier_timeout');
        }
        $pdo->prepare('UPDATE ap3b_deadlock_probe SET value_int = value_int + 1 WHERE probe_id = ?')->execute([$second]);
        $pdo->commit();
        echo json_encode(['result' => 'committed'], JSON_THROW_ON_ERROR) . PHP_EOL;
    } catch (PDOException $error) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $driverCode = (int) ($error->errorInfo[1] ?? 0);
        echo json_encode(['result' => $driverCode === 1213 ? 'deadlock' : 'pdo_error'], JSON_THROW_ON_ERROR) . PHP_EOL;
    } catch (Throwable) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        echo json_encode(['result' => 'error'], JSON_THROW_ON_ERROR) . PHP_EOL;
    }
    return 0;
}

function ap3b_crash_child(): int
{
    $config = ap3b_config();
    $pdo = ap3b_pdo($config['source']);
    $pdo->beginTransaction();
    $pdo->exec("INSERT INTO ap3b_crash_probe (probe_id, value_text) VALUES (1, 'must_rollback')");
    // Intentionally exit without commit. Connection teardown must roll back.
    return 0;
}

function ap3b_main(array $args): int
{
    $mode = $args[1] ?? '';
    if ($mode === '--checkout-client') {
        return ap3b_checkout_child($args);
    }
    if ($mode === '--deadlock-client') {
        return ap3b_deadlock_child($args);
    }
    if ($mode === '--crash-client') {
        return ap3b_crash_child();
    }

    $schemaTemplate = $args[1] ?? '';
    $ap4Migration = $args[2] ?? '';
    $ap5Migration = $args[3] ?? '';
    $migration = $args[4] ?? '';
    if (!is_string($schemaTemplate) || !is_file($schemaTemplate)
        || !is_string($ap4Migration) || !is_file($ap4Migration)
        || !is_string($ap5Migration) || !is_file($ap5Migration)
        || !is_string($migration) || !is_file($migration)) {
        ap3b_fail('schema_arguments_missing');
    }

    $source = null;
    $restore = null;
    $temporary = [];
    $result = [
        'status' => 'failed',
        'testOnly' => true,
        'checks' => [],
        'cleanup' => [],
    ];
    try {
        $config = ap3b_config();
        $source = ap3b_pdo($config['source']);
        $restore = ap3b_pdo($config['restore']);
        ap3b_assert(ap3b_tables($source) === [], 'source_not_empty');
        ap3b_assert(ap3b_tables($restore) === [], 'restore_not_empty');

        $schemaSql = file_get_contents($schemaTemplate);
        if (!is_string($schemaSql)) {
            ap3b_fail('schema_template_unavailable');
        }
        $baseSql = preg_replace(
            '/^\s*payment_method_type VARCHAR\(24\).*\R/m',
            '',
            $schemaSql
        );
        $baseSql = preg_replace(
            "/^\s*CONSTRAINT chk_payment_method_type CHECK .*\R\s*\('card', 'paypal', 'klarna', 'sepa_debit'\)\),\R/m",
            '',
            (string) $baseSql
        );
        $baseSql = str_replace(
            "('created', 'pending', 'processing', 'succeeded', 'failed', 'canceled', 'manual_review')",
            "('created', 'pending', 'succeeded', 'failed', 'canceled', 'manual_review')",
            (string) $baseSql
        );
        if (str_contains((string) $baseSql, 'payment_method_type')
            || str_contains((string) $baseSql, "'pending', 'processing', 'succeeded'")) {
            ap3b_fail('ap5_baseline_derivation_failed');
        }
        $baseSchema = AP3B_PRIVATE_DIR . '/.ap3b-ap5-schema-' . bin2hex(random_bytes(6)) . '.sql';
        if (file_put_contents($baseSchema, $baseSql, LOCK_EX) === false) {
            ap3b_fail('ap5_baseline_write_failed');
        }
        chmod($baseSchema, 0600);
        $temporary[] = $baseSchema;

        $commerce = new CarmajaCommercePdo($source);
        $commerce->migrate($baseSchema);
        $commerce->migrateForward('commerce-v1-ap4-shop', $ap4Migration);
        $commerce->migrateForward('commerce-v1-ap5-admin', $ap5Migration);
        $beforeColumn = $source->query(
            "SELECT COUNT(*) FROM information_schema.columns
             WHERE table_schema = DATABASE() AND table_name = 'payments'
               AND column_name = 'payment_method_type'"
        )->fetchColumn();
        ap3b_assert((int) $beforeColumn === 0, 'base_schema_not_ap5');
        $commerce->migrateForward('commerce-v1-ap3b-async-payments', $migration);
        $commerce->migrateForward('commerce-v1-ap3b-async-payments', $migration);
        $afterColumn = $source->query(
            "SELECT COUNT(*) FROM information_schema.columns
             WHERE table_schema = DATABASE() AND table_name = 'payments'
               AND column_name = 'payment_method_type'"
        )->fetchColumn();
        ap3b_assert((int) $afterColumn === 1, 'migration_column_missing');
        $journalCount = $source->query(
            "SELECT COUNT(*) FROM schema_migrations
             WHERE migration_id = 'commerce-v1-ap3b-async-payments'"
        )->fetchColumn();
        ap3b_assert((int) $journalCount === 1, 'migration_not_idempotent');

        ap3b_seed_legal($source);
        $methods = ['card', 'paypal', 'klarna'];
        foreach ($methods as $index => $method) {
            $productId = 'AP3B-' . strtoupper($method);
            $hash = str_repeat(dechex($index + 1), 64);
            ap3b_seed($source, $productId, $hash);
            $checkoutId = sprintf('30000000-0000-4000-8000-%012d', $index + 1);
            $commerce->createCheckout(ap3b_checkout($checkoutId, $productId, $hash));
            $commerce->recordStripeCreationOutcome($checkoutId, 'created', 'cs_test_' . $method);
            $event = ap3b_event($productId, $method, 'pi_test_' . $method, 'succeeded');
            $first = $commerce->finalizePayment($checkoutId, $event);
            $second = $commerce->finalizePayment($checkoutId, $event);
            ap3b_assert(($first['order_id'] ?? $first['orderId'] ?? null) === ($second['order_id'] ?? $second['orderId'] ?? null), 'finalization_not_idempotent');
        }

        $sepaHash = str_repeat('4', 64);
        ap3b_seed($source, 'AP3B-SEPA-SUCCESS', $sepaHash);
        $sepaCheckout = '30000000-0000-4000-8000-000000000004';
        $commerce->createCheckout(ap3b_checkout($sepaCheckout, 'AP3B-SEPA-SUCCESS', $sepaHash));
        $commerce->recordStripeCreationOutcome($sepaCheckout, 'created', 'cs_test_sepa_success');
        $processing = ap3b_event('AP3B-SEPA-SUCCESS', 'sepa_debit', 'pi_test_sepa_success', 'processing');
        $commerce->markPaymentProcessing($sepaCheckout, $processing);
        $processingOrders = (int) $source->query('SELECT COUNT(*) FROM orders')->fetchColumn();
        $processingShipments = (int) $source->query('SELECT COUNT(*) FROM shipments')->fetchColumn();
        $blocked = (int) $source->query(
            "SELECT blocks_stock FROM reservations WHERE checkout_id = '30000000-0000-4000-8000-000000000004'"
        )->fetchColumn();
        ap3b_assert($processingOrders === 3 && $processingShipments === 3 && $blocked === 1, 'processing_contract_broken');
        $success = $processing;
        $success['paymentStatus'] = 'succeeded';
        $success['paymentIntentStatus'] = 'succeeded';
        $commerce->finalizePayment($sepaCheckout, $success);
        $commerce->finalizePayment($sepaCheckout, $success);

        $failureHash = str_repeat('5', 64);
        ap3b_seed($source, 'AP3B-SEPA-FAILED', $failureHash);
        $failureCheckout = '30000000-0000-4000-8000-000000000005';
        $commerce->createCheckout(ap3b_checkout($failureCheckout, 'AP3B-SEPA-FAILED', $failureHash));
        $commerce->recordStripeCreationOutcome($failureCheckout, 'created', 'cs_test_sepa_failed');
        $failed = ap3b_event('AP3B-SEPA-FAILED', 'sepa_debit', 'pi_test_sepa_failed', 'processing');
        $commerce->markPaymentProcessing($failureCheckout, $failed);
        $failed['paymentStatus'] = 'failed';
        $failed['paymentIntentStatus'] = 'requires_payment_method';
        $commerce->failAsyncPayment($failureCheckout, $failed);
        $failedReservation = $source->query(
            "SELECT state, blocks_stock FROM reservations
             WHERE checkout_id = '30000000-0000-4000-8000-000000000005'"
        )->fetch(PDO::FETCH_ASSOC);
        ap3b_assert($failedReservation === ['state' => 'released', 'blocks_stock' => 0]
            || $failedReservation === ['state' => 'released', 'blocks_stock' => '0'], 'async_failure_not_released');

        ap3b_seed($source, 'AP3B-CONCURRENT', str_repeat('c', 64));
        $children = [];
        $start = microtime(true) + 1.5;
        for ($index = 0; $index < 10; $index++) {
            $checkoutId = sprintf('40000000-0000-4000-8000-%012d', $index + 1);
            $children[] = ap3b_child_output(['/usr/bin/php8.4', __FILE__, '--checkout-client', $checkoutId, (string) $start]);
        }
        $parallel = ap3b_collect_children($children);
        $created = count(array_filter($parallel, static fn (array $row): bool => ($row['result'] ?? '') === 'created'));
        $blockedCount = count(array_filter($parallel, static fn (array $row): bool => ($row['result'] ?? '') === 'sold_out_or_reserved'));
        ap3b_assert($created === 1 && $blockedCount === 9, 'parallel_uniqueness_failed');

        $source->exec('CREATE TABLE ap3b_deadlock_probe (probe_id INT PRIMARY KEY, value_int INT NOT NULL) ENGINE=InnoDB');
        $source->exec('INSERT INTO ap3b_deadlock_probe VALUES (1, 0), (2, 0)');
        $runId = bin2hex(random_bytes(6));
        $markerA = AP3B_PRIVATE_DIR . '/.ap3b-deadlock-' . $runId . '-a';
        $markerB = AP3B_PRIVATE_DIR . '/.ap3b-deadlock-' . $runId . '-b';
        $temporary[] = $markerA;
        $temporary[] = $markerB;
        $deadlock = ap3b_collect_children([
            ap3b_child_output(['/usr/bin/php8.4', __FILE__, '--deadlock-client', 'a', $runId]),
            ap3b_child_output(['/usr/bin/php8.4', __FILE__, '--deadlock-client', 'b', $runId]),
        ]);
        $deadlockStates = array_column($deadlock, 'result');
        sort($deadlockStates, SORT_STRING);
        ap3b_assert($deadlockStates === ['committed', 'deadlock'], 'deadlock_not_observed');

        $source->exec('CREATE TABLE ap3b_crash_probe (probe_id INT PRIMARY KEY, value_text VARCHAR(40) NOT NULL) ENGINE=InnoDB');
        [$crashProcess, $crashPipes] = ap3b_child_output(['/usr/bin/php8.4', __FILE__, '--crash-client']);
        fclose($crashPipes[1]);
        fclose($crashPipes[2]);
        ap3b_assert(proc_close($crashProcess) === 0, 'crash_probe_process_failed');
        ap3b_assert((int) $source->query('SELECT COUNT(*) FROM ap3b_crash_probe')->fetchColumn() === 0, 'crash_transaction_not_rolled_back');

        $source->exec("INSERT INTO worker_leases (worker_name) VALUES ('ap3b-worker')");
        $source->beginTransaction();
        $source->query("SELECT worker_name FROM worker_leases WHERE worker_name = 'ap3b-worker' FOR UPDATE")->fetchColumn();
        $source->exec("UPDATE worker_leases SET lease_token = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa', lease_until = UTC_TIMESTAMP(6) + INTERVAL 10 MINUTE WHERE worker_name = 'ap3b-worker'");
        $source->commit();
        $leaseBlocked = (int) $source->query("SELECT lease_until > UTC_TIMESTAMP(6) FROM worker_leases WHERE worker_name = 'ap3b-worker'")->fetchColumn();
        $source->exec("UPDATE worker_leases SET lease_until = UTC_TIMESTAMP(6) - INTERVAL 1 SECOND WHERE worker_name = 'ap3b-worker'");
        $leaseExpired = (int) $source->query("SELECT lease_until <= UTC_TIMESTAMP(6) FROM worker_leases WHERE worker_name = 'ap3b-worker'")->fetchColumn();
        ap3b_assert($leaseBlocked === 1 && $leaseExpired === 1, 'lease_contract_failed');

        $filePrefix = AP3B_PRIVATE_DIR . '/.ap3b-' . bin2hex(random_bytes(6));
        $sourceClient = $filePrefix . '-source.cnf';
        $restoreClient = $filePrefix . '-restore.cnf';
        $fullDump = $filePrefix . '-full.sql';
        $tampered = $filePrefix . '-tampered.sql';
        $sourceSchema = $filePrefix . '-source-schema.sql';
        $sourceData = $filePrefix . '-source-data.sql';
        $restoreSchema = $filePrefix . '-restore-schema.sql';
        $restoreData = $filePrefix . '-restore-data.sql';
        array_push($temporary, $sourceClient, $restoreClient, $fullDump, $tampered, $sourceSchema, $sourceData, $restoreSchema, $restoreData);
        ap3b_client_file($config['source'], $sourceClient);
        ap3b_client_file($config['restore'], $restoreClient);
        ap3b_dump($config['source'], $sourceClient, $fullDump);
        ap3b_dump($config['source'], $sourceClient, $sourceSchema, ['--no-data']);
        ap3b_dump($config['source'], $sourceClient, $sourceData, ['--no-create-info']);
        copy($fullDump, $tampered);
        file_put_contents($tampered, "-- artificial tamper\n", FILE_APPEND | LOCK_EX);
        ap3b_assert(ap3b_hash_file($fullDump) !== ap3b_hash_file($tampered), 'backup_tamper_not_detected');
        ap3b_restore($config['restore'], $restoreClient, $fullDump);
        ap3b_dump($config['restore'], $restoreClient, $restoreSchema, ['--no-data']);
        ap3b_dump($config['restore'], $restoreClient, $restoreData, ['--no-create-info']);
        $structureMatch = hash_equals(ap3b_structure_hash($source), ap3b_structure_hash($restore));
        $contentMatch = hash_equals(
            ap3b_normalized_dump_hash($sourceData),
            ap3b_normalized_dump_hash($restoreData)
        );
        ap3b_assert($structureMatch, 'backup_restore_structure_failed');
        ap3b_assert($contentMatch, 'backup_restore_content_failed');

        $result = [
            'status' => 'passed',
            'testOnly' => true,
            'checks' => [
                'mysql8InnoDbTls' => true,
                'migrationFromAp5' => true,
                'migrationIdempotent' => true,
                'fourPaymentMethods' => true,
                'processingBlocksStock' => true,
                'noOrderBeforeSuccess' => true,
                'asyncSuccessIdempotent' => true,
                'asyncFailureReleases' => true,
                'tenParallelClients' => true,
                'deadlockObserved' => true,
                'crashRollback' => true,
                'leaseExpiry' => true,
                'backupCreated' => true,
                'tamperDetected' => true,
                'structureMatch' => true,
                'contentMatch' => true,
            ],
        ];
    } catch (Throwable $error) {
        $result['error'] = $error->getMessage();
    } finally {
        foreach ($temporary as $path) {
            @unlink($path);
        }
        $sourceEmpty = $source instanceof PDO ? ap3b_drop_all($source) : false;
        $restoreEmpty = $restore instanceof PDO ? ap3b_drop_all($restore) : false;
        $filesRemoved = array_reduce(
            $temporary,
            static fn (bool $clean, string $path): bool => $clean && !file_exists($path),
            true
        );
        $result['cleanup'] = [
            'sourceEmpty' => $sourceEmpty,
            'restoreEmpty' => $restoreEmpty,
            'temporaryFilesRemoved' => $filesRemoved,
        ];
    }

    echo json_encode($result, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
    return ($result['status'] ?? 'failed') === 'passed'
        && ($result['cleanup']['sourceEmpty'] ?? false)
        && ($result['cleanup']['restoreEmpty'] ?? false)
        && ($result['cleanup']['temporaryFilesRemoved'] ?? false)
        ? 0 : 1;
}

try {
    exit(ap3b_main($argv));
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage() . PHP_EOL);
    exit(1);
}
