# Carmaja-Perlen Shop – finaler V1-Implementierungsplan mit V2-Ausbau

Stand: 2026-08-08
Änderungsvermerk: AP1, AP2, AP2a, AP3, AP4 und AP5 sind abgeschlossen und
abgenommen. AP3b ist für `card`, `paypal`, `klarna` und `sepa_debit` technisch
vollständig abgenommen; die AP6-Gesamtregression wurde danach erfolgreich
wiederholt. Rechts-, Datenschutz- und Versandfassung vom 2026-08-07 sind dem
freigegebenen Produktions-Legal-Bundle `cmj-production-legal-2026-08-07-v3`
zugeordnet. AP6 ist vollständig abgenommen. AP7.0 hat das lokale
Produktionspaket auf Basis des AP6-Commits vorbereitet und technisch geprüft;
AP7 und jede Produktionsmutation bleiben nicht freigegeben.
AP1-Abschlusscommit: `21da119db1c57be095764f8f75bb0c9863ec1759`  
AP2-Abschlusscommit: `b874baa410b54894ca462326f402ead859370ab6`
AP5-Abschlusscommit: `4a0b7e6a937a4bc0174eb40687b270437f7f2ccf`
AP6-Abschlusscommit: `bb4345fbfb26fede5bdf61be0ef6191746a98ef0`
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
abgenommen. AP7 bleibt nicht freigegeben. Es gibt keinen produktiven Cutover,
kein Deployment, keinen Push und keinen Zugriff auf Produktionsdaten.

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
| AP7 und später | nicht freigegeben |
| Produktion/Cutover/Deployment | nicht freigegeben |

Die Praxisnachweise von AP2 bis AP6 einschließlich AP3b sind bestanden. Die
freigegebenen Rechts-, Datenschutz- und Versandfassungen sind dem
Produktions-Legal-Bundle `cmj-production-legal-2026-08-07-v3` zugeordnet.
AP7.0 hat ausschließlich lokale Produktionsartefakte und Gates vorbereitet.
AP7, Deployment und Produktions-Cutover bleiben unangetastet und benötigen
einen gesonderten ausdrücklichen Auftrag.
