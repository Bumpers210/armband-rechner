# Manuelle Website-Reihenfolge in der PC-Artikelverwaltung

Stand: 16. November 2026. Lokal geprüft; die Betreiberin hat die kontrollierte
Live-Bereitstellung beauftragt. PR/CI, private API-Aktivierung und Website-Smoke
sind vor der Liveabnahme erforderlich. Keine Produktdatenänderung vorgesehen.

## Bedienung

In **Artikelverwaltung → Reihenfolge auf der Website → Reihenfolge bearbeiten**
die veröffentlichten Armbänder mit **Nach oben / Nach unten** verschieben.
Erst **Reihenfolge speichern** übernimmt die Auswahl. Die Liste enthält auch
veröffentlichte Produkte ohne Verkaufsfreigabe. Entwürfe und archivierte Produkte
werden dadurch nicht veröffentlicht. Abmelden, Verlassen und Neuladen warnen
vor dem Verlust einer noch nicht gespeicherten Reihenfolge.

Die Reihenfolge gilt für die öffentliche Übersicht und alle daraus abgeleiteten
Produktlisten. Produktbearbeitung, Bildertausch, Speichern und erneutes
Veröffentlichen verschieben bestehende Einträge nicht. Neue Veröffentlichungen
werden hinten angefügt. Archivierte IDs werden ausgeblendet; bei Wiederherstellung
gilt ihr gespeicherter Platz, sofern sie noch in der Reihenfolgedatei stehen,
sonst werden sie angefügt. Bis zur ersten manuellen Speicherung bleibt die
bisherige Sortierung nach Änderungsdatum erhalten.

Die zuletzt freigegebenen 3:4-Vorschaubereiche mit `object-fit: contain` bleiben
unverändert. Android benötigt kein Update.

## Getrennter Vertrag

- Browser: authentifiziertes `GET /product-browser/index.php?route=/catalog-order`
  liefert nur Revision und öffentliche Kennung/SKU/Bezeichnung in Anzeigereihenfolge.
- `PUT` desselben Wegs nimmt ausschließlich `expectedRevision` und `productIds`
  entgegen. Die Liste muss eine vollständige, eindeutige Permutation aller
  aktuell veröffentlichten Produkte sein (höchstens 1000). CSRF,
  `Idempotency-Key`, Origin-, Sitzungs-, Rate-Limit- und No-Store-Grenzen bleiben.
- Die Revision bindet öffentliche Projektion **und** Reihenfolgedatei. Ein neuer
  Artikel, eine Archivierung, eine Produkt- oder parallele Reihenfolgenänderung
  führt bei veraltetem Stand zu HTTP 409 `catalog_order_conflict`. Keine automatische
  Zusammenführung; Eingaben bleiben sichtbar, explizites Neuladen erforderlich.
- Kein Schreiben in Entwürfe, Produktfelder, Versionen, Quellhashes, Bilder,
  Verkaufsfreigaben oder Commerce. Keine Datenbankmigration.
- `content/product-order.json`: strikt `{version: 1, productIds: null | string[]}`.
  `null` hält den bisherigen Standard; eine Liste aktiviert die manuelle Ordnung.
  Der öffentliche Produktvertrag `{version, products}` bleibt unverändert.
- Produkt-Publisher ersetzen bestehende Einträge an Ort und Stelle und hängen
  neue an. Die Website hängt noch nicht einsortierte IDs in dieser stabilen
  Projektionsreihenfolge an, niemals nach dem späteren Änderungsdatum.

## Publisher und Sicherheit

In Produktion werden beide Dokumente aus demselben unveränderlichen GitHub-Commit
gelesen. Eine Speicherung erstellt ausschließlich einen Commit für die bestehende
`website/content/product-order.json`, mit Kennzeichnung `Carmaja collection reorder`
und `Carmaja-Publisher: catalog-order-v1`. Die bestehende GitHub-Pfad-Allowlist für
gewöhnliche Produkt-Publishes wird **nicht** erweitert. Der gesonderte
Workflowvalidator erlaubt für eine Sortierung genau diese eine Datei, keine
Produkt-, Bild-, Code- oder Workflowänderung. Er prüft weiterhin Akteur, Branch,
Vorgänger, nicht erzwungenen Commit und vollständige Produktzuordnung.

Publisher-, V5-Schreib-, Browser- und Wartungssperren bleiben wirksam. Die neue
Implementierung schaltet nichts frei. Commit-Vorbereitung wird privat vor dem
Ref-Update protokolliert; Wiederholung nach Verbindungsabbruch setzt genau denselben
Vorgang fort. Ein abweichender Remote-HEAD wird nicht überschrieben. Die UI meldet
die Website-Aktualisierung ausdrücklich als ausstehend, nicht als sofort live.

Im isolierten Testmodus schreibt der deaktivierte GitHub-Adapter ausschließlich
`products/catalog-order.json` unter dem bereits gewählten privaten Testdatenziel.
Alle IDs unterliegen zusätzlich der bestehenden Testprodukt-Grenze. Lokales
Publizieren und Sortieren verwenden denselben Dateilock. Ein Testexport benötigt
neben seiner `products.json` diese Datei unter dem Namen `product-order.json`;
andernfalls bleibt ausdrücklich der bisherige Standard aktiv. Es gibt keinen
automatischen Testdeploy und keine Übernahme produktiver IDs in Testfixtures.

Die private Vorgangshistorie liegt unter `products/operations/catalog-*.json`
und wird vom bestehenden verschlüsselten Backupumfang `products` erfasst;
die produktive Reihenfolgedatei ist zusätzlich in Git versioniert.
Der Paketvertrag enthält den neuen privaten Dienst `product-catalog-order.php`.

## Lokale Abnahme

Ergebnis am 16. November: lokale Funktionsabnahme bestanden, keine Liveabnahme
behauptet. `npm ci`, `lint:test`, `build:test` samt TypeScript/Export,
PHP-8.4-Lint aller sieben betroffenen PHP-Dateien, Geheimwertprüfung der 25
geänderten/neuen Dateien und `git diff --check` bestanden; `npm audit` meldet
keine Schwachstellen.

Der breite Node-Lauf hatte 288 Prüfungen: 285 unter Windows bestanden,
die drei unveränderten POSIX-Dateirechte-/Shellprüfungen dort fehlgeschlagen.
Alle drei separat in einer temporären Linux-Kopie bestanden; ebenso die
Bootstrap-/Rollback- und sieben Produktions-Smoke-Szenarien. Der zusätzliche
unveränderte Statistik-Hostingtest wurde dort wegen des absichtlich nicht
kopierten Produktions-Hostingbereichs nicht ausgeführt. Die abschließende
gezielte Node-Suite einschließlich zweier weiterer Website-/Sitemap-Prüfungen
bestand mit 53/53.

PHP: 60 Produkt-API-Prüfungen, 34 neue Katalog-/Publisherprüfungen, 37
Browserprüfungen, 46 Produktions-Browsergrenzen, 33 gepackte Loopback-HTTP-
Prüfungen, 14 Bootstrap- und 10 Backupprüfungen bestanden. Alle Fixtures sind
künstlich; kein IONOS-, Datenbank- oder GitHub-Schreibzugriff. Android nicht
erneut gebaut, da keinerlei App- oder Gradle-Dateien geändert wurden.

- Synthetische PHP-/Publisherprüfungen: strikte Eingaben, vollständige Permutation,
  zwei konkurrierende Verwaltungsstände, Wiederholungen, verlorene GitHub-Antwort,
  gesperrter Publisher und Testzielgrenze; keine externen Provideraufrufe.
- Gepackter Loopback-HTTP-Test: Anmeldung, CSRF, Idempotenz, CORS/No-Store,
  Wartungs- und Publisher-Sperren einschließlich der neuen Route.
- Ausführbare Komponentenprüfungen: Verschieben/Grenzen, explizites Speichern,
  Wiederholung mit gleichem Schlüssel, Konflikterhalt und abgebrochenes Neuladen.
- Website-/Workflow-Vertrag: bisheriger Standard, stabile spätere Ordnung,
  neue/archivierte Produkte, keine Änderung von Sichtbarkeit oder Produktdaten,
  enger Publisher-Dateivertrag und Paketvollständigkeit.

## Freigegebene Bereitstellung

Website und privates API-Programm werden gemeinsam nach grüner PR-/Paket-CI
bereitgestellt. Vor dem privaten Programmwechsel sind die bisherigen Dateien
hashgebunden zu sichern; nur die betroffenen Programmdateien werden ersetzt.
Runtime, Produktdaten, Bilder, Bestände, Worker und Sicherungen bleiben erhalten.
Der Websiteworkflow wird auf den resultierenden main-Commit gepinnt; sein
manueller Deployschalter wird anschließend wieder deaktiviert. Die bestehenden
Publishergrenzen werden nicht durch einen allgemeinen automatischen Deploy ersetzt.

Live werden die neue Route, Anmeldungsschranke, CORS/No-Store, UI-Auslieferung
und unveränderte Produktdarstellung geprüft. Eine tatsächliche Reihenfolge wird
nicht ohne Auswahl der Betreiberin gesetzt. Bis zur ersten Speicherung bleibt
die bisherige Anzeige erhalten. Das erfolgreiche Liveergebnis ist im
Deploymentlauf und im Abschlussbericht nachzuweisen.
