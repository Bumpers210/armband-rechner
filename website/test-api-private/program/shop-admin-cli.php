<?php

declare(strict_types=1);

define('CARMAJA_BOOTSTRAP_NO_RUN', true);
require_once __DIR__ . '/bootstrap.php';

function carmaja_shop_admin_cli_usage(): string
{
    return implode(PHP_EOL, [
        'AP5 Shop-Admin CLI (Passwörter niemals als Argument oder Umgebungswert übergeben)',
        '  /usr/bin/php8.4 shop-admin-cli.php admin:create --username NAME [--config ABSOLUTER_PFAD]',
        '  /usr/bin/php8.4 shop-admin-cli.php admin:password --username NAME [--config ABSOLUTER_PFAD]',
        '  /usr/bin/php8.4 shop-admin-cli.php admin:revoke-sessions --username NAME [--config ABSOLUTER_PFAD]',
    ]) . PHP_EOL;
}

function carmaja_shop_admin_cli_options(array $args): array
{
    $options = ['username' => null, 'config' => null];
    for ($i = 0; $i < count($args); $i++) {
        $arg = $args[$i];
        if (str_starts_with($arg, '--username=')) {
            $options['username'] = substr($arg, 11);
        } elseif ($arg === '--username' && isset($args[$i + 1])) {
            $options['username'] = $args[++$i];
        } elseif (str_starts_with($arg, '--config=')) {
            $options['config'] = substr($arg, 9);
        } elseif ($arg === '--config' && isset($args[$i + 1])) {
            $options['config'] = $args[++$i];
        } else {
            throw new CarmajaShopAdminException('admin_cli_argument_invalid', 'Unbekanntes CLI-Argument.', 2);
        }
    }
    return $options;
}

function carmaja_shop_admin_cli_password(callable $prompt, string $username): string
{
    $first = $prompt('Neues Passwort: ');
    $second = $prompt('Passwort wiederholen: ');
    if (!is_string($first) || !is_string($second) || !hash_equals($first, $second)) {
        throw new CarmajaShopAdminException('admin_password_mismatch', 'Passwörter stimmen nicht überein.', 2);
    }
    return carmaja_shop_admin_hash_password($first);
}

function carmaja_shop_admin_cli_main(array $argv, array $io = []): int
{
    $line = $io['line'] ?? static function (string $prompt): string {
        fwrite(STDOUT, $prompt);
        $value = fgets(STDIN);
        return is_string($value) ? rtrim($value, "\r\n") : '';
    };
    $password = $io['password'] ?? function (string $prompt) use ($line): string {
        if (function_exists('shell_exec')) {
            $value = shell_exec('/usr/bin/stty -echo 2>/dev/null');
            fwrite(STDOUT, $prompt);
            $result = fgets(STDIN);
            shell_exec('/usr/bin/stty echo 2>/dev/null');
            fwrite(STDOUT, PHP_EOL);
            return is_string($result) ? rtrim($result, "\r\n") : '';
        }
        return $line($prompt);
    };
    $out = $io['out'] ?? static function (string $message): void { fwrite(STDOUT, $message . PHP_EOL); };
    try {
        $command = $argv[1] ?? 'help';
        if ($command === 'help') {
            $out(carmaja_shop_admin_cli_usage());
            return 0;
        }
        if (PHP_SAPI !== 'cli') {
            throw new CarmajaShopAdminException('admin_cli_only', 'CLI-Ausführung ist erforderlich.', 2);
        }
        $options = carmaja_shop_admin_cli_options(array_slice($argv, 2));
        if (!is_string($options['username'])) {
            throw new CarmajaShopAdminException('admin_username_required', 'Benutzername ist erforderlich.', 2);
        }
        $username = carmaja_shop_admin_username($options['username']);
        $config = carmaja_bootstrap_prepare($options['config']);
        $commerce = carmaja_bootstrap_commerce($config);
        if ($command === 'admin:create') {
            if (is_array($commerce->loadAdminUser($username))) {
                throw new CarmajaShopAdminException('admin_user_exists', 'Admin-Konto existiert bereits.', 2);
            }
            $hash = carmaja_shop_admin_cli_password($password, $username);
            $commerce->createAdminUser(carmaja_commerce_new_id(), $username, $hash);
            $out('Admin-Konto angelegt.');
            return 0;
        }
        if ($command === 'admin:password') {
            if (!is_array($commerce->loadAdminUser($username))) {
                throw new CarmajaShopAdminException('admin_user_not_found', 'Admin-Konto fehlt.', 2);
            }
            $commerce->updateAdminPassword($username, carmaja_shop_admin_cli_password($password, $username));
            $out('Passwort geändert.');
            return 0;
        }
        if ($command === 'admin:revoke-sessions') {
            $user = $commerce->loadAdminUser($username);
            if (!is_array($user)) {
                throw new CarmajaShopAdminException('admin_user_not_found', 'Admin-Konto fehlt.', 2);
            }
            $out('Sitzungen widerrufen: ' . $commerce->revokeAdminSessions((string) $user['admin_id']));
            return 0;
        }
        throw new CarmajaShopAdminException('admin_cli_command_invalid', 'Unbekannter CLI-Befehl.', 2);
    } catch (CarmajaShopAdminException|CarmajaCommerceException|CarmajaBootstrapException $error) {
        fwrite(STDERR, $error->getMessage() . PHP_EOL);
        return $error instanceof CarmajaShopAdminException || $error instanceof CarmajaCommerceException
            ? $error->httpStatus
            : 2;
    }
}

if (!defined('CARMAJA_SHOP_ADMIN_CLI_NO_RUN')) {
    exit(carmaja_shop_admin_cli_main($argv));
}
