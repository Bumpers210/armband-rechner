# Tagesbesucher: vorbereiteter Cron- und Alarmtest

Stand: 28. November 2026. **Vorbereitung, nicht ausgeführt.**
Keine Cronanlage, Nachricht, Serververbindung oder Konfigurationsänderung durch
diesen Plan. Ausschließlich künstliche Daten im später separat freizugebenden
Ziel. Vorhandene Statistiken, Testprodukte und private Konfigurationen erhalten.

## Abgrenzung und Startbedingungen

Datenschutzfreigabe und 14 Tage IONOS-Aufbewahrung für Sicherungen und Serverlogs
sind von der Betreiberseite bestätigt. Noch offen sind die tatsächlichen
Cronläufe und der unabhängige Alarmweg. Ein lokaler CLI-Exitcode ist kein
Nachweis einer zugestellten Warnung; künstlich gesetzte Zeitpunkte sind kein
echter Cron- oder Mitternachtsnachweis.

Erst nach gesonderter Bereitstellungs- und Testcronfreigabe:

1. Neue, eindeutig diesem Test zugeordnete private Verzeichniswurzel außerhalb
   aller Webroots wählen. Bei vorhandenem Ziel stoppen, nicht wiederverwenden.
2. Geprüfte CLI und Helper hashgebunden bereitstellen. Isolierte Konfiguration
   0600, nur künstliche Zähler und Prüfwerte; `enabled=false`, `privacyApproved=false`.
   Kein Laden der produktiven Konfiguration. Eigener Test-Webroot ist nur ein
   privates Verzeichnis für den Helper, keine öffentliche HTTP-Route.
3. PHP `/usr/bin/php8.4`, Pfad-/Symlinkschutz, Laufzeitlimit und private
   Ausführungsprotokollierung prüfen. Runlog: UTC-Beginn, UTC-Ende, Dauer,
   Exitcode, Aktion und feste Testlauf-ID; keine Kennungen, Cookies, Rohdaten,
   personenbezogenen Empfänger oder privaten Konfigurationsinhalte.
4. Alarmempfänger und unabhängigen Wächter privat festlegen. Keine Secrets suchen,
   keine neue Mail-/Monitoring-Verbindung erraten. Existierenden Zugang nur nach
   belegter Testfreigabe nutzen. Ein IONOS-Job allein erkennt keinen Gesamtausfall
   von IONOS; externer Wächter braucht einen eigenen Ausfall-/Heartbeat-Nachweis.
5. Vorher-Projektion künstlicher Tagesanzahlen und Kontrollfixture der alten
   Statistik privat sichern. Kein Test setzt aktive Zähler zurück.

## Befehlsvertrag (Vorlage, jetzt nicht ausführen)

`<TESTROOT>` ist die zuvor geprüfte **neue** private Testwurzel, niemals
`/home/www/carmaja`, das produktive Statistikverzeichnis oder ein Webroot.
Das Verzeichnis enthält `program/daily-visitors-maintenance.php`,
`web/_internal/daily-visitors.php` und `config/daily-visitors.php`.
`dataFile` zeigt ausschließlich auf `<TESTROOT>/data/daily-visitors.json`.

```text
env CARMAJA_DAILY_VISITORS_WEBROOT=<TESTROOT>/web CARMAJA_DAILY_VISITORS_CONFIG=<TESTROOT>/config/daily-visitors.php /usr/bin/php8.4 <TESTROOT>/program/daily-visitors-maintenance.php prune
env CARMAJA_DAILY_VISITORS_WEBROOT=<TESTROOT>/web CARMAJA_DAILY_VISITORS_CONFIG=<TESTROOT>/config/daily-visitors.php /usr/bin/php8.4 <TESTROOT>/program/daily-visitors-maintenance.php status
env CARMAJA_DAILY_VISITORS_WEBROOT=<TESTROOT>/web CARMAJA_DAILY_VISITORS_CONFIG=<TESTROOT>/config/daily-visitors.php /usr/bin/php8.4 <TESTROOT>/program/daily-visitors-maintenance.php export-aggregates
```

IONOS-UnixCron für den protokollierten `prune`-Test: `*/15 * * * *`.
Separater externer Wächter: alle fünf Minuten `status`, mit festem Timeout
(höchstens 40 Sekunden pro Versuch) und höchstens einer kurzen Wiederholung bei
vorübergehendem Lockkonflikt. Erforderliche Umgebung und Laufzeitbegrenzung
vor Ort prüfen; kein unkontrolliertes Shell-crontab als Ersatz.
Die Kalenderberechnung im Helper ist immer Europe/Berlin, Runlogs sind UTC.

## Exakte Prüfreihenfolge und Sollwerte

| ID | Durchführung nach Freigabe | Erwartung / Beleg |
| --- | --- | --- |
| preflight | Pfade, Rechte, Gates, künstliche Ausgangswerte und laufenden Wächter prüfen | Ziel eindeutig isoliert, beide Gates false, keine fremde Ressource eingebunden |
| cron_runs | Mindestens zwei echte IONOS-Läufe abwarten, ohne direkte CLI-Läufe als Cron auszugeben | benachbarte geplante Viertelstunden; tatsächlicher Abstand 900 Sekunden mit dokumentierter Schedulerabweichung; Exit 0, jeder Lauf unter 40 Sekunden |
| midnight | Künstliche Prüfwerte vor Berliner Mitternacht, danach keinerlei Websitebesuch oder Zählrequest | tatsächlicher Cron entfernt Vortagsprüfwerte im Normalbetrieb bis 00:15; Tagesanzahlen unverändert; Runlog vor/nach dem echten Tageswechsel |
| lock | Ausschließlich den eigenen Testlock kurz kontrolliert halten | konkurrierender Lauf scheitert begrenzt; keine Zähleränderung oder Löschung einer noch aktiven Schreibdatei; nächster freier Lauf erfolgreich |
| cleanup_outage | Nur den neu erstellten Testcron pausieren; Wächter weiterlaufen lassen | nach mehr als 30 Minuten ohne Wartung `status` nicht erfolgreich; künstlicher direkter Erfassungsversuch fail-closed; kein Gate auf true setzen |
| alarm_delivery | Überfälligen Status zweimal beobachten | echte Warnung erreicht den privat festgelegten Empfänger; Ereignis-/Empfangszeit dokumentiert; Wiederholungen kontrolliert, keine Alarmflut; keine Kennungen/Pfade/Secrets in Nachricht |
| timeout | Nur Testziel des Wächters unerreichbar machen oder isolierten Prüfvorgang verzögern | Timeout erzeugt ebenfalls Warnung; fehlende Antwort gilt nie als OK; keine Produktionsnetzregel ändern |
| recovery | Nur Testcron wieder starten | alte Prüfwerte und Abbruchreste entfernt, Tagesanzahlen erhalten, `status` OK; tatsächliche Entwarnung empfangen |
| observer_outage | Nur Testwächter/Heartbeat unterbrechen | unabhängige Ausfallkontrolle erkennt fehlenden Wächter; kein falsches Grün bei ausbleibenden Messungen |
| provider_restore | 13 Tage alte künstliche Kopie in separates Restore-Testverzeichnis übernehmen | vor Wartung Status nicht OK und Erfassung gesperrt; nach Bereinigung alte Prüfwerte weg, gespeicherte Tagesanzahlen unverändert; keine aktuelle Statistik überschreiben |
| cleanup | Nur eigene Testjobs, Alarme, Fixtures und Protokolle nach Nachweissicherung entfernen | Abwesenheit der Testjobs separat prüfen; eigene lokale/remote Artefakte entfernt; keine dauerhafte eigene Kopie von Kennungsdaten; bestehende Statistiken bytegleich |

Die Warnung muss spätestens beim nächsten Fünf-Minuten-Wächterlauf nach einem
fehlerhaften Status ausgelöst werden (zuzüglich begrenztem Timeout/Retry).
Alarmkanal-Ausfall und Schedulerverzögerungen sind ausdrücklich Fehler, keine
bestandenen Nachweise. Entwarnung erst nach bestätigtem gesunden Status.

## Abbruch, Cleanup und Protokoll

Bei unbekanntem Pfad, falscher Konfiguration, echten Daten, nicht bestätigtem
Empfänger, Geheimwertausgabe oder unerwarteter Mutation sofort stoppen.
Nur eigene Testprozesse/Jobs beenden und eigene Ressourcen entfernen. Nicht
pauschal Verzeichnisse, Logs, Backups oder fremde Cronjobs löschen. Originale
Statistiken niemals aus einer älteren Testkopie zurückspielen.

Das [Nachweisprotokoll](daily-visitors-cron-alarm-proof.template.json) bleibt bis
zur Durchführung auf `not_run`, Zeit- und Empfangsfelder auf `null`. Private
Belege außerhalb des Repositorys speichern; im Repository nur Ergebnis,
UTC-Zeiten, Dauer, geprüfter Code-SHA und anonyme Belegreferenz, keine Inhalte
von Statistiken, Nachrichten, Empfängeradressen oder Geheimwerte.
Alle Pflichtfälle bestanden und Cleanup verifiziert bedeutet **Betriebsnachweis**,
nicht automatisch Freigabe von Deployment oder Besucherzählung.

## Jetzt lokal ausführbare künstliche Prüfungen

```text
php tests/php/daily-visitors-operations-test.php
php tests/php/daily-visitors-test.php
node --test tests/node/daily-visitors.test.mjs tests/node/daily-visitors-operations-plan.test.mjs
```

Aus `website/` mit PHP 8.4. Diese Tests nutzen temporäre lokale Fixtures,
keine Konfiguration von IONOS, keinen Cron und keinen Mailversand. Die
Provider-Restoreprobe prüft nur das Verhalten unserer Bereinigung, nicht
die tatsächliche automatische Löschung durch IONOS nach 14 Tagen.
