# Kontrollierter Warenkorb-Shopstart

Dokumentationsstand: 14. November 2026; Shop-Abnahme und App-Veroeffentlichung:
13. November 2026. Auftrag: autonome Schritte bis zur Veroeffentlichung,
ohne Sicherheitsgates zu umgehen. Personenbezogene Nachweisinhalte, private
Konfigurationen und Zugangsdaten sind nicht Bestandteil dieser Dokumentation.

Aktueller main-, CI- und Releasebezug:
[Nachtrag vom 14. November](#main-und-app-release-stand-vom-14-november-2026).
Die folgenden datierten Zwischenstaende bleiben unveraenderte Historie und
sind keine Behauptung einer erneuten Produktionspruefung am 14. November.

## Bestaetigte Schritte

- PR #103 nach aktueller gruener CI integriert: main
  `7900bc35715b3f4c77e0ccdf5c2bb5e3d7c3992f`.
- Native MySQL-8-/Linux-Pruefung: 36 Checks mit TLS, UTC, atomarem Mengenimport,
  Row-/Range-Locks, Timeout/Deadlock, harten Prozessabbruechen, Wiederanlauf,
  idempotenter Wiederholung und authentifiziertem Backup/Restore. Tests nur mit
  kuenstlichen Daten; danach beide Testziele und Artefakte bereinigt.
- [Native CI](https://github.com/Bumpers210/armband-rechner/actions/runs/43401105709)
  und [vollstaendige main-Paketregression](https://github.com/Bumpers210/armband-rechner/actions/runs/43402234284)
  bestanden. Keine automatischen Deployments.
- Privater Mengenimportaufrufer: exakte 12-Dateien-Allowlist, SHA-Bindung,
  PHP-8.4-Lint und atomare Bereitstellung. Keine oeffentliche HTTP-Route.
- Produktions-Legal-Bundle `cmj-production-legal-2026-10-25-v7` registriert und
  Runtime darauf gebunden. Vorherige Bundles bleiben erhalten. Separater
  Browserzugang explizit gesperrt; uebrige Einstellungen unveraendert.
- Frisches Produktionsbackup extern heruntergeladen, Groessen und Hashes
  geprueft und erst danach quittiert. Echter Restore im getrennten leeren Ziel
  mit Vergleich von 38 Tabellen, 23 Produktdateien und 76 referenzierten Bildern;
  Restoreziel und temporaere Restoredateien anschliessend wieder leer.
- Ares CP-2026-0002: vier Stueck initial importiert, null reserviert, vier
  verfuegbar. Atomarer Audit-/Versionsnachweis und Vollvergleich bestanden.
  Read-only-Inspect bestaetigt Abschluss; kein erneuter Import.
- Die erste Vorbereitung wurde wegen einer zwischenzeitlichen Aktualisierung
  von `worker_leases` abgelehnt. Es wurde kein Vergleichsfeld ausgenommen:
  frischer gebundener Stand zwischen Workerlaeufen bestand unveraendert streng.
- Lesender Live-Providercheck: Stripe SDK/API/Webhookversion passen; aktiver
  Live-Endpunkt besitzt exakt die neun benoetigten Ereignistypen. Karte, Klarna
  und SEPA sind verfuegbar, Zahlungen im Konto aktiviert. Brevo bestaetigt den
  konfigurierten Absender als aktiv. Keine Zahlung oder Mail dabei ausgeloest.
- Lokaler Katalogtest: sechs erlaubte Produkte, 16 unveraenderte Bilder,
  Projektionsversion 4. Produktionsbuild mit 31 Seiten und 48 kleinen
  Vorschaubildern sowie ESLint bestanden. 274 Node-Tests ohne Fehler; der
  Testexport ist darin eingeschlossen. Die anfangs parallel gestarteten Builds
  kollidierten am Next-Buildlock; die serielle Wiederholung bestand. Der
  historische Katalogausschlusstest erwartet jetzt ausdruecklich Modell 4
  und prueft weiterhin die ausgeschlossenen Produkte und Legacy-Felder.
- PR #104 nach bestandenen Website- und vollstaendigen Paketpruefungen
  integriert. Aktueller main-/Website-Stand:
  `b3167b9babf27bdc9fa547cfbfcde8d0b3fb8e66`.
- SHA-gepinntes manuelles Website-Deployment ausgefuehrt. Build und
  Deployment-/Smoke-Job bestanden; Serverstatus `verified`. Unabhaengiger
  Vollvergleich: 236 ausgelieferte Dateien und 169 gesicherte Rollbackdateien
  stimmen mit ihren jeweiligen Manifesten ueberein.
- 20 oeffentliche Seiten nach Deployment per HTTPS geprueft: Startseite,
  Uebersicht, sechs Produktdetails, Warenkorb, Verwaltungs-/Adminhuelle,
  Erfolgs-/Abbruch-/Fehlerseite, fuenf Rechtsseiten und Legal-v7-Archiv.
  Produkt-IDs/-Versionen passen; keine Testdomain, Vinted-Verweise oder
  ausgeschlossenen CP-2026-0008/-0009. Aktive Bedingungen ohne PayPal und
  Archiv mit freigegebenem v7-Inhaltshash. HTTP-Pruefungen sind kein Ersatz
  fuer die noch ausstehende echte Bestellung oder einen Verwaltungslogin.
- Warenkorb-Lesevertrag produktiv vorbereitet (`inventoryCartEnabled=true`),
  Kaufweg weiterhin geschlossen. Live-API fuer alle sechs Produkte,
  Shopsitzung, sichere Cookies, CORS, Fremd-Origin-Ablehnung und `no-store`
  geprueft. Checkoutversuch bleibt `503 checkout_maintenance`; keine neue
  Session bei Stripe, Reservierung, Bestellung, Zahlung oder Mail.
- Abschliessender lesender Servercheck: Checkout-/Produkt-Schreib-/Browser-/
  Publishergates geschlossen, Wartungsmarker vorhanden, Runtime `0600`,
  beide Datenbankverbindungen mit aktiver TLS-Sitzung, Restoreziel leer.
  Geschaeftsdatensatzanzahlen unveraendert; weiterhin ausschliesslich eine
  Inventarzeile fuer Ares mit vier Stueck und Inventarversion 1.
- Eigene lokale Einmal-Steuerskripte und temporaere Paketkopie entfernt;
  Abwesenheit kontrolliert. Private Serverkonfigurationen, Importaudit,
  Restorebelege und Rollbacksicherungen bleiben absichtlich erhalten.
  Es wurde kein temporaerer oeffentlicher Diagnoseendpunkt angelegt.

## GitHub-Zugang erneuert und erste Produktfreigabe

Fortsetzung am 13. November 2026: Der neueste, vom Betreiber bereitgestellte
Fine-grained Token wurde in einer geschuetzten lokalen Eingabemaske verdeckt
entgegengenommen. GET, PATCH auf ausschliesslich `false` und erneutes GET der
Repository-Deployvariable bestanden mit genau diesem Token. Danach erfolgte
die verschluesselte Ablage im Environment-Secret; dessen neuer Zeitstempel
wurde bestaetigt. Kein Tokenwert in Datei, Chat, Argumenten oder Repository.
Die Zeitstempel-Nachpruefung der ersten Eingabemaske hatte einen lokalen
DateTime-Konvertierungsfehler; der behobene Wiederholungslauf mit dem neuesten
Token bestand vollstaendig. Keine erneute Eingabe erforderlich.

Die gezielte Wiederholung nur des artefaktfreien Schliessjobs startete kein
Deployment. GitHub uebersprang ihn wegen des bereits geschlossenen Schalters;
der Gesamtstatus ist dadurch `success`, **nicht** der Nachweis eines ausgefuehrten
Resetjobs. Der tatsaechliche Schreibnachweis fuer den neuesten Token ist die
oben beschriebene direkte API-Pruefung. Der urspruengliche HTTP-401-Lauf bleibt
als Historie unten erhalten. Produktions-/Testdeploy und Publisher bleiben false.

- Frisches verschluesseltes Backup `20261113T161214Z-fac672b1c97e` erzeugt,
  OneDrive-Abruf/Dateivergleich/Quittierung bestanden. Eine aeltere lokale
  Offsitekopie wurde durch die bestehende Aufbewahrungsregel entfernt;
  eine Rueckholung aus dem OneDrive-Papierkorb wurde nicht geprueft.
- Echter CLI-Restore: `restored_and_compared`; danach getrenntes Restoreziel
  und private Entschluesselungsdateien leer. Der Backup-Programmvergleich
  bestaetigte identischen Code; ausschliesslich CRLF/LF unterschieden sich,
  kein Programm wurde dafuer ersetzt.
- 99 autoritative Produkt-/Bilddateien unveraendert, aktive Programmdateien
  gegen ihren Paketnachweis geprueft. Sechs oeffentliche Modell-4-Projektionen,
  Versions-/Hash-/Preisbindung, Legal v7, 270 Cent Versand und ausschliesslich
  Karte/Klarna/SEPA erneut bestaetigt. Stripe-Konto/Webhook und Brevo-Absender
  ausschliesslich lesend geprueft; Worker gesund.
- `shopCheckoutEnabled=true` und `productApiV5WritesEnabled=true` atomar
  bereitgestellt, erst danach den gemeinsamen Wartungsmarker privat
  rollbackfaehig verschoben. Produkt-/Inventarzeilen waren dabei kurz in
  stabiler Reihenfolge gesperrt; keine Provideraufrufe unter Row-Locks.
  Alle anderen Runtimewerte einschliesslich Geheimwerte blieben identisch.
- Kaufbar ist ausschliesslich Ares CP-2026-0002 mit 4/0/4. Die weiteren fuenf
  Produkte bleiben ohne Mengenimport nicht kaufbar. Keine Produkt-, Preis-,
  Bestands- oder Verkaufsflagmutation durch die Aktivierung. Browserverwaltung,
  Publisher und automatische Deployments bleiben gesperrt. Alte Produkt-
  Schreibclients werden durch den V5-Versionsvertrag abgewehrt.
- Live-Warenkorb fuer eine eigene anonyme Pruefsitzung: ein Ares, 2000 Cent
  Artikelpreis, einmalig 270 Cent Versand, 2270 Cent gesamt, gueltiger
  Checkoutkontext. Anschliessend ueber die API wieder geleert. Keine Stripe-
  Session gestartet; kein neuer Checkout, keine Reservierung, Bestellung,
  Zahlung oder Mail. Normale kurzlebige Sitzungs-/Idempotenzdaten unterliegen
  ihrer bestehenden Aufbewahrung; fremde Sitzungen bleiben unangetastet.
- Abschliessender lesender Vollvergleich bestaetigt historische Checkout-,
  Reservierungs-, Zahlungs-, Bestell-, Inbox- und Outboxinhalte unveraendert,
  Inventar weiterhin 4/0/4, nur die beiden beabsichtigten Runtimeflags anders,
  Restoreziel und Runtime-Staging leer. Geschuetzte lokale Eingabemaske samt
  Statusdatei und Einmal-Aktivierungsskript entfernt; private Nachweise und
  Rollbackkopien auf dem Server bewusst erhalten.

Sicherer Fortsetzungspunkt: Betreiber bestellt genau ein Ares ueber den echten
Shop und bezahlt 22,70 EUR. Erst nach Webhook-/Worker-/Bestell-/Bestands-/
Mailnachweis folgen die weiteren fuenf Mengen und die gesonderte PC-Verwaltung.
Keine vollstaendige Startabnahme vor dieser notwendigen menschlichen Zahlung.

## Historischer GitHub-Betriebsblocker vor der Erneuerung

Der [Produktionslauf 43406404331](https://github.com/Bumpers210/armband-rechner/actions/runs/43406404331)
ist insgesamt **nicht erfolgreich**: Nur der abschliessende Job
`reset-production-deploy-gate` scheiterte mit HTTP 401 / `Bad credentials`.
Das Environment-Secret `CARMAJA_PRODUCTION_VARIABLES_TOKEN` unter
`carmaja-production` ist fuer diesen Aufruf nicht verwendbar. Ein Ablaufdatum
wurde nicht nachgewiesen und wird nicht als Ursache behauptet.

Der unabhaengige lokale Finally-Abschluss hat
`CARMAJA_PRODUCTION_DEPLOY_ENABLED=false` erfolgreich gesetzt; der Endwert
wurde anschliessend erneut ueber GitHub gelesen. Auch Publish- und Testdeploy-
Schalter bleiben false. Die erfolgreiche Websitebereitstellung wird deshalb
nicht zurueckgenommen, aber der Gesamtworkflow nicht als gruen dokumentiert.
Kein erneutes Deployment nur zum Wiederholen des fehlgeschlagenen Resetjobs.

Vor der Kaufweg-Aktivierung ist ein gueltiger, minimal berechtigter Zugang
fuer das automatische Zuruecksetzen bereitzustellen und technisch zu pruefen.
Keine Suche nach Ersatzsecrets und keine Wiederverwendung des persoenlichen
CLI-Anmeldetokens als Workflow-Secret. Notwendige Benutzereingabe: neuer
GitHub-Zugang mit Variables-Schreibrecht ausschliesslich fuer dieses Repository;
Uebernahme nur privat, niemals im Chat oder Repository.

## Unveraenderte Daten und Grenzen

Die Betreiberzaehlung fuer CP-2026-0002 bis CP-2026-0007 betraegt jeweils vier
fertige, verkaufsfaehige Stuecke. Zunaechst ist ausschliesslich Ares importiert;
die uebrigen fuenf Modelle erhalten erst nach dem kontrollierten Erstkauf ihren
gebundenen Import. CP-2026-0008/-0009 bleiben ausserhalb des oeffentlichen
Katalogs. Bestehende Testprodukte, Testbestaende und private Konfigurationen
wurden nicht angefasst. Produktionsproduktdateien/Bilder bleiben unveraendert.

Der nun ausgelieferte oeffentliche Katalog ist eine strikte Modell-4-Projektion
derselben sechs bestehenden Produkte. Nur Modell-/Produktversion, Quellhash,
Umstellungszeitpunkt und die leere Hinweiszuordnung werden abgeglichen. Preise,
Texte, Messwerte, Bilder und Verkaufsflags aendern sich dadurch nicht.
Ein Verkaufsflag allein ist keine Kaufbarkeit: bestaetigter Commerce-Bestand,
Live-Verfuegbarkeit und die separaten Runtime-/Checkoutgates bleiben notwendig.

## Historischer Erstzahlungsblocker vor PR #105

### Erstzahlung: abgesicherte Klarna-Korrektur

Die Betreiberin hat den kontrollierten Erstkauf abgeschlossen. Der lesende
Providerabgleich bestaetigt eine echte Klarna-Zahlung von 2270 Cent, bezahlte
Session, erfolgreichen PaymentIntent, AGB-Zustimmung und passende Checkout-/
Snapshot-/Legal-Bindung. Der Worker stellte den Vorgang jedoch mit
`cart_payment_verification_failed` in `manual_review`: Stripe lieferte einen
Versandempfaengernamen, aber keinen optionalen Kunden-/Rechnungsnamen.
Neue Checkouts wurden daraufhin atomar geschlossen; alle anderen Runtimewerte
blieben identisch. Ein Stueck bleibt sicher reserviert, noch keine neue
Bestellung oder Mail. Kein erneuter Kauf und keine zweite Zahlung erforderlich.

Die eng begrenzte Worker-Korrektur nutzt bei fehlendem/leerem Kundennamen den
von Stripe erfassten Versandempfaengernamen als Anzeigebezeichnung, nicht als
Nachweis einer abweichenden Zahleridentitaet. Vorhandene Kundennamen bleiben
vorrangig; fehlerhafte Datentypen werden nicht kaschiert. Keine erfundenen Namen,
keine Ersetzung fehlender Lieferadressen durch Rechnungsadressen. Betrag,
Zustimmung, Snapshot, Bestand und alle Finalisierungsgates bleiben unveraendert.

Lokale PHP-8.4-Mappingregression: 34 Pruefungen; Stripe-, Warenkorb-,
Transaktions-, SEPA- und Commerce-Suiten bestanden. 57 relevante Node-Pruefungen
und Diffpruefung bestanden. [PR #105](https://github.com/Bumpers210/armband-rechner/pull/105)
enthaelt Commit `93716db886e0200d72587c1992976bdef1f6d554`.
Die [vollstaendige Paket-CI](https://github.com/Bumpers210/armband-rechner/actions/runs/43467890773)
bestand einschliesslich PHP-/Node-/Shellregression, Sicherheitspruefung,
kuenstlichem Produktionsbuild und Artefaktvalidierung.

Frisches verschluesseltes Backup `20261113T165745Z-1cf0edeed395`, gepruefter
OneDrive-Abruf/Quittierung und echter Restore `restored_and_compared` bestanden.
Restoreziel und temporaere Entschluesselungsdateien sind wieder leer. Der
abschliessende lesende Stand um 17:03 UTC: Zahlung weiterhin im Prueffall,
Inventar 4/1/3, keine neue Bestellung und keine Bestell-/Betreibermail.

Die Sicherheitspruefung lehnte den angeforderten main-Merge wegen fehlender
ausdruecklicher aktueller Mergefreigabe ab. Kein Umgehungsversuch, kein Merge
und keine Workerbereitstellung. Main bleibt `b3167b9babf27bdc9fa547cfbfcde8d0b3fb8e66`,
PR #105 offen/gruen, alle Deploygates geschlossen. Die einzige Produktions-
Runtimeaenderung dieses Schritts ist `shopCheckoutEnabled=false`; sonstige
Konfigurationen, Produktdateien und Testbereiche bleiben erhalten.

Dieser historische Wartepunkt wurde anschliessend durch die ausdrueckliche
Merge-/Workerfreigabe aufgehoben; siehe den aktuellen Nachweis unten.

## PR #105, regulaere Erstbestellung und administrativer Abschluss

- PR #105 kontrolliert integriert: main
  `54243f1f27ca22df870a163e4989818a459fe82c`. Vollstaendige Paket-CI vor dem
  Merge sowie [main-Lauf 43471345482](https://github.com/Bumpers210/armband-rechner/actions/runs/43471345482)
  bestanden. Alle automatischen Deploy-/Publishgates false.
- Ausschliesslich `ap3-worker.php` aus diesem main-SHA privat hashgeprueft,
  mit PHP 8.4 gelintet und atomar aktiviert. Altes Programm und Paketmanifest
  privat rollbackfaehig erhalten; Paketprovenienz um den Ein-Datei-Patch ergaenzt.
  Keine Website-/Appbereitstellung und keine sonstige Programm-/Secretmutation.
- Der regulaere Worker finalisierte die vorhandene Klarna-Zahlung von
  2270 Cent inklusive einmalig 270 Cent Versand. CMJ-00000002 ist confirmed;
  Snapshot, Legal-Zustimmung und Providerstatus verifiziert. Genau ein
  shop_sale-Audit: Ares von 4 auf 3, Inventarversion 2, keine Reservierung mehr.
- Bestell- und Betreibermail jeweils sent mit genau einem Versuch und
  passendem Snapshot. Brevo bestaetigt beide mit delivered. Kein Nachweis
  menschlichen Lesens, kein manueller Neuversand. Metadaten-Outbox sent,
  Abschlusswebhook processed. Wiederholung erzeugte keine Doppelbuchung.
- Erneuter Nachweis um 17:45 UTC mit frischem verschluesseltem Backup
  `20261113T174552Z-2f3195841dc8` und echtem Restore im getrennten Ziel bestanden.
- Adminanmeldung bestaetigt. Die bestehende Oberflaeche hat jedoch keine
  Statusaktion fuer Prueffaelle. Der ausdruecklich beauftragte Abschluss des
  einzigen behobenen Falls erfolgte daher als eng begrenzte private SSH-Wartung:
  open -> investigating -> resolved -> closed, atomar mit drei Auditereignissen,
  admin_id NULL und gekennzeichnetem Wartungsakteur statt nachgebildeter
  Benutzeridentitaet. Urspruenglicher Grund und Details bleiben erhalten.
- Eine erste Transaktion wurde wegen des automatisch aktualisierten
  updated_at vollstaendig zurueckgerollt; die korrekt begrenzte Wiederholung
  um 17:48 UTC bestand. Zahlungs-, Checkout-, Bestell-, Positions-, Bestands-,
  Versand- und Maildaten sowie Runtime vollstaendig unveraendert.
  Worker danach processed=0, Monitor recovered. Kein Versand als erfolgt markiert.

## Fuenf weitere Mengen und kontrollierte Kaufweg-Freigabe

- Nach geschlossenem Prueffall wurden die vorhandenen V5-Schreib- und
  Warenkorbgates temporaer geschlossen sowie der private Wartungsmarker gesetzt.
  Alle anderen Runtimewerte und Zugangsdaten blieben erhalten; keine neue
  Programm- oder Websitebereitstellung.
- Ausgewaehlt ausschliesslich CP-2026-0003 bis CP-2026-0007, jeweils vier
  bestaetigte Stuecke und Inventarversion 0 vor Import. Variantenbindung:
  18 cm, Artemis/Athena 8 mm und die drei weiteren Modelle 6 mm.
  Aktuelle autoritative Versionen und Hashes stimmen mit dem bestehenden
  oeffentlichen Katalog ueberein. Ares sowie CP-2026-0008/-0009 ausgeschlossen.
- Die erste Vorbereitung hielt wegen eines regulaeren Worker-Lease-Updates
  sicher mit database_changed an; kein Import. Keine Vergleichsausnahme:
  neuer isolierter Nachweissatz und frisches Backup zwischen Workerlaeufen.
- Backup `20261113T175446Z-262c802b8301`, Offsite-Quittierung und echter
  Restore bestanden. Gepruefter CLI-Aufrufer meldete
  inventory_imported_and_compared fuer fuenf Produkte; die direkte lesende
  Wiederholung already_imported. Vollvergleich erhielt historische Daten,
  bestehende Audits, nicht ausgewaehlte Produkte und alle Produktdateien.
- Ergebnis: Ares 3/0/3 mit Version 2, alle weiteren fuenf Modelle 4/0/4 mit
  Version 1, insgesamt 23 Stuecke. Kein zweites shop_sale, keine doppelte
  Erstzaehlung. Restoreziel und Entschluesselungsdateien bereinigt.
- Frisches Backup nach Import `20261113T175700Z-2b8773ad8be8`, gepruefter
  OneDrive-Pull und CLI-Restore restored_and_compared bestanden.
- Zunaechst ausschliesslich Warenkorb-Lesebetrieb geoeffnet. Sechs Livepreise/
  Versionen, Verfuegbarkeitssperre, no-store, Cookieeigenschaften, CSRF und CORS
  bestanden. Lokale Pruefskript-Annahmen wurden korrigiert: PowerShell behaelt
  benutzerdefinierte Header in WebSession; fuer die negative CSRF-Probe werden
  diese nun geleert. Fehlender Header ist vertraglich 422/header_required,
  ungueltiger Token 403/context_invalid. Keine API-Aenderung erforderlich.
- Um 18:02 UTC Kaufweg, V5-App-Schreibzugang und getrennten PC-Zugang atomar
  geoeffnet, dann Wartungsmarker privat archiviert. Warenkorb bleibt aktiviert;
  Publisher, GitHub-Adapter, Legacy-V4-Schreiben, alter Kollektionenbetrieb und
  automatische Deployments bleiben gesperrt. Sonstige Runtimewerte erhalten.
- Live-Smoke nach Oeffnung bestanden: alle sechs Produkte verfuegbar mit
  korrekten EUR-Preisen und Versionen; eigener Zwei-Positions-Warenkorb 42,20 EUR
  inklusive einmalig 2,70 EUR Versand; frischer GET-Checkoutkontext vorhanden;
  CSRF-Ablehnung, CORS-Preflight, Fremd-Origin-Ablehnung und no-store korrekt.
  Der Pruefwarenkorb wurde geleert, keine Stripe-Session gestartet. Normale
  kurzlebige Sitzungs-/Idempotenz-/Ratelimitdaten unterliegen der vorhandenen
  Aufbewahrung; fremde Sitzungen und Bestellungen werden nicht bereinigt.
- Worker processed=0 und monitorStatus=ok. PC-Verwaltung ohne Anmeldung
  korrekt 401/authentication_required, no-store und exakter Produktions-Origin.
  Loginseite sichtbar; sie benoetigt den bestehenden Produktverwaltungs-App-
  Zugang, nicht die separaten Shop-Admin-Zugangsdaten.
- Abschliessender lesender Nachweis um 18:05 UTC: genau eine neue Bestellung,
  beide zugehoerigen Mails sent/Versuch 1, geschlossener Prueffall mit drei
  auditierten Uebergaengen, sechs Inventare/23 Stuecke/keine Reservierung,
  alle 99 gebundenen Produkt-/Bilddateien identisch. Runtime weiterhin 0600;
  ausser den beabsichtigten Freigabeschaltern alle Werte unveraendert.
  Getrenntes Restoreziel und Entschluesselungsstaging leer, keine Monitorwarnung.
- Repository-Deploy-, Publish-, Statistik- und Testdeployschalter sowie der
  Environment-API-Deployschalter abschliessend false; main unveraendert auf
  54243f1f27ca22df870a163e4989818a459fe82c. In dieser Fortsetzung kein Commit,
  Push, Merge oder Programm-/Website-Deployment. Ausschliesslich die beiden
  Plan-/Nachweisdateien lokal aktualisiert; git diff --check bestanden.
- Vier eigene lokale Einmal-Pruefskripte und der exakt gepruefte temporaere
  Worker-Paketordner entfernt; Abwesenheit bestaetigt. Private Runtime,
  Schluessel, Import-/Abschlussaudit und Rollbacksicherungen bleiben erhalten.
  Keine Testprodukte oder privaten Testkonfigurationen angefasst.

## Lesender App-/PC- und Shop-Abgleich

Fortsetzung am 13. November 2026, Servernachweis um 18:32 UTC:

- Der gesondert freigegebene Passwortreset fuer die bestehende
  Produktions-Produktverwaltung wurde ueber verdeckte lokale Eingabe
  abgeschlossen. Konto erhalten, Anmeldung und Produktlesen technisch
  bestaetigt, eigene Probesitzung abgemeldet. Kein Passwort in Chat,
  Repository oder Log; die privaten Einmal-Eingabe-/Statusartefakte sind
  entfernt. Shop-Adminzugang, Produkte, Bilder, Bestände und Runtime erhalten.
- Produktive PC-Verwaltung erfolgreich angemeldet. Alle sechs Shopmodelle
  ausschliesslich geoeffnet und gelesen, nichts gespeichert, veroeffentlicht
  oder im Bestand bestaetigt. Preise, Messwerte, Versionen und alle 16 Bilder
  sichtbar; Ares 3/0/3, die weiteren fuenf Modelle jeweils 4/0/4. Keine
  automatischen Hinweise nachtraeglich an bestehende Produkte angehaengt.
- Read-only-Transaktion: kanonische autoritative Produkte, Versions-/Hashbindung,
  Commerce-Produktlesemodell, oeffentliche Projektion und Bilddateien stimmen
  fuer genau die sechs freigegebenen Produkte ueberein. 16 oeffentliche Bilder
  bytegleich zu ihren privaten Quelldateien. CP-2026-0008/-0009 nicht ergaenzt.
  Beide MySQL-Verbindungen mit aktiver TLS-Sitzung; Restoreziel leer.
- CMJ-00000002 weiterhin confirmed, Klarna succeeded, einmalige Ares-Abbuchung,
  keine Reservierung. Bestell- und Betreibermail jeweils sent/Versuch 1;
  der zuvor bestaetigte Brevo-Zustellnachweis bleibt gueltig. Kein Neuversand.
  Versand ready, nicht shipped. Keine offenen Prueffaelle oder unerledigten
  Eintraege in Webhook-Inbox, Mail- und Stripe-Metadaten-Outbox.
- Regulaere Workerlaeufe erfolgreich, Monitor ohne Issue-Codes. Backupstatus
  ok; weder Serverbackup noch Offsiteabruf ueberfaellig. Kein neuer Restore
  oder manuell ausgeloester Worker in diesem lesenden Abnahmeschritt.
- Aktives Legal Bundle cmj-production-legal-2026-10-25-v7 approved und
  inhaltsgleich zum freigegebenen Repositoryvertrag. Die Pruefhilfe wurde auf
  den tatsaechlichen Runtime-Schluessel activeLegalBundleId korrigiert;
  keine Runtime- oder Legal-Aenderung war notwendig.
- 18 oeffentliche Seiten erneut mit HTTP 200 geprueft: Start, Uebersicht,
  sechs Details, Warenkorb, fuenf Rechtsseiten, success/cancel/error und
  Legal-v7-Archiv. Keine Testdomain oder Vinted-Verweise im HTML.
  Alle sechs Live-Produkte erreichbar und verfuegbar, exakter Website-Origin
  in CORS und no-store. Keine neue Shop-/Stripe-Session oder Warenkorbmutation.
- Lokale PHP-8.4.23-Suiten product-browser-production, product-browser-http,
  cart-sales-permission, cart-quantity-limit, cart-worker-mapping und
  cart-contract bestanden. Vier zugehoerige Node-Dateien mit 42/42 bestandenen
  Tests: Browserverwaltung, Mengenvertrag, Mengenlimit-UI und Legal-Limit.
  Tests ausschliesslich mit kuenstlichen lokalen Daten.
- Signierter Produktions-App-Lauf
  [43249178666](https://github.com/Bumpers210/armband-rechner/actions/runs/43249178666)
  erfolgreich, Artefakt weiterhin vorhanden: Version 1.4.0 / Code 8,
  Produktionspaket de.carmajaperlen.armbandrechner und Produktions-API.
  Unit-, Lint-, Offline-Compose-, Release- und Signaturpruefschritte bestanden.
  App-/Gradle-/Releaseworkflow-Diff zwischen dessen Quellcommit
  261564aef3f62be759bc3efc0251b019a9bdd354 und main
  54243f1f27ca22df870a163e4989818a459fe82c ist leer. Kein neuer Build gestartet.
  CI-/Emulatornachweise ersetzen keine Bestaetigung auf dem echten Handy.
- Keine Geschaeftsdaten-, Bestands-, Programm- oder Runtimeaenderung durch
  diese Pruefung. Testprodukte und private Konfigurationen erhalten. Kein
  Commit, Push, Merge oder Deployment; GitHub-Deploy-/Publishschalter bleiben
  false. Nur dieser Nachweis und der Produktionsplan werden lokal ergaenzt.
- Eigene lokale Read-only-Pruefhilfe entfernt, Abwesenheit bestaetigt.
  Kein temporaeres Serverprogramm oder oeffentlicher Endpunkt angelegt.
  Abschliessendes git diff --check bestanden.

## Abschliessende Shop-Abnahme und Betriebshandover

**Technische Shop-Abnahme bestanden am 13. November 2026.** Die Betreiberin
bestaetigt den angeforderten abschliessenden Lesetest auf dem echten Handy:
"Produktions-App: Ares korrekt, Bestand 3 / 0 / 3". Die Bestaetigung bezieht
sich auf Produktions-App 1.4.0 / Code 8, 20,00 EUR, 18 cm, 8 mm und drei Bilder.
Dieser Nachweis stammt von der Betreiberin, nicht von einer automatisierten
Bedienung ihres Telefons. Er schliesst den letzten App-/PC-Abgleich.

| Abschlussgate | Ergebnis / Nachweis |
| --- | --- |
| Mengenmigration, Datenerhalt und Wiederherstellung | bestanden; gebundener Import, Wiederholung ohne Doppelbuchung, Offsite-/Restorebeleg nach Import |
| Oeffentlicher Shop, Legal v7, Warenkorb und Sicherheitsvertrag | bestanden; sechs freigegebene Modelle, 18 Seiten und Live-API/CORS/no-store nachgeprueft |
| Echte Zahlung bis Bestellung, Bestand und Bestellmail | bestanden; CMJ-00000002, 2270 Cent, eine Ares-Abbuchung, zwei einmalige Mails; Brevo-Zustellnachweis vorhanden |
| Produktverwaltung am PC | bestanden; sechs Modelle, Preise, Messwerte, Versionen und 16 Bilder lesend abgeglichen |
| Signierte Produktions-App und echter Handyabgleich | bestanden; CI-/Signaturvertrag unveraendert, manueller Ares-Lesetest bestaetigt |
| Worker, Monitoring, Backup und Offsite | erneute lesende Betriebspruefung 18:57 UTC bestanden; keine Warnung oder ueberfaellige Sicherung |
| Rechts-, Datenschutz- und Versandfreigabe | versioniert dokumentierte externe Freigabe; aktives v7 approved, keine eigene Rechtsfreigabe durch den Agenten |

Die erneute read-only-Transaktion um 18:57:51 UTC bestaetigt sechs Inventare
mit insgesamt 23 Stueck und null blockierenden Reservierungen: Ares 3/0/3,
die anderen fuenf Modelle jeweils 4/0/4. Bestellung confirmed, Zahlung
succeeded/verified; beide Mails sent mit attempt_count=1. Keine offenen
Prueffaelle, keine unerledigten Eintraege in Webhook-Inbox, Stripe-Metadaten-
oder Mail-Outbox. Beide regulaeren Worker waren um 18:57:35 UTC erfolgreich,
jeweils unter einer Sekunde; Monitor zur selben Zeit ohne Issue-Codes.
Beide MySQL-Verbindungen pruefen aktive TLS-Sitzungen, Restoreziel leer.
Runtime vor/nach der Pruefung identisch und weiterhin 0600.

Backupstatus ok: letztes fertiges Serverbackup 18:18:02 UTC, letzter
bestaetigter Download 18:38:16 UTC, keine Ueberfaelligkeit. In diesem Schritt
kein neuer Backup-/Restorelauf und keine Provideraktion. Der zuvor bestandene
Restore des Nachimport-Backups bleibt der Wiederherstellungsnachweis.

main und bestehende Paket-CI erneut gelesen: SHA
54243f1f27ca22df870a163e4989818a459fe82c, Run 43471345482 success.
Repository-Produktionsdeploy, Publisher, Statistikdeploy, Testdeploy und
Environment-API-Deploy bleiben false. Laufzeit-Kaufweg, V5-App und PC-Zugang
bleiben offen; Publisher/GitHub-Adapter und alter Kollektionenbetrieb bleiben
bewusst deaktiviert. Kein erneutes Oeffnen oder Aendern dieser Schalter.

### Verbleibende Betreiberaufgaben, keine technische Start-Sperre

- CMJ-00000002 tatsaechlich verpacken und versenden. Der Versandstatus ist
  weiterhin ready, nicht shipped; erst nach realem Versand im Shop-Admin
  bestaetigen. Kein erfundener Versand- oder Versandmailnachweis.
- Normale Betriebsueberwachung, stuendliche Serverbackups und regelmaessigen
  OneDrive-Pull aufrechterhalten; monatlichen Restoretest und Wiederherstellung
  vor weiteren relevanten Produktionsaenderungen nach dem Betriebsvertrag pruefen.
- Maximal zehn Armbaender und einmalig 2,70 EUR Versand, Karte/Wallets, Klarna
  und SEPA bleiben unveraendert. PayPal bleibt deaktiviert. Fehlende MySQL-
  CA-/Hostidentitaetspruefung und das Klarna-/Zustellrisiko ohne Tracking bleiben
  die bereits akzeptierten Restrisiken, keine neu geschlossenen Nachweise.
- Publisher-/Deploymentautomatik nicht nebenbei aktivieren. CP-2026-0008/-0009
  bleiben ausserhalb des oeffentlichen Katalogs; neue Produkte benoetigen ihren
  eigenen vollstaendigen Produkt-, Bestands- und Veroeffentlichungsweg.

Durch diesen Abschluss keine Produkt-, Bild-, Bestands-, Kunden-, Bestell-,
Konfigurations- oder Testdaten geaendert. Keine temporaere Datei oder
Serverroute angelegt; nur lesende Pruefungen und lokale Dokumentation.
Kein Commit, Push, Merge oder Deployment. Vorhandene fremde Aenderungen erhalten.

Bei Preis-, Hash-, Bestands-, Zahlungs- oder Restorewiderspruch neue Checkouts
sofort schliessen; bestehende Zahlungen und Bestellungen weiter verarbeiten.
Nach erster bestandswirksamer Zahlung kein Rueckwechsel zum unbegrenzten
Kollektionenverkauf. Technischer Shopstart abgenommen; die laufende Erfuellung
realer Bestellungen und wiederkehrende Betriebspruefungen bleiben erforderlich.
Keine erfundenen Ist-Stunden oder garantierten Termine.

## Main und App-Release-Stand vom 14. November 2026

Aenderungsvermerk: main-/Releasebezug und den bereits am 13. November
erhobenen lesenden Abschlussnachweis ergaenzt. Keine Fachregel, Freigabe,
Produktionsdatei oder Konfiguration geaendert. Historische Build-, Deploy-
und Abnahmezeitpunkte bleiben getrennt; ein neuer main-SHA bedeutet nicht,
dass alle Produktionsprogramme erneut bereitgestellt wurden.

| Gegenstand | Verifizierter Bezug |
| --- | --- |
| Aktueller main | `3a605f9f2568a266b4fcc8cf64d0b436196c7351`; [PR #106](https://github.com/Bumpers210/armband-rechner/pull/106) am 13. November 2026, 19:17:02 UTC integriert |
| Bestehende main-CI | [43490737279](https://github.com/Bumpers210/armband-rechner/actions/runs/43490737279), Validate production cart package: completed/success |
| Bereits bereitgestellte Websitebasis | `b3167b9babf27bdc9fa547cfbfcde8d0b3fb8e66`; kein Website-Deployment durch PR #106 oder das App-Release |
| Zuletzt bereitgestellter privater Workerpatch | `ap3-worker.php` aus `54243f1f27ca22df870a163e4989818a459fe82c`, Nachweis zu PR #105 oben; keine erneute Bereitstellung |
| Produktions-App | 1.4.0 / Code 8, Paket `de.carmajaperlen.armbandrechner`, ausschliesslich Produktions-API |
| App-Quellcommit und Release-Tag | `261564aef3f62be759bc3efc0251b019a9bdd354`; Tag `product-management-v1.4.0` verweist auf genau diesen Buildquellcommit |
| Signierter App-Build | [43249178666](https://github.com/Bumpers210/armband-rechner/actions/runs/43249178666), completed/success; Unit-, Lint-, Offline-Compose-, Release- und Signaturpruefungen bestanden |
| Veroeffentlichtes Release | [Carmaja-Perlen Produktverwaltung 1.4.0](https://github.com/Bumpers210/armband-rechner/releases/tag/product-management-v1.4.0), seit 13. November 2026, 19:30:45 UTC; latest, nicht draft, nicht prerelease |

Release-Dateien:

- [Carmaja-Perlen-Produktverwaltung-1.4.0-signed.apk](https://github.com/Bumpers210/armband-rechner/releases/download/product-management-v1.4.0/Carmaja-Perlen-Produktverwaltung-1.4.0-signed.apk), 9.237.225 Byte.
- [SHA256SUMS.txt](https://github.com/Bumpers210/armband-rechner/releases/download/product-management-v1.4.0/SHA256SUMS.txt), Pruefsumme fuer die APK unter ihrem Download-Dateinamen.

Bei Veroeffentlichung wurde die unveraenderte APK aus dem bestehenden
signierten Lauf erneut lokal auf Paket, Version, nicht gesetztes Debugflag,
stabile Produktionssignatur, Produktions-API und fehlende Test-/Beta-Verweise
geprueft. Upload-Digests und anschliessender Release-Download stimmten mit
den geprueften Dateien ueberein; lokale Transferkopien wurden bereinigt.
Am 14. November wurden Release-Metadaten, Tagziel, CI und der Vergleich
App/Gradle/Wrapper/Releaseworkflow gegen main erneut lesend bestaetigt:
keine geaenderten App-Build-Eingaben. Kein neuer APK-Build oder erneuter
Geraetetest. Wer 1.4.0 / Code 8 bereits verwendet, benoetigt deshalb keine
Neuinstallation. Die APK ist fuer die Shop-Produktverwaltung bestimmt;
eine Play-Store-Veroeffentlichung ist nicht Teil dieses Abschlusses.

### Letzter lesender Produktionsnachweis vom 13. November

- 20 oeffentliche Seiten einschliesslich Warenkorb, Verwaltungs-/Adminhuelle,
  Rechtsseiten, Rueckkehrseiten und Legal-v7-Archiv mit HTTP 200; keine
  Testdomains, Vinted-/Marktplatzlinks oder CP-2026-0008/-0009 im geprueften HTML.
- Sechs Live-Produkte mit passenden Preisen und Mengen, exaktem CORS-Origin
  und no-store. Ares verfuegbar mit drei Stueck, die weiteren fuenf mit je vier.
- Read-only-Datenbankpruefung 19:36:36 UTC: sechs Inventare mit 23 Stueck,
  null blockierende Reservierungen, keine offenen Prueffaelle und keine
  queued/processing/Fehlereintraege in den drei geprueften Inbox-/Outboxen.
  Eine zuerst fehlerhafte reine Leseabfrage der berechneten Reservierungsmenge
  wurde zurueckgerollt und anhand des vorhandenen Schemas korrigiert wiederholt;
  keine Programm- oder Datenkorrektur war dafuer erforderlich.
- CMJ-00000002 confirmed, Zahlung succeeded/verified, Versand ready.
  Bestell- und Betreibermail jeweils sent mit attempt_count=1. Kein neuer
  Zahlungsvorgang, Versand oder Mailversand ausgeloest.
- Beide Worker zuletzt 19:32:36 UTC erfolgreich, ohne Fehler; Monitor zur
  selben Zeit ohne Issue-Codes. Aktive Commerce-TLS-Sitzung bestaetigt.
- Backupstatus ok: Serverbackup 19:18:03 UTC, Offsite-Quittierung 18:38:16 UTC,
  keine Ueberfaelligkeit zum Pruefzeitpunkt. Kein neuer Restore in dieser
  Lesepruefung; der vorherige erfolgreiche Restorebeleg bleibt massgeblich.
- Runtime vor/nach identisch, Modus 0600; Kaufweg, Warenkorb, V5-App-Schreiben,
  PC-Zugang und Monitoring aktiv. Publisher und GitHub-Adapter bleiben false.

Am 14. November wurden nur lokale Dokumente und GitHub-Belege gelesen bzw.
die Dokumente aktualisiert, nicht die Produktion erneut aufgerufen. Alle fuenf
GitHub-Deploy-/Publishschalter wurden dabei erneut als false bestaetigt.
Keine neue Komplettregression fuer diese reine Dokumentationspflege; die
bestehenden CI-, APK- und Betriebsnachweise werden nicht als neue Tests ausgegeben.

### Verbleibender Betrieb und getrennte Verbesserungen

Der Versandnachweis fuer die Erstbestellung fehlt nach dem letzten gelesenen
Stand weiterhin; kein spaeterer Versand wird ohne Nachweis angenommen.
Monitoring, regelmaessiger OneDrive-Pull, monatlicher Restoretest und die
bereits dokumentierten TLS-/Klarna-Versandrestrisiken bleiben bestehen.
Die Adminoberflaeche zeigt Prueffaelle nur an; ihre Statusbearbeitung ist eine
moegliche separat zu beauftragende Verbesserung, keine bereits gelieferte
Funktion. Publisherautomatik und automatische Deployments bleiben bewusst
deaktiviert. Neue Artikel benoetigen den kontrollierten Veroeffentlichungsweg;
die ausgeschlossenen Produkte werden nicht automatisch hinzugefuegt.

Urteil: Shop und App im freigegebenen Umfang veroeffentlicht; kein aus den
vorliegenden Nachweisen offener technischer Veroeffentlichungsblocker.
Diese Aktualisierung umfasst ausschliesslich drei Dokumentationsdateien;
kein Commit, Push, Merge, Deployment oder Produktionszugriff.
