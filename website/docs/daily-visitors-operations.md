# Tagesbesucher: Datenschutz- und Betriebsnachweise

Stand: 28. November 2026. Basis: geprüfter Inhaltsstand aus PR #128, auf `main`
integriert als `b87dd8ed04f6a76577936a806b21305e27352937`.
Status: **Datenschutzfreigabe bestätigt; technische Betriebsabnahme offen**.
Die Betreiberseite bestätigt 14 Tage automatische IONOS-Aufbewahrung für
Sicherungskopien UND Serverlogs. Keine erneute pauschale Freigabeanfrage.
Neue Anpassungen ausschließlich lokal; kein Zugriff auf Produktionsdateien
oder private Konfigurationen. Keine Live-Abnahme, Bereitstellung oder Aktivierung.

## Nachweismatrix

| Nachweis | Ergebnis | Verbleibender Schritt |
| --- | --- | --- |
| Einwilligung vor neuer Kennung; gleichwertige Ablehnung; direkter Widerruf | Tests bestanden; Datenschutzfreigabe durch Betreiberseite bestätigt | endgültige Revision in neues Legal Bundle übernehmen, nicht aktivieren |
| Ein Browser je Berliner Tag, Sommer-/Winterzeit, Parallelität | bestehende 95 PHP-Prüfungen bestanden | später geschützter Zielumgebungstest |
| Löschung ohne nächsten Besuch; Ausfall, Wiederanlauf und Provider-Restore | 43 lokale Betriebsprüfungen bestanden, Fixtures bereinigt | echter IONOS-Löschcron und Ausfalltest erst nach Bereitstellungsfreigabe |
| Abbruchreste mit Kennungen | Fehler nachgestellt, lokal korrigiert und retestet | korrigiertes Paket nach separater Freigabe bereitstellen |
| Nichtveränderung alter Statistik und Konfiguration | in künstlichen Fixtures bytegleich | vor/nach späterer Bereitstellung private Integritätskontrolle |
| Eigener Produktbackup-Code | echte Inventarmethode mit künstlichen Dateien geprüft; Statistikpfad nicht in Allowlist | tatsächliche Runtimepfade später lesend abgleichen |
| IONOS-Sicherungen und Serverlogs | 14 Tage, danach automatische Löschung: direkte Betreiberbestätigung für beide Bereiche | Frist in Text und Restoreablauf berücksichtigen; keine Behauptung eines Backupausschlusses |
| POSIX 0600 / Symlinkabwehr der Abbruchkorrektur | PR #128 und Merge-CI mit PHP 8.4 unter Linux bestanden | später Zielumgebung prüfen |
| Unabhängiger Alarm und tatsächlicher Empfang | Statusfehler/Entwarnung lokal geprüft; kein Versand behauptet | getrennten Wächter und Alarmempfang im Ziel belegen |
| AVV, neue aktive Datenschutzfassung, Archivbindung | Freigabe bestätigt, neue technische Inhaltsbindung noch nicht bereitgestellt | vorhandenen Hostingvertrag zuordnen, neue Fassung integrieren; alte Archive erhalten |

„Lokal bestanden“ bedeutet nicht „alle Betriebsbedingungen erfüllt“.
Die fünf zuvor lokal übersprungenen Linux-Prüfungen sind geschlossen:
[Merge-CI](https://github.com/Bumpers210/armband-rechner/actions/runs/46153759610)
mit 42 Betriebs-, 95 Tagesbesucher-, 93 Berichts- und 318 Node-Prüfungen,
ohne übersprungene Node-Tests. Reale Cron-/Alarmnachweise bleiben offen.

Frühere lokale Regression für PR #128 am 28. November 2026: PHP 8.4.22, Lint der drei betroffenen
PHP-Dateien, 37 Betriebs-, 95 Tagesbesucher- und 93 Berichtprüfungen bestanden.
Die bestehende Klickstatistik wurde zusätzlich nativ geprüft, da WSL kein PHP
bereitstellt. Node: 316 bestanden, keine Fehler, zwei optionale Apache-Prüfungen
mangels lokalem Apache übersprungen. Deployment-Shelltests mit künstlichen
Dateien, Website-Lint, Testbuild/Exportprüfung und `git diff --check` bestanden.
Geheimwert-Musterprüfung der geänderten Dateien ohne Treffer; keine Prüfung
realer Secrets. Dieser lokale Lauf umfasste keinen Serverzugriff oder Deployment.
PR #128 wurde anschließend freigegeben zusammengeführt. Für die jetzt folgenden
14-Tage-Anpassungen gibt es noch keinen Commit/Push; weiterhin keine Bereitstellung.

Aktuelle lokale Regression der 14-Tage-Anpassung am 28. November 2026:
PHP 8.4.22, Lint des ergänzten Tests, 43 Betriebs-, 95 Tagesbesucher- und
93 Berichtprüfungen sowie native Klickstatistik bestanden. Die sechs neuen
Betriebsprüfungen verwenden eine 13 Tage alte künstliche Sicherung: vor Wartung
gesperrt, danach alte Prüfwerte entfernt und Tagesanzahlen erhalten. Die
unveränderten fünf POSIX-Prüfungen werden unter Windows weiterhin übersprungen;
deren oben genannter Linux-Nachweis stammt aus PR #128, nicht aus einem neuen
CI-Lauf dieser Anpassung.

`npm ci` meldet keine bekannten Schwachstellen. Website-Lint, 320 von 322
Node-Prüfungen (zwei optionale Apache-Prüfungen mangels Apache übersprungen),
alle Deployment-Shelltests mit künstlichen Dateien und der frische Testbuild
einschließlich Exportprüfung bestanden. Der Produktionsbuild mit aktivierter
Statistik wurde erwartungsgemäß bereits durch die unveränderte Freigabesperre
abgewiesen: bestätigte Datenschutzfreigabe allein aktiviert nichts.

## Festgestellte und behobene Abbruchlücke

Ein abrupt endender Prozess kann eine atomare Schreibdatei mit Tagesprüfwerten
hinterlassen. Der bisherige `finally`-Cleanup läuft bei einem harten Prozessende
nicht zuverlässig; `status` prüfte solche Reste nicht. Ein separater Prozess
mit echtem Writer-Lock und nachgestellter Teil-Schreibdatei reproduzierte den
Fehler vor der Korrektur. Kein echter Besucher wurde dafür erfasst.

Der nächste Schreib-/Bereinigungslauf entfernt nun ausschließlich eigene Dateien
`<Datendatei>.tmp-<24 hexadezimale Zeichen>` **unter exklusivem Writer-Lock**.
Andere Namen, Verzeichnisse und Symlinkziele werden nicht gelöscht. Symlinks im
exakten Namensbereich lösen einen Fehler aus. `status` prüft lesend unter einem
gemeinsamen Lock, meldet Reste sowie überfällige Wartung und gibt weder Kennungen
noch Pfade aus. Bei einer laufenden konkurrierenden Operation liefert er einen
vorübergehend nicht bestätigten Zustand statt unbegrenzt zu warten.

Bestätigte Tageszahlen werden aus der letzten atomar gespeicherten Datei gelesen;
ein Fragment wird niemals als Backup oder als neuer Zähler übernommen. Bei
beschädigter Hauptdatei bleibt der Fehler sichtbar; kein automatischer Reset.
Ein Restore von ausschließlich künstlichen Tagesanzahlen wurde nachgestellt:
ohne alte Prüfwerte, zunächst gesperrt, nach erfolgreicher Wartung wieder bereit.
Ein Restore während eines laufenden Tags kann erneute Zählungen erlauben;
betroffene Messzeiträume sind dann als unvollständig zu dokumentieren.

## Was die öffentlichen IONOS-Quellen belegen – und was nicht

Historische Recherche am 28. November 2026 vor der Betreiberbestätigung.
Für diesen Vertrag ist nun die direkte Angabe **14 Tage für beide Bereiche**
maßgeblich. Die folgenden allgemeinen Artikel ersetzen diese Zuordnung nicht;
abweichende öffentliche Fristen werden nicht als Shopfrist übernommen.
Es wurden keine Kontodokumente oder vertraulichen Logs abgerufen.

- IONOS beschreibt tägliche Webspace-Sicherungen, die bis zu **14 Tage** für eine
  Wiederherstellung verfügbar sind. Ein zugesicherter Ausschluss unseres privaten
  Statistikpfads ist dort nicht dokumentiert. Ein Pfad außerhalb eines Webroots
  ist deshalb **kein belegter Backupausschluss**.
  [Automatische Webspace-Backups](https://www.ionos.de/hilfe/hosting/dateien-sichern-wiederherstellen/geloeschte-dateien-aus-automatischem-backup-wiederherstellen/)
- Die beim Abruf geöffnete Webhosting-Datenschutzseite nennt **7 Tage** für die dort
  beschriebenen Besucherdaten. Sie beschreibt außerdem anonymisierte IP-Daten
  und eine zusätzliche WebAnalytics-Verarbeitung. Das belegt nicht das konkrete
  Format sämtlicher Access-, Proxy-, Fehler- oder Sicherheitslogs dieses Vertrags.
  [Datenverarbeitung durch Webhosting](https://www.ionos.de/hilfe/datenschutz/datenverarbeitung-durch-ihr-ionos-produktes/datenverarbeitung-durch-webhosting-produkte/)
- Die gesonderte Logdokumentation unterscheidet Verträge vor/nach dem
  18. September 2025; für ältere Logs nennt sie bis zu **8 Wochen** und beschreibt
  auch `X-Forwarded-For`. Für neuere Verträge nennt sie `log-tail`/`log-cat`.
  Deshalb weder 7 Tage noch 8 Wochen pauschal als bestätigte Shopfrist übernehmen.
  Suchauszüge und geöffnete Artikelfassungen wichen beim Abruf ebenfalls ab;
  maßgeblich bleibt die konkrete Providerbestätigung.
  [Hinweise zu Logdateien](https://www.ionos.de/hilfe/hosting/log-dateien/hinweise-zu-logdateien/)
- Für neue Verträge seit 19. Juli 2022 ist die AVV laut IONOS Bestandteil der AGB.
  Vertrag, anwendbare Fassung und Verarbeitung müssen dennoch zugeordnet werden;
  eine zusätzliche Unterschrift ist nicht pauschal zu verlangen.
  [IONOS-AVV](https://www.ionos.de/hilfe/datenschutz/allgemeine-informationen-zur-datenschutz-grundverordnung-dsgvo/vereinbarung-zur-auftragsverarbeitung-avv-mit-ionos-abschliessen/)

**Aktueller Vertrag:** Eigene Exporte/Backups bleiben kennungsfrei.
Automatische IONOS-Sicherungskopien können Tagesprüfwerte enthalten und werden
nach 14 Tagen gelöscht; ebenso IONOS-Serverlogs. Dies ist ausdrücklich getrennt
von der täglichen Löschung in der laufenden Statistik dokumentiert.
Kein weiterer pauschaler Backupausschluss-Gate und keine erneute Anfrage zur
bereits bestätigten Frist. Backup-/Logfelder, AVV und tatsächlicher technischer
Betrieb werden dadurch nicht erfunden oder als unabhängig geprüft bezeichnet.
Keine Providerkopien für Analysen verwenden, keine unbefristeten eigenen Kopien
erzeugen und keine vorhandenen Backups global abschalten.

## Einwilligungsnachweis ohne zusätzliche Besucherkartei

Die DSK erläutert in ihrer Orientierungshilfe, Randnummern 84–85, dass regelmäßig
der Nachweis des Einwilligungsverfahrens und der jeweiligen vorgelegten
Information genügt; eine langlebige UID allein für diesen Nachweis ist nicht
erforderlich. Auch frühere Bannertexte und Konfigurationen sollten nachvollziehbar
bleiben. Das stützt die technische Ausrichtung, ersetzt aber nicht die Prüfung
der konkreten Rechtsgrundlage, 180-Tage-Frist und Gestaltung.
[DSK, OH Digitale Dienste, November 2024](https://www.datenschutzkonferenz-online.de/media/oh/OH_Digitale_Dienste.pdf)

Vor Aktivierung zusammen dokumentieren: geprüfter Code-SHA, unveränderliche
Banner-/Datenschutzfassung und Version, Legal-Bundle-ID und Inhaltsbindung,
Ergebnisse zu Zustimmung/Ablehnung/Widerruf und die erteilte Betreiberbestätigung.
Keine zentralen Besucher- oder Einwilligungskennungen zusätzlich einführen.
Die vorhandenen Legal-Bundle-Archive werden nicht geändert.

## Späterer Betriebsnachweis – noch nicht ausführen

1. Bestätigte 14-Tage-Providerfrist, private Datenpfade außerhalb **aller** Webroots,
   0600 und kennungsfreie eigene Exporte dokumentieren. Nur anonymisierte Ergebnisnotiz
   ins Repository, keine Vertragsnummern, Logs, Kontodaten oder Prüferdaten.
2. Nach gesonderter Bereitstellungsfreigabe private CLI und Statistikpaket
   hashgebunden installieren; `enabled=false`, `privacyApproved=false` belassen.
3. `prune`, danach lesendes `status`; Tagesanzahlen und alte Statistiken vergleichen.
4. Echten IONOS-UnixCron `*/15 * * * *` getrennt freigeben und anlegen. Mindestens
   zwei Zeitstempel und eine Bereinigung über Berliner Mitternacht ohne neuen
   Websitebesuch nachweisen. Nur künstliche Daten im isolierten Ziel verwenden.
5. Einen **unabhängig geplanten Wächter** (nicht im Löschjob selbst) den privaten
   `status` alle fünf Minuten ausführen lassen. Ein zweiter Job beim gleichen
   Provider erkennt nicht sicher den Ausfall des gesamten Providers/Schedulers.
   Der Wächter braucht deshalb auch einen von dort unabhängigen Ausfallalarm.
   Vorhandene Commerce-Überwachung ist kein ungeprüfter Ersatz.
6. Nichtnull-Exitcode bzw. Timeout alarmieren; bei Lockkonflikt einmal nach kurzer
   Pause erneut prüfen, keine Dauerwiederholung. Testalarm am tatsächlichen
   Empfänger bestätigen, danach Entwarnung nach erfolgreicher Wartung belegen.
7. Löschjob-Ausfall nachstellen: Zählung nach mehr als 30 Minuten Wartungsalter
   gesperrt; Alarm kommt ohne Websiteaufruf an; Wiederanlauf entfernt Reste und
   alte Prüfwerte, ohne Zähler oder andere Daten zurückzusetzen. Auch den Ausfall
   des Wächters prüfen. Keine Kontaktdaten im öffentlichen Jobbefehl.
8. Bestätigte Datenschutzfassung technisch in ein neues Bundle übernehmen;
   Veröffentlichung und Aktivierung erst nach den Betriebsnachweisen separat
   freigeben. Noch kein Flag auf `true` setzen.

Die IONOS-Cronhinweise belegen die PHP-8.4-CLI und empfehlen Fehlerberichte,
belegen aber keine eingerichtete Berichtsadresse oder Zustellung in unserem Konto.
[IONOS-Cronhinweise](https://www.ionos.de/hilfe/hosting/cronjobs/best-practices-fuer-die-einrichtung-von-cronjobs/)

Private Befehle nach gesonderter Freigabe:

```text
/usr/bin/php8.4 /home/www/carmaja-production-stats-maintenance/program/daily-visitors-maintenance.php prune
/usr/bin/php8.4 /home/www/carmaja-production-stats-maintenance/program/daily-visitors-maintenance.php status
```

Bei Alarm: neue Statistikmessung gesperrt lassen, Ursache privat prüfen, letzte
Zählerstände erhalten, Wartung nachholen, erfolgreichen Status und Alarmende
nachweisen. Website, Shop, Zahlungen und bestehende Statistiken nicht reparieren
oder zurücksetzen. Gesamtarchiv-Restore darf keine alten Prüfwerte unbemerkt
erneut produktiv machen; vor Erfassung erst Wartung und Statusprüfung.

## Vorbereiteter nächster Betriebsnachweis

[Cron-/Alarmtestplan](daily-visitors-cron-alarm-test-plan.md) mit Reihenfolge,
isolierten Befehlen, Abnahmekriterien, Abbruch und Cleanup. Das zugehörige
[leere Nachweisprotokoll](daily-visitors-cron-alarm-proof.template.json) enthält
ausdrücklich noch keine echten Läufe, Alarmempfänger oder bestandenen Livechecks.
Die frühere IONOS-Rückfrage zu den Fristen ist durch die Betreiberantwort erledigt.
Eine gesonderte Bereitstellungs-/Testcronfreigabe und ein privat festgelegter
Alarmweg sind für echte Tests weiterhin erforderlich, nicht für diese Vorbereitung.
