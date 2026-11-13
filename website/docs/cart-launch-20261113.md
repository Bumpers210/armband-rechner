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

## Unveraenderte Daten und Grenzen

Die Betreiberzaehlung fuer CP-2026-0002 bis CP-2026-0007 betraegt jeweils vier
fertige, verkaufsfaehige Stuecke. Zunaechst ist ausschliesslich Ares importiert;
die uebrigen fuenf Modelle erhalten erst nach dem kontrollierten Erstkauf ihren
gebundenen Import. CP-2026-0008/-0009 bleiben ausserhalb des oeffentlichen
Katalogs. Bestehende Testprodukte, Testbestaende und private Konfigurationen
wurden nicht angefasst. Produktionsproduktdateien/Bilder bleiben unveraendert.

Der neue lokale oeffentliche Katalog ist eine strikte Modell-4-Projektion
derselben sechs bestehenden Produkte. Nur Modell-/Produktversion, Quellhash,
Umstellungszeitpunkt und die leere Hinweiszuordnung werden abgeglichen. Preise,
Texte, Messwerte, Bilder und Verkaufsflags aendern sich dadurch nicht.
Ein Verkaufsflag allein ist keine Kaufbarkeit: bestaetigter Commerce-Bestand,
Live-Verfuegbarkeit und die separaten Runtime-/Checkoutgates bleiben notwendig.

## Noch offene Freigabegates

1. Aktuelle lokale/CI-Katalog- und Websitepruefung, sauberer main-Release.
2. SHA-gepinntes Website-Deployment mit Backup/Rollback und anschliessendem
   Zuruecksetzen des Deployschalters; Kaufweg bleibt dabei geschlossen.
3. Live-Pruefung: Modell-4-/Shop-v3-Vertrag, Ares-Menge, restliche Modelle nicht
   kaufbar, Legal-v7-Hashbindung, Card/Klarna/SEPA, CORS/No-Store und Worker.
4. Begrenzte Aktivierung und kontrollierte echte Ares-Erstbestellung durch die
   Betreiberin; Zahlung, atomare Bestellung/Abbuchung und Bestellmail nachweisen.
5. Erst danach weitere bestaetigte Mengen mit frischem Versions-/Backup-/
   Restorebezug importieren, schrittweise freigeben und beobachten.
6. Gemeinsame App-/PC-Verwaltung gesondert pruefen und kontrolliert oeffnen.
   Publisher-/Deploymentautomatik nicht ohne passenden geprueften Vertrag aktivieren.

Bei Preis-, Hash-, Bestands-, Zahlungs- oder Restorewiderspruch neue Checkouts
sofort schliessen; bestehende Zahlungen und Bestellungen weiter verarbeiten.
Nach erster bestandswirksamer Zahlung kein Rueckwechsel zum unbegrenzten
Kollektionenverkauf. Noch keine vollstaendige Shopstart-Abnahme und keine
erfundenen Ist-Stunden oder garantierten Termine.
