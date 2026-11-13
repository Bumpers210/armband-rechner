# Kontrollierter Warenkorb-Shopstart

Stand: 13. November 2026. Auftrag: autonome Schritte bis zur Veroeffentlichung,
ohne Sicherheitsgates zu umgehen. Personenbezogene Nachweisinhalte, private
Konfigurationen und Zugangsdaten sind nicht Bestandteil dieser Dokumentation.

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

## Noch offene Freigabegates

1. Kontrollierte echte Ares-Erstbestellung durch die
   Betreiberin; Zahlung, atomare Bestellung/Abbuchung und Bestellmail nachweisen.
2. Erst danach weitere bestaetigte Mengen mit frischem Versions-/Backup-/
   Restorebezug importieren, schrittweise freigeben und beobachten.
3. Gemeinsame App-/PC-Verwaltung gesondert pruefen und kontrolliert oeffnen.
   Publisher-/Deploymentautomatik nicht ohne passenden geprueften Vertrag aktivieren.

Bei Preis-, Hash-, Bestands-, Zahlungs- oder Restorewiderspruch neue Checkouts
sofort schliessen; bestehende Zahlungen und Bestellungen weiter verarbeiten.
Nach erster bestandswirksamer Zahlung kein Rueckwechsel zum unbegrenzten
Kollektionenverkauf. Noch keine vollstaendige Shopstart-Abnahme und keine
erfundenen Ist-Stunden oder garantierten Termine.
