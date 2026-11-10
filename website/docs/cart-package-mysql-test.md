# Isolierter MySQL-8-Paketnachweis

Stand: 10. November 2026. Freigegeben ist ausschliesslich der Testworkflow
auf `codex/cart-package-mysql-test`, einschliesslich Commit und Push.
Kein Merge, Deployment oder Produktionszugriff.

## Gepruefter Kandidat

Das Paket wird aus dem unveraenderten, separat ausgecheckten main-Commit
`1e361a3e6714fdcd3e2c20ddf9187ef28f38965b` gebaut. Der Testcode wird getrennt
aus dem Testbranch geladen. Alle 40 Paketdateien werden vor dem Test mit
Manifest und Quellstand verglichen; nur diese Programme und SQL-Dateien
werden gegen MySQL ausgefuehrt. Keine Anpassung des Kandidaten im Testlauf.

## Isolation und Geheimwerte

- Frischer GitHub-gehosteter Linux-Rechner, ausschliesslich dieser Branch.
- Offizieller MySQL-8.0-Container, Port nur an `127.0.0.1`; Datenverzeichnis
  fluechtig. Exakte Serverversion und Image-Digest stehen im Ergebnisbericht.
- Zwei neue Datenbanken und getrennte Benutzer. Zufaellige Passwoerter,
  Backupschluessel und kurzlebige TLS-CA ausschliesslich in privaten Dateien.
- TLS wird erzwungen, CA und Hostidentitaet geprueft; Klartextverbindung
  muss scheitern. Dies ist kein neuer IONOS-CA-Nachweis.
- MariaDB-Dump-/Restoreclients wie im IONOS-Vertrag, gegen MySQL 8, nicht
  gegen einen MariaDB-Server. Kein Nachweis fuer MariaDB-11-Kompatibilitaet.
- Keine Repositorysecrets, SSH-Zugaenge, Provider oder IONOS-Konfigurationen.
- Einziger hochgeladener Inhalt ist ein erlaubnisbeschraenkter Ergebnisbericht
  mit Pruefnamen, Versionen und Cleanupstatus; keine Datenbank, Runtime, Keys,
  Backups oder Rohlogs. Artefaktaufbewahrung: 14 Tage.

## Nachweise

1. Alle fuenf Paketmigrationen von leerem Schema, InnoDB und Journalbindung;
   Wiederholung und Ablehnung widerspruechlicher Migrationspruefsummen.
2. Vier historische Migrationen mit kuenstlicher bestaetigter Einzelbestellung,
   Zahlungs-/Versand-/Rechtsnachweis und Outbox. Verschluesseltes Backup und
   Restore vor Migration; anschliessend Warenkorbmigration ohne Snapshotverlust.
3. Zwei kuenstliche gezaehlte Produkte, kein Verkaufsstart. Echter Prozessabbruch
   waehrend der zweiten Bestandsanpassung; zehn konkurrierende Wiederholungen,
   Idempotenz, spaetere Bestandsaenderung und Sperren bei laufender Zahlung oder
   weiterhin blockierender Reservierung. Echter InnoDB-Row-Lock-Timeout.
4. Vollstaendiges verschluesseltes Backup nach Migration mit Unicode, binaeren
   Inhalten, Produktdateien und kuenstlicher Runtime. Wiederholter Restore mit
   Schema-, Inhalts-, Metadaten- und Dateivergleich durch den Paketdienst.
5. Backup-Lock, verwaiste Zwischenablage, falscher Schluessel, HMAC-Manipulation,
   veraenderte/abgeschnittene Archive, unsichere Archivpfade, nichtleeres
   Restoreziel, Wiederanlauf nach Fehler und Quellenunveraendertheit.
6. Beide Datenbanken am Ende leer. Container, temporaere Dateien, Schluessel
   und Konfigurationen per EXIT-Trap und zusaetzlichem `always()`-Schritt entfernt.

## Grenzen und Status

Der kuenstliche Import verwendet den Wartungs-/Freigabevertrag des Adapters
ausschliesslich mit lokal erzeugten Testbelegen. Er erteilt keine echte
Produktionsfreigabe. MySQL-AUTO_INCREMENT-Luecken nach Rollback sind erlaubt;
Datensaetze, Mengen, Audit und Snapshots muessen vollstaendig zurueckgerollt sein.

Der praktische Paketnachweis ist bestanden. Ein gruenes
Ergebnis ersetzt nicht den spaeter separat freizugebenden Produktions-Preflight,
aktuelles Produktionsbackup/Restore, bestaetigte Mengen oder Cutoverfreigabe.
Bestehende IONOS-Testprodukte, Bestaende und private Konfigurationen werden
weder gelesen noch veraendert.

## Praktische Abnahme

Erster vollstaendiger GitHub-Lauf:
[42896991357](https://github.com/Bumpers210/armband-rechner/actions/runs/42896991357),
Testcommit `1305f9ab03dc45dba78ea66f09596ed6d9b3f09a`.
Gepruefter Paketcommit bleibt `1e361a3e6714fdcd3e2c20ddf9187ef28f38965b`.

| Nachweis | Ergebnis |
| --- | --- |
| Server / PHP | MySQL 8.0.46 / PHP 8.4.27 |
| TLS | Beide Sitzungen verschluesselt; CA und Hostidentitaet geprueft; Klartextverbindung abgelehnt |
| Praktischer Pakettest | 62 Pruefungen bestanden |
| Leeres Schema / historische Migration | Fuenf Migrationen, 38 InnoDB-Tabellen, Journal und Wiederholung bestanden |
| Historische Daten | Bestellung, Position, Zahlung, Versand, Legal Bundle und Outbox erhalten |
| Import und Wiederanlauf | SIGKILL waehrend zweiter Anpassung vollstaendig zurueckgerollt; zehn parallele Aufrufe, genau zwei Auditeintraege |
| Backup / Restore | Vor und nach Migration bestanden; Wiederholung, Unicode, Binaerdaten, Struktur, Inhalt und Produktdateien verglichen |
| Fehlerfaelle | Lock, Row-Lock-Timeout, HMAC, falscher Schluessel, Manipulation, Abbruch, Pfade und nichtleeres Restoreziel bestanden |
| Paket / PHP | 40 Dateien und 31 PHP-Dateien geprueft; 49 unterschiedliche Offline-Import-/Freigabepruefungen und 9 Backup-Tests bestanden |
| Node / Shell / Website | 254 bestehende Node-Tests, 3 Workflowvertraege, Shelltests, Lint und Testexport bestanden |
| Cleanup | Beide Datenbanken leer; Container, Datenbanken, Schluessel und temporaere Dateien anschliessend entfernt; separat bestaetigt |

Der lokale Windows-Gesamtlauf war durch den nicht verfuegbaren WSL-Bash-Aufruf
eingeschraenkt. Die unveraenderte vollstaendige Node-/Shell-Suite bestand auf
Linux in diesem GitHub-Lauf. Lokaler PHP-Lint, die betroffenen PHP- und
Node-Vertragstests, ESLint, Website-Testbuild und `git diff --check` bestanden.

Der Ergebnisbericht enthaelt den konkreten MySQL-Image-Digest sowie den
Cleanupnachweis. Die Testdatenbank-/Schluesseldateien und Backups werden nicht
als Artefakt aufbewahrt. Nur Testbranch gepusht, kein PR/Merge, kein Deployment,
kein IONOS- oder Produktionszugriff. Main, bestehende Produkte, Bestaende und
private Konfigurationen unveraendert. Dies ist ausschliesslich die Abnahme
des isolierten Pakettests, keine oeffentliche Shopfreigabe.
