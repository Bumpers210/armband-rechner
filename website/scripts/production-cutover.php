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
    if (($manifest['manifestVersion'] ?? null) !== 1
        || ($manifest['environment'] ?? null) !== 'production'
        || ($manifest['productModelVersion'] ?? null) !== 2
        || ($manifest['inventorySource'] ?? null) !== 'commerce_inventory.on_hand') {
        throw new CarmajaProductionCutoverException('cutover_contract_invalid');
    }
    if (($manifest['paymentMethodTypes'] ?? null)
        !== ['card', 'klarna', 'sepa_debit']) {
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
        || ($legal['legalBundleId'] ?? null) !== 'cmj-production-legal-2026-08-11-v4'
        || ($legal['status'] ?? null) !== 'approved') {
        throw new CarmajaProductionCutoverException('legal_bundle_contract_invalid');
    }

    $migrations = $manifest['schemaMigrations'] ?? null;
    if (!is_array($migrations) || count($migrations) !== 3) {
        throw new CarmajaProductionCutoverException('migration_manifest_invalid');
    }
    foreach ($migrations as $migration) {
        if (!is_array($migration)
            || preg_match('/^[A-Za-z0-9._:-]{1,100}$/', (string) ($migration['migrationId'] ?? '')) !== 1
            || preg_match('/^[0-9a-f]{64}$/', (string) ($migration['fileSha256'] ?? '')) !== 1
            || preg_match('/^[0-9a-f]{64}$/', (string) ($migration['journalSha256'] ?? '')) !== 1) {
            throw new CarmajaProductionCutoverException('migration_manifest_invalid');
        }
        $relativePath = str_replace('/', DIRECTORY_SEPARATOR, (string) ($migration['path'] ?? ''));
        $absolutePath = $repositoryRoot . DIRECTORY_SEPARATOR . $relativePath;
        if (!hash_equals($migration['fileSha256'], carmaja_cutover_sha256_file($absolutePath))) {
            throw new CarmajaProductionCutoverException('migration_file_hash_mismatch');
        }
    }

    $selection = $manifest['selectedProducts'] ?? null;
    $approved = ($manifest['status'] ?? null) === 'approved_for_cutover';
    return [
        'approved' => $approved,
        'selectedProductCount' => is_array($selection) ? count($selection) : -1,
        'legalBundleId' => $legal['legalBundleId'],
    ];
}

function carmaja_cutover_canonicalize(mixed $value): mixed
{
    if (!is_array($value)) {
        return $value;
    }
    if (array_is_list($value)) {
        return array_map('carmaja_cutover_canonicalize', $value);
    }
    ksort($value, SORT_STRING);
    foreach ($value as $key => $entry) {
        $value[$key] = carmaja_cutover_canonicalize($entry);
    }
    return $value;
}

function carmaja_cutover_source_hash(array $product): string
{
    $canonical = [
        'braceletSizeCm' => $product['braceletSizeCm'] ?? 0,
        'careInstructions' => array_values($product['careInstructions'] ?? []),
        'currency' => (string) ($product['currency'] ?? ''),
        'description' => (string) ($product['description'] ?? ''),
        'images' => array_values($product['images'] ?? []),
        'materials' => array_values($product['materials'] ?? []),
        'metalElements' => array_values($product['metalElements'] ?? []),
        'productModelVersion' => 2,
        'name' => (string) ($product['name'] ?? ''),
        'pearlSizeMm' => $product['pearlSizeMm'] ?? 0,
        'priceMinor' => (int) ($product['priceMinor'] ?? 0),
        'productId' => (string) ($product['productId'] ?? ''),
        'productVersion' => (int) ($product['productVersion'] ?? 0),
        'salesEnabled' => (bool) ($product['salesEnabled'] ?? false),
    ];
    return hash('sha256', json_encode(
        carmaja_cutover_canonicalize($canonical),
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
    ));
}

function carmaja_cutover_selected_product(array $manifest, array $productSource): array
{
    $selection = $manifest['selectedProducts'] ?? null;
    if (($manifest['status'] ?? null) !== 'approved_for_cutover'
        || !is_array($selection) || count($selection) !== 1
        || !is_array($selection[0])) {
        throw new CarmajaProductionCutoverException('product_selection_not_approved');
    }
    $selected = $selection[0];
    $expectedKeys = [
        'expectedProductVersion', 'expectedSourceHash', 'legacyStock',
        'productId', 'targetOnHand',
    ];
    $keys = array_keys($selected);
    sort($keys);
    sort($expectedKeys);
    if ($keys !== $expectedKeys
        || ($selected['legacyStock'] ?? null) !== 1
        || ($selected['targetOnHand'] ?? null) !== 1
        || !is_int($selected['expectedProductVersion'] ?? null)
        || $selected['expectedProductVersion'] < 1
        || preg_match('/^[0-9a-f]{64}$/', (string) ($selected['expectedSourceHash'] ?? '')) !== 1) {
        throw new CarmajaProductionCutoverException('product_selection_invalid');
    }
    if (($productSource['productModelVersion'] ?? null) !== 2
        || !is_array($productSource['products'] ?? null)) {
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
    if (($product['productModelVersion'] ?? null) !== 2
        || ($product['productVersion'] ?? null) !== $selected['expectedProductVersion']
        || ($product['sourceHash'] ?? null) !== $selected['expectedSourceHash']
        || ($product['salesEnabled'] ?? null) !== true
        || ($product['currency'] ?? null) !== 'eur'
        || !is_int($product['priceMinor'] ?? null)
        || $product['priceMinor'] < 50
        || !is_string($product['name'] ?? null)
        || trim($product['name']) === ''
        || !is_string($product['description'] ?? null)
        || (!is_int($product['braceletSizeCm'] ?? null) && !is_float($product['braceletSizeCm'] ?? null))
        || (float) $product['braceletSizeCm'] <= 0
        || (!is_int($product['pearlSizeMm'] ?? null) && !is_float($product['pearlSizeMm'] ?? null))
        || (float) $product['pearlSizeMm'] <= 0
        || !is_array($product['materials'] ?? null)
        || !is_array($product['metalElements'] ?? null)
        || !is_array($product['careInstructions'] ?? null)
        || !is_array($product['images'] ?? null)
        || count($product['images']) < 1
        || !hash_equals($product['sourceHash'], carmaja_cutover_source_hash($product))) {
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
    $query = $pdo->prepare('SELECT checksum FROM schema_migrations WHERE migration_id = ?');
    foreach ($manifest['schemaMigrations'] as $migration) {
        $query->execute([$migration['migrationId']]);
        $stored = $query->fetchColumn();
        if (!is_string($stored) || !hash_equals($migration['journalSha256'], $stored)) {
            throw new CarmajaProductionCutoverException('migration_journal_mismatch');
        }
    }
    foreach (['checkout_sagas', 'payments', 'orders', 'commerce_products', 'commerce_inventory'] as $table) {
        if ((int) $pdo->query('SELECT COUNT(*) FROM ' . $table)->fetchColumn() !== 0) {
            throw new CarmajaProductionCutoverException('commerce_not_empty');
        }
    }
    $legal = $pdo->prepare('SELECT status FROM legal_bundles WHERE legal_bundle_id = ?');
    $legal->execute([$manifest['legalBundle']['legalBundleId']]);
    if ($legal->fetchColumn() !== 'approved') {
        throw new CarmajaProductionCutoverException('approved_legal_bundle_missing');
    }
}

function carmaja_cutover_apply(PDO $pdo, array $manifest, array $product): void
{
    $manifestHash = hash('sha256', json_encode($manifest, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    $pdo->beginTransaction();
    try {
        carmaja_cutover_verify_database($pdo, $manifest);
        $insertProduct = $pdo->prepare(
            'INSERT INTO commerce_products
             (product_id, product_version, source_hash, name, description, materials,
              metal_elements, bracelet_size, care_instructions, images, price_minor,
              currency, sales_enabled, synchronized_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, UTC_TIMESTAMP(6))'
        );
        $insertProduct->execute([
            $product['productId'], $product['productVersion'], $product['sourceHash'],
            $product['name'], $product['description'],
            json_encode($product['materials'], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            json_encode($product['metalElements'], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            (string) $product['braceletSizeCm'], implode("\n", $product['careInstructions']),
            json_encode($product['images'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            $product['priceMinor'], $product['currency'],
        ]);
        $pdo->prepare('INSERT INTO commerce_inventory (product_id, on_hand, inventory_version) VALUES (?, 0, 0)')
            ->execute([$product['productId']]);
        $pdo->prepare('UPDATE commerce_inventory SET on_hand = 1, inventory_version = 1 WHERE product_id = ?')
            ->execute([$product['productId']]);
        $pdo->prepare(
            'INSERT INTO inventory_adjustments
             (product_id, target_on_hand, previous_on_hand, inventory_version, reason,
              correlation_id, idempotency_key, actor_id)
             VALUES (?, 1, 0, 1, ?, ?, ?, ?)'
        )->execute([
            $product['productId'], 'activate_new_unique', 'ap7-cutover-' . substr($manifestHash, 0, 24),
            'ap7-cutover-' . $manifestHash, 'ap7-production-cutover',
        ]);
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
    $repositoryRoot = dirname(__DIR__, 2);
    $contract = carmaja_cutover_validate_contract($manifest, $repositoryRoot);
    $mode = $options['mode'] ?? 'plan';
    if ($mode === 'plan') {
        echo json_encode([
            'ok' => true,
            'mode' => 'plan',
            'readyForApply' => $contract['approved'] && $contract['selectedProductCount'] === 1,
            'selectedProductCount' => $contract['selectedProductCount'],
            'legalBundleId' => $contract['legalBundleId'],
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
        return 0;
    }
    if ($mode !== 'apply'
        || ($options['confirmation'] ?? null) !== 'APPLY-CARMAJA-PRODUCTION-CUTOVER') {
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
    echo json_encode(['ok' => true, 'mode' => 'apply'], JSON_THROW_ON_ERROR) . PHP_EOL;
    return 0;
}

if (!defined('CARMAJA_PRODUCTION_CUTOVER_NO_RUN')) {
    try {
        exit(carmaja_cutover_main($argv));
    } catch (Throwable $error) {
        fwrite(STDERR, 'AP7-Cutover abgebrochen: ' . $error->getMessage() . PHP_EOL);
        exit(1);
    }
}
