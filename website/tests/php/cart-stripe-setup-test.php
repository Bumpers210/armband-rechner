<?php
declare(strict_types=1);
define('CARMAJA_CART_STRIPE_SETUP_NO_RUN',true);
require_once dirname(__DIR__,2).'/scripts/install-cart-stripe-test.php';
function cart_stripe_setup_assert(bool $condition,string $name): void {
    if (!$condition) { throw new RuntimeException($name); }
    echo 'PASS '.$name.PHP_EOL;
}
$key='sk_test_'.str_repeat('x',32);
cart_stripe_setup_assert(cart_stripe_setup_key(['stripeSecretKey'=>$key])===$key,'test_key_preserved');
foreach ([['stripeSecretKey'=>'sk_live_'.str_repeat('x',32)],['stripeSecretKey'=>'pk_test_'.str_repeat('x',32)],
    ['stripeSecretKey'=>$key."\n"],['stripeSecretKey'=>$key,'unexpected'=>true]] as $input) {
    $rejected=false; try { cart_stripe_setup_key($input); } catch (RuntimeException) { $rejected=true; }
    cart_stripe_setup_assert($rejected,'reject_live_public_multiline_extra_fields');
}
if (PHP_OS_FAMILY==='Windows') { echo "SKIP POSIX filesystem assertions on Windows; separate IONOS proof required.\n"; return; }
$root='/home/www/carmaja-private-test';
cart_stripe_setup_assert(realpath($root)===$root && !is_link($root),'private_test_root');
$base=$root.'/cart-stripe-setup-'.bin2hex(random_bytes(8));
mkdir($base,0700); $file=$base.'/shop-cart-stripe-test.json';
try {
    cart_stripe_setup_install(json_encode(['stripeSecretKey'=>$key],JSON_THROW_ON_ERROR),$base);
    $data=json_decode((string)file_get_contents($file),true,8,JSON_THROW_ON_ERROR);
    cart_stripe_setup_assert((fileperms($file)&0777)===0600 && $data['stripeSecretKey']===$key && $data['testOnly']===true,'private_test_only_install');
    cart_stripe_setup_assert(strlen(base64_decode($data['stripeWebhookPayloadKey'],true))===32,'server_generated_payload_key');
    $before=file_get_contents($file); $rejected=false;
    try { cart_stripe_setup_install(json_encode(['stripeSecretKey'=>$key],JSON_THROW_ON_ERROR),$base); }
    catch (RuntimeException) { $rejected=true; }
    cart_stripe_setup_assert($rejected && file_get_contents($file)===$before,'no_existing_config_overwrite');
    cart_stripe_setup_assert(glob($base.'/.cart-stripe-*')===[],'no_installer_temp_left');
} finally { if (is_file($file)) { unlink($file); } rmdir($base); }
