<?php
declare(strict_types=1);

function cart_stripe_setup_key(array $input): string
{
    if (array_keys($input) !== ['stripeSecretKey'] || !is_string($input['stripeSecretKey'])
        || preg_match('/^sk_test_[a-zA-Z0-9]{16,4096}$/D', $input['stripeSecretKey']) !== 1) {
        throw new RuntimeException('TEST_KEY_REQUIRED');
    }
    return $input['stripeSecretKey'];
}

function cart_stripe_setup_install(string $payload, string $root): void
{
    if (strlen($payload)>8192) { throw new RuntimeException('TEST_KEY_REQUIRED'); }
    $key = cart_stripe_setup_key(json_decode($payload,true,4,JSON_THROW_ON_ERROR));
    if (!is_dir($root) || is_link($root) || realpath($root)!==$root || !is_writable($root)) {
        throw new RuntimeException('PRIVATE_PATH_INVALID');
    }
    $destination=$root.'/shop-cart-stripe-test.json';
    if (file_exists($destination) || is_link($destination)) { throw new RuntimeException('CONFIG_EXISTS'); }
    $mask=umask(0077); $temporary=null; $installed=false;
    try {
        $temporary=tempnam($root,'.cart-stripe-');
        if (!is_string($temporary) || dirname($temporary)!==$root || !chmod($temporary,0600)) { throw new RuntimeException('CONFIG_WRITE_FAILED'); }
        $contents=json_encode(['testOnly'=>true,'stripeSecretKey'=>$key,
            'stripeWebhookPayloadKey'=>base64_encode(random_bytes(32)),
            'stripeWebhookPayloadKeyId'=>'cart-test-2026-10-v1'],JSON_THROW_ON_ERROR);
        if (file_put_contents($temporary,$contents,LOCK_EX)!==strlen($contents)) { throw new RuntimeException('CONFIG_WRITE_FAILED'); }
        if (!@link($temporary,$destination)) { throw new RuntimeException('CONFIG_EXISTS'); }
        $installed=true; clearstatcache(true,$destination);
        if (is_link($destination) || (fileperms($destination)&0777)!==0600
            || !hash_equals(hash('sha256',$contents),(string)hash_file('sha256',$destination))) { throw new RuntimeException('CONFIG_VERIFY_FAILED'); }
    } catch (Throwable $error) {
        if ($installed) { @unlink($destination); }
        throw $error;
    } finally { if (is_string($temporary)) { @unlink($temporary); } umask($mask); }
}

if (!defined('CARMAJA_CART_STRIPE_SETUP_NO_RUN')) {
    ini_set('display_errors','0'); ini_set('log_errors','0');
    try {
        if (PHP_SAPI!=='cli' || PHP_VERSION_ID<80400 || PHP_VERSION_ID>=80500) { throw new RuntimeException('PHP84_CLI_REQUIRED'); }
        cart_stripe_setup_install((string)stream_get_contents(STDIN,8193),'/home/www/carmaja-private-test');
        echo "CART_STRIPE_TEST_CONFIG_CREATED_0600\n";
    } catch (Throwable) { fwrite(STDERR,"CART_STRIPE_SETUP_FAILED\n"); exit(1); }
}
