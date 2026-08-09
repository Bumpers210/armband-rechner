<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/test-api-private/program/product-api.php';
require_once dirname(__DIR__, 2) . '/test-api-private/program/product-api-v2.php';

final class CarmajaApiTestFailure extends RuntimeException
{
}

$carmajaApiTests = [];
$carmajaApiTestRoots = [];

function carmaja_api_test(string $name, callable $test): void
{
    global $carmajaApiTests;
    $carmajaApiTests[$name] = $test;
}

function carmaja_api_test_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new CarmajaApiTestFailure($message);
    }
}

function carmaja_api_test_same(mixed $expected, mixed $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new CarmajaApiTestFailure(
            $message
            . ' Erwartet: '
            . var_export($expected, true)
            . '; erhalten: '
            . var_export($actual, true)
        );
    }
}

function carmaja_api_test_exception(
    callable $callback,
    int $statusCode,
    string $errorCode
): CarmajaApiException {
    try {
        $callback();
    } catch (CarmajaApiException $error) {
        carmaja_api_test_same(
            $statusCode,
            $error->statusCode,
            'Unerwarteter HTTP-Status.'
        );
        carmaja_api_test_same(
            $errorCode,
            $error->errorCode,
            'Unerwarteter Fehlercode.'
        );
        return $error;
    }

    throw new CarmajaApiTestFailure('Erwartete API-Ausnahme wurde nicht ausgelöst.');
}

function carmaja_api_test_write_json(string $path, array $data): void
{
    $parent = dirname($path);

    if (!is_dir($parent) && !mkdir($parent, 0750, true) && !is_dir($parent)) {
        throw new CarmajaApiTestFailure('Testverzeichnis konnte nicht angelegt werden.');
    }

    $result = file_put_contents(
        $path,
        json_encode(
            $data,
            JSON_PRETTY_PRINT
                | JSON_UNESCAPED_SLASHES
                | JSON_UNESCAPED_UNICODE
                | JSON_THROW_ON_ERROR
        ) . PHP_EOL
    );

    if ($result === false) {
        throw new CarmajaApiTestFailure('Testdatei konnte nicht geschrieben werden.');
    }
}

function carmaja_api_test_remove_tree(string $path): void
{
    if (!file_exists($path)) {
        return;
    }

    if (is_file($path) || is_link($path)) {
        @unlink($path);
        return;
    }

    $entries = scandir($path);

    if (!is_array($entries)) {
        return;
    }

    foreach ($entries as $entry) {
        if ($entry !== '.' && $entry !== '..') {
            carmaja_api_test_remove_tree($path . DIRECTORY_SEPARATOR . $entry);
        }
    }

    @rmdir($path);
}

function carmaja_api_test_private_structure(string $path, string $environment): void
{
    $directories = [
        '',
        'auth',
        'audit',
        'locks',
        'products',
        'products/operations',
        'drafts',
        'idempotency',
        'uploads',
        'uploads-temp',
        'backups',
        'sku-counter',
    ];

    foreach ($directories as $relative) {
        $directory = $relative === ''
            ? $path
            : $path . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);

        if (!is_dir($directory) && !mkdir($directory, 0750, true) && !is_dir($directory)) {
            throw new CarmajaApiTestFailure('Private Teststruktur konnte nicht angelegt werden.');
        }
    }

    carmaja_api_test_write_json(
        $path . DIRECTORY_SEPARATOR . 'environment.json',
        ['environment' => $environment]
    );
}

function carmaja_api_test_users_file(
    string $private,
    string $environment,
    bool $withAdmin = true
): string {
    $path = $private . DIRECTORY_SEPARATOR . 'auth' . DIRECTORY_SEPARATOR . 'api-users.json';
    $users = [];

    if ($withAdmin) {
        $users[] = [
            'username' => 'admin',
            'passwordHash' => password_hash('secret', PASSWORD_DEFAULT),
            'active' => true,
        ];
    }

    carmaja_api_test_write_json($path, [
        'environment' => $environment,
        'users' => $users,
    ]);

    return $path;
}

function carmaja_api_test_fixture(): array
{
    global $carmajaApiTestRoots;

    $root = sys_get_temp_dir()
        . DIRECTORY_SEPARATOR
        . 'carmaja-api-phase3-'
        . bin2hex(random_bytes(6));
    $testPrivate = $root . DIRECTORY_SEPARATOR . 'test-private';
    $productionPrivate = $root . DIRECTORY_SEPARATOR . 'production-private';
    $testApi = $root . DIRECTORY_SEPARATOR . 'test-api-webroot';
    $testWebsite = $root . DIRECTORY_SEPARATOR . 'test-website-webroot';
    $productionApi = $root . DIRECTORY_SEPARATOR . 'production-api-webroot';
    $productionWebsite = $root . DIRECTORY_SEPARATOR . 'production-website-webroot';

    carmaja_api_test_private_structure($testPrivate, 'test');
    carmaja_api_test_private_structure($productionPrivate, 'production');

    foreach ([$testApi, $testWebsite, $productionApi, $productionWebsite] as $directory) {
        mkdir($directory, 0750, true);
    }

    $fixture = [
        'root' => $root,
        'testPrivate' => $testPrivate,
        'productionPrivate' => $productionPrivate,
        'testApi' => $testApi,
        'testWebsite' => $testWebsite,
        'productionApi' => $productionApi,
        'productionWebsite' => $productionWebsite,
        'testUsers' => carmaja_api_test_users_file($testPrivate, 'test'),
        'productionUsers' => carmaja_api_test_users_file(
            $productionPrivate,
            'production'
        ),
    ];
    $carmajaApiTestRoots[] = $root;
    carmaja_api_test_use_target($fixture, 'test');
    unset($GLOBALS['CARMAJA_API_PUBLISH_ADAPTER']);
    unset($GLOBALS['CARMAJA_API_GITHUB_REQUEST_ADAPTER']);
    unset($GLOBALS['CARMAJA_API_GITHUB_READONLY_TOKEN']);
    $_SERVER['HTTP_AUTHORIZATION'] = '';
    $_SERVER['REMOTE_ADDR'] = '203.0.113.10';
    $_SERVER['HTTP_USER_AGENT'] = 'Should-Not-Be-Audited';
    $_POST = [];
    $_FILES = [];

    return $fixture;
}

function carmaja_api_test_use_target(array $fixture, string $target): void
{
    $isTest = $target === 'test';
    $private = $isTest ? $fixture['testPrivate'] : $fixture['productionPrivate'];
    $apiWebroot = $isTest ? $fixture['testApi'] : $fixture['productionApi'];
    $users = $isTest ? $fixture['testUsers'] : $fixture['productionUsers'];

    putenv('CARMAJA_PUBLISH_TARGET=' . $target);
    putenv('CARMAJA_PRIVATE_DIR=' . $private);
    putenv('CARMAJA_TEST_PRIVATE_DIR=' . $fixture['testPrivate']);
    putenv('CARMAJA_PRODUCTION_PRIVATE_DIR=' . $fixture['productionPrivate']);
    putenv('CARMAJA_PUBLIC_WEBROOT=' . $apiWebroot);
    putenv('CARMAJA_TEST_API_WEBROOT=' . $fixture['testApi']);
    putenv('CARMAJA_TEST_WEBSITE_WEBROOT=' . $fixture['testWebsite']);
    putenv('CARMAJA_PRODUCTION_API_WEBROOT=' . $fixture['productionApi']);
    putenv('CARMAJA_PRODUCTION_WEBSITE_WEBROOT=' . $fixture['productionWebsite']);
    putenv('CARMAJA_API_USERS_FILE=' . $users);
    putenv('CARMAJA_TOKEN_PEPPER=' . str_repeat('p', 48));
    putenv('CARMAJA_PRODUCTION_PUBLISH_ENABLED=false');
    putenv('CARMAJA_GITHUB_ADAPTER_ENABLED=false');
    putenv('CARMAJA_GITHUB_REPOSITORY');
    putenv(
        'CARMAJA_GITHUB_BRANCH='
            . ($isTest ? CARMAJA_TEST_BRANCH : 'main')
    );
    putenv('CARMAJA_GITHUB_TOKEN_FILE');
    $_SERVER['DOCUMENT_ROOT'] = $apiWebroot;
}

function carmaja_api_test_actor(): array
{
    return [
        'tokenId' => str_repeat('a', 32),
        'username' => 'admin',
        'deviceName' => 'Testgerät',
    ];
}

function carmaja_api_test_create_jpeg(
    string $path,
    int $width = 120,
    int $height = 80
): void {
    $parent = dirname($path);

    if (!is_dir($parent) && !mkdir($parent, 0750, true) && !is_dir($parent)) {
        throw new CarmajaApiTestFailure('Bildverzeichnis konnte nicht angelegt werden.');
    }

    $image = imagecreatetruecolor($width, $height);
    $color = imagecolorallocate($image, 180, 40, 90);
    imagefill($image, 0, 0, $color);

    if (!imagejpeg($image, $path, 82)) {
        throw new CarmajaApiTestFailure('Test-JPEG konnte nicht erzeugt werden.');
    }

    unset($image);
}

function carmaja_api_test_prepare_image_upload(
    int $expectedVersion,
    string $imageId,
    array $desiredImageIds,
    string $source,
    string $alt
): void {
    $_POST = [
        'expectedVersion' => (string) $expectedVersion,
        'imageId' => $imageId,
        'desiredImageIds' => json_encode(
            $desiredImageIds,
            JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        ),
        'alt' => $alt,
    ];
    $_FILES = [
        'image' => [
            'tmp_name' => $source,
            'name' => 'image.jpg',
            'size' => filesize($source),
            'error' => UPLOAD_ERR_OK,
        ],
    ];
}

function carmaja_api_test_ready_draft(
    string $draftId,
    ?string $vintedUrl = null,
    array $internalCalculation = []
): array {
    $imageDirectory = carmaja_api_path('uploads/' . $draftId);
    $imagePath = $imageDirectory . DIRECTORY_SEPARATOR . '01.jpg';
    carmaja_api_test_create_jpeg($imagePath);

    return carmaja_api_save_draft([
        'draftId' => $draftId,
        'sku' => null,
        'slug' => null,
        'version' => 1,
        'status' => 'ready',
        'name' => 'Rosenquarz Armband',
        'materials' => ['Rosenquarz'],
        'metalElements' => [],
        'braceletSize' => '17 cm',
        'stock' => 1,
        'shortDescription' => 'Zartes Testarmband.',
        'careInstructions' => ['Vor Wasser schützen'],
        'vintedUrl' => $vintedUrl,
        'internalCalculation' => $internalCalculation,
        'images' => [[
            'path' => $imagePath,
            'width' => 120,
            'height' => 80,
            'alt' => 'Rosenquarz Armband',
            'isMain' => true,
        ]],
        'createdAt' => carmaja_api_now(),
        'updatedAt' => carmaja_api_now(),
    ]);
}

function carmaja_api_test_public_product(
    string $draftId,
    string $sku = 'CP-2026-0001'
): array {
    $draft = carmaja_api_test_ready_draft($draftId);
    $draft['sku'] = $sku;
    $draft['slug'] = strtolower($sku) . '-rosenquarz-armband';
    $draft['status'] = 'published';
    $draft['updatedAt'] = '2026-07-28T10:00:00+00:00';

    return carmaja_api_public_product_from_draft($draft);
}

function carmaja_api_test_save_payload(int $expectedVersion): array
{
    return [
        'expectedVersion' => $expectedVersion,
        'status' => 'ready',
        'name' => 'Rosenquarz Armband',
        'materials' => ['Rosenquarz'],
        'metalElements' => [],
        'braceletSize' => '17 cm',
        'stock' => 1,
        'shortDescription' => 'Zartes Testarmband.',
        'careInstructions' => [],
        'internalCalculation' => [],
    ];
}

function carmaja_api_test_json_files(string $directory): array
{
    return glob($directory . DIRECTORY_SEPARATOR . '*.json') ?: [];
}

carmaja_api_test('Authentifizierung mit normalisiertem Benutzer', static function (): void {
    $fixture = carmaja_api_test_fixture();
    $login = carmaja_api_login([
        'username' => '  ADMIN  ',
        'password' => 'secret',
        'deviceName' => 'Telefon 1',
        'publishTarget' => 'test',
    ]);

    carmaja_api_test_same('admin', $login['username'], 'Benutzer wurde nicht normalisiert.');
    carmaja_api_test_same('test', $login['publishTarget'], 'Ziel fehlt in Loginantwort.');
    carmaja_api_test_assert(
        str_starts_with($login['token'], 'ct_'),
        'Login muss ein opakes Gerätetoken ausgeben.'
    );

    $tokens = carmaja_api_load_tokens();
    $stored = reset($tokens['tokens']);
    $encoded = json_encode($tokens, JSON_THROW_ON_ERROR);
    carmaja_api_test_assert(isset($stored['secretHash']), 'Token muss gehasht gespeichert sein.');
    carmaja_api_test_assert(
        !str_contains($encoded, $login['token']),
        'Klartexttoken darf nicht gespeichert werden.'
    );
});

carmaja_api_test('Falsches und unbekanntes Login bleiben ununterscheidbar', static function (): void {
    carmaja_api_test_fixture();
    $wrong = carmaja_api_test_exception(
        static fn (): array => carmaja_api_login([
            'username' => 'admin',
            'password' => 'wrong',
            'deviceName' => 'Telefon',
            'publishTarget' => 'test',
        ]),
        401,
        'invalid_credentials'
    );
    $unknown = carmaja_api_test_exception(
        static fn (): array => carmaja_api_login([
            'username' => 'unknown',
            'password' => 'wrong',
            'deviceName' => 'Telefon',
            'publishTarget' => 'test',
        ]),
        401,
        'invalid_credentials'
    );

    carmaja_api_test_same(
        $wrong->getMessage(),
        $unknown->getMessage(),
        'Fehlermeldungen dürfen Benutzerexistenz nicht verraten.'
    );
});

carmaja_api_test('Zielabweichung erzeugt kein Gerätetoken', static function (): void {
    $fixture = carmaja_api_test_fixture();
    carmaja_api_test_exception(
        static fn (): array => carmaja_api_login([
            'username' => 'admin',
            'password' => 'secret',
            'deviceName' => 'Telefon',
            'publishTarget' => 'production',
        ]),
        409,
        'publish_target_mismatch'
    );

    carmaja_api_test_assert(
        !is_file($fixture['testPrivate'] . '/auth/device-tokens.json'),
        'Zielabweichung darf kein Gerätetoken erzeugen.'
    );
});

carmaja_api_test('Widerrufenes Gerät erhält HTTP 401', static function (): void {
    carmaja_api_test_fixture();
    $login = carmaja_api_login([
        'username' => 'admin',
        'password' => 'secret',
        'deviceName' => 'Telefon',
        'publishTarget' => 'test',
    ]);
    $tokens = carmaja_api_load_tokens();
    $tokenId = $login['tokenId'];
    $tokens['tokens'][$tokenId]['revokedAt'] = carmaja_api_now();
    carmaja_api_store_tokens($tokens);

    carmaja_api_test_exception(
        static fn (): array => carmaja_api_authorize_token('Bearer ' . $login['token']),
        401,
        'device_token_revoked'
    );
});

carmaja_api_test('Fehlende Benutzerdatei wird sicher abgelehnt', static function (): void {
    $fixture = carmaja_api_test_fixture();
    @unlink($fixture['testUsers']);

    carmaja_api_test_exception(
        static fn (): array => carmaja_api_login([
            'username' => 'admin',
            'password' => 'secret',
            'deviceName' => 'Telefon',
            'publishTarget' => 'test',
        ]),
        503,
        'users_file_unavailable'
    );
});

carmaja_api_test('Falsche Benutzerdatei-Umgebung wird abgelehnt', static function (): void {
    $fixture = carmaja_api_test_fixture();
    carmaja_api_test_users_file($fixture['testPrivate'], 'production');

    carmaja_api_test_exception(
        static fn (): array => carmaja_api_login([
            'username' => 'admin',
            'password' => 'secret',
            'deviceName' => 'Telefon',
            'publishTarget' => 'test',
        ]),
        503,
        'data_environment_mismatch'
    );
});

carmaja_api_test('Loginversuche werden ohne IP oder User-Agent begrenzt', static function (): void {
    $fixture = carmaja_api_test_fixture();

    for ($attempt = 0; $attempt < CARMAJA_LOGIN_LIMIT; $attempt++) {
        carmaja_api_test_exception(
            static fn (): array => carmaja_api_login([
                'username' => 'admin',
                'password' => 'wrong',
                'deviceName' => 'Telefon',
                'publishTarget' => 'test',
            ]),
            401,
            'invalid_credentials'
        );
    }

    carmaja_api_test_exception(
        static fn (): array => carmaja_api_login([
            'username' => 'admin',
            'password' => 'wrong',
            'deviceName' => 'Telefon',
            'publishTarget' => 'test',
        ]),
        429,
        'login_rate_limited'
    );

    $audit = implode(
        '',
        array_map(
            static fn (string $path): string => (string) file_get_contents($path),
            glob($fixture['testPrivate'] . '/audit/*.jsonl') ?: []
        )
    );
    carmaja_api_test_assert(
        !str_contains($audit, '203.0.113.10')
            && !str_contains($audit, 'Should-Not-Be-Audited')
            && !str_contains($audit, '"password"')
            && !str_contains($audit, '"secret"'),
        'Auditlog darf keine Netzwerk- oder Zugangsdaten enthalten.'
    );
});

carmaja_api_test('Fehlerantwort enthält keine Secrets', static function (): void {
    $fixture = carmaja_api_test_fixture();
    $error = carmaja_api_test_exception(
        static fn (): array => carmaja_api_login([
            'username' => 'admin',
            'password' => 'secret-value-not-for-output',
            'deviceName' => 'Telefon',
            'publishTarget' => 'test',
        ]),
        401,
        'invalid_credentials'
    );
    $encoded = json_encode(carmaja_api_error_response($error), JSON_THROW_ON_ERROR);

    foreach ([
        'secret-value-not-for-output',
        'passwordHash',
        $fixture['testPrivate'],
        'Stack trace',
    ] as $forbidden) {
        carmaja_api_test_assert(
            !str_contains($encoded, $forbidden),
            'Fehlerantwort enthält vertrauliche Information.'
        );
    }
});

carmaja_api_test('Vinted-URL wird strukturell validiert', static function (): void {
    carmaja_api_test_same(
        null,
        carmaja_api_validate_vinted_url('', false),
        'Leerer Testlink muss zulässig sein.'
    );
    carmaja_api_test_same(
        'https://www.vinted.de/items/123',
        carmaja_api_validate_vinted_url('https://www.vinted.de/items/123', false),
        'Gültiger Link wurde abgelehnt.'
    );

    foreach ([
        'http://vinted.de/items/123',
        'https://vinted.de.fremd.example/items/123',
        'https://user@vinted.de/items/123',
        'https://vinted.de:443/items/123',
        'https://vinted.de/redirect?url=https://example.org',
    ] as $url) {
        carmaja_api_test_exception(
            static fn (): ?string => carmaja_api_validate_vinted_url($url, false),
            422,
            'vinted_url_invalid'
        );
    }

    carmaja_api_test_exception(
        static fn (): ?string => carmaja_api_validate_vinted_url(null, true),
        422,
        'vinted_url_required'
    );
});

carmaja_api_test('Test-Publish ohne Vinted-Link ist erfolgreich', static function (): void {
    $fixture = carmaja_api_test_fixture();
    $draftId = '019fa2e6-cf3c-7073-9275-7d3b566f5401';
    carmaja_api_test_ready_draft($draftId);
    $result = carmaja_api_publish(
        $draftId,
        ['expectedVersion' => 1, 'operationId' => 'test-no-vinted-0001'],
        carmaja_api_test_actor(),
        'published'
    );

    carmaja_api_test_same('published', $result['status'], 'Test-Publish fehlgeschlagen.');
    carmaja_api_test_same('test', $result['publishTarget'], 'Falsches Ziel.');
    carmaja_api_test_same(null, $result['commitSha'], 'Phase 3 darf keinen Commit erzeugen.');

    $public = carmaja_api_read_target_json(
        $fixture['testPrivate'] . '/products/public-products.json',
        [],
        'Öffentliche Testproduktdaten'
    );
    carmaja_api_test_assert(
        !array_key_exists('vintedUrl', $public['products'][0]),
        'Öffentliches Testprodukt darf keinen leeren Vinted-Link enthalten.'
    );
    $publicKeys = array_keys($public['products'][0]);
    sort($publicKeys);
    $expectedKeys = [
        'careInstructions',
        'description',
        'images',
        'materials',
        'metalElements',
        'size',
        'sku',
        'slug',
        'status',
        'stock',
        'title',
        'updatedAt',
    ];
    sort($expectedKeys);
    carmaja_api_test_same(
        $expectedKeys,
        $publicKeys,
        'Öffentliches Produktmodell enthält nicht exakt die Feld-Allowlist.'
    );
});

carmaja_api_test('Vollständiger Testentwurf wird atomar veröffentlicht', static function (): void {
    $fixture = carmaja_api_test_fixture();
    $draftId = '019fa2e6-cf3c-7073-9275-7d3b566f5421';
    $draft = carmaja_api_test_ready_draft($draftId);
    $draft['status'] = 'draft';
    carmaja_api_save_draft($draft);

    $result = carmaja_api_publish(
        $draftId,
        ['expectedVersion' => 1, 'operationId' => 'test-draft-publish-0001'],
        carmaja_api_test_actor(),
        'published'
    );
    $stored = carmaja_api_load_draft($draftId);

    carmaja_api_test_same('published', $result['status'], 'Testentwurf wurde nicht veröffentlicht.');
    carmaja_api_test_same(2, $result['version'], 'Publish muss die Version genau einmal erhöhen.');
    carmaja_api_test_assert(
        is_string($result['sku']) && $result['sku'] !== '',
        'Erfolgreicher Publish muss genau eine SKU vergeben.'
    );
    carmaja_api_test_same('published', $stored['status'], 'Entwurf blieb im falschen Status.');
    carmaja_api_test_same($result['sku'], $stored['sku'], 'Gespeicherte SKU weicht ab.');
    carmaja_api_test_assert(
        is_file($fixture['testPrivate'] . '/products/public-products.json'),
        'Öffentliche Testproduktdaten fehlen.'
    );
});

carmaja_api_test('Unvollständiger Testentwurf bleibt ohne Nebenwirkungen', static function (): void {
    $fixture = carmaja_api_test_fixture();
    $draftId = '019fa2e6-cf3c-7073-9275-7d3b566f5422';
    $draft = carmaja_api_test_ready_draft($draftId);
    $draft['status'] = 'draft';
    $draft['shortDescription'] = '';
    carmaja_api_save_draft($draft);

    carmaja_api_test_exception(
        static fn (): array => carmaja_api_publish(
            $draftId,
            ['expectedVersion' => 1, 'operationId' => 'test-draft-invalid-0001'],
            carmaja_api_test_actor(),
            'published'
        ),
        422,
        'product_not_publishable'
    );
    $stored = carmaja_api_load_draft($draftId);

    carmaja_api_test_same('draft', $stored['status'], 'Ungültiger Entwurf wurde verändert.');
    carmaja_api_test_same(null, $stored['sku'], 'Ungültiger Entwurf erhielt eine SKU.');
    carmaja_api_test_same(
        [],
        carmaja_api_test_json_files($fixture['testPrivate'] . '/idempotency'),
        'Validierungsfehler darf keinen Idempotency-Datensatz erzeugen.'
    );
});

carmaja_api_test('Test-Publish mit gültigem Vinted-Link ist erfolgreich', static function (): void {
    $fixture = carmaja_api_test_fixture();
    $draftId = '019fa2e6-cf3c-7073-9275-7d3b566f5402';
    carmaja_api_test_ready_draft($draftId, 'https://vinted.de/items/123');
    $result = carmaja_api_publish(
        $draftId,
        ['expectedVersion' => 1, 'operationId' => 'test-with-vinted-0001'],
        carmaja_api_test_actor(),
        'published'
    );

    carmaja_api_test_same('published', $result['status'], 'Test-Publish fehlgeschlagen.');
});

carmaja_api_test('Produktion lehnt direkten Draft-Publish weiterhin ab', static function (): void {
    $fixture = carmaja_api_test_fixture();
    carmaja_api_test_use_target($fixture, 'production');
    putenv('CARMAJA_PRODUCTION_PUBLISH_ENABLED=true');
    $draftId = '019fa2e6-cf3c-7073-9275-7d3b566f5423';
    $draft = carmaja_api_test_ready_draft(
        $draftId,
        'https://vinted.de/items/123'
    );
    $draft['status'] = 'draft';
    carmaja_api_save_draft($draft);

    carmaja_api_test_exception(
        static fn (): array => carmaja_api_publish(
            $draftId,
            ['expectedVersion' => 1, 'operationId' => 'production-draft-0001'],
            carmaja_api_test_actor(),
            'published'
        ),
        422,
        'invalid_status_transition'
    );
    $stored = carmaja_api_load_draft($draftId);

    carmaja_api_test_same('draft', $stored['status'], 'Produktionsentwurf wurde verändert.');
    carmaja_api_test_same(null, $stored['sku'], 'Produktionsentwurf erhielt eine SKU.');
    carmaja_api_test_same(
        [],
        carmaja_api_test_json_files($fixture['productionPrivate'] . '/idempotency'),
        'Abgelehnter Produktions-Publish darf keine Operation erzeugen.'
    );
});

carmaja_api_test('Produktions-Publish ohne Link erhält HTTP 422', static function (): void {
    $fixture = carmaja_api_test_fixture();
    carmaja_api_test_use_target($fixture, 'production');
    putenv('CARMAJA_PRODUCTION_PUBLISH_ENABLED=true');
    $draftId = '019fa2e6-cf3c-7073-9275-7d3b566f5403';
    carmaja_api_test_ready_draft($draftId);

    carmaja_api_test_exception(
        static fn (): array => carmaja_api_publish(
            $draftId,
            ['expectedVersion' => 1, 'operationId' => 'production-no-link-0001'],
            carmaja_api_test_actor(),
            'published'
        ),
        422,
        'vinted_url_required'
    );
    carmaja_api_test_same(
        [],
        carmaja_api_test_json_files($fixture['productionPrivate'] . '/idempotency'),
        'Validierungsfehler darf keinen Idempotency-Datensatz erzeugen.'
    );
});

carmaja_api_test('Produktions-Publish mit Fremddomain erhält HTTP 422', static function (): void {
    $fixture = carmaja_api_test_fixture();
    carmaja_api_test_use_target($fixture, 'production');
    putenv('CARMAJA_PRODUCTION_PUBLISH_ENABLED=true');
    $draftId = '019fa2e6-cf3c-7073-9275-7d3b566f5404';
    carmaja_api_test_ready_draft(
        $draftId,
        'https://vinted.de.fremd.example/items/123'
    );

    carmaja_api_test_exception(
        static fn (): array => carmaja_api_publish(
            $draftId,
            ['expectedVersion' => 1, 'operationId' => 'production-bad-link-0001'],
            carmaja_api_test_actor(),
            'published'
        ),
        422,
        'vinted_url_invalid'
    );
});

carmaja_api_test('Deaktivierter Produktions-Publish hat keine Nebenwirkungen', static function (): void {
    $fixture = carmaja_api_test_fixture();
    carmaja_api_test_use_target($fixture, 'production');
    $draftId = '019fa2e6-cf3c-7073-9275-7d3b566f5405';
    carmaja_api_test_ready_draft($draftId, 'https://vinted.de/items/123');
    $adapterCalls = 0;
    $GLOBALS['CARMAJA_API_PUBLISH_ADAPTER'] = static function () use (&$adapterCalls): array {
        $adapterCalls++;
        return ['commitSha' => 'unexpected', 'deploymentStatus' => 'unexpected'];
    };

    carmaja_api_test_exception(
        static fn (): array => carmaja_api_publish(
            $draftId,
            ['expectedVersion' => 1, 'operationId' => 'production-disabled-0001'],
            carmaja_api_test_actor(),
            'published'
        ),
        403,
        'production_publish_disabled'
    );
    $draft = carmaja_api_load_draft($draftId);
    carmaja_api_test_same(null, $draft['sku'], 'Produktionssperre darf keine SKU vergeben.');
    carmaja_api_test_same(0, $adapterCalls, 'Publish-Adapter darf nicht aufgerufen werden.');
    carmaja_api_test_same(
        [],
        carmaja_api_test_json_files($fixture['productionPrivate'] . '/idempotency'),
        'Produktionssperre darf keine Idempotency-Nebenwirkung erzeugen.'
    );
    carmaja_api_test_assert(
        !is_file($fixture['productionPrivate'] . '/products/public-products.json'),
        'Produktionssperre darf keinen öffentlichen Datensatz erzeugen.'
    );
});

carmaja_api_test('ExpectedVersion verhindert Überschreiben durch zwei Geräte', static function (): void {
    carmaja_api_test_fixture();
    $draftId = '019fa2e6-cf3c-7073-9275-7d3b566f5406';
    $first = carmaja_api_save_product(
        $draftId,
        carmaja_api_test_save_payload(0),
        carmaja_api_test_actor()
    );
    carmaja_api_test_same(1, $first['version'], 'Erste Version muss 1 sein.');

    $error = carmaja_api_test_exception(
        static fn (): array => carmaja_api_save_product(
            $draftId,
            carmaja_api_test_save_payload(0),
            ['tokenId' => str_repeat('b', 32), 'username' => 'admin']
        ),
        409,
        'version_conflict'
    );
    carmaja_api_test_same(
        1,
        $error->fields['currentVersion'],
        'Konflikt muss aktuelle Version enthalten.'
    );
    carmaja_api_test_assert(
        is_string($error->fields['updatedAt']),
        'Konflikt muss Änderungszeitpunkt enthalten.'
    );
});

carmaja_api_test('Erfolgreiche Idempotency-Wiederholung ruft Adapter einmal auf', static function (): void {
    carmaja_api_test_fixture();
    $draftId = '019fa2e6-cf3c-7073-9275-7d3b566f5407';
    carmaja_api_test_ready_draft($draftId);
    $calls = 0;
    $GLOBALS['CARMAJA_API_PUBLISH_ADAPTER'] = static function () use (&$calls): array {
        $calls++;
        return [
            'commitSha' => str_repeat('c', 40),
            'deploymentStatus' => 'queued_test',
        ];
    };
    $body = ['expectedVersion' => 1, 'operationId' => 'idempotent-success-0001'];
    $first = carmaja_api_publish(
        $draftId,
        $body,
        carmaja_api_test_actor(),
        'published'
    );
    $second = carmaja_api_publish(
        $draftId,
        $body,
        carmaja_api_test_actor(),
        'published'
    );

    carmaja_api_test_same($first, $second, 'Wiederholung muss Originalantwort liefern.');
    carmaja_api_test_same(1, $calls, 'Adapter darf nur einmal aufgerufen werden.');
});

carmaja_api_test('Gleiche operationId mit anderem Payload wird abgelehnt', static function (): void {
    carmaja_api_test_fixture();
    $draftId = '019fa2e6-cf3c-7073-9275-7d3b566f5408';
    carmaja_api_test_ready_draft($draftId);
    carmaja_api_publish(
        $draftId,
        ['expectedVersion' => 1, 'operationId' => 'idempotency-conflict-0001'],
        carmaja_api_test_actor(),
        'published'
    );

    carmaja_api_test_exception(
        static fn (): array => carmaja_api_publish(
            $draftId,
            ['expectedVersion' => 2, 'operationId' => 'idempotency-conflict-0001'],
            carmaja_api_test_actor(),
            'published'
        ),
        409,
        'idempotency_key_reused'
    );
});

carmaja_api_test('Verwaistes in_progress wird sicher fortgesetzt', static function (): void {
    carmaja_api_test_fixture();
    $draftId = '019fa2e6-cf3c-7073-9275-7d3b566f5409';
    $operationId = 'orphaned-operation-0001';
    carmaja_api_test_ready_draft($draftId);
    $requestHash = carmaja_api_request_hash([
        'draftId' => $draftId,
        'expectedVersion' => 1,
        'operationId' => $operationId,
        'status' => 'published',
        'publishTarget' => 'test',
    ]);
    carmaja_api_write_json_atomic(
        carmaja_api_idempotency_path($operationId),
        carmaja_api_target_document([
            'operationId' => $operationId,
            'requestHash' => $requestHash,
            'publishTarget' => 'test',
            'productId' => $draftId,
            'phase' => 'validated',
            'status' => 'in_progress',
            'createdAt' => carmaja_api_timestamp(-1800),
            'updatedAt' => carmaja_api_timestamp(-1800),
            'expiresAt' => carmaja_api_timestamp(-900),
            'reservedSku' => null,
            'commitSha' => null,
            'savedResponse' => null,
        ])
    );

    $result = carmaja_api_publish(
        $draftId,
        ['expectedVersion' => 1, 'operationId' => $operationId],
        carmaja_api_test_actor(),
        'published'
    );
    carmaja_api_test_same('published', $result['status'], 'Verwaiste Operation wurde nicht fortgesetzt.');
});

carmaja_api_test('Retrybarer Fehler wird mit derselben SKU fortgesetzt', static function (): void {
    $fixture = carmaja_api_test_fixture();
    $draftId = '019fa2e6-cf3c-7073-9275-7d3b566f5410';
    carmaja_api_test_ready_draft($draftId);
    $attempts = 0;
    $sideEffects = 0;
    $GLOBALS['CARMAJA_API_PUBLISH_ADAPTER'] = static function () use (
        &$attempts,
        &$sideEffects
    ): array {
        $attempts++;

        if ($attempts === 1) {
            throw new CarmajaApiException(
                503,
                'Temporär nicht verfügbar.',
                [],
                'adapter_temporarily_unavailable'
            );
        }

        $sideEffects++;
        return [
            'commitSha' => str_repeat('d', 40),
            'deploymentStatus' => 'queued_test',
        ];
    };
    $body = ['expectedVersion' => 1, 'operationId' => 'retryable-operation-0001'];

    carmaja_api_test_exception(
        static fn (): array => carmaja_api_publish(
            $draftId,
            $body,
            carmaja_api_test_actor(),
            'published'
        ),
        503,
        'adapter_temporarily_unavailable'
    );
    $afterFailure = carmaja_api_load_draft($draftId);
    $result = carmaja_api_publish(
        $draftId,
        $body,
        carmaja_api_test_actor(),
        'published'
    );

    carmaja_api_test_same($afterFailure['sku'], $result['sku'], 'SKU muss stabil bleiben.');
    carmaja_api_test_same(2, $attempts, 'Retry muss Adapter erneut versuchen.');
    carmaja_api_test_same(1, $sideEffects, 'Externe Nebenwirkung darf einmal erfolgen.');
    $counter = carmaja_api_read_target_json(
        $fixture['testPrivate'] . '/sku-counter/counter.json',
        [],
        'SKU-Zähler'
    );
    carmaja_api_test_same(1, count($counter['reservations']), 'SKU darf einmal reserviert werden.');
});

carmaja_api_test('Finaler Fehler wird reproduzierbar gespeichert', static function (): void {
    carmaja_api_test_fixture();
    $draftId = '019fa2e6-cf3c-7073-9275-7d3b566f5411';
    carmaja_api_test_ready_draft($draftId);
    $calls = 0;
    $GLOBALS['CARMAJA_API_PUBLISH_ADAPTER'] = static function () use (&$calls): array {
        $calls++;
        throw new CarmajaApiException(
            422,
            'Finaler Adapterfehler.',
            ['publish' => 'Nicht wiederholbar.'],
            'adapter_final_error'
        );
    };
    $body = ['expectedVersion' => 1, 'operationId' => 'final-operation-0001'];
    $first = carmaja_api_test_exception(
        static fn (): array => carmaja_api_publish(
            $draftId,
            $body,
            carmaja_api_test_actor(),
            'published'
        ),
        422,
        'adapter_final_error'
    );
    $second = carmaja_api_test_exception(
        static fn (): array => carmaja_api_publish(
            $draftId,
            $body,
            carmaja_api_test_actor(),
            'published'
        ),
        422,
        'adapter_final_error'
    );

    carmaja_api_test_same($first->getMessage(), $second->getMessage(), 'Finaler Fehler muss stabil sein.');
    carmaja_api_test_same(1, $calls, 'Finaler Fehler darf Adapter nicht erneut aufrufen.');
});

carmaja_api_test('Gültiges JPEG wird bereinigt und neu gespeichert', static function (): void {
    $fixture = carmaja_api_test_fixture();
    $source = $fixture['root'] . '/source.jpg';
    $target = $fixture['root'] . '/clean.jpg';
    carmaja_api_test_create_jpeg($source, 120, 80);
    $info = carmaja_api_process_jpeg(
        $source,
        'photo.jpg',
        filesize($source),
        $target,
        false
    );

    carmaja_api_test_same(120, $info['width'], 'JPEG-Breite stimmt nicht.');
    carmaja_api_test_same(80, $info['height'], 'JPEG-Höhe stimmt nicht.');
    carmaja_api_test_assert(is_file($target), 'Bereinigtes JPEG fehlt.');
});

carmaja_api_test('Bildendung und Dateiinhalt werden getrennt validiert', static function (): void {
    $fixture = carmaja_api_test_fixture();
    $source = $fixture['root'] . '/source.jpg';
    $target = $fixture['root'] . '/target.jpg';
    carmaja_api_test_create_jpeg($source);

    carmaja_api_test_exception(
        static fn (): array => carmaja_api_process_jpeg(
            $source,
            'photo.png',
            filesize($source),
            $target,
            false
        ),
        422,
        'image_extension_invalid'
    );

    file_put_contents($source, 'not-a-jpeg');
    carmaja_api_test_exception(
        static fn (): array => carmaja_api_process_jpeg(
            $source,
            'photo.jpg',
            filesize($source),
            $target,
            false
        ),
        422,
        'image_content_invalid'
    );
});

carmaja_api_test('Zu große Datei und zu große Kante werden abgelehnt', static function (): void {
    $fixture = carmaja_api_test_fixture();
    $largeFile = $fixture['root'] . '/large.jpg';
    $largeEdge = $fixture['root'] . '/edge.jpg';
    $target = $fixture['root'] . '/target.jpg';
    file_put_contents($largeFile, str_repeat('x', CARMAJA_MAX_IMAGE_BYTES + 1));

    carmaja_api_test_exception(
        static fn (): array => carmaja_api_process_jpeg(
            $largeFile,
            'large.jpg',
            filesize($largeFile),
            $target,
            false
        ),
        413,
        'image_too_large'
    );

    carmaja_api_test_create_jpeg($largeEdge, CARMAJA_MAX_IMAGE_EDGE + 1, 2);
    carmaja_api_test_exception(
        static fn (): array => carmaja_api_process_jpeg(
            $largeEdge,
            'edge.jpg',
            filesize($largeEdge),
            $target,
            false
        ),
        422,
        'image_dimensions_invalid'
    );
});

carmaja_api_test('Bildmanifest ist auf fünf eindeutige IDs begrenzt', static function (): void {
    carmaja_api_test_fixture();
    $_POST = [
        'expectedVersion' => '1',
        'imageId' => '00000000-0000-4000-8000-000000000001',
        'desiredImageIds' => json_encode([
            '00000000-0000-4000-8000-000000000001',
            '00000000-0000-4000-8000-000000000002',
            '00000000-0000-4000-8000-000000000003',
            '00000000-0000-4000-8000-000000000004',
            '00000000-0000-4000-8000-000000000005',
            '00000000-0000-4000-8000-000000000006',
        ], JSON_THROW_ON_ERROR),
    ];

    carmaja_api_test_exception(
        static fn (): array => carmaja_api_upload_images(
            '019fa2e6-cf3c-7073-9275-7d3b566f5412',
            [],
            carmaja_api_test_actor()
        ),
        422,
        'image_manifest_invalid'
    );
});

carmaja_api_test(
    'Einzelbilder verwenden fortlaufende Versionen und bleiben idempotent',
    static function (): void {
        $fixture = carmaja_api_test_fixture();
        $draftId = '019fa2e6-cf3c-7073-9275-7d3b566f5412';
        $firstId = '00000000-0000-4000-8000-000000000001';
        $secondId = '00000000-0000-4000-8000-000000000002';
        $desiredIds = [$firstId, $secondId];
        $firstSource = $fixture['root'] . '/first.jpg';
        $secondSource = $fixture['root'] . '/second.jpg';
        carmaja_api_test_create_jpeg($firstSource);
        carmaja_api_test_create_jpeg($secondSource, 100, 100);
        $GLOBALS['CARMAJA_API_ALLOW_LOCAL_UPLOADS_FOR_TESTS'] = true;

        try {
            $saved = carmaja_api_save_product(
                $draftId,
                carmaja_api_test_save_payload(0),
                carmaja_api_test_actor()
            );
            carmaja_api_test_same(1, $saved['version'], 'Speichern muss Version 1 liefern.');

            carmaja_api_test_prepare_image_upload(
                1,
                $firstId,
                $desiredIds,
                $firstSource,
                'Hauptbild'
            );
            $first = carmaja_api_upload_images($draftId, [], carmaja_api_test_actor());
            carmaja_api_test_same(2, $first['version'], 'Erster Upload muss Version 2 liefern.');
            carmaja_api_test_same(
                $firstId,
                $first['images'][0]['imageId'] ?? null,
                'Server muss stabile imageId bestätigen.'
            );
            carmaja_api_test_same(
                '01.jpg',
                $first['images'][0]['fileName'] ?? null,
                'Dateiname muss serverseitig aus dem Index entstehen.'
            );

            carmaja_api_test_prepare_image_upload(
                2,
                $secondId,
                $desiredIds,
                $secondSource,
                'Detailbild'
            );
            $second = carmaja_api_upload_images($draftId, [], carmaja_api_test_actor());
            carmaja_api_test_same(3, $second['version'], 'Zweiter Upload muss Version 3 liefern.');
            carmaja_api_test_same(2, count($second['images']), 'Beide Bilder müssen bestätigt sein.');
            carmaja_api_test_same(
                [$firstId, $secondId],
                array_column($second['images'], 'imageId'),
                'Serverreihenfolge muss dem Bildmanifest entsprechen.'
            );

            carmaja_api_test_prepare_image_upload(
                3,
                $secondId,
                $desiredIds,
                $secondSource,
                'Detailbild'
            );
            $repeated = carmaja_api_upload_images($draftId, [], carmaja_api_test_actor());
            carmaja_api_test_same(
                3,
                $repeated['version'],
                'Identischer Retry darf die Version nicht erneut erhöhen.'
            );
            carmaja_api_test_same(
                2,
                count($repeated['images']),
                'Identischer Retry darf kein Duplikat erzeugen.'
            );

            carmaja_api_test_prepare_image_upload(
                1,
                $secondId,
                $desiredIds,
                $secondSource,
                'Detailbild'
            );
            carmaja_api_test_exception(
                static fn (): array => carmaja_api_upload_images(
                    $draftId,
                    [],
                    carmaja_api_test_actor()
                ),
                409,
                'version_conflict'
            );

            $auditPath = $fixture['testPrivate']
                . '/audit/actions-'
                . gmdate('Y-m')
                . '.jsonl';
            $audit = (string) file_get_contents($auditPath);
            carmaja_api_test_assert(
                str_contains($audit, '"action":"image_upload_started"')
                    && str_contains($audit, '"action":"image_upload_succeeded"')
                    && str_contains($audit, '"action":"image_upload_rejected"'),
                'Sichere Bild-Upload-Ereignisse fehlen im Auditlog.'
            );
            carmaja_api_test_assert(
                !str_contains($audit, $firstSource)
                    && !str_contains($audit, $secondSource)
                    && !str_contains($audit, 'Should-Not-Be-Audited')
                    && !str_contains($audit, '203.0.113.10'),
                'Auditlog darf keine Pfade, IP-Adresse oder User-Agent enthalten.'
            );
        } finally {
            unset($GLOBALS['CARMAJA_API_ALLOW_LOCAL_UPLOADS_FOR_TESTS']);
        }
    }
);

carmaja_api_test('EXIF und Standortmarker werden entfernt', static function (): void {
    $fixture = carmaja_api_test_fixture();
    $source = $fixture['root'] . '/exif.jpg';
    $target = $fixture['root'] . '/clean.jpg';
    carmaja_api_test_create_jpeg($source);
    $raw = (string) file_get_contents($source);
    $payload = "Exif\0\0GPSLatitude\0TestLocation";
    $segment = "\xFF\xE1" . pack('n', strlen($payload) + 2) . $payload;
    file_put_contents($source, substr($raw, 0, 2) . $segment . substr($raw, 2));

    carmaja_api_process_jpeg(
        $source,
        'exif.jpg',
        filesize($source),
        $target,
        false
    );
    $clean = (string) file_get_contents($target);
    carmaja_api_test_assert(
        !str_contains($clean, 'Exif')
            && !str_contains($clean, 'GPSLatitude')
            && !str_contains($clean, 'TestLocation'),
        'Neu gespeichertes JPEG darf keine EXIF-/Standortdaten enthalten.'
    );
});

carmaja_api_test('Unsicherer Dateiname beeinflusst Zielpfad nicht', static function (): void {
    $fixture = carmaja_api_test_fixture();
    $source = $fixture['root'] . '/source.jpg';
    $targetDirectory = $fixture['root'] . '/safe';
    $target = $targetDirectory . '/01.jpg';
    mkdir($targetDirectory, 0750, true);
    carmaja_api_test_create_jpeg($source);
    carmaja_api_process_jpeg(
        $source,
        '../../../../outside.jpg',
        filesize($source),
        $target,
        false
    );

    carmaja_api_test_assert(is_file($target), 'Serverseitiger Zielname wurde nicht verwendet.');
    carmaja_api_test_assert(
        !is_file($fixture['root'] . '/outside.jpg'),
        'Nutzergesteuerter Dateiname darf keinen Pfad erzeugen.'
    );
});

carmaja_api_test('Unvollständiger Upload blockiert Publish', static function (): void {
    carmaja_api_test_fixture();
    $draftId = '019fa2e6-cf3c-7073-9275-7d3b566f5413';
    $draft = carmaja_api_test_ready_draft($draftId);
    @unlink($draft['images'][0]['path']);

    carmaja_api_test_exception(
        static fn (): array => carmaja_api_publish(
            $draftId,
            ['expectedVersion' => 1, 'operationId' => 'missing-image-operation-0001'],
            carmaja_api_test_actor(),
            'published'
        ),
        422,
        'product_images_incomplete'
    );
});

carmaja_api_test('Öffentliche Daten enthalten keine internen Werte', static function (): void {
    $fixture = carmaja_api_test_fixture();
    $draftId = '019fa2e6-cf3c-7073-9275-7d3b566f5414';
    carmaja_api_test_ready_draft($draftId, null, [
        'materialCosts' => 'TESTSECRET-PRIVATE-COST',
        'recommendedSalePrice' => '999.99',
    ]);
    carmaja_api_publish(
        $draftId,
        ['expectedVersion' => 1, 'operationId' => 'public-data-operation-0001'],
        carmaja_api_test_actor(),
        'published'
    );
    $publicRaw = (string) file_get_contents(
        $fixture['testPrivate'] . '/products/public-products.json'
    );

    foreach ([
        'internalCalculation',
        'materialCosts',
        'recommendedSalePrice',
        'TESTSECRET-PRIVATE-COST',
        $fixture['testPrivate'],
        '"vintedUrl": ""',
    ] as $forbidden) {
        carmaja_api_test_assert(
            !str_contains($publicRaw, $forbidden),
            'Öffentliche Produktdaten enthalten privaten Wert: ' . $forbidden
        );
    }
});

carmaja_api_test('GitHub-Adapter bleibt standardmäßig deaktiviert', static function (): void {
    carmaja_api_test_fixture();

    carmaja_api_test_exception(
        static fn (): bool => carmaja_api_github_adapter_enabled()
            ? true
            : throw new CarmajaApiException(
                503,
                'GitHub-Testadapter ist deaktiviert.',
                [],
                'github_adapter_disabled'
            ),
        503,
        'github_adapter_disabled'
    );
});

carmaja_api_test(
    'Interaktiver GitHub-Token ist nur fuer deaktivierte Nur-Lese-Diagnose verfuegbar',
    static function (): void {
        carmaja_api_test_fixture();
        putenv('CARMAJA_GITHUB_REPOSITORY=' . CARMAJA_TEST_REPOSITORY);
        putenv('CARMAJA_GITHUB_BRANCH=' . CARMAJA_TEST_BRANCH);
        $candidate = 'github_' . 'pat_FAKE_READONLY_TEST_123';
        $GLOBALS['CARMAJA_API_GITHUB_READONLY_TOKEN'] = $candidate;

        carmaja_api_test_same(
            $candidate,
            carmaja_api_github_token(false),
            'Nur-Lese-Diagnose hat den In-Memory-Token nicht verwendet.'
        );

        putenv('CARMAJA_GITHUB_ADAPTER_ENABLED=true');
        carmaja_api_test_exception(
            static fn (): string => carmaja_api_github_token(true),
            503,
            'service_unavailable'
        );

        foreach ([
            '',
            'github_pat_FAKE TOKEN',
            'github_' . 'pat_FAKEgithub_' . 'pat_SECOND',
            'prefix_github_pat_FAKE',
        ] as $invalidToken) {
            carmaja_api_test_exception(
                static fn (): string =>
                    carmaja_api_validate_github_token($invalidToken),
                400,
                'github_token_invalid'
            );
        }

        unset($GLOBALS['CARMAJA_API_GITHUB_READONLY_TOKEN']);
    }
);

carmaja_api_test('GitHub-Testbranch und Pfad-Allowlist sind fest', static function (): void {
    $fixture = carmaja_api_test_fixture();
    putenv('CARMAJA_GITHUB_ADAPTER_ENABLED=true');
    putenv('CARMAJA_GITHUB_REPOSITORY=Bumpers210/armband-rechner');
    putenv('CARMAJA_GITHUB_BRANCH=main');

    carmaja_api_test_exception(
        static fn (): string => carmaja_api_github_branch(),
        503,
        'github_branch_mismatch'
    );

    putenv('CARMAJA_GITHUB_BRANCH=' . CARMAJA_TEST_BRANCH);
    carmaja_api_test_same(
        CARMAJA_TEST_BRANCH,
        carmaja_api_github_branch(),
        'Erlaubter Testbranch wurde abgelehnt.'
    );
    putenv('CARMAJA_GITHUB_REPOSITORY=other/example');
    carmaja_api_test_exception(
        static fn (): string => carmaja_api_github_repository(),
        503,
        'service_unavailable'
    );
    putenv('CARMAJA_GITHUB_REPOSITORY=' . CARMAJA_TEST_REPOSITORY);

    foreach ([
        '.github/workflows/deploy.yml',
        'app/src/main/AndroidManifest.xml',
        'website/app/page.tsx',
        'website/impressum/page.tsx',
        'website/public/images/products/CP-2026-0001/06.jpg',
        'website/public/images/products/CP-2026-0001/../01.jpg',
    ] as $path) {
        carmaja_api_test_exception(
            static function () use ($path): void {
                carmaja_api_assert_repo_path_allowed($path);
            },
            500,
            'internal_error'
        );
    }

    carmaja_api_assert_repo_path_allowed('website/content/products.json');
    carmaja_api_assert_repo_path_allowed(
        'website/public/images/products/CP-2026-0001/01.jpg'
    );
});

carmaja_api_test(
    'GitHub-Adapter ist mit Mocks idempotent und entfernt nur veraltete SKU-Bilder',
    static function (): void {
        carmaja_api_test_fixture();
        putenv('CARMAJA_GITHUB_ADAPTER_ENABLED=true');
        putenv('CARMAJA_GITHUB_REPOSITORY=Bumpers210/armband-rechner');
        putenv('CARMAJA_GITHUB_BRANCH=' . CARMAJA_TEST_BRANCH);
        $publicProduct = carmaja_api_test_public_product(
            '019fa2e6-cf3c-7073-9275-7d3b566f5490'
        );
        $headSha = str_repeat('a', 40);
        $treeSha = str_repeat('b', 40);
        $newTreeSha = str_repeat('c', 40);
        $commitSha = str_repeat('d', 40);
        $calls = [];
        $treeBody = null;
        $existingProduct = array_diff_key($publicProduct, ['_imageBlobs' => true]);
        $existingProduct['images'][] = [
            'src' => '/images/products/CP-2026-0001/02.jpg',
            'alt' => 'Veraltetes Bild',
            'width' => 120,
            'height' => 80,
            'isMain' => false,
        ];
        $GLOBALS['CARMAJA_API_GITHUB_REQUEST_ADAPTER'] =
            static function (
                string $method,
                string $path,
                ?array $body
            ) use (
                &$calls,
                &$treeBody,
                $headSha,
                $treeSha,
                $newTreeSha,
                $commitSha,
                $existingProduct
            ): array {
                $calls[] = [$method, $path];

                if ($method === 'GET' && str_contains($path, '/git/ref/heads/')) {
                    return ['object' => ['sha' => $headSha]];
                }

                if ($method === 'GET' && str_contains($path, '/git/commits/')) {
                    return ['tree' => ['sha' => $treeSha]];
                }

                if ($method === 'GET' && str_contains($path, '/contents/')) {
                    return [
                        'content' => base64_encode(json_encode([
                            'version' => 1,
                            'products' => [$existingProduct],
                        ], JSON_THROW_ON_ERROR)),
                    ];
                }

                if ($method === 'POST' && str_ends_with($path, '/git/blobs')) {
                    return ['sha' => str_repeat('e', 40)];
                }

                if ($method === 'POST' && str_ends_with($path, '/git/trees')) {
                    $treeBody = $body;
                    return ['sha' => $newTreeSha];
                }

                if ($method === 'POST' && str_ends_with($path, '/git/commits')) {
                    return ['sha' => $commitSha];
                }

                if ($method === 'PATCH' && str_contains($path, '/git/refs/heads/')) {
                    carmaja_api_test_same(
                        false,
                        $body['force'] ?? null,
                        'GitHub-Ref darf niemals mit Force aktualisiert werden.'
                    );
                    return ['object' => ['sha' => $commitSha]];
                }

                throw new CarmajaApiTestFailure(
                    'Unerwarteter GitHub-Mock-Aufruf: ' . $method . ' ' . $path
                );
            };
        $operation = [
            'operationId' => 'github-mock-operation-0001',
            'requestHash' => hash('sha256', 'github-mock-operation-0001'),
        ];

        $first = carmaja_api_github_publish_adapter($publicProduct, $operation);
        $firstCallCount = count($calls);
        $second = carmaja_api_github_publish_adapter($publicProduct, $operation);

        carmaja_api_test_same($first, $second, 'Idempotente Antwort weicht ab.');
        carmaja_api_test_same(
            $firstCallCount,
            count($calls),
            'Idempotente Wiederholung hat weitere GitHub-Aufrufe erzeugt.'
        );
        carmaja_api_test_same(
            $commitSha,
            $first['commitSha'],
            'Commit-SHA des Mock-Adapters fehlt.'
        );
        carmaja_api_test_same(
            'queued',
            $first['deploymentStatus'],
            'GitHub-Commit muss den Testdeploy als wartend markieren.'
        );
        carmaja_api_test_assert(
            is_array($treeBody['tree'] ?? null),
            'GitHub-Tree wurde nicht erzeugt.'
        );

        $treeEntries = $treeBody['tree'];
        $paths = array_column($treeEntries, 'path');
        carmaja_api_test_assert(
            in_array('website/content/products.json', $paths, true)
                && in_array(
                    'website/public/images/products/CP-2026-0001/01.jpg',
                    $paths,
                    true
                )
                && in_array(
                    'website/public/images/products/CP-2026-0001/02.jpg',
                    $paths,
                    true
                ),
            'GitHub-Tree enthält nicht exakt Produktdaten, aktuelles und veraltetes Bild.'
        );

        foreach ($treeEntries as $entry) {
            carmaja_api_assert_repo_path_allowed((string) $entry['path']);

            if (($entry['path'] ?? null)
                === 'website/public/images/products/CP-2026-0001/02.jpg') {
                carmaja_api_test_assert(
                    array_key_exists('sha', $entry),
                    'Bildlöschung benötigt einen expliziten null-SHA.'
                );
                carmaja_api_test_same(
                    null,
                    $entry['sha'],
                    'Veraltetes Bild muss als Löschung markiert sein.'
                );
            }
        }
    }
);

carmaja_api_test('GitHub-Adapter lehnt geänderten Remote-HEAD ab', static function (): void {
    carmaja_api_test_fixture();
    putenv('CARMAJA_GITHUB_ADAPTER_ENABLED=true');
    putenv('CARMAJA_GITHUB_REPOSITORY=Bumpers210/armband-rechner');
    putenv('CARMAJA_GITHUB_BRANCH=' . CARMAJA_TEST_BRANCH);
    $publicProduct = carmaja_api_test_public_product(
        '019fa2e6-cf3c-7073-9275-7d3b566f5491',
        'CP-2026-0002'
    );
    $headCalls = 0;
    $patchCalls = 0;
    $GLOBALS['CARMAJA_API_GITHUB_REQUEST_ADAPTER'] =
        static function (
            string $method,
            string $path,
            ?array $body
        ) use (&$headCalls, &$patchCalls): array {
            if ($method === 'GET' && str_contains($path, '/git/ref/heads/')) {
                $headCalls++;
                return [
                    'object' => [
                        'sha' => str_repeat($headCalls === 1 ? 'a' : 'f', 40),
                    ],
                ];
            }

            if ($method === 'GET' && str_contains($path, '/git/commits/')) {
                return ['tree' => ['sha' => str_repeat('b', 40)]];
            }

            if ($method === 'GET' && str_contains($path, '/contents/')) {
                return [
                    'content' => base64_encode('{"version":1,"products":[]}'),
                ];
            }

            if ($method === 'POST' && str_ends_with($path, '/git/blobs')) {
                return ['sha' => str_repeat('c', 40)];
            }

            if ($method === 'POST' && str_ends_with($path, '/git/trees')) {
                return ['sha' => str_repeat('d', 40)];
            }

            if ($method === 'POST' && str_ends_with($path, '/git/commits')) {
                return ['sha' => str_repeat('e', 40)];
            }

            if ($method === 'PATCH') {
                $patchCalls++;
                return [];
            }

            if ($method === 'GET' && str_contains($path, '/compare/')) {
                return [
                    'status' => 'diverged',
                    'merge_base_commit' => ['sha' => str_repeat('a', 40)],
                ];
            }

            throw new CarmajaApiTestFailure('Unerwarteter GitHub-Mock-Aufruf.');
        };

    $operation = [
        'operationId' => 'github-head-change-0001',
        'requestHash' => hash('sha256', 'github-head-change-0001'),
    ];
    carmaja_api_test_exception(
        static fn (): string => carmaja_api_commit_public_product(
            $publicProduct,
            $operation,
            [],
            static function (string $baseHeadSha, string $commitSha): void {
            }
        ),
        409,
        'github_head_changed'
    );
    carmaja_api_test_same(0, $patchCalls, 'Geänderter Remote-HEAD darf nicht gepatcht werden.');
});

carmaja_api_test(
    'GitHub-Adapter setzt nach verlorener PATCH-Antwort keinen zweiten Commit',
    static function (): void {
        carmaja_api_test_fixture();
        putenv('CARMAJA_GITHUB_ADAPTER_ENABLED=true');
        putenv('CARMAJA_GITHUB_REPOSITORY=' . CARMAJA_TEST_REPOSITORY);
        putenv('CARMAJA_GITHUB_BRANCH=' . CARMAJA_TEST_BRANCH);
        $publicProduct = carmaja_api_test_public_product(
            '019fa2e6-cf3c-7073-9275-7d3b566f5492',
            'CP-2026-0003'
        );
        $baseHeadSha = str_repeat('a', 40);
        $commitSha = str_repeat('d', 40);
        $remoteHeadSha = $baseHeadSha;
        $commitCalls = 0;
        $patchCalls = 0;
        $GLOBALS['CARMAJA_API_GITHUB_REQUEST_ADAPTER'] =
            static function (
                string $method,
                string $path,
                ?array $body
            ) use (
                &$remoteHeadSha,
                &$commitCalls,
                &$patchCalls,
                $commitSha
            ): array {
                if ($method === 'GET' && str_contains($path, '/git/ref/heads/')) {
                    return ['object' => ['sha' => $remoteHeadSha]];
                }

                if ($method === 'GET' && str_contains($path, '/git/commits/')) {
                    return ['tree' => ['sha' => str_repeat('b', 40)]];
                }

                if ($method === 'GET' && str_contains($path, '/contents/')) {
                    return [
                        'content' => base64_encode('{"version":1,"products":[]}'),
                    ];
                }

                if ($method === 'POST' && str_ends_with($path, '/git/blobs')) {
                    return ['sha' => str_repeat('c', 40)];
                }

                if ($method === 'POST' && str_ends_with($path, '/git/trees')) {
                    return ['sha' => str_repeat('e', 40)];
                }

                if ($method === 'POST' && str_ends_with($path, '/git/commits')) {
                    $commitCalls++;
                    return ['sha' => $commitSha];
                }

                if ($method === 'PATCH' && str_contains($path, '/git/refs/heads/')) {
                    $patchCalls++;
                    $remoteHeadSha = $commitSha;
                    throw new CarmajaApiException(
                        502,
                        'Simulierter Verbindungsabbruch.',
                        [],
                        'github_response_lost'
                    );
                }

                throw new CarmajaApiTestFailure(
                    'Unerwarteter GitHub-Mock-Aufruf: ' . $method . ' ' . $path
                );
            };
        $operation = [
            'operationId' => 'github-lost-response-0001',
            'requestHash' => hash('sha256', 'github-lost-response-0001'),
        ];

        carmaja_api_test_exception(
            static fn (): array =>
                carmaja_api_github_publish_adapter($publicProduct, $operation),
            502,
            'github_response_lost'
        );
        $result = carmaja_api_github_publish_adapter($publicProduct, $operation);

        carmaja_api_test_same($commitSha, $result['commitSha'], 'Commit wurde nicht wiedererkannt.');
        carmaja_api_test_same(1, $commitCalls, 'Retry hat einen zweiten Commit erzeugt.');
        carmaja_api_test_same(1, $patchCalls, 'Retry hat den Ref erneut geschrieben.');
    }
);

carmaja_api_test(
    'GitHub-Diagnose bleibt lesend und Actions-Status wird fest zugeordnet',
    static function (): void {
        carmaja_api_test_fixture();
        putenv('CARMAJA_GITHUB_REPOSITORY=' . CARMAJA_TEST_REPOSITORY);
        putenv('CARMAJA_GITHUB_BRANCH=' . CARMAJA_TEST_BRANCH);
        $commitSha = str_repeat('f', 40);
        $calls = [];
        $GLOBALS['CARMAJA_API_GITHUB_REQUEST_ADAPTER'] =
            static function (
                string $method,
                string $path,
                ?array $body
            ) use (&$calls, $commitSha): array {
                $calls[] = [$method, $path, $body];

                if (str_contains($path, '/git/ref/heads/')) {
                    return ['object' => ['sha' => $commitSha]];
                }

                if (str_contains($path, '/contents/website/content/products.json')) {
                    return ['content' => base64_encode('{"version":1,"products":[]}')];
                }

                if (str_contains($path, '/actions/workflows/')) {
                    return [
                        'workflow_runs' => [[
                            'id' => 123456,
                            'head_sha' => $commitSha,
                            'head_branch' => CARMAJA_TEST_BRANCH,
                            'event' => 'push',
                            'status' => 'completed',
                            'conclusion' => 'success',
                            'html_url' =>
                                'https://github.com/Bumpers210/armband-rechner/actions/runs/123456',
                        ]],
                    ];
                }

                throw new CarmajaApiTestFailure('Unerwarteter Diagnoseaufruf.');
            };

        $diagnostic = carmaja_api_github_readonly_diagnostic();
        carmaja_api_test_same(false, $diagnostic['writePerformed'], 'Diagnose darf nicht schreiben.');
        carmaja_api_test_assert(
            array_reduce(
                $calls,
                static fn (bool $onlyGet, array $call): bool =>
                    $onlyGet && $call[0] === 'GET',
                true
            ),
            'Lesende Diagnose hat eine mutierende GitHub-Anfrage erzeugt.'
        );

        putenv('CARMAJA_GITHUB_ADAPTER_ENABLED=true');
        $status = carmaja_api_github_deployment_status($commitSha);
        carmaja_api_test_same('succeeded', $status['deploymentStatus'], 'Actions-Status ist falsch.');
        carmaja_api_test_same(123456, $status['workflowRunId'], 'Run-ID fehlt.');
        carmaja_api_test_assert(
            str_contains($calls[array_key_last($calls)][1], CARMAJA_TEST_DEPLOY_WORKFLOW),
            'Statusabfrage verwendet nicht den festen Testworkflow.'
        );
    }
);

carmaja_api_test('IONOS-Diagnose erlaubt fehlende Produktionspfade im Testmodus', static function (): void {
    $fixture = carmaja_api_test_fixture();
    $result = carmaja_api_diagnose_environment();
    $encoded = json_encode($result, JSON_THROW_ON_ERROR);

    carmaja_api_test_same(true, $result['ok'], 'Diagnose muss erfolgreich sein.');
    carmaja_api_test_same('test', $result['publishTarget'], 'Diagnoseziel stimmt nicht.');
    carmaja_api_test_assert(
        !str_contains($encoded, $fixture['root']),
        'Diagnoseausgabe darf keine privaten Pfade enthalten.'
    );

    putenv('CARMAJA_PRODUCTION_PRIVATE_DIR');
    putenv('CARMAJA_PRODUCTION_API_WEBROOT');
    putenv('CARMAJA_PRODUCTION_WEBSITE_WEBROOT');
    $withoutProductionPaths = carmaja_api_diagnose_environment();
    carmaja_api_test_same(
        true,
        $withoutProductionPaths['ok'],
        'Im Testmodus dürfen Produktionspfade vollständig fehlen.'
    );

    unlink($fixture['testUsers']);
    $beforeFirstUser = carmaja_api_diagnose_environment();
    carmaja_api_test_same(
        true,
        $beforeFirstUser['ok'],
        'Diagnose muss vor dem ersten per CLI erzeugten Benutzer möglich sein.'
    );

    putenv('CARMAJA_PRODUCTION_PRIVATE_DIR=' . $fixture['productionPrivate']);
    putenv('CARMAJA_PRODUCTION_API_WEBROOT=' . $fixture['productionApi']);
    putenv('CARMAJA_PRODUCTION_WEBSITE_WEBROOT=' . $fixture['productionWebsite']);
    putenv('CARMAJA_TEST_WEBSITE_WEBROOT=' . $fixture['testApi']);

    try {
        carmaja_api_test_exception(
            static fn (): array => carmaja_api_diagnose_environment(),
            503,
            'webroot_separation_failed'
        );
    } finally {
        putenv('CARMAJA_TEST_WEBSITE_WEBROOT=' . $fixture['testWebsite']);
    }
});

carmaja_api_test('Produktmodell v2 erzeugt Version und sourceHash serverseitig', static function (): void {
    carmaja_api_test_fixture();
    $productId = '11111111-1111-4111-8111-111111111111';
    $body = [
        'expectedProductVersion' => 0,
        'name' => 'V2-Testarmband',
        'description' => 'V2-Beschreibung.',
        'materials' => ['Rosenquarz'],
        'metalElements' => [],
        'braceletSizeCm' => 18.0,
        'pearlSizeMm' => 6.0,
        'careInstructions' => [],
        'images' => [],
        'priceMinor' => 2490,
        'currency' => 'eur',
        'salesEnabled' => true,
    ];

    $first = carmaja_api_v2_put_product(
        $productId,
        $body,
        carmaja_api_test_actor(),
        'v2-contract-0001'
    );

    carmaja_api_test_same(2, $first['productModelVersion'] ?? null, 'Produktmodellversion fehlt.');
    carmaja_api_test_same(1, $first['productVersion'] ?? null, 'Produktversion muss bei eins starten.');
    carmaja_api_test_assert(
        is_string($first['sourceHash'] ?? null)
            && preg_match('/^[0-9a-f]{64}$/', $first['sourceHash']) === 1,
        'sourceHash muss ein serverseitiger SHA-256-Hash sein.'
    );
    carmaja_api_test_same(
        'f31f10410c31d160b40d78e79a922db5b73b955b1b23056d0ba710715dceea92',
        $first['sourceHash'],
        'PHP- und Node-Kanonisierung müssen denselben sourceHash erzeugen.'
    );
    carmaja_api_test_assert(
        !array_key_exists('stock', $first) && !array_key_exists('vintedUrl', $first),
        'v2-Produkt darf keine Legacy-Verkaufsfelder enthalten.'
    );

    $replay = carmaja_api_v2_put_product(
        $productId,
        $body,
        carmaja_api_test_actor(),
        'v2-contract-0001'
    );
    carmaja_api_test_same($first, $replay, 'Idempotente v2-Wiederholung muss identisch sein.');

    $reusedKeyBody = $body;
    $reusedKeyBody['name'] = 'Abweichender Inhalt';
    carmaja_api_test_exception(
        static fn (): array => carmaja_api_v2_put_product(
            $productId,
            $reusedKeyBody,
            carmaja_api_test_actor(),
            'v2-contract-0001'
        ),
        409,
        'idempotency_key_reused'
    );

    $nextBody = $body;
    $nextBody['expectedProductVersion'] = 1;
    $nextBody['name'] = 'V2-Testarmband geändert';
    $next = carmaja_api_v2_put_product(
        $productId,
        $nextBody,
        carmaja_api_test_actor(),
        'v2-contract-0002'
    );
    carmaja_api_test_same(2, $next['productVersion'], 'Produktversion wurde nicht monoton erhöht.');
    carmaja_api_test_assert(
        $next['sourceHash'] !== $first['sourceHash'],
        'Produktänderung muss den sourceHash ändern.'
    );
});

carmaja_api_test('V2-Kette speichert, lädt Bilder hoch und publiziert ohne Legacy-Felder', static function (): void {
    carmaja_api_test_fixture();
    $productId = '55555555-5555-4555-8555-555555555555';
    $imageId = '66666666-6666-4666-8666-666666666666';
    $body = [
        'expectedProductVersion' => 0,
        'name' => 'Künstliches V2-Kettentestarmband',
        'description' => 'Ausschließlich künstliche Testdaten.',
        'materials' => ['Testmaterial'],
        'metalElements' => ['Testspacer Edelstahl'],
        'braceletSizeCm' => 17.5,
        'pearlSizeMm' => 6,
        'careInstructions' => [],
        'images' => [[
            'imageId' => $imageId,
            'fileName' => '01.jpg',
            'alt' => 'Künstliches V2-Kettentestarmband',
            'width' => 120,
            'height' => 80,
            'isMain' => true,
        ]],
        'priceMinor' => 2790,
        'currency' => 'eur',
        'salesEnabled' => false,
    ];
    $saved = carmaja_api_v2_put_product(
        $productId,
        $body,
        carmaja_api_test_actor(),
        'v2-chain-save-0001'
    );
    carmaja_api_test_same([], $saved['images'], 'Metadaten-PUT darf kein Bild als hochgeladen bestätigen.');

    $source = carmaja_api_path('v2-chain-source.jpg');
    carmaja_api_test_create_jpeg($source, 120, 80);
    carmaja_api_test_prepare_image_upload(
        $saved['version'],
        $imageId,
        [$imageId],
        $source,
        'Künstliches V2-Kettentestarmband'
    );
    $GLOBALS['CARMAJA_API_ALLOW_LOCAL_UPLOADS_FOR_TESTS'] = true;
    try {
        $uploadedDraft = carmaja_api_upload_images($productId, $_POST, carmaja_api_test_actor());
    } finally {
        unset($GLOBALS['CARMAJA_API_ALLOW_LOCAL_UPLOADS_FOR_TESTS']);
    }
    $uploaded = carmaja_api_v2_product_response_from_draft($uploadedDraft);
    carmaja_api_test_same(1, count($uploaded['images']), 'V2-Upload wurde nicht bestätigt.');
    carmaja_api_test_same($saved['sourceHash'], $uploaded['sourceHash'], 'Bildtransfer darf den Produkthash nicht verändern.');

    $publication = carmaja_api_v2_publish_product(
        $productId,
        [
            'expectedProductVersion' => $uploaded['productVersion'],
            'expectedSourceHash' => $uploaded['sourceHash'],
            'operationId' => 'v2-chain-publish-0001',
        ],
        carmaja_api_test_actor()
    );
    $published = $publication['product'];
    carmaja_api_test_same('published', $published['status'], 'V2-Produkt wurde nicht veröffentlicht.');
    carmaja_api_test_same(17.5, $published['braceletSizeCm'], 'Armbandumfang ging verloren.');
    carmaja_api_test_same(6, $published['pearlSizeMm'], 'Perlengröße ging verloren.');
    carmaja_api_test_same(false, $published['salesEnabled'], 'Kettentest darf keine Verkaufsfreigabe aktivieren.');

    $publicDocument = carmaja_api_v2_read_public_projection(
        carmaja_api_path('products/public-products-v2.json'),
        [],
        'v2-Kettentest-Publikation'
    );
    $publicDocumentKeys = array_keys($publicDocument);
    sort($publicDocumentKeys, SORT_STRING);
    carmaja_api_test_same(
        ['products', 'version'],
        $publicDocumentKeys,
        'Public V2 projection must match the strict website contract.'
    );
    $public = $publicDocument['products'][0] ?? [];
    carmaja_api_test_same(17.5, $public['braceletSizeCm'] ?? null, 'Öffentlicher Umfang fehlt.');
    carmaja_api_test_same(6, $public['pearlSizeMm'] ?? null, 'Öffentliche Perlengröße fehlt.');
    carmaja_api_test_assert(
        !array_key_exists('stock', $public) && !array_key_exists('vintedUrl', $public),
        'Öffentliche V2-Projektion enthält Legacy-Felder.'
    );
});

carmaja_api_test('Produktmodell v2 verwendet eine deterministische Kanonisierung', static function (): void {
    carmaja_api_test_fixture();
    $left = [
        'productId' => '11111111-1111-4111-8111-111111111111',
        'productVersion' => 1,
        'name' => 'Kanonisch',
        'description' => 'Beschreibung',
        'materials' => ['Rosenquarz'],
        'metalElements' => [],
        'braceletSizeCm' => 18.0,
        'pearlSizeMm' => 6.0,
        'careInstructions' => [],
        'images' => [],
        'priceMinor' => 2490,
        'currency' => 'eur',
        'salesEnabled' => true,
    ];
    $right = [
        'salesEnabled' => true,
        'currency' => 'eur',
        'priceMinor' => 2490,
        'images' => [],
        'careInstructions' => [],
        'braceletSizeCm' => 18.0,
        'pearlSizeMm' => 6.0,
        'metalElements' => [],
        'materials' => ['Rosenquarz'],
        'description' => 'Beschreibung',
        'name' => 'Kanonisch',
        'productVersion' => 1,
        'productId' => '11111111-1111-4111-8111-111111111111',
    ];

    carmaja_api_test_same(
        carmaja_api_v2_source_hash($left),
        carmaja_api_v2_source_hash($right),
        'Schlüsselreihenfolge darf sourceHash nicht verändern.'
    );
});

carmaja_api_test('Produktmodell v2 lehnt clientseitige Legacy- und Versionsfelder ab', static function (): void {
    carmaja_api_test_fixture();
    $base = [
        'expectedProductVersion' => 0,
        'name' => 'V2-Testarmband',
        'description' => 'V2-Beschreibung.',
        'materials' => ['Rosenquarz'],
        'metalElements' => [],
        'braceletSizeCm' => 18.0,
        'pearlSizeMm' => 6.0,
        'careInstructions' => [],
        'images' => [],
        'priceMinor' => 2490,
        'currency' => 'eur',
        'salesEnabled' => true,
    ];

    foreach ([
        ['stock' => 1, 'error' => 'stock_write_disabled'],
        ['vintedUrl' => 'https://example.invalid', 'error' => 'legacy_product_field_forbidden'],
        ['sourceHash' => str_repeat('a', 64), 'error' => 'client_managed_field_forbidden'],
        ['productVersion' => 9, 'error' => 'client_managed_field_forbidden'],
    ] as $case) {
        carmaja_api_test_exception(
            static fn (): array => carmaja_api_v2_put_product(
                '22222222-2222-4222-8222-222222222222',
                array_merge($base, array_diff_key($case, ['error' => true])),
                carmaja_api_test_actor(),
                'v2-forbidden-' . $case['error']
            ),
            $case['error'] === 'stock_write_disabled' ? 409 : 422,
            $case['error']
        );
    }

    $incomplete = $base;
    unset($incomplete['salesEnabled']);
    carmaja_api_test_exception(
        static fn (): array => carmaja_api_v2_validate_put_payload($incomplete),
        422,
        'validation_failed'
    );
});

carmaja_api_test('Publisher v2 erzeugt nur den öffentlichen v2-Vertrag', static function (): void {
    carmaja_api_test_fixture();
    $productId = '33333333-3333-4333-8333-333333333333';
    $body = [
        'expectedProductVersion' => 0,
        'name' => 'V2-Publisher-Test',
        'description' => 'Öffentliche v2-Abbildung.',
        'materials' => ['Amazonit'],
        'metalElements' => [],
        'braceletSizeCm' => 18.0,
        'pearlSizeMm' => 6.0,
        'careInstructions' => [],
        'images' => [[
            'imageId' => '44444444-4444-4444-8444-444444444444',
            'fileName' => '01.jpg',
            'alt' => 'V2-Publisher-Test',
            'width' => 1200,
            'height' => 900,
            'isMain' => true,
        ]],
        'priceMinor' => 1990,
        'currency' => 'eur',
        'salesEnabled' => true,
    ];
    carmaja_api_v2_put_product(
        $productId,
        $body,
        carmaja_api_test_actor(),
        'v2-publisher-0001'
    );
    $draft = carmaja_api_load_draft($productId);
    $draft['sku'] = 'CP-2026-0099';
    $draft['slug'] = 'cp-2026-0099-v2-publisher-test';
    $beforeStock = $draft['stock'] ?? null;
    $public = carmaja_api_v2_public_product_from_draft($draft);

    carmaja_api_test_assert(
        !array_key_exists('stock', $public) && !array_key_exists('vintedUrl', $public),
        'Publisher v2 darf keine Legacy-Felder ausgeben.'
    );
    carmaja_api_local_publish_adapter_v2($public, ['operationId' => 'v2-publisher-0001']);
    carmaja_api_test_same(
        ['commitSha' => null, 'deploymentStatus' => 'not_started'],
        carmaja_api_local_publish_adapter_v2($public, ['operationId' => 'v2-publisher-0001']),
        'Publisher v2 muss eine Wiederholung idempotent beantworten.'
    );
    $changedPublic = $public;
    $changedPublic['priceMinor'] = 2090;
    carmaja_api_test_exception(
        static fn (): array => carmaja_api_local_publish_adapter_v2(
            $changedPublic,
            ['operationId' => 'v2-publisher-0001']
        ),
        409,
        'publish_adapter_conflict'
    );
    $stored = carmaja_api_v2_read_public_projection(
        carmaja_api_path('products/public-products-v2.json'),
        [],
        'v2-Publisherdaten'
    );
    $afterPublishDraft = carmaja_api_load_draft($productId);
    carmaja_api_test_same(2, $stored['version'] ?? null, 'v2-Publisher muss Dokumentversion 2 speichern.');
    carmaja_api_test_same(
        $beforeStock,
        $afterPublishDraft['stock'] ?? null,
        'Publisher darf den Bestand auch nach dem Schreiben nicht veraendern.'
    );
    carmaja_api_test_same($beforeStock, $draft['stock'] ?? null, 'Publisher darf Bestand nicht verändern.');

    $uploadDirectory = carmaja_api_path('uploads/' . $productId);
    carmaja_api_ensure_directory($uploadDirectory);
    $uploadedImage = $draft['imageManifest'][0];
    $uploadedImage['path'] = $uploadDirectory . DIRECTORY_SEPARATOR . '01.jpg';
    file_put_contents($uploadedImage['path'], 'artificial-ap7-image');
    $draft['images'] = [$uploadedImage];
    carmaja_api_save_draft($draft);
    $current = carmaja_api_v2_product_from_draft(carmaja_api_load_draft($productId));
    $publication = carmaja_api_v2_publish_product(
        $productId,
        [
            'expectedProductVersion' => $current['productVersion'],
            'expectedSourceHash' => $current['sourceHash'],
            'operationId' => 'v2-publisher-ap7-0002',
        ],
        carmaja_api_test_actor()
    );
    carmaja_api_test_same(
        $current['sourceHash'],
        $publication['publication']['sourceHash'] ?? null,
        'v2-Publishroute muss den serverseitigen Quellhash binden.'
    );
    carmaja_api_test_same(
        $beforeStock,
        (carmaja_api_load_draft($productId)['stock'] ?? null),
        'v2-Publishroute darf den Legacy-Bestand nicht verändern.'
    );
});

carmaja_api_test('AP1.5 sperrt Legacy-Produktfelder und alte Clientversionen', static function (): void {
    carmaja_api_test_fixture();

    carmaja_api_test_exception(
        static fn (): int => carmaja_api_validate_client_version_code('1'),
        426,
        'client_update_required'
    );
    carmaja_api_test_same(
        2,
        carmaja_api_validate_client_version_code('2'),
        'Mindest-App-Version 2 muss akzeptiert werden.'
    );
    carmaja_api_test_same(
        4,
        carmaja_api_validate_client_version_code(4),
        'Höhere App-Version muss akzeptiert werden.'
    );

    carmaja_api_test_exception(
        static function (): void {
            carmaja_api_validate_product_write_payload(['stock' => 1]);
        },
        409,
        'stock_write_disabled'
    );
    carmaja_api_test_exception(
        static function (): void {
            carmaja_api_validate_product_write_payload(['productVersion' => 3]);
        },
        422,
        'client_managed_field_forbidden'
    );
    carmaja_api_test_exception(
        static function (): void {
            carmaja_api_validate_product_write_payload(['sourceHash' => str_repeat('a', 64)]);
        },
        422,
        'client_managed_field_forbidden'
    );
});

carmaja_api_test('AP1.5 validiert den Inventory-Adjustment-Vertrag ohne Mutation', static function (): void {
    carmaja_api_test_fixture();
    $productId = '55555555-5555-4555-8555-555555555555';
    $base = [
        'productId' => $productId,
        'targetOnHand' => 1,
        'expectedInventoryVersion' => 0,
        'reason' => 'activate_new_unique',
        'correlationId' => 'ap15-inventory-0001',
    ];

    $validated = carmaja_api_validate_inventory_adjustment($base, 'ap15-idempotency-0001');
    carmaja_api_test_same($productId, $validated['productId'], 'Produkt-ID fehlt im normalisierten Vertrag.');
    carmaja_api_test_same(1, $validated['targetOnHand'], 'targetOnHand wurde nicht normalisiert.');
    carmaja_api_test_same(
        'ap15-idempotency-0001',
        $validated['idempotencyKey'],
        'Idempotency-Key fehlt im normalisierten Vertrag.'
    );

    foreach ([
        ['targetOnHand' => 2, 'error' => 'validation_failed'],
        ['expectedInventoryVersion' => -1, 'error' => 'validation_failed'],
        ['reason' => 'unknown_reason', 'error' => 'invalid_inventory_reason'],
        ['correlationId' => 'short', 'error' => 'validation_failed'],
    ] as $case) {
        $invalid = array_merge($base, array_diff_key($case, ['error' => true]));
        carmaja_api_test_exception(
            static fn (): array => carmaja_api_validate_inventory_adjustment(
                $invalid,
                'ap15-idempotency-' . $case['error']
            ),
            422,
            $case['error']
        );
    }

    carmaja_api_test_exception(
        static fn (): array => carmaja_api_validate_inventory_adjustment(
            $base,
            'bad key'
        ),
        422,
        'validation_failed'
    );
    carmaja_api_test_exception(
        static fn (): array => carmaja_api_validate_inventory_adjustment(
            array_merge($base, ['stock' => 1]),
            'ap15-idempotency-stock'
        ),
        409,
        'stock_write_disabled'
    );
    carmaja_api_test_exception(
        static fn (): array => carmaja_api_validate_inventory_adjustment(
            array_merge($base, ['reason' => 'shop_sale', 'targetOnHand' => 0]),
            'ap15-idempotency-manual-sale'
        ),
        422,
        'invalid_inventory_reason'
    );
    $shopSale = carmaja_api_validate_inventory_adjustment(
        array_merge($base, ['reason' => 'shop_sale', 'targetOnHand' => 0]),
        'ap15-idempotency-shop-sale',
        false
    );
    carmaja_api_test_same('shop_sale', $shopSale['reason'], 'Shopverkaufsgrund muss systemisch zulässig bleiben.');
});

$failures = 0;

try {
    foreach ($carmajaApiTests as $name => $test) {
        try {
            $test();
            echo '[OK] ' . $name . PHP_EOL;
        } catch (Throwable $error) {
            $failures++;
            fwrite(
                STDERR,
                '[FEHLER] ' . $name . ': ' . $error->getMessage() . PHP_EOL
            );
        }
    }
} finally {
    foreach ($carmajaApiTestRoots as $root) {
        carmaja_api_test_remove_tree($root);
    }
}

if ($failures > 0) {
    fwrite(STDERR, $failures . ' Test(s) fehlgeschlagen.' . PHP_EOL);
    exit(1);
}

echo count($carmajaApiTests) . ' Product-API-Tests erfolgreich.' . PHP_EOL;
