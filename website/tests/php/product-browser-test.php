<?php
declare(strict_types=1);
require __DIR__ . '/product-api-test.php';
require dirname(__DIR__, 2) . '/test-api-private/program/product-browser.php';

$checks = 0;
$assert = static function (bool $value) use (&$checks): void { if (!$value) throw new RuntimeException('browser_contract_failed'); $checks++; };
$reject = static function (callable $call, int $status) use (&$checks): void {
    try { $call(); } catch (CarmajaApiException $e) { if ($e->statusCode !== $status) throw new RuntimeException('browser_wrong_status'); $checks++; return; }
    throw new RuntimeException('browser_expected_rejection');
};
$fixture = carmaja_api_test_fixture();
try {
    $config = ['environment'=>'test','publishTarget'=>'test','productionPublishEnabled'=>false,'githubAdapterEnabled'=>false,
        'privateDir'=>$fixture['testPrivate'],'productApiV5WritesEnabled'=>true];
    $browser = new CarmajaProductBrowser($config, static fn () => null);
    foreach (['https://evil.invalid', 'null', '', 'https://test.carmaja-perlen.de.evil.invalid'] as $origin) {
        $reject(fn () => CarmajaProductBrowser::origin($origin), 403);
    }
    CarmajaProductBrowser::origin(CarmajaProductBrowser::ORIGIN); $assert(true);
    $reject(fn () => new CarmajaProductBrowser([...$config,'publishTarget'=>'production'], static fn () => null), 503);
    $reject(fn () => new CarmajaProductBrowser([...$config,'githubAdapterEnabled'=>true], static fn () => null), 503);
    $reject(fn () => $browser->authenticate('', null, false, time()), 401);
    $reject(fn () => $browser->login(['username'=>'admin','password'=>'not-the-test-password'], time()), 401);
    $now = time(); $login = $browser->login(['username'=>'admin','password'=>'secret'], $now);
    $actor = $browser->authenticate($login['cookie'], $login['csrfToken'], true, $now);
    $assert(str_starts_with($actor['tokenId'], 'browser-'));
    $reject(fn () => $browser->authenticate($login['cookie'], 'wrong', true, $now), 403);
    $reject(fn () => $browser->authenticate($login['cookie'], null, true, $now), 403);
    $reject(fn () => $browser->authenticate($login['cookie'], null, false, $now + 1800), 401);
    $id = '99999999-9999-4999-8999-999999999992';
    $imageId = '99999999-9999-4999-8999-999999999993';
    $body = ['expectedProductVersion'=>0,'name'=>'HANDY-TEST Browser',
        'descriptionDocument'=>['version'=>1,'blocks'=>[['type'=>'paragraph','spans'=>[
            ['text'=>'Format bleibt erhalten','bold'=>true,'italic'=>true,'font'=>'elegant','size'=>'large']]]]],
        'materials'=>['Naturstein'],'metalElements'=>[],'braceletSizeCm'=>18.5,'pearlSizeMm'=>8,'careInstructions'=>[],
        'images'=>[['imageId'=>$imageId,'fileName'=>'01.jpg','alt'=>'Künstliches Testbild','width'=>120,'height'=>80,'isMain'=>true]],
        'priceMinor'=>2800,'currency'=>'eur','salesEnabled'=>false];
    $first = $browser->dispatch('PUT',['products',$id],$body,$actor,'browser-create-0001')['product'];
    $assert($first['productVersion'] === 1 && $first['images'][0]['uploaded'] === false);
    $assert($first['notices'] === CARMAJA_PRODUCT_NOTICES && $first['salesEnabled'] === false);
    $assert($first === $browser->dispatch('PUT',['products',$id],$body,$actor,'browser-create-0001')['product']);
    // Browser -> same Android read service; Android write -> same Browser read.
    $app = carmaja_api_v5_product_response_from_draft(carmaja_api_load_draft($id));
    $assert($app['descriptionDocument'] === $body['descriptionDocument'] && $app['braceletSizeCm'] === 18.5 && $app['pearlSizeMm'] === 8);
    carmaja_api_v5_put($id,[...$body,'expectedProductVersion'=>1,'name'=>'HANDY-TEST Appänderung'],carmaja_api_test_actor(),'app-update-0001');
    $read = $browser->dispatch('GET',['products',$id],[],$actor,null)['product'];
    $assert($read['name'] === 'HANDY-TEST Appänderung' && $read['productVersion'] === 2);
    $reject(fn () => $browser->dispatch('PUT',['products',$id],[...$body,'expectedProductVersion'=>1],$actor,'browser-stale-0001'),409);
    foreach (['stock'=>3,'sourceHash'=>'fake','productVersion'=>99,'notices'=>[],'noticePolicyId'=>null] as $field=>$value) {
        $reject(fn () => $browser->dispatch('PUT',['products',$id],[...$body,$field=>$value],$actor,'browser-forbidden-0001'),422);
    }
    $reject(fn () => $browser->dispatch('PUT',['products',$id],$body,$actor,null),422);
    $disabled = new CarmajaProductBrowser([...$config,'productApiV5WritesEnabled'=>false],static fn () => null);
    $reject(fn () => $disabled->dispatch('PUT',['products',$id],$body,$actor,'browser-disabled-0001'),503);
    $GLOBALS['CARMAJA_API_ALLOW_LOCAL_UPLOADS_FOR_TESTS'] = true;
    $jpg = $fixture['root'] . '/artificial.jpg'; carmaja_api_test_create_jpeg($jpg);
    carmaja_api_test_prepare_image_upload($read['version'],$imageId,[$imageId],$jpg,'Künstliches Testbild');
    $uploaded = $browser->dispatch('POST',['products',$id,'images'],$_POST,$actor,'browser-image-0001')['product'];
    $assert($uploaded['images'][0]['uploaded'] === true);
    $file = $browser->dispatch('GET',['products',$id,'images',$imageId],[],$actor,null)['imageFile'];
    $assert(is_file($file['path']) && $uploaded['salesEnabled'] === false);
    $GLOBALS['CARMAJA_API_COLLECTION_PROJECTION_V4'] = static fn () => ['applied'=>true];
    $transition = static function($action,$p,$op) use ($browser,$actor,$id) {
        return $browser->dispatch('POST',['products',$id,$action],['expectedProductVersion'=>$p['productVersion'],
            'expectedSourceHash'=>$p['sourceHash'],'operationId'=>$op],$actor,$op)['product'];
    };
    $published = $transition('publish',$uploaded,'browser-publish-0001');
    $assert($published['status'] === 'published' && $published['salesEnabled'] === false);
    $assert($published === $transition('publish',$uploaded,'browser-publish-0001'));
    $archived = $transition('archive',$published,'browser-archive-0001');
    $assert($archived['status'] === 'disabled' && !$archived['salesEnabled']);
    $restored = $transition('restore',$archived,'browser-restore-0001');
    $assert($restored['status'] === 'published' && !$restored['salesEnabled']);
    $assert(!str_contains(json_encode($browser->dispatch('GET',['products'],[],$actor,null)), $fixture['root']));
    for ($i = 1; $i < 120; $i++) { $browser->authenticate($login['cookie'],null,false,$now); }
    $reject(fn () => $browser->authenticate($login['cookie'],null,false,$now),429);
    $browser->logout($login['cookie']); $reject(fn () => $browser->authenticate($login['cookie'],null,false,$now+60),401);
    $next = $browser->login(['username'=>'admin','password'=>'secret'],$now+60);
    carmaja_api_test_write_json($fixture['testUsers'], ['environment'=>'test','users'=>[]]);
    $reject(fn () => $browser->authenticate($next['cookie'],null,false,$now+60),401);
    $assert(!file_exists(carmaja_api_tokens_path())); // Browser does not create or change Android device tokens.
    echo "PASS product browser security/shared-data contract: $checks checks\n";
} finally {
    unset($GLOBALS['CARMAJA_API_COLLECTION_PROJECTION_V4'],$GLOBALS['CARMAJA_API_ALLOW_LOCAL_UPLOADS_FOR_TESTS']);
    carmaja_api_test_remove_tree($fixture['root']);
}
