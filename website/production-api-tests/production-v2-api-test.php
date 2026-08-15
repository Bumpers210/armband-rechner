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

    $uploadDirectory = $fixture['private'] . DIRECTORY_SEPARATOR . 'uploads'
        . DIRECTORY_SEPARATOR . $productId;
    if (!mkdir($uploadDirectory, 0750, true) && !is_dir($uploadDirectory)) {
        throw new ProductionV2ApiTestFailure('V2-Publisher-Bildverzeichnis konnte nicht erstellt werden.');
    }
    $sourceImage = $uploadDirectory . DIRECTORY_SEPARATOR . '01.jpg';
    file_put_contents($sourceImage, 'controlled-production-v2-publisher-image');
    $publicProduct = [
        'productModelVersion' => 2,
        'productId' => $productId,
        'productVersion' => 1,
        'sourceHash' => str_repeat('1', 64),
        'sku' => 'CP-2026-0006',
        'slug' => 'cp-2026-0006-ares',
        'title' => 'Ares',
        'description' => 'Kontrolliertes Produktionsprodukt.',
        'materials' => ['Tigerauge'],
        'metalElements' => [],
        'braceletSizeCm' => 18,
        'pearlSizeMm' => 8,
        'priceMinor' => 2000,
        'currency' => 'eur',
        'salesEnabled' => false,
        'images' => [[
            'imageId' => '44444444-4444-4444-8444-444444444444',
            'fileName' => '01.jpg',
            'src' => '/images/products/CP-2026-0006/01.jpg',
            'alt' => 'Ares',
            'width' => 1200,
            'height' => 900,
            'isMain' => true,
        ]],
        'updatedAt' => '2026-08-15T08:00:00+00:00',
        '_imageBlobs' => [[
            '_sourcePath' => $sourceImage,
            '_repoPath' => 'website/public/images/products/CP-2026-0006/01.jpg',
        ]],
    ];
    $existingProduct = array_diff_key($publicProduct, ['_imageBlobs' => true]);
    $existingProduct['productId'] = '55555555-5555-4555-8555-555555555555';
    $existingProduct['sourceHash'] = str_repeat('2', 64);
    $existingProduct['sku'] = 'CP-2026-0005';
    $existingProduct['slug'] = 'cp-2026-0005-bestand';
    $existingProduct['title'] = 'Bestehendes Produkt';
    $existingProduct['images'][0]['imageId'] = '66666666-6666-4666-8666-666666666666';
    $existingProduct['images'][0]['src'] = '/images/products/CP-2026-0005/01.jpg';
    $existingProduct['images'][0]['alt'] = 'Bestehendes Produkt';
    $projection = ['version' => 2, 'products' => [$existingProduct]];
    $baseSha = str_repeat('a', 40);
    $commitSha = str_repeat('f', 40);
    $currentHead = $baseSha;
    $calls = [];
    $createdTree = null;
    $GLOBALS['CARMAJA_API_GITHUB_REQUEST_ADAPTER'] = static function (
        string $method,
        string $path,
        ?array $body
    ) use (&$calls, &$createdTree, &$currentHead, &$projection, $baseSha, $commitSha): array {
        $calls[] = [$method, $path, $body];

        if ($method === 'GET' && str_ends_with($path, '/git/ref/heads/main')) {
            return ['object' => ['sha' => $currentHead]];
        }
        if ($method === 'GET' && str_ends_with($path, '/git/commits/' . $baseSha)) {
            return ['tree' => ['sha' => str_repeat('b', 40)]];
        }
        if ($method === 'GET' && str_contains($path, '/contents/website/content/products.json')) {
            return ['content' => base64_encode(json_encode($projection, JSON_THROW_ON_ERROR))];
        }
        if ($method === 'POST' && str_ends_with($path, '/git/blobs')) {
            return ['sha' => str_repeat('c', 40)];
        }
        if ($method === 'POST' && str_ends_with($path, '/git/trees')) {
            $createdTree = $body;
            return ['sha' => str_repeat('d', 40)];
        }
        if ($method === 'POST' && str_ends_with($path, '/git/commits')) {
            return ['sha' => $commitSha];
        }
        if ($method === 'PATCH' && str_ends_with($path, '/git/refs/heads/main')) {
            $currentHead = (string) ($body['sha'] ?? '');
            return ['object' => ['sha' => $currentHead]];
        }

        throw new ProductionV2ApiTestFailure('Unerwartete GitHub-Publisher-Anfrage: ' . $method . ' ' . $path);
    };
    putenv('CARMAJA_PRODUCTION_PUBLISH_ENABLED=true');
    putenv('CARMAJA_GITHUB_ADAPTER_ENABLED=true');
    putenv('CARMAJA_GITHUB_REPOSITORY=Bumpers210/armband-rechner');
    putenv('CARMAJA_GITHUB_BRANCH=main');
    $operation = [
        'operationId' => 'production-v2-github-0001',
        'requestHash' => str_repeat('9', 64),
    ];
    $published = carmaja_api_github_publish_adapter_v2($publicProduct, $operation);
    production_v2_assert(
        ($published['commitSha'] ?? null) === $commitSha
            && ($published['deploymentStatus'] ?? null) === 'queued',
        'Kontrollierter v2-GitHub-Publisher liefert kein eindeutiges Ergebnis.'
    );
    $callCount = count($calls);
    production_v2_assert(
        carmaja_api_github_publish_adapter_v2($publicProduct, $operation) === $published
            && count($calls) === $callCount,
        'Kontrollierter v2-GitHub-Publisher ist nicht idempotent.'
    );
    $treeEntries = is_array($createdTree['tree'] ?? null) ? $createdTree['tree'] : [];
    $treePaths = array_map(
        static fn (array $entry): string => (string) ($entry['path'] ?? ''),
        $treeEntries
    );
    sort($treePaths, SORT_STRING);
    production_v2_assert(
        $treePaths === [
            'website/content/products.json',
            'website/public/images/products/CP-2026-0006/01.jpg',
        ],
        'v2-Publisher versucht Dateien außerhalb der Produkt-Allowlist zu ändern.'
    );
    $productsEntry = array_values(array_filter(
        $treeEntries,
        static fn (array $entry): bool =>
            ($entry['path'] ?? null) === 'website/content/products.json'
    ))[0] ?? [];
    $writtenProjection = json_decode((string) ($productsEntry['content'] ?? ''), true);
    production_v2_assert(
        is_array($writtenProjection)
            && ($writtenProjection['version'] ?? null) === 2
            && count($writtenProjection['products'] ?? []) === 2
            && !array_key_exists('_imageBlobs', $writtenProjection['products'][1] ?? [])
            && !array_key_exists('stock', $writtenProjection['products'][1] ?? [])
            && !array_key_exists('vintedUrl', $writtenProjection['products'][1] ?? []),
        'v2-Publisher schreibt nicht ausschließlich den öffentlichen v2-Vertrag.'
    );
    $invalidProduct = $publicProduct;
    $invalidProduct['stock'] = 1;
    production_v2_expect(500, 'github_v2_product_invalid', static function () use (
        $invalidProduct
    ): void {
        carmaja_api_github_publish_adapter_v2($invalidProduct, [
            'operationId' => 'production-v2-github-0002',
            'requestHash' => str_repeat('8', 64),
        ]);
    });
    production_v2_assert(
        count($calls) === $callCount,
        'Ungültiges v2-Produkt hat eine GitHub-Anfrage ausgelöst.'
    );
    $projection = ['version' => 1, 'products' => []];
    $currentHead = $baseSha;
    production_v2_expect(409, 'public_product_model_conflict', static function () use (
        $publicProduct
    ): void {
        carmaja_api_github_publish_adapter_v2($publicProduct, [
            'operationId' => 'production-v2-github-0003',
            'requestHash' => str_repeat('7', 64),
        ]);
    });
    $writesAfterModelConflict = array_filter(
        array_slice($calls, $callCount),
        static fn (array $call): bool => in_array($call[0], ['POST', 'PATCH'], true)
    );
    production_v2_assert(
        $writesAfterModelConflict === [],
        'Produktmodellkonflikt hat eine schreibende GitHub-Anfrage ausgelöst.'
    );

    echo "production-v2-api: OK\n";
} finally {
    putenv('CARMAJA_PRODUCTION_PUBLISH_ENABLED=false');
    putenv('CARMAJA_GITHUB_ADAPTER_ENABLED=false');
    unset($GLOBALS['CARMAJA_API_PUBLISH_ADAPTER_V2']);
    unset($GLOBALS['CARMAJA_API_GITHUB_REQUEST_ADAPTER']);
    production_v2_remove_tree($fixture['root']);
}
