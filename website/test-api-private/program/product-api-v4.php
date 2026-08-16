<?php

declare(strict_types=1);

const CARMAJA_PRODUCT_API_V4 = 4;

function carmaja_api_v4_success_response(array $data): array
{
    $response = carmaja_api_success_response($data);
    $response['apiVersion'] = CARMAJA_PRODUCT_API_V4;
    $response['productModelVersion'] = CARMAJA_PRODUCT_MODEL_V3;
    return $response;
}

function carmaja_api_v4_validate_client_version_code(mixed $value): int
{
    $versionCode = is_int($value)
        ? $value
        : (is_string($value) && preg_match('/^[0-9]+$/', $value) === 1 ? (int) $value : null);
    $minimum = carmaja_api_publish_target() === 'test' ? 7 : 5;
    if ($versionCode === null || $versionCode < $minimum) {
        throw new CarmajaApiException(
            426,
            'Bitte aktualisiere die App, um Kollektionen zu verwalten.',
            ['minimumVersionCode' => (string) $minimum],
            'client_update_required'
        );
    }
    return $versionCode;
}

function carmaja_api_v4_reject_legacy_write(): never
{
    throw new CarmajaApiException(
        426,
        'Bitte aktualisiere die App. Kollektionen können nur mit der aktuellen App geändert werden.',
        ['minimumVersionCode' => carmaja_api_publish_target() === 'test' ? '7' : '5'],
        'client_update_required'
    );
}

function carmaja_api_v4_allowed_put_fields(): array
{
    return array_values(array_filter(
        carmaja_api_v3_allowed_put_fields(),
        static fn (string $field): bool => $field !== 'salesEnabled'
    ));
}

function carmaja_api_v4_product_response_from_draft(array $draft): array
{
    $response = carmaja_api_v3_product_response_from_draft($draft);
    $response['available'] = ($draft['status'] ?? null) === 'published';
    unset($response['salesEnabled']);
    return $response;
}

function carmaja_api_v4_put_product(
    string $productId,
    array $body,
    array $actor,
    mixed $idempotencyKey
): array {
    if (array_key_exists('salesEnabled', $body) || array_key_exists('stock', $body)) {
        throw new CarmajaApiException(
            422,
            'Verfügbarkeit wird bei Kollektionen ausschließlich vom Server verwaltet.',
            ['availability' => 'Veröffentlichen, löschen oder wiederherstellen verwenden.'],
            'client_managed_availability_forbidden'
        );
    }
    carmaja_api_reject_unknown_fields($body, carmaja_api_v4_allowed_put_fields());
    foreach (carmaja_api_v4_allowed_put_fields() as $field) {
        if (!array_key_exists($field, $body)) {
            throw new CarmajaApiException(
                422,
                'PUT /v4/products/{productId} erfordert eine vollständige Aktualisierung.',
                [$field => 'Pflichtfeld fehlt.'],
                'validation_failed'
            );
        }
    }

    $v3Body = $body;
    $v3Body['salesEnabled'] = false;
    $key = carmaja_api_v2_validate_idempotency_key($idempotencyKey);
    carmaja_api_v3_put_product(
        $productId,
        $v3Body,
        $actor,
        'v4-' . hash('sha256', $key),
        true
    );
    $saved = carmaja_api_load_draft($productId);
    if (!is_array($saved)) {
        throw new CarmajaApiException(500, 'Gespeicherte Kollektion fehlt.', [], 'draft_missing_after_save');
    }
    return carmaja_api_v4_product_response_from_draft($saved);
}

function carmaja_api_v4_list_products(): array
{
    $products = [];
    foreach (glob(carmaja_api_path('drafts/*.json')) ?: [] as $path) {
        $draft = carmaja_api_read_target_json($path, [], 'v4-Produkt');
        if (($draft['productModelVersion'] ?? null) === CARMAJA_PRODUCT_MODEL_V3) {
            $products[] = carmaja_api_v4_product_response_from_draft($draft);
        } elseif (($draft['productModelVersion'] ?? null) === CARMAJA_PRODUCT_MODEL_V2) {
            $legacy = carmaja_api_v2_product_from_draft($draft);
            $legacy['available'] = ($draft['status'] ?? null) === 'published';
            $legacy['status'] = (string) ($draft['status'] ?? 'draft');
            unset($legacy['salesEnabled'], $legacy['stock']);
            $products[] = $legacy;
        }
    }
    usort(
        $products,
        static fn (array $left, array $right): int => strcmp($left['productId'], $right['productId'])
    );
    return ['productModelVersion' => CARMAJA_PRODUCT_MODEL_V3, 'products' => $products];
}

function carmaja_api_v4_validate_lifecycle_body(array $body, string $action): array
{
    carmaja_api_reject_unknown_fields($body, [
        'expectedProductVersion', 'expectedSourceHash', 'operationId',
    ]);
    $version = $body['expectedProductVersion'] ?? null;
    $sourceHash = $body['expectedSourceHash'] ?? null;
    $operationId = $body['operationId'] ?? null;
    if (!is_int($version) || $version < 1
        || !is_string($sourceHash) || preg_match('/^[0-9a-f]{64}$/', $sourceHash) !== 1
        || !is_string($operationId)) {
        throw new CarmajaApiException(422, 'v4-' . $action . '-Vertrag ist ungültig.', [], 'validation_failed');
    }
    carmaja_api_validate_operation_id($operationId);
    return [$version, $sourceHash, $operationId];
}

function carmaja_api_v4_run_collection_projection(
    array $publicProduct,
    string $action,
    string $operationId,
    string $requestHash
): array {
    $adapter = $GLOBALS['CARMAJA_API_COLLECTION_PROJECTION_V4'] ?? null;
    if (!is_callable($adapter)) {
        throw new CarmajaApiException(
            503,
            'Die Kollektionen-Verwaltung ist noch nicht vollständig eingerichtet.',
            [],
            'collection_projection_unavailable'
        );
    }
    $projectionProduct = array_diff_key($publicProduct, [
        '_imageBlobs' => true,
        '_removePublic' => true,
    ]);
    return $adapter($projectionProduct, $action, $operationId, $requestHash);
}

function carmaja_api_v4_transition_product(
    string $productId,
    array $body,
    array $actor,
    string $action
): array {
    carmaja_api_validate_draft_id($productId);
    [$expectedVersion, $expectedHash, $operationId] = carmaja_api_v4_validate_lifecycle_body($body, $action);
    $requestHash = carmaja_api_request_hash([
        'action' => $action,
        'operationId' => $operationId,
        'productId' => $productId,
        'productVersion' => $expectedVersion,
        'sourceHash' => $expectedHash,
    ]);

    $prepared = carmaja_api_with_lock('draft-' . $productId, function () use (
        $productId,
        $expectedVersion,
        $expectedHash,
        $operationId,
        $requestHash,
        $action
    ): array {
        $draft = carmaja_api_load_draft($productId);
        if (!is_array($draft)
            || ($draft['productModelVersion'] ?? null) !== CARMAJA_PRODUCT_MODEL_V3) {
            throw new CarmajaApiException(
                409,
                'Produkt muss zuerst mit der aktuellen App gespeichert werden.',
                [],
                'product_model_migration_required'
            );
        }
        $operationField = 'lastV4' . ucfirst($action) . 'OperationId';
        $sameOperation = ($draft['lastV4LifecycleOperationId'] ?? null) === $operationId
            && ($draft['lastV4LifecycleAction'] ?? null) === $action;
        if (!$sameOperation) {
            $current = carmaja_api_v3_product_from_draft($draft);
            if ($current['productVersion'] !== $expectedVersion
                || !hash_equals($current['sourceHash'], $expectedHash)) {
                throw new CarmajaApiException(
                    409,
                    'Produktversion oder Quellhash wurde zwischenzeitlich geändert.',
                    [],
                    'product_version_conflict'
                );
            }
            if ($action === 'archive' && ($draft['status'] ?? null) !== 'published') {
                throw new CarmajaApiException(409, 'Nur veröffentlichte Kollektionen können gelöscht werden.', [], 'collection_not_published');
            }
            if ($action === 'restore' && ($draft['status'] ?? null) !== 'disabled') {
                throw new CarmajaApiException(409, 'Nur gelöschte Kollektionen können wiederhergestellt werden.', [], 'collection_not_archived');
            }
            if ($action === 'publish' && ($draft['status'] ?? null) === 'disabled') {
                throw new CarmajaApiException(409, 'Gelöschte Kollektion muss wiederhergestellt werden.', [], 'collection_restore_required');
            }
            if (!is_string($draft['sku'] ?? null) || $draft['sku'] === '') {
                $draft['sku'] = carmaja_api_allocate_sku($operationId);
            }
            if (!is_string($draft['slug'] ?? null) || $draft['slug'] === '') {
                $draft['slug'] = strtolower((string) $draft['sku'])
                    . '-' . carmaja_api_slugify((string) $draft['name']);
            }
            $draft['status'] = $action === 'archive' ? 'disabled' : 'published';
            $draft['salesEnabled'] = $action !== 'archive';
            $draft['publishedAt'] = $draft['publishedAt'] ?? carmaja_api_now();
            if ($action === 'archive') {
                $draft['archivedAt'] = carmaja_api_now();
            } elseif ($action === 'restore') {
                unset($draft['archivedAt']);
            }
            $draft['updatedAt'] = carmaja_api_now();
            $draft['version'] = (int) ($draft['version'] ?? 0) + 1;
            $draft[$operationField] = $operationId;
            $draft['lastV4LifecycleOperationId'] = $operationId;
            $draft['lastV4LifecycleAction'] = $action;
        }

        $public = carmaja_api_v3_public_product_with_blobs($draft);
        if ($action === 'archive') {
            $public['_removePublic'] = true;
            carmaja_api_v4_run_collection_projection($public, $action, $operationId, $requestHash);
            if (!$sameOperation) {
                $draft = carmaja_api_save_draft($draft);
            }
        } else {
            if (!$sameOperation) {
                $draft = carmaja_api_save_draft($draft);
            }
            carmaja_api_v4_run_collection_projection($public, $action, $operationId, $requestHash);
        }
        return ['draft' => $draft, 'public' => $public];
    });

    $publishResult = carmaja_api_run_publish_adapter_v3($prepared['public'], [
        'operationId' => $operationId,
        'requestHash' => $requestHash,
    ]);
    carmaja_api_audit_best_effort('product_v4_' . $action, [
        'productId' => $productId,
        'productVersion' => $expectedVersion,
        'operationId' => $operationId,
        'deviceId' => $actor['tokenId'] ?? null,
        'result' => 'success',
    ]);
    $responseProduct = carmaja_api_v4_product_response_from_draft($prepared['draft']);
    return [
        'publication' => [
            'productId' => $productId,
            'productVersion' => $responseProduct['productVersion'],
            'sourceHash' => $responseProduct['sourceHash'],
            'operationId' => $operationId,
            'commitSha' => $publishResult['commitSha'] ?? null,
            'deploymentStatus' => $publishResult['deploymentStatus'] ?? 'not_started',
            'action' => $action,
        ],
        'product' => $responseProduct,
    ];
}

function carmaja_api_v4_publish_product(string $productId, array $body, array $actor): array
{
    return carmaja_api_v4_transition_product($productId, $body, $actor, 'publish');
}

function carmaja_api_v4_archive_product(string $productId, array $body, array $actor): array
{
    return carmaja_api_v4_transition_product($productId, $body, $actor, 'archive');
}

function carmaja_api_v4_restore_product(string $productId, array $body, array $actor): array
{
    return carmaja_api_v4_transition_product($productId, $body, $actor, 'restore');
}
