<?php

declare(strict_types=1);

// Separate from clicks.json: never reinterpret or rewrite historical page views.
const CDV_CONSENT = 'daily-visitors-v2';
const CDV_CONSENT_SECONDS = 180 * 86400;
const CDV_MAINTENANCE_SECONDS = 1800;
const CDV_MAX_BYTES = 16_000_000;

function cdv_day(?DateTimeImmutable $now = null): string
{
    return ($now ?? new DateTimeImmutable())->setTimezone(new DateTimeZone('Europe/Berlin'))->format('Y-m-d');
}

function cdv_expires_at(?DateTimeImmutable $now = null): int
{
    return ($now ?? new DateTimeImmutable())->setTimezone(new DateTimeZone('Europe/Berlin'))
        ->modify('tomorrow')->setTime(0, 0)->getTimestamp();
}

function cdv_has_consent(string $value, ?DateTimeImmutable $now = null): bool
{
    if (!preg_match('/^daily-visitors-v2\.allow\.(\d{10})$/D', $value, $match)) { return false; }
    $age = ($now ?? new DateTimeImmutable())->getTimestamp() - (int) $match[1];
    return $age >= 0 && $age < CDV_CONSENT_SECONDS;
}

function cdv_private_path(string $path, string $webroot): string
{
    $parent = realpath(dirname($path));
    $root = realpath($webroot);
    if ($parent === false || $root === false || !is_dir($parent) || is_link($path)
        || basename($path) === '.' || basename($path) === '..'
        || $parent === $root || str_starts_with($parent, $root . DIRECTORY_SEPARATOR)) {
        throw new RuntimeException('Visitor storage unavailable.');
    }
    return $parent . DIRECTORY_SEPARATOR . basename($path);
}

function cdv_config(string $webroot, bool $requireEnabled = true): ?array
{
    $path = getenv('CARMAJA_DAILY_VISITORS_CONFIG');
    if (!is_string($path) || $path === '') { return null; }
    $path = cdv_private_path($path, $webroot);
    if (!is_file($path) || (PHP_OS_FAMILY !== 'Windows' && (fileperms($path) & 0777) !== 0600)) {
        throw new RuntimeException('Visitor configuration unavailable.');
    }
    $config = require $path;
    if (!is_array($config)) { throw new RuntimeException('Visitor configuration unavailable.'); }
    if ($requireEnabled && (($config['enabled'] ?? false) !== true || ($config['privacyApproved'] ?? false) !== true)) { return null; }
    if (($config['consentVersion'] ?? null) !== CDV_CONSENT || !is_string($config['origin'] ?? null)
        || !preg_match('#^https://[a-z0-9.-]+$#D', $config['origin'])
        || !is_string($config['dataFile'] ?? null) || basename($config['dataFile']) !== 'daily-visitors.json') {
        throw new RuntimeException('Visitor configuration unavailable.');
    }
    $config['dataFile'] = cdv_private_path($config['dataFile'], $webroot);
    return $config;
}

function cdv_empty(): array
{
    return ['version' => 1, 'kind' => 'daily-visitors', 'days' => [], 'activeDay' => null, 'seen' => [], 'maintenanceAt' => null];
}

function cdv_read(string $path): array
{
    if (is_link($path)) { throw new RuntimeException('Visitor storage unavailable.'); }
    if (!file_exists($path)) { return cdv_empty(); }
    if (!is_file($path) || filesize($path) > CDV_MAX_BYTES) { throw new RuntimeException('Visitor storage unavailable.'); }
    $data = json_decode((string) @file_get_contents($path), true, 32, JSON_THROW_ON_ERROR);
    if (!is_array($data) || ($data['version'] ?? null) !== 1 || ($data['kind'] ?? null) !== 'daily-visitors'
        || !array_key_exists('activeDay', $data) || !is_array($data['days'] ?? null) || !is_array($data['seen'] ?? null)) {
        throw new RuntimeException('Visitor storage invalid.');
    }
    foreach ($data['days'] as $day => $count) {
        if (!is_string($day) || !preg_match('/^\d{4}-\d{2}-\d{2}$/D', $day) || !is_int($count) || $count < 0) {
            throw new RuntimeException('Visitor storage invalid.');
        }
    }
    if ($data['activeDay'] !== null && (!is_string($data['activeDay']) || !isset($data['days'][$data['activeDay']]))) {
        throw new RuntimeException('Visitor storage invalid.');
    }
    foreach ($data['seen'] as $key => $value) {
        if (!preg_match('/^[a-f0-9]{64}$/D', (string) $key) || $value !== true) { throw new RuntimeException('Visitor storage invalid.'); }
    }
    if (isset($data['maintenanceAt']) && (!is_int($data['maintenanceAt']) || $data['maintenanceAt'] < 0)) {
        throw new RuntimeException('Visitor maintenance invalid.');
    }
    return $data;
}

function cdv_maintenance_fresh(array $data, ?DateTimeImmutable $now = null): bool
{
    $time = ($now ?? new DateTimeImmutable())->getTimestamp();
    return isset($data['maintenanceAt']) && $data['maintenanceAt'] <= $time
        && $time - $data['maintenanceAt'] <= CDV_MAINTENANCE_SECONDS;
}

// Only our exact writer filenames; never recurse, follow links or touch backups.
function cdv_abandoned_writes(string $path): array
{
    $pattern = '/^' . preg_quote(basename($path), '/') . '\.tmp-[a-f0-9]{24}$/D';
    $paths = [];
    foreach (new DirectoryIterator(dirname($path)) as $entry) {
        if (!preg_match($pattern, $entry->getFilename())) { continue; }
        if ($entry->isLink() || !$entry->isFile()) { throw new RuntimeException('Visitor storage unavailable.'); }
        $paths[] = $entry->getPathname();
    }
    return $paths;
}

// Read-only and serialized with writers: a live temporary write is not an orphan.
function cdv_retention_healthy(string $path, ?DateTimeImmutable $now = null): bool
{
    if (is_link($path . '.lock')) { throw new RuntimeException('Visitor storage unavailable.'); }
    $lock = @fopen($path . '.lock', 'rb');
    if ($lock === false) { return false; }
    try {
        if (!flock($lock, LOCK_SH | LOCK_NB)) { return false; }
        $data = cdv_read($path);
        return cdv_maintenance_fresh($data, $now)
            && ($data['seen'] === [] || $data['activeDay'] === cdv_day($now))
            && cdv_abandoned_writes($path) === [];
    } finally { flock($lock, LOCK_UN); fclose($lock); }
}

// One lock and atomic replacement for counting and scheduled retention alike.
function cdv_update(string $path, callable $change): mixed
{
    if (is_link($path) || is_link($path . '.lock')) { throw new RuntimeException('Visitor storage unavailable.'); }
    $oldMask = umask(0077);
    $lock = null;
    $temporary = null;
    try {
        $lock = @fopen($path . '.lock', 'c');
        if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) { throw new RuntimeException('Visitor storage busy.'); }
        // With the exclusive lock held, any previous writer fragment is abandoned.
        foreach (cdv_abandoned_writes($path) as $abandoned) {
            if (!@unlink($abandoned)) { throw new RuntimeException('Visitor storage unavailable.'); }
        }
        $data = cdv_read($path);
        $result = $change($data);
        $json = json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        if (strlen($json) > CDV_MAX_BYTES) { throw new RuntimeException('Visitor storage limit reached.'); }
        $temporary = $path . '.tmp-' . bin2hex(random_bytes(12));
        $handle = @fopen($temporary, 'x');
        if ($handle === false) { throw new RuntimeException('Visitor storage unavailable.'); }
        try {
            if (@fwrite($handle, $json) !== strlen($json) || !@fflush($handle) || !@fsync($handle)) { throw new RuntimeException('Visitor storage unavailable.'); }
        } finally { fclose($handle); }
        if (!@rename($temporary, $path)) { throw new RuntimeException('Visitor storage unavailable.'); }
        return $result;
    } finally {
        if ($temporary !== null && is_file($temporary)) { unlink($temporary); }
        if (is_resource($lock)) { flock($lock, LOCK_UN); fclose($lock); }
        umask($oldMask);
    }
}

function cdv_maintain(string $path, ?DateTimeImmutable $now = null): void
{
    $now ??= new DateTimeImmutable();
    cdv_update($path, function (array &$data) use ($now): void {
        $day = cdv_day($now);
        if (($data['activeDay'] ?? '') > $day) { throw new RuntimeException('Visitor clock moved backwards.'); }
        if ($data['activeDay'] !== $day) {
            $data['seen'] = [];
            $data['activeDay'] = null; // Do not invent a measured zero for a day with no event.
        }
        $data['maintenanceAt'] = $now->getTimestamp();
    });
}

function cdv_record(string $path, string $day, string $token, ?DateTimeImmutable $now = null): bool
{
    $now ??= new DateTimeImmutable();
    if ($day !== cdv_day($now) || !preg_match('/^[a-f0-9]{64}$/D', $token)) {
        throw new InvalidArgumentException('Invalid daily visitor.');
    }
    return cdv_update($path, function (array &$data) use ($day, $token, $now): bool {
        if (!cdv_maintenance_fresh($data, $now)) { throw new RuntimeException('Visitor retention unavailable.'); }
        if (($data['activeDay'] ?? '') > $day) { throw new RuntimeException('Visitor clock moved backwards.'); }
        if ($data['activeDay'] !== $day) { $data['activeDay'] = $day; $data['seen'] = []; }
        $key = hash('sha256', $day . ':' . $token);
        if (isset($data['seen'][$key])) { return false; }
        if (count($data['seen']) >= 100_000) { throw new RuntimeException('Visitor daily limit reached.'); }
        $data['seen'][$key] = true;
        $data['days'][$day] = ($data['days'][$day] ?? 0) + 1;
        return true;
    });
}

// Reporting never exposes deduplication keys, and does not invent historical zeros.
function cdv_metric(array $data, array $days): array
{
    $known = array_intersect_key($data['days'], array_fill_keys($days, true));
    return ['value' => $known === [] ? null : array_sum($known),
        'status' => $known === [] ? 'missing' : 'partial'];
}
