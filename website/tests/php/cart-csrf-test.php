<?php
declare(strict_types=1);
define('CARMAJA_BOOTSTRAP_NO_RUN', true);
require_once dirname(__DIR__, 2) . '/test-api-private/program/cart-public.php';
$raw = carmaja_shop_token(); $now = 1791740000;
$old = carmaja_cart_csrf_token($raw, $now);
$new = carmaja_cart_csrf_token($raw, $now + 60);
carmaja_cart_verify_csrf($old, $raw, $now + 120);
carmaja_cart_verify_csrf($new, $raw, $now + 120);
carmaja_cart_verify_csrf($old, $raw, $now + 7199);
$cases = [[$old, $raw, $now + 7200], [$old, carmaja_shop_token(), $now + 120],
    [substr($old, 0, -1) . ($old[-1] === 'a' ? 'b' : 'a'), $raw, $now + 120],
    [carmaja_shop_token(), $raw, $now + 120], [$new, $raw, $now], ['', $raw, $now]];
foreach ($cases as [$token, $session, $at]) {
    try { carmaja_cart_verify_csrf($token, $session, $at); throw new RuntimeException('unsafe_token_accepted'); }
    catch (CarmajaCommerceException $e) { if ($e->httpStatus !== 403) { throw $e; } }
}
if ($old === $raw || $old === $new || strlen($old) > 128) { throw new RuntimeException('csrf_contract'); }
echo "PASS cart multitab CSRF: overlapping tokens, session binding, tampering and exact two-hour expiry\n";
