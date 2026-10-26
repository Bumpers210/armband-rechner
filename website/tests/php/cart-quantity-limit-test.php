<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/test-api-private/program/cart-public.php';

// Execute the real commerce methods against scripted PDO replies. No server,
// database, file, provider or real customer is accessed by this boundary test.
final class LimitPdo extends PDO
{
    public array $writes = [];
    public array $reads = [];
    public ?array $existing = null;
    private bool $active = false;
    public int $rollbacks = 0;
    public function __construct(public array $items, public array $snapshot) {}
    public function setAttribute(int $attribute, mixed $value): bool { return true; }
    public function beginTransaction(): bool { $this->active = true; return true; }
    public function inTransaction(): bool { return $this->active; }
    public function commit(): bool { $this->active = false; return true; }
    public function rollBack(): bool { $this->active = false; $this->rollbacks++; return true; }
    public function prepare(string $query, array $options = []): PDOStatement|false { return new LimitStatement($this, $query); }
    public function reply(string $query, array $args): array
    {
        $sql = preg_replace('/\s+/', ' ', trim($query));
        if (!str_starts_with($sql, 'SELECT ')) {
            $this->writes[] = [$sql, $args];
            if (!str_starts_with($sql, 'INSERT INTO ')) { throw new RuntimeException('Unexpected write'); }
            return [];
        }
        $this->reads[] = $sql;
        if (str_contains($sql, 'FROM shop_sessions')) { return [['session_hash'=>'artificial-owner']]; }
        if (str_contains($sql, 'FROM shop_carts')) { return [['cart_version'=>1, 'items'=>json_encode($this->items), 'price_reference'=>null]]; }
        if (str_contains($sql, 'JOIN payments')) { return $this->existing === null ? [] : [$this->existing]; }
        if (str_contains($sql, 'FROM checkout_sagas WHERE session_hash')) { return []; }
        if (str_contains($sql, 'FROM cart_quotes')) { return [['cart_version'=>1, 'snapshot'=>json_encode($this->snapshot)]]; }
        if (str_contains($sql, 'FROM legal_bundles')) { return [['bundle_hash'=>str_repeat('a',64), 'status'=>'approved']]; }
        if (str_contains($sql, 'FROM commerce_products')) {
            return [['product_id'=>$args[0], 'name'=>'Artificial', 'price_minor'=>2800, 'currency'=>'eur',
                'product_version'=>1, 'source_hash'=>str_repeat('b',64), 'sales_enabled'=>1,
                'sales_model'=>'stocked_collection', 'notice_snapshot'=>null, 'public_snapshot'=>null]];
        }
        if (str_contains($sql, 'FROM commerce_inventory')) { return [['on_hand'=>50, 'quantity_confirmed'=>1, 'inventory_version'=>1]]; }
        if (str_contains($sql, 'FROM reservations')) { return []; }
        if (str_contains($sql, 'FROM checkout_sagas WHERE checkout_id')) { return [['checkout_id'=>$args[0]]]; }
        throw new RuntimeException('Unexpected query');
    }
}
final class LimitStatement extends PDOStatement
{
    private array $rows = [];
    public function __construct(private LimitPdo $database, private string $sql) {}
    public function execute(?array $params = null): bool { $this->rows = $this->database->reply($this->sql, $params ?? []); return true; }
    public function fetch(int $mode = PDO::FETCH_DEFAULT, int $cursorOrientation = PDO::FETCH_ORI_NEXT, int $cursorOffset = 0): mixed { return array_shift($this->rows) ?? false; }
    public function fetchAll(int $mode = PDO::FETCH_DEFAULT, mixed ...$args): array { return $this->rows; }
}
function limit_fixture(array $amounts, ?array $quoteAmounts = null): LimitPdo {
    $items = static fn ($values) => array_map(static fn ($quantity,$i) => ['productId'=>'product-000'.($i+1),'quantity'=>$quantity], $values, array_keys($values));
    $lines = array_map(static fn ($item) => [...$item,'priceMinor'=>2800,'currency'=>'eur','productVersion'=>1,'sourceHash'=>str_repeat('b',64)], $items($quoteAmounts ?? $amounts));
    return new LimitPdo($items($amounts), ['items'=>$lines,'legalBundleId'=>'artificial-legal','legalBundleHash'=>str_repeat('a',64),
        'shippingSnapshot'=>['amountMinor'=>270],'totalMinor'=>carmaja_cart_total($lines)]);
}
function limit_assert(bool $ok, string $label): void { if (!$ok) { throw new RuntimeException($label); } echo "[OK] $label\n"; }
function limit_reject(callable $action, LimitPdo $pdo, string $label): void {
    try { $action(); throw new RuntimeException('Expected rejection'); }
    catch (CarmajaCommerceException $error) { limit_assert($error->errorCode === 'cart_quantity_limit' && $error->httpStatus === 422, $label); }
    limit_assert($pdo->writes === [], 'No mutation: '.$label);
}
$body = ['expectedCartVersion'=>1, 'checkoutContextToken'=>str_repeat('t',43)];
$key = 'artificial-idempotency'; $owner = 'artificial-owner';

$pdo = limit_fixture([6,4]); $commerce = new CarmajaCartCommerce($pdo);
limit_assert($commerce->readCart($owner)['canCheckout'], '6+4 remains buyable');
$reservation = $commerce->reserveCart($owner, $body, $key);
$reservations = array_values(array_filter($pdo->writes, static fn ($write) => str_starts_with($write[0], 'INSERT INTO reservations')));
limit_assert(count($reservations) === 2 && $reservations[0][1][3] === 6 && $reservations[1][1][3] === 4, 'All ten reserved together through real reserveCart');
limit_assert($reservation['snapshot']['totalMinor'] === 28270, 'Shipping once, unchanged');

$pdo = limit_fixture([6,5]); $commerce = new CarmajaCartCommerce($pdo);
$cart = $commerce->readCart($owner);
limit_assert(!$cart['canCheckout'] && $cart['quantityLimitExceeded'] && count($cart['items']) === 2 && $cart['items'][1]['quantity'] === 5, 'Old oversized cart visible without silent reduction');
limit_reject(fn () => $commerce->quoteCart($owner, ['amountMinor'=>270], 'artificial-legal'), $pdo, 'No quote for oversized cart');
limit_reject(fn () => $commerce->reserveCart($owner, $body, $key), $pdo, 'No checkout from older oversized cart');
limit_assert($pdo->rollbacks === 1, 'Rejected reservation rolls back');

$pdo = limit_fixture([6,4], [6,5]); $commerce = new CarmajaCartCommerce($pdo);
limit_reject(fn () => $commerce->reserveCart($owner, $body, $key), $pdo, 'Older or manipulated oversized quote rejected independently');
limit_assert(!array_filter($pdo->reads, static fn ($sql) => str_contains($sql, 'FROM commerce_products') || str_contains($sql, 'FROM commerce_inventory')), 'Quote rejected before product and inventory row locks');

$pdo = limit_fixture([6,4]); $commerce = new CarmajaCartCommerce($pdo);
limit_reject(fn () => $commerce->replaceCart($owner, ['expectedCartVersion'=>1,'items'=>[['productId'=>'product-0001','quantity'=>11]]], $key), $pdo, 'Manipulated PUT rejected before transaction');
limit_assert($pdo->reads === [] && !$pdo->inTransaction(), 'Rejected PUT does not reach persistence');

$pdo = limit_fixture([6,5]); $commerce = new CarmajaCartCommerce($pdo);
$pdo->existing = ['checkout_id'=>'existing-checkout','session_hash'=>$owner,
    'request_hash'=>carmaja_commerce_request_hash(['owner'=>$owner,'body'=>$body]), 'payment_status'=>'processing'];
limit_assert($commerce->reserveCart($owner, $body, $key)['reused'] === true && $pdo->writes === [], 'Existing idempotent payment remains untouched above new limit');
echo "PASS cart quantity boundary integration with scripted PDO (no real database)\n";
