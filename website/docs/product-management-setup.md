# Produktverwaltung: Testumgebung, Admin-CLI und Restore

Zugangsdaten, Token, Hashes, Auditdaten und Backups dürfen weder in Git noch
in APKs oder in den öffentlichen Webroot gelangen. Das Admin-CLI dieser
Beta-Version arbeitet ausschließlich mit der Zielumgebung `test`.

## 1. Tatsächliche IONOS-Pfade prüfen

Vor der ersten Serverinstallation müssen im tatsächlichen IONOS-Konto per SSH
zwei getrennte, absolute Pfade ermittelt werden:

- öffentlicher Webroot der Test-API, vorgesehen für
  `https://test-api.carmaja-perlen.de/`
- privater, von PHP les- und schreibbarer Datenpfad außerhalb dieses Webroots

Die konkrete Verzeichnisstruktur darf nicht aus lokalen Pfaden oder aus einer
IONOS-Standardstruktur abgeleitet werden. In der SSH-Sitzung sind mindestens
folgende Punkte zu prüfen:

```bash
pwd
php -r 'echo PHP_VERSION, PHP_EOL;'
php -r 'echo getcwd(), PHP_EOL;'
```

Anschließend müssen der konfigurierte Test-Webroot im IONOS-Konto und der
private Pfad anhand ihrer absoluten Pfade verglichen werden. Der private Pfad
darf weder der Webroot selbst noch ein Unterordner des Webroots sein. PHP muss
dort Verzeichnisse anlegen sowie Dateien lesen, schreiben und atomar umbenennen
können.

Wenn die Subdomain, der private Pfad oder die Schreibrechte nicht eindeutig
bestätigt werden können, wird die Einrichtung an dieser Stelle beendet. Es
darf dann weder eine Benutzerdatei angelegt noch die Test-API aktiviert werden.

## 2. Private Teststruktur

Die folgenden Befehle werden erst ausgeführt, nachdem
`CARMAJA_PRIVATE_DIR` auf den tatsächlich geprüften privaten Pfad gesetzt
wurde:

```bash
mkdir -p \
  "$CARMAJA_PRIVATE_DIR/auth" \
  "$CARMAJA_PRIVATE_DIR/audit" \
  "$CARMAJA_PRIVATE_DIR/locks" \
  "$CARMAJA_PRIVATE_DIR/products" \
  "$CARMAJA_PRIVATE_DIR/idempotency" \
  "$CARMAJA_PRIVATE_DIR/uploads"
chmod 0750 \
  "$CARMAJA_PRIVATE_DIR" \
  "$CARMAJA_PRIVATE_DIR/auth" \
  "$CARMAJA_PRIVATE_DIR/audit" \
  "$CARMAJA_PRIVATE_DIR/locks" \
  "$CARMAJA_PRIVATE_DIR/products" \
  "$CARMAJA_PRIVATE_DIR/idempotency" \
  "$CARMAJA_PRIVATE_DIR/uploads"
umask 027
printf '%s\n' '{"environment":"test"}' \
  > "$CARMAJA_PRIVATE_DIR/environment.json"
chmod 0640 "$CARMAJA_PRIVATE_DIR/environment.json"
```

Die Benutzerdatei wird nicht manuell vorbereitet. Der erste Aufruf von
`user:create` legt sie mit der festen Kennung `"environment": "test"` an.
Es werden keine Standardbenutzer und keine Standardpasswörter erzeugt.

Vorgesehene private Dateien:

```text
environment.json
auth/api-users.json
auth/device-tokens.json
audit/admin-actions-YYYY-MM.jsonl
locks/device-tokens.lock
products/
idempotency/
uploads/
```

Benutzer- und Gerätedateien erhalten nach Möglichkeit Modus `0640`.
Verzeichnisse erhalten Modus `0750`. Die API und die SSH-Sitzung müssen unter
einem Benutzer beziehungsweise einer Gruppe laufen, die diese Rechte
tatsächlich verwenden kann.

## 3. Testvariablen für die SSH-Sitzung

Die Platzhalter werden durch die zuvor tatsächlich ermittelten absoluten Pfade
ersetzt:

```bash
export CARMAJA_PUBLISH_TARGET='test'
export CARMAJA_PRIVATE_DIR='/ABSOLUTER/GEPRUEFTER/PRIVATER/TESTPFAD'
export CARMAJA_PUBLIC_WEBROOT='/ABSOLUTER/GEPRUEFTER/TEST-API-WEBROOT'
export CARMAJA_API_USERS_FILE="$CARMAJA_PRIVATE_DIR/auth/api-users.json"
export CARMAJA_ADMIN_SCRIPT='/ABSOLUTER/GEPRUEFTER/PRIVATER/PROGRAMMPFAD/product-admin.php'
export CARMAJA_PRODUCTION_PUBLISH_ENABLED='false'
unset CARMAJA_PRODUCTION_DEPLOY_ENABLED
```

Das CLI bricht mit Exit-Code `5` ab, wenn eine Variable fehlt, ein Pfad nicht
absolut ist, Webroot und privater Bereich nicht getrennt sind oder eine
Umgebungsmarkierung nicht exakt `test` lautet.

Für die getrennte Test-API werden zusätzlich eigene Werte benötigt:

```apache
SetEnv CARMAJA_PRIVATE_DIR "/ABSOLUTER/GEPRUEFTER/PRIVATER/TESTPFAD"
SetEnv CARMAJA_API_USERS_FILE "/ABSOLUTER/GEPRUEFTER/PRIVATER/TESTPFAD/auth/api-users.json"
SetEnv CARMAJA_TOKEN_PEPPER "EIGENER_LANGER_ZUFAELLIGER_TESTWERT"
SetEnv CARMAJA_GITHUB_REPOSITORY "Bumpers210/armband-rechner"
SetEnv CARMAJA_GITHUB_BRANCH "test/product-management-beta"
SetEnv CARMAJA_GITHUB_TOKEN_FILE "/ABSOLUTER/GEPRUEFTER/PRIVATER/TESTPFAD/github-token.txt"
SetEnv CARMAJA_PRODUCTION_PUBLISH_ENABLED "false"
```

`CARMAJA_TOKEN_PEPPER`, GitHub-Token und weitere Zugangsdaten werden nur auf
dem Server gesetzt und in keiner Dokumentation mit realen Werten abgelegt.
Der Testbereich verwendet keine produktiven Benutzer, Tokens, SKU-Zähler,
Auditlogs oder sonstigen privaten Daten.

## 4. Admin-CLI installieren

Die Quelldatei ist `website/scripts/product-admin.php`. Auf dem Server wird sie
in den geprüften privaten Programmpfad außerhalb des Webroots kopiert:

```bash
chmod 0750 "$CARMAJA_ADMIN_SCRIPT"
php -l "$CARMAJA_ADMIN_SCRIPT"
```

Ein HTTP-Aufruf wird ohne Antwortinhalt abgewiesen. Passwörter werden niemals
als Option akzeptiert. Die verdeckte Passworteingabe ist für eine interaktive
Linux-/IONOS-SSH-Sitzung implementiert und benötigt `/dev/tty` sowie `stty`.
Eine nicht interaktive Pipe oder die lokale Windows-Eingabe wird für
Passwortbefehle bewusst nicht unterstützt.

Verbindliche Exit-Codes:

- `0`: Erfolg
- `2`: falscher Aufruf, fehlende Bestätigung oder Bedienfehler
- `3`: ungültige Eingabe
- `4`: Konflikt oder Datensatz nicht gefunden
- `5`: Konfigurations-, Datei-, Audit- oder I/O-Fehler

Fehlermeldungen werden auf STDERR geschrieben.

## 5. Ersten Testbenutzer anlegen

Nach dem Export der Variablen ist dies der konkrete SSH-Befehl:

```bash
php "$CARMAJA_ADMIN_SCRIPT" user:create
```

Alternativ darf der nicht geheime Benutzername als Option angegeben werden:

```bash
php "$CARMAJA_ADMIN_SCRIPT" user:create --username 'BENUTZERNAME'
```

Benutzernamen werden außen getrimmt und in Kleinschreibung gespeichert. Sie
müssen 3 bis 64 Zeichen lang sein, mit Buchstabe oder Zahl beginnen und enden
und dürfen nur `a-z`, `0-9`, Punkt, Bindestrich und Unterstrich enthalten.
Doppelte normalisierte Benutzernamen werden abgelehnt.

Das Passwort wird verdeckt zweimal abgefragt. Es muss mindestens 14 Zeichen
lang sein, darf den vollständigen Benutzernamen nicht enthalten und darf kein
offensichtlich schwaches Muster sein. Gespeichert wird ausschließlich ein mit
`password_hash(..., PASSWORD_DEFAULT)` erzeugter Hash.

## 6. Passwort ändern

```bash
php "$CARMAJA_ADMIN_SCRIPT" user:password --username 'BENUTZERNAME'
```

Nach erfolgreicher Änderung fragt das CLI, ob alle bestehenden Geräte des
Benutzers widerrufen werden sollen. Die dokumentierte Standardantwort ist
`N`: Eine leere Eingabe oder `N` lässt vorhandene Geräte aktiv. Nur eine
ausdrückliche Antwort `j`, `ja`, `y` oder `yes` widerruft sie.

## 7. Geräte verwalten

Alle Geräte auflisten:

```bash
php "$CARMAJA_ADMIN_SCRIPT" device:list
```

Nach Benutzer filtern:

```bash
php "$CARMAJA_ADMIN_SCRIPT" device:list --username 'BENUTZERNAME'
```

Die Ausgabe enthält ausschließlich Geräte-ID, Benutzer, Erstellungszeit,
letzte Nutzung, Widerrufszeit und Status. Token, Token-Hash und sonstige
Geheimnisse werden nicht ausgegeben.

Ein einzelnes Gerät idempotent widerrufen:

```bash
php "$CARMAJA_ADMIN_SCRIPT" device:revoke --device-id 'GERAETE_ID'
```

Alle aktiven Geräte genau eines Benutzers widerrufen:

```bash
php "$CARMAJA_ADMIN_SCRIPT" device:revoke-user --username 'BENUTZERNAME'
```

Das CLI zeigt zuerst die Zahl der aktiven Geräte. Die Änderung erfolgt nur,
wenn anschließend exakt `WIDERRUFEN` eingegeben wird. Token-Datensätze werden
nicht gelöscht; stattdessen wird `revokedAt` mit Serverzeit gesetzt.

Das CLI und die API verwenden für Gerätedaten dieselbe Sperrdatei
`locks/device-tokens.lock`. Schreibvorgänge verwenden eine vollständige
temporäre JSON-Datei, erneute JSON-Prüfung, Modus `0640` und atomare
Umbenennung. Ein Fehler lässt die bisherige Datendatei unverändert.

## 8. Auditlog

Administrative Aktionen schreiben in
`audit/admin-actions-YYYY-MM.jsonl`. Jeder Eintrag enthält Zeit, Aktion,
betroffenen Benutzer, gegebenenfalls Geräte-ID und Ergebnis. Passwörter,
Hashes, Tokens und Umgebungsgeheimnisse werden nicht protokolliert.

Kann das Auditlog nicht geschrieben werden, meldet das CLI den Fehler und
beendet sich mit Exit-Code `5`.

## 9. Private Dateien sichern

Der Backup-Zielpfad muss ebenfalls privat, außerhalb des Webroots und
außerhalb von `CARMAJA_PRIVATE_DIR` liegen:

```bash
export CARMAJA_BACKUP_DIR='/ABSOLUTER/GEPRUEFTER/PRIVATER/BACKUPPFAD'
mkdir -p "$CARMAJA_BACKUP_DIR"
chmod 0700 "$CARMAJA_BACKUP_DIR"
umask 077
backup_file="$CARMAJA_BACKUP_DIR/carmaja-test-private-$(date -u +%Y%m%dT%H%M%SZ).tar.gz"
tar \
  --exclude='./uploads/*' \
  --exclude='./locks/*.lock' \
  -C "$CARMAJA_PRIVATE_DIR" \
  -czf "$backup_file" \
  .
chmod 0600 "$backup_file"
sha256sum "$backup_file" > "$backup_file.sha256"
chmod 0600 "$backup_file.sha256"
```

Das Archiv enthält dadurch insbesondere Umgebungsmarkierung, Benutzer,
Geräte, Produkt-/Kalkulationsdaten, Idempotency-Daten und Auditlogs, aber
keine unvollständigen Uploads oder aktiven Lockdateien.

Für eine einfache Rotation dürfen ausschließlich Dateien im bestätigten
Backup-Ziel gelöscht werden, zum Beispiel nach 30 Tagen:

```bash
find "$CARMAJA_BACKUP_DIR" -maxdepth 1 -type f \
  \( -name 'carmaja-test-private-*.tar.gz' -o -name 'carmaja-test-private-*.tar.gz.sha256' \) \
  -mtime +30 -delete
```

## 10. Aus einem Backup wiederherstellen

Ein Restore wird zuerst mit Testdaten und niemals direkt im öffentlichen
Webroot durchgeführt:

1. Test-API auf IONOS in Wartung setzen und prüfen, dass keine mutierenden
   Anfragen mehr möglich sind.
2. Aktuellen privaten Datenbereich nochmals wie oben sichern.
3. Prüfsumme mit `sha256sum -c DATEI.tar.gz.sha256` validieren.
4. Archiv in ein neues temporäres Verzeichnis auf demselben Dateisystem
   entpacken.
5. `environment.json` und alle JSON-Dateien prüfen; die Umgebung muss exakt
   `test` sein.
6. Rechte auf Verzeichnissen auf `0750` und auf privaten Dateien auf `0640`
   setzen.
7. Bisherigen privaten Ordner als Rückfallstand atomar umbenennen und den
   geprüften Restore-Ordner an seine Stelle verschieben.
8. Mit `device:list` die Lesbarkeit prüfen, anschließend einen Test-Login
   durchführen und das Auditlog kontrollieren.
9. Erst danach die Test-API wieder freigeben.

Beispiel für die isolierte Prüfung der Umgebungsmarkierung im Restore-Ordner:

```bash
php -r '$d=json_decode(file_get_contents($argv[1]),true,512,JSON_THROW_ON_ERROR);exit(($d["environment"]??null)==="test"?0:1);' \
  '/ABSOLUTER/PFAD/ZUM/RESTORE/environment.json'
```

Der erste reale Restore-Test bleibt bis zur bestätigten IONOS-Pfad- und
Rechteprüfung offen. Ein Restore darf weder produktive Daten noch
`www.carmaja-perlen.de` verändern.

## GitHub- und Deploymentgrenzen

Das Fine-grained GitHub-Token der Test-API darf ausschließlich auf
`Bumpers210/armband-rechner` zugreifen und nur `Contents: write` besitzen.
Workflow-, Admin-, Secret- und weitere Rechte sind verboten.

Produktänderungen der Test-API dürfen nur auf
`test/product-management-beta` und nur in den fest erlaubten öffentlichen
Produktdaten- und Produktbildpfaden erfolgen. `.github/`, Workflows,
App-Quellcode, Website-Komponenten, Rechtstexte, Hosting-Konfiguration und
alle sonstigen Pfade bleiben gesperrt.

Die Phase-2-Einrichtung löst weder ein IONOS-Deployment noch eine öffentliche
Produktveröffentlichung aus. Produktion bleibt deaktiviert:

- `CARMAJA_PRODUCTION_PUBLISH_ENABLED=false`
- `CARMAJA_PRODUCTION_DEPLOY_ENABLED` ist nicht `true`
- kein produktiver Commit
- kein produktives Website-Deployment
