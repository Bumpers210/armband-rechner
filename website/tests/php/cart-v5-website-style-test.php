<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/scripts/cart-v5-website-style-test.php';
$count = 0;
foreach (['index.html','armbaender/cp-2026-0001-handy-test/index.html','datenschutz/index.html','__next._full.txt'] as $path) {
    if (!cart_style_page($path)) { throw new RuntimeException('Expected public page'); } $count++;
}
foreach (['../index.html','a/../index.html','/index.html','a//index.html','.htaccess','config/runtime-config.php','_next/a.txt','a\\b.html'] as $path) {
    if (cart_style_page($path)) { throw new RuntimeException('Unsafe page accepted'); } $count++;
}
if (cart_style_css('<link href="/_next/static/chunks/test.css"><link href="/_next/static/chunks/test.css">') !== '/_next/static/chunks/test.css') {
    throw new RuntimeException('CSS binding failed');
} $count++;
foreach (['<link href="https://elsewhere/a.css">','<link href="/_next/static/a.css"><link href="/_next/static/b.css">',''] as $html) {
    $rejected = false;
    try { cart_style_css($html); } catch (RuntimeException) { $rejected = true; }
    if (!$rejected) { throw new RuntimeException('Ambiguous CSS accepted'); } $count++;
}
echo "Style preview guards: $count passed\n";
$before = ['kontakt/index.html'=>'test', 'config/runtime-config.php'=>'test', 'datenschutz/index.html'=>'test'];
foreach (['kontakt/index.html'=>true, 'kontakt/__next.kontakt/__PAGE__.txt'=>true,
    'unknown/__next.unknown/__PAGE__.txt'=>false, 'config/runtime-config.php'=>false,
    'unknown/index.html'=>false, '../kontakt/index.html'=>false] as $path=>$expected) {
    if (cart_style_payload_path($path,$before,'cp-2026-0001-handy-test','CP-2026-0001') !== $expected) {
        throw new RuntimeException('Existing-page allowlist failed');
    }
}
if (cart_style_legal_text('<main><svg aria-hidden="true"></svg><h1>Recht &amp; Text</h1><p>Unverändert.</p></main>')
    !== cart_style_legal_text('<main class="restyled"><h1>Recht &amp; Text</h1><p>Unverändert.</p></main>')) {
    throw new RuntimeException('Legal presentation-only comparison failed');
}
echo "All-page preview guards: 7 passed\n";
$before['images/products/CP-2026-0001/01.jpg'] = 'test';
foreach (['images/product-previews/CP-2026-0001/aaaaaaaaaaaaaaaa/01-640.jpg'=>true,
    'images/product-previews/CP-2026-0001/aaaaaaaaaaaaaaaa/02-640.jpg'=>false,
    'images/product-previews/CP-2026-0001/aaaaaaaaaaaaaaaa/01-3200.jpg'=>false,
    'images/product-previews/CP-2026-0001/../01-640.jpg'=>false] as $path=>$expected) {
    if (cart_style_payload_path($path,$before,'cp-2026-0001-handy-test','CP-2026-0001') !== $expected) {
        throw new RuntimeException('Preview image allowlist failed');
    }
}
echo "Image preview guards: 4 passed\n";
