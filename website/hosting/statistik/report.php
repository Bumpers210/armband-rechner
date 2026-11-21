<?php

declare(strict_types=1);

// Loaded only after the dashboard's authentication check.
if (!defined('CARMAJA_STATISTICS_CONTEXT')) {
    http_response_code(404);
    exit;
}

const CS_LABELS = [
    'missing' => 'Nicht erfasst', 'disconnected' => 'Nicht verbunden',
    'partial' => 'Noch unvollständig', 'error' => 'Abruf fehlgeschlagen', 'ok' => 'Erfasst',
];

function cs_escape(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function cs_number(?int $value, bool $money = false): string
{
    return $value === null ? 'Nicht erfasst' : ($money
        ? number_format($value / 100, 2, ',', '.') . ' €'
        : number_format($value, 0, ',', '.'));
}

function cs_time(?string $value): string
{
    if ($value === null) { return 'Nicht erfasst'; }
    try { return (new DateTimeImmutable($value))->setTimezone(new DateTimeZone('Europe/Berlin'))->format('d.m.Y, H:i') . ' Uhr'; }
    catch (Throwable) { return 'Nicht erfasst'; }
}

function cs_date(string $date): DateTimeImmutable
{
    $result = DateTimeImmutable::createFromFormat('!Y-m-d', $date, new DateTimeZone('Europe/Berlin'));
    if ($result === false || $result->format('Y-m-d') !== $date) {
        throw new InvalidArgumentException('Bitte gültige Kalendertage wählen.');
    }
    return $result;
}

function cs_period(array $query, ?DateTimeImmutable $now = null): array
{
    $today = ($now ?? new DateTimeImmutable('now'))->setTimezone(new DateTimeZone('Europe/Berlin'))->setTime(0, 0);
    $tomorrow = $today->modify('+1 day');
    $preset = $query['period'] ?? '28';
    if (!in_array($preset, ['7', '28', '90', 'custom'], true)) {
        throw new InvalidArgumentException('Bitte einen angebotenen Zeitraum wählen.');
    }
    if ($preset === 'custom') {
        if (!is_string($query['from'] ?? null) || !is_string($query['to'] ?? null)) {
            throw new InvalidArgumentException('Bitte Anfang und Ende wählen.');
        }
        $start = cs_date($query['from']);
        $end = cs_date($query['to'])->modify('+1 day');
    } else {
        $end = $tomorrow;
        $start = $end->modify('-' . $preset . ' days');
    }
    $length = (int) $start->diff($end)->format('%r%a');
    if ($length < 1 || $length > 3660 || $end > $tomorrow) {
        throw new InvalidArgumentException('Bitte 1 bis 3.660 Kalendertage bis einschließlich heute wählen.');
    }
    $previous = $start->modify('-' . $length . ' days');
    return ['preset' => $preset, 'start' => $start, 'end' => $end, 'previous' => $previous, 'length' => $length, 'today' => $today];
}

function cs_days(DateTimeImmutable $start, DateTimeImmutable $end): array
{
    $result = [];
    for ($day = $start; $day < $end; $day = $day->modify('+1 day')) {
        $result[] = $day->format('Y-m-d');
    }
    return $result;
}

function cs_snapshot(string $variable, string $kind, bool $allowTest = false): array
{
    $path = getenv($variable);
    if (!is_string($path) || $path === '') {
        return ['status' => 'disconnected', 'data' => null];
    }
    return cs_snapshot_file($path, $kind, $allowTest);
}

function cs_snapshot_file(string $path, string $kind, bool $allowTest = false): array
{
    try {
        if (!is_file($path) || !is_readable($path) || @filesize($path) > 32_000_000) {
            throw new RuntimeException();
        }
        $data = json_decode((string) @file_get_contents($path), true, 64, JSON_THROW_ON_ERROR);
        if (!is_array($data) || ($data['version'] ?? null) !== 1 || ($data['kind'] ?? null) !== $kind
            || !is_string($data['generatedAt'] ?? null)
            || !preg_match('/^\d{4}-\d{2}-\d{2}T.*(?:Z|[+-]\d{2}:\d{2})$/D', $data['generatedAt'])
            || !in_array($data['environment'] ?? null, $allowTest ? ['live', 'test'] : ['live'], true)) {
            throw new RuntimeException();
        }
        $generated = new DateTimeImmutable($data['generatedAt']);
        if ($generated > new DateTimeImmutable('+5 minutes')) {
            throw new RuntimeException();
        }
        foreach (match ($kind) { 'commerce' => ['days', 'catalog'], 'marketing' => ['posts', 'platforms'], 'google' => ['days', 'queries'], default => [] } as $key) {
            if (!is_array($data[$key] ?? null)) { throw new RuntimeException(); }
        }
        if (isset($data['days'])) {
            foreach ($data['days'] as $day => $bucket) {
                if (!is_string($day) || cs_date($day)->format('Y-m-d') !== $day || !is_array($bucket)) { throw new RuntimeException(); }
            }
        }
        $status = $generated < new DateTimeImmutable('-36 hours') ? 'partial' : 'ok';
        if ($kind === 'commerce' && ($data['operatorTestFilterConfirmed'] ?? true) !== true) { $status = 'partial'; }
        if (is_file($path . '.status.json') && @filesize($path . '.status.json') < 4096) {
            $attempt = json_decode((string) @file_get_contents($path . '.status.json'), true, 8, JSON_THROW_ON_ERROR);
            if (($attempt['status'] ?? '') === 'error' && new DateTimeImmutable($attempt['generatedAt']) >= $generated) { $status = 'error'; }
        }
        return ['status' => $status, 'data' => $data];
    } catch (Throwable) {
        return ['status' => 'error', 'data' => null];
    }
}

function cs_count(mixed $value): ?int
{
    return is_int($value) && $value >= 0 ? $value : null;
}

function cs_business(array $source, array $days, string $field, ?string $product = null): array
{
    $data = $source['data'];
    if ($data === null) {
        return ['value' => null, 'status' => $source['status']];
    }
    $sum = 0;
    $known = 0;
    foreach ($days as $day) {
        $bucket = $product === null ? ($data['days'][$day] ?? []) : ($data['days'][$day]['products'][$product] ?? []);
        $value = $field === 'netGoodsMinor' && is_int($bucket[$field] ?? null)
            ? $bucket[$field] : cs_count($bucket[$field] ?? null);
        if ($field === 'netGoodsMinor' && array_key_exists($field, $bucket) && $value === null) {
            return ['value' => null, 'status' => 'partial'];
        }
        if ($value !== null) {
            $sum += $value;
            ++$known;
        }
    }
    return ['value' => $known > 0 ? $sum : null,
        'status' => $source['status'] === 'error' ? 'error' : ($known === count($days) && $source['status'] === 'ok' ? 'ok' : 'partial')];
}

function cs_public_view_stats(array $stats): array
{
    foreach (['days', 'months'] as $period) {
        foreach ($stats[$period] ?? [] as $date => $bucket) {
            if (!isset($bucket['pageviews'])) {
                continue;
            }
            $stats[$period][$date]['pageviews'] = array_filter(
                $bucket['pageviews'],
                static fn (string $route): bool => !str_starts_with($route, '/admin/')
                    && !str_starts_with($route, '/artikelverwaltung/')
                    && preg_match('#^/armbaender/cp-2026-0022(?:-[a-z0-9-]+)?/$#D', $route) !== 1,
                ARRAY_FILTER_USE_KEY,
            );
        }
    }
    return $stats;
}

function cs_product_views_baseline(string $path): ?array
{
    if (!is_file($path)) {
        return null;
    }
    $data = cs_snapshot_file($path, 'product-views-baseline')['data'];
    if (!is_array($data['totals'] ?? null)) {
        throw new RuntimeException('Ungültiger Ausgangsstand der Armband-Aufrufe.');
    }
    foreach ($data['totals'] as $route => $count) {
        if (!is_string($route) || !preg_match('#^/armbaender/[a-z0-9]+(?:-[a-z0-9]+)*/$#D', $route) || cs_count($count) === null) {
            throw new RuntimeException('Ungültiger Ausgangsstand der Armband-Aufrufe.');
        }
    }
    return $data;
}

function cs_product_views_since(array $stats, array $days, array $baseline): array
{
    $cut = new DateTimeImmutable($baseline['generatedAt']);
    $day = $cut->setTimezone(new DateTimeZone('Europe/Berlin'))->format('Y-m-d');
    $days = array_values(array_filter($days, static fn (string $date): bool => $date >= $day));
    foreach ($baseline['totals'] as $route => $before) {
        $views = cs_count($stats['days'][$day]['pageviews'][$route]['views'] ?? null);
        if ($views !== null) {
            $stats['days'][$day]['pageviews'][$route]['views'] = max(0, $views - $before);
        }
    }
    return [$stats, $days];
}

function cs_views(array $stats, array $days, string $field = 'views', string|Closure|null $path = null): array
{
    $sum = 0;
    $known = 0;
    foreach ($days as $day) {
        // No bucket means missing data. An empty bucket after filtering means zero public views.
        $rows = $stats['days'][$day]['pageviews'] ?? null;
        if (!is_array($rows)) {
            continue;
        }
        ++$known;
        foreach ($rows as $route => $row) {
            if ((is_string($path) && $route !== $path) || ($path instanceof Closure && !$path($route))) {
                continue;
            }
            $value = $field === 'views' ? ($row['views'] ?? null) : ($row['sources'][$field] ?? 0);
            $sum += cs_count($value) ?? 0;
        }
    }
    return ['value' => $known ? $sum : null, 'status' => ($stats['_status'] ?? '') === 'error' ? 'error' : ($known ? 'partial' : 'missing')];
}

function cs_all_product_views(array $stats, array $days): array
{
    return cs_views($stats, $days, 'views', static fn (string $route): bool =>
        preg_match('#^/armbaender/cp-\d{4}-\d{4}(?:-[a-z0-9-]+)?/$#D', $route) === 1
    );
}

function cs_product_views(array $stats, array $days, array $product): array
{
    $paths = [];
    if (is_string($product['path'] ?? null)) { $paths[$product['path']] = true; }
    $sku = strtoupper((string) ($product['sku'] ?? ''));
    if (preg_match('/^CP-\d{4}-\d{4}$/D', $sku)) {
        foreach ($stats['days'] ?? [] as $bucket) {
            foreach (array_keys($bucket['pageviews'] ?? []) as $route) {
                if (preg_match('#^/armbaender/(cp-\d{4}-\d{4})(?:-[a-z0-9-]+)?/$#iD', $route, $match)
                    && strtoupper($match[1]) === $sku) { $paths[$route] = true; }
            }
        }
    }
    if ($paths === []) { return ['value' => null, 'status' => 'missing']; }
    $result = null;
    foreach (array_keys($paths) as $path) {
        $metric = cs_views($stats, $days, 'views', $path);
        if ($result === null) { $result = $metric; }
        elseif ($metric['value'] !== null) { $result['value'] = ($result['value'] ?? 0) + $metric['value']; }
    }
    return $result;
}

function cs_comparison(array $current, array $previous, bool $money = false): string
{
    $before = $previous['value'];
    if ($before === null) {
        return 'Vorher: ' . CS_LABELS[$previous['status']] . ' · Veränderung nicht berechenbar';
    }
    $label = 'Vorher ' . cs_number($before, $money);
    if ($current['value'] === null || $current['status'] !== 'ok' || $previous['status'] !== 'ok') {
        return $label . ' · Veränderung bei unvollständiger Datengrundlage nicht berechenbar';
    }
    $difference = $current['value'] - $before;
    return $label . ' · ' . cs_number(abs($difference), $money) . ($difference < 0 ? ' weniger' : ' mehr');
}

function cs_catalog(array $business): array
{
    $products = [];
    foreach ($business['data']['catalog'] ?? [] as $row) {
        if (is_array($row) && is_string($row['id'] ?? null) && is_string($row['name'] ?? null)) {
            $products[$row['id']] = $row;
        }
    }
    // Local development may read the actual catalogue. This path is never supplied by a request.
    $catalogPath = getenv('CARMAJA_STATS_CATALOG_FILE');
    if (is_string($catalogPath) && is_file($catalogPath)) {
        $catalog = json_decode((string) @file_get_contents($catalogPath), true, 64, JSON_THROW_ON_ERROR);
        foreach ($catalog['products'] ?? [] as $row) {
            $id = $row['productId'] ?? null;
            if (is_string($id) && !isset($products[$id])) {
                $products[$id] = ['id' => $id, 'name' => $row['title'], 'sku' => $row['sku'],
                    'path' => '/armbaender/' . $row['slug'] . '/', 'availability' => null,
                    'searchTerms' => implode(' ', array_filter($row['materials'] ?? [], 'is_string'))];
            }
        }
    }
    // On the host, published Product JSON-LD is the public fallback, never the old stock field.
    foreach (glob(carmaja_page_root() . '/armbaender/*/index.html') ?: [] as $file) {
        $html = @file_get_contents($file);
        preg_match_all('#<script[^>]*type=["\']application/ld\+json["\'][^>]*>(.*?)</script>#s', (string) $html, $matches);
        foreach ($matches[1] as $json) {
            $item = json_decode($json, true);
            if (!is_array($item) || ($item['@type'] ?? '') !== 'Product' || !is_string($item['sku'] ?? null)) {
                continue;
            }
            if (array_filter($products, static fn ($p) => ($p['sku'] ?? '') === $item['sku'])) {
                continue;
            }
            $id = 'sku:' . $item['sku'];
            $products[$id] = ['id' => $id, 'name' => (string) ($item['name'] ?? $item['sku']),
                'sku' => $item['sku'], 'path' => '/armbaender/' . basename(dirname($file)) . '/', 'availability' => null];
        }
    }
    return $products;
}

function cs_posts(array $source, array $period, string $window): array
{
    $unique = [];
    $observations = [];
    foreach ($source['data']['posts'] ?? [] as $post) {
        if (!is_array($post) || !in_array($post['platform'] ?? null, ['pinterest', 'instagram'], true)
            || !is_string($post['id'] ?? null) || !is_string($post['readAt'] ?? null)
            || !preg_match('/^\d{4}-\d{2}-\d{2}T.*(?:Z|[+-]\d{2}:\d{2})$/D', $post['readAt'])) {
            continue;
        }
        try { $readAt = (new DateTimeImmutable($post['readAt']))->getTimestamp(); }
        catch (Throwable) { continue; }
        $key = $post['platform'] . ':' . $post['id'];
        $kind = is_array($post['metrics'] ?? null) ? ($post['metrics']['window'] ?? '') : '';
        if (in_array($kind, ['first14', 'lifetime'], true)
            && (!isset($observations[$key][$kind]) || $readAt > (new DateTimeImmutable($observations[$key][$kind]['readAt']))->getTimestamp())) {
            $observations[$key][$kind] = $post;
        }
        if (!isset($unique[$key]) || $readAt > (new DateTimeImmutable($unique[$key]['readAt']))->getTimestamp()) {
            $unique[$key] = $post;
        }
    }
    $result = [];
    foreach ($unique as $key => $post) {
        $published = $post['publishedAt'] ?? null;
        if (!is_string($published) || !preg_match('/^\d{4}-\d{2}-\d{2}T.*(?:Z|[+-]\d{2}:\d{2})$/D', $published)) {
            continue; // Planned/attempted rows are reported separately; never count as published.
        }
        try {
            $at = new DateTimeImmutable($published);
            if ($at < $period['start'] || $at >= $period['end'] || ($post['status'] ?? '') !== 'published'
                || !cs_social_url($post['url'] ?? '', $post['platform'])) {
                continue;
            }
            $observation = $observations[$key][$window] ?? [];
            $metrics = is_array($observation['metrics'] ?? null) ? $observation['metrics'] : [];
            $end = $at->getTimestamp() + 14 * 86400;
            $complete = ($metrics['window'] ?? '') === 'first14'
                && (new DateTimeImmutable($metrics['from'] ?? 'invalid'))->getTimestamp() === $at->getTimestamp()
                && (new DateTimeImmutable($metrics['to'] ?? 'invalid'))->getTimestamp() === $end
                && ($metrics['complete'] ?? false) === true
                && (new DateTimeImmutable($observation['readAt']))->getTimestamp() >= $end;
            if ($window === 'first14' && !$complete) {
                $metrics = [];
            } elseif ($window === 'lifetime' && ($metrics['window'] ?? '') !== 'lifetime') {
                $metrics = [];
            }
            $result[] = [...$post, 'metrics' => $metrics, 'complete' => $complete,
                'readAt' => $observation['readAt'] ?? $post['readAt']];
        } catch (Throwable) {
            continue;
        }
    }
    return $result;
}

function cs_social_url(mixed $url, string $platform): bool
{
    if (!is_string($url)) { return false; }
    $parts = parse_url($url);
    $host = strtolower($parts['host'] ?? '');
    $domain = $platform === 'pinterest' ? 'pinterest.com' : 'instagram.com';
    return ($parts['scheme'] ?? '') === 'https' && !isset($parts['user']) && !isset($parts['pass'])
        && ($host === $domain || $host === 'www.' . $domain);
}

function cs_insights(array $business, array $days): array
{
    if ($business['status'] === 'error') { return []; }
    $orders = cs_business($business, $days, 'orders');
    $open = cs_business($business, $days, 'open');
    $review = cs_business($business, $days, 'review');
    $result = [];
    if (($orders['value'] ?? 0) > 0) {
        $result[] = cs_number($orders['value']) . ' bestätigte Bestellungen, Besuchs- und Herkunftszuordnung nicht erfasst. '
            . 'Eine Kaufquote ist nicht berechenbar. Als Nächstes eine datenschutzkonforme Verknüpfung prüfen.';
    }
    if (($open['value'] ?? 0) > 0) {
        $result[] = cs_number($open['value']) . ' begonnene Bestellvorgänge sind zum Datenstand noch offen. '
            . 'Verzögerte SEPA-Zahlungen sind möglich. Zahlungsstatus und Worker prüfen; kein nachgewiesener Kaufabbruch.';
    }
    if (($review['value'] ?? 0) > 0) {
        $result[] = cs_number($review['value']) . ' Bestellvorgänge benötigen eine manuelle Prüfung. Im geschützten Shop-Admin prüfen.';
    }
    if ($result === [] && $orders['status'] === 'ok' && $orders['value'] !== null && $orders['value'] < 10) {
        $result[] = cs_number($orders['value']) . ' bestätigte Bestellungen: Die Datenmenge ist klein. '
            . 'Weitere abgeschlossene Zeiträume beobachten, bevor Produkte oder Quellen bewertet werden.';
    }
    if ($orders['status'] !== 'ok' || $open['status'] !== 'ok' || $review['status'] !== 'ok') {
        $result = array_map(static fn ($text) => 'Vorläufig bei unvollständigem Datenstand: ' . $text, $result);
    }
    return array_slice($result, 0, 3);
}

function cs_google_totals(array $source, array $days): array
{
    $clicks = $impressions = $known = 0;
    foreach ($days as $day) {
        $row = $source['data']['days'][$day] ?? [];
        $c = cs_count($row['clicks'] ?? null);
        $i = cs_count($row['impressions'] ?? null);
        if ($c === null || $i === null || $c > $i) { continue; }
        $clicks += $c;
        $impressions += $i;
        ++$known;
    }
    $status = $source['data'] === null || $source['status'] === 'error' ? $source['status']
        : ($known === count($days) && $source['status'] === 'ok' ? 'ok' : 'partial');
    return ['clicks' => ['value' => $known ? $clicks : null, 'status' => $status],
        'impressions' => ['value' => $known ? $impressions : null, 'status' => $status],
        'ctr' => $known && $impressions > 0 ? 100 * $clicks / $impressions : null];
}

function cs_google_queries(array $source, array $days): array
{
    $unique = [];
    foreach ($source['data']['queries'] ?? [] as $row) {
        if (!is_array($row) || !in_array($row['day'] ?? '', $days, true)
            || !is_string($row['query'] ?? null) || !is_string($row['page'] ?? null)) { continue; }
        $clicks = cs_count($row['clicks'] ?? null);
        $impressions = cs_count($row['impressions'] ?? null);
        if ($clicks === null || $impressions === null || $clicks > $impressions) { continue; }
        // One authoritative daily row per dimensions. A repeated import replaces that row.
        $unique[json_encode([$row['day'], $row['query'], $row['page']], JSON_THROW_ON_ERROR)] = $row;
    }
    $grouped = [];
    foreach ($unique as $row) {
        $key = json_encode([$row['query'], $row['page']], JSON_THROW_ON_ERROR);
        $grouped[$key] ??= ['query' => $row['query'], 'page' => $row['page'], 'clicks' => 0, 'impressions' => 0];
        $grouped[$key]['clicks'] += $row['clicks'];
        $grouped[$key]['impressions'] += $row['impressions'];
    }
    usort($grouped, static fn ($a, $b) => $b['clicks'] <=> $a['clicks']);
    return $grouped;
}

function cs_origin_verified(array $origin): bool
{
    if (($origin['status'] ?? '') === 'error') { return false; }
    $checks = $origin['data']['verification'] ?? [];
    if (($checks['tlsActive'] ?? false) !== true || ($checks['serverFilesWritten'] ?? true) !== false) { return false; }
    foreach (['current', 'previous', 'allAvailable'] as $period) {
        $row = $checks['commerce'][$period] ?? [];
        if (($row['matches'] ?? false) !== true || !is_array($row['source'] ?? null)
            || $row['source'] !== ($row['projection'] ?? null)) { return false; }
    }
    foreach (['current', 'previous'] as $period) {
        $row = $checks['website'][$period] ?? [];
        if (($row['matches'] ?? false) !== true || !isset($row['sourceViews'], $row['sourceEntryViews'])
            || $row['sourceViews'] !== ($row['copiedViews'] ?? null) || $row['sourceEntryViews'] !== ($row['copiedEntryViews'] ?? null)) { return false; }
    }
    $stripe = $checks['stripe'] ?? [];
    if (($stripe['requested'] ?? false) === true || ($stripe['checkedPayments'] ?? 0) > 0) {
        if (($stripe['requested'] ?? false) !== true || !is_array($stripe['records'] ?? null)
            || ($stripe['checkedPayments'] ?? -1) !== count($stripe['records'])
            || $stripe['checkedPayments'] !== $checks['commerce']['allAvailable']['source']['orders']) { return false; }
        $paid = 0;
        foreach ($stripe['records'] as $row) {
            if (($row['matches'] ?? false) !== true || ($row['currency'] ?? '') !== 'eur' || !is_int($row['paidMinor'] ?? null)) { return false; }
            $paid += $row['paidMinor'];
        }
        if ($paid !== $checks['commerce']['allAvailable']['source']['paidTotalMinor']) { return false; }
    }
    return true;
}
