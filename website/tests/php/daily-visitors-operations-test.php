<?php
declare(strict_types=1);

// Local artificial fixtures only: no IONOS, database, provider or mail connection.
require dirname(__DIR__, 2) . '/hosting/_internal/daily-visitors.php';
$checks = 0;
$skips = [];
function dvo_check(bool $condition, string $label): void {
    global $checks;
    if (!$condition) { throw new RuntimeException($label); }
    ++$checks;
}
function dvo_process(string $code, array $input): array {
    $process = proc_open([PHP_BINARY, '-d', 'display_errors=0', '-r', $code],
        [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes);
    if (!is_resource($process)) { throw new RuntimeException('Fixture process unavailable'); }
    fwrite($pipes[0], json_encode($input, JSON_THROW_ON_ERROR)); fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]); fclose($pipes[1]);
    $stderr = stream_get_contents($pipes[2]); fclose($pipes[2]);
    return [proc_close($process), $stdout, $stderr];
}
function dvo_remove(string $path): void {
    if (is_link($path) || !is_dir($path)) { unlink($path); return; }
    foreach (scandir($path) as $name) {
        if ($name !== '.' && $name !== '..') { dvo_remove($path . '/' . $name); }
    }
    rmdir($path);
}
$root = sys_get_temp_dir() . '/carmaja-daily-operations-' . bin2hex(random_bytes(8));
mkdir($root, 0700);
try {
    $webroot = $root . '/web'; mkdir($webroot . '/_internal', 0700, true);
    $library = realpath(dirname(__DIR__, 2) . '/hosting/_internal/daily-visitors.php');
    copy($library, $webroot . '/_internal/daily-visitors.php');
    $file = $root . '/daily-visitors.json';
    $configFile = $root . '/config.php';
    $config = ['enabled' => false, 'privacyApproved' => false, 'consentVersion' => CDV_CONSENT,
        'origin' => 'https://example.invalid', 'dataFile' => $file];
    file_put_contents($configFile, '<?php return ' . var_export($config, true) . ';'); chmod($configFile, 0600);
    $configBytes = file_get_contents($configFile);
    $legacy = $root . '/clicks.json'; file_put_contents($legacy, '{"artificial_legacy_count":123}');
    $legacyBytes = file_get_contents($legacy);
    $cli = realpath(dirname(__DIR__, 2) . '/scripts/daily-visitors-maintenance.php');
    $run = fn(string $action) => dvo_process(
        '$r=json_decode(stream_get_contents(STDIN),true);putenv("CARMAJA_DAILY_VISITORS_WEBROOT=".$r["webroot"]);putenv("CARMAJA_DAILY_VISITORS_CONFIG=".$r["config"]);$argv=[$r["cli"],$r["action"]];require $r["cli"];',
        ['webroot' => $webroot, 'config' => $configFile, 'cli' => $cli, 'action' => $action]);
    $success = "Daily visitor retention: OK\n";
    $failure = "Daily visitor retention: unavailable; check private configuration and scheduled cleanup.\n";

    dvo_check($run('status') === [1, '', $failure], 'Missing state must raise a machine-readable failure');
    dvo_check(!file_exists($file), 'Status must not initialize storage');
    dvo_check($run('prune') === [0, $success, ''], 'Cleanup works with both activation gates false');
    dvo_check($run('status') === [0, $success, ''], 'Healthy status');
    $now = new DateTimeImmutable();
    cdv_record($file, cdv_day($now), str_repeat('a', 64), $now);
    $before = file_get_contents($file);
    dvo_check($run('status') === [0, $success, ''] && file_get_contents($file) === $before, 'Status is read-only');

    // Model a process dying while holding the real writer lock, with a partial file.
    $fragment = $file . '.tmp-' . str_repeat('b', 24);
    [$exit, $stdout, $stderr] = dvo_process(
        '$r=json_decode(stream_get_contents(STDIN),true);require $r["library"];cdv_update($r["file"],function(&$data)use($r){file_put_contents($r["fragment"],json_encode($data));exit(73);});',
        ['library' => $library, 'file' => $file, 'fragment' => $fragment]);
    dvo_check($exit === 73 && $stdout === '' && $stderr === '', 'Abrupt writer exit has no diagnostic leakage');
    dvo_check(file_exists($fragment) && file_get_contents($file) === $before, 'Interrupted write preserves committed counts');
    dvo_check($run('status') === [1, '', $failure], 'Orphaned identifier fragment must alarm');
    $unrelated = $file . '.tmp-other'; file_put_contents($unrelated, 'unrelated fixture');
    dvo_check($run('prune') === [0, $success, ''], 'Cleanup after terminated writer');
    dvo_check(!file_exists($fragment), 'Abandoned identifier fragment is removed');
    dvo_check(file_get_contents($unrelated) === 'unrelated fixture', 'Cleanup never removes other private files');
    dvo_check(cdv_read($file)['days'] === json_decode($before, true)['days'], 'Crash recovery preserves committed aggregate counts');
    dvo_check(!cdv_record($file, cdv_day(), str_repeat('a', 64)), 'Recovery does not double count the same browser');
    dvo_check($run('status') === [0, $success, ''], 'Recovered status allows an independent observer to clear its alert');

    // Independent observer is represented by CLI exit codes, not a claimed email delivery.
    $stale = cdv_read($file); $stale['maintenanceAt'] = time() - CDV_MAINTENANCE_SECONDS - 1;
    file_put_contents($file, json_encode($stale));
    dvo_check($run('status') === [1, '', $failure], 'Stopped cleanup is detectable without website visits');
    $stable = file_get_contents($file);
    try { cdv_record($file, cdv_day(), str_repeat('c', 64)); throw new LogicException('Unexpected collection'); }
    catch (RuntimeException) { dvo_check(true, 'Overdue maintenance fails closed'); }
    dvo_check(file_get_contents($file) === $stable, 'No collection or data reset during an outage');
    dvo_check($run('prune') === [0, $success, ''] && $run('status') === [0, $success, ''], 'Independent recovery after outage');
    $lock = fopen($file . '.lock', 'c'); flock($lock, LOCK_EX);
    try {
        file_put_contents($fragment, 'writer still owns this artificial fragment');
        dvo_check($run('prune') === [1, '', $failure], 'Contended cron exits nonzero without data leakage');
        dvo_check(file_exists($fragment), 'A running writer fragment is never deleted');
        dvo_check($run('status') === [1, '', $failure], 'Busy status is read-only and cannot wait indefinitely');
    }
    finally { flock($lock, LOCK_UN); fclose($lock); }
    dvo_check($run('prune') === [0, $success, ''], 'Next cron attempt succeeds after lock release');
    dvo_check(!file_exists($fragment), 'Fragment removed only after writer released the lock');

    foreach (['2026-11-28T23:59:00+01:00', '2026-03-28T23:59:00+01:00', '2026-10-24T23:59:00+02:00'] as $date) {
        $stamp = new DateTimeImmutable($date);
        $fixture = $root . '/rollover-' . str_replace(':', '', $stamp->format('Ymd')) . '.json';
        cdv_maintain($fixture, $stamp); cdv_record($fixture, cdv_day($stamp), str_repeat('d', 64), $stamp);
        $counts = cdv_read($fixture)['days'];
        $next = $stamp->modify('tomorrow')->setTime(0, 15);
        cdv_maintain($fixture, $next); cdv_maintain($fixture, $next);
        dvo_check(cdv_read($fixture)['seen'] === [] && cdv_read($fixture)['days'] === $counts, 'Rollover without a visitor is repeatable and preserves counts');
    }
    [$exit, $out, $err] = $run('export-aggregates');
    $export = json_decode($out, true, 32, JSON_THROW_ON_ERROR);
    dvo_check($exit === 0 && $err === '' && array_keys($export) === ['version', 'kind', 'days'], 'Backup projection is an exact identifier-free allowlist');
    dvo_check(!str_contains($out, 'seen') && !str_contains($out, 'maintenanceAt') && !str_contains($out, 'activeDay'), 'No deduplication or per-request time in aggregate backup');
    $counts = cdv_read($file)['days']; file_put_contents($file, '{broken');
    dvo_check($run('status') === [1, '', $failure] && $run('prune') === [1, '', $failure], 'Corruption raises an alarm and is not repaired by resetting counters');
    dvo_check(file_get_contents($file) === '{broken', 'Corrupt file remains unchanged');
    // Recovery from a synthetic counts-only backup; never resurrect previous identifiers.
    file_put_contents($file, json_encode([...cdv_empty(), 'days' => $export['days']]));
    dvo_check($run('status') === [1, '', $failure], 'Restore needs maintenance before collection');
    dvo_check($run('prune') === [0, $success, ''] && cdv_read($file)['days'] === $counts && cdv_read($file)['seen'] === [], 'Counts-only restore retains history without visitor identifiers');

    // IONOS can retain a copy containing old identifiers for 14 days. Rehearse
    // its restore only in a separate synthetic file, never over live statistics.
    $providerFile = $root . '/provider-restore.json';
    $providerBackup = $root . '/provider-backup.json';
    $oldTime = (new DateTimeImmutable())->modify('-13 days');
    cdv_maintain($providerFile, $oldTime);
    cdv_record($providerFile, cdv_day($oldTime), str_repeat('e', 64), $oldTime);
    copy($providerFile, $providerBackup);
    $providerBytes = file_get_contents($providerBackup);
    $providerCounts = cdv_read($providerFile)['days'];
    copy($providerBackup, $providerFile);
    dvo_check(!cdv_retention_healthy($providerFile), 'Old provider restore is not healthy before cleanup');
    try { cdv_record($providerFile, cdv_day(), str_repeat('f', 64)); throw new LogicException('Unexpected restored collection'); }
    catch (RuntimeException) { dvo_check(true, 'Old provider restore cannot resume collection before maintenance'); }
    dvo_check(file_get_contents($providerFile) === $providerBytes, 'Rejected collection does not overwrite the restored counts');
    cdv_maintain($providerFile);
    dvo_check(cdv_read($providerFile)['seen'] === [] && cdv_read($providerFile)['days'] === $providerCounts, 'Provider restore cleanup removes old identifiers but preserves its aggregate history');
    dvo_check(cdv_retention_healthy($providerFile), 'Clean provider restore passes retention status');
    dvo_check(file_get_contents($providerBackup) === $providerBytes, 'Runtime cleanup does not claim to erase provider copies');

    // Inspect the real backup inventory method without credentials, commands or DB access.
    require_once dirname(__DIR__, 2) . '/test-api-private/program/production-backup.php';
    $products = $root . '/product-private'; mkdir($products . '/products', 0700, true);
    mkdir($products . '/dashboard-cache', 0700);
    file_put_contents($products . '/products/artificial.json', '{"artificial":true}');
    file_put_contents($products . '/dashboard-cache/daily-visitors.json', '{"seen":"never back up"}');
    $runtime = $root . '/artificial-runtime.php'; file_put_contents($runtime, '<?php return [];');
    $reflection = new ReflectionClass(CarmajaProductionBackup::class);
    $backup = $reflection->newInstanceWithoutConstructor();
    $reflection->getProperty('config')->setValue($backup, ['productPrivateDir' => $products, 'runtimeFile' => $runtime]);
    $inventory = $reflection->getMethod('productInventory')->invoke($backup);
    dvo_check(array_keys($inventory['files']) === ['products/artificial.json', 'runtime/runtime-config.php'], 'Product backup inventory excludes visitor state even in a non-allowlisted product subdirectory');
    dvo_check(!str_contains(json_encode($inventory), 'daily-visitors'), 'Only approved product paths and runtime are in inventory; not a provider-backup proof');

    if (PHP_OS_FAMILY !== 'Windows') {
        clearstatcache();
        dvo_check((fileperms($file) & 0777) === 0600, 'State file is private 0600');
        dvo_check((fileperms($file . '.lock') & 0777) === 0600, 'Lock is private 0600');
        chmod($configFile, 0644);
        dvo_check($run('status') === [1, '', $failure], 'World-readable configuration is rejected');
        chmod($configFile, 0600);
        $outside = $root . '/unrelated-private'; file_put_contents($outside, 'preserve');
        symlink($outside, $fragment);
        dvo_check($run('prune') === [1, '', $failure], 'Symlink fragment fails closed');
        dvo_check(file_get_contents($outside) === 'preserve' && is_link($fragment), 'Symlink target and link untouched');
        unlink($fragment);
    } else { $skips[] = 'POSIX permissions and symlinks require a Linux run'; }
    dvo_check(file_get_contents($configFile) === $configBytes, 'Private configuration unchanged');
    dvo_check(file_get_contents($legacy) === $legacyBytes, 'Existing statistics byte-identical');
} finally { dvo_remove($root); }
dvo_check(!file_exists($root), 'Synthetic fixture cleanup');
echo "Daily visitor operations: $checks checks passed.\n";
foreach ($skips as $skip) { echo "SKIP: $skip\n"; }
echo "Synthetic fixture cleanup: OK\n";
