<?php
declare(strict_types=1);

/** Receives secrets only on stdin; never connects to a database. */
function cart_setup_validate(array $config): void
{
    if (array_diff(array_keys($config), ['testOnly','requireTls','acceptUnverifiedTls','tlsCaPath','source','restore']) !== []
        || ($config['testOnly'] ?? null) !== true || ($config['requireTls'] ?? null) !== true
        || ($config['acceptUnverifiedTls'] ?? null) !== true || ($config['tlsCaPath'] ?? null) !== '') {
        throw new RuntimeException('CONFIG_REJECTED');
    }
    foreach (['source' => 'dbs15962244', 'restore' => 'dbs15962566'] as $label => $database) {
        $target = $config[$label] ?? null;
        if (!is_array($target) || array_diff(array_keys($target), ['host','port','database','username','password']) !== []
            || ($target['database'] ?? null) !== $database || ($target['port'] ?? null) !== 3306) {
            throw new RuntimeException('CONFIG_REJECTED');
        }
        foreach (['host', 'username', 'password'] as $field) {
            $value = $target[$field] ?? null;
            if (!is_string($value) || $value === '' || strlen($value) > 4096 || strpbrk($value, "\r\n\0") !== false) {
                throw new RuntimeException('CONFIG_REJECTED');
            }
        }
        if (preg_match('/^[a-zA-Z0-9][a-zA-Z0-9.-]{0,252}$/D', $target['host']) !== 1
            || preg_match('/^[a-zA-Z0-9_-]{1,128}$/D', $target['username']) !== 1) {
            throw new RuntimeException('CONFIG_REJECTED');
        }
    }
    if ($config['source']['username'] === $config['restore']['username']) {
        throw new RuntimeException('CONFIG_REJECTED');
    }
}

function cart_setup_install(string $payload, string $base): void
{
    if (strlen($payload) > 65536) { throw new RuntimeException('CONFIG_REJECTED'); }
    $config = json_decode($payload, true, 32, JSON_THROW_ON_ERROR);
    if (!is_array($config)) { throw new RuntimeException('CONFIG_REJECTED'); }
    cart_setup_validate($config);
    if (!is_dir($base) || is_link($base) || str_replace('\\', '/', (string) realpath($base)) !== $base || !is_writable($base)) {
        throw new RuntimeException('PRIVATE_PATH_INVALID');
    }
    $destination = $base . '/shop-cart-test.json';
    if (file_exists($destination) || is_link($destination)) { throw new RuntimeException('CONFIG_EXISTS'); }
    $oldMask = umask(0077);
    $temporary = null;
    $installed = false;
    try {
        $temporary = tempnam($base, '.cart-config-');
        if (!is_string($temporary) || str_replace('\\', '/', dirname($temporary)) !== $base || !chmod($temporary, 0600)) {
            throw new RuntimeException('CONFIG_WRITE_FAILED');
        }
        if (file_put_contents($temporary, $payload, LOCK_EX) !== strlen($payload)) {
            throw new RuntimeException('CONFIG_WRITE_FAILED');
        }
        // Atomic publish without overwriting a concurrently created configuration.
        if (!@link($temporary, $destination)) { throw new RuntimeException('CONFIG_EXISTS_OR_LINK_FAILED'); }
        $installed = true;
        clearstatcache(true, $destination);
        if (is_link($destination) || (fileperms($destination) & 0777) !== 0600
            || !hash_equals(hash('sha256', $payload), (string) hash_file('sha256', $destination))) {
            throw new RuntimeException('CONFIG_VERIFY_FAILED');
        }
    } catch (Throwable $error) {
        if ($installed) { @unlink($destination); }
        throw $error;
    } finally {
        if (is_string($temporary) && file_exists($temporary)) { @unlink($temporary); }
        umask($oldMask);
    }
}

if (!defined('CARMAJA_CART_SETUP_NO_RUN')) {
    ini_set('display_errors', '0');
    ini_set('log_errors', '0');
    try {
        if (PHP_SAPI !== 'cli' || PHP_VERSION_ID < 80400 || PHP_VERSION_ID >= 80500) {
            throw new RuntimeException('PHP84_CLI_REQUIRED');
        }
        $payload = stream_get_contents(STDIN, 65537);
        if (!is_string($payload)) { throw new RuntimeException('CONFIG_REJECTED'); }
        cart_setup_install($payload, '/home/www/carmaja-private-test');
        echo "CART_TEST_CONFIG_CREATED_0600\n";
    } catch (Throwable $error) {
        $code = $error instanceof RuntimeException && preg_match('/^[A-Z0-9_]{1,50}$/D', $error->getMessage()) === 1
            ? $error->getMessage() : 'CONFIG_REJECTED';
        fwrite(STDERR, $code . "\n");
        exit(1);
    }
}
