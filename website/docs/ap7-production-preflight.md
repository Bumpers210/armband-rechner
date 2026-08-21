# AP7 – endgültige Produktions-Preflight-Checkliste

Stand: 2026-08-13

Status: **nicht zur Ausführung freigegeben**

Diese Checkliste ist in der angegebenen Reihenfolge abzuarbeiten. Jeder
Fehler stoppt den Produktionsstart. Kein Schritt darf durch eine Annahme oder
einen Testwert ersetzt werden.

## 1. Release Candidate und Freigabe

- [x] Finalen Integrationsstand per Pull Request und CI prüfen.
- [x] Bestätigen, dass ausschließlich der freigegebene Commit nach `main`
  übernommen wurde.
- [x] Für die Beta-App die getrennte Paket-ID
  `de.steinhart.armbandrechner.test` im APK und im Workflowvertrag nachweisen;
  ein APK mit Produktions-Paket-ID nicht auf einem Testgerät installieren.
- [x] Das korrigierte, signierte Beta-Artefakt aus Workflowlauf `31710343644`
  auf einem realen Testgerät installieren und den Test-API-Roundtrip abnehmen.
- [x] Den freigegebenen Produktionswebsite-Stand
  `01e86d61c4eb6b620e013ccb64522dc6b90adf1c` SHA-gepinnt bereitstellen,
  vollständig live prüfen und `CARMAJA_PRODUCTION_DEPLOY_ENABLED` danach
  wieder auf `false` setzen.
- [ ] Produktionsfreigabe mit Zeitfenster, Betreiberin und Stop-Verantwortung
  schriftlich dokumentieren.

**Aktueller Testnachweis:** Die Betreiberin bestätigte die Installation der
isolierten Test-App und die Anmeldung an der getrennten Testumgebung. Der
anschließende App-Publish von `CP-2026-0006` erreichte über Test-API und
Testbranch die geschützte Testwebsite. Produktübersicht und Detailseite wurden
im verifizierten Testrelease
`a8908a3502bd43f15cc709005e1b28df6e597b0c-31726947719-2` abschließend
abgenommen. Die Produktions-App und die Produktions-API blieben unverändert.

**Stop:** abweichender Commit, unaufgelöster Review oder ungeprüfter Build.

## 2. Startkollektion

- [x] Ares als realen Datensatz aus der autoritativen Produktverwaltung
  auswählen.
- [x] Ares mit der aktuellen App auf Produktmodell 3 speichern und eindeutige
  `productId`, unveränderte SKU/Adresse, monotone `productVersion` und
  serverseitigen 64-stelligen `sourceHash` nachweisen.
- [x] Namen, formatierte Beschreibung, Bilder, Preis `>= 50` Cent und Währung
  `eur` fachlich bestätigen. `stock`, `targetOnHand` und ein Bestandsimport
  sind nicht Teil des Vertrags.
- [x] Produkt- und Bildprojektion mit der öffentlichen Projektion
  vergleichen; keine internen Felder zulassen.
- [x] Ares mit erwarteter Version, erwartetem Hash und dauerhafter
  Vorgangskennung in `website/config/production-collection-cutover-manifest.v2.json`
  eintragen.
- [ ] Manifeststatus erst nach Vier-Augen-Prüfung und freigegebenem Legal
  Bundle v5 auf `approved_for_cutover` setzen.

**Aktueller Nachweis vom 21. August 2026:** Ares wurde mit der Produktions-App
`1.3.0` als Produktmodell 3 gespeichert. Produkt-ID
`3da76a24-3213-4e8f-b9aa-336ea95e4aa3`, SKU `CP-2026-0002`, Adresse
`cp-2026-0002-ares`, Preis 2000 Cent und Währung `eur` blieben stabil. Gebunden
sind Produktversion 4, der serverseitige Quellhash und die dauerhafte
Vorgangskennung `production-collection-cutover-ares-20260821-v1`. Die
vorhandene öffentliche Projektion blieb absichtlich unverändert und nicht
kaufbar; Publisher, Checkout und Shopstart waren durchgehend deaktiviert.
Das Manifest wartet weiterhin auf die getrennte Cutover-Freigabe.

**Stop:** kein exakt passender Ares-Datensatz, fehlende v3-Speicherung,
irgendein Feldkonflikt oder nicht freigegebenes Legal Bundle v5.

## 3. Öffentliche Produktprojektion

- [ ] Öffentliche Produktseite, Produktprojektion, Hosting und Suchindex auf
  unerwünschte externe Kauflinks prüfen.
- [ ] Belegen, dass App und Publisher Verfügbarkeit nur durch
  Veröffentlichen, Archivieren und Wiederherstellen ändern.
- [ ] Belegen, dass Archivieren zuerst neue Checkouts sperrt und danach Seite,
  Sitemap und öffentliche Bilder entfernt.

**Stop:** abweichende Produktprojektion oder ein Checkout trotz Archivierung.

## 4. Produktionsdatenbank

- [x] Exakte Datenbankidentität als ausschließlich produktives Commerce-Ziel
  bestätigen; niemals Test- oder Bestandsdatenbank verwenden.
- [x] MySQL 8, InnoDB, benötigte Rechte und aktive TLS-Sitzung nachweisen.
- [x] Fehlende CA-/Hostidentitätsprüfung als akzeptiertes V1-Restrisiko im
  Produktionsprotokoll bestätigen.
- [x] Vorhandene Tabellen und Migrationen inventarisieren; unerwartete Daten
  stoppen den Lauf.
- [x] Migrationen und die im Manifest gebundenen Datei-/Journalhashes im
  Planmodus prüfen.

**Stop:** unklare Datenbankidentität, fehlendes TLS, falsche Version,
unerwartete Daten oder Hashabweichung.

## 5. Secrets und private Laufzeitkonfiguration

- [x] Private Konfiguration ausschließlich unter
  `/home/www/carmaja-private-shop/config/runtime-config.php` mit Modus `0600`
  anlegen.
- [x] Datenbank-, Stripe-, Webhook-, Payload-Verschlüsselungs-, Brevo-,
  Admin- und Backup-Schlüssel nur auf Vorhandensein und Trennung prüfen.
- [x] Keine Secrets in Repository, Webroot, URL, Cronbefehl, Logs, Manifest
  oder Deploymentartefakt.
- [x] Stripe-Payload- und Backup-Schlüssel-ID samt getrenntem
  Wiederherstellungszugriff dokumentieren.

**Stop:** fehlender, offengelegter, gemeinsam genutzter oder nicht
wiederherstellbarer Schlüssel.

## 6. Stripe Live

- [x] `stripe/stripe-php` exakt `20.3.0`, API- und Webhook-Version gemäß
  Produktionsvertrag verifizieren.
- [x] Genau einen Live-Webhook mit der festgelegten Neun-Ereignis-Allowlist
  und der gepinnten Webhook-API-Version registrieren.
- [ ] Signaturprüfung mit unverändertem Rohpayload in der kontrollierten
  Live-Checkout-/Webhook-Abnahme testen.
- [x] `card`, `klarna`, `sepa_debit` als exakte Allowlist prüfen; Apple Pay und
  Google Pay laufen auf Kartenbasis, PayPal bleibt deaktiviert.
- [x] Link pro Session deaktivieren; Promotion Codes, Recovery und dynamische
  weitere Zahlungsarten deaktiviert lassen.
- [x] 30-minütige Checkout-Laufzeit, Terms-URL, verpflichtende Zustimmung,
  Versand 270 Cent und Legal Bundle v4 in einer nicht zahlungswirksamen
  Konfigurationsprüfung vergleichen.
- [x] Inbox-Persistierung vor `2xx`, Retry, ungeordnete Events und
  Stripe-Abgleich im Produktionsvertrag und in den fokussierten Tests
  bestätigen.
- [ ] Inbox, Retry und Stripe-Abgleich mit einem kontrollierten echten
  Live-Webhookereignis betrieblich nachweisen.

**Stop:** Live-/Testverwechslung, abweichende Version, zusätzliche
Zahlungsart, fehlende Zustimmung oder nicht persistierbarer Webhook.

**Aktueller Live-Nachweis:** Das Livekonto, das gepinnte SDK und genau ein
aktivierter Webhook mit der vollständigen Neun-Ereignis-Allowlist sind lesend
bestätigt. Karte, Klarna, SEPA-Lastschrift und Google Pay sind aktiv; PayPal ist
deaktiviert. Die produktive Terms-of-Service-URL ist gespeichert. Legal Bundle
v4 ist in Produktionsdatenbank und Runtime aktiv und die öffentlichen
Legal-Seiten sind aus dem aktuellen `main`-SHA live verifiziert. Link ist im
Dashboard grundsätzlich verfügbar, wird vom verbindlichen Sessionvertrag aber
mit `wallet_options.link.display=never` unterdrückt. Produktaktivierung,
Cutover, Checkout und Shopstart bleiben bis zu den übrigen Gates gesperrt.

## 7. Brevo Live

- [x] Verifizierten produktiven Absender und API-Zugang bestätigen.
- [x] Bestell-, Betreiber-, Versand- und Widerrufsvorlage gegen die
  freigegebenen Texte prüfen.
- [ ] Idempotency-Key, Deduplizierung, `delivery_unknown`, Retry und
  manuellen auditierten Neuversand prüfen.
- [ ] Dauerhafte Zustellfehler und letzte erfolgreiche Verarbeitung im Admin
  sichtbar machen.

**Aktueller Arbeitsstand:** Brevo-Konto und Absenderliste sind erreichbar. Der
eingestellte Produktionsabsender ist aktiv; die empfangene
Monitoring-Testwarnung bestätigt den tatsächlichen Versandweg. Im Konto
bestehen zwei aktive Einträge für dieselbe Absenderadresse. Die am 13. August
festgestellten Inhalts- und Oberflächenlücken sind im Arbeitszweig geschlossen:
Bestell-, Betreiber-, Versand- und Widerrufsmail verwenden vollständige
fachliche Vorlagen; die Betreiber-Mail ist auf Bestellnummer, Produktname/-ID
und Gesamtbetrag begrenzt. Mailzustände, letzte erfolgreiche Verarbeitung,
Reviewfälle und der bestätigungspflichtige auditierte Neuversand sind in der
Verwaltungsoberfläche sichtbar. Die fokussierten PHP-, Node-, Lint- und
Testbuild-Prüfungen sind bestanden. Der sichtbare Teststand `987fc77` wurde in
Workflow `31807670264`, Versuch 2, aktiviert, live geprüft und als verifiziert
markiert. Die privaten Programme aus Commit `da37149` bestanden auf dem
Testserver mit PHP 8.4 alle 10 AP5- und 17 Commerce-Fälle ausschließlich mit
künstlichen Daten. Die Test-Runtime blieb ohne Commerce-, Stripe- und
Brevo-Zugänge; es entstand weder Checkout noch Mailversand. Die Betreiberin
hat die vier Texte am 14. August 2026 anhand der geschützten Testvorschau
inhaltlich freigegeben; damit ist die erste Checkbox geschlossen. Die beiden
verbleibenden Checkboxen bleiben bis zum getrennt freigegebenen
Produktionsnachweis offen. Der
Ausgangsbefund steht in
`website/docs/ap7-nonpayment-validation-2026-08-13.md`.

Die erneute lesende Prüfung vom 14. August 2026 bestätigte den gesunden
Worker- und Warteschlangenzustand sowie genau einen doppelten aktiven
Brevo-Absendereintrag. Es wurde weder versendet noch bereinigt. Der vollständige
Nachweis und die weiterhin offenen Produktionsgates stehen in
`website/docs/ap7-operational-checks-2026-08-14.md`.

**Stop:** unverifizierter Absender, Testkonto, unklare Vorlage oder fehlende
Fehlersichtbarkeit.

## 8. Backup und Restore

- [x] OneDrive über den vorgesehenen Microsoft-Ablauf mit dem registrierten
  Kontostamm `D:\Carmaja-OneDrive\OneDrive` betreiben und den synchronisierten
  Backupzielordner `D:\Carmaja-OneDrive\OneDrive\Carmaja-OneDrive` nachweisen;
  `D:\Carmaja-Perlen` bleibt unsynchronisiert.
- [x] 32-Byte-Backupschlüssel lokal erzeugen, im Passwortmanager und getrennt
  offline sichern; private Serverdatei und Runtime mit Modus `0600` prüfen.
- [x] Stündlichen privaten Backupdienst (`17 * * * *`) und 30-minütigen
  Windows-Pull ohne HTTP-Route nachweisen.
- [ ] Unmittelbar vor Migration vollständiges verschlüsseltes Produkt- und
  Commerce-Backup erstellen.
- [x] Dateigrößen, SHA-256, Manifest-HMAC, Key-ID, atomare OneDrive-Ablage und
  Sichtbarkeit in OneDrive Web prüfen; erst danach Download quittieren.
- [x] Restore-Dry-Run in ein isoliertes Ziel durchführen; Struktur, Inhalt und
  Prüfsummen vergleichen.
- [ ] Server-RPO 1 Stunde, Offsite-RPO bis 24 Stunden, RTO 4 Stunden,
  Serverrotation 7 Tage sowie OneDrive-Rotation 48/30/12 bestätigen.
- [ ] Alarm nach 90 Minuten ohne Serverbackup und nach 24 Stunden ohne
  bestätigten Download sowie Zugriff der dokumentierten Notfallperson prüfen.

**Aktueller Nachweis:** Das nach der Legal-v4-Aktivierung erzeugte Backup
`20260812T161001Z-3e6dd5bdd009` ist verschlüsselt und hashgeprüft im
festgelegten OneDrive-Ziel abgelegt, serverseitig quittiert und in einem
isolierten Restore-Dry-Run erfolgreich geprüft. Der aktuelle Status meldete
weder Server- noch Offsite-Überfälligkeit. Dieses Backup ersetzt nicht das
unmittelbar vor dem späteren Cutover vorgeschriebene Abschlussbackup.

Eine erneute lesende Prüfung vom 14. August 2026 bestätigte aktuelle, nicht
überfällige Server- und Offsite-Stände, einen erfolgreichen Windows-Task und
einen vollständigen jüngsten OneDrive-Stand. Es wurde kein manuelles Backup
oder Restore ausgelöst. Details stehen in
`website/docs/ap7-operational-checks-2026-08-14.md`.

**Stop:** fehlendes Backup, fehlende Entschlüsselbarkeit oder nicht
bestandener Restore.

## 9. Private API und Worker

- [x] Private Programme nach `/home/www/carmaja-private-shop/program`, Worker
  nach `/home/www/carmaja-private-shop/worker.php` und öffentlichen Einstieg
  in den bestehenden Webroot `/home/www/carmaja-production-api` staged
  bereitstellen. Die Shop-API bleibt unter `/shop/v2`; ein zweiter
  öffentlicher API-Webroot ist ausgeschlossen.
- [x] Release-SHA und alle Artefakthashes vor atomarer Aktivierung prüfen.
- [x] Öffentlicher Einstieg darf nur den privaten Produktions-Bootstrap laden;
  keine Testpfade oder Runtime-Konfiguration im Webroot.
- [x] PHP 8.4, Module, CORS, Cookies, CSRF, `no-store`, Rate Limits und
  fail-closed Produktantworten prüfen.
- [x] Worker direkt mit
  `/usr/bin/php8.4 /home/www/carmaja-private-shop/worker.php
  /home/www/carmaja-private-shop/config/runtime-config.php` testen.
- [x] Laufzeit unter 40 Sekunden, Batch 10–20, Lock, zehnminütige Lease,
  Runlog und Fehleralarm nachweisen.

**Stop:** Pfad-, Hash-, Laufzeit-, Lock-, Lease- oder Sicherheitsabweichung.

## 10. UnixCron und Monitoring

- [x] IONOS-UnixCron mit `*/5 * * * *` und exakt dem privaten Workerbefehl
  anlegen; keine Secrets im Befehl.
- [x] Mindestens zwei echte Läufe im Fünf-Minuten-Abstand nachweisen.
- [ ] Lock, Lease-Übernahme, Runlog, Laufzeit und Fortsetzung eines
  Teilbatches prüfen.
- [x] Alarm für ausbleibenden erfolgreichen Lauf, dauerhafte Outboxfehler,
  Reviewcases, Webhookrückstand und knappen Speicher aktivieren.
- [x] Manuellen privaten Notlauf und Stripe-Abgleich dokumentieren.
- [x] Den separaten Backup-Cron `17 * * * *` und `backup status` ohne Secrets
  prüfen; Worker- und Backup-Cron dürfen sich nicht gegenseitig ersetzen.

**Stop:** kein echter Schedulernachweis oder fehlendes Monitoring.

**Aktiver Nachweis:** Der private, nur lesende Monitor samt gebündelter
Warnung, sechsstündiger Erinnerung, Entwarnung und kontrollierter Testwarnung
ist aus dem nach `main` übernommenen Stand aktiviert. Die Alarmadresse ist
gesetzt, die Betreiberin hat den Eingang der Testwarnung bestätigt und ein
anschließender automatischer Fünf-Minuten-Lauf war ohne Befund. Produkte,
Bestand, Checkout, Zahlungen und Bestellungen blieben unverändert.

Die erneute aggregierte Prüfung vom 14. August 2026 zeigte zwei aktuelle,
fehlerfreie Workerläufe, freie Leases und keine fälligen, endgültig
fehlgeschlagenen oder hängen gebliebenen Warteschlangeneinträge. Der
Produktionszustand wurde dabei nicht verändert. Details stehen in
`website/docs/ap7-operational-checks-2026-08-14.md`.

## 11. Cutover

- [x] Neue Checkouts bis zum ausdrücklich freigegebenen Cutover deaktiviert
  halten.
- [ ] Produktverwaltung für das kurze Cutoverfenster sperren.
- [ ] Alte v2-/v3-Schreibwege nach Aktivierung von `/v4` sperren und Clients
  unter Mindestversion verständlich zum Update auffordern.
- [ ] Finales Backup erneut bestätigen.
- [ ] Cutoveradapter zuerst mit `--mode=plan` ausführen und Ausgabe gegen das
  freigegebene Manifest prüfen.
- [ ] Apply nur mit der expliziten Produktionsbestätigung ausführen.
- [ ] Atomar prüfen: `commerce_products` entspricht Quellversion/-hash,
  `sales_model=collection`, `sales_enabled=1`, keine neue Inventory-Zeile und
  genau ein idempotenter Projektionsvorgang.
- [ ] Nach erstem Kollektionen-Checkout bei Problemen neue Checkouts schließen;
  keine Rückkehr zu `stock` oder `onHand`.

**Stop:** jede Abweichung; bei unklarem Zustand Shop deaktivieren und Daten
nicht zurückschreiben.

## 12. Website und echte Erstbestellung

- [x] Produktionswebsite ausschließlich über den manuellen, SHA-gepinnten
  Workflow und das geschützte Environment aus `01e86d61…` bereitstellen.
- [x] Danach `CARMAJA_PRODUCTION_DEPLOY_ENABLED` wieder auf `false` setzen.
- [x] Öffentliche Legal-Seiten, Footer, fehlende interne Bundle-Metadaten,
  fehlende PayPal-/Testmarker und erfolgreiche Antworten live prüfen.
- [ ] Ares als aktive Kollektion projizieren; Testprodukte bleiben getrennt.
- [ ] Live-Preis, EUR, Verfügbarkeit, Versand, Legal Consent, Datenschutz- und
  Widerrufsseiten prüfen.
- [ ] Eine echte Erstbestellung als Gast mit Menge 1 kontrolliert durchführen.
- [ ] Nicht blockierende Zuordnung, Stripe-Zustand, Webhook-Inbox, Worker,
  atomare Bestellung, Bestellnummer, unveränderte Verfügbarkeit, Brevo-Mail,
  Admin und Versandfreigabe prüfen.
- [ ] Beobachtungsfenster ohne weitere Produktfreigabe abschließen; bei jedem
  Widerspruch neue Checkouts deaktivieren.

**Stop:** falscher Preis/Versand, fehlende Zustimmung, doppelte Bestellung,
unklare Zahlung, geänderte Verfügbarkeit, fehlende Mail oder Worker-/Webhookfehler.
