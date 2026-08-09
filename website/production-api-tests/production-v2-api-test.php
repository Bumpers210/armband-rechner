<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/production-api-private/program/product-api.php';
require_once dirname(__DIR__) . '/production-api-private/program/product-api-v2.php';

final class ProductionV2ApiTestFailure extends RuntimeException
{
}

function production_v2_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new ProductionV2ApiTestFailure($message);
    }
}

function production_v2_expect(int $status, string $code, callable $callback): void
{
    try {
        $callback();
    } catch (CarmajaApiException $error) {
        production_v2_assert($error->statusCode === $status, 'Falscher API-Status.');
        production_v2_assert(
            $error->errorCode === $code,
            'Falscher API-Fehlercode: ' . $error->errorCode
        );
        return;
    }

    throw new ProductionV2ApiTestFailure('Erwartete API-Ausnahme fehlt.');
}

function production_v2_remove_tree(string $path): void
{
    if (!file_exists($path) && !is_link($path)) {
        return;
    }

    if (is_file($path) || is_link($path)) {
        @unlink($path);
        return;
    }

    foreach (scandir($path) ?: [] as $entry) {
        if ($entry !== '.' && $entry !== '..') {
            production_v2_remove_tree($path . DIRECTORY_SEPARATOR . $entry);
        }
    }

    @rmdir($path);
}

function production_v2_fixture(): array
{
    $root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'carmaja-production-v2-'
        . bin2hex(random_bytes(6));
    $private = $root . DIRECTORY_SEPARATOR . 'private';
    $api = $root . DIRECTORY_SEPARATOR . 'api';
    $site = $root . DIRECTORY_SEPARATOR . 'site';

    foreach ([
        $private . DIRECTORY_SEPARATOR . 'audit',
        $private . DIRECTORY_SEPARATOR . 'drafts',
        $private . DIRECTORY_SEPARATOR . 'idempotency',
        $private . DIRECTORY_SEPARATOR . 'locks',
        $private . DIRECTORY_SEPARATOR . 'products' . DIRECTORY_SEPARATOR . 'operations',
        $private . DIRECTORY_SEPARATOR . 'sku-counter',
        $api,
        $site,
    ] as $directory) {
        if (!mkdir($directory, 0750, true) && !is_dir($directory)) {
            throw new ProductionV2ApiTestFailure('Fixture-Verzeichnis konnte nicht erstellt werden.');
        }
    }

    file_put_contents(
        $private . DIRECTORY_SEPARATOR . 'environment.json',
        json_encode(['environment' => 'production'], JSON_THROW_ON_ERROR)
    );

    putenv('CARMAJA_PUBLISH_TARGET=production');
    putenv('CARMAJA_PRIVATE_DIR=' . $private);
    putenv('CARMAJA_PUBLIC_WEBROOT=' . $api);
    putenv('CARMAJA_PRODUCTION_PRIVATE_DIR=' . $private);
    putenv('CARMAJA_PRODUCTION_API_WEBROOT=' . $api);
    putenv('CARMAJA_PRODUCTION_WEBSITE_WEBROOT=' . $site);
    putenv('CARMAJA_PRODUCTION_PUBLISH_ENABLED=false');
    putenv('CARMAJA_GITHUB_ADAPTER_ENABLED=false');
    $_SERVER['DOCUMENT_ROOT'] = $api;

    return compact('root', 'private');
}

function production_v2_payload(int $expectedProductVersion = 0): array
{
    return [
        'expectedProductVersion' => $expectedProductVersion,
        'name' => 'Rosenquarz Unikat',
        'description' => 'Handgefertigtes V2-Armband.',
        'materials' => ['Rosenquarz'],
        'metalElements' => ['Edelstahl'],
        'braceletSizeCm' => 18,
        'pearlSizeMm' => 6,
        'careInstructions' => ['Trocken lagern'],
        'images' => [],
        'priceMinor' => 4690,
        'currency' => 'eur',
        'salesEnabled' => false,
    ];
}

function production_v2_actor(): array
{
    return [
        'tokenId' => str_repeat('a', 32),
        'username' => 'admin',
        'deviceName' => 'Android',
    ];
}

$fixture = production_v2_fixture();

try {
    production_v2_assert(
        carmaja_api_validate_client_version_code(4) === 4,
        'Freigegebene V2-App-Version wurde abgelehnt.'
    );
    production_v2_expect(426, 'client_update_required', static function (): void {
        carmaja_api_validate_client_version_code(1);
    });

    $productId = '019fa2e6-cf3c-7073-9275-7d3b566f54a1';
    $payload = production_v2_payload();
    $saved = carmaja_api_v2_put_product(
        $productId,
        $payload,
        production_v2_actor(),
        'production-v2-put-0001'
    );
    production_v2_assert(
        ($saved['productModelVersion'] ?? null) === 2
            && ($saved['productVersion'] ?? null) === 1
            && ($saved['priceMinor'] ?? null) === 4690
            && ($saved['currency'] ?? null) === 'eur'
            && ($saved['salesEnabled'] ?? null) === false,
        'Produktiver V2-Vertrag speichert die autoritativen Felder nicht konsistent.'
    );
    production_v2_assert(
        is_string($saved['sourceHash'] ?? null)
            && preg_match('/^[0-9a-f]{64}$/', $saved['sourceHash']) === 1
            && !array_key_exists('stock', $saved)
            && !array_key_exists('vintedUrl', $saved),
        'V2-Hash oder Legacy-Feldsperre ist inkonsistent.'
    );

    $repeated = carmaja_api_v2_put_product(
        $productId,
        $payload,
        production_v2_actor(),
        'production-v2-put-0001'
    );
    production_v2_assert($repeated === $saved, 'V2-PUT ist nicht idempotent.');

    production_v2_expect(409, 'idempotency_key_reused', static function () use (
        $productId,
        $payload
    ): void {
        $changed = $payload;
        $changed['name'] = 'Abweichender Inhalt';
        carmaja_api_v2_put_product(
            $productId,
            $changed,
            production_v2_actor(),
            'production-v2-put-0001'
        );
    });

    production_v2_expect(403, 'production_publish_disabled', static function () use (
        $productId,
        $saved
    ): void {
        carmaja_api_v2_publish_product(
            $productId,
            [
                'expectedProductVersion' => 1,
                'expectedSourceHash' => $saved['sourceHash'],
                'operationId' => 'production-v2-publish-0001',
            ],
            production_v2_actor()
        );
    });
    putenv('CARMAJA_PRODUCTION_PUBLISH_ENABLED=true');
    production_v2_expect(503, 'publish_adapter_invalid', static function () use (
        $productId,
        $saved
    ): void {
        carmaja_api_v2_publish_product(
            $productId,
            [
                'expectedProductVersion' => 1,
                'expectedSourceHash' => $saved['sourceHash'],
                'operationId' => 'production-v2-publish-0002',
            ],
            production_v2_actor()
        );
    });
    putenv('CARMAJA_PRODUCTION_PUBLISH_ENABLED=false');
    $stored = carmaja_api_load_draft($productId);
    production_v2_assert(
        ($stored['status'] ?? null) === 'draft'
            && ($stored['lastV2PublishOperationId'] ?? null) === null,
        'Deaktivierter V2-Publisher hat Produktdaten mutiert.'
    );

    echo "production-v2-api: OK\n";
} finally {
    putenv('CARMAJA_PRODUCTION_PUBLISH_ENABLED=false');
    unset($GLOBALS['CARMAJA_API_PUBLISH_ADAPTER_V2']);
    production_v2_remove_tree($fixture['root']);
}
