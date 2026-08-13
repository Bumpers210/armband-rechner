# Produktionsüberwachung und Alarmweg

Stand: 2026-08-13
Status: im Repository vorbereitet; auf dem Produktionsserver noch nicht aktiviert

## Zweck und Grenzen

Die Überwachung läuft zusammen mit dem vorhandenen privaten Worker alle fünf
Minuten. Sie liest ausschließlich Betriebszustände und führt keine automatische
Reparatur aus. Produkte, Bestand, Checkout, Zahlungen und Bestellungen werden
nicht verändert.

Geprüft werden:

- letzter erfolgreicher Stripe- und E-Mail-Workerlauf, jeweils höchstens
  15 Minuten alt;
- Webhook-, E-Mail- und Stripe-Metadaten-Rückstände über 15 Minuten;
- endgültig fehlgeschlagene oder manuell zu prüfende Warteschlangeneinträge;
- offene Prüffälle;
- Serverbackup älter als 90 Minuten und fehlende Offsite-Quittierung nach
  24 Stunden;
- vom Server gemeldete Speichernutzung ab 90 Prozent oder weniger als 1 GiB
  freier Speicher.

Die E-Mail enthält nur Warnungsart und Anzahl. Rohfehler, Kundendaten,
Bestelldaten und Geheimwerte werden nicht versendet.

## Benachrichtigungsverhalten

- Eine neue Problemgruppe erzeugt genau eine Warnung.
- Solange sich die Problemgruppe nicht ändert, folgt frühestens nach sechs
  Stunden eine Erinnerung.
- Wenn alle gemeldeten Probleme behoben sind, folgt genau eine Entwarnung.
- Eine unsichere Versandantwort wird nicht sofort wiederholt. Die nächste
  reguläre Erinnerung bleibt erhalten.
- Der private Zustand liegt atomar und gesperrt unter
  `/home/www/carmaja-private-shop/monitor/state.json`.

## Kontrollierte Aktivierung

Diese Schritte benötigen eine gesonderte Produktionsfreigabe:

1. Den freigegebenen Commit und die Hashes von privatem Programm, Worker und
   Monitor-CLI prüfen und zunächst nur in ein privates Stagingverzeichnis
   übertragen.
2. PHP-Dateien auf dem Server mit `/usr/bin/php8.4 -l` prüfen.
3. In der privaten Laufzeitkonfiguration die festgelegte Alarmadresse
   eintragen, `monitorEnabled` aber zunächst auf `false` lassen.
4. Private Programme, Worker und Monitor-CLI atomar aktivieren. Webroots und
   Commerce-Daten bleiben unverändert.
5. `monitorEnabled` atomar auf `true` setzen und die Konfiguration erneut
   prüfen.
6. Im IONOS-UnixCron zusätzlich die Berichtsadresse für fehlgeschlagene
   Jobläufe bestätigen. Sie ist der zweite Meldeweg, falls Brevo selbst nicht
   erreichbar ist; der Worker beendet einen fehlgeschlagenen Alarmversand mit
   Fehlerstatus. IONOS beschreibt die Berichtsadresse unter
   `https://www.ionos.de/hilfe/hosting/cronjobs/best-practices-fuer-die-einrichtung-von-cronjobs/`.
7. Genau eine kontrollierte Testwarnung senden:

   ```text
   /usr/bin/php8.4 /home/www/carmaja-private-shop/monitor.php test-alert /home/www/carmaja-private-shop/config/runtime-config.php SEND-CARMAJA-PRODUCTION-MONITOR-TEST
   ```

8. Eingang der Testwarnung durch die festgelegte Empfängerin bestätigen.
9. Einen normalen Workerlauf ausführen und den privaten Monitorzustand ohne
   Ausgabe sensibler Inhalte auf Aktualität prüfen.

Bei einem Fehler wird `monitorEnabled` wieder auf `false` gesetzt. Ein
Code-Rollback darf weder die Datenbank noch Backups oder den gespeicherten
Monitorzustand verändern.

## Reaktion auf Warnungen

1. Neue Checkout-Freigaben nicht erteilen; einen bereits laufenden Shop bei
   unklarem Zahlungs- oder Bestandszustand kontrolliert für neue Checkouts
   schließen.
2. Zuerst Worker, Warteschlange, Prüffälle und Backupstatus nur lesend prüfen.
3. Keine Warteschlangeneinträge löschen und keinen Bestand manuell ändern.
4. Bei Stripe- oder Zahlungsunklarheit den Stripe-Zustand abgleichen, bevor
   irgendeine Bestellung oder Reservierung bewertet wird.
5. Ursache, Beginn, Prüfung, Maßnahme und Entwarnung im Betriebsprotokoll
   festhalten.

## Speicherprüfung bei IONOS

Die automatische Prüfung nutzt den vom PHP-Prozess gemeldeten freien
Dateisystemspeicher. Der tarifbezogene Webspace ist zusätzlich regelmäßig im
IONOS-Konto zu kontrollieren, weil diese Kontosicht nicht über den privaten
Worker verfügbar ist. IONOS beschreibt die Anzeige unter:
`https://www.ionos.de/hilfe/hosting/speicherplatz-verwalten/freien-und-belegten-speicherplatz-des-webspace-anzeigen/`.
