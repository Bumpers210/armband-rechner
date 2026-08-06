# Produktions-Website-Statistik

Die Hostingdateien unter `website/hosting/` werden nicht vom normalen Website-Deployment verwaltet. Sie werden ausschliesslich als einzeln gepruefte Produktionswartung installiert.

## Daten und Datenschutz

`CARMAJA_STATS_FILE` zeigt auf `/home/www/carmaja/private-data/clicks.json`. Die Statistik speichert ausschliesslich aggregierte Linkklicks sowie Seitenaufrufe pro Tag und Monat. Seitenaufrufe werden nach kanonischer öffentlicher Route und beim Einstieg nach einer festen Herkunftskategorie gespeichert. IP-Adressen, Cookies, User-Agents, Referer und vollständige Herkunfts-URLs werden nicht gespeichert.

Die Version-3-Statistik liest vorhandene Version-1- und Version-2-Daten weiter. Beim naechsten Schreibvorgang wird sie atomar mit einem stabilen Lock und einem Austausch im selben privaten Verzeichnis aktualisiert.

`pageview.php` akzeptiert ausschliesslich `POST` mit einer tatsächlich veröffentlichten kanonischen Route. Die Website ordnet beim ersten Aufruf eines geladenen Dokuments nur einen der Kategorien `google`, `other-search`, `instagram`, `other-social`, `direct-unknown` oder `other-website` zu. Interne Navigationen erhalten keine neue Herkunftszuordnung.

Produktklicks sind nur erlaubt, wenn die statische Detailseite fuer den CP-Slug existiert und dort der Vinted-Link gerendert wird. Damit sind `sold`, `disabled` und unbekannte Produkte ausgeschlossen.

## Statistikbereich

Der Statistikbereich liegt unter `/statistik/` und benoetigt Apache Basic Auth. Die aktive Passwortdatei bleibt ausschliesslich unter:

`/home/www/carmaja-production-auth/statistik.htpasswd`

Der Ordner wird mit `0711`, die Passwortdatei mit `0604` angelegt, falls Apache diese Leserechte benoetigt. Benutzername und Passwort werden ausschliesslich interaktiv mit `htpasswd -B` erfasst. Die Passwortdatei wird nie vom Website-Deployment, Manifest oder Rollback verwaltet.

## Kontrollierte Installation

Vor der Installation alle betroffenen Produktionsdateien ausserhalb des Webroots sichern, Hashes und Rechte erfassen und den Rollback in einem isolierten Verzeichnis pruefen. Danach nur diese Dateien atomar austauschen:

- `.htaccess`
- `click.php`
- `pageview.php`
- `_internal/.htaccess`
- `_internal/tracking.php`
- `private-data/.htaccess`
- `statistik/.htaccess`
- `statistik/index.php`

`private-data/clicks.json` bleibt erhalten und wird nicht manuell bearbeitet. Nach der Installation ist genau eine kontrollierte Seitenaufrufzaehlung zulaessig. Sie wird als technische Testzaehlung dokumentiert.

## Pruefungen

Der PHP-Integrationstest liegt unter `website/tests/php/production-click-statistics-test.php`. Er prueft Weiterleitung, Parametergrenzen, veröffentlichte Seiten, Herkunftskategorien, Datenmigration, parallele Schreibvorgaenge und den Ausschluss personenbezogener Felder. Auf IONOS muss er mit `/usr/bin/php8.4` in einer temporären Kopie ausgefuehrt werden.
