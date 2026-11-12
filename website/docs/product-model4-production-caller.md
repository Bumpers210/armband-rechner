# Privater Modell-4-Produktionsaufrufer

Stand: 12. November 2026. **Nur lokal implementiert und mit kuenstlichen Daten
geprueft. Keine Bereitstellungs-, Ausfuehrungs- oder Cutoverfreigabe.**

Der anschliessend freigegebene MySQL-/Linux-Integrationslauf ist als separater
GitHub-hosted Test vorbereitet (`model4-caller-mysql.yml`). Zwei frische
Loopback-Datenbanken, temporaere TLS-Zertifikate und ausschliesslich erzeugte
Testzugangsdaten; kein IONOS-/Produktionszugang und keine bestehenden Secrets.
Die echte CLI und der Testtreiber verwenden dieselben Restore-/Guardfunktionen.
Ein lokales Ciphertext-Zweitziel ersetzt im Test die Offsiteablage, belegt aber
keine neue OneDrive-Uebertragung. Erst ein erfolgreicher Lauf samt Cleanupbericht
schliesst diesen Nachweis; Vorbereitung oder Commit allein sind keine Abnahme.

## Eng begrenzter Zweck

`scripts/product-model4-production.php` verbindet den bereits separat geprueften
Modell-4-Adapter mit echten Pfad-, Runtime-, Release-, Datenbank- und
Backupnachweisen. Er erzeugt keine Produkte, veroeffentlicht nichts und importiert
keine Mengen. Preis, Texte, Bilder, Verkaufsfreigabe, Bestand, Bestellungen und
historische Nachweise bleiben im unveraenderten Adapterumfang erhalten.
CP-2026-0008/-0009 werden nicht oeffentlich hinzugefuegt. Rechtsbundle,
Website, App, Publisher, Cron, Checkoutfreigabe und Deployschalter werden nicht
umgestellt. Es gibt keine HTTP-Route und keine automatische Aktivierung.

## Artefakt und private Pfade

Der lokale Builder `scripts/prepare-product-model4-tool.mjs` erzeugt genau acht
PHP-Dateien und `release.json` in einem **neuen** lokalen Verzeichnis. Er liest
nur Repositorycode, niemals Laufzeitdaten oder Secrets. Wiederholung ergibt bei
gleichem Stand identische Bytes; ein vorhandenes Ziel wird nicht ueberschrieben.
Ein schmutziger/uncommitteter Arbeitsstand wird ehrlich mit
`workingTreeDirty=true` gekennzeichnet und vom Produktionsaufrufer abgelehnt.
Der lokale Entwicklungsstand ist deshalb noch kein ausfuehrbarer Release.

Spaeterer, separat freizugebender Installationsort:

```text
/home/www/carmaja-private-shop/model4-tool/
```

Dieses getrennte Werkzeug ersetzt weder die aktiven API-/Workerprogramme noch
den installierten Backupdienst. Der aktuelle Warenkorb-Deploymentworkflow und
seine Paket-Allowlist bleiben unveraendert. Die neue optionale Restorepruefung
ist im Werkzeug enthalten; ihre bestehende Einargument-CLI bleibt kompatibel.

Der echte Einstieg verlangt Linux, PHP 8.4 und exakt diesen privaten Werkzeugpfad.
Keine Testroot-, SQLite-, Environment- oder DSN-Overrides ueber CLI/Umgebung.
Die ausschliesslich lokalen Tests verwenden injizierte I/O-Abhaengigkeiten;
der produktive Dispatcher bietet dafuer keinen Schalter.

Weitere fest gebundene Pfade:

| Zweck | Pfad |
| --- | --- |
| Autoritative Produktdaten | `/home/www/carmaja-private-production` |
| Vorhandene Runtime, nur lesen | `/home/www/carmaja-private-shop/config/runtime-config.php` |
| Vorhandener Backupschluessel, nur lesen | `/home/www/carmaja-private-shop/config/backup-key.php` |
| Wartungsmarker, erhalten | `/home/www/carmaja-private-shop/maintenance/product-cleanup.lock` |
| Aktiver API-Einstieg, nur Hashpruefung | `/home/www/carmaja-production-api` |
| Privater Vorgang | `/home/www/carmaja-private-production/audit/<operationId>` |

Pfade muessen kanonisch, regulaer und ohne Symlinks sein. Private Freigabe,
Manifest, Runtime, Schluessel und Restorebelege verlangen `0600`; der
Vorgangsordner `0700`. Gruppen-/weltbeschreibbare Vorfahren werden abgewiesen.
Bestehende Modi werden nicht automatisch repariert. Es werden keine fremden
Konfigurationen gesucht, eingelesen oder kopiert.

## Freigabe- und Manifestbindung

Die benoetigten privaten Vorgangsdateien werden erst nach separater Freigabe
auf Grundlage eines aktuellen lesenden Vorflugs bereitgestellt. Keine dieser
Dateien gehoert mit echten Daten ins Repository oder ein CI-Artefakt.

- `manifest.json`: Ergebnis von `cmj_m4_capture`, einschliesslich Originalbytes,
  Zielbytes, Bildhashes, Commerce-Vor-/Zielzeilen, Zielidentitaet, festem Zeitpunkt
  und bisheriger oeffentlicher Referenz. Kein Normal-API-PUT als Ersatz.
- `public-reference.json`: die separat gepruefte bisherige oeffentliche Projektion;
  sie muss exakt zur Manifestreferenz passen. Der Aufrufer ruft keine Website ab
  und behauptet keinen erneuten Live-Abgleich.
- `release.json`: sauberes, separat zu pruefendes Werkzeugartefakt mit Commit und
  exakter Dateihashliste. Der Aufrufer baut oder installiert dieses nicht selbst.
- `active-package.json`: Manifest des bereits bereitgestellten, geprueften
  Wartungs-/Warenkorbpakets. Aktive private Programme, API-Einstiege und Worker
  werden gegen dessen Hashes geprueft, nicht ersetzt. Historische
  Migrationsjournalhashes bleiben erhalten und werden separat gebunden.
- `approval.json`: ausdrueckliche, zeitlich begrenzte Ausfuehrungsfreigabe. Ihr
  **Datei-SHA-256** wird beim Aufruf separat angegeben. Das Manifest verwendet
  dagegen seinen **kanonischen Digest**. Beides nicht verwechseln.

`approval.json` verlangt diese Felder:

```text
format = model4-execution-approval-v1
environment = production
approved = true (erst nach gesonderter ausdruecklicher Freigabe)
operationId = model4-<eindeutige Kennung>
actions = ausdruecklich freigegebene Aktionen, keine implizite Applyfreigabe
issuedAt, expiresAt = UTC, gueltig, maximal 24 Stunden auseinander
releaseSha = gepruefter sauberer Werkzeugcommit
manifestDigest = kanonischer Digest des privaten Umstellungsmanifests
runtimeSha256, keyFileSha256 (bei separater Schluesseldatei)
releaseManifestSha256, activePackageSha256
maintenanceSha256, publicReferenceSha256
schemaJournalDigest = Digest der sortierten migration_id/checksum-Zeilen
backupId, backupManifestSha256
publicationAllowed = false
inventoryImportAllowed = false
selectedProducts = []
```

Die Datei ist eine Operatorfreigabe, kein sich selbst erteilender Rechts- oder
Produktionsnachweis. Freigabe und Releasebindung sind vor einem spaeteren
Produktionslauf separat zu pruefen. Ein Angreifer mit Schreibzugriff auf den
vertrauenswuerdigen Unix-Account und seine privaten Schluessel wird dadurch
nicht zu einem anderen Sicherheitsbereich; Dateirechte und Zugangsschutz
bleiben notwendig.

## Vorflug und Ruhestellung

1. Freigabe, Laufzeit, Pfade, Dateihashes, Manifestkompilierung und Zielidentitaet
   pruefen. Alle sieben vorhandenen Runtime-Schalter muessen ausdruecklich
   `false` sein: Publisher, GitHub-Adapter, V4-/V5-Schreiben, Warenkorb,
   neue Checkouts und Kollektionen-Commerce. Fehlende Werte sind kein `false`.
2. MySQL-DSN ohne Socket-/Mehrfachparameter, getrennte Source-/Restore-Namen und
   Benutzer; PDO-Exceptions, echte Prepared Statements, kein LOCAL INFILE und
   keine Mehrfachanweisungen. Explizit TLS anfordern, aktive `Ssl_cipher` **und**
   `Ssl_version` pruefen; MySQL 8, InnoDB und UTC erzwingen. Vorhandene CA wird
   mit Zertifikatspruefung verwendet. Ohne CA bleibt die ausdruecklich akzeptierte
   fehlende Host-/CA-Pruefung Restrisiko, niemals Klartextfallback.
3. Historisches Migrationsjournal byteinhaltlich in Kennung/Checksum vergleichen;
   alle fuenf benoetigten Migrationen muessen vorhanden sein. Keine Migration
   erneut ausfuehren, keine Journalzeile korrigieren.
4. Keine offenen/unklaren Checkouts, blockierenden Reservierungen, laufenden
   Zahlungen oder unbestaetigten Erfolgszahlungen. Inbox vollstaendig verarbeitet,
   Metadaten-/Mail-Outbox versendet; auch `failed`, `manual_review` und
   `delivery_unknown` stoppen. Keine gehaltene Workerlease, auch keine bloss
   abgelaufene, noch unbehandelte Lease. Der Aufrufer leert keine Warteschlange.
5. Nur noch das erwartete Kollektionen-Vorstadium in `commerce_products`.
   Warenkorb-Adminbestandsrouten sind durch `inventoryCartEnabled=false` aus;
   alte Wiedereinlagerung verweigert Kollektionen. Der Wartungsmarker blockiert
   Produkt-/Publisher-/Checkoutschreiben im hashgeprueften aktiven Paket.
6. Vor Apply/Resume/Dateirueckfall dieselben Drainchecks unter echten
   InnoDB-Row-/Range-Locks auf einer **zweiten**, identitaetsgleichen Verbindung:
   Workerleases, Checkouts, Payments, Reservierungen, Inbox, beide Outboxen und
   Inventar. `REPEATABLE READ` schuetzt auch leere Bereiche. Damit bleiben
   relevante Admin-/Workerbestandsmutationen fuer den kurzen Vorgang blockiert.
   Andere nicht produktbezogene Adminfunktionen werden nicht pauschal gesperrt.
7. Eigentliche Produktumstellung nutzt die erste Verbindung und die bestehenden
   Draft-/Migrationsdateisperren. Guards werden immer per Rollback freigegeben;
   der Guard schreibt selbst keine Lease-, Bestands- oder Geschaeftszeile.
   Vor Dateiumbenennungen und unmittelbar vor SQL-Commit wird zusaetzlich per
   SQL die Verbindung/Transaktion des Guards geprueft. Guardverlust stoppt den
   Vorgang; vor Commit werden SQL-Aenderungen zurueckgerollt. Der vorhandene
   lokale Datei-/Freigabecallback bleibt ohne Provider- oder Datenbankaufrufe.

PDO-Verbindungswartezeit fuenf Sekunden, InnoDB-/Metadaten-Lockwartezeit drei
Sekunden, SELECT-Ausfuehrungslimit fuenf Sekunden. Das kooperative 40-Sekunden-
Budget umfasst die Guardphase und Umstellung, ersetzt aber keinen harten
Betriebssystem-/Stromausfallschutz. Keine Provider-, Backup-, Restore- oder
sonstigen externen Aufrufe unter den gehaltenen Row-Locks. Lokale Datei-/Hash-
und Freigabepruefungen werden vor den einzelnen Schreibschritten wiederholt.

Die vorhandene Datei `backups/locks/backup.lock` wird ohne Warten mit dem
Backupdienst geteilt; sie wird weder neu angelegt noch ersetzt. Damit kann
waehrend Restore/Umstellung kein Stundenbackup einen gemischten Zwischenstand
sichern oder das gebundene Archiv rotieren. Ein laufendes Backup blockiert
den Aufrufer vor Produktmutationen. Der Restore erwirbt zusaetzlich eine
benannte MySQL-Sperre gegen einen zweiten Lauf dieses Aufrufers. Andere
manuelle Restoreprogramme duerfen waehrenddessen nicht parallel laufen.

## Backup-/Restorevertrag und Aufrufe

Syntax des spaeteren privaten CLI, **hier nicht auf Produktion ausgefuehrt**:

```text
/usr/bin/php8.4 /home/www/carmaja-private-shop/model4-tool/product-model4-production.php <Aktion> <operationId> <Freigabedatei-SHA256>
```

Nur fuenf benannte Aktionen, keine frei uebergebenen Pfade/SQL/Secrets:

| Aktion | Wirkung |
| --- | --- |
| `verify-restore` | Separat freizugebender Restore ausschliesslich im getrennten leeren Restoreziel, anschliessend private Nachweisdatei |
| `preflight` | Nur lesen; alle Nachweise und aktueller Quell-/Journalzustand, keine Lockdatei oder Geschaeftsmutation |
| `apply` | Nur frisch vorbereiteter Vorgang ohne Journal; eng begrenzte Produktumstellung |
| `resume` | Nur derselbe gebundene Vorgang mit vorhandenem Journal; keine zweite Versionserhoehung |
| `rollback-files` | Nur Originaldateien vor SQL-Commit zuruecksetzen; nach Commit verboten |

Vor erster Umstellung: gebundenes Backup hoechstens 90 Minuten alt, fertiger
Ready-Marker, authentifizierter Manifest-HMAC, passende Key-ID, beide vollstaendig
groessen-/hashgeprueften Archive und passende Offsite-Downloadquittierung. Eine
Quittierung ist kein erneuter Nachweis der OneDrive-Websichtbarkeit.

`verify-restore` verlangt ein wirklich leeres Restoreziel (Tabellen/Views,
Routinen, Events, Trigger), benutzt den bestehenden verschluesselten Backup-
Restoreweg und vergleicht zusaetzlich **restaurierte** Commerce-Produktzeilen,
Quelldateien und Bilder mit den gebundenen Originaldaten des Umstellungsplans.
Das neue optionale Callback laeuft erst nach den bestehenden Schema-/Inhalts- und
Dateivergleichen. Ein Callbackfehler durchlaeuft weiterhin den Cleanup im
`finally`-Block. Nach Erfolg werden Leerheit und fehlende private Restore-
Temporaerdateien nochmals geprueft. Erst dann entsteht ein `0600`-Beleg
`restore-proof-<Freigabedatei-SHA256>.json`, mit getrennt abgeleitetem HMAC-Key,
Freigabe-, Release-, Ziel-, Plan- und Backupbindung. Keine Test-Booleans oder
einfachen Statusbehauptungen ersetzen diesen Beleg.

Ein Beleg ist 90 Minuten gueltig. Bestehende Belege werden nicht ueberschrieben;
abgeschnittene Dateien blockieren sicher. Nach laengerem Abbruch wird **nicht**
ein teilweise umgestellter Stand als neue Originalquelle ausgegeben: alter Plan
und originales Backup bleiben erhalten. Neue ausdrueckliche Wiederanlauffreigabe
und erneuter Restorebeleg sind erforderlich. Nur bei vorhandenem konsistentem
Journal darf das Originalbackup aelter sein. Keine Erleichterung fuer ein neues
`apply`, keine automatische Entsperrung nach Zeitablauf.

Rueckgaben enthalten nur feste Statuswerte und Anzahlen. Fehler enthalten keine
Exceptiondetails, Produkttexte, Pfade, DSNs, Keys oder Zugangsdaten. Auch
unerwartete Ausgabe beim Runtime-Laden wird nicht an stdout weitergereicht.

## Lokale Abnahme und verbleibende Gates

- 389 neue PHP-Callerpruefungen mit kuenstlichen Dateien, SQLite-Transaktionen
  und expliziten I/O-/MySQL-Protokoll-Doubles bestanden. Echte lokale Guards,
  Hash-/HMAC-Pruefungen, Planvalidierung, Quellenvergleich, Freigabeverlust,
  Abbruch vor/nach Commit, Resume, Dateirueckfall, frische erneuerte Recovery-
  Belege, gemeinsamer Backupsperre, simuliertem Guardverlust unmittelbar vor
  Commit, CLI-Redaktion und Cleanup geprueft.
- Bestehende Vorbereitung 104 und Adapter 421 Pruefungen erneut bestanden;
  Backup 10/10, vorhandene API-, Bootstrap-, Reparatur-, Projektions-, Import-,
  Bestands-/Historien- und Backupvertragstests bestanden.
- Zwei neue Node-Artefakt-/Abgrenzungstests: exakte Codeliste, deterministischer
  Inhalt, Verweigerung vorhandener Ziele, keine Verbindung zu aktiven Routen
  oder Deploymentworkflows. Insgesamt 263 Node-Tests bestanden.
- Lokaler ESLint-, TypeScript-, Testbuild-/Exportnachweis mit 25 Seiten bestanden.
  Die WSL-Shelltests benoetigten zunaechst lokalen Zugriff ausserhalb der Sandbox;
  kein Serverzugriff. Shell-/Rollback-Mocks, Diff- und eingegrenzte Geheimwert-
  pruefung bestanden. Der in WSL fehlende optionale PHP-Klickstatistikaufruf
  bestand separat mit lokalem PHP 8.4. Keine Hostingdatei veraendert oder gelintet.
- Ein anfangs offenes SQLite-Handle in der neuen Testfixture verhinderte ihren
  Windows-Cleanup. Der Test wurde korrigiert und die eigenen Restdateien entfernt;
  Wiederholung samt Cleanup bestanden. Keine vorhandenen Daten betroffen.

**Nachweisgrenze:** Dieser Auftrag hat keine echte MySQL-/IONOS-Verbindung
geoeffnet. Die frueheren 206 MySQL-/Linux-Adapterpruefungen bleiben gueltiger
Adapterbeleg, beweisen aber nicht den neu hinzugekommenen Zwei-Verbindungs-Guard
oder den neuen Restore-Callback im Zusammenspiel. Vor Bereitstellung ist dafuer
ein isolierter kuenstlicher MySQL-/Linux-Nachweis erforderlich (einschliesslich
Guard-Timeout/Verbindungsverlust, paralleler Worker/Admin und Callbackfehler mit
vollstaendigem Restore-Cleanup). Windows ersetzt keine neue POSIX-Abnahme.

Danach: sauberer Review-/CI-Release, gesonderte private Bereitstellungsfreigabe,
aktueller lesender Produktionsvorflug, wirklich freigegebener Plan samt
Backup/Restore und ausdrueckliche eng begrenzte Ausfuehrungsfreigabe.
Mengenzaehlung/-import, v7-Umschaltung, App-/Websitewechsel und Shopstart bleiben
eigene nachgelagerte Gates. Kein Commit, Push, Merge oder Deployment in diesem
lokalen Auftrag; Testprodukte, Bestaende und private Konfigurationen erhalten.
