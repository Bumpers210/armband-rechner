<?php

declare(strict_types=1);

$program = dirname(__DIR__, 2) . '/test-api-private/program';
foreach (['product-api.php', 'product-api-v2.php', 'product-api-v3.php'] as $name) require_once $program . '/' . $name;
require_once dirname(__DIR__, 2) . '/scripts/prepare-product-projection-alignment.php';
$checks = 0;
function projection_check(bool $value): void { global $checks; if (!$value) throw new RuntimeException('assertion_failed'); $checks++; }
function projection_reject(callable $fn, string $code): void {
    try { $fn(); } catch (RuntimeException $e) { projection_check($e->getMessage() === $code); return; }
    throw new RuntimeException('expected_refusal');
}
function projection_fixture(int $n, string $status = 'published'): array {
    $draft = ['draftId' => sprintf('10000000-0000-4000-8000-%012d', $n), 'productModelVersion' => 3,
        'productVersion' => 2, 'sku' => sprintf('CP-2099-%04d', $n), 'slug' => 'kuenstlich-' . $n,
        'name' => 'Kuenstliches Armband', 'shortDescription' => 'Nur lokale Testdaten.',
        'descriptionDocument' => ['version' => 1, 'blocks' => [['type' => 'paragraph', 'spans' => [[
            'text' => 'Nur lokale Testdaten.', 'bold' => false, 'italic' => false, 'font' => 'standard', 'size' => 'normal']]]]],
        'materials' => ['Quarz'], 'metalElements' => [], 'careInstructions' => ['Trocken lagern'],
        'braceletSizeCm' => 18, 'pearlSizeMm' => 8, 'priceMinor' => 3250, 'currency' => 'eur',
        'status' => $status, 'salesEnabled' => $status === 'published', 'updatedAt' => '2026-11-11T00:00:00Z',
        'imageManifest' => [['imageId' => '20000000-0000-4000-8000-000000000001', 'fileName' => '01.jpg',
            'alt' => 'Kuenstlich', 'width' => 800, 'height' => 600, 'isMain' => true]],
        'internalCalculation' => ['mustNotEscape' => true], 'privateField' => 'NOT_PUBLIC'];
    $draft['sourceHash'] = carmaja_api_v3_product_from_draft($draft)['sourceHash'];
    return $draft;
}
function projection_public(array $draft): array {
    $p = carmaja_api_v2_public_product_from_draft($draft);
    $p['productModelVersion'] = 3; $p['sourceHash'] = $draft['sourceHash'];
    $p['descriptionDocument'] = $draft['descriptionDocument']; return $p;
}
$a = projection_fixture(1); $b = projection_fixture(2); $c = projection_fixture(3); $d = projection_fixture(4, 'disabled');
$reference = ['version' => 3, 'products' => [projection_public($a), projection_public($b)]];
$a['productVersion'] = 3; $a['updatedAt'] = '2026-11-11T01:00:00Z';
$a['sourceHash'] = carmaja_api_v3_product_from_draft($a)['sourceHash'];
$sources = [$a, $b, $c, $d]; $beforeSources = serialize($sources); $beforeReference = serialize($reference);
$plan = cmj_prepare_existing_projection($reference, $sources);
projection_check($plan === cmj_prepare_existing_projection($reference, $sources));
projection_check(serialize($sources) === $beforeSources && serialize($reference) === $beforeReference);
projection_check(count($plan['summary']['changes']) === 1 && $plan['summary']['unchanged'] === [$b['sku']]);
projection_check($plan['summary']['newPublicProductsHeldBack'] === [$c['sku']] && $plan['summary']['otherSourcesExcluded'] === 1);
projection_check(count($plan['candidate']['products']) === 2 && $plan['candidate']['products'][1] === $reference['products'][1]);
projection_check($plan['candidate']['products'][0]['productVersion'] === 3);
projection_check(!str_contains(json_encode($plan['candidate']), 'NOT_PUBLIC') && !str_contains(json_encode($plan['candidate']), 'internalCalculation'));
projection_check(!$plan['summary']['publicationAllowed'] && !$plan['summary']['websiteDeploymentAllowed'] && !$plan['summary']['unlockAllowed']);
projection_check($plan['summary']['changes'][0]['fields'] === ['productVersion','sourceHash','updatedAt']);
$replay = cmj_prepare_existing_projection($plan['candidate'], $sources);
projection_check($replay['summary']['changes'] === [] && $replay['candidate'] === $plan['candidate']);
$bad = $sources; $bad[0]['sourceHash'] = str_repeat('f', 64);
projection_reject(fn () => cmj_prepare_existing_projection($reference, $bad), 'stored_source_hash');
$bad = $sources; $bad[0]['priceMinor']++; $bad[0]['sourceHash'] = carmaja_api_v3_product_from_draft($bad[0])['sourceHash'];
projection_reject(fn () => cmj_prepare_existing_projection($reference, $bad), 'public_business_content_changed');
$bad = $reference; $bad['products'][0]['stock'] = 1;
projection_reject(fn () => cmj_prepare_existing_projection($bad, $sources), 'public_whitelist');
$bad = $reference; $bad['products'][0]['vintedUrl'] = 'forbidden';
projection_reject(fn () => cmj_prepare_existing_projection($bad, $sources), 'public_whitelist');
$bad = $reference; $bad['products'][0]['productVersion'] = 99;
projection_reject(fn () => cmj_prepare_existing_projection($bad, $sources), 'public_version_or_hash');
$bad = $sources; $bad[0]['status'] = 'disabled';
projection_reject(fn () => cmj_prepare_existing_projection($reference, $bad), 'source_identity_or_status');
projection_reject(fn () => cmj_prepare_existing_projection($reference, [$a,$a]), 'duplicate_source');
$bad = $reference; $bad['products'][] = $bad['products'][0];
projection_reject(fn () => cmj_prepare_existing_projection($bad, $sources), 'duplicate_public_identity');
$bad = $reference; $bad['environment'] = 'production';
projection_reject(fn () => cmj_prepare_existing_projection($bad, $sources), 'projection_contract');
projection_reject(fn () => cmj_prepare_existing_projection($reference, [$b,$c,$d]), 'source_identity_or_status');
echo "$checks artificial projection preparation checks passed\n";
