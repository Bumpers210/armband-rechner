<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/scripts/cart-mysql-integration.php';

function cart_schema_check(bool $condition, string $name): void
{
    if (!$condition) { throw new RuntimeException($name); }
    echo 'PASS ' . $name . "\n";
}

$original = 'CREATE TABLE t (label varchar(500) COLLATE utf8mb4_general_ci DEFAULT NULL, quantity int NOT NULL, KEY idx (label), CHECK (quantity > 0)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4';
$restored = str_replace('COLLATE utf8mb4_general_ci', 'CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci', $original);
cart_schema_check(cart_probe_schema_text($original) === cart_probe_schema_text($restored), 'redundant charset display normalized');
foreach ([
    'collation difference' => str_replace('utf8mb4_general_ci', 'utf8mb4_unicode_ci', $restored),
    'column type difference' => str_replace('quantity int', 'quantity bigint', $restored),
    'default difference' => str_replace('DEFAULT NULL', "DEFAULT 'other'", $restored),
    'index difference' => str_replace('KEY idx (label)', 'KEY idx (quantity)', $restored),
    'constraint difference' => str_replace('quantity > 0', 'quantity >= 0', $restored),
] as $name => $changed) {
    cart_schema_check(cart_probe_schema_text($original) !== cart_probe_schema_text($changed), $name . ' remains detectable');
}
cart_schema_check(cart_probe_schema_text('ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4')
    === cart_probe_schema_text('ENGINE=InnoDB AUTO_INCREMENT=7 DEFAULT CHARSET=utf8mb4'), 'runtime auto-increment excluded from DDL comparison');
