<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/test-api-private/program/cart-commerce.php';

// Local PDO contract double: no connection, database, credentials or network.
final class CartSalesPdo extends PDO
{
    public array $statements = [];
    public int $commits = 0;
    public int $rollbacks = 0;
    public function __construct() {}
    public function setAttribute(int $attribute, mixed $value): bool { return true; }
    public function beginTransaction(): bool { return true; }
    public function inTransaction(): bool { return true; }
    public function commit(): bool { $this->commits++; return true; }
    public function rollBack(): bool { $this->rollbacks++; return true; }
    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        return new CartSalesStatement($this, $query);
    }
}
final class CartSalesStatement extends PDOStatement
{
    public function __construct(private CartSalesPdo $pdo, private string $sql) {}
    public function execute(?array $params = null): bool { $this->pdo->statements[] = [$this->sql, $params]; return true; }
    public function fetch(int $mode = PDO::FETCH_DEFAULT, int $cursorOrientation = PDO::FETCH_ORI_NEXT, int $cursorOffset = 0): mixed { return false; }
}
function permission_assert(bool $ok, string $name): void
{
    if (!$ok) { throw new RuntimeException($name); }
    echo 'PASS ', $name, "\n";
}

$base = ['productId' => 'artificial-permission', 'productVersion' => 1, 'sourceHash' => str_repeat('a',64),
    'title' => 'Synthetic permission product', 'priceMinor' => 2800, 'currency' => 'eur'];
foreach ([3,4] as $model) {
    foreach ([false,true] as $permission) {
        foreach (['publish','archive','restore'] as $action) {
            $product = [...$base, 'productModelVersion' => $model, 'salesEnabled' => $permission];
            $expected = $action !== 'archive' && ($model === 3 || $permission);
            $pdo = new CartSalesPdo();
            $result = (new CarmajaCommercePdo($pdo))->projectCollectionProduct($product, $action, 'permission-test', str_repeat('b',64));
            $upserts = array_values(array_filter($pdo->statements, fn ($s) => str_contains($s[0], 'INSERT INTO commerce_products')));
            permission_assert(count($upserts) === 1 && $upserts[0][1][12] === (int) $expected
                && $result['available'] === $expected && $pdo->commits === 1,
                'PDO projection model ' . $model . ' permission ' . (int)$permission . ' ' . $action);
            $memory = new CarmajaCommerceMemory();
            $first = $memory->projectCollectionProduct($product, $action, 'permission-test', str_repeat('b',64));
            permission_assert($first['available'] === $expected && $memory->products[$base['productId']]['salesEnabled'] === $expected
                && $memory->inventory === [] && $first === $memory->projectCollectionProduct($product, $action, 'permission-test', str_repeat('b',64)),
                'Memory projection, no inventory mutation and replay model ' . $model . ' ' . $action);
        }
    }
}
foreach ([null,0,1,'false'] as $invalid) {
    $pdo = new CartSalesPdo();
    try {
        (new CarmajaCommercePdo($pdo))->projectCollectionProduct([...$base, 'productModelVersion' => 4, 'salesEnabled' => $invalid],
            'publish', 'permission-invalid', str_repeat('b',64));
        throw new RuntimeException('invalid permission accepted');
    } catch (CarmajaCommerceException $e) {
        permission_assert($e->errorCode === 'projection_product_invalid' && $pdo->commits === 0 && $pdo->rollbacks === 1
            && count($pdo->statements) === 1, 'invalid permission rejected before product write: ' . get_debug_type($invalid));
    }
}
echo "28 sales permission projection checks passed; no MySQL integration claimed.\n";
