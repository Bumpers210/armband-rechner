# Produktangebote im initialen HTML

Lokaler Lösungsvorschlag vom 19. November 2026. Branch `codex/product-offers-server-rendering`, Basis `72f5e119f7aa6b5c9960c77eade9b480187558f8`. Nicht veröffentlicht. Die Lösung benötigt zusätzlich zum statischen Export eine separat freizugebende PHP-/Apache-Installation.

## Bestätigter Fehler

Alle neun gespeicherten Google-URL-Detailprüfungen wurden geöffnet: Amazonit (0010), Sodalith (0009), Sonnenstein (0021), Labradorit (0017), Afrikanischer Türkis (0015), Amethyst (0014), Karneol (0012), Tigerauge/Falkenauge mit Strass (0011), Fluorit (0008). Jeweils dieselbe kritische Meldung: entweder `offers`, `review` oder `aggregateRating` angeben. Alle Seiten sind unabhängig davon indexiert. Keine Bewertung wird ergänzt oder erfunden.

Der Next.js-Export enthält bislang den Product-Grunddatensatz, aber noch kein Offer. Erst der Browser liest die öffentliche Produkt-API und ergänzt das Angebot. Google hat bei den betroffenen gespeicherten Crawls kein Offer erfasst. Warum Google gerade diese einzelnen JavaScript-Abrufe nicht verarbeitet hat, ist nicht direkt nachweisbar. Google empfiehlt Preis und Verfügbarkeit im initialen HTML und nennt dynamische Product-Ausgabe als mögliche Zuverlässigkeitsgrenze: [Product structured data](https://developers.google.com/search/docs/appearance/structured-data/product-snippet).

## Datenfluss und Schutz der Shopfunktionen

1. Apache leitet ausschließlich bestehende `/armbaender/cp-…/`-Seiten intern an `product-offer.php`. Beide separat installierten PHP-Dateien und das kanonische `index.html` müssen existieren. Ohne Runtime bleibt die bisherige statische Auslieferung aktiv. HTTPS-/www-Regeln bleiben vorgeschaltet.
2. PHP liest das aktuelle exportierte HTML. Route, reale Dateipfade, Produkt-UUID, SKU und kanonische Product-URL werden geprüft. Es gibt keine zweite Zuordnungstabelle und keine neue Produktdatenbank.
3. PHP liest einmal `GET https://api.carmaja-perlen.de/shop/v3/products/{productId}` mit dem bestehenden Website-Origin. Diese öffentliche API verwendet `cart-commerce.php::loadLiveProduct`, `commerce_products.price_minor` und den bestehenden verfügbaren Bestand. Besucher-Cookies, Sitzung, Authentifizierung oder Zugangsdaten werden nicht weitergereicht. Zielhost ist fest; Query-Parameter können Quelle und Produktidentität nicht ändern. TLS-Verifikation bleibt eingeschaltet; keine Redirects, 3 Sekunden Timeout und höchstens 32 KiB JSON.
4. Ausschließlich validierte öffentliche Produkt-ID, EUR-Centpreis, Verfügbarkeit und verfügbare Menge gelangen in den Antwortzustand. Derselbe API-Datensatz erzeugt sichtbaren Preis/Status, `Product.offers` und einen kleinen inerten JSON-Startzustand für React. Versand und Rückgabe werden aus den bereits im Export enthaltenen geprüften Organization-Daten übernommen. Produkttexte, Bilder und Rechtsbedingungen bleiben unverändert.
5. Die erste React-Ausgabe entspricht der PHP-Ausgabe exakt. Es gibt kein `suppressHydrationWarning`, keine RSC-Manipulation und kein Ersetzen des gesamten Dokuments. [React hydrateRoot](https://react.dev/reference/react-dom/client/hydrateRoot) verlangt übereinstimmendes initiales Markup.
6. Der Browser führt weiterhin seinen eigenen frischen Produkt-GET aus. Erst dessen Erfolg kann Kaufen freigeben. Hinzufügen, Warenkorbversion/CSRF, Mengenprüfung, Preis-/Bestandskontrolle im Checkout, Bestellungen und Zahlungen werden nicht geändert.

Es gibt keinen Preis-Snapshot, keinen Angebots-Cache und keine Datei-/Datenbank-Schreiboperation der Runtime. HTML bekommt `Cache-Control: no-store`. Jeder vollständige Seitenabruf liest die bestehende Live-Quelle neu. Next-interne Navigation bleibt mit dem bisherigen Browserabruf kompatibel. Die Produktkomponente hat bereits einen UUID-Key, sodass ihr Startzustand nicht zwischen Produkten weiterlebt.

## Fehlerverhalten

- API meldet tatsächlich nicht verfügbar: sichtbarer Status und `OutOfStock` stimmen überein; Kaufen bleibt gesperrt.
- Serverseitiger API-Ausfall oder ungültige Daten: HTTP 503, `Retry-After: 60`, kein Preis und kein erfundenes Offer. Der Produktinhalt bleibt bei gültigem Export lesbar. React erhält denselben Fehlerzustand und bietet erneutes Prüfen an. Kein pauschaler Ausverkauft-Ersatz und keine internen Fehlermeldungen.
- Serverabruf erfolgreich, anschließender Browserabruf fehlgeschlagen: Das beim Abruf verifizierte Angebot bleibt sichtbar und strukturiert erhalten. Ein zusätzlicher Hinweis erklärt den Fehler; Kaufen ist gesperrt. Erneutes Prüfen kann Anzeige und JSON-LD gemeinsam aktualisieren. So entfernt ein einzelner nachgelagerter JavaScript-Netzwerkfehler nicht das gerade serverseitig geprüfte Angebot. Wie bei jeder Seite können sich Preis/Bestand nach dem Abruf ändern; der bestehende Warenkorb/Checkout bleibt maßgeblich.
- Unbekannter/ungültiger Pfad: 404; fremde Methoden: 405; vorhandenes direktes Produkt-`index.html`: 301 zur Slash-URL. GET und HEAD werden unterstützt.
- Nicht zum Verkauf freigegebene Informationsseiten werden durch den Renderer nicht kaufbar gemacht. Für den aktuellen Katalog wurden alle 15 Seiten geprüft.

## Dateien und automatische Prüfungen

- `components/shop-buy-now.tsx`, `components/json-ld.tsx`: gemeinsamer Startzustand und identifizierbare Angebotsbereiche.
- `lib/live-product-offer.mjs` / `.d.mts`: gemeinsame strikte Projektion, Bootstrap-Validierung, Angebotserzeugung und exakte Centdarstellung.
- `product-offer-hosting/product-offer.php`, `_internal/product-offers.php`, `apache-rules.conf`: getrennte, nur lesende Hosting-Ergänzung.
- `hosting/.htaccess`, `.htaccess.publish.example`: identisches, durch vorhandene Runtime-Dateien begrenztes Produkt-Routing. Bestehende Cache-/Kompressionsregeln bleiben erhalten.
- `scripts/verify-export.mjs`: JSON-LD-Parser akzeptiert das neue Script-ID-Attribut; alle bestehenden Export-/Datenprüfungen bleiben aktiv. Der reine statische Export darf weiterhin keinen statisch eingefrorenen Preis und kein Offer enthalten.
- `tests/php/product-offer-renderer-test.php`: Transport, Datenvalidierung, Preis-/Bestandsänderung, Fehler, Routing, Escaping und unveränderte Richtlinien.
- `tests/node/live-product-offer.test.mjs`, `tests/shell/product-offer-routing-test.sh`: Client-Vertrag und tatsächlicher isolierter Apache-Routingtest. Der Apache-Test benötigt auch das MIME-Modul; die zunächst unvollständige lokale Testkonfiguration wurde korrigiert.
- `tests/product-offer-rendering.mjs`: PHP 8.4 plus echte exportierte Seiten und die tatsächliche React-Komponente; 15 Produkte × verfügbar/ausverkauft/API-Ausfall = 45 exakte Markupvergleiche. Keine echten API-/Bestellaufrufe. Im bestehenden PHP-8.4-Paket-CI läuft zusätzlich der echte Produktionsbuild mit dieser Integrationsprüfung; dessen vorhandene PHP-Schleife umfasst auch den neuen PHP-Test.
- `tests/fixtures/product-offers-router.php`: ausschließlich Loopback-Testserver mit künstlicher API, Warenkorb und Zahlungsstub. Die lokal ausgelieferten Test-JS-Antworten ersetzen nur für die Testverbindung den API-Origin. Keine gebauten Dateien werden dabei geändert. Diese Fixture darf niemals veröffentlicht werden.

Aus dem Repository-Stamm nach `npm --prefix website ci`:

```sh
npm --prefix website audit --audit-level=moderate
npm --prefix website run lint -- --deny-warnings
npm --prefix website test
npm --prefix website run build
php -l website/product-offer-hosting/product-offer.php
php -l website/product-offer-hosting/_internal/product-offers.php
php website/tests/php/product-offer-renderer-test.php
node website/tests/product-offer-rendering.mjs
```

Apache muss für den echten Routingtest verfügbar sein, optional über `CARMAJA_APACHE_BINARY` und `CARMAJA_APACHE_MODULES`. PHP 8.4 ist für die PHP-/React-Integration erforderlich; Windows kann den PHP-Pfad über `CARMAJA_PHP_BINARY` angeben. Es werden keine TLS-Ausnahmen benötigt. Die spätere Live-Quelle lässt sich mit aktivem OpenSSL und einer vertrauenswürdigen CA prüfen.

## Lokaler Nachweis vom 19. November 2026

- Vollständiger Linux-Lauf mit Node 24.16.0: Audit 0 Schwachstellen, Lint 0 Warnungen/Fehler, 300 Node-Tests ohne übersprungene Fälle, beide Apache-Tests, alle bestehenden Deployment-Shelltests und Produktionsbuild/Exportprüfung erfolgreich.
- Native PHP 8.4.22: Runtime-Syntaxprüfung, 57 Assertions und alle 45 PHP-/React-Markupvergleiche erfolgreich. Die unter Linux fehlende Klickstatistik-PHP-Prüfung wurde separat erfolgreich ausgeführt. Neun betroffene Paket-/Workflow-Vertragstests nach der CI-Ergänzung erneut erfolgreich.
- Alle 15 frischen öffentlichen API-Antworten mit der lokalen initialen HTML-Ausgabe verglichen: sichtbarer Preis, Offer-Preis, EUR und Verfügbarkeit jeweils identisch; 14 InStock, Wunscharmband OutOfStock. Nur lesende Produkt-GETs, kein Checkout. Gemessene API-Lese-/Renderzeit dieses Rechners ca. 492–1.677 ms; kein Hosting-/CWV-Nachweis.
- Browser mit künstlicher API bei Desktop 1280 px und Mobil 390 px: Navigation, Bilder/Galerie, Warenkorb hinzufügen/ändern/leeren sowie Checkout bis zum lokalen Zahlungsstub erfolgreich. Kein horizontaler Überlauf, keine kaputten Bilder oder Hydrationfehler in den geprüften Ansichten. Ausverkauf, API-Ausfall beider Seiten, nur Browserausfall und Retry mit geändertem Preis bestanden. Keine echten Bestellungen/Zahlungen.
- `git diff --check` erfolgreich; Produkttexte, Katalog, Bilder, Rechtsbedingungen, API/DB, Warenkorb-/Checkout-Code und Lockfile unverändert. Keine Hosting-Installation, kein Push, kein Deployment oder Google-Validierungsantrag.

## Rein lesende Hosting-Vorprüfung

`scripts/check-product-offer-hosting.php` bleibt außerhalb des öffentlichen Webroots. Sie läuft ausschließlich als PHP-8.4-CLI, prüft HTTPS-Wrapper/OpenSSL und `allow_url_fopen`, liest Sitemap/Produkt-UUIDs aus dem angegebenen Export und ruft ausschließlich die vorhandenen öffentlichen Produkt-GETs auf. Sie schreibt keine Dateien oder Shopdaten und gibt keine Zugangsdaten oder privaten Konfigurationen aus. Der optionale Zusatz `--verify-render` vergleicht den im Speicher erzeugten HTML-/Offer-Datensatz mit derselben Live-Antwort.

Für die spätere **gesondert freizugebende** lesende IONOS-Prüfung, aus einer privaten Kopie mit unveränderter Paketstruktur:

```sh
/usr/bin/php8.4 /absoluter/privater/pfad/scripts/check-product-offer-hosting.php --webroot=/home/www/carmaja
```

Erst mit dem passenden neuen Export `--verify-render` ergänzen. Die erste Prüfung benötigt keine Runtime-Installation im Webroot und keine Änderung der Root-.htaccess. Das Ergebnis bestätigt nur CLI und lesenden API-Zugriff. Die tatsächlich verwendete Web-PHP-Version und wirksame Apache-Sperren/Rewrite-Regeln müssen separat geprüft werden; ein vorhandener Guard-Dateiinhalt allein beweist keine wirksame HTTP-Sperre.

Am 19. November lokal zusätzlich geprüft: Standard-CLI ohne aktiviertes OpenSSL bricht korrekt mit `HTTPS_STREAM_REQUIRED` ab; mit OpenSSL und vertrauenswürdiger CA bestehen alle 15 Live-GETs plus HTML-/Offer-Vergleich. Fehlender Webroot bzw. fehlender interner Guard sollen vor einem Produktabruf abbrechen. Der native Windows-PHP-Schalter kann von der späteren IONOS-Webkonfiguration abweichen; TLS-Prüfung wird niemals abgeschaltet.

Ein lokales Reviewpaket kann die beiden Runtime-Dateien, das separate Regel-Fragment, diese private Vorprüfung und die Dokumentation mit SHA-256-Manifest enthalten. Dies ist kein freigegebenes Installationsartefakt. Es enthält weder den allgemeinen Statistikhosting-Installer noch vorhandene Tracking-PHP-Dateien, Konfigurationen oder Shop-/Zahlungscode. Vor einer tatsächlichen Installation ist es aus dem freigegebenen und erfolgreich in CI geprüften Commit neu zu erzeugen.

## Freigegebene lesende IONOS-Prüfung

Am 19. November 2026 gab der Nutzer ausschließlich lesenden SSH-Zugriff frei, ausdrücklich ohne Push, Upload oder Deployment. Der Prüfcode wurde über Standardeingabe ausgeführt und nicht als Datei auf dem Server abgelegt.

- `/usr/bin/php8.4`: CLI 8.4.28, OpenSSL, HTTPS-Wrapper und `allow_url_fopen` aktiv, automatischer Sitzungsstart aus. Alle 15 Produkt-GETs aus dem aktuellen serverseitigen Sitemap-/HTML-Bestand funktionierten mit regulärer TLS-Prüfung. Gemessene Server-API-Zeiten 38,6–284,9 ms, kein CWV-/vollständiger Seitenzeitnachweis.
- Root-.htaccess, interner Guard und `pageview.php` entsprechen dem Basiscommit. Die drei bereits bekannten Abweichungen bei `click.php`, `_internal/tracking.php` und `statistik/index.php` bestehen weiterhin. Kein allgemeiner Hosting-Installer und keine Überschreibungen.
- Die interne Sperre wurde auch öffentlich geprüft: vorhandene `_internal/tracking.php` liefert HTTP 403. HEAD auf `pageview.php` liefert erwartungsgemäß 405 mit Allow POST und ohne Antwortkörper; kein Tracking-POST wurde gesendet.
- Beide neuen Runtime-Dateien fehlen weiterhin, der Regelblock ist nicht aktiv. HEAD auf die neue Entry-URL liefert 404. Die Lösung ist weiterhin nicht veröffentlicht.
- **Offen:** Die genaue PHP-Version und HTTPS-Konfiguration der Web-SAPI sind durch diese CLI-Prüfung nicht bewiesen. Der Webserver gibt keinen `X-Powered-By`-Versionsheader aus; die lesbaren Hostingdateien enthalten keine explizite Versionszuordnung. Vor Aktivierung muss diese Zuordnung über die vorhandene Hostingverwaltung oder eine separat freigegebene Diagnose geprüft werden. Es wurde kein öffentlicher Diagnose-Endpunkt angelegt.

Keine Serverdateien, Freigabe-Gates, Warenkorb-, Bestell- oder Zahlungsdaten verändert. Der zusätzliche neue CLI-Prüfer wurde lokal lintgeprüft, mit allen 15 tatsächlichen Produktantworten samt Offer-Vergleich getestet und gegen fehlenden Webroot/Guard geprüft. Ein versehentlicher HTTP-Aufruf des Prüfers ergibt 404 und einen leeren Antwortkörper. Die bestehenden 57 PHP-Prüfungen und 45 exakten PHP-/React-Markupvergleiche bestehen weiterhin; Lint und Diff-Prüfung erfolgreich.

## Spätere Veröffentlichung: noch nicht freigegeben

Der normale statische Website-Deploy allein aktiviert diese Lösung nicht. Runtime-Dateien bleiben absichtlich außerhalb des statischen Export-/Deployartefakts und des Warenkorb-Pakets. Keine bestehenden Deployment-Schutzprüfungen werden gelockert.

Vor einer späteren Veröffentlichung sind auf IONOS PHP 8.4, `allow_url_fopen`, HTTPS-Wrapper/OpenSSL, vertrauenswürdige CA-Zertifikate, ausgehender lesender API-Zugriff und die vorhandene Sperre des `_internal`-Verzeichnisses zu bestätigen. Der lokale HTTPS-Abruf mit geprüfter CA war erfolgreich; das ist kein Nachweis der IONOS-Konfiguration. Verfügbarkeit und Laufzeit dieses zusätzlichen synchronen API-Abrufs müssen dort gemessen werden: Er verbessert die Daten-Erfassung, fügt dem Produkt-HTML aber eine API-Abhängigkeit und deren Antwortzeit hinzu. Drei Sekunden sind die Timeoutgrenze, kein Performanceziel.

Nach ausdrücklicher Freigabe: exakte Dateistände sichern und vergleichen, Hilfsdatei sowie Entry atomar separat installieren, passenden Website-Export bereitstellen, die begrenzte Apache-Regel zuletzt aktivieren und sofort alle 15 initialen HTML-Antworten ohne JavaScript, HEAD/Status/No-Store, Preise/Richtlinien sowie Browser und Warenkorb prüfen. Bei Fehlschlag zuerst den neuen Regelblock zurücknehmen; die bisherige Browserlösung bleibt kompatibel. Erst danach Google Rich Results und Search Console erneut prüfen bzw. Fehlerbehebung validieren.

**Nicht den allgemeinen acht Dateien umfassenden Statistikhosting-Installer ausführen:** Die zuletzt dokumentierten Serverabweichungen bei `click.php`, `_internal/tracking.php` und `statistik/index.php` sind ungeklärt. Diese Dateien sind für die Lösung nicht erforderlich und dürfen nicht überschrieben werden. Keine API-/Checkout-Installation und keine Änderungen an Freigabe-Gates erforderlich.

Die lokalen Testergebnisse und der aktuelle Bearbeitungsstatus stehen im bestehenden Notion-SEO-Bericht. Ein lokal bestandener Test bedeutet nicht, dass Google die Korrektur schon gecrawlt oder die neun Hinweise schon entfernt hat.
