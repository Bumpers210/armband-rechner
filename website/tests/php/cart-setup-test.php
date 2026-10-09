<?php
declare(strict_types=1);
define('CARMAJA_CART_SETUP_NO_RUN', true);
require_once dirname(__DIR__, 2) . '/scripts/install-cart-test-config.php';

function cart_setup_assert(bool $condition, string $label): void {
    if (!$condition) { throw new RuntimeException($label); }
    echo 'PASS ' . $label . PHP_EOL;
}
$config = ['testOnly'=>true,'requireTls'=>true,'acceptUnverifiedTls'=>true,'tlsCaPath'=>'',
    'source'=>['host'=>'source.example.invalid','port'=>3306,'database'=>'dbs15962244','username'=>'synthetic_source','password'=>' test $password; '],
    'restore'=>['host'=>'restore.example.invalid','port'=>3306,'database'=>'dbs15962566','username'=>'synthetic_restore','password'=>'synthetic']];
cart_setup_validate($config);
foreach (['database','username','password','tls'] as $case) {
    $bad = $config;
    if ($case === 'database') { $bad['source']['database'] = 'unexpected'; }
    if ($case === 'username') { $bad['restore']['username'] = $bad['source']['username']; }
    if ($case === 'password') { $bad['source']['password'] = "first\nsecond"; }
    if ($case === 'tls') { $bad['requireTls'] = false; }
    $rejected = false;
    try { cart_setup_validate($bad); } catch (RuntimeException) { $rejected = true; }
    cart_setup_assert($rejected, 'reject_' . $case);
}
if (PHP_OS_FAMILY === 'Windows') {
    echo "SKIP POSIX-0600 filesystem test on Windows; required separately on IONOS.\n";
    return;
}
$root = '/home/www/carmaja-private-test';
cart_setup_assert(is_dir($root) && !is_link($root) && realpath($root) === $root, 'safe_private_test_root');
$base = $root . '/cart-setup-' . bin2hex(random_bytes(8));
mkdir($base, 0700);
$base = str_replace('\\', '/', (string) realpath($base));
$path = $base . '/shop-cart-test.json';
try {
    $payload = json_encode($config, JSON_THROW_ON_ERROR);
    cart_setup_install($payload, $base);
    cart_setup_assert((fileperms($path) & 0777) === 0600 || PHP_OS_FAMILY === 'Windows', 'private_install');
    cart_setup_assert(file_get_contents($path) === $payload, 'exact_json_and_password');
    $rejected = false;
    try { cart_setup_install($payload, $base); } catch (RuntimeException) { $rejected = true; }
    cart_setup_assert($rejected && file_get_contents($path) === $payload, 'existing_file_not_overwritten');
    cart_setup_assert(count(glob($base . '/.cart-config-*') ?: []) === 0, 'no_temporary_config_left');
} finally {
    if (is_file($path)) { unlink($path); }
    rmdir($base);
}
