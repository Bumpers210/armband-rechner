<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/scripts/product-admin.php';

final class CarmajaAdminTestFailure extends RuntimeException
{
}

$carmajaAdminTestRoots = [];
$carmajaAdminTests = [];

function carmaja_admin_test(string $name, callable $test): void
{
    global $carmajaAdminTests;
    $carmajaAdminTests[$name] = $test;
}

function carmaja_admin_test_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new CarmajaAdminTestFailure($message);
    }
}

function carmaja_admin_test_same(mixed $expected, mixed $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new CarmajaAdminTestFailure(
            $message
            . ' Erwartet: '
            . var_export($expected, true)
            . '; erhalten: '
            . var_export($actual, true)
        );
    }
}

function carmaja_admin_test_exception(
    callable $callback,
    int $exitCode,
    string $message
): CarmajaAdminException {
    try {
        $callback();
    } catch (CarmajaAdminException $error) {
        carmaja_admin_test_same($exitCode, $error->exitCode, $message);
        return $error;
    }

    throw new CarmajaAdminTestFailure($message . ' Keine Ausnahme erhalten.');
}

function carmaja_admin_test_write_json(string $path, array $data): void
{
    $written = file_put_contents(
        $path,
        json_encode(
            $data,
            JSON_PRETTY_PRINT
                | JSON_UNESCAPED_SLASHES
                | JSON_UNESCAPED_UNICODE
                | JSON_THROW_ON_ERROR
        ) . PHP_EOL
    );

    if ($written === false) {
        throw new CarmajaAdminTestFailure('Testdatei konnte nicht geschrieben werden.');
    }
}

function carmaja_admin_test_remove_tree(string $path): void
{
    if (!file_exists($path)) {
        return;
    }

    if (is_file($path) || is_link($path)) {
        unlink($path);
        return;
    }

    $entries = scandir($path);

    if (!is_array($entries)) {
        return;
    }

    foreach ($entries as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }

        carmaja_admin_test_remove_tree($path . DIRECTORY_SEPARATOR . $entry);
    }

    rmdir($path);
}

function carmaja_admin_test_fixture(
    string $privateEnvironment = 'test',
    bool $withUsersFile = true
): array {
    global $carmajaAdminTestRoots;

    $root = sys_get_temp_dir()
        . DIRECTORY_SEPARATOR
        . 'carmaja-admin-test-'
        . bin2hex(random_bytes(6));
    $private = $root . DIRECTORY_SEPARATOR . 'private';
    $webroot = $root . DIRECTORY_SEPARATOR . 'webroot';
    $auth = $private . DIRECTORY_SEPARATOR . 'auth';
    $audit = $private . DIRECTORY_SEPARATOR . 'audit';
    $locks = $private . DIRECTORY_SEPARATOR . 'locks';

    foreach ([$root, $private, $webroot, $auth, $audit, $locks] as $directory) {
        if (!is_dir($directory) && !mkdir($directory, 0750, true)) {
            throw new CarmajaAdminTestFailure('Testverzeichnis konnte nicht angelegt werden.');
        }
    }

    $carmajaAdminTestRoots[] = $root;
    carmaja_admin_test_write_json(
        $private . DIRECTORY_SEPARATOR . 'environment.json',
        ['environment' => $privateEnvironment]
    );

    $usersFile = $auth . DIRECTORY_SEPARATOR . 'api-users.json';

    if ($withUsersFile) {
        carmaja_admin_test_write_json($usersFile, [
            'environment' => 'test',
            'users' => [],
        ]);
    }

    putenv('CARMAJA_PUBLISH_TARGET=test');
    putenv('CARMAJA_PRIVATE_DIR=' . $private);
    putenv('CARMAJA_PUBLIC_WEBROOT=' . $webroot);
    putenv('CARMAJA_API_USERS_FILE=' . $usersFile);

    return [
        'root' => $root,
        'private' => $private,
        'webroot' => $webroot,
        'usersFile' => $usersFile,
        'devicesFile' => $auth . DIRECTORY_SEPARATOR . 'device-tokens.json',
        'audit' => $audit,
    ];
}

function carmaja_admin_test_add_user(
    array $fixture,
    string $username,
    string $password = 'Nordlicht!7Tasse#Weg'
): string {
    $hash = password_hash($password, PASSWORD_DEFAULT);

    if (!is_string($hash)) {
        throw new CarmajaAdminTestFailure('Testhash konnte nicht erzeugt werden.');
    }

    carmaja_admin_test_write_json($fixture['usersFile'], [
        'environment' => 'test',
        'users' => [
            [
                'username' => $username,
                'passwordHash' => $hash,
                'active' => true,
            ],
        ],
    ]);

    return $hash;
}

function carmaja_admin_test_token(
    string $tokenId,
    string $username,
    ?string $revokedAt = null
): array {
    return [
        'tokenId' => $tokenId,
        'secretHash' => 'sensitive-test-token-hash-' . $tokenId,
        'username' => $username,
        'deviceName' => 'Testgerät',
        'createdAt' => '2026-07-01T10:00:00+00:00',
        'lastUsedAt' => '2026-07-02T11:00:00+00:00',
        'revokedAt' => $revokedAt,
    ];
}

function carmaja_admin_test_run_cli(
    array $argv,
    array $lineInputs = [],
    array $passwordInputs = []
): array {
    $stdout = '';
    $stderr = '';
    $lineIndex = 0;
    $passwordIndex = 0;

    $exitCode = carmaja_admin_main($argv, [
        'linePrompt' => static function () use (&$lineInputs, &$lineIndex): string {
            return (string) ($lineInputs[$lineIndex++] ?? '');
        },
        'passwordPrompt' => static function () use (
            &$passwordInputs,
            &$passwordIndex
        ): string {
            return (string) ($passwordInputs[$passwordIndex++] ?? '');
        },
        'output' => static function (string $message) use (&$stdout): void {
            $stdout .= $message;
        },
        'error' => static function (string $message) use (&$stderr): void {
            $stderr .= $message;
        },
    ]);

    return [
        'exitCode' => $exitCode,
        'stdout' => $stdout,
        'stderr' => $stderr,
    ];
}

carmaja_admin_test('HTTP-Ausführung wird verweigert', static function (): void {
    carmaja_admin_test_assert(
        !carmaja_admin_sapi_allowed('apache2handler'),
        'Nicht-CLI-SAPI muss verweigert werden.'
    );
    carmaja_admin_test_assert(
        carmaja_admin_sapi_allowed('cli'),
        'CLI-SAPI muss erlaubt sein.'
    );
});

carmaja_admin_test('Fehlende Benutzerdatei-Konfiguration', static function (): void {
    carmaja_admin_test_fixture();
    putenv('CARMAJA_API_USERS_FILE');

    carmaja_admin_test_exception(
        'carmaja_admin_build_config',
        CARMAJA_ADMIN_EXIT_IO,
        'Fehlende CARMAJA_API_USERS_FILE muss Exit-Code 5 ergeben.'
    );
});

carmaja_admin_test('Falsche Umgebungsmarkierung', static function (): void {
    carmaja_admin_test_fixture('production');

    carmaja_admin_test_exception(
        'carmaja_admin_build_config',
        CARMAJA_ADMIN_EXIT_IO,
        'Abweichende Umgebungsmarkierung muss Exit-Code 5 ergeben.'
    );
});

carmaja_admin_test('Benutzername wird normalisiert', static function (): void {
    carmaja_admin_test_same(
        'test.admin_1',
        carmaja_admin_validate_username('  Test.Admin_1  '),
        'Benutzername wurde nicht korrekt normalisiert.'
    );
});

carmaja_admin_test('Ungültige Benutzernamen werden abgelehnt', static function (): void {
    foreach (['ab', 'name mit leerzeichen', 'ümlaut', '.admin', 'admin-', 'Admin/Root'] as $name) {
        carmaja_admin_test_exception(
            static fn (): string => carmaja_admin_validate_username($name),
            CARMAJA_ADMIN_EXIT_INPUT,
            'Ungültiger Benutzername muss Exit-Code 3 ergeben: ' . $name
        );
    }
});

carmaja_admin_test('Doppelter Benutzer wird nicht überschrieben', static function (): void {
    $fixture = carmaja_admin_test_fixture();
    $oldHash = carmaja_admin_test_add_user($fixture, 'test.admin');
    $before = file_get_contents($fixture['usersFile']);
    $config = carmaja_admin_build_config();
    $newHash = carmaja_admin_hash_password('Fjord!Blick7Stern#', 'test.admin');

    carmaja_admin_test_exception(
        static function () use ($config, $newHash): void {
            carmaja_admin_create_user($config, 'test.admin', $newHash);
        },
        CARMAJA_ADMIN_EXIT_CONFLICT,
        'Doppelter Benutzer muss Exit-Code 4 ergeben.'
    );

    carmaja_admin_test_same(
        $before,
        file_get_contents($fixture['usersFile']),
        'Doppelter Benutzer darf die Datei nicht verändern.'
    );
    carmaja_admin_test_assert(
        str_contains((string) $before, $oldHash),
        'Bestehender Passworthash muss erhalten bleiben.'
    );
});

carmaja_admin_test('Zu kurzes Passwort wird abgelehnt', static function (): void {
    carmaja_admin_test_exception(
        static fn (): string => carmaja_admin_hash_password('Kurz!7', 'test.admin'),
        CARMAJA_ADMIN_EXIT_INPUT,
        'Zu kurzes Passwort muss Exit-Code 3 ergeben.'
    );
});

carmaja_admin_test('Offensichtlich schwache Passwörter werden abgelehnt', static function (): void {
    foreach (['Passwort123456!', 'Hallo-test.admin-Secret7!'] as $password) {
        carmaja_admin_test_exception(
            static fn (): string => carmaja_admin_hash_password(
                $password,
                'test.admin'
            ),
            CARMAJA_ADMIN_EXIT_INPUT,
            'Schwaches Passwort muss Exit-Code 3 ergeben.'
        );
    }
});

carmaja_admin_test('Unterschiedliche Passworteingaben werden abgelehnt', static function (): void {
    $passwords = ['Nordlicht!7Tasse#Weg', 'Nordlicht!8Tasse#Weg'];
    $index = 0;

    carmaja_admin_test_exception(
        static function () use (&$passwords, &$index): string {
            return carmaja_admin_collect_password(
                'test.admin',
                static function () use (&$passwords, &$index): string {
                    return $passwords[$index++];
                }
            );
        },
        CARMAJA_ADMIN_EXIT_INPUT,
        'Abweichende Passworteingaben müssen Exit-Code 3 ergeben.'
    );
});

carmaja_admin_test('Passwortargument wird ohne Ausgabe abgelehnt', static function (): void {
    $secret = 'NichtAusgeben!42';
    $result = carmaja_admin_test_run_cli([
        'product-admin.php',
        'user:create',
        '--password=' . $secret,
    ]);

    carmaja_admin_test_same(
        CARMAJA_ADMIN_EXIT_USAGE,
        $result['exitCode'],
        'Passwortargument muss Exit-Code 2 ergeben.'
    );
    carmaja_admin_test_assert(
        !str_contains($result['stdout'] . $result['stderr'], $secret),
        'Abgewiesenes Passwortargument darf nicht ausgegeben werden.'
    );
});

carmaja_admin_test('Benutzer wird korrekt angelegt', static function (): void {
    $fixture = carmaja_admin_test_fixture('test', false);
    $password = 'Nordlicht!7Tasse#Weg';
    $result = carmaja_admin_test_run_cli(
        ['product-admin.php', 'user:create', '--username', '  Test.Admin  '],
        [],
        [$password, $password]
    );

    carmaja_admin_test_same(
        CARMAJA_ADMIN_EXIT_SUCCESS,
        $result['exitCode'],
        'user:create muss erfolgreich sein.'
    );
    $data = json_decode(
        (string) file_get_contents($fixture['usersFile']),
        true,
        512,
        JSON_THROW_ON_ERROR
    );
    carmaja_admin_test_same('test', $data['environment'], 'Umgebungskennung fehlt.');
    carmaja_admin_test_same(
        'test.admin',
        $data['users'][0]['username'],
        'Normalisierter Benutzername fehlt.'
    );
    carmaja_admin_test_assert(
        password_verify($password, $data['users'][0]['passwordHash']),
        'Gespeicherter Hash muss mit password_verify funktionieren.'
    );
    carmaja_admin_test_assert(
        !str_contains((string) file_get_contents($fixture['usersFile']), $password),
        'Klartextpasswort darf nicht gespeichert werden.'
    );
});

carmaja_admin_test('Passwortänderung erzeugt gültigen neuen Hash', static function (): void {
    $fixture = carmaja_admin_test_fixture();
    $oldHash = carmaja_admin_test_add_user($fixture, 'test.admin');
    $password = 'Fjord!Blick7Stern#';
    $result = carmaja_admin_test_run_cli(
        ['product-admin.php', 'user:password', '--username=test.admin'],
        [''],
        [$password, $password]
    );

    carmaja_admin_test_same(
        CARMAJA_ADMIN_EXIT_SUCCESS,
        $result['exitCode'],
        'user:password muss erfolgreich sein.'
    );
    $data = json_decode(
        (string) file_get_contents($fixture['usersFile']),
        true,
        512,
        JSON_THROW_ON_ERROR
    );
    $newHash = $data['users'][0]['passwordHash'];
    carmaja_admin_test_assert($newHash !== $oldHash, 'Passworthash muss ersetzt werden.');
    carmaja_admin_test_assert(
        password_verify($password, $newHash),
        'Neuer Passworthash ist ungültig.'
    );
    carmaja_admin_test_assert(
        str_contains($result['stdout'], 'bestehende Geräte bleiben aktiv'),
        'Standardantwort darf Geräte nicht automatisch widerrufen.'
    );
});

carmaja_admin_test('Unbekannter Benutzer bei Passwortänderung', static function (): void {
    carmaja_admin_test_fixture();
    $result = carmaja_admin_test_run_cli(
        ['product-admin.php', 'user:password', '--username=unbekannt']
    );

    carmaja_admin_test_same(
        CARMAJA_ADMIN_EXIT_CONFLICT,
        $result['exitCode'],
        'Unbekannter Benutzer muss Exit-Code 4 ergeben.'
    );
});

carmaja_admin_test('Geräteauflistung zeigt keine Hashes', static function (): void {
    $fixture = carmaja_admin_test_fixture();
    carmaja_admin_test_add_user($fixture, 'test.admin');
    $tokenId = str_repeat('a', 32);
    $token = carmaja_admin_test_token($tokenId, 'test.admin');
    carmaja_admin_test_write_json($fixture['devicesFile'], [
        'environment' => 'test',
        'tokens' => [$tokenId => $token],
    ]);

    $result = carmaja_admin_test_run_cli([
        'product-admin.php',
        'device:list',
        '--username=test.admin',
    ]);

    carmaja_admin_test_same(
        CARMAJA_ADMIN_EXIT_SUCCESS,
        $result['exitCode'],
        'device:list muss erfolgreich sein.'
    );
    carmaja_admin_test_assert(
        str_contains($result['stdout'], $tokenId),
        'Geräte-ID muss angezeigt werden.'
    );
    carmaja_admin_test_assert(
        !str_contains($result['stdout'], 'secretHash')
            && !str_contains($result['stdout'], $token['secretHash']),
        'Token-Hash darf nicht angezeigt werden.'
    );
});

carmaja_admin_test('Einzelnes Gerät wird widerrufen', static function (): void {
    $fixture = carmaja_admin_test_fixture();
    $tokenId = str_repeat('b', 32);
    carmaja_admin_test_write_json($fixture['devicesFile'], [
        'environment' => 'test',
        'tokens' => [
            $tokenId => carmaja_admin_test_token($tokenId, 'test.admin'),
        ],
    ]);
    $config = carmaja_admin_build_config();
    $result = carmaja_admin_revoke_device($config, $tokenId);
    $data = json_decode(
        (string) file_get_contents($fixture['devicesFile']),
        true,
        512,
        JSON_THROW_ON_ERROR
    );

    carmaja_admin_test_same('success', $result['result'], 'Widerruf muss erfolgreich sein.');
    carmaja_admin_test_assert(
        is_string($data['tokens'][$tokenId]['revokedAt']),
        'revokedAt muss gesetzt werden.'
    );
});

carmaja_admin_test('Erneuter Widerruf beschädigt keine Daten', static function (): void {
    $fixture = carmaja_admin_test_fixture();
    $tokenId = str_repeat('c', 32);
    carmaja_admin_test_write_json($fixture['devicesFile'], [
        'environment' => 'test',
        'tokens' => [
            $tokenId => carmaja_admin_test_token(
                $tokenId,
                'test.admin',
                '2026-07-03T12:00:00+00:00'
            ),
        ],
    ]);
    $config = carmaja_admin_build_config();
    $before = file_get_contents($fixture['devicesFile']);
    $result = carmaja_admin_revoke_device($config, $tokenId);

    carmaja_admin_test_same(
        'already_revoked',
        $result['result'],
        'Erneuter Widerruf muss idempotent sein.'
    );
    carmaja_admin_test_same(
        $before,
        file_get_contents($fixture['devicesFile']),
        'Erneuter Widerruf darf Gerätedaten nicht verändern.'
    );
});

carmaja_admin_test('Alle Geräte genau eines Benutzers werden widerrufen', static function (): void {
    $fixture = carmaja_admin_test_fixture();
    carmaja_admin_test_add_user($fixture, 'test.admin');
    $adminOne = str_repeat('d', 32);
    $adminTwo = str_repeat('e', 32);
    $other = str_repeat('f', 32);
    carmaja_admin_test_write_json($fixture['devicesFile'], [
        'environment' => 'test',
        'tokens' => [
            $adminOne => carmaja_admin_test_token($adminOne, 'test.admin'),
            $adminTwo => carmaja_admin_test_token($adminTwo, 'test.admin'),
            $other => carmaja_admin_test_token($other, 'andere.person'),
        ],
    ]);

    $result = carmaja_admin_test_run_cli(
        ['product-admin.php', 'device:revoke-user', '--username=test.admin'],
        ['WIDERRUFEN']
    );
    $data = json_decode(
        (string) file_get_contents($fixture['devicesFile']),
        true,
        512,
        JSON_THROW_ON_ERROR
    );

    carmaja_admin_test_same(
        CARMAJA_ADMIN_EXIT_SUCCESS,
        $result['exitCode'],
        'device:revoke-user muss erfolgreich sein.'
    );
    carmaja_admin_test_assert(
        is_string($data['tokens'][$adminOne]['revokedAt'])
            && is_string($data['tokens'][$adminTwo]['revokedAt']),
        'Alle aktiven Geräte des Benutzers müssen widerrufen werden.'
    );
    carmaja_admin_test_same(
        null,
        $data['tokens'][$other]['revokedAt'],
        'Geräte anderer Benutzer dürfen nicht verändert werden.'
    );
});

carmaja_admin_test('Atomarer Fehler erhält alte Datei', static function (): void {
    $fixture = carmaja_admin_test_fixture();
    $path = $fixture['private'] . DIRECTORY_SEPARATOR . 'atomic.json';
    carmaja_admin_test_write_json($path, ['value' => 'old']);
    $before = file_get_contents($path);

    carmaja_admin_test_exception(
        static function () use ($path): void {
            carmaja_admin_write_json_atomic(
                $path,
                ['value' => 'new'],
                static function (): void {
                    throw new RuntimeException('simulierter Fehler');
                }
            );
        },
        CARMAJA_ADMIN_EXIT_IO,
        'Simulierter atomarer Fehler muss Exit-Code 5 ergeben.'
    );

    carmaja_admin_test_same(
        $before,
        file_get_contents($path),
        'Alte Datei muss bei Fehler unverändert bleiben.'
    );
    carmaja_admin_test_same(
        [],
        glob($path . '.tmp.*') ?: [],
        'Temporäre Dateien müssen entfernt werden.'
    );
});

carmaja_admin_test('Auditfehler wird nicht ignoriert', static function (): void {
    $fixture = carmaja_admin_test_fixture();
    $config = carmaja_admin_build_config();
    $config['auditDir'] = $fixture['private'] . DIRECTORY_SEPARATOR . 'nicht-vorhanden';

    carmaja_admin_test_exception(
        static function () use ($config): void {
            carmaja_admin_audit(
                $config,
                'device_list',
                'test.admin',
                null,
                'success'
            );
        },
        CARMAJA_ADMIN_EXIT_IO,
        'Auditfehler muss Exit-Code 5 ergeben.'
    );
});

carmaja_admin_test('Korrekte CLI-Exit-Codes', static function (): void {
    carmaja_admin_test_fixture();
    $usage = carmaja_admin_test_run_cli(['product-admin.php']);
    $invalid = carmaja_admin_test_run_cli([
        'product-admin.php',
        'user:create',
        '--username=ungültig',
    ]);
    $conflict = carmaja_admin_test_run_cli([
        'product-admin.php',
        'user:password',
        '--username=unbekannt',
    ]);
    putenv('CARMAJA_API_USERS_FILE');
    $io = carmaja_admin_test_run_cli([
        'product-admin.php',
        'device:list',
    ]);

    carmaja_admin_test_same(2, $usage['exitCode'], 'Bedienfehler muss Exit-Code 2 liefern.');
    carmaja_admin_test_same(3, $invalid['exitCode'], 'Eingabefehler muss Exit-Code 3 liefern.');
    carmaja_admin_test_same(4, $conflict['exitCode'], 'Konflikt muss Exit-Code 4 liefern.');
    carmaja_admin_test_same(5, $io['exitCode'], 'I/O-Fehler muss Exit-Code 5 liefern.');
});

$failures = 0;

try {
    foreach ($carmajaAdminTests as $name => $test) {
        try {
            $test();
            echo '[OK] ' . $name . PHP_EOL;
        } catch (Throwable $error) {
            $failures++;
            fwrite(STDERR, '[FEHLER] ' . $name . ': ' . $error->getMessage() . PHP_EOL);
        }
    }
} finally {
    foreach ($carmajaAdminTestRoots as $root) {
        carmaja_admin_test_remove_tree($root);
    }
}

if ($failures > 0) {
    fwrite(STDERR, $failures . ' Test(s) fehlgeschlagen.' . PHP_EOL);
    exit(1);
}

echo count($carmajaAdminTests) . ' Product-Admin-Tests erfolgreich.' . PHP_EOL;
