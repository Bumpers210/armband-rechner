<?php

declare(strict_types=1);

const CARMAJA_TARGETS = ['instagram'];
const CARMAJA_POSITIONS = ['hero', 'gallery', 'contact', 'footer'];

function carmaja_empty_stats(): array
{
    return [
        'version' => 2,
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

function carmaja_decode_stats(string $json): array
{
    if (trim($json) === '') {
        return carmaja_empty_stats();
    }

    $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

    if (!is_array($decoded)) {
        throw new RuntimeException('Ungültiges Statistikformat.');
    }

    $decoded['version'] = 2;
    $decoded['days'] = is_array($decoded['days'] ?? null)
        ? $decoded['days']
        : [];
    $decoded['months'] = is_array($decoded['months'] ?? null)
        ? $decoded['months']
        : [];

    return $decoded;
}

function carmaja_increment_bucket(
    array &$bucket,
    string $target,
    string $position,
    int $amount = 1
): void {
    if (!in_array($target, CARMAJA_TARGETS, true)) {
        return;
    }

    if (!in_array($position, CARMAJA_POSITIONS, true)) {
        return;
    }

    if (!isset($bucket[$target]) || !is_array($bucket[$target])) {
        $bucket[$target] = [];
    }

    $currentValue = $bucket[$target][$position] ?? 0;
    $bucket[$target][$position] = max(0, (int) $currentValue) + $amount;
}

function carmaja_merge_bucket(array &$destination, array $source): void
{
    foreach (CARMAJA_TARGETS as $target) {
        foreach (CARMAJA_POSITIONS as $position) {
            $amount = (int) ($source[$target][$position] ?? 0);

            if ($amount > 0) {
                carmaja_increment_bucket(
                    $destination,
                    $target,
                    $position,
                    $amount
                );
            }
        }
    }
}

function carmaja_archive_expired_days(
    array &$stats,
    DateTimeImmutable $today
): void {
    $cutoff = $today->modify('-12 months')->format('Y-m-d');

    foreach ($stats['days'] as $date => $bucket) {
        if (!is_string($date) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            unset($stats['days'][$date]);
            continue;
        }

        if ($date >= $cutoff) {
            continue;
        }

        $month = substr($date, 0, 7);

        if (!isset($stats['months'][$month])
            || !is_array($stats['months'][$month])) {
            $stats['months'][$month] = [];
        }

        if (is_array($bucket)) {
            carmaja_merge_bucket($stats['months'][$month], $bucket);
        }

        unset($stats['days'][$date]);
    }

    ksort($stats['days']);
    ksort($stats['months']);
}

function carmaja_record_click(
    string $target,
    string $position
): void {
    date_default_timezone_set('Europe/Berlin');

    $path = carmaja_stats_file_path();
    $directory = dirname($path);

    if (!is_dir($directory)
        && !mkdir($directory, 0750, true)
        && !is_dir($directory)) {
        throw new RuntimeException('Statistikverzeichnis nicht verfügbar.');
    }

    $handle = fopen($path, 'c+');

    if ($handle === false) {
        throw new RuntimeException('Statistikdatei nicht verfügbar.');
    }

    try {
        if (!flock($handle, LOCK_EX)) {
            throw new RuntimeException('Statistikdatei konnte nicht gesperrt werden.');
        }

        rewind($handle);
        $contents = stream_get_contents($handle);
        $stats = carmaja_decode_stats(
            is_string($contents) ? $contents : ''
        );
        $today = new DateTimeImmutable('today');
        $date = $today->format('Y-m-d');

        carmaja_archive_expired_days($stats, $today);

        if (!isset($stats['days'][$date])
            || !is_array($stats['days'][$date])) {
            $stats['days'][$date] = [];
        }

        carmaja_increment_bucket(
            $stats['days'][$date],
            $target,
            $position,
            1
        );

        $encoded = json_encode(
            $stats,
            JSON_PRETTY_PRINT
                | JSON_UNESCAPED_SLASHES
                | JSON_UNESCAPED_UNICODE
                | JSON_THROW_ON_ERROR
        );

        rewind($handle);
        ftruncate($handle, 0);
        fwrite($handle, $encoded . PHP_EOL);
        fflush($handle);
        flock($handle, LOCK_UN);
    } finally {
        fclose($handle);
    }
}

function carmaja_read_stats(): array
{
    $path = carmaja_stats_file_path();

    if (!is_file($path)) {
        return carmaja_empty_stats();
    }

    $handle = fopen($path, 'r');

    if ($handle === false) {
        throw new RuntimeException('Statistikdatei nicht lesbar.');
    }

    try {
        if (!flock($handle, LOCK_SH)) {
            throw new RuntimeException('Statistikdatei konnte nicht gesperrt werden.');
        }

        $contents = stream_get_contents($handle);
        flock($handle, LOCK_UN);

        return carmaja_decode_stats(
            is_string($contents) ? $contents : ''
        );
    } finally {
        fclose($handle);
    }
}

function carmaja_bucket_total(
    array $bucket,
    ?string $target = null,
    ?string $position = null
): int {
    $total = 0;

    foreach (CARMAJA_TARGETS as $candidateTarget) {
        if ($target !== null && $candidateTarget !== $target) {
            continue;
        }

        foreach (CARMAJA_POSITIONS as $candidatePosition) {
            if ($position !== null && $candidatePosition !== $position) {
                continue;
            }

            $total += max(
                0,
                (int) ($bucket[$candidateTarget][$candidatePosition] ?? 0)
            );
        }
    }

    return $total;
}
