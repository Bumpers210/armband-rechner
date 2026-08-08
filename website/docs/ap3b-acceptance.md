# AP3b-Abnahme – Vier Zahlungsarten

Stand: 2026-08-07
Status: **technisch vollständig abgenommen; damalige Produktionsfreigaben in AP6 abgeschlossen**

AP3b erweitert den V1-Stripe-Vertrag im Testmodus verbindlich auf `card`,
`paypal`, `klarna` und `sepa_debit`. AP7, Produktion, Deployment, Push und
Cutover bleiben ausgeschlossen.

## Abgenommener Vertrag

- Exakte serverseitige `payment_method_types`-Allowlist mit vier Einträgen;
  Stripe Link, Promotion-Codes, Recovery und dynamische Zahlungsarten bleiben
  deaktiviert.
- Separate Zahlungsachse `processing` und normalisierte
  `payments.payment_method_type`.
- `checkout.session.completed` finalisiert nur bei bestätigtem
  `payment_status=paid`; andernfalls bleibt das Unikat reserviert.
- `checkout.session.async_payment_succeeded` finalisiert atomar und
  idempotent; `checkout.session.async_payment_failed` gibt die Reservierung
  atomar frei.
- Kein Auftrag, keine Bestellnummer, keine Mail-Outbox und kein Versand vor
  endgültigem Zahlungserfolg.
- Gaststatus für laufende Zahlungen einschließlich SEPA-Bearbeitungshinweis;
  Zahlungsart und Bearbeitungszustand sind im Admin sichtbar.
- Refund-, Dispute-, Lease-, Retry- und Reconciliation-Verträge bleiben
  getrennt und gelten für alle vier Zahlungsarten.
- Die Legal-Bundles v2 bleiben bis zur externen Prüfung
  `awaiting_external_approval`. Das erhöhte Klarna-Risiko bei Versand ohne
  Sendungsverfolgung bleibt ein ausdrückliches Produktions-Gate.

## Praktische Nachweise

- MySQL 8/InnoDB: Vorwärtsmigration, Wiederholbarkeit, Transaktionen,
  Parallelität mit zehn Clients, Deadlock, Crash-Rollback, Lease-Übernahme,
  Backup/Restore, Struktur-/Inhaltsvergleich und Manipulationserkennung
  bestanden. Beide isolierten Testdatenbanken wurden anschließend geleert.
- Stripe-Sandbox: Card, PayPal und Klarna jeweils erfolgreich und genau einmal
  bestellt. SEPA wurde zunächst als `processing` mit blockierender Reservierung
  und ohne Bestellung gespeichert; asynchroner Erfolg erzeugte genau eine
  Bestellung, der asynchrone Fehlerfall keine Bestellung und gab die
  Reservierung frei.
- Stripe-Sonderfälle: Session-Ablauf, vollständige Erstattung ohne automatische
  Wiedereinlagerung, Streitfall mit getrenntem Reviewcase sowie ungeordnete
  Zustellung von Async-Erfolg und Completed wurden erfolgreich verarbeitet.
- Die persistente Webhook-Inbox, Deduplizierung und die exakte
  Neun-Ereignis-Allowlist wurden praktisch nachgewiesen.
- IONOS-UnixCron: zwei echte Läufe im Abstand von 298 Sekunden, mit Lock,
  Lease, Datenbank-Runlog und Laufzeiten von 16 beziehungsweise 20 ms.
  Cronjob und Laufmarker wurden entfernt.
- Brevo: echter Testversand, deterministische UUID-v4-Idempotenz im
  Request-Body, Duplicate-Behandlung als terminales `delivery_unknown`, Retry
  und auditierter manueller Neuversand bestanden.
- Stabile Beta-Signierung: `assembleBeta` bestanden; die APK-Signatur ist
  gültig und entspricht dem festgeschriebenen SHA-256-Fingerabdruck.

## Regression

- PHP 8.4.23 mit `mbstring`, GD, `exif`, PDO/`pdo_mysql`, `sodium`, `curl`,
  `openssl` und `intl`: Lint bestanden.
- PHP: Commerce 16/16, Stripe 4/4, AP4 4/4, AP5 9/9, Product-API 46/46,
  Admin 20/20 und Bootstrap 9/9 bestanden.
- Vollständige Node-Suite: 70/77 bestanden; ausschließlich die sieben
  unveränderten CRLF-/Shell-Basisfehler schlagen fehl.
- `npm run lint:test`, `npm run build:test` und `npm audit --omit=dev`
  bestanden; Audit meldet 0 Schwachstellen.
- Android: `testDebugUnitTest`, `lintDebug` und `assembleBeta` bestanden.

## Cleanup und verbleibende Gates

Der temporäre Stripe-Webhook, Stripe-/Commerce-/Brevo-Konfigurationen,
Stage-Dateien, Cronjob, Laufmarker und künstliche Daten wurden entfernt. Das
festgeschriebene Beta-Signingmaterial liegt ausschließlich im ignorierten
lokalen Signingordner und wird nicht versioniert.

AP3b ist technisch vollständig abgenommen. Die zu diesem Zeitpunkt noch
offenen Rechts-, Datenschutz- und Versandfreigaben wurden anschließend in AP6
für die versionierten Fassungen vom 2026-08-07 abgeschlossen. Maßgeblich ist
`website/docs/ap6-acceptance.md`. AP7 bleibt nicht freigegeben.
