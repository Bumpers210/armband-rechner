# Produktions-Klickstatistik

Die Hostingdateien unter `website/hosting/` werden nicht vom normalen Website-Deployment verwaltet. Sie werden ausschliesslich als einzeln gepruefte Produktionswartung installiert.

## Daten und Datenschutz

`CARMAJA_STATS_FILE` zeigt auf `/home/www/carmaja/private-data/clicks.json`. Die Statistik speichert ausschliesslich aggregierte Klickzahlen pro Tag, Monat, Ziel, Position und Produkt-Slug. IP-Adressen, Cookies, User-Agents und Referrer werden nicht gespeichert.

Die Version-2-Statistik liest vorhandene Version-1-Daten weiter. Beim naechsten Schreibvorgang wird sie atomar mit einem stabilen Lock und einem Austausch im selben privaten Verzeichnis aktualisiert.

Produktklicks sind nur erlaubt, wenn die statische Detailseite fuer den CP-Slug existiert und dort der Vinted-Link gerendert wird. Damit sind `sold`, `disabled` und unbekannte Produkte ausgeschlossen.

## Statistikbereich

Der Statistikbereich liegt unter `/statistik/` und benoetigt Apache Basic Auth. Die aktive Passwortdatei bleibt ausschliesslich unter:

`/home/www/carmaja-production-auth/statistik.htpasswd`

Der Ordner wird mit `0711`, die Passwortdatei mit `0604` angelegt, falls Apache diese Leserechte benoetigt. Benutzername und Passwort werden ausschliesslich interaktiv mit `htpasswd -B` erfasst. Die Passwortdatei wird nie vom Website-Deployment, Manifest oder Rollback verwaltet.

## Kontrollierte Installation

Vor der Installation alle betroffenen Produktionsdateien ausserhalb des Webroots sichern, Hashes und Rechte erfassen und den Rollback in einem isolierten Verzeichnis pruefen. Danach nur diese Dateien atomar austauschen:

- `.htaccess`
- `click.php`
- `_internal/.htaccess`
- `_internal/tracking.php`
- `private-data/.htaccess`
- `statistik/.htaccess`
- `statistik/index.php`

`private-data/clicks.json` bleibt erhalten und wird nicht manuell bearbeitet. Nach der Installation ist genau eine kontrollierte Produktklickzaehlung zulaessig. Sie bleibt als technische Testzaehlung bestehen, bis ein eigener versionierter Wartungsvorgang zum bereinigten Entfernen existiert.

## Pruefungen

Der PHP-Integrationstest liegt unter `website/tests/php/production-click-statistics-test.php`. Er prueft Weiterleitung, Parametergrenzen, Produktstatus, Datenmigration, parallele Schreibvorgaenge und den Ausschluss personenbezogener Felder. Auf IONOS muss er mit `/usr/bin/php8.4` in einer temporären Kopie ausgefuehrt werden.
