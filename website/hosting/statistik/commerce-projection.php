<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli' && !defined('CARMAJA_STATISTICS_CONTEXT')) {
    http_response_code(404);
    exit;
}

function carmaja_statistics_live_connection(array $config): PDO
{
    if (($config['environment'] ?? '') !== 'production' || ($config['commerceRequireTls'] ?? false) !== true) {
        throw new RuntimeException('source_environment_invalid');
    }
    $options = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_EMULATE_PREPARES => false,
        PDO::ATTR_TIMEOUT => 5,
        PDO::MYSQL_ATTR_LOCAL_INFILE => false,
        PDO::MYSQL_ATTR_MULTI_STATEMENTS => false,
        PDO::MYSQL_ATTR_SSL_CIPHER => 'HIGH',
    ];
    $ca = $config['commerceTlsCaPath'] ?? '';
    if (is_string($ca) && $ca !== '') {
        $options[PDO::MYSQL_ATTR_SSL_CA] = $ca;
    }
    // Same TLS policy as the deployed commerce connection.
    $options[PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT] = is_string($ca) && $ca !== '';
    $pdo = new PDO($config['commerceDsn'], $config['commerceUser'], $config['commercePassword'], $options);
    $tls = $pdo->query("SHOW SESSION STATUS WHERE Variable_name IN ('Ssl_cipher','Ssl_version')")->fetchAll(PDO::FETCH_KEY_PAIR);
    if (empty($tls['Ssl_cipher']) || empty($tls['Ssl_version'])) {
        throw new RuntimeException('tls_required');
    }
    $pdo->exec("SET time_zone = '+00:00'");
    $pdo->exec('SET SESSION TRANSACTION READ ONLY');
    return $pdo;
}

function carmaja_statistics_live_options(PDO $pdo, array $days, bool $filterConfirmed): array
{
    $utc = new DateTimeZone('UTC');
    $zone = new DateTimeZone('Europe/Berlin');
    $baseline = $pdo->query("SELECT applied_at FROM schema_migrations WHERE migration_id='commerce-v1-schema'")->fetchColumn();
    if (!is_string($baseline)) {
        throw new RuntimeException('source_baseline_missing');
    }
    // Start with the first full Berlin calendar day after schema initialization.
    $since = (new DateTimeImmutable($baseline, $utc))->setTimezone($zone)->setTime(0, 0)->modify('+1 day')->format('Y-m-d');
    $excluded = [];
    if ($days !== []) {
        $select = $pdo->prepare("SELECT p.checkout_id FROM orders o JOIN payments p ON p.payment_id=o.payment_id
            WHERE o.confirmed_at>=? AND o.confirmed_at<? AND LEFT(p.stripe_checkout_session_id,8)='cs_live_'");
        foreach (array_unique($days) as $day) {
            $start = DateTimeImmutable::createFromFormat('!Y-m-d', $day, $zone);
            if ($start === false || $start->format('Y-m-d') !== $day) {
                throw new RuntimeException('exclusion_invalid');
            }
            $select->execute([
                $start->setTimezone($utc)->format('Y-m-d H:i:s'),
                $start->modify('+1 day')->setTimezone($utc)->format('Y-m-d H:i:s'),
            ]);
            $matches = $select->fetchAll(PDO::FETCH_COLUMN);
            if (count($matches) !== 1) {
                throw new RuntimeException('exclusion_ambiguous');
            }
            $excluded[] = $matches[0];
        }
    }
    return [
        $baseline,
        ['environment' => 'live', 'dataSince' => $since, 'excludedCheckoutIds' => $excluded,
            'operatorTestFilterConfirmed' => $filterConfirmed],
    ];
}

function carmaja_statistics_published_catalog(array $commerce, string $webroot): array
{
    $published = [];
    foreach (glob($webroot . '/armbaender/*/index.html') ?: [] as $file) {
        $html = file_get_contents($file);
        preg_match_all('#<script[^>]*type=["\']application/ld\+json["\'][^>]*>(.*?)</script>#s', $html, $matches);
        foreach ($matches[1] as $json) {
            $item = json_decode($json, true);
            if (!is_array($item) || ($item['@type'] ?? '') !== 'Product' || !is_string($item['sku'] ?? null)) {
                continue;
            }
            $route = '/armbaender/' . basename(dirname($file)) . '/';
            if (isset($published[$item['sku']]) && $published[$item['sku']]['path'] !== $route) {
                throw new RuntimeException('public_product_ambiguous');
            }
            $published[$item['sku']] = ['name' => (string) ($item['name'] ?? $item['sku']), 'path' => $route];
        }
    }
    foreach ($commerce['catalog'] as &$product) {
        $product['published'] = isset($published[$product['sku']]);
        if ($product['published']) {
            $product['name'] = $published[$product['sku']]['name'];
            $product['path'] = $published[$product['sku']]['path'];
        }
    }
    unset($product);
    return [$commerce, count($published)];
}

/** Read-only projection. Never boot the router, worker, Stripe or the publisher. */
function carmaja_statistics_projection(PDO $pdo, array $options, ?DateTimeImmutable $now = null): array
{
    $now ??= new DateTimeImmutable('now', new DateTimeZone('UTC'));
    $zone = new DateTimeZone('Europe/Berlin');
    $since = DateTimeImmutable::createFromFormat('!Y-m-d', (string) ($options['dataSince'] ?? ''), $zone);
    if ($since === false || $since->format('Y-m-d') !== ($options['dataSince'] ?? null)
        || !in_array($options['environment'] ?? null, ['live', 'test'], true)) {
        throw new InvalidArgumentException('Datenbeginn und getrennte Umgebung erforderlich.');
    }
    $today = $now->setTimezone($zone)->setTime(0, 0);
    if ($since > $today || (int) $since->diff($today)->days > 3659) {
        throw new InvalidArgumentException('Datenbeginn liegt außerhalb des unterstützten Bereichs.');
    }
    $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    if ($driver === 'mysql') {
        $tls = $pdo->query("SHOW SESSION STATUS WHERE Variable_name IN ('Ssl_cipher','Ssl_version')")->fetchAll(PDO::FETCH_KEY_PAIR);
        if (trim((string) ($tls['Ssl_cipher'] ?? '')) === '' || trim((string) ($tls['Ssl_version'] ?? '')) === '') {
            throw new RuntimeException('Verschlüsselte Datenbankverbindung erforderlich.');
        }
        $pdo->exec("SET time_zone = '+00:00'");
        $pdo->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        $pdo->exec('START TRANSACTION WITH CONSISTENT SNAPSHOT, READ ONLY');
    } elseif ($driver === 'sqlite' && $options['environment'] === 'test') {
        $pdo->exec('PRAGMA query_only = ON');
        $pdo->beginTransaction();
    } else {
        throw new InvalidArgumentException('Nicht unterstützte Datenquelle.');
    }
    try {
        $fetch = static fn (string $sql): array => $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
        $orders = $fetch('SELECT o.order_id,o.status AS order_status,o.confirmed_at,o.payment_id,
            p.status AS payment_status,p.verification_status,p.stripe_checkout_session_id,p.amount_minor,p.currency,
            o.shipping_snapshot,p.checkout_id FROM orders o JOIN payments p ON p.payment_id=o.payment_id AND p.order_id=o.order_id');
        $items = $fetch('SELECT order_id,position_no,product_id,quantity,price_minor,currency FROM order_items');
        $refunds = $fetch('SELECT refund_id,payment_id,stripe_refund_id,status,amount_minor,currency FROM refunds');
        $checkouts = $fetch('SELECT c.checkout_id,c.created_at,p.status,p.stripe_checkout_session_id
            FROM checkout_sagas c JOIN payments p ON p.checkout_id=c.checkout_id');
        $catalog = $fetch('SELECT p.product_id,p.name,p.public_snapshot,p.sales_enabled,i.on_hand,i.quantity_confirmed,
            COALESCE(r.reserved,0) AS reserved FROM commerce_products p LEFT JOIN commerce_inventory i ON i.product_id=p.product_id
            LEFT JOIN (SELECT product_id,SUM(quantity) AS reserved FROM reservations WHERE blocks_stock=1 GROUP BY product_id) r
            ON r.product_id=p.product_id');
        $result = carmaja_statistics_aggregate($orders, $items, $refunds, $checkouts, $catalog, $options, $now);
        $pdo->rollBack(); // Read-only even on successful completion.
        return $result;
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        throw $error;
    }
}

function carmaja_statistics_aggregate(array $orders, array $items, array $refunds, array $checkouts,
    array $catalog, array $options, DateTimeImmutable $now): array
{
    $zone = new DateTimeZone('Europe/Berlin');
    $today = $now->setTimezone($zone)->setTime(0, 0);
    $excluded = array_fill_keys($options['excludedCheckoutIds'] ?? [], true);
    $prefix = $options['environment'] === 'live' ? 'cs_live_' : 'cs_test_';
    $eligible = static fn (array $row): bool => !isset($excluded[$row['checkout_id']])
        && is_string($row['stripe_checkout_session_id'] ?? null)
        && str_starts_with($row['stripe_checkout_session_id'], $prefix);
    $localDay = static function (string $at) use ($zone, $now): ?string {
        $timestamp = new DateTimeImmutable($at, new DateTimeZone('UTC'));
        return $timestamp <= $now ? $timestamp->setTimezone($zone)->format('Y-m-d') : null;
    };
    $empty = ['orders' => 0, 'grossGoodsMinor' => 0, 'refundGoodsMinor' => 0, 'netGoodsMinor' => 0,
        'shippingMinor' => 0, 'refundShippingMinor' => 0, 'unresolvedRefunds' => 0, 'canceled' => 0,
        'checkouts' => 0, 'open' => 0, 'failed' => 0, 'review' => 0, 'products' => []];
    $output = ['version' => 1, 'kind' => 'commerce', 'environment' => $options['environment'],
        'generatedAt' => $now->format(DATE_ATOM), 'dataSince' => $options['dataSince'],
        'operatorTestFilterConfirmed' => ($options['operatorTestFilterConfirmed'] ?? false) === true,
        'refundBasis' => 'sale-cohort-current-state', 'attribution' => 'not-recorded',
        'filters' => ['stripeMode' => $options['environment'], 'excludedCheckouts' => count($excluded),
            'sessionlessCheckoutsExcluded' => true], 'days' => [], 'catalog' => []];
    foreach ($catalog as $product) {
        $snapshot = json_decode($product['public_snapshot'] ?? '{}', true, 32, JSON_THROW_ON_ERROR);
        $slug = $snapshot['slug'] ?? null;
        $output['catalog'][] = ['id' => $product['product_id'], 'name' => $product['name'],
            'sku' => $snapshot['sku'] ?? '',
            'searchTerms' => implode(' ', array_filter($snapshot['materials'] ?? [], 'is_string')),
            'path' => is_string($slug) && preg_match('/^[a-z0-9-]+$/D', $slug) ? '/armbaender/' . $slug . '/' : null,
            'availability' => (int) $product['sales_enabled'] === 0 ? 'disabled'
                : ((int) ($product['quantity_confirmed'] ?? 0) !== 1 ? null
                    : ((int) $product['on_hand'] - (int) $product['reserved'] > 0 ? 'available' : 'unavailable'))];
        $empty['products'][$product['product_id']] = ['units' => 0, 'grossGoodsMinor' => 0, 'netGoodsMinor' => 0];
    }
    for ($day = new DateTimeImmutable($options['dataSince'], $zone); $day <= $today; $day = $day->modify('+1 day')) {
        $output['days'][$day->format('Y-m-d')] = $empty;
    }
    $byOrder = [];
    foreach ($items as $item) {
        $key = $item['order_id'] . ':' . $item['position_no'];
        if (isset($byOrder[$item['order_id']][$key]) && $byOrder[$item['order_id']][$key] !== $item) {
            throw new RuntimeException('Widersprüchliche Bestellposition.');
        }
        $byOrder[$item['order_id']][$key] = $item;
    }
    $byPayment = [];
    foreach ($refunds as $refund) {
        $key = $refund['stripe_refund_id'];
        if (isset($byPayment[$refund['payment_id']][$key]) && $byPayment[$refund['payment_id']][$key] !== $refund) {
            throw new RuntimeException('Widersprüchliche Erstattung.');
        }
        $byPayment[$refund['payment_id']][$key] = $refund;
    }
    $seen = [];
    foreach ($orders as $order) {
        if (!$eligible($order) || $order['payment_status'] !== 'succeeded' || $order['verification_status'] !== 'verified') { continue; }
        $id = $order['order_id'];
        if (isset($seen[$id])) {
            if ($seen[$id] !== $order) { throw new RuntimeException('Widersprüchliche Bestellung.'); }
            continue;
        }
        $seen[$id] = $order;
        $day = $localDay($order['confirmed_at']);
        if (!isset($output['days'][$day])) { continue; }
        $lines = $byOrder[$id] ?? [];
        if ($lines === [] || $order['currency'] !== 'eur') { throw new RuntimeException('Bestellnachweis unvollständig.'); }
        $goods = 0;
        foreach ($lines as $line) {
            if ($line['currency'] !== 'eur' || (int) $line['quantity'] < 1 || (int) $line['price_minor'] < 0) {
                throw new RuntimeException('Bestellbetrag ungültig.');
            }
            $goods += (int) $line['quantity'] * (int) $line['price_minor'];
        }
        $shipping = json_decode($order['shipping_snapshot'], true, 16, JSON_THROW_ON_ERROR);
        $shippingAmount = $shipping['amountMinor'] ?? null;
        if (!is_int($shippingAmount) || $shippingAmount < 0 || $goods + $shippingAmount !== (int) $order['amount_minor']) {
            throw new RuntimeException('Zahlung und Positionen stimmen nicht überein.');
        }
        $refunded = 0;
        $uncertain = false;
        foreach ($byPayment[$order['payment_id']] ?? [] as $refund) {
            if ($refund['currency'] !== 'eur' || (int) $refund['amount_minor'] < 0) { throw new RuntimeException('Erstattung ungültig.'); }
            if ($refund['status'] === 'succeeded') { $refunded += (int) $refund['amount_minor']; }
            if (!in_array($refund['status'], ['succeeded', 'failed'], true)) { $uncertain = true; }
        }
        $full = $refunded === (int) $order['amount_minor'];
        // Unexpected partial refunds cannot be allocated between goods and shipping without evidence.
        $uncertain = $uncertain || ($refunded > 0 && !$full);
        $bucket =& $output['days'][$day];
        ++$bucket['orders'];
        $bucket['canceled'] += $order['order_status'] === 'canceled' ? 1 : 0;
        $bucket['grossGoodsMinor'] += $goods;
        $bucket['shippingMinor'] += $shippingAmount;
        $bucket['refundGoodsMinor'] += $full ? $goods : 0;
        $bucket['refundShippingMinor'] += $full ? $shippingAmount : 0;
        $bucket['unresolvedRefunds'] += $uncertain ? 1 : 0;
        $bucket['netGoodsMinor'] = $uncertain || $bucket['netGoodsMinor'] === null
            ? null : $bucket['netGoodsMinor'] + ($full ? 0 : $goods);
        foreach ($lines as $line) {
            $product =& $bucket['products'][$line['product_id']];
            $product ??= ['units' => 0, 'grossGoodsMinor' => 0, 'netGoodsMinor' => 0];
            $amount = (int) $line['quantity'] * (int) $line['price_minor'];
            $product['units'] += (int) $line['quantity'];
            $product['grossGoodsMinor'] += $amount;
            $product['netGoodsMinor'] = $uncertain || $product['netGoodsMinor'] === null
                ? null : $product['netGoodsMinor'] + ($full ? 0 : $amount);
            unset($product);
        }
        unset($bucket);
    }
    $seen = [];
    foreach ($checkouts as $checkout) {
        if (!$eligible($checkout) || isset($seen[$checkout['checkout_id']])) { continue; }
        $seen[$checkout['checkout_id']] = true;
        $day = $localDay($checkout['created_at']);
        if (!isset($output['days'][$day])) { continue; }
        ++$output['days'][$day]['checkouts'];
        $field = match ($checkout['status']) {
            'created', 'pending', 'processing' => 'open', 'failed', 'canceled' => 'failed',
            'manual_review' => 'review', 'succeeded' => null,
            default => throw new RuntimeException('Unbekannter Zahlungsstatus.'),
        };
        if ($field !== null) { ++$output['days'][$day][$field]; }
    }
    return $output;
}

function carmaja_statistics_write(string $path, string $webroot, array $data): void
{
    $directory = realpath(dirname($path));
    $root = realpath($webroot);
    if ($directory === false || $root === false || is_link($path)
        || str_starts_with(strtolower($directory . DIRECTORY_SEPARATOR), strtolower($root . DIRECTORY_SEPARATOR))
        || $directory === $root) {
        throw new RuntimeException('Privates Ausgabeziel außerhalb des Webroots erforderlich.');
    }
    $temporary = @tempnam($directory, '.statistics-');
    if ($temporary === false) { throw new RuntimeException('Ausgabe nicht möglich.'); }
    try {
        $json = json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        if (!@chmod($temporary, 0600) || @file_put_contents($temporary, $json) !== strlen($json)
            || !@rename($temporary, $path)) {
            throw new RuntimeException('Ausgabe nicht möglich.');
        }
    } finally {
        if (is_file($temporary)) { @unlink($temporary); }
    }
}
