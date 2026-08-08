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
        [PHP_BINARY, '-d', 'display_errors=0', '-r', $runner],
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
    expect_true(
        proc_close($process) === 0,
        'Klickhandler ist fehlgeschlagen: ' . trim($errors . "\n" . $output),
    );
    $marker = strrpos($output, '__CARMAJA_RESULT__');
    expect_true($marker !== false, 'Klickhandler lieferte kein Testergebnis.');
    $result = json_decode(substr($output, $marker + strlen('__CARMAJA_RESULT__')), true, 512, JSON_THROW_ON_ERROR);
    expect_true(is_array($result), 'Klickhandler-Testresultat ist ungueltig.');

    return $result;
}

function run_pageview_request(string $pageviewPath, array $parameters, array $environment, string $method = 'POST'): array
{
    $runner = '$_POST=json_decode(getenv("CARMAJA_TEST_POST"),true,512,JSON_THROW_ON_ERROR);'
        . '$_SERVER["REQUEST_METHOD"]=getenv("CARMAJA_TEST_METHOD");'
        . 'register_shutdown_function(static function(): void {'
        . 'echo "\\n__CARMAJA_RESULT__".json_encode(['
        . '"status"=>http_response_code(),"headers"=>headers_list()],JSON_THROW_ON_ERROR);'
        . '});'
        . 'require ' . var_export($pageviewPath, true) . ';';
    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $process = proc_open(
        [PHP_BINARY, '-d', 'display_errors=0', '-r', $runner],
        $descriptors,
        $pipes,
        null,
        array_merge(getenv() ?: [], $environment, [
            'CARMAJA_TEST_POST' => json_encode($parameters, JSON_THROW_ON_ERROR),
            'CARMAJA_TEST_METHOD' => $method,
        ]),
    );
    expect_true(is_resource($process), 'Seitenaufrufhandler konnte nicht gestartet werden.');
    fclose($pipes[0]);
    $output = stream_get_contents($pipes[1]);
    $errors = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    expect_true(proc_close($process) === 0, 'Seitenaufrufhandler ist fehlgeschlagen: ' . trim($errors));
    $marker = strrpos($output, '__CARMAJA_RESULT__');
    expect_true($marker !== false, 'Seitenaufrufhandler lieferte kein Testergebnis.');
    $result = json_decode(substr($output, $marker + strlen('__CARMAJA_RESULT__')), true, 512, JSON_THROW_ON_ERROR);
    expect_true(is_array($result), 'Seitenaufruf-Testresultat ist ungueltig.');

    return $result;
}

$websiteRoot = dirname(__DIR__, 2);
$hostingRoot = $websiteRoot . DIRECTORY_SEPARATOR . 'hosting';
$testRoot = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'carmaja-click-statistics-' . bin2hex(random_bytes(8));
$statsPath = $testRoot . DIRECTORY_SEPARATOR . 'private' . DIRECTORY_SEPARATOR . 'clicks.json';
$pageRoot = $testRoot . DIRECTORY_SEPARATOR . 'public';

try {
    mkdir(dirname($statsPath), 0750, true);
    mkdir($pageRoot . DIRECTORY_SEPARATOR . 'kontakt', 0750, true);
    mkdir($pageRoot . DIRECTORY_SEPARATOR . 'armbaender', 0750, true);
    file_put_contents($pageRoot . DIRECTORY_SEPARATOR . 'index.html', '<main>Startseite</main>');
    file_put_contents($pageRoot . DIRECTORY_SEPARATOR . 'kontakt' . DIRECTORY_SEPARATOR . 'index.html', '<main>Kontakt</main>');
    file_put_contents($pageRoot . DIRECTORY_SEPARATOR . 'armbaender' . DIRECTORY_SEPARATOR . 'index.html', '<main>Armbänder</main>');
    file_put_contents($statsPath, json_encode([
        'version' => 1,
        'days' => ['2026-07-01' => [
            'instagram' => ['footer' => 3],
            'vinted' => ['footer' => 7],
        ]],
        'months' => [],
    ], JSON_THROW_ON_ERROR));

    putenv('CARMAJA_STATS_FILE=' . $statsPath);
    putenv('CARMAJA_PAGE_ROOT=' . $pageRoot);
    require $hostingRoot . DIRECTORY_SEPARATOR . '_internal' . DIRECTORY_SEPARATOR . 'tracking.php';

    carmaja_record_click('instagram', 'footer');
    $afterDirectWrite = read_json($statsPath);
    expect_true(($afterDirectWrite['days']['2026-07-01']['instagram']['footer'] ?? 0) === 3, 'Bestehende Instagram-Statistikdaten gingen verloren.');
    expect_true(!isset($afterDirectWrite['days']['2026-07-01']['vinted']), 'Veraltete Marktplatzdaten wurden nicht entfernt.');

    $environment = array_merge(getenv() ?: [], [
        'CARMAJA_STATS_FILE' => $statsPath,
        'CARMAJA_PAGE_ROOT' => $pageRoot,
    ]);
    $clickPath = $hostingRoot . DIRECTORY_SEPARATOR . 'click.php';
    $instagramResult = run_click_request($clickPath, [
        'target' => 'instagram',
        'position' => 'footer',
    ], $environment);
    expect_true(($instagramResult['status'] ?? 0) === 302, 'Gueltiger Instagram-Link leitet nicht weiter.');

    $pageviewPath = $hostingRoot . DIRECTORY_SEPARATOR . 'pageview.php';
    $googlePageview = run_pageview_request($pageviewPath, ['path' => '/', 'source' => 'google'], $environment);
    expect_true(($googlePageview['status'] ?? 0) === 204, 'Gueltiger Google-Seitenaufruf wurde nicht akzeptiert.');
    $directPageview = run_pageview_request($pageviewPath, ['path' => '/armbaender/'], $environment);
    expect_true(($directPageview['status'] ?? 0) === 204, 'Seitenaufruf ohne Herkunft wurde nicht akzeptiert.');
    $instagramPageview = run_pageview_request($pageviewPath, ['path' => '/kontakt/', 'source' => 'instagram'], $environment);
    expect_true(($instagramPageview['status'] ?? 0) === 204, 'Gueltiger Instagram-Seitenaufruf wurde nicht akzeptiert.');

    foreach ([
        ['path' => '/statistik/', 'source' => 'google'],
        ['path' => '/kontakt', 'source' => 'google'],
        ['path' => '/kontakt/?campaign=test', 'source' => 'google'],
        ['path' => '/', 'source' => 'unknown-source'],
        ['path' => '/', 'source' => 'google', 'extra' => 'value'],
    ] as $parameters) {
        $result = run_pageview_request($pageviewPath, $parameters, $environment);
        expect_true(($result['status'] ?? 0) === 400, 'Ungueltiger Seitenaufruf wurde akzeptiert.');
    }
    $wrongMethod = run_pageview_request($pageviewPath, ['path' => '/'], $environment, 'GET');
    expect_true(($wrongMethod['status'] ?? 0) === 405, 'Falsche Methode fuer Seitenaufruf wurde akzeptiert.');

    foreach ([
        'target=vinted&position=footer',
        'target=instagram&position=product',
        'target=instagram&position=footer&product=cp-2026-0001',
        'target=invalid&position=footer',
    ] as $query) {
        parse_str($query, $parameters);
        $result = run_click_request($clickPath, $parameters, $environment);
        expect_true(($result['status'] ?? 0) === 400, 'Ungueltiger Klickparameter wurde akzeptiert: ' . $query);
    }

    mkdir($testRoot . DIRECTORY_SEPARATOR . 'stats-directory', 0750, true);
    $failedCounterResult = run_click_request($clickPath, [
        'target' => 'instagram',
        'position' => 'footer',
    ], array_merge($environment, ['CARMAJA_STATS_FILE' => $testRoot . DIRECTORY_SEPARATOR . 'stats-directory']));
    expect_true(
        ($failedCounterResult['status'] ?? 0) === 302,
        'Zaehlerfehler verhindert die Weiterleitung: ' . json_encode($failedCounterResult),
    );

    $parallel = [];
    for ($index = 0; $index < 8; ++$index) {
        $code = 'putenv(' . var_export('CARMAJA_STATS_FILE=' . $statsPath, true) . ');'
            . 'require ' . var_export($hostingRoot . DIRECTORY_SEPARATOR . '_internal' . DIRECTORY_SEPARATOR . 'tracking.php', true) . ';'
            . 'carmaja_record_click("instagram", "footer");';
        $parallel[] = proc_open(
            [PHP_BINARY, '-d', 'display_errors=0', '-r', $code],
            [STDIN, STDOUT, STDERR],
            $childPipes,
        );
    }
    foreach ($parallel as $process) {
        expect_true(is_resource($process) && proc_close($process) === 0, 'Paralleler Schreibvorgang ist fehlgeschlagen.');
    }

    $finalStats = read_json($statsPath);
    $todayStats = $finalStats['days'][(new DateTimeImmutable('today', new DateTimeZone('Europe/Berlin')))->format('Y-m-d')] ?? [];
    expect_true(($todayStats['instagram']['footer'] ?? 0) === 10, 'Parallele Instagram-Zaehlungen gingen verloren.');
    expect_true(($finalStats['days'][(new DateTimeImmutable('today', new DateTimeZone('Europe/Berlin')))->format('Y-m-d')]['pageviews']['/']['views'] ?? 0) === 1, 'Seitenaufruf der Startseite fehlt.');
    expect_true(($finalStats['days'][(new DateTimeImmutable('today', new DateTimeZone('Europe/Berlin')))->format('Y-m-d')]['pageviews']['/']['sources']['google'] ?? 0) === 1, 'Google-Herkunft fehlt.');
    expect_true(($finalStats['days'][(new DateTimeImmutable('today', new DateTimeZone('Europe/Berlin')))->format('Y-m-d')]['pageviews']['/kontakt/']['sources']['instagram'] ?? 0) === 1, 'Instagram-Herkunft fehlt.');
    expect_true(!contains_personal_statistic_key($finalStats), 'Personenbezogene Statistikfelder vorhanden.');

    $source = file_get_contents($hostingRoot . DIRECTORY_SEPARATOR . 'statistik' . DIRECTORY_SEPARATOR . 'index.php');
    expect_true(is_string($source) && str_contains($source, 'Seitenaufrufe'), 'Dashboard zeigt keine Seitenaufrufe.');
    expect_true(is_string($source) && str_contains($source, 'Herkunft beim Einstieg'), 'Dashboard zeigt keine Herkunftskanäle.');
    expect_true(is_string($source) && str_contains($source, 'Externe Linkklicks'), 'Dashboard zeigt keine Linkklicks.');
    expect_true(is_string($source) && str_contains($source, "Content-Type: text/html; charset=utf-8"), 'Dashboard liefert keinen UTF-8-Content-Type.');
    expect_true(is_string($source) && str_contains($source, 'Häufige Seiten'), 'Dashboard nutzt keine korrekte deutsche Umlautschreibweise.');
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
