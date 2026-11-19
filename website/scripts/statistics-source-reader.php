<?php

declare(strict_types=1);

// Streamed over SSH together with export-statistics.php. No remote file is installed.
if (PHP_SAPI !== 'cli' || !defined('CARMAJA_STATISTICS_READ_OPTIONS') || !function_exists('carmaja_statistics_projection')) {
    exit(1);
}
ini_set('display_errors', '0');
ini_set('log_errors', '0');
set_error_handler(static function (): never { throw new RuntimeException('source_read_failed'); });
$stage = 'configuration';
try {
    $request = CARMAJA_STATISTICS_READ_OPTIONS;
    $request['excludedConfirmationDays'] = array_values(array_unique($request['excludedConfirmationDays'] ?? []));
    $config = require '/home/www/carmaja-private-shop/config/runtime-config.php';
    if (!is_string($config['stripeSecretKey'] ?? null) || !str_starts_with($config['stripeSecretKey'], 'sk_live_')) {
        throw new RuntimeException('source_environment_invalid');
    }
    $stage = 'connection';
    $pdo = carmaja_statistics_live_connection($config);
    $zone = new DateTimeZone('Europe/Berlin');
    $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
    $today = $now->setTimezone($zone)->setTime(0, 0);
    [$baseline, $options] = carmaja_statistics_live_options($pdo, $request['excludedConfirmationDays'],
        ($request['operatorTestFilterConfirmed'] ?? false) === true);
    $since = $options['dataSince'];
    $excluded = $options['excludedCheckoutIds'];
    $stage = 'commerce_projection';
    $commerce = carmaja_statistics_projection($pdo, $options, $now);
    $stage = 'published_catalog';
    [$commerce, $publishedCount] = carmaja_statistics_published_catalog($commerce, '/home/www/carmaja');

    $stage = 'source_reconciliation';
    $pdo->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
    $pdo->exec('START TRANSACTION WITH CONSISTENT SNAPSHOT, READ ONLY');
    $reports = [];
    foreach (['current' => [$today->modify('-28 days'), $today],
        'previous' => [$today->modify('-56 days'), $today->modify('-28 days')],
        'allAvailable' => [new DateTimeImmutable($since, $zone), $today->modify('+1 day')]] as $label => [$from, $to]) {
        $upperBound = $label === 'allAvailable' ? $now->modify('+1 microsecond') : $to;
        $parameters = [$from->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s'), $upperBound->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u')];
        $exclusionSql = $excluded === [] ? '' : ' AND p.checkout_id NOT IN (' . implode(',', array_fill(0, count($excluded), '?')) . ')';
        $parameters = [...$parameters, ...$excluded];
        $where = "o.confirmed_at>=? AND o.confirmed_at<? AND p.status='succeeded' AND p.verification_status='verified'
            AND LEFT(p.stripe_checkout_session_id,8)='cs_live_'" . $exclusionSql;
        $query = $pdo->prepare("SELECT COUNT(*) AS orders,COALESCE(SUM(p.amount_minor),0) AS paidTotalMinor,
            COALESCE(SUM(item_totals.goods),0) AS grossGoodsMinor,COALESCE(SUM(item_totals.units),0) AS units,
            COALESCE(SUM(p.amount_minor-item_totals.goods),0) AS shippingMinor,
            COALESCE(SUM(CASE WHEN r.refunded=p.amount_minor THEN item_totals.goods ELSE 0 END),0) AS refundGoodsMinor,
            COALESCE(SUM(CASE WHEN r.refunded=p.amount_minor THEN p.amount_minor-item_totals.goods ELSE 0 END),0) AS refundShippingMinor,
            COALESCE(SUM(CASE WHEN r.pending>0 OR (r.refunded>0 AND r.refunded<>p.amount_minor) THEN 1 ELSE 0 END),0) AS unresolvedRefunds,
            COALESCE(SUM(CASE WHEN o.status='canceled' THEN 1 ELSE 0 END),0) AS canceled
            FROM orders o JOIN payments p ON p.payment_id=o.payment_id AND p.order_id=o.order_id
            JOIN (SELECT order_id,SUM(quantity*price_minor) AS goods,SUM(quantity) AS units FROM order_items GROUP BY order_id) item_totals ON item_totals.order_id=o.order_id
            LEFT JOIN (SELECT payment_id,SUM(CASE WHEN status='succeeded' THEN amount_minor ELSE 0 END) AS refunded,
                SUM(CASE WHEN status NOT IN ('succeeded','failed') THEN 1 ELSE 0 END) AS pending FROM refunds GROUP BY payment_id) r ON r.payment_id=p.payment_id
            WHERE $where");
        $query->execute($parameters);
        $source = array_map('intval', $query->fetch(PDO::FETCH_ASSOC));
        $source['netGoodsMinor'] = $source['unresolvedRefunds'] > 0 ? null : $source['grossGoodsMinor'] - $source['refundGoodsMinor'];
        $projected = array_fill_keys(array_keys($source), 0);
        foreach ($commerce['days'] as $date => $bucket) {
            if ($date < $from->format('Y-m-d') || $date >= $to->format('Y-m-d')) { continue; }
            foreach (['orders', 'grossGoodsMinor', 'shippingMinor', 'refundGoodsMinor', 'refundShippingMinor', 'unresolvedRefunds', 'canceled'] as $key) { $projected[$key] += $bucket[$key]; }
            foreach ($bucket['products'] as $product) { $projected['units'] += $product['units']; }
            $projected['netGoodsMinor'] = $bucket['netGoodsMinor'] === null || $projected['netGoodsMinor'] === null ? null : $projected['netGoodsMinor'] + $bucket['netGoodsMinor'];
        }
        $projected['paidTotalMinor'] = $projected['grossGoodsMinor'] + $projected['shippingMinor'];
        if ($source !== $projected) { throw new RuntimeException('reconciliation_mismatch'); }
        $reports[$label] = ['from' => $from->format('Y-m-d'), 'toExclusive' => $to->format('Y-m-d'), 'source' => $source, 'projection' => $projected, 'matches' => true];
    }
    $sequence = $pdo->query("SELECT COUNT(*) AS retainedOrders,MIN(CAST(SUBSTRING(order_number,5) AS UNSIGNED)) AS lowestNumber,
        MAX(CAST(SUBSTRING(order_number,5) AS UNSIGNED)) AS highestNumber FROM orders")->fetch(PDO::FETCH_ASSOC);
    $stripeRows = [];
    if (($request['verifyStripe'] ?? false) === true) {
        $stripeQuery = $pdo->prepare("SELECT p.stripe_payment_intent_id,p.amount_minor,p.currency,o.confirmed_at,COALESCE(r.refunded,0) AS refunded
            FROM orders o JOIN payments p ON p.payment_id=o.payment_id AND p.order_id=o.order_id
            LEFT JOIN (SELECT payment_id,SUM(CASE WHEN status='succeeded' THEN amount_minor ELSE 0 END) AS refunded FROM refunds GROUP BY payment_id) r
            ON r.payment_id=p.payment_id WHERE $where");
        $stripeQuery->execute($parameters);
        $stripeRows = $stripeQuery->fetchAll(PDO::FETCH_ASSOC);
    }
    $pdo->rollBack();

    // Network calls occur after the read transaction has ended. Only GET requests, no SDK/router/worker startup.
    $stage = 'stripe_readonly_reconciliation';
    $stripeProof = ['requested' => ($request['verifyStripe'] ?? false) === true, 'checkedPayments' => 0, 'records' => []];
    $seenIntents = [];
    if (count($stripeRows) > 100) { throw new RuntimeException('stripe_read_limit_exceeded'); }
    foreach ($stripeRows as $row) {
        $intentId = $row['stripe_payment_intent_id'];
        if (!is_string($intentId) || !preg_match('/^pi_[A-Za-z0-9_]+$/D', $intentId) || isset($seenIntents[$intentId])) { throw new RuntimeException('stripe_reference_invalid'); }
        $seenIntents[$intentId] = true;
        $curl = curl_init('https://api.stripe.com/v1/payment_intents/' . rawurlencode($intentId) . '?expand%5B%5D=latest_charge');
        curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPGET => true, CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => 8, CURLOPT_TIMEOUT => 20, CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $config['stripeSecretKey'], 'Stripe-Version: ' . $config['stripeApiVersion']]]);
        $body = curl_exec($curl);
        $httpStatus = curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        curl_close($curl);
        if (!is_string($body) || strlen($body) > 1_000_000 || $httpStatus !== 200) { throw new RuntimeException('stripe_read_failed'); }
        $intent = json_decode($body, true, 64, JSON_THROW_ON_ERROR);
        unset($body);
        $charge = $intent['latest_charge'] ?? null;
        if (($intent['id'] ?? '') !== $intentId || ($intent['object'] ?? '') !== 'payment_intent'
            || ($intent['livemode'] ?? false) !== true || ($intent['status'] ?? '') !== 'succeeded'
            || ($intent['currency'] ?? '') !== $row['currency'] || ($intent['amount_received'] ?? -1) !== (int) $row['amount_minor']
            || ($intent['amount'] ?? -1) !== (int) $row['amount_minor'] || !is_array($charge)
            || ($charge['payment_intent'] ?? '') !== $intentId || ($charge['livemode'] ?? false) !== true
            || ($charge['status'] ?? '') !== 'succeeded' || ($charge['captured'] ?? false) !== true
            || ($charge['currency'] ?? '') !== $row['currency'] || ($charge['amount_captured'] ?? -1) !== (int) $row['amount_minor']
            || ($charge['amount_refunded'] ?? -1) !== (int) $row['refunded']) {
            throw new RuntimeException('stripe_source_mismatch');
        }
        $stripeProof['records'][] = ['confirmedDay' => (new DateTimeImmutable($row['confirmed_at'], new DateTimeZone('UTC')))->setTimezone($zone)->format('Y-m-d'),
            'paidMinor' => (int) $row['amount_minor'], 'refundMinor' => (int) $row['refunded'], 'currency' => $row['currency'], 'matches' => true];
        ++$stripeProof['checkedPayments'];
        unset($intent, $charge);
    }

    $stage = 'website';
    $path = '/home/www/carmaja/private-data/clicks.json';
    $handle = fopen($path, 'rb');
    if ($handle === false) { throw new RuntimeException('website_unreadable'); }
    try { $fileState = fstat($handle); $raw = json_decode(stream_get_contents($handle), true, 64, JSON_THROW_ON_ERROR); }
    finally { fclose($handle); }
    if (($raw['version'] ?? null) !== 3 || !is_array($raw['days'] ?? null) || !is_array($raw['months'] ?? null)) { throw new RuntimeException('website_schema_invalid'); }
    $website = ['version' => 3, 'days' => [], 'months' => []];
    foreach (['days' => '/^\d{4}-\d{2}-\d{2}$/D', 'months' => '/^\d{4}-\d{2}$/D'] as $key => $pattern) {
        foreach ($raw[$key] as $date => $bucket) {
            if (!is_string($date) || !preg_match($pattern, $date) || !is_array($bucket)) { throw new RuntimeException('website_bucket_invalid'); }
            $clean = [];
            foreach ($bucket['pageviews'] ?? [] as $route => $row) {
                if (!is_string($route) || !preg_match('#^/(?:[a-z0-9]+(?:-[a-z0-9]+)*/)*$#D', $route) || !is_array($row)
                    || !is_int($row['views'] ?? null) || $row['views'] < 0) { throw new RuntimeException('website_row_invalid'); }
                $clean['pageviews'][$route] = ['views' => $row['views'], 'sources' => []];
                foreach ($row['sources'] ?? [] as $source => $count) {
                    if (!in_array($source, ['google', 'other-search', 'instagram', 'other-social', 'direct-unknown', 'other-website'], true)
                        || !is_int($count) || $count < 0) { throw new RuntimeException('website_source_invalid'); }
                    $clean['pageviews'][$route]['sources'][$source] = $count;
                }
            }
            foreach (['hero', 'gallery', 'contact', 'footer'] as $position) {
                if (isset($bucket['instagram'][$position])) {
                    if (!is_int($bucket['instagram'][$position]) || $bucket['instagram'][$position] < 0) { throw new RuntimeException('website_click_invalid'); }
                    $clean['instagram'][$position] = $bucket['instagram'][$position];
                }
            }
            $website[$key][$date] = $clean;
        }
    }
    $siteChecks = [];
    foreach (['current' => [$today->modify('-28 days'), $today], 'previous' => [$today->modify('-56 days'), $today->modify('-28 days')]] as $label => [$from, $to]) {
        $sourceTotal = $projectedTotal = $sourceEntries = $projectedEntries = $observedDays = 0;
        foreach ($raw['days'] as $date => $bucket) {
            if ($date < $from->format('Y-m-d') || $date >= $to->format('Y-m-d')) { continue; }
            if (($bucket['pageviews'] ?? []) !== []) { ++$observedDays; }
            foreach ($bucket['pageviews'] ?? [] as $row) { $sourceTotal += $row['views']; $sourceEntries += array_sum($row['sources'] ?? []); }
        }
        foreach ($website['days'] as $date => $bucket) {
            if ($date < $from->format('Y-m-d') || $date >= $to->format('Y-m-d')) { continue; }
            foreach ($bucket['pageviews'] ?? [] as $row) { $projectedTotal += $row['views']; $projectedEntries += array_sum($row['sources'] ?? []); }
        }
        if ($sourceTotal !== $projectedTotal || $sourceEntries !== $projectedEntries) { throw new RuntimeException('website_reconciliation_mismatch'); }
        $siteChecks[$label] = ['from' => $from->format('Y-m-d'), 'toExclusive' => $to->format('Y-m-d'),
            'sourceViews' => $sourceTotal, 'copiedViews' => $projectedTotal, 'sourceEntryViews' => $sourceEntries,
            'copiedEntryViews' => $projectedEntries, 'daysWithRecords' => $observedDays, 'missingDays' => 28 - $observedDays, 'matches' => true];
    }
    $metadata = ['version' => 1, 'kind' => 'origin', 'environment' => 'live', 'generatedAt' => $now->format(DATE_ATOM),
        'website' => ['sourceUpdatedAt' => (new DateTimeImmutable('@' . $fileState['mtime']))->format(DATE_ATOM),
            'readAt' => $now->format(DATE_ATOM), 'firstRetainedDay' => min(array_keys($raw['days'])), 'lastRetainedDay' => max(array_keys($raw['days']))],
        'commerce' => ['schemaInitializedAtUtc' => $baseline, 'coverageBasis' => 'current-database-after-schema-initialization',
            'historicalDeletionsIndependentlyExcluded' => false, 'sequenceContinuity' => $sequence,
            'operatorTestFilterConfirmed' => ($request['operatorTestFilterConfirmed'] ?? false) === true,
            'excludedConfirmationDays' => $request['excludedConfirmationDays'] ?? [], 'excludedCount' => count($excluded)],
        'catalog' => ['commerceCount' => count($commerce['catalog']), 'publishedCount' => $publishedCount,
            'routeBasis' => 'published-product-jsonld-matched-by-sku'],
        'verification' => ['commerce' => $reports, 'website' => $siteChecks, 'stripe' => $stripeProof, 'tlsActive' => true, 'serverFilesWritten' => false]];
    echo json_encode(['status' => 'ok', 'commerce' => $commerce, 'website' => $website, 'metadata' => $metadata], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
} catch (Throwable $error) {
    if (isset($pdo) && $pdo->inTransaction()) { $pdo->rollBack(); }
    $reason = $error instanceof PDOException ? 'database_read_failed'
        : (preg_match('/^[a-z_]{1,64}$/D', $error->getMessage()) ? $error->getMessage() : 'source_validation_failed');
    echo json_encode(['status' => 'error', 'stage' => $stage, 'reason' => $reason]);
    exit(1);
}
