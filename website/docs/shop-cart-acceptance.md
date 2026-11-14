# Mengenbestand und Warenkorb – Entwicklungs- und Abnahmestand

## Aktueller Abschluss, Dokumentationsstand 14. November 2026

**Warenkorb-Erweiterung und technischer Produktions-Shopstart abgenommen.**
Die nachfolgenden datierten Entwicklungsstaende und damaligen offenen Gates
bleiben als Historie erhalten. Massgeblich fuer den aktuellen Abschluss ist
der [Shopstart-Nachweis](cart-launch-20261113.md#abschliessende-shop-abnahme-und-betriebshandover):
MySQL-/Mengenimport und Restore bestanden, sechs bestehende Modelle kontrolliert
aktiviert, echte Erstbestellung samt einmaliger Bestandsabbuchung und Mails
nachgewiesen, PC-Verwaltung gelesen und Produktions-App 1.4.0 / Code 8 durch
den manuellen Handyabgleich bestaetigt. Letzter lesender Betriebscheck:
13. November 2026, 19:36 UTC ohne offene Prueffaelle oder Betriebswarnungen.
Der noch ausstehende Versandnachweis der Erstbestellung ist eine Betreiberaufgabe,
kein technischer Veroeffentlichungsblocker. Testprodukte, Bestaende und private
Konfigurationen wurden durch diesen Abschluss nicht veraendert.

Die Shop-Abnahmedokumentation vom 13. November ist seit
[PR #106](https://github.com/Bumpers210/armband-rechner/pull/106) in main
`3a605f9f2568a266b4fcc8cf64d0b436196c7351` integriert;
[Paket-CI 43490737279](https://github.com/Bumpers210/armband-rechner/actions/runs/43490737279)
success. Die Produktions-App **1.4.0 / Code 8** ist als stabiles
[GitHub-Release product-management-v1.4.0](https://github.com/Bumpers210/armband-rechner/releases/tag/product-management-v1.4.0)
mit signierter APK und SHA256SUMS.txt veroeffentlicht. Release-Tag und APK
stammen aus `261564aef3f62be759bc3efc0251b019a9bdd354`; die App-/Build-Eingaben
sind gegen den aktuellen main-Stand identisch. Kein neuer Build oder
Play-Store-Release. Details und getrennte Nachweiszeitpunkte:
[Release- und Abschlussnachtrag](cart-launch-20261113.md#main-und-app-release-stand-vom-14-november-2026).

Aenderungsvermerk 14. November: GitHub-/main-/Releasebelege lesend bestaetigt
und Dokumentation aktualisiert; keine erneute Produktionspruefung oder
Komplettregression, kein Commit, Push, Merge oder Deployment. Wiederkehrende
Betriebspruefungen und die dokumentierten Restrisiken bleiben bestehen.

Historischer Ausgangsstand: 25. Oktober 2026, damals in Umsetzung und nicht
vollstaendig abgenommen. Spaetere Belege unten und der aktuelle Startnachweis
schliessen die damaligen Wartepunkte; Sicherheits- und Wiederanlaufregeln bleiben.

Isolierter Worktree: `G:\BS-Stein-Hart-shop-cart`, Branch
`codex/shop-inventory-cart`, Basis `68a308a3cbbd9d6ed0d496d115b2582ac7419ef5`.
Keine Änderungen an Produktion, vorhandenen Bestellungen oder anderen Worktrees.
Bis zum 8. Oktober keine Commits, Pushes oder Deployments. Am 9. Oktober ist
ein Entwicklungscheckpoint mit Push ausschliesslich dieses Branches und ein
manueller GitHub-Beta-Build beauftragt. Keine Merge-/Deploymentfreigabe und
keine Freigabe des Warenkorb-Kaufwegs.

## 1. Implementierter Zielvertrag

- Produktmodell 4, Produkt-API `/v5`, öffentliche Projektion 4, Shop `/shop/v3`.
- Produktion-App 1.4.0 / Code 8; aktuelle installierte Testbeta 1.4.0-beta.4 / Code 13 (zuvor beta.3 / Code 12, beta.2 / Code 11 und beta.1 / Code 10). Pakete und Zertifikatsvertrag unverändert. Manuelle Teilnachweise sind ihrer jeweiligen Beta zugeordnet; Beta.3-Publishbestaetigung siehe 3.57, Beta.4-Freigabe-aus-Roundtrip siehe 3.64. Keine pauschale Geraete-Gesamtabnahme.
- `inventoryCartEnabled=false` und `productApiV5WritesEnabled=false` als Standard; keine automatische Aktivierung oder Migration.
- Mengenbestand, getrennte authentifizierte `targetOnHand`-Vorgänge, Inventarversion, Idempotenz und Audit. Keine Bestandsmutation beim Produktspeichern.
- Gründe `initial_count`, `receive_stock`, `mark_unsellable`, `release_return`, `correction`; `shop_sale` ausschließlich bei erfolgreicher Zahlungsfinalisierung.
- Ungezählte Bestände bleiben nicht kaufbar. Vormerkungen für neue Anfangsbestände werden bei der Commerce-Projektion genau einmal angewendet.
- Serverwarenkorb: maximal 10 Armbaender insgesamt je Bestellung (Betreiberfreigabe 25.10.2026, Nachweis 3.70), summiert ueber alle Positionen; technisches Formatlimit weiterhin 100 unterschiedliche Positionen. Zusammenführung gleicher Produkte, Versionierung, keine Reservierung vor Checkout.
- Gemeinsame Reservierung und Finalisierung in kurzen Transaktionen, Produktsperren in ID-Reihenfolge, Netzwerkaufrufe danach.
- Einmalig 270 Cent Versand; EUR; Karte/Wallets, Klarna, SEPA; PayPal deaktiviert. Technische Gesamtbetragsgrenze 99.999.999 Cent.
- SEPA hält alle Mengen; Ablauf/Rückkehrseite gibt eine laufende Zahlung nicht frei. Sichere Session-Erstellungsfehler geben alle Mengen atomar frei; unklare Ergebnisse bleiben prüfpflichtig.
- Mengen- und Hinweis-Snapshots, Teilwiderruf, echte Refund-Objekte, Teilrefund-Summen, geprüfte positionsbezogene Wiedereinlagerung. Historische Snapshots unverändert.
- Hinweise nur bei neuen serverseitigen Anlagen; bestehende registrierte Entwürfe/Produkte erhalten keine nachträgliche Zuordnung. Keine Clientänderung und keine Kopie in Beschreibungstexte.
- Live-Daten `no-store`, exakte Origin, CSRF, Idempotency-Key, JSON maximal 64 KiB. Warenkorbanfragen: 60/min je Sitzung und 120/min je IP; bestehendes Checkout-Tageslimit zusätzlich.
- Worker bereinigt abgelaufene Quotes, Warenkorboperationen und Ratelimitfenster; niemals Zahlungen oder Reservierungen aufgrund des Sitzungsablaufs.

## 2. Lokal durchgeführte Prüfungen

| Prüfung | Nachweis |
| --- | --- |
| Android `testDebugUnitTest`, `lintDebug` | bestanden, einschließlich erneuter Prüfung nach den App-Änderungen |
| Android `assembleBeta` | am 8. Oktober bestanden, stabile Beta-Signatur und Test-API am APK geprueft; siehe 3.12 |
| PHP 8.4.23, geänderte PHP-Dateien | Lint bestanden |
| PHP-Regression | am 9. Oktober 2026 alle 21 relevanten Suites bestanden; 37 geaenderte PHP-Dateien gelintet; fruehere gezielte Transaktions- und Schema-Vergleichsnachweise bleiben erhalten |
| Website-Lint, TypeScript, Testbuild und Export | bestanden |
| Node-Regression | am 8. Oktober 2026 179/179 bestanden, einschliesslich Modell-4-Publisher, sicherer Konfiguration, Crashrunner, privater Stripe-Eingabe, TLS-pflichtigem Backupadapter und privater Beta-Signierung |
| Shell-Integration | bestanden; PHP-Klickstatistik zusätzlich direkt mit PHP 8.4 bestanden |
| `git diff --check` | bestanden |

Diese lokalen Tests sind **kein** praktischer MySQL-, Stripe-, Brevo- oder Geräte-E2E-Nachweis.
Historische AP2-Speichermodelle testen weiterhin ihre damaligen Einzelkaufverträge, nicht den neuen Mengenbetrieb.

## 3. Isolierter MySQL-Nachweis

Adapter: `website/scripts/cart-mysql-integration.php`.
Die vorbereitete Funktions- und Backup-/Restore-Pruefreihe wurde am
7. Oktober 2026 nach den unten dokumentierten Korrekturen **dreimal vollstaendig
bestanden**. Das ist eine Teilabnahme dieser Pruefreihe, keine Shop-Gesamtabnahme.
Explizite, neue Konfiguration außerhalb aller Webroots:
`/home/www/carmaja-private-test/shop-cart-test.json`, Modus `0600`.
Die Konfiguration wurde am 7. Oktober 2026 durch das Eingabeskript privat
bereitgestellt und mit Modus 0600, gueltigem JSON und getrennten autorisierten
Zielen geprueft. Keine bestehenden Konfigurationen oder fremden Secrets wurden
durchsucht. Die Betreiberfreigabe zur Bereinigung der 29 vorhandenen,
ausschliesslich entbehrlichen Testtabellen in `dbs15962244` lag ausdruecklich vor;
die Restore-Datenbank `dbs15962566` war bereits leer.

Seit 7. Oktober 2026: `website/scripts/configure-cart-test.ps1` fragt nur Host,
Benutzer und Passwort der bestaetigten MySQL-Testziele `dbs15962244` (Quelle)
und `dbs15962566` (Restore) im Windows-Eingabefenster ab. Datenbanknamen sind
fest vorgegeben. Zwischenablage-Einfuegen und normales Strg+V sind moeglich;
Passwoerter bleiben verdeckt. Keine lokalen Credential-Dateien, keine Secrets
in Befehlsargumenten oder Logs. Uebertragung nur per bekanntem SSH-Testalias,
Public Key und geprueftem Hostkey, als UTF-8-JSON ueber stdin.
Der mitgelieferte PHP-Installer laeuft nur im Speicher und legt die private
Konfiguration atomar mit 0600 an; bestehende Dateien werden nicht ersetzt.
Das Formular bestaetigt die isolierten Testziele und das TLS-Restrisiko.
Es startet keine Datenbankpruefung oder Migration.
Eingabeskript-Selbsttest und zwei Node-Sicherheitsvertraege bestanden.
Installer mit kuenstlichen Werten am 7. Oktober 2026 auf IONOS geprueft:
neun Pruefungen einschliesslich 0600, unveraenderter JSON-/Passwortuebernahme,
Ueberschreibsperre und Cleanup bestanden. Keine Datenbank verbunden und keine
echte Zugangskonfiguration durch diesen kuenstlichen Nachweis angelegt.

Benötigte Struktur (Werte nur privat eintragen, niemals in Chat/Git):

```json
{
  "testOnly": true,
  "requireTls": true,
  "acceptUnverifiedTls": true,
  "tlsCaPath": "",
  "source": {"host": "TESTHOST", "port": 3306, "database": "LEERE_TESTDATENBANK_1", "username": "TESTBENUTZER_1", "password": "NUR_PRIVAT"},
  "restore": {"host": "TESTHOST", "port": 3306, "database": "LEERE_TESTDATENBANK_2", "username": "TESTBENUTZER_2", "password": "NUR_PRIVAT"}
}
```

`testOnly=true` bestätigt ausschließlich zwei isolierte künstliche Testziele.
Unterschiedliche Datenbanken und Benutzer, beide vollständig leer, PHP 8.4,
MySQL 8, InnoDB und aktive TLS-Sitzungen müssen vor jeder Mutation bestehen.
Fehlende CA-/Hostidentitätsprüfung bleibt nur bei expliziter Restrisikoannahme erlaubt.
Der Adapter verlangt MySQL-8-kompatible `mysqldump`-/`mysql`-Clients mit TLS-Optionen.
IONOS stellt MariaDB-10.11.18-Clients bereit, welche `ssl-mode=REQUIRED` nicht
unterstuetzen. Eine rein lesende Pruefung mit Zertifikatsverifikation war fuer
beide Testziele ebenfalls nicht erfolgreich. Es wurde kein unverschluesselter
Fallback verwendet. Stattdessen wurden die offiziellen Oracle-MySQL-8.0.46-
Debian-12-Clientpakete gegen ihre SHA-256-Werte im HTTPS-Paketindex geprueft,
ausschliesslich in einem neuen privaten Testunterordner entpackt und ueber
`--mysql-client-directory` benutzt. Keine Systeminstallation und keine
Produktionsbereitstellung. Auch die CLI-Sitzungen wurden auf befuellte
`Ssl_cipher` und `Ssl_version` geprueft; `ssl-mode=REQUIRED` blieb zwingend.
CA-/Hostidentitaetsverifikation bleibt das bestaetigte Test-Restrisiko.

Bestandene Faelle: Vorwärtsmigration/Journaling und Wiederholung, Mengenbestand,
überlappende Warenkörbe ohne Teilreservierung, zehn echte parallele Clients auf
das letzte Stück, SEPA-Halten, atomare und doppelte Finalisierung, Teilrefund,
geprüfte Rückgabe, Backup-/Restore-Struktur- und Inhaltsvergleich sowie Cleanup.
Die erste Praxispruefung fand einen offenen SQL-Cursor bei dynamischen
Migrationsanweisungen. Der Vorwaertsmigrationslaeufer schliesst diese Cursor
nun vor der naechsten Anweisung. Die Konkurrenzpruefung reproduzierte zudem
einen MySQL-Deadlock (1213). Warenkorbtransaktionen wiederholen deshalb nur
vollstaendig zurueckgerollte Deadlocks/Lock-Timeouts (1213/1205), maximal vier
Versuche mit begrenztem Jitter; verschachtelte Arbeit wird nur am aeusseren
Transaktionsrand wiederholt. Keine Wiederholung von Fachfehlern oder
Provideraufrufen. Acht lokale Transaktionsassertionen bestanden.

Beim Restore unterschieden sich gleichwertige SHOW-CREATE-Darstellungen:
ein redundantes `CHARACTER SET` neben einer bereits charsetbestimmenden
`COLLATE`-Angabe. Nur diese Darstellung und der laufende AUTO_INCREMENT-Zaehler
werden normalisiert. Sieben Vergleichsassertionen belegen, dass veraenderte
Kollationen, Spaltentypen, Defaults, Indizes und Constraints weiterhin erkannt
werden. In allen drei Abschlusslaeufen waren Struktur, Zeilen und
deterministische Inhaltspruefsummen danach gleich.

Nach jedem Lauf wurden beide Testziele wieder vollstaendig geleert. Das
isolierte Testprogramm, die temporaeren Clientpakete, Dumps, Controller und
lokalen Uebertragungsdateien wurden nach den Abschlusslaeufen entfernt.
Keine Webroots, echten Testprodukte oder Produktionsressourcen wurden veraendert.
Die Konfiguration bleibt bis zum Abschluss weiterer E2E-Läufe privat erhalten;
bei vollständigem Testabschluss wird sie zusammen mit dem Testprogramm entfernt.

### 3.1 Zusaetzlicher Crash-/Timeoutnachweis

`website/scripts/cart-mysql-faults.php` wurde am 7. Oktober 2026 ausschliesslich
in einem verifizierten neuen privaten IONOS-Testunterordner ausgefuehrt.
Vor jedem Lauf waren die zwei ausdruecklich autorisierten Ziele leer, mit
MySQL 8/InnoDB und aktiver TLS-Sitzung. Der Runner injiziert Fehler nur in
Testprozessen; die produktive Fachlogik besitzt keine Crash-Hooks.

| Fall | Ergebnis |
| --- | --- |
| SIGKILL nach erster Reservierungsanlage, vor Commit | Keine Checkout-/Teilreservierung; vollstaendiger Fingerprint unveraendert |
| SIGKILL nach Reservierungscommit | Idempotenter Wiederanlauf ohne weitere Reservierung; unklarer Stripe-Ausgang haelt alle Mengen und bleibt manual_review |
| Sicher nicht erstellte Session | Checkout failed und alle Reservierungen released gemeinsam |
| Injizierter Stripe-Transporttimeout ueber carmaja_cart_start | Provideraufruf ohne laufende Transaktion; alle Mengen bleiben blockiert. Kein echter Netzwerk- oder Zahlungstest |
| SIGKILL nach erster Bestandsabbuchung, vor Finalisierungscommit | Bestand, Bestellung, Sequenz, Reservierungen und Outboxen vollstaendig zurueckgerollt |
| Erzwungener Inventar-Row-Lock-Timeout | Bounded Retry beendet mit MySQL 1205; vollstaendiger Fingerprint unveraendert, keine offene Transaktion; unter 20 Sekunden |
| SIGKILL nach Bestellcommit | Replay erzeugt keine zweite Bestellung, Abbuchung, Bestellnummer oder Outbox |
| Bewusst invertierte Row-Locks zweier Testprozesse | Realer Deadlock mit Wiederholung des Opfers; genau zwei erfolgreiche Commits, keine doppelte Mutation |
| SIGKILL eines Workers nach Lease-Commit | Uebernahme erst nach Ablauf; alter Besitzer kann neue Lease nicht freigeben |

Die acht Datenbank-/Crashfaelle bestanden in drei Laeufen; der zusaetzliche
injizierte Provider-Timeout bestand im letzten Lauf. Backup/Restore wurde in
diesen Laeufen ausdruecklich nicht wiederholt; der Nachweis aus Abschnitt 3
bleibt separat bestehen. Nach jedem Lauf waren beide Datenbanken leer.
Private Testprogramme, Prozesscontroller, Laufmarker und lokale Transferarchive
sind entfernt. Bestehende Test-API, Testwebsite und reale Testprodukte blieben
unveraendert. Die explizite MySQL-Konfiguration bleibt fuer den folgenden
Zahlungs-E2E mit 0600 erhalten.

### 3.2 Vorbereitung echter Stripe-Testzahlungen

Die neue private Datei `/home/www/carmaja-private-test/shop-cart-stripe-test.json`
wurde nach der Benutzereingabe am 7. Oktober 2026 erfolgreich geprueft:
kanonischer Pfad, kein Symlink, Modus 0600, gueltiger Testschluessel und
32-Byte-Payloadschluessel. Ein echter lesender Stripe-Aufruf lieferte HTTP 200
und `livemode=false`; keine Geheimwerte oder Kontodaten wurden ausgegeben.
Das SDK fehlte am alten Testpfad
`/home/www/carmaja-private-test/vendor/autoload.php`.
Keine bestehenden Secret-Stores oder Runtime-Konfigurationen wurden durchsucht.

`website/scripts/configure-cart-stripe-test.ps1` fragt zunaechst nur den
Sandbox-Secret-Key ab, verdeckt und mit Strg+V beziehungsweise Einfuegebutton.
Der Installer akzeptiert ausschliesslich sk_test_, uebertraegt Werte nur auf
SSH-stdin und legt eine neue Datei atomar mit 0600 an; keine lokale Credential-Datei.
Der Payload-Aufbewahrungsschluessel wird serverseitig zufaellig erzeugt.
Bestehende Konfigurationen werden nicht ueberschrieben. Noch kein Webhook,
Runtimewechsel, SDK-Deployment oder Checkout wird dadurch ausgeloest.

Eingabe-Selbsttest, PHP-/Node-Vertragstests und zehn Installerpruefungen mit
kuenstlichem Schluessel auf IONOS bestanden einschliesslich Dateirechten,
Ueberschreibschutz und Cleanup. Die echte lesende Providerpruefung ist nun
bestanden. Ein simulierter Timeout ersetzt weiterhin keine Stripe-Testzahlung
und keinen von Stripe zugestellten Webhook.

### 3.3 Echter HTTP-Warenkorb und vorbereitete Sandboxzahlung

`website/scripts/cart-stripe-sandbox.php` verwendet nur die zwei fest
freigegebenen Testziele und die neue private Stripe-Datei. Vor Initialisierung
waren beide Datenbanken leer; MySQL 8/InnoDB und aktive TLS-Sitzungen wurden
erneut bestaetigt. Fehlende CA-/Hostpruefung bleibt das akzeptierte Test-Restrisiko.

Programme und offizielles Stripe-SDK 20.3.0 (Release-Commit
`266f0b05890172184cca66a0688abaf1a96b08d8`) liegen in einem neuen privaten
`cart-e2e-*`-Unterordner. Die bestehende API-Runtime, der Einstieg und die
Rewrite-Datei, Testwebsite und reale Produkte bleiben unveraendert. Ein eigener
temporaerer Unterordner im Test-API-Webroot enthaelt nur einen geheimwertfreien
PHP-Einstieg. Runtime und Cookie-Datei haben 0600, die Testlaufzeit endet nach
24 Stunden. Publisher und Produkt-Schreibwege sind dort deaktiviert.
Ein ausschliesslich kuenstlicher Legal-Snapshot ist fuer diesen Test technisch
nutzbar; er besitzt keine externe Rechtsfreigabe.

Am 7. Oktober 2026 bestanden ueber echte HTTPS-Anfragen:

- CORS-Preflight mit PUT/Idempotency-Key, falsche Origin abgelehnt.
- Shopsitzung, CSRF und no-store; ungueltiger CSRF-Token abgelehnt.
- Live-Preis/Mengenverfuegbarkeit, Warenkorbersatz und idempotenter Replay.
- Veraltete Warenkorbversion mit 409 abgelehnt.
- Quote, Checkout-Start, Checkout-Idempotenz und geschuetzter Statusabruf.
- Gemeinsame Reservierung: zwei Stueck A und ein Stueck B.
- Echte Stripe-Session: Sandbox, zwei Positionen/Mengen 2 und 1, 77,70 EUR,
  Versand genau einmal 2,70 EUR; Karte/Klarna/SEPA, kein PayPal.
- Temporaerer Stripe-Webhook mit gepinnter Version und neun Ereignissen.
- Unsignierte Anfrage mit 400, lokal signiertes unbekanntes Event mit 204;
  keine Inboxmutation. Die echte Stripe-Zustellung ist separat in Abschnitt 3.4 nachgewiesen.

13 lokale Isolationsassertionen und PHP-Lint bestanden. Der erste Apache-403
lag an umask-reduzierten Rechten des neuen oeffentlichen Ordners; nur dieser
Ordner wurde auf 0755 gesetzt. Keine bestehenden Verzeichnisrechte geaendert.

**Offen:** weitere Zahlungsmethoden und Brevo. Der gesamte Website-/Geraete-E2E bleibt
separat offen. Stage, Endpoint und kuenstliche Daten bleiben ausschliesslich
fuer den laufenden Zahlungsnachweis erhalten. Nach Abschluss: eigene Sessions
beenden, Webhook/Endpunkt/Testtabellen entfernen, bestehende API-Hashes pruefen,
private Stage und Eingabekonfigurationen bereinigen. Noch keine Gesamtabnahme.

### 3.4 Echte Warenkorb-Kartenzahlung

Am 7. Oktober 2026 ist die vom Nutzer abgeschlossene Sandbox-Kartenzahlung
praktisch bestanden: Stripe bestaetigt `livemode=false`, Session `complete`,
Zahlung `paid`, 7770 Cent und akzeptierte AGB. Der echte signierte
Abschluss-Webhook wurde vor der erfolgreichen Antwort persistiert und vom
Worker verarbeitet. Session-, Betrags-, Waehrungs-, Legal- und Snapshotbindung
wurden geprueft. Genau eine Bestellung enthaelt zwei Positionen mit Mengen
2 und 1. Die kuenstlichen Bestaende wurden von 30 auf 28 beziehungsweise 29
reduziert; beide Reservierungen sind konvertiert und nicht mehr blockierend.

Der erste Workerabgleich erkannte eine fehlende Versandadresse und hielt die
Zahlung sicher in einem Reviewcase, ohne Bestellung oder Bestandsabbuchung.
Die Ursache war die Verwendung des alten Stripe-Sessionfelds. Der Worker
verwendet nun vorrangig `collected_information.shipping_details`; der alte
Pfad bleibt nur fuer historische Sessions als Fallback bestehen. Keine
Ersatzadresse aus Rechnungsdaten und kein Maskieren ungueltiger aktueller
Adressdaten. Sechs gezielte Mappingassertionen bestanden. Nach korrigiertem
Abgleich wurde die Zahlung genau einmal finalisiert und der kuenstliche
Reviewcase ueber die auditierte Statusfolge geschlossen.
Referenz: [Stripe: Checkout collected information](https://docs.stripe.com/changelog/acacia/2025-02-24/checkout-sessions-collected-info).

Doppelte Inboxpersistierung sowie zweimalige Verarbeitung desselben echten
Events veraenderten den vollstaendigen Datenbankfingerprint nicht. Der
Testadapter bildet binaere SQL-Werte fuer diesen Vergleich verlustfrei ab;
sechs Assertions pruefen Byteabweichungen sowie NULL-/Typunterschiede.
Metadaten-Outbox und Bestell-Mail-Outbox wurden angelegt; ein tatsaechlicher
Brevo-Versand ist damit nicht nachgewiesen.

Abschliessende lokale Regression nach diesen Korrekturen: 19 PHP-Suites,
PHP-Lint der betroffenen Dateien, 177/177 Node-Tests und `git diff --check`
bestanden. Der private IONOS-Testcontroller ist ebenfalls syntaxgeprueft.

### 3.5 Echte Warenkorb-Klarna-Zahlung

Am 7. Oktober 2026 ist auch Klarna praktisch bestanden: 7770 Cent,
`livemode=false`, bestaetigter Zahlungserfolg und akzeptierte AGB. Der echte
signierte Abschluss-Webhook wurde persistiert und verarbeitet. Genau eine
weitere Bestellung enthaelt die zwei kuenstlichen Positionen mit Mengen 2
und 1; Bestand 28 auf 26 beziehungsweise 29 auf 28. Beide Reservierungen
sind konvertiert und nicht mehr blockierend. Doppelte Inboxpersistierung
und zweimalige Verarbeitung desselben Events lassen den vollstaendigen
Datenbankfingerprint unveraendert. Brevo-Versand bleibt separat offen.

### 3.6 Echte Warenkorb-SEPA-Einreichung

Am 7. Oktober 2026 ist der echte Processing-Zwischenstand nach Einreichung
mit der verzoegerten Stripe-Test-IBAN technisch geprueft: Session `complete`,
`payment_status=unpaid`, PaymentIntent `processing`, 7770 Cent,
`livemode=false` und akzeptierte AGB. Der signierte Abschluss-Webhook wurde
persistiert und verarbeitet. Das lokale Payment ist `processing/verified`,
ohne Orderzuordnung. Fuer diesen Checkout existiert keine Bestellung.
Die zwei Reservierungen halten Mengen 2 und 1; Bestand bleibt 26 und 28.
Ein geheimwertfreier Pruefmarker liegt ausschliesslich im privaten Testordner
und wird beim Abschluss bereinigt. Die erste Diagnose musste den
PaymentIntent separat abrufen, da die Session ihn nicht expandiert liefert;
keine Fachlogikaenderung und keine Fehlbuchung.

Der anschliessende asynchrone Erfolg ist ebenfalls praktisch bestanden:
echter signierter `checkout.session.async_payment_succeeded`-Webhook mit
gepinntem API-Vertrag, Sessionbindung, `paid` und 7770 Cent. Erst danach
wurde genau eine weitere Bestellung mit Mengen 2 und 1 erzeugt; Bestand
26 auf 24 beziehungsweise 28 auf 27. Reservierungen konvertiert,
AGB-/Legalbindung und Zahlungsart geprueft. Zweimaliger Replay des frueheren
Abschlussereignisses nach dem Erfolg sowie zweimaliger Replay des Async-
Erfolgsereignisses liessen den Datenbankfingerprint unveraendert.

Eine erfolgreiche Browserrueckkehr gilt nicht als Zahlungserfolg; der
separat erfasste Zwischenstand bestaetigt dies. Ein echter Ablauf waehrend
Processing ist damit noch nicht nachgewiesen. 19 lokale Sandbox-Isolationsassertionen, Controller-Lint
lokal und auf IONOS sowie `git diff --check` bestanden. Der Testcontroller
wurde nur innerhalb der eigenen privaten Stage hashgeprueft atomar erneuert.
Nur eigene Testressourcen bleiben waehrend dieser laufenden Pruefreihe
erhalten; bestehende API, Testwebsite, reale Produkte und Produktion bleiben
unveraendert. Die Erweiterung bleibt insgesamt nicht abgenommen.

### 3.7 Echter verzoegerter SEPA-Fehler und Wiederanlauf

Am 7. Oktober 2026 wurde auch der separate Fehlerfall eingereicht und sein
Processing-Zwischenstand privat nachgewiesen: keine Bestellung, kein
Bestandsabzug, Mengen 2 und 1 blockierend. Der Freigabeversuch allein wegen
Sessionablaufs blieb ohne Mutation. Das ist kein Nachweis eines tatsaechlich
abgelaufenen Stripe-Checkouts waehrend Processing.

Der echte signierte `checkout.session.async_payment_failed`-Webhook ist
persistiert und verarbeitet. Der aktuelle PaymentIntent meldet
`requires_payment_method`. Stripe entfernt dabei die aktuelle Zahlungsart;
die gepruefte Zahlungsart steht im `last_payment_error.payment_method`.
Die zunaechst entstandene Zuordnungsluecke fuehrte sicher zu einem Reviewcase
mit weiterhin blockierten Mengen, nicht zu einer Fehlbestellung.

Gezielt korrigiert: Fehlerfallback nur bei fehlender aktueller Zahlungsart
und `requires_payment_method`, keine Ueberdeckung ungueltiger aktueller
Evidenz, kein Fallback bei Erfolg oder Processing. Ein verspaetetes
Abschlussereignis folgt dem aktuellen finalen Stripe-Fehlerzustand.
Erneute Fehlerverarbeitung veraendert keinen Freigabezeitpunkt.
20 Mapping-/Routingassertionen bestanden.

Der normale Worker-/Event-Wiederanlauf ist mit dem echten Ereignis bestanden:
Payment `failed/verified`, Checkout `failed`, keine Bestellung, Bestand
unveraendert 24 beziehungsweise 27, beide Reservierungen `released` und
nicht blockierend. Echte Abschluss- und Async-Fehlerereignisse wurden jeweils
zweimal wiederholt; Deduplizierung und vollstaendiger Datenbankfingerprint
blieben unveraendert. Nur der zugehoerige kuenstliche Mapping-Reviewcase wurde
nach erfolgreicher Verifikation ueber den normalen Auditdienst geschlossen.
Gepruefte PHP-Programme wurden ausschliesslich in der eigenen privaten
Teststage hashgebunden, gelintet und atomar erneuert. Kein Produktionsdeploy.

Anschliessende Regression: 19 PHP-Testreihen und 177 Node-Tests bestanden.
Alle vier echten bisherigen Zahlungsfaelle erneut einschliesslich Replay
bestanden. Der Testbericht prueft den kumulierten Bestand aller kuenstlichen
Bestellpositionen; die alten punktuellen Bestandswerte nach dem ersten Kauf
sind nach weiteren Kaeufen kein gueltiger Vergleich mehr. Keine offene
Reviewcase, Restore-Testziel leer, bestehender oeffentlicher Test-API-Einstieg
hashgleich. Die eigenen Sandbox-Artefakte bleiben nur fuer die naechsten
Teilrefund-/E2E-Nachweise erhalten; vollstaendiger Cleanup bleibt ein Pflichtgate.

### 3.8 Echte Sandbox-Teilerstattung

Die Betreiberin hat am 7. Oktober 2026 im Stripe-Sandbox-Dashboard 2800 Cent
der ersten Warenkorb-Kartenzahlung ueber 7770 Cent erstattet. Beide echten
signierten Ereignistypen `charge.refunded` und `refund.updated` wurden vor
der Verarbeitung persistiert und anschliessend vom normalen Worker verarbeitet.
Genau ein Refundnachweis mit 2800 Cent/EUR und `succeeded`; Payment weiterhin
`succeeded`, Refundaggregation `partial`, Bestellung weiterhin `confirmed`.
Admin-Domaenenabfrage zeigt zwei Positionen, 7770 Cent Gesamt und 2800 Cent
erstattet. Keine automatische Wiedereinlagerung: Bestand unveraendert 24/27,
keine blockierenden Reservierungen. Doppelte Inboxpersistierung und jeweils
zweimaliger Replay beider Erstattungsereignisse lassen den vollstaendigen
Datenbankfingerprint unveraendert. Es wurden keine Refunds per API ausgeloest.

Eine ausschliesslich testseitige Zusatzannahme wurde korrigiert:
Stripe-Refundobjekte besitzen kein `livemode`-Feld. Der Nachweis verwendet
den expliziten Testschluessel, den gebundenen aktuellen PaymentIntent mit
`livemode=false` und die echten Test-Webhooks; Betrag, Waehrung und Bindung
bleiben zwingend geprueft. Keine Aenderung der Refund-Fachlogik.
Referenz: [Stripe-Refundobjekt](https://docs.stripe.com/api/refunds/object).

### 3.9 Positionsbezogener HTTP-Widerruf und kuenstliche Wiedereinlagerung

Mit der kuenstlichen Kartentestbestellung ist der Live-HTTP-Ablauf der
isolierten Test-API bestanden: Identifikation/Vorschau liefert beide
Positionen, verbleibt `awaiting_confirmation` und erzeugt keine Empfangsmail.
Erst die ausdrueckliche Bestaetigung speichert `submitted`, genau eine
ausgewaehlte Position mit Menge 1 und einen Receipt-Outboxeintrag.
Wiederholung der Bestaetigung wird mit 409 abgelehnt, ohne doppelte Position
oder Empfangsmail. Der Bestand bleibt unveraendert.

Ein zweiter Mengenanspruch uebersteigt die urspruenglich bestellte Menge:
Die bestaetigte Erklaerung bleibt vollstaendig gespeichert und bekommt
`manual_review`, keine gueltige Rueckgabeposition und keine Bestandsaenderung.
Der eigene kuenstliche Uebermengen-Reviewcase wurde ueber den normalen
Auditdienst untersucht, aufgeloest und geschlossen.

Separat nachgestellte, ausdruecklich gepruefte kuenstliche Rueckgabe:
ohne `receivedAndChecked=true` abgelehnt; genau ein Stueck der ersten
Position wurde wiedereingelagert (24 auf 25, Version 4 auf 5), zweites
Produkt unveraendert 27/Version 4. Idempotenter Replay unveraendert,
ueberhoehte sowie veraltete Mengen-/Versionsanfragen abgelehnt und vollstaendig
zurueckgerollt. Bestellung und Teilrefundstatus bleiben unveraendert.
Dies ist kein Nachweis einer real eingegangenen physischen Rueckgabe.

Danach sind Teilerstattungs- und Zahlungs-Replays erneut gegen die gemeinsame
Verkaufs-/Rueckgabehistorie geprueft; keine Wiederabbuchung oder Wiedereinlagerung
durch Webhooks. Lokale Regression: 20 PHP-Testreihen, 178 Node-Tests bestanden;
29 Sandbox-Isolationsassertionen und PHP-Lint bestanden.

### 3.10 Private Brevo-Konfiguration und praktischer Mail-Teilnachweis

Der feste private Pfad `shop-cart-brevo-test.json` wurde am 8. Oktober 2026
mit 0600, vollstaendigen Feldern, Testbindung und ausdruecklich erlaubtem
Testempfaenger geprueft. Keine anderen Secrets wurden durchsucht.
`configure-cart-brevo-test.ps1` fragt ausschliesslich einen Test-API-Key,
verifizierten Absender, Absendernamen und eine ausdruecklich erlaubte eigene
Testempfaengeradresse ab. Keine lokale Credential-Datei; nur SSH-stdin mit
Hostkeypruefung und Public-Key-Zugang. Installer legt ausschliesslich die
neue private Datei mit 0600 an, atomar und ohne Ueberschreiben.
Windows-Eingabe-/PHP-Negativtests sowie ein kuenstlicher IONOS-PHP-8.4-
Installations-/Nichtueberschreib-/Cleanupnachweis bestanden.
`cart-brevo-sandbox.php` hat sechs echte Testmails ueber den normalen AP5-Worker
versendet; Brevo hat jeweils eine Nachrichten-ID geliefert. Ausschliesslich
der privat bestaetigte Testempfaenger wurde angeschrieben. Am 8. Oktober 2026
hat die Betreiberin den Empfang aller sechs Mails ausdruecklich bestaetigt.
Keine Empfaengeradresse oder vertraulichen Mailinhalte wurden dokumentiert.

Die drei Bestellmails des frueheren Stripe-Harness besitzen eine kuenstliche
Legal-ID ausserhalb des Mailvertrags und wurden korrekt vor jedem Provideraufruf
abgelehnt. Ihre Checkout-, Bestell- und Mail-Snapshots blieben unveraendert.
Separat angelegte Rendering-Fixtures mit gueltigem Test-ID-Format prueften
Bestell-, Betreiber- und Versandmail; die zwei ausdruecklich eingereichten
kuenstlichen Widerrufe prueften die Empfangsmail. Mengen 2/1, Gesamt 77,70 EUR,
einmaliger Versand 2,70 EUR und Produkthinweise wurden vor dem Versand geprueft.
Das ersetzt **nicht** die noch offene originale Zahlung-bis-Bestellmail-E2E-Kette.
Der Testcontroller verwendet fuer zukuenftige neue Stages eine vertragsgueltige
Test-Legal-ID. Die Verfuegbarkeit der zugehoerigen Legal-Archivseite ist damit
ebenfalls noch nicht nachgewiesen. Kein kuenstliches Bundle erhaelt Rechtsfreigabe.

Sent-Replay ohne zweiten Versand bestanden. Injizierter HTTP-429 folgt Backoff
und erreicht beim nachgestellten Wiederanlauf `sent`; injizierter Transport-
Unklarfall bleibt `delivery_unknown` und wird nicht automatisch erneut gesendet.
Ausdruecklicher Neuversand ueber den normalen Auditdienst erhielt einen neuen
Deduplizierungsschluessel und wurde von Brevo angenommen, waehrend der alte
Unklarfall erhalten blieb. Der Akteur ist ausschliesslich ein deaktivierter
kuenstlicher Testakteur, kein Nachweis eines echten Admin-Browserlogins.
Workerlaeufe 578 ms beziehungsweise 90 ms, keine Bestandsaenderung.
Keine echte Providerstoerung und kein UnixCron-Nachweis durch diesen Test.

Regression: 21 PHP-Suites, 20 neue Brevo-Assertions, PHP-Lint und 178 Node-Tests
bestanden. Keine App-Funktionsaenderung in diesem Teilschritt.

### 3.11 Cleanup der ersten Zahlungs-/Mail-Pruefreihe

Am 8. Oktober 2026 sind die eigenen Stripe-Test-Sessions geprueft, der eigene
URL-gebundene Testwebhook und der temporaere oeffentliche API-Unterordner entfernt.
Beide isolierten MySQL-Testdatenbanken sind nach Entfernung der erlaubten
Testtabellen wieder vollstaendig leer. Die exakt verifizierte private Stage
einschliesslich SDK, Archiven, Runtime, Cookies und Pruefmarkern sowie die drei
expliziten MySQL-/Stripe-/Brevo-Eingabekonfigurationen wurden entfernt und ihre
Abwesenheit geprueft. Es wurde kein Cronjob angelegt. Bestehender Test-API-
Einstieg und Rewrite-Datei hashgleich; Testwebsite und reale Produkte unberuehrt.
Main, Produktion und andere Worktrees unveraendert. Fuer weitere praktische
E2E-Nachweise ist ein neuer isolierter Testaufbau erforderlich.

### 3.12 Stabile Beta-Signierung und lokaler Beta-Build

Am 8. Oktober 2026 ausschliesslich auf Vorhandensein geprueft: Im isolierten
Worktree fehlen `.signing/carmaja-product-management-beta.jks` und
`.signing/beta-keystore.properties`; die drei zugehoerigen Gradle-Prozessvariablen
sind nicht gesetzt. Keine anderen privaten Ordner oder Secrets durchsucht.
Anschliessend auf ausdruecklichen Suchauftrag drei byteidentische Kandidaten
gefunden: AP6, AP73b und Hauptworktree. Beta-Alias und oeffentliches Zertifikat
passen zum gepinnten Vertrag; Passwort/Schluesselzugriff dabei ungeprueft.
Auf Fortsetzungsauftrag wurde ausschliesslich die AP6-Kopie in den Git-ignorierten
`.signing`-Ordner des Warenkorb-Worktrees uebernommen, bytegleich und mit
geschuetzter Benutzer-/SYSTEM-ACL. Keine andere Signierung veraendert.
`configure-cart-beta-signing.ps1` fragt nun nur lokal maskierte Passwoerter ab.
Der Java-Pruefer validiert Keystore-Integritaet, Alias, Zertifikat und
Schluesselzugriff inklusive Signaturprobe. Eingaben werden erst nach Erfolg
benutzergebunden per DPAPI ausserhalb des Repositorys gespeichert; keine
Klartext-Properties, keine Netzwerkaufrufe und keine Passwoerter in Argumenten.
Die geschuetzte Eingabedatei und kopierte Keystore-Datei sind nach dem
abgenommenen Beta-Build zu bereinigen; die bestaetigten Originale bleiben erhalten.
Parser-/synthetischer DPAPI-Selbsttest und geheimwertfreie Java-Negativpruefung
bestanden. Weil das Passwort nicht mehr bekannt war, wurde ausschliesslich die
bereits identifizierte private AP6-Beta-Properties-Datei intern wiederverwendet.
Keine Passwortsuche in fremden Dateien, keine Passwortausgabe und kein neuer
Schluessel. Der explizite Importmodus akzeptiert nur den eindeutigen Beta-Vertrag
und prueft beide Passwoerter am gepinnten Keystore vor der DPAPI-Speicherung.

Lokaler Nachweis am 8. Oktober 2026: `testDebugUnitTest` mit 55 Tests ohne Fehler,
`lintDebug` ohne Error/Fatal und `assembleBeta` bestanden; 179 Node-Vertragstests
bestanden. APK: `app/build/outputs/apk/beta/app-beta.apk`, Version
1.4.0-beta.1 / Code 10, Paket `de.steinhart.armbandrechner.test`. Signatur mit
`apksigner` geprueft und Zertifikat identisch zum stabilen Beta-Vertrag.
DEX-Pruefung: Test-API enthalten, Produktions-API nicht enthalten.
Kopierter Keystore und temporaere DPAPI-Eingabe anschliessend entfernt,
Abwesenheit geprueft; private AP6-Originale unveraendert erhalten.
Keine Installation am Geraet, kein Upload/Deployment und keine Gesamtabnahme.

### 3.13 Testwebsite-Runtime: isolierter Aufbau und HTTP-Nachweis

Am 11. Oktober 2026 wurden die drei explizit bereitgestellten privaten
MySQL-, Stripe- und Brevo-Testkonfigurationen validiert. Beide getrennten
MySQL-8-Ziele waren vor der Mutation vollstaendig leer; InnoDB und aktive
TLS-Sitzungen sind nachgewiesen. Fehlende CA-/Hostidentitaetspruefung bleibt
das bereits akzeptierte Test-Restrisiko. Stripe ist nachweislich im Testmodus;
Brevo wird auf genau die privat bestaetigte eigene Empfangsadresse begrenzt.

Die neue private, zeitlich begrenzte Teststage bindet ausschliesslich
`/shop/v3` an den bestehenden Test-API-Webroot. Die alte Rewrite-Datei wurde
vorher privat gesichert; ihre Wiederherstellung erfordert einen Hashvergleich.
Bestehende Produkt-, Draft-, Upload- und SKU-Dateien sowie bestehende Runtime,
Bootstrap und API-Einstieg sind unveraendert. Keine fremden Secrets gelesen,
keine Authdatei veraendert. Zwei getrennte kuenstliche Commerce-Produkte haben
je 30 Stueck; kein bestehendes Testprodukt wurde importiert oder veraendert.
Der Test-Legal-Snapshot stimmt mit ID und Inhalts-Hash des Testwebsite-Bundles
ueberein. Dies ist keine rechtliche Freigabe.

Echte HTTPS-Pruefungen am Rootpfad bestanden: CORS-Preflight einschliesslich
`Idempotency-Key`, Ablehnung fremder Origins, sichere Shop-Cookie-Attribute,
CSRF-Ablehnung, `no-store`, Live-Preis und Verfuegbarkeit, Warenkorb-Ersetzung,
Idempotenz-Replay und Ablehnung veralteter Warenkorbversionen. Mengen 2/1
ergeben 77,70 EUR inklusive einmalig 2,70 EUR Versand. Der Pruefwarenkorb
wurde wieder geleert; keine Checkout-Session, Zahlung, Bestellung oder Mail
wurde in dieser Pruefung erzeugt, kein Bestand reserviert oder abgebucht.

Der direkte private Worker mit leeren Warteschlangen war in 37 ms erfolgreich;
Stripe-/Brevo-Arbeit jeweils null. Gehaltener Dateilock verhindert einen
zweiten Lauf. Dies ist kein UnixCron-Nachweis und noch kein Mail-E2E.
PHP-8.4-Lint, die neuen Adapter-Vertragstests (14 Assertions) und die
bestehenden Worker-Mapping-Tests (20 Assertions) bestanden lokal und auf IONOS.
Beide Datenbank-Worker-Leases sind nach dem Lauf freigegeben und als erfolgreich
protokolliert. Lokale Regression: Test-Lint und alle 179 Node-Tests inklusive
frischem kuenstlichem Testexport bestanden; `git diff --check` ohne Fehler.

Der echte Browserablauf ist noch offen: Der Codex-Browser kann die geschuetzte
Testwebsite ohne manuelle Anmeldung nicht oeffnen
(`ERR_INVALID_AUTH_CREDENTIALS`). Keine Authentifizierung abgeschwaecht oder
umgangen. Die zeitlich begrenzte Stage bleibt nur fuer die Fortsetzung erhalten;
nach Ablauf verweigert sie weitere HTTP-/Worker-Aktionen. Routing-Rueckbau,
Entfernung des temporaeren Stripe-Webhooks, Leerung beider Testdatenbanken und
Entfernung der eigenen Stage/Konfigurationen erfolgen nach dem E2E-Nachweis.
Noch kein vollstaendiger Cleanup und keine Gesamtabnahme. Kein Cron angelegt.
Main, Produktion und Produktionshosting unveraendert; Testdeploygate `false`.

### 3.14 Echter Chrome-Warenkorb bis zum Stripe-Testcheckout

Am 11. Oktober 2026 konnte die vom Nutzer angemeldete Chrome-Sitzung verwendet
werden. Zwei weitere eindeutig kuenstliche V4-Produkte mit UUID, serverseitigem
Hash, Messwerten, Bildern und Produkthinweisen wurden ausschliesslich in der
isolierten Commerce-Teststage angelegt. Ihre zwei Detailseiten und fehlenden
Buildassets wurden additiv, pfadvalidiert und hashgeprueft auf der Testwebsite
bereitgestellt. Ein privates Cleanupjournal wurde vorher geschrieben; alle
bereits vorhandenen Website-Dateien und realen Testprodukte blieben hashgleich.
Der kuenstliche Next.js-Testexport und die Exportpruefung bestanden.

Der echte UI-Weg Produktdetailseite -> Mengenwahl -> Hinzufuegen -> Warenkorb
ist nachgewiesen. Zwei Stueck zu 28,00 EUR und ein Stueck zu 19,00 EUR ergeben
77,70 EUR einschliesslich einmalig 2,70 EUR Versand. Eine Menge von 31 bei
30 verfuegbaren Stuecken wird sichtbar als nicht verfuegbar markiert; der
Checkout bleibt gesperrt und es erfolgt keine stille Mengenreduzierung.
Vor Checkout waren beide kuenstlichen Bestaende unveraendert und unreserviert.

Offener Mehrtab-Fehler: Ein weiterer GET /shop/v3/context ersetzt den einzigen
gespeicherten CSRF-Hash der gemeinsamen Sitzung. Ein aelterer Tab kann daher
mit seinem zwischengespeicherten Token eine gueltige Warenkorbaenderung nur
fail-closed ablehnen. Keine Datenueberschreibung beobachtet. Dieser Befund ist
noch zu korrigieren und erneut parallel zu pruefen; er ist kein bestandener
Mehrtab-Nachweis.

Ueber die echte Warenkorboberflaeche entstand genau eine offene Stripe-
Sandboxsession. Provider und lokale Testdatenbank bestaetigen zwei Positionen,
Mengen 2/1, Gesamtbetrag 7770 Cent, ausschliesslich card/klarna/sepa_debit,
verpflichtende Terms-Zustimmung und passenden Legal-Bundle-Hash des Testbuilds.
Beide Reservierungen blockieren gemeinsam; onHand bleibt je 30. Keine Zahlung,
Bestellung, Mail oder Verkaufsabbuchung vor dem manuellen Zahlungsabschluss.
Private Nachweisdaten bleiben ausserhalb des Repositorys.

Die eigenen Testadapter sind mit PHP 8.4 gelintet; 30 Routing-, Pfad-,
Fixture-, Bestandsschutz-, Empfaenger- und Laufzeit-Assertions bestanden lokal.
Der Browser ist kein Blocker mehr. Offen bleiben Zahlungsfinalisierung,
Original-Bestellmail, Widerruf, Admin-/Cron-E2E und vollstaendiger Cleanup.
Die zeitlich begrenzte Stage bleibt fuer diese Fortsetzung erhalten und
verweigert nach Ablauf weitere HTTP-/Worker-Aktionen. Keine Gesamtabnahme,
kein Commit/Push, kein Produktionszugriff und kein Produktionsdeployment.

### 3.15 Originaler Browser-Zahlung-bis-Bestellmail-Nachweis

Am 11. Oktober 2026 wurde die vom Nutzer abgeschlossene Browser-Kartenzahlung
im echten Stripe-Testmodus verifiziert: Session complete/paid, 7770 Cent,
AGB-Zustimmung accepted und Kundenziel identisch zur ausschliesslich privat
bestaetigten eigenen Brevo-Testadresse. Der echte Abschluss-Webhook war vor
dem Worker dauerhaft in der Inbox gespeichert.

Der private Worker verarbeitete den Abschluss und die Metadaten-Outbox sowie
beide Original-Bestellmails in insgesamt 1011 ms. Nachgewiesen sind genau eine
bestaetigte Bestellung und zwei historische Positions-Snapshots mit Mengen
2/1, Preisen 2800/1900 Cent, Messwerten, Produktversion 2 und beiden Hinweisen.
Snapshots sind bytegleich zu den Checkoutpositionen; Legal-ID und eingefrorener
Bundle-Hash stimmen ueberein. Versand wird einmalig mit 270 Cent berechnet.

Bestand wurde gemeinsam von 30 auf 28 beziehungsweise 29 reduziert.
Beide Reservierungen sind converted und blockieren nicht mehr. Beide
Bestandsversionen sind genau einmal auf 2 gestiegen; zwei shop_sale-Audits
enthalten die zugehoerigen Vorher-/Nachherwerte. Keine offenen Reviewcases.

Bestellbestaetigung und Betreiberbenachrichtigung wurden jeweils genau einmal
an die bestaetigte Testadresse gesendet. Brevo-IDs, gesendete Zeitpunkte und
korrekte Original-Payloads sind privat vorhanden. Ein Rendernachweis ohne
erneuten Versand prueft Mengen, Gesamtsumme, einmaligen Versand und den
Test-Legal-Archivlink. Die auf genau diese beiden Nachrichten eingeschraenkte
Brevo-Abfrage bestaetigt jeweils delivered. Dies bestaetigt Providerzustellung,
nicht den konkreten Posteingangsordner oder ein manuelles Lesen.

Ein direkter Wiederanlauf in 43 ms verarbeitete null Zahlungen und null Mails.
Ein lokal neu signierter HTTP-Replay des Originalereignisses erhielt 204.
Inbox bleibt ein Eintrag; erneuter Worker in 101 ms verarbeitet null Eintraege.
Bestellung, zwei Verkaufsabbuchungen und je ein Mailversuch bleiben unveraendert.
Beide Worker-Leases sind freigegeben, letzter Erfolg vorhanden, kein Fehler.

Die sichtbare Rueckkehrseite zeigt den vorhandenen Vielen-Dank-Ablauf. Der
ausgelieferte Testwebsite-Zweig besitzt dort jedoch noch keinen
CartPaymentStatus; ein dynamischer v3-Zahlungs-/SEPA-processing-Nachweis der
Rueckkehrseite ist damit noch nicht erbracht. Kein Bestellstatus wird allein
aus der Rueckkehrseite abgeleitet. Dieser Frontend-Abgleich und der Mehrtab-
CSRF-Befund aus 3.14 bleiben offen.

Bestehende Testprodukte, Entwuerfe, Uploads, SKU-Dateien und die geschuetzte
bestehende API-Konfiguration bleiben hashgleich. Alle sensiblen Nachweise
liegen ausschliesslich in der zeitlich begrenzten privaten Teststage.
Die Stage samt kuenstlicher Bestellung bleibt fuer Widerrufs-/Admin-/Cron-
Nachweise erhalten; der vollstaendige Cleanup ist noch ausstehend.
Keine Gesamtabnahme, kein Commit/Push und keine Produktionsaenderung.

### 3.16 Mehrtab-, HTTP-Sicherheits-, Teilwiderrufs- und Adminnachweis

Am 11. Oktober 2026 wurde der bestaetigte Mehrtab-CSRF-Fehler ausschliesslich
im neuen Warenkorbrouter korrigiert. V3-CSRF-Tokens tragen einen HMAC ueber
Zweck und Ablaufzeit; der Schluessel ist die nicht an JavaScript ausgegebene
256-Bit-HttpOnly-Shopsitzung. Mehrere Tokens bleiben unabhaengig bis maximal
7200 Sekunden gueltig. Sitzungsablauf, Pflicht-Origin und konstante MAC-Pruefung
bleiben wirksam; es werden keine Roh-Tokens gespeichert. Legacy-Routen und
produktive Programme wurden nicht veraendert. Gezielte PHP-Tests pruefen
ueberlappende Tokens, andere Sitzung, Manipulation und die exakte Ablaufgrenze.

Der echte Root-HTTP-Test besteht: alter und neuer Kontext koennen nacheinander
schreiben, eine veraltete Warenkorbversion ergibt 409; Replay veraendert keine
Version. Fremder/fehlender Origin, fremder/abgelaufener CSRF, Sitzungsablauf,
negative/gebrochene Mengen und Preismanipulation werden abgelehnt. Ungueltiger
Checkoutkontext ergibt vertragsgemaess 409 ohne neue Session/Reservierung;
Ratelimit ergibt 429. Liveantworten bleiben no-store. Zwei anfaengliche
Prueffehlversuche (Replay und falsche erwartete HTTP-Klasse des Kontextfehlers)
sind keine Abnahme: Replay wurde danach gesondert sowie in der gesamten Reihe
erfolgreich geprueft; die 409-Assertion folgt dem bestehenden Vertrag.

Im echten Chrome-Mehrtabablauf wird eine veraltete Menge sichtbar abgelehnt.
Zusaetzlich sperrt die Warenkorboberflaeche nach fehlgeschlagener Aenderung den
alten Checkoutkontext bis zum Neuladen. Der korrigierte Button ist im Browser
nachweislich deaktiviert. Ein kurzzeitig gesperrter eigener Test-Runtime-Gate
beweist den API-Ausfall: kein Checkoutbutton, kein statischer Bestandsfallback.
Die eigene Runtime wurde unmittelbar danach hashgleich wiederhergestellt und
die temporaere Runtime-Zwischensicherung entfernt.

Der echte HTTP-Teilwiderruf zur kuenstlichen bezahlten Zwei-Positions-Bestellung
ist bestanden: Vorschau erzeugt noch keine eingereichte Erklaerung oder Mail;
erst Bestaetigung speichert eine ausgewaehlte Menge. Doppelbestaetigung ergibt
409. Wiederholte/ueberhoehte Menge und nicht zuordenbare Angaben bleiben nach
Bestaetigung als Erklaerung mit zwei Reviewcases erhalten. Keine automatische
Erstattung oder Wiedereinlagerung; Bestaende weiterhin 28/29. Drei originale
Eingangsmails sind jeweils genau einmal gesendet; Brevo bestaetigt delivered.

Ein ausschliesslich kuenstliches Adminkonto prueft ueber den echten Router der
isolierten Stage: ohne Admincookie 401, Argon2id-Login, zwei Bestellpositionen
und 7770 Cent Gesamt, Reviewliste, CSRF-Pflicht und Sitzungswiderruf durch
Logout. Kein bestehendes Betreiberkonto wurde gelesen oder veraendert.
Dies ist ein HTTP-/Backendnachweis, keine pauschale Abnahme aller Admin-UI-Flows.

Der aktuelle kuenstliche Testexport mit CartPaymentStatus besteht Build-,
TypeScript- und Exportpruefung. Nur Warenkorb-, Rueckkehr- und Widerrufsseiten
sowie eigene neue Assets sind temporaer mit vorherigem Rollbackjournal aktiv;
Katalog, reale Detailseiten, Bilder, Auth und vorhandene Testprodukte bleiben
unveraendert. Der dynamische Browser-Zahlungs-/SEPA-status ist noch nicht
vollstaendig abgenommen. Die temporaeren Seiten sind beim Cleanup hashgleich
zurueckzunehmen, nicht als dauerhafter Branch-Deploy zu behandeln.

Regression: npm ci, Test-Lint und der abschliessende npm-test-Lauf mit allen
180 Node-Tests sowie der nachfolgenden Shell-Integration bestanden.
Die optionale PHP-Klickstatistik wird im Shelllauf wegen fehlendem php im PATH
uebersprungen; das ist kein neuer PHP-Klickstatistiknachweis.
PHP-Vertrags-, Mapping-,
AP4-, AP5- und neue CSRF-Tests sowie 43 Testadapter-Pfad-/Cleanup-Assertions
bestanden. Ein gleichzeitiger Node-Exporttest kollidierte mit dem separaten
Buildlock; der anschliessende sequenzielle Testbuild bestand. Ein weiterer
Aufruf aus dem Repositoryroot statt website scheiterte an relativen Testpfaden;
der vorgeschriebene npm-test-Lauf aus website bestand danach vollstaendig.
Kein Lock wurde gewaltsam entfernt. git diff --check ohne Fehler.

Worker-Dateilock wurde mit einem echten zweiten CLI-Prozess nachgewiesen:
zweiter Lauf locked, keine Verarbeitung. Ein kuenstlicher UUID-Lease-Test
bestaetigt Konfliktsperre, Uebernahme nach Ablauf und Schutz gegen Freigabe
durch den alten Besitzer. Der eigene Pruef-Leasedatensatz ist danach entfernt.
Ein anfaenglicher fehlerhafter Test verwendete zu lange Tokens statt der
vorgeschriebenen 36-Zeichen-UUIDs; keine Produkt- oder Providerdaten betroffen.

npm audit meldet bestehende Abhaengigkeitsrisiken (neun high, ein critical,
darunter Next.js). Kein ungefragtes Paketupdate oder pauschales Sicherheits-
Entwarnungsurteil. Der IONOS-Testwebroot liefert statische Exportdateien aus;
die genaue Abhaengigkeitsrisikobewertung ist gesondert zu behandeln.

Echter UnixCron bleibt offen: die geoeffnete IONOS-Seite verlangt erneut Login.
Kein Ersatz durch Shell-crontab, kein Cron bisher angelegt. Die eigene private
Stage, kuenstliche Datenbanknachweise und Runtime bleiben zeitbegrenzt fuer
diesen Nachweis erhalten. Der vollstaendige Cleanup erfolgt danach; keine
Gesamtabnahme. Main, Produktion, Produktionshosting und reale Testprodukte
bleiben unveraendert. Kein Commit, Push oder Produktionsdeployment.

### 3.17 Nachtrag 11. Oktober: Ablauf, historische Bestellungen und Cron-Diagnose

Der aktuelle isolierte Warenkorb-Test besitzt eine originale bezahlte
Zwei-Positions-Bestellung mit 7770 Cent einschliesslich einmalig 270 Cent Versand.
Ein weiterer Browser-Checkout wurde nur abgebrochen und danach ausdruecklich
im Stripe-Testmodus abgelaufen gesetzt. Vor dem bestaetigten Stripe-Endzustand
blieben drei Stueck reserviert; danach verarbeitete der echte Worker das Event:
Checkout expired, Payment canceled, keine blockierenden Reservierungen und
keine zweite Bestellung. Browserabbruch allein gab keinen Bestand frei.

Ein echter HTTP-Aufruf mit abgelaufenem Checkout-Quote wurde mit 409 ohne
Checkoutanlage abgelehnt. Ein abgelaufener sitzungsgebundener Checkouttoken
ergab 403 und die sichere Browsermeldung statt einer Bestellbestaetigung;
die eigene Token-Pruefmutation wurde anschliessend zurueckgenommen.
Nicht zuordenbare Widerrufsangaben zeigen im Browser weiterhin erst die
Vorschau und die ausdrueckliche Bestaetigung; keine neue Erklaerung oder Mail
entstand durch die Identifikationsanfrage.

In der vorher leeren getrennten Restore-Testdatenbank wurde eine kuenstliche
historische Einzelbestellung im alten Schema erzeugt und vorwaerts migriert.
Alle vorherigen Tabellenfelder, Zahlungs-/Legal-/Produktsnapshots und Inhalte
blieben identisch; die Adminprojektion liefert eine Position mit Menge 1.
Die zweite Migration war idempotent. Die Restore-Datenbank ist danach wieder
vollstaendig leer. Keine vorhandene Bestellung oder Produktionsdaten betroffen.

Nur die Warenkorb-Rueckkehranzeige wurde korrigiert: canceled/expired zeigt
nun beendet/abgelaufen statt unbegrenzt Bitte warten. processing hat ausdruecklich
Vorrang vor dem Checkoutablauf, ebenso bleiben Zahlungserfolg und manual_review
massgeblich. Sieben gezielte Mapping-Assertions und der echte abgelaufene
Browser-Checkout bestaetigen dies. Die temporaere Seitenbereitstellung besitzt
ein Rollbackjournal; Katalog und vorhandene Testprodukte sind unveraendert.

Regression dieses Nachtrags: 181 Node-Tests, Shell-Integration, Test-Lint,
TypeScript-/Testbuild-/Exportpruefung und 18 relevante PHP-8.4-Suites bestanden.
Sechs geaenderte/neue PHP-Dateien ohne Lintfehler. Windows-POSIX-Pruefungen
bleiben explizite Skips; private 0600-Dateien und TLS werden auf IONOS geprueft.
Ein falsch gesetzter Bildwurzelpfad im ersten Testbuild wurde nur im
Testprozess korrigiert. Direkte Workeraufrufe funktionieren auch aus /,
bleiben unter 40 Sekunden und sind ausdruecklich kein echter Cronnachweis.

Der eigene temporaere IONOS-UnixCron wurde aktiv mit */5 und einem privaten
PHP-8.4-Worker angelegt. Ein Startmarker vor dem Worker-Bootstrap unterscheidet
fehlende Scheduleraufrufe von spaeteren Bootstrapfehlern. Der echte
Cronnachweis ist nicht bestanden; Cleanup siehe 3.18. Keine Gesamtfreigabe.

### 3.18 Abschliessender Cronbefund und vollstaendiger Cleanup am 11. Oktober

Der in der IONOS-Kundenoberflaeche angelegte eigene Testjob
Cart-E2E-336033b5 war aktiv mit */5 * * * * und dem privaten PHP-8.4-Befehl.
Zwischen 19:11 und 19:40:58 UTC blieben sechs faellige Fuenf-Minuten-Termine
ohne nachweisbaren automatischen Workerstart. Die spaeter ergaenzte
Startmarkerdatei vor dem Bootstrap enthielt ausschliesslich den ausdruecklich
direkten Prueflauf von 19:20:43 UTC. Laufjournal und Datenbank-Leasezeitpunkte
enthielten nur die dokumentierten Direktlaeufe, keine automatischen Laeufe.
Der Direktlauf nach bestaetigtem Stripeablauf benoetigte 53 ms; der separate
Arbeitsverzeichnis-Test aus / um 19:32:50 UTC 195 ms. Beide sind kein Cronbeweis.

Geprueft wurden: gespeicherter Befehl, aktiver Job, erweiterte Cron-Syntax,
privater absoluter Pfad, PHP 8.4, Besitzer und Rechte (Stage 0700, Skript 0600),
PHP-Lint, Direktlauf und Unabhaengigkeit vom Arbeitsverzeichnis. Die genaue
Ursache der fehlenden Scheduleraufrufe ist nicht nachgewiesen. Keine
Freigabe dieses Gates und kein Ersatz durch Shell-crontab oder oeffentlichen
HTTP-Worker. Zur Fortsetzung ist ein IONOS-seitiger UnixCron-/Ausfuehrungsnachweis
erforderlich. Fehler- und Timeoutmeldungen der Kundenoberflaeche wurden nicht
als Loeschbeweis verwendet: erneutes Laden bestaetigt separat die Abwesenheit
des Testjobs und die unveraenderten zwei vorhandenen Produktionsjobs.

Cleanup danach bestanden:

- temporaeren Cron entfernt und seine Abwesenheit in IONOS separat geprueft;
- temporaere Flowseiten und eigene Produktseiten/Bilder/Assets anhand ihrer
  Rollbackjournale entfernt, vorherigen Websitebestand hashgleich hergestellt;
- temporaeres API-Routing hashgleich zurueckgenommen, vorhandene Produktdaten,
  Entwuerfe, Uploads, SKU-Zaehler und bestehende Test-Runtime unveraendert;
- eigenen Stripe-Sandboxwebhook entfernt und Abwesenheit per API-404 bestaetigt;
- eigene oeffentliche Teststage entfernt;
- nur eigene kuenstliche Schemaobjekte/Daten entfernt: Source dbs15962244 und
  Restore dbs15962566 erneut vollstaendig leer, Datenbanken selbst erhalten;
- eigene private Stage einschliesslich 715 Dateien, Worker, Marker, Runtime,
  Rollbackjournale und temporaere Archive vollstaendig entfernt;
- drei explizite private Warenkorb-Testkonfigurationen, eigene Incomingreste
  und lokalen temporaeren E2E-Ordner entfernt, Abwesenheit geprueft;
- vorhandene drei sichtbare Testprodukte abschliessend im Browser erhalten;
  Testwebsite bleibt ohne temporaere Commerce-Runtime fail-closed.

Stripe-Sandboxtransaktionen und zugestellte Testmails bleiben als
providerseitige Testhistorie bestehen; kein Anspruch auf deren Loeschung.
Repository-Testwerkzeuge, Korrekturen und Abnahmedokumentation bleiben lokal
uncommittet erhalten. Die temporaere Seitenbereitstellung ist kein dauerhafter
Testwebsite-Deploy der neuen Rueckkehranzeige. Kein Commit, Push, Main-Merge,
Produktionsdeploy oder Produktionsdatenzugriff. Testdeployschalter weiterhin
false. Geheimwertpruefung der 14 geaenderten/neuen Dateien ohne Fund;
git diff --check bestanden. Testadapter-Pfad-/Cron-Guards jetzt 52 Assertions.

### 3.19 Fortsetzung am 14. Oktober: Cron-Blocker durch Nutzerentscheidung geschlossen

Die ausdrueckliche Nutzerentscheidung vom 14. Oktober 2026 schliesst den
konkreten Cron-Blocker fuer die Fortsetzung der Abnahme. Keine weitere
IONOS-Supportaktion oder Wiederholung dieses Cronversuchs wird verlangt.
Der technische Befund aus 3.18 bleibt unveraendert: zwei echte automatische
Laeufe wurden in dieser Pruefreihe nicht nachgewiesen. Der Status lautet
geschlossen durch Nutzerentscheidung, nicht technisch bestanden. Daraus
entsteht keine Produktions-, Deployment- oder Cutoverfreigabe.

Der Cleanup aus 3.18 bleibt erhalten. Der erneute ausschliesslich lesende
Vorflug bestaetigt, dass alle drei expliziten privaten Warenkorb-Test-
konfigurationen fehlen. Keine bestehenden Runtime-/Secret-Stores durchsucht.
Zur Fortsetzung der noch offenen SEPA-/Browser-/UI-Nachweise werden nur diese
freigegebenen Testzugaenge erneut interaktiv bereitgestellt; als erster
Einzelschritt Source dbs15962244 und Restore dbs15962566. Vor Mutation folgt
erneut ein lesender Identitaets-, Leerheits- und aktiver TLS-Vorflug. Bereits
bestandene MySQL-Funktions-/Crash-/Backup-Pruefreihen werden nicht wiederholt.

Lokale Kontrollpruefungen am 14. Oktober bestanden: fuenf gezielte
Node-Warenkorb-Vertragstests, PHP-Mehrtab-CSRF-Test, 52 Testadapter-/Cron-
Guardassertions und der schreibfreie Selbsttest des Datenbank-Eingabeskripts.
Keine neue praktische Providerabnahme oder pauschale Gesamtfreigabe.

### 3.20 Verbindlicher Konfigurations-Cleanup-Vertrag ab 14. Oktober

Die private Warenkorb-Testdatenbank-Konfiguration
/home/www/carmaja-private-test/shop-cart-test.json darf gemaess ausdruecklicher
Nutzerfreigabe zwischen Testlaeufen erhalten bleiben und wiederverwendet werden.
Sie bleibt ausschliesslich privat, ausserhalb aller Webroots, mit Modus 0600;
keine Zugangsdaten in Git, Artefakten, Chat oder Logs. Keine Suche in anderen
Konfigurationen oder Secret-Stores. Bestehende Inhalte niemals automatisch
ueberschreiben. Vor jeder Nutzung Struktur, feste getrennte Testidentitaeten,
TLS und erforderliche Leerheit lesend pruefen.

Beim Abschluss weiterer Pruefreihen wird diese Datenbank-Konfiguration
ausdruecklich vom Cleanup ausgenommen und unveraendert erhalten. Temporaere
Stages, Runtimekopien darin, Endpunkte, eigene Cronjobs, Testdaten, Tabellen,
Transferdateien und lokale Artefakte bleiben vollstaendig zu bereinigen.
Diese Freigabe gilt nicht automatisch fuer Stripe-/Brevo-Konfigurationen,
Produktionszugaenge oder dauerhaft aktive Test-Shop-Runtimes.
Die spaetere ausdrueckliche Stripe-Aufbewahrungsfreigabe steht in 3.25;
die Brevo-Konfiguration ist davon nicht umfasst.

Der lesende Check am 14. Oktober bestaetigt weiterhin: die frueher geloeschte
Datenbank-Konfiguration fehlt. Die Aufbewahrungsfreigabe stellt keine bereits
geloeschten Zugangsdaten wieder her. Eine einmalige erneute Bereitstellung per
geschuetztem Eingabeskript ist erforderlich; danach keine routinemaessige
Loeschung oder wiederholte Eingabe dieser Datei mehr.

### 3.21 Erneute private Datenbankbereitstellung und lesender Vorflug am 14. Oktober

Die Nutzerbestaetigung zur erneuten Datenbank-Konfiguration wurde technisch
geprueft: /home/www/carmaja-private-test/shop-cart-test.json vorhanden,
kein Symlink, Modus 0600 und gueltiger Test-/TLS-Konfigurationsvertrag.
Source dbs15962244 und Restore dbs15962566 sind getrennte feste Testziele
mit unterschiedlichen Benutzern. Beide PDO-Verbindungen unter PHP 8.4
erfolgreich; beide Server MySQL 8.0.36, InnoDB verfuegbar und aktuelle
Ssl_cipher-/Ssl_version-Werte befuellt (TLSv1.3). Beide Datenbanken ohne
Tabellen, Routinen oder Events. Keine Datenbankmutation oder Wiederholung
der bereits bestandenen Funktions-/Crash-/Backup-Pruefreihen.

Die CA-/Hostidentitaetspruefung ist weiterhin nicht nachgewiesen; das
ausdruecklich akzeptierte Test-Restrisiko bleibt bestehen, aktive TLS-Sitzung
ist Pflicht. Keine Geheimwerte ausgegeben und keine Konfiguration veraendert.
Die Datenbank-Datei bleibt gemaess 3.20 privat erhalten.

Die explizite Stripe-Testkonfiguration fehlt noch. Naechster einzelner
manueller Schritt ist die maskierte Eingabe ausschliesslich eines sk_test_-
Schluessels ueber configure-cart-stripe-test.ps1. Das Skript nimmt keinen
Live-Key an, uebertraegt per SSH-stdin und erzeugt den Payloadschluessel
serverseitig; noch kein Checkout, kein Webhook und kein Zahlungsnachweis.
PowerShell-Selbsttest und PHP-Schluesselvertrag bestanden; POSIX-Assertions
des lokalen PHP-Tests unter Windows bewusst uebersprungen. Private
Serverrechte werden nach der Bereitstellung separat geprueft.

### 3.22 Stripe-Sandboxkonfiguration bestaetigt am 14. Oktober

Die erneut bereitgestellte explizite private Stripe-Testkonfiguration ist
vorhanden, kein Symlink, Modus 0600. Sandboxschluesselformat und separat
serverseitig erzeugter 32-Byte-Webhook-Aufbewahrungsschluessel mit Key-ID
geprueft, ohne Werte auszugeben. Der echte ausschliesslich lesende Aufruf
der Stripe-Balance-API ueber TLS und gepinnte API-Version war erfolgreich;
livemode=false. Keine Zahlung oder Liveproviderressource angelegt.
Die erhaltene Datenbank-Konfiguration ist weiterhin vorhanden.

Die private Brevo-Testkonfiguration fehlt noch. Der vorhandene Test-Site-
Adapter benoetigt sie vor der Stage-Initialisierung fuer die bestaetigte
eigene Empfaengeradresse und die Mail-Outbox-Kette. Deshalb noch keine neue
Stage, keine Schema-Migration, kein Webhook und keine Routing-/Website-
Aenderung angelegt. Naechster einzelner manueller Schritt ist das geschuetzte
Brevo-Eingabeskript mit Test-API-Key, verifizierter Absenderadresse,
Absendername und eigener ausdruecklich bestaetigter Testempfaengeradresse.
PowerShell-Selbsttest, PHP-Eingabevertrag und Installer-Lint bestanden.
Bereitstellung allein ist weder Mailversand- noch Gesamt-E2E-Nachweis.

### 3.23 Brevo-Vorflug und erneute Browser-SEPA-Pruefreihe am 14. Oktober

Die explizite private Brevo-Testkonfiguration ist vorhanden, kein Symlink,
Modus 0600; Testempfaenger durch den Nutzer bestaetigt. Lesender Abruf der
Brevo-Absenderliste erfolgreich, der konfigurierte Absender ist aktiv.
Keine Adressen oder Zugangsdaten ausgegeben. Die dauerhaft erhaltene
Datenbank-Konfiguration wird weder veraendert noch beim Cleanup geloescht.

Eine neue zeitlich begrenzte private E2E-Stage wurde in den beiden leeren,
bereits nachgewiesenen Testdatenbanken eingerichtet. Keine Wiederholung
der bestandenen MySQL-Funktions-/Crash-/Backup-Pruefreihen. Die gepinnte
Stripe-SDK-Auslieferung benoetigt neben init.php und lib/ auch data/ mit
ihrem CA-Bundle; die zunaechst unvollstaendige temporaere SDK-Kopie wurde
vor dem erfolgreichen Setup vervollstaendigt, ohne TLS-Pruefung abzuschalten.

Der Smoke-Adapter vergleicht bei Warenkorb-Replay nun alle Geschaeftsdaten
unveraendert, nimmt aber ausschliesslich den je Anfrage erzeugten
items[].responseAt-Zeitstempel vom Gleichheitsvergleich aus. Zuvor konnte
der sekundenuebergreifende Replay faelschlich scheitern. Neue Tests belegen,
dass Mengen-, Preis-, Warenkorbversions- und sonstige Antwortabweichungen
weiterhin erkannt werden. Fuer zwei weitere voruebergehende Smoke-/Reset-
Fehler liegt keine nachgewiesene Ursache vor; sie sind nicht als behoben
deklariert. Sichere HTTP-/Fehlercode-Diagnostik wurde ergaenzt. Der spaetere
vollstaendige Smoke bestand; die bisherige CSRF-Pruefung bleibt unveraendert.

Lokale PHP-8.4-Pruefungen: Lint der geaenderten Adapter/Testdateien und
16 relevante Suites bestanden, darunter 57 Site-Adapter-Assertions.
Test-Lint, alle 181 Node-Tests, Shell-Integrationen sowie Testbuild/Export
bestanden. Optionale Shell-PHP-Clickstats mangels PHP im PATH uebersprungen;
kein neuer Nachweis dafuer. git diff --check bestanden. Keine Android-
Funktionsaenderung in dieser Pruefreihe. Keine pauschale Sicherheitsfreigabe
oder Gesamtabnahme aus diesen lokalen Ergebnissen.

Live-Smoke: /shop/v3 mit korrektem Test-Origin, CORS/Idempotency-Key,
No-Store, sicheren Cookies, CSRF, Livepreis/-menge, Replay und Versionskonflikt
bestanden. Ein privater direkter Leer-Workerlauf dauerte 100 ms, ohne Payment-
oder Mailaktion; nicht als Cronnachweis gewertet. Die drei vorhandenen
Testprodukte, Produkt-API und baseline Runtime sind unveraendert.

Fuer die Browserpruefung sind ausschliesslich zwei kuenstliche Detailseiten
und rollback-journalgebundene Warenkorb-/Rueckkehr-/Widerrufsseiten temporaer
bereitgestellt. Reale Katalog-/Produktseiten bleiben unveraendert.
Chrome-Anmeldung vom Nutzer bestaetigt und technisch geprueft. Browser-
Warenkorb: zwei Positionen mit Mengen 2/1 und Gesamtbetrag 7.770 Cent,
einschliesslich einmalig 270 Cent Versand. Echter Checkout-Start ueber die
Website erfolgreich; Stripe bestaetigt Sandbox, offene Session und 7.770 Cent.
Beide Mengen sind gemeinsam reserviert; keine Bestellung angelegt.

Der SEPA-Checkout wartet auf die persoenliche Testbestaetigung des Nutzers.
Noch kein neuer SEPA-Endzustands-, Versand- oder Mailnachweis. Die Stage ist
nur bis 15. Oktober 2026, 07:46:24 UTC freigegeben; ihre privaten Konfigurationen
werden fuer diesen laufenden Nachweis nicht vorzeitig entfernt. Abschliessend
Rollback der temporaeren Seiten/Routen, Entfernung eigener Providerendpunkte,
Testtabellen/-daten und Stage-/Transferdateien; die Datenbank-Konfiguration
bleibt nach unveraendertem Hash-/0600-Nachweis erhalten. Kein Commit, Push,
Produktionszugriff oder Cutover. CARMAJA_TEST_DEPLOY_ENABLED weiterhin false.

### 3.24 Originale Browser-SEPA-Kette bis Bestellung und Mail am 14. Oktober

Der Nutzer hat den vorbereiteten echten Stripe-Sandbox-Checkout bestaetigt.
Lesender Vorflug: Session complete/unpaid, PaymentIntent processing,
AGB-Zustimmung accepted, Betrag 7.770 Cent. Beide Reservierungen mit Mengen
2/1 aktiv/blockierend, keine Bestellung. Die privaten Testproduktdaten und
die erhaltene Datenbank-Konfiguration waren unveraendert.

Der erste private Workerlauf verarbeitete den echten Abschlusswebhook und
den Stripe-Abgleich in 797 ms. Payment processing; Bestand weiterhin
30/30, keine Bestellung, keine Mail. Die originale Browser-Rueckkehrseite
zeigte live: SEPA wird bearbeitet; Bestaetigung und Versand erst nach Erfolg.
Keine kuenstliche Payment-Statusmutation und kein Versand simuliert.

Der echte checkout.session.async_payment_succeeded-Webhook traf danach
in der persistenten Inbox ein. Der zweite Workerlauf dauerte 1.143 ms und
finalisierte genau eine bestaetigte Bestellung mit zwei Positionen und
Mengen 2/1. Bestand 30/30 -> 28/29; genau zwei shop_sale-Audits,
beide Reservierungen gemeinsam converted und nicht mehr blockierend.
AGB-Zustimmung, Legal-Bundle-ID/-Hash, Versand einmalig 270 Cent,
Produkthinweise und unveraenderte Positionssnapshots geprueft. Die originale
Rueckkehrseite wechselte ohne Neuanlage zu Zahlung bestaetigt samt Bestellnummer.

Beide realen Inbox-Ereignisse sind processed, Sandbox-/Session-/Webhook-
Versionsbindung geprueft. Erneute und umgekehrte fachliche Verarbeitung
derselben Events liess den gesamten Datenbankfingerabdruck unveraendert.
Keine offenen Reviewcases. Dieser Nachweis ist keine erneute HTTP-Zustellung;
HTTP-Replay wurde bereits separat in 3.15 nachgewiesen.

Originale order_confirmation und operator_order_notification jeweils
genau einmal an die zuvor bestaetigte eigene Testempfaengeradresse versandt.
Brevo-Nachrichtenkennung vorhanden, attempt_count=1, Payload unveraendert;
Renderer prueft zwei Positionen, Mengen, Gesamtsumme, einmaligen Versand
und beide Produkthinweise. Lesender nachrichtenbezogener Brevo-Eventreport
meldet fuer beide Mails delivered. Keine persoenliche Inbox gelesen und
kein Oeffnungsnachweis behauptet. Dritter Workerlauf: 52 ms, null Payment-
und null Mailaktionen; keine zweite Bestellung oder Mail.

Die Zahlung erreichte ihren Erfolg VOR dem 30-Minuten-Checkoutzeitpunkt.
Damit ist der dynamische Browser-SEPA-Zwischenzustand samt echter
Erfolgsfinalisierung bestanden, NICHT ein erst nach diesem Zeitpunkt
eintretender Provider-Endzustand. Der entsprechende Grenzfall bleibt offen.
Keine neue pauschale Warenkorb-, Rechts- oder Produktionsfreigabe.

Cleanup vollstaendig bestanden: temporaere Warenkorb-/Rueckkehr-/Widerrufs-
Seiten sowie kuenstliche Detailseiten/Bilder/Assets journalgebunden entfernt,
Testwebsite und API-Routing hashgleich wiederhergestellt. Eigener Stripe-
Webhook und oeffentlicher E2E-Einstieg entfernt; beide Testdatenbanken wieder
vollstaendig leer (Datenbanken selbst erhalten). Private Stage, Laufdaten,
temporaere Stripe-/Brevo-Konfigurationen, eigener Incoming-Pfad, lokaler
Tempordner und zugeordnete ignorierte Testexport-/Builddateien entfernt.
Provider-Sandboxhistorie und zugestellte Testmails bleiben beim Provider
bzw. Empfaenger bestehen; keine gegenteilige Loeschbehauptung.

Die dauerhaft freigegebene private shop-cart-test.json blieb hashgleich
mit Modus 0600 erhalten. Protected-Fingerabdruck einschliesslich vorhandener
Produkte, Drafts, Uploads und baseline Runtime unveraendert. Live-Sichtpruefung
nach Cleanup: alle drei bisherigen Test-Armbänder vorhanden und nicht kaufbar.
In dieser Pruefreihe kein Cron angelegt; 3.19 bleibt eine Nutzerentscheidung,
kein technischer Cron-Erfolgsnachweis. Kein Commit, Push, main-/Produktions-
zugriff oder Deployment; CARMAJA_TEST_DEPLOY_ENABLED weiterhin false.

### 3.25 Stripe-Testkonfiguration erhalten und sichtbare Eingabe am 14. Oktober

Der Nutzer verlangt ausdruecklich, den Stripe-Testschluessel nicht nach jedem
Testlauf erneut abzufragen. Deshalb bleibt auch die explizite private Datei
/home/www/carmaja-private-test/shop-cart-stripe-test.json zwischen den
Warenkorb-Testlaeufen erhalten und wird wiederverwendet. Ausschliesslich
Sandbox, kein Symlink, Modus 0600 und ausserhalb aller Webroots; niemals
Geheimwerte in Chat, Repository, Artefakte, Befehlsargumente oder Logs.
Vor Nutzung Struktur, Sandbox-Key-Praefix, Payload-Key-Vertrag und reale
lesende Stripe-Testverbindung pruefen. Keine automatische Ueberschreibung;
fehlende oder ungueltige Konfiguration sperrt den neuen Zahlungstest.

Die erhaltene Stripe-Datei ist wie die Datenbank-Konfiguration ausdruecklich
vom Testlauf-Cleanup ausgenommen. Ihre Unveraendertheit und 0600-Rechte
werden geprueft. Runtimekopien und sitzungsbezogene Webhook-Secrets in eigenen
Stages, Testendpunkte, Testdaten, Cronjobs und sonstige temporaere Artefakte
weiterhin vollstaendig entfernen. Keine automatische Erweiterung auf Brevo,
Live-Secrets oder dauerhaft aktive Shop-Runtimes. Eine explizite spaetere
Widerrufs-/Rotations-/Loeschanweisung des Nutzers bleibt massgeblich.

Der in 3.24 dokumentierte Cleanup hatte die Stripe-Datei nach dem damaligen
Vertrag bereits geloescht; keine Suche nach anderen Secrets oder Behauptung
einer Wiederherstellung. Einmalige erneute sichere Eingabe ist erforderlich.
Der zuvor gestartete eigene Eingabeprozess besass kein sichtbar erkanntes
Hauptfenster; nach eindeutiger Skriptzuordnung beendet und als ausdruecklich
interaktives Fenster neu gestartet. Prozesspruefung bestaetigt sichtbares
Fenster mit Titel Carmaja - Stripe-Warenkorbtest. Keine neuen Anwendungscode-
oder Produktionsaenderungen; noch kein neuer SEPA-Nachweis.

### 3.26 Gezielter echter SEPA-Endzustand nach Checkoutzeitpunkt vorbereitet

Die erneut bereitgestellte private Stripe-Testkonfiguration wurde am
14. Oktober technisch bestaetigt: kein Symlink, Modus 0600, sk_test_-Vertrag,
32-Byte-Payloadschluessel und Key-ID gueltig. Echte lesende Stripe-Balance-
Abfrage erfolgreich, livemode=false. Datenbank-Konfiguration weiterhin
privat vorhanden; beide fest freigegebenen Testziele leer, MySQL 8/InnoDB
und aktive TLSv1.3-Sitzung. Keine Suche in bestehenden Secret-Stores.

Neue eigene Stage:
/home/www/carmaja-private-test/cart-e2e-20261014-0910-deb13325.
Programme und vollstaendiges gepinntes Stripe-SDK einschliesslich CA-Bundle
hashgeprueft, Archivpfade/symlinkfreie Extraktion geprueft und fuenf
relevante PHP-Dateien auf IONOS unter PHP 8.4 gelintet. Nur die zwei leeren
Testdatenbanken initialisiert; zwei kuenstliche Produkte mit Bestand 30/30,
Warenkorb 2/1 und Betrag 7.770 Cent einschliesslich 270 Cent Versand.
Eigenes gepinntes Sandbox-Webhookziel und oeffentlicher Teststage-Einstieg.
Keine globale Test-API-Routing-, Testwebsite-, Produktdaten- oder Produktions-
aenderung. Keine Wiederholung bestandener Datenbank-/Mail-Pruefreihen.

Echte Stripe-Sessionlaufzeit 1.800 Sekunden. Ende am 14. Oktober 2026,
09:41:25 UTC / 11:41:25 Europe/Berlin. Gezielte Nutzerbestaetigung geplant
um 09:40:00 UTC / 11:40:00 Europe/Berlin. Die offizielle successDelayed_de-
Test-IBAN wurde anhand der Stripe-Dokumentation bestaetigt: Erfolg erst
nach mindestens drei Minuten. Mit rechtzeitiger Einreichung im genannten
Fenster soll dadurch ein realer Provider-Endzustand nach dem Checkout-
zeitpunkt entstehen; kein Manipulieren von Sessionlaufzeit oder Datenbankuhr.

Browserformular ausschliesslich mit kuenstlichem Namen, Adresse und
example.com-E-Mail vorbereitet; keine echte Bankverbindung, keine
AGB-Zustimmung und keine Zahlung durch den Agenten. Wartet auf persoenliche
Nutzerbestaetigung im Zeitfenster. Noch kein bestandener Ablaufnachweis.
Dieser abgegrenzte Test startet keinen Brevo-Mailworker; Bestellmailkette
bereits in 3.24 separat bestanden. Die bestehende Testwebsite-Rueckkehrseite
ist fuer diesen Zeitgrenzfall kein neuer dynamischer UI-Nachweis.

Private Ownership-/Konfigurations-/Produkt-Baseline vor Mutation gesichert.
Datenbank- und Stripe-Konfiguration bleiben hashgleich erhalten (3.20/3.25).
Kuenstliche Testdaten, Stage, eigener Endpunkt/Webhook und Transferartefakte
nach Abschluss bereinigen; bestehende Testprodukte erhalten. Stagefrist:
15. Oktober 2026, 09:11:23 UTC / 11:11:23 Europe/Berlin; keine unbemerkte
Verlaengerung. Kein Cron, Commit, Push, Deployment oder Produktionszugriff.

### 3.27 Zeitgrenzfall nicht erreicht; bestaetigter unbezahlter Ablauf bereinigt

Die Nutzerbestaetigung zur Einreichung wurde technisch geprueft. Stripe
meldet fuer genau die vorbereitete Session expired/unpaid, keine akzeptierte
AGB-Zustimmung und keinen Zahlungsabschluss. In der Inbox liegt ausschliesslich
der echte checkout.session.expired-Event mit Zeitpunkt 14. Oktober 2026,
09:41:25 UTC. Kein checkout.session.completed und kein asynchroner Erfolg.
Der Browser zeigte die mehrdeutige Stripe-Anzeige, die sowohl Zahlungsabschluss
als auch Zeitueberschreitung nennt. Diese Anzeige ist kein Zahlungsnachweis.
Warum die Einreichung nicht abgeschlossen wurde, ist nicht nachgewiesen;
keine unbelegte Zuordnung zu Nutzerfehler oder einer Anwendungsregression.

Vor Workerbearbeitung nach dem Zeitpunkt: beide kuenstlichen Mengen 2/1
noch reserviert, Bestand 30/30, keine Bestellung und keine Mail. Erst nach
dem signaturgeprueften Stripe-Ablaufwebhook gab der private Worker beide
Reservierungen frei: Checkout expired, Payment canceled, Reservierungen
released und blocks_stock=0, Bestand weiterhin 30/30. Laufzeit 16 ms,
null Bestellungen, null Mails, keine shop_sale-Abbuchung und kein offener
Reviewcase. Doppelte fachliche Verarbeitung desselben echten Ablauf-Events
liess den gesamten Datenbankfingerabdruck unveraendert.

Damit ist nur der unbezahlte bestaetigte Sessionablauf erneut praktisch
nachgewiesen. Der gewuenschte SEPA-Provider-Endzustand erst nach dem
Checkoutzeitpunkt ist NICHT bestanden und bleibt offen. Keine simulierte
Zahlungsbestaetigung, keine manipulierte Laufzeit/Uhr, keine Gesamtfreigabe.

Cleanup vollstaendig: eigener Stripe-Testwebhook entfernt und dessen
Abwesenheit separat per 404 geprueft; eigener oeffentlicher und privater
Teststage-Einstieg, Skripte, Runtimekopien, Cookiejar, Laufdaten und lokale
Transfer-/Handoffdateien entfernt. Beide Testdatenbanken wieder vollstaendig
leer, Datenbanken selbst nicht geloescht. Die dauerhaft freigegebenen
shop-cart-test.json und shop-cart-stripe-test.json blieben jeweils hashgleich
mit Modus 0600 erhalten. Geschuetzte Produkte/Drafts/Uploads/SKU-Zaehler,
baseline Runtime/Bootstrap und API-Dateien unveraendert. Testwebsite wurde
in dieser Pruefreihe nicht veraendert; kein Cron angelegt. Providerhistorie
bleibt beim Provider. Nur Abnahmebericht/Produktionsplan aktualisiert,
keine Fachlogikaenderung, kein Commit, Push, Workflow, Deployment oder
Produktionszugriff. git diff --check bestanden.

### 3.28 Begleitete Wiederholung mit privatem automatischem Beobachter

Der Nutzer ist fuer die Wiederholung bereit. Neue ausschliesslich private
Stage /home/www/carmaja-private-test/cart-e2e-20261014-retry-be6d1e32 mit
frischem Besitz-/Baseline-/Konfigurationsjournal. Lesender Vorflug mit den
erhaltenen Dateien: beide Testdatenbanken leer, MySQL 8/InnoDB und aktive
TLS-Sitzungen, reale Stripe-Sandboxverbindung erfolgreich. Keine erneute
Secret-Eingabe, kein Zugriff auf andere Konfigurationen oder Produktion.

Neuer repositoryseitiger Testadapter cart-sepa-deadline-test.php:
begrenzt auf eigene private kuenstliche Stage und Sandbox-Session,
unveraenderte 1.800-Sekunden-Laufzeit, eigener Dateilock, fuenfsekundige
Abfragen und Ende spaetestens 15 Minuten nach Checkoutzeitpunkt.
Jeder bestehende Payment-Worker-Teillauf bleibt unter 40 Sekunden.
Erfasst echte processing-Invarianten vor/nach dem Zeitpunkt: Bestand
30/30, Reservierung 2/1, keine Bestellung/Mail. Ein Erfolg gilt fuer den
Grenzfall nur mit beobachtetem processing NACH dem Zeitpunkt und echtem,
signaturgeprueftem asynchronem Erfolgsevent mit spaeterem Stripe-Zeitstempel.
Keine virtuelle Uhr, keine Statusinjektion, kein Brevo-Mailworker.

Nur Testharness/Tests, keine Fachlogik oder App geaendert. Lokales PHP-8.4-
Lint beider neuen Dateien, 35 Observer-Invarianten/Guards, 29 vorhandene
Sandbox-Guards, 57 Site-Adapter-Guards und fuenf AP3-Stripe-Tests bestanden.
Artefakte hash-/pfadgeprueft; privater Adapter auch auf IONOS gelintet.
Ein erster Kontextabruf scheiterte vor Checkoutanlage; anschliessende
gezielte lesende Diagnose ergab HTTP 200/ok. Ursache nicht nachgewiesen,
keine unbelegte Fehlerbehebung deklariert. Danach genau ein Checkout angelegt.

Echte neue Stripe-Session am 14. Oktober 2026: 10:13:07 UTC erstellt,
10:43:07 UTC / 12:43:07 Europe/Berlin vorgesehenes Ende. Aufforderung zur
persoenlichen Bestaetigung erst ab 10:41:07 UTC / 12:41:07 Europe/Berlin.
Private Beobachtung gestartet, noch kein Grenzfall bestanden. Kein Cron
oder globale Routing-/Website-/Produktdatenaenderung. Nach dem Versuch
eigene Testdaten/Stages/Endpunkte/Transfers bereinigen; Datenbank- und
Stripe-Dateien unveraendert mit 0600 erhalten. Bestehende Testprodukte
bewahren. Keine Gesamt-, Produktions-, Deployment- oder Cutoverfreigabe.

### 3.29 Ergebnis und Cleanup der begleiteten Wiederholung vom 14. Oktober 2026

Der neue Versuch erreichte den gewuenschten SEPA-Zeitgrenzfall nicht.
Die Aufforderung zur persoenlichen Bestaetigung erfolgte im laufenden Dialog
ab 12:41:07 Europe/Berlin; bis zum vorgesehenen Ende um 12:43:07 wurde
serverseitig keine Zahlungseinreichung beobachtet. Stripe bestaetigte danach
expired/unpaid. Keine echte processing-Probe und kein asynchroner
Zahlungserfolg; ausschliesslich checkout.session.expired wurde verarbeitet.
Das Browserformular zeigte weiterhin eine unbestaetigte Zustimmung.
Keine technische Ursache fuer die ausgebliebene Einreichung behaupten.

Die Ablaufverarbeitung selbst bestand: Bestand 30/30 unveraendert,
Reservierungen gemeinsam auf 0/0 freigegeben, null Bestellungen und Mails.
Ein erneuter Payment-Worker-Lauf veraenderte den Bestand nicht.
Der private Beobachter war beendet und sein Lock nachweislich frei,
bevor die Bereinigung begann. Es lief kein Brevo-Mailworker und kein Cron.

Vollstaendiger Cleanup: eigene Sandbox-Webhookregistrierung nachweislich
nicht mehr vorhanden, oeffentlicher Testendpunkt und private Stage entfernt,
lokale Transferdateien entfernt; beide isolierten MySQL-Testdatenbanken
wieder leer. Geschuetzte bestehende Produkt-, Runtime- und API-Dateien
hashgleich. Private Datenbank- und Stripe-Konfigurationen unveraendert mit
0600 erhalten; keine erneute Eingabe ihrer Zugangsdaten erforderlich.
Bestehende Testprodukte und Testwebsite erhalten, Produktion/main unveraendert.
Kein Commit, Push, Deployment oder Cutover.

Die 35 Observer-Assertions und abgegrenzten Tests aus 3.28 bleiben bestanden;
git diff --check bestanden. Der reale SEPA-Erfolg nach dem Checkoutzeitpunkt
bleibt offen. Keine Gesamtabnahme oder Umdeklaration dieses fehlenden Gates.
Ein weiterer Versuch erfordert eine neue Sandbox-Session und persoenliche
Einreichung im tatsaechlichen Klickfenster; abgelaufene Sessions nicht wiederverwenden.

### 3.30 Sichtbestaetigung vor weiterer zeitkritischer SEPA-Einreichung

Nach Nutzerhinweis, dass kein Zahlungsfenster sichtbar war, erst Sichtbarkeit
der Testwebsite bestaetigt. Neue eigene private Stage
cart-e2e-20261014-visible-50948fcd; unveraenderte private Datenbank-/Stripe-
Konfigurationen mit 0600, beide Testdatenbanken zuvor leer, MySQL 8/InnoDB
und aktive TLS-Sitzungen lesend bestaetigt. Nur zwei kuenstliche Produkte;
bestehende Produktdaten, Runtime und oeffentlicher API-Einstieg hashgebunden
geschuetzt. Gepinntes offizielles SDK 20.3.0 separat in der Stage bereitgestellt.

Testharness-Zeitpruefung korrigiert: lokale Saga wurde um 11:20:24 UTC
angelegt und endet exakt 1.800 Sekunden spaeter um 11:50:24 UTC.
Stripe legte sein Objekt erst um 11:20:25 UTC an; sein identisches
expires_at liegt deshalb 1.799 Sekunden nach seinem created-Zeitstempel.
Das ist kein verkuerzter lokaler Checkoutvertrag. Observer bindet jetzt
Providerablauf an die persistierte lokale 1.800-Sekunden-Laufzeit und lehnt
abweichende Fristen/Versionen weiterhin ab. Keine Shopfachlogik geaendert.
PHP-8.4-Lint und 44 Observer-Testassertions sowie git diff --check bestanden.

Eigener begrenzter Observer laeuft; Sandboxformular mit kuenstlichen Angaben
und offiziellem verzoegert erfolgreichen Test-IBAN vorbereitet. Zustimmung
und Zahlen nicht durch den Agenten ausgefuehrt. Erst Sichtbestaetigung des
tatsaechlichen Zahlungsformulars anfordern; anschliessend persoenliche
Einreichung ab 13:48:24 Europe/Berlin, Ablauf 13:50:24. Noch kein Nachweis
des asynchronen Endzustands nach Ablauf und keine Gesamtabnahme.
Stage/Endpunkt/Transfers bleiben nur bis zum Abschluss dieses offenen
Tests temporaer vorhanden; danach eigener vollstaendiger Cleanup.
Masterkonfigurationen bleiben erhalten. Kein Cron, Deployment, Commit,
Push, Produktionszugriff oder Cutover; bestehende Testwebsite unveraendert.

### 3.31 Echter SEPA-Erfolg nach dem Checkoutzeitpunkt und vollstaendiger Cleanup

Am 14. Oktober 2026 nach persoenlicher Sichtbestaetigung und Einreichung
des Nutzers bestanden. Keine Zustimmung oder Zahlung durch den Agenten.
Lokaler Checkoutzeitpunkt: 11:50:24 UTC / 13:50:24 Europe/Berlin.
Echtes processing um 11:50:14 UTC erfasst; entscheidende weitere Probe
um 11:50:26 UTC, also NACH diesem Zeitpunkt: Stripe-Session complete/unpaid,
PaymentIntent und lokales Payment processing, Bestand 30/30 unveraendert,
Mengen 2/1 weiterhin reserviert, null Bestellungen und Mail-Outboxeintraege.
Keine virtuelle Uhr, keine manipulierten Providerzustaende, kein erneuter
MySQL-/Crash-/Backup-Praxisdurchlauf.

Echter signaturgepruefter checkout.session.async_payment_succeeded mit
Stripe-Zeitstempel 11:54:24 UTC / 13:54:24 Europe/Berlin, vier Minuten
nach dem Checkoutzeitpunkt. Session paid, PaymentIntent succeeded,
lokales Payment succeeded/verified. Normaler Payment-Worker finalisierte
genau eine confirmed-Bestellung mit zwei Positionen, Mengen 2/1, 7.770 Cent
einschliesslich einmalig 270 Cent Versand. Bestand 30/30 -> 28/29,
zwei shop_sale-Audits, beide Reservierungen converted/nicht blockierend.
Keine offenen Reviewcases. Zustimmung, produktbezogene Hinweis-/Versions-
Snapshots, Legal-Bundle-Hash und Versand-Snapshot unveraendert gebunden.

Deduplizierung und Replay im privaten Observer bestanden. Zusaetzlich
beide echten Events in umgekehrter Reihenfolge jeweils zweimal erneut
verarbeitet: vollstaendiger Datenbankfingerprint unveraendert. Kein zweiter
Bestandsabzug, keine zweite Bestellung oder Bestellnummer.
Genau eine order_confirmation fuer die kuenstliche example.com-Adresse
queued/attemptCount=0; Mengen, Summen, Versand und Legal-Hash im Payload
geprueft. Kein Brevo-Mailworker und kein Versand an diese kuenstliche Adresse.
Dieser Nachweis ersetzt nicht den getrennt bereits bestandenen echten
Bestellmail-Empfang aus 3.24 und behauptet keine erneute Mailzustellung.

Observer beendet und sein Lock frei vor Cleanup. Eigener Stripe-Testwebhook
danach per 404 als nicht mehr vorhanden nachgewiesen; oeffentlicher Endpunkt,
private Stage samt SDK, Runtime, Beobachterdateien/Markern/Cookies/Archiven
und lokale Transferdateien vollstaendig entfernt. Beide isolierten
Testdatenbanken wieder leer. Bestehende Produkt-, Runtime- und API-Dateien
hashgleich; private Datenbank- und Stripe-Konfigurationen unveraendert mit
0600 erhalten. Bestehende Testprodukte/Testwebsite bewahrt. Stripe behaelt
seine Sandbox-Zahlungshistorie; diese wurde nicht als geloescht behauptet.
Kein Cron, Commit, Push, Deployment, Produktionszugriff oder Cutover.

44 Observer-, 29 Sandbox- und 57 Site-Adapter-Assertions sowie fuenf
AP3-Stripe-PHP-Tests bestanden. git diff --check bestanden.
Nur der reale SEPA-ERFOLG nach dem Checkoutzeitpunkt ist damit geschlossen.
Der entsprechende echte FEHLER nach diesem Zeitpunkt bleibt getrennt offen;
der fruehere Fehlerfall aus 3.7 war davor und ersetzt diesen Grenzfall nicht.
Weitere UI-, Geraete- und Rechtsgates bleiben offen; keine Gesamtabnahme.

### 3.32 Echter SEPA-Fehler nach dem Checkoutzeitpunkt vorbereitet

Am 14. Oktober 2026 ausdruecklich zur autonomen Fortsetzung beauftragt,
mit Sichtbestaetigung des Zahlungstabs vor persoenlicher Einreichung.
Neue eigene private Stage cart-e2e-20261014-failure-da934f4c.
Beide bekannten Testdatenbanken zuvor leer, MySQL 8/InnoDB und aktive TLS-
Sitzungen lesend bestaetigt; erhaltene Datenbank-/Stripe-Konfigurationen
unveraendert mit 0600. Bestehende Testprodukte, Runtime/API und Testwebsite
nicht veraendert. Nur zwei kuenstliche Produkte mit Bestand 30/30 und
Mengen 2/1 fuer insgesamt 7.770 Cent einschliesslich 270 Cent Versand.

Privater Testbeobachter um erwarteten Fehlerausgang erweitert, Standard-
Erfolgsmodus unveraendert. Fehlnachweis nur mit echtem verarbeitetem,
signaturgeprueftem async_payment_failed der eigenen Sandbox-Session nach
der persistierten Zeitgrenze UND vorher beobachtetem processing nach
derselben Grenze. Final nur failed/verified, requires_payment_method mit
Fehlernachweis, Bestand 30/30, Reservierungen gemeinsam 0/0, keine
Bestellung/Mail; Reviewcases duerfen nicht offen sein. Bestehende
Fehler-Deduplizierungs-/Replaypruefung wird anschliessend verwendet.
Keine Shopfachlogik geaendert, keine virtuelle Uhr oder Statusinjektion.
79 Observer-, 29 Sandbox- und 57 Site-Adapter-Assertions, fuenf AP3-Stripe-
PHP-Tests, PHP-8.4-Lint sowie git diff --check bestanden.

Gepinntes SDK 20.3.0 separat hash-/pfadgeprueft bereitgestellt. Offizielle
verzoegert fehlschlagende SEPA-Test-IBAN anhand der
[Stripe-Checkout-Dokumentation](https://docs.stripe.com/payments/sepa-debit/accept-a-payment?payment-ui=checkout)
verifiziert. CLI-Beobachter im expliziten failure-Modus gestartet,
fehlerfrei wartend. Neues Sandboxformular mit kuenstlichen Daten vorbereitet;
Chrome meldet den eigenen Zahlungstab als ausgewaehlt. Tatsaechliche
Sichtbarkeit jetzt vom Nutzer bestaetigen lassen, noch keine Zahlung.
Persoenliches Klickfenster ab 14:40:25 Europe/Berlin, unveraenderte
Checkoutzeitgrenze 14:42:25. Kein Fehlerfall bereits als bestanden markiert.
Nach Abschluss eigenen vollstaendigen Cleanup ausfuehren; beide privaten
Masterkonfigurationen und bestehende Testprodukte erhalten. Kein Cron,
Commit, Push, Deployment, Produktionszugriff oder Cutover.

### 3.33 Fehlerfall-Vorbereitung abgelaufen, sicher verarbeitet und bereinigt

Sichtbestaetigung am 14. Oktober 2026 eingegangen. Lesende Wiederaufnahme
um 14:20:57 UTC / 16:20:57 Europe/Berlin: eigenes Klickfenster ab 14:40:25
Europe/Berlin und Checkoutende 14:42:25 waren bereits vorbei.
Stripe bestaetigt expired/unpaid, kein processing-Nachweis und kein
async_payment_failed. Der private Observer meldet unpaid_expired und ist
beendet. Sichtbares Formular nicht mit einer noch offenen Session gleichsetzen.
Keine Ursache einer ausgebliebenen Zahlungseinreichung als Codefehler behaupten.
Der echte SEPA-Fehler nach dem Checkoutzeitpunkt ist nicht nachgewiesen.
Erfolgsnachweis aus 3.31 bleibt unveraendert bestanden.

Bestaetigter unbezahlter Ablauf korrekt verarbeitet: Bestand 30/30,
Reservierungen 0/0, keine Bestellung oder Mail. Ausschliesslich echtes
checkout.session.expired verarbeitet; erneuter Worker-Lauf ohne
Bestandsmutation. Observer beendet und Dateilock frei vor Cleanup.
Eigener Sandbox-Webhook nach Entfernung per 404 als nicht mehr vorhanden
nachgewiesen, oeffentlicher Endpunkt und private Stage vollstaendig entfernt,
ebenso lokale Transferdateien. Beide Testdatenbanken wieder leer.
Bestehende Produkt-, Runtime- und API-Dateien hashgleich. Private Datenbank-
und Stripe-Konfigurationen unveraendert mit 0600 erhalten. Bestehende
Testprodukte/Testwebsite bewahrt; Produktion/main unveraendert. Kein Cron,
Commit, Push, Deployment oder Cutover. git diff --check bestanden.

Vor erneuter Sessionanlage kurze Verfuegbarkeit fuer die begleitete
30-Minuten-Pruefung abstimmen. Neuer Checkout erst danach; persoenliche
Sichtbestaetigung und Einreichung im tatsaechlichen Klickfenster erforderlich.
Keine abgelaufene Session wiederverwenden und keine Zugangsdaten erneut anfordern.

### 3.34 Autonom vorbereitete Wiederholung des SEPA-Fehlers nach Ablauf

Nach erneuter Nutzerfreigabe am 14. Oktober 2026 eigene private Stage
cart-e2e-20261014-failure-retry-fc1ff6a0 aufgebaut. Lesender Vorflug:
beide Testdatenbanken leer, MySQL 8/InnoDB, aktive TLS-Sitzungen,
private Datenbank-/Stripe-Konfigurationen mit 0600. Bestehende
Testprodukte/Runtime/API hashgebunden geschuetzt und unveraendert.
Gepinntes SDK 20.3.0 nur in der Stage; eigene zwei kuenstliche Produkte.

Erster HTTP-Checkoutversuch scheiterte vor lokaler Saga/Zahlung/Reservierung.
Gezielte lesende Diagnose: null Sagas/Payments, Bestand 30/30, reserviert 0/0.
Keinen Stripe-Ausgang erraten oder einen unklaren Vorgang duplizieren.
Danach frischer Kontext und erneute aktuelle Quote; genau eine Saga und
HTTP 201. Ursache des ersten Fehlers nicht belegt, keine unbelegte
Fachlogikfehlerbehebung. Noch keine persoenliche Zahlungseinreichung.

Neue Session um 14:32:43 UTC / 16:32:43 Europe/Berlin erstellt, lokaler
1.800-Sekunden-Vertrag und identischer Stripe-Ablauf um 17:02:43 bestaetigt.
Klickaufforderung erst ab 17:00:43 Europe/Berlin. Privater begrenzter
failure-Observer gestartet und fehlerfrei wartend. Kuenstliches
Sandboxformular vorbereitet; Browser meldet eigenen neuen Tab ausgewaehlt.
Bild im laufenden Dialog eingebettet. Keine Zustimmung oder Zahlung durch
den Agenten. Technische Arbeiten ohne weitere Eingaben fortgesetzt;
persoenliche Einreichung bleibt erforderlicher Hand-off.

79 Observer-, 29 Sandbox- und 57 Site-Adapter-Assertions, fuenf AP3-Stripe-
PHP-Tests sowie git diff --check erneut bestanden; keine Fachlogik geaendert.
Noch keine Fehlerfallabnahme. Nach Abschluss eigene Tabellen/Stage/Endpunkt/
Webhook/Transferdateien vollstaendig bereinigen, beide privaten Master-
konfigurationen und vorhandene Testprodukte erhalten. Kein Cron, Commit,
Push, Deployment, Produktionszugriff oder Cutover.

### 3.35 Vorheriger Fehler-Retest bereinigt, neuer begleiteter Versuch offen

Am 14. Oktober 2026 um 15:57:10 UTC Nutzerhinweis zur verspaeteten
Einreichung und jetzt laengerer Verfuegbarkeit aufgenommen. Vorheriger
Versuch 3.34: Observer beendet, unpaid_expired, keine processing-Probe
und kein asynchroner Zahlungsfehler. Bestaetigter unbezahlter Ablauf hielt
Bestand 30/30 unveraendert, gab Reservierungen gemeinsam frei und erzeugte
keine Bestellung/Mail. Erneuter Worker-Lauf ohne Bestandsmutation.
Eigener Webhook nach Cleanup per 404 abwesend, Endpunkt/private Stage und
lokale Transferdateien entfernt; beide Testdatenbanken wieder leer.
Bestehende Produkt-/Runtime-/API-Dateien hashgleich, Datenbank-/Stripe-
Masterkonfigurationen hashgleich mit 0600 erhalten. Keine Fehlerabnahme.

Anschliessend auf denselben nachweislich leeren, per TLS erreichbaren
MySQL-8-/InnoDB-Testzielen eigene Stage
cart-e2e-20261014-failure-guided-ce1b1489 initialisiert. Nur zwei
kuenstliche Produkte und eigene Sandbox-Webhookregistrierung;
SDK 20.3.0 hash-/pfadgeprueft. Genau ein Checkout, HTTP 201 ohne Retry,
erstellt 16:00:42 UTC / 18:00:42 Europe/Berlin. Lokaler unveraenderter
1.800-Sekunden-Vertrag und identische Stripe-Zeitgrenze 18:30:42 geprueft.
Persoenliche Klickaufforderung erst ab 18:28:42 Europe/Berlin.
Privater failure-Observer laeuft fehlerfrei; aktuelles kuenstliches
Formular vorbereitet, in Chrome ausgewaehlt und Bild im Dialog eingebettet.
Keine Zustimmung oder Zahlung durch den Agenten. Technische Fortsetzung
ohne erneute Eingabe von Zugangsdaten; noch keine neue Einreichung oder
Fehlerfallabnahme. Eigenen vollstaendigen Cleanup nach Abschluss ausfuehren,
Masterkonfigurationen und bestehende Testprodukte erhalten.
Keine Fachlogikaenderung, Produktion/main unveraendert, kein Commit,
Push, Deployment oder Cron. Erfolgsnachweis 3.31 bleibt bestanden.

### 3.36 Echter SEPA-Fehler nach der Checkoutzeitgrenze bestanden und bereinigt

Am 14. Oktober 2026 persoenliche Einreichung vom Nutzer bestaetigt.
Genau der begleitete Checkout aus 3.35 verwendet; kein neuer Checkout,
keine kuenstliche Uhr-/Statusmutation und unveraenderte 1.800 Sekunden.
Erstes echtes processing um 16:29:05 UTC / 18:29:05 Europe/Berlin.
Checkoutzeitgrenze 16:30:42 UTC / 18:30:42 Europe/Berlin. Um 16:30:44 UTC
weiterhin Stripe-Session complete/unpaid, PaymentIntent und lokales Payment
processing/verified: beide Mengen 2/1 reserviert, Bestand 30/30 und keine
Bestellung oder Mail. Kein vorzeitiges Freigeben durch den Zeitablauf.

Echtes, signaturgeprueftes checkout.session.async_payment_failed um
16:35:10 UTC / 18:35:10 Europe/Berlin, nach der Zeitgrenze und eindeutig
an die eigene Sandbox-Session und gepinnte Webhook-Version gebunden.
Inbox verarbeitet; normaler Zahlungsworker finalisiert failed/verified,
Checkout failed, PaymentIntent requires_payment_method mit Fehlernachweis.
Beide Reservierungen gemeinsam released, reserviert 0/0; Bestand 30/30
unveraendert, keine Verkaufsabbuchung, Bestellung, Sendung oder Mail.
Keine Reviewcases. Tatsächliche completed-/async_failed-Ereignisse dedupliziert
und in umgekehrter Reihenfolge jeweils doppelt erneut verarbeitet:
vollstaendiger Datenfingerprint unveraendert. Kein Mailworker gestartet.
Privater Observer meldet failure_deadline_passed und ist beendet;
Dateilock vor Cleanup nachweislich frei.

Cleanup abgeschlossen: beide isolierten Testdatenbanken vollstaendig leer,
eigener Sandbox-Webhook nach Loeschung per 404 abwesend, eigener oeffentlicher
Endpunkt, private Stage samt Runtime/SDK/Laufdaten und lokaler Transferordner
entfernt und Abwesenheit geprueft. Bestehende Produkt-/Entwurfs-/Upload-/
Runtime-/Bootstrap-/API-Dateien hashgleich. Private Datenbank- und Stripe-
Masterkonfigurationen hashgleich mit 0600 erhalten; drei bestehende reale
Testprodukte und Testwebsite unveraendert. Keine neue Cronanlage und kein
Commit, Push, Deployment, Produktionszugriff oder Cutover. Stripe behaelt
seine Sandbox-Zahlungshistorie; diese wurde nicht als geloescht behauptet.

Erfolgsnachweis 3.31 bleibt bestanden. Damit beide echten asynchronen SEPA-
Endzustaende nach der Checkoutzeitgrenze nachgewiesen; keine pauschale
Warenkorb-Gesamtabnahme. Weitere UI-, Geraete- und Rechtsgates bleiben offen.

Abschliessend PHP 8.4.23: drei relevante Lints, 79 Observer-, 29 Sandbox-
und 57 Site-Adapter-Assertions sowie fuenf AP3-Stripe-PHP-Tests bestanden.
Alle fuenf relevanten Node-Warenkorb-Vertragstests und git diff --check
bestanden. Keine Fachlogik geaendert; in diesem Abschluss nur Nachweis und
Produktionsplan aktualisiert. Keine erneute Vollregression behauptet.

### 3.37 UI-Lespruefung und Beta-Geraetevorflug, echter Geraetelauf noch offen

Am 14. Oktober 2026 zur autonomen Fortsetzung der offenen UI-/Geraetetests
beauftragt. Angemeldete Chrome-Testsitzung verwendet, keine Zugangsdaten
erneut angefordert. Vorhandene Testprodukte und private Konfigurationen
bewahrt; keine Servermutation oder neue Teststage.

Browserpruefungen: Widerrufsseite kennzeichnet ihre Fassung weiterhin als
nicht rechtlich freigegeben und beschreibt Positions-/Mengenauswahl.
Leeres Identifikationsformular bleibt durch drei required-Felder gesperrt;
vor erfolgreicher Identifikation ist kein Bestaetigungsschritt sichtbar.
Admin-Login bleibt bei leeren Pflichtfeldern gesperrt; ohne Anmeldung keine
Bestellungen oder Bestandsbedienung sichtbar. Nach dem vorherigen Runtime-
Cleanup zeigt der Warenkorb einen verstaendlichen Fehler und Neu laden,
aber keinen Checkoutbutton. Keine Reservierung oder Zahlung angefragt.
Dies sind abgegrenzte Oberflaechen-/Fail-closed-Pruefungen, keine Abnahme
von angemeldeten Adminaktionen oder bestaetigten UI-Teilwiderrufen.

Zusatzbefund: Die separate statische Mail-Vorschau unter
/admin/mail-vorschau/ zeigt noch ein historisches Einzelproduktbeispiel mit
4,90 EUR Versand und altem Test-Legal-Archiv. Sie kennzeichnet sich als
kuenstliche Vorschau ohne Datenbank-/Providerverbindung und ist kein Nachweis
des aktuellen Warenkorb-Mailvertrags. Der echte Mailnachweis bleibt gueltig;
lokale AP5-Tests bestaetigen Mehrpositionen, Hinweise, einmaligen Versand
und Positionsauswahl. Vorschauabweichung nicht stillschweigend korrigiert.

Lesender bekannter Test-Runtime-Vorflug: environment=test, derzeit kein
product-api-v5.php im permanenten Programmverzeichnis, V5-Schreib- und
Warenkorbgate false. Private Datenbank-/Stripe-Konfigurationen vorhanden
mit 0600, unveraendert erhalten. Fuer weitere dynamische UI-/Geraeteflows
ist wieder eine passend isolierte kuenstliche Runtime erforderlich; die
bisherige Testproduktverwaltung wird nicht pauschal auf V5 umgeschaltet.

Vorhandene APK erneut technisch geprueft: 1.4.0-beta.1 / Code 10,
de.steinhart.armbandrechner.test, apksigner erfolgreich und Zertifikat
identisch zum gepinnten Beta-Vertrag. Direkt aus dem APK gelesene DEX-
Eintraege enthalten Test-API, keine Produktions-API. Keine Installation,
Signatursuche oder Passwortabfrage. adb meldet kein angeschlossenes Geraet;
kein lokales AVD eingerichtet. Instrumentierungs- und echter Geraete-
Roundtrip bleiben offen. Mobil-Viewportversuch wurde nicht wirksam
(gemessene Breite nicht 390); nicht als Mobilabnahme gewertet und Override
zurueckgesetzt. Nur eigene neue Browser-Tabs, keine vorhandenen geschlossen.

PHP 8.4.23: AP4 4/4, AP5 12/12, Warenkorbvertrag, Mehrtab-CSRF und
57 Site-Adapter-Assertions bestanden; fuenf Node-Warenkorbtests und
git diff --check bestanden. Keine erneute Android-/Gesamtregression
behauptet. Nur Dokumentation aktualisiert, keine Fachlogik oder Runtime
veraendert. Kein Commit/Push/Deployment, Produktionszugriff oder Cutover.
Naechste einzelne menschliche Aktion: entsperrtes Android-Testhandy per
USB anschliessen; danach Verfuegbarkeit/Autorisierungsstatus technisch
pruefen, vor einer Installation erneut vorhandenen Paketstand feststellen.

### 3.38 Manuelle lokale Beta-Handypruefung bestaetigt

Am 14. Oktober 2026 meldet der Nutzer die auf dem Handy verwendete Version
1.4.0-beta.1 und bestaetigt alle abgefragten lokalen Sicht-/Bedienpruefungen:
Test-API angezeigt, Werte nach erneutem Oeffnen erhalten, ungueltige Eingaben
abgelehnt, Bestandsfeld vorhanden, beide Produkthinweise sichtbar und keine
Bedienungsprobleme. Damit die beschriebene lokale Handypruefung manuell
bestanden. Keine personenbezogenen Geraetedaten oder Zugangsdaten erfasst.

Keine Server-Synchronisierung, V5-Veroeffentlichung, Bestandsmutation oder
vollstaendige Geraete-E2E-Abnahme daraus ableiten. Die Unveraenderbarkeit der
serverseitigen Hinweise, Version/Hash, wiederholte Initialisierung und der
App/API/Publisher-Roundtrip bleiben separat nachzuweisen. versionCode,
Paket und Signatur der konkret installierten App nicht per ADB gelesen;
der lokale APK-Vertrag aus 3.37 bleibt davon getrennt.

Autonom ergaenzt: PHP 8.4.23 mit nur prozesslokalen Erweiterungen,
57/57 Product-API-Tests einschliesslich der V5-Hinweis-/Versions-/Hash-/
Altentwurfsvertraege sowie 13/13 Bootstrap-Tests bestanden. Kuenstliche
lokale Fixtures durch die Test-finally-Bloecke bereinigt. Erneuter lesender
ADB-Vorflug meldet weiterhin kein Geraet; nur den dafuer gestarteten
diagnostischen ADB-Dienst wieder beendet. Keine Installation oder
Instrumentierung ausgefuehrt. Kein Serverzugriff oder Runtimewechsel in
diesem Nachtrag, Testprodukte und private Konfigurationen unveraendert.
Nur Abnahmebericht und Produktionsplan aktualisiert; kein Commit/Push/
Deployment, Produktionszugriff oder Cutover. Naechster benoetigter
menschlicher Schritt fuer automatisierte Geraetepruefungen: entsperrtes
Android-Testhandy per USB mit diesem Rechner verbinden.

### 3.39 Verbindlicher manueller Handy-Pruefmodus

Nutzer legt am 14. Oktober 2026 fest: saemtliche Pruefungen am Handy
werden persoenlich und manuell durchgefuehrt. Keine USB-Verbindung,
ADB-Automation, automatische Handyinstallation oder Instrumentierung
als Voraussetzung verlangen. Die USB-Aufforderungen aus 3.37/3.38 sind
damit ueberholt; die historischen Diagnoseergebnisse bleiben unveraendert.
Die bereits bestaetigte lokale Handypruefung aus 3.38 bleibt bestanden.

Der Agent bereitet isolierte kuenstliche Serverfixtures vor, prueft
API-/Versions-/Hash-/Bestands- und Publisherergebnisse serverseitig und
bereinigt nur eigene Testdaten/Artefakte. Nutzer erhaelt jeweils kurze
manuelle Handy-Schritte und meldet deren Ergebnis; diese Rueckmeldungen
werden ausdruecklich als manuelle Nachweise dokumentiert, nicht als
automatisierte Instrumentierung oder unabhaengige Geraeteinspektion.
Lokale APK-/Unit-/Vertragstests bleiben davon getrennt moeglich.

Naechste technische Voraussetzung ist eine passend isolierte V5-Test-
Runtime. Bis sie nachgewiesen bereitsteht, keine Synchronisierung,
Veroeffentlichung oder Bestandsaktion am Handy anfordern. Bestehende
Testprodukte und private Masterkonfigurationen bleiben erhalten;
Produktion, Commit/Push/Deployment und Cutover nicht durch den geaenderten
Pruefmodus freigegeben. Keine zusaetzlichen Tests als bestanden markiert.

### 3.40 Isolierte V5-Test-API fuer manuelle Handypruefungen bereit

Am 14. Oktober 2026 ausdruecklich beauftragt und ausschliesslich testseitig
bereitgestellt: private Stage
`/home/www/carmaja-private-test/cart-e2e-20261014-v5device-9cd3831c-v5`.
Nur `/v5` wird temporaer per eigener Rewrite-Regel an ihren separaten
oeffentlichen Einstieg gebunden. Vorherige `.htaccess` privat mit Hashbindung
gesichert; bestehender API-Einstieg, Bootstrap und Runtime unveraendert.
V1-/V2-/V3-/V4-Wege werden nicht umgeschaltet. Kein Website-, Shop-,
Admin-, Webhook-, Worker- oder Providerzugang durch diesen Testadapter.

Lesender Vorflug vor jeder Initialisierung: bekannte unterschiedliche
MySQL-8-/InnoDB-Testdatenbanken dbs15962244/dbs15962566 leer, aktive TLS-
Sitzung jeweils belegt, private Masterkonfigurationen mit 0600 vorhanden.
Bekanntes fehlendes CA-/Hostidentitaetspruefen bleibt Test-Restrisiko.
Nur isoliertes Commerce-Schema mit fuenf bekannten Migrationsjournal-
Eintraegen. Keine Bestandsuebernahme bestehender Produkte.

Eigener kurzlebiger Testbenutzer und eigene Geraetetokens ausschliesslich
in der Stage; keine bisherigen Benutzer-/Geraetedateien oder Zugangsdaten
kopiert, gelesen oder ersetzt. V5-Schreib- und Warenkorbgate nur in dieser
privaten Runtime aktiv; GitHub-/Produktionspublisher deaktiviert. Der
Publisher schreibt nur die private kuenstliche Projektion der Stage,
keine Website-/Branchveroeffentlichung. Bekannte bestehende Produktkennungen
gesperrt; PUT-Namen auf HANDY-TEST bzw. V5-PROBE begrenzt, maximal 20
eigene Entwuerfe. 24-Stunden-Grenze und Shared-/Exclusive-Dateilock fuer
HTTP-Anfragen bzw. Cleanup. Nach Ablauf bleibt der V5-Einstieg fail-closed.

Echter HTTPS-Rundlauf mit kuenstlicher Probe bestanden: Anmeldung,
401 ohne Token, 426 fuer alten Client, vollstaendiges V5-PUT,
serverseitig monotone Version und kanonischer Quellhash, beide festen
Hinweise, verbotene Clientfelder abgelehnt, identischer PUT-Replay,
409 bei veralteter Version, JPEG-Upload, erneutes Laden mit Messwerten,
private Projektion exakt {version, products} in Version 4 ohne stock/
vintedUrl. Anfangsmenge 3 genau einmal angewendet; erneutes Veroeffentlichen
veraendert Bestand nicht. Separate Erhoehung auf 4, identischer Replay und
409 bei veralteter Inventarversion nachgewiesen. Keine Checkout-, Payment-,
Reservierungs-, Bestell- oder Maildaten und keine Worker-/Provideraktion.

Testadapterkorrekturen: 0755 explizit nach restriktiver umask am eigenen
oeffentlichen Ordner; Hashvergleich gegen die autoritative Darstellung
statt gegen eine abweichende Leseprojektion; Commerce-Fehler korrekt aus
httpStatus abgebildet. Keine Produkt-/Bestandsfachlogik geaendert.
Weitere Vorlaeufe brachen bei der erwarteten Altclient-Ablehnung ab;
deren Ursache wurde nicht nachgewiesen. Anschliessend gezielte Diagnose
und fuenf komplette Login-/Lese-/Altclient-Zyklen mit jeweils 426 bestanden.
Keine fehlgeschlagenen Vorlaeufe als Erfolg umdeklariert.

Vollstaendiger Rueckbau praktisch bestanden: urspruengliches Routing
hashgleich wiederhergestellt, beide Testdatenbanken leer, eigener Einstieg
und private Stage entfernt. Danach frische Stage mit korrigiertem Paket
hash-/lintgeprueft initialisiert und kompletter HTTPS-Rundlauf erneut
bestanden. Automatische Probe danach entfernt: null Nutzdatenzeilen,
null Entwuerfe/Uploads/Probetokens; nur fuenf Migrationsjournal-Eintraege
im Source-Schema, Restore vollstaendig leer. Bestehende Produkt-/Entwurfs-/
Upload-/SKU-Dateien, API-Einstieg, Originalruntime und Datenbank-/Stripe-
Masterkonfigurationen hashgleich; letztere weiterhin 0600. Testwebsite
und Basic-Auth-Datei unberuehrt.

Fuer die ausstehenden manuellen Tests bleibt nur diese eigene Stage bis
15. Oktober 2026, 17:38:38 UTC / 19:38:38 Europe/Berlin, erhalten.
Temporarer Testzugang lokal ACL-geschuetzt und benutzergebunden per DPAPI
verschluesselt, keine Geheimwerte im Chat, Repository oder Argumenten.
Lokaler und serverseitiger Klartexttransfer entfernt. Eigenes Anzeigefenster
mit Maskierung/Kopierfunktionen und geheimwertfreiem Sichtbarkeitsmarker
bereit. Lokaler Transferordner vollstaendig entfernt. Private Stage,
Testschema, Routing und lokale verschluesselte Zugangskopie nach manueller
Pruefreihe separat vollstaendig bereinigen; Masterkonfigurationen erhalten.

PHP 8.4: neue Adapter-/Smoke-/Testdateien gelintet, 27 Guardassertions,
57 Product-API-, 13 Bootstrap- und 29 Warenkorb-Vertragsfaelle bestanden.
Fuenf relevante Node-Vertragstests und PowerShell-Parser-/DPAPI-Rundvergleich
bestanden; Quellen ohne reale Stripe-/Privatschluesselmuster. Keine neue
Android- oder Gesamtregression behauptet, keine Appveraenderung, kein
USB/ADB. Kein Commit/Push/Merge, Produktionszugriff oder Produktionsdeploy.
Die API ist fuer den manuellen Geraetest bereit, der tatsaechliche Handy-
Server-Roundtrip und dynamische Admin-/Widerrufsflows bleiben offen.

### 3.41 Manuelle V5-Handyanmeldung serverseitig bestaetigt

Am 14. Oktober 2026 meldet der Nutzer V5-Testanmeldung erfolgreich.
Nur lesende Kontrolle derselben isolierten Stage aus 3.40: neuer aktiver
Geraetetoken nach dem Uebergabezeitpunkt und anschliessender authentifizierter
Lesezugriff vorhanden, ausschliesslich dem eigenen Stage-Testbenutzer
zugeordnet. Keine Benutzer-/Geraetenamen, Tokenwerte oder Zugangsdaten
ausgegeben oder im Repository dokumentiert. Der manuelle Anmeldeschritt
ist damit auch serverseitig bestaetigt; kein USB/ADB und keine neue
Anmeldung durch den Agenten.

Stage weiterhin ohne Entwuerfe und Commerce-Nutzdaten, Restore leer;
vorhandene Testprodukte und private Masterkonfigurationen hashgleich,
Routing unveraendert. Kein Speichern, Veroeffentlichen oder Bestandsvorgang
als bereits bestanden markieren. Naechster manueller Schritt: ausschliesslich
den kuenstlichen lokalen Entwurf HANDY-TEST mit 28,00 EUR, 18 cm und 8 mm
ueber Speichern und synchronisieren speichern, erneut oeffnen und Werterhalt
melden; danach serverseitig Produktmodell/Version/Hash/Hinweise kontrollieren.
Noch keine Veroeffentlichung oder Anfangsbestandsaktion anfordern.

Nutzerpraeferenz fuer kuenftige kurzlebige Testzugangsfenster festgehalten:
einfach abtippbarer Benutzername und gut lesbare zufaellig erzeugte
Passphrase statt schwer uebertragbarer Hexfolgen. Keine festen allgemeinen
Testpasswoerter, keine Ausgabe im Chat und keine Abschwaechung von
Argon2id, Login-Sperren, privater Speicherung oder Befristung. Aktuellen
bereits angemeldeten Zugang unveraendert lassen, nicht erneut abfragen.
Nur Nachweisdokumentation aktualisiert; kein Commit/Push/Merge, Runtime-
oder Produktionswechsel. Manueller Handy-Server-Roundtrip bleibt offen.

### 3.42 Manueller V5-Entwurf-Roundtrip serverseitig bestaetigt

Am 14. Oktober 2026 bestaetigt der Nutzer: V5-Testentwurf synchronisiert,
Werte und Hinweise beim erneuten Oeffnen erhalten. Getrennte ausschliesslich
lesende Kontrolle derselben isolierten Stage aus 3.40 bestanden: genau
ein kuenstlicher HANDY-TEST-Entwurf, Produktmodell 4 und serverseitige
Produktversion 1. Gespeicherter sourceHash ist gueltig und entspricht
sowohl der kanonischen autoritativen Darstellung als auch ihrer erneuten
Hashberechnung; keine Hashwerte ausgegeben oder dokumentiert.
Preis 2800 Minor Units / EUR, Armbandumfang 18 cm und Perlengroesse 8 mm
stimmen. Hinweiszuordnung natural-stones-v1 und beide verbindlichen Texte
stimmen exakt; keine Duplikate. Zwei Bildzuordnungen vorhanden, daraus
noch keinen separaten Handy-Bild-/Publisher-Nachweis ableiten.

Entwurf weiterhin draft und salesEnabled=false, noch keine private
oeffentliche Projektion. Source ausser dem Migrationsjournal ohne
Commerce-Nutzdaten, Restore vollstaendig leer; aktive TLS-Verbindungen
ueber bestehenden Testvertrag. Vorhandene Testprodukte und private
Masterkonfigurationen gegen geschuetzte Dateimenge/hashgleich geprueft,
Routing unveraendert. Keine Produkt-, Datenbank-, Provider- oder Runtime-
Mutation durch diese Kontrolle, kein USB/ADB, Commit/Push/Merge oder
Produktionszugriff. Nur diese Nachweisdokumentation aktualisiert.

Manuelles Speichern/Synchronisieren und erneutes Oeffnen ist abgegrenzt
bestanden. Naechster einzelner Handy-Schritt: fuer denselben kuenstlichen
Entwurf Anfangsmenge 3 mit Anfangsbestand einmalig vormerken erfassen,
noch nicht veroeffentlichen. Danach getrennt nur die Vormerkung und
ausbleibende Bestandsinitialisierung vor der Veroeffentlichung kontrollieren.
Publisher-/Bestands-Roundtrip, dynamische UI-Flows und Gesamtabnahme
bleiben offen. Eigene temporaere Stage weiterhin fuer die manuelle
Pruefreihe erhalten; anschliessender Cleanup nach Vertrag aus 3.40.

### 3.43 Manuelle V5-Anfangsbestandsvormerkung serverseitig bestaetigt

Am 14. Oktober 2026 bestaetigt der Nutzer Anfangsbestand 3 vorgemerkt.
Getrennte ausschliesslich lesende Pruefung derselben isolierten Stage
aus 3.40 bestanden: genau eine inventory_initializations-Zeile fuer
denselben kuenstlichen HANDY-TEST-Entwurf, target_on_hand=3 und
applied_at=NULL, mit vorhandenem Operations- und Akteursbezug. Keine
Identitaets-/Operations-/Geheimwerte ausgegeben oder dokumentiert.
Noch kein commerce_products-/commerce_inventory-Eintrag, keine
Reservierung, Zahlung, Bestellung oder Mail; alle anderen Source-
Nutzdatentabellen leer. Restore weiterhin vollstaendig leer.

Entwurf weiterhin draft und salesEnabled=false, keine private oeffentliche
Projektion. Durch die der Vormerkung vorgeschaltete Synchronisierung
serverseitige Produktversion 2; 2800 Minor Units / EUR, 18 cm, 8 mm,
natural-stones-v1 und beide Hinweistexte unveraendert. Gespeicherter Hash
entspricht erneut der kanonischen autoritativen Darstellung. Beide
Bilddateien ueber den produktiven V4-Bildvalidator ausschliesslich lesend
als innerhalb eigener Uploadpfade liegende gueltige JPEGs bestaetigt.
Bestehende Testprodukte und private Masterkonfigurationen hashgleich,
Routing unveraendert; aktive TLS-Verbindungen ueber bestehenden Vertrag.

Vormerkung vor Veroeffentlichung ist damit abgegrenzt bestanden. Noch
keinen Nachweis der Anwendung, des Replays oder der Bestandsanzeige
am Handy ableiten. Naechster einzelner manueller Schritt: ausschliesslich
HANDY-TEST ueber Vorschau und veroeffentlichen / Jetzt veroeffentlichen
in derselben eigenen V5-Stage veroeffentlichen. Privater lokaler Publisher,
keine Testwebsite-/GitHub-/Produktionsveroeffentlichung und kein Checkout.
Danach getrennte lesende Kontrolle von Projektion, Hash und genau
einmaligem Anfangsbestand. Handy-Bestands-/Publisher-Roundtrip und
Gesamtabnahme weiter offen, eigener Stage-Cleanup nach Abschluss.
Nur Nachweisdokumentation geaendert; kein Commit/Push/Merge oder
Produktionszugriff, keine Backendmutation durch diese Kontrolle.

### 3.44 Manuelle V5-Testveroeffentlichung und Anfangsbestand bestaetigt

Am 14. Oktober 2026 bestaetigt der Nutzer HANDY-TEST veroeffentlicht.
Getrennte ausschliesslich lesende Kontrolle derselben eigenen V5-Stage
aus 3.40 bestanden: genau dieses kuenstliche Produkt published,
Produktmodell 4 und serverseitige Produktversion 4. Autoritativer
gespeicherter sourceHash stimmt mit erneut kanonisierter Darstellung,
Commerce-Produktlesemodell und privater oeffentlicher Projektion ueberein.
28,00 EUR, 18 cm, 8 mm, beide festen Hinweise und zwei validierte JPEGs
erhalten. Projektion exakt {version, products} mit Version 4 und genau
einem Produkt, kein stock oder vintedUrl. Keine Hash-/Geheimwerte ausgegeben.

Anfangsbestandsvormerkung angewendet: target_on_hand=3 und applied_at
gesetzt. commerce_inventory mit onHand=3, reserved=0, available=3,
quantityConfirmed=true und inventoryVersion=1. Genau ein Bestandsaudit
mit previous_on_hand=0, target_on_hand=3, initial_count und Inventarversion
1; Idempotenzschluessel, Korrelations- und Akteursbezug entsprechen der
Vormerkung. Genau ein Commerce-Produkt und eine Projektionsoperation;
alle uebrigen Nutzdatentabellen leer, Restore leer. Damit genau einmalige
Erstanwendung nachgewiesen, noch kein zusaetzlicher manueller Replaytest.

Nur privater lokaler Publisher in eigener Stage, GitHub- und Produktions-
Publisher deaktiviert, keine Stripe-/Brevo-Konfiguration in dieser Runtime.
Keine Testwebsite-Veröffentlichung, kein Checkout, Payment, Bestellung,
Reservierung, Mail oder Worker. Bestehende Testprodukte und private
Masterkonfigurationen gegen geschuetzte Dateimenge/hashgleich bestaetigt,
Routing unveraendert, aktive TLS-Verbindungen ueber bestehenden Vertrag.
Keine Servermutation durch diese Kontrolle, kein USB/ADB, Commit/Push/
Merge oder Produktionszugriff. Nur Nachweisdokumentation aktualisiert.

Manueller App/API/privater-Publisher-Veröffentlichungsweg ist abgegrenzt
bestanden. Naechster einzelner Handy-Schritt: fuer HANDY-TEST Bestand
neu laden und Anzeige Vorhanden: 3, reserviert: 0, verfuegbar: 3 melden,
ohne neue Bestandsaktion. Weitere manuelle Bestandspflege und erneute
Synchronisierung ohne Bestandsmutation bleiben offen; keine Gesamt-
abnahme. Eigene Stage fuer diese Tests erhalten, danach vollstaendiger
Cleanup nach Vertrag aus 3.40; bisherige Testprodukte/Masterconfigs erhalten.

### 3.45 HANDY-TEST kontrolliert auf der Testwebsite sichtbar

Am 14. Oktober 2026 beauftragt der Nutzer ausdruecklich die Anbindung
von HANDY-TEST an die Testwebsite bei Erhalt bestehender Testprodukte
und privater Konfigurationen. Lesender Vorflug zeigt drei ausgelieferte
Produkte, aber nur zwei in der alten privaten Root-Projektion. Deshalb
nicht diese veraltete Projektion und nicht die Produktionsprodukte des
Entwicklungsworktrees als Websitequelle verwendet, sondern die drei
unveraenderten oeffentlichen Datensaetze des tatsaechlichen Testwebsite-
Basiscommits 78a90893347f836d0e479ea405d4814e38308ff5. Dazu genau
die private V4-Projektion von HANDY-TEST aus Stage 3.40, gebunden an
Produktversion 4 und kanonischen sourceHash. Keine autoritativen
Produktdaten, Masterconfigs oder Publisher-Schalter geaendert.

Neuer ausschliesslich privater Testhelfer cart-v5-website-test.php:
strikte Pfad-/Produkt-/Versions-/Manifestguards, Symlinkverbot und
Hashpruefung aller 37 Overlaydateien. Erst Ruecksicherungsjournal 0600,
danach pro Datei atomare Aktivierung nur unter carmaja-test-site:
Startseite/Katalog-RSC, Produktuebersicht, HANDY-TEST-Detail-RSC/Bilder
und benoetigte _next-Assets. Bestehende drei Detailseiten, Legal-/Admin-/
Shopseiten, Apache-/Basic-Auth-Einstellungen und sonstige Dateien gegen
die vollstaendige Dateimenge/hashgleich erhalten. Zwei alte gleichnamige
SKU-Bilddateien waren in keiner bestehenden HTML-/RSC-Seite referenziert;
vor temporaerer Ersetzung privat gesichert, nicht verworfen.

Praktischer Rueckweg bestanden: Overlay installiert, vollstaendig
entfernt und Original-Website-Dateimenge inklusive alter Bilder hashgleich
wiederhergestellt; danach identisches Overlay erneut aktiviert und
verifiziert. Kein offizielles Deploymentmanifest umgeschrieben und kein
GitHub-Publisher oder Workflow gestartet. Der Overlayjournal ist als
gesonderter temporaerer Teststand verbindlich. Neuer V5-Cleanupguard
verweigert das Entfernen der Stage, solange deren Websiteoverlay nicht
nachweislich wiederhergestellt ist. Reihenfolge am Ende der manuellen
Pruefreihe: cart-v5-website-test.php remove, Rueckweg verifizieren,
danach cart-v5-device-test.php cleanup und eigene lokale Zugangskopie
bereinigen. Ruecksicherung/Journal bleiben bis dahin bewusst erhalten.

Live im bereits angemeldeten Chrome geprueft: Uebersicht mit HANDY-TEST
und allen drei bisherigen Produkten; Navigation zur neuen Detailseite,
18 cm / 8 mm, Beschreibung, zwei geladene Bilder, beide festen Hinweise,
zweites Bild in Grossansicht und Rueckkehr zur Detailseite. Bestehende
Footerlinks weiterhin vorhanden. Websitepreis-/Kaufweg bleibt fail-closed:
Live-Verfuegbarkeit momentan nicht erreichbar, In den Warenkorb deaktiviert.
Keine neue Shop-/Stripe-/Brevo-Runtime und keine Testzahlung beauftragt
oder ausgefuehrt; keine statische Bestandsannahme eingefuehrt. Dies ist
eine kontrollierte statische Sichtpruefung, keine allgemeine automatische
App-Publisher-Website-Anbindung fuer spaetere Aenderungen.

Nach Livepruefung getrennte lesende DB-/Dateikontrolle: onHand=3,
reserved=0, available=3, inventoryVersion=1, Restore leer, keine Checkout-,
Payment-, Reservierungs-, Bestell- oder Maildaten. Bestehende Testprodukte
und private Datenbank-/Stripe-Masterkonfigurationen hashgleich, 0600
erhalten. Eigene Incoming- und lokale Transferartefakte vollstaendig
entfernt; temporaere Websiteansicht und privater Rueckweg fuer die
manuelle Pruefreihe erhalten. Produktionsressourcen nicht angesprochen.
CARMAJA_TEST_DEPLOY_ENABLED lesend bestaetigt false.

Tests: npm ci bestanden, lint:test bestanden, 181 Node-Tests und die
anschliessende Deployment-Shell-Suite bestanden; deren optionaler
PHP-Klickstatistiktest meldet mangels php im Shell-PATH uebersprungen
(Produktionsbereich unberuehrt). Echter Vierprodukt-build:test samt
strikter Exportpruefung bestanden, separate Geheimwert-/Produktions-URL-
Pruefung des Overlays bestanden. PHP 8.4: Helper/Guardtests gelintet,
39 neue Overlay- und 27 V5-Guardassertions, 57 Product-API- sowie
13 Bootstrapfaelle bestanden. git diff --check bestanden. npm ci meldet
10 bestehende Dependency-Auditbefunde (9 high, 1 critical); keine
ungefragte Abhaengigkeitsaktualisierung oder umfassende Sicherheits-
freigabe daraus abgeleitet. Keine Appaenderung oder neue Androidtests,
kein Commit/Push/Merge, kein Produktionsdeployment oder Cutover.

### 3.46 Waermere, mystische Testwebsite und kompakte Produkthinweise

Stand: 15. Oktober 2026. Auf ausdruecklichen Gestaltungsauftrag nach der
Arbeitspause umgesetzt, ausschliesslich im Warenkorb-Worktree und in der
geschuetzten Testwebsite. Keine neue Warenkorb-Gesamtabnahme.

- Pflaume, warmes Creme, Salbei und Gold; feine dekorative Mondphasen und
  Stern-/Kreislinien. Ruhige Formen und persoenlichere Start-/Katalogtexte.
  Keine Wirkungsversprechen, neuen Produktdaten oder geaenderten Rechtstexte.
- Produkthinweise unter den Material-/Groessenangaben: Ueberschrift 12 px
  wie die dortigen Bezeichnungen, Hinweistext 14 px. Beide verbindlichen
  Texte bleiben unveraendert, vollstaendig sichtbar und semantisch gegliedert.
- Betroffen: `app/site.css`, `app/page.tsx`, `app/armbaender/page.tsx`,
  `app/armbaender/[slug]/page.tsx`, `content/site-content.ts` und der neue
  rein dekorative Baustein `components/celestial-ornament.tsx`.
- Temporarer Testadapter `scripts/cart-v5-website-style-test.php` mit
  `tests/php/cart-v5-website-style-test.php`: hashgebundene Darstellung auf
  dem vorhandenen HANDY-TEST-Overlay. Andere Seiten erhalten ausschliesslich
  den neuen Stylesheet-Verweis; bestehende Texte, Bilder, Skripte und
  Rechtstextversionen bleiben erhalten. Alle 25 vorhandenen HTML-Seiten
  verwenden nach lesender Abschlusspruefung dieselbe neue CSS-Datei.
- Installieren, vollstaendiges hashgleiches Ruecksichern zur vorherigen
  HANDY-TEST-Vorschau und erneutes Installieren praktisch bestanden.
  Journal wird vor dem ersten Website-Schreibzugriff privat angelegt;
  unbeteiligte Dateiaenderungen und Assetkollisionen fuehren zum Abbruch.
- Live geprueft: Startseite, HANDY-TEST mit beiden Bildern/Hinweisen,
  unveraenderter Kaufsperre und bestehende Ueber-mich-Seite. Lokal zusaetzlich
  Katalog, 390-px-Mobilansicht, Menue/Escape und fehlender horizontaler
  Ueberlauf. Dekorationen werden von assistiven Technologien ignoriert.
- Pruefungen: `npm ci`, `npm run lint:test`, 181 Node-Tests und
  Deployment-Shellsuite bestanden; Testbuild mit den vier unveraenderten
  Testprodukten einschliesslich Exportpruefung bestanden. Build nach den
  Node-Tests separat ausgefuehrt, da deren Exporttest denselben Ausgabeordner
  verwendet. PHP-8.4-Lint, 16 neue und 39 bestehende Vorschauguards bestanden.
  Der optionale PHP-Klicktracking-Shelltest bleibt mangels PHP im Shell-PATH
  uebersprungen; kein Produktionshosting-Lint. Die bekannten npm-Auditmeldungen
  des bestehenden Lockfiles (9 high, 1 critical) bleiben unveraendert offen.
- Eigene SFTP-Transferdateien/-ordner bereinigt. Private Ruecksicherungsdaten
  und der Testadapter bleiben fuer den spaeteren Rueckbau erhalten.
  Bestehende Produkte, HANDY-TEST und private Konfigurationen unveraendert;
  keine Datenbank-/Bestandsmutation, Zahlungs-, Mail- oder Appaenderung.
- Kein Commit, Push, Merge, Workflowstart oder Produktionszugriff.
  `CARMAJA_TEST_DEPLOY_ENABLED` unveraendert `false`.

**Ergaenzte Cleanup-Reihenfolge:** Zuerst
`cart-v5-website-style-test.php remove` und hashgleiche Ruecksicherung
pruefen, dann `cart-v5-website-test.php remove`, erst danach den bestehenden
V5-Stage-Cleanup. Der alte Websitevergleich verweigert einen vorzeitigen
Rueckbau bei noch aktivem Gestaltungs-Overlay. Die neue private Datei
`website-style-journal.json` und der Adapter duerfen daher vorher nicht
geloescht werden. Stage-Laufzeit und offene manuelle Handy-/Rechtsgates
aus 3.40 bis 3.45 bleiben unveraendert.

### 3.47 Einheitliche Gestaltung aller oeffentlichen Seiten (15. Oktober 2026)

- Nutzerwahl fuer den Einstieg: „Edelsteinarmbaender mit Charakter.“;
  Ritual-Spruch und vorherige Herzensstuecke-Zeile entfernt.
- Gemeinsame Farbvariablen auch fuer Rechtsseiten und Formulare. Mondornamente
  auf den Informationsseiten, gemeinsamer Footer, gestalteter Warenkorb sowie
  vollstaendiger Seitenrahmen fuer Abbruch, Fehler und Nicht-gefunden-Seite.
  Admin nur optisch angepasst; keine Authentifizierungs-/Kauflogik geaendert.
- Alle 24 HTML-Dateien des frischen Vier-Produkte-Testexports bereitgestellt.
  Der separate bestehende Admin-Mailvorschaupfad bleibt inhaltlich erhalten;
  dessen Stylesheetbindung wird weiterhin mitgefuehrt. Keine zweite Datenquelle.
- Private Testvorschau-Allowlist erlaubt neue RSC-Dateien ausschliesslich fuer
  bereits vorhandene Routen. Sieben Rechtsseiten samt Archiven vor Aktivierung
  auf identischen sichtbaren Hauptinhalt geprueft. 145 gebundene Dateiaenderungen;
  Installieren -> Ruecksichern -> erneutes Installieren praktisch bestanden.
- npm ci und lint:test bestanden; 181 bestehende Node-Tests sowie zwei neue
  Gestaltungstests und Shelltests bestanden. PHP 8.4: Lint und 23 Style-Guards
  sowie 39 bestehende Vorschau-Guards bestanden. Testbuild/Export bestanden.
  Beim ersten Build fehlte die prozesslokale Testfixture-Kennzeichnung; der
  Schutz stoppte korrekt, danach mit richtiger Testumgebung erfolgreich.
- Desktoppruefung aller oeffentlichen Seitentypen lokal und live bestanden;
  sechs mobile Seitentypen ohne horizontalen Ueberlauf, Menue/Escape geprueft.
  Produkthinweise weiterhin 14px, Ueberschrift 12px. Keine Kauf-/Widerrufseingabe
  oder neue E2E-Gesamtabnahme. Bekannte npm-Auditmeldungen und optionaler
  Shell-PHP-Skip aus 3.46 bleiben unveraendert.
- HANDY-TEST-Upload und Websitekopie dateigleich: 01.jpg 1600 x 1490 Pixel,
  02.jpg 1506 x 1600 Pixel. App begrenzt die laengste Bildseite auf 1600 Pixel;
  keine erneute Bildbearbeitung fuer diese Gestaltung.
- Testprodukte und geschuetzte private Konfigurationen nachweislich erhalten.
  Eigene lokale Vorschau-/Transferdateien und serverseitige Stagingdateien
  entfernt; aktive Testvorschau und benoetigte private Ruecksicherung erhalten.
  Keine App-/API-/Bestands-/Rechtstextaenderung, kein Commit/Push/Workflowstart,
  keine Produktionsaenderung. Deployment-Gate bleibt false. Rueckbau weiterhin
  zuerst Style-Overlay, danach HANDY-Websiteoverlay und erst dann V5-Stage.

### 3.48 Hoehere Testbildaufloesung und getrennte Vorschauen (15. Oktober 2026)

- Test-App und Test-API: JPEG, maximal fuenf Bilder, 3200 Pixel laengste
  Seite und 5 MiB pro Datei. Einzelbild-Upload bleibt sequenziell. Download
  und Import pruefen dieselbe Groessenobergrenze. Produktionsziel behaelt
  explizit 1600 Pixel/1 MiB; keine Produktionskonfiguration geaendert.
- Android `ProductImagePolicy` skaliert proportional, niemals hoch und nicht
  auf Nullpixel. Bestehende lokale/serverseitige Bilder werden nicht neu
  gespeichert. Fuer mehr echte Details ist spaeter die erneute Auswahl eines
  hoeher aufgeloesten Originals in der neuen Beta notwendig.
- API prueft Dimensionen bereits vor der Bitmap-Decodierung, begrenzt Bytes,
  kontrolliert JPEG-Inhalt und bereinigt EXIF/Standort-/angehaengte Daten.
  Geschuetzter V4/V5-Bilddownload akzeptiert die groesseren Testbilder.
- Statischer Testexport erzeugt mit fest gepinntem, bereits vorhandenem
  Sharp 0.35.3 drei separate JPEG-Vorschauen (240, 640, 1200 Pixel).
  Keine Vergroesserung kleiner Originale, keine Metadatenuebernahme.
  Pfade sind auf SKU, nummeriertes Foto, freigegebene Groesse und oeffentlichen
  Quellhash gebunden. Produktmodell, oeffentliche JSON-Whitelist, Versionen,
  sourceHash und Originalpfade bleiben unveraendert. Produktion verwendet
  weiterhin ihren bisherigen Bildpfad; keine Produktionsaktivierung.
- Lokale Nachweise: 59 Android-Unit-Tests ohne Fehler; lintDebug bestanden.
  PHP 8.4: Lint, 58 Product-API-, 13 Bootstrap-, 20 Admin-Tests bestanden.
  npm ci offline, lint:test, alle 186 Node-Tests und Deployment-Shelltests
  bestanden. Optionaler Shell-PHP-Klickstatistiktest weiterhin uebersprungen
  (kein PHP im PATH), geaenderte PHP-Dateien separat mit PHP 8.4 geprueft.
  Der Offline-Installationslauf ist kein neuer Online-Vulnerability-Nachweis.
  Testbuild mit den vier bestehenden Produkten und Exportpruefung bestanden.
- Neuer kuenstlicher, zusaetzlich token-geschuetzter Web-SAPI-Test unter einem
  eigenen privaten `image3200-*`-Verzeichnis: echter HTTPS-V5-PUT, JPEG-Upload
  3200 x 2400 mit mehr als 1 MiB, geschuetzter Download, erneutes Lesen mit
  stabilem Hash, Ablehnung von 3201 Pixeln und mehr als 5 MiB ohne Mutation
  des zuvor gespeicherten Produkts. Nicht authentifizierte Anfragen abgelehnt.
  Kein Datenbankzugriff und keine bestehenden Produkte im Probe-Lauf.
  Initialer Probe-Bootstrap stoppte wegen fehlender Kopplung der V5-Schalter;
  rein synthetische Konfiguration korrigiert, danach vollstaendig bestanden.
- Genau zwei private Programme der vorhandenen isolierten Handy-V5-Stage
  hashgebunden und rollbackfaehig aktualisiert. Root-Test-API, Runtime,
  Benutzer/Geraete, Produkte, Entwuerfe, Uploads, Zaehler und bestehende
  private Datenbank-/Stripe-Konfigurationen unveraendert nachgewiesen.
- Testwebsite: 24 neue Vorschaudateien fuer acht bestehende Fotos, 171
  manifestgebundene Dateiaenderungen. Installieren -> hashgleich ruecksichern
  -> erneut installieren bestanden. Alle vorhandenen sichtbaren Haupttexte
  und Originalbilder identisch; 640-Pixel-Vorschauen zusammen 606989 Byte
  statt 3306704 Byte Originaldaten (rund 82 Prozent weniger).
- Live-Browser: Uebersicht laedt 640-Pixel-Dateien, Detailseite 1200 Pixel,
  weitere Fotos 240 Pixel. Kein Originalbild in den geschlossenen
  Produktgalerien oder Produkt-Preloads; Original erst im geoeffneten Dialog.
  Wechseln, Schliessen/Escape und Fokusrueckkehr bestanden. HANDY-TEST bleibt
  bei den vorhandenen Originalen 1600 x 1490 und 1506 x 1600. Kaufweg bleibt
  bei nicht erreichbarer Live-Runtime fail-closed; keine neue Commerce-Abnahme.
- Kuenstlicher Entwurf, Upload, Token, private Probe-Konfiguration, Programme
  und HTTP-Endpunkt vollstaendig entfernt; Test-Transferpaket und lokale
  Transferdateien bereinigt. Erforderliche private Rollbacknachweise fuer
  aktive Testvorschau und zwei Programme bleiben erhalten. Rueckbau weiterhin
  erst Style-Overlay (nun inklusive Vorschaubilder), dann HANDY-Overlay, dann
  V5-Stage. Keine Verlaengerung der bestehenden Stage-Laufzeit.
- Beta-Ziel 1.4.0-beta.2 / Code 11, Testpaket/Zertifikatsvertrag unveraendert.
  `assembleBeta` korrekt fail-closed abgebrochen: lokale stabile Signierung
  fehlt. Kein Ersatzschluessel/Debug-Signing, keine Signaturdaten gesucht.
  Signierter Beta-Build und anschliessender manueller Handy-Uploadnachweis
  bleiben offen. Bisherige Handyabnahme von beta.1 gilt nicht fuer diese
  Bildaenderung. main, Produktionsressourcen und Produktions-App unveraendert;
  kein Commit/Push/Workflowlauf, `CARMAJA_TEST_DEPLOY_ENABLED=false`.

### 3.49 Signierte Bild-Testbeta und entfernte Hero-Ornamente

Nachtrag 15. Oktober 2026 zum inzwischen ausgefuehrten separaten Nutzerauftrag:
Der in 3.48 offene signierte Build ist durch GitHub Actions geschlossen.
Run [38727800430](https://github.com/Bumpers210/armband-rechner/actions/runs/38727800430)
ist fuer Commit `3cc5a703fa42e0bd4cc195316ccc1f96aa4e2ff6` erfolgreich;
Job `build-test-apk` ebenfalls erfolgreich. Artefakt:
`Carmaja-Perlen-Produktverwaltung-Test-1.4.0-beta.2-code11`.
Paket `de.steinhart.armbandrechner.test`, stabiler Beta-Zertifikatsvertrag
und Test-API-Bindung sind im bisherigen APK-Nachweis geprueft. Der neue
Workflow-Erfolg wurde beim Abnahmelauf erneut ausschliesslich lesend bestaetigt.
Kein neuer Build, Push oder Signaturwechsel in diesem Abnahmelauf.
Der manuelle 3200-Pixel-Originalupload mit dieser Beta ist weiterhin offen.

Die anschliessend beauftragte Entfernung der Mond-/Kreisornamente betrifft
nur das Startseitenfoto. `app/page.tsx`, `app/site.css` und der zugehoerige
Gestaltungstest entfernen bzw. sichern ausschliesslich diese Dekoration.
Die vorherige Testbereitstellung ist durch den privaten Overlayjournal
gebunden; dessen vollstaendiger Dateivergleich wurde jetzt erneut bestanden.
Kein weiterer Website-Deploy in diesem Abnahmelauf, keine Bild-, Produkt-,
Rechtstext- oder Produktionsaenderung.

### 3.50 Erneute Abnahmepruefung bei erhaltener Handy-Teststage

15. Oktober 2026, ausschliesslich Warenkorb-Worktree und IONOS-Testumgebung.
Bestehende Zahlungs-, Parallelitaets-, Restore- und Zeitgrenznachweise wurden
nicht durch neue Zahlungen oder einen Datenbank-Neuaufbau wiederholt.

| Erneut ausgefuehrte Pruefung | Ergebnis |
| --- | --- |
| PHP 8.4.23: 17 `cart-*-test.php` sowie Product-API, Bootstrap, AP4 und AP5 | 21 Suites bestanden; Product-API 58, Bootstrap 13, AP4 4 und AP5 12 Faelle |
| PHP-Lint der derzeit 15 geaenderten/neuen PHP-Dateien | bestanden; Produktionshosting ausgeschlossen |
| `npm run lint:test` und `npm test` | bestanden; 187 Node-Tests und anschliessende Shellsuite |
| `npm run build:test` mit kuenstlicher Standardfixture | TypeScript, statischer Export und Testexportpruefung bestanden; nicht deployt |
| `testDebugUnitTest` und `lintDebug` | bestanden; 59 Unit-Tests, keine Fehler oder Skips |
| `git diff --check` | bestanden |
| Source/Restore, MySQL 8, InnoDB, aktive TLS-Sitzungen | lesend bestaetigt; getrennte Ziele, Restore leer |
| HANDY-TEST | Version/Hash/Projektion konsistent; onHand 3, reserved 0, available 3, inventoryVersion 1 |
| Bestehende Testprodukte und private Masterkonfigurationen | geschuetzter Dateivergleich unveraendert; keine erneute Eingabe erforderlich |
| Aktive Testwebsite | kompletter Overlay-Dateivergleich bestanden; kein Deployment |

Die Windows-POSIX-Rechtepruefungen in zwei Setup-Suites und der optionale
Shell-PHP-Klickstatistiktest bleiben als plattformbedingte Skips ausgewiesen.
Sie ersetzen keinen neuen IONOS-Rechtenachweis. Die vorhandenen Dependency-
Auditbefunde aus 3.46 (9 high, 1 critical) sind weiterhin nicht bereinigt;
dieser Lauf ist keine umfassende Sicherheits- oder Produktionsfreigabe.

Aktueller Browsernachweis auf der geschuetzten Testwebsite:

- Warenkorb meldet bei fehlender Shop-Runtime nachvollziehbar, dass er nicht
  erreichbar ist und nichts reserviert wurde. Neu laden bleibt bedienbar;
  kein Checkout oder statischer Bestandsersatz.
- Widerruf zeigt die Testkennzeichnung und verlangt Bestellnummer, Namen
  und E-Mail. Leere Pflichtfelder werden nativ abgewiesen. Eine kuenstliche
  Identifikationsanfrage bei fehlender Runtime bleibt vor der Bestaetigung;
  keine Erklaerung gespeichert. Der sichtbare Fehlertext `Failed to fetch`
  ist noch technisch und wird als offener UI-Punkt festgehalten.
- Admin zeigt ohne Anmeldung nur Login, keine Bestell-/Bestandsdaten.
  Pflichtfelder und Passwortmaskierung vorhanden. Kein Adminlogin, keine
  neue Benutzeranlage oder Bestandsaktion durch diese Sichtpruefung.

Das ersetzt ausdruecklich nicht die noch offenen angemeldeten Admin- und
zweistufigen Teilwiderrufs-UI-Flows mit eigener kuenstlicher Testbestellung.
Die derzeitige V5-Handystage ist absichtlich kein aktiver Shopbetrieb und
enthaelt keinen Adminbenutzer. Der alte leere-DB-Initialisierungs-/Cleanup-
Adapter darf nicht auf die inzwischen befuellte HANDY-TEST-Source angewendet
werden. Ein weiterer dynamischer UI-Nachweis braucht einen getrennten,
leeren Fixturebereich; vorhandene Daten/Privateinstellungen bleiben erhalten.

Abschlusskontrolle: genau ein Commerce-Produkt und eine Inventarzeile;
keine Checkouts, Zahlungen, Reservierungen, Bestellungen, Mails oder
Widerrufe. Keine neuen serverseitigen Testdateien, Endpunkte, Cronjobs,
Credentials oder Testdatensaetze angelegt; daher kein neuer Remote-Cleanup.
Vorhandene V5-Stage, sichtbares HANDY-TEST und erforderliche private
Ruecksicherungsjournale bewusst erhalten. Die bestehende Laufzeit endet
weiterhin am 15. Oktober 2026 um 19:38:38 Europe/Berlin, nicht verlaengert.
Die eigenen reinen Prueftabs werden geschlossen; lokale Build-/Testberichte
bleiben unversionierte Nachweise. `CARMAJA_TEST_DEPLOY_ENABLED=false` frisch
lesend bestaetigt. Kein Commit, Push, Merge, Deployment oder Produktionszugriff.

Naechste notwendige Nutzereingabe: HANDY-TEST in der angemeldeten Test-App
oeffnen, Bestand neu laden und nur die Anzeige 3 vorhanden / 0 reserviert /
3 verfuegbar bestaetigen. Noch nicht speichern, erneut veroeffentlichen oder
Bestand aendern. Danach folgen getrennte manuelle Bestands- und Bildnachweise;
die Warenkorb-Gesamtabnahme bleibt offen.

### 3.51 Manuelle Handy-Bestandsanzeige bestaetigt

15. Oktober 2026: Nutzer bestaetigt die nach erneutem Laden sichtbare Anzeige
3 vorhanden / 0 reserviert / 3 verfuegbar. Die anschliessende unabhaengige,
ausschliesslich lesende Serverkontrolle bestaetigt denselben Zustand bei
Inventarversion 1 und Produktversion 4. Genau eine Anfangsbestandsvormerkung
und genau ein Audit 0 -> 3 mit Grund `initial_count`; keine zusaetzliche
Bestandsinitialisierung durch Anzeige oder erneutes Laden.

Quellprodukt, kanonischer Hash, private oeffentliche Projektion und aktiver
Websiteoverlay konsistent. Bestehende Testprodukte und geschuetzte private
Konfigurationen unveraendert, Source/Restore getrennt und ueber aktive TLS-
Sitzungen mit MySQL 8/InnoDB erreichbar. Restore weiterhin leer; keine
Checkouts, Reservierungen, Zahlungen, Bestellungen, Mails oder Widerrufe.
Die erste reine Kontrollabfrage verwendete einen nicht vorhandenen Audit-
Tabellennamen und wurde ohne Mutation abgewiesen; nach Abgleich mit dem
Schema (`inventory_adjustments`) vollstaendige Kontrolle bestanden.

Der Teilnachweis **Handy-Bestandsanzeige bestanden** ist damit geschlossen.
Keine App-/API-Aenderung, kein erneuter Kompletttest, kein Commit, Push,
Deployment, Produktionszugriff oder neuer serverseitiger Testartefakt.
Nur Abnahmebericht/Plan aktualisiert und `git diff --check` ausgefuehrt.
Die zuvor erhaltenen Testprodukte, V5-Stage, private Konfigurationen und
Ruecksicherungen bleiben erhalten; Ablauf weiterhin 15. Oktober 2026,
19:38:38 Europe/Berlin. Bestehende Regression aus 3.50 gilt unveraendert.

Naechster manueller Handyfall: HANDY-TEST ueber `Kollektion bearbeiten`
ohne Feld-, Bild- oder Bestandsaenderung mit `Speichern und synchronisieren`
erneut synchronisieren. Danach `Bestand neu laden`; erwartet weiterhin
3 / 0 / 3. Keine erneute Veroeffentlichung und keine Bestands-Schreibaktion.
Erst nach Nutzerbestaetigung den Produkt-/Versions-/Hash- und Inventaraudit-
Vergleich serverseitig ausfuehren. Dieser Roundtrip ist noch nicht bestanden;
weitere Bestandspflege-, Bild-, dynamische UI- und Rechtsgates bleiben offen.

### 3.52 Erneute manuelle Synchronisierung ohne Bestandsmutation

15. Oktober 2026: Nutzer bestaetigt erneutes Speichern/Synchronisieren von
HANDY-TEST ohne Feldaenderung und anschliessend weiterhin 3 / 0 / 3.
Ausschliesslich lesender Serververgleich bestanden:

- Autoritativer Entwurf jetzt Produktversion 5 statt 4; serverseitiger
  kanonischer sourceHash gueltig. Die Version gehoert zur Hashdarstellung,
  deshalb ist ein anderer Hash bei neuer Version erwartetes Verhalten.
- Oeffentliche Produktprojektion und Commerce-Lesemodell bleiben bei der
  zuletzt veroeffentlichten Version 4 und stimmen untereinander ueberein.
  Speichern/Synchronisieren wurde nicht als neue Veroeffentlichung behandelt.
- Saemtliche oeffentlichen Produktinhalte nach Ausklammern von Version,
  Hash und Zeitstempel identisch; beide Originalbilder bytegleich zur
  erhaltenen Testwebsite. Weiterhin 2800 Cent/EUR, 18 cm, 8 mm und zwei
  unveraenderte feste Hinweise. Keine Bildneukomprimierung durch diesen Lauf.
- onHand 3, reserved 0, available 3, inventoryVersion 1. Weiterhin genau
  eine Initialisierung und genau ein Audit 0 -> 3 (`initial_count`). Keine
  neue Inventarversion, erneute Anfangsbestandsanwendung oder Kaufdaten.
- Source/Restore getrennt, MySQL 8/InnoDB/TLS bestaetigt; Restore leer.
  Geschuetzte bestehende Testprodukte und private Konfigurationen unveraendert.
  Vollstaendiger Dateivergleich des aktiven Websiteoverlays bestanden.

**Manuelles Speichern/Synchronisieren ohne Bestandsmutation bestanden.**
Die bewusste Trennung zwischen neuer Entwurfsfassung und bisheriger
Veroeffentlichung ist kein Fehler und darf nicht durch stilles Publizieren
oder Anpassen der vorhandenen Websitejournale verdeckt werden. Fuer weitere
Pruefungen beide Fassungen getrennt validieren, bis erneut veroeffentlicht
wurde; der alte Gleichheitsguard fuer Entwurf/Projektion allein reicht
in diesem Bearbeitungszustand nicht aus.

Nur Plan und Abnahmebericht geaendert, `git diff --check` bestanden; keine
App-/API-Aenderung und keine erneute Komplettregression erforderlich.
Kein Commit, Push, Deployment, Produktionszugriff, neue Konfiguration,
neuer Testdatensatz oder neuer Remote-Testartefakt. HANDY-TEST und private
Zugaenge/Stage/Ruecksicherungen bleiben erhalten. Laufzeit unveraendert
bis 15. Oktober 2026, 19:38:38 Europe/Berlin.

Naechster ausschliesslich manueller Handyfall: im separaten Bestandsbereich
von HANDY-TEST Zielmenge 4 mit `Neue fertige Armbaender` erfassen und danach
neu laden; erwartet 4 / 0 / 4. Nicht erneut erstzaehlen, publizieren oder
Produktfelder bearbeiten. Danach serverseitig Inventarversion, genau einen
neuen `receive_stock`-Audit und unveraenderte Produktfassungen kontrollieren.
Die Rueckkehr auf den Ausgangs-Testbestand 3 erfolgt als anschliessender
getrennter, auditierter Handytest. Diese Bestands-Schreibpruefung sowie
erneutes Publizieren, Bild-, dynamische UI- und Rechtsgates sind noch offen.

### 3.53 Manuelle Bestandserhoehung am Handy bestaetigt

15. Oktober 2026: Nutzer bestaetigt HANDY-TEST mit Zielmenge 4 ueber
`Neue fertige Armbaender` und Anzeige 4 / 0 / 4 nach erneutem Laden.
Anschliessender ausschliesslich lesender IONOS-Nachweis bestanden:

- onHand 4, reserved 0, available 4, inventoryVersion 2.
- Genau ein neuer Audit 3 -> 4 mit `receive_stock` und Version 2;
  Akteur, Korrelations-ID und neuer Idempotenzschluessel vorhanden und
  gebunden, ohne deren Werte auszugeben. Insgesamt zwei Bestandsaudits:
  die unveraenderte Erstinitialisierung 0 -> 3 und diese Erhoehung.
- Genau eine Anfangsbestandsvormerkung; keine erneute Initialisierung.
- Entwurf weiterhin Version 5 mit gueltigem kanonischem Hash, publizierte
  Projektion und Commerce weiterhin Version 4. Oeffentliche Fachfelder
  (ohne Versionsmetadaten) und beide Originalbilder unveraendert.
- Keine Checkouts, Reservierungen, Zahlungen, Bestellungen, Mails oder
  Widerrufe. Getrennte Source/Restore mit MySQL 8/InnoDB/TLS bestaetigt;
  Restore leer. Geschuetzte bestehende Testprodukte, Websiteoverlay und
  private Konfigurationen unveraendert.

**Manuelle Bestandserhoehung ueber die App bestanden.** Dies ist kein
zusatzlicher Replay-/Konkurrenznachweis; deren bisherige isolierte Tests
bleiben separat dokumentiert. Keine automatisierte Handybedienung, neue
Produkt-/Bestandsmutation durch den Agenten oder erneute Komplettregression.
Nur Plan und Abnahmebericht angepasst, `git diff --check` bestanden.
Kein Commit/Push/Deployment, Produktionszugriff oder neuer Remote-Artefakt.
Bestehende Testprodukte, Zugaenge und Ruecksicherungen bleiben erhalten.
Die Menge 4 bleibt bis zur naechsten manuellen Aktion sichtbar; kein stilles
Zuruecksetzen. Handystage unveraendert bis 19:38:38 Europe/Berlin befristet.

Naechster manueller Handyfall: ausschliesslich HANDY-TEST mit Zielmenge 3
ueber `Zaehlkorrektur` wieder auf den Ausgangs-Testbestand bringen und
`Bestand neu laden`; erwartet 3 / 0 / 3. Danach genau einen neuen
`correction`-Audit 4 -> 3 und Inventarversion 3 lesend nachweisen.
Keine Erstzaehlung, Veroeffentlichung oder Produktfeldbearbeitung.
Rueckkorrektur, erneutes Publizieren ohne Bestandswiederholung sowie Bild-,
dynamische UI- und Rechtsgates sind weiterhin offen.

### 3.54 Manuelle Rueckkorrektur auf den Ausgangs-Testbestand bestaetigt

15. Oktober 2026: Nutzer bestaetigt Zaehlkorrektur auf 3 und nach erneutem
Laden Anzeige 3 / 0 / 3. Anschliessender lesender IONOS-Abgleich bestanden:

- onHand 3, reserved 0, available 3, inventoryVersion 3.
- Vollstaendige Auditfolge exakt 0 -> 3 (`initial_count`, Version 1),
  3 -> 4 (`receive_stock`, Version 2), 4 -> 3 (`correction`, Version 3).
  Genau drei Eintraege mit getrennten Idempotenzschluesseln und jeweils
  gebundenem Akteur/Korrelationsbezug, keine Werte ausgegeben.
- Anfangsbestandsvormerkung weiterhin genau einmal vorhanden. Entwurf
  Version 5, publizierte Projektion/Commerce Version 4; kanonischer Hash,
  unveraenderte Produktinhalte und bytegleiche Originalbilder geprueft.
- Source/Restore getrennt, aktive TLS-Sitzungen, MySQL 8/InnoDB und leere
  Restore-Datenbank bestaetigt. Keine Checkouts, Reservierungen, Zahlungen,
  Bestellungen, Mails oder Widerrufe. Geschuetzte Testprodukte, private
  Konfigurationen und vollstaendiger Websiteoverlay unveraendert.

**Manuelle Bestandspflege mit Erhoehung und Rueckkorrektur bestanden.**
Der Ausgangs-Testbestand ist wiederhergestellt, seine Auditgeschichte bleibt
erhalten. Keine automatische Handybedienung oder weitere Mutation durch den
Agenten. Nur Plan/Abnahmebericht geaendert; `git diff --check` bestanden.
Keine neue Komplettregression, kein Commit/Push/Deployment, kein Produktions-
zugriff und keine neuen Remote-Testartefakte. Bestehende private Teststage,
Konfigurationen, HANDY-TEST und Ruecksicherungsdaten bewusst erhalten.

Naechster manueller Handyfall: HANDY-TEST ohne Feldaenderung ueber
`Kollektion bearbeiten` -> `Vorschau der Aenderungen` ->
`Jetzt veroeffentlichen` nochmals ausschliesslich in der isolierten Test-API
publizieren; anschliessend `Bestand neu laden`, erwartet weiterhin 3 / 0 / 3.
Keine Erstzaehlung oder andere Bestandsaktion. Danach neue Produktversion/
Hashbindung zwischen Quelle, privater Projektion und Commerce sowie weiterhin
Inventarversion 3 und genau drei Bestandsaudits pruefen. Dies ist keine
Websitebereitstellung oder Produktionsveroeffentlichung. Der vorhandene
statische Websiteoverlay bleibt bestehen. Erneutes Publizieren, Bild-,
dynamische UI- und Rechtsgates bleiben bis zu ihrem Nachweis offen.
Stage weiterhin nur bis 15. Oktober 2026, 19:38:38 Europe/Berlin; keine
Verlaengerung oder neue Zugangsdaten ohne gesonderte Behandlung des Ablaufs.

### 3.55 Erneutes Publizieren nicht bestaetigt; sichtbare App-Rueckmeldung lokal korrigiert

15. Oktober 2026: Nutzer meldet erneutes Publizieren und weiterhin 3 / 0 / 3,
beobachtet aber keine erkennbare Reaktion auf `Jetzt veroeffentlichen`.
Getrennte ausschliesslich lesende Serverkontrolle nach Ablauf des bisherigen
Testfensters (19:38:38 Europe/Berlin):

- Handystage abgelaufen; keine Verlaengerung oder Umgehung des HTTP-Guards.
- Entwurf weiterhin Produktversion 5, publizierte Projektion und Commerce
  weiterhin Version 4. Entwurfshash gueltig, Projektion/Commerce hashgleich,
  aber keine neue publizierte Fassung. **Erneutes Publizieren nicht bestanden.**
  Der Ablauf ist eine nachgewiesene aktuelle Sperre; ohne weitere Logs ist
  der genaue Zeitpunkt bzw. Ausgang des Handyaufrufs nicht feststellbar.
- onHand 3, reserved 0, available 3, inventoryVersion 3. Genau drei Audits
  0 -> 3 -> 4 -> 3 mit unveraenderter Akteur-/Korrelations-/Idempotenzbindung;
  genau eine Anfangsbestandsvormerkung, keine erneute Initialisierung.
- Produktinhalte ohne Versionsmetadaten identisch, Originalbilder bytegleich.
  Keine Checkouts, Reservierungen, Zahlungen, Bestellungen, Mails oder Widerrufe.
- Source/Restore getrennt und MySQL 8/InnoDB/aktive TLS-Sitzungen bestaetigt,
  Restore leer. Geschuetzte bestehende Testprodukte, private Konfigurationen
  und kompletter Websiteoverlay unveraendert.

Bestaetigter UI-Fehler: Die Veroeffentlichungsvorschau ersetzt den Bildschirm
mit dem SnackbarHost. Deshalb sind dort Fehler nicht sichtbar. Bei Erfolg
wurde sofort zuruecknavigiert und nur eine kurzlebige Snackbar angezeigt.
Gezielte lokale Korrektur in MainActivity, ProductUi und ProductViewModel:

- Ladeindikator und laufender Status; Aktionen waehrend der Anfrage gesperrt.
- Erfolg oder Fehler als bestaetigungspflichtiger Dialog direkt in der Vorschau;
  Erfolg bleibt bis `Fertig` sichtbar. Kein automatischer Ruecksprung.
- Netzwerk-/Serverfehler gelten nicht als Erfolg. Meldung unterscheidet
  bestaetigte Produktveroeffentlichung von separat laufender Websitebereitstellung.
- Nach Erfolg kein erneutes Absenden aus derselben Vorschau. Gespeicherte
  Operations-/Versionszwischenstaende aktualisieren die offene Vorschau,
  statt bei einem erneuten Versuch deren veralteten Anfangssnapshot zu nutzen.
- Keine Preis-/Bestands-/API-Vertragsaenderung; Produktmodell 4 und /v5,
  Produktionsversion 1.4.0 / Code 8 sowie Beta 1.4.0-beta.2 / Code 11 unveraendert.

Lokale Nachweise:

- `testDebugUnitTest`: 63 Tests, 0 Fehler, 0 Skips; vier neue Feedback-/Sperrtests.
- `lintDebug`, `assembleDebug`, `assembleDebugAndroidTest`: bestanden.
- Drei neue Compose-Faelle fuer Fortschritt/Aktionssperre, dauerhafte Fehler-
  und Erfolgsrueckmeldung kompiliert, mangels lokalem AVD nicht ausgefuehrt.
  Kein automatisierter Zugriff auf das Nutzerhandy; Geraetepruefung bleibt manuell.
- 11 Node-Vertragstests (Produktkompatibilitaet, Warenkorb, Bildvorschauen) bestanden.
- `assembleBeta`: korrekt abgebrochen, lokale stabile Beta-Signierung fehlt.
  Keine Secrets gesucht, kein neuer Keystore, keine Ersatzsignierung.
  Vorhandene GitHub-Beta aus 3.49 enthaelt diese Korrektur noch nicht.
- `git diff --check` und gezielte Geheimwertpruefung ohne Befund.

Nur diese App-/Testdateien, Plan und Abnahmebericht geaendert; vorherige
Websiteaenderungen erhalten. Keine Servermutation, neue Remote-Artefakte,
Produktdatenbereinigung, Datenbankwiederholung, API-/Websitebereitstellung,
Commits, Pushes oder Workflowstarts. main und Produktion unveraendert.
HANDY-TEST, bestehende Testprodukte, private Konfigurationen und Ruecksicherungen
bleiben erhalten. Naechster Gate-Schritt: Korrektur als stabil signierte Test-
Beta bereitstellen; anschliessend kontrolliert befristetes Testfenster erneuern
und den Publish-/Bestaetigungsnachweis am Handy wiederholen. Keine Gesamtfreigabe.

### 3.56 Signierte Rueckmeldungs-Beta installiert; V5-Testfenster erneuert

15. Oktober 2026: Die separat freigegebene Rueckmeldungskorrektur und
Beta-Version sind in Commit `0f14956b0d1d6c9712a5936103928530acac6676`
auf `codex/shop-inventory-cart` gesichert und gepusht. GitHub-Actions-Lauf
[38782340550](https://github.com/Bumpers210/armband-rechner/actions/runs/38782340550)
erfolgreich: Android Unit/Lint, signierter Beta-Build, Paket-/Versions-/
Zertifikatspruefung, Artefakt und Signing-Cleanup bestanden. Artefakt
`Carmaja-Perlen-Produktverwaltung-Test-1.4.0-beta.3-code12`; heruntergeladenes
APK zusaetzlich auf Testpaket, Test-API, stabiles Beta-Zertifikat und enthaltene
Rueckmeldungskorrektur geprueft. Produktionsversion bleibt 1.4.0 / Code 8.
Keine fremden Website-/Dokumentationsaenderungen in diesen Commit aufgenommen.
Der Nutzer bestaetigt die Installation von 1.4.0-beta.3.

Auf ausdruecklichen Auftrag das abgelaufene V5-Testfenster kontrolliert
erneuert; keine API-Neubereitstellung oder neue Zugangskonfiguration:

- Vorflug: bekannter isolierter Testpfad, Eigentuemerkennung, Modus 0600,
  private Runtime und vorhandene aktive Argon2id-Testanmeldung mit aktivem
  Geraetetoken bestaetigt. Fruehere einmalige Login-Uebergabedatei fehlt;
  keine Suche nach Secrets und kein Zuruecksetzen bestehender Zugangsdaten.
- Nur `expiresEpoch` in `v5-owner.json` unter exklusivem Stage-Lock atomar
  erneuert, alle anderen Metadaten erhalten. Neue Grenze:
  **16. Oktober 2026, 18:18:36 UTC / 20:18:36 Europe/Berlin**.
- Vollstaendiger Stage-Dateivergleich ausser dieser Eigentuemerdatei unveraendert;
  Runtime, Authentifizierung, Produkte und Bilder identisch. Bestehende
  Testprodukte/private Masterkonfigurationen und kompletter Websiteoverlay
  ebenfalls unveraendert verglichen.
- Source/Restore getrennt, MySQL 8/InnoDB und aktive TLS-Sitzungen nachgewiesen;
  bestehender akzeptierter CA-/Hostidentitaetsvorbehalt unveraendert.
  Beide Datenbankfingerabdruecke vor/nach identisch, Restore leer.
- HANDY-TEST weiterhin onHand 3 / reserved 0 / available 3, Inventarversion 3.
  Keine Checkouts, Reservierungen, Zahlungen, Bestellungen oder Mails erzeugt.
- HTTPS-GET `/v5/products` mit App-Code 12 ohne Token liefert jetzt 401
  statt Ablauf-503; `Cache-Control: no-store` und HTTPS-Zertifikatspruefung
  bestanden. Dies belegt den Schutz, noch keinen neuen angemeldeten Handyaufruf.
- Keine temporaeren Skriptdateien erzeugt; nur privater PHP-stdin-Aufruf.
  Vorhandene Testprodukte und private Konfigurationen ausdruecklich erhalten.
  Kein Commit, Push, Workflowstart, Deployment oder Produktionszugriff im
  Erneuerungsschritt. main und Produktion unveraendert.

Naechster manueller Nachweis: HANDY-TEST erneut ohne Feldaenderung
veroeffentlichen, dauerhafte Erfolgs-/Fehlerrueckmeldung pruefen und Bestand
erneut laden. Erst danach serverseitig Publishversion, Hash und unveraenderte
Initialisierungs-/Bestandsauditfolge bestaetigen. Noch keine Gesamtabnahme.

### 3.57 Erneutes Publizieren ohne Bestandsmutation bestanden; Erfolgstext gekuerzt

15. Oktober 2026: Nutzer bestaetigt auf Beta 1.4.0-beta.3 die sichtbare
Veroeffentlichungsbestaetigung und erneut geladene Bestandsanzeige 3 / 0 / 3.
Anschliessende ausschliesslich lesende Serverpruefung unter dem vorhandenen
Stage-Lock bestanden:

- Genau HANDY-TEST ist published. Entwurf, private oeffentliche V4-Projektion
  und Commerce-Produkt jetzt einheitlich Produktversion **7**; alle sourceHashes
  stimmen mit der serverseitig neu berechneten kanonischen Darstellung ueberein.
- Preis 2800 Cent/EUR, Armbandumfang 18 cm, Perlengroesse 8 mm und beide
  unveraenderlichen Hinweise korrekt. Kein stock oder vintedUrl in der Projektion.
- Beide Originalbilder bytegleich mit dem bisherigen Websitebild:
  1600 x 1490 und 1506 x 1600 Pixel. Dieser Nachweis ist noch kein manueller
  Upload eines 3200-Pixel-Bildes.
- onHand 3 / reserved 0 / available 3, Inventarversion 3. Genau die drei
  vorhandenen Audits 0 -> 3 -> 4 -> 3 mit Akteur-, Korrelations- und
  Idempotenzbindung; genau eine angewendete Anfangsinitialisierung auf 3.
  **Erneutes Veroeffentlichen initialisiert oder veraendert den Bestand nicht.**
- Keine Reservierungen, Checkouts, Zahlungen, Bestellungen, Mails oder Widerrufe.
  Source/Restore getrennt, MySQL 8/InnoDB/aktive TLS-Sitzungen bestaetigt;
  Restore weiterhin leer, akzeptierter TLS-Identitaetsvorbehalt unveraendert.
- Datenbankfingerabdruck und kompletter Stage-Dateibaum vor/nach der
  lesenden Pruefung identisch. Geschuetzte bestehende Testprodukte,
  private Konfigurationen und Websiteoverlay unveraendert. Die statische
  Website wurde durch den privaten Publish nicht neu bereitgestellt.

Zusaetzlicher Nutzerwunsch lokal umgesetzt: Der Erfolgsdialog zeigt nur
`Veroeffentlichung bestaetigt` (mit Umlauten in der App) und die bestehende
Fertig-Schaltflaeche. Der bisherige erklaerende Erfolgsabsatz entfaellt.
Fehlerdialog, Warteanzeige, Quittierung und Schutz gegen Doppelabsenden bleiben
unveraendert. Geaendert nur ProductUi.kt und der passende Compose-Test;
keine Versions-, API-, Bestands- oder Preislogikaenderung.

Nachweise der lokalen Textaenderung:

- testDebugUnitTest: 63 Tests, 0 Fehler, 0 Skips.
- lintDebug, assembleDebug, assembleDebugAndroidTest: bestanden.
- Compose-Test fordert die kurze Ueberschrift und Abwesenheit des langen
  Absatzes; nur kompiliert, kein konfigurierter AVD. Keine automatisierten
  Handytests. Die manuelle Beta.3-Pruefung belegt die vorherige Dialogfassung.
- 11 Produkt-/Warenkorb-/Bild-Node-Vertragstests bestanden.
- assembleBeta korrekt gesperrt: lokale stabile Signierung fehlt. Kein
  Suchen/Anfordern von Secrets, kein Ersatzschluessel oder Debug-Fallback.
  Textkuerzung noch nicht signiert gebaut und nicht auf dem Handy installiert.
- git diff --check bestanden. Keine temporaeren Serverdateien angelegt.

Keine Remote-Mutation, Bereinigung vorhandener Testprodukte/Konfigurationen,
Commits, Pushes, Workflowstarts oder Deployments. main, Produktionsressourcen
und Produktionsversionierung unveraendert. Naechster manueller Teilnachweis:
hoeher aufgeloestes Foto in einem separaten kuenstlichen Handy-Testentwurf
speichern/synchronisieren, ohne vorhandene Fotos zu ersetzen. Gesamtabnahme
wegen der unten verbleibenden Bild-/UI-/Rechtsnachweise weiterhin offen.

### 3.58 Manueller 3200-Pixel-Upload serverseitig bestaetigt

15. Oktober 2026: Nutzer meldet `Bildtest-Entwurf synchronisiert`. Ausschliesslich
lesende Pruefung in der vorhandenen isolierten V5-Teststage:

| Datei | Tatsaechliche Aufloesung | Dateigroesse | Ergebnis |
| --- | --- | ---: | --- |
| 01.jpg | 3200 x 1800 Pixel | 733664 Byte | valides, vollstaendig dekodierbares JPEG |
| 02.jpg | 1800 x 3200 Pixel | 702436 Byte | valides, vollstaendig dekodierbares JPEG |

- Genau ein Entwurf HANDY-TEST BILD3200 gefunden, Status draft,
  Produktmodell 4 / Produktversion 1. Kanonischer sourceHash gueltig und
  beide serverseitigen Hinweise unveraendert vorhanden.
- Dateiinhalt, gespeicherte Pruefsumme, Breite, Hoehe und Dateigroesse
  stimmen fuer beide Bilder ueberein. Beide liegen unter 5 MiB, erreichen
  exakt 3200 Pixel an der langen Seite und enthalten keine GPS-Metadaten.
  **Manueller hochaufloesender Upload technisch bestanden.**
- Entwurf nicht publiziert und nicht in der oeffentlichen Projektion.
  Keine neue Commerce-Produkt-/Inventarzeile und keine Bestandsvormerkung.
  Vorhandenes HANDY-TEST weiterhin 3 / 0 / 3, Inventarversion 3,
  genau eine Initialisierung und drei Bestandsaudits. Keine Kaufdaten entstanden.
- Pruefung unter vorhandenem Stage-Lock. Gesamter privater Stage-Dateibaum
  und Datenbankfingerabdruck vor/nach identisch; bestehende Testprodukte,
  private Konfigurationen und Websiteoverlay unveraendert.
  Source/Restore per aktiver TLS-Sitzung erreicht; Restore weiterhin leer.

Ergaenzende lokale Regression mit ausschliesslich kuenstlichen Fixtures:

- PHP 8.4.23 mit prozesslokalen Erweiterungen: 58 Product-API-Tests bestanden,
  darunter 3200-Pixel-Verarbeitung, Bildabruf und unveraenderte Produktionsgrenzen.
- Isolierte V5-Guards: 27 Assertions bestanden; Warenkorb-Vertragssuite bestanden.
- 11 Node-Tests bestanden. Darunter deterministische 240-/640-/1200-Pixel-
  Vorschauen ohne Aenderung der Originale, JPEG-/Metadaten-/Pfadpruefungen
  und Laden des Originals erst in der Website-Grossansicht.
- Eine optionale zusaetzliche lokale Pruefung mit Kopien der privaten
  Handy-Originale wurde durch die Sicherheitspruefung vor Ausfuehrung blockiert.
  Keine Originalbilder uebertragen oder lokale Kopien angelegt. Keine Umgehung;
  die Vorschau-Regression verwendet stattdessen kuenstliche Bilder.
  Sie belegt nicht die reale Browser-Grossansicht dieser beiden Handyfotos.
- Keine Remote-Testdatei erzeugt, keine Produkte/Konfigurationen geloescht.
  Lokale Testfixtures dieses Laufs durch die vorhandenen Finalizer bereinigt;
  keine aktuellen PHP- oder Vorschau-Testverzeichnisse zurueckgeblieben.
  Aeltere PHP-Testreste vom August wurden nicht angefasst.
  Kein Commit, Push, Workflow, Deployment oder Produktionszugriff.

Naechster manueller Handy-Schritt: App schliessen/erneut oeffnen,
Bildtest-Entwurf laden und beide Fotos in der Veroeffentlichungsvorschau
anzeigen, ohne ihn zu veroeffentlichen oder Bestand zu setzen. Danach
Anzeigepruefung getrennt dokumentieren. Website-Grossansicht, dynamische
Admin-/Widerrufs-UI-Flows und erneute Rechtsfreigabe bleiben offen;
keine Warenkorb-Gesamtabnahme.

### 3.59 Bildanzeige nach erneutem Oeffnen am Handy bestaetigt

15. Oktober 2026: Nutzer bestaetigt beide Bilder nach erneutem Oeffnen des
separaten Bildtest-Entwurfs. Zusammen mit der lesenden Serverpruefung aus
3.58 sind manueller 3200-Pixel-Upload und erneute Bildanzeige in Beta.3
bestanden. Dies ist eine manuelle Anzeigepruefung, kein separater Nachweis
eines erneuten Netzwerkdownloads statt eines lokalen Bildcaches.
Keine erneute Handypruefung derselben Schritte erforderlich.

HANDY-TEST BILD3200 bleibt unveroeffentlicht; keine Freigabe zum Ersetzen
bestehender Fotos oder zum automatischen Publizieren abgeleitet. Bestehende
Testprodukte und private Konfigurationen bleiben erhalten.

### 3.60 Lokale Galerie-/Widerrufs-UI und sichere Fehlerrueckmeldung

15. Oktober 2026: Den bereits in 3.50 dokumentierten technischen Rohfehler
im Widerrufsformular lokal behoben. Beide Formularschritte verwenden feste
oeffentliche Meldungen anhand bekannter Fehlercodes; weder Netzwerkfehler
noch beliebige Servertexte werden direkt dargestellt. Bei unklarem Ausgang
nach Bestaetigung wird kein fehlender Eingang behauptet, sondern auf die
Eingangsmail und den alternativen Kontaktweg verwiesen. Erfolg wird nur mit
`state=submitted` und vorhandenem Eingangszeitpunkt angezeigt. Fehler im
Bestaetigungsschritt sind als Statusmeldung zugaenglich. Keine automatische
Wiederholung, keine Aenderung an API, Datenbank oder rechtlichen Fristen.

Browsernachweise mit dem Computer-Use-Skill, ohne Remote-Mutation:

- Bestehendes HANDY-TEST auf der Testwebsite lesend kontrolliert: Hauptvorschau
  1200 x 1118, zweite Vorschau 226 x 240; Grossansicht laedt die unveraenderten
  Originale 1600 x 1490 bzw. 1506 x 1600. Wechsel, Escape und Fokusrueckkehr
  bestanden. Kauf bleibt bei nicht verfuegbarer Shop-Runtime gesperrt.
- Separater lokaler Testexport mit zwei ausschliesslich kuenstlich erzeugten
  Bildern: Vorschauen 1200 x 675 bzw. 135 x 240; Grossansicht vollstaendig
  dekodiert mit 3200 x 1800 bzw. 1800 x 3200. Keine privaten Handyfotos
  uebertragen oder oeffentlich bereitgestellt. Dies ersetzt noch nicht die
  Website-Veröffentlichungskette des echten Bildtest-Entwurfs.
- Echter Formularcode im lokalen Browser, Antworten aus einem ausschliesslich
  an 127.0.0.1 gebundenen Simulator: genau Position 1 / Menge 1 statt aller
  Positionen gesendet; Vorschau ohne Einreichung; nicht zuordenbare Erklaerung
  erst nach ausdruecklicher Bestaetigung gesendet. Kein Datenbank-/Providerzugriff.
- Verbindungsabbruch beim Bestaetigen: verstaendliche Unklarfallmeldung,
  genau ein Versuch, kein automatischer Retry. Unlesbare HTML-Fehlerantwort
  bei der Vorschau: verstaendliche Meldung, kein technischer Rohfehler.
- Simulator zaehlt vier Vorschauen, zwei ausdrueckliche kuenstliche
  Einreichungen, einen Bestaetigungs-Netzwerkfehler und eine unlesbare
  Vorschauantwort. Keine echten Widerrufe, Mails oder Bestandsaenderungen.

Regression: npm ci, Website-Lint, 190 Node-Tests, Shellsuite sowie PHP 8.4.23
Warenkorb-Vertrag, Mehrtab-CSRF und 57 Testadapter-Assertions bestanden.
Optionaler Shell-Klickstatistiktest weiterhin mangels Shell-PHP uebersprungen;
kein Hosting-Lint und keine Nebenreparatur. Kuenstlicher 3200-Pixel-Testexport
inklusive sechs Vorschauen, TypeScript und Exportpruefung bestanden.
Die lokale Browser-/Simulatorpruefung ist **kein erneuter IONOS-E2E- oder
Mailnachweis**. Authentifizierte Admin-UI und integrierter Widerrufs-UI-Lauf
mit Testdatenbank/Mail bleiben getrennte offene Gates.

Lokaler Simulator beendet, Testtab geschlossen, eigene temporaere Skript-/
Bild-/JSON-Fixtures entfernt. Anschliessend regulären kuenstlichen Testexport
mit Test-API statt lokalem Simulator erstellt. Keine SSH-Verbindung,
Remote-Bereinigung, Commits, Pushes, Workflows oder Deployments in diesem
Schritt. Bestehende App-/Designaenderungen nicht ueberschrieben. main,
Produktion, bestehende Testprodukte und private Konfigurationen unveraendert.
Die Fehlerrueckmeldung ist noch nicht auf die Testwebsite deployed.
Abschliessend git diff --check, gezielte Geheimwertpruefung sowie Abwesenheit
des lokalen Simulators im regulaeren Testexport bestanden. Gesamtabnahme
weiterhin offen.

### 3.61 Bildtest-Publish geprueft; Verkaufsfreigabe-Vertrag lokal korrigiert

15. Oktober 2026: Nutzer meldet HANDY-TEST BILD3200 als veroeffentlicht,
Verkaufsfreigabe aus und ohne Anfangsbestand. Ausschliesslich lesende
Serverkontrolle unter dem bestehenden Stage-Lock:

- Produktmodell 4 / Produktversion 3, Status published. Entwurf und private
  oeffentliche Projektion versions-/hashgleich; positiver EUR-Preis.
- Beide validierten Original-JPEGs unveraendert: 3200 x 1800 / 733664 Byte
  und 1800 x 3200 / 702436 Byte. Keine privaten Fotos lokal kopiert.
- Kein Anfangsbestandsvorgang, kein Inventar-/Audit-/Reservierungsdatensatz
  fuer den Bildtest. Effektiv 0 / 0 / 0, quantityConfirmed=false, nicht kaufbar.
- Bestehendes HANDY-TEST weiterhin 3 / 0 / 3, Inventarversion 3.
  Restore-Ziel leer; beide Verbindungen MySQL 8/InnoDB mit aktiver TLS-Sitzung.
- Stage, geschuetzte Basisprodukte/Konfigurationen, beide Datenbank-
  Fingerabdruecke und Testwebsite vor/nach identisch. Keine Remote-Testdateien
  erzeugt und keine Testdaten bereinigt. Die statische Testwebsite besitzt
  noch keine Detailseite fuer BILD3200; Publish ist kein Website-Deploynachweis.

**Abweichung:** salesEnabled ist in Quelle und Projektion tatsaechlich true.
Die bisherige V5-Kette hat das Kollektionen-Verhalten geerbt: App-Payloads
liessen salesEnabled weg, Antworten ersetzten es durch available, Speichern
und Veroeffentlichen leiteten es aus published ab, auch die Commerce-Projektion
aktivierte es automatisch. Beta.3 bietet keinen separaten wirksamen Schalter.
Die vorherige Aufforderung, beim Veroeffentlichen die Freigabe ausgeschaltet
zu lassen, war deshalb nicht vollstaendig ausfuehrbar. Kein Bedienfehler;
die Bestandspruefung verhinderte dennoch den Kauf. Serverprodukt nicht
nachtraeglich veraendert, um den fehlgeschlagenen Nachweis zu verdecken.

Lokal im Warenkorb-Worktree korrigiert, noch nicht bereitgestellt:

- V5-PUT verlangt salesEnabled als explizites boolesches Pflichtfeld.
  Keine Ableitung aus dem Publikationsstatus. Vollupdate, erwartete Version,
  Idempotenz, serverseitiger Hash und verbotene Clientfelder bleiben erhalten.
- V5-Lesen und Bildantworten erhalten salesEnabled. Modell-4-Publish und
  Wiederholung erteilen keine Verkaufsfreigabe. Archivierung sperrt den
  Verkauf; Wiederherstellung erteilt keine neue Freigabe. Modell 3 unveraendert.
- Commerce-Memory-/PDO-Projektion uebernimmt fuer Modell 4 ausschliesslich
  die boolesche Freigabe; Kaufbarkeit braucht zusaetzlich bestaetigten Bestand.
  Keine Bestands-, Checkout-, Migrations- oder SQL-Schemaaenderung.
- App-Editor, gespeicherter Entwurf, PUT, Synchronisierung, Serverimport und
  Publishantwort fuehren die Freigabe getrennt von available. Sichtbarer
  Schalter und Vorschau zeigen die Freigabe ohne Kaufbarkeitsversprechen.
  Fehlende/ungueltige Freigaben in Modell-4-Antworten werden sicher abgewiesen.
- Lokaler Publisher-Artefaktvertrag akzeptiert bei Modell 4 eine sichtbare,
  bewusst nicht kaufbare Projektion. Fehlende/nichtboolesche Werte werden
  abgelehnt; Modell-3-Vertrag, Provenienz, Pfadguards und Workflow unveraendert.
  Kein Publisher, Workflow oder Deploy aktiviert.

Pruefungen: 59 Product-API-Faelle einschliesslich neuem Publish-/Replay-/
Upload-/Restoretest; 28 neue Memory-/PDO-Bindungspruefungen ohne echte
Datenbank; insgesamt 24 relevante PHP-Suites und Lint bestanden. 66 Android-
Unit-Tests, lintDebug, assembleDebug und assembleDebugAndroidTest bestanden.
Neuer Compose-Schaltertest nur kompiliert; keine automatisierte Handybedienung.
assembleBeta bleibt lokal wegen fehlender stabiler Signierung gesperrt;
kein Ersatzschluessel und keine Passwortsuche. Versionierung unveraendert.
npm ci, Website-Lint, 191 Node-Tests, Shellsuite und kuenstlicher Testexport
bestanden; nach der Publisher-Guardkorrektur weitere 20 gezielte Node-Tests
und Lint bestanden. Optionaler Shell-PHP-Klickstatistiktest weiterhin
uebersprungen. Bestehende Dependency-Auditbefunde nicht nebenbei veraendert.

Kein Commit, Push, Workflowstart, Deployment oder Produktionszugriff.
Neue signierte Beta und abgestimmte isolierte Test-API erfordern den naechsten
freigegebenen Bereitstellungsschritt. Alte Beta nicht gegen den korrigierten
schreibenden Vertrag aktivieren: ihr PUT enthaelt das neue Pflichtfeld nicht.
Anschliessend Freigabe-aus-Roundtrip manuell erneut nachweisen, ohne Bestand
zu setzen; bestehende Produkte/Privatkonfigurationen weiterhin erhalten.
Bild-/Publikations-Teilnachweis bestanden, Freigabe-aus-Gate und gesamte
Warenkorb-Abnahme **noch nicht bestanden**.

### 3.62 Verkaufsfreigabe-Fix: signierte Beta und isolierte Test-API (15.10.2026)

Auf ausdrueckliche Freigabe ausschliesslich den Verkaufsfreigabe-Vertrag,
zugehoerige Tests und Beta-Version committed und nach
`codex/shop-inventory-cart` gepusht:
`0b60340e794e1e333c93b927b539738a88896342`.
Andere offene Website-, Design-, Rueckmeldungstext- und Dokumentationsaenderungen
sind nicht Bestandteil dieses Commits.

GitHub-Lauf `38797484661` bestanden: Unit-Tests, Lint, stabil signierter
Beta-Build und gepinnter Zertifikats-/Paketnachweis.
Artefakt: `Carmaja-Perlen-Produktverwaltung-Test-1.4.0-beta.4-code13`
(ID `12002932206`). Produktionsversion unveraendert 1.4.0 / Code 8.
Heruntergeladene APK erneut geprueft: Code 13, Version 1.4.0-beta.4,
Testpaket, APK-Pruefsumme und gepinntes Beta-Zertifikat korrekt. Kompilierte
BuildConfig direkt aus DEX geprueft: ausschliesslich Test-API und Ziel `test`.
Lokaler exakter Indexexport: fuenf PHP-Lints, 18 PHP-Suites und 18 gezielte
Node-Vertragstests bestanden. Lokale Android-Unit-/Lint-/Debug- und
AndroidTest-Kompilierung bestanden; keine automatisierten Handytests behauptet.
66 Android-Unit-Tests ohne Fehler. Zusaetzliche Regression im erhaltenen
Arbeitsstand: npm ci, Lint, 191 Node-Tests, Shelltests sowie Testexport mit
23 Seiten bestanden. Optionaler Shell-PHP-Klickstatistiktest mangels PHP im
Shell-PATH uebersprungen; bestehende zehn npm-Auditbefunde unveraendert.
Abschliessender git diff --check bestanden. Lokalen Index-Testexport entfernt;
heruntergeladene Beta-APK als Ausgabe erhalten. Deployschalter weiterhin false.

Nur `commerce-core.php`, `product-api-v4.php` und `product-api-v5.php` im
aktiven privaten V5-Teststage aktualisiert. Vorherigen Programmstand gegen
den Commitvorgaenger geprueft; neue Dateien per SHA-256/PHP-Lint und unter
exklusivem Teststage-Lock mit privaten Rollbackkopien atomar je Datei ersetzt.
Neuer Prozess bestaetigt den V5-Lesevertrag und die ausgeschaltete Freigabe
an rein speicherinternen Testdaten.
Live-HTTP-Pruefung: `/v5/products` ohne Anmeldung liefert 401 und no-store.

Vorher-/Nachhervergleich bestaetigt unveraenderte vorhandene Testprodukte,
Bilder, Bestandsdaten, beide Datenbanken, private Konfigurationen, oeffentliche
API-Dateien und Testwebsite. Temporaere Serverdateien und Rollbackkopien nach
erfolgreicher Pruefung entfernt. Kein Produktionszugriff, main-Merge,
Website-Deployment oder Datenkorrektur. Insbesondere wurde die gespeicherte
Freigabe des Bildtests nicht eigenmaechtig umgestellt.

Die Installation und der manuelle Freigabe-aus-Roundtrip mit Beta.4 bleiben
offen. Bereitstellung ist keine vollstaendige Warenkorb-Abnahme.

### 3.63 V5-Testfenster fuer sieben Tage erneuert (21.10.2026)

Auf ausdrueckliche Nutzerfreigabe ausschliesslich `expiresEpoch` in der
privaten `v5-owner.json` der bestehenden isolierten Handystage unter exklusivem
Stage-Lock atomar erneuert. Modus 0600 und alle anderen Metadaten erhalten.
Neue Frist: **28. Oktober 2026, 11:12:55 UTC / 12:12:55 Europe/Berlin**
(604800 Sekunden ab Erneuerung; Ende nach der Zeitumstellung).

Vorher-/Nachhervergleich bestanden: alle anderen Stagedateien einschliesslich
Produkten, Bildern, Programmen, Runtime und Authentifizierung unveraendert;
private Master-Testdaten/-konfigurationen, oeffentliche API-Dateien und gesamte
Testwebsite ebenfalls unveraendert. Bestehende aktive Benutzer und Geraetetokens
nur lesend bestaetigt; keine neue Anmeldung, kein Tokenreset, keine Secretwerte
ausgegeben. Beide getrennten Testdatenbanken mit MySQL 8/InnoDB und aktiver TLS-
Sitzung gelesen; Schema-/Inhaltsfingerabdruecke identisch, Restore weiterhin leer.
HANDY-TEST bleibt 3 / 0 / 3, bestaetigter Bestand, Inventarversion 3.

Bootstrap und V5-Zeitfensterpruefung bestanden. HTTPS-GET `/v5/products` mit
App-Code 13 liefert ohne Token wieder 401 `authentication_required` statt 503,
mit `Cache-Control: no-store` und aktiver HTTPS-Zertifikatspruefung. Dies ersetzt
nicht den noch offenen angemeldeten Freigabe-aus-Roundtrip am Handy.
Atomare temporaere Datei bereinigt; kein dauerhaftes Testskript erzeugt.
Keine Produkt-/Bestands-/Zugangsaenderung, kein Commit/Push/Workflowstart,
kein Deployment, keine Produktionsaenderung. Gesamtabnahme bleibt offen.

### 3.64 Freigabe-aus-Roundtrip und echte Website-Bildkette (25.10.2026)

Manuelle Beta.4-Pruefung durch den Nutzer bestaetigt: HANDY-TEST BILD3200
bleibt nach Synchronisierung und Veroeffentlichung ohne Verkaufsfreigabe;
beide Bilder beim erneuten Oeffnen vorhanden. Anschliessender ausschliesslich
lesender Servernachweis bestanden: Quelle, V5-Antwort, private oeffentliche
V4-Projektion und Commerce einheitlich Produktversion 7, kanonisch hashgleich
und salesEnabled=false. Beide Produkthinweise erhalten. Originalbilder
3200 x 1800 sowie 1800 x 3200, gespeicherte Bildpruefsummen korrekt.
Kein Inventar-, Initialisierungs-, Bestandsaudit- oder Reservierungseintrag
fuer den Bildtest; weiterhin 0 / 0 / 0 ohne bestaetigten Anfangsbestand.
HANDY-TEST unveraendert 3 / 0 / 3, Inventarversion 3.

Auf gesonderte Freigabe den Bildtest in die bestehende geschuetzte
Testwebsite eingebunden. Die vier bisherigen Katalogprodukte wurden aus
der vorhandenen oeffentlichen Previewquelle unveraendert uebernommen.
Nur Start-/Uebersichtsseiten, neue Bildtest-Detailseite und benoetigte
statische Assets aktiviert (26 abweichende Dateien, atomarer Austausch je
Datei, Assets vor Seiten). Bestehende Produktdetailseiten, Rechtstexte,
Footerziele, API, Authentifizierung, Programme und Runtime nicht ersetzt.
Bereits vorhandene unbenutzte Bilder an der neuen Test-SKU vor dem Austausch
auf fehlende Referenzen geprueft und privat rollbackfaehig gesichert.

Live in angemeldetem Chrome bestanden: Startseite und Uebersicht zeigen den
Bildtest als nicht verfuegbar; Uebersicht enthaelt weiterhin alle vier alten
Produkte. Neue Detailseite ohne Kaufbutton, mit beiden Hinweisen. Kleine
Vorschauen laden mit 1200 x 675 bzw. 135 x 240; Grossansichten laden die
Originale mit 3200 x 1800 bzw. 1800 x 3200. Beide Ausloeser, Bildwechsel,
Pfeiltasten, Escape, Fokusbegrenzung und Fokusrueckkehr funktionieren.
Mobilansicht 390 x 844: Bild passt vollstaendig, Schliessen erreichbar;
Viewport anschliessend zurueckgesetzt. Dies ist ein Browsernachweis,
kein automatisierter Handy-/Instrumentierungstest.

Regression: npm ci, Website-Lint, 191 Node-Tests, Shellsuite, realer
Fuenf-Produkte-Testbuild/Export (27 Seiten, 30 Vorschauen), PHP-8.4-Lint
des temporaeren Aktivierers und 28 Verkaufsfreigabe-Vertragspruefungen
bestanden. Optionaler Shell-PHP-Klickstatistiktest mangels php im Shell-PATH
weiterhin uebersprungen. npm meldet sechs Auditbefunde (fuenf hoch, einer
kritisch); keine ungefragte Abhaengigkeitsaenderung.

Vorher-/Nachhervergleich von privaten Produkt-/Bild-/Zugangsdateien,
Konfigurationen, oeffentlicher API sowie beiden MySQL-Testdatenbanken
bestanden; Restore leer. Ruecksicherung und gebundener Fuenf-Produkte-
Katalog privat unter Test-Deploy/backups/bild3200-20261025-a79f2601.
Uebertragungs- und lokale Zwischenartefakte nach erfolgreicher Pruefung
vollstaendig entfernt; private Ruecksicherung erhalten. Test-Deployschalter unmittelbar nach Aktivierung wieder
false; kein Commit, Push, main-Merge oder Produktionszugriff.
Der GitHub-Publisher bleibt deaktiviert; diese Test-Preview ist keine
dauerhafte Publisheraktivierung. Spaetere Testbuilds muessen den gebundenen
Fuenf-Produkte-Katalog erhalten. Gesamtabnahme weiterhin offen.

### 3.65 Integrierte Widerrufs-UI auf getrenntem Testziel (25.10.2026)

Nur Testumgebung, keine Produktionsaenderung. Source dbs15962244 mit den
vorhandenen 38 Tabellen und den Handy-Testprodukten bleibt unveraendert.
Die getrennte Restore-Testdatenbank dbs15962566 war vor der Vorbereitung
vollstaendig leer; beide Verbindungen MySQL 8, InnoDB und aktive TLS-Sitzung.
Der neue Adapter `scripts/cart-ui-acceptance.php` richtet ausschliesslich
dort kuenstliche UI-Pruefdaten ein. Keine Stripe-/Brevo-Schluessel geladen,
keine Provideraufrufe, keine Wiederholung bereits bestandener Testzahlungen.

Isolierte, auf vier Stunden begrenzte Stage:
`cart-e2e-ui-20261025-a4c8172d` unter dem privaten Testbereich. Neue
gleichnamige Unterordner in Test-API und Testwebsite; kein bestehender
Einstieg, keine bestehende Seite und kein Produkt ueberschrieben.
30 hashgepruefte statische UI-Dateien mit aktuellem Admin-/Widerrufscode;
Runtime nur privat mit Modus 0600. HTTP-Adapter erlaubt nur die benoetigten
Admin-, Kontext- und Widerrufsrouten; Checkout, Produktwrites, Provider,
Webhook und Worker sind nicht erreichbar. Deployschalter wieder false.

Echte Chrome-UI -> HTTPS/CORS -> unveraenderter PHP-Router -> MySQL:

| Pruefung | Ergebnis |
| --- | --- |
| Falsches Adminpasswort | Verstaendliche Ablehnung, keine Anmeldung |
| Passende Bestellidentifikation | Zwei Positionen mit Mengen 2 und 1 angezeigt |
| Vor ausdruecklicher Bestaetigung | awaiting_confirmation, keine Widerrufsposition und keine Eingangsmail |
| Teilwiderruf | Position 1/Menge 1 gespeichert, Position 2/Menge 0 ausgeschlossen |
| Nicht zuordenbare Bestellung | Ebenfalls Vorschau/Bestaetigung; erst danach submitted/manual_review und Reviewcase |
| Wiederholte Uebermenge | Erklaerung erhalten, Reviewcase; keine doppelte gueltige Rueckgabemenge |
| Eingangsbeleg | Drei bestaetigte Erklaerungen, genau drei queued-Eingangsmails; kein Versand in dieser UI-Reihe |
| Bestand und Zahlung | Keine automatische Wiedereinlagerung/Erstattung; kuenstliche Bestandswerte unveraendert |
| Schutz bestehender Daten | Source-Fingerprint, Handy-Stage, Fotos, private Konfigurationen und bestehende API-/Website-Dateien nach jeder Reihe unveraendert |

Die kuenstliche Bestellung ueber 77,70 EUR, der Teilrefund 28,00 EUR und
eine weitere SEPA-processing-Reservierung wurden ausschliesslich als
lokale Commerce-Fixtures auf dem Restore-Testziel erzeugt. Das ist kein
zusaetzlicher echter Stripe- oder Brevo-Nachweis.

Lokale Regression: Website-Lint, 191 Node-Tests samt Shellsuite, 23 vorhandene
PHP-8.4-Suites sowie neue UI-Adapter-Guardpruefung und PHP-Lint bestanden.
Kuenstlicher Testexport mit 23 Seiten bestanden. Android testDebugUnitTest
und lintDebug erfolgreich (bestehende Ergebnisse unveraendert/up-to-date:
66 Tests, keine Fehler; keine Geraeteautomatisierung, keine neue Beta).
Optionaler Shell-PHP-Klickstatistiktest weiterhin mangels php im Shell-PATH
uebersprungen. Npm-Audit weiter sechs Befunde: Next kritisch; sharp,
brace-expansion, js-yaml, source-map-js und zod hoch. Keine pauschale
Unbedenklichkeitsbewertung und kein ungefragtes Dependency-Upgrade.

Noch offen: angemeldete Admin-Browseraktionen (Mengenpflege,
Positions-/Refund-/SEPA-Anzeige und gepruefte Wiedereinlagerung). Das
neu erzeugte, ausschliesslich temporaere Adminpasswort liegt lokal
benutzerbeschraenkt; die Browserautomatik kann die Datei nicht lesen.
Nutzer um einmalige Anmeldung gebeten, ohne Passwort im Chat.
Beim anfaenglichen, inzwischen korrigierten Testadapter-Pfadfehler blieb
die Admin-Anzeige nach einem Netzwerkfehler bei "Anmeldung wird geprueft";
diese fehlende UI-Fehlerrueckmeldung ist ein eigener offener Befund.

Cleanup noch NICHT abgeschlossen: die markierte kuenstliche Restore-Stage
bleibt bis zum Adminnachweis erhalten und sperrt nach Ablauf HTTP-Aufrufe.
Anschliessend `cart-ui-acceptance.php cleanup`, Restore-Leerheit sowie
Schutzfingerprints pruefen und ausschliesslich eigene Stage-Unterordner,
Uebertragungsdateien und temporaere Login-Datei entfernen. Die zusaetzliche,
nicht nutzbare lokale Login-Zwischenkopie wurde bereits entfernt.
Bestehende Datenbank-/Stripe-/Brevo-Konfigurationen und reale Testprodukte
bleiben ausdruecklich erhalten. Kein Commit, Push oder Produktionszugriff;
keine Gesamtabnahme, keine Rechts- oder Cutoverfreigabe.

### 3.66 Admin-Browsernachweis und Cleanup vom 25. Oktober 2026

Nach manueller Anmeldung ausschliesslich in der temporaeren Stage aus 3.65
mit echten Browseraktionen gegen den unveraenderten Admin-Router geprueft:

| Pruefung | Ergebnis |
| --- | --- |
| Anmeldung | Angemeldet; falsches Passwort zuvor abgewiesen |
| Bestellpositionen | Zwei A und ein B, zusammen 77,70 EUR inklusive einmalig 2,70 EUR Versand |
| Teilrefund | 28,00 EUR angezeigt, keine Erstattungsaktion im Admin |
| SEPA processing | Separater Zahlungseintrag ohne Bestellung; reservierte Menge bleibt blockiert |
| Bestandsuntergrenze | targetOnHand=0 bei reserved=1 abgewiesen, Bestand unveraendert |
| Zaehlkorrektur | A: 3/1/2 -> 4/1/3 -> 3/1/2; Version 2 -> 3 -> 4 |
| Gepruefte Rueckgabe | Eine Menge der Position A explizit bestaetigt; A danach 4/1/3, Version 5; genau ein restocked_items-Eintrag |
| Wiederholung | Wiedereinlagerungsschaltflaechen nach Erfolg gesperrt |
| Zweites Produkt | B unveraendert 4/0/4, Version 2 |
| Widerruf/Prueffall/Mailanzeige | Die drei Einreichungen und zwei offenen Prueffaelle aus 3.65 sichtbar; Mails nur queued, kein Provideraufruf |
| Schutz bestehender Daten | Source-, Handy-Stage-, Bilder-, Konfigurations-, API- und Website-Fingerprints unveraendert |

Diese Daten waren ausschliesslich kuenstliche Fixtures. Es wurde weder eine
reale Rueckgabe noch eine echte Erstattung oder ein Versand vorgenommen.

Zwei eng begrenzte UI-Befunde lokal korrigiert:

- `components/admin-console.tsx`: Netzwerkausfall beendet die Login-Ladeanzeige
  mit einer generischen Meldung; fehlender CSRF-Vertrag bleibt gesperrt.
  Ein Dashboardausfall nach erfolgreicher Anmeldung wird gesondert angezeigt.
- `components/inventory-admin.tsx`: Nach Wiedereinlagerung beziehungsweise
  unklarem Antwortausgang werden die angezeigten Mengen erneut geladen.
  Scheitert dies, werden veraltete Mengen nicht weiter als aktueller Bestand
  angezeigt. Bestehende Wiederholungssperre und Serververtraege unveraendert.
- Sieben ausfuehrende Komponententests in `tests/node/cart-admin-ui.test.mjs`
  mit kuenstlichen HTTP-Antworten bestanden. Kein echter Netzwerkzugriff in
  diesen Tests. Diese beiden UI-Korrekturen sind noch nicht deployed; der
  Live-Browsernachweis oben betrifft den zuvor bereitgestellten Stand.

Abschliessende lokale Regression: `npm ci` ohne Lockdateiaenderung,
198/198 Node-Tests, Shell-Integration, Website-Lint, TypeScript und
23-Seiten-Testexport mit Exportpruefung bestanden. Die sieben neuen Tests
nach einer ausschliesslichen Testvariablen-Lintkorrektur erneut bestanden.
PHP-8.4-Lint fuer Adapter/Test, Adapter-Guard und AP5-Suite 12/12 erneut
bestanden; breitere PHP- und Android-Nachweise siehe 3.65 (keine neue
App-Aenderung). Optionaler Shell-PHP-Klickstatistiktest ohne php im PATH
weiterhin uebersprungen. `git diff --check` und fokussierter Geheimwertscan
bestanden. `npm audit` bestaetigt weiterhin sechs Paketbefunde; keine
Dependency-Version veraendert und keine Sicherheitsabnahme behauptet.

Cleanup abgeschlossen: eigener API-Einstieg zuerst entfernt, ausschliesslich
die markierten kuenstlichen Restore-Tabellen geloescht und Datenbankleerheit
bestaetigt. Alle drei eigenen Stage-Verzeichnisse, drei Uebertragungsdateien
und lokale temporaere Dateien einschliesslich Login entfernt; Abwesenheit
geprueft. Der temporaere Admin ist damit ebenfalls entfernt. Zwischenablage
geleert und Admin-Testtab geschlossen. Keine Cronanlage in dieser Pruefreihe.
Bestehende Testprodukte, Bestandswerte und dauerhaft freigegebene private
Datenbank-/Stripe-/Brevo-Konfigurationen bleiben erhalten. Rollbackartefakte
frueherer Websitebereitstellungen bleiben erhalten. Deployschalter false.

Offen bleiben die sechs dokumentierten Dependency-Befunde, die erneute
Rechtsfreigabe und die kontrollierte Zusammenfuehrung/Bereitstellung der noch
lokalen Websitekorrekturen. Keine pauschale Gesamt-, Produktions- oder
Cutoverfreigabe; kein Commit oder Push.

### 3.67 Gezielte Dependency-Sicherheitsupdates und lokale Regression (25.10.2026)

Auf ausdrueckliche Freigabe ausschliesslich im Warenkorb-Worktree aktualisiert:

| Paket | Vorher | Jetzt |
| --- | --- | --- |
| next / eslint-config-next | 16.3.0 | 16.4.3 |
| sharp | 0.35.3 | 0.35.5 |
| brace-expansion | 1.1.18 / 5.0.9 | 1.1.21 / 5.0.12 |
| js-yaml | 4.3.1 | 4.3.2 |
| source-map-js | 1.2.1 | 1.2.2 |
| zod | 4.4.3 | 4.6.7 |

Die Lockdatei bindet auch die zugehoerigen Next-/sharp-Nativpakete und
libvips 1.3.4; erforderliche SWC-Helfer und die aufgeloeste fastq-Patchversion
sind mit aktualisiert. Keine neuen direkten Abhaengigkeiten, kein
`npm audit fix --force`, keine Overrides, keine fachliche Aenderung.
Node 24.16.0 / npm 11.13.0; direkte Versionen bleiben exakt gepinnt.
Releaseabgleich anhand der offiziellen
[Next-Releasehinweise](https://github.com/vercel/next.js/releases/tag/v16.4.3)
und [sharp-Releasehinweise](https://github.com/lovell/sharp/releases/tag/v0.35.5).

| Nachweis | Ergebnis |
| --- | --- |
| Saubere Installation `npm ci` | Bestanden |
| `npm audit --json` und `npm audit --omit=dev --json` | Jeweils 0 bekannte Befunde, zuvor 6 |
| `npm ls` fuer direkte und betroffene indirekte Pakete | Versionen konsistent, keine ungueltigen Peer-Abhaengigkeiten |
| `npm run lint:test` | Bestanden |
| Node-Regression | 201/201 bestanden, einschliesslich 3 neuer Offline-Versions-/Lockdateitests |
| Shell-Integration | Vorhandene lokale Mock-Suite bestanden; optionaler PHP-Klickstatistiktest mangels `php` im Bash-PATH uebersprungen |
| `npm run build:test` | 23 Seiten, TypeScript, Vorschaubilder und Testexportpruefung bestanden |
| PHP 8.4.23 | Lint und Ausfuehrung aller 29 nicht-Hosting-PHP-Testsuites bestanden; Erweiterungen nur prozesslokal geladen |
| Android `testDebugUnitTest lintDebug --offline` | Erfolgreich; Unit-Ergebnis unveraendert/up-to-date: 66 Tests, 0 Fehler, 0 uebersprungen; Lint erneut ausgefuehrt |
| `git diff --check` / Geheimwertpruefung | Bestanden |

Windows-POSIX-Rechtepruefungen bleiben in den betreffenden PHP-Setup-Tests
plattformbedingt ausgenommen. Keine erneuten echten MySQL-, Provider-,
Browser- oder Geraetenachweise behauptet. Ein unterbrochener Testbuild wurde
vollstaendig erfolgreich wiederholt; seine lokale kuenstliche Bild-Fixture
nach Vergleich entfernt und ihre Abwesenheit geprueft. Die drei neuen Offline-Tests verhindern den
Rueckfall auf die hier bekannten verwundbaren Versionen, ersetzen aber
keinen erneuten aktuellen Audit bei spaeteren Aenderungen.

Das Dependency-Gate aus 3.66 ist lokal geschlossen; dies ist kein allgemeiner
Sicherheitsfreispruch und keine Warenkorb-Gesamt-, Rechts- oder Produktionsfreigabe.
Produktionsplan und Abnahmebericht fortgeschrieben. Alle zuvor vorhandenen
anderen Arbeitsbaumaenderungen bleiben unveraendert. Keine App-/PHP-/Workflow-
Funktionsaenderung, kein Commit, Push, Deployment oder Serverzugriff.
Testprodukte, Bilder, Bestandsdaten und private Konfigurationen bleiben erhalten.
Erneute Rechtsfreigabe und kontrollierte Integration/Bereitstellung der lokalen
Websitekorrekturen bleiben offen.

### 3.68 Gemeinsamer Testwebsite-Stand und abschliessende Sichtpruefung (25.10.2026)

Die vorhandenen lokalen Websitekorrekturen aus 3.66 und die gezielten
Sicherheitsupdates aus 3.67 sind gemeinsam als privater, rollbackfaehiger
Previewstand ausschliesslich unter `/home/www/carmaja-test-site` bereitgestellt.
Kein Commit, Push, GitHub-Workflowlauf oder dauerhafte Uebernahme in den
Testwebsite-Zweig; dieser Nachweis ist nicht mit einer CI-/Branchintegration
gleichzusetzen. App, Test-API, Publisher, Runtime und Produktion unveraendert.

Der reale bestehende Testkatalog enthaelt weiterhin genau fuenf Produkte.
Bei HANDY-TEST waren die oeffentlichen Versionsmetadaten des bisherigen
Previewkatalogs veraltet: nur productVersion, sourceHash und updatedAt
wichen von der bereits veroeffentlichten Quelle ab. Nach kanonischer
Serverpruefung wurden ausschliesslich diese Metadaten im Buildkatalog auf
Version 7 angeglichen; kein Speichern, Publizieren oder Bestandsvorgang.
Produktinhalt und Verkaufsfreigabe unveraendert. HANDY-TEST BILD3200 bleibt
salesEnabled=false und ohne Anfangsbestand; HANDY-TEST weiterhin 3 / 0 / 3.

| Nachweis | Ergebnis |
| --- | --- |
| Testbuild aus dem aktuellen Warenkorb-Worktree | 27 statische Seiten, TypeScript, 30 Vorschaubilder und Exportpruefung bestanden |
| Bildschutz | Alle zehn kataloggebundenen Originale unveraendert; auch nicht verwendete vorhandene Bilder bleiben erhalten |
| Bereitstellung | 142 geaenderte Dateien, darunter 25 HTML-Seiten; Assets vor Seiten, atomarer Austausch je Datei, bestehende gehashte Assets erhalten |
| Startseite, Uebersicht, fuenf Detailseiten | Aktuelles Design, alle fuenf Produkte, Texte, Messwerte und Bilder sichtbar; keine Vinted-/Marktplatzlinks |
| Bildtest und Produkthinweise | Beide Bilder und kompakte Hinweise sichtbar; deaktivierte Verkaufsfreigabe, kein aktiver Kaufweg |
| Warenkorb ohne freigegebene Kauf-Runtime | Verstaendliche Nicht-erreichbar-Meldung; ausdruecklich nichts reserviert, kein Checkout |
| success / cancel / error | Seiten und Rueckmeldungen sichtbar; ohne gueltigen Kontext keine behauptete Bestellung |
| Admin-Netzwerkfehler | Kuenstliche, nicht existente Anmeldung endet mit "Anmeldung nicht moeglich." statt einer dauerhaft laufenden Ladeanzeige |
| Rechtstexte und Navigation | Impressum, Datenschutz, Shopbedingungen, Widerruf, Versand/Zahlung sowie Ueber mich, Material/Pflege und Kontakt mit Footer erreichbar; Rechtstexte weiterhin Testfassung |
| Zugangsschutz | Testwebsite ohne bestehende Basic-Auth-Anmeldung weiterhin HTTP 401 |
| Bestehende Daten | Vorher-/Nachhervergleich der privaten Produkte, Entwuerfe, Bilder, Konfigurationen, isolierten Handystage, API-Dateien und beider MySQL-Testziele unveraendert; Datenbankpruefung nur lesend ueber TLS |
| Abschluss | Browserpruefung bestanden, danach Schutzvergleich erneut bestanden und Previewjournal auf verified gesetzt |

Der erste Aktivierungsversuch wurde vor jeder Websiteaenderung vom
Originalbildschutz abgewiesen: der Export enthielt zusaetzliche, nicht zum
Katalog gehoerende Repositorybilder. Das temporaere Paket wurde auf die
zehn benoetigten Originale begrenzt und erneut geprueft. Keine bestehenden
Bilder ueberschrieben oder geloescht. Temporarer PHP-Helfer lokal und
serverseitig gelintet; acht Pfadguards bestanden.

Die bestehende Root-Shop-API bleibt ohne dauerhafte Test-Kaufkonfiguration
fail-closed (Context HTTP 401). Dieser statische Sichtnachweis ersetzt weder
die isolierten dynamischen E2E-Nachweise noch eine dauerhafte Kauf-Runtime.
Die neue Bestandsanzeige nach Wiedereinlagerung ist durch die sieben
ausfuehrenden Komponententests aus 3.66 abgedeckt; fuer diese Korrektur wurde
hier kein neuer angemeldeter Browser-/Rueckgabevorgang behauptet.

Cleanup abgeschlossen: ausschliesslich das eigene Incoming-Verzeichnis
`/home/www/carmaja-test-deploy/incoming/cart-final-20261025-c8d193ab` und das
zugehoerige lokale temporaere Build-/Uebertragungsverzeichnis entfernt;
Abwesenheit geprueft. Die private Ruecksicherung unter
`/home/www/carmaja-test-deploy/backups/cart-final-20261025-c8d193ab` bleibt
mit Journal, Originalen und geprueftem restore.php erhalten. Nach Cleanup
erneut unveraenderte Schutzfingerprints bestaetigt. Ein Windows-Zeilenende
im zunaechst per stdin uebertragenen Verify-Aufruf wurde durch den direkten
SSH-Aufruf korrigiert; kein Fehler des installierten Websitepakets.
Keine neuen Testtabellen, Endpunkte, Zugangsdaten oder Cronjobs angelegt.
Die dauerhaft freigegebenen Testkonfigurationen bleiben erhalten.
`CARMAJA_TEST_DEPLOY_ENABLED=false` abschliessend bestaetigt.

Abschliessend 16 gezielte Node-Tests fuer Adminfehler, Bestandsaktualisierung,
Dependency-Vertrag, Seitendesign und sichere Widerrufsfehlermeldungen erneut
bestanden. `git diff --check` und fokussierter Geheimwertscan bestanden.
Gegenueber dem Stand der Sicherheitsregression wurden im Arbeitsbaum nur
Produktionsplan und dieser Abnahmebericht weitergeschrieben; alle anderen
vorhandenen Aenderungen unveraendert erhalten.

Offen bleiben die dauerhafte Git-/Branchintegration, die erneute externe
Rechts-/Datenschutzfreigabe und die praktische Versandfreigabe. Insbesondere
ist noch zu bestaetigen, wie viele fertig verpackte Armbaender in die einzige
Sendung fuer 2,70 EUR passen. Keine Mengenobergrenze oder weitere Versandart
erfunden; vor der Entscheidung keine Produktionsfreigabe. Der ausstehende
schriftliche Nachweis ist in `shop-cart-legal-review.md` abgegrenzt.
Keine Warenkorb-Gesamt-, Produktions- oder Cutoverfreigabe.

### 3.69 Praktische Versandkapazitaet bestaetigt (25.10.2026)

Die Betreiberin hat die Frage nach der maximalen Zahl fertig verpackter
Armbaender pro Maxibrief fuer 2,70 EUR mit "10" beantwortet. Die bislang
fehlende Angabe zur Packkapazitaet aus 3.68 liegt damit vor. Dies aendert
weder Bestandsmengen noch den aktiven Warenkorb-/Checkoutvertrag.

Lesender Vertragsabgleich: `cart-contract.php` erlaubt derzeit hoechstens
100 unterschiedliche Positionen, jedoch keine Gesamtmengengrenze von 10.
Als Folgeentscheidung wird maximal 10 Stueck je Bestellung ueber alle
Positionen vorgeschlagen. Diese neue Bestellgrenze muss noch ausdruecklich
bestaetigt und anschliessend technisch umgesetzt/getestet werden; sie wird
nicht aus der blossen Kapazitaetsangabe als bereits freigegeben behauptet.
Versand weiterhin einmalig 2,70 EUR, keine weitere Versandart/Teillieferung.
Erneute Rechts-/Datenschutz-/Versandfreigabe bleibt separat ausstehend.
Nur Dokumentation aktualisiert, keine Code-, Daten-, Server- oder
Produktionsaenderung; kein Commit, Push oder Deployment.

### 3.70 Freigegebene Zehnergrenze lokal implementiert (25.10.2026)

Auf die ausdrueckliche Nutzerfreigabe "Maximal 10 Armbaender je Bestellung
freigegeben" die Gesamtmengengrenze im bestehenden Warenkorb-Worktree
implementiert. Diese Entscheidung ersetzt den offenen Vorschlag aus 3.69.
Einmalig 2,70 EUR Versand, Zahlungsarten und Bestandsvertrag unveraendert.

- `cart-contract.php`: Gesamtmenge hoechstens 10, einschliesslich zusammen-
  gefuehrter Produkt-IDs; eigener HTTP-422-Fehler `cart_quantity_limit`.
  Pruefung vor Addition verhindert Ueberlaeufe durch manipulierte Mengen.
- `cart-commerce.php`: Pruefung beim Ersetzen, Ausstellen des Checkout-
  Kontexts und erneut beim Reservieren von Warenkorb und Snapshot. Alte
  uebergrosse Kontexte ergeben keine neuen Checkout-/Payment-/Reservierungs-
  eintraege. Alte zu grosse Warenkoerbe bleiben unverkuerzt lesbar, aber
  canCheckout=false; neue Antwortkennzeichnung quantityLimitExceeded.
- Bereits begonnene Zahlungen bleiben ueber ihre bestehende Idempotenzbindung
  erreichbar. Historische Summenpruefung, Finalisierung, Worker, Refund und
  Wiedereinlagerung werden nicht nachtraeglich mit der neuen Grenze blockiert.
- `cart-client.ts`, `shop-cart.tsx`, `shop-buy-now.tsx`: gemeinsamer Hinweis,
  Eingabegrenzen unter Beruecksichtigung anderer Positionen, verstaendliche
  Serverfehlermeldung, gesperrter Checkout bei Ueberschreitung. Kein stilles
  Kuerzen. Explizites Leeren erlaubt auch die Korrektur alter zu grosser
  Warenkoerbe. Lagerbestaende ueber 10 bleiben zulaessig.

| Pruefung | Ergebnis |
| --- | --- |
| 10 sowie 6+4, 1+9, 9+1 | Erlaubt |
| 11 sowie 6+5, 5+6, elf verschiedene Produkte | Abgewiesen |
| Doppelte Produkt-IDs und manipulierte grosse/negative/gebrochene Mengen | Grenze nicht umgehbar, kein Integerueberlauf |
| Alter Warenkorb / alter oder manipulierter Checkout-Kontext ueber 10 | Kein neuer Checkout, keine Bestandsmutation; Warenkorb sichtbar/leerbar |
| Bestehende Zahlung mit gleicher Idempotenzbindung ueber 10 | Unveraendert wiederverwendbar, keine neue Mutation |
| Zehn Stueck reservieren | Beide Positionen gemeinsam, Versand genau einmal 270 Cent |
| Lagerbestand 50 / historische Zahlungssumme mit Menge 11 | Weiterhin unveraendert verarbeitbar |
| PHP 8.4.23 | Lint und alle 30 lokalen Nicht-Hosting-Testsuites bestanden |
| Node | 208/208 bestanden, einschliesslich sieben neuer Client-/UI-Grenztests |
| Installation / Audit / Lint | npm ci, 0 bekannte Auditbefunde und Website-Lint bestanden |
| Testbuild | 23 kuenstliche Seiten, TypeScript, drei Vorschaubilder und Exportpruefung bestanden |
| Shell | Syntax sowie lokale Bootstrap-/Rollback-Mocks und sieben Orchestrator-Mockszenarien bestanden |
| Abschluss | git diff --check und fokussierter Geheimwertscan bestanden |

Die neue PHP-Integrationssuite fuehrt die echten Commerce-Methoden gegen
geskriptete PDO-Antworten aus und prueft insbesondere ausbleibende Writes
bei Ablehnung sowie Rollback. Dies ist kein erneuter echter MySQL-, HTTP-,
Stripe- oder Browsernachweis. Vorhandene praktische Nachweise werden nicht
umgedeutet. Eine Testvariablen-Lintregel und zwei bestehende Regex-
Vertragserwartungen an die nun zusaetzlich gesperrte Checkoutschaltflaeche
angepasst; danach vollstaendige Node-Regression und Lint gruen.
Die Hosting-Kopier-/Statistik-Suite aus dem pauschalen npm-test-Befehl und
der PHP-Hosting-Klickstatistiktest bleiben gemaess Testgrenze ausgeschlossen;
stattdessen Node-Regression und oben genannte Shell-Mocks separat ausgefuehrt.
Windows-POSIX-Pruefungen bleiben in den Setup-Suites plattformbedingt
ausgenommen. Keine neue Android-Pruefung, da App-Dateien unveraendert.
Keine Aenderungen an App, Schema, Legal-Bundle-Inhalten oder Konfigurationen.
Neue verbindliche Mengenregel in Produktionsplan und Rechtspruefuebersicht
nachgetragen; keine Rechtsfreigabe erteilt. Vor Bereitstellung sind die
versionierten Rechtstext-/Checkoutfassungen entsprechend nachzuziehen.
Kein Serverzugriff, Commit, Push, Deployment oder Cutover. Testprodukte,
Bilder, Bestandswerte und private Konfigurationen bleiben erhalten.

### 3.71 Zehnergrenze in versionierten Testtexten und Website-Preview (25.10.2026)

Neue Testfassung `cmj-test-legal-2026-10-25-v4` mit Summengrenze zehn und
einmalig 2,70 EUR Versand. Testfassung v3 bleibt unter ihrer bisherigen
Archivadresse erhalten. Neuer Produktionsentwurf
`cmj-production-legal-2026-10-25-v7`, weiterhin
`awaiting_external_approval` und nicht als aktives Produktionsbundle
ausgewaehlt. Sechs neue ausfuehrende Node-Tests pruefen die echten Bundles,
alle sechs historischen Inhalts-Hashes, Archivzuordnung, Checkout-Snapshot-
Trennung, deterministische Hashes und die Produktionssperre. Zahlungs-,
Datenschutz- und Widerrufsinhalte gegen den bisherigen Entwurf unveraendert.
Der lokale E2E-Adapter erwartet bei einem kuenftigen neuen Lauf die aktuelle
Testfassung; keine bestehende Runtime oder Datenbankbindung umgeschrieben.

| Pruefung | Ergebnis |
| --- | --- |
| npm ci / Audit / Lint | Bestanden, 0 bekannte Auditbefunde |
| Node | 214/214 bestanden |
| PHP 8.4.23 | Geaenderter Adapter gelintet, alle 30 Nicht-Hosting-Suites bestanden |
| Kuenstlicher Testbuild | 24 Seiten, TypeScript und Exportpruefung bestanden |
| Build mit bestehenden Testprodukten | 28 Seiten, 30 Vorschaubilder, zehn Originalbilder unveraendert |
| Shell | Syntax, Bootstrap-/Rollback-Mocks und sieben Orchestratorszenarien bestanden |
| Browser | Mengenhinweis in Warenkorb und HANDY-TEST; Shopbedingungen und Versand mit Gesamtgrenze; neues und altes Archiv erreichbar |
| Weitere Seiten | Fuenf Produkte, Bildtest mit beiden Bildern/Freigabe aus, Footer, Impressum, Datenschutz, Widerruf, success/cancel/error erhalten |
| Schutzvergleich | Testprodukte/-bilder, private Konfigurationen, komplette V5-Stufe, Test-API und beide Datenbanken vor/nach identisch |

Der geschuetzte Preview `cart-limit-20261025-7bd491e2` wurde ausschliesslich
unter `/home/www/carmaja-test-site` aktiviert: 133 geaenderte Dateien,
26 geaenderte HTML-Seiten, Assets vor Seiten, dateiweiser atomarer Austausch.
Hashgebundene private Ruecksicherung unter
`/home/www/carmaja-test-deploy/backups/cart-limit-20261025-7bd491e2`;
`mark-verified` erst nach Browserkontrolle und erneutem Schutzvergleich.
Der erste Versuch stoppte vor jeder Websiteaenderung an der nicht erlaubten
neuen Archivdatei `index.txt`. Genau diese Route im isolierten Helper
ergaenzt, Pfadtests/Lint wiederholt, danach Aktivierung erfolgreich.
Kein Umgehen des Pfadschutzes, keine pauschale Verzeichnisfreigabe.

Wichtig: Dies ist eine statische Testwebsite-Bereitstellung mit neuem
Clientvertrag, kein dauerhafter Shop-Runtime-Deploy. Root-Warenkorb/API
bleibt ohne diese Runtime fail-closed; sichtbar ist die verstaendliche
Nicht-erreichbar-Meldung ohne Kaufmoeglichkeit. Keine neue Reservierung,
Bestellung, Zahlung oder Mail erzeugt. Der neue serverseitige Grenzvertrag
aus 3.70 bleibt lokal gegen geskriptete PDO-Antworten getestet; kein
erneuter echter MySQL-/Stripe-/dynamischer Warenkorb-E2E-Nachweis behauptet.
Historische Zahlungsnachweise und bestehende Testkonfigurationen erhalten.

Eigene temporaere lokale und serverseitige Build-/Upload-/Diagnosedateien
nach Verifikation entfernt; Ruecksicherung bewusst erhalten. Deployschalter
wieder `false`. Kein Commit, Push oder Branchwechsel. App, main,
Produktionshosting und Produktion unveraendert. Externe Rechtsfreigabe,
dauerhafte Integration und spaetere Produktions-/Cutoverfreigabe bleiben offen.

### 3.72 Zehnergrenze praktisch gegen MySQL 8 nachgewiesen (25.10.2026)

Nach dem Website-Preview den abgegrenzten neuen Serververtrag separat mit
`website/scripts/cart-limit-mysql-test.php` nachgewiesen. Ausschliesslich
privater CLI-Aufruf mit `/usr/bin/php8.4`; feste vorhandene Testkonfiguration
wiederverwendet, keine neuen Credentials und keine Suche in Secret-Stores.
Privates Testverzeichnis `cart-e2e-ui-20261025-89a1d34c`, kein oeffentlicher
Endpunkt, Worker oder Cron. Programme vor Ausfuehrung per SHA-256 und Lint
geprueft. Beide Verbindungen mit aktiver TLS-Sitzung, MySQL 8 und InnoDB;
bereits akzeptiertes CA-/Hostidentitaets-Restrisiko unveraendert.

Nur die nachweislich leere getrennte Restore-Testdatenbank verwendet.
Source mit Handy-Testprodukten ausschliesslich lesend geprueft. Eigentum
und Leerheit vor DDL protokolliert; Schema und Vorwaertsmigrationen nur in
diesem kuenstlichen Ziel. Je zwei kuenstliche Produkte mit Bestand 50,
neue Test-Legal-Fassung v4 kanonisch hashgeprueft und am Snapshot gebunden.
Interner SQL-Status approved ist nur Teil der wegwerfbaren Testfixture,
keine externe Rechtsfreigabe oder Produktionsaenderung.

| Praktischer Fall | Ergebnis |
| --- | --- |
| 6 + 4 Stueck | Beide Positionen atomar reserviert, genau einmal 270 Cent Versand |
| Wiederholung desselben Checkoutschluessels | Bestehende Bindung wiederverwendet, kompletter Datenfingerprint unveraendert |
| Nachweislich nicht erstellte Session, kuenstlich injiziert | Beide Reservierungen gemeinsam freigegeben |
| Neue Warenkorbaenderung 6 + 5 | HTTP-Vertragsfehler cart_quantity_limit / 422, keine Datenmutation |
| Persistierter alter Warenkorb mit 11 | Ungekuerzt lesbar, kein neuer Kontext oder Checkout; bestehende Idempotenzbindung bleibt erreichbar |
| Warenkorb 10, alter/manipulierter Quote 11 | Abgewiesen ohne Datenmutation |
| Explizites Leeren | Erfolgreich |
| Neue Legal-Fassung | Snapshot verweist auf v4 und identischen Inhalts-Hash |
| Provider/Bestellungen/Mails | Kein Provideraufruf, keine Bestellung und kein Maileintrag |
| Cleanup | Eigene Tabellen vollstaendig entfernt, Restore wieder leer; Source/Produkte/Dateien/Konfigurationen unveraendert |

Der Adapter bereinigt auch Fehlerlaeufe in finally und verweigert Cleanup
bei fremden Tabellen. Kein HTTP-/Stripe-Zahlungsnachweis fuer diese neue
Grenze behauptet; diese Testreihe ergaenzt die vorhandenen Zahlungs- und
Browsernachweise. Lokale Node-Regression nun 215/215, Lint und Diff-Pruefung
bestanden. Eigene private Serverstufe und lokale Paketdateien entfernt,
permanente private Testkonfigurationen sowie Website-Ruecksicherung bleiben.
Root-Shop-Runtime weiterhin nicht dauerhaft aktiviert; keine Gesamt-,
Produktions- oder Cutoverfreigabe. Naechstes menschliches Gate bleibt die
erneute Rechts-/Datenschutz-/Versandfreigabe des Warenkorbentwurfs v7.

### 3.73 Externe Rechts-, Datenschutz- und Versandfreigabe gemeldet (25.10.2026)

Die Betreiberin hat ausdruecklich mitgeteilt, dass der externe Pruefer die
endgueltige Dokumentversion samt Inhalts-Hash, Mehrpositions-Kaufablauf,
Vertragsschluss/AGB-Zustimmung, SEPA-Bearbeitung, Teilwiderruf-/
Teilerstattungsregeln, Versand-/Ruecksendekosten und Datenschutz bestaetigt
hat. Zuordnung zur aktuellen Prueffassung
`cmj-production-legal-2026-10-25-v7` mit maximal zehn Armbaendern insgesamt
und einmalig 2,70 EUR Versand. Der erneut kanonisch validierte Inhalts-Hash
und der genaue Bestaetigungsumfang sind in `shop-cart-legal-review.md`
festgehalten. Keine vertraulichen Originalunterlagen oder Prueferdaten
uebernommen; keine eigene rechtliche Bewertung oder Einsichtnahme behauptet.

Das externe fachliche Freigabegate ist damit geschlossen. Die bestehende
Prueffassung bleibt byte-/hashgleich: Ihr technischer Status ist Teil des
Hashes und wird hier nicht umgeschrieben. Vor spaeterer Auswahl einer
`approved`-Fassung muessen Textgleichheit und neuer technischer Bundle-Hash
kontrolliert zu dieser Freigabe gebunden werden. Historische Bundles,
Testfassung v4 und aktive Produktionsauswahl v5 bleiben unveraendert.

Ausschliesslich drei bestehende Dokumentationsdateien aktualisiert.
Keine Anwendungscode-, Runtime-, Daten- oder Serveraenderung. Kein Commit,
Push, Deployment, Produktionszugriff oder Cutover. Root-Testshop weiterhin
fail-closed; dauerhafte Integration und Produktionsgates bleiben offen.
Pruefung: 22/22 gezielte Legal-Bundle-/Archiv-/Snapshot-Tests bestanden;
separate ausfuehrende Pruefung der dokumentierten ID/Hash-Bindung,
unveraenderten Produktionsauswahl und Checkout-Sperre bestanden.
`git diff --check` bestanden. Keine Wiederholung von Provider- oder
Datenbanktests fuer diese reine Dokumentation.
Die frueheren ausstehenden Rechtsfreigaben in 3.69 bis 3.72 sind historische
Zwischenstaende und werden durch diesen Nachtrag abgeloest.

### 3.74 Lokale Zusammenfuehrung des geprueften Warenkorbstands (26.10.2026)

Auf Auftrag der Betreiberin die bislang uncommitteten Warenkorb-Aenderungen
gemeinsam auf `codex/shop-inventory-cart` geprueft und fuer einen lokalen
Integrationscommit zusammengefasst. Ausgangspunkt
`0b60340e794e1e333c93b927b539738a88896342`; keine Uebernahme fremder Worktrees
oder neuer main-Aenderungen, kein Merge nach main. Der Stand umfasst:

- einheitliche Websitegestaltung, kompakte Natursteinhinweise und das
  Startseitenfoto ohne Mondornamente;
- Gesamtmengengrenze zehn, einmalig 270 Cent Versand, sichere Warenkorb-
  und Mehrtab-CSRF-Behandlung sowie unveraenderte historische Zahlungssnapshots;
- Rueckkehr-/Widerrufsfehlermeldungen, Admin-Anmeldung und Aktualisierung
  der Bestandsanzeige nach einer geprueften Rueckgabe;
- bereits gewuenschte knappe App-Rueckmeldung "Veroeffentlichung bestaetigt"
  einschliesslich angepasstem Compose-Test;
- gezielte Abhaengigkeitsupdates, die isolierten Testwerkzeuge und ihre
  Schutz-/Cleanup-Tests sowie den dokumentierten Freigabenachweis v7.

| Erneute lokale Pruefung | Ergebnis |
| --- | --- |
| npm ci / Audit | 345 Pakete geprueft, 0 gemeldete Sicherheitsluecken |
| Website-Lint | Bestanden |
| Node | 215/215 bestanden, einschliesslich lokal gemockter Shell-/Deploymentfaelle |
| PHP 8.4.23 | 22 geaenderte/neue PHP-Dateien gelintet, 30/30 Nicht-Hosting-Suites bestanden |
| Android testDebugUnitTest | 66/66 Tests, 15 Suites, keine Fehler oder uebersprungenen Tests |
| Android lintDebug | Bestanden, neun Warnungen, keine Error/Fatal-Befunde |
| Compose-Testquellen | compileDebugAndroidTestKotlin bestanden; kein neuer Geraetelauf, Handypruefungen bleiben manuell |
| Testbuild/Export | 24 Seiten mit kuenstlichem Katalog, drei Vorschaubilder, Exportpruefung bestanden |
| Zusaetzliche Shell-Pruefung | Bash-Syntax, Bootstrap/Rollback/Reparatur und sieben Smoke-Orchestratorszenarien mit lokalen Mocks bestanden; separate Hosting-PHP-Pruefung nicht ausgefuehrt |
| PowerShell-Eingabehelper | Syntaxpruefung bestanden, nicht gestartet |
| Geheimwert-/Pfadpruefung | 68 geaenderte/neue Dateien ohne Treffer der geprueften Secretmuster; keine Runtime-, Signing- oder Buildartefakte enthalten |

`assembleBeta` erneut versucht und korrekt vor dem Build abgebrochen,
weil die lokale stabile Beta-Signierung fehlt. Keine Suche nach Secrets,
kein Ersatzschluessel und keine Signierungs- oder Versionsaenderung.
Dieser lokale Integrationscommit ist deshalb kein neuer signierter
Beta-Release: Fuer die noch nicht in der installierten Beta enthaltene
Textkuerzung bleibt ein separat freizugebender GitHub-Build mit den
vorhandenen CI-Secrets erforderlich. Bestehende Beta.4-Nachweise bleiben
historisch erhalten und werden nicht als neuer Buildnachweis ausgegeben.

Die externe Freigabe v7 bleibt an die unveraenderte Prueffassung gebunden;
kein Wechsel der aktiven Produktionsfassung. Technische approved-
Ueberfuehrung, dauerhafte Testbranch-/Runtimeintegration und spaetere
Produktionsgates bleiben getrennt. Keine erneuten Server-, MySQL-, Stripe-,
Brevo- oder Browsertests in diesem reinen Integrationsschritt.
Kein Push, Deployment, Serverzugriff, main-Merge oder Cutover; vorhandene
Testprodukte, Bilder, Bestaende und private Konfigurationen unangetastet.
`git diff --check` vor der lokalen Sicherung bestanden; im Commit keine
Datei aus `website/hosting/**`, keine Zugangsdaten oder Laufzeitdatei.

### 3.75 Integrierter Testbetrieb am 27. Oktober 2026 – verifiziert

Auf ausdruecklichen Auftrag wurde der lokale Integrationsstand
`1b20d2e2c7cc1f95f549612144ecc1f9844dfc26` ausschliesslich in der
bestehenden IONOS-Testumgebung bereitgestellt und abschliessend verifiziert. Keine Produktion, kein
main-Merge, kein Push und kein neuer Commit in diesem Schritt.

Bereits nachgewiesen:

- npm ci und Audit ohne Befund, Website-Lint, 215 Node-Tests und 31
  PHP-8.4-Suites im ersten Durchlauf. Abschliessend erneut alle 32 aktuellen
  PHP-Suites bestanden, darunter 23 Test-Runtime-Sicherheitspruefungen;
  PHP-Lint und `git diff --check` ebenfalls bestanden.
- Testexport mit sieben Produkten fuer den kuenstlichen E2E-Lauf sowie
  anschliessender Fuenf-Produkt-Export fuer den abschliessenden Cleanup
  erfolgreich gebaut und auf den Testexportvertrag geprueft.
- Private, getrennte Test-Shop-Runtime mit MySQL 8/InnoDB und aktiver TLS-
  Sitzung, Stripe-Sandbox, Test-Legal-Bundle v4 und Brevo-Testempfaenger-
  Allowlist. Persistente private Datenbank-, Stripe- und Brevo-Konfigurationen
  bleiben erhalten; kein Zugriff auf fremde Secret-Stores.
- Root-Routen `/shop/v3` und `/admin/v1` rollbackfaehig angebunden;
  bestehende Produkt- und V5-App-Routen unveraendert erhalten.
- CORS, sichere Shopsitzung, Mehrtab-CSRF, no-store, Idempotenz,
  veraltete Warenkorbversion und ungueltige Mengen praktisch per HTTP geprueft.
- Aktueller Websiteexport privat rueckgesichert und dateiweise atomar
  bereitgestellt. Eine Bildkollision mit unbenutzten Repository-Beispielbildern
  wurde vor der ersten Websiteaenderung abgefangen; das korrigierte Paket
  enthaelt ausschliesslich die Originalbilder des ausgewaehlten Testkatalogs.
- Alle fuenf vorhandenen Produkte erhalten. HANDY-TEST BILD3200 bleibt
  nicht kaufbar; beide Browser-Grossansichten laden 3200 x 1800 bzw.
  1800 x 3200 Pixel. Rechtstext-, Widerrufs-, Abbruch- und Fehlerseiten
  erreichbar, ohne produktive API-/Websiteverlinkung im geprueften Vertrag.
- Browser-Warenkorb mit ausschliesslich zwei neuen kuenstlichen Artikeln,
  Mengen 2 + 1, 75,00 EUR Warenwert plus einmalig 2,70 EUR Versand.
  Stripe-Sandbox zeigt Karte, Klarna und SEPA, keinen PayPal-Kaufweg;
  verpflichtende AGB-Zustimmung und Test-Rechtstextlink vorhanden.
- Eigener IONOS-UnixCron `Carmaja-Test-Cart-Worker`, `*/5 * * * *`,
  mit `/usr/bin/php8.4` und ausschliesslich privatem Testworker angelegt
  und in der Kundenoberflaeche verifiziert. Erster echter Lauf
  18:46:34 UTC: 735 ms, Exit 0, beide Worker-Leases freigegeben und
  MySQL-Start-/Erfolgszeitpunkte vorhanden. Direkter Erstlauf: 116 ms.
  Separater flock-/Lease-Konkurrenztest samt Wiederuebernahme bestanden;
  dessen eigener technischer Pruefeintrag unmittelbar entfernt.

Fortsetzung 27. Oktober: Zweiter echter Cronlauf um 18:51:38 UTC, 662 ms,
anschliessende Laeufe im Fuenf-Minuten-Takt ebenfalls erfolgreich. Der
erste Browsercheckout wurde von Stripe als `expired`/`unpaid` bestaetigt;
kein PaymentIntent, keine Zustimmung und keine Bestellung. Die generische
Stripe-Endseite unterscheidet Zahlung und Zeitueberschreitung nicht eindeutig.
Signiert zugestelltes Ablaufereignis durch den Worker verarbeitet, beide
Reservierungen gemeinsam `released`, kuenstlicher Bestand weiterhin 30/30,
keine Bestellmail. Privater Ablaufnachweis gespeichert; kein Zahlungserfolg
allein aus der Nutzerbestaetigung abgeleitet.

Abschliessender erfolgreicher Browserkauf:

- Stripe bestaetigt ausschliesslich Sandbox, Karte, `paid`, 7.770 Cent und
  angenommene AGB. Originales signiertes `checkout.session.completed`
  verschluesselt persistiert und durch den Worker verarbeitet.
- Die vorhandene Testdatenbank hatte keine `carmaja-v1`-Bestellnummernsequenz.
  Die erste Finalisierung scheiterte deshalb transaktional ohne Teilabbuchung;
  Reservierungen blieben blockierend und die Inbox im Retry. Der neue
  Test-Runtime-Initialisierer legt ausschliesslich bei fehlender Sequenz und
  null Bestellungen den Startwert an. Vorhandene gueltige Sequenzen werden
  unveraendert erhalten; fehlende Sequenz bei vorhandenen Bestellungen oder
  ungueltiger Zaehler werden abgelehnt. Zweiter Initialisiererlauf: `preserve`.
- Wiederanlauf um 19:24:08 UTC: 1.003 ms, zwei Zahlungs-/Inboxarbeiten und
  zwei Mails verarbeitet. Bestellung `CMJ-00000001`, zwei Positionen mit
  Mengen 2 und 1, Versand einmalig 270 Cent, Test-Legal-Snapshot gebunden.
- Nur kuenstliche Artikel: Bestand 30/30 -> 28/29, reserviert 0/0,
  beide Reservierungen `converted`. Zweifache erneute Ereignisverarbeitung
  und Inbox-Deduplizierung lassen Bestellung, Nummernsequenz, Zahlungen,
  Bestand und Mail-Outbox unveraendert.
- Bestellbestaetigung und Betreiberbenachrichtigung jeweils mit genau
  einem Versuch von Brevo angenommen. Anschliessend beide Nachrichten
  ueber ihre private Nachrichtenkennung im Bericht fuer den erlaubten
  Testempfaenger als `delivered` bestaetigt. Keine Empfaengeradresse,
  Provider-ID oder sonstigen personenbezogenen Inhalte im Repository.
- Browser-Rueckkehr zeigt die bestaetigte Bestellnummer. Abschliessender
  Fuenf-Produkt-Export (194 Dateien) dateiweise atomar aktiviert und gehasht.
  Produktdetail, 3200x1800-/1800x3200-Grossbilder, ausgeschalteter Verkauf
  beim Bildtest, leerer Warenkorb, Rechtstext-/Footer-, Abbruch- und
  Fehlerseiten erneut live geprueft. Live-API: HTTP 200, korrekter
  Test-Origin/CORS und `no-store`.
- Echter Cron laeuft weiter im Fuenf-Minuten-Takt; nach Daten-Cleanup
  erfolgreicher Lauf 19:31:35 UTC mit 107 ms. Worker-Leases freigegeben,
  kein offener Prueffall und keine ausstehende Zahlungs-/Mailarbeit.

Cleanup und erhaltener Betrieb:

- Nur zwei eigene kuenstliche Commerce-Produkte, zwei terminale Checkouts,
  vier Reservierungen, eine Bestellung samt Positionen und zugehoerige
  Payment-/Inbox-/Outbox-/Audit-/Warenkorbreihen transaktional entfernt.
  Bestellnummernsequenz nicht zurueckgesetzt. Keine Tabellen geloescht.
- 18 eigene kuenstliche Produktseiten-/Bilddateien hashgebunden entfernt;
  private Uploadpakete, Exportkopien, HTTP-Testcookie und Einmalskripte
  entfernt. Lokale temporäre Taskdateien nach Abschluss entfernt.
- Bestehende fuenf oeffentliche Produkte und ihre Bilder sowie beide
  V5-/Commerce-Produkte mit bestehenden Bestaenden, Entwuerfen und
  privaten DB-/Stripe-/Brevo-Konfigurationen unveraendert verglichen.
  Getrennte Restore-Datenbank weiterhin leer.
- Angeforderte dauerhafte Test-Runtime, Stripe-Sandbox-Webhook, SDK,
  privater Worker und `Carmaja-Test-Cart-Worker` bleiben bewusst erhalten,
  ebenso die private Originalwebsite-Ruecksicherung und geheimwertfreie
  Testnachweise. Dies sind Betriebsbestandteile, keine offenen Testfixtures.
  Normale anonyme Sitzungen/Rate-Limits bleiben nach ihrem Laufzeitvertrag
  erhalten. Stripe-/Brevo-Sandbox-/Versandhistorie wird nicht als geloescht
  behauptet; lokale kuenstliche Kaufdaten sind entfernt.
- Test-Deployschalter abschliessend `false`. Kein Commit, Push, main-Merge,
  Produktionszugriff oder Cutover. Keine Laufzeitdateien/Secrets versioniert.

Urteil: zusammengefuehrter Testwebsite-/Warenkorb-/Kartenzahlungsbetrieb
verifiziert. Bereits separat bestandene Klarna-/SEPA- und Geraetereihen
bleiben eigene Nachweise; keine erneute Vollpruefung aller Zahlungsmethoden
in diesem Lauf. Release-/CI-, approved-Legal-Ueberfuehrung und alle
Produktionsgates bleiben gesonderte Schritte.

### 3.76 Warenkorb mit Produktvorschaubildern (28.10.2026)

Auf Nutzerauftrag ausschliesslich die geschuetzte Test-Warenkorbansicht
erweitert: kleine Hauptbilder aus der oeffentlichen Projektions-Whitelist,
Artikelverlinkung, getrennte Einzel-/Positionspreise und eine warme,
responsive Bestelluebersicht. Bilder bevorzugen vorhandene 240-Pixel-
Derivate; fehlende oder defekte Bilder erhalten einen neutralen Platzhalter.
Der statische Katalog liefert ausschliesslich Bild- und Linkdaten.
Preise, Verfuegbarkeit, Summen, CSRF, Idempotenz und Checkoutberechtigung
bleiben an die unveraenderte Live-API gebunden.

Nachweise: npm ci/Audit ohne Befund, Website-Lint, 219 Node-Tests (darunter
vier neue ausfuehrende Bild-/Fallback-/Preisvertragspruefungen), bestehende
Shell-Mockreihen, TypeScript, Fuenf-Produkt-Testbuild und Exportpruefung
bestanden. Keine PHP-/API- oder Android-Aenderung; der optionale lokale
Tracking-PHP-Test blieb mangels `php` im PATH uebersprungen.

Nur Warenkorb-HTML/RSC und benoetigte neue statische Assets aktiviert,
zehn geaenderte/neue Dateien; vorherige Fassung privat hashgebunden
rueckgesichert, Aktivierung dateiweise atomar. Andere Seiten einschliesslich
Rechtstexten und Produktdetails unveraendert. Live im Browser: Vorschaubild
240 x 223 geladen, Desktop- und 390-Pixel-Ansicht ohne horizontalen
Ueberlauf. Menge 2 -> 1 -> 2 mit korrekten Summen 58,70/30,70/58,70 EUR;
kein Checkout, keine Zahlung, keine Reservierung ausgeloest.

Vorher-/Nachhervergleich der privaten Produkt-/Bild-/Zugangskonfigurationen
und Commerce-Produkt-/Inventar-/Auditzeilen identisch. Temporäre Kopien,
Uploadpakete und Hilfsdateien bereinigt; private Ruecksicherung bleibt.
Kein Commit, Push, main-Merge oder Produktionszugriff. Test-Deployschalter
lesend weiterhin `false`. Keine neue Gesamt- oder Produktionsfreigabe.

### 3.77 Artikelverwaltung am PC, ausschliesslich Test (28.10.2026)

Auf ausdruecklichen Auftrag eine Test-Browserverwaltung unter
`/artikelverwaltung/` ergaenzt; erreichbar auch ueber den Test-Admin-Link.
Produktname, formatiertes Beschreibungsdokument, Materialien, Metall- und
Pflegeangaben, Messwerte, Preis in EUR, Verkaufsfreigabe und bis zu fuenf
Bilder lassen sich bearbeiten. JPEG-Aufbereitung bis 3200 Pixel/5 MiB,
Hauptbild/Reihenfolge/Entfernen und unterbrochene Bilduebertragung werden
getrennt behandelt. Bei ungeklaerter Bilduebertragung bleibt Publizieren
gesperrt. Automatische Hinweise bleiben serververwaltet und nicht loeschbar.

Der neue private Browseradapter ruft dieselben V5-Dienste wie Android auf:
kein paralleler Produktstore, keine zweite Bestandsquelle. Vollstaendiges PUT
mit `expectedProductVersion`, serverseitiger Version/Hash und Idempotenz;
409 behaelt die Eingaben und sperrt weiteres Speichern/Publizieren bis zum
bestaetigten Neuladen. Speichern, Publizieren, Archivieren/Wiederherstellen
und Bestandspflege sind getrennte Aktionen. Anfangsbestand nutzt die
bestehende einmalige Vormerkung, Anpassungen den bestehenden versionierten
Commerce-Dienst; gepruefte Rueckgaben bleiben in der Bestellverwaltung.

Authentifizierung mit bestehenden App-Testbenutzern, jedoch eigenstaendiger
30-Minuten-Browsersitzung. Hostgebundenes Secure/HttpOnly/SameSite=Strict-
Cookie ausschliesslich unter `/product-browser/`, CSRF, exakte Test-Origin-
Pruefung, bestehende Anmeldesperre und 120 Anfragen/Minute je Sitzung.
Passwort-/Benutzerwiderruf beendet Sitzungen. Keine Geraetetokens im Browser,
kein Passwort in Browser-Speichern, keine Zugangsaenderung. Testgates und
bestehendes V5-Testfenster einschliesslich Namespace/Produktlimit bleiben
wirksam. Alte geschuetzte Master-Testprodukte werden nicht migriert.

Neue Dateien: `components/product-manager.tsx`, `lib/product-browser-client.ts`,
`app/artikelverwaltung/page.tsx`, zugehoeriges CSS,
`test-api-private/program/product-browser.php`, `scripts/product-browser-test.php`
und `tests/node/product-browser.test.mjs` sowie
`tests/php/product-browser-test.php`. Der bestehende Admin erhaelt nur einen
Test-Link; Produktionsseite der Artikelverwaltung bleibt nicht erreichbar.

Nachweise:

- PHP 8.4.23: Lint aller drei neuen PHP-Dateien; 59 bestehende API-Tests und
  37 neue Browser-/Shared-Data-Pruefungen bestanden. Kuenstlicher Roundtrip
  Browser -> App-Dienst -> Browser einschliesslich Konflikten, Bildern und
  Lifecycle; keine lokalen Testfixtures zurueckgelassen. Bootstrap (13),
  Warenkorb- und V5-Guardreihen ebenfalls bestanden.
- npm ci, Lint, TypeScript, 233 Node-Tests einschliesslich 14 neuer
  Browservertrag-/ausfuehrender Editorpruefungen, bestehende Shell-Mockreihen
  und Testbuild/Export bestanden. Audit ohne Befund. Optionaler lokaler
  Tracking-PHP-Aufruf mangels `php` im PATH uebersprungen; Produktionshosting
  nicht veraendert oder global gelintet. Keine Android-Aenderung.
- Test-HTTP: ohne Login 401, fremder Origin 403, DELETE 405; gueltiger
  PUT-Preflight 204 mit Credentials, expliziten CSRF-/Idempotenzheadern und
  `no-store`. Anmeldeseite im Browser sichtbar, keine JavaScript-Fehler.
- Nur Verwaltungs-/Adminseiten, neue statische Assets und zwei private
  Programme plus gekapselter oeffentlicher Testadapter bereitgestellt:
  22 geaenderte/neue Dateien, private hashgebundene Ruecksicherung und
  dateiweise atomare Aktivierung. Die bestehenden Rootrouter und V5-App-
  Programme bleiben unveraendert. Kein Produkt- oder Bildexport ersetzt.
- Vorher-/Nachhervergleich bestehender Produktdateien, Bilder, Konfigurationen,
  App-Zugangs-/Geraetedateien sowie Commerce-Produkt-/Inventar-/Auditzeilen
  identisch. Keine Checkout-, Zahlungs-, Mail- oder Bestandsmutation.

Offen: angemeldeter Live-Browser-/App-Praxisnachweis. Betreiberin kennt ihre
App-Testanmeldung nicht mehr; kein Passwort erraten, ausgelesen oder
zurueckgesetzt. Vor einem Reset ist die gemeinsame Auswirkung auf die
App-Anmeldung ausdruecklich zu klaeren. Lokale Nachweise sind keine Behauptung
eines bestandenen authentifizierten Live-E2E-Tests. Publizieren erzeugt wie
bisher die Testprojektion; ein statischer Testwebsite-Neubuild wird dadurch
nicht automatisch gestartet.

Installationspakete und eigene temporaere Hilfsdateien sind entfernt;
private Ruecksicherung/Installationsjournal und die angeforderte neue
Verwaltung bleiben fuer Betrieb und Rollback erhalten. Kein Commit, Push,
main-Merge oder Produktionszugriff. `CARMAJA_TEST_DEPLOY_ENABLED=false`.
Urteil: implementiert und testseitig bereitgestellt, Browser-Endabnahme offen.

### 3.78 Perlenauswahl und Kalkulation am PC (29.10.2026)

Auf Rueckmeldung der Betreiberin die fehlenden App-Kalkulationsfunktionen in
der Test-Artikelverwaltung ergaenzt, ohne Android-/Produktionsaenderungen:

- Authentifizierter read-only-Endpunkt `GET /product-browser/.../price-list`
  (technisch `index.php?route=/price-list`) liest dieselbe feste
  Google-Preisliste wie `GvizPriceListSource` in Android. Keine frei waehlbare
  URL, keine Zugangsdaten, keine Speicherung einer zweiten Preisliste.
  TLS-Zertifikatspruefung, keine Redirects, 20-Sekunden-/1-MB-Grenze,
  strikter Spalten-/Aktiv-/Duplikatvertrag; keine eval-Ausfuehrung.
- Perlen/Spacer suchen und Mengen 0..9999 waehlen. Arbeitsminuten,
  Stundenlohn, sonstige Kosten und Aufschlag entsprechen der App.
  Dezimalarithmetik ohne Gleitkomma-Geldrechnung; Zeitdivision und Aufschlag
  mit zehn Nachkommastellen HALF_UP, Verkaufspreis mit zwei Stellen HALF_UP,
  Empfehlung auf 50 Cent CEILING, Geldanzeige HALF_EVEN wie NumberFormat.
- Explizite Uebernahme in neuen/geoeffneten Artikel: nur Materialien,
  Spacer/Edelstahl-Zuordnung und Centpreis. Bestehende andere Felder bleiben
  erhalten; Ueberschreiben im Editor bestaetigen. Erst gesondertes Speichern
  ruft V5 auf. Keine automatische Veroeffentlichung oder Bestandsmutation.
- Interne Kalkulationseingaben der App liegen bisher nur auf dem Handy und
  gehoeren nicht zum V5-Payload. Der Browser behauptet keine Synchronisierung
  dieser Daten; seine Vorschau bleibt im offenen Fenster. Seitenwechsel
  warnen bei geaenderter Rechnung. Neue Rechnung behaelt Stundenlohn/Aufschlag.
  In oeffentlichen Produkten erscheinen keine internen Kosten.

Nachweise: PHP 8.4.23 Lint aller fuenf betroffenen PHP-Dateien bestanden;
59 Product-API-Tests, 37 Browser-Sicherheits-/Shared-Data-Pruefungen und
15 Katalogpruefungen bestanden. 242 Node-Tests inkl. neun neuer
Dezimal-/Mapping-/UI-/Katalogvertragsfaelle bestanden; Shell-Mockintegration
bestanden (separater lokaler PHP-Klickstatistiktest mangels `php` im PATH
uebersprungen, kein Lint/Kopieren des Produktionshostings). Nach `npm ci`
Lint/TypeScript sauber wiederholt, Audit 0 Befunde, Testexport mit 25 Seiten
bestanden. Erstes parallel zur Paketinstallation gestartetes Lint wurde
dadurch unterbrochen; nach Installation erfolgreich wiederholt, kein Codefehler.

Testbereitstellung: ausschliesslich drei private Browser-/Katalogprogramme,
`artikelverwaltung/` und notwendige neue hashbenannte Websiteassets; 13
geaenderte/neue Dateien. Keine Produktprojektion oder Produktbilder deployt.
Private Ruecksicherung/Journal unter
`/home/www/carmaja-private-test/product-calculator-release-20261029-5c91f0`.
Aktive Quelldaten und beide Testkonfigurationen/Providerkonfigurationen,
V5-Benutzer/Geraete, Bestands-/Audit-/Initialisierungszeilen und alle anderen
Websiteinhalte vor/nachher unveraendert verglichen; Test-DB nur lesend ueber
TLS. Serverseitiger echter HTTPS-Katalogabruf: 18 aktive Eintraege.
Unauthentifizierter HTTP-Katalogabruf: 401, `no-store`, keine Preiswerte.
Neuer Browser-Tab zeigt die Anmeldeseite; angemeldete Sichtpruefung noch offen.

Der separat freigegebene App-Testpasswortreset ist technisch bestaetigt:
Passwort geaendert, Benutzerdatenvertrag/Bestandsdaten erhalten, Datei 0600;
keine Geheimwerte im Bericht. Serverseitiger Resethelper/Receipt und lokale
Resetprogramme vollstaendig entfernt. Die aktuelle Deployment-Vergleichsbasis
ist nach diesem Reset aufgenommen. Bestehende Geraetetokens nicht veraendert.
Neues Paket und temporaerer Installer entfernt; private Rollbacknachweise
bleiben erhalten. GitHub-Schalter lesend `CARMAJA_TEST_DEPLOY_ENABLED=false`.
Kein Commit, Push, Merge, Workflowlauf oder Produktionszugriff.
Urteil: Erweiterung lokal bestanden und testseitig bereit; angemeldete
Browser-Endabnahme ausstehend, keine neue Warenkorb-Gesamtabnahme.

### 3.79 Angemeldeter PC-Kalkulationsnachweis (29.10.2026)

Nach ausdruecklicher Anmeldebestaetigung der Betreiberin im echten
Chrome-Testtab geprueft; keine Anmeldung automatisiert oder ausgelesen:

- Authentifizierter Abruf der 18 aktiven Perlen-/Spacerpreise sowie Suche
  nach Rosenquarz erfolgreich. Aktualisierung erhaelt die bereits
  eingegebenen Mengen und Kosten.
- Kuenstliche Rechnung: 20 Rosenquarz und 2 Edelstahlspacer ergeben
  3,26 EUR Material, 30 Minuten bei 20 EUR/Stunde ergeben 10,00 EUR Arbeit.
  Mit 1,50 EUR sonstigen Kosten: 14,76 EUR Gesamtkosten, 50 Prozent
  Aufschlag = 7,38 EUR, rechnerisch 22,14 EUR, Empfehlung 22,50 EUR.
- Leere Rechnung wird nicht uebernommen. Menge -1, 1.5 und 10000 sowie
  negative Arbeitszeit abgewiesen; letzte gueltige Werte bleiben erhalten.
- Als neuen Artikel uebernommen: Materialien Rosenquarz, Metallelemente
  Edelstahlspacer, Preis 22,50 EUR, Version 0/ungespeichert, Verkaufsfreigabe
  aus, Veroeffentlichen deaktiviert. Kein Speichern, Upload, Publish oder
  Bestandsbefehl ausgeloest.
- Bestehende Artikel ausschliesslich gelesen: HANDY-TEST Version 7, 28 EUR,
  Masse 18 cm/8 mm, Bestand 3/0/3 und beide Bilder geladen. HANDY-TEST
  BILD3200 Version 7, 20 EUR, Verkaufsfreigabe aus, vorhandener Bestand
  5/0/5 unveraendert; beide Originale mit 3200x1800 bzw. 1800x3200 geladen.
  Diese Bestandswerte sind Beobachtungen, keine Initialisierung/Korrektur.

Abgrenzung: Der native Bestaetigungsdialog wurde geoeffnet und geschlossen;
danach hing die Browserautomatik dieses Tabs. Nachverfolgung des
Abbruchergebnisses dort nicht als bestandenen Live-Test behauptet. Mit
frischem Tab autonom fortgesetzt; erneuter Katalog-/Bildabruf ohne
Browserkonsolenfehler. Zwei zusaetzliche ausfuehrende Tests des echten
ProductManager-Callbacks belegen: Abbruch erhaelt saemtliche Felder und
loest keinen Request aus; Bestaetigung veraendert nur Perlen/Spacer/Preis
im lokalen Editor, behaelt uebrige Werte und sperrt Veroeffentlichen bis
zum Speichern. Keine neue Fachlogik und kein erneutes Deployment.

25 gezielte Browser-/Kalkulationstests und insgesamt 244 Node-Tests bestanden;
Website-Lint bestanden. Bisheriger PHP-/TypeScript-/Testbuildnachweis bleibt
unveraendert gueltig, da ausschliesslich Tests und Dokumentation ergaenzt.
Erneute Shell-Mockregression vollstaendig bestanden; der separate lokale
PHP-Klickstatistiktest bleibt mangels `php` im PATH uebersprungen. Keine
Ausfuehrung oder Aenderung produktiver Hostingdateien.

Eigene ungespeicherte Testeingaben mit den beiden Testtabs verworfen; kein
Testprodukt serverseitig angelegt, folglich keine Produktbereinigung noetig.
Ein sauberer angemeldeter Artikelverwaltungstab bleibt fuer die Betreiberin
offen. Erneuter privater Vergleich gegen die Releasebasis bestaetigt
unveraenderte Produkte, Bilder, Bestands-/Auditzeilen, Zugangsdaten und
private Konfigurationen; nur technische Browsersitzungsdaten wurden benutzt.
Keine temporaeren Testdateien auf dem Server zurueckgelassen. Deployschalter lesend weiterhin
`CARMAJA_TEST_DEPLOY_ENABLED=false`. Kein Commit, Push, Merge,
Produktionszugriff oder Produktionsaenderung.

Urteil: abgegrenzter angemeldeter Lese-/Kalkulationsnachweis bestanden.
Dies ist keine schreibende Browser-/App-End-to-End-Abnahme und keine neue
Warenkorb-Gesamt- oder Produktionsfreigabe.

### 3.80 Abschluss der PC-Kalkulationsabnahme (29.10.2026)

Der noch offene native Abbruchdialog aus 3.79 wurde durch die Betreiberin
manuell auf der Testwebsite geprueft und im Chat bestaetigt:
"Abbruch geprueft - Eingaben unveraendert." Grundlage war der angeleitete
Versuch an einem ungespeicherten neuen Artikel: Kalkulation uebernehmen,
im Bestaetigungsdialog abbrechen, vorhandene Artikelfelder beibehalten.
Dies ist ein manueller Live-Nachweis, keine nachtraeglich behauptete
automatisierte Browserpruefung. Ein gesonderter Nachweis des sichtbaren
Rueckmeldungstexts wurde damit nicht behauptet.

Abschliessend erneut lokal bestanden, ohne Aenderung der Anwendungslogik:

- 244 Node-Tests, darunter alle 25 Browser-/Kalkulationstests. Der echte
  ProductManager-Callback erhaelt bei Abbruch saemtliche Editorfelder und
  erzeugt keinen Request; bei Bestaetigung bleiben nur die vorgesehenen
  Artikelfelder lokal geaendert, ohne automatisches Speichern.
- PHP 8.4.23 mit ausschliesslich prozesslokalen Erweiterungen: Syntax von
  fuenf betroffenen PHP-Dateien, 59 Product-API-Tests, 37 Browser-Sicherheits-/
  Shared-Data-Pruefungen und 15 Preiskatalogpruefungen bestanden.
- Website-Lint und Testbuild einschliesslich TypeScript, 25 statischen
  Seiten, Vorschaubildern und Testexport-Verifikation bestanden. Der Build
  verwendet kuenstliche Fixtures, kein Deployment und keine Produktionsdaten.
- `git diff --check` bestanden. Keine App-, Abhaengigkeits-, API- oder
  Workflowaenderung; nur diese Abnahmedokumentation und der Produktionsplan
  werden fortgeschrieben. Bestehende uncommittete Aenderungen bleiben erhalten.

Der bestehende private Releasevergleich wurde nach der manuellen
Bestaetigung erneut erfolgreich ausgefuehrt: bereitgestelltes Artefakt und
geschuetzte Produkt-/Bilddateien, Bestands-/Auditdaten, Zugangsdaten sowie
private Konfigurationen gegen die Vergleichsbasis unveraendert. Lediglich
der private technische Pruefnachweis wurde aktualisiert. Keine neuen
Testprodukte, Testtabellen, Endpunkte, Zugangskonfigurationen oder Cronjobs
angelegt; kein Daten-Cleanup erforderlich. Lokale PHP-Fixtures werden durch
die Tests bereinigt, erzeugte Testbuilddateien bleiben ausschliesslich lokal.
Der vorhandene angemeldete Browserzustand der Betreiberin wird nicht
veraendert. Deployschalter lesend `CARMAJA_TEST_DEPLOY_ENABLED=false`.

Urteil: Perlenauswahl und PC-Kalkulation einschliesslich ungespeicherter
Uebernahme und manuell bestaetigtem Live-Abbruch testseitig abgenommen.
Der Abbruchdialog-Blocker ist geschlossen. Diese abgegrenzte Abnahme ersetzt
keinen noch ausstehenden schreibenden Browser-/App-End-to-End-Nachweis der
gesamten Artikelverwaltung und erteilt keine neue Warenkorb-Gesamt-, Release-
oder Produktionsfreigabe. Kein Commit, Push, Merge, Deployment,
Produktionszugriff oder Cutover.

### 3.81 Schreibender PC-Praxisnachweis mit kuenstlichem Artikel (29. Oktober 2026)

Freigegeben ist ausschliesslich die Testumgebung. Der neue kuenstliche Artikel
`HANDY-TEST PC-ABNAHME 20261029-7e42c8` wurde ueber die angemeldete PC-Oberflaeche
angelegt; die beiden schon vorhandenen Handy-Testartikel wurden nicht bearbeitet.
Ein privater Vorhervergleich schuetzt bestehende Produkt-/Bilddateien,
Bestands-/Initialisierungs-/Auditdaten und Runtime-/Zugangskonfigurationen.

Praktisch bestanden:

- Zwei kuenstliche JPEGs im Browser ausgewaehlt: 4000 x 2500 wurde auf
  3200 x 2000 reduziert, 1800 x 3200 unveraendert uebertragen. Beide Bilder
  anschliessend auf dem Server mit den erwarteten Abmessungen vorhanden.
- Abgelaufene Browsersitzung lehnt Speichern ab, ohne einen Artikel anzulegen.
  Erneute Anmeldung erhaelt ungespeicherte Felder und vorbereitete Bilder;
  danach erfolgreiches Speichern. Keine Zugangswerte ausgelesen oder protokolliert.
- Erstes Speichern: 31,25 EUR, 18,5 cm, 8 mm, formatierte Beschreibung,
  Materialien, Pflegehinweis, zwei Bilder, automatisch genau zwei Hinweise,
  Verkaufsfreigabe aus. Serverseitige Produktversion 1 und kanonischer Hash
  gueltig; noch keine oeffentliche oder Commerce-Projektion.
- Serverstand erneut geladen: Daten, Bilder und Formatierung erhalten.
  Danach ausschliesslich den kuenstlichen Artikel auf 32,50 EUR / 19 cm
  geaendert und das Hochformat zum Hauptbild verschoben. Speichern ergibt
  Produktversion 2; beide Bilder bleiben vollstaendig erhalten.
- Veroeffentlichen und erneutes Veroeffentlichen: Versionen 3 und 4,
  gleiche Test-SKU, konsistente private Public-Projektion Version 4 ohne
  stock/vintedUrl sowie passende Commerce-Testprojektion. Verkaufsfreigabe
  bleibt false; kein Anfangsbestand, keine Verkaufsabbuchung. Anzeige 0 / 0 / 0.
- Vollstaendiges Neuladen der Website und erneutes Auswaehlen des Artikels:
  Version 4, 32,50 EUR, 19 cm, 8 mm, beide geladenen Bilder in neuer Reihenfolge,
  Formatierung und Hinweise erhalten. Kein Testwebsite- oder GitHub-Publish.
- App-V5- und Browser-Produktdienst liefern fuer den gespeicherten Datensatz
  identische Vertragsfelder. Das ist ein serverseitiger Dienstvergleich,
  ausdruecklich noch kein bestaetigter Handy-Roundtrip.
- Wiederholte private Vergleiche bestaetigen unveraenderte bestehende
  Testartikel, Bilder, Bestandszeilen und private Konfigurationen.

Erneute lokale Regression: 25 Node-Browser-/Kalkulationstests sowie PHP 8.4.23
mit 59 Product-API-Tests und 37 Browser-Sicherheits-/Shared-Data-Pruefungen
bestanden. Keine Funktionskorrektur erforderlich; die umfassendere Regression
aus 3.80 wird dadurch nicht als erneut durchgefuehrt ausgegeben.

Offen: manuelle Handy-Sichtpruefung des am PC angelegten Artikels gemaess dem
vereinbarten Geraetetestmodus. Der nicht kaufbare kuenstliche Artikel und sein
privater Vergleichs-/Cleanupnachweis bleiben dafuer voruebergehend erhalten.
Cleanup ist noch nicht abgeschlossen und erfolgt erst nach dieser Pruefung,
ausschliesslich fuer eigene Testdaten. Keine vorhandenen Testprodukte,
Zugangsdaten oder privaten Konfigurationen entfernen. Keine neue Endabnahme,
kein Commit, Push, Deployment, Produktionszugriff oder Cutover.

### 3.82 Manueller Handy-Abgleich, Cleanup und PC-Praxisabnahme (29. Oktober 2026)

Die Betreiberin bestaetigt: "PC-Testartikel am Handy vollstaendig sichtbar."
Dies schliesst die in 3.81 angeforderte manuelle Sichtpruefung des am PC
gespeicherten/veroeffentlichten Artikels mit 32,50 EUR, 19 cm, 8 mm, beiden
Bildern und Hinweisen sowie Verkaufsfreigabe aus. Kein automatisierter
Handytest und kein zusaetzlicher schreibender Handy-Roundtrip behauptet.
Der offene Vermerk am Ende von 3.81 ist damit historisch abgeschlossen.

Cleanup auf ausdruecklichen Auftrag und mit schreibfreiem Vorflug bestanden:

- Den genau benannten kuenstlichen Testartikel mit Version 4 und weiterhin
  Verkaufsfreigabe false serverseitig geprueft; kein Bestand, kein Checkout,
  keine Bestellung, keine Reservierungsnutzung und keine Bestandskorrektur.
- Ausschliesslich seine Produkt-/Commerce-Projektionsdaten und zehn eigene
  Produkt-, Bild-, Idempotenz-/Vorgangs- und Lockdateien entfernt. Keine
  Tabellen, bestehenden Produkte oder bestehenden Bestandseintraege geloescht.
- Eigene Eintraege in privater Public-Projektion, SKU-Zaehler und Testaudit
  eng begrenzt bereinigt. Fremde Aenderungen waeren vor dem Schreiben ein
  Abbruchgrund gewesen; vorhandene Daten wurden nicht pauschal ueberschrieben.
- Anschliessend kein eigener Testartikel mehr vorhanden. Bestehende
  Produkt-/Bilddateien, Bestands-/Auditzeilen und private Konfigurationen
  gegen den Vorherstand unveraendert; unabhaengiger privater Releasevergleich
  ebenfalls erfolgreich. Bestehende Browser-/App-Zugaenge bleiben erhalten.
- Eigenen privaten Prueferordner samt Vorher-/Statusdateien und lokale
  Hilfsskripte, kuenstliche Ausgangsbilder und temporaere Screenshots entfernt;
  Abwesenheit geprueft. Keine neuen Endpunkte oder Cronjobs zurueckgelassen.
  Dauerhafter Testbetrieb und dessen private Konfigurationen bleiben bestehen.

Abschlussregression erneut bestanden: 244 Node-Tests einschliesslich frischem
Testexport, PHP 8.4.23 mit 59 Product-API-, 37 Browser-Sicherheits-/Shared-Data-
und 15 Katalogpruefungen. Website-Lint und `git diff --check` bestanden.
Keine Anwendungscodekorrektur erforderlich; nur dieser Bericht und der
Produktionsplan fortgeschrieben. Kein erneuter Android-Build behauptet.

Urteil: PC-Artikelverwaltung im geprueften Testumfang aus 3.77 bis 3.82
einschliesslich Kalkulation, Speichern, Bildern, Veroeffentlichen, erneuter
Anzeige und manuell bestaetigtem PC-zu-App-Abgleich testseitig abgenommen;
eigener Cleanup abgeschlossen. Keine darueber hinausgehende Warenkorb-,
Release- oder Produktionsfreigabe. Kein Commit, Push, Merge oder Deployment.
`main`, `website/hosting/**` und Produktion unveraendert. Testdeployschalter
abschliessend lesend bestaetigt: `CARMAJA_TEST_DEPLOY_ENABLED=false`.

### 3.83 Gezielte minimatch-Sicherheitsbereinigung fuer PR #92 (3. November 2026)

Die 16 moderaten npm-Befunde des gesicherten Stands c9f3c43 gehen auf
[GHSA-g3hv-75wg-m249](https://github.com/advisories/GHSA-g3hv-75wg-m249)
in den Entwicklungs-/Lintabhaengigkeiten zurueck. Beide alten minimatch-Baeume
werden durch die gepinnte Upstream-Engine 11.0.4 ersetzt. Die sechs noch auf
callable v3 angewiesenen Lintverbraucher erhalten eine kleine, lokale und rein
entwicklungsseitige Kompatibilitaetsfassade; typescript-estree nutzt v11 direkt.
Die Fassade enthaelt keinen alten Matcher und erzeugt keine RegExp. Ihr eng
begrenzter makeRe/test-Vertrag und die spaetere Entfernung sind dokumentiert.
Keine Warnungsunterdrueckung, kein force-Downgrade, keine Aenderung an Next,
React, sharp, App, Shop-Fachlogik oder Laufzeitpaketen.

Lokale Nachweise: 250 Node-Tests (einschliesslich sechs neuer Engine-/Consumer-,
React-/A11y-/Importregel-, Ignore- und Amplifikationsregressionen), Shell-Mockreihen,
Website-Lint, TypeScript und Testexport mit 25 Seiten bestanden. Die Fassade
ist nicht im oeffentlichen JS-/HTML-Export enthalten. PHP 8.4.23: 23 Runtime-,
59 Produkt-API-, 37 Browser-, 15 Katalog-, 13 Bootstrap-, 20 Admin- und
57 lokale E2E-Vertragspruefungen bestanden. Optionaler Shell-PHP-Klickstatistik-
Test mangels PHP im Bash-PATH uebersprungen; kein Hosting-PHP veraendert/geprueft.
`npm audit` und `npm audit --omit=dev` jeweils 0 Befunde. Der Website-CI-Build
erhaelt einen zusaetzlichen `npm audit --audit-level=moderate`-Schritt; Trigger,
Deploymentbedingungen und Freigabeschalter bleiben unveraendert.

Commit/Push ausschliesslich auf `codex/shop-inventory-cart` fuer PR #92
freigegeben; der genaue neue CI-Nachweis wird nach dem Push im PR festgehalten.
Keine neue Gesamt-/Produktionsfreigabe. Kein Merge, Deployment, Server- oder
Providerzugriff. Testprodukte, Bestaende und private Konfigurationen unveraendert.

## 4. Noch offene Pflichtnachweise

Aktueller lokaler Nachtrag 10. November 2026: Der PR-92-main-Stand ist Basis
des isolierten `codex/cart-production-package`. Backupkorrekturen, V5-/Shop-v3-
Paketvertrag, gesperrter Mengenimport, separate Checkout-Sperre und textgleiche
technische approved-Ueberfuehrung von Legal v7 sind lokal umgesetzt und geprueft.
254 Node-Tests, 36 PHP-Suiten, PHP-Lint, Shell-Mocks, Website-Lint/TypeScript,
Testexport und kuenstlicher Produktionsbuild bestanden; npm-Audit 0 Befunde.
Details und ausdrueckliche Nachweisgrenzen stehen im
[lokalen Produktionspaket](cart-production-package.md).
Die unten historisch noch offene lokale Bundle-/Backupintegration ist damit
geschlossen, nicht jedoch deren Bereitstellung oder Produktionsabnahme.
Kein Serverzugriff, keine Aenderung bestehender Testprodukte/Konfigurationen,
kein Commit, Push, main-Merge oder Deployment. Praktischer Test der neuen
Paket-/Mengenimportkombination, saubere SHA-/CI-Bindung, aktuelle Produktions-
und Restorepruefung, reale Zaehlliste und gesonderte Aktivierung bleiben offen.

1. Historische Einzelbestellungen sind kuenstlich und praktisch verlustfrei migriert (3.17). Die abgegrenzten Funktions-/Parallelitaets-/Backup- und Crash-/Timeoutreihen sind bestanden; weitere Pflichtfaelle sind damit nicht pauschal freigegeben.
2. Echte Stripe-Testzahlungen einschliesslich Browser-SEPA bis Bestellung/Mail sind abgegrenzt nachgewiesen (3.24). Echter asynchroner SEPA-Erfolg und echter SEPA-Fehler nach dem Checkoutzeitpunkt mit jeweils beobachtetem processing nach diesem Zeitpunkt bestanden (3.31/3.36). Der fruehere Fehlerfall 3.7 ersetzt diesen Zeitgrenznachweis nicht. Bereits bestandene Doppel-/Reihenfolge-, Timeout- und Wiederanlaufnachweise bleiben erhalten, keine pauschale Gesamtfreigabe.
3. Originale Browser-Kartenzahlung-bis-Bestellmail-Kette einschliesslich Legal-Snapshot, Providerzustellung, Worker-Wiederanlauf und HTTP-Webhook-Replay bestanden (3.15). Cron-Blocker durch Nutzerentscheidung geschlossen (3.19), ohne nachtraeglichen technischen Erfolgsnachweis. Lock und Lease sind separat praktisch bestanden (3.16). Die abgegrenzten realen Mail-, bestaetigten Empfangs- und injizierten Fehlernachweise aus 3.10 sind bestanden.
4. Mehrtab-CSRF, Warenkorbkonflikt-Sperre, HTTP-CSRF-/Sessionablauf, Rate Limits, API-Ausfall sowie Teilwiderruf/Admin-HTTP bestanden (3.16). Quote-/Checkouttokenablauf und Browser-Abbruch/Ablauf sind bestanden (3.17). Dynamischer v3-Browser-SEPA-status processing -> bestaetigt live bestanden (3.24). Integrierte Teilwiderrufs- und angemeldete Admin-UI-Flows einschliesslich Mengenpflege und Wiedereinlagerung bestanden (3.65/3.66), eigener Cleanup abgeschlossen. Rueckkehrstatus, Website-/Admin-Korrekturen und Sicherheitsupdates sind gemeinsam als geschuetzter Testwebsite-Preview bereitgestellt und geprueft (3.68), aber noch nicht dauerhaft in den Testwebsite-Zweig uebernommen. Neue Admin-Fehlerrueckmeldung live negativ geprueft; Bestandsaktualisierung nach Rueckgabe lokal ausfuehrend getestet (3.66). Keine pauschale Gesamtfreigabe.
5. Signierte Beta lokal bestanden (3.12), APK-Vertrag erneut bestaetigt (3.37); manuelle lokale Handypruefung von Version 1.4.0-beta.1 bestanden (3.38). Weitere Handypruefungen einschliesslich App/API/Publisher-Roundtrip und Bestandspflege erfolgen gemaess 3.39 ausschliesslich manuell mit getrennten serverseitigen Kontrollen. Keine USB-/ADB-/Instrumentierungsvoraussetzung; isolierte V5-Test-API bereit (3.40), manuelle Handyanmeldung serverseitig bestaetigt (3.41). Manuelles V5-Speichern/Synchronisieren mit erneutem Oeffnen und kanonischer Serverpruefung bestanden (3.42); Anfangsmenge 3 vor Veroeffentlichung vorgemerkt (3.43), manuelle Testveroeffentlichung mit konsistenter privater V4-Projektion und einmaliger Initialisierung 0 -> 3 bestanden (3.44). Handy-Bestandsanzeige bestanden (3.51), erneute manuelle Synchronisierung ohne Bestandsmutation und ohne automatische Veroeffentlichung bestanden (3.52). Manuelle Erhoehung und Rueckkorrektur 3 -> 4 -> 3 mit vollstaendiger Auditfolge bestanden (3.53/3.54). Erneutes Publizieren ohne Anfangsbestandswiederholung mit sichtbarer Bestaetigung in Beta.3 bestanden (3.57). Lokale Sichtpruefungen ersetzen die dynamischen Flows aus Punkt 4 nicht.
6. Alle sechs abgeschlossenen Zeitgrenzfallversuche vom 14. Oktober vollstaendig bereinigt (3.27/3.29/3.31/3.33/3.35/3.36); kein Zeitgrenzfall-Testlauf mehr offen. Ebenso Browser-SEPA-Pruefreihe (3.24), fruehere Browser-/HTTP-/Cron-Pruefreihe (3.18) und erste Zahlungs-/Mail-Pruefreihe (3.11) bereinigt; reale Testprodukte unveraendert. Weitere Nachweise verwenden die nach 3.20/3.25 dauerhaft privat erhaltenen Datenbank-/Stripe-Testkonfigurationen und benoetigen weiterhin einen eigenen vollstaendigen Artefakt-/Testdaten-Cleanup.
7. Externe Rechts-, Datenschutz- und Versandfreigabe der aktuellen Prueffassung v7 samt Inhalts-Hash durch die Betreiberin bestaetigt und dokumentiert (3.73, `shop-cart-legal-review.md`). Praktische Packkapazitaet und Gesamtbestellgrenze von 10 Stueck bestaetigt, lokal und isoliert gegen MySQL 8 bestanden (3.69/3.70/3.72). Websitehinweis und Testfassung v4 als geschuetzter Preview bereit (3.71), inzwischen im erhaltenen Testbetrieb verifiziert (3.75). Offen bleiben die kontrollierte technische Ueberfuehrung in eine produktive `approved`-Fassung mit nachgewiesener Textgleichheit/neuem Bundle-Hash sowie die Release-/CI-Integration; kein stilles Umschreiben der geprueften Fassung. Root-Shop-Runtime und echter Workercron ausschliesslich in der Testumgebung dauerhaft angebunden (3.75). Keine Produktions-/Cutoverfreigabe.
8. Bild-Testbeta 1.4.0-beta.2 / Code 11 ist signiert bereit (3.49); die
   nachfolgende Rueckmeldungs-Beta 1.4.0-beta.3 / Code 12 ist installiert (3.56).
   Der manuelle Upload zweier 3200-Pixel-Originale ist serverseitig bestanden
   (3.58). Erneutes Oeffnen/Anzeigen am Handy ebenfalls bestanden (3.59).
   Lokale 3200-Pixel-Browser-Grossansicht mit kuenstlichen Bildern bestanden
   (3.60); der echte Bildtest ist inzwischen veroeffentlicht und lesend
   geprueft (3.61) und inzwischen mit beiden Originalen auf der Testwebsite
   einschliesslich Browser-Grossansicht bestanden (3.64). Die lokale
   Regression aus 3.50 schliesst weder diese Geraetepruefung noch die
   angemeldeten UI-Flows oder die erneute Rechtsfreigabe.
9. Verkaufsfreigabe-Vertrag in signierter Beta.4 und isolierter Test-API
   bereitgestellt (3.62); Installation, manueller Freigabe-aus-Roundtrip und
   lesender Serverabgleich bestanden (3.64). Bildtest ohne Bestand bleibt
   nicht kaufbar. Keine pauschale Warenkorb-Gesamtabnahme.
10. Die sechs Dependency-Befunde aus 3.66 sind durch die gezielten Updates
    und vollstaendige lokale Regression in 3.67 geschlossen: aktueller
    npm-Audit mit und ohne Entwicklungsabhaengigkeiten jeweils 0 Befunde.
    Dies ersetzt weder kuenftige Audits noch die Gesamt-/Produktionsfreigabe.

## 5. Produktionssperren und Wiederanlauf

Vor jeder späteren Aktivierung separat freigeben: vorhandene Backupkorrekturen
integrieren, frisches verschlüsseltes Backup/Restore, neue Checkouts sperren,
alle alten offenen Zahlungen bis zum bestätigten Endzustand abarbeiten,
DDL-Migration/journalgebunden prüfen, tatsächliche Mengen zählen und bestätigen,
API/App/Publisher/Website konsistent bereitstellen, erst anschließend Kaufweg aktivieren.
MySQL-DDL ist nicht atomar: bei abgebrochener Migration Kaufweg gesperrt lassen,
Schema/Journaling prüfen und kontrollierten Wiederherstellungsweg verwenden,
nicht blind dieselbe teilweise angewendete DDL wiederholen.

Fehlerbetrieb: neue Checkouts schließen, laufende Zahlungen/Bestellungen sicher
weiterverarbeiten. Nach dem ersten bestandswirksamen Warenkorbcheckout kein
Rückwechsel zum unbegrenzten Kollektionenverkauf. Kein Datenrollback durch einen Website-Rollback.

Referenzen: [Stripe Checkout/line_items](https://docs.stripe.com/api/checkout/sessions/create),
[Stripe Refunds](https://docs.stripe.com/refunds).
