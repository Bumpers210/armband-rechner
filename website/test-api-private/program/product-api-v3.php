<?php

declare(strict_types=1);

const CARMAJA_PRODUCT_MODEL_V3 = 3;
const CARMAJA_DESCRIPTION_DOCUMENT_VERSION = 1;
const CARMAJA_DESCRIPTION_MAX_PARAGRAPHS = 25;
const CARMAJA_DESCRIPTION_MAX_SPANS = 100;
const CARMAJA_DESCRIPTION_MAX_BYTES = 16384;

function carmaja_api_v3_success_response(array $data): array
{
    $response = carmaja_api_success_response($data);
    $response['apiVersion'] = 3;
    $response['productModelVersion'] = CARMAJA_PRODUCT_MODEL_V3;
    return $response;
}

function carmaja_api_v3_validate_client_version_code(mixed $value): int
{
    $versionCode = is_int($value)
        ? $value
        : (is_string($value) && preg_match('/^[0-9]+$/', $value) === 1 ? (int) $value : null);
    $minimum = carmaja_api_publish_target() === 'test' ? 6 : 5;
    if ($versionCode === null || $versionCode < $minimum) {
        throw new CarmajaApiException(
            426,
            'Die verwendete App-Version muss aktualisiert werden.',
            [
                'versionCode' => 'Neuere App für formatierte Beschreibungen erforderlich.',
                'minimumVersionCode' => (string) $minimum,
            ],
            'client_update_required'
        );
    }
    return $versionCode;
}

function carmaja_api_v3_reject_legacy_write(): never
{
    throw new CarmajaApiException(
        426,
        'Schreiben erfordert die aktuelle App mit Produktmodell 3.',
        ['minimumVersionCode' => carmaja_api_publish_target() === 'test' ? '6' : '5'],
        'client_update_required'
    );
}

function carmaja_api_v3_allowed_put_fields(): array
{
    return [
        'expectedProductVersion',
        'name',
        'descriptionDocument',
        'materials',
        'metalElements',
        'braceletSizeCm',
        'pearlSizeMm',
        'careInstructions',
        'images',
        'priceMinor',
        'currency',
        'salesEnabled',
    ];
}

function carmaja_api_v3_validate_description_document(mixed $value): array
{
    if (!is_array($value) || array_is_list($value)) {
        throw new CarmajaApiException(
            422,
            'Formatierte Beschreibung ist ungültig.',
            ['descriptionDocument' => 'Objekt erwartet.'],
            'validation_failed'
        );
    }
    carmaja_api_reject_unknown_fields($value, ['version', 'blocks']);
    $encoded = json_encode(
        $value,
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
    );
    $blocks = $value['blocks'] ?? null;
    if (($value['version'] ?? null) !== CARMAJA_DESCRIPTION_DOCUMENT_VERSION
        || !is_array($blocks)
        || !array_is_list($blocks)
        || count($blocks) < 1
        || count($blocks) > CARMAJA_DESCRIPTION_MAX_PARAGRAPHS
        || strlen($encoded) > CARMAJA_DESCRIPTION_MAX_BYTES) {
        throw new CarmajaApiException(
            422,
            'Formatierte Beschreibung ist ungültig.',
            ['descriptionDocument' => 'Version 1 mit höchstens 25 Absätzen und 16 KB erwartet.'],
            'validation_failed'
        );
    }

    $normalizedBlocks = [];
    $spanCount = 0;
    foreach ($blocks as $blockIndex => $block) {
        if (!is_array($block) || array_is_list($block)) {
            throw new CarmajaApiException(
                422,
                'Formatierte Beschreibung ist ungültig.',
                ['descriptionDocument.blocks.' . $blockIndex => 'Absatzobjekt erwartet.'],
                'validation_failed'
            );
        }
        carmaja_api_reject_unknown_fields($block, ['type', 'spans']);
        $spans = $block['spans'] ?? null;
        if (($block['type'] ?? null) !== 'paragraph'
            || !is_array($spans)
            || !array_is_list($spans)
            || $spans === []) {
            throw new CarmajaApiException(
                422,
                'Formatierte Beschreibung ist ungültig.',
                ['descriptionDocument.blocks.' . $blockIndex => 'Nichtleerer Absatz erwartet.'],
                'validation_failed'
            );
        }

        $normalizedSpans = [];
        foreach ($spans as $spanIndex => $span) {
            $spanCount++;
            if ($spanCount > CARMAJA_DESCRIPTION_MAX_SPANS
                || !is_array($span)
                || array_is_list($span)) {
                throw new CarmajaApiException(
                    422,
                    'Formatierte Beschreibung ist ungültig.',
                    ['descriptionDocument' => 'Höchstens 100 Textbereiche erlaubt.'],
                    'validation_failed'
                );
            }
            carmaja_api_reject_unknown_fields($span, ['text', 'bold', 'italic', 'font', 'size']);
            $text = $span['text'] ?? null;
            if (!is_string($text)
                || $text === ''
                || str_contains($text, "\0")
                || !is_bool($span['bold'] ?? null)
                || !is_bool($span['italic'] ?? null)
                || !in_array($span['font'] ?? null, ['standard', 'elegant'], true)
                || !in_array($span['size'] ?? null, ['small', 'normal', 'large'], true)) {
                throw new CarmajaApiException(
                    422,
                    'Formatierte Beschreibung ist ungültig.',
                    ['descriptionDocument.blocks.' . $blockIndex . '.spans.' . $spanIndex =>
                        'Text und freigegebene Formatwerte erwartet.'],
                    'validation_failed'
                );
            }
            $normalized = [
                'text' => $text,
                'bold' => $span['bold'],
                'italic' => $span['italic'],
                'font' => $span['font'],
                'size' => $span['size'],
            ];
            $lastIndex = count($normalizedSpans) - 1;
            if ($lastIndex >= 0) {
                $previous = $normalizedSpans[$lastIndex];
                $sameStyle = $previous['bold'] === $normalized['bold']
                    && $previous['italic'] === $normalized['italic']
                    && $previous['font'] === $normalized['font']
                    && $previous['size'] === $normalized['size'];
                if ($sameStyle) {
                    $normalizedSpans[$lastIndex]['text'] .= $text;
                    continue;
                }
            }
            $normalizedSpans[] = $normalized;
        }
        $normalizedBlocks[] = ['type' => 'paragraph', 'spans' => $normalizedSpans];
    }

    $plainText = implode("\n\n", array_map(
        static fn (array $block): string => implode('', array_column($block['spans'], 'text')),
        $normalizedBlocks
    ));
    if ($plainText !== trim($plainText)) {
        throw new CarmajaApiException(
            422,
            'Formatierte Beschreibung ist ungültig.',
            ['descriptionDocument' => 'Beschreibung darf nicht mit Leerraum beginnen oder enden.'],
            'validation_failed'
        );
    }
    $plainText = carmaja_api_validate_string($plainText, 'description', 500, true);
    return [
        'document' => [
            'version' => CARMAJA_DESCRIPTION_DOCUMENT_VERSION,
            'blocks' => $normalizedBlocks,
        ],
        'plainText' => $plainText,
    ];
}

function carmaja_api_v3_validate_put_payload(array $payload): array
{
    carmaja_api_v2_reject_client_managed_fields($payload);
    carmaja_api_reject_unknown_fields($payload, carmaja_api_v3_allowed_put_fields());
    foreach (carmaja_api_v3_allowed_put_fields() as $field) {
        if (!array_key_exists($field, $payload)) {
            throw new CarmajaApiException(
                422,
                'PUT /v3/products/{productId} erfordert eine vollständige Aktualisierung.',
                [$field => 'Pflichtfeld fehlt.'],
                'validation_failed'
            );
        }
    }
    $description = carmaja_api_v3_validate_description_document($payload['descriptionDocument']);
    $v2Payload = $payload;
    unset($v2Payload['descriptionDocument']);
    $v2Payload['description'] = $description['plainText'];
    $normalized = carmaja_api_v2_validate_put_payload($v2Payload);
    $normalized['descriptionDocument'] = $description['document'];
    return $normalized;
}

function carmaja_api_v3_canonical_product(array $product): array
{
    $canonical = carmaja_api_v2_canonical_product($product);
    $canonical['productModelVersion'] = CARMAJA_PRODUCT_MODEL_V3;
    $canonical['descriptionDocument'] = $product['descriptionDocument'] ?? null;
    return $canonical;
}

function carmaja_api_v3_source_hash(array $product): string
{
    return hash(
        'sha256',
        json_encode(
            carmaja_api_canonicalize(carmaja_api_v3_canonical_product($product)),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
        )
    );
}

function carmaja_api_v3_product_from_draft(array $draft): array
{
    if (($draft['productModelVersion'] ?? null) !== CARMAJA_PRODUCT_MODEL_V3
        || !is_array($draft['descriptionDocument'] ?? null)) {
        throw new CarmajaApiException(
            409,
            'Produkt ist nicht als v3-Produkt verfügbar.',
            [],
            'product_model_migration_required'
        );
    }
    $product = carmaja_api_v2_product_from_draft($draft);
    $product['productModelVersion'] = CARMAJA_PRODUCT_MODEL_V3;
    $product['descriptionDocument'] = $draft['descriptionDocument'];
    $product['sourceHash'] = carmaja_api_v3_source_hash($product);
    return $product;
}

function carmaja_api_v3_product_response_from_draft(array $draft): array
{
    $product = carmaja_api_v3_product_from_draft($draft);
    $product['draftId'] = $product['productId'];
    $product['version'] = (int) ($draft['version'] ?? 0);
    $product['status'] = (string) ($draft['status'] ?? 'draft');
    $product['updatedAt'] = isset($draft['updatedAt']) ? (string) $draft['updatedAt'] : null;
    $product['images'] = carmaja_api_v2_uploaded_images_from_draft($draft);
    return $product;
}

function carmaja_api_v3_idempotency_path(string $key): string
{
    return carmaja_api_path('idempotency/v3-' . hash('sha256', $key) . '.json');
}

function carmaja_api_v3_put_product(
    string $productId,
    array $body,
    array $actor,
    mixed $idempotencyKey
): array {
    carmaja_api_validate_draft_id($productId);
    $idempotencyKey = carmaja_api_v2_validate_idempotency_key($idempotencyKey);
    $requestHash = carmaja_api_request_hash(['productId' => $productId, 'body' => $body]);
    return carmaja_api_with_lock('draft-' . $productId, function () use (
        $productId,
        $body,
        $actor,
        $idempotencyKey,
        $requestHash
    ): array {
        $idempotencyPath = carmaja_api_v3_idempotency_path($idempotencyKey);
        $stored = is_file($idempotencyPath)
            ? carmaja_api_read_target_json($idempotencyPath, [], 'v3-Idempotenz')
            : [];
        if ($stored !== []) {
            if (($stored['requestHash'] ?? null) !== $requestHash) {
                throw new CarmajaApiException(
                    409,
                    'Idempotency-Key wurde mit anderem Inhalt wiederverwendet.',
                    [],
                    'idempotency_key_reused'
                );
            }
            if (($stored['status'] ?? null) === 'succeeded'
                && is_array($stored['savedResponse'] ?? null)) {
                return $stored['savedResponse'];
            }
        }

        $normalized = carmaja_api_v3_validate_put_payload($body);
        $existing = carmaja_api_load_draft($productId);
        $currentProductVersion = is_array($existing)
            ? (int) ($existing['productVersion'] ?? 0)
            : 0;
        if ($currentProductVersion !== $normalized['expectedProductVersion']) {
            throw new CarmajaApiException(
                409,
                'Das Produkt wurde bereits mit einer neueren Version gespeichert.',
                ['currentProductVersion' => $currentProductVersion],
                'product_version_conflict'
            );
        }

        $nextProductVersion = $currentProductVersion + 1;
        $currentDraftVersion = is_array($existing) ? (int) ($existing['version'] ?? 0) : 0;
        $draft = $existing ?? [
            'environment' => carmaja_api_publish_target(),
            'draftId' => $productId,
            'sku' => null,
            'slug' => null,
            'status' => 'draft',
            'version' => 0,
            'createdAt' => carmaja_api_now(),
            'images' => [],
        ];
        $uploadedById = [];
        foreach (array_values($draft['images'] ?? []) as $uploadedImage) {
            if (is_array($uploadedImage) && is_string($uploadedImage['imageId'] ?? null)) {
                $uploadedById[$uploadedImage['imageId']] = $uploadedImage;
            }
        }
        $retainedUploaded = [];
        foreach ($normalized['images'] as $manifestImage) {
            $uploadedImage = $uploadedById[$manifestImage['imageId']] ?? null;
            if (is_array($uploadedImage)
                && ($uploadedImage['fileName'] ?? null) === $manifestImage['fileName']
                && is_string($uploadedImage['path'] ?? null)
                && is_file($uploadedImage['path'])) {
                $uploadedImage['alt'] = $manifestImage['alt'];
                $uploadedImage['isMain'] = $manifestImage['isMain'];
                $retainedUploaded[] = $uploadedImage;
            }
        }

        $draft['productModelVersion'] = CARMAJA_PRODUCT_MODEL_V3;
        $draft['productVersion'] = $nextProductVersion;
        $draft['name'] = $normalized['name'];
        $draft['shortDescription'] = $normalized['description'];
        $draft['descriptionDocument'] = $normalized['descriptionDocument'];
        $draft['materials'] = $normalized['materials'];
        $draft['metalElements'] = $normalized['metalElements'];
        $draft['braceletSizeCm'] = $normalized['braceletSizeCm'];
        $draft['pearlSizeMm'] = $normalized['pearlSizeMm'];
        $draft['careInstructions'] = $normalized['careInstructions'];
        $draft['imageManifest'] = $normalized['images'];
        $draft['images'] = $retainedUploaded;
        $draft['priceMinor'] = $normalized['priceMinor'];
        $draft['currency'] = $normalized['currency'];
        $draft['salesEnabled'] = $normalized['salesEnabled'];
        $draft['version'] = $currentDraftVersion + 1;
        $draft['updatedAt'] = carmaja_api_now();
        unset($draft['braceletSize'], $draft['vintedUrl']);

        $product = carmaja_api_v3_product_from_draft($draft);
        $draft['sourceHash'] = $product['sourceHash'];
        $savedDraft = carmaja_api_save_draft($draft);
        $response = carmaja_api_v3_product_response_from_draft($savedDraft);
        carmaja_api_write_json_atomic(
            $idempotencyPath,
            carmaja_api_target_document([
                'requestHash' => $requestHash,
                'status' => 'succeeded',
                'productId' => $productId,
                'productVersion' => $nextProductVersion,
                'savedResponse' => $response,
                'updatedAt' => carmaja_api_now(),
            ])
        );
        carmaja_api_audit_best_effort('product_v3_saved', [
            'productId' => $productId,
            'productVersion' => $nextProductVersion,
            'deviceId' => $actor['tokenId'] ?? null,
            'result' => 'success',
        ]);
        return $response;
    });
}

function carmaja_api_v3_list_products(): array
{
    $products = [];
    foreach (glob(carmaja_api_path('drafts/*.json')) ?: [] as $path) {
        $draft = carmaja_api_read_target_json($path, [], 'v3-Produkt');
        $model = $draft['productModelVersion'] ?? null;
        if ($model === CARMAJA_PRODUCT_MODEL_V3) {
            $products[] = carmaja_api_v3_product_from_draft($draft);
        } elseif ($model === CARMAJA_PRODUCT_MODEL_V2) {
            $products[] = carmaja_api_v2_product_from_draft($draft);
        }
    }
    usort(
        $products,
        static fn (array $left, array $right): int =>
            strcmp($left['productId'], $right['productId'])
    );
    return ['productModelVersion' => CARMAJA_PRODUCT_MODEL_V3, 'products' => $products];
}

function carmaja_api_v3_public_product_with_blobs(array $draft): array
{
    $product = carmaja_api_v3_product_from_draft($draft);
    $public = carmaja_api_v2_public_product_from_draft($draft);
    $public['productModelVersion'] = CARMAJA_PRODUCT_MODEL_V3;
    $public['sourceHash'] = $product['sourceHash'];
    $public['descriptionDocument'] = $product['descriptionDocument'];
    $uploadedById = [];
    foreach ($draft['images'] ?? [] as $uploadedImage) {
        if (is_array($uploadedImage) && is_string($uploadedImage['imageId'] ?? null)) {
            $uploadedById[$uploadedImage['imageId']] = $uploadedImage;
        }
    }
    $blobs = [];
    foreach ($draft['imageManifest'] ?? [] as $index => $manifestImage) {
        $imageId = is_array($manifestImage) ? ($manifestImage['imageId'] ?? null) : null;
        $image = is_string($imageId) ? ($uploadedById[$imageId] ?? null) : null;
        $sourcePath = is_array($image) ? ($image['path'] ?? null) : null;
        $fileName = sprintf('%02d.jpg', $index + 1);
        if (!is_string($sourcePath) || !is_file($sourcePath)
            || !is_array($image)
            || ($image['fileName'] ?? null) !== $fileName) {
            throw new CarmajaApiException(
                409,
                'v3-Produktbilder sind nicht vollständig hochgeladen.',
                [],
                'product_images_incomplete'
            );
        }
        $blobs[] = [
            '_sourcePath' => $sourcePath,
            '_repoPath' => 'website/public/images/products/' . $public['sku'] . '/' . $fileName,
        ];
    }
    $public['_imageBlobs'] = $blobs;
    return $public;
}

function carmaja_api_v3_publish_product(string $productId, array $body, array $actor): array
{
    carmaja_api_validate_draft_id($productId);
    carmaja_api_reject_unknown_fields($body, [
        'expectedProductVersion', 'expectedSourceHash', 'operationId',
    ]);
    $expectedVersion = $body['expectedProductVersion'] ?? null;
    $expectedHash = $body['expectedSourceHash'] ?? null;
    $operationId = $body['operationId'] ?? null;
    if (!is_int($expectedVersion) || $expectedVersion < 1
        || !is_string($expectedHash)
        || preg_match('/^[0-9a-f]{64}$/', $expectedHash) !== 1
        || !is_string($operationId)) {
        throw new CarmajaApiException(422, 'v3-Publishvertrag ist ungültig.', [], 'validation_failed');
    }
    carmaja_api_validate_operation_id($operationId);

    $prepared = carmaja_api_with_lock('draft-' . $productId, function () use (
        $productId,
        $expectedVersion,
        $expectedHash,
        $operationId
    ): array {
        $draft = carmaja_api_load_draft($productId);
        if (!is_array($draft)
            || ($draft['productModelVersion'] ?? null) !== CARMAJA_PRODUCT_MODEL_V3) {
            throw new CarmajaApiException(
                409,
                'Produkt ist nicht als v3-Produkt verfügbar.',
                [],
                'product_model_migration_required'
            );
        }
        $product = carmaja_api_v3_product_from_draft($draft);
        if ($product['productVersion'] !== $expectedVersion
            || !hash_equals($product['sourceHash'], $expectedHash)) {
            throw new CarmajaApiException(
                409,
                'Produktversion oder Quellhash wurde zwischenzeitlich geändert.',
                [],
                'product_version_conflict'
            );
        }
        if (($draft['lastV3PublishOperationId'] ?? null) !== $operationId) {
            if (!is_string($draft['sku'] ?? null) || $draft['sku'] === '') {
                $draft['sku'] = carmaja_api_allocate_sku($operationId);
            }
            if (!is_string($draft['slug'] ?? null) || $draft['slug'] === '') {
                $draft['slug'] = strtolower((string) $draft['sku'])
                    . '-' . carmaja_api_slugify((string) $draft['name']);
            }
            $draft['status'] = 'published';
            $draft['publishedAt'] = $draft['publishedAt'] ?? carmaja_api_now();
            $draft['updatedAt'] = carmaja_api_now();
            $draft['version'] = (int) ($draft['version'] ?? 0) + 1;
            $draft['lastV3PublishOperationId'] = $operationId;
            $draft = carmaja_api_save_draft($draft);
        }
        return [
            'draft' => $draft,
            'public' => carmaja_api_v3_public_product_with_blobs($draft),
            'requestHash' => carmaja_api_request_hash([
                'operationId' => $operationId,
                'productId' => $productId,
                'productVersion' => $expectedVersion,
                'sourceHash' => $expectedHash,
            ]),
        ];
    });
    $result = carmaja_api_run_publish_adapter_v3($prepared['public'], [
        'operationId' => $operationId,
        'requestHash' => $prepared['requestHash'],
    ]);
    carmaja_api_audit_best_effort('product_v3_published', [
        'productId' => $productId,
        'productVersion' => $expectedVersion,
        'sourceHash' => $expectedHash,
        'deviceId' => $actor['tokenId'] ?? null,
        'result' => 'success',
    ]);
    return [
        'publication' => [
            'productId' => $productId,
            'productVersion' => $expectedVersion,
            'sourceHash' => $expectedHash,
            'operationId' => $operationId,
            'commitSha' => $result['commitSha'] ?? null,
            'deploymentStatus' => $result['deploymentStatus'] ?? 'not_started',
        ],
        'product' => carmaja_api_v3_product_response_from_draft($prepared['draft']),
    ];
}

function carmaja_api_local_publish_adapter_v3(array $publicProduct, array $operation): array
{
    $operationId = $operation['operationId'] ?? null;
    carmaja_api_validate_operation_id(is_string($operationId) ? $operationId : '');
    $requestHash = is_string($operation['requestHash'] ?? null)
        ? $operation['requestHash']
        : carmaja_api_request_hash(['operationId' => $operationId, 'product' => $publicProduct]);
    $productId = (string) ($publicProduct['productId'] ?? '');
    carmaja_api_validate_draft_id($productId);
    $adapterPath = carmaja_api_path(
        'products/operations/v3-' . hash('sha256', $operationId) . '.json'
    );
    $publicForJson = array_diff_key($publicProduct, ['_imageBlobs' => true]);
    return carmaja_api_with_lock(
        'publish-adapter-v3-' . hash('sha256', $operationId),
        function () use (
            $operationId,
            $requestHash,
            $productId,
            $adapterPath,
            $publicForJson
        ): array {
            if (is_file($adapterPath)) {
                $stored = carmaja_api_read_target_json($adapterPath, [], 'v3-Publishstatus');
                if (($stored['requestHash'] ?? null) !== $requestHash) {
                    throw new CarmajaApiException(
                        409,
                        'v3-Publish-Adapter wurde mit anderem Inhalt verwendet.',
                        [],
                        'publish_adapter_conflict'
                    );
                }
                return is_array($stored['result'] ?? null)
                    ? $stored['result']
                    : ['commitSha' => null, 'deploymentStatus' => 'not_started'];
            }
            $path = carmaja_api_path('products/public-products-v2.json');
            $document = carmaja_api_read_json($path, ['version' => 2, 'products' => []]);
            $products = is_array($document['products'] ?? null)
                ? array_values($document['products'])
                : [];
            $replaced = false;
            foreach ($products as $index => $product) {
                if (is_array($product) && ($product['productId'] ?? null) === $productId) {
                    $products[$index] = $publicForJson;
                    $replaced = true;
                    break;
                }
            }
            if (!$replaced) {
                $products[] = $publicForJson;
            }
            carmaja_api_write_json_atomic(
                $path,
                ['version' => CARMAJA_PRODUCT_MODEL_V3, 'products' => array_values($products)]
            );
            $result = ['commitSha' => null, 'deploymentStatus' => 'not_started'];
            carmaja_api_write_json_atomic(
                $adapterPath,
                carmaja_api_target_document([
                    'operationId' => $operationId,
                    'requestHash' => $requestHash,
                    'createdAt' => carmaja_api_now(),
                    'result' => $result,
                ])
            );
            return $result;
        }
    );
}

function carmaja_api_run_publish_adapter_v3(array $publicProduct, array $operation): array
{
    $adapter = $GLOBALS['CARMAJA_API_PUBLISH_ADAPTER_V3']
        ?? 'carmaja_api_local_publish_adapter_v3';
    if (!is_callable($adapter)) {
        throw new CarmajaApiException(
            500,
            'v3-Publish-Adapter ist nicht verfügbar.',
            [],
            'publish_adapter_invalid'
        );
    }
    return $adapter($publicProduct, $operation);
}
