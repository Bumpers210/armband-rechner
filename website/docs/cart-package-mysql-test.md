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

Der praktische Lauf steht bei Erstellung dieser Datei noch aus. Ein gruenes
Ergebnis ersetzt nicht den spaeter separat freizugebenden Produktions-Preflight,
aktuelles Produktionsbackup/Restore, bestaetigte Mengen oder Cutoverfreigabe.
Bestehende IONOS-Testprodukte, Bestaende und private Konfigurationen werden
weder gelesen noch veraendert.
