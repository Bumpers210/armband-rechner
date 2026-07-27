# Produktverwaltung: IONOS-Setup und Restore

Diese Werte dürfen nicht in Git, APKs oder den öffentlichen Webroot.

## Privater IONOS-Pfad

1. Im tatsächlichen IONOS-Konto per SSH prüfen, welcher Ordner außerhalb des Webroots liegt und von PHP gelesen und geschrieben werden kann.
2. Erst nach dieser Prüfung in der produktiven Root-`.htaccess` setzen:

   ```apache
   SetEnv CARMAJA_PRIVATE_DIR "/ABSOLUTER/GEPRUEFTER/PFAD/carmaja-private"
   SetEnv CARMAJA_API_USERS_FILE "/ABSOLUTER/GEPRUEFTER/PFAD/carmaja-private/api-users.json"
   SetEnv CARMAJA_TOKEN_PEPPER "MINDESTENS_32_ZEICHEN_LANGER_ZUFAELLIGER_WERT"
   SetEnv CARMAJA_GITHUB_REPOSITORY "Bumpers210/armband-rechner"
   SetEnv CARMAJA_GITHUB_BRANCH "main"
   SetEnv CARMAJA_GITHUB_TOKEN_FILE "/ABSOLUTER/GEPRUEFTER/PFAD/carmaja-private/github-token.txt"
   ```

3. Die API verweigert den Betrieb, wenn `CARMAJA_PRIVATE_DIR` im Webroot liegt.

## Benutzer und Geräte

`api-users.json` liegt im privaten Pfad:

```json
{
  "users": [
    {
      "username": "admin",
      "passwordHash": "$2y$...",
      "active": true
    }
  ]
}
```

Den Hash auf dem Server mit PHP erzeugen:

```bash
php -r 'echo password_hash("NEUES_PASSWORT", PASSWORD_DEFAULT), PHP_EOL;'
```

Jedes Smartphone erhält nach Login ein eigenes widerrufbares Geräte-Token. Tokens werden serverseitig nur gehasht gespeichert.

## GitHub Token

Das Fine-grained Token liegt ausschließlich in `CARMAJA_GITHUB_TOKEN_FILE`.

Erlaubte Rechte:

- genau ein Repository: `Bumpers210/armband-rechner`
- `Contents: write`

Nicht erlaubte Rechte:

- Workflows
- Administration
- Secrets
- weitere Repository- oder Organisationsrechte

Die PHP-API blockiert Produktcommits außerhalb der erlaubten Produktpfade und insbesondere `.github/workflows/`.

## Deployment-Secrets

GitHub Actions benötigt diese Secrets:

- `IONOS_SSH_HOST`
- `IONOS_SSH_PORT`
- `IONOS_SSH_USER`
- `IONOS_SSH_PRIVATE_KEY`
- `IONOS_WEBROOT`

Der Workflow löscht nur build-verwaltete Pfade:

- `/armbaender/`
- `/images/products/`

Private Daten, API-Konfiguration, Statistikdaten und echte `.htpasswd` werden nicht gelöscht.

## Backups und Restore-Test

Die API kann rotierende Backups im privaten Pfad erzeugen. Gesichert werden:

- Produktentwürfe
- interne Kalkulationsdaten
- Geräte-Token-Hashes
- Idempotency-Daten
- Auditdaten

Restore-Test:

1. Backup per API oder CLI erzeugen.
2. Privaten Datenordner lokal oder in einem getrennten Testordner sichern.
3. Einen Testentwurf ändern.
4. Backupordner in den privaten Datenbereich zurückkopieren.
5. API-Produktliste prüfen.
6. Testprodukt erneut veröffentlichen oder als verkauft markieren.
7. Website-Build und Deploymentstatus prüfen.

Ein Restore darf nie direkt in den öffentlichen Webroot kopieren.

## Getrennte Test-API

Die erste Smartphone-Testversion nutzt ausschließlich die getrennte Test-API:

- Basisadresse: `https://test-api.carmaja-perlen.de/`
- empfohlenes Webspace-Verzeichnis: `/carmaja-test-api/`
- Git-Branch für Produktcommits: `test/product-management-beta`

Vor der Installation müssen diese Punkte im tatsächlichen IONOS-Konto geprüft und eingerichtet werden:

1. Die Subdomain `test-api.carmaja-perlen.de` zeigt auf das separate Webspace-Verzeichnis `/carmaja-test-api/`.
2. PHP kann von dort aus einen eigenen privaten Datenpfad außerhalb des öffentlichen Webroots lesen und schreiben.
3. Die Test-API verwendet eigene Zugangsdaten, eigene Geräte-Tokens, eigenen SKU-Zähler, eigene Idempotency-Daten, eigenes Auditlog und eigene Backups.
4. `CARMAJA_GITHUB_BRANCH` ist für die Test-API auf `test/product-management-beta` gesetzt.
5. Der Test-GitHub-Token darf nur Produktpfade im Zielrepository schreiben und besitzt keine Workflow-, Admin-, Secret- oder Deployment-Rechte.

Beispiel für die Test-`.htaccess` im Verzeichnis `/carmaja-test-api/`:

```apache
SetEnv CARMAJA_PRIVATE_DIR "/ABSOLUTER/GEPRUEFTER/PFAD/carmaja-test-private"
SetEnv CARMAJA_API_USERS_FILE "/ABSOLUTER/GEPRUEFTER/PFAD/carmaja-test-private/api-users.json"
SetEnv CARMAJA_TOKEN_PEPPER "MINDESTENS_32_ZEICHEN_LANGER_TEST_WERT"
SetEnv CARMAJA_GITHUB_REPOSITORY "Bumpers210/armband-rechner"
SetEnv CARMAJA_GITHUB_BRANCH "test/product-management-beta"
SetEnv CARMAJA_GITHUB_TOKEN_FILE "/ABSOLUTER/GEPRUEFTER/PFAD/carmaja-test-private/github-token.txt"
```

Die Test-API darf keine produktiven Produktdaten verändern und kein produktives Website-Deployment auslösen. Falls die Subdomain oder das Verzeichnis bei IONOS nicht wie oben erreichbar eingerichtet werden kann, muss die Implementierung vor Inbetriebnahme anhalten und die benötigte IONOS-Konfiguration konkret nachgezogen werden.
