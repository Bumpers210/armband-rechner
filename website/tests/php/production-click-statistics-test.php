<?php

declare(strict_types=1);

function fail_test(string $message): never
{
    throw new RuntimeException($message);
}

function expect_true(bool $condition, string $message): void
{
    if (!$condition) {
        fail_test($message);
    }
}

function read_json(string $path): array
{
    $raw = file_get_contents($path);

    if (!is_string($raw)) {
        fail_test('Teststatistik ist nicht lesbar.');
    }

    $value = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);

    if (!is_array($value)) {
        fail_test('Teststatistik ist kein Objekt.');
    }

    return $value;
}

function contains_personal_statistic_key(mixed $value): bool
{
    if (!is_array($value)) {
        return false;
    }

    foreach ($value as $key => $child) {
        if (preg_match('/ip|user.?agent|referer|referrer|cookie/i', (string) $key)
            || contains_personal_statistic_key($child)) {
            return true;
        }
    }

    return false;
}

function run_click_request(string $clickPath, array $query, array $environment): array
{
    $runner = '$_GET=json_decode(getenv("CARMAJA_TEST_QUERY"),true,512,JSON_THROW_ON_ERROR);'
        . 'register_shutdown_function(static function(): void {'
        . 'echo "\\n__CARMAJA_RESULT__".json_encode(['
        . '"status"=>http_response_code(),"headers"=>headers_list()],JSON_THROW_ON_ERROR);'
        . '});'
        . 'require ' . var_export($clickPath, true) . ';';
    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $process = proc_open(
        escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg($runner),
        $descriptors,
        $pipes,
        null,
        array_merge(getenv() ?: [], $environment, [
            'CARMAJA_TEST_QUERY' => json_encode($query, JSON_THROW_ON_ERROR),
        ]),
    );
    expect_true(is_resource($process), 'Klickhandler konnte nicht gestartet werden.');
    fclose($pipes[0]);
    $output = stream_get_contents($pipes[1]);
    $errors = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    expect_true(proc_close($process) === 0, 'Klickhandler ist fehlgeschlagen: ' . trim($errors));
    $marker = strrpos($output, '__CARMAJA_RESULT__');
    expect_true($marker !== false, 'Klickhandler lieferte kein Testergebnis.');
    $result = json_decode(substr($output, $marker + strlen('__CARMAJA_RESULT__')), true, 512, JSON_THROW_ON_ERROR);
    expect_true(is_array($result), 'Klickhandler-Testresultat ist ungueltig.');

    return $result;
}

$websiteRoot = dirname(__DIR__, 2);
$hostingRoot = $websiteRoot . DIRECTORY_SEPARATOR . 'hosting';
$testRoot = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'carmaja-click-statistics-' . bin2hex(random_bytes(8));
$statsPath = $testRoot . DIRECTORY_SEPARATOR . 'private' . DIRECTORY_SEPARATOR . 'clicks.json';
$productRoot = $testRoot . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'armbaender';

try {
    mkdir($productRoot . DIRECTORY_SEPARATOR . 'cp-2026-0001', 0750, true);
    mkdir($productRoot . DIRECTORY_SEPARATOR . 'cp-2026-0002', 0750, true);
    mkdir(dirname($statsPath), 0750, true);
    file_put_contents(
        $productRoot . DIRECTORY_SEPARATOR . 'cp-2026-0001' . DIRECTORY_SEPARATOR . 'index.html',
        '<a href="/click.php?target=vinted&amp;position=product&amp;product=cp-2026-0001">Vinted</a>',
    );
    file_put_contents($productRoot . DIRECTORY_SEPARATOR . 'cp-2026-0002' . DIRECTORY_SEPARATOR . 'index.html', '<p>sold</p>');
    file_put_contents($statsPath, json_encode([
        'version' => 1,
        'days' => ['2026-07-01' => ['vinted' => ['footer' => 3]]],
        'months' => [],
    ], JSON_THROW_ON_ERROR));

    putenv('CARMAJA_STATS_FILE=' . $statsPath);
    putenv('CARMAJA_PRODUCT_PAGES_DIR=' . $productRoot);
    require $hostingRoot . DIRECTORY_SEPARATOR . '_internal' . DIRECTORY_SEPARATOR . 'tracking.php';

    expect_true(carmaja_is_published_product_slug('cp-2026-0001'), 'Published Produkt wurde nicht erkannt.');
    expect_true(!carmaja_is_published_product_slug('cp-2026-0002'), 'Sold Produkt wurde akzeptiert.');
    expect_true(!carmaja_is_published_product_slug('cp-2026-9999'), 'Unbekanntes Produkt wurde akzeptiert.');

    carmaja_record_click('vinted', 'product', 'cp-2026-0001');
    $afterDirectWrite = read_json($statsPath);
    expect_true(($afterDirectWrite['days']['2026-07-01']['vinted']['footer'] ?? 0) === 3, 'Bestehende Statistikdaten gingen verloren.');

    $environment = array_merge(getenv() ?: [], [
        'CARMAJA_STATS_FILE' => $statsPath,
        'CARMAJA_PRODUCT_PAGES_DIR' => $productRoot,
    ]);
    $clickPath = $hostingRoot . DIRECTORY_SEPARATOR . 'click.php';
    $productResult = run_click_request($clickPath, [
        'target' => 'vinted',
        'position' => 'product',
        'product' => 'cp-2026-0001',
    ], $environment);
    expect_true(($productResult['status'] ?? 0) === 302, 'Gueltiger Produktlink leitet nicht weiter.');
    $instagramResult = run_click_request($clickPath, [
        'target' => 'instagram',
        'position' => 'footer',
    ], $environment);
    expect_true(($instagramResult['status'] ?? 0) === 302, 'Gueltiger Instagram-Link leitet nicht weiter.');

    foreach ([
        'target=vinted&position=product&product=cp-2026-0002',
        'target=vinted&position=product&product=cp-2026-9999',
        'target=vinted&position=product',
        'target=invalid&position=footer',
        'target=vinted&position=footer&product=cp-2026-0001',
    ] as $query) {
        parse_str($query, $parameters);
        $result = run_click_request($clickPath, $parameters, $environment);
        expect_true(($result['status'] ?? 0) === 400, 'Ungueltiger Klickparameter wurde akzeptiert: ' . $query);
    }

    mkdir($testRoot . DIRECTORY_SEPARATOR . 'stats-directory', 0750, true);
    $failedCounterResult = run_click_request($clickPath, [
        'target' => 'vinted',
        'position' => 'product',
        'product' => 'cp-2026-0001',
    ], array_merge($environment, ['CARMAJA_STATS_FILE' => $testRoot . DIRECTORY_SEPARATOR . 'stats-directory']));
    expect_true(($failedCounterResult['status'] ?? 0) === 302, 'Zaehlerfehler verhindert die Weiterleitung.');

    $parallel = [];
    for ($index = 0; $index < 8; ++$index) {
        $code = 'putenv(' . var_export('CARMAJA_STATS_FILE=' . $statsPath, true) . ');'
            . 'require ' . var_export($hostingRoot . DIRECTORY_SEPARATOR . '_internal' . DIRECTORY_SEPARATOR . 'tracking.php', true) . ';'
            . 'carmaja_record_click("vinted", "product", "cp-2026-0001");';
        $parallel[] = proc_open(escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg($code), [STDIN, STDOUT, STDERR], $childPipes);
    }
    foreach ($parallel as $process) {
        expect_true(is_resource($process) && proc_close($process) === 0, 'Paralleler Schreibvorgang ist fehlgeschlagen.');
    }

    $finalStats = read_json($statsPath);
    $productCount = $finalStats['days'][(new DateTimeImmutable('today', new DateTimeZone('Europe/Berlin')))->format('Y-m-d')]['products']['cp-2026-0001'] ?? 0;
    expect_true($productCount === 10, 'Parallele Produktzaehlungen gingen verloren.');
    expect_true(!contains_personal_statistic_key($finalStats), 'Personenbezogene Statistikfelder vorhanden.');

    $source = file_get_contents($hostingRoot . DIRECTORY_SEPARATOR . 'statistik' . DIRECTORY_SEPARATOR . 'index.php');
    expect_true(is_string($source) && str_contains($source, 'Produktklicks'), 'Dashboard zeigt keine Produktklicks.');
    expect_true(is_string($source) && str_contains($source, "Content-Type: text/html; charset=utf-8"), 'Dashboard liefert keinen UTF-8-Content-Type.');
    expect_true(is_string($source) && str_contains($source, 'Übersicht'), 'Dashboard nutzt keine korrekte deutsche Umlautschreibweise.');
    echo "PHP Klickstatistik-Test erfolgreich.\n";
} finally {
    if (is_dir($testRoot)) {
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($testRoot, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($iterator as $entry) {
            $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        }
        rmdir($testRoot);
    }
}
