<?php

declare(strict_types=1);

require_once __DIR__ . '/cart-commerce.php';
require_once __DIR__ . '/cart-public.php';

function carmaja_api_v5_response(array $data): array
{
    return [...carmaja_api_success_response($data), 'apiVersion' => 5, 'productModelVersion' => 4];
}

function carmaja_api_v5_require_write(): void
{
    $config = carmaja_bootstrap_load_config();
    if (($config['productApiV5WritesEnabled'] ?? false) !== true) {
        throw new CarmajaApiException(503, 'Neue Produktverwaltung ist noch gesperrt.', [], 'product_v5_not_enabled');
    }
    $minimum = $config['publishTarget'] === 'test' ? 10 : 8;
    $version = filter_var($_SERVER['HTTP_X_CARMAJA_APP_VERSION_CODE'] ?? '', FILTER_VALIDATE_INT);
    if ($version === false || $version < $minimum) {
        throw new CarmajaApiException(426, 'Bitte die App aktualisieren.', ['minimumVersionCode' => (string) $minimum], 'client_update_required');
    }
}

function carmaja_api_v5_put(string $id, array $body, array $actor, mixed $key): array
{
    if (array_key_exists('stock', $body)) { throw new CarmajaApiException(422, 'Bestand separat ändern.', [], 'stock_write_disabled'); }
    if (array_intersect(array_keys($body), ['productVersion','sourceHash','noticePolicyId','notices']) !== []) {
        throw new CarmajaApiException(422, 'Serverfelder dürfen nicht gesetzt werden.', [], 'client_managed_field_forbidden');
    }
    carmaja_api_reject_unknown_fields($body, carmaja_api_v3_allowed_put_fields());
    foreach (carmaja_api_v3_allowed_put_fields() as $field) {
        if (!array_key_exists($field, $body)) { throw new CarmajaApiException(422, 'Vollständiges Produkt erforderlich.', [$field => 'Pflichtfeld'], 'validation_failed'); }
    }
    $saved = carmaja_api_v3_put_product($id, $body, $actor, 'v5-' . hash('sha256', carmaja_api_v2_validate_idempotency_key($key)), false, true);
    $saved['available'] = $saved['status'] === 'published' && $saved['salesEnabled'];
    return $saved;
}

function carmaja_api_v5_product_response_from_draft(array $draft): array
{
    $response = in_array($draft['productModelVersion'] ?? null, [3,4], true)
        ? carmaja_api_v4_product_response_from_draft($draft)
        : carmaja_api_v4_legacy_product_response_from_draft($draft);
    // Publication, sales permission and counted inventory are separate gates.
    $response['salesEnabled'] = ($draft['salesEnabled'] ?? false) === true;
    $response['available'] = $response['status'] === 'published' && $response['salesEnabled'];
    return $response;
}

function carmaja_api_v5_route(string $method, array $segments): never
{
    if ($method === 'POST' && $segments === ['login']) {
        carmaja_bootstrap_send(200, carmaja_api_v5_response(carmaja_api_login(carmaja_api_json_body())));
    }
    $actor = carmaja_api_authorize();
    if ($method !== 'GET') { carmaja_api_v5_require_write(); }
    if ($method === 'GET' && $segments === ['products']) {
        $products = [];
        foreach (glob(carmaja_api_path('drafts/*.json')) ?: [] as $path) {
            $draft = carmaja_api_read_target_json($path, [], 'Produkt');
            $products[] = carmaja_api_v5_product_response_from_draft($draft);
        }
        usort($products, static fn ($a, $b) => strcmp($a['productId'], $b['productId']));
        carmaja_bootstrap_send(200, carmaja_api_v5_response(['products' => $products]));
    }
    if (($segments[0] ?? '') !== 'products' || !isset($segments[1])) {
        throw new CarmajaApiException(404, 'Endpunkt fehlt.', [], 'endpoint_not_found');
    }
    $id = $segments[1];
    carmaja_api_validate_draft_id($id);
    $draft = carmaja_api_load_draft($id);
    if ($method === 'PUT' && count($segments) === 2) {
        carmaja_bootstrap_send(200, carmaja_api_v5_response(['product' => carmaja_api_v5_put($id, carmaja_api_json_body(), $actor, $_SERVER['HTTP_IDEMPOTENCY_KEY'] ?? null)]));
    }
    if ($draft === null) { throw new CarmajaApiException(404, 'Produkt fehlt.', [], 'product_not_found'); }
    if ($method === 'GET' && count($segments) === 2) {
        $product = carmaja_api_v5_product_response_from_draft($draft);
        carmaja_bootstrap_send(200, carmaja_api_v5_response(['product' => $product]));
    }
    if (($segments[2] ?? '') === 'images') {
        if ($method === 'GET' && count($segments) === 4) { carmaja_bootstrap_send_product_image(carmaja_api_v4_product_image_file($id, $segments[3])); }
        if ($method === 'POST' && count($segments) === 3) {
            carmaja_bootstrap_send(200, carmaja_api_v5_response(['product' => carmaja_api_v5_product_response_from_draft(carmaja_api_upload_images($id, $_POST, $actor))]));
        }
    }
    $commerce = carmaja_bootstrap_commerce(carmaja_bootstrap_load_config());
    if (!$commerce instanceof CarmajaCartCommerce) { throw new CarmajaApiException(503, 'Bestandspflege gesperrt.', [], 'inventory_not_enabled'); }
    if (($segments[2] ?? '') === 'inventory' && count($segments) === 3) {
        if ($method === 'GET') { carmaja_bootstrap_send(200, carmaja_api_v5_response(['inventory' => $commerce->loadInventoryForManagement($id)])); }
        if ($method === 'POST') {
            $body = carmaja_api_json_body();
            $key = carmaja_api_v2_validate_idempotency_key($_SERVER['HTTP_IDEMPOTENCY_KEY'] ?? null);
            carmaja_bootstrap_send(200, carmaja_api_v5_response(['inventory' => $commerce->adjustInventory([...$body, 'productId' => $id, 'idempotencyKey' => $key], (string) $actor['tokenId'])]));
        }
    }
    if (($segments[2] ?? '') === 'initial-inventory' && $method === 'POST' && count($segments) === 3) {
        $body = carmaja_api_json_body();
        if (array_keys($body) !== ['targetOnHand'] || !is_int($body['targetOnHand'])) { throw new CarmajaApiException(422, 'Anfangsmenge ungültig.', [], 'validation_failed'); }
        carmaja_bootstrap_send(200, carmaja_api_v5_response(['initialInventory' => $commerce->stageInitialInventory($id, $body['targetOnHand'], (string) $actor['tokenId'],
            carmaja_api_v2_validate_idempotency_key($_SERVER['HTTP_IDEMPOTENCY_KEY'] ?? null))]));
    }
    if ($method === 'POST' && count($segments) === 3 && in_array($segments[2], ['publish','archive','restore'], true)) {
        carmaja_bootstrap_send(200, carmaja_api_v5_response(carmaja_api_v4_transition_product($id, carmaja_api_json_body(), $actor, $segments[2])));
    }
    throw new CarmajaApiException(404, 'Endpunkt fehlt.', [], 'endpoint_not_found');
}
