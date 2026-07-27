# Carmaja-Perlen Test-API auf IONOS

Diese Anleitung gilt ausschließlich für die Test-API aus Phase 3.1.
Sie installiert keine Testwebsite, führt keinen GitHub-Commit aus und
verändert weder `main` noch `www.carmaja-perlen.de`.

## Verbindliche IONOS-Pfade

- Test-API-Webroot: `/home/www/carmaja-test-api`
- Testwebsite-Webroot: `/home/www/carmaja-test-site`
- Privater Testbereich: `/home/www/carmaja-private-test`
- Private Programme: `/home/www/carmaja-private-test/program`
- Private Konfiguration: `/home/www/carmaja-private-test/config/runtime-config.php`

Produktionspfade bleiben im Testmodus ungesetzt. Der Testmodus akzeptiert
das nur bei `publishTarget=test` und `productionPublishEnabled=false`.

## Quelldateien und Ziele

| Repository-Datei | IONOS-Zieldatei | Rechte |
| --- | --- | --- |
| `website/test-api-public/index.php` | `/home/www/carmaja-test-api/index.php` | `0644` |
| `website/test-api-public/.htaccess` | `/home/www/carmaja-test-api/.htaccess` | `0644` |
| `website/test-api-private/program/bootstrap.php` | `/home/www/carmaja-private-test/program/bootstrap.php` | `0640` |
| `website/test-api-private/program/product-api.php` | `/home/www/carmaja-private-test/program/product-api.php` | `0640` |
| `website/test-api-private/program/product-admin.php` | `/home/www/carmaja-private-test/program/product-admin.php` | `0640` |
| `website/test-api-private/program/product-api-diagnostics.php` | `/home/www/carmaja-private-test/program/product-api-diagnostics.php` | `0640` |

`website/test-api-private/config/runtime-config.example.php` ist nur eine
Vorlage. Sie wird nicht als aktive Konfiguration hochgeladen. Die echte
`runtime-config.php` wird direkt über SSH angelegt und niemals committed.

Tests, README-Dateien, GitHub-Token und Dateien aus `website/hosting/` gehören
nicht zur Test-API-Installation.

## Include-Beziehungen

1. Der öffentliche `index.php` liest den nicht geheimen
   `CARMAJA_BOOTSTRAP_FILE` aus der öffentlichen `.htaccess`.
2. Fehlt die Übergabe durch `SetEnv`, verwendet der Einstiegspunkt den
   fest geprüften Pfad `/home/www/carmaja-private-test/program/bootstrap.php`.
3. `bootstrap.php` lädt ausschließlich die private
   `/home/www/carmaja-private-test/config/runtime-config.php`.
4. Der Bootstrap validiert und aktiviert die Konfiguration, lädt
   `product-api.php` aus demselben privaten Programmverzeichnis und startet
   den Router.
5. Admin-CLI und Diagnose laden denselben Bootstrap über
   `CARMAJA_CONFIG_FILE`.

Fehler vor dem Router ergeben nur eine generische JSON-Antwort mit HTTP 503.
Absolute Pfade, Stacktraces und Geheimnisse werden nicht ausgegeben.

## PHP-CLI bestimmen

Auf IONOS darf nicht der Befehl `php` verwendet werden. Nach dem SSH-Login:

```bash
if [ -x /usr/bin/php8.4-cli ]; then
  export CARMAJA_PHP_CLI=/usr/bin/php8.4-cli
elif [ -x /usr/bin/php8.4 ]; then
  export CARMAJA_PHP_CLI=/usr/bin/php8.4
else
  echo 'Kein unterstütztes PHP-8.4-CLI gefunden.' >&2
  exit 1
fi

"$CARMAJA_PHP_CLI" -v
"$CARMAJA_PHP_CLI" -m | grep -E '^(exif|gd|json|mbstring)$'
```

Die Modulliste muss `exif`, `gd`, `json` und `mbstring` enthalten.

## Verzeichnisse anlegen

```bash
mkdir -p \
  /home/www/carmaja-test-api \
  /home/www/carmaja-private-test/program \
  /home/www/carmaja-private-test/config \
  /home/www/carmaja-private-test/auth \
  /home/www/carmaja-private-test/products/operations \
  /home/www/carmaja-private-test/drafts \
  /home/www/carmaja-private-test/uploads \
  /home/www/carmaja-private-test/uploads-temp \
  /home/www/carmaja-private-test/audit \
  /home/www/carmaja-private-test/idempotency \
  /home/www/carmaja-private-test/backups \
  /home/www/carmaja-private-test/sku-counter \
  /home/www/carmaja-private-test/locks

chmod 0755 /home/www/carmaja-test-api
chmod 0750 \
  /home/www/carmaja-private-test \
  /home/www/carmaja-private-test/program \
  /home/www/carmaja-private-test/config \
  /home/www/carmaja-private-test/auth \
  /home/www/carmaja-private-test/products \
  /home/www/carmaja-private-test/products/operations \
  /home/www/carmaja-private-test/drafts \
  /home/www/carmaja-private-test/uploads \
  /home/www/carmaja-private-test/uploads-temp \
  /home/www/carmaja-private-test/audit \
  /home/www/carmaja-private-test/idempotency \
  /home/www/carmaja-private-test/backups \
  /home/www/carmaja-private-test/sku-counter \
  /home/www/carmaja-private-test/locks
```

## Umgebungsmarkierung

```bash
umask 027
printf '%s\n' '{"environment":"test"}' \
  > /home/www/carmaja-private-test/environment.json
chmod 0640 /home/www/carmaja-private-test/environment.json
```

## Laufzeitkonfiguration

Nach dem Upload der Programmdateien wird der Pepper direkt auf IONOS erzeugt.
Der Wert wird weder angezeigt noch in der Shell-Historie gespeichert:

```bash
set +x
TOKEN_PEPPER="$("$CARMAJA_PHP_CLI" -r 'echo bin2hex(random_bytes(32));')"
umask 027

cat > /home/www/carmaja-private-test/config/runtime-config.php <<EOF
<?php

declare(strict_types=1);

return [
    'environment' => 'test',
    'publishTarget' => 'test',
    'productionPublishEnabled' => false,
    'privateDir' => '/home/www/carmaja-private-test',
    'testPrivateDir' => '/home/www/carmaja-private-test',
    'testApiWebroot' => '/home/www/carmaja-test-api',
    'testWebsiteWebroot' => '/home/www/carmaja-test-site',
    'productionPrivateDir' => null,
    'productionApiWebroot' => null,
    'productionWebsiteWebroot' => null,
    'usersFile' => '/home/www/carmaja-private-test/auth/api-users.json',
    'tokenPepper' => '${TOKEN_PEPPER}',
];
EOF

unset TOKEN_PEPPER
chmod 0640 /home/www/carmaja-private-test/config/runtime-config.php
```

In Phase 3.1 werden keine `CARMAJA_GITHUB_*`-Variablen und kein GitHub-Token
konfiguriert. `CARMAJA_PRODUCTION_DEPLOY_ENABLED` bleibt ungesetzt.

## Rechte nach dem Upload

```bash
chmod 0644 \
  /home/www/carmaja-test-api/index.php \
  /home/www/carmaja-test-api/.htaccess

chmod 0640 \
  /home/www/carmaja-private-test/program/bootstrap.php \
  /home/www/carmaja-private-test/program/product-api.php \
  /home/www/carmaja-private-test/program/product-admin.php \
  /home/www/carmaja-private-test/program/product-api-diagnostics.php
```

## Syntax und Diagnose

```bash
export CARMAJA_CONFIG_FILE=/home/www/carmaja-private-test/config/runtime-config.php

"$CARMAJA_PHP_CLI" -l /home/www/carmaja-private-test/program/bootstrap.php
"$CARMAJA_PHP_CLI" -l /home/www/carmaja-private-test/program/product-api.php
"$CARMAJA_PHP_CLI" -l /home/www/carmaja-private-test/program/product-admin.php
"$CARMAJA_PHP_CLI" -l /home/www/carmaja-private-test/program/product-api-diagnostics.php

"$CARMAJA_PHP_CLI" \
  /home/www/carmaja-private-test/program/product-api-diagnostics.php
```

Erwartete Diagnose:

```json
{
  "ok": true,
  "publishTarget": "test",
  "checks": {
    "privatePath": "ok",
    "environmentMarker": "ok",
    "directoryPermissions": "ok",
    "phpExtensions": "ok",
    "atomicRename": "ok",
    "flock": "ok",
    "webrootSeparation": "ok",
    "environmentPathSeparation": "ok",
    "privateConfigurationExposure": "ok"
  }
}
```

Jeder Diagnosefehler stoppt die Installation. Die Ausgabe darf keine
absoluten Pfade und keine Konfigurationswerte enthalten.

## Ersten Benutzer anlegen

Erst nach erfolgreicher Diagnose:

```bash
export CARMAJA_CONFIG_FILE=/home/www/carmaja-private-test/config/runtime-config.php
export CARMAJA_ADMIN_SCRIPT=/home/www/carmaja-private-test/program/product-admin.php

"$CARMAJA_PHP_CLI" "$CARMAJA_ADMIN_SCRIPT" user:create
```

Benutzername und Passwort werden interaktiv abgefragt. Das Passwort muss
mindestens 14 Zeichen lang sein und wird nicht angezeigt. Die Datei
`/home/www/carmaja-private-test/auth/api-users.json` wird mit `0640` erzeugt.

## HTTP-Prüfung unter Windows

Die Basisadresse ist `https://test-api.carmaja-perlen.de/`.

```powershell
$Api = 'https://test-api.carmaja-perlen.de'

try {
    Invoke-WebRequest -Uri "$Api/products" -Method Get -ErrorAction Stop
} catch {
    $_.Exception.Response.StatusCode.value__
    $_.ErrorDetails.Message
}
```

Ohne Token werden HTTP 401 und eine JSON-Antwort mit `ok=false` erwartet.

Für einen Login ohne Passwort in der PowerShell-Historie:

```powershell
$Username = Read-Host 'Benutzername'
$SecurePassword = Read-Host 'Passwort' -AsSecureString
$Credential = [pscredential]::new($Username, $SecurePassword)

try {
    $Body = @{
        username = $Username
        password = $Credential.GetNetworkCredential().Password
        deviceName = 'Windows API-Prüfung'
        publishTarget = 'test'
    } | ConvertTo-Json

    $Login = Invoke-RestMethod `
        -Uri "$Api/login" `
        -Method Post `
        -ContentType 'application/json' `
        -Body $Body
} finally {
    Remove-Variable Body, Credential, SecurePassword -ErrorAction SilentlyContinue
}
```

Ein erfolgreicher Login liefert `ok=true`, `data.publishTarget=test` und ein
einmalig ausgegebenes Gerätetoken. Das Token nicht auf dem Bildschirm
ausgeben und nach der Prüfung aus der PowerShell-Variable entfernen.

## Sicherheitsgrenzen

- Im öffentlichen Test-API-Webroot liegen nur `index.php` und `.htaccess`.
- Keine private PHP-Datei, Konfiguration oder Datendatei liegt im Webroot.
- Verzeichnisauflistung ist durch `Options -Indexes` deaktiviert.
- Der öffentliche Einstiegspunkt enthält keine Secrets.
- Produktion bleibt deaktiviert und unkonfiguriert.
- Der lokale Publish-Adapter führt weder GitHub-Commits noch Deployments aus.
- `/home/www/carmaja-test-site` bleibt in Phase 3.1 unverändert.
