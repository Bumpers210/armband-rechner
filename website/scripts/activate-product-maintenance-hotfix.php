<?php

declare(strict_types=1);

// This private operator command only stages the approved code fix and closes writes.
// It deliberately has no product repair, publication, schema or release command.
function cmj_hotfix_atomic(string $bytes, string $target, int $mode): void
{
    $temporary = $target . '.next-' . bin2hex(random_bytes(6));
    $handle = fopen($temporary, 'x');
    if ($handle === false) throw new RuntimeException('stage_failed');
    try {
        if (!chmod($temporary, $mode) || fwrite($handle, $bytes) !== strlen($bytes)
            || !fflush($handle) || !fsync($handle)) throw new RuntimeException('write_failed');
        fclose($handle);
        $handle = null;
        if (!rename($temporary, $target)) throw new RuntimeException('activate_failed');
        clearstatcache(true, $target);
    } finally {
        if (is_resource($handle)) fclose($handle);
        if (is_file($temporary)) unlink($temporary);
    }
}

function cmj_hotfix_hash(string $path): string
{
    if (!is_file($path) || is_link($path)) throw new RuntimeException('unsafe_file');
    return hash('sha256', str_replace("\r\n", "\n", (string) file_get_contents($path)));
}

function cmj_hotfix_activate(string $base, string $package, ?callable $checkpoint = null): array
{
    $names = ['product-maintenance-guard.php', 'bootstrap.php', 'product-api.php',
        'product-api-v2.php', 'product-api-v3.php', 'product-api-v4.php'];
    foreach ([$base, $base . '/program', $package] as $path) {
        if (str_replace('\\', '/', realpath($path) ?: '') !== str_replace('\\', '/', $path) || is_link($path)) throw new RuntimeException('unsafe_directory');
    }
    $manifest = json_decode((string) file_get_contents($package . '/manifest.json'), true, 16, JSON_THROW_ON_ERROR);
    $actualNames = array_keys($manifest['files'] ?? []);
    $expectedNames = $names;
    sort($actualNames); sort($expectedNames);
    if (($manifest['version'] ?? null) !== 1 || $actualNames !== $expectedNames) throw new RuntimeException('manifest_invalid');
    $release = hash('sha256', (string) file_get_contents($package . '/manifest.json'));
    $maintenance = $base . '/maintenance';
    if (!is_dir($maintenance) && !mkdir($maintenance, 0750)) throw new RuntimeException('maintenance_directory_failed');
    if (str_replace('\\', '/', realpath($maintenance) ?: '') !== str_replace('\\', '/', $maintenance) || is_link($maintenance)) throw new RuntimeException('unsafe_directory');
    if (is_link($maintenance . '/hotfix.lock')) throw new RuntimeException('unsafe_lock');
    $lock = fopen($maintenance . '/hotfix.lock', 'c');
    if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) throw new RuntimeException('hotfix_busy');
    try {
        $recovery = $maintenance . '/hashfix-' . substr($release, 0, 16);
        if (is_link($recovery) || is_link($recovery . '/prepared.json') || is_link($recovery . '/state.json')) throw new RuntimeException('unsafe_recovery');
        $resuming = is_file($recovery . '/prepared.json');
        if (file_exists($recovery) && !$resuming) throw new RuntimeException('incomplete_preparation');
        // Check all targets before replacing any program. Never overwrite foreign edits.
        foreach ($names as $name) {
            $entry = $manifest['files'][$name];
            if (!is_string($entry['after'] ?? null) || preg_match('/^[a-f0-9]{64}$/', $entry['after']) !== 1
                || !hash_equals($entry['after'], cmj_hotfix_hash($package . '/' . $name))) throw new RuntimeException('artifact_mismatch');
            $target = $base . '/program/' . $name;
            $actual = file_exists($target) || is_link($target) ? cmj_hotfix_hash($target) : null;
            if ($resuming) {
                if ($actual !== $entry['before'] && $actual !== $entry['after']) throw new RuntimeException('target_changed');
            } elseif ($actual !== $entry['before']) throw new RuntimeException('baseline_mismatch');
        }
        if (!$resuming) {
            if (!mkdir($recovery, 0700)) throw new RuntimeException('recovery_failed');
            foreach ($names as $name) {
                if ($manifest['files'][$name]['before'] !== null) {
                    cmj_hotfix_atomic((string) file_get_contents($base . '/program/' . $name), $recovery . '/' . $name, 0600);
                    if (!hash_equals($manifest['files'][$name]['before'], cmj_hotfix_hash($recovery . '/' . $name))) throw new RuntimeException('recovery_mismatch');
                }
            }
            cmj_hotfix_atomic(json_encode(['release' => $release, 'manifest' => $manifest], JSON_THROW_ON_ERROR), $recovery . '/prepared.json', 0600);
        } else {
            $prepared = json_decode((string) file_get_contents($recovery . '/prepared.json'), true, 16, JSON_THROW_ON_ERROR);
            if (($prepared['release'] ?? '') !== $release || ($prepared['manifest'] ?? []) !== $manifest) throw new RuntimeException('recovery_mismatch');
            foreach ($names as $name) if ($manifest['files'][$name]['before'] !== null
                && !hash_equals($manifest['files'][$name]['before'], cmj_hotfix_hash($recovery . '/' . $name))) throw new RuntimeException('recovery_mismatch');
        }
        $marker = $maintenance . '/product-cleanup.lock';
        $markerValue = "product-cleanup-v1\n";
        if (is_link($marker) || (file_exists($marker) && file_get_contents($marker) !== $markerValue)) throw new RuntimeException('foreign_marker');
        if (!is_file($marker)) cmj_hotfix_atomic($markerValue, $marker, 0640);
        // The guard is installed before its callers. Bootstrap closes HTTP first.
        // A failed/interrupted activation keeps this marker and resumes forward.
        foreach ($names as $name) {
            $target = $base . '/program/' . $name;
            if (!is_file($target) || cmj_hotfix_hash($target) !== $manifest['files'][$name]['after']) {
                cmj_hotfix_atomic((string) file_get_contents($package . '/' . $name), $target, 0640);
            }
            if (!hash_equals($manifest['files'][$name]['after'], cmj_hotfix_hash($target))) throw new RuntimeException('activated_file_mismatch');
            if (function_exists('opcache_invalidate')) opcache_invalidate($target, true);
            cmj_hotfix_atomic(json_encode(['lastFile' => $name, 'status' => 'activating'], JSON_THROW_ON_ERROR), $recovery . '/state.json', 0600);
            if ($checkpoint !== null) $checkpoint($name);
        }
        cmj_hotfix_atomic(json_encode(['status' => 'installed_writes_closed', 'at' => gmdate('c')], JSON_THROW_ON_ERROR), $recovery . '/state.json', 0600);
        return ['status' => 'installed_writes_closed', 'programs' => count($names), 'resumed' => $resuming];
    } finally {
        flock($lock, LOCK_UN); fclose($lock);
    }
}

if (PHP_SAPI === 'cli' && realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    ini_set('display_errors', '0');
    set_error_handler(static function (): never { throw new RuntimeException('runtime_warning'); });
    try {
        $base = '/home/www/carmaja-private-shop';
        $package = $argv[1] ?? '';
        if (preg_match('#^/home/www/carmaja-private-shop/\.hashfix-stage-[a-f0-9]{16}$#', $package) !== 1) throw new RuntimeException('stage_path_invalid');
        $runtime = $base . '/config/runtime-config.php';
        if (!is_file($runtime) || is_link($runtime) || (fileperms($runtime) & 0777) !== 0600) throw new RuntimeException('runtime_invalid');
        $config = require $runtime;
        if (($config['environment'] ?? '') !== 'production' || ($config['productPrivateDir'] ?? '') !== '/home/www/carmaja-private-production') throw new RuntimeException('environment_invalid');
        $runtimeBefore = hash_file('sha256', $runtime);
        foreach (['product-maintenance-guard.php', 'bootstrap.php', 'product-api.php', 'product-api-v2.php', 'product-api-v3.php', 'product-api-v4.php'] as $name) {
            $process = proc_open(['/usr/bin/php8.4', '-l', $package . '/' . $name], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            if (!is_resource($process)) throw new RuntimeException('lint_failed');
            fclose($pipes[0]); stream_get_contents($pipes[1]); stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]);
            if (proc_close($process) !== 0) throw new RuntimeException('lint_failed');
        }
        $result = cmj_hotfix_activate($base, $package);
        if (!hash_equals($runtimeBefore, hash_file('sha256', $runtime))) throw new RuntimeException('runtime_changed');
        $result['runtimeUnchanged'] = true;
        echo json_encode($result, JSON_THROW_ON_ERROR), "\n";
    } catch (Throwable) {
        fwrite(STDERR, "{\"status\":\"failed_safely_inspect_stage\"}\n");
        exit(1);
    }
}
