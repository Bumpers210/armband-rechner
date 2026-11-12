<?php

declare(strict_types=1);
foreach (['product-api.php', 'product-api-v2.php', 'product-api-v3.php', 'cart-contract.php'] as $file) {
    require_once dirname(__DIR__, 2) . '/test-api-private/program/' . $file;
}
require_once dirname(__DIR__, 2) . '/scripts/product-model4-preparation.php';

$checks = 0;
function model4_check(bool $value, string $label): void
{
    global $checks;
    if (!$value) throw new RuntimeException($label);
    $checks++;
}
function model4_reject(callable $action, string $code): void
{
    try { $action(); } catch (RuntimeException $e) {
        model4_check($e->getMessage() === $code, 'wrong_error_' . $code); return;
    }
    throw new RuntimeException('missing_error_' . $code);
}
function model4_fixture(int $number, string $status = 'published'): array
{
    $draft = ['draftId' => sprintf('10000000-0000-4000-8000-%012d', $number),
        'productModelVersion' => 3, 'productVersion' => 6, 'version' => 10,
        'name' => 'Kuenstliches Armband', 'shortDescription' => 'Kuenstlicher Text',
        'descriptionDocument' => ['version' => 1, 'blocks' => [['type' => 'paragraph', 'spans' => [
            ['text' => 'Kuenstlicher Text', 'bold' => false, 'italic' => false, 'font' => 'standard', 'size' => 'normal'],
        ]]]], 'materials' => ['Naturstein'], 'metalElements' => [], 'braceletSizeCm' => 18.5,
        'pearlSizeMm' => 8, 'careInstructions' => ['Trocken lagern'], 'priceMinor' => 3250,
        'currency' => 'eur', 'salesEnabled' => $status === 'published', 'status' => $status,
        'sku' => sprintf('CP-2026-%04d', $number), 'slug' => 'kuenstlich-' . $number,
        'updatedAt' => '2026-11-10T00:00:00Z', 'createdAt' => '2026-09-01T00:00:00Z',
        'imageManifest' => [['imageId' => '20000000-0000-4000-8000-000000000001',
            'fileName' => '01.jpg', 'alt' => 'Kuenstlich', 'width' => 1600, 'height' => 900, 'isMain' => true]],
        'images' => [['imageId' => '20000000-0000-4000-8000-000000000001', 'path' => '/private/artificial/01.jpg']],
        'privateCalculation' => ['preserve' => 123], 'environment' => 'test'];
    $draft['sourceHash'] = carmaja_api_v3_product_from_draft($draft)['sourceHash'];
    return $draft;
}
function model4_row(array $draft): array
{
    $p = carmaja_api_v3_product_from_draft($draft);
    return ['product_id' => $p['productId'], 'product_version' => (string) $p['productVersion'],
        'source_hash' => $p['sourceHash'], 'name' => $p['name'], 'description' => $p['description'],
        'bracelet_size' => (string) $p['braceletSizeCm'], 'care_instructions' => implode("\n", $p['careInstructions']),
        'price_minor' => (string) $p['priceMinor'], 'currency' => $p['currency'], 'sales_enabled' => (string) (int) $p['salesEnabled'],
        'materials' => json_encode($p['materials']), 'metal_elements' => json_encode($p['metalElements']),
        'images' => json_encode(cmj_model4_projection($draft)['images']), 'sales_model' => 'collection',
        'notice_policy_id' => null, 'notices' => '[]'];
}

$at = '2026-11-11T20:00:00Z';
$drafts = [model4_fixture(2), model4_fixture(8), model4_fixture(9, 'disabled'), model4_fixture(10, 'draft')];
$legacy = ['draftId' => '10000000-0000-4000-8000-000000000099', 'stock' => 1, 'status' => 'draft'];
$rows = array_map('model4_row', array_slice($drafts, 0, 3));
$public = ['version' => 3, 'products' => [cmj_model4_projection($drafts[0])]];
$input = [$drafts, $rows, $public, $legacy];
$plan = cmj_model4_plan([...$drafts, $legacy], $rows, $public, $at);
model4_check(count($plan['entries']) === 4 && count($plan['legacyPreserved']) === 1, 'counts');
model4_check($plan['status'] === 'not_approved' && $plan['selectedProducts'] === [], 'no_cutover');
model4_check(!$plan['publicationAllowed'] && !$plan['inventoryImportAllowed'] && !$plan['applySupported'], 'no_writes');
model4_check($input === [$drafts, $rows, $public, $legacy], 'input_immutable');
model4_check($plan === cmj_model4_plan([...$drafts, $legacy], $rows, $public, $at), 'repeatable');
model4_check($plan === cmj_model4_plan([$legacy, ...array_reverse($drafts)], array_reverse($rows), $public, $at), 'sorted_sources');
model4_check(count(array_filter($plan['entries'], fn ($e) => $e['alreadyPublic'])) === 1, 'private_stays_private');
model4_check(count(array_filter($plan['entries'], fn ($e) => $e['commerceBeforeHash'] !== null)) === 3, 'no_new_commerce');
model4_check($plan['legacyPreserved'][0]['recordHash'] === cmj_model4_digest($legacy), 'legacy_preserved');
model4_check(!str_contains(cmj_model4_json($plan), 'privateCalculation') && !str_contains(cmj_model4_json($plan), '/private/'), 'report_no_private_contents');

foreach ($drafts as $draft) {
    $after = cmj_model4_candidate($draft, $at);
    model4_check($after['productModelVersion'] === 4 && $after['productVersion'] === 7 && $after['version'] === 11, 'versions');
    model4_check($after['noticePolicyId'] === null && carmaja_api_v3_product_from_draft($after)['notices'] === [], 'no_retroactive_notice');
    model4_check($after['sourceHash'] !== $draft['sourceHash'] && $after['sourceHash'] === carmaja_api_v3_product_from_draft($after)['sourceHash'], 'server_hash');
    model4_check($after === cmj_model4_candidate($after, $at), 'already_model4_noop');
    foreach (['priceMinor', 'shortDescription', 'descriptionDocument', 'images', 'imageManifest', 'salesEnabled', 'status',
        'privateCalculation', 'createdAt', 'sku', 'braceletSizeCm', 'pearlSizeMm'] as $field) {
        model4_check($after[$field] === $draft[$field], 'preserved_' . $field);
    }
}
$new = cmj_model4_candidate($drafts[0], $at); $new['noticePolicyId'] = 'natural-stones-v1';
$new['sourceHash'] = carmaja_api_v3_product_from_draft($new)['sourceHash'];
model4_check(cmj_model4_candidate($new, $at) === $new, 'existing_model4_notices_preserved');
model4_check(carmaja_api_v3_product_from_draft($new)['notices'] === CARMAJA_PRODUCT_NOTICES, 'new_product_notices');
foreach (['stock' => 2, 'vintedUrl' => '', 'braceletSize' => 18] as $field => $value) {
    model4_reject(fn () => cmj_model4_candidate([...$drafts[0], $field => $value], $at), 'legacy_field');
}
foreach ([0, '6', PHP_INT_MAX] as $version) {
    model4_reject(fn () => cmj_model4_candidate([...$drafts[0], 'productVersion' => $version], $at), 'source_version');
}
foreach (['priceMinor' => 0, 'currency' => 'usd', 'salesEnabled' => 'true'] as $field => $value) {
    model4_reject(fn () => cmj_model4_candidate([...$drafts[0], $field => $value], $at), 'source_contract');
}
model4_reject(fn () => cmj_model4_candidate([...$drafts[0], 'sourceHash' => str_repeat('a', 64)], $at), 'source_hash_conflict');
model4_reject(fn () => cmj_model4_candidate([...$drafts[0], 'noticePolicyId' => 'natural-stones-v1'], $at), 'retroactive_notice');
model4_reject(fn () => cmj_model4_candidate($drafts[0], '2026-02-30T00:00:00Z'), 'time_invalid');
$badText = [...$drafts[0], 'shortDescription' => 'Andere Beschreibung'];
$badText['sourceHash'] = carmaja_api_v3_product_from_draft($badText)['sourceHash'];
model4_reject(fn () => cmj_model4_candidate($badText, $at), 'source_text_conflict');
model4_reject(fn () => cmj_model4_plan([...$drafts, $drafts[0]], $rows, $public, $at), 'duplicate_product');
model4_reject(fn () => cmj_model4_plan([[...$drafts[0], 'productModelVersion' => 5]], [], $public, $at), 'unknown_source_model');
model4_reject(fn () => cmj_model4_plan([$drafts[0], [...$drafts[1], 'sku' => $drafts[0]['sku']]], [], $public, $at), 'duplicate_or_invalid_sku');
model4_reject(fn () => cmj_model4_plan($drafts, [...$rows, $rows[0]], $public, $at), 'commerce_identity');
model4_reject(fn () => cmj_model4_plan($drafts, [[...$rows[0], 'product_version' => '5']], $public, $at), 'commerce_conflict');
model4_reject(fn () => cmj_model4_plan($drafts, [[...$rows[0], 'price_minor' => '1']], $public, $at), 'commerce_conflict');
model4_reject(fn () => cmj_model4_plan($drafts, [[...$rows[0], 'sales_model' => 'stocked']], $public, $at), 'commerce_sales_model');
model4_reject(fn () => cmj_model4_plan($drafts, [[...$rows[0], 'images' => '[]']], $public, $at), 'commerce_content_conflict');
model4_reject(fn () => cmj_model4_plan($drafts, [[...$rows[0], 'notice_policy_id' => 'natural-stones-v1']], $public, $at), 'commerce_notice_conflict');
model4_reject(fn () => cmj_model4_plan($drafts, $rows, ['version' => 3, 'products' => [...$public['products'], ...$public['products']]], $at), 'public_identity');
model4_reject(fn () => cmj_model4_plan($drafts, $rows, ['version' => 3, 'products' => [[...$public['products'][0], 'priceMinor' => 1]]], $at), 'public_conflict');
$changed = $drafts; $changed[0]['privateCalculation']['preserve']++;
$changedPlan = cmj_model4_plan($changed, $rows, $public, $at);
model4_check($changedPlan['planHash'] !== $plan['planHash'], 'private_metadata_bound');
$changedPlan = cmj_model4_plan($drafts, $rows, $public, '2026-11-11T20:00:01Z');
model4_check($changedPlan['planHash'] !== $plan['planHash'], 'time_bound');

// Small file integration: caller reads synthetic input; preparation must not touch it.
$temporary = sys_get_temp_dir() . '/cmj-model4-' . bin2hex(random_bytes(8)) . '.json';
try {
    file_put_contents($temporary, json_encode($drafts, JSON_THROW_ON_ERROR));
    $bytes = file_get_contents($temporary);
    $fromFile = json_decode($bytes, true, 64, JSON_THROW_ON_ERROR);
    cmj_model4_plan($fromFile, $rows, $public, $at);
    model4_check(file_get_contents($temporary) === $bytes, 'file_readonly');
} finally { if (is_file($temporary)) unlink($temporary); }
model4_check(!file_exists($temporary), 'fixture_cleanup');
echo "Product model 4 preparation: $checks checks passed; no apply, database or provider access.\n";
