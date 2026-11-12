<?php
declare(strict_types=1);

// Disposable loopback HTTP fixture, never an installable endpoint or a live TLS proof.
if (PHP_SAPI === 'cli-server') {
    $fixtureRoot = getenv('CMJ_BROWSER_FIXTURE');
    if (!is_string($fixtureRoot) || !str_starts_with(realpath($fixtureRoot) ?: '', realpath(sys_get_temp_dir()) . DIRECTORY_SEPARATOR)
        || basename($fixtureRoot) !== 'carmaja-browser-http-' . trim((string) @file_get_contents($fixtureRoot . '/owner'))) {
        http_response_code(503); exit;
    }
    $_SERVER['HTTPS'] = 'on'; // Simulated trusted server TLS flag; tests separately reject plaintext.
    define('CARMAJA_BOOTSTRAP_NO_RUN', true);
    require $fixtureRoot . '/package/private/program/bootstrap.php';
    carmaja_bootstrap_main();
}
require __DIR__ . '/product-api-test.php';
$checks = 0;
$assert = static function(bool $ok, string $name) use (&$checks): void {
    if (!$ok) throw new RuntimeException('browser_http_' . $name); $checks++;
};
$fixture = carmaja_api_test_fixture();
$nonce = bin2hex(random_bytes(6)); $root = sys_get_temp_dir() . '/carmaja-browser-http-' . $nonce;
$process = null; $pipes = [];
mkdir($root,0700); file_put_contents($root.'/owner',$nonce);
try {
    $build = proc_open(['node', dirname(__DIR__,2).'/scripts/prepare-production-cart-package.mjs','build',$root.'/package'],
        [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
    if (!is_resource($build)) throw new RuntimeException('package_start_failed');
    fclose($pipes[0]); stream_get_contents($pipes[1]); stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]);
    $assert(proc_close($build) === 0, 'real_package_build');
    mkdir($root.'/package/private/config',0700);
    $config = ['environment'=>'production','publishTarget'=>'production','productionPublishEnabled'=>false,
        'githubAdapterEnabled'=>false,'privateDir'=>$root.'/package/private','productPrivateDir'=>$fixture['productionPrivate'],
        'productionPrivateDir'=>$root.'/package/private','productionApiWebroot'=>$fixture['productionApi'],
        'productionWebsiteWebroot'=>$fixture['productionWebsite'],'usersFile'=>$fixture['productionUsers'],'tokenPepper'=>str_repeat('h',48),
        'productBrowserProductionEnabled'=>true,'productApiV5WritesEnabled'=>true,'inventoryCartEnabled'=>true];
    $writeConfig = static function(array $value) use($root): void {
        $path=$root.'/package/private/config/runtime-config.php';
        file_put_contents($path, '<?php return ' . var_export($value,true) . ';'); chmod($path,0600);
    };
    $writeConfig($config);
    $socket = stream_socket_server('tcp://127.0.0.1:0',$errno,$errstr);
    if (!is_resource($socket)) throw new RuntimeException('socket_failed');
    $address = stream_socket_get_name($socket,false); fclose($socket);
    $command = [PHP_BINARY];
    if (PHP_OS_FAMILY === 'Windows') {
        $command = [...$command,'-n','-d','extension_dir=' . ini_get('extension_dir')];
        foreach (['mbstring','gd','exif','pdo_mysql','pdo_sqlite','sodium','curl','openssl','intl'] as $extension) $command = [...$command,'-d','extension='.$extension];
    }
    $command = [...$command,'-d','display_errors=0','-S',$address,'-t',$fixture['productionApi'],__FILE__];
    $env = getenv(); $env['CMJ_BROWSER_FIXTURE']=$root;
    $process = proc_open($command,[0=>['pipe','r'],1=>['file',$root.'/server.log','a'],2=>['file',$root.'/server.log','a']],$pipes,null,$env);
    if (!is_resource($process)) throw new RuntimeException('http_server_failed');
    fclose($pipes[0]);
    for ($i=0;$i<50;$i++) { $s=@stream_socket_client('tcp://'.$address,$errno,$errstr,0.1); if(is_resource($s)){fclose($s);break;} usleep(100000); }
    $http = static function(string $route,string $method='GET',?array $body=null,array $extra=[]) use($address): array {
        $headers = ['Host: api.carmaja-perlen.de','Origin: https://www.carmaja-perlen.de',...$extra];
        if($body !== null) $headers[]='Content-Type: application/json';
        $context = stream_context_create(['http'=>['method'=>$method,'header'=>implode("\r\n",$headers),
            'content'=>$body === null ? '' : json_encode($body === [] ? (object)[] : $body,JSON_THROW_ON_ERROR),'ignore_errors'=>true,'timeout'=>8,'follow_location'=>0]]);
        $text = file_get_contents('http://'.$address.'/product-browser/index.php?route='.rawurlencode($route),false,$context);
        $responseHeaders = $http_response_header ?? [];
        preg_match('#^HTTP/\S+ (\d+)#',$responseHeaders[0]??'', $match);
        return ['status'=>(int)($match[1]??0),'headers'=>implode("\n",$responseHeaders),'raw'=>$text,'json'=>json_decode((string)$text,true)];
    };
    $response = $http('/products'); $assert($response['status']===401,'anonymous_denied');
    $assert(str_contains($response['headers'],'Cache-Control: no-store'),'no_store');
    $assert(str_contains($response['headers'],'Access-Control-Allow-Origin: https://www.carmaja-perlen.de'),'exact_cors');
    $response=$http('/products','OPTIONS',null,['Access-Control-Request-Method: PUT','Access-Control-Request-Headers: X-CSRF-Token, Idempotency-Key, Content-Type']);
    $assert($response['status']===204,'preflight');
    $response=$http('/login','POST',['username'=>'admin','password'=>'secret']);
    $assert($response['status']===200,'login');
    preg_match('/Set-Cookie: (__Secure-cmj_product_production=[a-f0-9]{64});/i',$response['headers'],$match);
    $assert(isset($match[1]),'cookie_name'); $cookie=$match[1]; $csrf=$response['json']['data']['csrfToken'];
    foreach(['secure','httponly','samesite=strict','path=/product-browser/'] as $flag) $assert(str_contains(strtolower($response['headers']),$flag),'cookie_attribute');
    $assert(!str_contains(strtolower($response['headers']),'domain='),'host_only');
    $assert(!str_contains($response['raw'],'secret')&&!isset($response['json']['data']['cookie']),'login_redacted');
    $auth=['Cookie: '.$cookie,'X-CSRF-Token: '.$csrf,'Idempotency-Key: browser-http-create-0001'];
    $id='99999999-9999-4999-8999-999999999995';
    $body=['expectedProductVersion'=>0,'name'=>'Artificial browser HTTP product','descriptionDocument'=>['version'=>1,'blocks'=>[
        ['type'=>'paragraph','spans'=>[['text'=>'Artificial only','bold'=>false,'italic'=>false,'font'=>'standard','size'=>'normal']]]]],
        'materials'=>['Test stone'],'metalElements'=>[],'braceletSizeCm'=>18.5,'pearlSizeMm'=>8,'careInstructions'=>[],
        'images'=>[],'priceMinor'=>2800,'currency'=>'eur','salesEnabled'=>false];
    $assert($http('/products/'.$id,'PUT',$body,['Cookie: '.$cookie])['status']===403,'csrf_required');
    $created=$http('/products/'.$id,'PUT',$body,$auth);
    $assert($created['status']===200,'shared_v5_write');
    $assert($created['json']['data']['product']['productVersion']===1,'server_version');
    $assert($created['json']['data']['product']['salesEnabled']===false,'sales_unchanged');
    $assert(count($created['json']['data']['product']['notices'])===2,'server_notices');
    $assert($http('/products/'.$id,'PUT',$body,$auth)['json']===$created['json'],'idempotent_retry');
    carmaja_api_test_use_target($fixture,'production');
    $app=carmaja_api_v5_product_response_from_draft(carmaja_api_load_draft($id));
    $assert($app['braceletSizeCm']===18.5&&$app['pearlSizeMm']===8,'app_reads_same_data');
    // A real maintenance marker in the packaged runtime must block all writes.
    mkdir($root.'/package/private/maintenance',0700); file_put_contents($root.'/package/private/maintenance/product-cleanup.lock','artificial');
    $assert($http('/products/'.$id,'PUT',[...$body,'expectedProductVersion'=>1],$auth)['status']===503,'maintenance_denies');
    $assert($http('/products/'.$id,'GET',null,['Cookie: '.$cookie])['status']===200,'maintenance_reads_continue');
    unlink($root.'/package/private/maintenance/product-cleanup.lock');
    $writeConfig([...$config,'productApiV5WritesEnabled'=>false]);
    $assert($http('/products/'.$id,'PUT',$body,$auth)['status']===503,'runtime_write_gate');
    $writeConfig([...$config,'productBrowserProductionEnabled'=>false]);
    $assert($http('/products','GET',null,['Cookie: '.$cookie])['status']===503,'runtime_browser_gate');
    $writeConfig($config);
    $assert($http('/logout','POST',[],$auth)['status']===200,'logout');
    $assert($http('/products','GET',null,['Cookie: '.$cookie])['status']===401,'revocation');
    $assert(!file_exists(carmaja_api_tokens_path()),'no_device_token_created');
    echo "PASS packaged production browser HTTP: $checks checks; loopback fixture only\n";
} finally {
    if(is_resource($process)) { proc_terminate($process); proc_close($process); }
    carmaja_api_test_remove_tree($fixture['root']); carmaja_api_test_remove_tree($root);
}
