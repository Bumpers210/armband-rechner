# Produktions-Website-Statistik

Die Hostingdateien unter `website/hosting/` werden nicht vom normalen Website-Deployment verwaltet. Der separate Workflow `deploy-statistics-hosting.yml` installiert ausschliesslich die acht geprueften Statistikdateien nach einer manuellen Freigabe.

## Daten und Datenschutz

`CARMAJA_STATS_FILE` zeigt auf `/home/www/carmaja/private-data/clicks.json`. Die Statistik speichert ausschliesslich aggregierte Linkklicks sowie Seitenaufrufe pro Tag und Monat. Seitenaufrufe werden nach kanonischer öffentlicher Route und beim Einstieg nach einer festen Herkunftskategorie gespeichert. IP-Adressen, Cookies, User-Agents, Referer und vollständige Herkunfts-URLs werden nicht gespeichert.

Die Version-3-Statistik liest vorhandene Version-1- und Version-2-Daten weiter. Nicht mehr unterstützte Marktplatz- und Produktklickzähler werden bei der Normalisierung verworfen. Beim nächsten Schreibvorgang wird die verbleibende Statistik atomar mit einem stabilen Lock und einem Austausch im selben privaten Verzeichnis aktualisiert.

`pageview.php` akzeptiert ausschliesslich `POST` mit einer tatsächlich veröffentlichten kanonischen Route. Die Website ordnet beim ersten Aufruf eines geladenen Dokuments nur einen der Kategorien `google`, `other-search`, `instagram`, `other-social`, `direct-unknown` oder `other-website` zu. Interne Navigationen erhalten keine neue Herkunftszuordnung.

Externe Linkklicks sind ausschließlich für die fest hinterlegte Instagram-Adresse und die erlaubten Positionen vorgesehen. Produkt- oder Marktplatzparameter werden abgelehnt; der Kaufweg läuft direkt über den Carmaja-Shop.

## Statistikbereich

Der Statistikbereich liegt unter `/statistik/` und benoetigt Apache Basic Auth. Die aktive Passwortdatei bleibt ausschliesslich unter:

`/home/www/carmaja-production-auth/statistik.htpasswd`

Der Ordner wird mit `0711`, die Passwortdatei mit `0604` angelegt, falls Apache diese Leserechte benoetigt. Benutzername und Passwort werden ausschliesslich interaktiv mit `htpasswd -B` erfasst. Die Passwortdatei wird nie vom Website-Deployment, Manifest oder Rollback verwaltet.

## Kontrollierte Installation

Der separate Workflow verlangt den exakten Main-Commit, die Bestaetigung `INSTALL_PRODUCTION_STATISTICS` und die kurzzeitig gesetzte Variable `CARMAJA_PRODUCTION_STATISTICS_HOSTING_ENABLED=true`. Er setzt die Variable abschliessend unabhaengig vom Ergebnis wieder auf `false`.

Vor der Installation sichert der Installer alle betroffenen Produktionsdateien ausserhalb des Webroots, erfasst Hashes und Rechte und prueft den Rollback in einem isolierten Verzeichnis. Danach tauscht er nur diese Dateien dateiweise atomar aus:

- `.htaccess`
- `click.php`
- `pageview.php`
- `_internal/.htaccess`
- `_internal/tracking.php`
- `private-data/.htaccess`
- `statistik/.htaccess`
- `statistik/index.php`

`private-data/clicks.json` bleibt erhalten und wird nicht manuell bearbeitet. Der Smoketest speichert genau eine dokumentierte technische Seitenaufrufzaehlung fuer `/` mit `direct-unknown`; er prueft ausserdem den Basic-Auth-Schutz von `/statistik/`.

## Pruefungen

Der PHP-Integrationstest liegt unter `website/tests/php/production-click-statistics-test.php`. Er prueft Weiterleitung, Parametergrenzen, veröffentlichte Seiten, Herkunftskategorien, Datenmigration, parallele Schreibvorgaenge und den Ausschluss personenbezogener Felder. Auf IONOS muss er mit `/usr/bin/php8.4` in einer temporären Kopie ausgefuehrt werden.
