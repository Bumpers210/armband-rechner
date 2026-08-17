<?php

declare(strict_types=1);

define('CARMAJA_BOOTSTRAP_NO_RUN', true);
require_once dirname(__DIR__, 2) . '/test-api-private/program/commerce-core.php';
require_once dirname(__DIR__, 2) . '/test-api-private/program/shop-public.php';

$failures = [];
$tests = [
    'Roh-Token wird nur als Hash gespeichert' => static function (): void {
        $token = carmaja_shop_token();
        if (strlen($token) < 32 || carmaja_shop_token_hash($token) === $token) {
            throw new RuntimeException('Tokenvertrag verletzt.');
        }
    },
    'Originvertrag ist exakt' => static function (): void {
        if (carmaja_shop_origin('https://test.carmaja-perlen.de') !== 'https://test.carmaja-perlen.de') {
            throw new RuntimeException('Gültige Origin wurde abgelehnt.');
        }
        if (carmaja_shop_origin('http://test.carmaja-perlen.de') !== null
            || carmaja_shop_origin('https://test.carmaja-perlen.de/path') !== null) {
            throw new RuntimeException('Unsichere Origin wurde akzeptiert.');
        }
    },
    'Rate Limit nimmt erfolgreiche Zahlungen aus' => static function (): void {
        $limiter = new CarmajaShopRateLimiter();
        foreach (range(1, CARMAJA_SHOP_MAX_CHECKOUT_ATTEMPTS) as $unused) {
            if (!$limiter->allow('session|ip', false, 1000)) {
                throw new RuntimeException('Erster Versuch wurde zu früh blockiert.');
            }
        }
        if ($limiter->allow('session|ip', false, 1000)) {
            throw new RuntimeException('Limit wurde nicht wirksam.');
        }
        $limiter->allow('successful-session|ip', true, 1000);
        $limiter->allow('successful-session|ip', true, 1000);
        $limiter->allow('successful-session|ip', true, 1000);
        if (!$limiter->allow('successful-session|ip', true, 1000)) {
            throw new RuntimeException('Erfolgreiche Wiederholung wurde blockiert.');
        }
    },
    'V1-Konstanten halten getrennte Lebensdauern' => static function (): void {
        if (CARMAJA_SHOP_CONTEXT_TTL_SECONDS !== 600
            || CARMAJA_SHOP_CSRF_TTL_SECONDS !== 7200
            || CARMAJA_SHOP_SESSION_TTL_SECONDS !== 86400) {
            throw new RuntimeException('Lebensdauervertrag wurde verändert.');
        }
    },
];

foreach ($tests as $name => $test) {
    try {
        $test();
        echo "PASS {$name}\n";
    } catch (Throwable $error) {
        $failures[] = $name . ': ' . $error->getMessage();
        echo "FAIL {$name}: {$error->getMessage()}\n";
    }
}

if ($failures !== []) {
    exit(1);
}

echo 'AP4 PHP shop tests: ' . count($tests) . '/' . count($tests) . " bestanden.\n";
