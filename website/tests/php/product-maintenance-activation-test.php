<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/scripts/activate-product-maintenance-hotfix.php';
$root = str_replace('\\', '/', realpath($argv[1] ?? '') ?: '');
if ($root === '' || !str_starts_with(basename($root), 'carmaja-product-maintenance-')) throw new RuntimeException('fixture_required');
$package = $root . '/package';
$manifest = json_decode(file_get_contents($package . '/manifest.json'), true, 16, JSON_THROW_ON_ERROR);
$checks = 0;
function check(bool $value): void {
    global $checks;
    if (!$value) throw new RuntimeException('activation_assertion_failed');
    $checks++;
}
function fixture(string $root, string $name): string {
    $base = $root . '/' . $name;
    mkdir($base); mkdir($base . '/program'); mkdir($base . '/config');
    foreach (glob($root . '/original/*.php') as $file) copy($file, $base . '/program/' . basename($file));
    file_put_contents($base . '/config/runtime-config.php', '<?php return ["artificial" => true];');
    file_put_contents($base . '/program/untouched.php', '<?php // unrelated fixture');
    return $base;
}
function refuses(callable $fn, string $reason): void {
    try { $fn(); } catch (RuntimeException $error) { check($error->getMessage() === $reason); return; }
    throw new RuntimeException('expected_refusal_missing');
}

// Interrupt after every activated file, then resume; the maintenance guard stays closed.
$names = ['product-maintenance-guard.php', 'bootstrap.php', 'product-api.php', 'product-api-v2.php', 'product-api-v3.php', 'product-api-v4.php'];
foreach ($names as $index => $stop) {
    $base = fixture($root, 'interrupt-' . $index);
    $runtime = hash_file('sha256', $base . '/config/runtime-config.php');
    $unrelated = hash_file('sha256', $base . '/program/untouched.php');
    refuses(static fn () => cmj_hotfix_activate($base, $package, static function (string $name) use ($stop): void {
        if ($name === $stop) throw new RuntimeException('simulated_crash');
    }), 'simulated_crash');
    check(is_file($base . '/maintenance/product-cleanup.lock'));
    check(cmj_hotfix_activate($base, $package)['resumed'] === true);
    foreach ($manifest['files'] as $name => $entry) check(cmj_hotfix_hash($base . '/program/' . $name) === $entry['after']);
    check(cmj_hotfix_activate($base, $package)['status'] === 'installed_writes_closed');
    check(hash_file('sha256', $base . '/config/runtime-config.php') === $runtime);
    check(hash_file('sha256', $base . '/program/untouched.php') === $unrelated);
    check(count(glob($base . '/program/*.next-*')) === 0);
}
$base = fixture($root, 'foreign-edit');
file_put_contents($base . '/program/product-api-v4.php', '<?php // foreign edit');
refuses(static fn () => cmj_hotfix_activate($base, $package), 'baseline_mismatch');
check(!file_exists($base . '/maintenance/product-cleanup.lock'));
check(!file_exists($base . '/program/product-maintenance-guard.php'));
$base = fixture($root, 'foreign-marker');
mkdir($base . '/maintenance');
file_put_contents($base . '/maintenance/product-cleanup.lock', 'another operator');
refuses(static fn () => cmj_hotfix_activate($base, $package), 'foreign_marker');
check(file_get_contents($base . '/maintenance/product-cleanup.lock') === 'another operator');
check(!file_exists($base . '/program/product-maintenance-guard.php'));
$base = fixture($root, 'tampered-artifact');
$artifact = $package . '/product-api-v4.php';
$saved = file_get_contents($artifact);
try {
    file_put_contents($artifact, $saved . "\n// corrupted\n");
    refuses(static fn () => cmj_hotfix_activate($base, $package), 'artifact_mismatch');
    check(!file_exists($base . '/program/product-maintenance-guard.php'));
} finally { file_put_contents($artifact, $saved); }
echo "Activation, interrupted recovery, replay and tampering: $checks checks passed\n";
