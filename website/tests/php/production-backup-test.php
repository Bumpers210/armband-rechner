<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/test-api-private/program/production-backup.php';

final class CarmajaProductionBackupTestFailure extends RuntimeException
{
}

function backup_test_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new CarmajaProductionBackupTestFailure($message);
    }
}

function backup_test_throws(callable $callback, string $expectedCode): void
{
    try {
        $callback();
    } catch (CarmajaProductionBackupException $error) {
        backup_test_assert($error->errorCode === $expectedCode, 'Unerwarteter Fehlercode: ' . $error->errorCode);
        return;
    }
    throw new CarmajaProductionBackupTestFailure('Erwarteter Fehler wurde nicht ausgelöst: ' . $expectedCode);
}

function backup_test_remove_tree(string $path): void
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
            backup_test_remove_tree($path . DIRECTORY_SEPARATOR . $entry);
        }
    }
    @rmdir($path);
}

function backup_test_config(string $root, string $key): array
{
    return [
        'environment' => 'production',
        'publishTarget' => 'production',
        'productionPublishEnabled' => false,
        'githubAdapterEnabled' => false,
        'runtimeFile' => $root . '/runtime-config.php',
        'privateDir' => $root,
        'productPrivateDir' => $root . '/products-private',
        'commerceDsn' => 'mysql:host=source.example;port=3306;dbname=cmj_source;charset=utf8mb4',
        'commerceUser' => 'cmj_source_user',
        'commercePassword' => 'unit-test-only',
        'commerceTlsCaPath' => null,
        'commerceRequireTls' => true,
        'commerceRestoreDsn' => 'mysql:host=restore.example;port=3306;dbname=cmj_restore;charset=utf8mb4',
        'commerceRestoreUser' => 'cmj_restore_user',
        'commerceRestorePassword' => 'unit-test-only',
        'commerceRestoreTlsCaPath' => null,
        'commerceRestoreRequireTls' => true,
        'backupDirectory' => $root . '/backups',
        'backupOffsiteTarget' => 'onedrive-pull://carmaja-production/Carmaja-Perlen/Backups',
        'backupEncryptionKey' => $key,
        'backupEncryptionKeyId' => 'carmaja-backup-test-v1',
    ];
}

$root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'carmaja-production-backup-' . bin2hex(random_bytes(6));
mkdir($root, 0700, true);
$key = base64_encode(random_bytes(SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_KEYBYTES));
$otherKey = base64_encode(random_bytes(SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_KEYBYTES));
$tests = [];

$tests['streaming roundtrip'] = static function () use ($root, $key): void {
    $plain = str_repeat("Carmaja\0Backup\n", 90000);
    $source = fopen('php://memory', 'w+b');
    fwrite($source, $plain);
    rewind($source);
    $cipher = $root . '/roundtrip.cmjbkp';
    $result = CarmajaBackupCipher::encryptResource($source, $cipher, $key, 'carmaja-backup-test-v1');
    fclose($source);
    $target = fopen('php://memory', 'w+b');
    $decrypted = CarmajaBackupCipher::decryptToResource($cipher, $target, $key, 'carmaja-backup-test-v1');
    rewind($target);
    backup_test_assert(stream_get_contents($target) === $plain, 'Der entschlüsselte Inhalt weicht ab.');
    fclose($target);
    backup_test_assert($result['plainSha256'] === hash('sha256', $plain), 'Klartext-Hash weicht ab.');
    backup_test_assert($decrypted['plainSha256'] === $result['plainSha256'], 'Entschlüsselungs-Hash weicht ab.');
};

$tests['chunk iterator'] = static function () use ($root, $key): void {
    $cipher = $root . '/chunks.cmjbkp';
    $result = CarmajaBackupCipher::encryptChunks(['eins', '', str_repeat('zwei', 30000)], $cipher, $key, 'carmaja-backup-test-v1');
    $target = fopen('php://memory', 'w+b');
    CarmajaBackupCipher::decryptToResource($cipher, $target, $key, 'carmaja-backup-test-v1');
    rewind($target);
    $plain = stream_get_contents($target);
    fclose($target);
    backup_test_assert(hash('sha256', $plain) === $result['plainSha256'], 'Iterator-Hash weicht ab.');
};

$tests['wrong key and key id'] = static function () use ($root, $key, $otherKey): void {
    $input = fopen('php://memory', 'w+b');
    fwrite($input, 'secret-test');
    rewind($input);
    $cipher = $root . '/wrong-key.cmjbkp';
    CarmajaBackupCipher::encryptResource($input, $cipher, $key, 'carmaja-backup-test-v1');
    fclose($input);
    backup_test_throws(static function () use ($cipher, $otherKey): void {
        $target = fopen('php://memory', 'w+b');
        try {
            CarmajaBackupCipher::decryptToResource($cipher, $target, $otherKey, 'carmaja-backup-test-v1');
        } finally {
            fclose($target);
        }
    }, 'backup_authentication_failed');
    backup_test_throws(static function () use ($cipher, $key): void {
        $target = fopen('php://memory', 'w+b');
        try {
            CarmajaBackupCipher::decryptToResource($cipher, $target, $key, 'different-key-id');
        } finally {
            fclose($target);
        }
    }, 'backup_key_id_mismatch');
};

$tests['tamper and truncation'] = static function () use ($root, $key): void {
    $input = fopen('php://memory', 'w+b');
    fwrite($input, str_repeat('payload', 20000));
    rewind($input);
    $original = $root . '/integrity.cmjbkp';
    CarmajaBackupCipher::encryptResource($input, $original, $key, 'carmaja-backup-test-v1');
    fclose($input);
    $bytes = file_get_contents($original);
    backup_test_assert(is_string($bytes), 'Testarchiv fehlt.');
    $originalBytes = $bytes;
    $tampered = $root . '/tampered.cmjbkp';
    $bytes[intdiv(strlen($bytes), 2)] = chr(ord($bytes[intdiv(strlen($bytes), 2)]) ^ 1);
    file_put_contents($tampered, $bytes);
    backup_test_throws(static function () use ($tampered, $key): void {
        $target = fopen('php://memory', 'w+b');
        try {
            CarmajaBackupCipher::decryptToResource($tampered, $target, $key, 'carmaja-backup-test-v1');
        } finally {
            fclose($target);
        }
    }, 'backup_authentication_failed');
    $truncated = $root . '/truncated.cmjbkp';
    file_put_contents($truncated, substr($originalBytes, 0, -8));
    backup_test_throws(static function () use ($truncated, $key): void {
        $target = fopen('php://memory', 'w+b');
        try {
            CarmajaBackupCipher::decryptToResource($truncated, $target, $key, 'carmaja-backup-test-v1');
        } finally {
            fclose($target);
        }
    }, 'backup_truncated');
};

$tests['manifest hmac and path traversal'] = static function () use ($root, $key): void {
    $config = backup_test_config($root, $key);
    $backup = new CarmajaProductionBackup($config, PHP_BINARY, PHP_BINARY, PHP_BINARY);
    $backup->validateTarEntries(['products/AP7.json', 'runtime/runtime-config.php']);
    backup_test_throws(static fn () => $backup->validateTarEntries(['../../runtime-config.php']), 'backup_archive_path_invalid');

    $ready = $root . '/backups/ready/20260809T120000Z-abcdef123456';
    mkdir($ready, 0700, true);
    $schema = ['migrations' => [], 'state' => 'initialized'];
    $schema['sha256'] = hash('sha256', json_encode($schema, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    $manifest = [
        'formatVersion' => 1,
        'schema' => $schema,
        'backupId' => '20260809T120000Z-abcdef123456',
        'createdAt' => '2026-08-09T12:00:00+00:00',
        'keyId' => 'carmaja-backup-test-v1',
        'offsiteTarget' => 'onedrive-pull://carmaja-production/Carmaja-Perlen/Backups',
        'artifacts' => [
            'commerce' => [
                'file' => 'commerce.sql.gz.cmjbkp',
                'bytes' => 1,
                'sha256' => hash('sha256', 'a'),
                'plainSha256' => str_repeat('a', 64),
                'sourcePlainSha256' => str_repeat('b', 64),
                'databaseFingerprint' => [
                    'schemaSha256' => str_repeat('e', 64),
                    'dataSha256' => str_repeat('f', 64),
                    'metadataSha256' => str_repeat('0', 64),
                ],
            ],
            'productData' => [
                'file' => 'product-data.tar.gz.cmjbkp',
                'bytes' => 1,
                'sha256' => hash('sha256', 'b'),
                'plainSha256' => str_repeat('c', 64),
                'inventorySha256' => str_repeat('d', 64),
            ],
        ],
    ];
    $method = new ReflectionMethod($backup, 'manifestHmac');
    $manifest['manifestHmac'] = $method->invoke($backup, $manifest);
    $payload = json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
    file_put_contents($ready . '/manifest.json', $payload);
    file_put_contents($ready . '/commerce.sql.gz.cmjbkp', 'a');
    file_put_contents($ready . '/product-data.tar.gz.cmjbkp', 'b');
    file_put_contents($ready . '/ready', hash('sha256', $payload) . "\n");
    backup_test_assert(count($backup->listReady()) === 1, 'Authentifiziertes Manifest wurde nicht geladen.');
    $manifestHash = hash('sha256', $payload);
    $firstAck = $backup->acknowledge('20260809T120000Z-abcdef123456', $manifestHash);
    $ackPayload = file_get_contents($ready . '/download-ack.json');
    $secondAck = $backup->acknowledge('20260809T120000Z-abcdef123456', $manifestHash);
    backup_test_assert(($firstAck['idempotent'] ?? null) === false, 'Erste Quittierung ist falsch markiert.');
    backup_test_assert(($secondAck['idempotent'] ?? null) === true, 'Wiederholte Quittierung ist nicht idempotent.');
    backup_test_assert(file_get_contents($ready . '/download-ack.json') === $ackPayload, 'Quittierungszeit wurde verändert.');

    $manifest['createdAt'] = '2026-08-09T12:01:00+00:00';
    $tampered = json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
    file_put_contents($ready . '/manifest.json', $tampered);
    file_put_contents($ready . '/ready', hash('sha256', $tampered) . "\n");
    backup_test_throws(static fn () => $backup->listReady(), 'backup_manifest_authentication_failed');
};

$tests['initial auto increment fingerprint normalization'] = static function () use ($root, $key): void {
    $backup = new CarmajaProductionBackup(backup_test_config($root, $key), PHP_BINARY, PHP_BINARY, PHP_BINARY);
    $method = new ReflectionMethod($backup, 'normalizeTableMetadata');
    $autoIncrementColumn = [['EXTRA' => 'auto_increment']];
    $ordinaryColumn = [['EXTRA' => '']];
    $fresh = $method->invoke($backup, [['AUTO_INCREMENT' => null]], $autoIncrementColumn);
    $advanced = $method->invoke($backup, [['AUTO_INCREMENT' => '7']], $autoIncrementColumn);
    $ordinary = $method->invoke($backup, [['AUTO_INCREMENT' => null]], $ordinaryColumn);
    backup_test_assert($fresh === [['AUTO_INCREMENT' => '1']], 'Initialwert wurde nicht auf 1 normalisiert.');
    backup_test_assert($advanced === [['AUTO_INCREMENT' => '7']], 'Fortgeschrittener Zähler wurde verändert.');
    backup_test_assert($ordinary === [['AUTO_INCREMENT' => null]], 'Tabelle ohne AUTO_INCREMENT wurde verändert.');
};

$passed = 0;
try {
    foreach ($tests as $name => $test) {
        $test();
        $passed++;
        fwrite(STDOUT, "PASS: {$name}\n");
    }
    fwrite(STDOUT, "{$passed}/" . count($tests) . " production backup tests passed.\n");
} finally {
    backup_test_remove_tree($root);
}
