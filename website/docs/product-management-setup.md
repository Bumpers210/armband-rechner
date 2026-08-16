# Carmaja-Perlen Test-API auf IONOS

Diese Anleitung gilt ausschließlich für die getrennte Testumgebung. Die
Phase-5-Dateien richten lokal den geschützten Build-, Publish- und
Deploymentweg ein, aktivieren ihn aber nicht. Es werden keine Secrets
eingerichtet, keine Dateien hochgeladen und weder `main` noch
`www.carmaja-perlen.de` verändert.

## Verbindliche IONOS-Pfade

- Test-API-Webroot: `/home/www/carmaja-test-api`
- Testwebsite-Webroot: `/home/www/carmaja-test-site`
- Testwebsite-Deployworkspace: `/home/www/carmaja-test-deploy`
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
| `website/test-api-private/program/product-api-v2.php` | `/home/www/carmaja-private-test/program/product-api-v2.php` | `0640` |
| `website/test-api-private/program/product-api-v3.php` | `/home/www/carmaja-private-test/program/product-api-v3.php` | `0640` |
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
   `product-api.php`, `product-api-v2.php` und `product-api-v3.php` aus
   demselben privaten Programmverzeichnis und startet den Router.
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

Die bestehende IONOS-Testinstallation bleibt nach der lokalen
Phase-5-Implementierung beim lokalen Publish-Adapter. Die aktive
`runtime-config.php` bleibt zunächst bei:

```php
'githubAdapterEnabled' => false,
'githubRepository' => 'Bumpers210/armband-rechner',
'githubBranch' => 'test/product-management-beta',
'githubTokenFile' => null,
```

`CARMAJA_PRODUCTION_DEPLOY_ENABLED` ist nicht `true`. Ein später auf IONOS
anzulegender Fine-grained Token darf nur auf
`Bumpers210/armband-rechner` zugreifen und ausschließlich `Contents: write`
sowie `Actions: read` besitzen. Er liegt als einzelne private Datei
außerhalb aller Webroots, beispielsweise unter
`/home/www/carmaja-private-test/config/github-token`, mit Modus `0640`.
Der Tokenwert wird weder in die Runtime-PHP-Datei noch in die Shell-Historie
geschrieben. Die interaktive Installation erfolgt ausschließlich mit
`install-test-github-token.sh`: Die Eingabe wird pro Zeichen mit `*` maskiert,
Backspace wird verarbeitet und nach einer Bestätigung wird der Kandidat nur
über `stdin` an die Nur-Lese-Diagnose gegeben. Erst eine erfolgreiche Diagnose
erzeugt die Token-Datei atomar. HTTP 401 verwirft den Kandidaten; nach
spätestens drei Versuchen endet das Skript.

Vor einer späteren Aktivierung werden die drei privaten Programme
`bootstrap.php`, `product-api.php` und `product-api-diagnostics.php` sowie das
private Installationsskript `install-test-github-token.sh` aktualisiert.
Danach werden in der privaten
Runtime-Konfiguration `githubTokenFile` auf die geprüfte Token-Datei gesetzt
und erst nach erfolgreicher Nur-Lese-Diagnose
`githubAdapterEnabled=true` gesetzt. Öffentliche API-Dateien, Benutzer- und
Gerätedaten, Entwürfe, Uploads, Auditlogs und SKU-Zähler bleiben unverändert.

Der statische Phase-4-Testkatalog wird lokal mit `npm run build:test` nach
`website/out-test/` gebaut. In Phase 4 wird dieser Ordner nicht zu IONOS
übertragen. Die später zu verwendende Test-`.htaccess` liegt unter
`website/hosting-test/.htaccess`; eine `.htpasswd` ist ausdrücklich nicht Teil
des Repositories oder Exports.

## Rechte nach dem Upload

```bash
chmod 0644 \
  /home/www/carmaja-test-api/index.php \
  /home/www/carmaja-test-api/.htaccess

chmod 0640 \
  /home/www/carmaja-private-test/program/bootstrap.php \
  /home/www/carmaja-private-test/program/product-api.php \
  /home/www/carmaja-private-test/program/product-api-v2.php \
  /home/www/carmaja-private-test/program/product-api-v3.php \
  /home/www/carmaja-private-test/program/product-admin.php \
  /home/www/carmaja-private-test/program/product-api-diagnostics.php

chmod 0750 \
  /home/www/carmaja-private-test/program/install-test-github-token.sh
```

## Syntax und Diagnose

```bash
export CARMAJA_CONFIG_FILE=/home/www/carmaja-private-test/config/runtime-config.php

"$CARMAJA_PHP_CLI" -l /home/www/carmaja-private-test/program/bootstrap.php
"$CARMAJA_PHP_CLI" -l /home/www/carmaja-private-test/program/product-api.php
"$CARMAJA_PHP_CLI" -l /home/www/carmaja-private-test/program/product-api-v2.php
"$CARMAJA_PHP_CLI" -l /home/www/carmaja-private-test/program/product-api-v3.php
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

## Produktmodell 3 in der Testumgebung

Die Beta-App 1.2.0-beta.1 (Code 6) verwendet ausschließlich die
Produktwege unter `/v3`. Formatierte Beschreibungen werden dort als
strukturierte Absätze und Textbereiche gespeichert; die API erzeugt die
zusätzliche reine Beschreibung selbst. Modell-2-Produkte bleiben über die
lesenden `/v2`- und `/v3`-Wege verfügbar und werden erst beim Speichern mit
der neuen App auf Modell 3 angehoben.

Nach Installation von `product-api-v3.php` sind schreibende Modell-1- und
Modell-2-Aufrufe gesperrt. Sie erhalten HTTP 426, damit eine ältere App eine
bereits formatierte Beschreibung nicht überschreiben kann. Anmeldung und
lesende Abfragen bleiben möglich. Die öffentliche Produktdatei besitzt ab
der ersten Modell-3-Veröffentlichung Wurzelversion 3 und darf Modell-2- und
Modell-3-Produkte gemeinsam enthalten.

Die sichere Token-Eingabe und erste GitHub-Prüfung erfolgen bei weiterhin
deaktiviertem Adapter:

```bash
/home/www/carmaja-private-test/program/install-test-github-token.sh
```

Das Skript fordert den Token interaktiv und maskiert an, nennt danach nur die
erkannte Zeichenanzahl und verlangt eine Bestätigung mit `[y/N]`. Es übergibt
den Kandidaten weder als Argument noch über eine Environment-Variable. Bei
HTTP 401 wird keine Token-Datei geschrieben und die Eingabe erneut angefordert.

Nach erfolgreicher Installation wird `githubTokenFile` in der privaten
`runtime-config.php` auf
`/home/www/carmaja-private-test/config/github-token` gesetzt.
`githubAdapterEnabled` bleibt dabei `false`. Anschließend kann dieselbe
Nur-Lese-Prüfung dateibasiert wiederholt werden:

```bash
"$CARMAJA_PHP_CLI" \
  /home/www/carmaja-private-test/program/product-api-diagnostics.php \
  --github-readonly
```

Sie liest ausschließlich den festen Branch und
`website/content/products.json`. Erst wenn Repository, Branch, Remote-HEAD
und Produktdatei bestätigt sind, darf der Adapter in einem gesondert
freigegebenen IONOS-Schritt aktiviert werden.

Lokale Syntax- und Regressionstests:

```bash
bash -n website/scripts/install-test-github-token.sh
bash website/tests/shell/install-test-github-token-test.sh \
  website/scripts/install-test-github-token.sh
```

## GitHub-Environment für das Testdeployment

Der Workflow verwendet für Geheimwerte ausschließlich das GitHub-Environment
`carmaja-test`. Solange die folgende nicht geheime Repository-Variable fehlt
oder nicht exakt `true` ist, wird nur gebaut und ein geprüftes Artefakt
erzeugt:

```text
CARMAJA_TEST_DEPLOY_ENABLED
```

Für eine spätere Freigabe benötigt das Environment diese Secrets:

```text
CARMAJA_TEST_SSH_HOST
CARMAJA_TEST_SSH_USER
CARMAJA_TEST_SSH_PORT
CARMAJA_TEST_SSH_PRIVATE_KEY
CARMAJA_TEST_SSH_KNOWN_HOSTS
CARMAJA_TEST_BASIC_AUTH_USER
CARMAJA_TEST_BASIC_AUTH_PASSWORD
```

Die beiden Basic-Auth-Secrets werden ausschließlich für Smoke-Tests
verwendet. Der Workflow schreibt weder sie noch eine `.htpasswd` auf den
Server. Der SSH-Host-Key wird fest in `CARMAJA_TEST_SSH_KNOWN_HOSTS`
hinterlegt; ein ungeprüftes `ssh-keyscan` findet nicht statt.

## Privates Deploymentworkspace

Vor der ersten späteren Aktivierung sind per SSH die tatsächlichen Pfade,
Werkzeuge und Schreibrechte zu prüfen. Der Workflow prüft zunächst
`awk`, `cmp`, `cp`, `date`, `dirname`, `find`, `grep`, `head`, `ls`,
`mkdir`, `mv`, `realpath`, `rm`, `rmdir`, `sed`, `sha256sum`, `sort`,
`tar`, `tr`, `uniq` und `wc`, ohne zu schreiben. Erst danach darf er diese
Struktur anlegen:

```bash
mkdir -p \
  /home/www/carmaja-test-deploy/incoming \
  /home/www/carmaja-test-deploy/releases \
  /home/www/carmaja-test-deploy/backups \
  /home/www/carmaja-test-deploy/state \
  /home/www/carmaja-test-deploy/locks

chmod 0750 \
  /home/www/carmaja-test-deploy \
  /home/www/carmaja-test-deploy/incoming \
  /home/www/carmaja-test-deploy/releases \
  /home/www/carmaja-test-deploy/backups \
  /home/www/carmaja-test-deploy/state \
  /home/www/carmaja-test-deploy/locks
```

Das erste Deployment akzeptiert nur einen leeren, bisher unverwalteten
`/home/www/carmaja-test-site`. Spätere Releases sichern den vorherigen
manifestverwalteten Dateisatz unter `backups/`, schreiben jede neue Datei
über temporäre Datei plus atomarem `mv` und entfernen nur Pfade des vorherigen
Manifests. Vier Releases und drei Backups werden aufbewahrt. Ein fehlgeschlagener
Deploy- oder Smoke-Test stellt den vorherigen Dateisatz wieder her.

Das Deploymentskript wird über den bereits authentifizierten SSH-Kanal
eingelesen und nicht dauerhaft auf IONOS installiert. Hochgeladen werden
ausschließlich Archiv, Manifest und Prüfsumme des geprüften
`website/out-test/` nach `incoming/`. Weder `website/hosting/**` noch private
API-, Statistik- oder Passwortdateien gehören zum Paket.

## Phase 5.3: Basic Auth auf IONOS sicher diagnostizieren

Ein HTTP-500-Fehler bei der Prüfung konkreter Zugangsdaten ist noch keine
bestätigte Ursache. Vor einer Änderung an Pfad, Rechten, Passwortdatei oder
`.htaccess` wird deshalb ausschließlich das manuelle Diagnoseskript verwendet:

```bash
bash ./diagnose-test-basic-auth.sh --diagnose
```

Das Skript wird separat und manuell per SSH/SFTP bereitgestellt. Es gehört
nicht zum Websiteartefakt und wird nicht durch GitHub Actions ausgeführt.
Es benötigt Bash; die Syntaxprüfung erfolgt deshalb ausschließlich mit:

```bash
bash -n website/scripts/diagnose-test-basic-auth.sh
```

`--diagnose` verändert keine Datei und gibt weder Benutzernamen noch Hashes
oder vollständige Logzeilen aus. Es prüft:

- Existenz, Symlinkstatus und kanonischen Pfad der festen Passwortdatei,
- numerische Besitzer-, Gruppen- und Rechteinformationen,
- Such- und Leserechte des aktuellen SSH-Benutzers für alle Elternpfade,
- Struktur und Anzahl der `htpasswd`-Einträge, ohne deren Inhalt auszugeben,
- Verfügbarkeit von `htpasswd` und `log-cat`,
- ausschließlich relevante Apache-Meldungen.

IONOS dokumentiert `log-cat` als SSH-Werkzeug für die Webspace-Logs:
<https://www.ionos.de/hilfe/hosting/log-dateien/webspace-logdateien-herunterladen/>.
Das Diagnoseskript gibt keine Logzeile aus, sondern nur Anzahl,
IP-Schwärzungszähler und genau eine Klassifikation:

```text
auth_file_not_found
auth_file_permission_denied
auth_file_invalid_format
auth_module_error
htaccess_syntax_error
unknown
```

### Passwort interaktiv prüfen

Erst nach der reinen Diagnose wird ein vorhandener Eintrag geprüft:

```bash
bash ./diagnose-test-basic-auth.sh --verify
```

Das Skript fragt den Benutzernamen interaktiv ab. Anschließend führt es
ausschließlich diese Passwortprüfung aus:

```bash
htpasswd -v "$AUTH_FILE" "$AUTH_USER"
```

`htpasswd` fragt das Passwort selbst verdeckt ab. Das Skript speichert es
weder in einem Argument noch in einer Umgebungsvariablen, Pipe oder Datei.
Ausgaben von `htpasswd` werden um Benutzername und Hash bereinigt.
`AUTH_USER` wird unmittelbar danach mit `unset AUTH_USER` entfernt.

### Passwort optional interaktiv erneuern

Eine Änderung ist vom Diagnosemodus getrennt:

```bash
bash ./diagnose-test-basic-auth.sh --reset
```

Vor der Änderung erscheint exakt die Sicherheitsabfrage
`Passwort für bestehenden Benutzer interaktiv neu setzen? [y/N]`. Nur `y`
oder `Y` führt weiter. Das Skript bestätigt zunächst, dass der eingegebene
Benutzer bereits in der Datei existiert, und ruft danach ohne `-c` auf:

```bash
htpasswd -B "$AUTH_FILE" "$AUTH_USER"
```

Das neue Passwort wird zweimal direkt durch `htpasswd` abgefragt. Es gibt
keinen Batch-, stdin- oder Klartextmodus.

`-c` ist ausschließlich bei einer nachweislich fehlenden Datei zulässig.
Auch dieser erstmalige Vorgang bleibt manuell und interaktiv:

```bash
AUTH_FILE='/home/www/carmaja-test-auth/test-website.htpasswd'
if [ ! -e "$AUTH_FILE" ]; then
  read -r -p 'Basic-Auth-Benutzername: ' AUTH_USER
  htpasswd -Bc "$AUTH_FILE" "$AUTH_USER"
  unset AUTH_USER
else
  printf '%s\n' 'Abbruch: Passwortdatei existiert bereits.'
fi
unset AUTH_FILE
```

Dieser Block darf erst verwendet werden, wenn die Diagnose
`BASIC_AUTH_FILE_EXISTS=no` bestätigt. Eine bestehende Datei darf niemals mit
`-c` neu erzeugt werden.

Der bestätigte separate Auth-Bereich `/home/www/carmaja-test-auth` besitzt
Modus `0711`; die manuell verwaltete Passwortdatei
`/home/www/carmaja-test-auth/test-website.htpasswd` besitzt Modus `0604`.
Sie liegt außerhalb von Test-Webroot und Deploymentworkspace. GitHub Actions,
Deployment und Rollback dürfen sie weder erzeugen noch lesen, kopieren,
ersetzen oder löschen. Die bisherige Datei im privaten API-Bereich bleibt bis
zur erfolgreichen Liveabnahme manuell bestehen.

### Technische Korrektur erst nach Klassifikation

Noch keine der folgenden Maßnahmen wird durch Phase 5.3 automatisch
ausgeführt:

- `auth_file_not_found`: Nur den festen Testpfad prüfen. Eine spätere
  Pfadänderung benötigt `realpath`- und Apache-Bestätigung.
- `auth_file_permission_denied`: Nur die konkret fehlende Traversier- oder
  Leseberechtigung bestimmen. Keine rekursiven Änderungen und keine Öffnung
  des privaten API-Verzeichnisses.
- `auth_file_invalid_format`: Den vorhandenen Eintrag ausschließlich über
  `--reset` und damit interaktiv mit bcrypt erneuern.
- `auth_module_error` oder `htaccess_syntax_error`: Zuerst die zugehörige
  AH-Fehlernummer und die auf IONOS verfügbaren Apache-Module prüfen.
- `unknown`: Keine Konfigurationsänderung vornehmen; die gefilterte
  Diagnoseausgabe zur weiteren Analyse verwenden.

Eine Passwortdatei im Test-Webroot wird nicht verwendet. Die bestätigte,
Apache-lesbare Ablage bleibt der separate Auth-Bereich außerhalb von Webroot
und Deploymentworkspace. Direkter HTTP-Zugriff, Aufnahme in Export oder
Manifest sowie jede Verwaltung durch GitHub Actions bleiben ausgeschlossen.

Verboten bleiben insbesondere `chmod 777`, `chmod -R`, `chown -R`, das
Kopieren der Passwortdatei in den Webroot und jede Ausgabe ihres Inhalts.

### Manuelle Curl-Abnahme

Nach der bestätigten Serverkorrektur, aber vor einem neuen Workflowlauf:

```bash
curl --disable -I https://test.carmaja-perlen.de/
```

Ohne Anmeldung wird HTTP 401 mit einer Basic-Challenge erwartet.

Für einen absichtlich falschen und danach für den korrekten Passwortversuch
wird derselbe interaktive Ablauf verwendet:

```bash
read -r -p 'Testbenutzer: ' AUTH_USER
curl --disable -I -u "$AUTH_USER" https://test.carmaja-perlen.de/
unset AUTH_USER
```

`curl` fragt das Passwort interaktiv ab. Das falsche Passwort muss HTTP 401,
das korrekte Passwort HTTP 200 liefern. HTTP 500 ist in beiden Fällen nicht
zulässig. Benutzername und Passwort niemals gemeinsam hinter `-u` angeben.

Ein weiterer Deploymentlauf ist erst erlaubt, wenn Diagnoseklassifikation,
gegebenenfalls minimale manuelle Korrektur und alle drei Curl-Ergebnisse
vorliegen. `CARMAJA_TEST_DEPLOY_ENABLED` bleibt bis unmittelbar vor diesem
gesondert freigegebenen Lauf `false`.

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
