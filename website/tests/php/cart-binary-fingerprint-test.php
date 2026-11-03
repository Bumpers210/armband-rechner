<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/scripts/cart-mysql-integration.php';
$checks = 0;
$assert = static function (bool $ok) use (&$checks): void {
    if (!$ok) { throw new RuntimeException('binary_fingerprint_failed'); }
    $checks++;
};
$row = ['id' => 1, 'payload' => "\0\xff\xfe\x80binary", 'optional' => null];
$hash = cart_probe_row_hash($row);
$assert(preg_match('/^[a-f0-9]{64}$/', $hash) === 1);
$assert($hash === cart_probe_row_hash(['optional' => null, 'payload' => $row['payload'], 'id' => 1]));
$assert($hash !== cart_probe_row_hash([...$row, 'payload' => "\0\xff\xfe\x81binary"]));
$assert(cart_probe_row_hash(['value' => '1']) !== cart_probe_row_hash(['value' => 1]));
$assert(cart_probe_row_hash(['value' => '']) !== cart_probe_row_hash(['value' => null]));
try { cart_probe_row_hash(['value' => ['stringBytesBase64' => 'YQ==']]); throw new RuntimeException('unsupported_value_accepted'); }
catch (RuntimeException $error) { $assert($error->getMessage() === 'unsupported_sql_value'); }
echo 'PASS binary SQL fingerprints: ' . $checks . " assertions\n";
