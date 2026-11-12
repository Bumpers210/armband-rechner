<?php

declare(strict_types=1);

// Pure preparation library. No filesystem, PDO, router, publisher or apply path.
// The caller loads the current product API's canonicalization functions.
function cmj_model4_require(bool $condition, string $code): void
{
    if (!$condition) throw new RuntimeException($code);
}

function cmj_model4_json(mixed $value): string
{
    return json_encode(carmaja_api_canonicalize($value), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
}

function cmj_model4_digest(mixed $value): string
{
    return hash('sha256', cmj_model4_json($value));
}

function cmj_model4_candidate(array $draft, string $at): array
{
    $date = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s\Z', $at, new DateTimeZone('UTC'));
    cmj_model4_require($date !== false && $date->format('Y-m-d\TH:i:s\Z') === $at, 'time_invalid');
    cmj_model4_require(in_array($draft['productModelVersion'] ?? null, [3, 4], true), 'source_model');
    foreach (['version', 'productVersion'] as $key) {
        cmj_model4_require(is_int($draft[$key] ?? null) && $draft[$key] > 0 && $draft[$key] < PHP_INT_MAX, 'source_version');
    }
    cmj_model4_require(is_int($draft['priceMinor'] ?? null) && $draft['priceMinor'] > 0
        && ($draft['currency'] ?? null) === 'eur' && is_bool($draft['salesEnabled'] ?? null)
        && in_array($draft['status'] ?? null, CARMAJA_API_STATUSES, true), 'source_contract');
    foreach (['stock', 'vintedUrl', 'braceletSize'] as $key) {
        cmj_model4_require(!array_key_exists($key, $draft), 'legacy_field');
    }
    $product = carmaja_api_v3_product_from_draft($draft);
    cmj_model4_require(is_string($draft['sourceHash'] ?? null)
        && hash_equals($product['sourceHash'], $draft['sourceHash']), 'source_hash_conflict');
    $payload = array_intersect_key($product, array_flip(carmaja_api_v3_allowed_put_fields()));
    $payload['expectedProductVersion'] = $draft['productVersion'];
    $validated = carmaja_api_v3_validate_put_payload($payload);
    cmj_model4_require($validated['description'] === $product['description'], 'source_text_conflict');
    cmj_model4_require($product['images'] !== [], 'source_images');
    if ($draft['productModelVersion'] === 4) {
        cmj_model4_require(in_array($draft['noticePolicyId'] ?? null, [null, 'natural-stones-v1'], true), 'notice_policy');
        if (array_key_exists('notices', $draft)) {
            cmj_model4_require($draft['notices'] === $product['notices'], 'notice_conflict');
        }
        return $draft;
    }
    cmj_model4_require(($draft['noticePolicyId'] ?? null) === null && ($draft['notices'] ?? []) === [], 'retroactive_notice');
    $candidate = $draft;
    $candidate['productModelVersion'] = 4;
    $candidate['productVersion']++;
    $candidate['version']++;
    $candidate['updatedAt'] = $at;
    $candidate['noticePolicyId'] = null;
    $candidate['sourceHash'] = carmaja_api_v3_product_from_draft($candidate)['sourceHash'];
    // Preserve every unknown/private field, image path, SKU, status and sales flag.
    $before = $draft; $after = $candidate;
    foreach (['productModelVersion', 'productVersion', 'version', 'updatedAt', 'noticePolicyId', 'sourceHash'] as $key) {
        unset($before[$key], $after[$key]);
    }
    cmj_model4_require($before === $after, 'unexpected_field_change');
    return $candidate;
}

/** Same public metadata as the V3/V5 publisher, without image reads or publishing. */
function cmj_model4_projection(array $draft): array
{
    $product = carmaja_api_v3_product_from_draft($draft);
    $public = carmaja_api_v2_public_product_from_draft($draft);
    foreach (['productModelVersion', 'sourceHash', 'descriptionDocument'] as $key) $public[$key] = $product[$key];
    if ($product['productModelVersion'] === 4) {
        $public['noticePolicyId'] = $product['noticePolicyId'];
        $public['notices'] = $product['notices'];
    }
    return $public;
}

function cmj_model4_check_commerce(array $draft, array $row): void
{
    $product = carmaja_api_v3_product_from_draft($draft);
    $expected = ['product_id' => $product['productId'], 'product_version' => $product['productVersion'],
        'source_hash' => $product['sourceHash'], 'name' => $product['name'], 'description' => $product['description'],
        'bracelet_size' => $product['braceletSizeCm'], 'care_instructions' => implode("\n", $product['careInstructions']),
        'price_minor' => $product['priceMinor'], 'currency' => $product['currency'], 'sales_enabled' => (int) $product['salesEnabled']];
    foreach ($expected as $key => $value) {
        cmj_model4_require(isset($row[$key]) && (string) $row[$key] === (string) $value, 'commerce_conflict');
    }
    cmj_model4_require(($row['sales_model'] ?? null) === 'collection', 'commerce_sales_model');
    foreach (['materials' => 'materials', 'metal_elements' => 'metalElements', 'images' => 'images'] as $column => $key) {
        $actual = $row[$column] ?? null;
        if (is_string($actual)) $actual = json_decode($actual, true, 64, JSON_THROW_ON_ERROR);
        $matches = cmj_model4_json($actual) === cmj_model4_json($product[$key]);
        if ($column === 'images') $matches = $matches || cmj_model4_json($actual) === cmj_model4_json(cmj_model4_projection($draft)['images']);
        cmj_model4_require($matches, 'commerce_content_conflict');
    }
    cmj_model4_require(($row['notice_policy_id'] ?? null) === null, 'commerce_notice_conflict');
    $notices = $row['notices'] ?? [];
    if (is_string($notices)) $notices = json_decode($notices, true, 64, JSON_THROW_ON_ERROR);
    cmj_model4_require($notices === [], 'commerce_notice_conflict');
}

/** A deterministic NOT-APPROVED report, not a cutover manifest or executable migration. */
function cmj_model4_plan(array $drafts, array $commerceRows, array $public, string $at): array
{
    cmj_model4_require(($public['version'] ?? null) === 3 && is_array($public['products'] ?? null)
        && array_is_list($public['products']), 'public_contract');
    $byId = []; $bySku = []; $candidates = []; $legacy = [];
    foreach ($drafts as $draft) {
        $id = $draft['draftId'] ?? '';
        carmaja_api_validate_draft_id($id);
        cmj_model4_require(!isset($byId[$id]), 'duplicate_product');
        $byId[$id] = $draft;
        $sku = $draft['sku'] ?? null;
        if ($sku !== null && $sku !== '') {
            cmj_model4_require(is_string($sku) && preg_match('/^CP-[0-9]{4}-[0-9]{4}$/', $sku) === 1
                && !isset($bySku[$sku]), 'duplicate_or_invalid_sku');
            $bySku[$sku] = true;
        }
        if (!in_array($draft['productModelVersion'] ?? null, [3, 4], true)) {
            cmj_model4_require(in_array($draft['productModelVersion'] ?? null, [null, 1, 2], true), 'unknown_source_model');
            $legacy[] = ['productId' => $id, 'recordHash' => cmj_model4_digest($draft)];
            continue;
        }
        $candidates[$id] = cmj_model4_candidate($draft, $at);
    }
    $bindings = [];
    foreach ($commerceRows as $row) {
        $id = $row['product_id'] ?? '';
        cmj_model4_require(isset($candidates[$id]) && !isset($bindings[$id]), 'commerce_identity');
        cmj_model4_check_commerce($byId[$id], $row);
        $bindings[$id] = cmj_model4_digest($row);
    }
    $publicIds = [];
    foreach ($public['products'] as $product) {
        $id = $product['productId'] ?? '';
        cmj_model4_require(isset($candidates[$id]) && !isset($publicIds[$id]), 'public_identity');
        cmj_model4_require(($byId[$id]['status'] ?? null) === 'published'
            && cmj_model4_json(cmj_model4_projection($byId[$id])) === cmj_model4_json($product), 'public_conflict');
        $publicIds[$id] = true;
    }
    $entries = [];
    foreach ($candidates as $id => $candidate) {
        $before = $byId[$id];
        $entries[] = ['productId' => $id, 'sku' => $before['sku'] ?? null,
            'action' => $before['productModelVersion'] === 3 ? 'prepare_model4' : 'already_model4',
            'beforeVersion' => $before['productVersion'], 'afterVersion' => $candidate['productVersion'],
            'beforeSourceHash' => $before['sourceHash'], 'afterSourceHash' => $candidate['sourceHash'],
            'beforeRecordHash' => cmj_model4_digest($before), 'afterRecordHash' => cmj_model4_digest($candidate),
            'commerceBeforeHash' => $bindings[$id] ?? null, 'alreadyPublic' => isset($publicIds[$id])];
    }
    usort($entries, static fn ($a, $b) => strcmp($a['productId'], $b['productId']));
    usort($legacy, static fn ($a, $b) => strcmp($a['productId'], $b['productId']));
    $plan = ['format' => 'product-model4-preparation-v1', 'status' => 'not_approved', 'preparedAt' => $at,
        'sourceSetHash' => cmj_model4_digest(carmaja_api_canonicalize($byId)), 'publicBeforeHash' => cmj_model4_digest($public),
        'entries' => $entries, 'legacyPreserved' => $legacy, 'selectedProducts' => [],
        'publicationAllowed' => false, 'inventoryImportAllowed' => false, 'applySupported' => false];
    $plan['planHash'] = cmj_model4_digest($plan);
    return $plan;
}
