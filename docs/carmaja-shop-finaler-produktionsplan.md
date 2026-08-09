# Carmaja-Perlen Shop – finaler V1-Implementierungsplan mit V2-Ausbau

Stand: 2026-08-09
Änderungsvermerk: AP1, AP2, AP2a, AP3, AP4 und AP5 sind abgeschlossen und
abgenommen. AP3b ist für `card`, `paypal`, `klarna` und `sepa_debit` technisch
vollständig abgenommen; die AP6-Gesamtregression wurde danach erfolgreich
wiederholt. Rechts-, Datenschutz- und Versandfassung vom 2026-08-07 sind dem
freigegebenen Produktions-Legal-Bundle `cmj-production-legal-2026-08-07-v3`
zugeordnet. AP6 ist vollständig abgenommen. AP7.0 hat das lokale
Produktionspaket auf Basis des AP6-Commits vorbereitet und technisch geprüft.
AP7.2b hat App `1.1.2`/Code `4` integriert; AP7.3b hat die vollständige
V2-Produktkette lokal und mit künstlichen Daten in der bereinigten
IONOS-Testumgebung nachgewiesen. AP7.3d hat die V2-Testwebsite ausschließlich
in der IONOS-Testumgebung bereitgestellt und serverseitig verifiziert. AP7.3e
hat den vollständigen V2-Kettenstand einschließlich AP7.3d kontrolliert in den
AP7-Integrationsbranch übernommen. AP7.3g hat die vollständige private
Test-Shop-Laufzeit und den End-to-End-Betrieb mit allen vier Zahlungsarten,
Worker, Brevo und öffentlichem Widerruf nachgewiesen und anschließend
bereinigt. AP7.5 hat den getesteten Produktvertrag V2 in das manuell gegatete
Produktions-API-Artefakt übernommen und lokal verifiziert. AP7.7a ergänzt den
privaten verschlüsselten IONOS-Backupdienst und den kontrollierten
OneDrive-Pull; der isolierte IONOS-E2E mit künstlichen Daten ist bestanden,
während Produktionsaktivierung und OneDrive-Pull ausstehen. AP7
und jede Produktionsmutation bleiben nicht freigegeben.
AP1-Abschlusscommit: `21da119db1c57be095764f8f75bb0c9863ec1759`
AP2-Abschlusscommit: `b874baa410b54894ca462326f402ead859370ab6`
AP5-Abschlusscommit: `4a0b7e6a937a4bc0174eb40687b270437f7f2ccf`
AP6-Abschlusscommit: `bb4345fbfb26fede5bdf61be0ef6191746a98ef0`
AP7.3d-Abschlusscommit: `3ae346c095d8564c07ce6539a70d38a936a20ff6`
Produktionsziel: ausschließlich der eigene Carmaja-Shop. Parallele externe
Verkaufsangebote müssen vor dem produktiven Cutover deaktiviert oder gelöscht
sein; eine Synchronisierung wird nicht entwickelt.

## 1. Status und Leitplanken

AP0-A bis AP0-C.2 sowie AP1.1 bis AP1.7 sind abgenommen. AP1 umfasst das
Produktmodell v2, den serverseitigen `sourceHash`, die Mindest-App-Version,
die Legacy-Schreibsperre, das schreibfreie Migrationswerkzeug sowie den
praktischen MySQL-8-/InnoDB-Backup-/Restore-Nachweis. Der akzeptierte
V1-Restpunkt fehlender CA-/Hostidentitätsprüfung bleibt dokumentiert; eine
aktive TLS-Sitzung ist Pflicht.

AP2 ist vollständig abgenommen. AP2a ist technisch abgenommen. AP3, AP4 und
AP5 sind abgenommen. AP3b und die anschließende AP6-Gesamtregression sind
vollständig bestanden. Die Rechts-, Datenschutz- und Versandfreigaben liegen
für die versionierten Fassungen vom 2026-08-07 vor. AP6 ist vollständig
abgenommen. AP7.2b, AP7.3b, AP7.3d, AP7.3e und AP7.3g sind technisch
geschlossen.
AP7 bleibt nicht freigegeben. Das verifizierte AP7.3d-Testdeployment war auf
die getrennte IONOS-Testwebsite begrenzt. Es gibt keinen produktiven Cutover,
kein Produktionsdeployment, keinen Merge nach `main` und keinen Zugriff auf
Produktionsdaten.

V1 bleibt ein direkter Einzelkauf ohne Warenkorb, Kundenkonto oder parallele
Marktplätze. Ein Checkout enthält genau ein Unikat mit Menge `1`. Nach dem
Produktionsstart ist der eigene Shop der einzige Verkaufskanal.

## 2. Autoritative Modelle

Die bestehende Produktverwaltung ist fachlich autoritativ für Name,
Beschreibung, Bilder, Preis, Währung und Verkaufsfreigabe. `commerce_products`
ist ein versioniertes lokales Lesemodell; es ist keine zweite
Produktverwaltung. `commerce_inventory.on_hand` ist die einzige veränderliche
Bestandsquelle. Der Product-Synchronisierer darf `on_hand` niemals ändern.

Ein Unikatbestand ist binär. Zulässige Vorgänge sind ausschließlich:

| Vorgang | Übergang | Auslöser |
| --- | --- | --- |
| `activate_new_unique` | `0 → 1` | Betreiber aktiviert ein neues Unikat |
| `shop_sale` | `1 → 0` | atomare erfolgreiche Shop-Finalisierung; keine manuelle Aktion |
| `mark_unsellable` | `1 → 0` | Betreiber markiert ein Unikat als beschädigt/nicht verkäuflich |
| `release_return` | `0 → 1` | tatsächlich eingegangene und geprüfte Rückgabe |

`adjustInventory(productId, targetOnHand, reason, expectedInventoryVersion,
correlationId, Idempotency-Key)` läuft in einer MySQL-Transaktion. Ein
manueller Aufruf mit `shop_sale` wird abgelehnt. Aktive, abgelaufene-
blockierende und prüfpflichtige Reservierungen verhindern eine externe
Bestandsreduzierung; es gibt keinen externen Verkaufskanal mehr.

## 3. AP2-Zielarchitektur

* MySQL 8 mit InnoDB ist die Commerce-Datenbank. SQLite/WAL ist verworfen.
* Jede fachliche Mutation verwendet kurze Transaktionen und `SELECT ... FOR
  UPDATE` auf den betroffenen Produkt-, Inventar- und Checkout-Zeilen.
* Innerhalb einer Transaktion finden keine Stripe-, Brevo- oder sonstigen
  Netzwerkaufrufe statt.
* Webhooks werden vor einer späteren HTTP-Bestätigung dauerhaft in der Inbox
  gespeichert. Die konkrete öffentliche Stripe-Integration bleibt AP3.
* Workerläufe verwenden einen Datenbank-Lock, zehnminütige Leases, kleine
  Batches und die Retry-Staffel 5 Minuten, 15 Minuten, 1 Stunde, 4 Stunden,
  12 Stunden, danach `manual_review` oder `failed`.
* Bestellnummern werden über eine transaktional gesperrte
  `order_sequences`-Zeile vergeben.
* Bestellung und Versand sind getrennte Statusachsen. `orders.status` kennt
  nur `confirmed` und `canceled`; Versandstatus liegt ausschließlich in
  `shipments`.
* Zahlungs-, Prüf-, Erstattungs- und Streitfallstatus werden getrennt
  gespeichert.
* Die explizite V1-Zahlungsarten-Allowlist lautet ausschließlich `card`,
  `paypal`, `klarna`, `sepa_debit`. Stripe Link, dynamische Zahlungsarten und
  alle weiteren Verfahren bleiben deaktiviert.
* `payments.status=processing` bezeichnet eine von Stripe angenommene, aber
  noch nicht endgültig erfolgreiche Zahlung. Währenddessen bleiben Checkout
  und Unikatreservierung blockierend; Bestellung, Bestellnummer, Mail-Outbox
  und Versand entstehen noch nicht.
* Nur ein serverseitig verifizierter endgültiger Zahlungserfolg darf die
  bestehende atomare Bestellfinalisierung auslösen. Ein endgültiger
  asynchroner Fehler setzt Zahlung und Checkout auf `failed` und gibt die
  Reservierung atomar frei. Ein unklarer oder widersprüchlicher Ausgang bleibt
  blockiert und erzeugt `manual_review`.

## 4. AP2-Arbeitspakete und Nachweise

### AP2.1 – Schema und Vorwärtsmigration

Lieferumfang: InnoDB-Schema, Fremdschlüssel, Checks, Indizes,
Schema-Migrationsjournal und getrennte Produkt-/Inventartabellen.

Nachweis: wiederholbare Migration auf leeres künstliches MySQL-8-Ziel,
idempotente Vorwärtsmigration, Strukturvergleich und Rollback-Gate
`stock_rollback_locked` nach einem Commerce-Checkout.

### AP2.2 – Commerce-Kern

Lieferumfang: Checkout-Snapshot, Reservierung, Zahlungsobjekt, atomare
Bestellfinalisierung, Bestellnummer, Inventory-Audit, Versandzeile und
Reviewcase-Erzeugung.

Nachweis: Parallelitäts-, Idempotenz-, Zustands- und Crash-Tests mit
künstlichen Daten. Ein unbezahlter oder abgebrochener Checkout erhält keine
Bestellnummer.

### AP2.3 – Inboxen, Outboxen und Worker

Lieferumfang: Webhook-Inbox, Mail-Outbox, Stripe-Metadaten-Outbox,
Reviewcases, Leases, Retry und manueller Notbetrieb. Externe Aktionen werden
nur außerhalb gehaltener Row-Locks ausgeführt.

Nachweis: zwei parallele Worker, Lease-Ablauf/Übernahme, Wiederanlauf,
begrenzte Batches und `manual_review` nach dem fünften Retry.

### AP2.4 – Backup-/Restore-Integration

Lieferumfang: serverseitiger `mysqldump`-/`mysql`-Adapter ohne Secrets in
Argumenten, Prüfsummen, Struktur-/Inhaltsvergleich und künstliche
Manipulationsprüfung.

Nachweis: getrenntes Restore-Ziel, vollständiger Vergleich, Fehlerfall bei
manipuliertem Dump und Cleanup. Produktionsdaten bleiben ausgeschlossen.

### AP2.5 – AP2-Abnahme

Alle AP2-Nachweise werden zusammengeführt. AP2a wurde anschließend separat
bearbeitet; AP3 und spätere Pakete werden nicht begonnen. Die Abnahme ist erst
möglich, wenn keine AP2-spezifischen
Fehler offen sind und alle temporären Testressourcen entfernt wurden.

### AP2a – Technische Legal-Seiten und Legal-Bundles

Lieferumfang: technisch erreichbare Seiten `/shopbedingungen/`,
`/datenschutz/`, `/widerruf/` und `/versand-und-zahlung/`; ein kanonisch
gehashtes, versioniertes Legal-Bundle-Lesemodell mit ID, Status,
Archiv-URL und getrennter Test-/Produktionsauswahl; unveränderliche
Legal-Bundle-Snapshots für die spätere Checkout-Zuordnung.

Testfassungen enthalten ausschließlich künstliche, deutlich als nicht
rechtlich freigegeben gekennzeichnete Inhalte. Die Produktionsfassung bleibt
bis zur externen Freigabe `awaiting_external_approval`; kein Bundle wird als
rechtlich freigegeben behauptet. AP2a verändert weder Stripe-Checkout noch
Produktionsdaten und beginnt AP3 nicht.

Nachweis: Bundle-Hash- und Statusvalidierung, Test-/Produktions-Trennung,
Snapshot-Vergleich, statischer Testexport aller vier Seiten, Lint und
Node-Tests. Die technische Abnahme ist in
`website/docs/ap2a-acceptance.md` dokumentiert.

### AP3 – Stripe Checkout, Webhooks und Worker

Lieferumfang: gepinnte Stripe-SDK-/API-/Webhook-Versionen, serverseitiger Checkout mit
Preis-, Versand- und Legal-Bundle-Snapshot, verpflichtende
Terms-of-Service-Zustimmung, Webhook-Signaturprüfung, persistente Inbox vor
`2xx`, exakte Sieben-Ereignis-Allowlist, Deduplizierung, ungeordnete Events,
Leases, Retry, Stripe-Abgleich und Stripe-Metadaten-Outbox.

Die exakte V1-Allowlist lautet:

```text
checkout.session.completed
checkout.session.expired
charge.refunded
refund.updated
charge.dispute.created
charge.dispute.updated
charge.dispute.closed
```

Die Implementierung liegt isoliert auf `feature/shop-ap3`. Node-Vertrags- und
Website-Nachweise sowie PHP-8.4-Lint-/API-Nachweise sind bestanden; AP3 ist formal abgenommen. Es wurden weder Test-/Produktions-
Secrets noch produktive Ressourcen verwendet. Die Details stehen in
`website/docs/ap3-acceptance.md`.

### AP3b – PayPal, Klarna und SEPA-Lastschrift

AP3b ersetzt für V1 die bisherige Card-only-Zahlungsgrenze durch die explizite
und vollständig serverseitige Allowlist:

```text
card
paypal
klarna
sepa_debit
```

Die Checkout-Session übermittelt genau diese Liste über
`payment_method_types`; dynamische Zahlungsarten bleiben ausgeschaltet. Die
gewählte Zahlungsart wird aus dem von Stripe geladenen PaymentIntent
normalisiert und in `payments.payment_method_type` gespeichert. Vom Client
gesendete Angaben zur Zahlungsart haben keine Autorität.

`checkout.session.completed` finalisiert nur bei `payment_status=paid`. Bei
`payment_status=unpaid` wird die Zahlung zu `processing`, der Checkout bleibt
`payment_pending`, und die Reservierung bleibt mit `blocks_stock=1` aktiv.
`checkout.session.async_payment_succeeded` darf nach vollständiger Prüfung
dieselbe atomare, idempotente Bestellfinalisierung wie ein unmittelbar
bezahlter Checkout auslösen. `checkout.session.async_payment_failed` setzt
Zahlung und Checkout atomar auf `failed` und die Reservierung auf `released`
mit `blocks_stock=0`. Abweichende, ungeordnete oder nicht eindeutig
verifizierbare Stripe-Zustände erzeugen einen Reviewcase; dabei bleibt das
Unikat blockiert. Es gibt niemals Bestellung, Bestellnummer oder
Versandfreigabe vor bestätigtem Zahlungserfolg.

Die AP3b-Allowlist umfasst exakt neun Ereignisse:

```text
checkout.session.completed
checkout.session.async_payment_succeeded
checkout.session.async_payment_failed
checkout.session.expired
charge.refunded
refund.updated
charge.dispute.created
charge.dispute.updated
charge.dispute.closed
```

Der öffentliche Bestellstatus zeigt für laufende SEPA-Lastschriften einen
neutralen Zustand „Zahlung wird bearbeitet“ und gibt keine Bestellnummer aus.
Der Admin zeigt die normalisierte Zahlungsart und die getrennten Zahlungs-,
Prüf-, Refund- und Dispute-Zustände. Refunds werden weiterhin ausschließlich
im Stripe-Dashboard ausgelöst; Webhook, Worker und Stripe-Abgleich
synchronisieren sie idempotent. Retry, Lease, Wiederanlauf und
`manual_review` verwenden unverändert die V1-Staffel.

PayPal, Klarna und SEPA-Lastschrift werden vor AP7 ausschließlich in einer
Stripe-Sandbox aktiviert und getestet. Produktionsaktivierung ist nicht Teil
von AP3b. Die öffentlichen Legal-Bundles, Zahlungs- und Versandinformationen
werden wegen der neuen Zahlungsarten wieder zu
`awaiting_external_approval`-Entwürfen. Insbesondere ist das erhöhte
Nachweis- und Verlustrisiko von Klarna bei Versand ohne Sendungsverfolgung vor
einer Freigabe fachlich und rechtlich zu bewerten; ohne positive Freigabe darf
Klarna nicht produktiv aktiviert werden.

Dieses AP3b-Gate wurde anschließend in AP6 für die versionierten Fassungen vom
2026-08-07 abgeschlossen. Die Betreiberfreigabe umfasst den Maxibrief der
Deutschen Post mit Basis-Sendungsverfolgung ohne Zustellnachweis sowie die vier
Zahlungsarten. Die produktive Aktivierung selbst bleibt Bestandteil von AP7.

Abnahme: Schema-/Migrations-, Vertrags-, Zustands-, Webhook-, Worker-,
Reconciliation-, Admin-, Statusseiten-, Refund-, Dispute-, Retry-,
Wiederanlauf- und Sicherheitstests für alle vier Verfahren sind bestanden.
Card, PayPal, Klarna, SEPA-Erfolg und SEPA-Fehler wurden praktisch in der
Stripe-Sandbox geprüft. Ablauf, vollständige Erstattung ohne Wiedereinlagerung,
Streitfall, Reviewcase und ungeordnete Ereignisse sind ebenfalls bestanden.
Der technische Abnahmebericht steht in `website/docs/ap3b-acceptance.md`.
AP7 bleibt gesperrt.

### AP4 – Öffentliche Shop-API, Checkout-UI und Widerruf

Lieferumfang: getrennte anonyme Shopsitzung, CSRF-Token, zehnminütiger
Live-Kontext und kurzlebige Checkout-Berechtigung; serverseitig gehashte
Token, `Secure`-/`HttpOnly`-/`SameSite=Lax`-Cookies, exakter CORS-Vertrag,
`Cache-Control: no-store`, Rate-Limits und fail-closed Live-Produktdaten.
Checkout-Start und Token-Ausgabe bleiben in einem Endpunkt; Stripe erhält
ausschließlich den serverseitig geladenen Preis-, Produkt-, Versand- und
Legal-Snapshot. Erfolgs-, Abbruch- und Fehlerseiten sowie die dauerhaft
öffentliche zweistufige Widerrufsfunktion sind enthalten. Statische
Bestandswerte werden nicht als Kaufentscheidung verwendet.

Der eigene Carmaja-Shop ist der einzige Verkaufskanal. Externe Verkaufslinks
und -verweise sind aus den sichtbaren Produkt-, Start- und Checkoutseiten
entfernt; es gibt keine Kanalsynchronisierung. Die bestehende Legacy-
Produktverwaltung bleibt für AP1-Kompatibilität lesbar, erzeugt aber keine
öffentlichen Shoplinks.

Die Umsetzung liegt isoliert auf `feature/shop-ap4`. Die AP4-PHP-Vertrags-,
Node-, Website-, Integrations- und Sicherheitstests sind bestanden; sieben
bereits bekannte AP4-fremde CRLF-/Shellfehler bleiben außerhalb des AP4-Scopes.
Der
technische Abnahmebericht steht in `website/docs/ap4-acceptance.md`. Es wurden
weder Test-/Produktionsdaten noch produktive Ressourcen verwendet.

### AP5 – Minimaler Admin und Brevo-Outbox

Lieferumfang: getrenntes Admin-Konto in `admin_users` mit Argon2id,
serverseitigen `admin_sessions`, CSRF-Hash, kurzer Sitzungsdauer,
fehlgeschlagenen-Anmelde-Sperren sowie CLI-Bootstrap, Passwortwechsel und
Sitzungswiderruf über `/usr/bin/php8.4`. Die `/admin/v1`-Routen bieten nur
Bestellübersicht/-detail, Versandmarkierung, Widerrufsprüfung,
Wiedereinlagerungsbestätigung, Reviewübersicht und auditierte Mail-
Neuversendung. Bestellung, Zahlung, Versand, Widerruf, Review und
Wiedereinlagerung bleiben getrennte Statusachsen.

Refunds werden im Carmaja-Admin ausschließlich angezeigt und über Stripe-
Webhook/Abgleich synchronisiert; es existiert keine eigene Refund-Auslösung.
Die Brevo-Outbox verwendet Deduplizierung und eine deterministische UUID v4 im
JSON-Feld `headers.idempotencyKey`, zehnminütige Leases, die V1-Retry-Staffel
und den terminalen Zustand `delivery_unknown` für unklare oder als Duplicate
erkannte Provider-Ausgänge. Ein manueller Neuversand erzeugt erst nach
Betreiberaktion einen neuen, auditierten Outbox-Eintrag.

Die AP5-Migration ist `website/database/migrations/commerce-v1-ap5-admin.sql`;
die technischen Nachweise stehen in `website/docs/ap5-acceptance.md`.
AP5 wurde nur mit künstlichen Testdaten geprüft; AP6 und Produktion bleiben
unangetastet.

### AP6 – Gesamtregression, Betriebs- und Sicherheitsabnahme

AP6 umfasst nach AP3b die vollständige PHP-8.4-Regressionsprüfung mit
`mbstring`, GD, `exif`, PDO/`pdo_mysql`, `sodium`, `curl`, `openssl` und
`intl`, die PHP-, Node-, Android-, Lint-, Build- und Diff-Prüfungen, den
Vergleich mit dem unveränderten Sieben-Fehler-Basissatz sowie die technischen
MySQL-, Stripe-Testmodus-, Shop-Sicherheits-, Admin-, Brevo- und
IONOS-Nachweise. Alle praktischen Prüfungen verwendeten ausschließlich
künstliche Daten und isolierte Testressourcen; AP7 und Produktion blieben
ausgeschlossen.

Die technische Gesamtregression wurde am 2026-08-07 vollständig bestanden:
PHP-Lint sowie Commerce 16/16, AP4 4/4, AP5 9/9, Admin 20/20, Bootstrap 9/9,
Product-API 46/46 und Stripe 4/4; Node 73/80 mit ausschließlich den sieben
unveränderten CRLF-/Shell-Basisfehlern; `npm run lint:test`,
`npm run build:test` und `npm audit --omit=dev` mit 0 Schwachstellen; Android
`testDebugUnitTest`, `lintDebug` und `assembleBeta`. Die Beta-APK ist gültig
signiert und stimmt mit dem festgeschriebenen Zertifikatsfingerabdruck überein.

Der MySQL-8-/InnoDB-Nachweis einschließlich Migration, Parallelität, Deadlock,
Crash-Rollback, Leases, Idempotenz, Backup/Restore, Struktur-/Inhaltsvergleich
und Manipulationserkennung ist bestanden; beide isolierten Testdatenbanken
sind leer. Die vier Stripe-Zahlungsarten, die neun Webhook-Ereignisse,
SEPA-`processing`, asynchroner Erfolg und Fehler, Bestellung, Ablauf,
vollständige Erstattung ohne Wiedereinlagerung, Streitfall und separater
Reviewcase sind praktisch bestanden. Ungeordnete Stripe-Ereignisse und
Wiederanlauf wurden ebenfalls geprüft.

Brevo bestand realen Versand, deterministische Provider-Idempotenz,
Duplicate-Behandlung als `delivery_unknown`, Retry und auditierten manuellen
Neuversand. Der IONOS-UnixCron bestand zwei echte Läufe im Abstand von 298
Sekunden mit Lock, Lease, Runlog und Laufzeiten von 16 und 20 ms. Cronjob,
Stage, Endpunkte, Testkonfigurationen und künstliche Daten wurden anschließend
entfernt. Die aktive TLS-Sitzung bleibt Pflicht; die fehlende
CA-/Hostidentitätsprüfung ist das akzeptierte V1-Restrisiko.

AP6 ist vollständig abgenommen. Das Paket
`website/docs/legal-review/ap6-2026-08-07-v1/` bindet die freigegebenen
Rechts-, Datenschutz- und Versandfassungen per Inhalts- und Dateihash an das
Produktions-Legal-Bundle `cmj-production-legal-2026-08-07-v3`. Versand erfolgt
innerhalb Deutschlands als Maxibrief der Deutschen Post bis 1.000 g für
2,70 EUR mit Basis-Sendungsverfolgung ohne Zustellnachweis, Haftung oder
Versicherung. Der vollständige Bericht steht in
`website/docs/ap6-acceptance.md`. AP7 bleibt gesondert gesperrt.

### AP7.0 – Lokales Produktionspaket

AP7.0 wurde am 2026-08-08 ausschließlich lokal im separaten Worktree
`G:\BS-Stein-Hart-ap7` auf `feature/shop-ap7` aus dem unveränderten AP6-Commit
vorbereitet. Der Produktions-API-Einstieg ist von Testpfaden getrennt; private
API-, Konfigurations- und Workerziele sind festgelegt. Der endgültige Worker
liegt unter `/home/www/carmaja-private-shop/worker.php` und wird später per
UnixCron `*/5 * * * *` mit `/usr/bin/php8.4` gestartet.

Das automatische `main`-Deployment wurde durch einen manuellen, SHA-gepinnten
und standardmäßig deaktivierten Produktionsworkflow ersetzt. Produktions-
Hosting, Tracking und Export verwenden keine externen Verkaufsziele. Website
und Publisher erwarten Produktmodell v2 ohne `stock` oder externe
Verkaufsfelder; die Website besitzt keinen statischen Bestandsfallback.

Der lokale Cutover-Adapter ist CLI-only und standardmäßig schreibfrei. Das
versionierte Manifest bindet Schema-Migrationen, Zahlungsarten, Versand und
Legal Bundle per Vertrag und Prüfsumme. Ohne Status `approved_for_cutover`,
genau ein ausgewähltes autoritatives v2-Produkt, passende Produktversion und
passenden serverseitigen `sourceHash` ist kein Apply möglich. Es wurden weder
ein reales Produkt ausgewählt noch Produktionssecrets, Produktionsdatenbank,
Liveprovider, Cron, Deployment, Migration oder Produktaktivierung berührt.

Die lokale Regression ist bestanden: PHP 116/116; AP7-PHP 7/7; Node 77/84
mit ausschließlich den sieben unveränderten CRLF-/Bash-Basisfehlern; AP7-Node
4/4; Lint, Testbuild, lokaler Produktionsbuild mit künstlicher v2-Fixture,
Exportprüfung, Android-Unit/Lint und Produktionsabhängigkeitsaudit sind grün.
Die Readiness- und Gate-Dokumentation steht in
`website/docs/ap7-readiness.md`. AP7.0 ist technisch bereit zur gesonderten
Produktionsfreigabe; AP7 selbst bleibt gesperrt.

### AP7.2b – App v1.1.2 und Draft-Synchronisierung

AP7.2b wurde am 2026-08-08 ausschließlich lokal im Integrations-Worktree
`G:\BS-Stein-Hart-ap7-integration` auf `codex/ap7-integration` durchgeführt.
Der autoritative App-Stand `app-v1.1.2` aus Commit
`4cbd0489628d0c5e7e347d376df67bd12342fe5e` ersetzt den vorläufigen
AP7.2a-Vertrag `2`/`1.1.0`. Der Produktionsbuild verwendet verbindlich
`versionCode 4` und `versionName 1.1.2`. Die vorhandenen Felder
`braceletSizeCm` und `pearlSizeMm` werden ohne parallele Neuentwicklung durch
Draft, Editor, lokalen Speicher, API, Server und Synchronisierer geführt. Die
bereits integrierten AP7-Shopmodelle und -verträge bleiben erhalten; andere
Dateien des Tags, insbesondere Produktionshosting, wurden nicht übernommen.

`testDebugUnitTest`, `lintDebug` und der explizit freigegebene, weiterhin als
unsigniert verifizierte CI-Prüfbuild sowie 21/21 relevante
Node-Vertragstests, 46/46 Product-API-Tests, 10/10 Bootstrap-Tests und der
Produktions-Bootstrap-Test sind bestanden. Der unsignierte Prüfbuild ist nur
über den fest benannten Gradle-Schalter zulässig; ohne ihn bleibt
`assembleRelease` bei fehlender Produktionssignatur gesperrt. Der explizite
Draft-Roundtrip weist Laden, Bearbeiten, V2-Speichern/Synchronisieren und
erneutes Laden von Armbandumfang und Perlengröße ohne Wertverlust nach. Der
Android-Versions- und Draft-Synchronisierungsblocker ist damit geschlossen.

Der Release Candidate bleibt bis zur Anlage genau eines geeigneten realen
v2-Startprodukts nicht bereit. Dieses Produkt muss über die bestehende
Produktverwaltung mit gültigem `priceMinor`, `currency=eur` und
`salesEnabled=false` gespeichert und veröffentlicht werden; `productVersion`
und `sourceHash` entstehen serverseitig. Weder `stock` noch Commerce-Bestand
dürfen dabei verändert werden. Produktionsdaten, Deployment, Merge, Migration,
Produktaktivierung und Cutover bleiben gesperrt.

### AP7.3b – Vollständige V2-Produktkette

AP7.3b wurde am 2026-08-08 im separaten Worktree
`G:\BS-Stein-Hart-ap73b` auf `codex/ap7-v2-chain` durchgeführt. Die App
verwendet für Draft, Editor, lokalen Speicher, Speichern, Bild-Upload,
Publizieren und Synchronisieren das Produktmodell V2. Der vollständige PUT
führt `priceMinor`, `currency`, `salesEnabled`, `braceletSizeCm`,
`pearlSizeMm` und `expectedProductVersion`; Wiederholungen verwenden eine
persistierte V2-Idempotenz-ID. `productVersion` und `sourceHash` entstehen
ausschließlich serverseitig. Legacy-Leseverträglichkeit bleibt begrenzt
erhalten; V2-Schreibwege lehnen `stock`, `vintedUrl`, clientseitige Versionen
und clientseitige Hashes ab.

Der V2-Publisher ist an den echten Publish-Ablauf gebunden. Die öffentliche
Projektion führt Preis, EUR-Währung, Verkaufsfreigabe, Version, Hash und beide
Maße konsistent und enthält weder `stock` noch `vintedUrl`. Die leere
Produktionsquelle und die Produktions-Exportprüfung verwenden ebenfalls
Schema V2.

Lokal bestanden `testDebugUnitTest`, `lintDebug`, stabil signiertes
`assembleBeta`, 118/118 PHP-Tests, die V2-relevanten Node-Verträge,
`npm run lint:test`, `npm run build:test`, `npm run build` und
`git diff --check`. Von 130 vollständigen Node-Testfällen bestehen 121; die
neun übrigen Fälle betreffen ausschließlich gegenüber der Ausgangsbasis
unveränderte CRLF-/Bash-Dateien und sind nicht durch AP7.3b verursacht. Ein
Emulator für Instrumentierungstests war nicht angeschlossen.

In der IONOS-Testumgebung bestand ein vollständig isolierter, token-geschützter
Web-SAPI-Roundtrip mit einem künstlichen Produkt und `salesEnabled=false`:
V2-Login, idempotentes Speichern, Bild-Upload, serverseitige Version/Hash,
Publisher, öffentliche V2-Allowlist sowie erneutes Laden ohne Verlust von
Preis, Währung, Verkaufsfreigabe, Armbandumfang oder Perlengröße. Sämtliche
temporären Endpunkte, privaten Konfigurationen, Token, Nutzer, Produktdaten,
Bilder und Projektionen wurden danach entfernt. Testwebsite, Produktion,
Commerce-Bestand, reales Produkt, Manifest, Push, Merge und Cutover blieben
unverändert.

Der nächste zulässige Schritt ist ausschließlich, genau ein reales Unikat über
die bestehende App `1.1.2` als V2 mit gültigem Preis, `currency=eur` und
`salesEnabled=false` zu speichern und zu veröffentlichen. Erst ein danach
bestandener lesender Dry-Run darf eine weiterhin gesperrte Manifestbindung
vorbereiten.

### AP7.3d – V2-Testwebsite

AP7.3d wurde am 2026-08-08 ausschließlich auf der geschützten IONOS-Testwebsite
bereitgestellt. Der Testexport verwendet die öffentliche V2-Projektion und
zeigt auch ein veröffentlichtes Produkt mit `salesEnabled=false` sichtbar als
„Nicht verfügbar“, ohne Kaufmöglichkeit. Produktdetailseite, Bilder,
Rechtstextseiten und Footer sind erreichbar; Vinted-/Marktplatzlinks,
`stock`, `vintedUrl` und produktive API-Ziele fehlen im Export. Der aktive
Testrelease wurde nach Server- und manueller Sichtprüfung als `verified`
markiert. Das Test-Armband und die Testumgebung blieben für weitere
Geräteprüfungen erhalten; Produktion und Commerce-Bestand wurden nicht
verändert.

Der geprüfte Stand ist im lokalen Commit
`3ae346c095d8564c07ce6539a70d38a936a20ff6` auf
`codex/ap7-v2-chain` gesichert.

### AP7.3e – AP7-Integrationsstand

AP7.3e hat am 2026-08-08 den vollständigen V2-Kettenstand einschließlich
AP7.3d per konfliktfreiem Fast-forward in `codex/ap7-integration` übernommen.
Die zuvor vorhandenen AP7.2b-/`main`-Änderungen wurden vorab gesichert und
dreiseitig geprüft; alle gültigen Änderungen sind im V2-Zielstand enthalten.
Veraltete Beta-Versions-, Paketsuffix- und Legacy-Payloadvarianten wurden nicht
wieder eingeführt.

Die Produktions-App bleibt bei `versionName=1.1.2`, `versionCode=4`, Paket
`de.carmajaperlen.armbandrechner` und ausschließlich produktiver Produkt-API.
Die Beta bleibt bei `versionName=1.1.3-beta.1`, `versionCode=5`, demselben Paket,
dem gepinnten Beta-Zertifikat und ausschließlich der Test-API. Website und
Publisher verwenden den öffentlichen V2-Vertrag ohne `stock` und `vintedUrl`.

Bestanden sind `testDebugUnitTest`, `lintDebug`, das stabil signierte
`assembleBeta`, der explizit unsignierte Produktions-Prüfbuild, 118/118
PHP-Tests, 29/29 CRLF-unabhängige V2-/AP7-Node-Verträge, `npm run lint:test`,
`npm run build:test`, ein künstlicher Produktionsbuild, beide Exportprüfungen,
`npm audit --omit=dev`, Geheimwertprüfung und `git diff --check`. Der
vollständige Node-Lauf reproduziert unverändert 121/130 bestandene Fälle und
exakt die neun dokumentierten Windows-CRLF-/Bash-Basisfehler. Test-Armband,
Test-API und Testwebsite wurden durch AP7.3e nicht verändert oder bereinigt.

### AP7.3g – vollständige Test-Shop-Laufzeit

AP7.3g hat am 2026-08-08 ausschließlich in der IONOS-Testumgebung die private
Commerce-Laufzeit mit MySQL 8/InnoDB, Test-Stripe, Test-Brevo, Testversand,
Test-Legal-Bundle, privatem Worker und IONOS-UnixCron wiederhergestellt. Die
öffentliche V2-Projektion verwendet nun unmittelbar den strikten Vertrag
`{version, products}`. Das vorhandene reale Test-Armband blieb unverändert mit
`salesEnabled=false`; sämtliche Käufe verwendeten ein separates künstliches
V2-Testprodukt mit künstlichem Bestand.

Live nachgewiesen wurden Shopsitzung, CSRF, CORS-Preflight, `no-store`,
Live-Preis und -Verfügbarkeit, Checkout-Start sowie erfolgreiche
Testzahlungen mit Karte, PayPal, Klarna und SEPA-Lastschrift. Bei SEPA blieb
der Checkout bis zum asynchron bestätigten Zahlungserfolg in Bearbeitung;
vorher entstanden weder Bestellung noch Bestandsabgang. Webhook-Inbox, Worker,
atomare Bestellfinalisierung, Bestandsänderung, Mail-Outbox/Brevo,
zweistufiger Widerruf, Legal-Snapshot und die vorgesehenen Admin-/Reviewlisten
wurden gemeinsam geprüft. Ein bestätigter Session-Ablauf gab die Reservierung
frei, stornierte die noch offene lokale Zahlung und erzeugte keine Bestellung.

Die Abschlussregression bestand 119/119 relevante PHP-Tests, 23/23 gezielte
V2-/Shop-Node-Tests, `npm run lint:test`, `npm run build:test`,
Geheimwertprüfung und `git diff --check`. Der vollständige Node-Lauf bestand
122/131 Fälle; die neun übrigen Fälle sind ausschließlich die bereits gegen
den sauberen Basisstand reproduzierten CRLF-/Bash-Basisfehler.

Der Worker-Nachweis umfasst direkten Lauf, parallelen Lockversuch, Lease und
Runlog sowie zwei echte IONOS-Cronläufe im Fünf-Minuten-Takt; sämtliche Läufe
blieben deutlich unter 40 Sekunden. Nach Abschluss wurden künstliches Produkt,
Commerce-Testdaten, temporärer Cron, Worker, Laufmarker, Testwebhook, private
AP7.3g-Konfiguration und Testartefakte entfernt. Das reale Test-Armband blieb
erhalten; Testwebsite und Test-API wurden fail-closed hinterlassen. Produktion,
`main`, Produktivdaten und produktive Providerkonfiguration blieben unverändert.

### AP7.5 – produktionsfähiges V2-Produkt-API-Artefakt

AP7.5 bindet den in AP7.3b nachgewiesenen `product-api-v2.php`-Vertrag an den
privaten Produktions-Bootstrap und dessen `/v2`-Routing. Das manuelle,
SHA-gepinnte Produktions-API-Artefakt enthält dadurch V1 und V2, aber keine
Shopprogramme, Runtime-Konfiguration, Secrets oder Testpfade. Die bestehende
Legacy-Routenführung bleibt unverändert.

Publisher, GitHub-Adapter und automatische Deployments bleiben standardmäßig
deaktiviert. Ein V2-Publish wird bei ausgeschaltetem Produktionspublisher vor
jeder Produktmutation mit `production_publish_disabled` abgelehnt. Die
Artefakt-Allowlist erwartet exakt sieben Dateien; Staging und Aktivierung
prüfen V2-Datei, PHP-8.4-Syntax, Dateizahl und den Ausschluss bekannter
Testpfade.

Lokal bestanden sind die PHP-8.4-Lints aller elf produktiven API-/Testdateien,
Product-API 47/47, Bootstrap 10/10, AP7-Vertrag 7/7,
Produktions-Bootstrap, Produktions-Admin, der neue produktive V2-Test, beide
Workflow-Vertragstests, `npm run lint:test`, Artefakt-Allowlist, SHA-256 und
`git diff --check`. Der vollständige Node-Lauf zeigte keine neue fachliche
Regression; die bereits dokumentierten Windows-Bash-/CRLF-Fälle und ein durch
die eingeschränkte Sandbox blockierter temporärer Export bleiben Aufgaben des
Linux-PR-CI-Nachweises. Weder Deployment noch Produktionszugriff,
V2-Migration, Produktanlage oder Cutover wurden ausgeführt.

### AP7.7a – verschlüsseltes Produktionsbackup und OneDrive-Pull

Der lokale Produktionsvertrag enthält einen ausschließlich privaten
CLI-Backupdienst ohne HTTP-Route. Er streamt den Commerce-Dump mit
`mysqldump --single-transaction` und die ausdrücklich erlaubten autoritativen
Produktdaten ohne dauerhaftes unverschlüsseltes Zwischenarchiv in
`sodium_crypto_secretstream_xchacha20poly1305`. Ein HMAC-geschütztes Manifest
bindet UTC-Zeit, Backup-ID, Key-ID, Schemajournal, Größen und SHA-256-Hashes;
der `ready`-Marker wird zuletzt atomar veröffentlicht. `flock`, ein
40-Sekunden-Limit, Änderungsprüfung der Produktdateien, vollständiger
Fehler-Cleanup und eine siebentägige, das jüngste bestätigte Backup schonende
Serverrotation sind verbindlich.

Der IONOS-Testhost stellt für das MySQL-8-Ziel den kompatiblen MariaDB-Client
10.11 bereit. Deshalb verwendet der CLI-Vertrag `ssl`,
`--single-transaction`, `--events`, `--routines`, `--triggers`,
`--order-by-primary` und `--no-tablespaces`. Dump und Restore werden nur nach
einem nicht leeren `Ssl_cipher` der jeweiligen CLI-Sitzung fortgesetzt. Ein
fehlender CA-/Hostidentitätsnachweis bleibt das bereits akzeptierte
V1-Restrisiko; eine aktive TLS-Sitzung ist weiterhin zwingend.

Der getrennte Backup-Cron ist für `17 * * * *` vorgesehen. Der Windows-Agent
läuft bei Anmeldung und anschließend alle 30 Minuten, prüft den exakten
OneDrive-Stamm `D:\Carmaja-OneDrive`, OneDrive-Prozess, freien Speicher,
Größen und Hashes. Unvollständige Transfers liegen außerhalb von OneDrive im
eindeutig markierten Stagingordner `D:\Carmaja-Backup-Incoming`; die geprüfte
Sicherung wird auf demselben Laufwerk atomar in OneDrive verschoben und erst
danach quittiert. OneDrive erhält 48 stündliche, 30 tägliche und 12
monatliche verschlüsselte Stände; der Schlüssel bleibt im Passwortmanager und
in einer getrennten Offlinekopie.

Server-RPO ist eine Stunde, Offsite-RPO bis 24 Stunden und RTO vier Stunden.
Die lokale Implementierung, ihre Kryptografie-/Vertragstests und der isolierte
IONOS-E2E mit zwei getrennten MySQL-8-Testdatenbanken sind bestanden. Der Lauf
hat aktive TLS-Sitzungen, verschlüsseltes Backup, Lock, Restore, semantischen
Schema-/Inhaltsvergleich, Quittierung und vollständigen Cleanup nachgewiesen.
OneDrive-Umzug, Schlüsselübertragung, Produktions-Cron, erster Pull und der
produktive Restore-Dry-Run benötigen weiterhin getrennte Gates.
Bis zum bestandenen Restore bleiben Publisher, Deployments, Produktanlage,
Migration, Shopaktivierung und Cutover gesperrt. Der Betriebsvertrag steht in
`website/docs/production-backup-onedrive.md`.

## 5. Meilensteine und kritischer Pfad

| Meilenstein | Stand | Nachweis |
| --- | --- | --- |
| AP0-A bis AP0-C.2 | abgeschlossen | Hosting-, MySQL-8-, InnoDB-, Cron- und Restoreprotokoll |
| AP1.1 bis AP1.7 | abgeschlossen | Commit `21da119`, AP1-Abnahme, 46 PHP- und AP1-Node-Tests, Android-Test |
| AP2.1 | abgeschlossen | MySQL-8-/InnoDB-Schema zweimal idempotent angewendet; Struktur und Migration geprüft |
| AP2.2 | abgeschlossen | Transaktion, Rollback, Row-Lock-Timeout, zehn Clients, Deadlock und Crash-Rollback bestanden |
| AP2.3 | abgeschlossen | Lease-Sperre und Lease-Übernahme sowie Inbox-/Outbox-Zuordnung bestanden |
| AP2.4 | abgeschlossen | Dump, Restore, Manipulationserkennung, Struktur-/Inhaltsvergleich und Cleanup bestanden |
| AP2.5 | abgeschlossen | AP2-Gesamtabnahme dokumentiert; AP3 und spätere Pakete nicht begonnen |
| AP2a | abgeschlossen | Vier technische Legal-Seiten, Bundle-Hash-/Statusprüfung, Snapshot-Zuordnung, Testexport und Abnahmebericht bestanden |
| AP3 | abgeschlossen | Stripe-Vertrag, Checkout-Parameter, Webhook-Inbox, Worker, PHP-8.4-Lint/API und Node-Nachweise bestanden |
| AP3b | technisch vollständig abgenommen | Vier-Zahlungsarten-Allowlist, asynchrone Zahlung, blockierende Reservierung, Admin/Status, Sandbox- und Wiederanlaufnachweise bestanden |
| AP4 | vollständig abgenommen | Shop-Sitzung, Token-/CSRF-/CORS-Vertrag, Live-API, Checkout-UI, Widerruf, Verkaufskanalgrenze und Sicherheitstests bestanden |
| AP5 | vollständig abgenommen | getrenntes Admin-Konto, Session/CSRF/Sperre, Admin-Verwaltung, Brevo-Outbox, Refund-Anzeige und AP5-Nachweise bestanden |
| AP6 | vollständig abgenommen | PHP-, Node-, Android-, MySQL-, Stripe-, Brevo-, IONOS-Cron-, Backup-/Restore- und Sicherheitsnachweise bestanden; Rechts-, Datenschutz- und Versandfassung versioniert freigegeben |
| AP7.0 | lokal technisch bereit | Produktionsverträge, manueller Deployment-Gate, v2-Website/Publisher, Cutovermanifest/-Adapter und lokale Regression bestanden; keine Produktionsmutation |
| AP7.2b | App- und Draft-Synchronisierungsblocker geschlossen | App `1.1.2`/Code `4`, vollständiges Messwert-Mapping, Android-Prüfbuild und relevante Vertragsregression bestanden; reales v2-Startprodukt ausstehend |
| AP7.3b | vollständige V2-Produktkette technisch geschlossen | App/Draft, API, serverseitige Version/Hash, Publisher und öffentliche V2-Projektion lokal und auf IONOS mit künstlichen Daten nachgewiesen und bereinigt; reales v2-Startprodukt ausstehend |
| AP7.3d | V2-Testwebsite verifiziert | V2-Testprodukt sichtbar und nicht kaufbar; Test-API, Produktdetail, Bilder, Legal-Seiten und Verkaufskanalgrenze live geprüft; Produktion unverändert |
| AP7.3e | AP7-Integrationsstand bereit | V2-Kette einschließlich AP7.3d übernommen; App-, PHP-, Node-, Test-/Produktionsbuild-, Export- und Geheimwertregression bestanden |
| AP7.3g | Testumgebung vollständig AP7-fähig | vollständige private Testlaufzeit, vier Zahlungsarten, Webhooks, Worker/Cron, Bestellung, Brevo, Widerruf, Legal-Snapshot, Admin/Review und Cleanup nachgewiesen |
| AP7.5 | produktives V2-API-Artefakt lokal bereit | produktiver Bootstrap und `/v2`-Router, fail-closed Publisher, Sieben-Dateien-Allowlist, PHP-/Node-/Artefaktnachweise bestanden; kein Deployment |
| AP7.7a | Implementierung und isolierter IONOS-Test-E2E bestanden | private CLI, Secretstream-Verschlüsselung, HMAC-Manifest, MySQL-8-Backup/Restore mit semantischem Vergleich, Lock und vollständigem Cleanup nachgewiesen; OneDrive- und Produktionsaktivierung ausstehend |

Kritischer Pfad bis zur AP6-Abnahme: AP2.1 → AP2.2 → AP2.3 → AP2.4 → AP2.5
→ AP2a → AP3 → AP4 → AP5 → AP3b → AP6. Dieser Pfad ist abgeschlossen. Der
verbleibende Produktionspfad beginnt nach dem abgeschlossenen AP7.0 weiterhin
ausschließlich mit einer gesonderten ausdrücklichen AP7-Freigabe.
Produktions-Cutover, Deployment und AP7 bleiben bis dahin gesperrt.

## 6. Aufwand und Kalenderkorridore

Die folgenden Werte sind Planungskorridore, keine gemessenen Ist-Stunden und
kein verbindliches Angebot.

| AP2-Teil | Planaufwand |
| --- | ---: |
| AP2.1 Schema/Migration | 18–28 h |
| AP2.2 Commerce-Kern | 32–48 h |
| AP2.3 Worker/Inboxen/Outboxen | 20–32 h |
| AP2.4 Backup/Restore | 12–20 h |
| AP2.5 Tests/Abnahme/Dokumentation | 12–20 h |
| **Gesamt AP2** | **94–148 h** |
| AP2a technische Legal-Seiten/Bundles | 18–28 h |
| **Gesamt AP2 + AP2a (Planungskorridor)** | **112–176 h** |
| AP3 Stripe/Checkout/Webhooks/Worker | 32–52 h |
| **Gesamt AP2 + AP2a + AP3 (Planungskorridor)** | **144–228 h** |
| AP4 öffentliche Shop-API/UI/Widerruf | 36–58 h |
| **Gesamt AP2 + AP2a + AP3 + AP4 (Planungskorridor)** | **180–286 h** |
| AP5 Admin/Brevo/Abnahme | 24–40 h |
| **Gesamt AP2 + AP2a + AP3 + AP4 + AP5 (Planungskorridor)** | **204–326 h** |
| AP6 Gesamtregression/Betrieb/Sicherheit/Abnahme | 32–56 h |
| **Gesamt AP2 + AP2a + AP3 + AP4 + AP5 + AP6 (Planungskorridor)** | **236–382 h** |
| AP3b Vier-Zahlungsarten-Erweiterung einschließlich erneuter AP3–AP5-Nachweise | 28–48 h; abgeschlossen, keine Ist-Stunden erfasst |
| erneute AP6-Gesamtregression nach AP3b | 24–44 h; abgeschlossen, keine Ist-Stunden erfasst |
| **verbleibender technischer Rest bis einschließlich AP6** | **0 h** |

| Wochenaufwand | verbleibender technischer Korridor bis einschließlich AP6 |
| ---: | --- |
| 5 h/Woche | 0 Wochen |
| 10 h/Woche | 0 Wochen |
| 15 h/Woche | 0 Wochen |

Für AP2 plus AP2a ergibt sich der kombinierte Planungskorridor von ca. 23–36
Wochen bei 5 h/Woche, 12–18 Wochen bei 10 h/Woche und 8–12 Wochen bei
15 h/Woche. Die Werte sind Planwerte, keine Ist-Arbeitszeiten.

Für AP2 plus AP2a plus AP3 ergibt sich ein Planungskorridor von ca. 29–46
Wochen bei 5 h/Woche, 15–23 Wochen bei 10 h/Woche und 10–16 Wochen bei
15 h/Woche. Der PHP-8.4-Nachweis ist abgeschlossen; die Werte bleiben
Planwerte und sind keine gemessenen Arbeitszeiten.

Für AP2 plus AP2a plus AP3 plus AP4 ergibt sich ein Planungskorridor von ca.
36–58 Wochen bei 5 h/Woche, 18–29 Wochen bei 10 h/Woche und 12–20 Wochen bei
15 h/Woche. AP4 ist abgenommen.

Für AP2 plus AP2a plus AP3 plus AP4 plus AP5 ergibt sich ein
Planungskorridor von ca. 41–66 Wochen bei 5 h/Woche, 21–33 Wochen bei
10 h/Woche und 14–22 Wochen bei 15 h/Woche. AP5 ist ein Planungskorridor und
keine gemessene Ist-Arbeitszeit.

Für AP2 plus AP2a plus AP3 plus AP4 plus AP5 plus AP6 ergab sich vor AP3b ein
Planungskorridor von ca. 48–77 Wochen bei 5 h/Woche, 24–39 Wochen bei
10 h/Woche und 16–26 Wochen bei 15 h/Woche. AP3b und die erneute
AP6-Gesamtregression waren mit weiteren 52–92 Stunden geplant. Beide sind
technisch abgeschlossen; Ist-Arbeitsstunden wurden nicht erfasst und werden
nicht nachträglich geschätzt. AP7.0 ist lokal abgeschlossen; Ist-Stunden
werden nicht nachträglich geschätzt. AP7 besitzt weiterhin keinen
freigegebenen Ausführungs- oder belastbaren Restkorridor.

Die Kalenderkorridore enthalten keinen externen IONOS-, Stripe-, Brevo- oder
Rechtsprüfungswarteanteil. Der erste schreibende AP2-Schritt ist auf
`feature/shop-ap2` isoliert; der AP1-Abschlusscommit ist bereits vorhanden.

## 7. Freigaben und nächste Schritte

| Bereich | Status |
| --- | --- |
| AP1 | freigegeben und lokal abgeschlossen |
| AP2.1 bis AP2.5 | freigegeben und abgenommen |
| AP2a | freigegeben und abgenommen |
| AP3 | freigegeben und vollständig abgenommen |
| AP3b | technisch vollständig abgenommen |
| AP4 | freigegeben und vollständig abgenommen |
| AP5 | freigegeben und vollständig abgenommen |
| AP6 | vollständig abgenommen |
| AP7.0 | lokal technisch bereit; keine Produktionsfreigabe |
| AP7.2b | App- und Draft-Synchronisierungsblocker geschlossen; Startprodukt ausstehend |
| AP7.3b | vollständige V2-Produktkette technisch geschlossen; Startprodukt ausstehend |
| AP7.3d | V2-Testwebsite serverseitig verifiziert; keine Produktionsfreigabe |
| AP7.3e | AP7-Integrationsstand technisch bereit; Startprodukt und Produktionsgates ausstehend |
| AP7.3g | Testumgebung vollständig AP7-fähig und bereinigt; keine Produktionsfreigabe |
| AP7.5 | produktionsfähiges V2-Produkt-API-Artefakt lokal bereit; PR-/CI-Nachweis und Produktionsfreigabe getrennt |
| AP7.7a | verschlüsselter Backupvertrag lokal implementiert und isolierter IONOS-Test-E2E bestanden; OneDrive-/Produktionsaktivierung und produktives Restore-Gate ausstehend |
| AP7 und später | nicht freigegeben |
| Produktion/Cutover/Deployment | nicht freigegeben |

Die Praxisnachweise von AP2 bis AP6 einschließlich AP3b sind bestanden. Die
freigegebenen Rechts-, Datenschutz- und Versandfassungen sind dem
Produktions-Legal-Bundle `cmj-production-legal-2026-08-07-v3` zugeordnet.
AP7.0 hat ausschließlich lokale Produktionsartefakte und Gates vorbereitet.
AP7, Deployment und Produktions-Cutover bleiben unangetastet und benötigen
einen gesonderten ausdrücklichen Auftrag.
