<?php
declare(strict_types=1);

// Disposable V5 device-test adapter. Never installed in a production program.
if (!defined('CARMAJA_BOOTSTRAP_NO_RUN')) { define('CARMAJA_BOOTSTRAP_NO_RUN', true); }
require_once __DIR__ . '/cart-mysql-integration.php';
require_once dirname(__DIR__) . '/test-api-private/program/bootstrap.php';
require_once dirname(__DIR__) . '/test-api-private/program/product-api.php';

function cart_v5_root(): string
{
    $root = dirname(__DIR__, 2);
    cart_probe_assert(realpath($root) === $root && !is_link($root)
        && preg_match('#^/home/www/carmaja-private-test/cart-e2e-[A-Za-z0-9-]+-v5$#D', $root) === 1, 'isolated_v5_stage_required');
    return $root;
}

function cart_v5_read(string $path): array
{
    cart_probe_assert(realpath($path) === $path && !is_link($path) && is_file($path)
        && (fileperms($path) & 0777) === 0600, 'private_v5_file_required');
    $value = json_decode((string) file_get_contents($path), true, 32, JSON_THROW_ON_ERROR);
    cart_probe_assert(is_array($value), 'private_v5_file_invalid');
    return $value;
}

function cart_v5_atomic(string $path, string $text, int $mode): void
{
    cart_probe_assert(realpath(dirname($path)) === dirname($path) && !is_link($path), 'v5_atomic_path_unsafe');
    $tmp = tempnam(dirname($path), '.v5-tmp-');
    cart_probe_assert(is_string($tmp), 'v5_temp_failed');
    try { cart_probe_assert(chmod($tmp, $mode) && file_put_contents($tmp, $text, LOCK_EX) === strlen($text)
        && rename($tmp, $path), 'v5_atomic_write_failed'); }
    finally { if (is_file($tmp)) { unlink($tmp); } }
}

function cart_v5_write(string $path, array $value): void
{
    cart_probe_assert(!file_exists($path) && !is_link($path), 'v5_file_exists');
    cart_v5_atomic($path, json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), 0600);
}

function cart_v5_rewrite(string $original, string $stage): string
{
    cart_probe_assert(preg_match('/^cart-e2e-[A-Za-z0-9-]+-v5$/D', $stage) === 1
        && substr_count($original, 'RewriteEngine On') === 1
        && !str_contains($original, '# CART-V5-DEVICE')
        && preg_match('/RewriteRule\s+\^v5/i', $original) !== 1, 'v5_routing_baseline_invalid');
    return str_replace('RewriteEngine On', "RewriteEngine On\n# CART-V5-DEVICE\nRewriteRule ^v5(?:/.*)?$ "
        . $stage . "/index.php [E=HTTP_AUTHORIZATION:%{HTTP:Authorization},QSA,L]\n# END CART-V5-DEVICE", $original);
}

function cart_v5_protected(): array
{
    $result = []; $base = '/home/www/carmaja-private-test';
    foreach (['products','drafts','uploads','sku-counter'] as $name) {
        $dir = $base . '/' . $name;
        cart_probe_assert(is_dir($dir) && realpath($dir) === $dir && !is_link($dir), 'existing_product_path_unsafe');
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)) as $file) {
            cart_probe_assert(!$file->isLink(), 'existing_product_link_unsafe');
            if ($file->isFile()) { $result[$file->getPathname()] = hash_file('sha256', $file->getPathname()); }
        }
    }
    foreach ([$base . '/config/runtime-config.php', $base . '/program/bootstrap.php',
        $base . '/shop-cart-test.json', $base . '/shop-cart-stripe-test.json',
        '/home/www/carmaja-test-api/index.php'] as $path) {
        cart_probe_assert(is_file($path) && !is_link($path), 'protected_test_file_missing');
        $result[$path] = hash_file('sha256', $path);
    }
    ksort($result, SORT_STRING); return $result;
}

function cart_v5_active(): array
{
    $r = cart_v5_root(); $owner = cart_v5_read($r . '/v5-owner.json');
    cart_probe_assert(($owner['testOnly'] ?? false) === true && $owner['stage'] === basename($r)
        && $owner['expiresEpoch'] > time(), 'v5_stage_expired');
    return carmaja_bootstrap_prepare($r . '/website/test-api-private/config/runtime-config.php');
}

function cart_v5_guard_product(string $id, array $owner, ?array $body = null): void
{
    carmaja_api_validate_draft_id($id);
    if (in_array(strtolower($id), $owner['blockedIds'], true)) {
        throw new CarmajaApiException(403, 'Bestehende Testprodukte bleiben gesperrt.', [], 'existing_product_protected');
    }
    if ($body !== null && preg_match('/^(?:HANDY-TEST|V5-PROBE)(?:$|[- ])/iu', trim((string) ($body['name'] ?? ''))) !== 1) {
        throw new CarmajaApiException(422, 'Nur kuenstliche HANDY-TEST-Produkte erlaubt.', [], 'artificial_product_required');
    }
}

function cart_v5_init(): array
{
    $r = cart_v5_root(); $public = '/home/www/carmaja-test-api/' . basename($r);
    cart_probe_assert(!file_exists($r . '/v5-owner.json') && !file_exists($public)
        && !is_link($public) && !file_exists('/home/www/carmaja-test-api/v5'), 'v5_target_exists');
    $db = cart_probe_config('/home/www/carmaja-private-test/shop-cart-test.json');
    cart_probe_assert($db['source']['database'] === 'dbs15962244' && $db['restore']['database'] === 'dbs15962566', 'v5_test_identity_invalid');
    $source = cart_probe_connect($db['source'], $db); $restore = cart_probe_connect($db['restore'], $db);
    cart_probe_assert(cart_probe_empty($source) && cart_probe_empty($restore), 'v5_targets_must_be_empty');
    foreach (['shop-cart-test.json','shop-cart-stripe-test.json'] as $name) {
        cart_probe_assert((fileperms('/home/www/carmaja-private-test/' . $name) & 0777) === 0600, 'retained_config_permissions_invalid');
    }
    $routing = '/home/www/carmaja-test-api/.htaccess';
    cart_probe_assert(realpath($routing) === $routing && !is_link($routing), 'routing_path_unsafe');
    $original = (string) file_get_contents($routing); $bound = cart_v5_rewrite($original, basename($r));
    $ids = [];
    foreach (glob('/home/www/carmaja-private-test/drafts/*.json') ?: [] as $path) {
        $id = basename($path, '.json'); if (preg_match(CARMAJA_DRAFT_PATTERN, $id) === 1) { $ids[] = strtolower($id); }
    }
    sort($ids, SORT_STRING);
    cart_v5_write($r . '/v5-owner.json', ['testOnly' => true, 'stage' => basename($r), 'createdEpoch' => time(),
        'expiresEpoch' => time() + 86400, 'protected' => cart_v5_protected(), 'blockedIds' => $ids,
        'originalRoutingHash' => hash('sha256', $original), 'boundRoutingHash' => hash('sha256', $bound)]);
    cart_v5_atomic($r . '/v5-routing-before.txt', $original, 0600);
    foreach (['auth','products','products/operations','drafts','uploads','uploads-temp','audit','idempotency','sku-counter','locks'] as $name) {
        if (!is_dir($r . '/' . $name)) { cart_probe_assert(mkdir($r . '/' . $name, 0750, true), 'v5_directory_failed'); }
    }
    cart_v5_write($r . '/environment.json', ['environment' => 'test']);
    $username = 'v5-handy-' . bin2hex(random_bytes(5)); $password = bin2hex(random_bytes(12));
    cart_v5_write($r . '/auth/api-users.json', ['environment' => 'test', 'users' => [
        ['username' => $username, 'passwordHash' => password_hash($password, PASSWORD_ARGON2ID), 'active' => true]]]);
    cart_v5_write($r . '/v5-login-handoff.json', ['testOnly' => true, 'username' => $username,
        'password' => $password, 'expiresEpoch' => cart_v5_read($r . '/v5-owner.json')['expiresEpoch']]);
    unset($password);
    cart_probe_assert(mkdir($public, 0755) && chmod($public, 0755), 'v5_public_directory_failed');
    $target = $db['source'];
    $config = ['environment' => 'test', 'publishTarget' => 'test', 'productionPublishEnabled' => false,
        'productApiV4WritesEnabled' => false, 'collectionCommerceEnabled' => false,
        'productApiV5WritesEnabled' => true, 'inventoryCartEnabled' => true, 'githubAdapterEnabled' => false,
        'privateDir' => $r, 'productPrivateDir' => $r, 'testPrivateDir' => $r,
        'testApiWebroot' => $public, 'testWebsiteWebroot' => '/home/www/carmaja-test-site',
        'usersFile' => $r . '/auth/api-users.json', 'tokenPepper' => bin2hex(random_bytes(32)),
        'commerceDsn' => sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $target['host'], $target['port'], $target['database']),
        'commerceUser' => $target['username'], 'commercePassword' => $target['password'],
        'commerceRequireTls' => true, 'commerceTlsCaPath' => $db['tlsCaPath'] ?: null];
    cart_v5_atomic($r . '/website/test-api-private/config/runtime-config.php', "<?php\nreturn " . var_export($config, true) . ";\n", 0600);
    $commerce = new CarmajaCartCommerce($source);
    $commerce->migrate(dirname(__DIR__) . '/database/commerce-schema.sql');
    foreach (['commerce-v1-ap4-shop','commerce-v1-ap5-admin','commerce-v2-collections','commerce-v3-cart-inventory'] as $id) {
        $commerce->migrateForward($id, dirname(__DIR__) . '/database/migrations/' . $id . '.sql');
    }
    cart_v5_active();
    cart_v5_atomic($public . '/index.php', "<?php\nini_set('display_errors','0');ini_set('log_errors','0');\nrequire "
        . var_export(__FILE__, true) . ";\ncart_v5_http();\n", 0644);
    cart_probe_assert(cart_v5_protected() === cart_v5_read($r . '/v5-owner.json')['protected']
        && hash_equals(hash('sha256', $original), hash_file('sha256', $routing)), 'v5_prebind_protected_changed');
    cart_v5_atomic($routing, $bound, 0644);
    return ['status' => 'v5_ready_for_smoke', 'sourceTls' => true, 'restoreEmpty' => true,
        'isolatedAuthentication' => true, 'existingProductsProtected' => true, 'publisher' => 'private_local_only',
        'checkoutWorkerMailEnabled' => false];
}

function cart_v5_http(): never
{
    ini_set('display_errors', '0'); ini_set('log_errors', '0');
    header('Content-Type: application/json'); header('Cache-Control: no-store');
    header('X-Robots-Tag: noindex, nofollow'); header('X-Content-Type-Options: nosniff');
    try {
        $r = cart_v5_root();
        $lock = fopen($r . '/v5-stage.lock', 'c');
        cart_probe_assert(is_resource($lock) && chmod($r . '/v5-stage.lock', 0600)
            && flock($lock, LOCK_SH | LOCK_NB), 'v5_stage_busy');
        cart_v5_active(); $owner = cart_v5_read($r . '/v5-owner.json');
        $path = (string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
        $prefix = '/' . basename($r) . '/index.php';
        if (str_starts_with($path, $prefix . '/')) { $path = substr($path, strlen($prefix)); }
        $parts = explode('/', trim($path, '/')); $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        if (($parts[0] ?? '') !== 'v5' || !in_array($method, ['GET','POST','PUT'], true)) {
            throw new CarmajaApiException(404, 'Test-Endpunkt fehlt.', [], 'endpoint_not_found');
        }
        $_SERVER['HTTP_AUTHORIZATION'] ??= $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
        if ($_SERVER['HTTP_AUTHORIZATION'] === '' && function_exists('getallheaders')) {
            foreach (getallheaders() as $key => $value) { if (strtolower($key) === 'authorization') { $_SERVER['HTTP_AUTHORIZATION'] = $value; } }
        }
        if (($parts[1] ?? '') === 'products' && isset($parts[2])) {
            carmaja_api_authorize();
            cart_v5_guard_product($parts[2], $owner, $method === 'PUT' && count($parts) === 3 ? carmaja_api_json_body() : null);
            if ($method === 'PUT' && !is_file($r . '/drafts/' . $parts[2] . '.json')) {
                cart_probe_assert(count(glob($r . '/drafts/*.json') ?: []) < 20, 'v5_fixture_limit');
            }
        }
        carmaja_api_v5_route($method, array_slice($parts, 1));
    } catch (CarmajaApiException $e) {
        carmaja_bootstrap_send($e->statusCode, ['ok' => false, 'error' => ['code' => $e->errorCode,
            'message' => $e->getMessage(), 'fields' => (object) $e->fields]]);
    } catch (CarmajaCommerceException $e) {
        carmaja_bootstrap_send($e->httpStatus, ['ok' => false, 'error' => ['code' => $e->errorCode,
            'message' => 'Kuenstlicher Bestandsvorgang konnte nicht verarbeitet werden.', 'fields' => (object) []]]);
    } catch (Throwable) {
        carmaja_bootstrap_send(503, ['ok' => false, 'error' => ['code' => 'test_v5_unavailable',
            'message' => 'Isolierte V5-Testumgebung nicht verfuegbar.', 'fields' => (object) []]]);
    }
}

function cart_v5_cleanup(): array
{
    $r = cart_v5_root(); $owner = cart_v5_read($r . '/v5-owner.json');
    cart_probe_assert(!file_exists($r . '/website-preview-journal.json')
        || (is_file($r . '/website-preview-restored.json')
            && (cart_v5_read($r . '/website-preview-restored.json')['restored'] ?? false) === true),
        'v5_website_preview_must_be_restored_first');
    cart_probe_assert($owner['testOnly'] === true && $owner['stage'] === basename($r)
        && cart_v5_protected() === $owner['protected'], 'v5_cleanup_protected_changed');
    $lock = fopen($r . '/v5-stage.lock', 'c');
    cart_probe_assert(is_resource($lock) && chmod($r . '/v5-stage.lock', 0600)
        && flock($lock, LOCK_EX | LOCK_NB), 'v5_cleanup_request_running');
    $routing = '/home/www/carmaja-test-api/.htaccess';
    $hash = hash_file('sha256', $routing); $original = (string) file_get_contents($r . '/v5-routing-before.txt');
    cart_probe_assert(hash_equals($owner['originalRoutingHash'], hash('sha256', $original))
        && in_array($hash, [$owner['originalRoutingHash'], $owner['boundRoutingHash']], true), 'v5_cleanup_routing_changed');
    $db = cart_probe_config('/home/www/carmaja-private-test/shop-cart-test.json');
    cart_probe_assert($db['source']['database'] === 'dbs15962244' && $db['restore']['database'] === 'dbs15962566', 'v5_cleanup_database_identity');
    $allowed = [];
    foreach ([dirname(__DIR__) . '/database/commerce-schema.sql', ...glob(dirname(__DIR__) . '/database/migrations/*.sql')] as $file) {
        preg_match_all('/CREATE TABLE(?: IF NOT EXISTS)?\\s+([a-z_]+)/i', (string) file_get_contents($file), $m); $allowed = [...$allowed, ...$m[1]];
    }
    $connections = [];
    foreach (['source','restore'] as $name) { $pdo = cart_probe_connect($db[$name], $db);
        cart_probe_assert(array_diff(cart_probe_tables($pdo), $allowed) === [], 'v5_cleanup_unknown_tables'); $connections[$name] = $pdo; }
    $public = '/home/www/carmaja-test-api/' . basename($r);
    if (file_exists($public)) { cart_probe_assert(realpath($public) === $public && !is_link($public)
        && in_array(scandir($public), [['.','..'],['.','..','index.php']], true), 'v5_cleanup_public_scope'); }
    $entries = [];
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($r, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $file) {
        $p = $file->getPathname(); cart_probe_assert(!$file->isLink() && realpath($p) === $p && str_starts_with($p, $r . '/'), 'v5_cleanup_stage_link'); $entries[] = [$p, $file->isDir()];
    }
    if ($hash === $owner['boundRoutingHash']) { cart_v5_atomic($routing, $original, 0644); }
    foreach ($connections as $pdo) { $pdo->exec('SET FOREIGN_KEY_CHECKS=0'); foreach (cart_probe_tables($pdo) as $table) { $pdo->exec('DROP TABLE `' . $table . '`'); } $pdo->exec('SET FOREIGN_KEY_CHECKS=1'); cart_probe_assert(cart_probe_empty($pdo), 'v5_cleanup_database_not_empty'); }
    if (is_file($public . '/index.php')) { cart_probe_assert(unlink($public . '/index.php'), 'v5_cleanup_public_file'); }
    if (is_dir($public)) { cart_probe_assert(rmdir($public), 'v5_cleanup_public_dir'); }
    cart_probe_assert(cart_v5_protected() === $owner['protected']
        && hash_equals($owner['originalRoutingHash'], hash_file('sha256', $routing)), 'v5_cleanup_baseline_mismatch');
    foreach ($entries as [$p,$dir]) { cart_probe_assert($dir ? rmdir($p) : unlink($p), 'v5_cleanup_private_file'); }
    flock($lock, LOCK_UN); fclose($lock); cart_probe_assert(rmdir($r), 'v5_cleanup_root');
    return ['status'=>'v5_cleaned','sourceEmpty'=>true,'restoreEmpty'=>true,'protectedDataUnchanged'=>true,'routingRestored'=>true,'stageAbsent'=>true];
}

if (PHP_SAPI === 'cli' && realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    ini_set('display_errors', '0'); ini_set('log_errors', '0'); umask(0077);
    try {
        $result = match ($argv[1] ?? '') { 'init' => cart_v5_init(), 'cleanup' => cart_v5_cleanup(),
            default => throw new RuntimeException('v5_cli_action_invalid') };
        echo json_encode($result, JSON_THROW_ON_ERROR) . "\n";
    } catch (Throwable $e) { cart_probe_report($e); exit(1); }
}
