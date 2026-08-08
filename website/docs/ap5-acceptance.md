# AP5-Abnahme – Minimaler Admin und Brevo-Outbox

Stand: 2026-08-02
Branch: `feature/shop-ap5`
Voraussetzung: AP4 lokal abgenommen; kein Push, Deployment, Produktionszugriff oder Cutover.

## Lieferumfang

AP5 ergänzt eine getrennte Shop-Admin-Domäne. Das Admin-Konto liegt in
`admin_users`; Kennwörter werden ausschließlich als Argon2id-Hash gespeichert.
`admin_sessions`, CSRF-Hash und Login-Sperren sind serverseitig. Bootstrap,
Passwortwechsel und Sitzungswiderruf erfolgen über `shop-admin-cli.php` mit
`/usr/bin/php8.4`; Passwörter werden nicht als Argument, Umgebungswert oder
Datei angenommen. Der private Outboxlauf ist über `ap5-worker-cli.php`
vorbereitet; seine IONOS-Freigabe bleibt ein späteres Betriebs-Gate.

Die Routen unter `/admin/v1` erlauben nur die täglichen V1-Aufgaben:
Bestellübersicht und -detail, Versandmarkierung, Widerrufprüfung,
Wiedereinlagerungsbestätigung, Reviewübersicht sowie auditierte Mail-
Neuversendung. Refunds besitzen keinen Auslöseendpoint. Vollständige
Stripe-Erstattungen werden ausschließlich über die vorhandene Stripe-
Synchronisierung übernommen und im Admin angezeigt.

Die Migration `commerce-v1-ap5-admin.sql` ergänzt Admin-, Sitzungs-,
Loginversuchs- und Audit-Tabellen sowie den Brevo-Status `delivery_unknown`.
Der Brevo-Worker verwendet Provider-Idempotenz, zehnminütige Leases und die
V1-Retry-Staffel. Ein unklarer Provider-Ausgang bleibt `delivery_unknown` und
wird nicht blind mit einem neuen Schlüssel erneut gesendet.

## Nachweise

* AP5-PHP-Vertragstests: 8/8 bestanden.
* AP5-Node-Vertragstests: 6/6 bestanden.
* PHP-Lint der geänderten AP5-Dateien: bestanden mit PHP 8.4.23.
* `npm run lint:test`: bestanden.
* `npm run build:test`: bestanden; statischer `/admin/`-Export erzeugt.
* Android `testDebugUnitTest`: bestanden; AP5 verändert keine Android-Dateien.
* Vollständiger Node-Lauf: 65/72 bestanden. Sieben bekannte, AP5-fremde
  CRLF-/Shell-Testfehler in den bestehenden Diagnose-, Deployment-, Smoke-
  und Token-Installer-Tests bleiben unverändert.

Es wurden keine Produktionsdaten, keine IONOS-Ressourcen und keine Brevo-
oder Stripe-Geheimwerte verwendet. AP6 ist nicht begonnen.

## Status

AP5 ist technisch vollständig abgenommen. Die produktive Freigabe bleibt bis
zu AP6, den Sicherheits-/Restore-Gates, der rechtlichen Prüfung und AP7
gesperrt.
