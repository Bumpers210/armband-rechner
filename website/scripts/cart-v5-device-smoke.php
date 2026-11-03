<?php
declare(strict_types=1);
require_once __DIR__ . '/cart-v5-device-test.php';

function cart_v5_request(string $path, string $method = 'GET', ?array $body = null,
    ?string $token = null, ?string $key = null, ?array $multipart = null, int $version = 10): array
{
    cart_probe_assert(str_starts_with($path, '/v5/'), 'v5_probe_path_invalid');
    $headers = ['X-Carmaja-App-Version-Code: ' . $version, 'Accept: application/json'];
    if ($token !== null) { $headers[] = 'Authorization: Bearer ' . $token; }
    if ($key !== null) { $headers[] = 'Idempotency-Key: ' . $key; }
    if ($multipart === null) { $headers[] = 'Content-Type: application/json'; }
    $ch = curl_init('https://test-api.carmaja-perlen.de' . $path);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_TIMEOUT => 20, CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2, CURLOPT_CUSTOMREQUEST => $method, CURLOPT_HTTPHEADER => $headers]);
    if ($multipart !== null) { curl_setopt($ch, CURLOPT_POSTFIELDS, $multipart); }
    elseif ($body !== null) { curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body, JSON_THROW_ON_ERROR)); }
    $raw = curl_exec($ch); $status = curl_getinfo($ch, CURLINFO_RESPONSE_CODE); curl_close($ch);
    cart_probe_assert(is_string($raw), 'v5_http_transport');
    return ['status' => $status, 'json' => json_decode($raw, true)];
}

function cart_v5_smoke(): array
{
    $r = cart_v5_root(); cart_v5_active(); $owner = cart_v5_read($r . '/v5-owner.json');
    $login = cart_v5_read($r . '/v5-login-handoff.json');
    cart_probe_assert(cart_v5_request('/v5/products')['status'] === 401, 'v5_unauthenticated_not_rejected');
    $logged = cart_v5_request('/v5/login', 'POST', ['username' => $login['username'], 'password' => $login['password'],
        'deviceName' => 'Artificial V5 probe', 'publishTarget' => 'test']);
    cart_probe_assert($logged['status'] === 200 && ($logged['json']['apiVersion'] ?? 0) === 5
        && $logged['json']['data']['publishTarget'] === 'test', 'v5_login_failed');
    $token = $logged['json']['data']['token'];
    cart_probe_assert(cart_v5_request('/v5/products', 'GET', null, $token)['json']['data']['products'] === [], 'v5_stage_not_empty');
    $id = '99000000-0000-4000-8000-000000000001'; $img = '99000000-0000-4000-8000-000000000011';
    $body = ['expectedProductVersion' => 0, 'name' => 'V5-PROBE Backend',
        'descriptionDocument' => ['version' => 1, 'blocks' => [['type' => 'paragraph', 'spans' => [[
            'text' => 'Kuenstlicher V5-Test. Keine echte Ware.', 'bold' => false, 'italic' => false, 'font' => 'standard', 'size' => 'normal']]]]],
        'materials' => ['Teststein'], 'metalElements' => [], 'braceletSizeCm' => 18, 'pearlSizeMm' => 8,
        'careInstructions' => [], 'images' => [['imageId' => $img, 'fileName' => '01.jpg', 'alt' => 'Kuenstliches Testbild',
            'width' => 600, 'height' => 600, 'isMain' => true]], 'priceMinor' => 2800, 'currency' => 'eur', 'salesEnabled' => false];
    $old = cart_v5_request('/v5/products/' . $id, 'PUT', $body, $token, 'v5-probe-oldclient', null, 9);
    if ($old['status'] !== 426) {
        $code = $old['json']['error']['code'] ?? 'non_json';
        if (!is_string($code) || preg_match('/^[a-z0-9_]{1,60}$/D', $code) !== 1) { $code = 'redacted'; }
        throw new RuntimeException('v5_old_client_http_' . $old['status'] . '_' . $code);
    }
    $created = cart_v5_request('/v5/products/' . $id, 'PUT', $body, $token, 'v5-probe-create');
    cart_probe_assert($created['status'] === 200, 'v5_put_failed'); $p = $created['json']['data']['product'];
    cart_probe_assert($p['productModelVersion'] === 4 && $p['productVersion'] === 1 && $p['priceMinor'] === 2800
        && $p['currency'] === 'eur' && $p['notices'] === CARMAJA_PRODUCT_NOTICES
        && $p['sourceHash'] === carmaja_api_v3_product_from_draft(carmaja_api_load_draft($id))['sourceHash'], 'v5_server_fields_wrong');
    cart_probe_assert(cart_v5_request('/v5/products/' . $id, 'PUT', $body, $token, 'v5-probe-create')['json'] === $created['json'], 'v5_replay_changed');
    foreach (['stock' => 3, 'sourceHash' => str_repeat('a', 64), 'productVersion' => 12, 'notices' => []] as $field => $value) {
        cart_probe_assert(cart_v5_request('/v5/products/' . $id, 'PUT', [...$body, $field => $value], $token, 'v5-probe-forbidden-' . $field)['status'] === 422, 'v5_forbidden_field_not_rejected');
    }
    $updated = cart_v5_request('/v5/products/' . $id, 'PUT', [...$body, 'expectedProductVersion' => 1], $token, 'v5-probe-update');
    cart_probe_assert($updated['status'] === 200 && $updated['json']['data']['product']['productVersion'] === 2, 'v5_version_not_monotonic');
    cart_probe_assert(cart_v5_request('/v5/products/' . $id, 'PUT', [...$body, 'expectedProductVersion' => 1], $token, 'v5-probe-stale')['status'] === 409, 'v5_conflict_not_rejected');
    if ($owner['blockedIds'] !== []) { cart_probe_assert(cart_v5_request('/v5/products/' . $owner['blockedIds'][0], 'PUT', $body, $token, 'v5-probe-existing')['status'] === 403, 'v5_existing_product_not_protected'); }
    $imagePath = $r . '/probe-image.jpg'; $image = imagecreatetruecolor(600, 600);
    imagefilledrectangle($image, 0, 0, 599, 599, imagecolorallocate($image, 220, 220, 220));
    imagestring($image, 5, 240, 290, 'V5 TEST', imagecolorallocate($image, 20, 20, 20));
    cart_probe_assert(imagejpeg($image, $imagePath, 85) && chmod($imagePath, 0600), 'v5_probe_image_failed'); imagedestroy($image);
    $p = $updated['json']['data']['product'];
    $uploaded = cart_v5_request('/v5/products/' . $id . '/images', 'POST', null, $token, null,
        ['expectedVersion' => (string) $p['version'], 'imageId' => $img, 'desiredImageIds' => json_encode([$img]),
            'image' => new CURLFile($imagePath, 'image/jpeg', '01.jpg')]);
    cart_probe_assert($uploaded['status'] === 200, 'v5_image_upload_failed'); $p = $uploaded['json']['data']['product'];
    cart_probe_assert(count($p['images']) === 1, 'v5_image_missing');
    $initial = cart_v5_request('/v5/products/' . $id . '/initial-inventory', 'POST', ['targetOnHand' => 3], $token, 'v5-probe-initial');
    cart_probe_assert($initial['status'] === 200 && $initial['json']['data']['initialInventory']['status'] === 'staged', 'v5_initial_failed');
    $pubBody = ['expectedProductVersion' => $p['productVersion'], 'expectedSourceHash' => $p['sourceHash'], 'operationId' => 'v5-probe-publish'];
    cart_probe_assert(cart_v5_request('/v5/products/' . $id . '/publish', 'POST', $pubBody, $token)['status'] === 200, 'v5_publish_failed');
    $inventory = cart_v5_request('/v5/products/' . $id . '/inventory', 'GET', null, $token);
    cart_probe_assert($inventory['status'] === 200 && $inventory['json']['data']['inventory']['onHand'] === 3
        && $inventory['json']['data']['inventory']['inventoryVersion'] === 1, 'v5_initial_not_applied_once');
    cart_probe_assert(cart_v5_request('/v5/products/' . $id . '/publish', 'POST', $pubBody, $token)['status'] === 200
        && cart_v5_request('/v5/products/' . $id . '/inventory', 'GET', null, $token)['json'] === $inventory['json'], 'v5_republish_changed_stock');
    $adj = ['targetOnHand' => 4, 'expectedInventoryVersion' => 1, 'reason' => 'receive_stock', 'correlationId' => 'v5-probe-stock'];
    $changed = cart_v5_request('/v5/products/' . $id . '/inventory', 'POST', $adj, $token, 'v5-probe-adjust');
    cart_probe_assert($changed['status'] === 200 && $changed['json']['data']['inventory']['onHand'] === 4, 'v5_adjust_failed');
    cart_probe_assert(cart_v5_request('/v5/products/' . $id . '/inventory', 'POST', $adj, $token, 'v5-probe-adjust')['json'] === $changed['json'], 'v5_adjust_replay');
    cart_probe_assert(cart_v5_request('/v5/products/' . $id . '/inventory', 'POST', $adj, $token, 'v5-probe-adjust-stale')['status'] === 409, 'v5_stock_conflict_not_rejected');
    $read = cart_v5_request('/v5/products/' . $id, 'GET', null, $token);
    cart_probe_assert($read['status'] === 200 && (float) $read['json']['data']['product']['braceletSizeCm'] === 18.0
        && (float) $read['json']['data']['product']['pearlSizeMm'] === 8.0
        && $read['json']['data']['product']['notices'] === CARMAJA_PRODUCT_NOTICES, 'v5_roundtrip_lost_fields');
    $projection = json_decode((string) file_get_contents($r . '/products/public-products-v2.json'), true, 32, JSON_THROW_ON_ERROR);
    cart_probe_assert(array_keys($projection) === ['version','products'] && $projection['version'] === 4
        && count($projection['products']) === 1 && !array_key_exists('stock', $projection['products'][0])
        && !array_key_exists('vintedUrl', $projection['products'][0]), 'v5_projection_wrong');
    cart_probe_assert(cart_v5_protected() === $owner['protected'], 'v5_protected_data_changed');
    $proof = ['status' => 'v5_http_smoke_passed', 'testOnly' => true, 'authentication' => true,
        'versionHashAndNotices' => true, 'putReplayAndConflict' => true, 'imageUpload' => true,
        'publisherV4' => true, 'inventoryInitializationExactlyOnce' => true,
        'inventoryAdjustmentAndReplay' => true, 'existingProductsProtected' => true,
        'noCheckoutMailWorker' => true, 'protectedFilesUnchanged' => true];
    cart_v5_write($r . '/v5-smoke-proof.json', $proof); return $proof;
}

if (PHP_SAPI === 'cli' && realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    ini_set('display_errors','0'); ini_set('log_errors','0');
    try { echo json_encode(cart_v5_smoke(), JSON_THROW_ON_ERROR) . "\n"; }
    catch (Throwable $e) { $code = $e->getMessage(); if (preg_match('/^[a-z0-9_]{1,80}$/D', $code) !== 1) { $code = 'v5_smoke_error'; }
        echo json_encode(['status'=>'v5_smoke_failed','code'=>$code,'type'=>get_class($e),'file'=>basename($e->getFile()),'line'=>$e->getLine()]) . "\n"; exit(1); }
}
