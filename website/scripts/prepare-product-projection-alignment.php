<?php

declare(strict_types=1);

// Pure preparation only: no file, database, network, publisher or activation writes.
function cmj_projection_require(bool $condition, string $code): void
{
    if (!$condition) throw new RuntimeException($code);
}

function cmj_projection_json(mixed $value): string
{
    return json_encode(carmaja_api_canonicalize($value), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
}

function cmj_prepare_existing_projection(array $reference, array $drafts): array
{
    $keys = array_keys($reference); sort($keys);
    cmj_projection_require($keys === ['products', 'version'] && $reference['version'] === 3
        && is_array($reference['products']) && array_is_list($reference['products']), 'projection_contract');
    $sources = []; $sourceSkus = [];
    foreach ($drafts as $draft) {
        cmj_projection_require(is_array($draft), 'source_contract');
        $id = $draft['draftId'] ?? '';
        carmaja_api_validate_draft_id($id);
        cmj_projection_require(!isset($sources[$id]), 'duplicate_source');
        $sources[$id] = $draft;
        $sku = $draft['sku'] ?? '';
        if ($sku !== '') {
            cmj_projection_require(is_string($sku) && !isset($sourceSkus[$sku]), 'duplicate_source_sku');
            $sourceSkus[$sku] = true;
        }
    }
    $candidate = $reference; $seen = []; $skus = []; $slugs = []; $changes = []; $unchanged = [];
    foreach ($reference['products'] as $index => $before) {
        cmj_projection_require(is_array($before), 'public_product_contract');
        $id = $before['productId'] ?? ''; $sku = $before['sku'] ?? ''; $slug = $before['slug'] ?? '';
        cmj_projection_require(is_string($id) && is_string($sku) && is_string($slug)
            && !isset($seen[$id]) && !isset($skus[$sku]) && !isset($slugs[$slug]), 'duplicate_public_identity');
        $seen[$id] = true; $skus[$sku] = true; $slugs[$slug] = true;
        $draft = $sources[$id] ?? null;
        cmj_projection_require(is_array($draft) && ($draft['productModelVersion'] ?? null) === 3
            && ($draft['status'] ?? '') === 'published' && ($draft['salesEnabled'] ?? null) === true
            && ($draft['sku'] ?? '') === $sku && ($draft['slug'] ?? '') === $slug, 'source_identity_or_status');
        $product = carmaja_api_v3_product_from_draft($draft);
        cmj_projection_require(($draft['sourceHash'] ?? '') === $product['sourceHash'], 'stored_source_hash');
        $description = carmaja_api_v3_validate_description_document($draft['descriptionDocument']);
        cmj_projection_require($description['plainText'] === $product['description'], 'source_text_conflict');
        $after = carmaja_api_v2_public_product_from_draft($draft);
        $after['productModelVersion'] = 3;
        $after['sourceHash'] = $product['sourceHash'];
        $after['descriptionDocument'] = $product['descriptionDocument'];
        $beforeKeys = array_keys($before); $afterKeys = array_keys($after);
        sort($beforeKeys); sort($afterKeys);
        cmj_projection_require($beforeKeys === $afterKeys, 'public_whitelist');
        cmj_projection_require(is_int($before['productVersion']) && $before['productVersion'] > 0
            && is_int($after['productVersion']) && $after['productVersion'] >= $before['productVersion']
            && is_string($before['sourceHash']) && preg_match('/^[a-f0-9]{64}$/', $before['sourceHash']) === 1,
            'public_version_or_hash');
        $fields = [];
        foreach ($after as $key => $value) {
            if (cmj_projection_json($before[$key]) !== cmj_projection_json($value)) $fields[] = $key;
        }
        cmj_projection_require(array_diff($fields, ['productVersion', 'sourceHash', 'updatedAt']) === [], 'public_business_content_changed');
        if ($fields === []) { $unchanged[] = $sku; continue; }
        cmj_projection_require($after['productVersion'] > $before['productVersion'], 'public_version_not_advanced');
        foreach ($fields as $key) $candidate['products'][$index][$key] = $after[$key];
        sort($fields);
        $changes[] = ['sku' => $sku, 'fromVersion' => $before['productVersion'],
            'toVersion' => $after['productVersion'], 'fields' => $fields];
    }
    $heldBack = []; $excluded = 0;
    foreach ($sources as $id => $draft) {
        if (isset($seen[$id])) continue;
        if (($draft['status'] ?? '') === 'published' && ($draft['sku'] ?? '') !== '') $heldBack[] = $draft['sku'];
        else $excluded++;
    }
    sort($heldBack); sort($unchanged);
    return ['candidate' => $candidate,
        'referenceDigest' => hash('sha256', cmj_projection_json($reference)),
        'candidateDigest' => hash('sha256', cmj_projection_json($candidate)),
        'summary' => ['status' => 'prepared_not_approved', 'publicProductCount' => count($candidate['products']),
            'changes' => $changes, 'unchanged' => $unchanged, 'newPublicProductsHeldBack' => $heldBack,
            'otherSourcesExcluded' => $excluded, 'publicationAllowed' => false,
            'websiteDeploymentAllowed' => false, 'unlockAllowed' => false]];
}
