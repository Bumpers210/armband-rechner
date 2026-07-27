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
];
