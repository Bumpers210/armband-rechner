# AP7-Produktions- und Deploymentvertrag

Stand: 2026-08-08
Status: lokal vorbereitet; Produktionsfreigabe ausstehend

## 1. Unveränderliche Zielpfade

Der maschinenlesbare Vertrag liegt in
`website/config/production-shop-deployment.json`. Private Shop-Programme
werden nach `/home/www/carmaja-private-shop/program`, der CLI-Worker nach
`/home/www/carmaja-private-shop/worker.php` und die ausschließlich private
Laufzeitkonfiguration nach
`/home/www/carmaja-private-shop/config/runtime-config.php` bereitgestellt.
Produktdaten verbleiben getrennt unter `/home/www/carmaja-private-production`.
Website und öffentliche API verwenden ausschließlich die bestehenden Webroots
`/home/www/carmaja` und `/home/www/carmaja-production-api`. Produkt-API und
Shop-API teilen diesen API-Webroot; die Shop-Routen liegen unter
`https://api.carmaja-perlen.de/shop/v1`. Ein zweiter Shop-API-Webroot ist
unzulässig.

Der Produktionsworker wird ausschließlich so gestartet:

```text
/usr/bin/php8.4 /home/www/carmaja-private-shop/worker.php /home/www/carmaja-private-shop/config/runtime-config.php
```

Der IONOS-UnixCron verwendet `*/5 * * * *`. Laufzeitkonfiguration,
Zugangsdaten, Logs, Datenbankdumps und Backups sind niemals Deploymentinhalt.
Der aktive Produktionsvertrag enthält keine GitHub-Tokenreferenz. Publisher,
API-, Website- und sonstige automatische Deployments bleiben bis zu ihrer
jeweiligen separaten Freigabe deaktiviert und fail-closed.

Der davon getrennte private Backupdienst liegt unter
`/home/www/carmaja-private-shop/backup.php` und besitzt keine HTTP-Route. Sein
separater UnixCron läuft stündlich um Minute 17 mit maximal 40 Sekunden:

```text
17 * * * *
/usr/bin/php8.4 /home/www/carmaja-private-shop/backup.php create /home/www/carmaja-private-shop/config/runtime-config.php
```

Verschlüsselte Backups liegen unter
`/home/www/carmaja-private-shop/backups`; der Offsite-Vertrag ist
`onedrive-pull://carmaja-production/Carmaja-Perlen/Backups`. Details und
Aktivierungsreihenfolge stehen in `website/docs/production-backup-onedrive.md`.

## 2. Getrennte Freigaben

Website, öffentlicher Shop-API-Einstieg und private Programme sind getrennte
Artefakte. Jedes Artefakt wird in ein release-spezifisches Stagingverzeichnis
kopiert, gegen den freigegebenen Commit und ein SHA-256-Manifest geprüft und
erst danach atomar aktiviert. Der private API-/Worker-Release darf weder die
Laufzeitkonfiguration noch vorhandene Commerce-Daten ersetzen.

Das spätere Website-Deployment ist nur als manueller Workflow auf `main`
zulässig. Es erfordert den exakten 40-stelligen Release-Commit, die Bestätigung
`DEPLOY-CARMAJA-PRODUCTION`, das geschützte Environment
`carmaja-production` und die temporäre Repositoryvariable
`CARMAJA_PRODUCTION_DEPLOY_ENABLED=true`. Nach jedem Versuch wird die Variable
wieder auf `false` gesetzt. Ein Push auf `main` startet kein Deployment.
Der Produktionsworkflow akzeptiert ausschließlich den als Secret hinterlegten,
vorab außerhalb des Workflows geprüften IONOS-Hostkey. Dynamisches Vertrauen
per `ssh-keyscan`, Passwort-Fallback und deaktivierte Hostprüfung sind
ausgeschlossen.

## 3. Reihenfolge und Stop-Grenzen

1. Produktionssecrets und Zielidentitäten nur auf Vorhandensein prüfen.
2. Verschlüsseltes Produkt- und MySQL-Backup erzeugen, per geprüftem
   OneDrive-Pull offsite speichern und Restoretest nachweisen.
3. Schema-Migrationsmanifest und Dateihashes prüfen.
4. Private API-/Worker-Artefakte im Staging prüfen; noch nicht aktivieren.
5. Website-Artefakt im Staging prüfen; noch nicht aktivieren.
6. Legacy-Schreibsperre und Entfernung paralleler Verkaufsangebote belegen.
7. Genau ein v2-Produkt im Cutovermanifest auswählen und Dry-Run abnehmen.
8. Erst nach separater AP7-Freigabe Schema, Cutover, private API, Worker/Cron
   und Website in der Produktionscheckliste aktivieren.

Bei fehlender Hashgleichheit, abweichender Zielidentität, unerwarteten
Commerce-Daten, fehlendem Backup/Restore-Nachweis oder inkonsistenter
Versand-, Legal- oder Zahlungsartenkonfiguration wird gestoppt. Es findet kein
automatisches Zurückschreiben in `stock` statt.

## 4. Rollback und Notfallmodus

Website-Rollback verändert keine Commerce-Daten. Code-Rollback ist nur auf
eine Version erlaubt, die das aktuelle Commerce-Schema lesen kann.
Bestands-Cutover-Rollback ist ausschließlich vor dem ersten Commerce-Checkout
zulässig. Danach werden neue Checkouts deaktiviert, während Webhooks,
Bestellungen, Worker und Stripe-Abgleich weiterlaufen.
