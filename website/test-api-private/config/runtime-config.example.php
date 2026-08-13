<?php

declare(strict_types=1);

return [
    'environment' => 'test',
    'publishTarget' => 'test',
    'productionPublishEnabled' => false,
    'privateDir' => '/home/www/carmaja-private-test',
    'testPrivateDir' => '/home/www/carmaja-private-test',
    'testApiWebroot' => '/home/www/carmaja-test-api',
    'testWebsiteWebroot' => '/home/www/carmaja-test-site',
    'productionPrivateDir' => null,
    'productionApiWebroot' => null,
    'productionWebsiteWebroot' => null,
    'usersFile' => '/home/www/carmaja-private-test/auth/api-users.json',

    // In runtime-config.php durch einen zufälligen Wert mit mindestens 32 Zeichen ersetzen.
    'tokenPepper' => null,

    // Bleibt bis zur manuellen IONOS-Aktivierung deaktiviert.
    // Fine-grained Token: nur dieses Repository, Contents: write, Actions: read.
    'githubAdapterEnabled' => false,
    'githubRepository' => 'Bumpers210/armband-rechner',
    'githubBranch' => 'test/product-management-beta',
    'githubTokenFile' => null,

    // AP3-Stripe-/Commerce-Vertrag. Niemals in diese Beispielkonfiguration
    // echte Zugangsdaten oder Schlüssel eintragen.
    'commerceDsn' => null,
    'commerceUser' => null,
    'commercePassword' => null,
    'commerceTlsCaPath' => null,
    'commerceRequireTls' => true,
    'stripeSecretKey' => null,
    'stripeWebhookSecret' => null,
    'stripeWebhookPayloadKey' => null,
    'stripeWebhookPayloadKeyId' => null,
    'stripeAutoload' => null,
    'stripeSdkVersion' => '20.3.0',
    'stripeApiVersion' => '2026-06-24.dahlia',
    'stripeWebhookApiVersion' => '2026-07-29.dahlia',
    'stripePaymentMethodTypes' => ['card', 'klarna', 'sepa_debit'],
    'stripeSuccessUrl' => null,
    'stripeCancelUrl' => null,
    'activeLegalBundleId' => null,
    'shippingMethodId' => null,
    'shippingPublicName' => null,
    'shippingAmountMinor' => null,
    'shippingMinBusinessDays' => null,
    'shippingMaxBusinessDays' => null,
    // Exakte Website-Origin fÃ¼r den Ã¶ffentlichen Shop-CORS-Vertrag.
    'shopWebsiteOrigin' => 'https://test.carmaja-perlen.de',
    // AP5-Brevo: im privaten runtime-config.php setzen, nie im Repository.
    'brevoApiKey' => null,
    'brevoSenderEmail' => null,
    'brevoSenderName' => 'Carmaja-Perlen Shop',
    // Produktionsmonitoring bleibt in der Testumgebung immer deaktiviert.
    'monitorEnabled' => false,
    'monitorAlertEmail' => null,
];
