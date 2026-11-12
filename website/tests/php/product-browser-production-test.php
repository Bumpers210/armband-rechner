<?php
declare(strict_types=1);
require __DIR__ . '/product-browser-test.php';
require dirname(__DIR__, 2) . '/test-api-private/program/product-browser-http.php';

$checks = 0;
$fixture = carmaja_api_test_fixture();
try {
    $config = ['environment'=>'production','publishTarget'=>'production','productionPublishEnabled'=>false,
        'githubAdapterEnabled'=>false,'privateDir'=>$fixture['root'], 'productPrivateDir'=>$fixture['productionPrivate'],
        'productBrowserProductionEnabled'=>true,'productApiV5WritesEnabled'=>false];
    $server = ['HTTPS'=>'on','HTTP_HOST'=>'api.carmaja-perlen.de','HTTP_ORIGIN'=>'https://www.carmaja-perlen.de','REQUEST_METHOD'=>'GET'];
    $request = static fn($c=[], $s=[], $q=['route'=>'/products']) => carmaja_product_browser_production_request([...$config,...$c],[...$server,...$s],$q);
    $assert($request()['route'] === '/products');
    foreach ([false, null, 'true', 1] as $flag) $reject(fn()=>$request(['productBrowserProductionEnabled'=>$flag]),503);
    $reject(fn()=>$request(['environment'=>'test']),503);
    $reject(fn()=>$request(['publishTarget'=>'test']),503);
    $reject(fn()=>$request([],['HTTPS'=>'off','HTTP_X_FORWARDED_PROTO'=>'https']),403);
    foreach (['', 'null', 'https://test.carmaja-perlen.de', 'https://www.carmaja-perlen.de.evil.invalid'] as $origin) {
        $reject(fn()=>$request([],['HTTP_ORIGIN'=>$origin]),403);
    }
    $reject(fn()=>$request([],['HTTP_HOST'=>'test-api.carmaja-perlen.de']),403);
    $reject(fn()=>$request([],['HTTP_AUTHORIZATION'=>'Bearer artificial']),403);
    $reject(fn()=>$request([],['REQUEST_METHOD'=>'DELETE']),405);
    foreach (['/../auth','/products/%2f','/products/a/delete',[]] as $route) $reject(fn()=>$request([],[],['route'=>$route]),404);
    $preflight = ['REQUEST_METHOD'=>'OPTIONS','HTTP_ACCESS_CONTROL_REQUEST_METHOD'=>'PUT','HTTP_ACCESS_CONTROL_REQUEST_HEADERS'=>'Content-Type, X-CSRF-Token, Idempotency-Key'];
    $assert($request([],$preflight)['method'] === 'OPTIONS');
    $reject(fn()=>$request([],[...$preflight,'HTTP_ACCESS_CONTROL_REQUEST_HEADERS'=>'Authorization']),403);
    $reject(fn()=>$request([],[...$preflight,'HTTP_ACCESS_CONTROL_REQUEST_METHOD'=>'DELETE']),403);
    $write = ['REQUEST_METHOD'=>'PUT','CONTENT_TYPE'=>'application/json; charset=utf-8','CONTENT_LENGTH'=>'20'];
    $assert(!$request([],$write)['multipart']);
    $reject(fn()=>$request([],[...$write,'CONTENT_TYPE'=>'text/plain']),415);
    $reject(fn()=>$request([],[...$write,'CONTENT_TYPE'=>'application/jsonp']),415);
    $reject(fn()=>$request([],[...$write,'CONTENT_LENGTH'=>'65537']),413);
    $reject(fn()=>$request([],[...$write,'CONTENT_LENGTH'=>'-1']),413);
    $image = ['REQUEST_METHOD'=>'POST','CONTENT_TYPE'=>'multipart/form-data; boundary=artificial','CONTENT_LENGTH'=>'5242880'];
    $assert($request([],$image,['route'=>'/products/abc/images'])['multipart']);
    $reject(fn()=>$request([],[...$image,'CONTENT_LENGTH'=>'6291457'],['route'=>'/products/abc/images']),413);
    $assert(carmaja_product_browser_json('{}') === []);
    foreach (['[]','null','{invalid','[1]'] as $raw) $reject(fn()=>carmaja_product_browser_json($raw),400);
    $reject(fn()=>carmaja_product_browser_json(str_repeat(' ',65537)),413);

    carmaja_api_test_use_target($fixture, 'production');
    $browser = new CarmajaProductBrowser($config, static fn()=>null);
    $assert($browser->cookieName() === '__Secure-cmj_product_production');
    $assert($browser->cookieName() !== CarmajaProductBrowser::COOKIE);
    $browser->requireOrigin('https://www.carmaja-perlen.de');
    $reject(fn()=>$browser->requireOrigin(CarmajaProductBrowser::ORIGIN),403);
    $login = $browser->login(['username'=>'admin','password'=>'secret'],time());
    $actor = $browser->authenticate($login['cookie'],$login['csrfToken'],true,time());
    $assert(is_dir($fixture['productionPrivate'].'/auth/browser-sessions'));
    $assert(!is_dir($fixture['root'].'/auth/browser-sessions'));
    $assert($browser->dispatch('GET',['products'],[],$actor,null) === ['products'=>[]]);
    $reject(fn()=>$browser->dispatch('PUT',['products','99999999-9999-4999-8999-999999999991'],[],$actor,'artificial-op'),503);
    // Even a copied/forged environment-less test session cannot authenticate in production.
    $sessionPath = $fixture['productionPrivate'].'/auth/browser-sessions/'.hash('sha256',$login['cookie']).'.json';
    $session = json_decode(file_get_contents($sessionPath),true);
    foreach (['test',null] as $environment) {
        $changed = $session; if ($environment === null) unset($changed['environment']); else $changed['environment']=$environment;
        carmaja_api_test_write_json($sessionPath,$changed);
        $reject(fn()=>$browser->authenticate($login['cookie'],null,false,time()),401);
    }
    carmaja_api_test_write_json($sessionPath,$session);
    // Password reset revokes this browser session, without touching Android token files.
    carmaja_api_test_write_json($fixture['productionUsers'],['environment'=>'production','users'=>[
        ['username'=>'admin','active'=>true,'passwordHash'=>password_hash('changed-artificial',PASSWORD_DEFAULT)]]]);
    $reject(fn()=>$browser->authenticate($login['cookie'],null,false,time()),401);
    $assert(!file_exists(carmaja_api_tokens_path()));
    echo "PASS production browser boundary: $checks checks; artificial data only\n";
} finally { carmaja_api_test_remove_tree($fixture['root']); }
