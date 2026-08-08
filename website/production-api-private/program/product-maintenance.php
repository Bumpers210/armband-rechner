<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

define('CARMAJA_BOOTSTRAP_NO_RUN', true);
require_once __DIR__ . '/bootstrap.php';

function carmaja_maintenance_usage(): string
{
    return implode(PHP_EOL, [
        'Verwendung:',
        '  product-maintenance.php backup',
        '  product-maintenance.php restore --backup <Backup-ID> --dry-run',
        '  product-maintenance.php restore --backup <Backup-ID>',
        '  product-maintenance.php migrate-v2 --dry-run',
        '  product-maintenance.php migrate-v2 --apply',
    ]) . PHP_EOL;
}

function carmaja_maintenance_require_config(): void
{
    $configFile = getenv('CARMAJA_CONFIG_FILE');

    if (!is_string($configFile) || trim($configFile) === '') {
        throw new CarmajaBootstrapException(
            'config_path_missing',
            'CARMAJA_CONFIG_FILE ist nicht konfiguriert.'
        );
    }

    $config = carmaja_bootstrap_prepare(trim($configFile));

    if ($config['publishTarget'] !== 'production') {
        throw new CarmajaBootstrapException(
            'maintenance_target_invalid',
            'Wartung ist nur fuer die Produktions-API erlaubt.'
        );
    }
}

function carmaja_maintenance_confirm_restore(string $backup): bool
{
    fwrite(
        STDERR,
        'Backup ' . $backup . ' wird wiederhergestellt. Tippen Sie WIEDERHERSTELLEN: '
    );
    $confirmation = fgets(STDIN);

    return is_string($confirmation) && trim($confirmation) === 'WIEDERHERSTELLEN';
}

function carmaja_maintenance_confirm_product_model_migration(): bool
{
    fwrite(
        STDERR,
        'V2-Produktmigration erstellt zuerst ein Backup. Tippen Sie '
            . CARMAJA_PRODUCT_MODEL_MIGRATION_CONFIRMATION . ': '
    );
    $confirmation = fgets(STDIN);

    return is_string($confirmation)
        && trim($confirmation) === CARMAJA_PRODUCT_MODEL_MIGRATION_CONFIRMATION;
}

function carmaja_maintenance_main(array $argv): int
{
    try {
        carmaja_maintenance_require_config();
        $arguments = array_values(array_slice($argv, 1));

        if ($arguments === ['backup']) {
            $result = carmaja_api_create_backup();
        } elseif ($arguments === ['migrate-v2', '--dry-run']) {
            $result = carmaja_api_migrate_product_model_v2(false);
        } elseif ($arguments === ['migrate-v2', '--apply']) {
            if (!carmaja_maintenance_confirm_product_model_migration()) {
                throw new RuntimeException('V2-Produktmigration wurde nicht bestätigt.');
            }

            $result = carmaja_api_migrate_product_model_v2(true);
        } elseif (($arguments[0] ?? null) === 'restore') {
            $backup = null;
            $dryRun = false;

            for ($index = 1; $index < count($arguments); $index++) {
                if ($arguments[$index] === '--dry-run') {
                    $dryRun = true;
                    continue;
                }

                if ($arguments[$index] === '--backup'
                    && isset($arguments[$index + 1])
                    && is_string($arguments[$index + 1])) {
                    $backup = $arguments[++$index];
                    continue;
                }

                throw new InvalidArgumentException('Ungueltige Wartungsoption.');
            }

            if (!is_string($backup) || $backup === '') {
                throw new InvalidArgumentException('Backup-ID fehlt.');
            }

            if (!$dryRun && !carmaja_maintenance_confirm_restore($backup)) {
                throw new RuntimeException('Wiederherstellung wurde nicht bestaetigt.');
            }

            $result = carmaja_api_restore_backup($backup, $dryRun);
        } else {
            throw new InvalidArgumentException('Wartungsaktion fehlt oder ist ungueltig.');
        }

        echo json_encode(
            $result,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
        ) . PHP_EOL;

        return 0;
    } catch (InvalidArgumentException $error) {
        fwrite(STDERR, $error->getMessage() . PHP_EOL . carmaja_maintenance_usage());
        return 2;
    } catch (CarmajaApiException|CarmajaBootstrapException|RuntimeException $error) {
        fwrite(STDERR, 'Wartung fehlgeschlagen: ' . $error->getMessage() . PHP_EOL);
        return 5;
    } catch (Throwable) {
        fwrite(STDERR, "Wartung fehlgeschlagen.\n");
        return 5;
    }
}

exit(carmaja_maintenance_main($_SERVER['argv'] ?? []));
