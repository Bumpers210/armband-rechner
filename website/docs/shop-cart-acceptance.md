# Mengenbestand und Warenkorb – Entwicklungs- und Abnahmestand

Stand: 9. Oktober 2026. **In Umsetzung, nicht vollständig abgenommen.**

Isolierter Worktree: `G:\BS-Stein-Hart-shop-cart`, Branch
`codex/shop-inventory-cart`, Basis `68a308a3cbbd9d6ed0d496d115b2582ac7419ef5`.
Keine Änderungen an Produktion, vorhandenen Bestellungen oder anderen Worktrees.
Bis zum 8. Oktober keine Commits, Pushes oder Deployments. Am 9. Oktober ist
ein Entwicklungscheckpoint mit Push ausschliesslich dieses Branches und ein
manueller GitHub-Beta-Build beauftragt. Keine Merge-/Deploymentfreigabe und
keine Freigabe des Warenkorb-Kaufwegs.

## 1. Implementierter Zielvertrag

- Produktmodell 4, Produkt-API `/v5`, öffentliche Projektion 4, Shop `/shop/v3`.
- Produktion-App 1.4.0 / Code 8; Beta 1.4.0-beta.1 / Code 10. Pakete und Zertifikatsvertrag unverändert.
- `inventoryCartEnabled=false` und `productApiV5WritesEnabled=false` als Standard; keine automatische Aktivierung oder Migration.
- Mengenbestand, getrennte authentifizierte `targetOnHand`-Vorgänge, Inventarversion, Idempotenz und Audit. Keine Bestandsmutation beim Produktspeichern.
- Gründe `initial_count`, `receive_stock`, `mark_unsellable`, `release_return`, `correction`; `shop_sale` ausschließlich bei erfolgreicher Zahlungsfinalisierung.
- Ungezählte Bestände bleiben nicht kaufbar. Vormerkungen für neue Anfangsbestände werden bei der Commerce-Projektion genau einmal angewendet.
- Serverwarenkorb: maximal 100 unterschiedliche Positionen, Zusammenführung gleicher Produkte, Versionierung, keine Reservierung vor Checkout.
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

## 4. Noch offene Pflichtnachweise

1. Historische Einzelbestellungen als kuenstliche Vor-Migrationsdaten explizit praktisch pruefen. Die abgegrenzten Funktions-/Parallelitaets-/Backup- und Crash-/Timeoutreihen sind bestanden; weitere Pflichtfaelle sind damit nicht pauschal freigegeben.
2. Echte Stripe-Testzahlungen, asynchrone SEPA-Endzustände nach Ablauf, doppelte/ungeordnete Webhooks, Provider-Timeout und Wiederanlauf.
3. Originale Zahlung-bis-Bestellmail-Kette mit gueltigem Test-Legal-Snapshot/Archiv sowie Worker-/Lease-/Cron-E2E. Die abgegrenzten realen Mail-, bestaetigten Empfangs- und injizierten Fehlernachweise aus 3.10 sind bestanden.
4. HTTP-/Browser-E2E: mehrere Tabs, Replay, Token-/Sessionablauf, CORS, Rate Limits, API-Ausfall, Admin und Teilwiderruf.
5. Signierte Beta lokal bestanden (3.12); Installation und Geräte-Roundtrip gegen eine passend isolierte V5-Test-API bleiben offen.
6. Weitere Testlaeufe ebenfalls vollstaendig bereinigen; erste Zahlungs-/Mail-Pruefreihe gemaess 3.11 bereinigt, reale Testprodukte unveraendert.
7. Rechtstexte/Checkoutinformationen erneut freigeben; Entwürfe sind nicht approved.

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
