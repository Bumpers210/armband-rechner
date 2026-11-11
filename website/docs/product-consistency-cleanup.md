# Produktversionen und Quellhashes: Korrektur und Bereinigungsplan

Stand: 11. November 2026. Lokale Basis: main `f2ca27f18350914a1e63631739071dbf6074450d`
(PR #94), isolierter Branch `codex/cart-product-consistency`.
Status: lokale Korrektur; **keine Datenbereinigung oder Produktionsfreigabe**.

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
