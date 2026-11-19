<?php

declare(strict_types=1);

require_once __DIR__ . '/../hosting/statistik/commerce-projection.php';

if (PHP_SAPI === 'cli' && realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    $arguments = [];
    $config = null;
    try {
        $arguments = getopt('', ['config:', 'out:']);
        if (!is_string($arguments['config'] ?? null) || !is_string($arguments['out'] ?? null)) {
            throw new InvalidArgumentException('Argumente fehlen.');
        }
        // Private local PHP config returns pdo, environment, dataSince, webroot and explicit test exclusions.
        $config = require $arguments['config'];
        if (!is_array($config) || !($config['pdo'] ?? null) instanceof PDO) { throw new RuntimeException(); }
        $data = carmaja_statistics_projection($config['pdo'], $config);
        carmaja_statistics_write($arguments['out'], $config['webroot'], $data);
        if (is_file($arguments['out'] . '.status.json') && !unlink($arguments['out'] . '.status.json')) {
            throw new RuntimeException('Aktualisierungszustand nicht gespeichert.');
        }
        echo "Privater Statistikstand aktualisiert.\n";
    } catch (Throwable) {
        if (is_array($config) && is_string($config['webroot'] ?? null) && is_string($arguments['out'] ?? null)) {
            try {
                carmaja_statistics_write($arguments['out'] . '.status.json', $config['webroot'],
                    ['status' => 'error', 'generatedAt' => gmdate(DATE_ATOM)]);
            } catch (Throwable) { /* A failed write must never disclose connection details. */ }
        }
        fwrite(STDERR, "Statistikexport fehlgeschlagen. Vorheriger Stand bleibt erhalten. Konfiguration und Datenquelle privat prüfen.\n");
        exit(1);
    }
}
