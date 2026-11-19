<?php
// Loopback-only development fixture. Never install or deploy this router.
declare(strict_types=1);
$configFile = getenv('CARMAJA_OFFER_FIXTURE_FILE');
if (!in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1','::1'], true) || !$configFile) { http_response_code(403); exit; }
$config = json_decode(file_get_contents($configFile), true, 16, JSON_THROW_ON_ERROR);
$root = $config['root']; $stateFile = $config['state'];
$state = json_decode(file_get_contents($stateFile), true, 16, JSON_THROW_ON_ERROR);
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$method = $_SERVER['REQUEST_METHOD'];
$reply = static function (array $data, int $status = 200): never {
    http_response_code($status); header('Content-Type: application/json'); header('Cache-Control: no-store'); echo json_encode($data); exit;
};
$product = static fn(string $id): object => (object) ['productId'=>$id,'priceMinor'=>$state['priceMinor'],'currency'=>'eur',
    'available'=>$state['available'],'availableQuantity'=>$state['availableQuantity']];
if (str_starts_with($path, '/shop/v3/')) {
    if ($path === '/shop/v3/context') $reply(['context'=>['csrfToken'=>'local-fixture-csrf']]);
    if (preg_match('~^/shop/v3/products/([a-f0-9-]+)$~', $path, $m)) {
        if ($state['browserFail']) $reply(['error'=>['message'=>'Fixture offline']],503);
        $reply(['product'=>$product($m[1])]);
    }
    $cart = static function () use (&$state): array {
        $items=[]; $total=270; $canCheckout=count($state['items'])>0;
        foreach ($state['items'] as $item) {
            $buyable=$state['available'] && $item['quantity'] <= $state['availableQuantity'];
            $items[]=$item+['name'=>'Lokales Testarmband','priceMinor'=>$state['priceMinor'], 'availableQuantity'=>$state['availableQuantity'],'buyable'=>$buyable];
            $total += $item['quantity']*$state['priceMinor']; $canCheckout=$canCheckout&&$buyable;
        }
        return ['cartVersion'=>$state['version'],'items'=>$items,'totalMinor'=>$items?$total:null,
            'shippingMinor'=>270,'canCheckout'=>$canCheckout,'checkoutContextToken'=>'local-fixture-quote'];
    };
    if ($path === '/shop/v3/cart' && $method === 'PUT') {
        $body=json_decode(file_get_contents('php://input'),true,16,JSON_THROW_ON_ERROR);
        if (($body['expectedCartVersion']??null)!==$state['version']) $reply(['error'=>['message'=>'Fixture version changed']],409);
        if (($_SERVER['HTTP_X_CSRF_TOKEN']??'')!=='local-fixture-csrf') $reply([],403);
        if (array_sum(array_column($body['items'],'quantity'))>10) $reply(['error'=>['code'=>'cart_quantity_limit']],422);
        $state['items']=$body['items']; $state['version']++;
        file_put_contents($stateFile,json_encode($state));
        $reply(['cart'=>$cart()]);
    }
    if ($path === '/shop/v3/cart') $reply(['cart'=>$cart()]);
    if ($path === '/shop/v3/checkouts' && $method === 'POST') $reply(['checkout'=>['checkoutId'=>'local-fixture','url'=>'http://'.$_SERVER['HTTP_HOST'].'/__payment/']]);
    $reply([],404);
}
if ($path === '/__payment/') { echo '<h1>Lokaler Zahlungsstub – keine Bestellung</h1>'; exit; }
if (in_array($path,['/pageview.php','/click.php'],true)) { http_response_code(204); exit; }
if (preg_match('~^/armbaender/cp-[0-9]{4}-[0-9]{4}-[a-z0-9-]+/(?:index\.html)?$~D',$path)) {
    require_once __DIR__.'/../../product-offer-hosting/_internal/product-offers.php';
    $r=\Carmaja\ProductOffers\render($root,$_SERVER['REQUEST_URI'],$method,static function(string $id) use($state,$product):object {
        if($state['serverFail'])throw new RuntimeException('Fixture offline');return $product($id);
    });
    http_response_code($r['status']);foreach($r['headers'] as $k=>$v)header($k.': '.$v);
    if($method!=='HEAD')echo $r['body'];return true;
}
$filename=realpath($root.$path.(str_ends_with($path,'/')?'index.html':''));
if($filename===false && preg_match('~^(.*)/(__next\.[a-zA-Z0-9_.$-]+\.__PAGE__\.txt)$~',$path,$m)) {
    $name=preg_replace('~^__next\.([^.]+)\.~','__next.$1/',$m[2]);
    $name=str_replace('.$d$','/$d$',$name);$name=str_replace('.__PAGE__.txt','/__PAGE__.txt',$name);
    $filename=realpath($root.$m[1].'/'.$name);
}
if($filename!==false && str_starts_with($filename,realpath($root).DIRECTORY_SEPARATOR) && str_ends_with($filename,'.js')) {
    header('Content-Type: text/javascript');header('Cache-Control: no-store');
    // Test transport only: keep production code untouched, point its configured API host at this fixture.
    echo str_replace('https://api.carmaja-perlen.de','http://'.$_SERVER['HTTP_HOST'],file_get_contents($filename));return true;
}
if($filename!==false && str_starts_with($filename,realpath($root).DIRECTORY_SEPARATOR) && str_ends_with($filename,'.txt')) {header('Content-Type: text/plain');readfile($filename);return true;}
return false;
