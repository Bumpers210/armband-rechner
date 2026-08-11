# AP7.0 – lokale Produktionsbereitschaft

Stand: 2026-08-11

Basis: AP6-Commit `bb4345fbfb26fede5bdf61be0ef6191746a98ef0`

Worktree: `G:\BS-Stein-Hart-ap7`, Branch `feature/shop-ap7`

AP7.2b-Integrationsstand: `G:\BS-Stein-Hart-ap7-integration`, Branch
`codex/ap7-integration`

AP7.3b-V2-Kettenstand: `G:\BS-Stein-Hart-ap73b`, Branch
`codex/ap7-v2-chain`

AP7.3d-Commit: `3ae346c095d8564c07ce6539a70d38a936a20ff6`

AP7.3e-Integrationsstand: `G:\BS-Stein-Hart-ap7-integration`, Branch
`codex/ap7-integration`

AP7.3g-Testbetriebsnachweis: IONOS-Testumgebung, abgeschlossen und bereinigt am
2026-08-08

Aktueller `main`-Stand: `0fbe085d4c6ddc0f801b88354167ec22adef0049`

Aktuell bereitgestellter Produktionswebsite-Stand:
`2cd1c5a2daeb8451a2ccc59e2795d2a79765f56c`

## Urteil

Der Android-Versions- und Draft-Synchronisierungsblocker ist in AP7.2b
geschlossen. AP7.3b hat anschließend die vollständige V2-Kette von App und
Draft über Web-API, serverseitige Version/Hash, Publisher und öffentliche
Projektion lokal und isoliert auf IONOS nachgewiesen. AP7.3d hat den
geschützten V2-Testwebsite-Stand serverseitig verifiziert; AP7.3e hat die
vollständige V2-Kette anschließend konfliktfrei in den AP7-Integrationsbranch
übernommen und vollständig regressionsgeprüft. AP7.3g hat danach die private
Test-Shop-Laufzeit einschließlich aller vier Zahlungsarten, Webhooks, Worker,
Brevo, Widerruf und IONOS-UnixCron vollständig nachgewiesen und bereinigt.
AP7.5 bindet den getesteten Produktvertrag V2 nun auch an das ausschließlich
manuell ausführbare Produktions-API-Artefakt; Publisher und automatische
Deployments bleiben gesperrt. Der AP7-Integrationsstand und die Testumgebung
sind technisch bereit. Genau ein reales V2-Startprodukt ist inzwischen lokal
im weiterhin unfreigegebenen Cutovermanifest vorbereitet. Sein früherer
`stock`-Wert wurde ausschließlich lesend aus der unveränderten
Migrationssicherung bestätigt. Produktaktivierung, Cutover und weitere
Produktionsdeployments bleiben bis zur erneuten Versions-/Hashprüfung nach
einer separat freizugebenden Aktivierung und bis zur Erfüllung aller übrigen
Produktionsgates gesperrt. Die statische Produktionswebsite und die
freigegebenen Legal-Seiten sind mittlerweile SHA-gepinnt bereitgestellt und
verifiziert; Produktprojektion, Publisher, Checkout und Bestand bleiben
fail-closed.

## Geschlossene lokale Blocker

- Bootstrap und Laufzeitvertrag verlangen die exakte Zahlungsarten-Allowlist
  `card`, `paypal`, `klarna`, `sepa_debit`.
- Der öffentliche Produktions-API-Einstieg lädt ausschließlich einen explizit
  gesetzten privaten Bootstrap außerhalb seines Webroots und besitzt keinen
  Testpfad-Fallback.
- Das private Produktions-API-Artefakt enthält den getesteten
  `product-api-v2.php`-Vertrag. Der Produktions-Bootstrap routet `/v2`, ohne
  Shopprogramme oder Testkonfiguration einzubinden. Bei deaktiviertem
  Produktionspublisher wird ein V2-Publish vor jeder Produktmutation mit
  `production_publish_disabled` abgelehnt.
- Der Deploymentvertrag trennt statische Website, öffentlichen API-Webroot,
  privates Programm, private Konfiguration und Worker. Der endgültige private
  Workerpfad ist `/home/www/carmaja-private-shop/worker.php`; UnixCron startet
  ihn alle fünf Minuten mit `/usr/bin/php8.4`.
- Das Website-Deployment von `main` ist nur noch manuell, SHA-gepinnt,
  umgebungsgebunden und durch Bestätigung sowie die standardmäßig deaktivierte
  Repository-Variable `CARMAJA_PRODUCTION_DEPLOY_ENABLED` gesperrt.
- Produktionshosting, Klicktracking, Statistik und Export enthalten keine
  externen Verkaufsziele oder Produkt-Klickparameter. Der eigene Shop ist der
  einzige vorgesehene Verkaufskanal.
- Website und v2-Publisher verwenden den öffentlichen Produktvertrag v2. Er
  enthält weder `stock` noch externe Verkaufsfelder. Die statische Website
  besitzt keinen Bestandsfallback.
- Das Cutover-Werkzeug ist CLI-only, standardmäßig schreibfrei und verlangt
  ein versioniertes, hashgebundenes Manifest, eine explizite Bestätigung,
  eine private Produktionskonfiguration sowie genau ein autoritatives
  v2-Produkt. Es prüft den serverseitigen `sourceHash` erneut.
- Versand (`deutsche-post-maxibrief`, 270 Cent, EUR), Legal Bundle
  `cmj-production-legal-2026-08-07-v3` und die vier Zahlungsarten sind in
  Deploymentvertrag, Laufzeitvorlage und Cutovermanifest identisch.

## Lokale Nachweise

- PHP 8.4: alle relevanten Dateien ohne Syntaxfehler; 118/118 PHP-Tests.
- Node vollständig: 121/130 Tests; ausschließlich die neun unveränderten,
  bereits für AP7.3b gegen den sauberen Basisstand reproduzierten
  Windows-CRLF-/Bash-Basisfehler bleiben bestehen. Die 29 gezielt
  CRLF-unabhängigen V2-/AP7-Vertragstests bestehen vollständig.
- `npm run lint:test`: bestanden.
- `npm run build:test`: bestanden und Testexport verifiziert.
- `npm run build` mit ausschließlich künstlicher v2-Fixture: bestanden und
  Produktionsexport verifiziert.
- `npm audit --omit=dev`: 0 bekannte Schwachstellen.
- Android AP7.2b: Der autoritative App-Stand `app-v1.1.2` aus Commit
  `4cbd0489628d0c5e7e347d376df67bd12342fe5e` ist kontrolliert integriert.
  Der Produktionsbuild verwendet `versionCode 4` und `versionName 1.1.2`.
  `braceletSizeCm` und `pearlSizeMm` bleiben im Draft-, Editor-, lokalen
  Speicher-, API-, Server- und Synchronisierungsweg erhalten. Die bereits
  integrierten AP7-Shopmodelle wurden nicht überschrieben.
- `testDebugUnitTest`, `lintDebug` und der explizit freigegebene, weiterhin
  als unsigniert verifizierte CI-Prüfbuild sind bestanden. Außerhalb dieses
  eng begrenzten Prüfschalters bleibt `assembleRelease` ohne
  Produktionssignatur gesperrt. Das APK weist Paket
  `de.carmajaperlen.armbandrechner`, `versionCode 4` und `versionName 1.1.2`
  aus.
- Der explizite Unit-Roundtrip lädt einen bestehenden Draft, ändert
  Armbandumfang und Perlengröße, speichert und synchronisiert V2 und lädt
  beide Werte identisch erneut. Der entsprechende Repository-/Gerätetest ist
  kompiliert und gelintet; lokal stand kein Emulator für seine Ausführung zur
  Verfügung.
- AP7-Vertragstests: PHP 7/7 und Node 4/4 bestanden.
- AP7.2b-Vertragstests: 21/21 relevante Node-Tests, 46/46 Product-API-Tests,
  10/10 Bootstrap-Tests und der Produktions-Bootstrap-Test bestanden.
- AP7.3b: App-Speichern verwendet den vollständigen V2-Payload mit
  `expectedProductVersion` und einer über unklare Ausgänge stabilen
  Idempotenz-ID. `productVersion` und `sourceHash` werden ausschließlich
  serverseitig erzeugt. Preis, EUR-Währung, Verkaufsfreigabe,
  `braceletSizeCm` und `pearlSizeMm` bleiben nach Speichern, Bildtransfer,
  Publizieren und erneutem Laden identisch.
- AP7.3b Android: `testDebugUnitTest`, `lintDebug` und das mit dem vorhandenen
  stabilen Beta-Zertifikat gebaute `assembleBeta` sind bestanden. Lokal war
  kein Emulator für Instrumentierungstests angeschlossen.
- AP7.3b PHP: 118/118 Tests und die Lints der geänderten V2-Dateien mit PHP
  8.4.23 bestanden. Die künstliche Kette prüft zusätzlich Idempotenz,
  serverseitigen Hash und die öffentliche Legacy-Feldsperre.
- AP7.3b Node/Build: alle 121 fachlich ausführbaren Tests bestanden; die neun
  fehlgeschlagenen Shell-Testfälle liegen vollständig in gegenüber der Basis
  unveränderten CRLF-Dateien. `npm run lint:test`, `npm run build:test` und
  `npm run build` einschließlich V2-Produktionsexport sind bestanden.
  Ein temporärer sauberer Worktree auf Basiscommit `2ad2c6c4e028c3ff81a0574540e9842753725d7f`
  reproduzierte exakt dieselben neun Fehlernamen bei identischen 22 Fällen
  (`13` bestanden, `9` fehlgeschlagen). Alle 21 beteiligten Test- und
  Shellpfade waren bytegleich und enthielten bereits auf der Basis CRLF; der
  temporäre Worktree wurde anschließend vollständig entfernt.
- AP7.3b IONOS: Ein token-geschützter Web-SAPI-Endpunkt unter einem eigenen
  temporären Testunterordner wies mit ausschließlich künstlichen Daten den
  Ablauf V2-Login → idempotentes Speichern → Bild-Upload → serverseitige
  Version/Hash → Publisher → öffentliche V2-Allowlist → erneutes Laden nach.
  `salesEnabled` blieb `false`; `stock` und `vintedUrl` waren nicht öffentlich.
  Privater Stagingbereich, Webendpunkt, künstlicher Nutzer, Token, Bild,
  Produkt und öffentliche Projektion wurden anschließend vollständig entfernt.
- AP7.3d Testwebsite: Der geschützte Testexport verwendet ausschließlich die
  Test-API und die öffentliche V2-Projektion. Das erhaltene Test-Armband ist
  sichtbar, bei `salesEnabled=false` als „Nicht verfügbar“ gekennzeichnet und
  nicht kaufbar. Detailseite, Bilder, Legal-Seiten und Footer sind erreichbar;
  Vinted-/Marktplatzdaten fehlen. Der serverseitige Release ist `verified`.
- AP7.3e Integration: `codex/ap7-integration` enthält den vollständigen
  V2-Kettenstand bis Commit `3ae346c095d8564c07ce6539a70d38a936a20ff6`.
  Bestehende AP7.2b-/`main`-Änderungen wurden erhalten. Produktions-App
  `1.1.2`/Code `4` verwendet ausschließlich die Produktions-API; Beta
  `1.1.3-beta.1`/Code `5` verwendet ausschließlich die Test-API und das
  gepinnte Beta-Zertifikat. Android Unit/Lint/Beta/Release-Vertrag, alle
  PHP-Tests, V2-/AP7-Node-Verträge, Testbuild, künstlicher Produktionsbuild,
  Export-, Geheimwert- und Diff-Prüfung sind bestanden.
- AP7.3g Publisher/Runtime: Die öffentliche Testprojektion erfüllt unmittelbar
  `{version, products}`. MySQL-8-/InnoDB-Commerce, Test-Stripe, Test-Brevo,
  Maxibrief-Testversand, Test-Legal-Bundle und privater Worker wurden nur in
  privaten Testpfaden bereitgestellt. Das reale Test-Armband blieb unverändert
  und nicht kaufbar; ein separates künstliches Produkt trug den Testbestand.
- AP7.3g E2E: Shopsitzung, CSRF, CORS, `no-store`, Live-Produktdaten,
  Checkout-Start, Karte, PayPal, Klarna und SEPA-Lastschrift, asynchrone
  SEPA-Finalisierung, Webhook-Inbox, Worker, atomare Bestellung,
  Bestandsänderung, Brevo-Outbox, zweistufiger Widerruf, Legal-Snapshot sowie
  Admin-/Reviewanzeige wurden live nachgewiesen. Session-Ablauf löste
  Reservierung und offene lokale Zahlung ohne Bestellung kontrolliert auf.
- AP7.3g Worker: direkter Lauf, paralleler Lockversuch, Lease/Runlog und zwei
  echte IONOS-UnixCronläufe im Abstand von fünf Minuten bestanden; alle
  Laufzeiten lagen unter 40 Sekunden. Testcron, Worker, künstliche Daten,
  Testwebhook, private Laufzeitkonfiguration und weitere temporäre Artefakte
  wurden entfernt. Testwebsite und Test-API blieben fail-closed; Produktion
  und `main` wurden nicht verändert.
- AP7.3g Regression: 119/119 relevante PHP-Tests, 23/23 gezielte
  V2-/Shop-Node-Tests, `npm run lint:test`, `npm run build:test`,
  Geheimwertprüfung und `git diff --check` bestanden. Der vollständige
  Node-Lauf bestand 122/131 Fälle und reproduzierte ausschließlich die neun
  bereits dokumentierten CRLF-/Bash-Basisfehler.
- AP7.5 Produktionsartefakt: 11 produktive PHP-Dateien wurden mit PHP 8.4.23
  gelintet. Product-API 47/47, Bootstrap 10/10, AP7-Vertrag 7/7 sowie
  Produktions-Bootstrap, Produktions-Admin und der neue produktive
  V2-Vertrag sind bestanden. Die zwei Workflow-Vertragstests und
  `npm run lint:test` sind bestanden. Eine lokale Artefaktsimulation enthielt
  exakt sieben erlaubte Dateien, keine Runtime-/Secretdatei und keinen
  Testpfad; SHA-256-Prüfung und Cleanup waren erfolgreich. Der vollständige
  Node-Lauf blieb fachlich unverändert; unter der eingeschränkten
  Windows-Sandbox waren die neun bekannten Bash-/CRLF-Fälle sowie ein
  temporärer Export-Schreibtest nicht lokal ausführbar. Die Linux-PR-CI bleibt
  der verbindliche Vollnachweis.
- AP7.7a Backup-E2E: Der private Backupdienst wurde mit zwei getrennten,
  anfangs leeren MySQL-8-Testdatenbanken und ausschließlich künstlichen Daten
  auf IONOS nachgewiesen. Aktive TLS-Sitzungen, Secretstream-Verschlüsselung,
  HMAC-Manifest, Lock, Dump/Restore, semantischer Schema-/Inhaltsvergleich,
  idempotente Quittierung und Status bestanden. Beide Testdatenbanken sowie
  Runtime, Programme, Backupverzeichnisse und lokaler DPAPI-Cache wurden
  vollständig bereinigt. OneDrive-Pull und Produktions-Restore bleiben eigene
  Gates.

## Produktionsnachweis vom 2026-08-11

- Der vollständige Integrationsstand ist über PR #38 nach `main` übernommen;
  die private Bootstrapkorrektur folgte kontrolliert über PR #43 und der
  Readinessstand über PR #44. Der aktuelle Produktionsbasisstand ist
  `2cd1c5a2daeb8451a2ccc59e2795d2a79765f56c`.
- Private Runtime (`0600`), Produktionspfade, MySQL-8-/InnoDB-Verbindung und
  aktive TLS-Sitzung sind nachgewiesen. Die fehlende CA-/Hostidentitätsprüfung
  bleibt das ausdrücklich akzeptierte V1-Restrisiko.
- Die drei freigegebenen Migrationen sind angewendet. Ihre normalisierten
  Datei- und Journalhashes stimmen mit dem weiterhin unfreigegebenen
  Cutovermanifest überein. Das freigegebene Legal Bundle ist vorhanden,
  `approved` und hashgleich.
- Privates Shop-/Workerpaket und kombinierter öffentlicher API-Einstieg sind
  hashgeprüft bereitgestellt. CORS, `no-store`, fail-closed Produktantworten
  und Produkt-API-v2-Kompatibilität sind bestanden. Es entstand dabei keine
  Reservierung, Zahlung, Bestellung, Mail oder Bestandsänderung.
- Der Produktionsworker läuft mit `*/5 * * * *`. Direktlauf, paralleler Lock,
  Lease, Runlog und zwei echte Cronläufe sind bestanden; beide Workerachsen
  sind fehlerfrei und besitzen keine aktive Lease.
- Genau ein produktives Adminkonto wurde über die private CLI angelegt. Das
  Konto ist aktiv, verwendet Argon2id und der Browser-Login wurde durch die
  Betreiberin erfolgreich bestätigt. Der öffentliche Admin-Einstieg ist
  erreichbar; ohne Sitzung antwortet die API korrekt mit `401`, CORS-Bindung
  an die Produktionswebsite und `no-store`.
- Der stündliche verschlüsselte Backupdienst und der 30-minütige Windows-Pull
  sind aktiv. `backup status` meldet weder Server- noch Offsite-Überfälligkeit;
  der erste produktive Restore-Dry-Run und die OneDrive-Websichtbarkeit sind
  bereits bestanden. Das automatisch um `2026-08-11T17:18:00Z` erzeugte
  Backup wurde im regulären Windows-Tasklauf mit Ergebnis `0` vollständig in
  OneDrive abgelegt und um `2026-08-11T17:38:07Z` serverseitig quittiert.
- Das reale Startprodukt stimmt weiterhin exakt mit der vorbereiteten Auswahl
  überein: Modell 2, Version 1, Hash
  `09cd71d56561b08b8373c3bc804d3298b47096c470751da3407a5e0eff1e4444`,
  2800 Cent, EUR, 18 cm, 8 mm, zwei Bilder und `salesEnabled=false`. `stock`
  und `vintedUrl` fehlen. Das Produkt ist noch nicht in Commerce importiert;
  alle Bestands- und Geschäftsobjekte sind leer.
- Alle externen Verkaufsangebote sind nach Betreiberbestätigung entfernt. Die
  Produktionswebsite wurde über den geschützten manuellen Workflow exakt aus
  `2cd1c5a2…` bereitgestellt. Build, Aktivierung, Smoke-Test und
  `mark_verified` sind bestanden; der Deployschalter wurde anschließend wieder
  auf `false` gesetzt. Website, Legal-Seiten und Footer antworten erfolgreich,
  enthalten keine Vinted-/Marktplatz- oder Testressourcen und zeigen weiterhin
  kein kaufbares Produkt.
- Brevo Live ist lesend erreichbar; der konfigurierte Produktionsabsender ist
  vorhanden und aktiv. Stripe Live ist lesend erreichbar. Die produktive
  Terms-of-Service-URL ist gespeichert; Karte, Apple Pay, Google Pay, Klarna
  und SEPA-Lastschrift sind aktiv. PayPal bleibt auf Betreiberwunsch
  deaktiviert und wird von der reduzierten V1-Allowlist nicht angefordert.
  Checkout, Produktaktivierung und Cutover bleiben bis zur Übernahme und
  Bereitstellung dieses Vertrags weiterhin gesperrt.

## Verbleibende Produktionsgates

1. Den aktualisierten Readiness-/Manifeststand prüfen und nach gesonderter
   Freigabe nach `main` übernehmen. Der Manifeststatus bleibt
   `prepared_awaiting_cutover_approval`.
2. Unmittelbar vor jeder Checkout- oder Verkaufsaktivierung die exakte
   Allowlist `card`, `klarna`, `sepa_debit` sowie Google Pay auf Kartenbasis
   nachweisen. PayPal bleibt deaktiviert und darf nicht angefordert werden.
3. Webhook-Allowlist, Terms-Consent, deaktiviertes Link/Recovery/Promotion
   Codes und alle übrigen Stripe-Live-Checkoutparameter ohne
   Zahlungserzeugung final abgleichen.
4. Unmittelbar vor dem Cutover ein neues verschlüsseltes Produkt- und
   Commerce-Backup erzeugen, den Offsite-Abruf bestätigen und den
   Restore-Dry-Run erneut bestehen.
5. In einem kontrollierten Wartungsfenster `salesEnabled=true` ausschließlich
   für das Startprodukt setzen. Die dadurch neu entstehenden Werte für
   `productVersion` und `sourceHash` erneut lesen, prüfen und in das Manifest
   binden.
6. Das Manifest nach Vier-Augen-Prüfung separat freigeben, den Cutover zuerst
   im Planmodus prüfen und erst nach ausdrücklicher Apply-Freigabe
   `commerce_products` sowie `commerce_inventory.onHand=1` initialisieren.
7. Publisher und Produktprojektion in der festgelegten Reihenfolge separat
   freigeben, die bereits bereitgestellte Produktionswebsite prüfen, genau eine
   echte Gastbestellung durchführen und das Beobachtungsfenster ohne weitere
   Produktfreigabe abschließen.

Jede Abweichung bei Produktidentität, Hash, Preis, Bestand, Migration,
Zahlungszuordnung, Backup, Worker oder Monitoring stoppt neue Checkouts. Ein
Website-Rollback verändert keine Commerce-Daten.
