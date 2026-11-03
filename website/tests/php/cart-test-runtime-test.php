<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/scripts/cart-test-runtime.php';
$count=0;
$check=static function(bool $ok) use (&$count):void { if(!$ok)throw new RuntimeException('test_assertion_failed');$count++; };
$valid='cart-e2e-runtime-20261027-6e94c0b1';
$check(cart_runtime_guard_name($valid));
foreach(['../production','cart-e2e-ui-20261027-6e94c0b1','cart-e2e-runtime-20261027-6e94c0b1/extra','carmaja-production'] as $bad){$check(!cart_runtime_guard_name($bad));}
$before="RewriteEngine On\n# CART-V5-DEVICE\nRewriteRule ^v5(?:/.*)?$ keep/index.php [QSA,L]\nSetEnv CARMAJA_BOOTSTRAP_FILE private/bootstrap.php\n";
$after=cart_runtime_rewrite($before,$valid);
$check(str_contains($after,'RewriteRule ^(?:shop/v3|admin/v1)(?:/.*)?$ '.$valid.'/index.php [QSA,L]'));
$check(str_contains($after,substr($before,strlen("RewriteEngine On\n"))));
$check(substr_count($after,'# CART-SHOP-RUNTIME')===1);
foreach([$after,"RewriteEngine On\n# CART-SITE-E2E",'RewriteEngine Off'] as $bad){$rejected=false;try{cart_runtime_rewrite($bad,$valid);}catch(Throwable){$rejected=true;}$check($rejected);}
$check(cart_runtime_recipient_allowed(['to'=>[['email'=>'test@example.invalid']]],'test@example.invalid'));
foreach([['to'=>[['email'=>'another@example.invalid']]],['to'=>[['email'=>'test@example.invalid'],['email'=>'another@example.invalid']]],['to'=>[['email'=>'test@example.invalid']],'bcc'=>[['email'=>'other@example.invalid']]]] as $request){$check(!cart_runtime_recipient_allowed($request,'test@example.invalid'));}
$check(!cart_runtime_recipient_allowed(['to'=>[['email'=>'invalid']]],'invalid'));
$check(cart_runtime_sequence_action(null,0)==='initialize');
$check(cart_runtime_sequence_action(['next_value'=>'1'],0)==='preserve');
$check(cart_runtime_sequence_action(['next_value'=>123],20)==='preserve');
foreach([[null,1],[['next_value'=>0],0],[['next_value'=>-1],0],[['next_value'=>'1.5'],0]] as [$sequence,$orders]){
    $rejected=false;try{cart_runtime_sequence_action($sequence,$orders);}catch(Throwable){$rejected=true;}$check($rejected);
}
echo "PASS $count test-runtime guards; no network, configuration or database access.\n";
