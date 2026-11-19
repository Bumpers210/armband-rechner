# Statische Performance-Optimierungen

Der lokale Stand basiert auf dem freigegebenen Main-Commit 542521d5e3fabc606421a19ae9502665ee5f87ae. Er verändert weder Produkttexte noch Shop-API, Warenkorb oder Checkout. Noch nicht veröffentlicht.

## Bilder

Logo und Hero behalten ihre Originaldateien und das bisherige Layout. Die versionierten Varianten unter public/images/site-previews werden mit dem vorhandenen Sharp erzeugt:

    npm run images:site

Nach einer Änderung der Originale ist dieser Befehl nötig. Das Manifest content/site-image-previews.json bindet die Varianten an Originalinhalt und Rezept. Der Export prüft die Bindung; veraltete oder fehlende Varianten verhindern einen Build. Logo-PNG und verlustfreie WebP-Varianten erhalten die Transparenz. Der Hero erhält responsive WebP-Varianten sowie einen passenden hoch priorisierten Preload. Metadaten und Social-Media-Bilder verwenden weiterhin die bestehenden Original-URLs.

Produktvorschauen behalten ihre bisherigen JPEG-Fallbacks. WebP mit Qualität 86 und zusätzlicher langer Kante 960 wird beim Export aus denselben Originalen erzeugt. Die WebP-Pfade enthalten webp-v1: bei späteren Änderungen des Kodierungsrezepts ist diese Version vor Verwendung langer Cache-Laufzeiten zu erhöhen. Originalbilder werden erst beim Öffnen der Großansicht geladen. Die Hero-Auswahl berücksichtigt die für object-fit: cover nötige Auflösung statt nur die schmale Bildschirmbreite. Die native picture/img-Ausgabe ersetzt den im statischen Export unnötigen Next-Image-Clientcode. Header und Footer nutzen dieselben kleinen Logoquellen.

## Hosting-Konfiguration separat prüfen

Der markierte Block CARMAJA STATIC PERFORMANCE in hosting/.htaccess und .htaccess.publish.example ergänzt gzip für CSS, JavaScript und SVG, falls die optionalen Apache-Module verfügbar sind. JSON, PHP und Bestands-/Warenkorbdaten gehören nicht zu diesen Kompressionstypen.

Ein Jahr immutable gilt ausschließlich für erfolgreiche HTTP-200-Antworten aus den versionierten Next-, Produktvorschau- und Websitebildpfaden. HTML, normale Produktoriginale mit stabilen Dateinamen, API, Warenkorb, Checkout und Fehlerantworten bleiben außerhalb dieser Regeln. ETag/Last-Modified und bestehende Sicherheits-/Redirectregeln bleiben bestehen.

Der normale Produktionswebsite-Workflow schließt die Root-.htaccess absichtlich aus. Die Hosting-Regeln werden daher durch einen späteren gewöhnlichen Website-Deploy NICHT automatisch aktiviert. Vor einer ausdrücklich freigegebenen Veröffentlichung müssen sie über den getrennten Hosting-Wartungsweg mit der tatsächlichen Serverkonfiguration abgeglichen werden. Keine automatische Änderung von Produktionsservern oder Deployment-Workflows ist Bestandteil dieses lokalen Stands.

## Verifikation

    npm run lint -- --deny-warnings
    npm test
    npm run build

Die Apache-Integration läuft automatisch, wenn Apache unter /usr/sbin/apache2 verfügbar ist, oder mit CARMAJA_APACHE_BINARY und CARMAJA_APACHE_MODULES. Ohne Apache wird nur dieser zusätzliche Integrationstest als nicht verfügbar ausgewiesen. Der Test startet ausschließlich eine kurzlebige localhost-Instanz und prüft gzip/identity, Vary, Cache-Abgrenzung, API no-store, 404 und fehlende optionale Module. Die bestehenden Deployment-/Warenkorbtests bleiben aktiv.

Lokale Vorher-/Nachher-Messungen und Sicht-/Shopprüfungen sind von den öffentlichen PageSpeed-Messungen zu unterscheiden. Neue Google-PageSpeed-Ergebnisse für die optimierte Live-Seite sind erst nach einem später ausdrücklich freigegebenen Deploy möglich.

## Ergebnis der lokalen Prüfung am 18.11.2026

Geprüft wurde der uncommittete Stand im Branch codex/performance-optimierung. Der bestehende GitHub-CI-Ablauf wurde lokal mit Node 24.16.0 und gesperrten Abhängigkeiten nachgebildet: npm ci, npm audit --audit-level=moderate (0 Schwachstellen), Lint mit --deny-warnings (0 Warnungen/Fehler), npm test (295 Node-Tests erfolgreich, kein übersprungener Node-Test, einschließlich echtem Apache) und Produktionsbuild mit erfolgreicher Exportprüfung. Alle vier bestehenden Deployment-Shelltests waren erfolgreich. Der darin auf Linux mangels PHP ausgelassene Klickstatistik-Test wurde separat unter Windows mit PHP 8.4.22 erfolgreich ausgeführt. Es gab keinen GitHub-Push oder Workflowstart.

Die Browserprüfung erfasste 30 Seiten, 84 interne Ziele und alle 15 Produkte. Geprüft wurden Galerie/Originalzoom, mobile Navigation, Hinzufügen zum Warenkorb, Mengenänderung, Angebotspreis, Checkout-Weiterleitung mit lokalem Zahlungsstub sowie Ausverkauf, API-503 mit erneutem Abruf und ungültige Währung. Keine echte Bestellung oder Zahlung. Eine zusätzliche Prüfung aller 15 Produkte bestätigte unveränderte Beschreibungen und Product-Daten, korrekte aus der simulierten API abgeleitete Offer-Preise sowie übereinstimmende Versand-/Rückgaberichtlinien. Keine JavaScript-Seitenfehler.

Desktop 1440 × 900 und Mobil 390 × 844: Überschriften-, Logo- und Bildrechtecke auf Startseite, Katalog und Sodalith waren gegenüber dem bisherigen Produktionsexport exakt gleich. Screenshots wurden verglichen. Eine zusätzliche Layoutprüfung bei 320 × 760 war erfolgreich.

Die folgende Dateigrößenmessung erfolgte lokal im frischen Chrome-Kontext mit DPR 1; es sind tatsächlich ausgewählte Bilddateien, keine Google-Schätzungen. Andere Auflösungen/DPR wählen andere passende Varianten.

| Bild | Vorher | Nachher | Einsparung |
| --- | ---: | ---: | ---: |
| Logo | 333.166 B | 2.790 B | 99 % |
| Hero Mobil | 233.773 B | 95.176 B | 59 % |
| Hero Desktop | 233.773 B | 168.044 B | 28 % |
| Erstes Katalogbild | 58.306 B | 45.264 B | 22 % |
| Sodalith Hauptbild Mobil | 60.560 B | 49.046 B | 19 % |
| Sodalith Hauptbild Desktop | 168.914 B | 84.258 B | 50 % |

Drei frische lokale Aufrufe je Seite/Gerät ergaben LCP-Mediane (vorher → nachher): Desktop Startseite 248 → 280 ms, Katalog 292 → 252 ms, Sodalith 276 → 344 ms; Mobil Startseite 340 → 220 ms, Katalog 276 → 204 ms, Sodalith 316 → 212 ms. CLS war jeweils 0. Diese localhost-Läufe ohne CPU-/4G-Drosselung und mit simulierter Shop-API sind Funktions-/Regressionsmessungen. Sie belegen keine entsprechenden Live-LCP-Werte; die Desktopzeiten zeigen die Laufzeitschwankung. JavaScript blieb praktisch gleich groß (Startseite/Katalog +141 B, Sodalith −304 B unkomprimiert), eine erhebliche JS-Verkleinerung wird nicht behauptet. INP/Felddaten wurden nicht gemessen.

Offen bis zu einer später freigegebenen Veröffentlichung: reale Hosting-Kompression und Cache-Header aktivieren/prüfen, danach neue PageSpeed-Mobil-/Desktopmessungen mit demselben Seitenumfang. Die lokal getesteten Regeln sind noch nicht auf dem Server aktiv. Die bestehenden Produkttexte, Originalbilder, Rechtsangaben, Preis-/Bestandsquelle, Shop-API und Zahlungssteuerung sowie package-lock.json blieben unverändert.
