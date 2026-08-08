# AP7 – endgültige Produktions-Preflight-Checkliste

Stand: 2026-08-08

Status: **nicht zur Ausführung freigegeben**

Diese Checkliste ist in der angegebenen Reihenfolge abzuarbeiten. Jeder
Fehler stoppt den Produktionsstart. Kein Schritt darf durch eine Annahme oder
einen Testwert ersetzt werden.

## 1. Release Candidate und Freigabe

- [ ] Finalen Commit von `feature/shop-ap7` prüfen und per Review freigeben.
- [ ] Bestätigen, dass ausschließlich der freigegebene Commit nach `main`
  übernommen wurde.
- [ ] `CARMAJA_PRODUCTION_DEPLOY_ENABLED` bleibt bis zum ausdrücklich
  freigegebenen Website-Deploy `false`.
- [ ] Produktionsfreigabe mit Zeitfenster, Betreiberin und Stop-Verantwortung
  schriftlich dokumentieren.

**Stop:** abweichender Commit, unaufgelöster Review oder ungeprüfter Build.

## 2. Startprodukt

- [ ] Genau einen realen Datensatz aus der autoritativen Produktverwaltung
  auswählen.
- [ ] Produktmodell `2`, eindeutige `productId`, monotone `productVersion` und
  serverseitigen 64-stelligen `sourceHash` nachweisen.
- [ ] Namen, Beschreibung, Bilder, Preis `>= 50` Cent, Währung `eur`,
  `salesEnabled`, bisheriges `stock=1` und Zielbestand `onHand=1` fachlich
  bestätigen.
- [ ] Produkt- und Bildprojektion mit der öffentlichen v2-Projektion
  vergleichen; keine externen Verkaufsfelder zulassen.
- [ ] Nur diesen Datensatz mit `legacyStock=1`, `targetOnHand=1`, erwarteter
  Version und erwartetem Hash in
  `website/config/production-cutover-manifest.v1.json` eintragen.
- [ ] Manifeststatus erst nach Vier-Augen-Prüfung auf
  `approved_for_cutover` setzen.

**Aktueller Nachweis:** In den vorhandenen lokalen und Remote-Git-Referenzen
existiert kein freigabefähiger realer v2-Datensatz. Die sichtbaren Kandidaten
sind v1-Daten ohne `productId`, `productVersion`, `sourceHash` und Preis. Das
Manifest bleibt deshalb korrekt auf `awaiting_production_product_selection`
mit leerem `selectedProducts`-Array.

**Stop:** kein exakt passender v2-Datensatz oder irgendein Feldkonflikt.

## 3. Parallele Verkaufsangebote

- [ ] Alle Vinted-Angebote und sonstigen parallelen Verkaufsangebote des
  ausgewählten Unikats deaktivieren oder löschen.
- [ ] Öffentliche Produktseite, Produktprojektion, Hosting und Suchindex auf
  verbleibende externe Kauflinks prüfen.
- [ ] Betreiberin bestätigt schriftlich, dass der eigene Shop der einzige
  Verkaufskanal ist.

**Stop:** weiterhin kaufbares externes Angebot.

## 4. Produktionsdatenbank

- [ ] Exakte Datenbankidentität als ausschließlich produktives Commerce-Ziel
  bestätigen; niemals Test- oder Bestandsdatenbank verwenden.
- [ ] MySQL 8, InnoDB, benötigte Rechte und aktive TLS-Sitzung nachweisen.
- [ ] Fehlende CA-/Hostidentitätsprüfung als akzeptiertes V1-Restrisiko im
  Produktionsprotokoll bestätigen.
- [ ] Vorhandene Tabellen und Migrationen inventarisieren; unerwartete Daten
  stoppen den Lauf.
- [ ] Migrationen und die im Manifest gebundenen Datei-/Journalhashes im
  Planmodus prüfen.

**Stop:** unklare Datenbankidentität, fehlendes TLS, falsche Version,
unerwartete Daten oder Hashabweichung.

## 5. Secrets und private Laufzeitkonfiguration

- [ ] Private Konfiguration ausschließlich unter
  `/home/www/carmaja-private-shop/config/runtime-config.php` mit Modus `0600`
  anlegen.
- [ ] Datenbank-, Stripe-, Webhook-, Payload-Verschlüsselungs-, Brevo-,
  Admin- und Backup-Schlüssel nur auf Vorhandensein und Trennung prüfen.
- [ ] Keine Secrets in Repository, Webroot, URL, Cronbefehl, Logs, Manifest
  oder Deploymentartefakt.
- [ ] Stripe-Payload- und Backup-Schlüssel-ID samt getrenntem
  Wiederherstellungszugriff dokumentieren.

**Stop:** fehlender, offengelegter, gemeinsam genutzter oder nicht
wiederherstellbarer Schlüssel.

## 6. Stripe Live

- [ ] `stripe/stripe-php` exakt `20.3.0`, API- und Webhook-Version gemäß
  Produktionsvertrag verifizieren.
- [ ] Live-Webhook nur für die festgelegte Allowlist registrieren und
  Signaturprüfung mit unverändertem Rohpayload testen.
- [ ] `card`, `paypal`, `klarna`, `sepa_debit` als exakte Allowlist prüfen.
- [ ] Link pro Session deaktivieren; Promotion Codes, Recovery und dynamische
  weitere Zahlungsarten deaktiviert lassen.
- [ ] 30-minütige Checkout-Laufzeit, Terms-URL, verpflichtende Zustimmung,
  Versand 270 Cent und Legal Bundle v3 in einer nicht zahlungswirksamen
  Konfigurationsprüfung vergleichen.
- [ ] Inbox-Persistierung vor `2xx`, Retry, ungeordnete Events und
  Stripe-Abgleich betriebsbereit bestätigen.

**Stop:** Live-/Testverwechslung, abweichende Version, zusätzliche
Zahlungsart, fehlende Zustimmung oder nicht persistierbarer Webhook.

## 7. Brevo Live

- [ ] Verifizierten produktiven Absender und API-Zugang bestätigen.
- [ ] Bestell-, Betreiber-, Versand- und Widerrufsvorlage gegen die
  freigegebenen Texte prüfen.
- [ ] Idempotency-Key, Deduplizierung, `delivery_unknown`, Retry und
  manuellen auditierten Neuversand prüfen.
- [ ] Dauerhafte Zustellfehler und letzte erfolgreiche Verarbeitung im Admin
  sichtbar machen.

**Stop:** unverifizierter Absender, Testkonto, unklare Vorlage oder fehlende
Fehlersichtbarkeit.

## 8. Backup und Restore

- [ ] Unmittelbar vor Migration vollständiges Produkt- und Commerce-Backup
  erstellen.
- [ ] Datenbankdump verschlüsseln, Prüfsumme und Key-ID speichern und an das
  getrennte Offsite-Ziel übertragen.
- [ ] Restore-Dry-Run in ein isoliertes Ziel durchführen; Struktur, Inhalt und
  Prüfsummen vergleichen.
- [ ] RPO, RTO, Aufbewahrung, Alarmierung und Zugriff einer dokumentierten
  Notfallperson bestätigen.

**Stop:** fehlendes Backup, fehlende Entschlüsselbarkeit oder nicht
bestandener Restore.

## 9. Private API und Worker

- [ ] Private Programme nach `/home/www/carmaja-private-shop/program`, Worker
  nach `/home/www/carmaja-private-shop/worker.php` und öffentlichen Einstieg
  nach `/home/www/carmaja-shop-api` staged bereitstellen.
- [ ] Release-SHA und alle Artefakthashes vor atomarer Aktivierung prüfen.
- [ ] Öffentlicher Einstieg darf nur den privaten Produktions-Bootstrap laden;
  keine Testpfade oder Runtime-Konfiguration im Webroot.
- [ ] PHP 8.4, Module, CORS, Cookies, CSRF, `no-store`, Rate Limits und
  fail-closed Produktantworten prüfen.
- [ ] Worker direkt mit
  `/usr/bin/php8.4 /home/www/carmaja-private-shop/worker.php
  /home/www/carmaja-private-shop/config/runtime-config.php` testen.
- [ ] Laufzeit unter 40 Sekunden, Batch 10–20, Lock, zehnminütige Lease,
  Runlog und Fehleralarm nachweisen.

**Stop:** Pfad-, Hash-, Laufzeit-, Lock-, Lease- oder Sicherheitsabweichung.

## 10. UnixCron und Monitoring

- [ ] IONOS-UnixCron mit `*/5 * * * *` und exakt dem privaten Workerbefehl
  anlegen; keine Secrets im Befehl.
- [ ] Mindestens zwei echte Läufe im Fünf-Minuten-Abstand nachweisen.
- [ ] Lock, Lease-Übernahme, Runlog, Laufzeit und Fortsetzung eines
  Teilbatches prüfen.
- [ ] Alarm für ausbleibenden erfolgreichen Lauf, dauerhafte Outboxfehler,
  Reviewcases, Webhookrückstand und knappen Speicher aktivieren.
- [ ] Manuellen privaten Notlauf und Stripe-Abgleich dokumentieren.

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
