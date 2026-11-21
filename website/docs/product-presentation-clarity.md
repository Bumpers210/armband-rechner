# Anfrageangebot, Katalogpreise und Versandhinweis

Stand: 21. November 2026. Ausschließlich lokal umgesetzt auf
`codex/shop-presentation-clarity`, Basis main
`954cb89f94512633b5160e83817f8942fdc7039f`. Commit, Push dieses Branches und
Pull Request mit CI sind freigegeben. Kein Merge oder Deployment.

## Umfang

- Der bereits redaktionell als `custom` gekennzeichnete Anfrageartikel zeigt
  „Preis nach Absprache“ und genau einen Link „Wunscharmband anfragen“.
  Kein fester Preis, Ausverkauftstatus, Mengenfeld oder Kaufbereich – auch wenn
  die unveränderte Produktquelle `salesEnabled=true` enthält. Die bestehenden
  Produktdaten und Verkaufsfreigaben werden nicht geändert.
- Produktkarten zeigen den EUR-Preis der validierten veröffentlichten
  Produktprojektion, nicht einen zweiten manuell gepflegten Preis. Der
  Anfrageartikel zeigt stattdessen „Preis nach Absprache“. Keine zusätzlichen
  API-Abfragen pro Karte. Aktualisierung erfolgt mit der bestehenden
  Veröffentlichung; Detailseite, Warenkorb und Checkout prüfen weiterhin live.
- Direkt nach „In den Warenkorb“ steht der Versandhinweis: innerhalb
  Deutschlands, einmalig 2,70 € je Bestellung, voraussichtlich 2–4 Werktage
  nach endgültiger Zahlungsbestätigung. Betrag und Zeitspanne kommen aus dem
  bestehenden Versandvertrag; Tests gleichen sie mit dem freigegebenen Legal
  Bundle ab. Der Link führt auf `/versand-und-zahlung/`.
- Der Versandhinweis ist eine Serverkomponente. Die private Pfade enthaltende
  Deploymentkonfiguration wird nicht als Client-Prop übertragen.

## Bestandsschutz

Originalbeschreibungen samt Formatierung, Produktdatei, Bilder,
3:4-Vorschaubereiche mit `object-fit: contain`, manuelle Katalogreihenfolge,
Rechtstexte, Verkaufsfreigaben und Bestände bleiben unverändert. Keine Änderung
an App, API, Publisher, Checkout, Datenbank, Hostingprogrammen oder Workflows.

Die vorhandene PHP-Angebotsdarstellung lässt Informationsseiten ohne
Kaufbereich bereits unverändert passieren. Deshalb ist kein PHP-/Apache-Update
nötig. Beim Anfrageartikel wird kein erfundenes `Offer` ausgegeben; sein
Produkt-Grunddatensatz bleibt erhalten. Alle regulären Angebote behalten
Live-Preis, No-Store und die unveränderte Kaufprüfung.

## Lokaler Nachweis

- `npm ci`: erfolgreich, Audit 0 Schwachstellen.
- `npm run lint:test`: erfolgreich.
- Node-Regression: 305 Tests, 303 bestanden, keine Fehler; zwei optionale
  Apache-Integrationstests mangels lokalem Apache übersprungen.
- Fünf neue React-/Vertragstests: Anfrageartikel bei beiden Verkaufsfreigaben,
  veröffentlichte Preise/Reihenfolge/Bilder, fail-closed Kaufbereich,
  Versand-/Legal-Konsistenz und Test-/Produktions-Trennung bestanden.
- `npm run build:test`: TypeScript und Testexportprüfung bestanden.
- `npm run build`: lokaler Produktions-Export mit allen 15 Produktseiten,
  Preisen in den Karten und Anfrage-/Versandprüfungen bestanden.
- PHP 8.4.22: `tests/php/product-offer-renderer-test.php`, 64 Assertions bestanden.
- `tests/product-offer-rendering.mjs`: 14 reguläre Produkte × verfügbar,
  ausverkauft und API-Ausfall = 42 exakte PHP-/React-Ausgabevergleiche bestanden;
  zusätzlich die Anfrage-Seite bei ausgefallener API unverändert mit HTTP 200.
- Lokaler Browser mit künstlicher API: Katalog, Anfrageartikel, reguläre
  Detailseite, Hinzufügen zum künstlichen Warenkorb, Versandlink und Tastatur
  geprüft. Bei 390 px kein horizontaler Überlauf. Simulierter API-Ausfall sperrt
  Kaufen; erneutes Prüfen übernimmt den geänderten künstlichen Preis und
  ermöglicht Kaufen erst nach erfolgreicher Live-Antwort. Keine React-Fehler.
- Allgemeine Deployment-Shelltests: Windows/Git Bash scheiterte an POSIX-
  Dateirechten. Unveränderte Tests anschließend unter WSL/Linux bestanden:
  Bootstrap/Inventur/Rollback, Deployment, sieben Smoke-Szenarien und
  Statistikhosting. Der dort mangels Linux-PHP übersprungene Klickstatistiktest
  wurde mit Windows-PHP 8.4 separat erfolgreich nachgeholt.
- `git diff --check`: bestanden; Produktdaten, Bilder, Sortierung, App,
  Konfigurationen, API, Hosting und Workflows ohne Diff.

Alle Kauf-/Preisänderungstests verwenden ausschließlich lokale künstliche
Antworten. Keine Serververbindung, Bestellung oder Zahlung. Nach erfolgreicher
PR-/CI-Prüfung sind vor Veröffentlichung eine gesonderte Mergefreigabe und ein
getrennt freigegebenes Website-Deployment erforderlich.
