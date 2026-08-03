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
    ], static fn (array $request, string $key): array => [
        'status' => 201, 'body' => '{"messageId":"<synthetic-1>"}',
    ]);
    $result = $client->send([
        'dedupe_key' => 'order-confirmation:synthetic', 'recipient' => 'buyer@example.invalid',
        'payload' => '{"subject":"Test","htmlContent":"synthetic"}',
    ]);
    $assert($result['outcome'] === 'sent' && $result['brevoMessageId'] === '<synthetic-1>', 'Brevo-Erfolg nicht gespeichert.');
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
$tests['AP5-Verträge und Statusachsen'] = static function () use ($assert): void {
    $migration = file_get_contents(dirname(__DIR__, 2) . '/database/migrations/commerce-v1-ap5-admin.sql');
    $schema = file_get_contents(dirname(__DIR__, 2) . '/database/commerce-schema.sql');
    $admin = file_get_contents(dirname(__DIR__, 2) . '/test-api-private/program/bootstrap.php');
    $assert(is_string($migration) && str_contains($migration, 'delivery_unknown'), 'Brevo-Status fehlt in Migration.');
    $assert(is_string($schema) && str_contains($schema, "'confirmed', 'canceled'"), 'Bestellstatusachse fehlt.');
    $assert(is_string($schema) && str_contains($schema, "'not_ready', 'ready', 'on_hold', 'shipped', 'delivery_issue', 'returned'"), 'Versandstatusachse fehlt.');
    $assert(is_string($admin) && str_contains($admin, "['refunds']"), 'Erstattungsanzeige fehlt.');
    $assert(!str_contains($admin, 'refunds/charge'), 'Refund-Auslöseendpoint wurde ergänzt.');
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
