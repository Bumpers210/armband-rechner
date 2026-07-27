# Carmaja-Perlen Website

Öffentlich freigegebene deutschsprachige Markenwebsite für Carmaja-Perlen.

- Hauptdomain und Canonical: `https://www.carmaja-perlen.de/`
- Sitemap: `https://www.carmaja-perlen.de/sitemap.xml`
- Robots-Datei: `https://www.carmaja-perlen.de/robots.txt`
- Indexierbar: ausschließlich die Startseite
- Impressum und Datenschutz: `noindex, follow`

## Entwicklung und Export

```powershell
npm install
npm run dev
```

Die vollständige Produktionsprüfung und der statische Export erfolgen mit:

```powershell
npm run lint
npm run build
```

`npm run build` erzeugt `out/`, kopiert danach die PHP- und Apache-Dateien
aus `hosting/` in das Exportverzeichnis und prüft den vollständigen
Veröffentlichungsstand automatisch.

Der Export ist für klassisches IONOS Webhosting Plus vorgesehen. Die Pfade
`/v2`, `/v2/`, `/verspielt` und `/verspielt/` werden nicht mehr als Seiten
exportiert und durch Apache dauerhaft auf die kanonische Hauptadresse
umgeleitet.

## Inhalte und Metadaten

Texte, Kontaktadressen, externe Links und die Hauptdomain werden zentral in
`content/site-content.ts` gepflegt.

Die Startseite enthält:

- einen absoluten Canonical-Link,
- Open-Graph- und Twitter-Metadaten mit dem vorhandenen Hero-Bild,
- Organization-JSON-LD mit den freigegebenen Anbieterangaben,
- ein typografisches SVG-Favicon,
- keine Tracking-Pixel oder eingebetteten Plattforminhalte.

Die Kontaktadresse lautet `kontakt@carmaja-perlen.de`. Der vollständige
Datenschutztext mit Stand Juli 2026 wird unter `/datenschutz/` ausgegeben.

## Robots und Sitemap

`robots.txt` erlaubt das Crawling der Website und schließt ausschließlich
technische Bereiche aus:

```text
User-Agent: *
Allow: /
Disallow: /statistik/
Disallow: /click.php
Disallow: /api/
Disallow: /_internal/

Sitemap: https://www.carmaja-perlen.de/sitemap.xml
Host: www.carmaja-perlen.de
```

Die Sitemap enthält ausschließlich `https://www.carmaja-perlen.de/`.
Impressum und Datenschutz werden nicht über `robots.txt` blockiert, damit
Suchmaschinen deren `noindex`-Metadaten lesen können.

## Klickmessung

Ausgehende Vinted- und Instagram-Links verwenden
`/click.php?target=...&position=...`.

Der PHP-Endpunkt:

- akzeptiert ausschließlich die Ziele `vinted` und `instagram`,
- akzeptiert ausschließlich `hero`, `gallery`, `contact` und `footer`,
- lehnt zusätzliche oder ungültige Parameter mit HTTP 400 ab,
- leitet gültige Ziele mit HTTP 302 weiter,
- speichert nur Datum, Ziel, Position und aggregierte Klickanzahl,
- verwendet `flock` für konkurrierende Schreibzugriffe,
- verwendet keine Cookies, Besucher-IDs oder Browser-Speicher,
- speichert weder IP-Adresse noch User-Agent noch Referrer,
- führt die Weiterleitung auch dann aus, wenn das Zählen fehlschlägt.

Tageswerte werden nach zwölf Monaten dauerhaft zu Monatswerten aggregiert.
Die kleine Datenmenge wird in einer JSON-Datei gespeichert; MySQL wird nicht
verwendet.

## Produktverwaltung

Die Android-App kann Produktentwürfe an eine geschützte PHP-API unter `/api/`
übertragen. Entwürfe verwenden eine unveränderliche `draftId`; die öffentliche
SKU wird erst beim ersten erfolgreichen Publish vergeben. Mutierende Anfragen
verwenden `expectedVersion` und erhalten bei veraltetem Stand HTTP 409.
Publish- und Statusaktionen sind über eine clientseitige `operationId`
idempotent.

Öffentliche Produktdaten liegen in `content/products.json`. Die Website zeigt
keine Verkaufspreise; Vinted bleibt die verbindliche Quelle für Preis und Kauf.
Statuswirkung:

- `published`: Übersicht, Detailseite und Sitemap
- `sold`: nicht in Übersicht/Sitemap, Detailseite mit `noindex`
- `disabled`: keine öffentliche Seite
- `draft` und `ready`: niemals öffentlich

Setup und Restore sind in
[`docs/product-management-setup.md`](docs/product-management-setup.md)
dokumentiert. Der private IONOS-Pfad muss im tatsächlichen Konto geprüft
werden, bevor die API produktiv genutzt wird.

Ohne weitere Konfiguration liegt die Datei unter
`out/private-data/clicks.json`. Dieses Verzeichnis wird durch eine aktive
`.htaccess` vollständig gegen Webzugriffe gesperrt. Bevorzugt wird ein
Speicherort außerhalb des öffentlichen Webverzeichnisses.

## Statistikbereich

Das Dashboard liegt unter `/statistik/`. Es zeigt ausschließlich:

- Klicks heute,
- Klicks der letzten 30 Tage,
- Klicks insgesamt,
- Vinted- und Instagram-Klicks,
- Klicks nach Position,
- eine Tabelle der Tageswerte.

Ohne von Apache bestätigte HTTP-Basic-Authentifizierung antwortet das
Dashboard zusätzlich mit HTTP 503. Es enthält keine Diagrammbibliothek und
keine externen Skripte.

## IONOS-Veröffentlichung

1. Per SSH bei IONOS anmelden und ein privates Verzeichnis anlegen:

   ```bash
   mkdir -p ~/carmaja-private
   cd ~/carmaja-private
   pwd
   ```

2. Ein starkes Dashboard-Passwort mit bcrypt erzeugen:

   ```bash
   htpasswd -Bc .htpasswd statistik
   ```

   Die erzeugte `.htpasswd` bleibt ausschließlich auf dem Webspace und darf
   niemals in Git oder `out/` abgelegt werden.

3. Optional die Statistikdatei außerhalb des Webroots vorbereiten:

   ```bash
   touch clicks.json
   chmod 640 clicks.json
   ```

4. Die bestehende Root-`.htaccess` auf dem Webspace sichern. Die Regeln aus
   `out/.htaccess` mit vorhandenen notwendigen Serverregeln zusammenführen
   und anschließend den übrigen Inhalt von `out/` hochladen. Die produktiven
   Regeln erzwingen HTTPS, die `www`-Domain und die Weiterleitungen alter
   Designpfade.

5. Einen bisherigen Passwortschutz der gesamten Vorschau erst beim
   öffentlichen Umschalten entfernen. Der separate Passwortschutz für
   `/statistik/`, die Sperre für `private-data/` und die Sperre für
   `/_internal/` bleiben aktiv.

6. In der Root-`.htaccess` die vorbereitete
   `SetEnv CARMAJA_STATS_FILE`-Zeile mit dem absoluten privaten Datenpfad
   ergänzen.

7. In `statistik/.htaccess.example` den absoluten Pfad zur erzeugten
   `.htpasswd` eintragen und die Datei als `statistik/.htaccess` ablegen.

8. Nach dem Upload kontrollieren:

   ```bash
   curl -I "http://carmaja-perlen.de/"
   curl -I "https://carmaja-perlen.de/impressum/"
   curl -I "https://www.carmaja-perlen.de/v2/?quelle=alt"
   curl -I "https://www.carmaja-perlen.de/click.php?target=vinted&position=hero"
   curl -I "https://www.carmaja-perlen.de/private-data/clicks.json"
   curl -I "https://www.carmaja-perlen.de/statistik/"
   ```

   Erwartet werden permanente Weiterleitungen zur kanonischen Domain,
   HTTP 302 für den gültigen Tracking-Link, ein verweigerter Zugriff auf die
   Statistikdatei und eine Authentifizierungsabfrage für `/statistik/`.

## Google Search Console

1. In der Google Search Console eine neue Domain-Property
   `carmaja-perlen.de` anlegen.
2. Den von Google ausgegebenen DNS-TXT-Eintrag im IONOS-DNS-Bereich der
   Domain hinterlegen.
3. Nach erfolgreicher Bestätigung
   `https://www.carmaja-perlen.de/sitemap.xml` einreichen.
4. Die Startseite über die URL-Prüfung testen.
5. Prüfen, dass die Seite erreichbar, indexierbar und nicht durch einen
   Passwortschutz blockiert ist.
6. Die Indexierung der Startseite beantragen.

Bei späteren Änderungen darf für die Startseite nicht versehentlich erneut
`noindex`, `nofollow` oder eine vollständige Robots-Sperre aktiviert werden.
Der Produktions-Build prüft diesen Zustand automatisch.

## IONOS WebAnalytics

Es ist kein Google Analytics, Meta Pixel oder sonstiger fremder
Analytics-Code eingebunden.

IONOS beschreibt Tracking und Logging für WebAnalytics aktuell als
standardmäßig aktiv. Der tatsächliche Aktivstatus muss im IONOS-Konto unter
`Hosting > Weitere Funktionen > Besucherstatistiken analysieren mit
WebAnalytics` kontrolliert werden. Die Datenschutzhinweise müssen der
tatsächlich bereitgestellten IONOS-Konfiguration entsprechen.
