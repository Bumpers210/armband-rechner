# AP7.0 – lokale Produktionsbereitschaft

Stand: 2026-08-08

Basis: AP6-Commit `bb4345fbfb26fede5bdf61be0ef6191746a98ef0`

Worktree: `G:\BS-Stein-Hart-ap7`, Branch `feature/shop-ap7`

## Urteil

Das lokale Produktionspaket ist technisch zur gesonderten AP7-
Produktionsfreigabe bereit. AP7, Produktion, Cutover, Push, Merge und
Deployment sind nicht freigegeben und wurden nicht begonnen.

## Geschlossene lokale Blocker

- Bootstrap und Laufzeitvertrag verlangen die exakte Zahlungsarten-Allowlist
  `card`, `paypal`, `klarna`, `sepa_debit`.
- Der öffentliche Produktions-API-Einstieg lädt ausschließlich einen explizit
  gesetzten privaten Bootstrap außerhalb seines Webroots und besitzt keinen
  Testpfad-Fallback.
- Der Deploymentvertrag trennt statische Website, öffentlichen API-Webroot,
  privates Programm, private Konfiguration und Worker. Der endgültige private
  Workerpfad ist `/home/www/carmaja-private-shop/worker.php`; UnixCron startet
  ihn alle fünf Minuten mit `/usr/bin/php8.4`.
- Das Website-Deployment von `main` ist nur noch manuell, SHA-gepinnt,
  umgebungsgebunden und durch Bestätigung sowie die standardmäßig deaktivierte
  Repository-Variable `CARMAJA_PRODUCTION_DEPLOY_ENABLED` gesperrt.
- Produktionshosting, Klicktracking, Statistik und Export enthalten keine
  externen Verkaufsziele oder Produkt-Klickparameter. Der eigene Shop ist der
  einzige vorgesehene Verkaufskanal.
- Website und v2-Publisher verwenden den öffentlichen Produktvertrag v2. Er
  enthält weder `stock` noch externe Verkaufsfelder. Die statische Website
  besitzt keinen Bestandsfallback.
- Das Cutover-Werkzeug ist CLI-only, standardmäßig schreibfrei und verlangt
  ein versioniertes, hashgebundenes Manifest, eine explizite Bestätigung,
  eine private Produktionskonfiguration sowie genau ein autoritatives
  v2-Produkt. Es prüft den serverseitigen `sourceHash` erneut.
- Versand (`deutsche-post-maxibrief`, 270 Cent, EUR), Legal Bundle
  `cmj-production-legal-2026-08-07-v3` und die vier Zahlungsarten sind in
  Deploymentvertrag, Laufzeitvorlage und Cutovermanifest identisch.

## Lokale Nachweise

- PHP 8.4: alle relevanten Dateien ohne Syntaxfehler; 116/116 PHP-Tests.
- Node: 77/84 Tests; ausschließlich die sieben unveränderten, bereits in AP6
  dokumentierten CRLF-/Bash-Basisfehler bleiben bestehen.
- `npm run lint:test`: bestanden.
- `npm run build:test`: bestanden und Testexport verifiziert.
- `npm run build` mit ausschließlich künstlicher v2-Fixture: bestanden und
  Produktionsexport verifiziert.
- `npm audit --omit=dev`: 0 bekannte Schwachstellen.
- Android: `testDebugUnitTest` und `lintDebug` bestanden. Es gibt keine AP7-
  Änderung unter `app/**`; die stabile Beta-Signierung und `assembleBeta`
  bleiben durch die AP6-Abnahme nachgewiesen und wurden ohne erneute
  Secret-Kopie nicht wiederholt.
- AP7-Vertragstests: PHP 7/7 und Node 4/4 bestanden.

## Verbleibende Produktionsgates

1. AP7 ausdrücklich schriftlich freigeben und den lokalen Stand kontrolliert
   prüfen, committen, pushen und über den vorgesehenen Reviewweg übernehmen.
2. Alle parallelen externen Verkaufsangebote nachweislich deaktivieren oder
   löschen; der eigene Shop bleibt der einzige Verkaufskanal.
3. Genau ein reales Produkt aus der autoritativen Produktverwaltung als v2-
   Datensatz auswählen. Produkt-ID, Version, serverseitiger `sourceHash`,
   Preis, EUR, Bilder, `salesEnabled`, bisheriger `stock=1` und Zielbestand
   `onHand=1` müssen bestätigt und in ein separat freigegebenes
   Cutovermanifest eingetragen werden.
4. Produktive MySQL-8-/InnoDB-Datenbank, private Runtime-Secrets und aktive
   TLS-Sitzung nachweisen. Die akzeptierte fehlende CA-/Hostidentitätsprüfung
   bleibt als V1-Restrisiko dokumentiert.
5. Privates API-/Workerpaket und öffentlichen API-Einstieg mit Prüfsummen
   bereitstellen; Stripe-PHP 20.3.0 außerhalb des Webroots installieren und
   den IONOS-SSH-Hostkey außerhalb des Workflows verifizieren.
6. Stripe-Livekonto, Webhook, Payload-Schlüssel, Terms-URL, vier
   Zahlungsarten und alle deaktivierten Checkout-Optionen verifizieren;
   Brevo-Liveabsender und -Zugang getrennt verifizieren.
7. Verschlüsseltes Offsite-Backup samt Schlüsselzugriff und Restore-Dry-Run
   nachweisen. Danach UnixCron `*/5` einrichten, zwei Läufe und Monitoring
   prüfen.
8. Unmittelbar vor dem Cutover Wartungs-/Stopmodus aktivieren, vollständiges
   Produkt- und Commerce-Backup erstellen, Datenbankmigrationen prüfen und
   alte `stock`-Schreibwege sperren.
9. Cutover zuerst im Planmodus prüfen und nur nach separater Bestätigung
   anwenden. Danach genau ein Produkt aktivieren, Smoke-Tests und eine echte
   Testbestellung durchführen und beobachten. Weitere Produkte bleiben bis
   zur gesonderten Freigabe deaktiviert.

Jede Abweichung bei Produktidentität, Hash, Preis, Bestand, Migration,
Zahlungszuordnung, Backup, Worker oder Monitoring stoppt neue Checkouts. Ein
Website-Rollback verändert keine Commerce-Daten.
