<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/hosting/_internal/product-api.php';

function assert_true(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$root = sys_get_temp_dir() . '/carmaja-api-test-' . bin2hex(random_bytes(4));
$private = $root . '/private';
$webroot = $root . '/webroot';
mkdir($private, 0750, true);
mkdir($webroot, 0750, true);
$_SERVER['DOCUMENT_ROOT'] = $webroot;
putenv('CARMAJA_PRIVATE_DIR=' . $private);
putenv('CARMAJA_TOKEN_PEPPER=' . str_repeat('a', 32));

$usersFile = $private . '/api-users.json';
file_put_contents($usersFile, json_encode([
    'users' => [
        [
            'username' => 'admin',
            'passwordHash' => password_hash('secret', PASSWORD_DEFAULT),
            'active' => true,
        ],
    ],
], JSON_THROW_ON_ERROR));
putenv('CARMAJA_API_USERS_FILE=' . $usersFile);

$login = carmaja_api_login([
    'username' => 'admin',
    'password' => 'secret',
    'deviceName' => 'Testgeraet',
]);
assert_true(str_starts_with($login['token'], 'ct_'), 'Login muss opakes Token liefern.');

$tokens = carmaja_api_read_json(carmaja_api_tokens_path());
$token = reset($tokens['tokens']);
assert_true(isset($token['secretHash']), 'Token muss gehasht gespeichert werden.');
assert_true(!str_contains(json_encode($tokens), $login['token']), 'Klartexttoken darf nicht gespeichert werden.');

$draftId = '019fa2e6-cf3c-7073-9275-7d3b566f54ee';
$actor = ['tokenId' => 'test', 'username' => 'admin'];
$draft = carmaja_api_save_product($draftId, [
    'expectedVersion' => 0,
    'status' => 'ready',
    'name' => 'Rosenquarz Armband',
    'materials' => ['Rosenquarz'],
    'metalElements' => [],
    'braceletSize' => '17 cm',
    'stock' => 1,
    'shortDescription' => 'Zartes Armband aus Rosenquarz.',
    'careInstructions' => ['Vor Wasser schuetzen'],
    'vintedUrl' => 'https://www.vinted.de/items/123',
    'internalCalculation' => ['materialCosts' => '1.23'],
], $actor);
assert_true($draft['version'] === 1, 'Erstes Speichern muss Version 1 erzeugen.');

try {
    carmaja_api_save_product($draftId, [
        'expectedVersion' => 0,
        'status' => 'ready',
        'name' => 'Veraltet',
        'materials' => ['Rosenquarz'],
        'braceletSize' => '17 cm',
        'shortDescription' => 'Text',
        'vintedUrl' => 'https://www.vinted.de/items/123',
    ], $actor);
    throw new RuntimeException('Versionskonflikt wurde nicht erkannt.');
} catch (CarmajaApiException $error) {
    assert_true($error->statusCode === 409, 'Veraltete expectedVersion muss HTTP 409 ergeben.');
}

try {
    carmaja_api_assert_repo_path_allowed('.github/workflows/deploy-website.yml');
    throw new RuntimeException('Workflow-Pfad wurde nicht blockiert.');
} catch (CarmajaApiException $error) {
    assert_true($error->statusCode === 500, 'Workflow-Pfad muss blockiert werden.');
}

$backup = carmaja_api_create_backup();
assert_true($backup['status'] === 'created', 'Backup muss erstellt werden.');

carmaja_api_remove_tree($root);
echo "Product API tests passed.\n";
