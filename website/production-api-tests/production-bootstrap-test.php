<?php

declare(strict_types=1);

define('CARMAJA_BOOTSTRAP_NO_RUN', true);
define('CARMAJA_PUBLIC_ENTRYPOINT_NO_RUN', true);
require_once dirname(__DIR__) . '/production-api-private/program/bootstrap.php';
require_once dirname(__DIR__) . '/production-api-public/index.php';

final class ProductionBootstrapTestFailure extends RuntimeException
{
}

function production_bootstrap_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new ProductionBootstrapTestFailure($message);
    }
}

function production_bootstrap_expect(string $code, callable $callback): void
{
    try {
        $callback();
    } catch (CarmajaBootstrapException $error) {
        production_bootstrap_assert($error->errorCode === $code, 'Falscher Fehlercode: ' . $error->errorCode);
        return;
    }

    throw new ProductionBootstrapTestFailure('Erwartete Bootstrap-Ausnahme fehlt.');
}

function production_bootstrap_fixture(): array
{
    $root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'carmaja-production-bootstrap-'
        . bin2hex(random_bytes(6));
    $private = $root . DIRECTORY_SEPARATOR . 'private';
    $api = $root . DIRECTORY_SEPARATOR . 'api';
    $site = $root . DIRECTORY_SEPARATOR . 'site';
    $configDirectory = $private . DIRECTORY_SEPARATOR . 'config';

    foreach ([$configDirectory, $private . DIRECTORY_SEPARATOR . 'auth', $api, $site] as $directory) {
        if (!mkdir($directory, 0750, true) && !is_dir($directory)) {
            throw new ProductionBootstrapTestFailure('Fixture-Verzeichnis konnte nicht erstellt werden.');
        }
    }

    $configFile = $configDirectory . DIRECTORY_SEPARATOR . 'runtime-config.php';
    file_put_contents($configFile, "<?php return [];\n");

    return [
        'root' => $root,
        'private' => $private,
        'api' => $api,
        'site' => $site,
        'configFile' => $configFile,
        'config' => [
            'environment' => 'production',
            'publishTarget' => 'production',
            'productionPublishEnabled' => false,
            'privateDir' => $private,
            'apiWebroot' => $api,
            'websiteWebroot' => $site,
            'usersFile' => $private . DIRECTORY_SEPARATOR . 'auth' . DIRECTORY_SEPARATOR . 'api-users.json',
            'tokenPepper' => str_repeat('p', 48),
            'githubAdapterEnabled' => false,
            'githubRepository' => 'Bumpers210/armband-rechner',
            'githubBranch' => 'main',
            'githubTokenFile' => null,
        ],
    ];
}

function production_bootstrap_remove_tree(string $path): void
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
            production_bootstrap_remove_tree($path . DIRECTORY_SEPARATOR . $entry);
        }
    }

    @rmdir($path);
}

$fixture = production_bootstrap_fixture();

try {
    $validated = carmaja_bootstrap_validate_config($fixture['config'], $fixture['configFile']);
    production_bootstrap_assert($validated['publishTarget'] === 'production', 'Produktionsziel fehlt.');
    production_bootstrap_assert($validated['githubAdapterEnabled'] === false, 'Publisher muss standardmaessig deaktiviert sein.');
    production_bootstrap_expect('github_target_invalid', static function () use ($fixture): void {
        $config = $fixture['config'];
        $config['githubBranch'] = 'test/product-management-beta';
        carmaja_bootstrap_validate_config($config, $fixture['configFile']);
    });
    production_bootstrap_expect('config_paths_not_separated', static function () use ($fixture): void {
        $config = $fixture['config'];
        $config['websiteWebroot'] = $fixture['private'] . DIRECTORY_SEPARATOR . 'site';
        carmaja_bootstrap_validate_config($config, $fixture['configFile']);
    });
    production_bootstrap_expect('github_adapter_configuration_invalid', static function () use ($fixture): void {
        $config = $fixture['config'];
        $config['githubAdapterEnabled'] = true;
        carmaja_bootstrap_validate_config($config, $fixture['configFile']);
    });
    production_bootstrap_assert(
        carmaja_public_resolve_bootstrap($fixture['api'] . DIRECTORY_SEPARATOR . 'bootstrap.php', $fixture['api']) === null,
        'Oeffentlicher Bootstrap darf nicht akzeptiert werden.'
    );
    $privateBootstrap = $fixture['private'] . DIRECTORY_SEPARATOR . 'bootstrap.php';
    copy(dirname(__DIR__) . '/production-api-private/program/bootstrap.php', $privateBootstrap);
    production_bootstrap_assert(
        carmaja_public_resolve_bootstrap($privateBootstrap, $fixture['api']) === realpath($privateBootstrap),
        'Privater Bootstrap wurde nicht akzeptiert.'
    );
    $runtime = file_get_contents(dirname(__DIR__) . '/production-api-private/config/runtime-config.example.php');
    production_bootstrap_assert(is_string($runtime) && str_contains($runtime, "'productionPublishEnabled' => false"), 'Sicherer Publisher-Default fehlt.');
    production_bootstrap_assert(is_string($runtime) && str_contains($runtime, "'githubBranch' => 'main'"), 'Fester Produktionsbranch fehlt.');
    $diagnostics = file_get_contents(dirname(__DIR__) . '/production-api-private/program/product-api.php');
    production_bootstrap_assert(is_string($diagnostics) && str_contains($diagnostics, 'PHP_MAJOR_VERSION !== 8 || PHP_MINOR_VERSION !== 4'), 'PHP-8.4-Diagnose fehlt.');
    echo "production-bootstrap: OK\n";
} finally {
    production_bootstrap_remove_tree($fixture['root']);
}
