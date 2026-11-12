# Produktmodell 4: Vorbereitung und abgesicherter Wiederanlauf

Stand: 11. November 2026. Basis: main
`e5d9627ce0dfa7e2adaf33fa81367d2a5111ead5` nach PR #97.
Lokaler Arbeitsbranch: `codex/cart-model4-preparation`.

Ergaenzung vom 12. November 2026: Der begrenzte Schreib-/Wiederanlaufadapter
ist lokal implementiert und mit kuenstlichen Daten geprueft. Die folgenden
Produktionsangaben bleiben Nachweise vom 11. November, keine erneute
Serverpruefung. Im damaligen lokalen Auftrag gab es keinen Serverzugriff.
Der anschliessend separat beauftragte MySQL-/Linux-Nachweis vom selben Tag
ist im letzten Abschnitt dokumentiert; Produktionszugriff blieb ausgeschlossen.

## Bereits abgeschlossene Bereitstellung

Das separat freigegebene private Warenkorbpaket und der API-Einstieg sind
bereitgestellt. Ausschliesslich die ausstehende Migration
`commerce-v3-cart-inventory` wurde angewendet; die Datenbank hat nun 38 statt
30 Tabellen. Historische Geschaeftsdaten, die vier bisherigen Journalzeilen,
deren Pruefsummen und Zeitstempel blieben erhalten. Die erneute Ausfuehrung
der Migration ist als wirkungslos geprueft.

Verschluesselte Backups vor und nach der Erweiterung, Offsite-Download mit
Quittierung und der anschliessende Restore mit Struktur-/Inhaltsvergleich
sind nachgewiesen. Das getrennte Restore-Ziel ist wieder leer. Die privaten
Nachweise unter `.cart-rollout-20261111-e5d9627` wurden nur lesend geprueft;
ihre Inhalte werden nicht ins Repository uebernommen. Automatischer Worker,
geschlossene HTTP-Kaufwege und Cleanup sind dort ebenfalls belegt.

Runtime, Schluessel, private Produktdateien, Wartungsmarker und bestehende
Website blieben erhalten. Keine Produktumstellung, Mengenuebernahme,
Veroeffentlichung oder Checkoutoeffnung ist Bestandteil dieser Bereitstellung.
Vorhandene Mengen sind weiterhin nicht als gezaehlter Warenkorbbestand bestaetigt.

## Ergebnis der anschliessenden Nur-Lese-Pruefung

| Gegenstand | Bestaetigter Stand |
| --- | --- |
| Autoritative Dateien | 23 Entwuerfe: 22 auf Modell 3, ein alter Entwurf bleibt unveraendert |
| Modell-3-Vertrag | Alle 22: gueltige serverseitige Hashes, positive EUR-Preise, Messwerte, Bildmetadaten und vollstaendiger PUT-Vertrag |
| Commerce | Alle neun vorhandenen Produktbindungen stimmen mit Quelle, Preis, Text, Bildern, Verkaufsflag, Version und Hash ueberein |
| Oeffentliche Repository-Projektion | Genau sechs bestehende Produkte; vollstaendiger Metadatenvergleich mit den Quellen bestanden |
| Private Produkte | CP-2026-0008/-0009 werden nicht hinzugefuegt; 32,50 EUR und aktueller Text von CP-2026-0008 bleiben erhalten |
| Probelauf | 22 Kandidaten; identischer zweiter Lauf auch bei geaenderter Eingabereihenfolge |
| Hinweise | Keine nachtraegliche Zuordnung zu bestehenden Produkten oder Entwuerfen |
| Rechtsbundle | Bereitgestellte v7: approved, Produktionsfassung, kanonischer Inhaltshash korrekt; noch nicht in Commerce registriert und nicht aktiv |
| Betriebssperren | Wartungsmarker vorhanden, V5-Schreiben und Warenkorbbetrieb deaktiviert |
| Zugriff | MySQL 8, aktive TLS-Sitzung, PDO-Sitzung ausschliesslich READ ONLY; kein Serverhilfsskript angelegt |

Die gelesenen Entwurfsdateien, Commerce-Produktzeilen, Runtime und Sperrdatei
wurden vor/nach dem Probelauf verglichen und blieben unveraendert. Dies ist
keine erneute Restore-, Bestands- oder Providerabnahme. Die Dateiinhalte und
der echte, hashgebundene Kandidatenbericht wurden nicht lokal gespeichert.
Im Repository stehen ausschliesslich Quellcode, kuenstliche Tests und diese
zusammengefassten Nachweise. Physische Bilddateien werden vom neuen reinen
Vorbereitungshelfer nicht geoeffnet; deren erneute Hash-/Lesbarkeitspruefung
bleibt eine Vorbedingung des spaeteren Schreibschritts.

## Vertrag des Vorbereitungshelfers

`scripts/product-model4-preparation.php` ist eine reine Funktionsbibliothek,
ohne Datei-, Datenbank-, Netzwerk-, Publish- oder Applypfad. Sie verwendet die
tatsaechliche serverseitige Produktkanonisierung. Der Aufrufer liefert nur
gelesene Quell-/Projektionsdaten und einen festen UTC-Zeitpunkt. Wiederholungen
mit denselben Daten liefern identische Datensatz- und Gesamthashes.

Fuer bestehende Modell-3-Quellen werden ausschliesslich folgende Werte als
Kandidat im Arbeitsspeicher vorbereitet:

- `productModelVersion`: 4;
- `productVersion` und Entwurfs-`version`: jeweils genau +1;
- `updatedAt`: fest gebundener UTC-Zeitpunkt;
- `noticePolicyId`: null, damit keine neuen Hinweise angehaengt werden;
- `sourceHash`: aus der neuen kanonischen Darstellung serverseitig berechnet.

Alle anderen Felder bleiben exakt erhalten, auch unbekannte private
Metadaten, Bildpfade, Beschreibung/Formatierung, Status und Verkaufsflag.
Kein Produkt wird durch diese Konvertierung neu angelegt. Bereits gueltige
Modell-4-Datensaetze bleiben unveraendert. Alte Modell-1/2-/Legacy-Entwuerfe
werden separat als unveraendert ausgewiesen; unbekannte Modellversionen
stoppen die Vorbereitung. Doppelkennungen, widerspruechliche Preise/Texte,
Versions-/Hashabweichungen, nachtraegliche Hinweise und abweichende
Commerce-/oeffentliche Projektionen brechen den gesamten Probelauf ab.

Der Bericht bindet Quelle, Commerce-Zuordnung und bisherige oeffentliche
Projektion. Er enthaelt keine Produkttexte, Bilddateipfade oder privaten
Kalkulationen. Er ist trotzdem ein privater Arbeitsnachweis, kein
oeffentliches Artefakt. Seine festen Gates lauten:

```text
status = not_approved
selectedProducts = []
publicationAllowed = false
inventoryImportAllowed = false
applySupported = false
```

## Lokaler Schreib-/Wiederanlaufvertrag vom 12. November

`scripts/product-model4-migration.php` ist eine getrennte private Bibliothek.
Sie besitzt keinen CLI-Dispatcher, HTTP-Einstieg, Runtime-Lader, Publisher oder
Deploymenthook und steht nicht in der Paket-Allowlist. Der schreibfreie
Vorbereitungshelfer behaelt unveraendert `applySupported=false`. Der neue
Adapter wird in diesem Auftrag ausschliesslich durch kuenstliche Tests benutzt.

### Bindung und Vorbedingungen

- Ein privates Manifest bindet den exakten Quelldateisatz, originale und
  erwartete Zielbytes, Dateimodi, Bilddateihashes, bisherige oeffentliche
  Projektion, saemtliche Commerce-Produktzeilen, Zielidentitaet und einen
  festen UTC-Zeitpunkt. Sein extern freizugebender Digest wird beim Aufruf
  separat uebergeben. Das Ziel wird erneut aus den Originaldaten berechnet;
  manipulierte Zielwerte werden auch bei neu berechnetem Digest abgelehnt.
- Das Manifest enthaelt private Quelldaten als Rueckfallkopie, nicht nur den
  datensparsamen Vorbereitungsbericht. Es gehoert ausschliesslich in den
  privaten Vorgangsordner, niemals ins Repository, in Logs oder Buildartefakte.
  POSIX: Vorgangsordner `0700`, Manifest/Journal/Audit `0600`; bestehende
  Quelldateimodi bleiben erhalten. Pfade muessen kanonisch und symlinkfrei sein.
- Der Aufrufer uebergibt eine bereits gepruefte PDO-Verbindung mit Exceptions,
  ohne offene Transaktion. MySQL verlangt Version 8, InnoDB, eine UTC-Sitzung
  und nichtleere Sitzungswerte `Ssl_cipher`/`Ssl_version`. Die Bibliothek sucht
  keine Zugangsdaten und baut keine Verbindung auf. Der bestehende Vertrag
  fuer explizit angefordertes TLS ohne Klartextfallback bleibt Pflicht.
- Vor Schreibbeginn und waehrend des Laufs muessen separate Ausfuehrungsfreigabe,
  Wartungssperre, gestoppte Produkt-/Admin-Schreiber, geschlossene Checkouts,
  abgearbeitete Zahlungen, geprueftes Backup/Restore und private Pfade bestaetigt
  sein. Der spaetere Produktionsaufrufer muss diese Zustandsnachweise wirklich
  pruefen; konstante Test-Booleans sind kein Produktionsnachweis.
- Sicherheitscallbacks sind ausschliesslich lokal. Es gibt keine Provider-
  oder anderen Netzwerkaufrufe waehrend der Transaktion/Row-Locks.

### Eng begrenzte Aenderungen

Bestehende Modell-3-Dateien erhalten ausschliesslich die oben genannten
Modell-/Versions-/Hashwerte, `updatedAt` und `noticePolicyId=null`. Bereits
gueltige Modell-4-Dateien, vorhandene Hinweise und alte Entwuerfe bleiben
bytegleich. Preis, Texte, Bilder, private Zusatzfelder, Status und
Verkaufsfreigabe werden nicht umgeschrieben.

In bestehenden `commerce_products` werden nur `product_version`, `source_hash`,
`synchronized_at` und `updated_at` nachgezogen. Es gibt keine Produktanlage,
Bestandsaenderung, Umschaltung von `sales_model`, nachtraegliche Hinweiszuordnung
oder Aenderung historischer Snapshots. Bereits belegte neuere Commerce-
Snapshots werden als Konflikt abgelehnt, nicht still ersetzt. CP-2026-0008/-0009
bleiben aus der oeffentlichen Projektion ausgeschlossen.

### Abbruch, Wiederanlauf und Rueckfall

1. Nicht wartende globale Migrationssperre und die vorhandenen Draft-Sperren
   in stabiler Reihenfolge erwerben. Quelle, Bilder, Commerce und Journal
   erneut vollstaendig gegen das Manifest pruefen.
2. `applying` privat protokollieren. Dateien einzeln ueber eine exklusiv
   angelegte, geflushte temporaere Datei auf demselben Dateisystem atomar
   ersetzen. Zwischen Datei- und SQL-Schritt bleibt die Wartungssperre aktiv.
3. Alle Commerce-Zeilen in einer kurzen Transaktion in Produkt-ID-Reihenfolge
   sperren, den vollstaendigen Vorzustand vergleichen und Version/Hash mit
   erwarteten Altwerten aktualisieren. Zielvergleich und erneute Sicherheits-
   pruefung erfolgen vor Commit. SQL-Fehler rollen die ganze SQL-Aenderung zurueck.
4. Nach bestaetigtem Commit `committed`, einen deterministischen Auditbeleg
   und abschliessend `complete` schreiben. Wiederholung erzeugt weder eine
   zweite Versionserhoehung noch einen zweiten Auditbeleg.
5. Ein Abbruch vor Commit hinterlaesst gegebenenfalls bereits umgestellte
   Dateien, aber keine teilweise festgeschriebenen Commerce-Zeilen. Derselbe
   gebundene Vorgang kann fortgesetzt oder ausdruecklich auf die originalen
   Dateibytes zurueckgesetzt werden (`rolling_back` -> `rolled_back`). Ein
   unterbrochener Rueckfall wird ebenfalls nur als Rueckfall fortgesetzt.
6. Nach Commit, auch bei verlorener Abschlussantwort, ist ein Dateirueckfall
   verboten. Der vollstaendige DB-Zielzustand entscheidet: Audit/Journal werden
   nachgeholt. Ein gemischter oder fremd geaenderter DB-/Dateizustand stoppt zur
   manuellen Pruefung, ohne automatische Datenkorrektur oder Entsperrung.
7. Nur die namentlich festgelegten eigenen `next-*`-Dateien im gebundenen
   Vorgangsordner duerfen beim Wiederanlauf entfernt werden. Fremde Dateien,
   Manifest, Rueckfallbelege, Sperrmarker und private Konfigurationen bleiben.

Das Laufzeitbudget ist kooperativ auf hoechstens 40 Sekunden begrenzt;
Dateisystem und SQL bilden weiterhin **keine** gemeinsame atomare Transaktion.
Ein einzelner blockierender Betriebssystem-/Treiberaufruf wird dadurch nicht
zwangsweise unterbrochen. Vor Bereitstellung sind reale MySQL-Lock-/Netzwerk-
Timeouts und POSIX-Dateisystemverhalten separat nachzuweisen. Ein Prozessabbruch
ist kein Nachweis gegen Stromausfall oder Verlust noch nicht persistierter
Dateisystem-Verzeichniseintraege.

## Vor einem gesondert freizugebenden Produktionsschreibschritt

1. Der isolierte MySQL-8-/POSIX-Nachweis ist inzwischen bestanden (siehe unten).
   Den noch fehlenden privaten Produktionsaufrufer mit echten Sicherheitschecks,
   Release-/Manifestbindung, begrenzten Treiber-/Lockwartezeiten und gesonderter
   Paketfreigabe fertigstellen. Keine konstanten Testfreigaben uebernehmen.
   Weder Produktionsaufrufer noch Paketanschluss sind bereits aktiviert.
2. Exakten geprueften Release und privaten Plan binden. Vor der Ausfuehrung
   frisches Backup/Restore, aktive TLS-Sitzung, Wartungssperre, unveraenderte
   Quellbytes/Versionen, Bilddateien und ruhende Geschaeftsschreiber bestaetigen.
   Kein Normal-API-PUT oder Publisher als Ersatz fuer die kontrollierte Migration.
3. Nur die gebundenen bestehenden Quellen auf Modell 4 umstellen. Bestehende
   Commerce-Zuordnungen in Version/Hash nachziehen, jedoch kein Produkt neu
   importieren und `sales_model`/Inventar nicht auf gezaehlten Bestand umschalten.
   Dateisystem und MySQL sind keine gemeinsame atomare Transaktion: private
   Wiederanlaufnachweise und weiterhin geschlossene Kaufwege sind Pflicht.
4. Rechtsbundle `cmj-production-legal-2026-10-25-v7` additiv mit exakt dem
   bereits freigegebenen Inhaltshash registrieren. Historische Bundles und
   Bestellsnapshots nicht ersetzen. Eine Runtime-/Website-Umschaltung benoetigt
   ihre eigene passende Freigabe und darf keine Kaufwege oeffnen.
5. Erneut ausschliesslich die bisherigen sechs oeffentlichen Produkte lokal
   abgleichen. Keine automatische Aufnahme von CP-2026-0008/-0009 und kein
   Publish aus diesem Probelauf.

Dieser Ablauf wird erst nach ausdruecklicher Schreibfreigabe ausgefuehrt.
Danach bleiben die reale Mengenzaehlung, versionsgebundene Manifestfreigabe,
kontrollierter Bestandsimport, kompatible Produktions-App/Website und die
separate Shopstartfreigabe offen. Die PC-Artikelverwaltung bleibt test-only.
Kein Rueckschluss auf Stueckzahlen aus Legacy-stock oder Kollektionenverkauf.
Bis zur bestaetigten Menge bleibt das jeweilige Produkt nicht kaufbar.

## Lokale Abnahme der schreibfreien Vorbereitung vom 11. November

- PHP 8.4.23: beide neuen Dateien lintfehlerfrei.
- 104 neue kuenstliche Pruefungen fuer Konvertierung, Wiederholbarkeit,
  Inhaltserhalt, Konflikte, Legacy-Erhalt, Geheimwert-/Pfadausschluss im Bericht
  sowie schreibfreien Dateiaufruf und vollstaendigen Fixture-Cleanup.
- Bestehende Produkt-API: 59 Tests; Bootstrap: 13; Verkaufsfreigabe: 28;
  Produktreparatur: 48; Cutoververtrag: 35; Warenkorbvertraege bestanden.
- 261 Node-Tests bestanden, einschliesslich Rechtsbundle-, oeffentlicher
  Produkt- und Paketvertraege. ESLint und `git diff --check` bestanden;
  gepinnte Abhaengigkeiten: npm-Audit ohne Befund.
- Lokaler Testbuild einschliesslich TypeScript und Exportpruefung bestanden:
  25 statische Seiten; ausschliesslich kuenstliche Testproduktquelle. Kein Upload.
- Bash-Syntax und zusaetzliche Deployment-/Rollback-Mocks bestanden.
  Der optionale PHP-Klickstatistikaufruf aus WSL wurde mangels dortigem PHP
  uebersprungen und anschliessend separat mit lokalem PHP 8.4 erfolgreich
  ausgefuehrt; dies betrifft keine geaenderte Programmdatei.
- Die ersten Node-Aufrufe scheiterten an fehlenden lokalen Testabhaengigkeiten,
  einem falschen Arbeitsverzeichnis und anschliessend der WSL-Sandboxgrenze.
  Nach Installation aus dem unveraenderten Lockfile und korrektem lokalem
  Aufruf bestand die gesamte Wiederholung. Keine Quellcodekorrektur an
  bestehenden Tests oder Produktlogik war dafuer erforderlich.

Keine App-, Runtime-, Workflow-, Website- oder Paket-Allowlist wurde veraendert.
Kein Commit, Push, Merge, Deployment, Entsperren oder Cutover in diesem
Vorbereitungsschritt. Keine erfundenen Ist-Stunden oder Kalenderzusagen.

## Lokaler Abbruch-/Wiederanlaufnachweis vom 12. November

- PHP 8.4.23: vier neue PHP-Dateien lintfehlerfrei; schreibfreie Vorbereitung
  erneut 104 Pruefungen bestanden.
- 421 Adapterpruefungen bestanden: erfolgreicher Lauf, identische Wiederholung,
  exakt erhaltene Fremdfelder/Bestands-/Bestell-/Rechtsdaten, bestehende Modell-
  4-Hinweise und Legacy-Dateien, globale/Draft-Sperren, fremde PDO-Transaktion,
  Fehlerbehandlung, verlorene Sicherheitsfreigabe und kooperatives Zeitbudget.
- Sieben echte lokale Kindprozessabbrueche (Exit 86, ohne Weiterlaufen des
  Adapters) vor/nach Dateiumbenennung, waehrend SQL, vor/nach Commit und beim
  Auditschreiben; alle Wiederanlaeufe bestanden. SQLite schliesst dabei die
  Verbindung und rollt unbestaetigte SQL-Aenderungen zurueck. Zusaetzlich wurden
  dieselben Fehlerpunkte durch Exceptions und konkurrierende SQLite-Schreiber
  getestet. Dies ist kein SIGKILL-/MySQL-/Stromausfallnachweis.
- Dateirueckfall vor DB-Commit, Unterbrechung dieses Rueckfalls und seine
  Wiederholung bestanden. Rueckfall nach DB-Commit, gemischter DB-Zustand,
  manipuliertes Manifest/Journal/Audit, fremde Quelle/Bilder und falsches Ziel
  werden abgewiesen. Eigene verwaiste Teildateien werden entfernt, fremde
  Dateien erhalten. Alle kuenstlichen Testverzeichnisse und Datenbanken wurden
  nach den Tests entfernt.
- Bestehende Regression: Produkt-API 59, Bootstrap 13, Verkaufsfreigabe 28,
  Reparatur 48, Projektionsabgleich 20, Cutover 35, lokaler Bestandsimport 49
  und historische Snapshots 113 Pruefungen bestanden; Warenkorb- und
  Backupvertraege ebenfalls bestanden. Keine Providerverbindung oder echte
  Bestandsaenderung aus diesen kuenstlichen Tests ableiten.
- 261 Node-Tests, ESLint sowie Testbuild/TypeScript/Exportpruefung bestanden
  (25 Seiten, kuenstliche Testproduktquelle). `npm ci --ignore-scripts` nutzte
  das unveraenderte Lockfile. Keine App-/Gradle-Datei wurde geaendert; deshalb
  kein neuer Android-Build oder Geraetetest in diesem Auftrag.
- Historische Wartungskompatibilitaet: 55 API-, 13 Bootstrap-, 39 Sperr- und
  86 Aktivierungs-/Wiederanlaufpruefungen bestanden. Das aktuelle Paket besteht
  ebenfalls seine 59 API-, 13 Bootstrap-, 39 Sperr- und 14 HTTP-Routenpruefungen;
  der Marker bleibt erhalten und die kuenstlichen Verzeichnisse sind bereinigt.
- Bash-Syntax und Deployment-/Rollback-Mocks aus `npm test` bestanden. Der
  optionale PHP-Klickstatistiktest wurde in WSL mangels PHP uebersprungen und
  danach separat mit lokalem PHP 8.4 bestanden. Kein echtes Deployment.
- Ein zunaechst direkt gestarteter Wartungstest verweigerte mangels der
  vorgesehenen kuenstlichen Fixture den Lauf vor jeder Mutation. Die danach
  vollstaendig ausgefuehrten vorhandenen Node-Testtreiber erzeugten und
  bereinigten diese Fixtures korrekt; keine Aenderung bestehender Tests.
- `git diff --check` einschliesslich separat gepruefter neuer Dateien und
  eingegrenzte Geheimwertmusterpruefung ohne Befund. Es wurde nichts gestaged,
  committed, gepusht oder bereitgestellt. Andere Worktrees, `main`, Hosting,
  bestehende Produkte, Bestaende und private Konfigurationen blieben unberuehrt.

### Damalige Nachweisgrenzen vor dem MySQL-/Linux-Auftrag

Lokal sind weder MySQL-Server/-Client noch Docker verfuegbar. Die SQLite-
Fixture belegt die Adapter-Zustandsmaschine, nicht InnoDB, TLS oder echte
MySQL-Deadlocks. Windows-Dateimodi und dort nicht verfuegbare Symlinkerzeugung
ersetzen keinen POSIX-/Rechtenachweis. Fuer diese Punkte bleiben isolierte
kuenstliche MySQL-8-/Linux-Tests erforderlich, ohne bestehende Testziele,
Testprodukte oder private Konfigurationen umzuwidmen. Server-/CI-Ausfuehrung,
Commit, Push und Bereitstellung sind in diesem lokalen Auftrag nicht erteilt.

**Urteil: lokaler Adapter- und Wiederanlaufnachweis bestanden; noch keine
Produktions- oder vollstaendige MySQL-Umstellungsabnahme.**

## Isolierter MySQL-8-/Linux-Nachweis vom 12. November 2026

**Urteil: isolierter MySQL-8-/Linux-Nachweis bestanden.** Dies ist keine
Produktionsumstellung, kein Bestandsimport und keine Freigabe zum Entsperren.

Der neue private Testtreiber `scripts/product-model4-mysql-test.php` verwendet
die bestehenden kuenstlichen Fixture-Helfer. Fuer MySQL wird die wirkliche
`commerce_products`-Definition samt Warenkorberweiterung aus den SQL-Dateien
geladen, nicht eine SQLite-Syntaxuebersetzung. Historische Fremddaten sind
bewusst kuenstliche Kontrollzeilen; eine komplette Schema-/Backupmigration
ist nicht Bestandteil dieses Produktumstellungsnachweises.

### Isolation und Vorflug

- Der bekannte IONOS-Testalias wurde mit Public-Key und Hostkeypruefung benutzt.
  Kein GitHub-Lauf, Commit, Push, Deploymentworkflow oder Produktionszugriff.
- Ausschliesslich das bekannte getrennte Restore-Testziel wurde verbunden.
  Vorher: null Tabellen, Routinen, Events und Trigger; MySQL 8.0.36, InnoDB,
  PHP 8.4.27/Linux und aktive TLS-Sitzung bestaetigt. Die Source-Testdatenbank
  wurde nicht verbunden. Bestehende Testprodukte und Bestaende bleiben unangetastet.
- Zugang nur aus der vorhandenen privaten `shop-cart-test.json`, Modus `0600`.
  Die Datei wurde weder kopiert noch geaendert oder geloescht. Keine Werte in
  Argumenten, Logs, Repository oder Artefakten. Aktive TLS-Sitzung auf jeder
  Verbindung Pflicht; das akzeptierte fehlende CA-/Hostidentitaetszeugnis
  bleibt Restrisiko und wird durch diesen Test nicht als behoben dargestellt.
- Neuer privater, kanonisch gepruefter Testordner ausserhalb aller Webroots;
  exakt elf Programm-/Fixture-/SQL-Dateien per Archivhash gebunden und auf
  dem Server lintgeprueft. Keine aktive API-, Worker- oder Runtime-Datei ersetzt.
- Exklusive Testsperre, eigene DB-Eigentuemerzeile und eindeutige Tabellen-
  Kommentare binden den Cleanup. Unbekannte Objekte werden niemals geloescht.

### Praktische Ergebnisse: 206 Pruefungen bestanden

| Nachweis | Ergebnis |
| --- | --- |
| Echte Datentypen und Transaktion | InnoDB, JSON, TIMESTAMP(6), serverseitige Version/Hash; Commit und identische Wiederholung bestanden |
| Sieben SIGKILL-Punkte | Vor/nach Dateiumbenennung, waehrend SQL, vor/nach Commit, beim Journal/Audit; kompletter Wiederanlauf bestanden |
| Row-Lock-Timeout | Echter InnoDB-Fehler 1205, drei Sekunden Lockwartezeit; Transaktion zurueckgerollt, Wiederholung bestanden |
| Deadlock | Echter InnoDB-Fehler 1213 durch zwei konkurrierende Verbindungen; sicherer Wiederanlauf bestanden |
| Verbindungsverlust | Ausschliesslich eigene Kindprozess-Verbindung vor Commit beendet; Fehler 2006/2013 erkannt, keine Teilbuchung |
| Fehlende Commit-Rueckmeldung | Echte SQL-Buchung, danach gezielt injizierter Rueckmeldungsfehler; Zielzustand erkannt, Dateirueckfall gesperrt, Abschluss nachgeholt |
| Zehn parallele Clients | Gemeinsame Dateisperren; Konkurrenz sicher abgewiesen oder identisch abgeschlossen, Versionen nur einmal erhoeht |
| POSIX-Schutz | Symlinkquelle, zu offene Manifest-/Verzeichnisrechte abgelehnt; echte verweigerte Dateiumbenennung ohne Verlust, danach Wiederanlauf |
| Rueckfall vor Commit | Exakte Originalbytes und Versionen wiederhergestellt; Wiederholung wirkungslos, erneutes Apply mit altem Rueckfallplan gesperrt |
| Erhalt | Preise, Texte, Bilder, bestehende Hinweise/Legacy-Dateien, kuenstliche Bestands-/Bestell-/Legal-/Journalzeilen und Konfigurationen unveraendert |

Der nach echtem Commit injizierte Fehler ist kein behaupteter physischer
Netzwerkabbruch exakt im COMMIT-Antwortpaket. Tatsaechlicher Prozessabbruch
nach Commit und echter Verbindungsverlust vor Commit wurden separat geprueft.
Das kooperative 40-Sekunden-Budget ersetzt weiterhin keinen Stromausfalltest
oder eine harte Grenze fuer jeden blockierenden Betriebssystemaufruf.

### Cleanup und unveraenderte Grenzen

Alle eigenen Tabellen und kuenstlichen Produktdateien wurden entfernt.
Eine anschliessende unabhaengige TLS-Verbindung bestaetigte: Restore-Ziel
vollstaendig leer, keine Routinen/Events/Trigger, Testsperre freigegeben.
Konfigurationsinhalt blieb hashgleich und Modus `0600` erhalten. Danach wurden
die exakt geprueften elf Testdateien, Archiv und Eigentuemermarker entfernt;
der neue Serverordner und das lokale Transferverzeichnis existieren nicht mehr.
Es wurde keine bestehende Datenbank geloescht und kein bestehender Testbestand
bereinigt. Keine temporaeren Benutzer, HTTP-Endpunkte oder Cronjobs angelegt.

Die Produktmodell-Bibliothek selbst blieb fuer diesen Nachweis unveraendert.
Neu ist der isolierte Testtreiber; der gemeinsame Fixture-Helfer unterstuetzt
jetzt zusaetzlich die echte MySQL-Produktdefinition. Die lokalen 104
Vorbereitungs- und 421 Wiederanlaufpruefungen bestehen erneut, ebenso die
35 Cutover-, 49 Import-, 113 historischen und 28 Verkaufsfreigabepruefungen.
Abschlussregression: 261 Node-Tests, Bash-/Rollback-Mocks, ESLint,
TypeScript und Testexport mit 25 Seiten bestanden. Der in WSL fehlende
optionale PHP-Klickstatistikaufruf bestand separat mit lokalem PHP 8.4.
PHP-Lint, Geheimwertmuster- und Diff-Pruefung bestanden. Ein direkter Aufruf
des neuen Testtreibers ausserhalb seines gebundenen Linux-Testordners wird
vor Konfigurationszugriff/Mutation abgelehnt. Ohne expliziten Testmodus laeuft
er nicht automatisch. Keine Abhaengigkeits-/Lockfileaenderung, kein App-Build.

Zum Zeitpunkt dieses MySQL-Nachweises offen vor jeder Produktionsaenderung: echter privater Produktionsaufrufer,
Release-/Manifestbindung, aktueller Backup-/Restorebeleg und ausdrueckliche
Schreibfreigabe. Mengenzaehlung, Mengenimport, Rechtsbundle-Umschaltung,
App-/Websitewechsel und Shopoeffnung bleiben getrennte nachgelagerte Gates.

## Folgestand: Produktionsaufrufer lokal implementiert

Der danach separat beauftragte private Aufrufer ist jetzt lokal vorhanden und
mit 389 neuen kuenstlichen Pruefungen abgenommen. Er prueft echte Dateien,
Freigaben und Datenbankzustaende, bindet den geprueften Programmstand und
verlangt einen authentifizierten Restorebeleg gegen den Originalplan.
Ein getrenntes reines Codeartefakt ersetzt keine aktiven Programme. Der
Adapter erhaelt eine schreibfreie Zustandsinspektion und einen zusaetzlichen
Pruefpunkt unmittelbar vor Commit fuer den Guardverbindungsnachweis; die
Umstellungsfelder und Rueckfallgrenzen bleiben unveraendert.

Der neue Zwei-Verbindungs-Guard und die Restorepruefung benoetigen vor
Bereitstellung noch ihren eigenen isolierten MySQL-/Linux-Integrationsnachweis.
Keine Uebertragung der alten 206 Adaptertests auf ungetestete neue Verdrahtung.
Kein Serverzugriff, Produktionslauf, Commit, Push oder Deployment.
Vertrag, lokale Tests und Folgegates:
[Privater Modell-4-Produktionsaufrufer](product-model4-production-caller.md).
