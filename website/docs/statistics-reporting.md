# Geschützte Statistikauswertung

Stand: 19.11.2026. Lokal implementiert, noch nicht veröffentlicht. Basis ist
der veröffentlichte Shopstand `542521d5e3fabc606421a19ae9502665ee5f87ae` im
isolierten Arbeitsbaum `carmaja-statistik-20261119`, Branch
`codex/statistik-erweiterung`. Der ältere Hauptarbeitsbaum und fremde
Änderungen wurden erhalten. Echte Quellen wurden lesend abgeglichen und mit
der lokalen Ansicht verbunden. Der gesonderte Statistik-Workflow setzt die
Online-Aktualisierung um. [Deployment und Rollback](production-click-statistics.md)

## Was die Seite beantwortet

Die vorhandene PHP-Seite `/statistik/` zeigt 7, standardmäßig 28, 90 oder frei
gewählte Kalendertage mit unmittelbar gleich langem Vergleich.
Tagesgrenzen gelten in Europe/Berlin, einschließlich Zeitumstellungen. Der
laufende Tag ist enthalten und gekennzeichnet. Online werden die Daten beim
Öffnen der geschützten Seite neu aus den vorhandenen Quellen gelesen.
Maximal 3.660 Tage begrenzen die Laufzeit.
Produktfilter gelten ausdrücklich nur für die Produkttabelle. Der gesamte
Shop bleibt Bezugsgröße der Hauptzahlen. Geräteklassen sind nicht erfasst.

Die Startansicht zeigt drei Zahlen: Seitenaufrufe, bestätigte Bestellungen und
Warenumsatz. Der Verlauf zeigt Aufrufe als Tagesbalken, die Tageszahlen lassen
sich aufklappen. Der Kaufweg zeigt auch Armbandseitenaufrufe und bereitgestellte
Zahlungsseiten. Ein eigener Warenkorb-Zähler fehlt. „Herkunft pro Tag“ zeigt
die bereits tagesweise gespeicherten Einstiege je Kanal, neueste Tage zuerst.
Die normale Auswertung filtert Aufrufe unter `/admin/` und
`/artikelverwaltung/` sowie die technische Prüfkollektion `CP-2026-0022`
nach Route heraus. Der Filter gilt auch für frühere Tageswerte und deren
Herkunftskanäle. Der Rohzähler bleibt zur Prüfung verfügbar. Aufrufe anderer
Seiten werden anhand der vorhandenen Routen gezählt.
Ein Strich markiert einen Tag ohne Aufrufdaten. Null bedeutet an einem Tag
mit Aufrufdaten, dass für den Kanal kein Einstieg gezählt wurde.
„Datenstand und Vorperiode“ zeigt die Stände und Vergleichszahlen.
Ein unbekannter, alter oder fehlgeschlagener Auszug beeinträchtigt die anderen
Quellen nicht. Vorhandene Linkklicks und häufige Seiten bleiben auswertbar.

| Kennzahl | Reale Quelle und Definition | Einschränkung |
| --- | --- | --- |
| Shopbesuche | Noch keine zulässige Besuchsmessung angebunden | Nicht erfasst |
| Besuche mit Produktansicht/Warenkorbaktion | Keine Besuchs-/Ereignisverknüpfung vorhanden | Nicht erfasst |
| Seitenaufrufe | Bestehender V3-Zähler, jede erfasste Routenansicht | Wiederladen zählt erneut, keine Personen oder Besuche |
| Quellen | Bestehende feste Kategorie beim Laden eines Dokuments | Keine Sitzungsherkunft; Pinterest bisher in `other-social` |
| Bestätigte Bestellungen | Eindeutige `orders.order_id`, zugehöriges `payments.status=succeeded`, `verification_status=verified` | Geschäftsereignisse unabhängig von Website-Messung |
| Warenumsatz | Summe `order_items.quantity * price_minor`, ohne Versand, abzüglich vollständig zuordenbarer erfolgreicher Erstattungen | Aktueller Erstattungsstand der Verkaufskohorte |
| Verkaufte Stückzahl | Bestätigte Bestellpositionen inklusive später erstatteter Stücke | Keine Netto-Lagerbewegung |
| Aktuelle Verfügbarkeit | Freigegebener Commerce-Verkauf, bestätigter `on_hand` minus blockierende Reservierungen | Keine Aussage über frühere Verfügbarkeit |
| Beitragsergebnisse | Nur belegte veröffentlichte Beiträge mit Plattformkennung, Zeit und Link | Keine Verbindung zu Websitebesuch oder Kauf |
| Google | Tagesgesamtsummen und gesonderte Query/Page-Zeilen aus Search Console | Andere Zeitzone, Datenverzug, unvollständige Suchanfragen |

Die Katalog-Fallbacks lesen veröffentlichte Product-JSON-LD-Daten oder einen
explizit konfigurierten lokalen Katalog. Das alte `stock` wird nicht verwendet.
Alle 15 vorhandenen Artikel wurden geprüft. Die tatsächlichen Bezeichnungen
lauten unter anderem `Flourit Armband` (CP-2026-0008), `Karneol Armband`
(CP-2026-0012), `Amnethyst Armband` (CP-2026-0014), `Serpentit Armband`
(CP-2026-0016) und `Bergkristall Armband` (CP-2026-0018). Kein Produkt wurde
umbenannt. Öffentlich vorhandene Materialnamen ergänzen die Suchbegriffe.

## Zahlungen, Erstattungen und Zeitbezüge

Die verwendete Stripe-Integration erlaubt `card`, `klarna`, `sepa_debit`.
`automatic_tax` und `allow_promotion_codes` sind deaktiviert. Die öffentliche
[Zahlungsinformation](https://www.carmaja-perlen.de/versand-und-zahlung/)
beschreibt verzögerte SEPA-Bestätigungen; das entspricht dem aktuellen
Warenkorbcode, nicht den älteren V1-Anweisungen ohne Warenkorb/SEPA.

Die Auswertung verändert keinen Router, Webhook, Worker, Checkout,
Reservierungs-, Zahlungs- oder Bestandszustand. Eine Dankeseite ist kein
Kaufnachweis. Doppelte Eingangsdaten werden anhand stabiler Bestell-,
Positions- und Stripe-Erstattungskennungen dedupliziert. Widersprüche
verhindern den neuen Auszug statt zu falschen Zahlen zu führen.

Bestellungen zählen nach `confirmed_at`, in UTC gelesen und nach Berlin
umgerechnet. Begonnene Vorgänge sowie ihr aktueller Status zählen nach
`checkout_sagas.created_at`: `created/pending/processing` offen,
`failed/canceled` fehlgeschlagen/beendet, `manual_review` gesondert. Eine
späte SEPA-Bestätigung ändert den ursprünglichen Vorgangsstatus und erscheint
am tatsächlichen Bestätigungsdatum als Bestellung. Diese unterschiedlichen
Einheiten bilden ausdrücklich **keinen Besuchstrichter**.

Erstattungen werden anhand ihres heutigen Status dem ursprünglichen Verkauf
zugerechnet. Ein verlässlich separat gespeichertes Stripe-Erstattungsdatum
ist dafür nicht erforderlich und wird nicht erfunden. Vollständige
Erstattungen ziehen den Warenanteil ab und weisen den Versandanteil getrennt
aus. Offene, zu prüfende und nicht eindeutig auf Waren/Versand aufteilbare
Teilbeträge lassen den betroffenen Netto-Warenumsatz offen. Der Bruttowarenwert
bleibt sichtbar. Stornierung allein zieht kein Geld ab. Berichte älterer
Verkaufskohorten können sich nach einer Erstattung ändern. Die Statistik
berechnet keine zusätzliche Umsatzsteuer und behauptet keine anderweitige
Steuerregel; sie übernimmt die gespeicherten EUR-Endpreise.

Eine besuchsbezogene Kaufquote wäre: gemessene Besuche mit verknüpftem
bestätigten Kauf / gemessene Besuche. Diese Verknüpfung fehlt. Es wird keine
Quote aus sämtlichen Bestellungen und unvollständigen Websitezahlen gebildet.
Auch mehrere Bestellungen ergeben keine zusätzlichen nachgewiesenen Besuche.

## Privater Commerce-Auszug

`scripts/export-statistics.php` liest ausschließlich benötigte technische
Felder; keine Kunden-, Adress-, Mail-, Token- oder Bestellnummern werden
ausgelesen. Bestell-/Payment-/Sessionkennungen dienen nur der Prüfung im
Arbeitsspeicher und verlassen den Export nicht. Die Ausgabe enthält Tages-
und Produktsummen, Katalog und Datenstand.

Vor einer Inbetriebnahme ist außerhalb des Webroots eine private PHP-Datei
einzurichten. Sie gibt ein Array mit folgenden Einträgen zurück:

- `pdo`: freigegebene MySQL-PDO-Verbindung eines ausschließlich lesenden
  Datenbankbenutzers mit geprüftem TLS; keine Zugangsdaten ins Repository.
- `environment`: exakt `live` oder `test`, niemals beide zusammen.
- `dataSince`: belegter Beginn des vollständig vorhandenen Bestellbestands,
  `YYYY-MM-DD`. Nicht ungeprüft aus der ältesten vorhandenen Bestellung ableiten.
- `excludedCheckoutIds`: private Liste eigener Live-Testbestellungen; darf leer
  sein, wenn keine entsprechenden Ausschlüsse bestehen.
- `operatorTestFilterConfirmed`: nur `true`, wenn eigene Live-Testkäufe
  ausdrücklich geprüft und gegebenenfalls ausgeschlossen wurden. Andernfalls
  bleiben die Geschäftskennzahlen als vorläufig gekennzeichnet.
- `webroot`: kanonischer tatsächlicher öffentlicher Webroot.

Auf IONOS ausschließlich `/usr/bin/php8.4 scripts/export-statistics.php
--config <private Konfiguration> --out <privater Auszug>`. Dieser Befehl ist
eine Einrichtungsbeschreibung, keine erteilte Server-/Deployfreigabe.

MySQL wird mit nachgewiesenem TLS, UTC, Repeatable Read und einer konsistenten
READ-ONLY-Transaktion ausgelesen. Auch nach Erfolg endet sie mit Rollback.
SQLite ist ausschließlich im getrennten Testmodus erlaubt und verwendet
`PRAGMA query_only=ON`. `cs_test_` und `cs_live_` trennen Stripe-Umgebungen.
Vorgänge ohne zuordenbare Stripe-Session sind ausgeschlossen; das ist eine
offen ausgewiesene Grenze für frühe Checkoutversuche ohne Stripe-Session.

Die Ausgabe liegt **außerhalb des Webroots** mit Modus 0600 und wird atomar
ersetzt. Wiederholung addiert keine Summen. Bei einem fehlgeschlagenen Abruf
bleibt der alte Auszug erhalten; wenn dessen privates Ziel weiter beschreibbar
ist, kennzeichnet eine kleine `.status.json`-Begleitdatei den Fehlversuch.
Andernfalls bleibt spätestens die Frischegrenze von 36 Stunden sichtbar.
Dateirechte müssen zum tatsächlich lesenden PHP-Benutzer passen; keine
pauschale Rechtefreigabe. Keine neuen Cronjobs oder Automationen eingerichtet.

Die geschützte Seite erhält den Pfad über `CARMAJA_STATS_COMMERCE_FILE`.
`CARMAJA_STATS_CATALOG_FILE` erlaubt bei lokaler Entwicklung einen expliziten
Katalog. Diese Pfade kommen ausschließlich aus der Serverkonfiguration,
niemals aus GET-Parametern.

## Beitrags- und Google-Auszüge

Optionale private JSON-Dateien werden über `CARMAJA_STATS_MARKETING_FILE` und
`CARMAJA_STATS_GOOGLE_FILE` verbunden. Gemeinsame Wurzel: `version:1`,
`kind:marketing|google`, `environment:live`, `generatedAt` als ISO-Zeitpunkt
mit Zeitzone. Fehlende Datei, ungültiges Format und alter Stand werden getrennt
gekennzeichnet. Die Produktivansicht lehnt `environment:test` ab.

Marketing: `platforms` enthält pro `pinterest`/`instagram` einen Zustand
`ok|partial|error|disconnected` mit kurzer `note`; `posts` enthält die Zeilen.
Eine Zeile benötigt `platform`, `id`, `status:published`, `url`, `publishedAt`,
`readAt`; optional `productId`, `variant` und ein lokales Produkt-JPEG unter
`thumbnail`. Externe Vorschaubilder werden nicht automatisch geladen.
`metrics` enthält `impressions`, `saves`, `pinClicks`, `outboundClicks` und
`window:lifetime|first14`. Fehlende Werte bleiben fehlend, echte 0 bleibt 0.
Plattformlinks sind auf HTTPS bei Instagram/Pinterest begrenzt.

`first14` benötigt zusätzlich `from`, `to`, `complete:true`, das exakte
Publikationsdatum als Anfang, einen Endzeitpunkt 336 Stunden später und einen
Abruf nach Ende des Fensters. Gesamtstände werden je Kennung und Fenster
ersetzt. Ein späterer Gesamtstand überschreibt den belegten 14-Tage-Wert
nicht. Gesamtstände sind keine Periodenleistung. `workflowCounts` kann separat
`planned`, `attempted`, `failed` für den **aktuellen** Arbeitsstand enthalten;
diese Werte behaupten keine Veröffentlichung im ausgewählten Zeitraum.

Google: `timezone:America/Los_Angeles`, `dataThrough`, `days` mit expliziten
Tagesgesamtsummen `{clicks,impressions}`, `queries` mit
`{day,query,page,clicks,impressions}`. Wiederholte Zeilen gleicher
Tag/Query/Page-Dimension werden ersetzt, nicht summiert. Anonymisierte oder
ausgelassene Suchanfragen bleiben außerhalb der Query-Summe. CTR ist Summe
Klicks / Summe Einblendungen. Markensuche enthält „carmaja“, ohne Beachtung
der Großschreibung. Die ausgewählten Datumsbezeichnungen gelten sichtbar in
Googles eigener Tageszeitzone und sind kein exakter Berlin-Zeitvergleich.

Dies sind implementierte Leseverträge, keine bereits laufenden Integrationen.
Ein erlaubter manueller Export oder später ausdrücklich eingerichteter
Collector muss sie befüllen. Keine Zugangsdaten oder Laufzeitdateien werden
mit dem Websiteexport paketiert. Kein Social-Media-Beitrag wird verändert.

## Reale Prüfung und verbleibende Lücken

- Der aktuelle Katalog mit allen 15 Namen/Artikelnummern wurde tatsächlich
  gelesen. Die Zahlungsbeschreibung wurde öffentlich lesend verifiziert.
- Metricool lieferte am 19.11.2026 eine verbundene Pinterest-Marke. Der erlaubte
  Abruf für 22.10.–18.11.2026 lieferte keine Beitragszeilen. Daraus werden
  **keine** Null-Kennzahlen und kein Nachweis fehlender Pins abgeleitet.
- Nach ausdrücklich erteiltem Lesezugriff wurden der produktive Website-Zähler
  und der Commerce-Bestand gelesen. Der nachstehende Quellenabgleich ersetzt
  die frühere Einschränkung, dass keine produktiven Summen geprüft waren.
- Kein laufender Instagram-Insights-/Queue- oder Search-Console-Export ist
  angebunden. Bereits anderweitig veröffentlichte Beiträge werden nicht allein
  aus einer Planungsdatei als neu veröffentlicht gezählt.
- Besuche, deduplizierte Aktionen, Gerät, UTM-Kampagnen, Besuch/Kauf-Zuordnung,
  historische Verfügbarkeit und Maßnahmenzeitpunkte fehlen. Die aktuelle
  Datenschutzerklärung deckt die bisherige aggregierte Erfassung ab. Es wurde
  keine Identitäts-/Sessionerkennung hinzugefügt und kein Erfassungsbeginn
  erfunden. Neue Messungen beginnen erst nach ausdrücklicher Klärung ihrer
  Einwilligungs-/Datenschutzgrundlage und tatsächlicher Aktivierung.

## Prüfungen und Vorschau

`npm ci --ignore-scripts --no-audit --no-fund`, `npm run lint:test` und
`npm run build:test` bestanden. Die Node-Suite bestand mit 288 von 291 Tests
unter Windows; die drei Fehler betrafen Unix-Rechte in unveränderten
Deployment-Shelltests. Diese drei Tests sowie der erweiterte Statistik-
Installer-/Rollbacktest bestanden anschließend unverändert unter WSL/Linux.
Auch Produktions-Bootstrap samt Wiederherstellung und Produktions-Smoke-
Orchestrierung (7 Szenarien) bestanden dort. Der Test-Smoke prüfte 28 Szenarien.
Ein zuerst parallel gestarteter Build kollidierte mit dem in der Testsuite
integrierten Build; der anschließende sequenzielle Lauf bestand den Buildtest.

PHP 8.4: `tests/php/statistics-report-test.php` prüft aktuell 70 Bedingungen,
einschließlich echter lesegeschützter SQLite-SQL-Projektion. Zusätzlich
bestanden Klickstatistik, Commerce-API (23), Warenkorbvertrag,
Worker-Mapping (34), SEPA-Deadline (79), Transaktions-Retry und
Stripe-Sandbox-Vertrag (29). Anschließend wurde die neue Exportabfrage gegen
die produktive MySQL-Datenbank geprüft und mit unabhängigen SQL-Summen
abgeglichen. Zwei vorhandene Stripe-Zahlungen wurden zusätzlich lesend
geprüft. Kein Kauf, Zahlungsversuch oder Erstattungsvorgang wurde ausgelöst.

Browserprüfung mit Headless Chrome: 55 Prüfungen bei Desktop 1440 Pixeln und
Mobil 390/320 Pixeln; keine Seitenfehler und kein horizontaler Seitenüberlauf.
Geprüft: fehlende Quellen, fehlerhafter Auszug, künstliche Kauf-/Google-/
Beitragsdaten mit sichtbarem Testbanner, Filter, Tabellenbedienung, mindestens
44 Pixel hohe Eingabefelder, PHP-Zugriffsschutz und direkte Helper-Aufrufe.
Apache Basic Auth bleibt unverändert; eine Live-Anmeldung wurde nicht getestet.

Lokale Vorschau während dieser Sitzung: `http://127.0.0.1:8791/statistik/`.
Beim Öffnen und Neuladen holt der lokale Prüfrouter die Website- und
Commerce-Zahlen erneut. Scheitert der Abruf, zeigt die Vorschau den letzten
verfügbaren Stand mit einem Fehlerhinweis.
Sie zeigt jetzt die geprüften echten Website- und Commerce-Auszüge sowie den
belegten Pinterest-Verbindungsstand. `?scenario=fixture` ist eine getrennte,
auffällig beschriftete Testvorschau. Der ausschließlich lokale Prüfrouter
simuliert einen bereits authentifizierten Benutzer; er gehört nicht zum
Repository oder Deployment. Die Screenshots und Prüfergebnisse liegen im
Codex-Artefaktordner `01a1f417-3110-7ff0-80e0-27849319a287`.

Die Betreiberseite wird ausschließlich über den Statistik-Workflow installiert.
Das Paket enthält die Dateien unter `statistik/`. Der separate CLI-Exporter
und die PowerShell-Lesebrücke greifen auf dieselbe Commerce-Projektion zurück.

## Echte Quellen verbunden: lesender Abgleich vom 19.11.2026

**Aktueller bereinigter Stand: 19.11.2026, 16:28 Uhr Europe/Berlin.**
Der Nutzer hat beide vorhandenen erfolgreichen Käufe vom 22.08. und
13.11.2026 ausdrücklich als eigene Testkäufe bestätigt. Beide sind aus
Bestell-, Stückzahl-, Waren- und Versandumsätzen der Statistik ausgeschlossen.
Für den Standardzeitraum und den gesamten ausgelesenen Bestand ergeben sich
damit **0 reguläre bestätigte Bestellungen und 0,00 € Warenumsatz**. Die
Originalbestellungen, Zahlungen und Lagerdaten bleiben auf dem Server erhalten.
Der bereinigte Auszug stimmt mit unabhängig gefilterten SQL-Summen überein.

Die private PHP-Konfiguration und SSH-Schlüssel wurden ausschließlich an
ihren bestehenden Orten verwendet. Es wurde keine Datei auf dem Server
installiert oder verändert und kein Datenbankbenutzer angelegt. Die
bestehende Datenbankverbindung arbeitet für den Export in einer erzwungenen
TLS-gesicherten READ-ONLY-Transaktion. Das ist kein neu eingerichteter,
ausschließlich lesender Datenbankaccount.

`scripts/read-production-statistics.ps1` (PowerShell 7) streamt den lokalen Exporter und
`scripts/statistics-source-reader.php` über den bestehenden, streng geprüften
SSH-Zugang in den Arbeitsspeicher von `/usr/bin/php8.4`. Es läuft kein Router,
Worker oder Publisher. Die Verbindung erlaubt ausschließlich Public-Key-
Authentifizierung; Hostschlüsselprüfung bleibt aktiv, Passwort-Fallback und
automatische Hostschlüsseländerungen sind ausgeschlossen.

Der Aufruf benötigt ein privates `-OutputDirectory` **außerhalb des Repositorys**.
Er schreibt dort `statistics-live-commerce.json`, `statistics-live-views.json`
und `statistics-live-origin.json`. Private/verbotene Felder, falsche Umgebung,
fehlende TLS-Nachweise und widersprüchliche Vergleichssummen verwerfen das
Paket vor jeder Ersetzung. Bei Abruf-/Validierungsfehlern bleiben die Daten
erhalten und erhalten einen separaten, geheimnisfreien Fehlermarker; die
Oberfläche zeigt „Abruf fehlgeschlagen“. Ein erfolgreicher Folgeabruf entfernt
diese Marker wieder. Wiederholte Abrufe ersetzen vorhandene Werte.
Der Export kann weiterhin manuell gestartet werden. Die lokale Vorschau
startet ihn zusätzlich beim Seitenaufruf.

`-VerifyStripe` ruft die vorhandenen PaymentIntents ausschließlich per HTTPS
GET mit expandierter `latest_charge` ab. Netzwerkaufrufe erfolgen nach Ende
der Datenbanktransaktion. Geprüft werden Live-Modus, eindeutige Zahlungsreferenz,
endgültiger Erfolg, Betrag, Währung, erfasster und erstatteter Betrag. Rohantworten,
PaymentIntent-/Checkoutkennungen, Kundendaten und Zugangsdaten verlassen den
Server nicht. Die Leseverträge entsprechen dem
[PaymentIntent-Abruf](https://docs.stripe.com/api/payment_intents/retrieve)
und dem [Charge-Objekt](https://docs.stripe.com/api/charges/object).

Ungefilterter Stripe-/SQL-Nachweis vor dem bestätigten Testausschluss:
**19.11.2026, 15:54 Uhr Europe/Berlin**. Die folgenden Rohsummen sind ein
Auditnachweis und enthalten die anschließend ausgeschlossenen Testkäufe.
Zeitraumgrenzen sind Berlin-Kalendertage, Ende exklusiv 19.11.:

| Quelle/Kennzahl | 22.10.–18.11.2026 | 24.09.–21.10.2026 |
| --- | ---: | ---: |
| Vorhandene Seitenaufrufe | 821 | 36 |
| Vorhandene Einstiegsaufrufe mit Quellkategorie | 234 | 31 |
| Tage mit Website-Zählern | 16 von 28 | 12 von 28 |
| Tage ohne Nachweis der Website-Erfassung | 12 | 16 |
| Bestätigte Live-Käufe vor Testausschluss | 1 | 0 |
| Warenwert vor Testausschluss | 20,00 € | 0,00 € |
| Versandkosten vor Testausschluss | 2,70 € | 0,00 € |
| Erfolgreiche zugeordnete Erstattungen | 0,00 € | 0,00 € |

Die gespeicherten Geschäftszahlen wurden für beide Zeiträume und den gesamten
vorhandenen Bestand unabhängig per SQL gegen Bestellung, Positionen, Zahlungen
und Erstattungen abgeglichen. Alle Summen stimmen überein. Der gesamte
ausgelesene Bestand enthält zwei bestätigte Live-Käufe: am 22.08. und am
13.11.2026, jeweils 20,00 € Warenwert und 2,70 € Versand. Beide Stripe-
PaymentIntents und Charges sind erfolgreich; eingegangene Beträge und null
Erstattungen stimmen exakt mit der Datenbank überein. Keine offenen Zahlungen
oder Erstattungen waren vorhanden. Zwei weitere Live-Zahlungsversuche sind
beendet und zählen nicht als Kauf.

**Bestätigte eigene Live-Testkäufe:** Die Nutzerantwort vom 19.11.2026 lautet
ausdrücklich „Beide: 22.08. und 13.11.2026“. Der Abruf wurde mit
`-ExcludedConfirmationDays '2026-08-22','2026-11-13' -OperatorTestFilterConfirmed`
bereinigt. Die Quelle enthält je Datum genau einen passenden Live-Kauf.
Mehrdeutige Datumsangaben werden abgelehnt; interne Kennungen bleiben auf dem
Server. Die Oberfläche nennt beide Ausschlüsse und zeigt echte Nullwerte.

Nachfolgende Abrufe übernehmen die bestätigte Ausschlussliste aus dem
vorhandenen privaten Herkunftsauszug, sofern keine ausdrücklichen neuen
Parameter gesetzt werden. Das wurde mit einem gewöhnlichen Live-Folgeabruf
ohne Ausschlussparameter geprüft. Eine abweichende vom Server zurückgelieferte
Filterregel wird abgelehnt, statt Testkäufe unbemerkt wieder einzurechnen.
Ausdrückliche neue Nutzerangaben haben weiterhin Vorrang.

Im bereinigten Bestand gibt es keine für einen erneuten Stripe-Abruf
verbleibenden Käufe; der Folgeabruf weist deshalb null eingeschlossene
Stripe-Prüfungen aus. Der vorherige direkte Nachweis der zwei ausgeschlossenen
Testzahlungen bleibt als Rohdaten-Audit oben dokumentiert. Es wurde keine
Bestellung gelöscht oder Zahlung erstattet. Website-Aufrufe und deren
Erfassungslücken bleiben vom Kauf-Testfilter unverändert.

Die früheste Migration ist vom 11.08.2026; die erste vollständig abgedeckte
Kalendergrenze des aktuellen Datenbankauszuges ist deshalb der 12.08.2026.
Die zwei vorhandenen Bestellnummern sind lückenlos. Unabhängige historische
Löschfreiheit wird daraus ausdrücklich nicht behauptet. Frühere Zeiträume
bleiben offen. Website-Daten sind weiterhin sparse; fehlende Tage sind keine
Nulltage, und aus 821 Aufrufen werden keine 821 Besuche gemacht.

Die Commerce-Daten enthalten 23 Artikel, davon 15 tatsächlich veröffentlichte
Produkte und acht weitere/frühere/deaktivierte Artikel. Öffentliche Namen
und kanonische Seitenpfade wurden anhand der SKU mit dem Product-JSON-LD der
wirklich vorhandenen Produktseiten abgeglichen. Frühere Pfadvarianten werden
bei Produktaufrufen anhand der stabilen SKU zusammengefasst. Der Quell-
Änderungszeitpunkt des Website-Zählers wird getrennt vom lokalen Abrufzeitpunkt
angezeigt. `CARMAJA_STATS_SOURCE_META_FILE` verbindet diese Metadaten.

Zusätzliche Prüfungen: 70 PHP-Bedingungen, 25 PowerShell-Prüfungen ohne
Netzwerk, 53 Browserprüfungen mit bereinigten echten Auszügen und erneut 55 Prüfungen
der getrennten Leer-/Fehler-/Testansichten erfolgreich. Die Echtansicht
prüft 28/7/90 Tage, Haupt- und Tageszahlen, Quellensummen, Waren-/Versandtrennung,
Katalog, Sortierung, Mobilbreiten 390/320, Zugriffsschutz und fehlende
Transaktionskennungen. Es gab keine Browserfehler und keine externen Requests
der lokalen Prüfung. Die Prüfungen erzeugten somit keine öffentlichen
Website-Zählungen oder Shopvorgänge.

Die echten Laufzeitdateien liegen ausschließlich im privaten Codex-
Artefaktverzeichnis `01a1f417-3110-7ff0-80e0-27849319a287/live-statistics`.
Sie sind nicht versioniert und kein Website-/Buildartefakt. Die lokale
Prüfrouter-Konfiguration bindet sie ein. Der Online-Abruf verwendet die
Vor-Ort-Quellen und benötigt diese lokalen Dateien nicht.

## Eng begrenzter Folgeauftrag

> Lies zunächst `website/docs/statistics-reporting.md` im Branch
> `codex/statistik-erweiterung`. Website-, SQL- und Stripe-Abgleich sind bereits
> umgesetzt und mit echten Daten geprüft. Beide bestätigten eigenen Live-
> Testkäufe sind bereits ausgeschlossen; diese Ausschlüsse bei Abrufen erhalten.
> Verbinde zusätzlich vorhandene Instagram-/Pinterest-Leistungsdaten und
> Search-Console-Auszüge, soweit passende erlaubte Lesezugänge vorliegen.
> Kläre vor einer neuen Besuchs-/Ereignismessung die konkrete Einwilligungs-
> und Datenschutzgrundlage; keine Identitätskennung aus technischen Shopdaten
> zweckentfremden. Ergänze anschließend nur die noch fehlende belegbare
> Besuch/Kauf-Verknüpfung. Bestehende Oberfläche und Auswertung nicht erneut
> bauen. Serverzugriff bleibt ausschließlich lesend. Kein Push oder Deployment.
> Eine später ausdrücklich freigegebene Veröffentlichung verwendet den getrennten
> Statistikwartungsweg, einschließlich Backup, Authprüfung und Rollback.
