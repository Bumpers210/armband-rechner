<?php
declare(strict_types=1);
require_once __DIR__ . '/cart-mysql-integration.php';
require_once dirname(__DIR__) . '/test-api-private/program/cart-public.php';

// Fault injection is confined to this private test runner, never Runtime code.
final class CartFaultState
{
    public function __construct(public string $pattern, public string $marker) {}
}

final class CartFaultStatement extends PDOStatement
{
    protected function __construct(private CartFaultState $fault) {}
    public function execute(?array $params = null): bool
    {
        $result = parent::execute($params);
        if (str_contains($this->queryString, $this->fault->pattern)) {
            cart_fault_marker($this->fault->marker);
            // Parent kills this process while its transaction remains open.
            usleep(20000000);
            throw new RuntimeException('fault_parent_did_not_kill');
        }
        return $result;
    }
}

function cart_fault_marker(string $path): void
{
    cart_probe_assert(!file_exists($path) && !is_link($path), 'fault_marker_exists');
    $handle = fopen($path, 'x');
    cart_probe_assert(is_resource($handle), 'fault_marker_failed');
    chmod($path, 0600);
    fwrite($handle, 'ready'); fclose($handle);
}

function cart_fault_wait(string $path, float $seconds = 10): void
{
    $deadline = microtime(true) + $seconds;
    do {
        clearstatcache(true, $path);
        if (is_file($path) && !is_link($path)) { return; }
        usleep(20000);
    } while (microtime(true) < $deadline);
    throw new RuntimeException('fault_marker_timeout');
}

function cart_fault_child(string $configuration, string $gate): void
{
    $config = cart_probe_config($configuration);
    cart_probe_assert($config['source']['database'] === 'dbs15962244'
        && $config['restore']['database'] === 'dbs15962566', 'test_identity_mismatch');
    $real = realpath($gate);
    cart_probe_assert(is_string($real) && $real === $gate && !is_link($gate)
        && preg_match('#^/home/www/carmaja-private-test/cart-e2e-[A-Za-z0-9-]+/website/scripts/fault-[a-f0-9]{16}\.json$#', $real) === 1
        && (fileperms($real) & 0777) === 0600, 'fault_gate_invalid');
    $job = json_decode((string) file_get_contents($gate), true, 32, JSON_THROW_ON_ERROR);
    cart_probe_assert(($job['configuration'] ?? '') === realpath($configuration)
        && ($job['deadline'] ?? 0) > time(), 'fault_gate_expired');
    foreach (['marker','peer'] as $field) {
        if (!isset($job[$field])) { continue; }
        cart_probe_assert(dirname($job[$field]) === dirname($gate)
            && preg_match('/^fault-[a-f0-9]{16}\.ready$/', basename($job[$field])) === 1, 'fault_path_invalid');
    }
    $pdo = cart_probe_connect($config['source'], $config);
    $commerce = new CarmajaCartCommerce($pdo);
    if (isset($job['pattern'])) {
        $pdo->setAttribute(PDO::ATTR_STATEMENT_CLASS, [CartFaultStatement::class, [new CartFaultState($job['pattern'], $job['marker'])]]);
    }
    switch ($job['mode']) {
        case 'reserve':
            $commerce->reserveCart($job['owner'], $job['body'], $job['key']);
            cart_fault_marker($job['marker']); usleep(20000000);
            break;
        case 'finalize':
            $commerce->finalizePayment($job['reservation']['snapshot']['checkoutId'], cart_probe_payment($job['reservation'], 'card', 'succeeded'));
            cart_fault_marker($job['marker']); usleep(20000000);
            break;
        case 'deadlock':
            $attempts = 0;
            $commerce->transaction(function (PDO $db) use ($job, &$attempts): void {
                $attempts++;
                $query = $db->prepare('SELECT product_id FROM commerce_inventory WHERE product_id=? FOR UPDATE');
                $query->execute([$job['first']]); $query->fetchColumn(); $query->closeCursor();
                if ($attempts === 1) { cart_fault_marker($job['marker']); cart_fault_wait($job['peer']); }
                $query->execute([$job['second']]); $query->fetchColumn(); $query->closeCursor();
                $query = $db->prepare('UPDATE commerce_inventory SET inventory_version=inventory_version+1 WHERE product_id IN (?,?)');
                $query->execute([$job['first'], $job['second']]);
            });
            echo 'committed:' . $attempts . "\n";
            break;
        case 'lease':
            cart_probe_assert($commerce->claimWorkerLease('cart-fault-worker', 'fault-old-owner', 2), 'fault_lease_failed');
            cart_fault_marker($job['marker']); usleep(20000000);
            break;
        default: throw new RuntimeException('fault_mode_invalid');
    }
}

function cart_fault_spawn(string $configuration, array $job, array &$jobs): array
{
    $prefix = __DIR__ . '/fault-' . bin2hex(random_bytes(8));
    $gate = $prefix . '.json';
    $marker = $job['marker'] ?? $prefix . '.ready';
    $job = [...$job, 'marker'=>$marker, 'configuration'=>realpath($configuration), 'deadline'=>time()+45];
    cart_probe_assert(file_put_contents($gate, json_encode($job, JSON_THROW_ON_ERROR), LOCK_EX) !== false, 'fault_gate_write_failed');
    chmod($gate, 0600);
    $process = proc_open([PHP_BINARY, '-d', 'display_errors=0', '-d', 'log_errors=0', __FILE__, '--fault-client', $configuration, $gate],
        [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']], $pipes);
    cart_probe_assert(is_resource($process), 'fault_client_start_failed');
    fclose($pipes[0]);
    $record = ['process'=>$process,'pipes'=>$pipes,'gate'=>$gate,'marker'=>$marker];
    $jobs[] = $record;
    return $record;
}

function cart_fault_kill(array $job): void
{
    cart_fault_wait($job['marker']);
    cart_probe_assert(proc_get_status($job['process'])['running'], 'fault_client_not_running');
    cart_probe_assert(proc_terminate($job['process'], 9), 'fault_kill_failed');
    foreach ([1,2] as $index) { stream_get_contents($job['pipes'][$index]); fclose($job['pipes'][$index]); }
    $exit = proc_close($job['process']);
    cart_probe_assert($exit !== 0, 'fault_kill_not_observed');
}

function cart_fault_tests(CarmajaCartCommerce $commerce, PDO $source, array $config, string $configuration): void
{
    $root = realpath(__DIR__);
    cart_probe_assert(is_string($root) && preg_match('#^/home/www/carmaja-private-test/cart-e2e-[A-Za-z0-9-]+/website/scripts$#', $root) === 1,
        'isolated_fault_stage_required');
    // Spawn gates are inside this verified private stage, not any Webroot.
    $jobs = [];
    $blocker = null;
    try {
        foreach (['CART-FAULT-A','CART-FAULT-B'] as $id) {
            $product = ['productId'=>$id,'productModelVersion'=>4,'productVersion'=>1,'sourceHash'=>str_repeat('f',64),
                'title'=>'Kuenstlicher Crash-Test','description'=>'Test','materials'=>['Test'],'metalElements'=>[],
                'braceletSizeCm'=>18,'pearlSizeMm'=>8,'careInstructions'=>[],'images'=>[['src'=>'/images/products/test/01.jpg']],
                'priceMinor'=>2800,'currency'=>'eur','notices'=>[]];
            $commerce->projectCollectionProduct($product, 'publish', 'fault-publish-'.$id, hash('sha256',$id));
            $commerce->adjustInventory(['productId'=>$id,'targetOnHand'=>20,'expectedInventoryVersion'=>0,'reason'=>'initial_count',
                'correlationId'=>'fault-initial-'.$id,'idempotencyKey'=>'fault-initial-'.$id], 'test-operator');
        }
        $items = [['productId'=>'CART-FAULT-B','quantity'=>2],['productId'=>'CART-FAULT-A','quantity'=>3]];
        $owner = cart_probe_session($commerce, 'fault-reserve');
        $commerce->replaceCart($owner, ['items'=>$items,'expectedCartVersion'=>0], 'fault-cart-replace');
        $quote = $commerce->quoteCart($owner, ['shippingMethodId'=>'maxibrief','amountMinor'=>270,'currency'=>'eur','country'=>'DE'], 'cart-e2e-legal');
        $body = ['expectedCartVersion'=>$quote['cartVersion'],'checkoutContextToken'=>$quote['checkoutContextToken']];
        $jobData = ['mode'=>'reserve','owner'=>$owner,'body'=>$body,'key'=>'fault-reserve-checkout'];
        $before = cart_probe_fingerprint($source);
        $job = cart_fault_spawn($configuration, [...$jobData,'pattern'=>'INSERT INTO reservations'], $jobs);
        cart_fault_kill($job);
        cart_probe_assert($before === cart_probe_fingerprint($source), 'crash_reservation_partial_commit');
        echo "PASS SIGKILL during first reservation: complete rollback, no partial checkout\n";

        $job = cart_fault_spawn($configuration, $jobData, $jobs);
        cart_fault_kill($job);
        $persisted = cart_probe_fingerprint($source);
        $again = $commerce->reserveCart($owner, $body, 'fault-reserve-checkout');
        cart_probe_assert($again['reused'] && $persisted === cart_probe_fingerprint($source), 'crash_committed_reservation_replay');
        $checkoutId = $again['checkout']['checkout_id'];
        $commerce->recordStripeCreationOutcome($checkoutId, 'unknown');
        $commerce->releaseExpiredReservation($checkoutId);
        cart_probe_assert((int) $source->query("SELECT SUM(quantity) FROM reservations WHERE checkout_id='$checkoutId' AND blocks_stock=1")->fetchColumn() === 5,
            'unknown_provider_released_stock');
        $commerce->recordStripeCreationOutcome($checkoutId, 'failed');
        cart_probe_assert($source->query("SELECT state FROM checkout_sagas WHERE checkout_id='$checkoutId'")->fetchColumn() === 'manual_review',
            'unknown_provider_downgraded');
        echo "PASS SIGKILL after reservation commit: idempotent restart; unknown provider outcome remains blocked\n";

        $failed = cart_probe_reserve($commerce, cart_probe_session($commerce,'fault-known-failure'), $items, 'fault-known-failure');
        $commerce->recordStripeCreationOutcome($failed['snapshot']['checkoutId'], 'failed');
        $id = $failed['snapshot']['checkoutId'];
        cart_probe_assert($source->query("SELECT state FROM checkout_sagas WHERE checkout_id='$id'")->fetchColumn() === 'failed'
            && (int) $source->query("SELECT COUNT(*) FROM reservations WHERE checkout_id='$id' AND (state<>'released' OR blocks_stock<>0)")->fetchColumn() === 0,
            'confirmed_creation_failure_not_atomic');
        echo "PASS proven non-created session: failed checkout and all reservations released\n";

        $timeoutOwner = cart_probe_session($commerce, 'fault-provider-timeout');
        $commerce->replaceCart($timeoutOwner, ['items'=>$items,'expectedCartVersion'=>0], 'fault-provider-cart');
        $timeoutQuote = $commerce->quoteCart($timeoutOwner, ['shippingMethodId'=>'maxibrief','amountMinor'=>270,'currency'=>'eur','country'=>'DE'], 'cart-e2e-legal');
        $timeoutClient = (object) ['checkout'=>(object) ['sessions'=>new class($source) {
            public bool $calledOutsideTransaction = false;
            public function __construct(private PDO $database) {}
            public function create(array $parameters, array $options): never
            {
                $this->calledOutsideTransaction = !$this->database->inTransaction();
                throw new RuntimeException('artificial_transport_timeout');
            }
        }]];
        $stripeConfig = ['stripeSdkVersion'=>CARMAJA_STRIPE_SDK_VERSION,'stripeApiVersion'=>CARMAJA_STRIPE_API_VERSION,
            'stripeWebhookApiVersion'=>CARMAJA_STRIPE_WEBHOOK_API_VERSION,'stripePaymentMethodTypes'=>CARMAJA_STRIPE_PAYMENT_METHOD_TYPES,
            'stripeSecretKey'=>'sk_test_artificial_not_a_real_key','stripeSuccessUrl'=>'https://test.carmaja-perlen.de/shop/success/',
            'stripeCancelUrl'=>'https://test.carmaja-perlen.de/shop/cancel/'];
        try {
            carmaja_cart_start($commerce, new CarmajaStripeGateway($stripeConfig, $timeoutClient), $stripeConfig, $timeoutOwner,
                ['expectedCartVersion'=>$timeoutQuote['cartVersion'],'checkoutContextToken'=>$timeoutQuote['checkoutContextToken']], 'fault-provider-timeout');
            throw new RuntimeException('provider_timeout_not_observed');
        } catch (CarmajaCommerceException $error) { cart_probe_assert($error->errorCode==='checkout_manual_review','provider_timeout_not_reviewed'); }
        $timedOut = $commerce->findCheckoutByIdempotency('fault-provider-timeout');
        $id = $timedOut['checkout_id'];
        cart_probe_assert($timeoutClient->checkout->sessions->calledOutsideTransaction && $timedOut['state']==='manual_review'
            && (int) $source->query("SELECT SUM(quantity) FROM reservations WHERE checkout_id='$id' AND blocks_stock=1")->fetchColumn()===5,
            'provider_timeout_not_safe');
        echo "PASS injected Stripe transport timeout through cart start: no held transaction, all quantities remain blocked\n";

        $reservation = cart_probe_reserve($commerce, cart_probe_session($commerce,'fault-finalize'), $items, 'fault-finalize');
        $id = $reservation['snapshot']['checkoutId'];
        $commerce->recordStripeCreationOutcome($id,'created','cs_test_'.$id);
        $before = cart_probe_fingerprint($source);
        $job = cart_fault_spawn($configuration, ['mode'=>'finalize','reservation'=>$reservation,'pattern'=>"'shop_sale'"], $jobs);
        cart_fault_kill($job);
        cart_probe_assert($before === cart_probe_fingerprint($source), 'crash_finalization_partial_commit');
        echo "PASS SIGKILL after first inventory debit: stock, order, sequence and outboxes rolled back together\n";

        $blocker = cart_probe_connect($config['source'],$config);
        $blocker->beginTransaction();
        $blocker->query("SELECT product_id FROM commerce_inventory WHERE product_id='CART-FAULT-B' FOR UPDATE")->closeCursor();
        $started = microtime(true);
        try {
            $commerce->finalizePayment($id, cart_probe_payment($reservation,'card','succeeded'));
            throw new RuntimeException('forced_timeout_not_observed');
        } catch (PDOException $error) { cart_probe_assert((int) ($error->errorInfo[1] ?? 0) === 1205, 'unexpected_timeout_code'); }
        finally { $blocker->rollBack(); $blocker = null; }
        cart_probe_assert(microtime(true)-$started < 20 && !$source->inTransaction()
            && $before === cart_probe_fingerprint($source), 'timeout_partial_commit');
        echo "PASS held inventory row: bounded lock-timeout retry, full rollback, no order or stock mutation\n";
        $job = cart_fault_spawn($configuration,['mode'=>'finalize','reservation'=>$reservation],$jobs);
        cart_fault_kill($job);
        $before = cart_probe_fingerprint($source);
        $result = $commerce->finalizePayment($id, cart_probe_payment($reservation,'card','succeeded'));
        cart_probe_assert($result['status']==='confirmed' && $before===cart_probe_fingerprint($source), 'committed_order_replay_changed_data');
        echo "PASS SIGKILL after order commit: replay keeps exactly one order, sequence, sale and outbox\n";

        $firstMarker = $root.'/fault-'.bin2hex(random_bytes(8)).'.ready';
        $secondMarker = $root.'/fault-'.bin2hex(random_bytes(8)).'.ready';
        $beforeA = $commerce->inventory('CART-FAULT-A'); $beforeB = $commerce->inventory('CART-FAULT-B');
        $first = cart_fault_spawn($configuration,['mode'=>'deadlock','first'=>'CART-FAULT-A','second'=>'CART-FAULT-B','marker'=>$firstMarker,'peer'=>$secondMarker],$jobs);
        $second = cart_fault_spawn($configuration,['mode'=>'deadlock','first'=>'CART-FAULT-B','second'=>'CART-FAULT-A','marker'=>$secondMarker,'peer'=>$firstMarker],$jobs);
        $attempts = [];
        foreach ([$first,$second] as $job) {
            $output=stream_get_contents($job['pipes'][1]); stream_get_contents($job['pipes'][2]);
            fclose($job['pipes'][1]); fclose($job['pipes'][2]);
            cart_probe_assert(proc_close($job['process'])===0 && preg_match('/^committed:([1-4])\s*$/',$output,$match)===1,'deadlock_client_failed');
            $attempts[]=(int)$match[1];
        }
        cart_probe_assert(max($attempts)>1 && $commerce->inventory('CART-FAULT-A')['inventoryVersion']===$beforeA['inventoryVersion']+2
            && $commerce->inventory('CART-FAULT-B')['inventoryVersion']===$beforeB['inventoryVersion']+2,'deadlock_retry_not_exactly_once');
        echo "PASS forced inverted-lock deadlock: victim retries, exactly two commits, no duplicate mutation\n";

        $job = cart_fault_spawn($configuration,['mode'=>'lease'],$jobs); cart_fault_kill($job);
        cart_probe_assert(!$commerce->claimWorkerLease('cart-fault-worker','fault-new-owner',2),'crashed_worker_lease_stolen');
        usleep(2100000);
        cart_probe_assert($commerce->claimWorkerLease('cart-fault-worker','fault-new-owner',30),'expired_worker_lease_not_recovered');
        $commerce->releaseWorkerLease('cart-fault-worker','fault-old-owner',true);
        cart_probe_assert(!$commerce->claimWorkerLease('cart-fault-worker','fault-third-owner',30),'old_worker_released_new_lease');
        $commerce->releaseWorkerLease('cart-fault-worker','fault-new-owner',true);
        echo "PASS SIGKILL worker: lease blocks takeover until expiry; stale owner cannot release new lease\n";
    } finally {
        if ($blocker instanceof PDO && $blocker->inTransaction()) { $blocker->rollBack(); }
        foreach ($jobs as $job) {
            if (is_resource($job['process'])) { proc_terminate($job['process'],9); proc_close($job['process']); }
            foreach ($job['pipes'] as $pipe) { if (is_resource($pipe)) { fclose($pipe); } }
            foreach (['gate','marker'] as $file) { if (is_file($job[$file])) { unlink($job[$file]); } }
            cart_probe_assert(!file_exists($job['gate']) && !file_exists($job['marker']), 'fault_artifact_cleanup_failed');
        }
    }
}

if (PHP_SAPI === 'cli' && realpath((string)($_SERVER['SCRIPT_FILENAME']??'')) === __FILE__) {
    try {
        cart_probe_assert(PHP_VERSION_ID>=80400 && PHP_VERSION_ID<80500,'php84_required');
        if (($argv[1]??'')==='--fault-client') { cart_fault_child($argv[2]??'', $argv[3]??''); }
        else { cart_probe_main($argv[1]??'/home/www/carmaja-private-test/shop-cart-test.json',false,null,'cart_fault_tests'); }
    } catch (Throwable $error) { cart_probe_report($error); exit(1); }
}
