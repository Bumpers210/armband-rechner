# Produktversionen und Quellhashes: Korrektur und Bereinigungsplan

Stand: 11. November 2026. Lokale Basis: main `f2ca27f18350914a1e63631739071dbf6074450d`
(PR #94), isolierter Branch `codex/cart-product-consistency`.
Historischer Ausgang: lokale Korrektur. Aktueller Ausfuehrungsstand siehe den
folgenden Nachweis; die urspruenglichen Freigabegrenzen weiter unten beschreiben
den damaligen Planungsstand.

## Lokaler oeffentlicher Abgleich am 11. November 2026

Nach gesonderter Freigabe ist der enge Abgleich der bereits vorhandenen
oeffentlichen Produkte lokal auf `codex/cart-product-cleanup` uebernommen.
Erneut gepruefte main-Basis: `6ca2fd17615061ed76b832646640cec8eebf1935`.
Der erneute Serververgleich hat keine Datei oder Datenbank veraendert und keine
Runtime oder Secrets geladen. Der Wartungsmarker blieb aktiv. Die folgende
Tabelle vergleicht die unveraendert ausgelieferte Website mit der lokalen
Produktdatei; sie ist keine Deploymentbestaetigung.

| Bereits oeffentliches Produkt | Ausgelieferte Version | Lokale und bereinigte Quellversion | Lokale Aenderung |
| --- | --- | --- | --- |
| CP-2026-0002 | 4 | 6 | nur Produktversion, Quellhash und Zeitstempel |
| CP-2026-0003 | 4 | 4 | unveraendert |
| CP-2026-0004 | 2 | 3 | nur Produktversion, Quellhash und Zeitstempel |
| CP-2026-0005 | 4 | 5 | nur Produktversion, Quellhash und Zeitstempel |
| CP-2026-0006 | 2 | 3 | nur Produktversion, Quellhash und Zeitstempel |
| CP-2026-0007 | 2 | 3 | nur Produktversion, Quellhash und Zeitstempel |

Der Kandidat behaelt genau sechs Eintraege, ihre Reihenfolge, Texte, Preise,
Verkaufsflags, Slugs, Bildmetadaten und Modell-/Projektionsversion 3 bei.
Alle 16 Bilder stimmen zwischen privater Quelle, ausgelieferter Website und
Git-Referenz bytegleich ueberein. Die strikte Website-Allowlist einschliesslich
Bildformat und Abmessungen akzeptiert den Kandidaten. **CP-2026-0008 und -0009
werden nicht hinzugefuegt.** Ihr Status in der Produktverwaltung allein ersetzt
keine Freigabe zur neuen oeffentlichen Aufnahme. CP-2026-0001 bleibt archiviert;
weitere Entwuerfe und der Legacy-Datensatz bleiben ausgeschlossen.

`website/scripts/prepare-product-projection-alignment.php` ist eine reine
Vorbereitungsfunktion ohne Schreib-, Datenbank-, Netzwerk- oder Publishpfad.
Sie akzeptiert nur Versions-/Hash-/Zeitstempelabweichungen innerhalb der
bestehenden oeffentlichen Menge. Fachliche Abweichungen, unbekannte Felder,
doppelte Identitaeten, veraltete Versionen und ungueltige gespeicherte
Quellhashes fuehren zum Stop. Ihr Ergebnis enthaelt ausdruecklich keine
Veroeffentlichungs-, Deployment- oder Entsperrfreigabe.
Die 20 kuenstlichen Tests in
`website/tests/php/product-projection-alignment-test.php` sind bestanden;
auch 59 Produkt-API-, 13 Bootstrap- und 48 Reparaturtests wurden wiederholt.
Der historische Wartungs-Hotfix reproduziert zuerst den alten Lebenszyklusfehler
mit kuenstlichen Daten. Mit Korrektur bestehen 55 historische Produkt-API-,
39 Sperr-, 86 Aktivierungs-/Abbruch-/Manipulations- und 13 Bootstrappruefungen.
Der PHP-Testtreiber laedt iconv und PDO plattformabhaengig, ohne eine lokale oder
serverseitige PHP-Konfiguration zu veraendern.
PHP-8.4-Lint, `npm ci`, Website-Lint, alle 260 Node-Tests und nachfolgenden
Shell-Mocks sind bestanden. Die vier Paket-/Workflowvertragstests wurden nach
Ergaenzung der historischen CI-Anbindung erneut bestanden. Testbuild,
kuenstlicher Produktionsbuild und lokaler Produktionsbuild mit den sechs
vorhandenen Produkten einschliesslich Exportpruefungen sind bestanden;
`npm audit --audit-level=moderate` meldet null Schwachstellen.
Geheimwert-/Laufzeitdateipruefung und `git diff --check` bestanden. Der optionale
WSL-PHP-Klickstatistik-Untertest bleibt mangels PHP in WSL ausgenommen; der PR
benoetigt einen eigenen vollstaendigen CI-Lauf. App-Code blieb unveraendert.

Nach der erneut bestandenen Vorbereitung wurden genau die drei Metadatenfelder
der fuenf ausgewaehlten Produkte in `website/content/products.json` angepasst.
Das temporaere lokale Validierungsverzeichnis ist entfernt. Websitebilder und
Deploymentkonfiguration blieben unveraendert. Der Build-only-Workflow
`production-cart-package.yml` laedt jetzt die fuer die historischen Fixtures
benoetigten Git-Commits und fuehrt deren Guard-/Aktivierungstests ueber den
vorhandenen isolierten Node-Testtreiber aus. Seine allgemeinen PHP-Tests lassen
nur diese beiden vom Treiber ausgefuehrten, fixtureabhaengigen Dateien aus;
keine Pruefung entfaellt. Es gibt keinen neuen Deploy- oder Serverzugriffsschritt.
Es gab keine erneute Datenbereinigung, keinen neuen Backup-/Restorelauf und
keinen Zugriff auf bestehende Testprodukte oder private Zugangskonfigurationen.

### PR und verbleibende Freigaben

1. Die freigegebene lokale Produktdatei behaelt die bestehende oeffentliche
   Menge und Modellversion bei; keine Modell-4-Konvertierung durch eine
   Exportnormalisierung und keine Aufnahme von CP-2026-0008/-0009.
2. Vorbereitung, Wartungs-/Reparaturwerkzeuge, Tests und Dokumentation sind
   Teil des freigegebenen Commits und PRs auf `codex/cart-product-cleanup`.
   Der PR muss die CI vollstaendig bestehen, bevor ein Merge freigegeben wird.
   Kein automatischer Publishercommit und kein direkter Push nach main.
3. Merge erst nach gesonderter Freigabe. Die gepruefte Workflowkonfiguration
   erfordert fuer automatisches Deployment beide weiterhin falschen Gates
   `publisherEnabled` und `automaticWebsiteDeployment`; ein PR ist Build-only.
   Die tatsaechlichen GitHub-Gates vor jedem freigegebenen Merge erneut lesen.
4. Website-Bereitstellung bleibt ein eigener spaeterer Schritt des abgestimmten
   Warenkorb-Produktionspakets. Ein Produktdatei-Abgleich allein erlaubt weder
   den neuen Shopbuild gegen die alte Runtime noch Publisher, Produktanlage,
   Bestandsimport, Entsperrung, Shopstart oder Cutover.

## Abgeschlossene private Produktbereinigung vom 11. November 2026

Die freigegebene Bereinigung ist im isolierten Branch `codex/cart-product-cleanup`
auf Basis des zusammengefuehrten PR #95 umgesetzt und auf den gebundenen
Produktionsdaten ausgefuehrt. Acht private Produktquellen und ihre vorhandenen
Commerce-Metadaten sind abgeglichen. **CP-2026-0008 verwendet 32,50 EUR und den
aktuellen Text der Produktverwaltung.** CP-2026-0003 blieb vollstaendig unveraendert.
Es gab keine Veroeffentlichung und keine Aenderung der Verkaufsfreigaben.

### Tatsaechlicher Aenderungsumfang

| Produkt | Quellversion vorher | Quelle und Commerce danach | Fachliche Aenderung |
| --- | --- | --- | --- |
| CP-2026-0001 | 3 | 4 | keine; bleibt archiviert und nicht zum Verkauf freigegeben |
| CP-2026-0002 | 5 | 6 | keine; veraltete interne Projektionsbindung berichtigt |
| CP-2026-0003 | 4 | 4 | keine; weder Quelldatei noch Commerce-Zeile geschrieben |
| CP-2026-0004 | 2 | 3 | keine |
| CP-2026-0005 | 4 | 5 | keine |
| CP-2026-0006 | 2 | 3 | keine |
| CP-2026-0007 | 2 | 3 | keine |
| CP-2026-0008 | 5 | 6 | nur bestehende Commerce-Projektion: 29,50 auf 32,50 EUR und aktueller Quelltext; Quelle bereits inhaltlich korrekt |
| CP-2026-0009 | 4 | 5 | keine |

Die acht Quelldateien aendern ausschliesslich serververwaltete Produktversion,
Entwurfsversion, `sourceHash` und `updatedAt`. In Commerce wurden Version, Hash
und Synchronisierungszeit erneuert; nur bei CP-2026-0008 zusaetzlich Preis und
Beschreibung. SKU, Status, Verkaufsfreigabe, Bilder, Masse, sonstige Texte und
Kalkulationsdaten blieben erhalten. Der Legacy-Entwurf wurde nicht migriert.

Der private Adapter verwendet ein vorab versiegeltes Manifest, den bestehenden
Wartungsmarker und sortierte Produktdatei-Locks. Jede Quelldatei wird atomar
ersetzt; alle acht Commerce-Zeilen werden gemeinsam in einer MySQL-Transaktion
mit `SELECT ... FOR UPDATE` aktualisiert. Dateisystem und Datenbank sind keine
gemeinsame Transaktion: Ein privates Journal erlaubt nach Unterbrechung nur den
geprueften Wiederanlauf unter weiterhin aktiver Sperre. Kein Netzwerkaufruf,
Publisher oder Bestandsdienst innerhalb dieser Transaktion.

Neue Operatorwerkzeuge: `website/scripts/product-consistency-repair.php` und
`website/scripts/run-product-consistency-repair.php`; kuenstliche Tests:
`website/tests/php/product-consistency-repair-test.php`. Die Helfer wurden nur
temporaer privat ausgefuehrt, nicht als API-Route oder dauerhafter Dienst eingebaut.

### Pruefungen und Sicherungen

| Pruefung | Ergebnis |
| --- | --- |
| Lokale kuenstliche Reparaturtests | 48 bestanden: Feldgrenzen, Konflikte, Manipulation, Lock, doppelte Ausfuehrung und Abbruch/Wiederanlauf an Datei-/SQL-/Commitgrenzen |
| Praktischer kuenstlicher MySQL-Test im zuvor leeren getrennten Restore-Ziel | 51 bestanden, einschliesslich echtem Row-Lock-Timeout und Wiederanlauf; anschliessend Leerheit und unveraenderte Runtime separat bestaetigt |
| Drei identische schreibfreie Produkt-Dry-Runs | jeweils acht Kandidaten, CP-2026-0003 unveraendert, keine Bestands- oder Veroeffentlichungsaktion |
| PHP-Lint lokal 8.4.23 und IONOS 8.4.27 | neue Helfer und Testdatei bestanden, Transferintegritaet und private Dateirechte geprueft |
| PHP-Regression | 59 Produkt-API-, 13 Bootstrap- und 9 Produktionsbackup-Tests sowie Warenkorb-Backupvertrag bestanden |
| Website-Regression | `npm ci`, `lint:test`, 260 Node-Tests, nachfolgende Shell-Mocks und `build:test` mit Exportpruefung bestanden; Lockdatei unveraendert |
| Unabhaengige lesende Abschlusspruefung | alle 22 Modell-3-Quellhashes gueltig; neun Quelle-/Commerce-Bindungen konsistent; CP-2026-0008 mit 3250 Cent und aktuellem Text |
| Geschuetzter Vorher-/Nachhervergleich, auch nach Restore und Smoke | Bilder, nicht ausgewaehlte Entwuerfe, Bestand, Bestandsaudit, historische Bestellungen/Payments/Snapshots, Inbox/Outbox, Idempotenzbelege, Schema-/Migrationsjournal, private Konfigurationen, Authdaten, Programme und oeffentliche Dateien unveraendert |
| Aktive Sperren nach Bereinigung | Produkt-/Publish- und Shop-v1/v2/v3-Checkoutschreiben jeweils 503 und `no-store`; keine Checkout-Session erzeugt |
| Geheimwertpruefung und `git diff --check` | bestanden; keine Laufzeitdaten oder Geheimwerte ins Repository uebernommen |

Der erste Node-/Shelllauf war durch `Bash/E_ACCESSDENIED` der lokalen Sandbox
blockiert; der vollstaendige Wiederholungslauf mit freigegebenem Bash-Zugang
endete ohne Codeaenderung mit Exit 0. Der unveraenderte optionale
PHP-Klickstatistik-Untertest unter WSL bleibt mangels dortigem PHP ausgenommen.
Keine Android-Aenderung und deshalb kein erneuter Android-Build.

Frische verschluesselte Sicherung vor Apply:
`20261111T140443Z-c0a73a353c1f`; nach erfolgreicher Bereinigung:
`20261111T141800Z-fa4cad65cb4a`. Beide Restore-Dry-Runs bestanden den Schema-,
Inhalts-, Metadaten- und Produktdateivergleich. Die getrennte Restore-Datenbank
ist wieder vollstaendig leer, einschliesslich Routinen und Events; zugehoerige
private Restore-Dateien sind entfernt. Produktionsdaten wurden nicht restauriert.
Beide bestehenden OneDrive-Pull-Laeufe endeten mit Ergebnis 0; je drei Dateien
wurden nach Groesse und SHA-256 geprueft und serverseitig quittiert. Eine neue
Sichtpruefung dieser konkreten Archive im OneDrive-Web ist damit nicht behauptet.

Source und Restore verwenden MySQL 8 mit aktiven TLS-Sitzungen; das bereits
akzeptierte Restrisiko fehlender CA-/Hostidentitaetspruefung bleibt unveraendert.
Die technisch laufende Tabelle `worker_leases` bleibt vom Inhaltsgleichheitsvergleich
ausgenommen; bestehende Zahlungsverarbeitung wurde nicht abgeschaltet.

### Cleanup und verbleibendes Veroeffentlichungsgate

Die fuenf privaten Stagingdateien samt Serverordner und das lokale temporaere
Paket sind entfernt. Dauerhaft erhalten bleiben verschluesselte Backups,
Programm-Rueckfallkopien, Wartungssperre sowie das private Reparaturmanifest,
Journal und Audit unter `carmaja-private-production/audit/`. Diese Nachweise
werden nicht ins Repository kopiert. Bestehende private Konfigurationen und
Testumgebungen blieben unveraendert.

Die aktive oeffentliche GitHub-Projektion mit sechs Produkten und die ausgelieferte
Website sind unveraendert. Ihre alten Versionsbindungen und die fehlenden Produkte
CP-2026-0008/-0009 sind deshalb **noch nicht oeffentlich abgeglichen**. Die interne
Bereinigung ist bestanden, nicht die vollstaendige oeffentliche Produktkette.
Als naechstes ist die gezielte oeffentliche Projektionsangleichung getrennt
vorzubereiten und freizugeben. Schreib-/Publisher-/Checkout-Sperren bleiben aktiv;
keine automatische Entsperrung, kein Website-Deployment, Shopstart, Mengenimport
oder Cutover. Kein neuer Commit, Push oder Merge; Git-main unveraendert.

Die folgenden Abschnitte halten die frueheren Vorfluege und Freigabestaende fest.

## Private Korrektur und Wartungssperren vom 11. November 2026

Die separat freigegebene Versions-/Hashkorrektur ist auf dem bestehenden
Produktionsstand bereitgestellt. Produkt-Schreibzugriffe, Publisher und neue
Checkouts bleiben bis zum Abschluss der abgesicherten Bereinigung gesperrt.
**Bestehende Produktdaten wurden noch nicht repariert oder veroeffentlicht.**
Fuer CP-2026-0008 hat die Betreiberin inzwischen die aktuelle Produktverwaltung
mit 32,50 EUR und aktuellem Text als massgeblich bestaetigt. Daraus folgt keine
Freigabe zur neuen oeffentlichen Veroeffentlichung von CP-2026-0008 oder -0009.

### Eng begrenzte Bereitstellung

Der installierte Modell-3-Stand wurde vorab bekannten Git-Fassungen eindeutig
zugeordnet. Ein reproduzierbarer Kompatibilitaets-Paketbau uebernimmt die
Lebenszykluskorrektur, ohne das neue Warenkorb-/Modell-4-Paket auszurollen:

- `product-api-v4.php`: Produktversion genau einmal je neuer Operation erhoehen,
  kanonischen Hash vor der Speicherung erneuern und Ergebnisversion auditieren.
- `product-api.php`, `product-api-v2.php`, `product-api-v3.php`: Produktdatei-,
  Upload-, Produktlock- und Publisher-Schreibwege vor dem Seiteneffekt sperren.
- `bootstrap.php` und neuer `product-maintenance-guard.php`: Produktmutationen
  sowie neue Checkouts am bestehenden oeffentlichen Einstieg blockieren.

Alle sechs Programme liegen ausschliesslich unter
`/home/www/carmaja-private-shop/program`. Die bisherigen Programmdateien wurden
privat gesichert, alle Transfers und Zielstaende hashgebunden geprueft und jede
Datei atomar ersetzt. Unterbrechung und Wiederanlauf sind lokal getestet;
es gibt keine implizite Ruecknahme der Sperre.

Der separate Marker
`/home/www/carmaja-private-shop/maintenance/product-cleanup.lock`
haelt die Sperre aktiv. Die Runtime samt Publisher-Konfigurationswerten und
Zugangsdaten wurde bewusst nicht umgeschrieben; die wirksame Sperre liegt vor
Produktmutation und Publisher-/GitHub-Schreibaufruf. Keine automatische
Entsperrung und keine neue Cronkonfiguration. Bestehende Zahlungs-, Worker-,
Widerrufs- und Adminwege wurden nicht abgeschaltet.

### Sicherung und Nachweise

Frisches verschluesseltes Backup `20261111T133340Z-5c1d46d9acd5` erstellt;
Restorevergleich in der getrennten Datenbank bestanden und das Ziel wieder
geleert. Der bestehende OneDrive-Pull endete mit Ergebnis `0`: drei Dateien
nach Groesse und SHA-256 geprueft, Download quittiert. Eine erneute Sichtpruefung
dieser konkreten Sicherung im OneDrive-Web ist damit nicht behauptet.

| Pruefung | Ergebnis |
| --- | --- |
| PHP 8.4.23 lokal, PHP 8.4.27 IONOS; Lint aller sechs Kandidaten und Helfer | bestanden |
| Historische API-Kompatibilitaet mit erweitertem Lebenszyklusnachweis | 55 Tests; Fehler zuvor am unkorrekten Original reproduziert |
| Historischer Bootstrap-Vertrag mit korrigiertem Einstieg | 13 Tests bestanden |
| Wartung, erlaubte Lese-/Auth-/Webhookwege, direkte Publisher-/Lock-Sperren | 39 kuenstliche Pruefungen bestanden |
| Aktivierungsunterbrechung nach jeder Datei, Wiederanlauf, Replay, Fremdaenderung und Manipulation | 86 kuenstliche Pruefungen bestanden |
| Oeffentlicher Produktions-Smoke | 7 Pruefungen: Produkt-/Publish-/Checkoutschreiben 503, unauthentifizierter Produktread 401, CORS-Preflight 204, ungueltige Webhooksignatur 400; jeweils no-store |
| Private installierte Schreib-/Publisher-Sperren | 7 Pruefungen, kein Adapter-/Netzwerkschreibaufruf erreicht |
| Geschuetzter Vorher-/Nachhervergleich, zweimal nach Aktivierung | Produkte, Bilder, Versionen, Preise, 29 Geschaeftstabellen, Konfigurationen, oeffentlicher API-Einstieg, Website-Dateien und andere private Programme unveraendert |

Die laufende technische Tabelle `worker_leases` ist vom Inhaltsgleichheitsvergleich
ausgenommen; der bestehende Worker darf weiterarbeiten. Beide Datenbanksitzungen
waren TLS-aktiv, MySQL 8, Source mit 30 InnoDB-Tabellen. Das bereits akzeptierte
Restrisiko fehlender CA-/Hostidentitaetspruefung bleibt bestehen.

Temporäres lokales Paket und privates Server-Staging samt Prüfhelfern und
Vorhervergleich entfernt. Verschluesselte Sicherung, notwendiger Wartungsmarker,
installierte Programme und private Rueckfallkopien samt Pruefbeleg bleiben erhalten.
Keine Testumgebung beruehrt. Kein Website-Deployment, keine Veroeffentlichung,
Produkt-/Bestands-/Schemaaenderung, kein Shopstart oder Cutover. Kein Commit,
Push oder Merge; Git-main unveraendert.

Reproduzierbarer lokaler Lauf: PHP-/Erweiterungspfad prozesslokal in
`CARMAJA_TEST_PHP` und `CARMAJA_TEST_PHP_EXT` setzen, dann
`node website/scripts/test-product-maintenance-hotfix.mjs`.
Der Paketbau und die privaten Operatorwerkzeuge liegen unter `website/scripts/`;
kuenstliche Sperr-/Aktivierungstests unter `website/tests/php/`.

Naechster Schritt bleibt der kuenstlich gepruefte, manifestgebundene
Reparaturadapter und ein frischer wiederholbarer Dry-Run unter aktiven Sperren.
Oeffentliche Projektionsluecken nicht durch einen Publish umgehen; historische
Bestell-/Zahlungssnapshots, Bestand und bestehende Idempotenzbelege erhalten.

## Bereinigungsvorflug vom 11. November 2026

Die Betreiberin hat die abgesicherte Datenbereinigung einschliesslich vorheriger
Backup-, Restore- und Dry-Run-Pruefung freigegeben. PR #95 ist bereits in main
`6ca2fd17615061ed76b832646640cec8eebf1935` integriert. Dieser Auftrag verwendet
den separaten lokalen Branch `codex/cart-product-cleanup` auf genau dieser Basis.
**Bereinigung noch nicht ausgefuehrt:** Der lesende Vorflug hat offene
Voraussetzungen und einen zusaetzlichen inhaltlichen Konflikt bestaetigt.

### Sicherung und Wiederherstellung

- Private Produktions-Runtime unveraendert mit Modus `0600`; bekannte private
  Produkt-, Programm- und Backuppfade eindeutig geprueft. Keine Testzugriffe.
- Source und Restore sind unterschiedliche Datenbanken mit unterschiedlichen
  Benutzern, MySQL 8.0.36 und aktiven TLS-1.3-Sitzungen. Die 30 Source-Tabellen
  verwenden InnoDB. Der vereinbarte Verzicht auf CA-/Hostidentitaetspruefung
  bleibt ein Restrisiko, kein Nachweis einer geprueften Serveridentitaet.
- Neues verschluesseltes Backup `20261111T122947Z-1cf09dadb13b` ueber den
  vorhandenen, mit main inhaltsgleichen privaten Backupdienst erstellt.
  Manifest und beide Archive: zusammen 26.455.210 Byte.
- Vorhandenen, mit main inhaltsgleichen Windows-Pull-Agenten gestartet.
  Alle drei Dateien im freigegebenen OneDrive-Ziel vorhanden; Groessen und
  SHA-256-Pruefungen stimmen mit der authentifizierten Serverliste ueberein.
  Download serverseitig quittiert, Windows-Aufgabe mit Ergebnis `0` beendet.
  Eine erneute Sichtpruefung dieser konkreten Sicherung im OneDrive-Web wurde
  nicht durchgefuehrt; lokaler Download ist kein eigenstaendiger Cloudnachweis.
- Restore ausschliesslich in der unmittelbar zuvor als leer bestaetigten
  getrennten Restore-Datenbank: Schema-, Inhalts-, Metadaten- und
  Produktdateivergleich bestanden. Danach Restore-Datenbank erneut leer und
  keine temporaeren Restore-Verzeichnisse vorhanden. Produktionsdaten wurden
  nicht zurueckgespielt. Die verschluesselte Sicherung bleibt erhalten.

### Zweifacher lesender Maßnahmenvergleich

Beide Durchlaeufe lieferten identische Ergebnisse und Bindungen fuer Quelldateien,
oeffentliche Bilder, Commerce-Produktmetadaten und private Runtime. Inventar,
Bestandsaudit, Checkouts, Reservierungen, Payments, Bestellungen, Positionen,
Legal Bundles und Migrationsjournal waren zwischen diesen Laeufen unveraendert.
Dies war ein lesender diagnostischer Dry-Run, kein erfolgreicher Apply- oder
Crash-Nachweis des weiterhin fehlenden Reparaturadapters.

| Kandidat | Aktueller Befund | Behandlung vor einer Bereinigung |
| --- | --- | --- |
| CP-2026-0001, -0004, -0005, -0006, -0007, -0009 | Alle sechs bekannten gespeicherten Hashfehler bestaetigt; jeweils zur gegenteiligen Verkaufsfreigabe passend | Nur versionierte Metadatenkorrektur; keine Aenderung der Verkaufsfreigabe. Bei -0009 zusaetzlich Projektionsluecke klaeren. |
| CP-2026-0002 | Quelle Version 5, Commerce und oeffentliche Projektion Version 4; oeffentliche Fachfelder und Bilddateien identisch, Unterschiede nur Version/Hash/Zeitstempel | Kein Preis- oder Textkonflikt; Versionsbindung ausdruecklich in das spaetere Reparaturmanifest aufnehmen. |
| CP-2026-0003 | Quelle, Commerce und oeffentliche Fassung Version 4 konsistent | Unveraendert erhalten. |
| CP-2026-0008 | Quelle Version 5 mit 32,50 EUR, Commerce Version 2 mit 29,50 EUR; auch Text abweichend | Inhaltliche Entscheidung erforderlich; keine automatische Uebernahme oder Preisangleichung. |
| CP-2026-0008 und -0009 | In der Quelle `published`, aber nicht in der aktiven oeffentlichen main-Projektion enthalten | Nicht automatisch neu veroeffentlichen; gewollten Projektionsstatus bestimmen. |
| Legacy-Entwurf | Weiterhin ausserhalb des Modell-3-Vertrags | Unveraendert ausschliessen; keine geschaetzten Pflichtangaben. |

Die sechs vorhandenen oeffentlichen Produkte wurden samt ihren Bilddateien
verglichen. Historische Zahlungsdaten sind terminal: ein Checkout `completed`,
einer `expired`; Reservierungen `converted` und `released`, beide nicht
bestandsblockierend. Eine bestaetigte Bestellung bleibt erhalten. Inbox und
Outboxen enthalten nur bereits verarbeitete/versandte Eintraege, keine Reviewcases.

### Stop vor Datenmutation

Auf dem Produktionsserver fehlt die zusammengefuehrte Lebenszykluskorrektur:
der installierte Uebergang setzt weder `productVersion` noch `sourceHash` neu.
Die installierten Produktprogramme sind nicht der aktuelle main-Stand.
Produkt-Schreiben, Produktionspublisher und GitHub-Adapter sind in der bestehenden
Runtime aktiv. Die Warenkorb- und neue Checkout-Sperrkonfiguration ist noch nicht
installiert. Fehlende neue Schalter gelten nicht als Beweis einer geschlossenen
alten Kaufstrecke. Keine Runtime wurde dafuer umgeschrieben.

Vor Apply muessen daher die fuer CP-2026-0008 geltende Fassung und der
Projektionsumfang geklaert, die eng begrenzte Codebereitstellung separat
freigegeben und ein wirksames Schreib-/Publisher-/Checkout-Sperrfenster
nachgewiesen werden. Kein stilles Gesamtdeployment des Warenkorbs. Der
Reparaturadapter benoetigt weiterhin die synthetischen Apply-, Konflikt-,
Crash- und Wiederanlauftests aus Abschnitt 4, ein privates gebundenes Manifest
und anschliessend einen frischen Dry-Run unter diesen Sperren.

Erneute lokale Regression mit PHP 8.4.23 und nur prozesslokalen Erweiterungen:
9 Backup-Tests und 59 Produkt-API-Tests bestanden. Keine Anwendungscodeaenderung,
kein Deploy, kein Commit, Push oder Merge; keine Produkt-, Preis-, Bestands-,
Zahlungs- oder Rechtstextmutation. Testprodukte, Testbestaende und vorhandene
private Konfigurationen wurden nicht veraendert. Shopstart und Cutover bleiben
gesperrt. Ein neues Backup und seine Downloadquittierung wurden bewusst erzeugt;
die Restore-Testobjekte wurden vollstaendig entfernt.

## 1. Befund und Abgrenzung

Die folgenden Datenbefunde stammen aus dem zuvor freigegebenen lesenden
Produktions-Preflight. Dieser Auftrag verwendet ausschließlich künstliche lokale
Fixtures und ruft weder Produktions- noch Testserver auf.

| Befund | Einordnung | Geplante Behandlung |
| --- | --- | --- |
| Sechs Modell-3-Datensätze mit gespeichertem Hash ungleich dem kanonischen Inhalt: CP-2026-0001, -0004, -0005, -0006, -0007, -0009 | Bei allen sechs passt der alte Hash zur gegenteiligen `salesEnabled`-Ausprägung. Lokale Reproduktion bestätigt den Lebenszyklusfehler. | Nach separater Freigabe eng begrenzte, versionierte Metadatenkorrektur; Verkaufsfreigabe nicht umschalten. |
| CP-2026-0002: Quellversion/Hash ungleich aktiver öffentlicher Projektion, Preis gleich | Eigenständige Projektionsabweichung. Ein Speichern ohne erneutes Veröffentlichen kann diesen Zustand korrekt erzeugen; die konkrete Ursache ist noch nicht abschließend belegt. | Inhalte und Veröffentlichungshistorie vergleichen; maßgebliche Fassung ausdrücklich bestimmen, keine automatische Veröffentlichung. |
| Ein Legacy-Entwurf `ready` ohne gültigen Preis-/Währungsvertrag | Nicht automatisch migrationsfähig. | Erhalten, vom Import ausschließen und erst nach bestätigten Angaben gesondert behandeln. Keine Schätzpreise. |
| Alte private `products/public-products-v2.json` mit einem Produkt | Nicht die aktive, über den GitHub-Publisher angebundene öffentliche Produktquelle. | Nicht als Reparaturquelle verwenden und nicht ungefragt löschen. |

Die Momentaufnahme umfasste 23 Entwürfe (22 Modell 3, ein Legacy-Datensatz)
und sechs Produkte in der aktiven öffentlichen Projektion, Dokumentversion 3.
Diese Zahlen sind keine dauerhafte Ziel-Allowlist. Vor einer späteren Mutation
müssen sie erneut lesend bestätigt werden. Private Rohdaten, Zugangsdaten und
konkrete Laufzeithashes werden nicht in dieses Dokument übernommen.

## 2. Bestätigte Codeursache und lokale Korrektur

`product-api-v4.php` verwendet für Modell 3 und 4 denselben Übergang für
Veröffentlichen, Archivieren und Wiederherstellen. `salesEnabled` gehört bei
beiden Modellen zur kanonischen Darstellung. Bisher wurden aber nur für Modell 4
`productVersion` erhöht und `sourceHash` neu gespeichert. Bei Modell 3 blieben
diese Felder nach der serverseitigen Verfügbarkeitsänderung veraltet.

Die Leseantwort berechnet den Hash erneut. Deshalb konnte die API einen gültigen
Hash melden, obwohl der gespeicherte Datensatz noch den alten Wert enthielt.
Außerdem benötigt die Commerce-Projektion bei verändertem Inhalt eine neue
Produktversion; bloßes Überschreiben des Hashfelds wäre unzureichend.

Die lokale Korrektur erhöht die Produktversion für beide unterstützten Modelle
genau einmal je neuer Lebenszyklusoperation und berechnet anschließend den
kanonischen Hash, bevor gespeichert und projiziert wird. Wiederholungen derselben
Operation ohne zwischenzeitliche Produktänderung verwenden den gespeicherten
Stand. Der Erfolgs-Audit verweist auf die Ergebnisversion statt die Eingabeversion.

Unverändert bleiben Hashalgorithmus und Kanonisierung, API-Felder,
Verkaufsfreigaberegeln, SKU/Slug, Bilder, Preise, Hinweise und Bestandsdienst.
Modell 4 erhält beim Veröffentlichen oder Wiederherstellen keine zusätzliche
Verkaufsfreigabe. Speichern allein veröffentlicht weiterhin nichts.
Die Korrektur repariert vorhandene Datensätze **nicht beim Lesen oder automatisch**.

## 3. Lokale Nachweise

Der erweiterte künstliche Lebenszyklustest schlug vor der Codekorrektur mit
`Gespeicherter Lebenszyklus-Hash ist veraltet` fehl. Er prüft danach:

- Version/Hash in gespeicherter Quelle, API-Antwort, Commerce-Adaptereingabe und öffentlicher Projektion;
- monotone Versionen bei Publish, Archive und Restore sowie Ablehnung eines veralteten neuen Auftrags;
- unveränderte Datensätze bei identischem Replay;
- Projektionsfehler vor Archivierung, Publisherfehler nach Speicherung und Restore-Projektionsfehler samt Wiederanlauf;
- Bindung der Audit-Ergebnisversion, Erhalt von SKU/Slug und privaten Bildern;
- Modell-4-Verkaufsfreigabe, Hinweise und hashgleiche Antworten/Projektionen;
- bewusst unveränderte öffentliche Projektion bei späterem bloßen Speichern.

Die Adapter sind lokal simuliert, Produktdateien und Bilder künstlich.
Kein heutiger MySQL-, Provider-, Geräte- oder Live-Publishernachweis wird daraus
abgeleitet.

Abschließende Regression mit PHP 8.4.23 (Erweiterungen nur prozesslokal):

| Prüfung | Ergebnis |
| --- | --- |
| PHP-Lint: geändertes Programm und Testdatei | bestanden |
| Produkt-API einschließlich erweiterter Modell-3-/Modell-4-Lebenszyklen | 59 Tests bestanden |
| Bootstrap / Produkt-Admin / Browservertrag | 13 / 20 / 37 Prüfungen bestanden; Browserlauf wiederholt zusätzlich die 59 API-Tests |
| Verkaufsfreigabe / Commerce-Kern | 28 / 23 Prüfungen bestanden; keine Bestandsmutation durch Projektion |
| Warenkorbvertrag und Transaktions-Retry | beide Suiten bestanden, nur lokale Testadapter |
| Historischer Schema-/Cutover-/Importvertrag | 113 lokale Prüfungen bestanden; Import-Suite separat erneut bestanden, keine MySQL-DDL-Aussage |
| `npm ci --ignore-scripts --prefer-offline --no-audit --no-fund` | gesperrte Abhängigkeitsversionen installiert, Lockdatei unverändert |
| `npm run lint:test` / `npm run build:test` | bestanden; 25 künstliche Testseiten, Exportprüfung bestanden |
| `npm test` | 260 Node-Tests und nachfolgende Shell-Mocks bestanden |
| Lokaler Paketbau und Integritätsprüfung | 40 Dateien; korrigierte API bytegleich, uncommittierter Kandidat markiert, alle Aktivierungsgates false |
| `git diff --check` | bestanden |

Der erste Node-Lauf konnte Bash innerhalb der Sandbox nicht starten
(`Bash/E_ACCESSDENIED`). Ohne Codeänderung bestand der erneute vollständige
Lauf mit freigegebener lokaler WSL-Ausführung. Kein verbliebener Testfehler
wird als Basisfehler ausgeblendet. Die Shell-Suite überspringt ihren optionalen
PHP-Klickstatistik-Untertest, weil WSL kein eigenes PHP besitzt; dieser
unveränderte, fachfremde Untertest wird nicht als bestanden gewertet.
Android wurde nicht erneut gebaut, da weder
App noch deren Vertrag geändert wurden.

Die PHP-Fixtures wurden entfernt (keine verbleibenden `carmaja-api-phase3-*`
Verzeichnisse). Das eigens gebaute temporäre Paket wurde nach Prüfung entfernt
und seine Abwesenheit bestätigt. Keine bestehenden Testprodukte, Bestände,
privaten Konfigurationen oder Laufzeitartefakte wurden geöffnet oder verändert.
Kein Commit, Push, CI-Start, Merge oder Deployment in diesem Auftrag.

## 4. Späterer Bereinigungsablauf – nicht ausgeführt

1. **Codefreigabe:** lokale Änderung reviewen, nach gesondertem Auftrag committen,
   pushen und CI prüfen. Merge und Bereitstellung benötigen eigene Freigaben.
2. **Aktuellen Zustand binden:** je Kandidat Quellmodell, Status, `productVersion`,
   Entwurfsversion, gespeicherten und neu berechneten Hash sowie aktive öffentliche
   und Commerce-Projektion lesend vergleichen. Bilder und alle fachlichen Felder
   mit einbeziehen. Unbekannte Abweichungen stoppen den betreffenden Kandidaten.
3. **Inhaltliche Entscheidung:** bei CP-2026-0002 festlegen, welche Fassung tatsächlich
   veröffentlicht sein soll. Den Legacy-Entwurf bis zu bestätigten Pflichtangaben
   unverändert ausschließen. Keine Behebung durch Umschalten von `salesEnabled`.
4. **Schreibfenster absichern:** Produktänderungen und Publisher vorübergehend
   sperren, neue Checkouts schließen, laufende Zahlungen sicher zum bestätigten
   Endzustand verarbeiten. Frisches verschlüsseltes Backup, Offsite-Nachweis und
   Restore in getrenntem Ziel bestätigen. Diese Aktionen sind hier nicht freigegeben.
5. **Privates Reparaturmanifest vorbereiten:** ausschließlich bestätigte Produkt-IDs,
   erwartete Datei-/Inhaltshashes und Versionen, gewählte Zielfassung, neue
   Operations-/Korrelations-ID, erlaubte Feldänderungen und Projektionsziele binden.
   Kein Klartextdump und keine Secrets im Repository. Für eine neue korrigierte
   Produktfassung eine serverseitige Version oberhalb aller bekannten Versionen
   dieses Produkts vorsehen; Entwurfsversion, `updatedAt` und Audit fortschreiben.
   Fachliche Felder bleiben außer einer gesondert bestätigten Inhaltskorrektur
   unverändert. Historische Idempotenzbelege nicht umschreiben oder wiederverwenden.
6. **Künstlicher Apply-Nachweis und realer Dry-Run:** den noch zu implementierenden
   Reparaturadapter zunächst mit synthetischen Altzuständen prüfen: Hashmanipulation,
   Versionskonflikt, gleichzeitiges Speichern, Abbruch nach jedem Schritt,
   doppelte Ausführung und Wiederanlauf. Danach nur lesenden echten Dry-Run
   separat freigeben; erneut identischer Lauf muss denselben Maßnahmenplan ergeben.
7. **Separat freigegebene Bereinigung:** nur bei unveränderten Erwartungen die
   gebundenen Quellen atomar ersetzen und die zugehörigen Commerce-/öffentlichen
   Projektionen kontrolliert aktualisieren. Archivierte Produkte bleiben entfernt;
   Entwürfe werden nicht neu veröffentlicht. Dateisystem, MySQL und GitHub sind
   keine gemeinsame Transaktion: jeder Schritt benötigt einen privaten Fortschritts-
   und Wiederanlaufnachweis. Bei Teilfehler bleibt der Kaufweg geschlossen.
8. **Abschlussprüfung:** Quelle, Lesemodell und tatsächlich ausgewählte öffentliche
   Fassung müssen nach Inhalt, Version und Hash zusammenpassen. Bestände,
   Reservierungen, Bestellungen, Payments, Legal-/Checkout-Snapshots und bisherige
   Migrationsjournale unverändert vergleichen. Neue Backups prüfen, temporäre
   Reparaturartefakte entfernen; vorhandene private Konfigurationen erhalten.

## 5. Stop- und Rollbackgrenzen

Stoppen bei unbekanntem Ziel, zwischenzeitlicher Änderung, neuer Hashabweichung,
ungeklärter öffentlicher Fassung, fehlendem Restore-Nachweis oder einem
unerwarteten Bestands-/Zahlungseffekt. Keine pauschale Neuveröffentlichung aller
Produkte und kein Import aus der alten privaten Projektion.

Historische Checkout-/Bestell-Snapshots bleiben unveränderlich, auch wenn sie
ältere Hashes tragen. Rollback darf keine Zahlung oder Bestellung zurücksetzen.
Unter nachgewiesener Schreibsperre und ohne nachfolgende Geschäftsvorgänge sind
nur die gebundenen Produkt-/Projektionsartefakte aus dem gesicherten Zustand
wiederherstellbar. Andernfalls nur vorwärts korrigieren und Kaufweg geschlossen
halten. Niemals pauschal Commerce aus einem älteren Backup überschreiben.

Nächster zulässiger Schritt nach diesem Auftrag ist die Prüfung der lokalen
Änderung. Commit/Push/CI, produktiver Reparaturadapter, Deployment, echte
Datenbereinigung, Mengenimport und Shopfreigabe sind damit nicht erteilt.
