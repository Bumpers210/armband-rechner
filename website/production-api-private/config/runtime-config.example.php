<?php

declare(strict_types=1);

return [
    'environment' => 'production',
    'publishTarget' => 'production',
    'productionPublishEnabled' => false,
    'privateDir' => '/REPLACE_WITH_VERIFIED_PRIVATE_PRODUCTION_PATH',
    'apiWebroot' => '/REPLACE_WITH_VERIFIED_PRODUCTION_API_WEBROOT',
    'websiteWebroot' => '/REPLACE_WITH_VERIFIED_PRODUCTION_WEBSITE_WEBROOT',
    'usersFile' => '/REPLACE_WITH_VERIFIED_PRIVATE_PRODUCTION_PATH/auth/api-users.json',
    'tokenPepper' => 'REPLACE_WITH_A_UNIQUE_RANDOM_PEPPER_OF_AT_LEAST_32_CHARACTERS',
    'githubAdapterEnabled' => false,
    'githubRepository' => 'Bumpers210/armband-rechner',
    'githubBranch' => 'main',
    'githubTokenFile' => null,
];
