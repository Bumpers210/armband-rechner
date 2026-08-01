<?php

declare(strict_types=1);

const CARMAJA_STATS_VERSION = 2;
const CARMAJA_TARGETS = ['vinted', 'instagram'];
const CARMAJA_POSITIONS = ['hero', 'gallery', 'contact', 'footer', 'product'];
const CARMAJA_PRODUCT_SLUG_PATTERN = '/^cp-\d{4}-\d{4}$/';

function carmaja_empty_stats(): array
{
    return [
        'version' => CARMAJA_STATS_VERSION,
        'days' => [],
        'months' => [],
    ];
}

function carmaja_stats_file_path(): string
{
    $configuredPath = getenv('CARMAJA_STATS_FILE');

    if (is_string($configuredPath) && trim($configuredPath) !== '') {
        return trim($configuredPath);
    }

    return dirname(__DIR__) . DIRECTORY_SEPARATOR
        . 'private-data' . DIRECTORY_SEPARATOR
        . 'clicks.json';
}

function carmaja_product_pages_root(): string
{
    $configuredPath = getenv('CARMAJA_PRODUCT_PAGES_DIR');

    if (is_string($configuredPath) && trim($configuredPath) !== '') {
        return trim($configuredPath);
    }

    return dirname(__DIR__) . DIRECTORY_SEPARATOR . 'armbaender';
}

function carmaja_non_negative_count(mixed $value): int
{
    return is_int($value) || is_float($value) || is_string($value)
        ? max(0, (int) $value)
        : 0;
}

function carmaja_normalize_bucket(mixed $value): array
{
    if (!is_array($value)) {
        return [];
    }

    $bucket = [];

    foreach (CARMAJA_TARGETS as $target) {
        $positions = $value[$target] ?? null;

        if (!is_array($positions)) {
            continue;
        }

        foreach (CARMAJA_POSITIONS as $position) {
            $count = carmaja_non_negative_count($positions[$position] ?? 0);

            if ($count > 0) {
                $bucket[$target][$position] = $count;
            }
        }
    }

    $products = $value['products'] ?? null;

    if (is_array($products)) {
        foreach ($products as $slug => $count) {
            if (!is_string($slug) || !preg_match(CARMAJA_PRODUCT_SLUG_PATTERN, $slug)) {
                continue;
            }

            $normalizedCount = carmaja_non_negative_count($count);

            if ($normalizedCount > 0) {
                $bucket['products'][$slug] = $normalizedCount;
            }
        }
    }

    return $bucket;
}

function carmaja_decode_stats(string $json): array
{
    if (trim($json) === '') {
        return carmaja_empty_stats();
    }

    $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

    if (!is_array($decoded)) {
        throw new RuntimeException('Ungueltiges Statistikformat.');
    }

    $stats = carmaja_empty_stats();

    foreach (['days' => '/^\d{4}-\d{2}-\d{2}$/', 'months' => '/^\d{4}-\d{2}$/'] as $period => $pattern) {
        $values = $decoded[$period] ?? [];

        if (!is_array($values)) {
            continue;
        }

        foreach ($values as $key => $bucket) {
            if (!is_string($key) || !preg_match($pattern, $key)) {
                continue;
            }

            $normalizedBucket = carmaja_normalize_bucket($bucket);

            if ($normalizedBucket !== []) {
                $stats[$period][$key] = $normalizedBucket;
            }
        }
    }

    ksort($stats['days']);
    ksort($stats['months']);

    return $stats;
}

function carmaja_increment_bucket(
    array &$bucket,
    string $target,
    string $position,
    ?string $productSlug = null,
    int $amount = 1,
): void {
    if (!in_array($target, CARMAJA_TARGETS, true)
        || !in_array($position, CARMAJA_POSITIONS, true)
        || $amount <= 0) {
        return;
    }

    $bucket[$target][$position] = carmaja_non_negative_count(
        $bucket[$target][$position] ?? 0,
    ) + $amount;

    if ($position === 'product'
        && $target === 'vinted'
        && is_string($productSlug)
        && preg_match(CARMAJA_PRODUCT_SLUG_PATTERN, $productSlug)) {
        $bucket['products'][$productSlug] = carmaja_non_negative_count(
            $bucket['products'][$productSlug] ?? 0,
        ) + $amount;
    }
}

function carmaja_merge_bucket(array &$destination, array $source): void
{
    $source = carmaja_normalize_bucket($source);

    foreach (CARMAJA_TARGETS as $target) {
        foreach (CARMAJA_POSITIONS as $position) {
            $amount = carmaja_non_negative_count($source[$target][$position] ?? 0);

            if ($amount > 0) {
                carmaja_increment_bucket($destination, $target, $position, null, $amount);
            }
        }
    }

    foreach ($source['products'] ?? [] as $slug => $amount) {
        if (is_string($slug) && $amount > 0) {
            $destination['products'][$slug] = carmaja_non_negative_count(
                $destination['products'][$slug] ?? 0,
            ) + $amount;
        }
    }
}

function carmaja_archive_expired_days(array &$stats, DateTimeImmutable $today): void
{
    $cutoff = $today->modify('-12 months')->format('Y-m-d');

    foreach ($stats['days'] as $date => $bucket) {
        if ($date >= $cutoff) {
            continue;
        }

        $month = substr($date, 0, 7);
        $stats['months'][$month] ??= [];
        carmaja_merge_bucket($stats['months'][$month], $bucket);
        unset($stats['days'][$date]);
    }

    ksort($stats['days']);
    ksort($stats['months']);
}

function carmaja_lock_file_path(string $statsPath): string
{
    return $statsPath . '.lock';
}

function carmaja_with_stats_lock(string $statsPath, int $lockType, callable $callback): mixed
{
    $directory = dirname($statsPath);

    if (!is_dir($directory)
        && !mkdir($directory, 0750, true)
        && !is_dir($directory)) {
        throw new RuntimeException('Statistikverzeichnis nicht verfuegbar.');
    }

    $lockHandle = fopen(carmaja_lock_file_path($statsPath), 'c');

    if ($lockHandle === false) {
        throw new RuntimeException('Statistiksperre nicht verfuegbar.');
    }

    @chmod(carmaja_lock_file_path($statsPath), 0640);

    try {
        if (!flock($lockHandle, $lockType)) {
            throw new RuntimeException('Statistikdatei konnte nicht gesperrt werden.');
        }

        return $callback();
    } finally {
        flock($lockHandle, LOCK_UN);
        fclose($lockHandle);
    }
}

function carmaja_write_stats_atomically(string $path, array $stats): void
{
    $encoded = json_encode(
        $stats,
        JSON_PRETTY_PRINT
            | JSON_UNESCAPED_SLASHES
            | JSON_UNESCAPED_UNICODE
            | JSON_THROW_ON_ERROR,
    ) . PHP_EOL;
    $temporaryPath = tempnam(dirname($path), '.clicks-');

    if ($temporaryPath === false) {
        throw new RuntimeException('Statistikdatei konnte nicht vorbereitet werden.');
    }

    try {
        $temporaryHandle = fopen($temporaryPath, 'wb');

        if ($temporaryHandle === false) {
            throw new RuntimeException('Statistikdatei konnte nicht geoeffnet werden.');
        }

        try {
            $written = fwrite($temporaryHandle, $encoded);

            if ($written !== strlen($encoded) || !fflush($temporaryHandle)) {
                throw new RuntimeException('Statistikdatei konnte nicht geschrieben werden.');
            }

            if (function_exists('fsync') && !fsync($temporaryHandle)) {
                throw new RuntimeException('Statistikdatei konnte nicht gesichert werden.');
            }
        } finally {
            fclose($temporaryHandle);
        }

        chmod($temporaryPath, 0640);

        if (!rename($temporaryPath, $path)) {
            throw new RuntimeException('Statistikdatei konnte nicht aktiviert werden.');
        }
    } finally {
        if (is_file($temporaryPath)) {
            @unlink($temporaryPath);
        }
    }
}

function carmaja_record_click(string $target, string $position, ?string $productSlug = null): void
{
    $statsPath = carmaja_stats_file_path();

    carmaja_with_stats_lock($statsPath, LOCK_EX, static function () use ($statsPath, $target, $position, $productSlug): void {
        $contents = is_file($statsPath) ? file_get_contents($statsPath) : '';
        $stats = carmaja_decode_stats(is_string($contents) ? $contents : '');
        $today = new DateTimeImmutable('today', new DateTimeZone('Europe/Berlin'));
        $todayKey = $today->format('Y-m-d');

        carmaja_archive_expired_days($stats, $today);
        $stats['days'][$todayKey] ??= [];
        carmaja_increment_bucket($stats['days'][$todayKey], $target, $position, $productSlug);
        carmaja_write_stats_atomically($statsPath, $stats);
    });
}

function carmaja_read_stats(): array
{
    $statsPath = carmaja_stats_file_path();

    return carmaja_with_stats_lock($statsPath, LOCK_SH, static function () use ($statsPath): array {
        if (!is_file($statsPath)) {
            return carmaja_empty_stats();
        }

        $contents = file_get_contents($statsPath);

        return carmaja_decode_stats(is_string($contents) ? $contents : '');
    });
}

function carmaja_bucket_total(array $bucket, ?string $target = null, ?string $position = null): int
{
    $total = 0;

    foreach (CARMAJA_TARGETS as $candidateTarget) {
        if ($target !== null && $target !== $candidateTarget) {
            continue;
        }

        foreach (CARMAJA_POSITIONS as $candidatePosition) {
            if ($position !== null && $position !== $candidatePosition) {
                continue;
            }

            $total += carmaja_non_negative_count(
                $bucket[$candidateTarget][$candidatePosition] ?? 0,
            );
        }
    }

    return $total;
}

function carmaja_bucket_product_total(array $bucket, string $slug): int
{
    return carmaja_non_negative_count($bucket['products'][$slug] ?? 0);
}

function carmaja_is_published_product_slug(string $slug): bool
{
    if (!preg_match(CARMAJA_PRODUCT_SLUG_PATTERN, $slug)) {
        return false;
    }

    $root = realpath(carmaja_product_pages_root());

    if ($root === false || !is_dir($root)) {
        return false;
    }

    $page = realpath($root . DIRECTORY_SEPARATOR . $slug . DIRECTORY_SEPARATOR . 'index.html');

    if ($page === false
        || !is_file($page)
        || !str_starts_with($page, $root . DIRECTORY_SEPARATOR)) {
        return false;
    }

    $markup = file_get_contents($page, false, null, 0, 1_000_000);

    if (!is_string($markup)) {
        return false;
    }

    $escapedSlug = preg_quote($slug, '/');
    $pattern = '/click\\.php\\?target=vinted(?:&|&amp;)position=product(?:&|&amp;)product='
        . $escapedSlug
        . '(?:["&]|&amp;)/';

    return preg_match($pattern, $markup) === 1;
}
