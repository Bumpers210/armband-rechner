# Produktions-API (privater Bereich)

Dieser Verzeichnisbaum ist ausschliesslich fuer den privaten, von PHP lesbaren
Produktionsdatenbereich vorgesehen. Er darf nicht im API-Webroot liegen.

## Nicht versionierte Laufzeitdaten

Die private `config/runtime-config.php` wird aus der Vorlage erzeugt und bleibt
unversioniert. Sie verweist auf einen eigenen Produktionsbereich mit diesen
Unterverzeichnissen:

- `auth/`: Benutzer, Geraete-Token-Hashes und Loginbegrenzung
- `drafts/`: Entwuerfe und interne Kalkulationsdaten
- `uploads/` und `uploads-temp/`: validierte Bilder und temporaere Uploads
- `products/`: idempotente Publish- und lokale Produktadapterdaten
- `sku-counter/`, `idempotency/` und `audit/`: Zaehler, Operationen und Auditlog
- `backups/`: rotierende Sicherungen der fachlichen privaten Daten

`environment.json` muss den Wert `production` enthalten. Der API-Webroot,
der Website-Webroot und der private Datenbereich muessen drei getrennte,
nicht verschachtelte absolute Pfade sein.

## Wartung

Die Wartungs-CLI wird nur mit PHP 8.4 und einer privaten Konfiguration
aufgerufen. Sie nimmt keine Passwoerter als Argumente entgegen.

```sh
CARMAJA_CONFIG_FILE=/absoluter/privater/pfad/config/runtime-config.php \
  /usr/bin/php8.4 product-maintenance.php backup

CARMAJA_CONFIG_FILE=/absoluter/privater/pfad/config/runtime-config.php \
  /usr/bin/php8.4 product-maintenance.php restore --backup <Backup-ID> --dry-run

CARMAJA_CONFIG_FILE=/absoluter/privater/pfad/config/runtime-config.php \
  /usr/bin/php8.4 product-maintenance.php restore --backup <Backup-ID>
```

Eine echte Wiederherstellung verlangt die interaktive Eingabe
`WIEDERHERSTELLEN`. Der Restore kopiert zuerst in einen privaten Stagingbereich
und tauscht die gesicherten Datenverzeichnisse unter Lock mit Rueckrollzustand
aus. Konfiguration, Pepper und GitHub-Token-Datei sind nicht Teil eines Backups.

## Publisher

Die Vorlage setzt `productionPublishEnabled` und `githubAdapterEnabled` auf
`false`. Erst eine private Runtime-Konfiguration mit beiden explizit auf `true`
und einem Token im privaten Datenbereich kann den Adapter aktivieren. Das Ziel
ist fest auf Repository `Bumpers210/armband-rechner` und Branch `main` begrenzt.
