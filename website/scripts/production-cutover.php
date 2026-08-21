<?php

declare(strict_types=1);

final class CarmajaProductionCutoverException extends RuntimeException
{
}

function carmaja_cutover_read_json(string $path): array
{
    if (!is_file($path) || !is_readable($path)) {
        throw new CarmajaProductionCutoverException('cutover_file_unavailable');
    }
    $value = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($value) || array_is_list($value)) {
        throw new CarmajaProductionCutoverException('cutover_json_invalid');
    }
    return $value;
}

function carmaja_cutover_sha256_file(string $path): string
{
    $contents = is_file($path) ? file_get_contents($path) : false;
    if (!is_string($contents)) {
        throw new CarmajaProductionCutoverException('migration_file_unavailable');
    }
    return hash('sha256', str_replace("\r\n", "\n", $contents));
}

function carmaja_cutover_validate_contract(array $manifest, string $repositoryRoot): array
{
    if (($manifest['manifestVersion'] ?? null) !== 2
        || ($manifest['environment'] ?? null) !== 'production'
        || ($manifest['productModelVersion'] ?? null) !== 3
        || ($manifest['salesModel'] ?? null) !== 'collection'
        || ($manifest['availabilitySource'] ?? null) !== 'commerce_products.sales_enabled') {
        throw new CarmajaProductionCutoverException('cutover_contract_invalid');
    }
    if (($manifest['paymentMethodTypes'] ?? null) !== ['card', 'klarna', 'sepa_debit']) {
        throw new CarmajaProductionCutoverException('payment_method_contract_invalid');
    }
    $shipping = $manifest['shipping'] ?? null;
    if (!is_array($shipping)
        || ($shipping['methodId'] ?? null) !== 'deutsche-post-maxibrief'
        || ($shipping['amountMinor'] ?? null) !== 270
        || ($shipping['currency'] ?? null) !== 'eur') {
        throw new CarmajaProductionCutoverException('shipping_contract_invalid');
    }
    $legal = $manifest['legalBundle'] ?? null;
    if (!is_array($legal)
        || ($legal['legalBundleId'] ?? null) !== 'cmj-production-legal-2026-08-16-v5'
        || !in_array(($legal['status'] ?? null), ['draft', 'approved'], true)) {
        throw new CarmajaProductionCutoverException('legal_bundle_contract_invalid');
    }
    $migrations = $manifest['schemaMigrations'] ?? null;
    if (!is_array($migrations) || count($migrations) !== 1 || !is_array($migrations[0])) {
        throw new CarmajaProductionCutoverException('migration_manifest_invalid');
    }
    $migration = $migrations[0];
    if (($migration['migrationId'] ?? null) !== 'commerce-v2-collections'
        || ($migration['path'] ?? null) !== 'website/database/migrations/commerce-v2-collections.sql'
        || preg_match('/^[0-9a-f]{64}$/', (string) ($migration['fileSha256'] ?? '')) !== 1
        || preg_match('/^[0-9a-f]{64}$/', (string) ($migration['journalSha256'] ?? '')) !== 1) {
        throw new CarmajaProductionCutoverException('migration_manifest_invalid');
    }
    $absolutePath = $repositoryRoot . DIRECTORY_SEPARATOR
        . str_replace('/', DIRECTORY_SEPARATOR, (string) $migration['path']);
    if (!hash_equals((string) $migration['fileSha256'], carmaja_cutover_sha256_file($absolutePath))) {
        throw new CarmajaProductionCutoverException('migration_file_hash_mismatch');
    }

    $selection = $manifest['selectedCollections'] ?? null;
    $status = $manifest['status'] ?? null;
    $legalApproved = ($legal['status'] ?? null) === 'approved';
    $readyForPlan = in_array($status, ['approved_for_plan', 'approved_for_cutover'], true)
        && $legalApproved;
    $readyForApply = $status === 'approved_for_cutover' && $legalApproved;
    return [
        'readyForPlan' => $readyForPlan,
        'readyForApply' => $readyForApply,
        'selectedProductCount' => is_array($selection) ? count($selection) : -1,
        'legalBundleId' => (string) $legal['legalBundleId'],
    ];
}

function carmaja_cutover_selected_product(array $manifest, array $productSource): array
{
    $selection = $manifest['selectedCollections'] ?? null;
    if (($manifest['status'] ?? null) !== 'approved_for_cutover'
        || ($manifest['legalBundle']['status'] ?? null) !== 'approved'
        || !is_array($selection) || count($selection) !== 1
        || !is_array($selection[0])) {
        throw new CarmajaProductionCutoverException('product_selection_not_approved');
    }
    $selected = $selection[0];
    $expectedKeys = [
        'expectedProductVersion', 'expectedSourceHash', 'operationId', 'productId', 'sku',
    ];
    $keys = array_keys($selected);
    sort($keys);
    sort($expectedKeys);
    if ($keys !== $expectedKeys
        || !is_int($selected['expectedProductVersion'] ?? null)
        || $selected['expectedProductVersion'] < 1
        || preg_match('/^[0-9a-f]{64}$/', (string) ($selected['expectedSourceHash'] ?? '')) !== 1
        || preg_match('/^[A-Za-z0-9._:-]{8,100}$/', (string) ($selected['operationId'] ?? '')) !== 1
        || !is_string($selected['productId'] ?? null) || $selected['productId'] === ''
        || !is_string($selected['sku'] ?? null) || $selected['sku'] === '') {
        throw new CarmajaProductionCutoverException('product_selection_invalid');
    }
    if (($productSource['version'] ?? null) !== 3 || !is_array($productSource['products'] ?? null)) {
        throw new CarmajaProductionCutoverException('product_source_invalid');
    }
    $matches = array_values(array_filter(
        $productSource['products'],
        static fn (mixed $product): bool => is_array($product)
            && ($product['productId'] ?? null) === $selected['productId']
    ));
    if (count($matches) !== 1) {
        throw new CarmajaProductionCutoverException('selected_product_not_unique');
    }
    $product = $matches[0];
    if (($product['productModelVersion'] ?? null) !== 3
        || ($product['productVersion'] ?? null) !== $selected['expectedProductVersion']
        || ($product['sourceHash'] ?? null) !== $selected['expectedSourceHash']
        || ($product['sku'] ?? null) !== $selected['sku']
        || ($product['salesEnabled'] ?? null) !== true
        || ($product['currency'] ?? null) !== 'eur'
        || !is_int($product['priceMinor'] ?? null) || $product['priceMinor'] < 50
        || !is_string($product['title'] ?? null) || trim($product['title']) === ''
        || !is_string($product['description'] ?? null)
        || !is_array($product['descriptionDocument'] ?? null)
        || !is_array($product['images'] ?? null) || count($product['images']) < 1) {
        throw new CarmajaProductionCutoverException('selected_product_contract_mismatch');
    }
    return $product;
}

function carmaja_cutover_load_config(string $path): array
{
    if (!is_file($path) || !is_readable($path)) {
        throw new CarmajaProductionCutoverException('runtime_config_unavailable');
    }
    if (DIRECTORY_SEPARATOR === '/' && ((int) fileperms($path) & 0777) !== 0600) {
        throw new CarmajaProductionCutoverException('runtime_config_permissions_invalid');
    }
    ob_start();
    try {
        $config = (static fn (string $file): mixed => require $file)($path);
        $output = ob_get_clean();
    } catch (Throwable) {
        ob_end_clean();
        throw new CarmajaProductionCutoverException('runtime_config_invalid');
    }
    if ($output !== '' || !is_array($config)
        || ($config['environment'] ?? null) !== 'production'
        || ($config['commerceRequireTls'] ?? null) !== true) {
        throw new CarmajaProductionCutoverException('runtime_config_invalid');
    }
    return $config;
}

function carmaja_cutover_connect(array $config): PDO
{
    $dsn = $config['commerceDsn'] ?? null;
    $user = $config['commerceUser'] ?? null;
    $password = $config['commercePassword'] ?? null;
    if (!is_string($dsn) || !str_starts_with($dsn, 'mysql:')
        || !is_string($user) || $user === '' || !is_string($password)) {
        throw new CarmajaProductionCutoverException('commerce_configuration_invalid');
    }
    $options = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false];
    $caPath = $config['commerceTlsCaPath'] ?? null;
    if (is_string($caPath) && $caPath !== '') {
        $options[PDO::MYSQL_ATTR_SSL_CA] = $caPath;
        if (defined('PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT')) {
            $options[PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT] = true;
        }
    } else {
        if (defined('PDO::MYSQL_ATTR_SSL_CIPHER')) {
            $options[PDO::MYSQL_ATTR_SSL_CIPHER] = 'HIGH';
        }
        if (defined('PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT')) {
            $options[PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT] = false;
        }
    }
    $pdo = new PDO($dsn, $user, $password, $options);
    $tls = $pdo->query("SHOW SESSION STATUS WHERE Variable_name IN ('Ssl_cipher','Ssl_version')")
        ->fetchAll(PDO::FETCH_KEY_PAIR);
    if (trim((string) ($tls['Ssl_cipher'] ?? '')) === ''
        || trim((string) ($tls['Ssl_version'] ?? '')) === '') {
        throw new CarmajaProductionCutoverException('commerce_tls_not_active');
    }
    return $pdo;
}

function carmaja_cutover_verify_database(PDO $pdo, array $manifest): void
{
    $migration = $manifest['schemaMigrations'][0];
    $query = $pdo->prepare('SELECT checksum FROM schema_migrations WHERE migration_id = ?');
    $query->execute([$migration['migrationId']]);
    $stored = $query->fetchColumn();
    if (!is_string($stored) || !hash_equals((string) $migration['journalSha256'], $stored)) {
        throw new CarmajaProductionCutoverException('migration_journal_mismatch');
    }
    $legal = $pdo->prepare('SELECT status FROM legal_bundles WHERE legal_bundle_id = ?');
    $legal->execute([$manifest['legalBundle']['legalBundleId']]);
    if ($legal->fetchColumn() !== 'approved') {
        throw new CarmajaProductionCutoverException('approved_legal_bundle_missing');
    }
}

function carmaja_cutover_apply(PDO $pdo, array $manifest, array $product): void
{
    $selected = $manifest['selectedCollections'][0];
    $requestHash = hash('sha256', json_encode([
        'action' => 'publish',
        'operationId' => $selected['operationId'],
        'productId' => $product['productId'],
        'productVersion' => $product['productVersion'],
        'sourceHash' => $product['sourceHash'],
    ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    $pdo->beginTransaction();
    try {
        carmaja_cutover_verify_database($pdo, $manifest);
        $existing = $pdo->prepare(
            'SELECT request_hash FROM product_projection_operations WHERE operation_id = ? FOR UPDATE'
        );
        $existing->execute([$selected['operationId']]);
        $storedHash = $existing->fetchColumn();
        if (is_string($storedHash)) {
            if (!hash_equals($storedHash, $requestHash)) {
                throw new CarmajaProductionCutoverException('idempotency_conflict');
            }
            $pdo->commit();
            return;
        }

        $upsert = $pdo->prepare(
            'INSERT INTO commerce_products
                (product_id, product_version, source_hash, name, description, materials,
                 metal_elements, bracelet_size, care_instructions, images, price_minor,
                 currency, sales_enabled, sales_model, synchronized_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, \'collection\', UTC_TIMESTAMP(6))
             ON DUPLICATE KEY UPDATE
                product_version = VALUES(product_version), source_hash = VALUES(source_hash),
                name = VALUES(name), description = VALUES(description), materials = VALUES(materials),
                metal_elements = VALUES(metal_elements), bracelet_size = VALUES(bracelet_size),
                care_instructions = VALUES(care_instructions), images = VALUES(images),
                price_minor = VALUES(price_minor), currency = VALUES(currency),
                sales_enabled = 1, sales_model = \'collection\', synchronized_at = UTC_TIMESTAMP(6)'
        );
        $care = $product['careInstructions'] ?? '';
        $upsert->execute([
            $product['productId'], $product['productVersion'], $product['sourceHash'], $product['title'],
            $product['description'], json_encode($product['materials'] ?? [], JSON_THROW_ON_ERROR),
            json_encode($product['metalElements'] ?? [], JSON_THROW_ON_ERROR),
            (string) ($product['braceletSizeCm'] ?? ''),
            is_array($care) ? implode("\n", array_map('strval', $care)) : (string) $care,
            json_encode($product['images'], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            $product['priceMinor'], $product['currency'],
        ]);
        $result = json_encode([
            'productId' => $product['productId'],
            'productVersion' => $product['productVersion'],
            'salesModel' => 'collection',
            'available' => true,
            'action' => 'publish',
        ], JSON_THROW_ON_ERROR);
        $pdo->prepare(
            'INSERT INTO product_projection_operations
                (operation_id, request_hash, product_id, action, result)
             VALUES (?, ?, ?, \'publish\', ?)'
        )->execute([$selected['operationId'], $requestHash, $product['productId'], $result]);
        $pdo->commit();
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $error;
    }
}

function carmaja_cutover_arguments(array $arguments): array
{
    $parsed = [];
    foreach (array_slice($arguments, 1) as $argument) {
        if (preg_match('/^--([a-z-]+)=(.+)$/', $argument, $matches) !== 1) {
            throw new CarmajaProductionCutoverException('argument_invalid');
        }
        $parsed[$matches[1]] = $matches[2];
    }
    return $parsed;
}

function carmaja_cutover_main(array $arguments): int
{
    if (PHP_SAPI !== 'cli') {
        throw new CarmajaProductionCutoverException('cli_only');
    }
    $options = carmaja_cutover_arguments($arguments);
    $manifestPath = $options['manifest'] ?? null;
    if (!is_string($manifestPath)) {
        throw new CarmajaProductionCutoverException('manifest_required');
    }
    $manifest = carmaja_cutover_read_json($manifestPath);
    $contract = carmaja_cutover_validate_contract($manifest, dirname(__DIR__, 2));
    $mode = $options['mode'] ?? 'plan';
    if ($mode === 'plan') {
        echo json_encode([
            'ok' => true,
            'mode' => 'plan',
            'readyForPlan' => $contract['readyForPlan'] && $contract['selectedProductCount'] === 1,
            'readyForApply' => $contract['readyForApply'] && $contract['selectedProductCount'] === 1,
            'selectedProductCount' => $contract['selectedProductCount'],
            'legalBundleId' => $contract['legalBundleId'],
            'salesModel' => 'collection',
            'inventoryMutation' => false,
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
        return 0;
    }
    if ($mode !== 'apply'
        || ($options['confirmation'] ?? null) !== 'APPLY-CARMAJA-PRODUCTION-COLLECTION-CUTOVER') {
        throw new CarmajaProductionCutoverException('cutover_confirmation_missing');
    }
    $productsPath = $options['products'] ?? null;
    $configPath = $options['config'] ?? null;
    if (!is_string($productsPath) || !is_string($configPath)) {
        throw new CarmajaProductionCutoverException('cutover_inputs_missing');
    }
    $product = carmaja_cutover_selected_product($manifest, carmaja_cutover_read_json($productsPath));
    carmaja_cutover_apply(
        carmaja_cutover_connect(carmaja_cutover_load_config($configPath)),
        $manifest,
        $product
    );
    echo json_encode(['ok' => true, 'mode' => 'apply', 'salesModel' => 'collection'], JSON_THROW_ON_ERROR)
        . PHP_EOL;
    return 0;
}

if (!defined('CARMAJA_PRODUCTION_CUTOVER_NO_RUN')) {
    try {
        exit(carmaja_cutover_main($argv));
    } catch (Throwable $error) {
        fwrite(STDERR, 'Kollektionen-Cutover abgebrochen: ' . $error->getMessage() . PHP_EOL);
        exit(1);
    }
}
