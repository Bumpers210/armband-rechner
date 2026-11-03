<?php
declare(strict_types=1);

function cart_brevo_setup_values(array $input): array
{
    $fields = ['brevoApiKey', 'brevoSenderEmail', 'brevoSenderName', 'testRecipientEmail', 'testRecipientConfirmed'];
    if (count($input) !== count($fields) || array_diff(array_keys($input), $fields) !== []
        || ($input['testRecipientConfirmed'] ?? null) !== true) { throw new RuntimeException('TEST_INPUT_INVALID'); }
    foreach (array_slice($fields, 0, 4) as $field) {
        if (!is_string($input[$field] ?? null)) { throw new RuntimeException('TEST_INPUT_INVALID'); }
        $input[$field] = trim($input[$field]);
    }
    if (preg_match('/^[!-~]{16,4096}$/D', $input['brevoApiKey']) !== 1
        || filter_var($input['brevoSenderEmail'], FILTER_VALIDATE_EMAIL) === false
        || filter_var($input['testRecipientEmail'], FILTER_VALIDATE_EMAIL) === false
        || strlen($input['brevoSenderEmail']) > 254 || strlen($input['testRecipientEmail']) > 254
        || $input['brevoSenderName'] === '' || strlen($input['brevoSenderName']) > 200
        || preg_match('/[\x00-\x1F\x7F]/', $input['brevoSenderName'])) { throw new RuntimeException('TEST_INPUT_INVALID'); }
    return ['testOnly' => true, ...$input];
}

function cart_brevo_setup_install(string $payload, string $root): void
{
    if (strlen($payload) > 8192) { throw new RuntimeException('TEST_INPUT_INVALID'); }
    $contents = json_encode(cart_brevo_setup_values(json_decode($payload, true, 4, JSON_THROW_ON_ERROR)), JSON_THROW_ON_ERROR);
    if (!is_dir($root) || is_link($root) || realpath($root) !== $root || !is_writable($root)) {
        throw new RuntimeException('PRIVATE_PATH_INVALID');
    }
    $destination = $root . '/shop-cart-brevo-test.json';
    if (file_exists($destination) || is_link($destination)) { throw new RuntimeException('CONFIG_EXISTS'); }
    $mask = umask(0077); $temporary = null; $installed = false;
    try {
        $temporary = tempnam($root, '.cart-brevo-');
        if (!is_string($temporary) || dirname($temporary) !== $root || !chmod($temporary, 0600)) { throw new RuntimeException('CONFIG_WRITE_FAILED'); }
        if (file_put_contents($temporary, $contents, LOCK_EX) !== strlen($contents)) { throw new RuntimeException('CONFIG_WRITE_FAILED'); }
        if (!@link($temporary, $destination)) { throw new RuntimeException('CONFIG_EXISTS'); }
        $installed = true; clearstatcache(true, $destination);
        if (is_link($destination) || (fileperms($destination) & 0777) !== 0600
            || !hash_equals(hash('sha256', $contents), (string) hash_file('sha256', $destination))) { throw new RuntimeException('CONFIG_VERIFY_FAILED'); }
    } catch (Throwable $error) {
        if ($installed) { @unlink($destination); }
        throw $error;
    } finally { if (is_string($temporary)) { @unlink($temporary); } umask($mask); }
}

if (!defined('CARMAJA_CART_BREVO_SETUP_NO_RUN')) {
    ini_set('display_errors', '0'); ini_set('log_errors', '0');
    try {
        if (PHP_SAPI !== 'cli' || PHP_VERSION_ID < 80400 || PHP_VERSION_ID >= 80500) { throw new RuntimeException('PHP84_CLI_REQUIRED'); }
        cart_brevo_setup_install((string) stream_get_contents(STDIN, 8193), '/home/www/carmaja-private-test');
        echo "CART_BREVO_TEST_CONFIG_CREATED_0600\n";
    } catch (Throwable) { fwrite(STDERR, "CART_BREVO_SETUP_FAILED\n"); exit(1); }
}
