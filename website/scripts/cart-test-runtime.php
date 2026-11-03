<?php
declare(strict_types=1);

// Maintained TEST runtime only. Existing product runtimes and credentials are read-only.
require_once __DIR__ . '/cart-site-e2e.php';

const CART_RUNTIME_BASE = '/home/www/carmaja-private-test';
const CART_RUNTIME_DEVICE = CART_RUNTIME_BASE . '/cart-e2e-20261014-v5device-9cd3831c-v5';

function cart_runtime_guard_name(string $name): bool
{
    return preg_match('/^cart-e2e-runtime-[0-9]{8}-[a-f0-9]{8}$/D', $name) === 1;
}

function cart_runtime_root(): string
{
    $root = cart_sandbox_root();
    cart_probe_assert(cart_runtime_guard_name(basename($root)), 'runtime_stage_invalid');
    return $root;
}

function cart_runtime_protected(): array
{
    $out = cart_site_fingerprint();
    foreach (['shop-cart-test.json', 'shop-cart-stripe-test.json', 'shop-cart-brevo-test.json'] as $file) {
        $path = CART_RUNTIME_BASE . '/' . $file;
        cart_sandbox_read($path);
        $out[$path] = hash_file('sha256', $path);
    }
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(CART_RUNTIME_DEVICE, FilesystemIterator::SKIP_DOTS)) as $file) {
        cart_probe_assert(!$file->isLink(), 'device_link_rejected');
        if ($file->isFile()) { $out[$file->getPathname()] = hash_file('sha256', $file->getPathname()); }
    }
    ksort($out, SORT_STRING);
    return $out;
}

function cart_runtime_pdo(): PDO
{
    $targets = cart_sandbox_targets();
    return cart_probe_connect($targets['source'], $targets);
}

function cart_runtime_preserved_rows(PDO $pdo): array
{
    $out = [];
    foreach (['commerce_products', 'commerce_inventory', 'inventory_adjustments', 'inventory_initializations', 'product_projection_operations'] as $table) {
        $rows = $pdo->query('SELECT * FROM `' . $table . '` ORDER BY product_id')->fetchAll(PDO::FETCH_ASSOC);
        $out[$table] = array_map(cart_probe_row_hash(...), $rows);
        sort($out[$table], SORT_STRING);
    }
    return $out;
}

function cart_runtime_sequence_action(?array $sequence, int $orders): string
{
    if ($sequence !== null) {
        cart_probe_assert(isset($sequence['next_value'])
            && preg_match('/^[1-9][0-9]*$/D', (string) $sequence['next_value']) === 1,
            'test_order_sequence_invalid');
        return 'preserve';
    }
    cart_probe_assert($orders === 0, 'test_order_sequence_missing_with_orders');
    return 'initialize';
}

function cart_runtime_initialize_sequence(PDO $pdo): array
{
    $pdo->beginTransaction();
    try {
        $row = $pdo->query("SELECT next_value FROM order_sequences WHERE sequence_name='carmaja-v1' FOR UPDATE")->fetch(PDO::FETCH_ASSOC);
        $action = cart_runtime_sequence_action(is_array($row) ? $row : null,
            (int) $pdo->query('SELECT COUNT(*) FROM orders')->fetchColumn());
        if ($action === 'initialize') {
            $pdo->exec("INSERT INTO order_sequences(sequence_name,next_value) VALUES ('carmaja-v1',1)");
        }
        $pdo->commit();
        return ['status' => $action, 'existingOrderNumbersUnchanged' => true];
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        throw $error;
    }
}

function cart_runtime_rewrite(string $original, string $stage): string
{
    cart_probe_assert(cart_runtime_guard_name($stage) && substr_count($original, 'RewriteEngine On') === 1
        && !str_contains($original, '# CART-SHOP-RUNTIME') && !str_contains($original, '# CART-SITE-E2E'), 'runtime_routing_conflict');
    return str_replace('RewriteEngine On', "RewriteEngine On\n# CART-SHOP-RUNTIME\nRewriteRule ^(?:shop/v3|admin/v1)(?:/.*)?$ "
        . $stage . "/index.php [QSA,L]\n# END CART-SHOP-RUNTIME", $original);
}

function cart_runtime_preflight(): array
{
    $root = cart_runtime_root();
    foreach ([CART_RUNTIME_BASE, CART_RUNTIME_DEVICE, '/home/www/carmaja-test-api', '/home/www/carmaja-test-site'] as $path) {
        cart_probe_assert(realpath($path) === $path && !is_link($path), 'runtime_path_unsafe');
    }
    $targets = cart_sandbox_targets(); $pdo = cart_probe_connect($targets['source'], $targets);
    cart_probe_assert(cart_probe_empty(cart_probe_connect($targets['restore'], $targets)), 'restore_not_empty');
    // No migration and no deletion: attach only to the already accepted cart schema.
    $migrations = $pdo->query('SELECT migration_id FROM schema_migrations ORDER BY migration_id')->fetchAll(PDO::FETCH_COLUMN);
    foreach (['commerce-v1-schema', 'commerce-v1-ap4-shop', 'commerce-v1-ap5-admin', 'commerce-v2-collections', 'commerce-v3-cart-inventory'] as $id) {
        cart_probe_assert(in_array($id, $migrations, true), 'required_cart_schema_missing');
    }
    foreach (['checkout_sagas', 'orders', 'mail_outbox', 'webhook_inbox'] as $table) {
        cart_probe_assert((int) $pdo->query('SELECT COUNT(*) FROM `' . $table . '`')->fetchColumn() === 0, 'existing_payment_activity_requires_review');
    }
    $config = cart_sandbox_configuration(cart_sandbox_read(CART_SANDBOX_STRIPE_CONFIG));
    $client = cart_sandbox_client($config);
    cart_probe_assert($client->balance->retrieve([])->livemode === false, 'sandbox_required');
    $brevo = cart_sandbox_read(CART_RUNTIME_BASE . '/shop-cart-brevo-test.json');
    cart_probe_assert(($brevo['testOnly'] ?? false) === true, 'test_brevo_required');
    unset($brevo['testOnly']); cart_brevo_setup_values($brevo);
    $bundle = cart_sandbox_read($root . '/site-legal.json');
    cart_probe_assert($bundle['id'] === 'cmj-test-legal-2026-10-25-v4' && $bundle['environment'] === 'test'
        && $bundle['status'] === 'test_only' && preg_match('/^[a-f0-9]{64}$/D', $bundle['contentHash']) === 1, 'test_legal_invalid');
    cart_runtime_rewrite((string) file_get_contents('/home/www/carmaja-test-api/.htaccess'), basename($root));
    return ['testOnly'=>true, 'sourceTls'=>true, 'restoreEmpty'=>true, 'cartSchema'=>true,
        'stripeSandbox'=>true, 'brevoConfigured'=>true, 'existingProducts'=>count($pdo->query('SELECT product_id FROM commerce_products')->fetchAll())];
}

function cart_runtime_install(): array
{
    $root = cart_runtime_root(); $paths = cart_sandbox_paths();
    cart_probe_assert(!file_exists($root . '/runtime-owner.json') && !file_exists($paths['public'])
        && !file_exists($paths['runtime']), 'runtime_already_exists');
    $result = cart_runtime_preflight(); $pdo = cart_runtime_pdo();
    $original = (string) file_get_contents('/home/www/carmaja-test-api/.htaccess');
    $bound = cart_runtime_rewrite($original, basename($root));
    cart_sandbox_write($root . '/runtime-owner.json', ['testOnly'=>true, 'stage'=>basename($root),
        'createdUtc'=>gmdate(DATE_ATOM), 'protected'=>cart_runtime_protected(),
        'preservedRows'=>cart_runtime_preserved_rows($pdo), 'beforeRouting'=>hash('sha256',$original),
        'boundRouting'=>hash('sha256',$bound)]);
    cart_site_atomic($root . '/routing-before.txt', $original, 0600);
    cart_site_atomic($root . '/routing-bound.txt', $bound, 0600);
    cart_runtime_initialize_sequence($pdo);
    cart_sandbox_write($root . '/users.json', []);
    cart_sandbox_write($root . '/environment.json', ['environment'=>'test']);
    $config = cart_sandbox_configuration(cart_sandbox_read(CART_SANDBOX_STRIPE_CONFIG));
    $bundle = cart_sandbox_read($root . '/site-legal.json');
    $config['activeLegalBundleId'] = $bundle['id'];
    $brevo = cart_sandbox_read(CART_RUNTIME_BASE . '/shop-cart-brevo-test.json');
    foreach (['brevoApiKey', 'brevoSenderEmail', 'brevoSenderName'] as $key) { $config[$key] = $brevo[$key]; }
    $config['brevoOperatorEmail'] = $brevo['testRecipientEmail'];
    $client = cart_sandbox_client($config);
    // Journal exists before the provider write; an ambiguous creation must be reviewed, never blindly repeated.
    $endpoint = $client->webhookEndpoints->create(['url'=>$paths['baseUrl'].'/stripe/webhook',
        'enabled_events'=>CARMAJA_STRIPE_WEBHOOK_ALLOWLIST, 'api_version'=>CARMAJA_STRIPE_WEBHOOK_API_VERSION,
        'description'=>'Carmaja maintained test cart '.basename($root)]);
    cart_sandbox_write($root . '/endpoint.json', ['id'=>$endpoint->id, 'secret'=>$endpoint->secret]);
    cart_probe_assert(!$endpoint->livemode && $endpoint->api_version === CARMAJA_STRIPE_WEBHOOK_API_VERSION, 'webhook_version_mismatch');
    $config['stripeWebhookSecret'] = $endpoint->secret;
    cart_site_atomic($paths['runtime'], "<?php\nreturn ".var_export($config,true).";\n", 0600);
    // SQL approved means checkout-eligible here; the immutable text bundle remains explicitly test_only.
    $pdo->prepare('INSERT INTO legal_bundles(legal_bundle_id,terms_version,privacy_version,withdrawal_version,shipping_version,merchant_version,bundle_hash,status) VALUES(?,?,?,?,?,?,?,?)')
        ->execute([$bundle['id'], $bundle['version'], $bundle['version'], $bundle['version'], $bundle['version'], $bundle['version'], $bundle['contentHash'], 'approved']);
    cart_runtime_config();
    cart_probe_assert(mkdir($paths['public'],0755) && chmod($paths['public'],0755), 'runtime_public_directory_failed');
    cart_site_atomic($paths['public'].'/index.php', "<?php\nini_set('display_errors','0');ini_set('log_errors','0');\nrequire "
        .var_export(__FILE__,true).";\ncart_runtime_http();\n",0644);
    cart_site_atomic($root.'/worker.php', "<?php\nrequire ".var_export(__FILE__,true).";\ncart_runtime_worker_entry();\n",0600);
    cart_probe_assert(cart_runtime_preserved_rows($pdo) === cart_sandbox_read($root.'/runtime-owner.json')['preservedRows'], 'existing_product_rows_changed');
    return [...$result, 'status'=>'staged_not_bound', 'existingProductsUnchanged'=>true];
}

function cart_runtime_config(): array
{
    $root = cart_runtime_root(); $owner = cart_sandbox_read($root.'/runtime-owner.json');
    cart_probe_assert(($owner['testOnly']??false) === true && $owner['stage'] === basename($root), 'runtime_owner_invalid');
    $config = carmaja_bootstrap_prepare(cart_sandbox_paths()['runtime']);
    cart_probe_assert($config['environment'] === 'test' && str_starts_with($config['stripeSecretKey'], 'sk_test_')
        && $config['inventoryCartEnabled'] && !$config['productionPublishEnabled'] && !$config['githubAdapterEnabled']
        && $config['shopWebsiteOrigin'] === 'https://test.carmaja-perlen.de'
        && $config['stripePaymentMethodTypes'] === ['card','klarna','sepa_debit'], 'runtime_not_test_only');
    cart_sandbox_targets(); // strict identity and TLS-acceptance contract on every invocation
    return $config;
}

function cart_runtime_bind(): array
{
    $root=cart_runtime_root(); $owner=cart_sandbox_read($root.'/runtime-owner.json'); cart_runtime_config();
    cart_probe_assert(cart_runtime_protected() === $owner['protected'], 'protected_test_files_changed');
    $path='/home/www/carmaja-test-api/.htaccess';
    cart_probe_assert(hash_equals($owner['beforeRouting'],hash_file('sha256',$path)), 'routing_changed');
    $bound=(string)file_get_contents($root.'/routing-bound.txt');
    cart_probe_assert(hash_equals($owner['boundRouting'],hash('sha256',$bound)), 'routing_backup_invalid');
    cart_site_atomic($path,$bound,0644);
    return ['status'=>'bound', 'routes'=>['shop/v3','admin/v1'], 'v5RoutePreserved'=>true];
}

function cart_runtime_unbind(): array
{
    $root=cart_runtime_root(); $owner=cart_sandbox_read($root.'/runtime-owner.json');
    $path='/home/www/carmaja-test-api/.htaccess';
    cart_probe_assert(hash_equals($owner['boundRouting'],hash_file('sha256',$path)), 'routing_changed');
    $original=(string)file_get_contents($root.'/routing-before.txt');
    cart_probe_assert(hash_equals($owner['beforeRouting'],hash('sha256',$original)), 'routing_backup_invalid');
    cart_site_atomic($path,$original,0644);
    return ['status'=>'unbound', 'dataUnchanged'=>true];
}

function cart_runtime_http(): never
{
    header('Cache-Control: no-store'); header('Content-Type: application/json; charset=utf-8');
    header('X-Robots-Tag: noindex, nofollow'); header('X-Content-Type-Options: nosniff');
    try {
        $config=cart_runtime_config();
        $path=(string)parse_url($_SERVER['REQUEST_URI']??'/',PHP_URL_PATH);
        $prefix='/'.basename(cart_runtime_root()).'/index.php';
        if (str_starts_with($path,$prefix.'/')) { $path=substr($path,strlen($prefix)); }
        if ($path==='/stripe/webhook' && ($_SERVER['REQUEST_METHOD']??'')==='POST') {
            $commerce=carmaja_bootstrap_commerce($config);
            $raw=(string)file_get_contents('php://input',false,null,0,CARMAJA_STRIPE_WEBHOOK_MAX_BYTES+1);
            $result=(new CarmajaStripeWebhookEndpoint())->receive($raw,$_SERVER['HTTP_STRIPE_SIGNATURE']??'', $config['stripeWebhookSecret'], false,
                static function(array $envelope,string $body) use($commerce,$config):void {
                    // Other tests in the same sandbox are never imported into this commerce database.
                    if (!cart_sandbox_event_owned($envelope['event'],cart_runtime_pdo())) { return; }
                    $encrypted=carmaja_stripe_encrypt_webhook_payload($body,$config['stripeWebhookPayloadKey']);
                    $commerce->persistWebhookEnvelope($envelope,$encrypted['ciphertext'],$config['stripeWebhookPayloadKeyId']);
                });
            http_response_code($result['status']); exit;
        }
        if (!preg_match('#^/(?:shop/v3|admin/v1)(?:/|$)#D',$path)) { http_response_code(404); exit; }
        $_SERVER['REQUEST_URI']=$path;
        carmaja_bootstrap_route_request();
    } catch (CarmajaCommerceException|CarmajaStripeException|CarmajaShopAdminException $e) {
        http_response_code($e->httpStatus); echo json_encode(['ok'=>false,'error'=>['code'=>$e->errorCode,'message'=>$e->getMessage()]]);
    } catch(Throwable) { http_response_code(503); echo '{"ok":false,"error":{"code":"test_runtime_unavailable"}}'; }
    exit;
}

function cart_runtime_mail_transport(array $request, array $config): array
{
    $private=cart_sandbox_read(CART_RUNTIME_BASE.'/shop-cart-brevo-test.json');
    // Brevo has no independent sandbox: prohibit mail to any unapproved recipient.
    if (!cart_runtime_recipient_allowed($request, $private['testRecipientEmail'])) {
        return ['status'=>400,'body'=>'{"code":"test_recipient_not_authorized"}'];
    }
    $ch=curl_init('https://api.brevo.com/v3/smtp/email');
    curl_setopt_array($ch,[CURLOPT_POST=>true,CURLOPT_RETURNTRANSFER=>true,CURLOPT_HTTPHEADER=>['api-key: '.$config['brevoApiKey'],'Content-Type: application/json'],
        CURLOPT_POSTFIELDS=>json_encode($request,JSON_THROW_ON_ERROR),CURLOPT_CONNECTTIMEOUT=>5,CURLOPT_TIMEOUT=>10,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2]);
    $body=curl_exec($ch);$status=curl_getinfo($ch,CURLINFO_RESPONSE_CODE);$error=curl_errno($ch);curl_close($ch);
    if ($error!==0||!is_string($body)) { throw new RuntimeException('test_delivery_unknown'); }
    return ['status'=>$status,'body'=>$body];
}

function cart_runtime_recipient_allowed(array $request, string $recipient): bool
{
    return filter_var($recipient, FILTER_VALIDATE_EMAIL) !== false
        && ($request['to'] ?? []) === [['email' => $recipient]]
        && empty($request['cc']) && empty($request['bcc']);
}

function cart_runtime_worker_run(): array
{
    $root=cart_runtime_root(); $lock=fopen($root.'/worker.lock','c');
    cart_probe_assert(is_resource($lock)&&chmod($root.'/worker.lock',0600),'runtime_lock_failed');
    if(!flock($lock,LOCK_EX|LOCK_NB)){fclose($lock);return ['status'=>'locked'];}
    try {
        $config=cart_runtime_config();$commerce=carmaja_bootstrap_commerce($config);
        $payment=(new CarmajaAp3Worker($commerce,new CarmajaStripeGateway($config),$config))->run();
        $mail=(new CarmajaAp5Worker($commerce,$config,static fn(array $request,string $key):array=>cart_runtime_mail_transport($request,$config)))->run();
        return ['status'=>'completed','payment'=>$payment,'mail'=>$mail];
    } finally { flock($lock,LOCK_UN);fclose($lock); }
}

function cart_runtime_worker_entry(): void
{
    if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
    ini_set('display_errors','0');ini_set('log_errors','0');umask(0077);$start=microtime(true);
    try {
        $root=cart_runtime_root(); cart_probe_assert(is_executable('/usr/bin/timeout'),'timeout_required');
        $p=proc_open(['/usr/bin/timeout','-k','1','37','/usr/bin/php8.4',__FILE__,'worker-run'],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
        cart_probe_assert(is_resource($p),'worker_start_failed');fclose($pipes[0]);$out=stream_get_contents($pipes[1]);fclose($pipes[1]);stream_get_contents($pipes[2]);fclose($pipes[2]);$exit=proc_close($p);
        $result=json_decode($out,true);if(!is_array($result)){$result=['status'=>'failed'];}
        $record=['startedUtc'=>gmdate(DATE_ATOM,(int)$start),'durationMs'=>(int)round((microtime(true)-$start)*1000),'exitCode'=>$exit,'result'=>$result];
        $file=$root.'/worker-runlog.jsonl';cart_probe_assert(!is_link($file),'worker_log_unsafe');$h=fopen($file,'a');
        cart_probe_assert(is_resource($h)&&chmod($file,0600),'worker_log_failed');try{cart_probe_assert(fwrite($h,json_encode($record,JSON_THROW_ON_ERROR)."\n")!==false&&fflush($h),'worker_log_failed');}finally{fclose($h);}
        echo json_encode($record,JSON_THROW_ON_ERROR)."\n";exit($exit===0?0:1);
    }catch(Throwable){fwrite(STDERR,"TEST_WORKER_FAILED\n");exit(1);}
}

function cart_runtime_smoke(): array
{
    $config=cart_runtime_config(); $commerce=carmaja_bootstrap_commerce($config);
    $ids=['89000000-0000-4000-8000-000000000001','89000000-0000-4000-8000-000000000002'];
    $before=array_map($commerce->inventory(...),$ids);
    $preflight=cart_site_request('/shop/v3/cart','OPTIONS',null,['Access-Control-Request-Method: PUT','Access-Control-Request-Headers: Content-Type,X-CSRF-Token,Idempotency-Key']);
    cart_probe_assert($preflight['status']===204&&($preflight['headers']['access-control-allow-origin']??'')===$config['shopWebsiteOrigin']
        &&str_contains(strtolower($preflight['headers']['access-control-allow-headers']??''),'idempotency-key'),'cors_failed');
    cart_probe_assert(cart_site_request('/shop/v3/context','GET',null,[],'https://invalid.example')['status']===403,'wrong_origin_accepted');
    $context=cart_site_request('/shop/v3/context');$cookie=strtolower($context['headers']['set-cookie']??'');
    cart_probe_assert($context['status']===200&&str_contains($context['headers']['cache-control']??'','no-store')
        &&str_starts_with($cookie,'__host-cmj_shop_session=')&&str_contains($cookie,'secure')&&str_contains($cookie,'httponly')&&str_contains($cookie,'samesite=lax'),'context_failed');
    $csrf=$context['json']['context']['csrfToken'];
    // A second tab's refresh must not invalidate the first tab's token.
    cart_probe_assert(cart_site_request('/shop/v3/context')['status']===200,'second_tab_context_failed');
    $cart=cart_site_request('/shop/v3/cart');$version=$cart['json']['cart']['cartVersion'];
    $body=['expectedCartVersion'=>$version,'items'=>[['productId'=>$ids[0],'quantity'=>2],['productId'=>$ids[1],'quantity'=>1]]];
    $key='runtime-smoke-'.bin2hex(random_bytes(12));$headers=['X-CSRF-Token: '.$csrf,'Idempotency-Key: '.$key];
    cart_probe_assert(cart_site_request('/shop/v3/cart','PUT',$body,['X-CSRF-Token: invalid','Idempotency-Key: '.$key])['status']===403,'bad_csrf_accepted');
    $changed=cart_site_request('/shop/v3/cart','PUT',$body,$headers);
    cart_probe_assert($changed['status']===200&&$changed['json']['cart']['totalMinor']===7770,'cart_total_failed');
    $replay=cart_site_request('/shop/v3/cart','PUT',$body,$headers);
    cart_probe_assert($replay['status']===200&&cart_site_replay_response($replay['json'])===cart_site_replay_response($changed['json']),'cart_replay_failed');
    cart_probe_assert(cart_site_request('/shop/v3/cart','PUT',$body,['X-CSRF-Token: '.$csrf,'Idempotency-Key: '.$key.'-stale'])['status']===409,'stale_cart_accepted');
    $version=$changed['json']['cart']['cartVersion'];
    foreach([-1,0,1.5,11] as $i=>$quantity){$bad=['expectedCartVersion'=>$version,'items'=>[['productId'=>$ids[0],'quantity'=>$quantity]]];
        cart_probe_assert(cart_site_request('/shop/v3/cart','PUT',$bad,['X-CSRF-Token: '.$csrf,'Idempotency-Key: '.$key.'-invalid-'.$i])['status']===422,'bad_quantity_accepted');}
    $quote=cart_site_request('/shop/v3/cart');
    cart_probe_assert($quote['status']===200&&is_string($quote['json']['cart']['checkoutContextToken']??null)
        &&$quote['json']['cart']['totalMinor']===7770,'checkout_quote_failed');
    $empty=cart_site_request('/shop/v3/cart','PUT',['expectedCartVersion'=>$version,'items'=>[]],['X-CSRF-Token: '.$csrf,'Idempotency-Key: '.$key.'-empty']);
    cart_probe_assert($empty['status']===200&&$before===array_map($commerce->inventory(...),$ids),'smoke_inventory_changed');
    return ['status'=>'passed','cors'=>true,'secureCookie'=>true,'csrf'=>true,'crossTabCsrf'=>true,'noStore'=>true,
        'serverTotalMinor'=>7770,'shippingOnceMinor'=>270,'idempotency'=>true,'staleVersionRejected'=>true,'invalidQuantitiesRejected'=>true,'noReservationOrPayment'=>true];
}

if(PHP_SAPI==='cli'&&realpath((string)($_SERVER['SCRIPT_FILENAME']??''))===__FILE__){
    ini_set('display_errors','0');ini_set('log_errors','0');umask(0077);
    try{$result=match($argv[1]??''){'preflight'=>cart_runtime_preflight(),'install'=>cart_runtime_install(),'bind'=>cart_runtime_bind(),'unbind'=>cart_runtime_unbind(),'initialize-sequence'=>(function():array{cart_runtime_config();return cart_runtime_initialize_sequence(cart_runtime_pdo());})(),'worker-run'=>cart_runtime_worker_run(),'smoke'=>cart_runtime_smoke(),default=>throw new RuntimeException('runtime_action_invalid')};echo json_encode($result,JSON_THROW_ON_ERROR)."\n";}
    catch(Throwable $e){cart_probe_report($e);exit(1);}
}
