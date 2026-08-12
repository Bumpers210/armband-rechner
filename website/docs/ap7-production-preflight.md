# AP7 – endgültige Produktions-Preflight-Checkliste

Stand: 2026-08-11

Status: **nicht zur Ausführung freigegeben**

Diese Checkliste ist in der angegebenen Reihenfolge abzuarbeiten. Jeder
Fehler stoppt den Produktionsstart. Kein Schritt darf durch eine Annahme oder
einen Testwert ersetzt werden.

## 1. Release Candidate und Freigabe

- [x] Finalen Integrationsstand per Pull Request und CI prüfen.
- [x] Bestätigen, dass ausschließlich der freigegebene Commit nach `main`
  übernommen wurde.
- [x] `CARMAJA_PRODUCTION_DEPLOY_ENABLED` bleibt bis zum ausdrücklich
  freigegebenen Website-Deploy `false`.
- [ ] Produktionsfreigabe mit Zeitfenster, Betreiberin und Stop-Verantwortung
  schriftlich dokumentieren.

**Stop:** abweichender Commit, unaufgelöster Review oder ungeprüfter Build.

## 2. Startprodukt

- [x] Genau einen realen Datensatz aus der autoritativen Produktverwaltung
  auswählen.
- [x] Produktmodell `2`, eindeutige `productId`, monotone `productVersion` und
  serverseitigen 64-stelligen `sourceHash` nachweisen.
- [x] Namen, Beschreibung, Bilder, Preis `>= 50` Cent, Währung `eur`,
  `salesEnabled=false`, bisherigen Wert `stock=1` aus der unveränderten
  Migrationssicherung und Zielbestand `onHand=1` fachlich bestätigen.
- [x] Produkt- und Bildprojektion mit der öffentlichen v2-Projektion
  vergleichen; keine externen Verkaufsfelder zulassen.
- [x] Nur diesen Datensatz mit `legacyStock=1`, `targetOnHand=1`, erwarteter
  Version und erwartetem Hash in
  `website/config/production-cutover-manifest.v1.json` eintragen.
- [ ] Manifeststatus erst nach Vier-Augen-Prüfung auf
  `approved_for_cutover` setzen.

**Aktueller Nachweis:** Produkt
`3a37a0a2-9bd6-4410-aa9c-a465fdc411a1` ist als Produktmodell V2 mit
`productVersion=1`, Preis 2800 Cent, Währung EUR, `salesEnabled=false` und
serverseitigem `sourceHash`
`09cd71d56561b08b8373c3bc804d3298b47096c470751da3407a5e0eff1e4444`
vorbereitet. Die ausschließlich lesende Prüfung der unveränderten
Migrationssicherung `20260810-193548-5cebba9e` bestätigte den früheren Wert
`stock=1`. Das Manifest bindet genau dieses Produkt, bleibt mit Status
`prepared_awaiting_cutover_approval` aber nicht ausführbar.

Vor einer späteren Freigabe sind `salesEnabled=true`, die dadurch neu
entstehenden Werte für `productVersion` und `sourceHash` sowie alle übrigen
Produkt- und Cutovergates erneut zu prüfen und atomar im Manifest zu binden.
Der aktuell vorbereitete Hash darf nicht freigegeben oder für den Cutover
verwendet werden.

**Stop:** kein exakt passender v2-Datensatz oder irgendein Feldkonflikt.

## 3. Parallele Verkaufsangebote

- [x] Alle Vinted-Angebote und sonstigen parallelen Verkaufsangebote des
  ausgewählten Unikats deaktivieren oder löschen.
- [x] Öffentliche Produktseite, Produktprojektion, Hosting und Suchindex auf
  verbleibende externe Kauflinks prüfen.
- [x] Betreiberin bestätigt schriftlich, dass der eigene Shop der einzige
  Verkaufskanal ist.

**Stop:** weiterhin kaufbares externes Angebot.

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
- [ ] Live-Webhook nur für die festgelegte Allowlist registrieren und
  Signaturprüfung mit unverändertem Rohpayload testen.
- [ ] `card`, `klarna`, `sepa_debit` als exakte Allowlist prüfen; Apple Pay und
  Google Pay laufen auf Kartenbasis, PayPal bleibt deaktiviert.
- [x] Link pro Session deaktivieren; Promotion Codes, Recovery und dynamische
  weitere Zahlungsarten deaktiviert lassen.
- [ ] 30-minütige Checkout-Laufzeit, Terms-URL, verpflichtende Zustimmung,
  Versand 270 Cent und Legal Bundle v4 in einer nicht zahlungswirksamen
  Konfigurationsprüfung vergleichen.
- [ ] Inbox-Persistierung vor `2xx`, Retry, ungeordnete Events und
  Stripe-Abgleich betriebsbereit bestätigen.

**Stop:** Live-/Testverwechslung, abweichende Version, zusätzliche
Zahlungsart, fehlende Zustimmung oder nicht persistierbarer Webhook.

**Aktueller Live-Nachweis:** Das Livekonto, das gepinnte SDK und genau ein
aktivierter Webhook mit der vollständigen Neun-Ereignis-Allowlist sind lesend
bestätigt. Karte, Klarna, SEPA-Lastschrift und Google Pay sind aktiv; PayPal ist
deaktiviert. Die produktive Terms-of-Service-URL ist gespeichert. Das
freigegebene Legal Bundle v4 ist lokal vorbereitet, aber noch nicht in
Produktionsdatenbank und Runtime aktiviert. Link ist im
Dashboard grundsätzlich verfügbar, wird vom verbindlichen Sessionvertrag aber
mit `wallet_options.link.display=never` unterdrückt. Bis Legal Bundle v4 nach
`main` übernommen, in der Produktionsdatenbank gespeichert und in der Runtime
aktiviert ist, bleiben Checkout und Shopstart gesperrt.

## 7. Brevo Live

- [x] Verifizierten produktiven Absender und API-Zugang bestätigen.
- [ ] Bestell-, Betreiber-, Versand- und Widerrufsvorlage gegen die
  freigegebenen Texte prüfen.
- [ ] Idempotency-Key, Deduplizierung, `delivery_unknown`, Retry und
  manuellen auditierten Neuversand prüfen.
- [ ] Dauerhafte Zustellfehler und letzte erfolgreiche Verarbeitung im Admin
  sichtbar machen.

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

**Stop:** fehlendes Backup, fehlende Entschlüsselbarkeit oder nicht
bestandener Restore.

## 9. Private API und Worker

- [x] Private Programme nach `/home/www/carmaja-private-shop/program`, Worker
  nach `/home/www/carmaja-private-shop/worker.php` und öffentlichen Einstieg
  in den bestehenden Webroot `/home/www/carmaja-production-api` staged
  bereitstellen. Die Shop-API bleibt unter `/shop/v1`; ein zweiter
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
- [ ] Alarm für ausbleibenden erfolgreichen Lauf, dauerhafte Outboxfehler,
  Reviewcases, Webhookrückstand und knappen Speicher aktivieren.
- [x] Manuellen privaten Notlauf und Stripe-Abgleich dokumentieren.
- [x] Den separaten Backup-Cron `17 * * * *` und `backup status` ohne Secrets
  prüfen; Worker- und Backup-Cron dürfen sich nicht gegenseitig ersetzen.

**Stop:** kein echter Schedulernachweis oder fehlendes Monitoring.

## 11. Cutover

- [ ] Neue Checkouts deaktiviert halten und Produktverwaltung für das kurze
  Cutoverfenster sperren.
- [ ] Alte `stock`-Schreibwege und Clients unter Mindestversion nachweislich
  sperren.
- [ ] Finales Backup erneut bestätigen.
- [ ] Cutoveradapter zuerst mit `--mode=plan` ausführen und Ausgabe gegen das
  freigegebene Manifest prüfen.
- [ ] Apply nur mit der expliziten Produktionsbestätigung ausführen.
- [ ] Atomar prüfen: `commerce_products` entspricht Quellversion/-hash,
  `commerce_inventory.on_hand=1`, `inventoryVersion=1` und genau ein
  `activate_new_unique`-Auditdatensatz.
- [ ] Nach erstem Commerce-Checkout ist ein Rollback zu `stock` verboten.

**Stop:** jede Abweichung; bei unklarem Zustand Shop deaktivieren und Daten
nicht zurückschreiben.

## 12. Website und echte Erstbestellung

- [ ] Produktionswebsite ausschließlich über den manuellen, SHA-gepinnten
  Workflow und das geschützte Environment bereitstellen.
- [ ] Danach `CARMAJA_PRODUCTION_DEPLOY_ENABLED` wieder auf `false` setzen.
- [ ] Genau das ausgewählte Produkt aktivieren; alle weiteren Produkte bleiben
  deaktiviert.
- [ ] Live-Preis, EUR, Verfügbarkeit, Versand, Legal Consent, Datenschutz- und
  Widerrufsseiten prüfen.
- [ ] Eine echte Erstbestellung als Gast mit Menge 1 kontrolliert durchführen.
- [ ] Reservierung, Stripe-Zustand, Webhook-Inbox, Worker, atomare Bestellung,
  Bestellnummer, Bestandsabgang, Brevo-Mail, Admin und Versandfreigabe prüfen.
- [ ] Beobachtungsfenster ohne weitere Produktfreigabe abschließen; bei jedem
  Widerspruch neue Checkouts deaktivieren.

**Stop:** falscher Preis/Versand, fehlende Zustimmung, doppelte Bestellung,
unklare Zahlung, falscher Bestand, fehlende Mail oder Worker-/Webhookfehler.
