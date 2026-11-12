<?php

declare(strict_types=1);

// Code-only cold start. No repository API/fixture preloads, runtime or database.
ini_set('display_errors', '0');
ini_set('log_errors', '0');
ini_set('zend.exception_ignore_args', '1');
umask(0077);
set_exception_handler(static function (Throwable $error): never {
    $label = $error->getMessage();
    fwrite(STDERR, 'PACKAGE_START_TEST_FAILED: ' . (preg_match('/^[a-z_]+$/D', $label) ? $label : 'private_test_failure') . "\n");
    exit(2);
});

function package_check(bool $ok, string $label): void
{
    if (!$ok) throw new RuntimeException($label);
}

if (($argv[1] ?? '') === '--child') {
    try {
        $tool = str_replace('\\', '/', realpath($argv[2]) ?: '');
        $mode = $argv[3] ?? '';
        package_check(!function_exists('carmaja_api_canonicalize') && !class_exists('CarmajaCommerceException', false), 'not_a_cold_process');
        require $tool . '/product-model4-production.php';
        $release = json_decode(file_get_contents($tool . '/release.json'), true, 64, JSON_THROW_ON_ERROR);
        try {
            cmj_m4p_load_dependencies($tool, $release);
        } catch (Throwable $error) {
            package_check(in_array($mode, ['missing', 'tampered', 'unbound'], true), 'complete_package_did_not_start');
            package_check(in_array($error->getMessage(), ['unsafe_path', 'release_file_set'], true), 'unexpected_refusal');
            package_check(!function_exists('carmaja_api_canonicalize') && !class_exists('CarmajaCommerceException', false), 'dependency_executed_before_validation');
            echo "PACKAGE_REFUSED_BEFORE_RUNTIME\n";
            exit(0);
        }
        package_check($mode === 'valid', 'broken_package_accepted');
        package_check(class_exists('CarmajaCommerceException', false)
            && class_exists('CarmajaCommercePdo', false)
            && class_exists('CarmajaProductionBackup', false)
            && class_exists('CarmajaModel4ProductionCaller', false), 'missing_package_class');
        // Same canonicalization path used to prepare real model-3 -> model-4 plans.
        $draft = [
            'draftId' => '10000000-0000-4000-8000-000000000002', 'version' => 4,
            'productVersion' => 3, 'productModelVersion' => 3, 'name' => 'Artificial bracelet',
            'shortDescription' => 'Artificial text', 'descriptionDocument' => ['version' => 1,
                'blocks' => [['type' => 'paragraph', 'spans' => [['text' => 'Artificial text',
                    'bold' => false, 'italic' => false, 'font' => 'standard', 'size' => 'normal']]]]],
            'materials' => ['Natural stone'], 'metalElements' => [], 'braceletSizeCm' => 18,
            'pearlSizeMm' => 8, 'careInstructions' => ['Keep dry'], 'priceMinor' => 3250,
            'currency' => 'eur', 'salesEnabled' => false, 'status' => 'draft',
            'updatedAt' => '2026-11-11T00:00:00Z',
            'imageManifest' => [['imageId' => '20000000-0000-4000-8000-000000000001',
                'fileName' => '01.jpg', 'width' => 8, 'height' => 6, 'alt' => 'Artificial', 'isMain' => true]],
        ];
        $draft['sourceHash'] = carmaja_api_v3_product_from_draft($draft)['sourceHash'];
        $before = $draft;
        $candidate = cmj_model4_candidate($draft, '2026-11-12T00:00:00Z');
        package_check($draft === $before && $candidate['productModelVersion'] === 4
            && $candidate['productVersion'] === 4 && $candidate['version'] === 5
            && $candidate['noticePolicyId'] === null
            && $candidate['sourceHash'] !== $draft['sourceHash'], 'candidate_contract');
        package_check(carmaja_cart_quantity([['productId' => $draft['draftId'], 'quantity' => 2]]) === 2, 'cart_dependency');
        // Every include must come from the isolated artifact, never the repository.
        $loaded = [];
        foreach (get_included_files() as $file) {
            if (realpath($file) === realpath(__FILE__)) continue;
            $file = str_replace('\\', '/', realpath($file) ?: '');
            package_check(dirname($file) === $tool, 'include_outside_package');
            $loaded[] = basename($file);
        }
        sort($loaded); $expected = CMJ_M4P_TOOL_FILES; sort($expected);
        package_check($loaded === $expected && count($loaded) === 9, 'incomplete_dependency_closure');
        echo "PACKAGE_COLD_START_OK\n";
        exit(0);
    } catch (Throwable) {
        fwrite(STDERR, "PACKAGE_COLD_START_FAILED\n");
        exit(2);
    }
}

package_check(PHP_MAJOR_VERSION === 8 && PHP_MINOR_VERSION === 4 && extension_loaded('mbstring'), 'php84_mbstring_required');
$source = realpath($argv[1] ?? '');
package_check(is_string($source) && is_file($source . '/release.json'), 'built_package_required');
$root = sys_get_temp_dir() . '/cmj-model4-cold-' . bin2hex(random_bytes(8));
package_check(mkdir($root, 0700), 'temporary_directory');
$checks = 0;
try {
    foreach (['valid', 'missing', 'tampered', 'unbound'] as $mode) {
        $tool = $root . '/' . $mode;
        package_check(mkdir($tool, 0700), 'temporary_package');
        foreach (scandir($source) as $name) {
            if ($name === '.' || $name === '..') continue;
            package_check(!is_link($source . '/' . $name) && is_file($source . '/' . $name), 'code_files_only');
            package_check(copy($source . '/' . $name, $tool . '/' . $name) && chmod($tool . '/' . $name, 0600), 'package_copy');
        }
        if ($mode === 'missing') unlink($tool . '/commerce-core.php');
        if ($mode === 'tampered') file_put_contents($tool . '/commerce-core.php', "\n/* artificial tamper */\n", FILE_APPEND);
        if ($mode === 'unbound') {
            $release = json_decode(file_get_contents($tool . '/release.json'), true, 64, JSON_THROW_ON_ERROR);
            unset($release['toolFiles']['commerce-core.php']);
            file_put_contents($tool . '/release.json', json_encode($release, JSON_THROW_ON_ERROR));
        }
        $filesBefore = array_map(static fn ($name) => [$name, is_file($tool . '/' . $name) ? hash_file('sha256', $tool . '/' . $name) : null], scandir($tool));
        // Fresh process, no auto_prepend/config/bootstrap and no inherited fixture classes.
        $process = proc_open([PHP_BINARY, '-n', '-d', 'extension_dir=' . ini_get('extension_dir'), '-d', 'extension=mbstring',
            __FILE__, '--child', $tool, $mode], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $tool);
        package_check(is_resource($process), 'child_start');
        fclose($pipes[0]); $out = stream_get_contents($pipes[1]); $err = stream_get_contents($pipes[2]);
        fclose($pipes[1]); fclose($pipes[2]); $exit = proc_close($process);
        $expected = $mode === 'valid' ? "PACKAGE_COLD_START_OK\n" : "PACKAGE_REFUSED_BEFORE_RUNTIME\n";
        package_check($exit === 0 && $out === $expected && $err === '', 'cold_start_' . $mode);
        $filesAfter = array_map(static fn ($name) => [$name, is_file($tool . '/' . $name) ? hash_file('sha256', $tool . '/' . $name) : null], scandir($tool));
        package_check($filesAfter === $filesBefore, 'no_runtime_or_data_mutation');
        echo "PASS package_cold_start_$mode\n"; $checks++;
    }
    // Exercise the actual packaged entry as well: an unapproved local location
    // must never fall back to a runtime, database or production action.
    $process = proc_open([PHP_BINARY, '-n', $root . '/valid/product-model4-production.php',
        'preflight', 'model4-artificial-cold-start', str_repeat('a', 64)],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $root . '/valid');
    package_check(is_resource($process), 'cli_start');
    fclose($pipes[0]); $out = stream_get_contents($pipes[1]); $err = stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]); $exit = proc_close($process);
    package_check($exit === 2 && $out === '' && $err === "MODEL4_REFUSED: private prerequisites or bound action failed; maintenance remains closed.\n", 'cli_refusal_and_redaction');
    package_check(!file_exists($root . '/valid/runtime-config.php') && !file_exists($root . '/valid/state.json'), 'cli_no_fallback');
    echo "PASS packaged_cli_refuses_unapproved_location\n"; $checks++;
} finally {
    // Only the freshly created, owned, non-symlink package copies; never source or runtime.
    foreach (scandir($root) as $name) {
        if ($name === '.' || $name === '..') continue;
        $dir = $root . '/' . $name;
        package_check(!is_link($dir) && is_dir($dir), 'cleanup_directory');
        foreach (scandir($dir) as $file) {
            if ($file === '.' || $file === '..') continue;
            package_check(!is_link($dir . '/' . $file) && is_file($dir . '/' . $file), 'cleanup_file');
            unlink($dir . '/' . $file);
        }
        rmdir($dir);
    }
    rmdir($root);
}
package_check(!file_exists($root), 'cleanup_complete');
echo "$checks isolated package starts passed; artificial data only; cleanup complete.\n";
