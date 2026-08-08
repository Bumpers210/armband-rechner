# AP6-Abnahmebericht

Stand: 2026-08-07
Status: **AP6 vollständig abgenommen**

AP6 wurde nach AP3b für `card`, `paypal`, `klarna` und `sepa_debit`
vollständig technisch wiederholt. Die Arbeiten liefen isoliert im Worktree
`G:\BS-Stein-Hart-ap6` auf `feature/shop-ap6`, ausschließlich mit
künstlichen Daten und privaten Testkonfigurationen. AP7 wurde nicht begonnen.
Produktion, `main`, Push und Deployment blieben unverändert.

## Technische Gesamtregression

- PHP 8.4.23 mit prozesslokal aktivierten Erweiterungen `mbstring`, GD,
  `exif`, PDO/`pdo_mysql`, `sodium`, `curl`, `openssl` und `intl`: Lint ohne
  Syntaxfehler; Commerce 16/16, AP4 4/4, AP5 9/9, Admin 20/20, Bootstrap 9/9,
  Product-API 46/46 und Stripe 4/4 bestanden.
- Website: vollständige Node-Suite 73/80; nur die sieben unveränderten
  CRLF-/Shell-Basisfehler bleiben. `npm run lint:test`, `npm run build:test`
  und `npm audit --omit=dev` bestanden; Audit meldet 0 Schwachstellen.
- Android: `testDebugUnitTest`, `lintDebug` und `assembleBeta` bestanden. Die
  erzeugte APK-Signatur ist gültig und entspricht dem im Workflow
  festgeschriebenen Beta-Zertifikatsfingerabdruck.

## MySQL, Stripe und Wiederanlauf

- Der bereits bestandene praktische MySQL-8-/InnoDB-Nachweis wurde gemäß
  Auftrag nicht unnötig wiederholt. Er umfasst Vorwärtsmigrationen,
  Transaktionen, Rollback, zehn parallele Clients, Deadlock, Crash-Rollback,
  Lease-Übernahme, Idempotenz, Backup/Restore, Struktur-/Inhaltsvergleich und
  Manipulationserkennung. Beide isolierten Ziele waren danach leer.
- Stripe-Sandbox: Card, PayPal, Klarna sowie SEPA mit asynchronem Erfolg und
  Fehler bestanden. Während `processing` blieb das Unikat reserviert und es
  entstanden weder Bestellung noch Versandfreigabe.
- Session-Ablauf, vollständige Erstattung ohne Wiedereinlagerung, Streitfall
  mit separatem Reviewcase, Retry, Reconciliation und ungeordnete Events
  wurden erfolgreich geprüft.
- Der Worker lädt vor Completed-/Async-Erfolg-/Async-Fehlerverarbeitung den
  aktuellen Checkout-Session-Zustand und finalisiert nur nach eindeutigem
  Stripe-Nachweis.
- Die persistente Inbox vor `2xx`, Deduplizierung und die exakte
  Neun-Ereignis-Allowlist sind nachgewiesen.

## Brevo und IONOS-Betrieb

- Brevo-Testkonto: realer Testversand, deterministische UUID-v4-Idempotenz im
  JSON-Feld `headers.idempotencyKey`, Duplicate-Behandlung als terminales
  `delivery_unknown`, Retry und auditierter manueller Neuversand bestanden.
  Ein zunächst im falschen HTTP-Header gesendeter Schlüssel wurde anhand des
  praktischen Providerverhaltens korrigiert und erneut erfolgreich geprüft.
- IONOS-UnixCron `*/5`: zwei echte Workerläufe im Abstand von 298 Sekunden,
  Laufzeiten von 16 und 20 ms, Lock, Lease und Datenbank-Runlog bestanden.
  Der temporäre Cronjob wurde entfernt; anschließend waren keine weiteren
  Läufe vorhanden.

## Akzeptiertes V1-Restrisiko

Die IONOS-MySQL-Verbindung erzwingt eine aktive TLS-Sitzung; `Ssl_cipher` und
`Ssl_version` müssen befüllt sein. Da kein nutzbarer CA-/Hostidentitätsnachweis
vorliegt, bleibt die fehlende Zertifikatsprüfung das ausdrücklich akzeptierte
V1-Restrisiko. Ein unverschlüsselter Fallback bleibt unzulässig.

## Cleanup

- Temporärer Stripe-Webhook, IONOS-Cronjob, Stage, Endpunkte, Runmarker,
  Testtabellen und künstliche Daten wurden entfernt.
- Private Stripe-, Commerce- und Brevo-Testkonfigurationen wurden entfernt.
- Die isolierten MySQL-Testdatenbanken sind leer.
- Das Beta-Signingmaterial liegt nur im ignorierten lokalen Signingordner;
  es wird weder ausgegeben noch versioniert. Produktionssignierung blieb
  unverändert.
- `website/hosting/**`, Testwebsite, Produktion, `main`, Push und Deployment
  blieben unverändert.

## Rechts-, Datenschutz- und Versandfreigabe

Die Shopbetreiberfreigabe liegt für die Fassungen vom 2026-08-07 vor. Sie ist
dem Paket `website/docs/legal-review/ap6-2026-08-07-v1/` und dem unveränderlich
gehashten Produktions-Legal-Bundle `cmj-production-legal-2026-08-07-v3`
zugeordnet. Das Bundle besitzt den Status `approved` und ist technisch für
Checkout-Snapshots zulässig.

Freigegeben sind der Einzelkauf ohne Warenkorb, die vier Stripe-Zahlungsarten,
der beschriebene Vertragsschluss, Datenschutz und elektronischer Widerruf sowie
der Versand innerhalb Deutschlands als Maxibrief der Deutschen Post bis
1.000 g für 2,70 EUR. Die Basis-Sendungsverfolgung besitzt regelmäßig keinen
Zustellnachweis; Haftung oder Versicherung sind nicht enthalten. Dieses
Versand- und Klarna-Nachweisrisiko ist von der Betreiberfreigabe umfasst.

Manifest, Inhalts- und Dateihashes dokumentieren die exakten Fassungen. Das
Repository speichert keine Identität, Unterschrift, Kommunikationskopie oder
vertrauliche Anlage einer freigebenden Person.

Urteil: **AP6 vollständig abgenommen. AP7 bleibt nicht freigegeben.**
