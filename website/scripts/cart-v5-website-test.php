<?php
declare(strict_types=1);

// Temporary catalog overlay only. Never a production deployment or publisher.
require_once __DIR__ . '/cart-v5-device-test.php';

function cart_v5_site_path(string $path, string $slug, string $sku): bool
{
    if (preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/D', $slug) !== 1
        || !str_ends_with($slug, '-handy-test') || preg_match('/^CP-2026-[0-9]{4}$/D', $sku) !== 1
        || str_contains($path, '\\') || array_intersect(explode('/', $path), ['', '.', '..']) !== []) { return false; }
    return preg_match('#^_next/static/[A-Za-z0-9_.$()/~-]+\.(?:js|css|svg|woff2?)$#D', $path) === 1
        || in_array($path, ['index.html','index.txt','__next.__PAGE__.txt','__next._full.txt','__next._tree.txt'], true)
        || preg_match('#^armbaender/(?:index\.(?:html|txt)|__next\.[A-Za-z0-9_.$~-]+\.txt)$#D', $path) === 1
        || preg_match('#^armbaender/' . preg_quote($slug, '#') . '/[A-Za-z0-9_.$/~\-]+\.(?:html|txt)$#D', $path) === 1
        || preg_match('#^images/products/' . preg_quote($sku, '#') . '/0[12]\.jpg$#D', $path) === 1;
}

function cart_v5_site_tree(string $root): array
{
    cart_probe_assert(realpath($root) === $root && !is_link($root), 'preview_root_unsafe');
    $files = [];
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)) as $file) {
        cart_probe_assert(!$file->isLink(), 'preview_symlink');
        if ($file->isFile()) { $files[substr($file->getPathname(), strlen($root) + 1)] = hash_file('sha256', $file->getPathname()); }
    }
    ksort($files, SORT_STRING); return $files;
}

function cart_v5_site_product(): array
{
    $r = cart_v5_root(); cart_v5_active(); $owner = cart_v5_read($r . '/v5-owner.json');
    cart_probe_assert(cart_v5_protected() === $owner['protected'], 'preview_protected_changed');
    $p = json_decode((string) file_get_contents($r . '/products/public-products-v2.json'), true, 32, JSON_THROW_ON_ERROR);
    cart_probe_assert(array_keys($p) === ['version','products'] && $p['version'] === 4 && count($p['products']) === 1, 'preview_projection_invalid');
    $product = $p['products'][0]; cart_v5_guard_product($product['productId'], $owner);
    $draft = carmaja_api_load_draft($product['productId']);
    cart_probe_assert(($draft['status'] ?? '') === 'published' && $product['title'] === 'HANDY-TEST'
        && hash_equals($product['sourceHash'], carmaja_api_v3_product_from_draft($draft)['sourceHash'])
        && cart_v5_site_path('armbaender/' . $product['slug'] . '/index.html', $product['slug'], $product['sku']), 'preview_product_invalid');
    return $product;
}

function cart_v5_site_prepare(): array
{
    $r = cart_v5_root(); $p = cart_v5_site_product(); $root = '/home/www/carmaja-test-site';
    $base = '/home/www/carmaja-test-deploy/incoming/cart-v5-website-20261014-9cd3831c-baseline.json';
    $baseline = cart_v5_read($base);
    cart_probe_assert($baseline['version'] === 3 && count($baseline['products']) === 3, 'preview_baseline_invalid');
    $manifest = (string) file_get_contents('/home/www/carmaja-test-deploy/state/current-manifest.tsv');
    cart_probe_assert(str_contains($manifest, "meta\tcommit\t78a90893347f836d0e479ea405d4814e38308ff5"), 'preview_baseline_commit_changed');
    $before = cart_v5_site_tree($root); $out = $r . '/website-preview-source';
    cart_probe_assert(!file_exists($out) && mkdir($out, 0700), 'preview_source_exists');
    foreach ($baseline['products'] as $existing) {
        cart_probe_assert($existing['productId'] !== $p['productId'] && $existing['sku'] !== $p['sku']
            && $existing['slug'] !== $p['slug'] && is_file($root . '/armbaender/' . $existing['slug'] . '/index.html'), 'preview_existing_product_conflict');
    }
    $all = [...$baseline['products'], $p];
    foreach ($all as $product) {
        cart_probe_assert(preg_match('/^CP-[0-9]{4}-[0-9]{4}$/D', $product['sku']) === 1, 'preview_image_sku_invalid');
        $dir = $out . '/images/products/' . $product['sku']; cart_probe_assert(mkdir($dir, 0700, true), 'preview_image_directory_failed');
        foreach ($product['images'] as $image) {
            cart_probe_assert(preg_match('/^0[1-5]\.jpg$/D', $image['fileName']) === 1
                && $image['src'] === '/images/products/' . $product['sku'] . '/' . $image['fileName'], 'preview_image_contract_invalid');
            if ($product['productId'] === $p['productId']) {
                $validated = carmaja_api_v4_product_image_file($p['productId'], $image['imageId']); $source = $validated['path'];
            } else { $source = $root . $image['src']; }
            cart_probe_assert(realpath($source) === $source && !is_link($source) && is_file($source)
                && copy($source, $dir . '/' . $image['fileName']) && chmod($dir . '/' . $image['fileName'], 0600), 'preview_image_copy_failed');
        }
    }
    // Existing unused same-SKU assets may be restored, but never overwrite another product's image.
    foreach ($before as $relative => $_hash) {
        if (preg_match('/\.(?:html|txt)$/D', $relative) === 1) {
            cart_probe_assert(!str_contains((string) file_get_contents($root . '/' . $relative), '/images/products/' . $p['sku'] . '/'), 'preview_image_used_by_existing_page');
        }
    }
    cart_v5_write($out . '/products.json', ['version' => 4, 'products' => $all]);
    cart_v5_write($r . '/website-preview-owner.json', ['testOnly'=>true, 'before'=>$before, 'slug'=>$p['slug'], 'sku'=>$p['sku'],
        'productVersion'=>$p['productVersion'], 'sourceHash'=>$p['sourceHash']]);
    $proc = proc_open(['tar','-czf',$r . '/website-preview-source.tar.gz','-C',$out,'.'], [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']], $pipes);
    cart_probe_assert(is_resource($proc), 'preview_archive_start_failed'); fclose($pipes[0]); stream_get_contents($pipes[1]); fclose($pipes[1]); stream_get_contents($pipes[2]); fclose($pipes[2]);
    cart_probe_assert(proc_close($proc) === 0 && chmod($r . '/website-preview-source.tar.gz', 0600), 'preview_archive_failed');
    cart_probe_assert(cart_v5_site_tree($root) === $before, 'preview_preparation_changed_site');
    return ['status'=>'preview_source_ready','products'=>4,'existingProducts'=>3,'slug'=>$p['slug'],'siteUnchanged'=>true];
}

function cart_v5_site_compare(array $journal, bool $partial = false): void
{
    $after = cart_v5_site_tree('/home/www/carmaja-test-site');
    foreach ($journal['files'] as $relative => $hash) {
        if ($partial && ($after[$relative] ?? null) === ($journal['before'][$relative] ?? null)) { continue; }
        cart_probe_assert(($after[$relative] ?? null) === $hash, 'preview_file_changed');
        if (isset($journal['before'][$relative])) { $after[$relative] = $journal['before'][$relative]; } else { unset($after[$relative]); }
    }
    cart_probe_assert($after === $journal['before'], 'preview_unrelated_site_changed');
}

function cart_v5_site_remove(): array
{
    $r = cart_v5_root(); $journal = cart_v5_read($r . '/website-preview-journal.json');
    $root = '/home/www/carmaja-test-site'; cart_v5_site_compare($journal, true);
    foreach ($journal['files'] as $relative => $_hash) {
        cart_probe_assert(cart_v5_site_path($relative, $journal['slug'], $journal['sku']), 'preview_restore_path_invalid');
        $path = $root . '/' . $relative;
        if (isset($journal['originals'][$relative])) {
            $text = base64_decode($journal['originals'][$relative], true); cart_probe_assert(is_string($text)
                && hash_equals($journal['before'][$relative], hash('sha256', $text)), 'preview_original_invalid');
            cart_v5_atomic($path, $text, 0644);
        } elseif (is_file($path)) { cart_probe_assert(!is_link($path) && unlink($path), 'preview_remove_failed'); }
    }
    $dirs = $journal['directories']; usort($dirs, static fn($a,$b)=>strlen($b)<=>strlen($a));
    foreach ($dirs as $dir) { $path=$root.'/'.$dir; if (is_dir($path)) { cart_probe_assert(realpath($path)===$path && !is_link($path)
        && count(scandir($path))===2 && rmdir($path), 'preview_directory_remove_failed'); } }
    cart_probe_assert(cart_v5_site_tree($root)===$journal['before'], 'preview_restore_mismatch');
    cart_v5_atomic($r.'/website-preview-restored.json', json_encode(['testOnly'=>true,'restored'=>true], JSON_THROW_ON_ERROR), 0600);
    return ['status'=>'preview_restored','originalSiteHashEqual'=>true];
}

function cart_v5_site_install(): array
{
    $r=cart_v5_root(); $p=cart_v5_site_product(); $owner=cart_v5_read($r.'/website-preview-owner.json');
    $root='/home/www/carmaja-test-site'; $source='/home/www/carmaja-test-deploy/incoming/cart-v5-website-20261014-9cd3831c-export';
    $journalPath=$r.'/website-preview-journal.json'; $restoredPath=$r.'/website-preview-restored.json';
    $reinstall=is_file($journalPath) && is_file($restoredPath)
        && (cart_v5_read($restoredPath)['restored']??false)===true;
    cart_probe_assert($owner['testOnly']===true && $owner['slug']===$p['slug'] && $owner['sku']===$p['sku']
        && $owner['productVersion']===$p['productVersion'] && hash_equals($owner['sourceHash'],$p['sourceHash'])
        && cart_v5_site_tree($root)===$owner['before'] && (!file_exists($journalPath)||$reinstall), 'preview_install_baseline_changed');
    $all=cart_v5_site_tree($source);
    $manifest=cart_v5_read('/home/www/carmaja-test-deploy/incoming/cart-v5-website-20261014-9cd3831c-manifest.json');
    cart_probe_assert(($manifest['testOnly']??false)===true && $manifest['files']===$all
        && $manifest['productVersion']===$p['productVersion'] && hash_equals($manifest['sourceHash'],$p['sourceHash']), 'preview_export_manifest_invalid');
    $files=[]; $originals=[]; $directories=[];
    foreach ($all as $relative=>$hash) {
        cart_probe_assert(cart_v5_site_path($relative,$p['slug'],$p['sku']), 'preview_file_not_allowed');
        if (($owner['before'][$relative]??null)===$hash) { continue; }
        if (isset($owner['before'][$relative])) { cart_probe_assert(!str_starts_with($relative,'_next/'), 'preview_asset_collision');
            $originals[$relative]=base64_encode((string)file_get_contents($root.'/'.$relative)); }
        $files[$relative]=$hash; $dir=dirname($relative);
        while ($dir!=='.') { if(!is_dir($root.'/'.$dir)) { $directories[$dir]=true; } $dir=dirname($dir); }
    }
    cart_probe_assert(isset($files['armbaender/index.html'],$files['armbaender/'.$p['slug'].'/index.html']), 'preview_pages_missing');
    // Journal precedes every website write, including rollback data for unused colliding images.
    $journal=[...$owner,'files'=>$files,'originals'=>$originals,'directories'=>array_keys($directories)];
    if($reinstall){cart_probe_assert(cart_v5_read($journalPath)===$journal && unlink($restoredPath), 'preview_reinstall_journal_changed');}
    else {cart_v5_write($journalPath,$journal);}
    try {
        foreach($files as $relative=>$hash) {
            $parent=$root;
            foreach(explode('/',dirname($relative)) as $part) { if($part==='.')continue; $parent.='/'.$part;
                if(!file_exists($parent)){cart_probe_assert(mkdir($parent,0755)&&chmod($parent,0755),'preview_mkdir_failed');}
                cart_probe_assert(realpath($parent)===$parent&&!is_link($parent),'preview_target_unsafe'); }
            $target=$root.'/'.$relative; cart_probe_assert(!is_link($target)
                && (isset($owner['before'][$relative]) ? hash_equals($owner['before'][$relative],hash_file('sha256',$target)) : !file_exists($target)), 'preview_concurrent_change');
            cart_v5_atomic($target,(string)file_get_contents($source.'/'.$relative),0644);
            cart_probe_assert(hash_equals($hash,hash_file('sha256',$target)), 'preview_installed_hash_invalid');
        }
        cart_v5_site_compare($journal); cart_v5_site_product();
    } catch(Throwable $e) { cart_v5_site_remove(); throw $e; }
    return ['status'=>'preview_installed','slug'=>$p['slug'],'existingDetailLegalAuthFilesUnchanged'=>true,'rollbackJournal'=>true];
}

if (PHP_SAPI === 'cli' && realpath((string)($_SERVER['SCRIPT_FILENAME']??''))===__FILE__) {
    ini_set('display_errors','0');ini_set('log_errors','0');umask(0077);
    try { $result=match($argv[1]??''){ 'prepare'=>cart_v5_site_prepare(),'install'=>cart_v5_site_install(),
        'remove'=>cart_v5_site_remove(),'verify'=>(function(){cart_v5_site_product();cart_v5_site_compare(cart_v5_read(cart_v5_root().'/website-preview-journal.json'));return ['status'=>'preview_verified'];})(),
        default=>throw new RuntimeException('preview_action_invalid')};echo json_encode($result,JSON_THROW_ON_ERROR)."\n";
    } catch(Throwable $e){$code=$e instanceof RuntimeException ? $e->getMessage() : 'preview_safety_check_failed';
        if(preg_match('/^[a-z0-9_]{1,80}$/D',$code)!==1){$code='preview_safety_check_failed';}
        echo json_encode(['status'=>'failed','code'=>$code],JSON_THROW_ON_ERROR)."\n";exit(1);}
}
