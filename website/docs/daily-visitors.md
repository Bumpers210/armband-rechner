# Tagesbesucher – lokale Vorbereitung, 28. November 2026

## Auftrag und Zählvertrag

Ein Browser zählt pro Kalendertag in `Europe/Berlin` einmal für die gesamte
Website. Navigation, Neuladen und mehrere Tabs erhöhen diese Zahl nicht.
Am folgenden Tag entsteht eine neue, zufällige Kennung. Das ist keine
Personenerkennung: andere Browser/Geräte, Inkognito oder gelöschte Browserdaten
können zusätzliche Zählungen erzeugen. Automatisierte Zugriffe sind damit nicht
zuverlässig als Menschen oder Bots unterscheidbar.

Die Hauptzahl und der Tagesverlauf zeigen Tagesbesucher. Über mehrere Tage heißt
die Summe **Besuchertage**, ausdrücklich nicht eindeutige Personen im Zeitraum.
Gezählt werden nur Browser mit Einwilligung und unterstütztem Browserspeicher /
Web Locks. Die Zahl ist daher keine vollständige Erhebung aller Besucher.

`clicks.json`, Monatsarchive, Marketing-Neustart und vorhandene Commerce-Daten
werden weder migriert noch gelöscht. Bisherige Seitenaufrufe bleiben in einem
aufklappbaren Abschnitt abrufbar. Produktseitenaufrufe und Herkunftsauswertung
bleiben ausdrücklich als Aufrufe bezeichnet, nicht als Unique-Besucher.
Historische Aufrufe werden niemals nachträglich zu Besuchern umgedeutet.
Fehlende Tage erscheinen unbekannt, nicht als null. Es gibt ohne zusätzliche
Betriebsüberwachung keinen Nachweis, dass ein Tag ohne Ereignis tatsächlich
null Besucher hatte. Summen bleiben deshalb als unvollständige Messung erklärt.

## Getrennte Aktivierung – derzeit AUS

Keine bestehende Datenschutzfreigabe wird hier erweitert oder als Einwilligung
ausgelegt. Die bisherigen freigegebenen Rechtstexte bleiben unverändert.
Einwilligungsoberfläche, technische Aufbewahrung und Datenschutzentwurf sind
lokal implementiert. Vor Livebetrieb ist das [Prüfpaket](daily-visitors-privacy-review.md)
extern zu bestätigen; diese Änderung erteilt keine Rechtsfreigabe.

Voneinander unabhängige Voraussetzungen:

1. Website-Build: `NEXT_PUBLIC_DAILY_VISITORS_ENABLED=true`; nicht gesetzt ist AUS.
   Produktion verweigert den Build bei ungeprüftem Datenschutzentwurf. Zusätzlich
   muss das aktive, freigegebene Legal Bundle alle neuen Datenschutzhinweise
   vollständig enthalten und die alte Aussage „keine Analyse-Cookies“ ersetzen.
2. Private PHP-Konfiguration über `CARMAJA_DAILY_VISITORS_CONFIG`, außerhalb
   **aller** Webroots, Modus `0600`, mit `enabled=true` und `privacyApproved=true`.
3. Aktive Wahl über gleich gestaltete Buttons „Statistik erlauben“ oder
   „Statistik ablehnen“. Keine Vorbelegung, Scroll-Zustimmung oder Shopblockade.
   `carmaja_analytics_consent=daily-visitors-v2.allow.<Unixzeit>` bzw.
   `.deny.<Unixzeit>` ist host-only, Secure, SameSite=Lax, Path=/ und läuft nach
   180 Tagen ab. Server und Browser validieren Version und Alter. Keine
   zufällige Nutzer-ID und keine Übernahme eines Shop-/Login-Consents.
4. Die private Löschroutine muss laufen und überwacht werden. Bei fehlendem,
   zukünftigem oder mehr als 30 Minuten altem Wartungsnachweis gibt es keine
   neue Erfassung. HTTP antwortet dann fail-closed mit 503, ohne neue Kennung.

Ein unaufdringlicher Abschnitt im Seitenfluss zeigt die Frage; er verdeckt
weder Impressum noch Datenschutz. Im Footer stehen „Statistik-Einstellungen“
und bei Zustimmung der direkte Ein-Klick-Widerruf. Änderungen lösen
`carmaja:analytics-consent` aus. Nach Ablehnung kein erneutes Nachfragen auf
jeder Seite. Widerruf löscht die Tageskennung sofort; neue Zählanfragen stoppen.
Ein bereits abgesendeter Request kann noch abgeschlossen werden. Bei
Speicherfehlern wird keine erfolgreiche Wahl behauptet. Bereits aggregierte
Zahlen werden nicht zurückgerechnet.

Kein Workflow setzt die Freigaben. Ohne sie gibt es keine Besucherkennung;
der bestehende aggregierte Seitenzähler läuft wie zuvor. Ein Wartungslauf kann
bereits vor Aktivierung eine leere, kennungsfreie Statistikdatei vorbereiten.

Private Konfigurationsstruktur (nur Beispiel, keine aktive Datei):

```php
return [
    'enabled' => false,
    'privacyApproved' => false,
    'consentVersion' => 'daily-visitors-v2',
    'origin' => 'https://www.carmaja-perlen.de',
    'dataFile' => '/home/www/carmaja-production-stats-maintenance/dashboard-cache/daily-visitors.json',
];
```

Die Statistik liest standardmäßig genau diese neue private Datendatei;
`CARMAJA_DAILY_VISITORS_FILE` erlaubt eine abweichende private Testdatei. Bei
abweichender Konfiguration müssen Schreib- und Lesepfad identisch sein.
Die Prüfung verhindert Pfade innerhalb des eigenen Webroots und Symlinks;
vor Bereitstellung muss der Betreiber zusätzlich die Trennung von allen
weiteren Webroots bestätigen. Das private Verzeichnis wird nicht automatisch
angelegt und darf keine anderen laufenden Daten ersetzen.

## Datenminimierung und Fehlerfälle

- Browser: Cookie `carmaja_daily_visitor` mit Berliner Serverdatum, zufälligen
  32 Bytes und Empfangsbestätigung. Ablauf an der nächsten Berliner Mitternacht
  über `Expires`, ausdrücklich auch ohne Rückkehr zur Website. Kein localStorage.
  Sommer-/Winterzeittage dauern korrekt 23 bzw. 25 Stunden.
- Web Lock serialisiert den ersten Zugriff über Tabs desselben Browsers.
- Server: nur Tagesanzahlen und SHA-256-Deduplizierungsschlüssel für den aktiven
  Tag. Keine IPs, User-Agents, Roh-Referrer, Shopkennungen oder Navigationsprofile.
- Der Pfad wird nur auf eine veröffentlichte Seite geprüft, nicht gespeichert.
- Neue Requests tragen die Kennung nur im POST-Body, nie in URL oder Logs.
- Gleiche Kennung wird unter Dateilock atomar genau einmal gezählt, auch nach
  verlorener Antwort. Lockkonflikt/Fehler bleibt ohne Datenreset und wird bei
  einem späteren Seiten-/Sichtbarkeitsereignis erneut versucht.
- Serverseitiger Kalendertag verhindert clientseitige Zeitmanipulation;
  verspätete Requests vom Vortag werden abgelehnt. Sommer-/Winterzeit geprüft.
- Keine Kennung bei fehlender Zustimmung, unbekannter Consent-Version,
  deaktiviertem Gate, gesperrtem Speicher oder fehlenden Web Locks.
- Serverbereinigung läuft unabhängig vom Besuch alle 15 Minuten. Prüfwerte des
  Vortags werden im Normalbetrieb bis 00:15 Berlin entfernt. Ausfälle können
  die tatsächliche Löschung verzögern: Überwachung und unverzügliche Nachholung
  sind Pflicht; die 30-Minuten-Sperre ersetzt keinen funktionierenden Cron.
  Aggregierte Tageszahlen und alte Statistikdaten bleiben erhalten.
- Exakte Zahlen gegen absichtliches Löschen/Fälschen von Browserkennungen oder
  Botverkehr sind nicht zugesichert. Begrenzte Request- und Dateigröße sowie
  100.000 Kennungen pro Tag verhindern unbegrenztes Dateiwachstum pro Tag.

## Private Aufbewahrung und Betriebsprüfung – noch nicht eingerichtet

CLI-Quelle: `website/scripts/daily-visitors-maintenance.php`. Vorgesehener privater
Pfad: `/home/www/carmaja-production-stats-maintenance/program/daily-visitors-maintenance.php`.
Defaultkonfiguration: `/home/www/carmaja-production-stats-maintenance/config/daily-visitors.php`
(0600). `CARMAJA_DAILY_VISITORS_CONFIG` und `CARMAJA_DAILY_VISITORS_WEBROOT` erlauben
isolierte Testpfade; Produktionswebroot default `/home/www/carmaja`.
Die CLI lädt den identischen Helper aus `<webroot>/_internal/daily-visitors.php`.
Sie wird **nicht** im öffentlichen Hosting-/Websitepaket ausgeliefert.

Nach gesonderter Freigabe privates Skript hashgeprüft bereitstellen, zuerst
`prune`, dann `status` ausführen und IONOS-Cron `*/15 * * * *` einrichten:

```text
/usr/bin/php8.4 /home/www/carmaja-production-stats-maintenance/program/daily-visitors-maintenance.php prune
```

`status` liefert nur OK oder einen generischen Fehler mit Exitcode 1; es prüft
Wartungsalter und überfällige Prüfwerte. Die bestehende Betriebsüberwachung muss
diesen Status unabhängig vom Zählverkehr regelmäßig abfragen und bei Fehlern
alarmieren. Ein Statusaufruf allein versendet keine Warnung. Vor Aktivierung
Cronausfall, Alarmweg, Wiederanlauf und Bereinigung im Ziel nachweisen.
`prune` funktioniert auch bei deaktivierten Zähl-/Datenschutzschaltern.

Die laufende Datei mit `seen` darf **nicht** gesichert werden. Ausschließlich
`export-aggregates` liefert eine kennungsfreie JSON-Projektion (`version`,
`kind`, `days`) für einen später freizugebenden Statistikbackupweg. Die lokal
geprüfte Produktionsbackup-Allowlist umfasst Produktpfade und Runtime, nicht
das separate Statistikverzeichnis. Provider-Snapshots, Access-/Errorlogs,
POST-/Cookie-Logging und tatsächliche Backupausschlüsse wurden ohne Serverzugriff
nicht verifiziert; deren Bestätigung bleibt ein Aktivierungsgate.

## Lokale Prüfung und späterer Bereitstellungsweg

Neue PHP- und Node-Tests verwenden ausschließlich temporäre künstliche Daten.
Sie decken Tages-/DST-Wechsel, zwei Browser, zehn parallele Clients/Tabs,
Replay/Antwortverlust, ungültige Eingaben, Consent-Widerruf, Speicherfehler,
beschädigte Zähler und bytegleichen Erhalt der alten Statistik ab.

Der gesperrte manuelle Statistik-Wartungsworkflow paketiert zusätzlich
`daily-visitor.php` und `_internal/daily-visitors.php` (neun Dateien insgesamt),
keine Konfiguration oder Laufzeitdaten. Die Website-JavaScript-Dateien benötigen
später weiterhin ihren eigenen freigegebenen Website-Deploy. Eine
Konfigurationsaktivierung gehört zu keinem dieser Deployments.
Der normale Website-Workflow entfernt `daily-visitor.php` vor der
Manifestbildung zusammen mit den übrigen separat verwalteten Hostingdateien.
Die PHP-Sperre des statischen Website-Manifests bleibt unverändert; der
Endpunkt wird ausschließlich über das separate Statistikpaket bereitgestellt.
Rollback des Codes darf weder alte noch neue Zähler zurücksetzen.

Noch erforderlich: Datenschutz-/Consent-Abnahme, gesonderte Freigabe für
Commit/Push/CI und später für Website-/Statistikbereitstellung samt
Aktivierung, geschützter Live-Smoke-Test und Betriebsüberwachung. Kein Server,
Produkt, Bestand, Authentifizierungsbereich oder Produktionsschalter wurde
für die lokale Vorbereitung verändert.

### Ergebnis der lokalen Prüfung

- PHP 8.4.22: gezielter Lint bestanden; 95 Prüfungen einschließlich echter
  HTTP-Aufrufe auf `127.0.0.1`, Parallelität und gerenderter Statistikansicht
  bestanden. Temporärer HTTP-Server und künstliche Laufzeitdateien bereinigt.
- Bestehender Statistikbericht: 93 Prüfungen bestanden, dazu bestehender
  Klickstatistik-Test. `pdo_sqlite` ausschließlich für den Testprozess geladen.
- Node: 318 Tests, 316 bestanden, zwei optionale Apache-Tests übersprungen,
  keine Fehler. Shell-/Paket-/Rollbacktests unter lokaler WSL-Bash bestanden.
  Windows-Sandbox-/Git-Bash-Probleme wurden durch passende lokale
  Prozessumgebung behoben, nicht durch Änderungen an fremden Tests. Ergänzende
  Build-/Legal-Bindungstests sichern die standardmäßig gesperrte Aktivierung ab.
- `npm run lint:test`, `npm run build:test`, Testexportprüfung und
  `git diff --check` bestanden; keine Geheimwertmuster in geänderten Dateien.
- Lokaler Chrome-Test: Desktop und 390 × 844 Pixel, keine horizontale
  Überbreite, gleichwertige Buttons, Ablehnung über Neuladen erhalten,
  Footerzugang mit Fokusführung, Zustimmung per Enter, direkter Widerruf,
  korrekte Statusmeldung und erreichbarer Datenschutzentwurf bestanden.
  Externe API-Aufrufe waren per lokaler CSP blockiert; der Vorschau-Endpunkt
  antwortete absichtlich mit 503. Die Zähl-/HTTP-Integration wurde separat
  durch die PHP-Tests mit künstlichen Daten geprüft, nicht gegen IONOS.
- Produktionsbuild mit aktiviertem Flag und ungeprüftem Entwurf brach wie
  vorgesehen vor dem eigentlichen Build ab. Endgültiger Testbuild inklusive
  TypeScript und Export erfolgreich. Temporärer lokaler Vorschauprozess und
  Router entfernt, Testdateien der PHP-Läufe bereinigt.
- Separater lokaler Branch `codex/statistics-daily-visitors`; kein Commit,
  Push, CI-Aufruf, Deployment oder Serverzugriff. `main`, App, Produkte,
  Verkaufsfreigaben und Bestände unverändert.
