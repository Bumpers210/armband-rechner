<?php

declare(strict_types=1);

define('CARMAJA_BOOTSTRAP_NO_RUN', true);
define('CARMAJA_PUBLIC_ENTRYPOINT_NO_RUN', true);
require_once dirname(__DIR__, 2) . '/test-api-private/program/bootstrap.php';
require_once dirname(__DIR__, 2) . '/test-api-public/index.php';

final class CarmajaBootstrapTestFailure extends RuntimeException
{
}

$carmajaBootstrapTests = [];
$carmajaBootstrapRoots = [];

function carmaja_bootstrap_test(string $name, callable $test): void
{
    global $carmajaBootstrapTests;
    $carmajaBootstrapTests[$name] = $test;
}

function carmaja_bootstrap_test_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new CarmajaBootstrapTestFailure($message);
    }
}

function carmaja_bootstrap_test_same(mixed $expected, mixed $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new CarmajaBootstrapTestFailure(
            $message
            . ' Erwartet: '
            . var_export($expected, true)
            . '; erhalten: '
            . var_export($actual, true)
        );
    }
}

function carmaja_bootstrap_test_exception(
    callable $callback,
    string $errorCode
): CarmajaBootstrapException {
    try {
        $callback();
    } catch (CarmajaBootstrapException $error) {
        carmaja_bootstrap_test_same(
            $errorCode,
            $error->errorCode,
            'Unerwarteter Bootstrap-Fehlercode.'
        );
        return $error;
    }

    throw new CarmajaBootstrapTestFailure('Erwarteter Bootstrap-Fehler wurde nicht ausgelöst.');
}

function carmaja_bootstrap_test_write_config(string $path, array $config): void
{
    $written = file_put_contents(
        $path,
        "<?php\n\ndeclare(strict_types=1);\n\nreturn "
            . var_export($config, true)
            . ";\n"
    );

    if ($written === false) {
        throw new CarmajaBootstrapTestFailure('Testkonfiguration konnte nicht geschrieben werden.');
    }
}

function carmaja_bootstrap_test_remove_tree(string $path): void
{
    if (!file_exists($path)) {
        return;
    }

    if (is_file($path) || is_link($path)) {
        @unlink($path);
        return;
    }

    foreach (scandir($path) ?: [] as $entry) {
        if ($entry !== '.' && $entry !== '..') {
            carmaja_bootstrap_test_remove_tree($path . DIRECTORY_SEPARATOR . $entry);
        }
    }

    @rmdir($path);
}

function carmaja_bootstrap_test_fixture(): array
{
    global $carmajaBootstrapRoots;

    $root = sys_get_temp_dir()
        . DIRECTORY_SEPARATOR
        . 'carmaja-bootstrap-test-'
        . bin2hex(random_bytes(6));
    $private = $root . DIRECTORY_SEPARATOR . 'private';
    $apiWebroot = $root . DIRECTORY_SEPARATOR . 'test-api';
    $websiteWebroot = $root . DIRECTORY_SEPARATOR . 'test-site';
    $configDirectory = $private . DIRECTORY_SEPARATOR . 'config';
    $authDirectory = $private . DIRECTORY_SEPARATOR . 'auth';

    foreach ([
        $root,
        $private,
        $apiWebroot,
        $websiteWebroot,
        $configDirectory,
        $authDirectory,
    ] as $directory) {
        if (!is_dir($directory) && !mkdir($directory, 0750, true) && !is_dir($directory)) {
            throw new CarmajaBootstrapTestFailure('Testverzeichnis konnte nicht angelegt werden.');
        }
    }

    file_put_contents(
        $private . DIRECTORY_SEPARATOR . 'environment.json',
        '{"environment":"test"}'
    );
    $usersFile = $authDirectory . DIRECTORY_SEPARATOR . 'api-users.json';
    file_put_contents($usersFile, '{"environment":"test","users":[]}');
    $configFile = $configDirectory . DIRECTORY_SEPARATOR . 'runtime-config.php';
    $config = [
        'environment' => 'test',
        'publishTarget' => 'test',
        'productionPublishEnabled' => false,
        'privateDir' => $private,
        'testPrivateDir' => $private,
        'testApiWebroot' => $apiWebroot,
        'testWebsiteWebroot' => $websiteWebroot,
        'productionPrivateDir' => null,
        'productionApiWebroot' => null,
        'productionWebsiteWebroot' => null,
        'usersFile' => $usersFile,
        'tokenPepper' => str_repeat('s', 48),
    ];
    carmaja_bootstrap_test_write_config($configFile, $config);
    $carmajaBootstrapRoots[] = $root;

    return [
        'root' => $root,
        'private' => $private,
        'apiWebroot' => $apiWebroot,
        'websiteWebroot' => $websiteWebroot,
        'configFile' => $configFile,
        'config' => $config,
    ];
}

carmaja_bootstrap_test(
    'Testkonfiguration ohne Produktionspfade wird aktiviert',
    static function (): void {
        $fixture = carmaja_bootstrap_test_fixture();
        $config = carmaja_bootstrap_prepare($fixture['configFile']);

        carmaja_bootstrap_test_same('test', $config['publishTarget'], 'Falsches Ziel.');
        carmaja_bootstrap_test_same(false, $config['productApiV4WritesEnabled'], 'v4-Schreiben muss standardmäßig gesperrt sein.');
        carmaja_bootstrap_test_same(false, $config['collectionCommerceEnabled'], 'Kollektionen-Checkout muss standardmäßig gesperrt sein.');
        carmaja_bootstrap_test_same(
            false,
            $config['githubAdapterEnabled'],
            'GitHub-Adapter muss ohne ausdrückliche Konfiguration deaktiviert sein.'
        );
        carmaja_bootstrap_test_same(
            false,
            $config['monitorEnabled'],
            'Produktionsmonitoring muss ohne ausdrückliche Konfiguration deaktiviert sein.'
        );
        carmaja_bootstrap_test_assert(
            !isset($GLOBALS['CARMAJA_API_PUBLISH_ADAPTER']),
            'Deaktivierter GitHub-Adapter darf nicht als Publish-Adapter gesetzt sein.'
        );
        carmaja_bootstrap_test_same(
            false,
            getenv('CARMAJA_PRODUCTION_PRIVATE_DIR'),
            'Fehlender Produktionspfad darf nicht erfunden werden.'
        );
        carmaja_bootstrap_test_same(
            realpath($fixture['private']),
            carmaja_api_private_dir(),
            'Aktiver privater Pfad stimmt nicht.'
        );
    }
);

carmaja_bootstrap_test(
    'Kollektionen-Schalter akzeptieren ausschließlich boolesche Werte',
    static function (): void {
        $fixture = carmaja_bootstrap_test_fixture();
        $config = $fixture['config'];
        $config['productApiV4WritesEnabled'] = 'true';
        carmaja_bootstrap_test_write_config($fixture['configFile'], $config);
        carmaja_bootstrap_test_exception(
            static fn (): array => carmaja_bootstrap_load_config($fixture['configFile']),
            'config_environment_invalid'
        );
    }
);

carmaja_bootstrap_test(
    'Produktionsmonitoring kann in der Testumgebung nicht aktiviert werden',
    static function (): void {
        $fixture = carmaja_bootstrap_test_fixture();
        $config = $fixture['config'];
        $config['monitorEnabled'] = true;
        $config['monitorAlertEmail'] = 'operator@example.invalid';
        $config['brevoApiKey'] = 'synthetic';
        $config['brevoSenderEmail'] = 'sender@example.invalid';
        carmaja_bootstrap_test_write_config($fixture['configFile'], $config);

        carmaja_bootstrap_test_exception(
            static fn (): array => carmaja_bootstrap_load_config($fixture['configFile']),
            'config_secret_invalid'
        );
    }
);

carmaja_bootstrap_test(
    'GitHub-Adapter akzeptiert ausschließlich private Testkonfiguration',
    static function (): void {
        $fixture = carmaja_bootstrap_test_fixture();
        $tokenFile = $fixture['private'] . DIRECTORY_SEPARATOR . 'github-token.txt';
        file_put_contents($tokenFile, 'test-token-placeholder');
        $config = $fixture['config'];
        $config['githubAdapterEnabled'] = true;
        $config['githubRepository'] = 'Bumpers210/armband-rechner';
        $config['githubBranch'] = 'test/product-management-beta';
        $config['githubTokenFile'] = $tokenFile;
        carmaja_bootstrap_test_write_config($fixture['configFile'], $config);
        $loaded = carmaja_bootstrap_prepare($fixture['configFile']);

        carmaja_bootstrap_test_same(
            true,
            $loaded['githubAdapterEnabled'],
            'Sichere Testkonfiguration wurde nicht aktiviert.'
        );
        carmaja_bootstrap_test_same(
            'carmaja_api_github_publish_adapter',
            $GLOBALS['CARMAJA_API_PUBLISH_ADAPTER'] ?? null,
            'GitHub-Testadapter wurde nicht eindeutig verdrahtet.'
        );
        carmaja_bootstrap_test_same(
            'carmaja_api_github_publish_adapter',
            $GLOBALS['CARMAJA_API_PUBLISH_ADAPTER_V2'] ?? null,
            'GitHub-Testadapter wurde nicht für den v2-Publisher verdrahtet.'
        );
        unset($GLOBALS['CARMAJA_API_PUBLISH_ADAPTER']);
        unset($GLOBALS['CARMAJA_API_PUBLISH_ADAPTER_V2']);
    }
);

carmaja_bootstrap_test(
    'Produktionskonfiguration darf main nur ohne automatischen GitHub-Adapter referenzieren',
    static function (): void {
        $fixture = carmaja_bootstrap_test_fixture();
        $productionPrivate = $fixture['root'] . DIRECTORY_SEPARATOR . 'production-runtime-private';
        $productionProductPrivate = $fixture['root'] . DIRECTORY_SEPARATOR . 'production-product-private';
        $productionApi = $fixture['root'] . DIRECTORY_SEPARATOR . 'production-api';
        $productionWebsite = $fixture['root'] . DIRECTORY_SEPARATOR . 'production-site';
        foreach ([$productionPrivate, $productionProductPrivate, $productionApi, $productionWebsite] as $directory) {
            mkdir($directory, 0750, true);
        }
        $productionAuth = $productionProductPrivate . DIRECTORY_SEPARATOR . 'auth';
        $productionConfigDirectory = $productionPrivate . DIRECTORY_SEPARATOR . 'config';
        mkdir($productionAuth, 0750, true);
        mkdir($productionConfigDirectory, 0750, true);
        $productionUsers = $productionAuth . DIRECTORY_SEPARATOR . 'api-users.json';
        file_put_contents($productionUsers, '{"environment":"production","users":[]}');
        file_put_contents(
            $productionProductPrivate . DIRECTORY_SEPARATOR . 'environment.json',
            '{"environment":"production"}'
        );
        $productionConfig = $productionConfigDirectory . DIRECTORY_SEPARATOR . 'runtime-config.php';
        $config = $fixture['config'];
        $config['environment'] = 'production';
        $config['publishTarget'] = 'production';
        $config['privateDir'] = $productionPrivate;
        $config['productPrivateDir'] = $productionProductPrivate;
        unset($config['testPrivateDir'], $config['testApiWebroot'], $config['testWebsiteWebroot']);
        $config['productionPrivateDir'] = $productionPrivate;
        $config['productionApiWebroot'] = $productionApi;
        $config['productionWebsiteWebroot'] = $productionWebsite;
        $config['usersFile'] = $productionUsers;
        $config['backupEncryptionKeyFile'] = $productionConfigDirectory
            . DIRECTORY_SEPARATOR
            . 'backup-key.php';
        $config['githubBranch'] = 'main';
        $config['brevoApiKey'] = 'synthetic';
        $config['brevoSenderEmail'] = 'sender@example.invalid';
        $config['brevoOperatorEmail'] = 'operator@example.invalid';
        $config['monitorEnabled'] = true;
        $config['monitorAlertEmail'] = 'operator@example.invalid';
        carmaja_bootstrap_test_write_config($productionConfig, $config);
        $loaded = carmaja_bootstrap_prepare($productionConfig);
        carmaja_bootstrap_test_same('main', $loaded['githubBranch'], 'Main-Referenz fehlt.');
        carmaja_bootstrap_test_same(false, $loaded['githubAdapterEnabled'], 'GitHub-Adapter muss deaktiviert bleiben.');
        carmaja_bootstrap_test_same(true, $loaded['monitorEnabled'], 'Produktionsmonitoring wurde nicht aktiviert.');
        carmaja_bootstrap_test_same(
            'operator@example.invalid',
            $loaded['monitorAlertEmail'],
            'Alarmadresse wurde nicht geladen.'
        );
        carmaja_bootstrap_test_same(
            $productionProductPrivate,
            $loaded['productPrivateDir'],
            'Produktdatenpfad wurde nicht getrennt geladen.'
        );
        carmaja_bootstrap_test_same(
            $config['backupEncryptionKeyFile'],
            $loaded['backupEncryptionKeyFile'],
            'Private Backup-Schlüsseldatei wurde nicht geladen.'
        );
        carmaja_bootstrap_test_same(
            realpath($productionProductPrivate),
            carmaja_api_private_dir(),
            'Produktionspfad muss ohne Testpfad sicher aktiviert werden.'
        );
    }
);

carmaja_bootstrap_test(
    'Produktionskonfiguration lehnt Legacy-GitHub-Token ab',
    static function (): void {
        $fixture = carmaja_bootstrap_test_fixture();
        $runtimePrivate = $fixture['root'] . DIRECTORY_SEPARATOR . 'production-runtime-private';
        $productPrivate = $fixture['root'] . DIRECTORY_SEPARATOR . 'production-product-private';
        $apiWebroot = $fixture['root'] . DIRECTORY_SEPARATOR . 'production-api';
        $websiteWebroot = $fixture['root'] . DIRECTORY_SEPARATOR . 'production-site';
        foreach ([$runtimePrivate, $productPrivate, $apiWebroot, $websiteWebroot] as $directory) {
            mkdir($directory, 0750, true);
        }
        mkdir($runtimePrivate . DIRECTORY_SEPARATOR . 'config', 0750, true);
        mkdir($productPrivate . DIRECTORY_SEPARATOR . 'auth', 0750, true);
        $usersFile = $productPrivate . DIRECTORY_SEPARATOR . 'auth' . DIRECTORY_SEPARATOR . 'api-users.json';
        file_put_contents($usersFile, '{"environment":"production","users":[]}');
        $tokenFile = $runtimePrivate . DIRECTORY_SEPARATOR . 'github-token';
        file_put_contents($tokenFile, 'legacy-token-placeholder');
        $configFile = $runtimePrivate . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'runtime-config.php';
        $config = [
            'environment' => 'production',
            'publishTarget' => 'production',
            'productionPublishEnabled' => false,
            'privateDir' => $runtimePrivate,
            'productPrivateDir' => $productPrivate,
            'productionPrivateDir' => $runtimePrivate,
            'productionApiWebroot' => $apiWebroot,
            'productionWebsiteWebroot' => $websiteWebroot,
            'usersFile' => $usersFile,
            'tokenPepper' => str_repeat('p', 48),
            'githubAdapterEnabled' => false,
            'githubBranch' => 'main',
            'githubTokenFile' => $tokenFile,
        ];
        carmaja_bootstrap_test_write_config($configFile, $config);

        carmaja_bootstrap_test_exception(
            static fn (): array => carmaja_bootstrap_load_config($configFile),
            'production_github_token_forbidden'
        );
    }
);

carmaja_bootstrap_test(
    'GitHub-Adapter lehnt main und öffentliche Tokenpfade ab',
    static function (): void {
        $fixture = carmaja_bootstrap_test_fixture();
        $config = $fixture['config'];
        $config['githubBranch'] = 'main';
        carmaja_bootstrap_test_write_config($fixture['configFile'], $config);
        carmaja_bootstrap_test_exception(
            static fn (): array => carmaja_bootstrap_load_config(
                $fixture['configFile']
            ),
            'github_branch_invalid'
        );

        $config['githubBranch'] = 'test/product-management-beta';
        $config['githubRepository'] = 'other/example';
        carmaja_bootstrap_test_write_config($fixture['configFile'], $config);
        carmaja_bootstrap_test_exception(
            static fn (): array => carmaja_bootstrap_load_config(
                $fixture['configFile']
            ),
            'github_repository_invalid'
        );

        $publicToken = $fixture['apiWebroot'] . DIRECTORY_SEPARATOR . 'token.txt';
        file_put_contents($publicToken, 'test-token-placeholder');
        $config['githubAdapterEnabled'] = true;
        $config['githubRepository'] = 'Bumpers210/armband-rechner';
        $config['githubBranch'] = 'test/product-management-beta';
        $config['githubTokenFile'] = $publicToken;
        carmaja_bootstrap_test_write_config($fixture['configFile'], $config);
        carmaja_bootstrap_test_exception(
            static fn (): array => carmaja_bootstrap_load_config(
                $fixture['configFile']
            ),
            'config_private_file_exposed'
        );
    }
);

carmaja_bootstrap_test(
    'Produktionsmodus verlangt alle Produktionspfade',
    static function (): void {
        $fixture = carmaja_bootstrap_test_fixture();
        $config = $fixture['config'];
        $config['environment'] = 'production';
        $config['publishTarget'] = 'production';
        carmaja_bootstrap_test_write_config($fixture['configFile'], $config);

        carmaja_bootstrap_test_exception(
            static fn (): array => carmaja_bootstrap_load_config($fixture['configFile']),
            'production_paths_required'
        );
    }
);

carmaja_bootstrap_test(
    'Konfigurierte Produktionspfade müssen getrennt bleiben',
    static function (): void {
        $fixture = carmaja_bootstrap_test_fixture();
        $config = $fixture['config'];
        $config['productionApiWebroot'] = $fixture['apiWebroot'];
        carmaja_bootstrap_test_write_config($fixture['configFile'], $config);

        carmaja_bootstrap_test_exception(
            static fn (): array => carmaja_bootstrap_load_config($fixture['configFile']),
            'config_paths_not_separated'
        );
    }
);

carmaja_bootstrap_test(
    'Konfigurationsdatei muss privat liegen',
    static function (): void {
        $fixture = carmaja_bootstrap_test_fixture();
        $publicConfig = $fixture['apiWebroot'] . DIRECTORY_SEPARATOR . 'runtime-config.php';
        carmaja_bootstrap_test_write_config($publicConfig, $fixture['config']);

        carmaja_bootstrap_test_exception(
            static fn (): array => carmaja_bootstrap_load_config($publicConfig),
            'config_private_file_exposed'
        );
    }
);

carmaja_bootstrap_test(
    'Öffentlicher Einstiegspunkt verwendet privaten Bootstrap',
    static function (): void {
        $fixture = carmaja_bootstrap_test_fixture();
        $bootstrapFile = realpath(
            dirname(__DIR__, 2) . '/test-api-private/program/bootstrap.php'
        );

        carmaja_bootstrap_test_assert(
            is_string($bootstrapFile),
            'Bootstrap-Testdatei fehlt.'
        );
        $publicBootstrap = $fixture['apiWebroot'] . DIRECTORY_SEPARATOR . 'bootstrap.php';
        file_put_contents($publicBootstrap, "<?php\n");
        carmaja_bootstrap_test_same(
            $bootstrapFile,
            carmaja_public_resolve_bootstrap(
                null,
                $bootstrapFile,
                $fixture['apiWebroot']
            ),
            'Nicht gesetztes SetEnv muss den festen privaten Fallback verwenden.'
        );
        carmaja_bootstrap_test_same(
            null,
            carmaja_public_resolve_bootstrap(
                $publicBootstrap,
                $bootstrapFile,
                $fixture['apiWebroot']
            ),
            'Explizit unsicherer Bootstrap darf nicht auf den Fallback ausweichen.'
        );
    }
);

carmaja_bootstrap_test(
    'Fehlermeldungen enthalten weder Pfad noch Geheimnis',
    static function (): void {
        $fixture = carmaja_bootstrap_test_fixture();
        $config = $fixture['config'];
        $config['tokenPepper'] = 'kurz';
        carmaja_bootstrap_test_write_config($fixture['configFile'], $config);
        $error = carmaja_bootstrap_test_exception(
            static fn (): array => carmaja_bootstrap_load_config($fixture['configFile']),
            'config_secret_invalid'
        );

        carmaja_bootstrap_test_assert(
            !str_contains($error->getMessage(), $fixture['root'])
                && !str_contains($error->getMessage(), 'kurz'),
            'Bootstrap-Fehler darf keine Pfade oder Geheimnisse ausgeben.'
        );
    }
);

carmaja_bootstrap_test(
    'Öffentliche Apache-Konfiguration enthält keine Geheimwerte',
    static function (): void {
        $contents = file_get_contents(
            dirname(__DIR__, 2) . '/test-api-public/.htaccess'
        );

        carmaja_bootstrap_test_assert(
            is_string($contents)
                && str_contains($contents, 'CARMAJA_BOOTSTRAP_FILE')
                && str_contains($contents, 'RewriteRule ^ index.php')
                && !preg_match('/token|pepper|password|secret/i', $contents),
            'Öffentliche .htaccess enthält unerwartete oder sensible Konfiguration.'
        );
    }
);

$failures = 0;

try {
    foreach ($carmajaBootstrapTests as $name => $test) {
        try {
            $test();
            echo '[OK] ' . $name . PHP_EOL;
        } catch (Throwable $error) {
            $failures++;
            fwrite(STDERR, '[FEHLER] ' . $name . ': ' . $error->getMessage() . PHP_EOL);
        }
    }
} finally {
    foreach ($carmajaBootstrapRoots as $root) {
        carmaja_bootstrap_test_remove_tree($root);
    }
}

if ($failures > 0) {
    fwrite(STDERR, $failures . ' Bootstrap-Test(s) fehlgeschlagen.' . PHP_EOL);
    exit(1);
}

echo count($carmajaBootstrapTests) . ' Bootstrap-Tests erfolgreich.' . PHP_EOL;
