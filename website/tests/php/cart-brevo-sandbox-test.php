<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/scripts/cart-brevo-sandbox.php';
$count = 0;
$assert = static function (bool $value) use (&$count): void {
    if (!$value) { throw new RuntimeException('cart_brevo_contract_failed'); } $count++;
};
$assert(preg_match('/^cmj-test-legal-[0-9]{4}-[0-9]{2}-[0-9]{2}-v[0-9]+$/', CART_SANDBOX_LEGAL_ID) === 1);
$config = ['environment'=>'test','shopWebsiteOrigin'=>'https://test.carmaja-perlen.de',
    'brevoApiKey'=>'synthetic-not-a-key','brevoSenderEmail'=>'sender@example.invalid'];
$payload = ['orderNumber'=>'TEST-CART-0001','totalMinor'=>7770,'shipping'=>['amountMinor'=>270],
    'legal'=>['legalBundleId'=>CART_SANDBOX_LEGAL_ID], 'items'=>[
        ['name'=>'Test <A>','quantity'=>2,'priceMinor'=>2800,'currency'=>'eur','notices'=>CARMAJA_PRODUCT_NOTICES],
        ['name'=>'Test B','quantity'=>1,'priceMinor'=>1900,'currency'=>'eur','notices'=>CARMAJA_PRODUCT_NOTICES]]];
$calls = 0; $keys = [];
$client = new CarmajaBrevoClient($config, static function (array $request) use (&$calls,&$keys,$assert): array {
    $calls++; $keys[] = $request['headers']['idempotencyKey']; $html = $request['htmlContent'];
    $assert(str_contains($html,'Test &lt;A&gt;') && !str_contains($html,'Test <A>'));
    $assert(str_contains($html,'2 × 28,00') && str_contains($html,'1 × 19,00'));
    $assert(str_contains($html,'77,70') && substr_count($html,'Versand einmalig: 2,70') === 1);
    $assert(str_contains($html,htmlspecialchars(CARMAJA_PRODUCT_NOTICES[0],ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8')));
    return ['status'=>201,'body'=>'{"messageId":"synthetic-message"}'];
});
$row = ['dedupe_key'=>'cart-brevo:unit','message_type'=>'order_confirmation','recipient'=>'recipient@example.invalid',
    'payload'=>json_encode($payload,JSON_THROW_ON_ERROR)];
$assert($client->send($row)['outcome'] === 'sent');
$assert($client->send($row)['outcome'] === 'sent' && $keys[0] === $keys[1]);
$payload['legal']['legalBundleId'] = 'cart-sandbox-legal-test-only';
$row['payload'] = json_encode($payload,JSON_THROW_ON_ERROR);
$assert($client->send($row)['error'] === 'mail_content_missing' && $calls === 2);
$text = file_get_contents(dirname(__DIR__,2).'/scripts/cart-brevo-sandbox.php');
foreach (['brevo_test_already_completed','foreign_order_items_rejected','unauthorized_recipient',
    'originalPaidOrderMailE2eStillOpen','uncertain_mail_automatically_retried','manual_resend_audit_missing',
    'mail_mutated_inventory','inboxDeliveryNotYetConfirmed'] as $guard) { $assert(str_contains($text,$guard)); }
echo 'PASS cart Brevo sandbox: '.$count." assertions\n";
