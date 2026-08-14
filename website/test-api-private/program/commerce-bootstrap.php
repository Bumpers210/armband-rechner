<?php

declare(strict_types=1);

require_once __DIR__ . '/commerce-core.php';
require_once __DIR__ . '/stripe-contract.php';

function carmaja_bootstrap_commerce(array $config): CarmajaCommercePdo
{
    $dsn = $config['commerceDsn'] ?? null;
    $user = $config['commerceUser'] ?? null;
    $password = $config['commercePassword'] ?? null;
    if (!is_string($dsn) || !str_starts_with($dsn, 'mysql:')
        || !is_string($user) || $user === '' || !is_string($password)) {
        throw new CarmajaBootstrapException('commerce_configuration_missing', 'Commerce-Datenbank ist nicht konfiguriert.');
    }
    if (($config['commerceRequireTls'] ?? null) !== true) {
        throw new CarmajaBootstrapException('commerce_tls_required', 'Commerce-Datenbank benötigt TLS.');
    }

    $options = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_EMULATE_PREPARES => false,
    ];
    $caPath = $config['commerceTlsCaPath'] ?? null;
    if (is_string($caPath) && $caPath !== '') {
        $options[PDO::MYSQL_ATTR_SSL_CA] = $caPath;
        if (defined('PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT')) {
            $options[PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT] = true;
        }
    } else {
        // AP6 V1 residual risk: IONOS has not provided a usable CA bundle.
        // An explicit cipher requests TLS from PDO; the negotiated cipher and
        // TLS version remain mandatory checks below.
        if (defined('PDO::MYSQL_ATTR_SSL_CIPHER')) {
            $options[PDO::MYSQL_ATTR_SSL_CIPHER] = 'HIGH';
        }
        if (defined('PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT')) {
            $options[PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT] = false;
        }
    }
    try {
        $pdo = new PDO($dsn, $user, $password, $options);
        $status = $pdo->query("SHOW SESSION STATUS WHERE Variable_name IN ('Ssl_cipher','Ssl_version')")
            ->fetchAll(PDO::FETCH_KEY_PAIR);
    } catch (Throwable) {
        throw new CarmajaBootstrapException('commerce_connection_failed', 'Commerce-Datenbank ist nicht erreichbar.');
    }
    if (trim((string) ($status['Ssl_cipher'] ?? '')) === ''
        || trim((string) ($status['Ssl_version'] ?? '')) === '') {
        throw new CarmajaBootstrapException('commerce_tls_not_active', 'Commerce-Datenbankverbindung ist nicht nachweislich verschlüsselt.');
    }
    return new CarmajaCommercePdo($pdo, $config['brevoOperatorEmail'] ?? null);
}

function carmaja_bootstrap_stripe(array $config): CarmajaStripeGateway
{
    return new CarmajaStripeGateway($config);
}
