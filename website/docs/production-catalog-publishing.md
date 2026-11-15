# Produktionskatalog und automatische Websiteaktualisierung

Stand: 15. November 2026. **Websitekandidat lokal geprüft; Automatik noch nicht
produktiv aktiviert. Drei ausdrücklich freigegebene Altartikel archiviert.**

## Auftrag und Befund

Die Betreiberin hat den Abgleich des aktuellen Produktkatalogs, die Aktualisierung
der Livewebsite und die automatische Aktualisierung bei Veröffentlichen und
Archivieren beauftragt. Bestände und Bestellungen müssen erhalten bleiben.

Die lesende Prüfung bestätigt: V5-Veröffentlichungen erreichen die private
Modell-4-Projektion, der lokale Adapter meldet aber `deploymentStatus=not_started`.
Die aktive Runtime hat `productionPublishEnabled=false` und
`githubAdapterEnabled=false`. Zusätzlich sperrt der versionierte
Deploymentvertrag den automatischen Publisherpfad. Der Server-Backupstatus und
der bestehende, privat gespeicherte Publisherzugang wurden lesend geprüft.

## Eng begrenzte Automatik

Der vorhandene Workflow bleibt unverändert. Nur `guards.publisherEnabled` und
`guards.automaticWebsiteDeployment` werden für den bestehenden, geprüften
Collection-v4-Publisher freigegeben. Dieser unterstützt die öffentliche
Modell-4-Projektion einschließlich `salesEnabled=false`.

Ein automatischer Lauf benötigt weiterhin:

- einen direkten, nicht erzwungenen Folgecommit von `Bumpers210` auf `main`;
- exakte Publisherkennzeichnung und eindeutige Operation;
- ausschließlich `website/content/products.json` und höchstens fünf Bilder
  genau der gekennzeichneten SKU;
- erfolgreiche Herkunftsprüfung, Regression, Build und Exportprüfung;
- das hashgebundene statische Artefakt und den bestehenden rollbackfähigen
  Aktivierungsweg.

Allgemeine Code-/PR-/Mergeänderungen lösen kein automatisches Deployment aus.
API, Runtime, Bestandsdaten, Bestellungen, Zahlungsanbieter und Worker werden
nicht mit dem Websiteartefakt ausgerollt. Die manuelle Repositoryvariable
`CARMAJA_PRODUCTION_DEPLOY_ENABLED` bleibt standardmäßig `false`.
Neue Runtimevorlagen behalten sichere deaktivierte Voreinstellungen.

## Kontrollierte Inbetriebnahme nach Integration

1. Aktuelle Archivierungsentscheidungen abschließen und Quelle, Versionen,
   Hashes sowie Bilder mit der Modell-4-Projektion vergleichen. Keine Artikel
   allein aufgrund fehlender Einträge aus der Quelle löschen oder reaktivieren.
2. Die geprüfte öffentliche Projektion samt zugehörigen Bildern als einmaligen
   Katalogabgleich integrieren. Keinen Sammelcommit als Einzelprodukt-Publish
   kennzeichnen; der erste Gesamtabgleich verwendet den manuellen Deployweg.
3. Frische Sicherung, Rückfallstand und unveränderten Bestand/Bestellstand prüfen.
4. Aktuellen freigegebenen `main`-SHA manuell bereitstellen; Übersicht, alle
   Detailseiten, Sitemap, Bilder und deaktivierte Kaufoptionen prüfen.
5. Erst nach erfolgreichem Gesamtabgleich die zwei Publisherflags in der aktiven
   privaten Runtime gemeinsam atomar aktivieren. Unbeteiligte Runtimewerte und
   Secrets unverändert lassen; gesicherte Rücknahme vorhalten.
6. Den nächsten autorisierten Publish-/Archivevorgang bis zum tatsächlichen
   Websiteergebnis verfolgen. Ein API-Publisherfolg allein ist kein
   Deploymentnachweis. Keine Bestandsbuchung oder Bestellung für diesen Test.

Bei einem Fehler Publisherflags wieder deaktivieren. Keine Datenbank- oder
Bestandsrücksetzung durch einen Websiterollback. API-/Website-Deployschalter
bleiben außerhalb eines ausdrücklich freigegebenen manuellen Laufs `false`.

## Durchgeführte Archivierung und lokale Nachweise

- Isolierte Kopie auf `codex/production-catalog-sync`, Basis
  `9bcc4d019a8c9c60628a7324a976d668b45085bb`; fremde Worktrees unverändert.
- 14 veröffentlichte Modell-4-Produkte gegen die autoritativen Entwürfe geprüft:
  Version, serverseitiger Hash und 56 JPEGs stimmen überein. Preise, Texte und
  Verkaufsfreigaben unverändert übernommen; Amazonit bleibt `salesEnabled=false`.
- Artemis (`CP-2026-0004`), Athena (`CP-2026-0006`) und Helios (`CP-2026-0007`)
  nach ausdrücklicher Betreiberfreigabe über den vorhandenen Archivierungsvertrag
  archiviert. Zuvor wurde das verschlüsselte Backup
  `20261115T113215Z-8bcc0b5bbdb3` erzeugt und mit dem vorhandenen Backupdienst
  einschließlich Manifest-HMAC, Ready-Marker und Dateiprüfsummen verifiziert.
- Aktive TLS-Sitzung und keine ausstehende Bestandsinitialisierung für diese
  drei Artikel nachgewiesen. Vollständiger Vorher-/Nachhervergleich der Tabellen
  `commerce_inventory`, `inventory_adjustments`, `orders`, `order_items`,
  `payments`, `checkout_sagas` und `reservations`: unverändert. Keine Zeilen,
  Zugangsdaten oder deren Prüfsummen werden in diesem Bericht abgelegt.
- Runtime unverändert, GitHub-Adapter weiterhin aus. Keine API-/Website-
  Bereitstellung und kein Push. Archivierung erhält private Produktbilder und
  historische Daten. Die 16 alten öffentlichen Bildkopien der sechs nicht mehr
  veröffentlichten Artikel sind nur im lokalen Kandidaten entfernt und aus Git
  wiederherstellbar; die Originaluploads bleiben erhalten.
- PHP 8.4: 60 Produkt-API-Tests einschließlich neuem zustandsbehaftetem
  Modell-4-GitHub-Mock für Erstveröffentlichung, Änderung von Text/Preis/Bildern,
  Archivierung, Wiederherstellung und idempotente Wiederholung bestanden.
  Fremde Produkte bleiben bytegleich, veraltete öffentliche Bilder werden
  entfernt, kein Force-Push und keine echte GitHub-/Datenbankverbindung im Mock.
- 14 Bootstrap-Tests, 28 Verkaufsfreigabe-/Bestandschecks, 389 künstliche
  Modell-4-Produktionsaufrufchecks und 39 temporäre Website-Overlaychecks bestanden.
- 278 Node-Tests, ESLint, Deployment-Shelltests mit Rollbackprüfung sowie
  Produktions- und Testexport bestanden. Der zusätzliche PHP-Klickstatistikcheck
  innerhalb der Bash-Suite wurde wegen fehlendem Linux-PHP übersprungen; die
  Statistikimplementierung ist nicht Teil dieser Änderung.
- Lokaler Produktionsexport: alle 14 Detailseiten samt Übersicht/Sitemap,
  56 Originalbilder hashgleich und die sechs alten Produktadressen nicht mehr
  enthalten. Amazonit bleibt sichtbar, ohne Kaufbedienung.

Ein erster Archivierungsversuch stoppte bei einer rein lesenden Tabellenprüfung
vor der ersten Artikelmutation: Die Audit-Tabelle heißt `inventory_adjustments`,
nicht `inventory_audit`. Nach Korrektur wurde der vollständige Schutzvergleich
erfolgreich durchgeführt. Die ursprünglichen Daten blieben beim Abbruch intakt.

## Integration und noch offene Nachweise

Am 15. November 2026 hat die Betreiberin Commit, Push, PR und nach erfolgreicher
CI den kontrollierten Merge dieser Katalog-/Publisherkorrektur freigegeben.
Anschließend sind Livebereitstellung und vollständige Lebenszyklusprüfung
beauftragt. Bestände und Bestellungen müssen unverändert erhalten bleiben.

- Git-Integration nach der erteilten Freigabe. Während der
  Prüfung wurde unabhängig PR #111 in `main` integriert; der neue Stand
  `65e5dfe1e2cb2c4470cef2cc6d2c260ec52b05ff` enthält Material-/Pflegetexte in
  `website/content/site-content.ts` und einen zugehörigen Test. Diese Änderungen
  nicht überschreiben; vor der Bereitstellung kontrolliert übernehmen und den
  kombinierten Stand erneut prüfen.
- Direkt vor der Integration erneut prüfen, ob die Betreiberin seit dem
  Katalogsnapshot weitere Artikel geändert/veröffentlicht/archiviert hat.
- Erstbereitstellung und tatsächlicher automatischer Publish-/Änderungs-/
  Archivnachweis bis zur öffentlichen Website. Mock- und Exporttests ersetzen
  diesen noch offenen Live-Nachweis nicht.

## Verbindliche Live-Abnahme nach Freigabe

| Vorgang | Erwartung und Prüfumfang |
| --- | --- |
| Gesamtabgleich | Exakt freigegebene aktuelle Produkte in Übersicht und Sitemap; jede Detailseite, Originalbilder und kleine Vorschauen erreichbar; alte Produktadressen entfernt. |
| Neue Kollektion veröffentlichen | Genau ein Publishercommit; Workflow erfolgreich; neues Produkt mit identischer Version/Hash, Preis, Messwerten, Bildern und Hinweisen auf der Website. |
| Vorhandene Kollektion ändern | Speichern allein veröffentlicht nicht. Nach erneutem Veröffentlichen geänderte Texte/Preis/Bilder sichtbar, alte Inhalte ersetzt, gleiche Identität/Adresse und keine Duplikate. |
| Löschen/Archivieren | Produkt aus Übersicht, Sitemap und Detailseite entfernt; keine neue Kaufmöglichkeit. Private Originale, Bestände und Bestellsnapshots bleiben erhalten. |
| Wiederholung/Fehler | Gleiche Operation erzeugt keinen zweiten Commit; Versionskonflikt überschreibt keine neueren Daten; fehlgeschlagenes Deployment liefert Fehler statt falscher Erfolgsbestätigung und ist rollbackfähig. |
| Schutz | Kein Checkout, keine Bestellung oder Bestandsbuchung durch die Prüfung; keine anderen Runtime-/Provider-/API-/Workeränderungen; alle nicht zugehörigen Artikel unverändert. |

Für echte Anlegen-/Ändern-/Archivieren-Proben nur eine ausdrücklich freigegebene
Prüfkollektion ohne Verkaufsfreigabe verwenden; reale Artikel nicht eigenmächtig
inhaltlich ändern. Abschließend Prüfkollektion archivieren und deren öffentliche
Artefakte entfernen. Dauerhaft benötigte Audit-/Operationsnachweise erhalten.

Diese Dokumentation behauptet keinen bereits erfolgten Liveabgleich.
