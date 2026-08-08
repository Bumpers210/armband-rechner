<?php

declare(strict_types=1);

define('CARMAJA_BOOTSTRAP_NO_RUN', true);
define('CARMAJA_AP5_WORKER_NO_RUN', true);
require_once dirname(__DIR__, 2) . '/test-api-private/program/commerce-core.php';
require_once dirname(__DIR__, 2) . '/test-api-private/program/shop-admin.php';
require_once dirname(__DIR__, 2) . '/test-api-private/program/ap5-worker.php';

final class CarmajaAp5TestFailure extends RuntimeException {}

$tests = [];
$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new CarmajaAp5TestFailure($message);
    }
};
$tests['Argon2id-Hash und Verifikation'] = static function () use ($assert): void {
    $hash = carmaja_shop_admin_hash_password('Cobalt!River-2026-Long');
    $assert(str_starts_with($hash, '$argon2id$'), 'Hash ist nicht Argon2id.');
    $assert(password_verify('Cobalt!River-2026-Long', $hash), 'Passwort lässt sich nicht prüfen.');
    $assert(!password_verify('falsch', $hash), 'Falsches Passwort wurde akzeptiert.');
};
$tests['Admin-Sitzung, CSRF und Logout'] = static function () use ($assert): void {
    $memory = new CarmajaShopAdminMemory();
    $memory->addUser('Owner.Admin', 'Cobalt!River-2026-Long');
    $login = $memory->login('owner.admin', 'Cobalt!River-2026-Long');
    $assert(strlen($login['sessionToken']) >= 32, 'Sitzungstoken fehlt.');
    $assert(hash_equals($memory->sessions[hash('sha256', $login['sessionToken'])]['csrf_hash'], hash('sha256', $login['csrfToken'])), 'CSRF-Bindung fehlt.');
    $memory->revoke($login['sessionToken']);
    $assert($memory->sessions[hash('sha256', $login['sessionToken'])]['revoked_at'] !== null, 'Sitzung wurde nicht widerrufen.');
};
$tests['Login-Sperre nach fünf Fehlern'] = static function () use ($assert): void {
    $memory = new CarmajaShopAdminMemory();
    $memory->addUser('owner.admin', 'Cobalt!River-2026-Long');
    for ($i = 0; $i < CARMAJA_SHOP_ADMIN_MAX_FAILED_LOGINS; $i++) {
        try {
            $memory->login('owner.admin', 'falsch', 1000);
        } catch (CarmajaShopAdminException $error) {
            $assert($error->errorCode === 'admin_auth_failed', 'Falscher Fehlercode vor Sperre.');
        }
    }
    try {
        $memory->login('owner.admin', 'Cobalt!River-2026-Long', 1001);
        throw new CarmajaAp5TestFailure('Gesperrte Anmeldung wurde akzeptiert.');
    } catch (CarmajaShopAdminException $error) {
        $assert($error->errorCode === 'admin_login_locked', 'Sperrzeit fehlt.');
    }
};
$tests['Passwort- und Benutzervalidierung'] = static function () use ($assert): void {
    foreach (['ab', 'owner admin', 'ümlaut'] as $name) {
        try { carmaja_shop_admin_username($name); throw new CarmajaAp5TestFailure('Ungültiger Benutzername akzeptiert.'); }
        catch (CarmajaShopAdminException $error) { $assert($error->errorCode === 'admin_username_invalid', 'Falscher Benutzerfehler.'); }
    }
    try { carmaja_shop_admin_hash_password('Kurz!7'); throw new CarmajaAp5TestFailure('Kurzes Passwort akzeptiert.'); }
    catch (CarmajaShopAdminException $error) { $assert($error->errorCode === 'admin_password_invalid', 'Falscher Passwortfehler.'); }
};
$tests['Brevo-Erfolg mit Nachrichten-ID'] = static function () use ($assert): void {
    $client = new CarmajaBrevoClient([
        'brevoApiKey' => 'synthetic', 'brevoSenderEmail' => 'test@example.invalid',
    ], static function (array $request, string $key) use ($assert): array {
        $providerKey = $request['headers']['idempotencyKey'] ?? null;
        $assert($key === 'order-confirmation:synthetic', 'Lokaler Deduplizierungsschluessel veraendert.');
        $assert(is_string($providerKey)
            && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $providerKey) === 1,
            'Brevo-idempotencyKey ist keine deterministische UUIDv4.');
        return ['status' => 201, 'body' => '{"messageId":"<synthetic-1>"}'];
    });
    $result = $client->send([
        'dedupe_key' => 'order-confirmation:synthetic', 'recipient' => 'buyer@example.invalid',
        'payload' => '{"subject":"Test","htmlContent":"synthetic"}',
    ]);
    $assert($result['outcome'] === 'sent' && $result['brevoMessageId'] === '<synthetic-1>', 'Brevo-Erfolg nicht gespeichert.');
};
$tests['Brevo rendert fachliche Outboxdaten erst beim Versand'] = static function () use ($assert): void {
    $requests = [];
    $client = new CarmajaBrevoClient([
        'brevoApiKey' => 'synthetic', 'brevoSenderEmail' => 'test@example.invalid',
    ], static function (array $request) use (&$requests): array {
        $requests[] = $request;
        return ['status' => 201, 'body' => '{"messageId":"<synthetic-rendered>"}'];
    });
    $result = $client->send([
        'dedupe_key' => 'order-confirmation:rendered',
        'message_type' => 'order_confirmation',
        'recipient' => 'buyer@example.invalid',
        'payload' => '{"orderNumber":"TEST-2026-000001"}',
    ]);
    $assert($result['outcome'] === 'sent', 'Fachliche Bestellmail wurde nicht versendet.');
    $assert(str_contains((string) ($requests[0]['subject'] ?? ''), 'TEST-2026-000001'), 'Bestellnummer fehlt im Betreff.');
    $assert(str_contains((string) ($requests[0]['htmlContent'] ?? ''), 'TEST-2026-000001'), 'Bestellnummer fehlt im Inhalt.');

    $withdrawal = $client->send([
        'dedupe_key' => 'withdrawal-receipt:rendered',
        'message_type' => 'withdrawal_receipt',
        'recipient' => 'buyer@example.invalid',
        'payload' => '{"withdrawalId":"withdrawal-test","receivedAt":"2026-08-08T18:00:00Z"}',
    ]);
    $assert($withdrawal['outcome'] === 'sent', 'Fachliche Widerrufsmail wurde nicht versendet.');
    $assert(str_contains((string) ($requests[1]['htmlContent'] ?? ''), '2026-08-08T18:00:00Z'), 'Eingangszeit fehlt.');
};
$tests['Brevo-unklarer Ausgang wird nicht blind erneut gesendet'] = static function () use ($assert): void {
    $client = new CarmajaBrevoClient([
        'brevoApiKey' => 'synthetic', 'brevoSenderEmail' => 'test@example.invalid',
    ], static function (): array { throw new RuntimeException('network'); });
    $result = $client->send([
        'dedupe_key' => 'order-confirmation:synthetic', 'recipient' => 'buyer@example.invalid',
        'payload' => '{"subject":"Test","htmlContent":"synthetic"}',
    ]);
    $assert($result['outcome'] === 'delivery_unknown', 'Unklarer Brevo-Ausgang ist nicht terminal.');
};
$tests['Brevo-temporärer Fehler folgt Retry-Vertrag'] = static function () use ($assert): void {
    $client = new CarmajaBrevoClient([
        'brevoApiKey' => 'synthetic', 'brevoSenderEmail' => 'test@example.invalid',
    ], static fn (): array => ['status' => 503, 'body' => '{}']);
    $result = $client->send([
        'dedupe_key' => 'order-confirmation:synthetic', 'recipient' => 'buyer@example.invalid',
        'payload' => '{"subject":"Test","htmlContent":"synthetic"}',
    ]);
    $assert($result['outcome'] === 'retry', 'Temporärer Brevo-Fehler wurde nicht für Retry markiert.');
};
$tests['Brevo-Duplikat bleibt terminal'] = static function () use ($assert): void {
    $client = new CarmajaBrevoClient([
        'brevoApiKey' => 'synthetic', 'brevoSenderEmail' => 'test@example.invalid',
    ], static fn (): array => [
        'status' => 400, 'body' => '{"code":"duplicate_parameter","message":"duplicate"}',
    ]);
    $result = $client->send([
        'dedupe_key' => 'order-confirmation:synthetic', 'recipient' => 'buyer@example.invalid',
        'payload' => '{"subject":"Test","htmlContent":"synthetic"}',
    ]);
    $assert($result['outcome'] === 'delivery_unknown'
        && $result['error'] === 'brevo_idempotency_duplicate',
        'Brevo-Duplikat wurde fuer einen blinden Retry freigegeben.');
};
$tests['AP5-Verträge und Statusachsen'] = static function () use ($assert): void {
    $migration = file_get_contents(dirname(__DIR__, 2) . '/database/migrations/commerce-v1-ap5-admin.sql');
    $schema = file_get_contents(dirname(__DIR__, 2) . '/database/commerce-schema.sql');
    $admin = file_get_contents(dirname(__DIR__, 2) . '/test-api-private/program/bootstrap.php');
    $commerce = file_get_contents(dirname(__DIR__, 2) . '/test-api-private/program/commerce-core.php');
    $assert(is_string($migration) && str_contains($migration, 'delivery_unknown'), 'Brevo-Status fehlt in Migration.');
    $assert(is_string($schema) && str_contains($schema, "'confirmed', 'canceled'"), 'Bestellstatusachse fehlt.');
    $assert(is_string($schema) && str_contains($schema, "'not_ready', 'ready', 'on_hold', 'shipped', 'delivery_issue', 'returned'"), 'Versandstatusachse fehlt.');
    $assert(is_string($admin) && str_contains($admin, "['refunds']"), 'Erstattungsanzeige fehlt.');
    $assert(is_string($admin) && str_contains($admin, "['payments']"), 'Zahlungsübersicht fehlt.');
    $assert(is_string($commerce) && str_contains($commerce, 'payment_method_type'), 'Zahlungsart fehlt im Adminvertrag.');
    $assert(!str_contains($admin, 'refunds/charge'), 'Refund-Auslöseendpoint wurde ergänzt.');
    $mailIdCapture = is_string($commerce) ? strpos($commerce, '$newMailId = (int) $pdo->lastInsertId();') : false;
    $mailAudit = is_string($commerce) ? strpos($commerce, "'mail_manual_resend_queued'") : false;
    $mailReturn = is_string($commerce) ? strpos($commerce, "return ['mailId' => \$newMailId") : false;
    $assert($mailIdCapture !== false && $mailAudit !== false && $mailReturn !== false
        && $mailIdCapture < $mailAudit && $mailAudit < $mailReturn,
        'Manueller Mail-Neuversand muss seine Outbox-ID vor dem Audit sichern.');
};

$passed = 0;
foreach ($tests as $name => $test) {
    try {
        $test();
        $passed++;
        echo "PASS {$name}\n";
    } catch (Throwable $error) {
        fwrite(STDERR, "FAIL {$name}: {$error->getMessage()}\n");
        exit(1);
    }
}
echo "AP5 PHP tests: {$passed}/" . count($tests) . " bestanden.\n";
