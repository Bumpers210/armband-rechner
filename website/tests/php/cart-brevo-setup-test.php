<?php
declare(strict_types=1);
define('CARMAJA_CART_BREVO_SETUP_NO_RUN', true);
require_once dirname(__DIR__, 2) . '/scripts/install-cart-brevo-test.php';
$input = ['brevoApiKey' => str_repeat('x', 32), 'brevoSenderEmail' => 'sender@example.invalid',
    'brevoSenderName' => 'Cart Sandbox', 'testRecipientEmail' => 'recipient@example.invalid', 'testRecipientConfirmed' => true];
$checks = 0;
$assert = static function(bool $condition) use (&$checks): void { if (!$condition) { throw new RuntimeException('brevo_setup_test_failed'); } $checks++; };
$assert(cart_brevo_setup_values($input)['testOnly'] === true);
$assert(cart_brevo_setup_values([...$input, 'brevoApiKey' => "\r\n" . $input['brevoApiKey'] . "\r\n"])['brevoApiKey'] === $input['brevoApiKey']);
foreach ([['brevoApiKey' => ''], ['brevoApiKey' => "bad\n" . str_repeat('x', 32)], ['brevoSenderEmail' => 'invalid'],
    ['testRecipientEmail' => 'bad@example.invalid\n'], ['testRecipientConfirmed' => false], ['unexpected' => true],
    ['brevoSenderName' => "Bad\nName"]] as $bad) {
    try { cart_brevo_setup_values([...$input, ...$bad]); throw new RuntimeException('invalid_accepted'); }
    catch (RuntimeException $error) { $assert($error->getMessage() === 'TEST_INPUT_INVALID'); }
}
$root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'carmaja-cart-brevo-' . bin2hex(random_bytes(8));
if (!mkdir($root, 0700)) { throw new RuntimeException('temp_failed'); }
$root = (string) realpath($root);
try {
    $path = $root . '/shop-cart-brevo-test.json';
    if (PHP_OS_FAMILY === 'Windows') {
        // POSIX mode 0600 cannot be proven on Windows; the installer runs on IONOS.
        try { cart_brevo_setup_install(json_encode($input, JSON_THROW_ON_ERROR), $root); throw new RuntimeException('windows_permissions_accepted'); }
        catch (RuntimeException $error) { $assert($error->getMessage() === 'CONFIG_VERIFY_FAILED'); }
        $assert(!file_exists($path));
    } else {
        cart_brevo_setup_install(json_encode($input, JSON_THROW_ON_ERROR), $root);
        $assert(is_file($path) && (fileperms($path) & 0777) === 0600
            && json_decode((string) file_get_contents($path), true)['testOnly'] === true);
        try { cart_brevo_setup_install(json_encode($input, JSON_THROW_ON_ERROR), $root); throw new RuntimeException('overwrite_accepted'); }
        catch (RuntimeException $error) { $assert($error->getMessage() === 'CONFIG_EXISTS'); }
    }
    $assert(count(glob($root . '/.cart-brevo-*')) === 0);
} finally { if (is_file($root . '/shop-cart-brevo-test.json')) { unlink($root . '/shop-cart-brevo-test.json'); } rmdir($root); }
echo 'PASS private Brevo setup: ' . $checks . " assertions\n";
