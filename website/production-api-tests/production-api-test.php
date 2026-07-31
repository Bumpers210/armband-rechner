<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/production-api-private/program/product-api.php';

final class ProductionApiTestFailure extends RuntimeException
{
}

function production_api_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new ProductionApiTestFailure($message);
    }
}

function production_api_expect(int $status, string $code, callable $callback): void
{
    try {
        $callback();
    } catch (CarmajaApiException $error) {
        production_api_assert($error->statusCode === $status, 'Falscher API-Status.');
        production_api_assert($error->errorCode === $code, 'Falscher API-Fehlercode: ' . $error->errorCode);
        return;
    }

    throw new ProductionApiTestFailure('Erwartete API-Ausnahme fehlt.');
}

function production_api_json(string $path, array $data): void
{
    $parent = dirname($path);

    if (!is_dir($parent) && !mkdir($parent, 0750, true) && !is_dir($parent)) {
        throw new ProductionApiTestFailure('JSON-Verzeichnis konnte nicht erstellt werden.');
    }

    file_put_contents($path, json_encode($data, JSON_THROW_ON_ERROR));
}

function production_api_remove_tree(string $path): void
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
            production_api_remove_tree($path . DIRECTORY_SEPARATOR . $entry);
        }
    }

    @rmdir($path);
}

function production_api_tree_contents(string $path): string
{
    $contents = '';

    foreach (new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS)
    ) as $entry) {
        if ($entry->isLink() || !$entry->isFile()) {
            throw new ProductionApiTestFailure('Backup enthaelt einen unzulaessigen Eintrag.');
        }

        $fileContents = file_get_contents($entry->getPathname());

        if (!is_string($fileContents)) {
            throw new ProductionApiTestFailure('Backupdatei konnte nicht gelesen werden.');
        }

        $contents .= $fileContents;
    }

    return $contents;
}

function production_api_permissions(string $path): int
{
    $permissions = fileperms($path);

    if ($permissions === false) {
        throw new ProductionApiTestFailure('Dateirechte konnten nicht gelesen werden.');
    }

    return $permissions & 0777;
}

function production_api_fixture(): array
{
    $root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'carmaja-production-api-'
        . bin2hex(random_bytes(6));
    $private = $root . DIRECTORY_SEPARATOR . 'private';
    $api = $root . DIRECTORY_SEPARATOR . 'api';
    $site = $root . DIRECTORY_SEPARATOR . 'site';

    foreach ([
        'auth', 'audit', 'locks', 'products/operations', 'drafts', 'idempotency',
        'uploads', 'uploads-temp', 'backups', 'sku-counter', 'config',
    ] as $relative) {
        $directory = $private . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
        if (!mkdir($directory, 0750, true) && !is_dir($directory)) {
            throw new ProductionApiTestFailure('Private Fixture-Struktur konnte nicht erstellt werden.');
        }
    }
    foreach ([$api, $site] as $directory) {
        if (!mkdir($directory, 0750, true) && !is_dir($directory)) {
            throw new ProductionApiTestFailure('Webroot-Fixture konnte nicht erstellt werden.');
        }
    }

    production_api_json($private . DIRECTORY_SEPARATOR . 'environment.json', ['environment' => 'production']);
    $passwordHash = password_hash('Ruhige7Farbe!Wolke', PASSWORD_DEFAULT);
    if (!is_string($passwordHash)) {
        throw new ProductionApiTestFailure('Passworthash konnte nicht erzeugt werden.');
    }
    $users = $private . DIRECTORY_SEPARATOR . 'auth' . DIRECTORY_SEPARATOR . 'api-users.json';
    production_api_json($users, [
        'environment' => 'production',
        'users' => [[
            'username' => 'admin',
            'passwordHash' => $passwordHash,
            'active' => true,
        ]],
    ]);
    file_put_contents(
        $private . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'runtime-config.php',
        "runtime-config-secret-marker\npepper-secret-marker\ngithub-token-secret-marker\n"
    );
    file_put_contents(
        $private . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'github-token',
        "github-token-secret-marker\n"
    );

    putenv('CARMAJA_PUBLISH_TARGET=production');
    putenv('CARMAJA_PRIVATE_DIR=' . $private);
    putenv('CARMAJA_PUBLIC_WEBROOT=' . $api);
    putenv('CARMAJA_PRODUCTION_PRIVATE_DIR=' . $private);
    putenv('CARMAJA_PRODUCTION_API_WEBROOT=' . $api);
    putenv('CARMAJA_PRODUCTION_WEBSITE_WEBROOT=' . $site);
    putenv('CARMAJA_API_USERS_FILE=' . $users);
    putenv('CARMAJA_TOKEN_PEPPER=' . str_repeat('p', 48));
    putenv('CARMAJA_PRODUCTION_PUBLISH_ENABLED=false');
    putenv('CARMAJA_GITHUB_ADAPTER_ENABLED=false');
    putenv('CARMAJA_GITHUB_REPOSITORY=Bumpers210/armband-rechner');
    putenv('CARMAJA_GITHUB_BRANCH=main');
    putenv('CARMAJA_GITHUB_TOKEN_FILE');
    $_SERVER['DOCUMENT_ROOT'] = $api;

    return compact('root', 'private', 'api', 'site');
}

function production_api_actor(): array
{
    return [
        'tokenId' => str_repeat('a', 32),
        'username' => 'admin',
        'deviceName' => 'Android',
    ];
}

function production_api_ready_payload(string $draftId, int $version): array
{
    return [
        'draftId' => $draftId,
        'expectedVersion' => $version,
        'status' => 'ready',
        'name' => 'Produkt ohne Testbindung',
        'materials' => ['Rosenquarz'],
        'metalElements' => ['Spacer Edelstahl'],
        'braceletSize' => '18 cm',
        'stock' => 1,
        'shortDescription' => 'Handgefertigtes Armband.',
        'careInstructions' => [],
        'vintedUrl' => 'https://www.vinted.de/items/1234567890',
        'internalCalculation' => [],
    ];
}

function production_api_create_jpeg(string $path): void
{
    $image = imagecreatetruecolor(80, 60);

    if ($image === false) {
        throw new ProductionApiTestFailure('GD-Bild konnte nicht erzeugt werden.');
    }

    try {
        $color = imagecolorallocate($image, 140, 40, 80);
        imagefill($image, 0, 0, $color);

        if (!imagejpeg($image, $path, 85)) {
            throw new ProductionApiTestFailure('GD-JPEG konnte nicht gespeichert werden.');
        }
    } finally {
        imagedestroy($image);
    }
}

$fixture = production_api_fixture();

try {
    production_api_expect(503, 'publish_target_not_configured', static function (): void {
        putenv('CARMAJA_PUBLISH_TARGET=test');
        carmaja_api_publish_target();
    });
    putenv('CARMAJA_PUBLISH_TARGET=production');

    carmaja_api_assert_repo_path_allowed('website/content/products.json');
    carmaja_api_assert_repo_path_allowed('website/public/images/products/CP-2026-0001/01.jpg');
    foreach (['.github/workflows/deploy.yml', 'app/build.gradle.kts', 'website/app/page.tsx'] as $path) {
        production_api_expect(500, 'internal_error', static function () use ($path): void {
            carmaja_api_assert_repo_path_allowed($path);
        });
    }

    production_api_expect(503, 'private_path_exposed', static function () use ($fixture): void {
        putenv('CARMAJA_PUBLIC_WEBROOT=' . $fixture['private']);
        carmaja_api_private_dir();
    });
    putenv('CARMAJA_PUBLIC_WEBROOT=' . $fixture['api']);

    production_api_expect(409, 'publish_target_mismatch', static function (): void {
        carmaja_api_login([
            'username' => 'admin',
            'password' => 'Ruhige7Farbe!Wolke',
            'deviceName' => 'Android',
            'publishTarget' => 'test',
        ]);
    });
    $login = carmaja_api_login([
        'username' => ' Admin ',
        'password' => 'Ruhige7Farbe!Wolke',
        'deviceName' => 'Android',
        'publishTarget' => 'production',
    ]);
    production_api_assert($login['username'] === 'admin', 'Anmeldung normalisiert den Benutzernamen nicht.');
    $actor = carmaja_api_authorize_token('Bearer ' . $login['token']);
    production_api_assert($actor['username'] === 'admin', 'Geraet-Token wurde nicht akzeptiert.');
    $tokens = carmaja_api_load_tokens();
    $tokens['tokens'][$login['tokenId']]['revokedAt'] = carmaja_api_now();
    carmaja_api_store_tokens($tokens);
    production_api_expect(401, 'device_token_revoked', static function () use ($login): void {
        carmaja_api_authorize_token('Bearer ' . $login['token']);
    });
    $backupLogin = carmaja_api_login([
        'username' => 'admin',
        'password' => 'Ruhige7Farbe!Wolke',
        'deviceName' => 'Backup-Validation',
        'publishTarget' => 'production',
    ]);
    production_api_assert(
        carmaja_api_authorize_token('Bearer ' . $backupLogin['token'])['username'] === 'admin',
        'Aktives Geraet-Token fuer die Backuppruefung wurde nicht akzeptiert.'
    );

    $draftId = 'd3b07384-d9a0-4bce-9f64-56ef7d22f777';
    $draft = carmaja_api_save_product($draftId, production_api_ready_payload($draftId, 0), production_api_actor());
    production_api_assert($draft['version'] === 1, 'Erste Entwurfsversion fehlt.');
    production_api_expect(409, 'version_conflict', static function () use ($draftId): void {
        carmaja_api_save_product($draftId, production_api_ready_payload($draftId, 0), production_api_actor());
    });

    $source = $fixture['root'] . DIRECTORY_SEPARATOR . 'source.jpg';
    production_api_create_jpeg($source);
    $imageId = 'd3b07384-d9a0-4bce-9f64-56ef7d22f778';
    $GLOBALS['CARMAJA_API_ALLOW_LOCAL_UPLOADS_FOR_VALIDATION'] = true;
    $_POST = [
        'expectedVersion' => '1',
        'imageId' => $imageId,
        'desiredImageIds' => json_encode([$imageId], JSON_THROW_ON_ERROR),
        'alt' => 'Produktbild',
    ];
    $_FILES = [
        'image' => [
            'tmp_name' => $source,
            'name' => 'produkt.jpg',
            'size' => filesize($source),
            'error' => UPLOAD_ERR_OK,
        ],
    ];
    $uploaded = carmaja_api_upload_images($draftId, [], production_api_actor());
    unset($GLOBALS['CARMAJA_API_ALLOW_LOCAL_UPLOADS_FOR_VALIDATION']);
    $_POST = [];
    $_FILES = [];
    production_api_assert($uploaded['version'] === 2, 'Bild-Upload hat die Version nicht erhoeht.');
    production_api_assert(
        is_file($uploaded['images'][0]['path'] ?? ''),
        'Bereinigtes Bild wurde nicht atomar uebernommen.'
    );
    production_api_assert(
        (glob(
            $fixture['private'] . DIRECTORY_SEPARATOR . 'uploads-temp'
                . DIRECTORY_SEPARATOR . $draftId . DIRECTORY_SEPARATOR . '*'
        ) ?: []) === [],
        'Temporare Bilddateien wurden nicht bereinigt.'
    );

    production_api_expect(403, 'production_publish_disabled', static function () use ($draftId): void {
        carmaja_api_publish($draftId, [
            'expectedVersion' => 2,
            'operationId' => 'production-publish-0001',
        ], production_api_actor(), 'published');
    });
    putenv('CARMAJA_PRODUCTION_PUBLISH_ENABLED=true');
    $first = carmaja_api_publish($draftId, [
        'expectedVersion' => 2,
        'operationId' => 'production-publish-0001',
    ], production_api_actor(), 'published');
    $second = carmaja_api_publish($draftId, [
        'expectedVersion' => 2,
        'operationId' => 'production-publish-0001',
    ], production_api_actor(), 'published');
    production_api_assert($first === $second, 'Idempotente Wiederholung liefert ein anderes Ergebnis.');
    production_api_assert(is_string($first['sku']) && $first['sku'] !== '', 'SKU wurde nicht vergeben.');
    $public = carmaja_api_read_target_json(
        $fixture['private'] . DIRECTORY_SEPARATOR . 'products' . DIRECTORY_SEPARATOR . 'public-products.json',
        [],
        'Oeffentliche Produktdaten'
    );
    production_api_assert(count($public['products'] ?? []) === 1, 'Idempotenz hat doppelte Produktdaten erzeugt.');
    putenv('CARMAJA_PRODUCTION_PUBLISH_ENABLED=false');

    $invalid = $fixture['root'] . DIRECTORY_SEPARATOR . 'invalid.jpg';
    file_put_contents($invalid, 'invalid');
    production_api_expect(422, 'image_content_invalid', static function () use ($invalid, $fixture): void {
        carmaja_api_process_jpeg(
            $invalid,
            'invalid.jpg',
            filesize($invalid),
            $fixture['private'] . DIRECTORY_SEPARATOR . 'uploads-temp' . DIRECTORY_SEPARATOR . 'invalid.jpg',
            false
        );
    });

    $backup = carmaja_api_create_backup();
    production_api_assert($backup['status'] === 'created', 'Backup wurde nicht erstellt.');
    $backupDirectory = $fixture['private'] . DIRECTORY_SEPARATOR . 'backups'
        . DIRECTORY_SEPARATOR . $backup['backup'];
    $manifest = carmaja_api_read_target_json(
        $backupDirectory . DIRECTORY_SEPARATOR . 'manifest.json',
        [],
        'Backup-Manifest'
    );
    production_api_assert(
        ($manifest['schemaVersion'] ?? null) === CARMAJA_BACKUP_SCHEMA_VERSION,
        'Backup verwendet nicht Schema-Version 2.'
    );
    production_api_assert(
        ($manifest['authentication']['includedFiles'] ?? null) === ['auth/api-users.json']
            && ($manifest['authentication']['excludedFiles'] ?? null) === CARMAJA_BACKUP_EXCLUDED_AUTH_FILES
            && ($manifest['authentication']['deviceSessionsRestored'] ?? null) === false,
        'Backup-Manifest dokumentiert die Authentifizierungsausschluesse nicht.'
    );
    production_api_assert(
        production_api_permissions($backupDirectory) === 0750,
        'Backup-Verzeichnis hat keine privaten Minimalrechte.'
    );
    production_api_assert(
        !is_file($backupDirectory . DIRECTORY_SEPARATOR . 'auth' . DIRECTORY_SEPARATOR . 'device-tokens.json')
            && !is_file($backupDirectory . DIRECTORY_SEPARATOR . 'auth' . DIRECTORY_SEPARATOR . 'login-attempts.json'),
        'Backup enthaelt Geraete- oder Sitzungsauthentifizierungsdaten.'
    );
    $tokenStateBeforeBackup = carmaja_api_load_tokens();
    production_api_assert(count($tokenStateBeforeBackup['tokens'] ?? []) === 2, 'Fixture enthaelt nicht zwei Geraete.');
    $backupContents = production_api_tree_contents($backupDirectory);

    foreach ($tokenStateBeforeBackup['tokens'] as $tokenRecord) {
        production_api_assert(
            !str_contains($backupContents, (string) ($tokenRecord['secretHash'] ?? '')),
            'Backup enthaelt einen Geraete-Token-Hash.'
        );
    }

    foreach ([
        $backupLogin['token'],
        'runtime-config-secret-marker',
        'pepper-secret-marker',
        'github-token-secret-marker',
    ] as $excludedValue) {
        production_api_assert(
            !str_contains($backupContents, $excludedValue),
            'Backup enthaelt ausgeschlossene vertrauliche Daten.'
        );
    }

    production_api_assert(
        str_contains($backupContents, 'passwordHash')
            && str_contains($backupContents, $draftId),
        'Backup enthaelt Benutzer- oder fachliche Daten nicht vollstaendig.'
    );
    foreach (new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($backupDirectory, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    ) as $entry) {
        if ($entry->isDir()) {
            production_api_assert(
                production_api_permissions($entry->getPathname()) === 0750,
                'Backup-Unterverzeichnis hat keine privaten Minimalrechte.'
            );
        } elseif ($entry->isFile()) {
            production_api_assert(
                production_api_permissions($entry->getPathname()) === 0640,
                'Backupdatei hat keine privaten Minimalrechte.'
            );
        }
    }

    $stored = carmaja_api_load_draft($draftId);
    $stored['name'] = 'Veraenderter Stand';
    carmaja_api_save_draft($stored);
    $dryRun = carmaja_api_restore_backup($backup['backup'], true);
    production_api_assert($dryRun['status'] === 'dry_run' && $dryRun['writePerformed'] === false, 'Restore-Dry-Run schreibt Daten.');
    production_api_assert(
        carmaja_api_authorize_token('Bearer ' . $backupLogin['token'])['username'] === 'admin',
        'Restore-Dry-Run hat eine Geraetesitzung veraendert.'
    );
    $restored = carmaja_api_restore_backup($backup['backup'], false);
    production_api_assert($restored['status'] === 'restored', 'Restore wurde nicht abgeschlossen.');
    production_api_assert(carmaja_api_load_draft($draftId)['name'] !== 'Veraenderter Stand', 'Restore hat Entwurfsdaten nicht wiederhergestellt.');
    production_api_assert(
        count(carmaja_api_load_users()['users'] ?? []) === 1,
        'Restore hat Benutzerkonten nicht wiederhergestellt.'
    );
    production_api_assert(
        (carmaja_api_load_tokens()['tokens'] ?? []) === [],
        'Restore hat Geraetesitzungen wiederhergestellt.'
    );
    production_api_expect(401, 'device_token_invalid', static function () use ($backupLogin): void {
        carmaja_api_authorize_token('Bearer ' . $backupLogin['token']);
    });
    production_api_json(
        $fixture['private'] . DIRECTORY_SEPARATOR . 'backups' . DIRECTORY_SEPARATOR
            . $backup['backup'] . DIRECTORY_SEPARATOR . 'environment.json',
        ['environment' => 'wrong-environment']
    );
    $liveName = carmaja_api_load_draft($draftId)['name'];
    production_api_expect(409, 'backup_environment_mismatch', static function () use ($backup): void {
        carmaja_api_restore_backup($backup['backup'], false);
    });
    production_api_assert(
        carmaja_api_load_draft($draftId)['name'] === $liveName,
        'Fehlgeschlagener Restore hat den aktiven Datenbestand veraendert.'
    );
    $legacyBackup = '20260101-000000-deadbeef';
    $legacyDirectory = $fixture['private'] . DIRECTORY_SEPARATOR . 'backups'
        . DIRECTORY_SEPARATOR . $legacyBackup;
    production_api_json($legacyDirectory . DIRECTORY_SEPARATOR . 'environment.json', ['environment' => 'production']);
    production_api_json($legacyDirectory . DIRECTORY_SEPARATOR . 'manifest.json', [
        'environment' => 'production',
        'backup' => $legacyBackup,
        'directories' => array_merge(['auth'], CARMAJA_BACKUP_DIRECTORIES),
    ]);
    production_api_json(
        $legacyDirectory . DIRECTORY_SEPARATOR . 'auth' . DIRECTORY_SEPARATOR . 'device-tokens.json',
        ['tokens' => ['legacy-device' => ['secretHash' => 'legacy-verifier']]]
    );
    production_api_expect(409, 'backup_legacy_device_tokens_rejected', static function () use ($legacyBackup): void {
        carmaja_api_restore_backup($legacyBackup, true);
    });

    $calls = [];
    $GLOBALS['CARMAJA_API_GITHUB_REQUEST_ADAPTER'] = static function (string $method, string $path, ?array $body) use (&$calls): array {
        $calls[] = [$method, $path, $body];
        if (str_contains($path, '/git/ref/heads/')) {
            return ['object' => ['sha' => str_repeat('a', 40)]];
        }
        if (str_contains($path, '/contents/website/content/products.json')) {
            return ['content' => base64_encode('{"version":1,"products":[]}')];
        }
        throw new ProductionApiTestFailure('Unerwartete GitHub-Diagnoseanfrage.');
    };
    $diagnostic = carmaja_api_github_readonly_diagnostic();
    production_api_assert($diagnostic['writePerformed'] === false, 'Read-only-Diagnose hat einen Schreibvorgang gemeldet.');
    production_api_assert(array_reduce($calls, static fn (bool $allGet, array $call): bool => $allGet && $call[0] === 'GET', true), 'Read-only-Diagnose hat eine schreibende GitHub-Anfrage erzeugt.');
    unset($GLOBALS['CARMAJA_API_GITHUB_REQUEST_ADAPTER']);

    echo "production-api: OK\n";
} finally {
    putenv('CARMAJA_PRODUCTION_PUBLISH_ENABLED=false');
    unset($GLOBALS['CARMAJA_API_GITHUB_REQUEST_ADAPTER']);
    unset($GLOBALS['CARMAJA_API_ALLOW_LOCAL_UPLOADS_FOR_VALIDATION']);
    $_POST = [];
    $_FILES = [];
    production_api_remove_tree($fixture['root']);
}
