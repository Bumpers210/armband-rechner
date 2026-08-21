<?php

declare(strict_types=1);

define('CARMAJA_PRODUCTION_SHOP_API_NO_RUN', true);
define('CARMAJA_PRODUCTION_CUTOVER_NO_RUN', true);
require_once dirname(__DIR__, 2) . '/production-shop-api-public/index.php';
require_once dirname(__DIR__, 2) . '/scripts/production-cutover.php';

$tests = 0;

function ap7_assert(bool $condition, string $message): void
{
    global $tests;
    $tests++;
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'carmaja-ap7-' . bin2hex(random_bytes(6));
$webroot = $root . DIRECTORY_SEPARATOR . 'public';
$private = $root . DIRECTORY_SEPARATOR . 'private';
mkdir($webroot, 0750, true);
mkdir($private, 0750, true);
$bootstrap = $private . DIRECTORY_SEPARATOR . 'bootstrap.php';
file_put_contents($bootstrap, "<?php\n");

try {
    ap7_assert(
        carmaja_production_shop_api_resolve_bootstrap($bootstrap, $webroot) === realpath($bootstrap),
        'Privater Produktions-Bootstrap wurde nicht akzeptiert.'
    );
    ap7_assert(
        carmaja_production_shop_api_resolve_bootstrap('relative/bootstrap.php', $webroot) === null,
        'Relativer Bootstrap-Pfad wurde nicht abgelehnt.'
    );
    $publicBootstrap = $webroot . DIRECTORY_SEPARATOR . 'bootstrap.php';
    file_put_contents($publicBootstrap, "<?php\n");
    ap7_assert(
        carmaja_production_shop_api_resolve_bootstrap($publicBootstrap, $webroot) === null,
        'Bootstrap im Webroot wurde nicht abgelehnt.'
    );

    $repositoryRoot = dirname(__DIR__, 3);
    $manifest = carmaja_cutover_read_json(
        dirname(__DIR__, 2) . '/config/production-collection-cutover-manifest.v2.json'
    );
    $contract = carmaja_cutover_validate_contract($manifest, $repositoryRoot);
    ap7_assert($contract['readyForPlan'] === true, 'Freigegebenes Cutovermanifest wurde nicht erkannt.');
    ap7_assert($contract['readyForApply'] === true, 'Cutovermanifest wurde nicht als bereit bewertet.');
    ap7_assert($contract['selectedProductCount'] === 1, 'Vorbereitete Ein-Produktauswahl fehlt.');
    ap7_assert(
        ($manifest['selectedCollections'][0]['productId'] ?? null)
            === '3da76a24-3213-4e8f-b9aa-336ea95e4aa3',
        'Ares fehlt im freigegebenen Kollektionen-Manifest.'
    );
    $planOnlyManifest = $manifest;
    $planOnlyManifest['status'] = 'approved_for_plan';
    try {
        carmaja_cutover_selected_product($planOnlyManifest, [
            'version' => 3,
            'products' => [],
        ]);
        throw new RuntimeException('Nur planfreigegebene Produktauswahl wurde fuer Apply akzeptiert.');
    } catch (CarmajaProductionCutoverException $error) {
        ap7_assert(
            $error->getMessage() === 'product_selection_not_approved',
            'Planmanifest ist fuer Apply nicht fail-closed.'
        );
    }

    $sourceProduct = [
        'productModelVersion' => 3,
        'productId' => '11111111-1111-4111-8111-111111111111',
        'productVersion' => 3,
        'sku' => 'CP-TEST-COLLECTION',
        'title' => 'Kuenstliche AP7-Kollektion',
        'description' => 'Nur fuer den lokalen Vertragstest.',
        'descriptionDocument' => [
            'paragraphs' => [[
                'runs' => [[
                    'text' => 'Nur fuer den lokalen Vertragstest.',
                    'bold' => false,
                    'italic' => false,
                    'font' => 'standard',
                    'size' => 'normal',
                ]],
            ]],
        ],
        'materials' => ['Testmaterial'],
        'metalElements' => [],
        'braceletSizeCm' => 18,
        'pearlSizeMm' => 6,
        'careInstructions' => ['Nur kuenstliche Testdaten'],
        'priceMinor' => 2500,
        'currency' => 'eur',
        'salesEnabled' => true,
        'images' => [[
            'imageId' => '11111111-1111-4111-8111-111111111112',
            'fileName' => '01.jpg',
            'alt' => 'Kuenstliches Testbild',
            'width' => 100,
            'height' => 100,
            'isMain' => true,
        ]],
    ];
    $sourceProduct['sourceHash'] = str_repeat('a', 64);
    $selection = [
        'productId' => $sourceProduct['productId'],
        'expectedProductVersion' => 3,
        'expectedSourceHash' => $sourceProduct['sourceHash'],
        'sku' => $sourceProduct['sku'],
        'operationId' => 'ap7-collection-cutover-operation',
    ];
    $manifest['status'] = 'approved_for_cutover';
    $manifest['legalBundle']['status'] = 'approved';
    $manifest['selectedCollections'] = [$selection];
    $productSource = [
        'version' => 3,
        'products' => [$sourceProduct],
    ];
    $product = carmaja_cutover_selected_product($manifest, $productSource);
    ap7_assert($product['productId'] === $selection['productId'], 'Kollektionen-Auswahl stimmt nicht.');
    $productSource['products'][0]['priceMinor'] = 49;
    try {
        carmaja_cutover_selected_product($manifest, $productSource);
        throw new RuntimeException('Ungueltiger Mindestpreis wurde akzeptiert.');
    } catch (CarmajaProductionCutoverException $error) {
        ap7_assert(
            $error->getMessage() === 'selected_product_contract_mismatch',
            'Unerwarteter Cutover-Fehlercode.'
        );
    }
} finally {
    $remove = static function (string $path) use (&$remove): void {
        if (is_dir($path)) {
            foreach (scandir($path) ?: [] as $entry) {
                if ($entry !== '.' && $entry !== '..') {
                    $remove($path . DIRECTORY_SEPARATOR . $entry);
                }
            }
            rmdir($path);
        } elseif (is_file($path)) {
            unlink($path);
        }
    };
    $remove($root);
}

echo "AP7 production contract: {$tests}/{$tests}\n";
