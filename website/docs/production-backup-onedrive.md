# Produktionsbackup: IONOS → verschlüsselt → OneDrive-Pull

Stand: 2026-08-09  
Status: lokal implementiert und mit künstlichen Daten auf IONOS nachgewiesen;
Produktionsaktivierung ausstehend

## Zielvertrag

Der private IONOS-Dienst erzeugt stündliche, authentifiziert verschlüsselte
Vollbackups. Der Windows-Agent lädt ausschließlich durch einen atomaren
`ready`-Marker veröffentlichte Sicherungen per SFTP nach
`D:\Carmaja-OneDrive\Carmaja-Perlen\Backups`. Skripte und Zustandsdaten liegen
nicht in OneDrive, sondern unter `%LOCALAPPDATA%\Carmaja\BackupAgent`.

* Server-RPO: 1 Stunde; Warnung nach 90 Minuten ohne fertiges Backup.
* Offsite-RPO: bis 24 Stunden; Warnung nach 24 Stunden ohne Downloadquittung.
* RTO: 4 Stunden.
* Serveraufbewahrung: 7 Tage; das jüngste Backup und das jüngste quittierte
  Backup werden niemals durch Rotation entfernt.
* OneDrive-Aufbewahrung: 48 stündliche, 30 tägliche und 12 monatliche Stände.
* Freier lokaler Speicher: mindestens 5 GiB und mindestens das Zehnfache der
  größten bekannten Sicherung.

## Private Serverdateien

```text
/home/www/carmaja-private-shop/backup.php
/home/www/carmaja-private-shop/program/production-backup.php
/home/www/carmaja-private-shop/config/runtime-config.php
/home/www/carmaja-private-shop/config/backup-key.php
/home/www/carmaja-private-shop/backups/
```

Es gibt keine HTTP-Route. `backup.php` beendet Web-SAPI-Aufrufe mit `404` und
akzeptiert ausschließlich:

```text
backup create
backup list-ready
backup acknowledge <backupId> <manifestSha256>
backup restore-dry-run <backupId>
backup status
```

Der konkrete Aufruf lautet jeweils:

```text
/usr/bin/php8.4 /home/www/carmaja-private-shop/backup.php <Aktion> /home/www/carmaja-private-shop/config/runtime-config.php
```

Auf dem IONOS-Testhost wurden `/usr/bin/mysql` und `/usr/bin/mysqldump` als
MariaDB-Client 10.11 nachgewiesen. Der Zielserver bleibt MySQL 8/InnoDB. Der
Backupdienst verwendet deshalb den von beiden Clients verstandenen
`ssl`-Vertrag sowie `--single-transaction`, `--events`, `--routines`,
`--triggers`, `--order-by-primary` und `--no-tablespaces`. Vor jedem Dump,
Vergleich oder Restore muss die jeweilige CLI-Sitzung einen nicht leeren
`Ssl_cipher` melden; andernfalls wird ohne Backup- oder Restoremutation
abgebrochen.

Runtime und Schlüsseldatei müssen reguläre, nicht verlinkte Dateien mit Modus
`0600` sein. Der Schlüssel ist ein Base64-kodierter 32-Byte-Zufallswert. Die
Runtime verweist auf die getrennte Schlüsseldatei; weder Schlüssel noch
Zugangsdaten erscheinen in Argumenten, Logs, Manifesten oder OneDrive.

## Backupformat und Umfang

Der Commerce-Dump wird mit `mysqldump --single-transaction` gelesen, in einem
PHP-Stream gzip-komprimiert und unmittelbar mit
`sodium_crypto_secretstream_xchacha20poly1305` verschlüsselt. Es gibt im
Backuppfad kein unverschlüsseltes SQL- oder tar-Archiv. Das Produktarchiv
enthält nur `environment.json`, `products`, `drafts`, `uploads`, `sku-counter`,
`auth`, `audit`, `idempotency` und die private Runtime. Programme, Locks,
temporäre Uploads, vorhandene Backups und Rollbackartefakte sind ausgeschlossen.

Jeder fertige Stand enthält:

```text
commerce.sql.gz.cmjbkp
product-data.tar.gz.cmjbkp
manifest.json
ready
```

Das HMAC-geschützte Manifest bindet Backup-ID, UTC-Zeit, Key-ID, Format- und
Schemajournal, Dateigrößen und SHA-256-Hashes. `ready` ist der letzte
veröffentlichte Marker. Ein nichtblockierender `flock` verhindert parallele
Erzeugung. Stimmen Produktinventar vor und nach dem Stream nicht überein, wird
der gesamte Stagingstand verworfen.

## Cron und Monitoring

Der getrennte IONOS-UnixCron wird erst nach Testabnahme angelegt:

```text
17 * * * *
/usr/bin/php8.4 /home/www/carmaja-private-shop/backup.php create /home/www/carmaja-private-shop/config/runtime-config.php
```

Die Erzeugung besitzt ein Laufzeitlimit von 40 Sekunden. Fehler entfernen den
gesamten Stagingstand. `backup status` liefert ausschließlich nicht vertrauliche
Zeitpunkte und die Flags `serverBackupOverdue` und
`offsiteDownloadOverdue`.

## Windows-Agent

`scripts/install-production-backup-task.ps1` kopiert den Agenten in den nur für
das aktuelle Windows-Konto zugänglichen lokalen Agentordner. Der Task läuft bei
Anmeldung und danach alle 30 Minuten, verwendet den SSH-Alias
`carmaja-production-ionos`, `BatchMode=yes` und
`StrictHostKeyChecking=yes`.

Der Pull prüft OneDrive-Prozess, den in der OneDrive-Kontokonfiguration
eingetragenen Stamm, freien Speicher, Dateigrößen und SHA-256. Der SFTP-Transfer
landet zunächst geschützt unter `D:\Carmaja-Backup-Incoming`, also außerhalb
von OneDrive, aber auf demselben Laufwerk. Der Agent verwendet den Ordner nur,
wenn sein eindeutiger Eigentumsmarker vorhanden ist. Erst nach vollständiger
Prüfung wird das Verzeichnis atomar in den OneDrive-Zielordner verschoben und
anschließend auf dem Server quittiert. Teiltransfers, Hashabweichungen, ein
falscher Stamm oder ein gestoppter OneDrive-Prozess werden niemals quittiert.

Die Verschiebung von OneDrive erfolgt ausschließlich über Microsofts Ablauf
„Verknüpfung aufheben → Speicherort ändern → neu verknüpfen“:
<https://support.microsoft.com/en-us/onedrive/change-the-location-of-your-onedrive-folder>.
`D:\Carmaja-Perlen` bleibt unverändert und unsynchronisiert.

## Schlüsselsetup

`scripts/initialize-production-backup-key.ps1` erzeugt den Schlüssel lokal mit
einem kryptografischen Zufallszahlengenerator. Der Klartext liegt nur kurz in
der Zwischenablage, muss im Passwortmanager und zusätzlich offline gesichert
werden und wird erst nach der Eingabe `GESICHERT` per SFTP in die private
Serverdatei übertragen. Lokale Übergabedatei, Bytepuffer und Zwischenablage
werden im `finally`-Pfad bereinigt. OneDrive erhält niemals den Schlüssel.

## Restore und Freigabegate

`restore-dry-run` verlangt eine aktive TLS-Sitzung und eine getrennte leere
Restore-Datenbank. Es prüft Manifest-HMAC und Archivauthentizität, entschlüsselt
nur unter einem privaten temporären Restorepfad, stellt MySQL im Restore-Ziel
wieder her und vergleicht Schemajournal, den semantischen MySQL-8-Fingerabdruck
aus Tabellenstruktur und Zeileninhalt sowie das Produktinventar. Auch nach einem
Teilfehler werden Restore-Tabellen und
temporäre Dateien entfernt.

Vor jeder Produktionsaktivierung sind in dieser Reihenfolge erforderlich:

1. OneDrive vollständig nach `D:\Carmaja-OneDrive` verschieben.
2. Schlüssel erzeugen und getrennt extern sichern.
3. private Runtime vervollständigen.
4. Backup-CLI und Backup-Cron privat bereitstellen.
5. erstes verschlüsseltes Backup erzeugen.
6. automatischen Pull sowie Sichtbarkeit in OneDrive Web prüfen.
7. vollständigen Restore-Dry-Run bestehen.
8. Erst danach AP7-Produktionsarbeiten fortsetzen.

Der Restoretest wird monatlich und zusätzlich unmittelbar vor dem Cutover
wiederholt. Publisher, Website-/API-Deployment, Produktanlage,
Bestandsmigration, Shopaktivierung und Cutover bleiben bis dahin gesperrt.

## Isolierter IONOS-Testnachweis

Der erste E2E-Lauf verwendet zwei leere MySQL-8-Testdatenbanken und künstliche
Produktdateien. Zugangsdaten werden lokal verdeckt abgefragt und nur in die
temporäre private Datei mit Modus `0600` übertragen:

```powershell
powershell.exe -NoProfile -File website\scripts\configure-backup-e2e.ps1
powershell.exe -NoProfile -File website\scripts\run-backup-e2e.ps1
```

Der Lauf stoppt vor jeder Mutation, wenn Source oder Restore nicht leer sind.
Er prüft aktive TLS-Sitzungen, InnoDB-Daten, Manifest/Hashes, parallelen Lock,
verschlüsselten Inhalt, Dump/Restore/Vergleich, idempotente Quittierung und
Status. Testtabellen, Produktfixture, Backupstände, Programme und private
Zugangskonfiguration werden anschließend vollständig entfernt. Produktionspfade
und Produktionsdaten sind in beiden Skripten ausgeschlossen.

Der isolierte IONOS-E2E-Lauf wurde am 9. August 2026 mit zwei getrennten,
anfangs leeren MySQL-8-Testdatenbanken bestanden. Aktive TLS-Sitzungen,
verschlüsseltes Streamingbackup, Lock, Restore, semantischer Schema-/Inhalts-
vergleich, Quittierung und Status waren erfolgreich. Source- und
Restore-Testtabellen, private Runtime, DPAPI-Wiederaufnahme, Programme und
Backupverzeichnisse wurden anschließend vollständig bereinigt. Dies ersetzt
nicht den separaten Produktionsbackup-, OneDrive-Pull- und Restore-Nachweis.
