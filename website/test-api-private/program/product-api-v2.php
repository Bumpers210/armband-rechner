<?php

declare(strict_types=1);

const CARMAJA_PRODUCT_MODEL_V2 = 2;
const CARMAJA_MINIMUM_APP_VERSION_CODE = 2;
const CARMAJA_INVENTORY_ADJUSTMENT_REASONS = [
    'activate_new_unique',
    'shop_sale',
    'mark_unsellable',
    'release_return',
];

function carmaja_api_v2_success_response(array $data): array
{
    $response = carmaja_api_success_response($data);
    $response['apiVersion'] = 2;
    $response['productModelVersion'] = CARMAJA_PRODUCT_MODEL_V2;

    return $response;
}

function carmaja_api_v2_allowed_put_fields(): array
{
    return [
        'expectedProductVersion',
        'name',
        'description',
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

function carmaja_api_v2_validate_idempotency_key(mixed $value): string
{
    if (!is_string($value)
        || preg_match('/^[A-Za-z0-9._:-]{8,100}$/', $value) !== 1) {
        throw new CarmajaApiException(
            422,
            'Idempotency-Key ist erforderlich und ungültig.',
            ['Idempotency-Key' => '8 bis 100 sichere Zeichen erwartet.'],
            'validation_failed'
        );
    }

    return $value;
}

/**
 * Product write clients must advertise a version that understands the v2
 * contract. Legacy reads remain available; this check is applied by the HTTP
 * write routes and is deliberately side-effect free.
 */
function carmaja_api_validate_client_version_code(mixed $value): int
{
    $versionCode = null;

    if (is_int($value)) {
        $versionCode = $value;
    } elseif (is_string($value) && preg_match('/^[0-9]+$/', $value) === 1) {
        $versionCode = (int) $value;
    }

    if ($versionCode === null || $versionCode < CARMAJA_MINIMUM_APP_VERSION_CODE) {
        throw new CarmajaApiException(
            426,
            'Die verwendete App-Version muss aktualisiert werden.',
            [
                'versionCode' => 'Mindestens App-Version 2 erforderlich.',
                'minimumVersionCode' => (string) CARMAJA_MINIMUM_APP_VERSION_CODE,
            ],
            'client_update_required'
        );
    }

    return $versionCode;
}

function carmaja_api_v2_reject_client_managed_fields(array $payload): void
{
    if (array_key_exists('stock', $payload)) {
        throw new CarmajaApiException(
            409,
            'Das alte Bestandsfeld darf über die v2-API nicht geschrieben werden.',
            ['stock' => 'Verwende den versionierten Inventory-Vertrag.'],
            'stock_write_disabled'
        );
    }

    if (array_key_exists('vintedUrl', $payload)) {
        throw new CarmajaApiException(
            422,
            'Marktplatzfelder sind im v2-Produktvertrag nicht zulässig.',
            ['vintedUrl' => 'Der eigene Shop ist der einzige Verkaufskanal.'],
            'legacy_product_field_forbidden'
        );
    }

    $forbidden = array_values(array_filter(
        ['sourceHash', 'productVersion'],
        static fn (string $field): bool => array_key_exists($field, $payload)
    ));

    if ($forbidden !== []) {
        $fields = [];

        foreach ($forbidden as $field) {
            $fields[$field] = 'Wird ausschließlich serverseitig verwaltet.';
        }

        throw new CarmajaApiException(
            422,
            'Serverseitig verwaltete Produktfelder dürfen nicht gesetzt werden.',
            $fields,
            'client_managed_field_forbidden'
        );
    }
}

function carmaja_api_v2_validate_measurement(mixed $value, string $field): int|float
{
    if ((!is_int($value) && !is_float($value))
        || !is_finite((float) $value)
        || (float) $value <= 0
        || (float) $value > 1000) {
        throw new CarmajaApiException(
            422,
            'Produktmaß ist ungültig.',
            [$field => 'Positive Zahl bis 1000 erwartet.'],
            'validation_failed'
        );
    }

    $number = (float) $value;
    return floor($number) === $number ? (int) $number : $number;
}

function carmaja_api_v2_normalize_measurement_for_hash(mixed $value): int|float
{
    $number = (float) $value;
    return floor($number) === $number ? (int) $number : $number;
}

/**
 * Shared HTTP-boundary guard for both legacy and v2 product writes. The
 * legacy read and storage helpers remain available for compatibility tests,
 * but no external product payload may mutate the old stock field anymore.
 */
function carmaja_api_validate_product_write_payload(array $payload): void
{
    carmaja_api_v2_reject_client_managed_fields($payload);
}

/**
 * Validate the AP1 inventory-adjustment contract without changing inventory.
 * The actual transactional mutation belongs to AP2/MySQL.
 */
function carmaja_api_validate_inventory_adjustment(
    array $payload,
    mixed $idempotencyKey,
    bool $manualOperator = true
): array {
    carmaja_api_v2_reject_client_managed_fields($payload);
    carmaja_api_reject_unknown_fields($payload, [
        'productId',
        'targetOnHand',
        'expectedInventoryVersion',
        'reason',
        'correlationId',
    ]);

    $productId = $payload['productId'] ?? null;
    if (!is_string($productId)) {
        throw new CarmajaApiException(
            422,
            'Produkt-ID ist erforderlich.',
            ['productId' => 'Textwert im Produkt-ID-Format erwartet.'],
            'validation_failed'
        );
    }
    carmaja_api_validate_draft_id($productId);

    $targetOnHand = $payload['targetOnHand'] ?? null;
    if (!is_int($targetOnHand) || !in_array($targetOnHand, [0, 1], true)) {
        throw new CarmajaApiException(
            422,
            'targetOnHand ist ungültig.',
            ['targetOnHand' => 'Ganzzahl 0 oder 1 erwartet.'],
            'validation_failed'
        );
    }

    $expectedInventoryVersion = $payload['expectedInventoryVersion'] ?? null;
    if (!is_int($expectedInventoryVersion) || $expectedInventoryVersion < 0) {
        throw new CarmajaApiException(
            422,
            'expectedInventoryVersion ist ungültig.',
            ['expectedInventoryVersion' => 'Nichtnegative Ganzzahl erwartet.'],
            'validation_failed'
        );
    }

    $reason = $payload['reason'] ?? null;
    if (!is_string($reason) || !in_array($reason, CARMAJA_INVENTORY_ADJUSTMENT_REASONS, true)) {
        throw new CarmajaApiException(
            422,
            'Bestandsgrund ist nicht zulässig.',
            ['reason' => 'Ein freigegebener AP1-Bestandsgrund ist erforderlich.'],
            'invalid_inventory_reason'
        );
    }

    if ($manualOperator && $reason === 'shop_sale') {
        throw new CarmajaApiException(
            422,
            'shop_sale darf nicht als manuelle Betreiberaktion verwendet werden.',
            ['reason' => 'Dieser Grund wird ausschließlich durch den Shopverkauf gesetzt.'],
            'invalid_inventory_reason'
        );
    }

    $correlationId = $payload['correlationId'] ?? null;
    if (!is_string($correlationId)
        || preg_match(CARMAJA_OPERATION_PATTERN, $correlationId) !== 1) {
        throw new CarmajaApiException(
            422,
            'correlationId ist ungültig.',
            ['correlationId' => '8 bis 100 sichere Zeichen erwartet.'],
            'validation_failed'
        );
    }

    return [
        'productId' => $productId,
        'targetOnHand' => $targetOnHand,
        'expectedInventoryVersion' => $expectedInventoryVersion,
        'reason' => $reason,
        'correlationId' => $correlationId,
        'idempotencyKey' => carmaja_api_v2_validate_idempotency_key($idempotencyKey),
    ];
}

function carmaja_api_v2_validate_images(mixed $value): array
{
    if (!is_array($value) || !array_is_list($value) || count($value) > CARMAJA_MAX_IMAGES) {
        throw new CarmajaApiException(
            422,
            'Bilder sind ungültig.',
            ['images' => 'Eine Liste mit höchstens fünf Bildern erwartet.'],
            'validation_failed'
        );
    }

    $images = [];

    foreach ($value as $index => $image) {
        if (!is_array($image) || array_is_list($image)) {
            throw new CarmajaApiException(
                422,
                'Bilder sind ungültig.',
                ['images.' . $index => 'Objekt erwartet.'],
                'validation_failed'
            );
        }

        $unknown = array_diff(
            array_keys($image),
            ['imageId', 'fileName', 'alt', 'width', 'height', 'isMain']
        );

        if ($unknown !== []) {
            throw new CarmajaApiException(
                422,
                'Bilder enthalten unbekannte Felder.',
                ['images.' . $index => 'Unbekannte Felder.'],
                'validation_failed'
            );
        }

        $imageId = $image['imageId'] ?? null;
        $fileName = $image['fileName'] ?? null;

        if (!is_string($imageId)
            || preg_match(CARMAJA_IMAGE_PATTERN, $imageId) !== 1
            || !is_string($fileName)
            || preg_match('/^0[1-5]\.jpg$/', $fileName) !== 1
            || !is_string($image['alt'] ?? '')
            || trim((string) ($image['alt'] ?? '')) === ''
            || !is_int($image['width'] ?? null)
            || ($image['width'] ?? 0) <= 0
            || !is_int($image['height'] ?? null)
            || ($image['height'] ?? 0) <= 0
            || !is_bool($image['isMain'] ?? null)) {
            throw new CarmajaApiException(
                422,
                'Bilder sind ungültig.',
                ['images.' . $index => 'Bildmetadaten sind ungültig.'],
                'validation_failed'
            );
        }

        $images[] = [
            'imageId' => $imageId,
            'fileName' => $fileName,
            'alt' => (string) $image['alt'],
            'width' => (int) $image['width'],
            'height' => (int) $image['height'],
            'isMain' => $image['isMain'],
        ];
    }

    if ($images !== []
        && count(array_filter($images, static fn (array $image): bool => $image['isMain'])) !== 1) {
        throw new CarmajaApiException(
            422,
            'Bilder sind ungültig.',
            ['images' => 'Genau ein Hauptbild erwartet.'],
            'validation_failed'
        );
    }

    return $images;
}

function carmaja_api_v2_validate_put_payload(array $payload): array
{
    carmaja_api_v2_reject_client_managed_fields($payload);
    carmaja_api_reject_unknown_fields($payload, carmaja_api_v2_allowed_put_fields());

    foreach (carmaja_api_v2_allowed_put_fields() as $field) {
        if ($field !== 'expectedProductVersion' && !array_key_exists($field, $payload)) {
            throw new CarmajaApiException(
                422,
                'PUT /v2/products/{productId} erfordert eine vollständige Aktualisierung.',
                [$field => 'Pflichtfeld fehlt.'],
                'validation_failed'
            );
        }
    }

    $expectedProductVersion = $payload['expectedProductVersion'] ?? null;

    if (!is_int($expectedProductVersion) || $expectedProductVersion < 0) {
        throw new CarmajaApiException(
            422,
            'expectedProductVersion ist erforderlich.',
            ['expectedProductVersion' => 'Nichtnegative Ganzzahl erwartet.'],
            'validation_failed'
        );
    }

    $priceMinor = $payload['priceMinor'] ?? null;

    if (!is_int($priceMinor) || $priceMinor < 50) {
        throw new CarmajaApiException(
            422,
            'Preis ist ungültig.',
            ['priceMinor' => 'Ganzzahl von mindestens 50 Cent erwartet.'],
            'invalid_price'
        );
    }

    if (($payload['currency'] ?? null) !== 'eur') {
        throw new CarmajaApiException(
            422,
            'Währung ist ungültig.',
            ['currency' => 'Für V1 wird ausschließlich eur unterstützt.'],
            'invalid_currency'
        );
    }

    if (!is_bool($payload['salesEnabled'])) {
        throw new CarmajaApiException(
            422,
            'Verkaufsfreigabe ist ungültig.',
            ['salesEnabled' => 'Boolean erwartet.'],
            'validation_failed'
        );
    }

    return [
        'expectedProductVersion' => $expectedProductVersion,
        'name' => carmaja_api_validate_string($payload['name'], 'name', 120, true),
        'description' => carmaja_api_validate_string(
            $payload['description'],
            'description',
            500,
            true
        ),
        'materials' => carmaja_api_normalize_string_list($payload['materials'], 'materials'),
        'metalElements' => carmaja_api_normalize_string_list(
            $payload['metalElements'],
            'metalElements'
        ),
        'braceletSizeCm' => carmaja_api_v2_validate_measurement(
            $payload['braceletSizeCm'],
            'braceletSizeCm'
        ),
        'pearlSizeMm' => carmaja_api_v2_validate_measurement(
            $payload['pearlSizeMm'],
            'pearlSizeMm'
        ),
        'careInstructions' => carmaja_api_normalize_string_list(
            $payload['careInstructions'],
            'careInstructions'
        ),
        'images' => carmaja_api_v2_validate_images($payload['images']),
        'priceMinor' => $priceMinor,
        'currency' => 'eur',
        'salesEnabled' => $payload['salesEnabled'],
    ];
}

function carmaja_api_v2_canonical_product(array $product): array
{
    return [
        'braceletSizeCm' => carmaja_api_v2_normalize_measurement_for_hash(
            $product['braceletSizeCm'] ?? 0
        ),
        'careInstructions' => array_values($product['careInstructions'] ?? []),
        'currency' => (string) ($product['currency'] ?? ''),
        'description' => (string) ($product['description'] ?? ''),
        'images' => array_values($product['images'] ?? []),
        'materials' => array_values($product['materials'] ?? []),
        'metalElements' => array_values($product['metalElements'] ?? []),
        'productModelVersion' => CARMAJA_PRODUCT_MODEL_V2,
        'name' => (string) ($product['name'] ?? ''),
        'pearlSizeMm' => carmaja_api_v2_normalize_measurement_for_hash(
            $product['pearlSizeMm'] ?? 0
        ),
        'priceMinor' => (int) ($product['priceMinor'] ?? 0),
        'productId' => (string) ($product['productId'] ?? ''),
        'productVersion' => (int) ($product['productVersion'] ?? 0),
        'salesEnabled' => (bool) ($product['salesEnabled'] ?? false),
    ];
}

function carmaja_api_v2_source_hash(array $product): string
{
    $canonical = carmaja_api_canonicalize(carmaja_api_v2_canonical_product($product));

    return hash(
        'sha256',
        json_encode(
            $canonical,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
        )
    );
}

function carmaja_api_v2_product_from_draft(array $draft): array
{
    $productId = (string) ($draft['draftId'] ?? '');
    carmaja_api_validate_draft_id($productId);

    $images = [];

    foreach (array_values($draft['imageManifest'] ?? $draft['images'] ?? []) as $index => $image) {
        if (!is_array($image)) {
            continue;
        }

        $fileName = is_string($image['fileName'] ?? null)
            ? $image['fileName']
            : sprintf('%02d.jpg', $index + 1);
        $images[] = [
            'imageId' => (string) ($image['imageId'] ?? ''),
            'fileName' => $fileName,
            'alt' => (string) ($image['alt'] ?? $draft['name'] ?? ''),
            'width' => (int) ($image['width'] ?? 0),
            'height' => (int) ($image['height'] ?? 0),
            'isMain' => (bool) ($image['isMain'] ?? ($index === 0)),
        ];
    }

    $product = [
        'productModelVersion' => CARMAJA_PRODUCT_MODEL_V2,
        'productId' => $productId,
        'productVersion' => (int) ($draft['productVersion'] ?? 0),
        'name' => (string) ($draft['name'] ?? ''),
        'description' => (string) ($draft['shortDescription'] ?? ''),
        'materials' => array_values($draft['materials'] ?? []),
        'metalElements' => array_values($draft['metalElements'] ?? []),
        'braceletSizeCm' => carmaja_api_v2_normalize_measurement_for_hash(
            $draft['braceletSizeCm'] ?? 0
        ),
        'pearlSizeMm' => carmaja_api_v2_normalize_measurement_for_hash(
            $draft['pearlSizeMm'] ?? 0
        ),
        'careInstructions' => array_values($draft['careInstructions'] ?? []),
        'images' => $images,
        'priceMinor' => (int) ($draft['priceMinor'] ?? 0),
        'currency' => (string) ($draft['currency'] ?? ''),
        'salesEnabled' => (bool) ($draft['salesEnabled'] ?? false),
    ];
    $product['sourceHash'] = carmaja_api_v2_source_hash($product);

    foreach (['sku', 'slug'] as $field) {
        if (is_string($draft[$field] ?? null) && $draft[$field] !== '') {
            $product[$field] = $draft[$field];
        }
    }

    return $product;
}

function carmaja_api_v2_uploaded_images_from_draft(array $draft): array
{
    $uploaded = [];
    foreach (array_values($draft['images'] ?? []) as $image) {
        if (!is_array($image)
            || !is_string($image['imageId'] ?? null)
            || !is_string($image['fileName'] ?? null)
            || !is_string($image['path'] ?? null)
            || !is_file($image['path'])) {
            continue;
        }
        $uploaded[] = [
            'imageId' => $image['imageId'],
            'fileName' => $image['fileName'],
            'isMain' => (bool) ($image['isMain'] ?? false),
        ];
    }
    return $uploaded;
}

function carmaja_api_v2_product_response_from_draft(array $draft): array
{
    $product = carmaja_api_v2_product_from_draft($draft);
    $product['draftId'] = $product['productId'];
    $product['version'] = (int) ($draft['version'] ?? 0);
    $product['status'] = (string) ($draft['status'] ?? 'draft');
    $product['updatedAt'] = isset($draft['updatedAt']) ? (string) $draft['updatedAt'] : null;
    $product['images'] = carmaja_api_v2_uploaded_images_from_draft($draft);
    return $product;
}

function carmaja_api_v2_idempotency_path(string $key): string
{
    $hash = hash('sha256', $key);
    return carmaja_api_path('idempotency/v2-' . $hash . '.json');
}

function carmaja_api_v2_put_product(
    string $productId,
    array $body,
    array $actor,
    mixed $idempotencyKey
): array {
    carmaja_api_validate_draft_id($productId);
    $idempotencyKey = carmaja_api_v2_validate_idempotency_key($idempotencyKey);
    $requestHash = carmaja_api_request_hash([
        'productId' => $productId,
        'body' => $body,
    ]);

    return carmaja_api_with_lock('draft-' . $productId, function () use (
        $productId,
        $body,
        $actor,
        $idempotencyKey,
        $requestHash
    ): array {
        $idempotencyPath = carmaja_api_v2_idempotency_path($idempotencyKey);
        $stored = is_file($idempotencyPath)
            ? carmaja_api_read_target_json($idempotencyPath, [], 'v2-Idempotenz')
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

        $normalized = carmaja_api_v2_validate_put_payload($body);
        $existing = carmaja_api_load_draft($productId);
        if (is_array($existing)
            && ($existing['productModelVersion'] ?? null) === 3) {
            throw new CarmajaApiException(
                409,
                'Formatierte Produktbeschreibung benötigt die aktuelle App.',
                [],
                'product_model_upgrade_required'
            );
        }
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
        $draft['productModelVersion'] = CARMAJA_PRODUCT_MODEL_V2;
        $draft['productVersion'] = $nextProductVersion;
        $draft['name'] = $normalized['name'];
        $draft['shortDescription'] = $normalized['description'];
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

        $product = carmaja_api_v2_product_from_draft($draft);
        $draft['sourceHash'] = $product['sourceHash'];
        $savedDraft = carmaja_api_save_draft($draft);
        $response = carmaja_api_v2_product_response_from_draft($savedDraft);

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
        carmaja_api_audit_best_effort('product_v2_saved', [
            'productId' => $productId,
            'productVersion' => $nextProductVersion,
            'deviceId' => $actor['tokenId'] ?? null,
            'result' => 'success',
        ]);

        return $response;
    });
}

function carmaja_api_v2_list_products(): array
{
    $products = [];

    foreach (glob(carmaja_api_path('drafts/*.json')) ?: [] as $path) {
        $draft = carmaja_api_read_target_json($path, [], 'v2-Produkt');

        if (($draft['productModelVersion'] ?? null) !== CARMAJA_PRODUCT_MODEL_V2) {
            continue;
        }

        $products[] = carmaja_api_v2_product_from_draft($draft);
    }

    usort(
        $products,
        static fn (array $left, array $right): int =>
            strcmp($left['productId'], $right['productId'])
    );

    return [
        'productModelVersion' => CARMAJA_PRODUCT_MODEL_V2,
        'products' => $products,
    ];
}

function carmaja_api_v2_public_product_from_draft(array $draft): array
{
    $product = carmaja_api_v2_product_from_draft($draft);
    $sku = $product['sku'] ?? null;
    $slug = $product['slug'] ?? null;

    if (!is_string($sku)
        || preg_match('/^CP-\\d{4}-\\d{4}$/', $sku) !== 1
        || !is_string($slug)
        || preg_match(CARMAJA_SLUG_PATTERN, $slug) !== 1) {
        throw new CarmajaApiException(
            500,
            'Öffentliche Produktkennung ist ungültig.',
            [],
            'public_product_identifier_invalid'
        );
    }

    $images = $product['images'];

    if (count($images) < 1 || count($images) > CARMAJA_MAX_IMAGES) {
        throw new CarmajaApiException(
            500,
            'Öffentliche Produktbilder sind unvollständig.',
            [],
            'public_product_images_invalid'
        );
    }

    $publicImages = [];

    foreach ($images as $index => $image) {
        $expectedFileName = sprintf('%02d.jpg', $index + 1);

        if (!is_string($image['imageId'] ?? null)
            || preg_match(CARMAJA_IMAGE_PATTERN, $image['imageId']) !== 1
            || ($image['fileName'] ?? null) !== $expectedFileName
            || !is_string($image['alt'] ?? null)
            || trim($image['alt']) === ''
            || !is_int($image['width'] ?? null)
            || ($image['width'] ?? 0) <= 0
            || !is_int($image['height'] ?? null)
            || ($image['height'] ?? 0) <= 0
            || ($image['isMain'] ?? null) !== ($index === 0)) {
            throw new CarmajaApiException(
                500,
                'Öffentliche Produktbilder sind ungültig.',
                [],
                'public_product_images_invalid'
            );
        }

        $publicImages[] = [
            'imageId' => $image['imageId'],
            'fileName' => $expectedFileName,
            'src' => '/images/products/' . $sku . '/' . $expectedFileName,
            'alt' => $image['alt'],
            'width' => $image['width'],
            'height' => $image['height'],
            'isMain' => $image['isMain'],
        ];
    }

    $public = [
        'productModelVersion' => CARMAJA_PRODUCT_MODEL_V2,
        'productId' => $product['productId'],
        'productVersion' => $product['productVersion'],
        'sourceHash' => $product['sourceHash'],
        'sku' => $sku,
        'slug' => $slug,
        'title' => $product['name'],
        'description' => $product['description'],
        'materials' => $product['materials'],
        'metalElements' => $product['metalElements'],
        'braceletSizeCm' => $product['braceletSizeCm'],
        'pearlSizeMm' => $product['pearlSizeMm'],
        'priceMinor' => $product['priceMinor'],
        'currency' => $product['currency'],
        'salesEnabled' => $product['salesEnabled'],
        'images' => $publicImages,
        'updatedAt' => (string) ($draft['updatedAt'] ?? ''),
    ];

    return $public;
}

function carmaja_api_v2_public_product_with_blobs(array $draft): array
{
    $public = carmaja_api_v2_public_product_from_draft($draft);
    $blobs = [];
    $uploadedById = [];
    foreach ($draft['images'] ?? [] as $uploadedImage) {
        if (is_array($uploadedImage) && is_string($uploadedImage['imageId'] ?? null)) {
            $uploadedById[$uploadedImage['imageId']] = $uploadedImage;
        }
    }

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
                'v2-Produktbilder sind nicht vollständig hochgeladen.',
                [],
                'product_images_incomplete'
            );
        }
        $blobs[] = [
            '_sourcePath' => $sourcePath,
            '_repoPath' => 'website/public/images/products/'
                . $public['sku'] . '/' . $fileName,
        ];
    }
    $public['_imageBlobs'] = $blobs;
    return $public;
}

function carmaja_api_v2_publish_product(
    string $productId,
    array $body,
    array $actor
): array {
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
        throw new CarmajaApiException(
            422,
            'v2-Publishvertrag ist ungültig.',
            [],
            'validation_failed'
        );
    }
    carmaja_api_validate_operation_id($operationId);

    $prepared = carmaja_api_with_lock('draft-' . $productId, function () use (
        $productId,
        $expectedVersion,
        $expectedHash,
        $operationId,
        $actor
    ): array {
        $draft = carmaja_api_load_draft($productId);
        if (!is_array($draft)
            || ($draft['productModelVersion'] ?? null) !== CARMAJA_PRODUCT_MODEL_V2) {
            throw new CarmajaApiException(
                409,
                'Produkt ist nicht als v2-Produkt verfügbar.',
                [],
                'product_model_migration_required'
            );
        }
        $product = carmaja_api_v2_product_from_draft($draft);
        if ($product['productVersion'] !== $expectedVersion
            || !hash_equals($product['sourceHash'], $expectedHash)) {
            throw new CarmajaApiException(
                409,
                'Produktversion oder Quellhash wurde zwischenzeitlich geändert.',
                [],
                'product_version_conflict'
            );
        }
        if (($draft['lastV2PublishOperationId'] ?? null) !== $operationId) {
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
            $draft['lastV2PublishOperationId'] = $operationId;
            $draft = carmaja_api_save_draft($draft);
        }
        $public = carmaja_api_v2_public_product_with_blobs($draft);
        $requestHash = carmaja_api_request_hash([
            'operationId' => $operationId,
            'productId' => $productId,
            'productVersion' => $expectedVersion,
            'sourceHash' => $expectedHash,
        ]);
        return [
            'draft' => $draft,
            'public' => $public,
            'requestHash' => $requestHash,
        ];
    });

    $result = carmaja_api_run_publish_adapter_v2($prepared['public'], [
            'operationId' => $operationId,
            'requestHash' => $prepared['requestHash'],
    ]);
    carmaja_api_audit_best_effort('product_v2_published', [
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
        'product' => carmaja_api_v2_product_response_from_draft($prepared['draft']),
    ];
}

function carmaja_api_v2_read_public_projection(string $path, mixed ...$unused): array
{
    $document = carmaja_api_read_json(
        $path,
        ['version' => CARMAJA_PRODUCT_MODEL_V2, 'products' => []]
    );
    $keys = array_keys($document);
    sort($keys, SORT_STRING);

    if ($keys !== ['products', 'version']
        || ($document['version'] ?? null) !== CARMAJA_PRODUCT_MODEL_V2
        || !is_array($document['products'] ?? null)
        || !array_is_list($document['products'])) {
        throw new CarmajaApiException(
            503,
            'Public v2 product data does not match the strict website contract.',
            [],
            'public_v2_projection_invalid'
        );
    }

    return $document;
}

function carmaja_api_local_publish_adapter_v2(
    array $publicProduct,
    array $operation
): array {
    if (array_key_exists('stock', $publicProduct)
        || array_key_exists('vintedUrl', $publicProduct)) {
        throw new CarmajaApiException(
            422,
            'v2-Publisher darf keine Legacy-Verkaufsfelder enthalten.',
            [],
            'public_v2_legacy_field'
        );
    }

    $operationId = $operation['operationId'] ?? null;
    carmaja_api_validate_operation_id(is_string($operationId) ? $operationId : '');
    $productId = (string) ($publicProduct['productId'] ?? '');
    carmaja_api_validate_draft_id($productId);
    $requestHash = is_string($operation['requestHash'] ?? null)
        ? $operation['requestHash']
        : carmaja_api_request_hash([
            'operationId' => $operationId,
            'product' => $publicProduct,
        ]);
    $adapterPath = carmaja_api_path(
        'products/operations/v2-' . hash('sha256', $operationId) . '.json'
    );
    $publicProductForJson = array_diff_key($publicProduct, ['_imageBlobs' => true]);

    return carmaja_api_with_lock(
        'publish-adapter-v2-' . hash('sha256', $operationId),
        function () use ($publicProductForJson, $productId, $operationId, $requestHash, $adapterPath): array {
            if (is_file($adapterPath)) {
                $stored = carmaja_api_read_target_json(
                    $adapterPath,
                    [],
                    'v2-Publish-Adapterstatus'
                );

                if (($stored['requestHash'] ?? null) !== $requestHash) {
                    throw new CarmajaApiException(
                        409,
                        'v2-Publish-Adapter wurde mit anderem Inhalt verwendet.',
                        [],
                        'publish_adapter_conflict'
                    );
                }

                return is_array($stored['result'] ?? null)
                    ? $stored['result']
                    : [
                        'commitSha' => null,
                        'deploymentStatus' => 'not_started',
                    ];
            }

            $path = carmaja_api_path('products/public-products-v2.json');
            $document = carmaja_api_v2_read_public_projection(
                $path,
                ['version' => CARMAJA_PRODUCT_MODEL_V2, 'products' => []],
                'Öffentliche v2-Produktdaten'
            );
            $products = is_array($document['products'] ?? null)
                ? $document['products']
                : [];
            $replaced = false;

            foreach ($products as $index => $product) {
                if (is_array($product) && ($product['productId'] ?? null) === $productId) {
                    $products[$index] = $publicProductForJson;
                    $replaced = true;
                    break;
                }
            }

            if (!$replaced) {
                $products[] = $publicProductForJson;
            }

            carmaja_api_write_json_atomic(
                $path,
                [
                    'version' => CARMAJA_PRODUCT_MODEL_V2,
                    'products' => array_values($products),
                ]
            );

            $result = [
                'commitSha' => null,
                'deploymentStatus' => 'not_started',
            ];

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

function carmaja_api_run_publish_adapter_v2(
    array $publicProduct,
    array $operation
): array {
    $adapter = $GLOBALS['CARMAJA_API_PUBLISH_ADAPTER_V2']
        ?? 'carmaja_api_local_publish_adapter_v2';

    if (!is_callable($adapter)) {
        throw new CarmajaApiException(
            500,
            'v2-Publish-Adapter ist nicht verfügbar.',
            [],
            'publish_adapter_invalid'
        );
    }

    return $adapter($publicProduct, $operation);
}
