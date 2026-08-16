# Kollektionen und Verfügbarkeit

Stand: 2026-08-16  
Status: technische Umsetzung; Test- und Produktionsaktivierung ausstehend

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

Die Schalter `productApiV4WritesEnabled` und `collectionCommerceEnabled` bleiben bis zur Testabnahme auf `false`. Vor der Aktivierung muss der offene Veröffentlichungsversuch „test 3“ eindeutig abgeschlossen oder verworfen sein. Danach werden Schreibzugriffe älterer Apps verständlich abgewiesen; lesender Zugriff auf alte Produktmodelle bleibt möglich.

Für Produktion sind zusätzlich erforderlich:

1. vollständige Testabnahme von Veröffentlichen, parallelem Kaufen, Archivieren und Wiederherstellen;
2. freigegebenes Legal Bundle v5;
3. frisches verschlüsseltes Backup, geprüfte OneDrive-Übertragung und isolierte Restore-Probe;
4. idempotente Vorwärtsmigration `commerce-v2-collections.sql`;
5. kontrollierte Projektion von Ares als aktive Kollektion ohne Bestandsimport.

Nach dem ersten Kollektionen-Checkout gibt es keinen Rückfall auf die Einzelstücklogik. Bei einem Problem werden neue Checkouts geschlossen. Bestellungen und Commerce-Daten werden weder zurückgesetzt noch in `stock` oder `onHand` zurückgeschrieben.
