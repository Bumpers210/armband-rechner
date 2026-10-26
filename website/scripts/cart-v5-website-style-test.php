<?php
declare(strict_types=1);

// Reversible visual preview on top of the existing HANDY-TEST preview only.
require_once __DIR__ . '/cart-v5-website-test.php';

function cart_style_page(string $path): bool
{
    return !str_contains($path, '\\') && array_intersect(explode('/', $path), ['', '.', '..']) === []
        && preg_match('#^[A-Za-z0-9_.$/~-]+\.(?:html|txt)$#D', $path) === 1
        && !str_starts_with($path, '_next/');
}

function cart_style_css(string $html): string
{
    preg_match_all('#href="(/_next/static/[A-Za-z0-9_./~-]+\.css)"#', $html, $matches);
    $css = array_values(array_unique($matches[1]));
    cart_probe_assert(count($css) === 1, 'style_single_css_required');
    return $css[0];
}

function cart_style_payload_path(string $path, array $before, string $slug, string $sku): bool
{
    if (preg_match('#^images/product-previews/(CP-[0-9]{4}-[0-9]{4})/[a-f0-9]{16}/(0[1-5])-(?:240|640|1200)\.jpg$#D', $path, $match) === 1) {
        return isset($before['images/products/' . $match[1] . '/' . $match[2] . '.jpg']);
    }
    // Extend the preview to existing public pages, never to config, API or arbitrary new routes.
    if (cart_v5_site_path($path, $slug, $sku)) { return true; }
    if (!cart_style_page($path)) { return false; }
    if (isset($before[$path])) { return true; }
    // Next.js may emit a new RSC filename for an already existing route.
    return preg_match('#^(.+)/__next\.[A-Za-z0-9_.$/~-]+/__PAGE__\.txt$#D', $path, $match) === 1
        && isset($before[$match[1] . '/index.html']);
}

function cart_style_legal_text(string $html): string
{
    cart_probe_assert(preg_match('#<main\b[^>]*>(.*?)</main>#s', $html, $match) === 1, 'style_legal_main_missing');
    $text = html_entity_decode(strip_tags($match[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    return trim((string) preg_replace('/\s+/u', ' ', $text));
}

function cart_style_verify(array $journal, bool $partial = false): void
{
    cart_probe_assert(($journal['testOnly'] ?? false) === true, 'style_test_only_required');
    $tree = cart_v5_site_tree('/home/www/carmaja-test-site');
    foreach ($journal['files'] as $relative => $hash) {
        if ($partial && ($tree[$relative] ?? null) === ($journal['before'][$relative] ?? null)) { continue; }
        cart_probe_assert(($tree[$relative] ?? null) === $hash, 'style_preview_changed');
        if (isset($journal['before'][$relative])) { $tree[$relative] = $journal['before'][$relative]; }
        else { unset($tree[$relative]); }
    }
    cart_probe_assert($tree === $journal['before'], 'style_unrelated_change');
}

function cart_style_remove(): array
{
    $r = cart_v5_root(); $root = '/home/www/carmaja-test-site';
    $j = cart_v5_read($r . '/website-style-journal.json'); cart_style_verify($j, true);
    foreach ($j['files'] as $relative => $_hash) {
        cart_probe_assert(cart_style_payload_path($relative, $j['before'], $j['slug'], $j['sku'])
            || (isset($j['originals'][$relative]) && cart_style_page($relative)), 'style_restore_path_invalid');
        $target = $root . '/' . $relative;
        if (isset($j['originals'][$relative])) {
            $bytes = base64_decode($j['originals'][$relative], true);
            cart_probe_assert(is_string($bytes) && hash_equals($j['before'][$relative], hash('sha256', $bytes)), 'style_backup_invalid');
            cart_v5_atomic($target, $bytes, 0644);
        } elseif (is_file($target)) { cart_probe_assert(!is_link($target) && unlink($target), 'style_remove_failed'); }
    }
    $dirs = $j['directories']; usort($dirs, static fn($a,$b) => strlen($b) <=> strlen($a));
    foreach ($dirs as $dir) {
        $path = $root . '/' . $dir;
        if (is_dir($path)) { cart_probe_assert(realpath($path) === $path && !is_link($path)
            && count(scandir($path)) === 2 && rmdir($path), 'style_directory_remove_failed'); }
    }
    cart_probe_assert(cart_v5_site_tree($root) === $j['before'], 'style_restore_mismatch');
    cart_v5_atomic($r . '/website-style-restored.json', '{"testOnly":true,"restored":true}', 0600);
    cart_v5_site_compare(cart_v5_read($r . '/website-preview-journal.json'));
    return ['status' => 'style_restored', 'previousPreviewHashEqual' => true];
}

function cart_style_install(): array
{
    $r = cart_v5_root(); $p = cart_v5_site_product(); $root = '/home/www/carmaja-test-site';
    cart_v5_site_compare(cart_v5_read($r . '/website-preview-journal.json'));
    $source = '/home/www/carmaja-test-deploy/incoming/cart-v5-style-20261015-export';
    $manifest = cart_v5_read('/home/www/carmaja-test-deploy/incoming/cart-v5-style-20261015-manifest.json');
    $all = cart_v5_site_tree($source); $before = cart_v5_site_tree($root);
    cart_probe_assert(($manifest['testOnly'] ?? false) === true && $manifest['files'] === $all
        && $manifest['productVersion'] === $p['productVersion'] && hash_equals($manifest['sourceHash'], $p['sourceHash']), 'style_manifest_invalid');
    $oldCss = cart_style_css((string) file_get_contents($root . '/index.html'));
    $newCss = cart_style_css((string) file_get_contents($source . '/index.html'));
    cart_probe_assert($oldCss !== $newCss && isset($all[ltrim($newCss, '/')]), 'style_css_missing');
    // The original pages and HANDY preview may reference different compiled stylesheet names.
    $oldStyles = [$oldCss => true];
    foreach ($before as $relative => $_hash) {
        if (cart_style_page($relative) && str_ends_with($relative, '.html')) {
            $css = cart_style_css((string) file_get_contents($root . '/' . $relative));
            cart_probe_assert(isset($before[ltrim($css, '/')]), 'style_existing_css_missing');
            $oldStyles[$css] = true;
        }
    }
    $contents = [];
    foreach ($all as $relative => $hash) {
        cart_probe_assert(cart_style_payload_path($relative, $before, $p['slug'], $p['sku']), 'style_payload_path_invalid');
        if (($before[$relative] ?? null) === $hash) { continue; }
        cart_probe_assert(!str_starts_with($relative, '_next/') || !isset($before[$relative]), 'style_asset_collision');
        $contents[$relative] = (string) file_get_contents($source . '/' . $relative);
        if (preg_match('#^(?:impressum|datenschutz|shopbedingungen|widerruf|versand-und-zahlung|legal-archive)/.*\.html$#D', $relative) === 1) {
            cart_probe_assert(isset($before[$relative]) && cart_style_legal_text((string) file_get_contents($root . '/' . $relative))
                === cart_style_legal_text($contents[$relative]), 'style_legal_text_changed');
        }
    }
    // Other pages receive only the stylesheet URL. Legal/product texts and their scripts stay intact.
    foreach ($before as $relative => $_hash) {
        if (isset($all[$relative]) || !cart_style_page($relative)) { continue; }
        $bytes = (string) file_get_contents($root . '/' . $relative);
        $styled = str_replace(array_keys($oldStyles), $newCss, $bytes);
        if ($styled !== $bytes) { $contents[$relative] = $styled; }
    }
    cart_probe_assert(isset($contents['index.html'], $contents['armbaender/index.html'],
        $contents['armbaender/' . $p['slug'] . '/index.html']), 'style_pages_missing');
    $files = []; $originals = []; $directories = [];
    foreach ($contents as $relative => $bytes) {
        $files[$relative] = hash('sha256', $bytes);
        if (isset($before[$relative])) { $originals[$relative] = base64_encode((string) file_get_contents($root . '/' . $relative)); }
        $dir = dirname($relative);
        while ($dir !== '.') { if (!is_dir($root . '/' . $dir)) { $directories[$dir] = true; } $dir = dirname($dir); }
    }
    $j = ['testOnly'=>true, 'slug'=>$p['slug'], 'sku'=>$p['sku'], 'before'=>$before,
        'files'=>$files, 'originals'=>$originals, 'directories'=>array_keys($directories)];
    $journalPath = $r . '/website-style-journal.json'; $restoredPath = $r . '/website-style-restored.json';
    if (is_file($journalPath)) {
        cart_probe_assert(is_file($restoredPath) && (cart_v5_read($restoredPath)['restored'] ?? false) === true
            && cart_v5_read($journalPath) === $j && unlink($restoredPath), 'style_reinstall_mismatch');
    } else { cart_v5_write($journalPath, $j); }
    try {
        foreach ($contents as $relative => $bytes) {
            $parent = $root;
            foreach (explode('/', dirname($relative)) as $part) {
                if ($part === '.') { continue; } $parent .= '/' . $part;
                if (!file_exists($parent)) { cart_probe_assert(mkdir($parent,0755) && chmod($parent,0755), 'style_mkdir_failed'); }
                cart_probe_assert(realpath($parent) === $parent && !is_link($parent), 'style_target_unsafe');
            }
            $target = $root . '/' . $relative;
            cart_probe_assert(!is_link($target) && (isset($before[$relative])
                ? hash_equals($before[$relative], hash_file('sha256',$target)) : !file_exists($target)), 'style_concurrent_change');
            cart_v5_atomic($target, $bytes, 0644);
        }
        cart_style_verify($j); cart_v5_site_product();
    } catch (Throwable $e) { cart_style_remove(); throw $e; }
    return ['status'=>'style_installed','files'=>count($files),'productsUnchanged'=>true,'privateRollback'=>true];
}

if (PHP_SAPI === 'cli' && realpath((string)($_SERVER['SCRIPT_FILENAME']??'')) === __FILE__) {
    ini_set('display_errors','0'); ini_set('log_errors','0'); umask(0077);
    try {
        $r = cart_v5_root(); $lock = fopen($r . '/website-style.lock', 'c');
        cart_probe_assert(is_resource($lock) && chmod($r . '/website-style.lock',0600)
            && flock($lock, LOCK_EX | LOCK_NB), 'style_locked');
        $result = match ($argv[1] ?? '') {
            'install' => cart_style_install(), 'remove' => cart_style_remove(),
            'verify' => (function() use ($r) { cart_v5_site_product(); cart_style_verify(cart_v5_read($r.'/website-style-journal.json'));
                return ['status'=>'style_verified','productsUnchanged'=>true]; })(),
            default => throw new RuntimeException('style_action_invalid'),
        };
        echo json_encode($result, JSON_THROW_ON_ERROR)."\n";
    } catch (Throwable $e) {
        $code = $e instanceof RuntimeException ? $e->getMessage() : 'style_safety_check_failed';
        if (preg_match('/^[a-z0-9_]{1,80}$/D',$code) !== 1) { $code = 'style_safety_check_failed'; }
        echo json_encode(['status'=>'failed','code'=>$code], JSON_THROW_ON_ERROR)."\n"; exit(1);
    }
}
