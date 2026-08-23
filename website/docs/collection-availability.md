# Kollektionen und Verfügbarkeit

Stand: 2026-08-22
Status: Kollektionen-Modell für Ares produktiv aktiviert

Dieses Dokument ersetzt für neue Shopstände die bisherige Annahme „Unikatbestand 1“. Historische Abnahmeprotokolle bleiben unverändert, damit frühere Entscheidungen nachvollziehbar bleiben.

## Fachlicher Vertrag

- Ein Checkout enthält genau ein Armband.
- Ein veröffentlichtes Produkt ist eine aktive Kollektion und kann beliebig oft sowie parallel bestellt werden.
- Es gibt für Kollektionen keine sichtbare oder intern wirksame Bestandsmenge.
- Eine offene oder prüfpflichtige Zahlung blockiert keine weitere Bestellung derselben Kollektion.
- Kauf, Rückgabe, Widerruf und Erstattung ändern die Verfügbarkeit nicht.
- Archivieren in der App sperrt neue Checkouts sofort und entfernt Produkt, Sitemap-Eintrag und öffentliche Bilder.
- Bereits vor der Archivierung erzeugte Checkout-Snapshots dürfen bis zu ihrem normalen Ablauf abgeschlossen werden.
- Private Produktdaten und Bilder bleiben für Bestellnachweise und Wiederherstellung erhalten.
- Wiederherstellen verwendet dieselbe Produkt-ID, SKU und Adresse.
- Auf der Website heißt der Bereich weiterhin „Armbänder“.

## Technischer Vertrag

Die Produkt-App schreibt über `/v4`. Sie sendet weder `stock` noch `salesEnabled`. Veröffentlichen, Archivieren und Wiederherstellen sind eigene, idempotente Vorgänge. Der Server prüft Produktversion, Quellhash, App-Mindestversion und Vorgangskennung und projiziert die Aktion in Produktdaten, öffentliche Website und Commerce.

Der Shop verwendet `/shop/v2`. Die öffentliche Live-Prüfung liefert `available`, aber keine Bestandsmenge. `commerce_products.sales_model='collection'` kennzeichnet das Modell; `sales_enabled` ist ausschließlich der serverseitige Aktiv-/Archivstatus. Neue Kollektionen erhalten keine Zeile in `commerce_inventory`. Vorhandene Inventory-, Anpassungs- und Wiedereinlagerungsdaten bleiben unverändert als historischer Nachweis erhalten.

Reservierungen bleiben zur Zuordnung und Wiederholungsvermeidung bestehen, tragen für Kollektionen jedoch `blocks_stock=0`. Bestellung, Versand und Mails entstehen nach endgültigem Zahlungserfolg ohne Bestandsabbau. Netzwerkaufrufe bleiben außerhalb globaler Commerce-Locks.

## Aktivierungsgrenzen

Die Testabnahme einschließlich Veröffentlichen, parallelem Kaufen, Archivieren
und Wiederherstellen ist abgeschlossen. Legal Bundle v5 ist freigegeben. Für
Ares ist `collectionCommerceEnabled` seit dem 22. August 2026 dauerhaft auf
`true`. Nach der Abnahme der Produktions-App 1.3.1 werden außerdem
`productApiV4WritesEnabled`, `githubAdapterEnabled` und
`productionPublishEnabled` dauerhaft aktiviert. Dadurch kann die angemeldete
Produktions-App Entwürfe speichern sowie eine ausdrücklich bestätigte
Veröffentlichung, Archivierung oder Wiederherstellung ohne weiteres
administratives Freigabefenster vollständig ausführen. Lesender Zugriff auf
alte Produktmodelle bleibt möglich; veraltete Apps dürfen nicht schreiben.

Der Shopstart wurde ohne Testbestellung geprüft: Ares ist als Kollektion der
Version 4 für 20,00 EUR verfügbar, Shop-API und Kaufbutton sind betriebsbereit,
Worker und Warteschlangen sind unauffällig. Bei einem Fehler wird ausschließlich
der Kaufweg wieder geschlossen; Bestellungen und Commerce-Daten bleiben
unverändert.

Der Produktionspublisher akzeptiert ausschließlich das Repository
`Bumpers210/armband-rechner` und den Branch `main`. Die private Token-Datei
bleibt an den privaten Shop- oder Produktbereich gebunden. Jeder v4-Vorgang
erzeugt genau einen nicht erzwungenen Folgecommit mit Vorgangskennung und
Quellhash. Der Produktionsworkflow stellt diesen Commit automatisch bereit,
aber nur wenn Akteur, Vorgänger, Commitkennzeichnung und sämtliche geänderten
Pfade dem Collection-v4-Vertrag entsprechen. Zulässig sind ausschließlich
`website/content/products.json` und höchstens fünf nummerierte JPEGs derselben
SKU. Normale Code-, Website- und manuelle Git-Änderungen behalten ihre
separate Deploymentfreigabe.

„Speichern“ und „Synchronisieren“ veröffentlichen nichts. Erst die bestätigte
Aktion „Jetzt veröffentlichen“ projiziert die Kollektion in Commerce, schreibt
den öffentlichen Produktcommit und startet die atomare Websitebereitstellung
mit Smoke-Test und automatischem Rollback. Archivieren und Wiederherstellen
verwenden denselben begrenzten Weg.

### Auflösung von „test 3“ am 17. August 2026

Eine rein lesende Prüfung der IONOS-Testumgebung hat den offenen Versuch
eindeutig aufgelöst:

- Der mit der neuen Beta-App bearbeitete Entwurf „test 3“ wurde weder als
  Serverentwurf gespeichert noch teilweise veröffentlicht.
- Es gibt keine zugehörige v3-/v4-Vorgangskennung und keinen Eintrag in der
  öffentlichen Produktdatei.
- Der ältere Datensatz `test3` mit SKU `CP-2026-0004` stammt vom 30. Juli
  2026, ist davon unabhängig und nicht öffentlich sichtbar.
- Auf dem Server war zu diesem Zeitpunkt noch keine v3-/v4-API installiert;
  beide neuen Funktionen waren daher weiterhin aus.

Es ist kein Rücksetzen von Produkt- oder Commerce-Daten erforderlich. Der
nächste Test darf nach Installation der neuen API mit einem neuen oder erneut
lokal gespeicherten Entwurf beginnen.

### Letzte Testserver-Vorprüfung am 17. August 2026

- SSH, PHP 8.4 und die benötigten PHP-Module sind erreichbar.
- Test-API und Testwebsite antworten mit dem erwarteten Anmeldeschutz.
- Test- und Produktions-Deployschalter stehen auf `false`; es besteht keine
  Deploysperre.
- Die aktive Runtime-Konfiguration blieb unverändert und privat.
- `commerce-core.php` besitzt derzeit noch Modus `0644`. Das kontrollierte
  Testdeployment muss die private Programmdatei auf den vorgesehenen Modus
  `0640` setzen und dies anschließend prüfen.
- Getrennte Zugangsdaten für den zerstörungsfreien künstlichen
  MySQL-Integrationstest sind auf dem Server noch nicht eingerichtet. Die
  Migration bleibt deshalb bis zu einem dafür klar abgegrenzten Testlauf
  ungeändert.

Für Produktion sind zusätzlich erforderlich:

1. Die idempotente Vorwärtsmigration `commerce-v2-collections.sql` wurde bei
   geschlossenem Shop ausgeführt.
2. Ares wurde mit der Produktions-App 1.3.0 als Modell 3 gespeichert und an
   Version 4 sowie den serverseitigen Quellhash gebunden.
3. Unmittelbar vor dem Cutover muss die Aktualität des verschlüsselten Backups
   erneut bestätigt werden.
4. Die kontrollierte Projektion von Ares als aktive Kollektion ohne
   Bestandsimport bleibt bis zur getrennten Freigabe gesperrt.

### Freigabe von Legal Bundle v5 am 18. August 2026

Die Shopbetreiberfreigabe ist dem Prüfpaket `ap8-2026-08-16-v1` und dem
Produktions-Legal-Bundle `cmj-production-legal-2026-08-16-v5` eindeutig
zugeordnet. Das Bundle ist im Repository freigegeben. Produktionsdeployment,
Checkoutstart und Kollektionen-Cutover bleiben eigene, weiterhin gesperrte
Arbeitsschritte.

Nach dem ersten Kollektionen-Checkout gibt es keinen Rückfall auf die Einzelstücklogik. Bei einem Problem werden neue Checkouts geschlossen. Bestellungen und Commerce-Daten werden weder zurückgesetzt noch in `stock` oder `onHand` zurückgeschrieben.
