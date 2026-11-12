# Schritt 2: PC-Artikelverwaltung und 3200-Pixel-Bilder

Stand: 12. November 2026. Produktionsfaehiges Paket, **keine Bereitstellung oder
Aktivierung**. Bestehende Test-/Produktionsdaten und private Konfigurationen
werden durch diesen Arbeitsschritt nicht gelesen oder veraendert.

## Gemeinsamer Datenweg

Die statische Seite `/artikelverwaltung/` ist auch im Produktionsbuild enthalten,
mit `noindex`, aber ohne Artikel-, Benutzer- oder Zugangsdaten. Sie spricht nur
`https://api.carmaja-perlen.de/product-browser/index.php` an. Der Testbuild spricht
weiterhin nur die Test-API an; der vorhandene isolierte Testadapter bleibt erhalten.
Der Produktionsrouter enthaelt keinen Teststagepfad und kein Testskript.

Browser und App verwenden dieselben privaten Produktbenutzer, V5-Dienste und
Produktdateien unter `productPrivateDir`. Kein zusaetzlicher Produktbestand,
kein Browser-Geraetetoken und kein zweites Benutzerkonto. Der Shop-Admin bleibt
ein eigener Sicherheitsbereich. Kalkulation, Preise, Version/Hash, Pflicht-
hinweise und Bestandsvorgaenge werden nicht neu implementiert oder umgangen.

`productBrowserProductionEnabled` ist ein eigener boolescher Runtimevertrag,
standardmaessig **false**; im Test darf der Produktionsschalter nicht true sein.
Die bestehende reale Runtime wird nicht ergaenzt. Die spaetere Aktivierung ist
ein eigener freizugebender Schritt nach erfolgreicher Produktumstellung.
V5-Schreibschalter, Warenkorb-/Bestandsvertrag, Publishergates und Wartungsmarker
bleiben zusaetzlich wirksam. Eine sichtbare Anmeldeseite aktiviert keinen Shop.

## Browser-Sicherheitsgrenze

- Ausschliesslich HTTPS, exakter Host und Origin; kein Vertrauen in Forwarded-
  Header, keine Origin-Reflexion und kein Geraete-Bearer im Browser.
- Separates hostgebundenes Cookie `__Secure-cmj_product_production`, Pfad
  `/product-browser/`, Secure, HttpOnly, SameSite=Strict, 30 Minuten.
- Private Sitzungen unter `productPrivateDir/auth/browser-sessions`, nicht im
  Shopverzeichnis oder Webroot; zufaellige Token, gespeicherte Dateinamen nur
  aus Tokenhashes, Atomik und 0600-Dateien. Umgebung wird je Sitzung gebunden.
- Vorhandene App-Passwortpruefung und Login-Sperrzeiten; Passwortwechsel,
  Benutzerdeaktivierung, Logout und Ablauf widerrufen den Browserzugang.
- CSRF fuer schreibende Aufrufe, 120 authentifizierte Anfragen/Minute/Sitzung,
  dieselben Idempotenz-/Versionsvertraege wie im vorhandenen Testbrowser.
- Strikte Methoden-/Routen-/Content-Type-/Groessenpruefung; CORS nur GET/POST/PUT
  und Content-Type, X-CSRF-Token, Idempotency-Key. Alle Antworten no-store.
- JSON maximal 64 KiB; einzelne Multipart-Anfrage maximal 6 MiB. Uploads werden
  vom bestehenden gemeinsamen JPEG-Dienst validiert und neu kodiert.
- Keine Auth-Daten in URLs, Antworten, lokalen Browserspeichern oder Logs;
  interne Fehlerdetails werden nicht ausgegeben. Die Kalkulationsquelle bleibt
  dieselbe feste, lesende Preisliste wie in der App (kein frei waehlbarer URL).

Das komplette private Paket enthaelt nun auch `product-browser.php`,
`product-browser-http.php` und `product-price-list.php`. Es enthaelt weiterhin
keine Runtime, Benutzerdatei, Testadapter oder sonstige privaten Laufzeitdaten.
Der vorhandene Wartungsschutz wird beim Paketbau beibehalten; ein fehlender
Schutz im direkten Browsercontroller sperrt Schreiboperationen ebenfalls.

## Bilder und Appvertrag

Test und Produktion: JPEG, hoechstens 3200 Pixel lange Kante und 5 MiB je Bild,
weiterhin maximal fuenf Bilder. App und Browser skalieren nur nach unten;
bestehende kleinere Originale werden nicht hochgerechnet oder erneut geschrieben.
Serverseitige Neukodierung entfernt EXIF/Standortdaten und angehaengte Daten.
Vorhandene kleine Website-Vorschaubilder bleiben getrennt vom Vergroesserungsbild.
Die Groessenvalidierung erfolgt vor der Bitmap-Decodierung.

Vor spaeterer Bereitstellung PHP-Web-SAPI auf mindestens 5 MiB
`upload_max_filesize` und 6 MiB `post_max_size` pruefen. Das Paket veraendert keine
PHP-/Apache-/IONOS-Konfiguration und umgeht keine bestehenden Servergrenzen.
Reale grosse Uploads und App-Abgleich sind beim spaeteren freigegebenen
Bereitstellungsschritt erneut zu pruefen.

App-Paket, Signaturen und Versionen bleiben unveraendert: Produktion 1.4.0 / 8,
Beta 1.4.0-beta.4 / 13. Der signierte Produktionsrelease und die Installation
der angepassten App gehoeren zum nachfolgenden Schritt 3, nicht zu Schritt 2.

## Nachweise und Grenzen

Lokal bestanden: Android `testDebugUnitTest`/`lintDebug`, PHP-8.4-API-Regressions-
suite, 37 bisherige Browserpruefungen, 46 neue Produktionsgrenzpruefungen und
27 echte HTTP-Pruefungen gegen das vollstaendige gepackte private Programm.
Letztere laufen auf Loopback mit kuenstlichen Benutzern/Artikeln und simuliertem
serverseitigem HTTPS-Merkmal; sie sind kein Live-TLS-/IONOS-Nachweis.
Anmeldung/Cookie, CORS, CSRF, GET/PUT, App-Ruecklesen, Idempotenz, Wartung,
Runtimegates und Logout werden durch den echten Bootstrap geroutet.
Alle kuenstlichen Dateien und der lokale HTTP-Prozess werden danach entfernt.

Node-/Shell-Regression: 267 Tests, ESLint und Testexport bestanden. Der erste
lokale Shellversuch wurde durch die Windows-Sandbox blockiert, die Wiederholung
mit vorhandener WSL bestand ohne Codeumgehung. Kuenstlicher Produktionsbuild,
vollstaendige PHP-/Paket-CI und signierter Beta-Pruefbuild sind vor Integration
zusaetzlich erforderlich; der endgueltige Stand wird unten dokumentiert.

Verbleibend: separate Bereitstellung, Web-SAPI-/TLS-Smoke, freigegebene
Produktumstellung, App-Release und kontrollierte Aktivierung. Keine geaenderte
Produktfreigabe, keine Bestandserfassung, kein Publisher oder Cutover.
