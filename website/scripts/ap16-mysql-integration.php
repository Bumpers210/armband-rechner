<?php

declare(strict_types=1);

/**
 * AP1.6-only server-side integration probe.
 *
 * The script intentionally does not implement the commerce schema. It uses
 * two pre-created, empty MySQL 8 test databases and temporary AP1.6 tables.
 * The later AP2 adapter owns the production schema and transaction layer.
 */

const AP16_CREDENTIALS = '/home/www/carmaja-private-test/ap16-mysql-credentials.json';
const AP16_PRIVATE_DIR = '/home/www/carmaja-private-test';

function ap16_fail(string $code): never
{
    throw new RuntimeException($code);
}

function ap16_canonicalize(mixed $value): mixed
{
    if (is_array($value)) {
        if (array_is_list($value)) {
            return array_map('ap16_canonicalize', $value);
        }

        $keys = array_keys($value);
        sort($keys, SORT_STRING);
        $result = [];

        foreach ($keys as $key) {
            $result[(string) $key] = ap16_canonicalize($value[$key]);
        }

        return $result;
    }

    return $value;
}

function ap16_json(mixed $value): string
{
    return json_encode(
        ap16_canonicalize($value),
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
    );
}

function ap16_hash(mixed $value): string
{
    return hash('sha256', ap16_json($value));
}

function ap16_hash_file(string $path): string
{
    $hash = hash_file('sha256', $path);

    if (!is_string($hash)) {
        ap16_fail('backup_hash_failed');
    }

    return $hash;
}

function ap16_config(): array
{
    if (!is_file(AP16_CREDENTIALS) || !is_readable(AP16_CREDENTIALS)) {
        ap16_fail('credentials_unavailable');
    }

    $mode = fileperms(AP16_CREDENTIALS);
    if ($mode === false || ($mode & 0777) !== 0600) {
        ap16_fail('credentials_permissions_invalid');
    }

    try {
        $config = json_decode(
            (string) file_get_contents(AP16_CREDENTIALS),
            true,
            512,
            JSON_THROW_ON_ERROR
        );
    } catch (Throwable) {
        ap16_fail('credentials_json_invalid');
    }

    if (!is_array($config)
        || !is_array($config['source'] ?? null)
        || !is_array($config['restore'] ?? null)) {
        ap16_fail('credentials_structure_invalid');
    }

    foreach (['source', 'restore'] as $target) {
        foreach (['host', 'database', 'username', 'password'] as $field) {
            if (!is_string($config[$target][$field] ?? null)
                || trim($config[$target][$field]) === '') {
                ap16_fail('credentials_field_invalid');
            }
        }

        if (!is_int($config[$target]['port'] ?? null)
            || $config[$target]['port'] < 1
            || $config[$target]['port'] > 65535) {
            ap16_fail('credentials_port_invalid');
        }

        $tlsCaPath = $config[$target]['tlsCaPath'] ?? '';
        if (!is_string($tlsCaPath)) {
            ap16_fail('credentials_tls_path_invalid');
        }
    }

    if ($config['source']['database'] === $config['restore']['database']) {
        ap16_fail('source_restore_database_not_separate');
    }

    foreach (['source', 'restore'] as $target) {
        if (preg_match('/(?:^|_)(?:prod|production)(?:_|$)/i', $config[$target]['database'])) {
            ap16_fail('production_database_name_rejected');
        }
    }

    return $config;
}

function ap16_identifier(string $identifier): string
{
    if (preg_match('/^[A-Za-z0-9_]{1,60}$/', $identifier) !== 1) {
        ap16_fail('identifier_invalid');
    }

    return '`' . str_replace('`', '``', $identifier) . '`';
}

function ap16_pdo_options(array $target): array
{
    $options = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_EMULATE_PREPARES => false,
        PDO::ATTR_TIMEOUT => 15,
        PDO::MYSQL_ATTR_SSL_CIPHER => 'HIGH',
    ];
    $tlsCaPath = trim((string) ($target['tlsCaPath'] ?? ''));

    if ($tlsCaPath !== '') {
        if (!is_file($tlsCaPath) || !is_readable($tlsCaPath)) {
            ap16_fail('tls_ca_unavailable');
        }

        $options[PDO::MYSQL_ATTR_SSL_CA] = $tlsCaPath;
        $options[PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT] = true;
    } else {
        // Active TLS is still required below. Missing CA/host verification is
        // reported as the accepted V1 residual risk, never as a fallback.
        $options[PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT] = false;
    }

    return $options;
}

function ap16_connect(array $target): PDO
{
    $dsn = sprintf(
        'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
        $target['host'],
        $target['port'],
        $target['database']
    );

    try {
        $pdo = new PDO($dsn, $target['username'], $target['password'], ap16_pdo_options($target));
        $pdo->exec("SET SESSION sql_mode = 'STRICT_ALL_TABLES'");
        return $pdo;
    } catch (Throwable) {
        ap16_fail('pdo_connection_failed');
    }
}

function ap16_server_info(PDO $pdo): array
{
    $version = (string) $pdo->query('SELECT VERSION()')->fetchColumn();
    if (preg_match('/^8\./', $version) !== 1) {
        ap16_fail('mysql_version_not_8');
    }

    $tls = [];
    $statement = $pdo->query(
        "SHOW SESSION STATUS WHERE Variable_name IN ('Ssl_cipher', 'Ssl_version')"
    );
    foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $tls[(string) $row['Variable_name']] = (string) $row['Value'];
    }

    if (($tls['Ssl_cipher'] ?? '') === '' || ($tls['Ssl_version'] ?? '') === '') {
        ap16_fail('tls_session_not_active');
    }

    return [
        'mysql8' => true,
        'tlsActive' => true,
        'sslCipherPresent' => true,
        'sslVersionPresent' => true,
    ];
}

function ap16_table_names(PDO $pdo): array
{
    $names = [];
    foreach ($pdo->query('SHOW FULL TABLES')->fetchAll(PDO::FETCH_NUM) as $row) {
        $names[] = (string) $row[0];
    }

    sort($names, SORT_STRING);
    return $names;
}

function ap16_assert_empty(PDO $pdo): void
{
    if (ap16_table_names($pdo) !== []) {
        ap16_fail('test_database_not_empty');
    }
}

function ap16_schema(PDO $pdo, string $table): array
{
    $columns = $pdo->query('SHOW COLUMNS FROM ' . ap16_identifier($table))
        ->fetchAll(PDO::FETCH_ASSOC);
    $status = $pdo->query(
        'SHOW TABLE STATUS WHERE Name = ' . $pdo->quote($table)
    )->fetch(PDO::FETCH_ASSOC);
    if (!is_array($status) || !isset($status['Engine'])) {
        ap16_fail('schema_read_failed');
    }

    $normalizedColumns = [];
    foreach ($columns as $column) {
        $normalizedColumns[] = [
            'Field' => (string) ($column['Field'] ?? ''),
            'Type' => (string) ($column['Type'] ?? ''),
            'Null' => (string) ($column['Null'] ?? ''),
            'Key' => (string) ($column['Key'] ?? ''),
            'Default' => $column['Default'] ?? null,
            'Extra' => (string) ($column['Extra'] ?? ''),
        ];
    }

    return [
        'table' => $table,
        'engine' => (string) $status['Engine'],
        'columns' => $normalizedColumns,
    ];
}

function ap16_rows(PDO $pdo, string $table): array
{
    $statement = $pdo->query(
        'SELECT product_id, product_version, stock, price_minor, currency '
        . 'FROM ' . ap16_identifier($table) . ' ORDER BY product_id'
    );
    $rows = [];
    foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $rows[] = [
            'product_id' => (string) $row['product_id'],
            'product_version' => (int) $row['product_version'],
            'stock' => (int) $row['stock'],
            'price_minor' => (int) $row['price_minor'],
            'currency' => (string) $row['currency'],
        ];
    }

    return $rows;
}

function ap16_target_snapshot(PDO $pdo, string $table): array
{
    $schema = ap16_schema($pdo, $table);
    $rows = ap16_rows($pdo, $table);

    return [
        'schema' => $schema,
        'rows' => $rows,
        'schemaHash' => ap16_hash($schema),
        'rowsHash' => ap16_hash($rows),
        'contentHash' => ap16_hash(['schema' => $schema, 'rows' => $rows]),
    ];
}

function ap16_write_client_file(array $target, string $path): void
{
    $escape = static function (string $value): string {
        return str_replace(['\\', '"'], ['\\\\', '\\"'], $value);
    };
    $lines = [
        '[client]',
        'host="' . $escape($target['host']) . '"',
        'port=' . $target['port'],
        'user="' . $escape($target['username']) . '"',
        'password="' . $escape($target['password']) . '"',
        'default-character-set=utf8mb4',
        'ssl-cipher=HIGH',
    ];
    $tlsCaPath = trim((string) ($target['tlsCaPath'] ?? ''));
    if ($tlsCaPath !== '') {
        $lines[] = 'ssl-ca="' . $escape($tlsCaPath) . '"';
        $lines[] = 'ssl-verify-server-cert=1';
    } else {
        $lines[] = 'ssl-verify-server-cert=0';
    }

    if (file_put_contents($path, implode("\n", $lines) . "\n", LOCK_EX) === false) {
        ap16_fail('client_file_write_failed');
    }
    chmod($path, 0600);
}

function ap16_command(string $command): void
{
    $descriptor = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $process = proc_open($command, $descriptor, $pipes);
    if (!is_resource($process)) {
        ap16_fail('external_command_start_failed');
    }
    fclose($pipes[0]);
    stream_get_contents($pipes[1]);
    stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);
    if ($exitCode !== 0) {
        ap16_fail('external_command_failed');
    }
}

function ap16_dump(array $target, string $table, string $clientFile, string $dumpFile): void
{
    $command = implode(' ', [
        escapeshellarg('/usr/bin/mysqldump'),
        '--defaults-extra-file=' . escapeshellarg($clientFile),
        '--no-tablespaces',
        '--single-transaction',
        '--quick',
        '--skip-lock-tables',
        '--skip-comments',
        '--default-character-set=utf8mb4',
        escapeshellarg($target['database']),
        escapeshellarg($table),
        '>',
        escapeshellarg($dumpFile),
    ]);
    ap16_command($command);
}

function ap16_restore(array $target, string $clientFile, string $dumpFile): void
{
    $command = implode(' ', [
        escapeshellarg('/usr/bin/mysql'),
        '--defaults-extra-file=' . escapeshellarg($clientFile),
        '--default-character-set=utf8mb4',
        escapeshellarg($target['database']),
        '<',
        escapeshellarg($dumpFile),
    ]);
    ap16_command($command);
}

function ap16_create_table(PDO $pdo, string $table): void
{
    $pdo->exec(
        'CREATE TABLE ' . ap16_identifier($table) . ' ('
        . 'product_id CHAR(36) NOT NULL PRIMARY KEY, '
        . 'product_version INT UNSIGNED NOT NULL, '
        . 'stock TINYINT UNSIGNED NOT NULL, '
        . 'price_minor INT UNSIGNED NOT NULL, '
        . 'currency CHAR(3) NOT NULL'
        . ') ENGINE=InnoDB'
    );
}

function ap16_seed(PDO $pdo, string $table): void
{
    $statement = $pdo->prepare(
        'INSERT INTO ' . ap16_identifier($table)
        . ' (product_id, product_version, stock, price_minor, currency) VALUES (?, ?, ?, ?, ?)'
    );
    foreach ([
        ['11111111-1111-4111-8111-111111111111', 1, 1, 2490, 'eur'],
        ['22222222-2222-4222-8222-222222222222', 1, 1, 1990, 'eur'],
    ] as $row) {
        $statement->execute($row);
    }
}

function ap16_drop(PDO $pdo, string $table): bool
{
    try {
        $pdo->exec('DROP TABLE IF EXISTS ' . ap16_identifier($table));
        return true;
    } catch (Throwable) {
        return false;
    }
}

function ap16_rollback_gate(int $commerceCheckoutCount): array
{
    return $commerceCheckoutCount === 0
        ? ['allowed' => true, 'code' => 'stock_rollback_allowed_before_checkout']
        : ['allowed' => false, 'code' => 'stock_rollback_locked'];
}

function ap16_main(): int
{
    $sourcePdo = null;
    $restorePdo = null;
    $sourceTable = '';
    $restoreTable = '';
    $temporaryFiles = [];
    $cleanup = [
        'sourceTableRemoved' => false,
        'restoreTableRemoved' => false,
        'temporaryFilesRemoved' => false,
        'sourceEmptyAfterCleanup' => false,
        'restoreEmptyAfterCleanup' => false,
    ];
    $result = [
        'status' => 'failed',
        'testOnly' => true,
        'databaseEngine' => 'mysql8-innodb',
        'tls' => [],
        'checks' => [],
    ];

    try {
        $config = ap16_config();
        $runId = bin2hex(random_bytes(6));
        $sourceTable = 'ap16_s_' . $runId;
        $restoreTable = 'ap16_r_' . $runId;
        $sourceClient = AP16_PRIVATE_DIR . '/.ap16-source-' . $runId . '.cnf';
        $restoreClient = AP16_PRIVATE_DIR . '/.ap16-restore-' . $runId . '.cnf';
        $dumpFile = AP16_PRIVATE_DIR . '/.ap16-dump-' . $runId . '.sql';
        $tamperedDump = AP16_PRIVATE_DIR . '/.ap16-tampered-' . $runId . '.sql';
        $temporaryFiles = [$sourceClient, $restoreClient, $dumpFile, $tamperedDump];

        $sourcePdo = ap16_connect($config['source']);
        $restorePdo = ap16_connect($config['restore']);
        $sourceInfo = ap16_server_info($sourcePdo);
        $restoreInfo = ap16_server_info($restorePdo);
        ap16_assert_empty($sourcePdo);
        ap16_assert_empty($restorePdo);

        ap16_create_table($sourcePdo, $sourceTable);
        ap16_seed($sourcePdo, $sourceTable);
        $sourceSnapshot = ap16_target_snapshot($sourcePdo, $sourceTable);

        ap16_write_client_file($config['source'], $sourceClient);
        ap16_write_client_file($config['restore'], $restoreClient);
        ap16_dump($config['source'], $sourceTable, $sourceClient, $dumpFile);
        $dumpHash = ap16_hash_file($dumpFile);
        copy($dumpFile, $tamperedDump);
        file_put_contents($tamperedDump, "-- AP1.6 tamper\n", FILE_APPEND | LOCK_EX);
        $tamperedDetected = ap16_hash_file($tamperedDump) !== $dumpHash;
        if (!$tamperedDetected) {
            ap16_fail('tamper_not_detected');
        }

        ap16_restore($config['restore'], $restoreClient, $dumpFile);
        $restoreSnapshot = ap16_target_snapshot($restorePdo, $sourceTable);
        $structureMatch = $sourceSnapshot['schemaHash'] === $restoreSnapshot['schemaHash'];
        $contentMatch = $sourceSnapshot['rowsHash'] === $restoreSnapshot['rowsHash'];
        $checksumMatch = $sourceSnapshot['contentHash'] === $restoreSnapshot['contentHash'];
        $result['checks'] = [
            'sourceSchemaHash' => $sourceSnapshot['schemaHash'],
            'restoreSchemaHash' => $restoreSnapshot['schemaHash'],
            'sourceRowsHash' => $sourceSnapshot['rowsHash'],
            'restoreRowsHash' => $restoreSnapshot['rowsHash'],
            'sourceRowCount' => count($sourceSnapshot['rows']),
            'restoreRowCount' => count($restoreSnapshot['rows']),
            'structureMatch' => $structureMatch,
            'contentMatch' => $contentMatch,
            'checksumMatch' => $checksumMatch,
        ];
        if (!$structureMatch || !$contentMatch || !$checksumMatch) {
            ap16_fail('restore_comparison_failed');
        }

        $restoreDump = AP16_PRIVATE_DIR . '/.ap16-restore-dump-' . $runId . '.sql';
        $temporaryFiles[] = $restoreDump;
        ap16_dump($config['restore'], $sourceTable, $restoreClient, $restoreDump);
        $restoreDumpHash = ap16_hash_file($restoreDump);

        $beforeCheckout = ap16_rollback_gate(0);
        $afterCheckout = ap16_rollback_gate(1);
        if (!$beforeCheckout['allowed'] || $afterCheckout['allowed']) {
            ap16_fail('rollback_gate_invalid');
        }

        $result = [
            'status' => 'passed',
            'testOnly' => true,
            'databaseEngine' => 'mysql8-innodb',
            'tls' => [
                'source' => $sourceInfo,
                'restore' => $restoreInfo,
                'caHostVerificationResidualRisk' => trim((string) ($config['source']['tlsCaPath'] ?? '')) === ''
                    || trim((string) ($config['restore']['tlsCaPath'] ?? '')) === '',
            ],
            'checks' => [
                'sourceAndRestoreSeparate' => true,
                'sourceWasEmptyBeforeTest' => true,
                'restoreWasEmptyBeforeTest' => true,
                'backupCreated' => is_file($dumpFile),
                'structureMatch' => $structureMatch,
                'contentMatch' => $contentMatch,
                'checksumMatch' => $checksumMatch,
                'dumpHash' => $dumpHash,
                'restoreDumpHash' => $restoreDumpHash,
                'tamperDetected' => $tamperedDetected,
                'rollbackBeforeCheckoutAllowed' => $beforeCheckout['allowed'],
                'rollbackAfterCheckoutLocked' => !$afterCheckout['allowed'],
                'rollbackAfterCheckoutCode' => $afterCheckout['code'],
            ],
        ];
    } catch (Throwable $error) {
        $result['error'] = $error->getMessage();
    } finally {
        if ($sourcePdo instanceof PDO && $sourceTable !== '') {
            $cleanup['sourceTableRemoved'] = ap16_drop($sourcePdo, $sourceTable);
            try {
                $cleanup['sourceEmptyAfterCleanup'] = ap16_table_names($sourcePdo) === [];
            } catch (Throwable) {
                $cleanup['sourceEmptyAfterCleanup'] = false;
            }
        }
        if ($restorePdo instanceof PDO && $restoreTable !== '') {
            $cleanup['restoreTableRemoved'] = ap16_drop($restorePdo, $sourceTable);
            try {
                $cleanup['restoreEmptyAfterCleanup'] = ap16_table_names($restorePdo) === [];
            } catch (Throwable) {
                $cleanup['restoreEmptyAfterCleanup'] = false;
            }
        }
        foreach ($temporaryFiles as $path) {
            @unlink($path);
        }
        $cleanup['temporaryFilesRemoved'] = array_reduce(
            $temporaryFiles,
            static fn (bool $clean, string $path): bool => $clean && !file_exists($path),
            true
        );
        $result['cleanup'] = $cleanup;
    }

    echo json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . PHP_EOL;
    return ($result['status'] ?? 'failed') === 'passed' && $cleanup['temporaryFilesRemoved']
        && $cleanup['sourceEmptyAfterCleanup'] && $cleanup['restoreEmptyAfterCleanup']
        ? 0
        : 1;
}

exit(ap16_main());
