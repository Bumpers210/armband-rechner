<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/test-api-private/program/cart-commerce.php';

final class CartTransactionTestPdo extends PDO
{
    public int $begins = 0;
    public int $commits = 0;
    public int $rollbacks = 0;
    private bool $active = false;
    public function __construct() {}
    public function setAttribute(int $attribute, mixed $value): bool { return true; }
    public function inTransaction(): bool { return $this->active; }
    public function beginTransaction(): bool { $this->begins++; $this->active = true; return true; }
    public function commit(): bool { $this->commits++; $this->active = false; return true; }
    public function rollBack(): bool { $this->rollbacks++; $this->active = false; return true; }
}

function cart_transaction_error(int $code): PDOException
{
    $error = new PDOException('synthetic database error');
    $error->errorInfo = [$code === 1213 ? '40001' : 'HY000', $code, 'synthetic'];
    return $error;
}

function cart_transaction_check(bool $condition, string $name): void
{
    if (!$condition) { throw new RuntimeException($name); }
    echo 'PASS ' . $name . "\n";
}

foreach ([1205, 1213] as $code) {
    $pdo = new CartTransactionTestPdo();
    $commerce = new CarmajaCartCommerce($pdo);
    $calls = 0;
    $result = $commerce->transaction(function () use (&$calls, $code): string {
        if (++$calls <= 2) { throw cart_transaction_error($code); }
        return 'complete';
    });
    cart_transaction_check($result === 'complete' && $pdo->begins === 3 && $pdo->rollbacks === 2 && $pdo->commits === 1,
        'complete rollback and bounded retry for ' . $code);
}

$pdo = new CartTransactionTestPdo();
$commerce = new CarmajaCartCommerce($pdo);
try { $commerce->transaction(fn () => throw cart_transaction_error(1213)); throw new RuntimeException('retry_limit_missing'); }
catch (PDOException) {
    cart_transaction_check($pdo->begins === 4 && $pdo->rollbacks === 4 && $pdo->commits === 0, 'retry limit: four attempts, no partial commit');
}

$pdo = new CartTransactionTestPdo();
$commerce = new CarmajaCartCommerce($pdo);
try { $commerce->transaction(fn () => throw cart_transaction_error(1062)); throw new RuntimeException('unexpected_retry'); }
catch (PDOException) {
    cart_transaction_check($pdo->begins === 1 && $pdo->rollbacks === 1 && $pdo->commits === 0, 'constraint errors are not retried');
}

$pdo = new CartTransactionTestPdo();
$commerce = new CarmajaCartCommerce($pdo);
$caught = false;
try { $commerce->transaction(fn () => $commerce->transaction(fn () => throw cart_transaction_error(1213))); }
catch (PDOException) {
    $caught = true;
    cart_transaction_check($pdo->begins === 4 && $pdo->rollbacks === 4 && $pdo->commits === 0, 'nested transactions retry only at outer boundary');
}
cart_transaction_check($caught, 'nested retry exhaustion remains an error');

$pdo = new CartTransactionTestPdo();
$commerce = new CarmajaCartCommerce($pdo);
$caught = false;
try { $commerce->transaction(fn () => throw new CarmajaCommerceException('inventory_reserved', 'synthetic', 409)); }
catch (CarmajaCommerceException) {
    $caught = true;
    cart_transaction_check($pdo->begins === 1 && $pdo->rollbacks === 1 && $pdo->commits === 0, 'domain errors are not retried');
}
cart_transaction_check($caught, 'domain rejection remains an error');
