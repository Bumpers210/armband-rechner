<?php

declare(strict_types=1);

final class CarmajaProductionBackupException extends RuntimeException
{
    public function __construct(public readonly string $errorCode)
    {
        parent::__construct($errorCode);
    }
}

final class CarmajaBackupCipher
{
    private const MAGIC = "CMJBKP1\0";
    private const CHUNK_SIZE = 65536;
    private const MAX_FRAME_SIZE = self::CHUNK_SIZE + 128;

    public static function decodeKey(string $encoded): string
    {
        $key = base64_decode($encoded, true);
        if (!is_string($key) || strlen($key) !== SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_KEYBYTES) {
            throw new CarmajaProductionBackupException('backup_key_invalid');
        }
        return $key;
    }

    public static function encryptResource($input, string $outputPath, string $encodedKey, string $keyId): array
    {
        if (!is_resource($input) || preg_match('/^[A-Za-z0-9._-]{1,80}$/', $keyId) !== 1) {
            throw new CarmajaProductionBackupException('backup_cipher_input_invalid');
        }

        $chunks = (static function () use ($input): Generator {
            while (!feof($input)) {
                $chunk = fread($input, self::CHUNK_SIZE);
                if ($chunk === false) {
                    throw new CarmajaProductionBackupException('backup_input_read_failed');
                }
                if ($chunk !== '') {
                    yield $chunk;
                }
            }
        })();

        return self::encryptChunks($chunks, $outputPath, $encodedKey, $keyId);
    }

    public static function encryptChunks(iterable $chunks, string $outputPath, string $encodedKey, string $keyId): array
    {
        if (preg_match('/^[A-Za-z0-9._-]{1,80}$/', $keyId) !== 1) {
            throw new CarmajaProductionBackupException('backup_cipher_input_invalid');
        }
        $key = self::decodeKey($encodedKey);
        [$state, $header] = sodium_crypto_secretstream_xchacha20poly1305_init_push($key);
        $output = fopen($outputPath, 'xb');
        if ($output === false) {
            sodium_memzero($key);
            throw new CarmajaProductionBackupException('backup_output_open_failed');
        }
        chmod($outputPath, 0640);
        $plainHash = hash_init('sha256');

        try {
            self::writeAll($output, self::MAGIC);
            self::writeAll($output, pack('n', strlen($keyId)) . $keyId . $header);
            foreach ($chunks as $chunk) {
                if (!is_string($chunk)) {
                    throw new CarmajaProductionBackupException('backup_input_read_failed');
                }
                if ($chunk === '') {
                    continue;
                }
                foreach (str_split($chunk, self::CHUNK_SIZE) as $piece) {
                    hash_update($plainHash, $piece);
                    $cipher = sodium_crypto_secretstream_xchacha20poly1305_push(
                        $state,
                        $piece,
                        '',
                        SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_MESSAGE
                    );
                    self::writeFrame($output, $cipher);
                }
            }
            $final = sodium_crypto_secretstream_xchacha20poly1305_push(
                $state,
                '',
                '',
                SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL
            );
            self::writeFrame($output, $final);
            fflush($output);
        } catch (Throwable $error) {
            fclose($output);
            @unlink($outputPath);
            sodium_memzero($key);
            if ($error instanceof CarmajaProductionBackupException) {
                throw $error;
            }
            throw new CarmajaProductionBackupException('backup_encrypt_failed');
        }

        fclose($output);
        sodium_memzero($key);
        $cipherHash = hash_file('sha256', $outputPath);
        $bytes = filesize($outputPath);
        if (!is_string($cipherHash) || !is_int($bytes)) {
            @unlink($outputPath);
            throw new CarmajaProductionBackupException('backup_artifact_stat_failed');
        }
        return [
            'plainSha256' => hash_final($plainHash),
            'sha256' => $cipherHash,
            'bytes' => $bytes,
        ];
    }

    public static function decryptToResource(string $inputPath, $output, string $encodedKey, string $expectedKeyId): array
    {
        if (!is_resource($output)) {
            throw new CarmajaProductionBackupException('backup_restore_output_invalid');
        }
        $input = fopen($inputPath, 'rb');
        if ($input === false) {
            throw new CarmajaProductionBackupException('backup_artifact_missing');
        }
        $key = self::decodeKey($encodedKey);
        $plainHash = hash_init('sha256');
        $finalSeen = false;
        try {
            if (self::readExact($input, strlen(self::MAGIC)) !== self::MAGIC) {
                throw new CarmajaProductionBackupException('backup_format_invalid');
            }
            $keyLength = unpack('nlength', self::readExact($input, 2))['length'] ?? 0;
            if (!is_int($keyLength) || $keyLength < 1 || $keyLength > 80) {
                throw new CarmajaProductionBackupException('backup_format_invalid');
            }
            $keyId = self::readExact($input, $keyLength);
            if (!hash_equals($expectedKeyId, $keyId)) {
                throw new CarmajaProductionBackupException('backup_key_id_mismatch');
            }
            $header = self::readExact(
                $input,
                SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_HEADERBYTES
            );
            $state = sodium_crypto_secretstream_xchacha20poly1305_init_pull($header, $key);
            while (!feof($input)) {
                $lengthBytes = fread($input, 4);
                if ($lengthBytes === false || $lengthBytes === '') {
                    break;
                }
                if (strlen($lengthBytes) !== 4) {
                    throw new CarmajaProductionBackupException('backup_truncated');
                }
                $length = unpack('Nlength', $lengthBytes)['length'] ?? 0;
                if (!is_int($length) || $length < 1 || $length > self::MAX_FRAME_SIZE) {
                    throw new CarmajaProductionBackupException('backup_frame_invalid');
                }
                $frame = self::readExact($input, $length);
                $opened = sodium_crypto_secretstream_xchacha20poly1305_pull($state, $frame);
                if (!is_array($opened)) {
                    throw new CarmajaProductionBackupException('backup_authentication_failed');
                }
                [$plain, $tag] = $opened;
                if ($finalSeen || ($tag !== SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_MESSAGE
                        && $tag !== SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL)) {
                    throw new CarmajaProductionBackupException('backup_frame_invalid');
                }
                if ($plain !== '') {
                    hash_update($plainHash, $plain);
                    self::writeAll($output, $plain);
                }
                if ($tag === SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL) {
                    $finalSeen = true;
                }
            }
            if (!$finalSeen) {
                throw new CarmajaProductionBackupException('backup_truncated');
            }
        } finally {
            fclose($input);
            sodium_memzero($key);
        }
        return ['plainSha256' => hash_final($plainHash)];
    }

    private static function writeFrame($output, string $frame): void
    {
        self::writeAll($output, pack('N', strlen($frame)) . $frame);
    }

    private static function writeAll($stream, string $content): void
    {
        $offset = 0;
        while ($offset < strlen($content)) {
            $written = fwrite($stream, substr($content, $offset));
            if (!is_int($written) || $written < 1) {
                throw new CarmajaProductionBackupException('backup_output_write_failed');
            }
            $offset += $written;
        }
    }

    private static function readExact($stream, int $length): string
    {
        $content = '';
        while (strlen($content) < $length) {
            $chunk = fread($stream, $length - strlen($content));
            if ($chunk === false || $chunk === '') {
                throw new CarmajaProductionBackupException('backup_truncated');
            }
            $content .= $chunk;
        }
        return $content;
    }
}

final class CarmajaProductionBackup
{
    private const FORMAT_VERSION = 1;
    private const READY_RETENTION_SECONDS = 604800;
    private const SERVER_WARNING_SECONDS = 5400;
    private const OFFSITE_WARNING_SECONDS = 86400;
    private const PRODUCT_PATHS = [
        'environment.json',
        'products',
        'drafts',
        'uploads',
        'sku-counter',
        'auth',
        'audit',
        'idempotency',
    ];

    public function __construct(
        private readonly array $config,
        private readonly string $dumpBinary = '/usr/bin/mysqldump',
        private readonly string $mysqlBinary = '/usr/bin/mysql',
        private readonly string $tarBinary = '/usr/bin/tar'
    ) {
        $this->validateConfig();
    }

    public static function loadRuntime(string $path): array
    {
        if (!is_file($path) || is_link($path) || (fileperms($path) & 0777) !== 0600) {
            throw new CarmajaProductionBackupException('backup_runtime_invalid');
        }
        $config = (static fn (string $runtime): mixed => require $runtime)($path);
        if (!is_array($config) || array_is_list($config)) {
            throw new CarmajaProductionBackupException('backup_runtime_invalid');
        }
        if ((!is_string($config['backupEncryptionKey'] ?? null) || $config['backupEncryptionKey'] === '')
            && is_string($config['backupEncryptionKeyFile'] ?? null)
            && $config['backupEncryptionKeyFile'] !== '') {
            $keyFile = $config['backupEncryptionKeyFile'];
            if (!is_file($keyFile) || is_link($keyFile) || (fileperms($keyFile) & 0777) !== 0600) {
                throw new CarmajaProductionBackupException('backup_key_file_invalid');
            }
            $keyConfig = (static fn (string $privateKeyFile): mixed => require $privateKeyFile)($keyFile);
            if (!is_array($keyConfig) || array_is_list($keyConfig)
                || !is_string($keyConfig['key'] ?? null) || !is_string($keyConfig['keyId'] ?? null)) {
                throw new CarmajaProductionBackupException('backup_key_file_invalid');
            }
            $config['backupEncryptionKey'] = $keyConfig['key'];
            $config['backupEncryptionKeyId'] = $keyConfig['keyId'];
        }
        $config['runtimeFile'] = $path;
        return $config;
    }

    public function create(): array
    {
        $root = $this->backupRoot();
        $this->ensureDirectory($root, 0750);
        $this->ensureDirectory($root . '/ready', 0750);
        $this->ensureDirectory($root . '/.staging', 0700);
        $this->ensureDirectory($root . '/locks', 0750);
        $lock = fopen($root . '/locks/backup.lock', 'c');
        if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
            if (is_resource($lock)) {
                fclose($lock);
            }
            throw new CarmajaProductionBackupException('backup_already_running');
        }

        $backupId = gmdate('Ymd\THis\Z') . '-' . bin2hex(random_bytes(6));
        $stage = $root . '/.staging/' . $backupId;
        $target = $root . '/ready/' . $backupId;
        try {
            $this->cleanupStaging();
            $this->ensureDirectory($stage, 0700);
            $inventoryBefore = $this->productInventory();
            $schemaBefore = $this->schemaDescriptor();
            $databaseBefore = $this->databaseFingerprint(false);
            $inventoryPath = $stage . '/product-inventory.json';
            $this->writeJson($inventoryPath, $inventoryBefore, 0600);

            $commerce = $this->createCommerceArtifact($stage . '/commerce.sql.gz.cmjbkp', $stage);
            $product = $this->createProductArtifact($stage . '/product-data.tar.gz.cmjbkp', $stage);
            if ($inventoryBefore !== $this->productInventory()) {
                throw new CarmajaProductionBackupException('backup_product_changed');
            }
            if ($schemaBefore !== $this->schemaDescriptor()) {
                throw new CarmajaProductionBackupException('backup_schema_changed');
            }
            if ($databaseBefore !== $this->databaseFingerprint(false)) {
                throw new CarmajaProductionBackupException('backup_database_changed');
            }

            $commerce['databaseFingerprint'] = $databaseBefore;

            $manifest = [
                'formatVersion' => self::FORMAT_VERSION,
                'schema' => $schemaBefore,
                'backupId' => $backupId,
                'createdAt' => gmdate('c'),
                'keyId' => $this->config['backupEncryptionKeyId'],
                'offsiteTarget' => $this->config['backupOffsiteTarget'],
                'artifacts' => [
                    'commerce' => ['file' => 'commerce.sql.gz.cmjbkp'] + $commerce,
                    'productData' => [
                        'file' => 'product-data.tar.gz.cmjbkp',
                        'inventorySha256' => hash('sha256', $this->canonicalJson($inventoryBefore)),
                    ] + $product,
                ],
            ];
            $manifest['manifestHmac'] = $this->manifestHmac($manifest);
            $manifestPath = $stage . '/manifest.json';
            $this->writeJson($manifestPath, $manifest, 0640);
            $manifestHash = hash_file('sha256', $manifestPath);
            if (!is_string($manifestHash)) {
                throw new CarmajaProductionBackupException('backup_manifest_hash_failed');
            }
            if (file_put_contents($stage . '/ready', $manifestHash . "\n", LOCK_EX) === false) {
                throw new CarmajaProductionBackupException('backup_ready_write_failed');
            }
            chmod($stage . '/ready', 0640);
            if (!rename($stage, $target)) {
                throw new CarmajaProductionBackupException('backup_publish_failed');
            }
            $this->prune();
            return ['status' => 'created', 'backupId' => $backupId, 'manifestSha256' => $manifestHash];
        } catch (Throwable $error) {
            $this->removeTree($stage);
            if ($error instanceof CarmajaProductionBackupException) {
                throw $error;
            }
            throw new CarmajaProductionBackupException('backup_create_failed');
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    public function listReady(): array
    {
        $items = [];
        foreach ($this->readyDirectories() as $directory) {
            $manifest = $this->loadManifest($directory);
            $items[] = [
                'backupId' => $manifest['backupId'],
                'createdAt' => $manifest['createdAt'],
                'keyId' => $manifest['keyId'],
                'manifestSha256' => hash_file('sha256', $directory . '/manifest.json'),
                'files' => [
                    [
                        'name' => 'manifest.json',
                        'bytes' => filesize($directory . '/manifest.json'),
                        'sha256' => hash_file('sha256', $directory . '/manifest.json'),
                    ],
                    [
                        'name' => 'commerce.sql.gz.cmjbkp',
                        'bytes' => $manifest['artifacts']['commerce']['bytes'],
                        'sha256' => $manifest['artifacts']['commerce']['sha256'],
                    ],
                    [
                        'name' => 'product-data.tar.gz.cmjbkp',
                        'bytes' => $manifest['artifacts']['productData']['bytes'],
                        'sha256' => $manifest['artifacts']['productData']['sha256'],
                    ],
                ],
                'totalBytes' => filesize($directory . '/manifest.json')
                    + (int) $manifest['artifacts']['commerce']['bytes']
                    + (int) $manifest['artifacts']['productData']['bytes'],
                'acknowledged' => is_file($directory . '/download-ack.json'),
            ];
        }
        return $items;
    }

    public function acknowledge(string $backupId, string $manifestSha256): array
    {
        $directory = $this->backupDirectory($backupId);
        $this->loadManifest($directory);
        $actual = hash_file('sha256', $directory . '/manifest.json');
        if (!is_string($actual) || preg_match('/^[a-f0-9]{64}$/', $manifestSha256) !== 1
            || !hash_equals($actual, $manifestSha256)) {
            throw new CarmajaProductionBackupException('backup_ack_hash_mismatch');
        }
        $ackPath = $directory . '/download-ack.json';
        if (is_file($ackPath)) {
            $existing = json_decode((string) file_get_contents($ackPath), true, 16, JSON_THROW_ON_ERROR);
            if (!is_array($existing) || ($existing['backupId'] ?? null) !== $backupId
                || ($existing['manifestSha256'] ?? null) !== $actual) {
                throw new CarmajaProductionBackupException('backup_ack_invalid');
            }
            return ['status' => 'acknowledged', 'backupId' => $backupId, 'idempotent' => true];
        }
        $ack = [
            'backupId' => $backupId,
            'manifestSha256' => $actual,
            'downloadedAt' => gmdate('c'),
        ];
        $this->writeJson($ackPath, $ack, 0640);
        return ['status' => 'acknowledged', 'backupId' => $backupId, 'idempotent' => false];
    }

    public function status(): array
    {
        $directories = $this->readyDirectories();
        $latestReady = $directories === [] ? null : end($directories);
        $latestAck = null;
        foreach ($directories as $directory) {
            if (is_file($directory . '/download-ack.json')) {
                $latestAck = $directory;
            }
        }
        $readyAge = $latestReady === null ? null : time() - (int) filemtime($latestReady . '/ready');
        $ackAge = $latestAck === null ? null : time() - (int) filemtime($latestAck . '/download-ack.json');
        return [
            'status' => ($readyAge === null || $readyAge > self::SERVER_WARNING_SECONDS
                    || $ackAge === null || $ackAge > self::OFFSITE_WARNING_SECONDS)
                ? 'warning'
                : 'ok',
            'latestReadyAt' => $latestReady === null ? null : gmdate('c', (int) filemtime($latestReady . '/ready')),
            'latestDownloadAt' => $latestAck === null ? null : gmdate('c', (int) filemtime($latestAck . '/download-ack.json')),
            'serverBackupOverdue' => $readyAge === null || $readyAge > self::SERVER_WARNING_SECONDS,
            'offsiteDownloadOverdue' => $ackAge === null || $ackAge > self::OFFSITE_WARNING_SECONDS,
        ];
    }

    public function restoreDryRun(string $backupId): array
    {
        $directory = $this->backupDirectory($backupId);
        $manifest = $this->loadManifest($directory);
        $restoreRoot = $this->backupRoot() . '/.restore/' . $backupId . '-' . bin2hex(random_bytes(4));
        $this->ensureDirectory($restoreRoot, 0700);
        $restoreAttempted = false;
        try {
            $this->assertRestoreDatabaseEmpty();
            $commerceGzip = $restoreRoot . '/commerce.sql.gz';
            $this->decryptArtifact($directory, $manifest['artifacts']['commerce'], $commerceGzip);
            $sqlPath = $restoreRoot . '/commerce.sql';
            $sqlHash = $this->gunzip($commerceGzip, $sqlPath);
            if (!hash_equals((string) $manifest['artifacts']['commerce']['sourcePlainSha256'], $sqlHash)) {
                throw new CarmajaProductionBackupException('backup_restore_sql_hash_mismatch');
            }
            $restoreAttempted = true;
            $this->restoreSql($sqlPath);
            $restoredFingerprint = $this->databaseFingerprint(true);
            $expectedFingerprint = $manifest['artifacts']['commerce']['databaseFingerprint'];
            if ($expectedFingerprint !== $restoredFingerprint) {
                foreach (['schemaSha256' => 'schema', 'dataSha256' => 'data', 'metadataSha256' => 'metadata'] as $field => $kind) {
                    if (!hash_equals((string) $expectedFingerprint[$field], (string) $restoredFingerprint[$field])) {
                        throw new CarmajaProductionBackupException('backup_restore_database_' . $kind . '_compare_failed');
                    }
                }
                throw new CarmajaProductionBackupException('backup_restore_database_compare_failed');
            }

            $productTar = $restoreRoot . '/product-data.tar.gz';
            $this->decryptArtifact($directory, $manifest['artifacts']['productData'], $productTar);
            $extract = $restoreRoot . '/product';
            $this->ensureDirectory($extract, 0700);
            $this->validateTarEntries($this->tarList($productTar));
            $this->runProcess([$this->tarBinary, '-xzf', $productTar, '-C', $extract]);
            $inventoryPath = $extract . '/product-inventory.json';
            $inventoryRaw = file_get_contents($inventoryPath);
            if (!is_string($inventoryRaw)
                || !hash_equals((string) $manifest['artifacts']['productData']['inventorySha256'], hash('sha256', $this->canonicalJson(json_decode($inventoryRaw, true, 64, JSON_THROW_ON_ERROR))))) {
                throw new CarmajaProductionBackupException('backup_restore_product_compare_failed');
            }
            $this->verifyExtractedInventory($extract, json_decode($inventoryRaw, true, 64, JSON_THROW_ON_ERROR));
            return ['status' => 'restored_and_compared', 'backupId' => $backupId];
        } finally {
            try {
                if ($restoreAttempted) {
                    $this->clearRestoreDatabase();
                }
            } finally {
                $this->removeTree($restoreRoot);
            }
        }
    }

    public function validateTarEntries(array $entries): void
    {
        foreach ($entries as $entry) {
            $entry = trim((string) $entry);
            if ($entry === '') {
                continue;
            }
            $normalized = str_replace('\\', '/', $entry);
            if (str_starts_with($normalized, '/') || preg_match('#(^|/)\.\.(/|$)#', $normalized) === 1) {
                throw new CarmajaProductionBackupException('backup_archive_path_invalid');
            }
        }
    }

    private function validateConfig(): void
    {
        $required = [
            'runtimeFile', 'productPrivateDir', 'commerceDsn', 'commerceUser', 'commercePassword',
            'commerceRestoreDsn', 'commerceRestoreUser', 'commerceRestorePassword', 'backupDirectory',
            'backupOffsiteTarget', 'backupEncryptionKey', 'backupEncryptionKeyId',
        ];
        foreach ($required as $key) {
            if (!is_string($this->config[$key] ?? null) || trim($this->config[$key]) === '') {
                throw new CarmajaProductionBackupException('backup_config_incomplete');
            }
        }
        $productionMode = ($this->config['environment'] ?? null) === 'production'
            && ($this->config['publishTarget'] ?? null) === 'production'
            && $this->config['backupOffsiteTarget'] === 'onedrive-pull://carmaja-production/Carmaja-Perlen/Backups';
        $testMode = ($this->config['environment'] ?? null) === 'test'
            && ($this->config['publishTarget'] ?? null) === 'test'
            && ($this->config['backupTestMode'] ?? null) === true
            && str_starts_with(rtrim((string) ($this->config['privateDir'] ?? ''), '/') . '/', '/home/www/carmaja-private-test/')
            && $this->config['backupOffsiteTarget'] === 'test-sink://ap7-backup-e2e';
        if ((!$productionMode && !$testMode)
            || ($this->config['productionPublishEnabled'] ?? null) !== false
            || ($this->config['githubAdapterEnabled'] ?? null) !== false
            || ($this->config['commerceRequireTls'] ?? null) !== true
            || ($this->config['commerceRestoreRequireTls'] ?? null) !== true
            || $this->databaseName($this->config['commerceDsn']) === $this->databaseName($this->config['commerceRestoreDsn'])
            || $this->config['commerceUser'] === $this->config['commerceRestoreUser']) {
            throw new CarmajaProductionBackupException('backup_config_unsafe');
        }
        $validatedKey = CarmajaBackupCipher::decodeKey($this->config['backupEncryptionKey']);
        sodium_memzero($validatedKey);
        foreach ([$this->dumpBinary, $this->mysqlBinary, $this->tarBinary] as $binary) {
            if (!is_file($binary) || !is_executable($binary)) {
                throw new CarmajaProductionBackupException('backup_tool_unavailable');
            }
        }
    }

    private function createCommerceArtifact(string $path, string $temporaryRoot): array
    {
        $options = $this->writeClientOptions($temporaryRoot, false);
        try {
            $this->assertClientTls($options, false);
            $command = $this->dumpCommand($options, false);
            $process = $this->openProcess($command);
            $gzip = deflate_init(ZLIB_ENCODING_GZIP, ['level' => 6]);
            if ($gzip === false) {
                throw new CarmajaProductionBackupException('backup_gzip_failed');
            }
            $sourceHash = hash_init('sha256');
            $chunks = (function () use ($process, $gzip, $sourceHash): Generator {
                while (!feof($process['stdout'])) {
                    $chunk = fread($process['stdout'], 65536);
                    if ($chunk === false) {
                        throw new CarmajaProductionBackupException('backup_dump_read_failed');
                    }
                    if ($chunk === '') {
                        continue;
                    }
                    hash_update($sourceHash, $chunk);
                    $encoded = deflate_add($gzip, $chunk, ZLIB_NO_FLUSH);
                    if (!is_string($encoded)) {
                        throw new CarmajaProductionBackupException('backup_gzip_failed');
                    }
                    if ($encoded !== '') {
                        yield $encoded;
                    }
                }
                $final = deflate_add($gzip, '', ZLIB_FINISH);
                if (!is_string($final)) {
                    throw new CarmajaProductionBackupException('backup_gzip_failed');
                }
                if ($final !== '') {
                    yield $final;
                }
            })();
            $artifact = CarmajaBackupCipher::encryptChunks(
                $chunks,
                $path,
                $this->config['backupEncryptionKey'],
                $this->config['backupEncryptionKeyId']
            );
            $this->finishProcess($process);
            $artifact['sourcePlainSha256'] = hash_final($sourceHash);
            return $artifact;
        } catch (Throwable $error) {
            if (isset($process) && is_array($process)) {
                $this->closeProcess($process);
            }
            @unlink($path);
            throw $error;
        } finally {
            @unlink($options);
        }
    }

    private function createProductArtifact(string $path, string $stage): array
    {
        $runtimeName = basename($this->config['runtimeFile']);
        if (preg_match('/^[A-Za-z0-9._-]+\.php$/', $runtimeName) !== 1) {
            throw new CarmajaProductionBackupException('backup_runtime_name_invalid');
        }
        $arguments = [$this->tarBinary, '-czf', '-', '--format=posix', '-C', $this->config['productPrivateDir']];
        foreach (self::PRODUCT_PATHS as $relative) {
            if (file_exists($this->config['productPrivateDir'] . '/' . $relative)) {
                $arguments[] = $relative;
            }
        }
        array_push(
            $arguments,
            '-C',
            dirname($this->config['runtimeFile']),
            '--transform=s|^' . preg_quote($runtimeName, '|') . '$|runtime/runtime-config.php|',
            $runtimeName,
            '-C',
            $stage,
            'product-inventory.json'
        );
        $process = $this->openProcess($arguments);
        try {
            $artifact = CarmajaBackupCipher::encryptResource(
                $process['stdout'],
                $path,
                $this->config['backupEncryptionKey'],
                $this->config['backupEncryptionKeyId']
            );
            $this->finishProcess($process);
            return $artifact;
        } catch (Throwable $error) {
            $this->closeProcess($process);
            throw $error;
        }
    }

    private function productInventory(): array
    {
        $inventory = [];
        foreach (self::PRODUCT_PATHS as $relative) {
            $path = $this->config['productPrivateDir'] . '/' . $relative;
            if (is_link($path)) {
                throw new CarmajaProductionBackupException('backup_product_symlink_forbidden');
            }
            if (is_file($path)) {
                $inventory[$relative] = $this->fileDescriptor($path);
                continue;
            }
            if (!is_dir($path)) {
                continue;
            }
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS)
            );
            foreach ($iterator as $entry) {
                if ($entry->isLink()) {
                    throw new CarmajaProductionBackupException('backup_product_symlink_forbidden');
                }
                if (!$entry->isFile()) {
                    continue;
                }
                $name = $relative . '/' . str_replace('\\', '/', substr($entry->getPathname(), strlen($path) + 1));
                $inventory[$name] = $this->fileDescriptor($entry->getPathname());
            }
        }
        $inventory['runtime/runtime-config.php'] = $this->fileDescriptor($this->config['runtimeFile']);
        ksort($inventory, SORT_STRING);
        return ['files' => $inventory];
    }

    private function schemaDescriptor(): array
    {
        $pdo = $this->databasePdo(false);
        $tables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('schema_migrations', $tables, true)) {
            $descriptor = ['state' => 'uninitialized', 'migrations' => []];
            $descriptor['sha256'] = hash('sha256', $this->canonicalJson($descriptor));
            return $descriptor;
        }
        $rows = $pdo->query(
            'SELECT migration_id, checksum FROM schema_migrations ORDER BY migration_id'
        )->fetchAll(PDO::FETCH_ASSOC);
        $migrations = [];
        foreach ($rows as $row) {
            $id = $row['migration_id'] ?? null;
            $checksum = $row['checksum'] ?? null;
            if (!is_string($id) || preg_match('/^[A-Za-z0-9._:-]{1,100}$/', $id) !== 1
                || !is_string($checksum) || preg_match('/^[a-f0-9]{64}$/', $checksum) !== 1) {
                throw new CarmajaProductionBackupException('backup_schema_journal_invalid');
            }
            $migrations[] = ['migrationId' => $id, 'checksum' => $checksum];
        }
        $descriptor = ['state' => 'initialized', 'migrations' => $migrations];
        $descriptor['sha256'] = hash('sha256', $this->canonicalJson($descriptor));
        return $descriptor;
    }

    private function databaseFingerprint(bool $restore): array
    {
        $pdo = $this->databasePdo($restore);
        $database = $this->databaseName(
            $this->config[$restore ? 'commerceRestoreDsn' : 'commerceDsn']
        );
        $encodeRows = function (array $rows): array {
            $hashes = [];
            foreach ($rows as $row) {
                if (!is_array($row)) {
                    throw new CarmajaProductionBackupException('backup_database_fingerprint_failed');
                }
                $encoded = [];
                foreach ($row as $column => $value) {
                    if (!is_string($column) || (!is_scalar($value) && $value !== null)) {
                        throw new CarmajaProductionBackupException('backup_database_fingerprint_failed');
                    }
                    $encoded[$column] = $value === null ? null : base64_encode((string) $value);
                }
                $hashes[] = hash('sha256', $this->canonicalJson($encoded));
            }
            sort($hashes, SORT_STRING);
            return $hashes;
        };
        $schemaRows = function (string $query, string $table) use ($pdo, $encodeRows): array {
            $statement = $pdo->prepare($query);
            $statement->execute([$table]);
            return $encodeRows($statement->fetchAll(PDO::FETCH_ASSOC));
        };

        $pdo->beginTransaction();
        try {
            $objects = $pdo->query(
                'SELECT TABLE_NAME, TABLE_TYPE FROM information_schema.TABLES
                 WHERE TABLE_SCHEMA = DATABASE() ORDER BY TABLE_NAME'
            )->fetchAll(PDO::FETCH_ASSOC);
            $schemaTables = [];
            $dataTables = [];
            foreach ($objects as $object) {
                $name = $object['TABLE_NAME'] ?? null;
                $type = $object['TABLE_TYPE'] ?? null;
                if (!is_string($name) || preg_match('/^[A-Za-z0-9_]+$/', $name) !== 1
                    || !in_array($type, ['BASE TABLE', 'VIEW'], true)) {
                    throw new CarmajaProductionBackupException('backup_database_fingerprint_failed');
                }
                $schema = [];
                if ($type === 'BASE TABLE') {
                    $tableStatement = $pdo->prepare(
                        'SELECT ENGINE, VERSION, ROW_FORMAT, AUTO_INCREMENT, TABLE_COLLATION,
                                CREATE_OPTIONS, TABLE_COMMENT
                         FROM information_schema.TABLES
                         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?'
                    );
                    $tableStatement->execute([$name]);
                    $columnStatement = $pdo->prepare(
                        'SELECT COLUMN_NAME, ORDINAL_POSITION, COLUMN_DEFAULT, IS_NULLABLE, DATA_TYPE,
                                CHARACTER_MAXIMUM_LENGTH, CHARACTER_OCTET_LENGTH, NUMERIC_PRECISION,
                                NUMERIC_SCALE, DATETIME_PRECISION, CHARACTER_SET_NAME, COLLATION_NAME,
                                COLUMN_TYPE, COLUMN_KEY, EXTRA, COLUMN_COMMENT, GENERATION_EXPRESSION, SRS_ID
                         FROM information_schema.COLUMNS
                         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? ORDER BY ORDINAL_POSITION'
                    );
                    $columnStatement->execute([$name]);
                    $columnRows = $columnStatement->fetchAll(PDO::FETCH_ASSOC);
                    $tableRows = $this->normalizeTableMetadata(
                        $tableStatement->fetchAll(PDO::FETCH_ASSOC),
                        $columnRows
                    );
                    $schema['table'] = $encodeRows($tableRows);
                    $schema['columns'] = $encodeRows($columnRows);
                    $schema['indexes'] = $schemaRows(
                        'SELECT INDEX_NAME, NON_UNIQUE, SEQ_IN_INDEX, COLUMN_NAME, COLLATION, SUB_PART,
                                PACKED, NULLABLE, INDEX_TYPE, COMMENT, INDEX_COMMENT, IS_VISIBLE, EXPRESSION
                         FROM information_schema.STATISTICS
                         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? ORDER BY INDEX_NAME, SEQ_IN_INDEX',
                        $name
                    );
                    $schema['constraints'] = $schemaRows(
                        'SELECT CONSTRAINT_NAME, CONSTRAINT_TYPE, ENFORCED
                         FROM information_schema.TABLE_CONSTRAINTS
                         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? ORDER BY CONSTRAINT_NAME',
                        $name
                    );
                    $schema['keys'] = $schemaRows(
                        'SELECT CONSTRAINT_NAME, COLUMN_NAME, ORDINAL_POSITION, POSITION_IN_UNIQUE_CONSTRAINT,
                                REFERENCED_TABLE_NAME, REFERENCED_COLUMN_NAME
                         FROM information_schema.KEY_COLUMN_USAGE
                         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?
                         ORDER BY CONSTRAINT_NAME, ORDINAL_POSITION',
                        $name
                    );
                    $schema['references'] = $schemaRows(
                        'SELECT CONSTRAINT_NAME, UNIQUE_CONSTRAINT_NAME, MATCH_OPTION, UPDATE_RULE, DELETE_RULE
                         FROM information_schema.REFERENTIAL_CONSTRAINTS
                         WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = ? ORDER BY CONSTRAINT_NAME',
                        $name
                    );
                    $schema['checks'] = $schemaRows(
                        'SELECT tc.CONSTRAINT_NAME, cc.CHECK_CLAUSE, tc.ENFORCED
                         FROM information_schema.TABLE_CONSTRAINTS tc
                         JOIN information_schema.CHECK_CONSTRAINTS cc
                           ON cc.CONSTRAINT_SCHEMA = tc.CONSTRAINT_SCHEMA
                          AND cc.CONSTRAINT_NAME = tc.CONSTRAINT_NAME
                         WHERE tc.TABLE_SCHEMA = DATABASE() AND tc.TABLE_NAME = ?
                         ORDER BY tc.CONSTRAINT_NAME',
                        $name
                    );
                } else {
                    $statement = $pdo->prepare(
                        'SELECT VIEW_DEFINITION, CHECK_OPTION, IS_UPDATABLE, SECURITY_TYPE,
                                CHARACTER_SET_CLIENT, COLLATION_CONNECTION
                         FROM information_schema.VIEWS
                         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?'
                    );
                    $statement->execute([$name]);
                    $viewRows = $statement->fetchAll(PDO::FETCH_ASSOC);
                    foreach ($viewRows as &$viewRow) {
                        if (is_string($viewRow['VIEW_DEFINITION'] ?? null)) {
                            $viewRow['VIEW_DEFINITION'] = str_replace(
                                '`' . $database . '`.',
                                '`<database>`.',
                                $viewRow['VIEW_DEFINITION']
                            );
                        }
                    }
                    unset($viewRow);
                    $schema['view'] = $encodeRows($viewRows);
                }
                $rows = [];
                if ($type === 'BASE TABLE') {
                    $rows = $encodeRows($pdo->query('SELECT * FROM `' . $name . '`')->fetchAll(PDO::FETCH_ASSOC));
                }
                $schemaTables[] = [
                    'name' => $name,
                    'type' => $type,
                    'descriptorSha256' => hash('sha256', $this->canonicalJson($schema)),
                ];
                $dataTables[] = [
                    'name' => $name,
                    'rowCount' => count($rows),
                    'rowsSha256' => hash('sha256', implode("\n", $rows)),
                ];
            }

            $metadata = [];
            foreach ([
                'routines' => 'SELECT ROUTINE_NAME, ROUTINE_TYPE, DATA_TYPE, DTD_IDENTIFIER, ROUTINE_DEFINITION,
                                      SQL_DATA_ACCESS, IS_DETERMINISTIC, SQL_MODE, CHARACTER_SET_CLIENT,
                                      COLLATION_CONNECTION, DATABASE_COLLATION
                               FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA = DATABASE()
                               ORDER BY ROUTINE_NAME, ROUTINE_TYPE',
                'triggers' => 'SELECT TRIGGER_NAME, EVENT_MANIPULATION, EVENT_OBJECT_TABLE, ACTION_ORDER,
                                      ACTION_CONDITION, ACTION_STATEMENT, ACTION_ORIENTATION, ACTION_TIMING,
                                      SQL_MODE, DEFINER, CHARACTER_SET_CLIENT, COLLATION_CONNECTION,
                                      DATABASE_COLLATION
                               FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE()
                               ORDER BY TRIGGER_NAME',
                'events' => 'SELECT EVENT_NAME, EVENT_DEFINITION, EVENT_TYPE, EXECUTE_AT, INTERVAL_VALUE,
                                    INTERVAL_FIELD, SQL_MODE, STARTS, ENDS, STATUS, ON_COMPLETION, DEFINER,
                                    CHARACTER_SET_CLIENT, COLLATION_CONNECTION, DATABASE_COLLATION
                             FROM information_schema.EVENTS WHERE EVENT_SCHEMA = DATABASE()
                             ORDER BY EVENT_NAME',
            ] as $kind => $query) {
                $metadata[$kind] = $encodeRows($pdo->query($query)->fetchAll(PDO::FETCH_ASSOC));
            }

            $fingerprint = [
                'schemaSha256' => hash('sha256', $this->canonicalJson($schemaTables)),
                'dataSha256' => hash('sha256', $this->canonicalJson($dataTables)),
                'metadataSha256' => hash('sha256', $this->canonicalJson($metadata)),
            ];
            $pdo->commit();
            return $fingerprint;
        } catch (Throwable $error) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $error;
        }
    }

    private function normalizeTableMetadata(array $tableRows, array $columnRows): array
    {
        $hasAutoIncrement = array_any(
            $columnRows,
            static fn (mixed $column): bool => is_array($column)
                && str_contains((string) ($column['EXTRA'] ?? ''), 'auto_increment')
        );
        if (!$hasAutoIncrement) {
            return $tableRows;
        }
        foreach ($tableRows as &$tableRow) {
            if (is_array($tableRow) && array_key_exists('AUTO_INCREMENT', $tableRow)
                && $tableRow['AUTO_INCREMENT'] === null) {
                // MySQL reports NULL for a freshly restored, still unused AUTO_INCREMENT table.
                // It is semantically identical to the source value 1 and must not break restore verification.
                $tableRow['AUTO_INCREMENT'] = '1';
            }
        }
        unset($tableRow);
        return $tableRows;
    }

    private function fileDescriptor(string $path): array
    {
        $hash = hash_file('sha256', $path);
        $bytes = filesize($path);
        if (!is_string($hash) || !is_int($bytes)) {
            throw new CarmajaProductionBackupException('backup_product_read_failed');
        }
        return ['bytes' => $bytes, 'sha256' => $hash];
    }

    private function loadManifest(string $directory): array
    {
        $path = $directory . '/manifest.json';
        $raw = file_get_contents($path);
        $ready = trim((string) @file_get_contents($directory . '/ready'));
        if (!is_string($raw) || preg_match('/^[a-f0-9]{64}$/', $ready) !== 1
            || !hash_equals($ready, hash('sha256', $raw))) {
            throw new CarmajaProductionBackupException('backup_manifest_invalid');
        }
        $manifest = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
        if (!is_array($manifest) || ($manifest['formatVersion'] ?? null) !== self::FORMAT_VERSION
            || !is_string($manifest['manifestHmac'] ?? null)
            || preg_match('/^[a-f0-9]{64}$/', $manifest['manifestHmac']) !== 1) {
            throw new CarmajaProductionBackupException('backup_manifest_invalid');
        }
        $hmac = $manifest['manifestHmac'];
        unset($manifest['manifestHmac']);
        if (!hash_equals($hmac, $this->manifestHmac($manifest))) {
            throw new CarmajaProductionBackupException('backup_manifest_authentication_failed');
        }
        $manifest['manifestHmac'] = $hmac;
        $this->validateManifest($directory, $manifest);
        return $manifest;
    }

    private function validateManifest(string $directory, array $manifest): void
    {
        $backupId = $manifest['backupId'] ?? null;
        $createdAt = $manifest['createdAt'] ?? null;
        if (!is_string($backupId) || $backupId !== basename($directory)
            || preg_match('/^[0-9]{8}T[0-9]{6}Z-[a-f0-9]{12}$/', $backupId) !== 1
            || !is_string($createdAt) || strtotime($createdAt) === false
            || ($manifest['keyId'] ?? null) !== $this->config['backupEncryptionKeyId']
            || ($manifest['offsiteTarget'] ?? null) !== $this->config['backupOffsiteTarget']) {
            throw new CarmajaProductionBackupException('backup_manifest_invalid');
        }
        $schema = $manifest['schema'] ?? null;
        if (!is_array($schema) || !is_string($schema['sha256'] ?? null)) {
            throw new CarmajaProductionBackupException('backup_manifest_invalid');
        }
        $schemaHash = $schema['sha256'];
        unset($schema['sha256']);
        if (preg_match('/^[a-f0-9]{64}$/', $schemaHash) !== 1
            || !hash_equals($schemaHash, hash('sha256', $this->canonicalJson($schema)))) {
            throw new CarmajaProductionBackupException('backup_manifest_invalid');
        }
        foreach ([
            'commerce' => 'commerce.sql.gz.cmjbkp',
            'productData' => 'product-data.tar.gz.cmjbkp',
        ] as $name => $file) {
            $artifact = $manifest['artifacts'][$name] ?? null;
            if (!is_array($artifact) || ($artifact['file'] ?? null) !== $file
                || !is_int($artifact['bytes'] ?? null) || $artifact['bytes'] < 1
                || !is_string($artifact['sha256'] ?? null)
                || preg_match('/^[a-f0-9]{64}$/', $artifact['sha256']) !== 1
                || !is_string($artifact['plainSha256'] ?? null)
                || preg_match('/^[a-f0-9]{64}$/', $artifact['plainSha256']) !== 1) {
                throw new CarmajaProductionBackupException('backup_manifest_invalid');
            }
            $path = $directory . '/' . $file;
            $bytes = filesize($path);
            $hash = hash_file('sha256', $path);
            if (!is_int($bytes) || $bytes !== $artifact['bytes'] || !is_string($hash)
                || !hash_equals($artifact['sha256'], $hash)) {
                throw new CarmajaProductionBackupException('backup_artifact_hash_mismatch');
            }
        }
        $databaseFingerprint = $manifest['artifacts']['commerce']['databaseFingerprint'] ?? null;
        if (!is_string($manifest['artifacts']['commerce']['sourcePlainSha256'] ?? null)
            || preg_match('/^[a-f0-9]{64}$/', $manifest['artifacts']['commerce']['sourcePlainSha256']) !== 1
            || !is_array($databaseFingerprint) || array_is_list($databaseFingerprint)
            || count($databaseFingerprint) !== 3
            || array_diff(array_keys($databaseFingerprint), ['schemaSha256', 'dataSha256', 'metadataSha256']) !== []
            || array_filter(
                $databaseFingerprint,
                static fn (mixed $hash): bool => !is_string($hash) || preg_match('/^[a-f0-9]{64}$/', $hash) !== 1
            ) !== []
            || !is_string($manifest['artifacts']['productData']['inventorySha256'] ?? null)
            || preg_match('/^[a-f0-9]{64}$/', $manifest['artifacts']['productData']['inventorySha256']) !== 1) {
            throw new CarmajaProductionBackupException('backup_manifest_invalid');
        }
    }

    private function manifestHmac(array $manifest): string
    {
        unset($manifest['manifestHmac']);
        $key = CarmajaBackupCipher::decodeKey($this->config['backupEncryptionKey']);
        $hmacKey = hash_hkdf('sha256', $key, 32, 'carmaja-backup-manifest-v1');
        sodium_memzero($key);
        $hmac = hash_hmac('sha256', $this->canonicalJson($manifest), $hmacKey);
        sodium_memzero($hmacKey);
        return $hmac;
    }

    private function canonicalJson(mixed $value): string
    {
        $normalize = function (mixed $item) use (&$normalize): mixed {
            if (!is_array($item)) {
                return $item;
            }
            if (!array_is_list($item)) {
                ksort($item, SORT_STRING);
            }
            foreach ($item as $key => $child) {
                $item[$key] = $normalize($child);
            }
            return $item;
        };
        return json_encode($normalize($value), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    private function writeClientOptions(string $directory, bool $restore): string
    {
        $path = $directory . '/mysql-' . ($restore ? 'restore' : 'source') . '.cnf';
        $prefix = $restore ? 'commerceRestore' : 'commerce';
        $dsn = $this->parseDsn($this->config[$prefix . 'Dsn']);
        $lines = [
            '[client]',
            'host=' . $this->optionValue($dsn['host']),
            'port=' . $dsn['port'],
            'user=' . $this->optionValue($this->config[$prefix . 'User']),
            'password=' . $this->optionValue($this->config[$prefix . 'Password']),
            'ssl',
        ];
        $ca = $this->config[$prefix . 'TlsCaPath'] ?? null;
        if (is_string($ca) && $ca !== '') {
            $lines[] = 'ssl-ca=' . $this->optionValue($ca);
            $lines[] = 'ssl-verify-server-cert';
        }
        if (file_put_contents($path, implode("\n", $lines) . "\n", LOCK_EX) === false) {
            throw new CarmajaProductionBackupException('backup_options_write_failed');
        }
        chmod($path, 0600);
        return $path;
    }

    private function dumpCommand(string $options, bool $restore): array
    {
        $prefix = $restore ? 'commerceRestore' : 'commerce';
        return [
            $this->dumpBinary,
            '--defaults-extra-file=' . $options,
            '--single-transaction',
            '--routines',
            '--triggers',
            '--events',
            '--tz-utc',
            '--hex-blob',
            '--order-by-primary',
            '--no-tablespaces',
            '--skip-comments',
            '--compact',
            $this->databaseName($this->config[$prefix . 'Dsn']),
        ];
    }

    private function dumpHash(string $temporaryRoot, bool $restore): string
    {
        $options = $this->writeClientOptions($temporaryRoot, $restore);
        try {
            $this->assertClientTls($options, $restore);
            $process = $this->openProcess($this->dumpCommand($options, $restore));
            $hash = hash_init('sha256');
            while (!feof($process['stdout'])) {
                $chunk = fread($process['stdout'], 65536);
                if ($chunk === false) {
                    throw new CarmajaProductionBackupException('backup_dump_read_failed');
                }
                if ($chunk !== '') {
                    hash_update($hash, $chunk);
                }
            }
            $this->finishProcess($process);
            return hash_final($hash);
        } finally {
            @unlink($options);
        }
    }

    private function restoreSql(string $sqlPath): void
    {
        $options = $this->writeClientOptions(dirname($sqlPath), true);
        $input = fopen($sqlPath, 'rb');
        if ($input === false) {
            throw new CarmajaProductionBackupException('backup_restore_sql_missing');
        }
        try {
            $this->assertClientTls($options, true);
            $process = proc_open(
                [
                    $this->mysqlBinary,
                    '--defaults-extra-file=' . $options,
                    "--init-command=SET @@SESSION.FOREIGN_KEY_CHECKS=0, @@SESSION.time_zone='+00:00'",
                    $this->databaseName($this->config['commerceRestoreDsn']),
                ],
                [0 => $input, 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $pipes
            );
            if (!is_resource($process)) {
                throw new CarmajaProductionBackupException('backup_process_start_failed');
            }
            $stdout = stream_get_contents($pipes[1]);
            $stderr = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $exit = proc_close($process);
            unset($stdout, $stderr);
            if ($exit !== 0) {
                throw new CarmajaProductionBackupException('backup_restore_mysql_failed');
            }
        } finally {
            fclose($input);
            @unlink($options);
        }
    }

    private function decryptArtifact(string $directory, array $artifact, string $target): void
    {
        $file = $artifact['file'] ?? null;
        if (!is_string($file) || !in_array($file, ['commerce.sql.gz.cmjbkp', 'product-data.tar.gz.cmjbkp'], true)) {
            throw new CarmajaProductionBackupException('backup_manifest_invalid');
        }
        $source = $directory . '/' . $file;
        $hash = hash_file('sha256', $source);
        if (!is_string($hash) || !hash_equals((string) ($artifact['sha256'] ?? ''), $hash)) {
            throw new CarmajaProductionBackupException('backup_artifact_hash_mismatch');
        }
        $output = fopen($target, 'xb');
        if ($output === false) {
            throw new CarmajaProductionBackupException('backup_restore_output_failed');
        }
        chmod($target, 0600);
        try {
            $result = CarmajaBackupCipher::decryptToResource(
                $source,
                $output,
                $this->config['backupEncryptionKey'],
                $this->config['backupEncryptionKeyId']
            );
            if (!hash_equals((string) ($artifact['plainSha256'] ?? ''), $result['plainSha256'])) {
                throw new CarmajaProductionBackupException('backup_artifact_plain_hash_mismatch');
            }
        } finally {
            fclose($output);
        }
    }

    private function gunzip(string $source, string $target): string
    {
        $input = gzopen($source, 'rb');
        $output = fopen($target, 'xb');
        if ($input === false || $output === false) {
            throw new CarmajaProductionBackupException('backup_gunzip_failed');
        }
        chmod($target, 0600);
        $hash = hash_init('sha256');
        try {
            while (!gzeof($input)) {
                $chunk = gzread($input, 65536);
                if (!is_string($chunk)) {
                    throw new CarmajaProductionBackupException('backup_gunzip_failed');
                }
                if ($chunk !== '') {
                    hash_update($hash, $chunk);
                    if (fwrite($output, $chunk) === false) {
                        throw new CarmajaProductionBackupException('backup_gunzip_failed');
                    }
                }
            }
        } finally {
            gzclose($input);
            fclose($output);
        }
        return hash_final($hash);
    }

    private function tarList(string $path): array
    {
        $process = $this->openProcess([$this->tarBinary, '-tzf', $path]);
        $output = stream_get_contents($process['stdout']);
        $this->finishProcess($process);
        return preg_split('/\r?\n/', (string) $output) ?: [];
    }

    private function verifyExtractedInventory(string $root, array $inventory): void
    {
        if (!is_array($inventory['files'] ?? null)) {
            throw new CarmajaProductionBackupException('backup_restore_product_compare_failed');
        }
        $actual = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $entry) {
            if ($entry->isLink()) {
                throw new CarmajaProductionBackupException('backup_archive_path_invalid');
            }
            if (!$entry->isFile()) {
                continue;
            }
            $relative = str_replace('\\', '/', substr($entry->getPathname(), strlen($root) + 1));
            if ($relative === 'product-inventory.json') {
                continue;
            }
            $actual[$relative] = $this->fileDescriptor($entry->getPathname());
        }
        ksort($actual, SORT_STRING);
        $expected = $inventory['files'];
        ksort($expected, SORT_STRING);
        if ($actual !== $expected) {
            throw new CarmajaProductionBackupException('backup_restore_product_compare_failed');
        }
    }

    private function assertRestoreDatabaseEmpty(): void
    {
        $pdo = $this->restorePdo();
        $tables = $pdo->query(
            'SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()'
        )->fetchAll(PDO::FETCH_COLUMN);
        $routines = $pdo->query(
            'SELECT ROUTINE_NAME FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA = DATABASE()'
        )->fetchAll(PDO::FETCH_COLUMN);
        $events = $pdo->query(
            'SELECT EVENT_NAME FROM information_schema.EVENTS WHERE EVENT_SCHEMA = DATABASE()'
        )->fetchAll(PDO::FETCH_COLUMN);
        if ($tables !== [] || $routines !== [] || $events !== []) {
            throw new CarmajaProductionBackupException('backup_restore_database_not_empty');
        }
    }

    private function clearRestoreDatabase(): void
    {
        $pdo = $this->restorePdo();
        $objects = $pdo->query(
            'SELECT TABLE_NAME, TABLE_TYPE FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE()
             ORDER BY CASE WHEN TABLE_TYPE = \'VIEW\' THEN 0 ELSE 1 END, TABLE_NAME'
        )->fetchAll(PDO::FETCH_ASSOC);
        $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
        try {
            foreach ($objects as $object) {
                $table = $object['TABLE_NAME'] ?? null;
                $type = $object['TABLE_TYPE'] ?? null;
                if (!is_string($table) || preg_match('/^[A-Za-z0-9_]+$/', $table) !== 1
                    || !in_array($type, ['BASE TABLE', 'VIEW'], true)) {
                    throw new CarmajaProductionBackupException('backup_restore_table_invalid');
                }
                $pdo->exec('DROP ' . ($type === 'VIEW' ? 'VIEW' : 'TABLE') . ' `' . $table . '`');
            }
        } finally {
            $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
        }
        $routines = $pdo->query(
            'SELECT ROUTINE_NAME, ROUTINE_TYPE FROM information_schema.ROUTINES
             WHERE ROUTINE_SCHEMA = DATABASE()'
        )->fetchAll(PDO::FETCH_ASSOC);
        foreach ($routines as $routine) {
            $name = $routine['ROUTINE_NAME'] ?? null;
            $type = $routine['ROUTINE_TYPE'] ?? null;
            if (!is_string($name) || preg_match('/^[A-Za-z0-9_]+$/', $name) !== 1
                || !in_array($type, ['PROCEDURE', 'FUNCTION'], true)) {
                throw new CarmajaProductionBackupException('backup_restore_routine_invalid');
            }
            $pdo->exec('DROP ' . $type . ' `' . $name . '`');
        }
        $events = $pdo->query(
            'SELECT EVENT_NAME FROM information_schema.EVENTS WHERE EVENT_SCHEMA = DATABASE()'
        )->fetchAll(PDO::FETCH_COLUMN);
        foreach ($events as $event) {
            if (!is_string($event) || preg_match('/^[A-Za-z0-9_]+$/', $event) !== 1) {
                throw new CarmajaProductionBackupException('backup_restore_event_invalid');
            }
            $pdo->exec('DROP EVENT `' . $event . '`');
        }
    }

    private function restorePdo(): PDO
    {
        return $this->databasePdo(true);
    }

    private function databasePdo(bool $restore): PDO
    {
        $prefix = $restore ? 'commerceRestore' : 'commerce';
        $options = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false];
        $ca = $this->config[$prefix . 'TlsCaPath'] ?? null;
        if (is_string($ca) && $ca !== '') {
            $options[PDO::MYSQL_ATTR_SSL_CA] = $ca;
            $options[PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT] = true;
        } else {
            if (defined('PDO::MYSQL_ATTR_SSL_CIPHER')) {
                $options[PDO::MYSQL_ATTR_SSL_CIPHER] = 'HIGH';
            }
            $options[PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT] = false;
        }
        $pdo = new PDO(
            $this->config[$prefix . 'Dsn'],
            $this->config[$prefix . 'User'],
            $this->config[$prefix . 'Password'],
            $options
        );
        $row = $pdo->query("SHOW SESSION STATUS LIKE 'Ssl_cipher'")->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row) || trim((string) ($row['Value'] ?? '')) === '') {
            throw new CarmajaProductionBackupException($restore ? 'backup_restore_tls_inactive' : 'backup_source_tls_inactive');
        }
        return $pdo;
    }

    private function parseDsn(string $dsn): array
    {
        if (!str_starts_with($dsn, 'mysql:')) {
            throw new CarmajaProductionBackupException('backup_dsn_invalid');
        }
        $parts = [];
        foreach (explode(';', substr($dsn, 6)) as $part) {
            [$key, $value] = array_pad(explode('=', $part, 2), 2, '');
            $parts[$key] = $value;
        }
        if (preg_match('/^[A-Za-z0-9._-]+$/', $parts['host'] ?? '') !== 1
            || preg_match('/^[A-Za-z0-9._-]+$/', $parts['dbname'] ?? '') !== 1
            || !ctype_digit($parts['port'] ?? '3306')) {
            throw new CarmajaProductionBackupException('backup_dsn_invalid');
        }
        return ['host' => $parts['host'], 'port' => $parts['port'] ?? '3306', 'database' => $parts['dbname']];
    }

    private function databaseName(string $dsn): string
    {
        return $this->parseDsn($dsn)['database'];
    }

    private function optionValue(string $value): string
    {
        if ($value === '' || str_contains($value, "\n") || str_contains($value, "\r")) {
            throw new CarmajaProductionBackupException('backup_option_invalid');
        }
        return '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $value) . '"';
    }

    private function assertClientTls(string $options, bool $restore): void
    {
        $prefix = $restore ? 'commerceRestore' : 'commerce';
        $process = $this->openProcess([
            $this->mysqlBinary,
            '--defaults-extra-file=' . $options,
            '--batch',
            '--skip-column-names',
            '--execute=SHOW SESSION STATUS LIKE \'Ssl_cipher\'',
            $this->databaseName($this->config[$prefix . 'Dsn']),
        ]);
        $output = stream_get_contents($process['stdout']);
        $this->finishProcess($process);
        if (!is_string($output)
            || preg_match('/^Ssl_cipher\s+\S+/m', trim($output)) !== 1) {
            throw new CarmajaProductionBackupException(
                $restore ? 'backup_restore_cli_tls_inactive' : 'backup_source_cli_tls_inactive'
            );
        }
    }

    private function openProcess(array $command): array
    {
        $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($process)) {
            throw new CarmajaProductionBackupException('backup_process_start_failed');
        }
        fclose($pipes[0]);
        return ['process' => $process, 'stdout' => $pipes[1], 'stderr' => $pipes[2]];
    }

    private function finishProcess(array $process): void
    {
        fclose($process['stdout']);
        $stderr = stream_get_contents($process['stderr']);
        fclose($process['stderr']);
        $exit = proc_close($process['process']);
        unset($stderr);
        if ($exit !== 0) {
            throw new CarmajaProductionBackupException('backup_process_failed');
        }
    }

    private function closeProcess(array $process): void
    {
        foreach (['stdout', 'stderr'] as $name) {
            if (is_resource($process[$name] ?? null)) {
                fclose($process[$name]);
            }
        }
        if (is_resource($process['process'] ?? null)) {
            proc_terminate($process['process']);
            proc_close($process['process']);
        }
    }

    private function runProcess(array $command): void
    {
        $process = $this->openProcess($command);
        stream_get_contents($process['stdout']);
        $this->finishProcess($process);
    }

    private function backupRoot(): string
    {
        $root = rtrim($this->config['backupDirectory'], '/');
        $private = rtrim((string) ($this->config['privateDir'] ?? ''), '/');
        if ($root === '' || $private === '' || !str_starts_with($root . '/', $private . '/')) {
            throw new CarmajaProductionBackupException('backup_path_unsafe');
        }
        return $root;
    }

    private function backupDirectory(string $backupId): string
    {
        if (preg_match('/^[0-9]{8}T[0-9]{6}Z-[a-f0-9]{12}$/', $backupId) !== 1) {
            throw new CarmajaProductionBackupException('backup_id_invalid');
        }
        $directory = $this->backupRoot() . '/ready/' . $backupId;
        if (!is_dir($directory) || is_link($directory)) {
            throw new CarmajaProductionBackupException('backup_not_found');
        }
        return $directory;
    }

    private function readyDirectories(): array
    {
        $directories = array_filter(glob($this->backupRoot() . '/ready/*') ?: [], static fn (string $path): bool => is_dir($path) && !is_link($path));
        sort($directories, SORT_STRING);
        return array_values($directories);
    }

    private function prune(): void
    {
        $directories = $this->readyDirectories();
        $latest = $directories === [] ? null : end($directories);
        $latestAck = null;
        foreach ($directories as $directory) {
            if (is_file($directory . '/download-ack.json')) {
                $latestAck = $directory;
            }
        }
        foreach ($directories as $directory) {
            if ($directory === $latest || $directory === $latestAck) {
                continue;
            }
            if (time() - (int) filemtime($directory . '/ready') > self::READY_RETENTION_SECONDS) {
                $this->removeTree($directory);
            }
        }
    }

    private function cleanupStaging(): void
    {
        $staging = $this->backupRoot() . '/.staging';
        foreach (scandir($staging) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $staging . '/' . $entry;
            if (preg_match('/^[0-9]{8}T[0-9]{6}Z-[a-f0-9]{12}$/', $entry) !== 1
                || !is_dir($path) || is_link($path)) {
                throw new CarmajaProductionBackupException('backup_staging_unsafe');
            }
            $this->removeTree($path);
        }
    }

    private function ensureDirectory(string $path, int $mode): void
    {
        if (!is_dir($path) && !mkdir($path, $mode, true) && !is_dir($path)) {
            throw new CarmajaProductionBackupException('backup_directory_create_failed');
        }
        chmod($path, $mode);
    }

    private function writeJson(string $path, array $value, int $mode): void
    {
        $temporary = $path . '.tmp-' . bin2hex(random_bytes(4));
        $payload = json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";
        if (file_put_contents($temporary, $payload, LOCK_EX) === false) {
            throw new CarmajaProductionBackupException('backup_json_write_failed');
        }
        chmod($temporary, $mode);
        if (!rename($temporary, $path)) {
            @unlink($temporary);
            throw new CarmajaProductionBackupException('backup_json_publish_failed');
        }
    }

    private function removeTree(string $path): void
    {
        if (!file_exists($path)) {
            return;
        }
        if (is_file($path) || is_link($path)) {
            @unlink($path);
            return;
        }
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                $this->removeTree($path . '/' . $entry);
            }
        }
        @rmdir($path);
    }
}
