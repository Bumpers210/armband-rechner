# Lokales Produktionspaket: Mengenbestand und Warenkorb

Stand: 10. November 2026. Basis: zuletzt bestaetigter `origin/main`/PR #92,
`f7b5604c7b59312667598ad4c60d4e2a7770f4e6`.
Isolierter Branch: `codex/cart-production-package`.
Nur lokale Vorbereitung, keine Produktions-, Deployment- oder Cutoverfreigabe.
Die separate lokale Referenz `main` steht noch auf dem historischen `8a5e9ff`;
sie wurde weder verwendet noch aktualisiert. Fuer diesen Kandidaten zaehlt
ausschliesslich der oben angegebene PR-92-Commit.

## Inhalt und abgeschlossene Korrekturen

- Explizite Dateiliste fuer den kombinierten privaten Produkt-V5-/Shop-v3-/
  Admin-/Webhook-/Workerbetrieb; oeffentlich ausschliesslich `index.php` und
  `.htaccess`. Keine Laufzeitkonfigurationen, Zugangsdaten, Produkte, Bilder,
  Authdateien, Backups, Stripe-vendor-Dateien oder Test-Adminprogramme im Paket.
- Versionsvertrag: Modell 4, Produkt-API `/v5`, Projektion 4, Shop `/shop/v3`,
  Mengenbestand; maximal zehn Armbaender insgesamt, 100 Positionen als
  zusaetzliche Formatgrenze, einmalig 270 Cent Versand, Karte/Klarna/SEPA.
- Backupkorrekturen aus `9b58d69` und `b4e9d3f`: konsistente erlaubte
  Publisherzustandskombinationen und `utf8mb4` fuer Dump-/Restoreclients.
  Verschluesselung, TLS-Pflicht, Fristen und Aufbewahrung bleiben unveraendert.
- Neue getrennte Sperre `shopCheckoutEnabled=false`. Ohne ausdrueckliches
  `true` sind neue Produktionscheckouts geschlossen, bevor Reservierung oder
  Stripe-Aufruf erfolgen. Bestehende Testkonfigurationen bleiben kompatibel.
  Worker, Webhooks, Zahlungsstatus und Widerruf werden dadurch nicht gestoppt.
- Produktionsvorlage und Deploymentvertrag halten alle Freigaben auf false.
  Der alte Sieben-Dateien-API-Deployworkflow stoppt vor jedem Build/Upload;
  er darf das neue kombinierte Paket nicht ueberschreiben. Automatische
  Publisher-Websitebereitstellung ist ebenfalls vertraglich gesperrt.
- Build-only-Workflow `Validate production cart package`: Tests, Audit,
  kuenstlicher Websitebuild, PHP-Lint, exakte Datei-/SHA-Pruefung und
  Artefaktupload; keine Secrets, Serververbindung oder Deploymentjobs.
- Mengenmanifest v3 ersetzt die historische Kollektionenfreigabe. Vorlage
  ist leer und `not_approved`; keine Schaetzung oder Uebernahme von alten
  `stock`-Werten. Alte Kollektionen-CLI kann nicht mehr `apply` ausfuehren.

## Rechtstexte: nachvollziehbare technische Ueberfuehrung

Die bereits extern freigegebene Prueffassung bleibt unveraendert erhalten.
Nur die neue technische Fassung ist `approved`; Texte, ID, Version,
Archivadresse und Dokumentdatum sind identisch. Der Status ist hashwirksam:

| Fassung | SHA-256 |
| --- | --- |
| Prueffassung v7 | `55b56a713077bf4697341392d1676c57667a198d371ab8e6f39b03e75a6c6501` |
| Technische approved-Fassung v7 | `ed7713ce8d6c9f16b320f1376303df7f38c306177793a9c5beeb548b9455d752` |

ID: `cmj-production-legal-2026-10-25-v7`.
Die Produktionsbuildauswahl ist lokal darauf vorbereitet. Auf dem Server
wurde nichts aktiviert; historische Bundles/Bestellsnapshots bleiben erhalten.
Keine neue Rechtsfreigabe durch den Agenten.

## Paket lokal bauen und kontrollieren

Im Verzeichnis `website`, mit einem noch nicht vorhandenen Zielordner:

```text
node scripts/prepare-production-cart-package.mjs build .production-cart-package
node scripts/prepare-production-cart-package.mjs verify .production-cart-package
node scripts/check-production-cart-build.mjs
```

Der dritte Befehl benoetigt einen noch nicht vorhandenen lokalen `out`-Ordner.
Er verwendet ausschliesslich kuenstliche Produkte und entfernt seinen
Produktions-Testexport wieder. Er erzeugt kein deploybares Websiteartefakt.

`manifest.json` bindet Quellcommit, alle 40 Dateien, Dateigroessen, SHA-256,
Legal Bundle und SQL-/Journalsummen. Symlinks, zusaetzliche, fehlende oder
veraenderte Dateien sowie das Ueberschreiben eines bestehenden Pakets werden
abgelehnt. Uncommittete Arbeit wird als `workingTreeDirty=true` gekennzeichnet.
Ein solches Paket ist nur ein lokaler Pruefkandidat, kein finaler Release.
Nach einem separat freigegebenen Commit muss es sauber neu gebaut werden.
Auch ein sauberer Build erteilt keine Deployment-/Migrationsfreigabe.

## Bestandsadapter und Grenzen der lokalen Nachweise

`cart-cutover.php plan <manifest.json> <products.json> <release-manifest.json>`
prueft offline und schreibt nichts. Der getestete Serviceadapter
`CarmajaCartCutover::importCounts` ist fuer eine spaeter gesondert freigegebene
Operatorausfuehrung vorbereitet; die CLI besitzt bewusst keinen allgemeinen
`apply`-Befehl. Kein automatischer Import durch Bootstrap, Worker oder CI.

Der Adapter fordert Freigabestatus, sauberen Releasecommit, Backup-ID,
Restore-Nachweisbindung, gezaehlte Mengen, Produktversion/serverseitig
neu berechneten Hash, Inventarversion und eindeutige Operationsschluessel.
Er prueft Schemajournal, leere alte Zahlungs-/Reservierungsrueckstaende,
freigegebenes Legal Bundle und ausstehende Anfangsbestandskonflikte.
Alle Mengen/Audits/Projektionen werden zusammen transaktional importiert.
Wiederholung verdoppelt nichts und setzt spaetere Bestandsaenderungen nicht
zurueck. Autoritative Produktdateien und Verkaufsfreigaben werden nicht geaendert.

Der Aufrufer muss vorher aktive MySQL-TLS-Sitzung, InnoDB, den konkreten
Restore-Nachweis und private Wartungssperre nachweisen. Ein eingetragener
Nachweishash allein ist kein bestandener Restore. Produkt-/Adminschreiben
muessen fuer den kurzen Import wirklich ruhen; deaktivierte V4/V5-Schalter
allein ersetzen keine Sperre saemtlicher Legacy-Schreibwege.

Lokaler Transaktionsnachweis verwendet einen eng begrenzten SQL-Adapter auf
einer fluechtigen In-Memory-Datenbank. Das beweist Rollback/Idempotenz/
Anwendungsgrenzen, aber weder MySQL-DDL, echte Row-Locks noch Provider/TLS.
Die neue Paket-/Importkombination benoetigt vor Produktion ihren eigenen
kuenstlichen MySQL-Test-/Restorelauf. Bestehende abgenommene Warenkorbtests
bleiben gueltig, ersetzen diese abschliessende Paketpruefung aber nicht.

## Verbindliche spaetere Reihenfolge – derzeit nicht ausgefuehrt

1. Lokalen Stand reviewen, nach Freigabe committen/pushen, CI und sauberes
   SHA-gebundenes Paket pruefen. Main-Merge separat freigeben.
2. Kuenstlichen Paket-/Migrations-/Import-/Backup-/Restoretest isoliert
   nachweisen. Bestehende Testprodukte und private Konfigurationen erhalten.
   Stabile Produktions-App 1.4.0 / Code 8 bauen und Signatur/Updatepfad pruefen.
3. Produktions-Preflight separat freigeben: Pfade, aktive TLS-Sitzung,
   vorhandene Runtime, Provider, Legal Bundle, Schemajournal und Backups.
   Keine Testschluessel oder Testdaten uebernehmen.
4. Neue Checkouts kontrolliert sperren; alte Zahlungen einschliesslich SEPA
   bis zum bestaetigten Endzustand weiterverarbeiten. Keine Reservierungen
   wegen Zeitablaufs oder Browserabbruch freigeben.
5. Korrigierten Backupdienst gesondert bereitstellen. Frisches verschluesseltes
   Backup, OneDrive-Abruf/Quittierung und Restore in getrenntem Ziel bestehen.
   Produkt-/Adminschreiben kurz pausieren und versionsgebunden inventarisieren.
6. Paket hashgeprueft privat stufen; Runtime, vendor, Auth, Produkte und Backups
   nicht kopieren, loeschen oder ueberschreiben. Nur ausstehende Migrationen
   anhand des echten Journals anwenden: v1, AP4, AP5, collections, cart.
   Die alte AP3b-Zusatzmigration niemals daneben anwenden. DDL ist nicht atomar:
   bei Teilfehler sofort stoppen, nicht blind wiederholen. Vorherigen Stand
   mit Restore-Nachweis und explizitem Wiederherstellungsplan absichern.
7. v7 mit exaktem approved-Hash registrieren. Tatsaechliche Produktmengen
   zaehlen/bestaetigen, autoritative Modell-4-Quellen und oeffentliche
   Projektion vergleichen; Manifest erst danach exakt freigeben.
8. Mengenadapter mit gesperrten Schreibwegen ausfuehren und Ergebnis/Audit
   vergleichen. Nicht bestaetigte Produkte bleiben nicht kaufbar. V5-App,
   API, Worker, Website und Publisher kontrolliert kompatibel umstellen.
   Kaufweg bleibt dabei geschlossen; Test-Browserverwaltung bleibt test-only.
9. Erst nach ausdruecklicher Freigabe neue Checkouts oeffnen; kontrollierte
   echte Bestellung, Zahlung, Mengenabbuchung, Mails und Beobachtung pruefen.

Stopkriterien: falsche Datenbank/Umgebung, fehlendes TLS, kein aktueller Restore,
offene Zahlungen, unbekannte Bestandsmenge, Versions-/Hash-/Journalkonflikt,
fehlende Worker-/Mail-/Monitoringfunktion oder nicht uebereinstimmende Bundles.
Fehlerbetrieb: neue Checkouts schliessen, laufende Zahlungen sicher abarbeiten.
Nach erstem Mengenverkauf kein Rueckwechsel zum unbegrenzten Kollektionenverkauf.
Ein Website-Rollback veraendert niemals Commerce-Daten.

## Nicht in dieser lokalen Aufgabe veraendert

Main, entfernte Branches, GitHub-Schalter, Test-/Produktionsserver, reale
Produkte, Mengen, private Konfigurationen, Cronjobs und laufende Zahlungen.
Die PC-Artikelverwaltung ist weiterhin ausschliesslich fuer die Testumgebung
freigegeben; ein produktiver Browser-Authentifizierungsvertrag ist ein
eigener Folgeauftrag, kein stiller Bestandteil dieses Shop-Releases.

## Lokale Abnahme am 10. November 2026

| Pruefung | Ergebnis |
| --- | --- |
| Vollstaendige Node-Suite | 254 bestanden; betroffene Paket-/Legal-/Backupvertraege anschliessend erneut bestanden |
| Shell-Syntax und Deployment-Mocks | bestanden in vorhandener Linux-Testumgebung; kein echter Deploy |
| PHP 8.4.23 | alle 36 lokalen Testsuiten bestanden; abschliessende betroffene Suiten erneut bestanden |
| Neue Freigabe-/Importpruefungen | 35 Offline-Vertragspruefungen, darin zwei Dienststarts ohne PDO-/Stripe-Zugriff; 14 zusaetzliche fluechtige SQL-Adapterpruefungen |
| PHP-Lint | geaenderte/neue PHP-Dateien und 31 PHP-Dateien im Paket bestanden |
| Website | ESLint, TypeScript und Testexport (25 Seiten) bestanden |
| Kuenstlicher Produktionsbuild | 26 Seiten, Export-/Domain-/Rechtstextpruefung bestanden; Export danach entfernt |
| Abhaengigkeiten | npm-Audit mit und ohne Entwicklungsabhaengigkeiten jeweils 0 Befunde |
| Workflows | YAML und lokale Sicherheits-/Triggervertraege bestanden; kein GitHub-Lauf gestartet |
| Paket | 40 Dateien plus Manifest, deterministische Integritaets-/Manipulationspruefung bestanden |

Der erste Windows-Shelllauf hatte drei plattformspezifische Dateirechtefehler;
unveraendert unter der vorhandenen Linux-Testumgebung bestanden. Beim neuen
Produktionsbuildtest wurde eine falsche Testannahme korrigiert: Next erzeugt
fuer die gesperrte Artikelverwaltungsroute eine Nicht-gefunden-Seite statt
gar keiner Datei. Der Test prueft nun diese Seite und das fehlende Loginformular.
Android-/Signierungstests wurden nicht wiederholt, da App und Gradle unveraendert
sind; der spaetere stabile Produktionsbuild bleibt ein eigenes Releasegate.

### PR-/CI-Fortsetzung am 10. November 2026

Auf ausdruecklichen Auftrag wurde der lokale Stand als `e7d73dc` gesichert,
nur `codex/cart-production-package` gepusht und PR #93 ohne Auto-Merge erstellt.
API- und Website-CI bestanden. Die neue Paket-CI machte eine fehlende
Testvorbereitung sichtbar: Linux-Dateirechtetests verlangen den festen
IONOS-aehnlichen privaten Pfad, der auf dem GitHub-Rechner noch nicht existiert.
Der Workflow erzeugt ihn deshalb ausschliesslich auf einem kurzlebigen
GitHub-gehosteten Linux-Runner, lehnt vorhandene Verzeichnisse/Symlinks ab
und entfernt den leeren Fixtureordner per EXIT-Trap. Keine Testauslassung,
keine Aenderung an Installations-/Shoplogik und kein IONOS-Zugriff.
Der Korrekturcommit loest eine neue vollstaendige CI aus; deren Ergebnis
wird im PR festgehalten. Die vollstaendige PHP-Regression bestand im zweiten
Lauf; das abschliessende `rmdir` benoetigt wie die Anlage `sudo`, weil das
Elternverzeichnis dem Runner-Administrator gehoert. Der Cleanup bleibt auf
genau den neu angelegten, leeren Fixtureordner beschraenkt (nicht rekursiv).
Keine Deployment- oder Mergefreigabe.
