<?php
declare(strict_types=1);

// Disposable browser-acceptance harness. Uses the EMPTY restore test target,
// never the source holding the user's device-test products. No provider keys.
define('CARMAJA_BOOTSTRAP_NO_RUN', true);
require_once __DIR__ . '/cart-mysql-integration.php';
require_once dirname(__DIR__) . '/test-api-private/program/bootstrap.php';
require_once dirname(__DIR__) . '/test-api-private/program/cart-public.php';

function cart_ui_stage_valid(string $name): bool
{
    return preg_match('/^cart-e2e-ui-[0-9]{8}-[a-f0-9]{8}$/D', $name) === 1;
}

function cart_ui_root(): string
{
    $root = dirname(__DIR__, 2);
    cart_probe_assert(realpath($root) === $root && !is_link($root)
        && dirname($root) === '/home/www/carmaja-private-test'
        && cart_ui_stage_valid(basename($root)), 'isolated_ui_stage_required');
    return $root;
}

function cart_ui_write(string $path, array|string $data): void
{
    cart_probe_assert(!file_exists($path) && !is_link($path)
        && realpath(dirname($path)) === dirname($path), 'ui_new_private_path_required');
    $handle = fopen($path, 'x');
    cart_probe_assert(is_resource($handle), 'ui_private_write_failed');
    try {
        cart_probe_assert(chmod($path, 0600), 'ui_private_permissions_failed');
        $text = is_array($data) ? json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) : $data;
        cart_probe_assert(fwrite($handle, $text) === strlen($text) && fflush($handle), 'ui_private_write_failed');
    } finally { fclose($handle); }
}

function cart_ui_read(string $file): array
{
    $path = cart_ui_root() . '/' . $file;
    cart_probe_assert(realpath($path) === $path && !is_link($path)
        && (fileperms($path) & 0777) === 0600, 'ui_private_file_required');
    return json_decode((string) file_get_contents($path), true, 64, JSON_THROW_ON_ERROR);
}

function cart_ui_targets(): array
{
    $config = cart_probe_config('/home/www/carmaja-private-test/shop-cart-test.json');
    cart_probe_assert($config['source']['database'] === 'dbs15962244'
        && $config['restore']['database'] === 'dbs15962566', 'ui_test_identity_mismatch');
    return $config;
}

function cart_ui_tree(string $root, string $exclude = ''): array
{
    cart_probe_assert(realpath($root) === $root && !is_link($root), 'ui_tree_path_unsafe');
    $result = [];
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)) as $file) {
        cart_probe_assert(!$file->isLink(), 'ui_tree_symlink');
        $relative = substr($file->getPathname(), strlen($root) + 1);
        if ($exclude !== '' && str_starts_with($relative, $exclude . '/')) { continue; }
        if ($file->isFile()) { $result[$relative] = hash_file('sha256', $file->getPathname()); }
    }
    ksort($result, SORT_STRING); return $result;
}

function cart_ui_protected(): array
{
    $base = '/home/www/carmaja-private-test'; $result = [];
    foreach (['products','drafts','uploads','sku-counter', 'cart-e2e-20261014-v5device-9cd3831c-v5'] as $dir) {
        $result[$dir] = cart_ui_tree($base . '/' . $dir);
    }
    foreach (['config/runtime-config.php','shop-cart-test.json','shop-cart-stripe-test.json','shop-cart-brevo-test.json'] as $file) {
        $path = $base . '/' . $file;
        if (is_file($path)) { cart_probe_assert(!is_link($path), 'ui_config_symlink'); $result[$file] = hash_file('sha256', $path); }
    }
    $stage = basename(cart_ui_root());
    $result['api'] = cart_ui_tree('/home/www/carmaja-test-api', $stage);
    $result['site'] = cart_ui_tree('/home/www/carmaja-test-site', $stage);
    $config = cart_ui_targets(); $source = cart_probe_connect($config['source'], $config);
    $source->exec('SET SESSION TRANSACTION READ ONLY'); $source->beginTransaction();
    try { $result['source'] = cart_probe_fingerprint($source); } finally { $source->rollBack(); }
    return $result;
}

function cart_ui_allowed_tables(): array
{
    $tables = [];
    foreach ([dirname(__DIR__) . '/database/commerce-schema.sql', ...glob(dirname(__DIR__) . '/database/migrations/*.sql')] as $path) {
        preg_match_all('/CREATE TABLE(?: IF NOT EXISTS)?\s+([a-z_]+)/i', (string) file_get_contents($path), $matches);
        $tables = [...$tables, ...$matches[1]];
    }
    return array_unique($tables);
}

function cart_ui_init(): array
{
    $root = cart_ui_root(); $stage = basename($root);
    cart_probe_assert(!file_exists($root . '/owner.json')
        && !file_exists('/home/www/carmaja-test-api/' . $stage)
        && !file_exists('/home/www/carmaja-test-site/' . $stage), 'ui_stage_exists');
    $targets = cart_ui_targets(); $pdo = cart_probe_connect($targets['restore'], $targets);
    cart_probe_assert(cart_probe_empty($pdo), 'ui_restore_must_be_empty');
    $protected = cart_ui_protected();
    // Ownership is persisted before DDL so even an incomplete initialization is cleanable.
    cart_ui_write($root . '/owner.json', ['testOnly'=>true, 'restoreWasEmpty'=>true,
        'stage'=>$stage, 'expires'=>time()+14400, 'protected'=>$protected]);
    $commerce = new CarmajaCartCommerce($pdo);
    $commerce->migrate(dirname(__DIR__) . '/database/commerce-schema.sql');
    foreach (['commerce-v1-ap4-shop','commerce-v1-ap5-admin','commerce-v2-collections','commerce-v3-cart-inventory'] as $id) {
        $commerce->migrateForward($id, dirname(__DIR__) . '/database/migrations/' . $id . '.sql');
    }
    $pdo->exec("INSERT INTO legal_bundles(legal_bundle_id,terms_version,privacy_version,withdrawal_version,shipping_version,merchant_version,bundle_hash,status)
        VALUES ('cart-e2e-legal','artificial','artificial','artificial','artificial','artificial',REPEAT('a',64),'approved')");
    // This approval exists only in the disposable artificial fixture, not a legal release.
    foreach (['A','B'] as $label) {
        $id = 'CART-UI-' . $label;
        $product = ['productId'=>$id,'productModelVersion'=>4,'productVersion'=>1,'sourceHash'=>hash('sha256',$id),
            'title'=>'Kuenstlicher UI-Test ' . $label,'description'=>'Keine echte Ware.','materials'=>['Test'],'metalElements'=>[],
            'braceletSizeCm'=>18,'pearlSizeMm'=>8,'careInstructions'=>[],'images'=>[['src'=>'/images/products/test/01.jpg']],
            'priceMinor'=>$label === 'A' ? 2800 : 1900,'currency'=>'eur','salesEnabled'=>true,
            'noticePolicyId'=>'natural-stones-v1','notices'=>CARMAJA_PRODUCT_NOTICES];
        $commerce->projectCollectionProduct($product,'publish','ui-publish-'.$label,hash('sha256',$id));
        $commerce->adjustInventory(['productId'=>$id,'targetOnHand'=>5,'expectedInventoryVersion'=>0,
            'reason'=>'initial_count','correlationId'=>'ui-initial-'.$label,'idempotencyKey'=>'ui-initial-'.$label], 'artificial-ui-operator');
    }
    $paid = cart_probe_reserve($commerce,cart_probe_session($commerce,'ui-paid'),[
        ['productId'=>'CART-UI-A','quantity'=>2],['productId'=>'CART-UI-B','quantity'=>1]],'ui-paid-order');
    $checkout = $paid['snapshot']['checkoutId'];
    $commerce->recordStripeCreationOutcome($checkout,'created','cs_test_'.$checkout);
    $order = $commerce->finalizePayment($checkout,cart_probe_payment($paid,'card','succeeded'));
    $payment = $pdo->query("SELECT payment_id FROM payments WHERE checkout_id='".$checkout."'")->fetchColumn();
    $commerce->applyRefund($payment,'re_artificial_ui_partial','succeeded',2800);
    $pending = cart_probe_reserve($commerce,cart_probe_session($commerce,'ui-processing'),[
        ['productId'=>'CART-UI-A','quantity'=>1]],'ui-processing');
    $pendingId = $pending['snapshot']['checkoutId'];
    $commerce->recordStripeCreationOutcome($pendingId,'created','cs_test_'.$pendingId);
    $commerce->markPaymentProcessing($pendingId,cart_probe_payment($pending,'sepa_debit','processing'));
    require_once dirname(__DIR__) . '/test-api-private/program/shop-admin.php';
    $password = bin2hex(random_bytes(24));
    $commerce->createAdminUser(carmaja_commerce_new_id(),'cart-ui-operator',carmaja_shop_admin_hash_password($password));
    cart_ui_write($root.'/login.json',['username'=>'cart-ui-operator','password'=>$password]); unset($password);
    $row = $commerce->loadAdminOrder($order['orderId']);
    cart_ui_write($root.'/fixture.json',['orderId'=>$order['orderId'],'orderNumber'=>$row['order_number'],
        'email'=>'cart-artificial@example.com','name'=>'Künstlicher Test']);
    cart_ui_write($root.'/users.json',[]); cart_ui_write($root.'/environment.json',['environment'=>'test']);
    $target = $targets['restore'];
    $config = ['environment'=>'test','publishTarget'=>'test','productionPublishEnabled'=>false,'githubAdapterEnabled'=>false,
        'inventoryCartEnabled'=>true,'productApiV4WritesEnabled'=>false,'productApiV5WritesEnabled'=>false,'collectionCommerceEnabled'=>false,
        'privateDir'=>$root,'productPrivateDir'=>$root,'testPrivateDir'=>$root,
        'testApiWebroot'=>'/home/www/carmaja-test-api','testWebsiteWebroot'=>'/home/www/carmaja-test-site',
        'usersFile'=>$root.'/users.json','tokenPepper'=>bin2hex(random_bytes(32)),
        'commerceDsn'=>sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',$target['host'],$target['port'],$target['database']),
        'commerceUser'=>$target['username'],'commercePassword'=>$target['password'],'commerceRequireTls'=>true,
        'commerceTlsCaPath'=>$targets['tlsCaPath'] ?: null,'shopWebsiteOrigin'=>'https://test.carmaja-perlen.de',
        'shippingMethodId'=>'maxibrief','shippingPublicName'=>'Kuenstlicher Maxibrief','shippingAmountMinor'=>270,
        'activeLegalBundleId'=>'cart-e2e-legal'];
    $configDir = dirname(__DIR__).'/test-api-private/config';
    cart_probe_assert(!file_exists($configDir) && mkdir($configDir,0700),'ui_config_directory_failed');
    cart_ui_write($configDir.'/runtime-config.php',"<?php\nreturn ".var_export($config,true).";\n");
    carmaja_bootstrap_prepare($configDir.'/runtime-config.php');
    cart_probe_assert(cart_ui_protected() === $protected,'ui_protected_changed');
    return ['status'=>'isolated_ui_ready','restoreTls'=>true,'sourceUnchanged'=>true,'providerAccess'=>false,
        'orderNumber'=>$row['order_number'],'orderAmount'=>7770,'refunded'=>2800,'pendingSepa'=>true];
}

function cart_ui_http(): never
{
    ini_set('display_errors','0'); ini_set('log_errors','0');
    header('Content-Type: application/json'); header('Cache-Control: no-store'); header('X-Robots-Tag: noindex, nofollow');
    try {
        $owner = cart_ui_read('owner.json');
        cart_probe_assert($owner['testOnly'] === true && $owner['expires'] > time(), 'ui_stage_expired');
        $prefix = '/'.basename(cart_ui_root()).'/index.php';
        $path = (string)parse_url($_SERVER['REQUEST_URI'] ?? '',PHP_URL_PATH);
        cart_probe_assert(str_starts_with($path,$prefix.'/'),'ui_route_invalid');
        $suffix = substr($path,strlen($prefix)); $method = $_SERVER['REQUEST_METHOD'] ?? '';
        // No checkout, provider, webhook, worker or product-write route exposed.
        $allowed = preg_match('#^/admin/v1/(?:login|logout|orders(?:/[a-zA-Z0-9-]+(?:/(?:ship|restock-position))?)?|payments|mails|reviews|inventory(?:/[a-zA-Z0-9-]+)?)$#D',$suffix) === 1
            || in_array($suffix,['/shop/v3/context','/shop/v3/withdrawals/preview','/shop/v3/withdrawals/confirm'],true);
        cart_probe_assert($allowed,'ui_route_not_allowed');
        carmaja_bootstrap_prepare();
        if(str_starts_with($suffix,'/shop/v3/')) { carmaja_cart_route($method,explode('/',substr($suffix,9))); }
        $_SERVER['REQUEST_URI']=$suffix; carmaja_bootstrap_route_request();
    } catch(CarmajaCommerceException|CarmajaShopAdminException $e) {
        http_response_code($e->httpStatus); echo json_encode(['ok'=>false,'error'=>['code'=>$e->errorCode,'message'=>$e->getMessage()]]);
    } catch(Throwable $e) {
        $code = property_exists($e,'errorCode') ? $e->errorCode : $e->getMessage();
        if(!is_string($code)||preg_match('/^[a-z_]{1,80}$/D',$code)!==1){$code='ui_internal_failure';}
        $diagnostic=cart_ui_root().'/http-diagnostic.json';
        if(!file_exists($diagnostic)){cart_ui_write($diagnostic,['code'=>$code,'file'=>basename($e->getFile()),'line'=>$e->getLine()]);}
        http_response_code(503); echo '{"ok":false,"error":{"code":"ui_test_unavailable"}}';
    }
    exit;
}

function cart_ui_status(): array
{
    $owner=cart_ui_read('owner.json'); $c=cart_ui_targets(); $p=cart_probe_connect($c['restore'],$c);
    $p->exec('SET SESSION TRANSACTION READ ONLY'); $p->beginTransaction();
    try {
        $result=['protectedUnchanged'=>cart_ui_protected()===$owner['protected'],
            'inventory'=>array_map((new CarmajaCartCommerce($p))->inventory(...), ['CART-UI-A','CART-UI-B']),
            'orders'=>$p->query('SELECT order_number,status FROM orders')->fetchAll(PDO::FETCH_ASSOC),
            'withdrawals'=>$p->query('SELECT state,match_status,COUNT(*) AS count FROM withdrawal_requests GROUP BY state,match_status')->fetchAll(PDO::FETCH_ASSOC),
            'withdrawalItems'=>$p->query('SELECT position_no,quantity FROM withdrawal_items')->fetchAll(PDO::FETCH_ASSOC),
            'reviews'=>$p->query('SELECT subject_type,reason,status FROM review_cases')->fetchAll(PDO::FETCH_ASSOC),
            'mail'=>$p->query('SELECT message_type,status,COUNT(*) AS count FROM mail_outbox GROUP BY message_type,status')->fetchAll(PDO::FETCH_ASSOC),
            'restocks'=>(int)$p->query('SELECT COUNT(*) FROM restocked_items')->fetchColumn()];
    } finally {$p->rollBack();} return $result;
}

function cart_ui_cleanup(): array
{
    $owner=cart_ui_read('owner.json');
    cart_probe_assert($owner['testOnly'] === true && $owner['restoreWasEmpty'] === true
        && $owner['stage']===basename(cart_ui_root()),'ui_cleanup_ownership_missing');
    $c=cart_ui_targets();$p=cart_probe_connect($c['restore'],$c);$tables=cart_probe_tables($p);
    cart_probe_assert(array_diff($tables,cart_ui_allowed_tables())===[],'ui_cleanup_unknown_table');
    // Disable only the new endpoint before dropping our owned artificial tables.
    $entry='/home/www/carmaja-test-api/'.basename(cart_ui_root()).'/index.php';
    if(is_file($entry)){cart_probe_assert(!is_link($entry),'ui_cleanup_symlink'); unlink($entry);}
    $p->exec('SET FOREIGN_KEY_CHECKS=0');
    try {foreach($tables as $table){$p->exec('DROP TABLE `'.$table.'`');}}
    finally {$p->exec('SET FOREIGN_KEY_CHECKS=1');}
    cart_probe_assert(cart_probe_empty($p),'ui_restore_cleanup_incomplete');
    cart_probe_assert(cart_ui_protected()===$owner['protected'],'ui_protected_changed');
    return ['status'=>'artificial_database_cleaned','restoreEmpty'=>true,'sourceAndProductsUnchanged'=>true];
}

if(PHP_SAPI==='cli' && realpath((string)($_SERVER['SCRIPT_FILENAME']??''))===__FILE__){
    try {
        $result=match($argv[1]??''){'init'=>cart_ui_init(),'status'=>cart_ui_status(),'cleanup'=>cart_ui_cleanup(),default=>throw new RuntimeException('ui_action_invalid')};
        echo json_encode($result,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES)."\n";
    }catch(Throwable $e){cart_probe_report($e);exit(1);}
}
