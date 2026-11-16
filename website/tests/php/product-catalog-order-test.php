<?php
declare(strict_types=1);
require __DIR__ . '/product-api-test.php';
require dirname(__DIR__, 2) . '/test-api-private/program/product-browser.php';

$checks = 0;
$assert = static function ($condition) use (&$checks): void {
    if (!$condition) throw new RuntimeException('catalog_order_assertion_failed'); $checks++;
};
$reject = static function ($call, $code) use (&$checks): void {
    try { $call(); } catch (CarmajaApiException $error) {
        if ($error->statusCode !== $code) throw new RuntimeException('catalog_order_wrong_status_' . $error->statusCode);
        $checks++; return;
    }
    throw new RuntimeException('catalog_order_rejection_missing');
};
$fixture = carmaja_api_test_fixture();
try {
    $ids = array_map(static fn($n) => '99999999-9999-4999-8999-99999999999' . $n, [1,2,3]);
    $products = array_map(static fn($id, $i) => ['productId'=>$id, 'title'=>'Test ' . $i, 'sku'=>'CP-2026-000' . $i,
        'updatedAt'=>'2026-11-0' . $i . 'T10:00:00Z', 'salesEnabled'=>false, 'productVersion'=>3,
        'sourceHash'=>str_repeat('a',64), 'priceMinor'=>2800, 'currency'=>'eur', 'images'=>[]], $ids, [1,2,3]);
    $projection = ['version'=>4,'products'=>array_slice($products,0,2)];
    $path = carmaja_api_path('products/public-products-v2.json');
    carmaja_api_write_json_atomic($path, $projection);
    $before = file_get_contents($path);
    $config = ['environment'=>'test','publishTarget'=>'test','productionPublishEnabled'=>false,'githubAdapterEnabled'=>false,
        'privateDir'=>$fixture['testPrivate'],'productApiV5WritesEnabled'=>true];
    $guarded = [];
    $browser = new CarmajaProductBrowser($config, static function($id) use (&$guarded) { $guarded[]=$id; });
    $actor = carmaja_api_test_actor();
    $read = static fn() => $browser->dispatch('GET',['catalog-order'],[],$actor,null);
    $save = static fn($body,$key) => $browser->dispatch('PUT',['catalog-order'],$body,$actor,$key);
    $first = $read(); $assert(array_column($first['products'],'productId') === [$ids[1],$ids[0]]);
    $body = ['expectedRevision'=>$first['revision'],'productIds'=>[$ids[0],$ids[1]]];
    foreach ([['productIds'=>[$ids[0],$ids[0]]], ['productIds'=>['../../auth']], ['stock'=>0], ['expectedRevision'=>'bad']] as $bad) {
        $reject(fn()=>$save([...$body,...$bad],'invalid-order-001'), isset($bad['productIds']) && $bad['productIds'] === ['../../auth'] ? 400 : 422);
    }
    $reject(fn()=>$save($body,null),422);
    $reject(fn()=>$save([...$body,'productIds'=>[$ids[0]]],'missing-order-001'),409);
    $reject(fn()=>$save([...$body,'productIds'=>$ids],'extra-order-0001'),409);
    $off = new CarmajaProductBrowser([...$config,'productApiV5WritesEnabled'=>false],static fn()=>null);
    $reject(fn()=>$off->dispatch('PUT',['catalog-order'],$body,$actor,'closed-order-001'),503);
    $denied = new CarmajaProductBrowser($config,static function(){throw new CarmajaApiException(403,'Testscope');});
    $reject(fn()=>$denied->dispatch('PUT',['catalog-order'],$body,$actor,'scope-order-001'),403);
    $result = $save($body,'save-order-0001'); $assert($result['saved'] && $result['publication']['commitSha'] === null);
    $assert(file_get_contents($path) === $before); // Exact preservation, including sales flag/version/hash/images.
    $assert($read()['products'][0]['productId'] === $ids[0]);
    $assert($save($body,'save-order-0001') === $result); // lost-response retry
    $reject(fn()=>$save([...$body,'productIds'=>array_reverse($body['productIds'])],'save-order-0001'),409);
    $reject(fn()=>$save($body,'second-admin-001'),409); // stale browser
    $assert(in_array($ids[0],$guarded,true) && in_array($ids[1],$guarded,true));
    // Normal app republication preserves ordering; new publication appends.
    $changed = [...$products[0], 'updatedAt'=>'2027-01-01T00:00:00Z', 'productVersion'=>4];
    carmaja_api_local_publish_adapter_v3($changed,['operationId'=>'edit-app-00001','requestHash'=>str_repeat('c',64)]);
    carmaja_api_local_publish_adapter_v3($products[2],['operationId'=>'new-app-000001','requestHash'=>str_repeat('d',64)]);
    $assert(array_column($read()['products'],'productId') === $ids);
    // Archive hides only the archived ID, restoration returns to its saved slot.
    carmaja_api_local_publish_adapter_v3([...$products[0],'_removePublic'=>true],['operationId'=>'archive-app-001']);
    $assert(array_column($read()['products'],'productId') === [$ids[1],$ids[2]]);
    carmaja_api_local_publish_adapter_v3($changed,['operationId'=>'restore-app-001']);
    $assert(array_column($read()['products'],'productId') === $ids);
    $assert(!str_contains(json_encode($read()), 'sourceHash'));

    // Entire production publisher proof is mocked; no real GitHub/server connection.
    carmaja_api_test_use_target($fixture,'production');
    $reject(fn()=>CarmajaCatalogOrder::read(true),503);
    putenv('CARMAJA_GITHUB_ADAPTER_ENABLED=true'); putenv('CARMAJA_PRODUCTION_PUBLISH_ENABLED=true');
    putenv('CARMAJA_GITHUB_REPOSITORY=Bumpers210/armband-rechner');
    $head = str_repeat('a',40); $prepared = str_repeat('b',40); $tree = str_repeat('c',40);
    $order = ['version'=>1,'productIds'=>null]; $commits=0; $patches=0; $loseResponse=true; $pausePatch=false; $diverged=false; $newOrder=null; $written=[];
    $GLOBALS['CARMAJA_API_GITHUB_REQUEST_ADAPTER'] = static function($method,$url,$data) use (&$head,&$prepared,$tree,&$order,$projection,&$commits,&$patches,&$loseResponse,&$pausePatch,&$diverged,&$newOrder,&$written) {
        if ($method==='GET' && str_contains($url,'/git/ref/heads/')) return ['object'=>['sha'=>$head]];
        if ($method==='GET' && str_contains($url,'/contents/')) {
            if (!str_ends_with($url,'?ref='.$head)) throw new RuntimeException('mutable_catalog_read');
            return ['content'=>base64_encode(json_encode(str_contains($url,'product-order.json') ? $order : $projection))];
        }
        if ($method==='GET' && str_contains($url,'/git/commits/')) return ['tree'=>['sha'=>$tree]];
        if ($method==='POST' && str_ends_with($url,'/git/trees')) {
            $written=$data['tree']; $newOrder=json_decode($written[0]['content'],true); return ['sha'=>$tree];
        }
        if ($method==='POST' && str_ends_with($url,'/git/commits')) {
            if (!str_starts_with($data['message'],"Carmaja collection reorder\n\nCarmaja-Publisher: catalog-order-v1\n")) throw new RuntimeException('wrong_order_provenance');
            $commits++; return ['sha'=>$prepared];
        }
        if ($method==='PATCH' && str_contains($url,'/git/refs/heads/')) {
            if ($data['force']!==false) throw new RuntimeException('force_forbidden');
            if ($pausePatch) { $pausePatch=false; throw new CarmajaApiException(502,'Simulated crash before ref update'); }
            $patches++; $head=$prepared; $order=$newOrder;
            if ($loseResponse) { $loseResponse=false; throw new CarmajaApiException(502,'Simulated lost response'); }
            return [];
        }
        if ($method==='GET' && str_contains($url,'/compare/')) return ['merge_base_commit'=>['sha'=>$diverged ? str_repeat('f',40) : $prepared],'status'=>$diverged ? 'diverged' : 'ahead'];
        throw new RuntimeException('unexpected_mock_request');
    };
    $view = CarmajaCatalogOrder::read(true); $request=['expectedRevision'=>$view['revision'],'productIds'=>[$ids[0],$ids[1]]];
    $remoteSave = static fn($body,$key) => CarmajaCatalogOrder::save(true,$body,$actor,$key,static fn()=>null);
    $reject(fn()=>$remoteSave($request,'remote-order-001'),502);
    $done=$remoteSave($request,'remote-order-001');
    $assert($done['saved'] && $commits===1 && $patches===1);
    $assert(count($written)===1 && $written[0]['path']===CarmajaCatalogOrder::REPO_PATH);
    $assert($remoteSave($request,'remote-order-001')===$done && $commits===1);
    $reject(fn()=>$remoteSave($request,'remote-stale-001'),409);
    // A later different order must never be rolled back by an old successful retry.
    $order=['version'=>1,'productIds'=>[$ids[1],$ids[0]]]; $head=str_repeat('e',40);
    $remoteSave($request,'remote-order-001');
    $assert($order['productIds']===[$ids[1],$ids[0]] && $patches===1);
    $view=CarmajaCatalogOrder::read(true); $request=['expectedRevision'=>$view['revision'],'productIds'=>[$ids[0],$ids[1]]];
    $prepared=str_repeat('c',40); $pausePatch=true;
    $reject(fn()=>$remoteSave($request,'remote-crash-001'),502);
    $assert($order['productIds']===[$ids[1],$ids[0]]);
    $remoteSave($request,'remote-crash-001');
    $assert($commits===2 && $patches===2 && $order['productIds']===[$ids[0],$ids[1]]);
    // Remote moves after preparation: reject, never force or recompute over newer data.
    $view=CarmajaCatalogOrder::read(true); $request=['expectedRevision'=>$view['revision'],'productIds'=>[$ids[1],$ids[0]]];
    $prepared=str_repeat('d',40); $pausePatch=true;
    $reject(fn()=>$remoteSave($request,'remote-race-0001'),502);
    $head=str_repeat('f',40); $diverged=true;
    $reject(fn()=>$remoteSave($request,'remote-race-0001'),409);
    $assert($commits===3 && $patches===2 && $order['productIds']===[$ids[0],$ids[1]]);
    echo "PASS catalogue ordering: $checks checks; local fixtures / mocked GitHub only\n";
} finally {
    unset($GLOBALS['CARMAJA_API_GITHUB_REQUEST_ADAPTER']);
    carmaja_api_test_remove_tree($fixture['root']);
}
