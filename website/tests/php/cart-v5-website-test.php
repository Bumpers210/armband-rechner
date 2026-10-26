<?php
declare(strict_types=1);
require_once dirname(__DIR__,2).'/scripts/cart-v5-website-test.php';
$checks=0;
$assert=static function(bool $ok) use (&$checks):void { if(!$ok){throw new RuntimeException('preview_guard_test_failed');}$checks++; };
$slug='cp-2026-0001-handy-test';$sku='CP-2026-0001';
foreach(['index.html','index.txt','__next._tree.txt','armbaender/index.html','armbaender/__next.armbaender.__PAGE__.txt',
    'armbaender/'.$slug.'/index.html','armbaender/'.$slug.'/__next._full.txt','armbaender/'.$slug.'/__next.armbaender/$d$slug/__PAGE__.txt','images/products/'.$sku.'/01.jpg',
    'images/products/'.$sku.'/02.jpg','_next/static/chunks/abc123.js','_next/static/chunks/style.css'] as $p){$assert(cart_v5_site_path($p,$slug,$sku));}
foreach(['../index.html','/index.html','armbaender//index.html','armbaender/./index.html','armbaender/'.$slug.'/../index.html',
    'runtime-config.php','.htaccess','.htpasswd','products.json','shop/success/index.html','datenschutz/index.html',
    'armbaender/cp-2026-0008-test-3/index.html','images/products/CP-2026-0008/01.jpg','images/products/'.$sku.'/03.jpg',
    '_next/static/secret.php','_next/static/a\\b.js'] as $p){$assert(!cart_v5_site_path($p,$slug,$sku));}
$assert(!cart_v5_site_path('index.html','../handy-test',$sku));
$assert(!cart_v5_site_path('index.html','real-product',$sku));
$assert(!cart_v5_site_path('index.html',$slug,'../production'));
$source=(string)file_get_contents(dirname(__DIR__,2).'/scripts/cart-v5-website-test.php');
foreach(['preview_image_used_by_existing_page','preview_baseline_commit_changed','preview_asset_collision',
    'preview_unrelated_site_changed','preview_original_invalid','cart_v5_site_remove(); throw $e;',
    'website-preview-journal.json'] as $guard){$assert(str_contains($source,$guard));}
$cleanup=(string)file_get_contents(dirname(__DIR__,2).'/scripts/cart-v5-device-test.php');
$assert(str_contains($cleanup,'v5_website_preview_must_be_restored_first'));
echo "PASS temporary V5 website overlay guards: $checks assertions\n";
