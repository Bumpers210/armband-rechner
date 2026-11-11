# Vorbereitung der Warenkorb Produktionsbereitstellung

Stand: 11. November 2026. Basis ist der nach PR #96 zusammengefuehrte und
remote bestaetigte main-Commit `bb4cf04abf4297d782b15cf9a54311e12f542da0`.
Die lokale Vorbereitung liegt isoliert auf `codex/cart-release-preparation`.
Sie gibt weder Deployment noch Entsperrung, Produktveroeffentlichung,
Bestandsimport, Shopstart oder Cutover frei.

## Ergebnis und Paketgrenze

Der aktuelle main-Paketbau haette die separat installierte Wartungssperre beim
Austausch des privaten Programmverzeichnisses verloren. Der lokale Kandidat
nimmt deshalb den vorhandenen `product-maintenance-guard.php` in seine
Dateiliste auf und wendet dessen bereits getestete Einbindung auf den
aktuellen Bootstrap sowie die Produktprogramme v1, v2 und v3 an. Die
historische V4-Versionskorrektur wird nicht erneut angewendet. Die gemeinsam
genutzten Quelldateien der Test-API bleiben unveraendert.

Das Paket enthaelt 41 Dateien plus Manifest. Der Marker selbst gehoert
ausdruecklich nicht zum Paket und muss bei jeder spaeteren Bereitstellung
erhalten bleiben:

```text
/home/www/carmaja-private-shop/maintenance/product-cleanup.lock
```

Produktmutationen, Publisher und neue HTTP-Checkouts bleiben damit vor ihren
Seiteneffekten gesperrt. Vorhandene Zahlungsstatusabfragen, Webhooks und
Widerrufe bleiben von dieser Sperre ausgenommen. Die zusaetzlichen
Runtime-/Deploymentgates werden weder ersetzt noch auf true gesetzt.
Unbekannte Quellanker brechen den Paketbau ab, statt den Schutz still
wegzulassen. Die vorhandene Runtime darf nicht durch eine Vorlage ersetzt werden.

## Unveraenderte Produkte und private Ressourcen

Die oeffentliche Quelldatei enthaelt weiterhin genau CP-2026-0002 bis
CP-2026-0007, sechs Produkte mit insgesamt 16 Bildern auf Modell-/
Projektionsversion 3. Produktdatei, Bilder, Preise, Texte, Verkaufsflags und
Versionen werden in dieser Vorbereitung nicht bearbeitet.
CP-2026-0008 und CP-2026-0009 bleiben ausserhalb des oeffentlichen Exports.
Auch eine spaetere V5-/Modell-4-Umstellung darf sie nicht automatisch publizieren.

Ausgeschlossen von jedem Programm-/Websiteartefakt sind Runtime, Auth- und
Geraetedateien, private Produktdaten, Zugangsdaten, vendor, Backups,
Wartungsmarker und Reparatur-/Rueckfallbelege. Bestehende Testprodukte,
Bestandsdaten und private Konfigurationen werden nicht gelesen oder veraendert.
Der lokale Build ist keine Bestaetigung des aktuellen Serverzustands.

## Lokale Pruefung

Der Pakettest verwendet die tatsaechlich erzeugten aktuellen Programmbytes
in einem neuen lokalen temporaeren Verzeichnis. Bei fehlendem Marker laufen
59 Produkt-API- und 13 Bootstrap-Pruefungen. Mit kuenstlichem Marker pruefen
39 direkte Schreib-/Publish-Gates und 14 zusaetzliche aktuelle Routen die
Sperre einschliesslich V5 und Shop-v3. Der Marker bleibt waehrend dieser
Pruefung unveraendert; danach wird ausschliesslich die kuenstliche Fixture
entfernt. Keine Serverkonfiguration, MySQL-Verbindung oder Provideraktion.

Reproduzierbar mit explizitem lokalem PHP-8.4-/Erweiterungspfad in
`CARMAJA_TEST_PHP` und `CARMAJA_TEST_PHP_EXT`, vom Repository-Stamm:

```text
node website/scripts/test-production-cart-maintenance.mjs
```

Der Build-only-Paketworkflow fuehrt diesen Nachweis kuenftig ebenfalls aus.
Seine Berechtigungen, Trigger und fehlenden Deployment-/Secretzugriffe bleiben
unveraendert. Ein lokaler Kandidat mit `workingTreeDirty=true` ist kein
freigegebener Release; nach separatem Commit und Integration muss er aus dem
sauberen, CI-geprueften Commit neu gebaut werden.

### Abschliessende lokale Ergebnisse

| Pruefung | Ergebnis |
| --- | --- |
| PHP 8.4.23 | 39 Suiten ohne Fehler; die beiden POSIX-Rechtepruefungen bleiben unter Windows explizit ausgenommen und sind kein neuer Servernachweis |
| Aktuelles Paket unter Wartung | 59 API-, 13 Bootstrap-, 39 Schreib-/Publish- und 14 Routengates bestanden; Fixture-Cleanup bestaetigt |
| Historische Hotfix-Kompatibilitaet | Ursprungsfehler kuenstlich reproduziert; danach 55 API-, 39 Sperr-, 86 Aktivierungs-/Unterbrechungs- und 13 Bootstrap-Pruefungen bestanden |
| PHP-Lint | Alle 32 PHP-Dateien des Pakets und die neue Testfixture bestanden |
| Node und Shell | 261 Node-Tests, Bash-Syntax und saemtliche Deployment-Mocks bestanden; kein echter Deploy |
| Klickstatistik | Separater Windows-PHP-Test bestanden; optionaler Aufruf aus WSL mangels dortigem PHP uebersprungen |
| Website | ESLint, TypeScript, Testexport mit 25 Seiten und kuenstlicher Produktionsbuild mit 26 Seiten bestanden |
| Lokaler Produktionswebsitebuild | 31 Seiten; bereinigter statischer Export mit 188 Dateien, sechs Produkten und 16 Bildern geprueft |
| Oeffentliche Ausschluesse | Keine CP-2026-0008/-0009, Testdomains oder Vinted-Verweise im statischen Export; Produktquelle gegen HEAD unveraendert |
| Paket | 41 Dateien plus Manifest; deterministische Erzeugung, Manipulationsschutz, exakte Verifikation und private Include-Vollstaendigkeit bestanden |
| Sicherheit | npm-Audit: 0 Befunde; Workflow-YAML, Quell-/Geheimwertpruefung und `git diff --check` bestanden |
| GitHub nur lesend | main weiterhin auf obigem SHA; alle sechs abgefragten Repository-/Umgebungs-Deployment-/Publishschalter false |

Der erste Lauf des neuen Testhelfers erreichte dessen zunaechst zu knappes
120-Sekunden-Limit. Ausschliesslich das lokale Suite-Limit wurde auf zehn
Minuten erhoeht; die vollstaendige Wiederholung bestand. Fachliche Zeitgrenzen
wie das 40-Sekunden-Workerlimit bleiben unveraendert. Beim Exportvergleich
wurden die normalen Git-CRLF-Zeilenenden beruecksichtigt; keine Produktdatei
wurde dafuer umgeschrieben.

Nur erzeugte Hostingkopien wurden aus dem lokalen statischen Export entfernt,
entsprechend der bestehenden Workflow-Allowlist. Originale unter `hosting/`
blieben unveraendert. Beide lokalen Artefakte liegen ignoriert unter
`website/.production-cart-package/` und `website/out/`. Der Websiteexport
besitzt bewusst noch keine finale CI-Releasebindung und darf ebenso wie der
dirty-Paketkandidat nicht bereitgestellt werden.

Android, Gradle und Signierung blieben unveraendert. Echte MySQL-/Provider-/
IONOS-Nachweise wurden nicht wiederholt; die vorherigen Abnahmen werden nicht
als neuer Produktionspreflight ausgegeben. Kein neuer CI-Lauf, Commit, Push,
Merge oder Produktionszugriff erfolgte.

## Verbindliche Reihenfolge der spaeteren Freigaben

1. **Lokalen Kandidaten sichern und pruefen:** separate Freigabe fuer Commit,
   Branch-Push und PR; Build-only-CI abwarten. Merge nach main separat freigeben.
   Danach finalen main-SHA und alle Paketpruefsummen binden. Keine automatische
   Bereitstellung durch PR, Merge oder Artefaktbau.
2. **Produktionspreflight nur lesend erneuern:** festgelegte Pfade, aktuelle
   Programmstaende, beide getrennten Datenbankziele, MySQL 8/InnoDB, aktive
   TLS-Sitzung, Schemajournal, Runtime-Vollstaendigkeit, Sperrmarker, Provider,
   Legal Bundle und Worker pruefen. Keine Secrets ausgeben oder suchen. Die
   akzeptierte fehlende CA-/Hostidentitaetspruefung bleibt ein V1-Restrisiko;
   eine unverschluesselte Sitzung bleibt ein harter Fehler.
3. **Laufende Geschaeftsvorgaenge abschliessen:** bestehende Sperren erhalten,
   neue Checkouts geschlossen lassen und offene Zahlungen einschliesslich SEPA
   bis zum bestaetigten Endzustand weiterverarbeiten. Alte Reservierungen nicht
   durch Zeitablauf oder Browserabbruch freigeben. Aktive Bestands-/Adminschreiber
   vor dem Import gesondert sperren; der Produktmarker allein sperrt nicht alle
   Adminbestandsaktionen. Keine bestehenden Sitzungen oder Konfigurationen loeschen.
4. **Frisches Backup und Restore:** korrigierten Backupdienst nur nach eigener
   Bereitstellungsfreigabe aktivieren. Verschluesseltes Vollbackup, vollstaendigen
   Offsite-Abruf/Quittierung und Restore im getrennten Ziel nachweisen. Struktur,
   Inhalt, Pruefsummen und private Produktdateien vergleichen; Restore-Ziel und
   temporaere Dateien bereinigen. Runtime, Schluessel und bisherige Backups erhalten.
5. **Kompatibles privates Paket und Vorwaertsmigration:** vor Schreibfreigabe
   den exakten Rollout-/Rueckfallablauf an das aktuelle Journal binden. Kein
   Worker darf waehrend inkompatibler DDL laufen. Nur ausstehende Migrationen
   ausfuehren; bekannte AP6-Journalzeilen, Pruefsummen und Zeitstempel niemals
   ersetzen oder mit der neuen Basisdatei erneut initialisieren. Keine zusaetzliche
   AP3b-Migration. MySQL-DDL ist nicht als Gesamtvorgang atomar; bei Teilfehler
   stoppen und den belegten Wiederanlauf-/Restoreweg verwenden. Programmwechsel
   muss Runtime, vendor, Auth, Backups, Produktdaten und `maintenance/` erhalten.
6. **Produktmodell und Rechtsbindung vorbereiten:** v7 mit dem exakten
   freigegebenen Hash zusaetzlich registrieren, historische Bundles und
   Bestellsnapshots erhalten. Die heutigen Modell-3-Produktquellen sind nicht
   bereits als Modell 4 abgenommen. Dafuer ist ein gesonderter, kuenstlich
   getesteter und versionsgebundener Umstellungs-/Dry-Run-Nachweis erforderlich.
   Kein automatisches Speichern/Publizieren aus der oeffentlichen JSON-Datei;
   keine nachtraeglichen Produkthinweise fuer bestehende Produkte.
7. **Mengen zaehlen und Manifest binden:** tatsaechliche Mengen manuell bestaetigen;
   Quellen, serverseitige Hashes/Versionen, Inventarversionen, Backup und Restore
   abgleichen. `selectedProducts` bleibt bis dahin leer und das Manifest
   `not_approved`. Keine Schaetzung aus `stock` oder Kollektionenverkauf und
   keine oeffentliche Aufnahme von CP-2026-0008/-0009.
8. **Getrennt freigegebener Mengenimport und fail-closed Gesamtwechsel:** erst
   nach Manifestfreigabe importieren, vergleichen und idempotente Wiederholung
   pruefen. API, Worker, Website und stabile Produktions-App muessen kompatibel
   sein; die Signatur-/Updatepruefung fuer App 1.4.0 / Code 8 bleibt ein eigenes
   Gate. Kein neuer Shop-v3-Websitebuild gegen die alte Produktionsruntime.
   Privaten Programmwechsel und danach den oeffentlichen API-/Websitewechsel
   jeweils mit Hashen, Rueckfallkopie und geschlossenen Kaufwegen pruefen.
   Worker/Cron, Mail, Webhooks und Monitoring ohne neue Kundenbestellung pruefen.
   Die PC-Artikelverwaltung bleibt test-only.
9. **Vor dem Shopstart erneut stoppen:** Publisher-/Schreibfreigaben und
   Checkoutoeffnung erst nach ausdruecklichem Auftrag und dokumentierter
   Gesamtpruefung. Kontrollierte echte Bestellung samt Mengenabbuchung,
   Versandkosten, Rechtssnapshot und Mail gehoert erst in diese spaetere Freigabe.

## Stop und Rueckfall

Sofort stoppen bei unbekanntem Ziel, fehlendem TLS, unvollstaendigem Backup/Restore,
offener Altzahlung, fehlendem Wartungsmarker, unbekannter Menge oder
Versions-/Hash-/Journalabweichung. Gleiches gilt bei unklaren Migrationsteilen,
abweichendem Legal Bundle und fehlendem Worker-/Mail-/Monitoringnachweis.

Ein Programm-/Website-Rueckfall darf Runtime, Wartungsmarker und Commerce-Daten
nicht ueberschreiben. Nach einer Schemamigration nur einen dazu kompatiblen
Programmstand einsetzen; ein Datensicherungs-Restore ist eine eigene,
ausdruecklich freizugebende Aktion. Nach dem ersten bestandswirksamen
Warenkorbcheckout kein Rueckwechsel zum unbegrenzten Kollektionenverkauf.
Bei Zahlungsunklarheit neue Checkouts geschlossen halten, bestehende Zahlungen
aber weiterhin sicher verarbeiten.

## Aktueller naechster Schritt

Den lokal geprueften Sperrerhalt in einem eigenen Commit/PR durch CI bringen.
Erst nach dessen separater Zusammenfuehrung kann der erneute lesende
Produktionspreflight fuer den finalen Releasecommit freigegeben werden.
Keine neuen Ist-Arbeitsstunden oder Kalenderzusagen werden daraus abgeleitet.
