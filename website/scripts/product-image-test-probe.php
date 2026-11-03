<?php
declare(strict_types=1);

// Disposable, synthetic Web-SAPI proof. No database or existing product data.
function image_probe_assert(bool $ok, string $code): void { if (!$ok) { throw new RuntimeException($code); } }
function image_probe_root(): string {
    $root = __DIR__;
    image_probe_assert(realpath($root) === $root && !is_link($root)
        && preg_match('#^/home/www/carmaja-private-test/image3200-[a-f0-9]{12}$#D', $root) === 1, 'unsafe_probe_root');
    return $root;
}
function image_probe_write(string $path, string $bytes, int $mode = 0600): void {
    image_probe_assert(!file_exists($path) && !is_link($path), 'probe_file_exists');
    image_probe_assert(file_put_contents($path,$bytes,LOCK_EX) === strlen($bytes) && chmod($path,$mode), 'probe_write_failed');
}
function image_probe_setup(): array {
    $r = image_probe_root(); $public = '/home/www/carmaja-test-api/' . basename($r);
    image_probe_assert(!file_exists($public), 'probe_public_exists');
    foreach (['auth','audit','drafts','products','products/operations','uploads','uploads-temp','backups','sku-counter','locks','idempotency','website/test-api-private/config'] as $dir) {
        image_probe_assert(mkdir($r.'/'.$dir,0750,true), 'probe_mkdir_failed');
    }
    image_probe_assert(mkdir($public,0755), 'probe_public_failed');
    $password = bin2hex(random_bytes(24)); $guard = bin2hex(random_bytes(32));
    image_probe_write($r.'/owner.json',json_encode(['testOnly'=>true,'expires'=>time()+1800,'guard'=>$guard,'password'=>$password], JSON_THROW_ON_ERROR));
    image_probe_write($r.'/environment.json','{"environment":"test"}');
    image_probe_write($r.'/auth/api-users.json',json_encode(['environment'=>'test','users'=>[
        ['username'=>'synthetic-image-probe','passwordHash'=>password_hash($password,PASSWORD_ARGON2ID),'active'=>true]]],JSON_THROW_ON_ERROR));
    $config = ['environment'=>'test','publishTarget'=>'test','productionPublishEnabled'=>false,'githubAdapterEnabled'=>false,
        'productApiV5WritesEnabled'=>true,'inventoryCartEnabled'=>true,'productApiV4WritesEnabled'=>false,'collectionCommerceEnabled'=>false,
        'privateDir'=>$r,'testPrivateDir'=>$r,'testApiWebroot'=>$public,'testWebsiteWebroot'=>'/home/www/carmaja-test-site',
        'usersFile'=>$r.'/auth/api-users.json','tokenPepper'=>bin2hex(random_bytes(32))];
    image_probe_write($r.'/website/test-api-private/config/runtime-config.php',"<?php\nreturn ".var_export($config,true).";\n");
    image_probe_write($public.'/index.php',"<?php\nini_set('display_errors','0');ini_set('log_errors','0');\nrequire ".var_export(__FILE__,true).";\nimage_probe_http();\n",0644);
    return ['setup'=>true,'syntheticOnly'=>true];
}
function image_probe_http(): never {
    header('Cache-Control: no-store'); header('X-Content-Type-Options: nosniff');
    try {
        $r=image_probe_root(); $owner=json_decode((string)file_get_contents($r.'/owner.json'),true,16,JSON_THROW_ON_ERROR);
        image_probe_assert($owner['testOnly'] === true && $owner['expires'] > time()
            && hash_equals($owner['guard'],(string)($_SERVER['HTTP_X_IMAGE_PROBE']??'')), 'probe_denied');
        define('CARMAJA_BOOTSTRAP_NO_RUN',true);
        require $r.'/website/test-api-private/program/bootstrap.php';
        carmaja_bootstrap_prepare();
        $_SERVER['HTTP_AUTHORIZATION'] ??= $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
        if ($_SERVER['HTTP_AUTHORIZATION'] === '' && function_exists('getallheaders')) {
            foreach (getallheaders() as $key=>$value) { if (strtolower($key)==='authorization') { $_SERVER['HTTP_AUTHORIZATION']=$value; } }
        }
        $parts=explode('/',trim((string)($_SERVER['PATH_INFO']??''),'/'));
        image_probe_assert(($parts[0]??'')==='v5' && !in_array($parts[3]??'', ['publish','initial-inventory','inventory','archive','restore'], true), 'probe_path_denied');
        header('X-Image-Probe-Upload-Max: '.ini_get('upload_max_filesize'));
        header('X-Image-Probe-Post-Max: '.ini_get('post_max_size'));
        carmaja_api_v5_route($_SERVER['REQUEST_METHOD'],array_slice($parts,1));
    } catch (CarmajaApiException $e) {
        http_response_code($e->statusCode); header('Content-Type: application/json');
        echo json_encode(['ok'=>false,'error'=>['code'=>$e->errorCode]]); exit;
    } catch (Throwable) { http_response_code(403); echo '{"ok":false}'; exit; }
}
function image_probe_request(string $path,string $method='GET',?array $body=null,?string $token=null,?array $multipart=null, bool $guard=true): array {
    $r=image_probe_root(); $owner=json_decode((string)file_get_contents($r.'/owner.json'),true,16,JSON_THROW_ON_ERROR);
    $headers=['X-Carmaja-App-Version-Code: 11','Idempotency-Key: image-probe-create-3200'];
    if ($guard) { $headers[]='X-Image-Probe: '.$owner['guard']; }
    if ($token!==null) { $headers[]='Authorization: Bearer '.$token; }
    if ($body!==null) { $headers[]='Content-Type: application/json'; }
    $ch=curl_init('https://test-api.carmaja-perlen.de/'.basename($r).'/index.php'.$path);
    curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_SSL_VERIFYPEER=>true,
        CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_CONNECTTIMEOUT=>5,CURLOPT_TIMEOUT=>30,CURLOPT_CUSTOMREQUEST=>$method,CURLOPT_HTTPHEADER=>$headers]);
    if ($body!==null || $multipart!==null) { curl_setopt($ch,CURLOPT_POSTFIELDS,$multipart??json_encode($body,JSON_THROW_ON_ERROR)); }
    $raw=curl_exec($ch); $status=curl_getinfo($ch,CURLINFO_RESPONSE_CODE); curl_close($ch);
    image_probe_assert(is_string($raw),'probe_http_failed');
    return ['status'=>$status,'raw'=>$raw,'json'=>json_decode($raw,true)];
}
function image_probe_run(): array {
    $r=image_probe_root(); $owner=json_decode((string)file_get_contents($r.'/owner.json'),true,16,JSON_THROW_ON_ERROR);
    image_probe_assert(image_probe_request('/v5/products',guard:false)['status']===403,'probe_guard_failed');
    image_probe_assert(image_probe_request('/v5/products')['status']===401,'probe_auth_failed');
    $login=image_probe_request('/v5/login','POST',['username'=>'synthetic-image-probe','password'=>$owner['password'],'deviceName'=>'Image proof','publishTarget'=>'test']);
    image_probe_assert($login['status']===200,'probe_login_failed'); $token=$login['json']['data']['token'];
    $id='88320000-0000-4000-8000-000000000001';$img='88320000-0000-4000-8000-000000000002';
    $body=['expectedProductVersion'=>0,'name'=>'Artificial image proof','descriptionDocument'=>['version'=>1,'blocks'=>[
        ['type'=>'paragraph','spans'=>[['text'=>'Synthetic image upload only.','bold'=>false,'italic'=>false,'font'=>'standard','size'=>'normal']]]]],
        'materials'=>['Test'],'metalElements'=>[],'braceletSizeCm'=>18,'pearlSizeMm'=>8,'careInstructions'=>[],
        'images'=>[['imageId'=>$img,'fileName'=>'01.jpg','alt'=>'Synthetic','width'=>3200,'height'=>2400,'isMain'=>true]],'priceMinor'=>2800,'currency'=>'eur'];
    $created=image_probe_request('/v5/products/'.$id,'PUT',$body,$token);
    image_probe_assert($created['status']===200,'probe_create_failed'); $p=$created['json']['data']['product'];
    $image=imagecreatetruecolor(3200,2400); imagefill($image,0,0,imagecolorallocate($image,130,80,120));
    $source=$r.'/synthetic.jpg'; image_probe_assert(imagejpeg($image,$source,88) && chmod($source,0600),'probe_jpeg_failed');
    unset($image); file_put_contents($source,str_repeat('x',1048576),FILE_APPEND); clearstatcache(true,$source);
    $form=['expectedVersion'=>(string)$p['version'],'imageId'=>$img,'desiredImageIds'=>json_encode([$img]),'image'=>new CURLFile($source,'image/jpeg','01.jpg')];
    $uploaded=image_probe_request('/v5/products/'.$id.'/images','POST',null,$token,$form);
    image_probe_assert($uploaded['status']===200,'probe_upload_failed'); $p=$uploaded['json']['data']['product'];
    image_probe_assert($p['images'][0]['width']===3200 && $p['images'][0]['height']===2400,'probe_dimensions_changed');
    $download=image_probe_request('/v5/products/'.$id.'/images/'.$img,'GET',null,$token);
    $size=getimagesizefromstring($download['raw']);
    image_probe_assert($download['status']===200 && $size[0]===3200 && $size[1]===2400
        && strlen($download['raw'])<filesize($source),'probe_download_failed');
    $read=image_probe_request('/v5/products/'.$id,'GET',null,$token);
    image_probe_assert($read['status']===200 && $read['json']['data']['product']['sourceHash']===$p['sourceHash'],'probe_reload_failed');
    // Reject too many pixels and bytes without changing the saved image or product version.
    $bad=imagecreatetruecolor(3201,2); imagejpeg($bad,$source,88); unset($bad); clearstatcache(true,$source);
    $form['expectedVersion']=(string)$p['version'];
    image_probe_assert(image_probe_request('/v5/products/'.$id.'/images','POST',null,$token,$form)['status']===422,'probe_edge_not_rejected');
    file_put_contents($source,str_repeat('x',5242881)); clearstatcache(true,$source);
    image_probe_assert(image_probe_request('/v5/products/'.$id.'/images','POST',null,$token,$form)['status']===413,'probe_size_not_rejected');
    image_probe_assert(image_probe_request('/v5/products/'.$id,'GET',null,$token)['json']===$read['json'],'probe_rejection_mutated_product');
    return ['httpUpload3200'=>true,'httpDownload3200'=>true,'overOneMiBAccepted'=>true,'metadataStripped'=>true,
        'oversizeRejected'=>true,'reloadHashStable'=>true,'noDatabaseOrExistingProductAccess'=>true];
}
function image_probe_cleanup(): array {
    $r=image_probe_root(); $public='/home/www/carmaja-test-api/'.basename($r);
    image_probe_assert(realpath($public)===$public && !is_link($public)
        && array_values(array_diff(scandir($public),['.','..']))===['index.php'], 'probe_cleanup_public_unsafe');
    image_probe_assert(unlink($public.'/index.php') && rmdir($public),'probe_cleanup_public_failed');
    // Fixed, freshly allocated root only; reject every symlink before deletion.
    $files=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($r,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
    $paths=[]; foreach($files as $f) { image_probe_assert(!$f->isLink(),'probe_cleanup_link'); $paths[]=[(string)$f,$f->isDir()]; }
    foreach($paths as [$path,$dir]) { image_probe_assert($dir?rmdir($path):unlink($path),'probe_cleanup_failed'); }
    image_probe_assert(rmdir($r) && !file_exists($public) && !file_exists($r),'probe_cleanup_incomplete');
    return ['temporaryEndpointAndPrivateFixturesRemoved'=>true];
}
if (PHP_SAPI==='cli' && realpath((string)($_SERVER['SCRIPT_FILENAME']??''))===__FILE__) {
    ini_set('display_errors','0'); ini_set('log_errors','0'); umask(0077);
    try { $result=match($argv[1]??'') { 'setup'=>image_probe_setup(),'run'=>image_probe_run(),'cleanup'=>image_probe_cleanup(),default=>throw new RuntimeException('probe_action_invalid') };
        echo json_encode($result,JSON_THROW_ON_ERROR)."\n";
    } catch(Throwable $e) { $code=$e->getMessage(); echo json_encode(['failed'=>preg_match('/^[a-z0-9_]{1,80}$/D',$code)?$code:'probe_failed'])."\n"; exit(1); }
}
