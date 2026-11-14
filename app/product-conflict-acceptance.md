# Lokaler Zwei-Geräte-Abgleich – 14. November 2026

## Umfang und Ursache

Lokale Korrektur auf `codex/app-product-conflicts`, isoliert aus dem zuletzt
bestätigten main-Commit `3a605f9f2568a266b4fcc8cf64d0b436196c7351`.
Keine Produktionsabfrage, kein Zugriff auf reale Handyentwürfe, kein Commit,
Push, Merge, Deployment oder Release. Die tatsächliche Änderungshistorie des
gemeldeten Amazonit-Artikels wurde nicht aus Produktionsdaten ermittelt.

Der Server schützt Produktmodell 4 / API `/v5` korrekt durch
`expectedProductVersion`. Bisher wurden vorhandene lokale Entwürfe beim
Listenabruf bewusst übersprungen; Synchronisieren begann mit einem PUT.
Ein veralteter lokaler Entwurf konnte daher einen Konflikt zwar anzeigen,
aber nicht sicher auflösen. Zusätzlich zeigte die Fehlermeldung irreführend
die Entwurfsversion statt der Produktversion und einen Verbindungshinweis.

## Korrigierter Ablauf

1. Am bestehenden Artikel „Serverstand vergleichen“: ausschließlich GET;
   keine Speicherung, kein Upload und keine Änderung des lokalen Entwurfs.
2. Bei bestätigtem Versionskonflikt ebenfalls den Vergleich öffnen. Namen,
   Preise, Verkaufsfreigabe, Materialien, Messwerte, Beschreibungen,
   Bildzuordnungen und serververwaltete Hinweise gegenüberstellen.
3. Keine vorausgewählte Auflösung. Abbrechen lässt beide Fassungen unverändert.
4. „Serverfassung laden“ oder „Eigene Fassung weiterbearbeiten“ erst nach
   ausdrücklicher Auswahl und Bestätigung. Vorher den Server erneut lesen;
   jede zwischenzeitliche Änderung verwirft die Auswahl und verlangt einen
   erneuten Vergleich.
5. Vor der lokalen Übernahme eine vollständige lokale Sicherung mit Bildern
   erstellen. Fehlende Bilder, Schreibfehler oder ungültige heruntergeladene
   Bilder brechen ab. Eine neue Server-Bildfassung ersetzt keine alte Bilddatei.
6. Bei eigener Fassung bleiben die bearbeitbaren Inhalte erhalten; nur
   serververwaltete Version/Hash/Status/Hinweise und Bildbestätigungen werden
   abgeglichen. Alte Produktoperations-IDs werden verworfen, ein späteres
   ausdrücklich ausgelöstes Speichern erhält einen neuen Idempotenzschlüssel.
7. Der Abgleich selbst führt weder PUT noch Veröffentlichung aus. Eine spätere
   Speicherung/Veröffentlichung bleibt versionsgeprüft. Bestand, Reservierungen,
   Verkauf und Inventarinitialisierung werden nicht ausgelöst. Eine bestehende
   lokale Inventarvormerkung bleibt unverändert.

Kalkulation und ursprüngliches Anlagedatum bleiben auch beim Laden der
Serverfassung erhalten. Die lokale Sicherung liegt ausschließlich im privaten
App-Speicher unter `files/product-conflicts/<draftId>/<UUID>/`, mit `draft.json`
und eigenen Bildkopien. Sie wird weder hochgeladen noch als neuer Artikel
importiert oder automatisch gelöscht. Eine allgemeine Sicherungsverwaltung
oder automatische Zusammenführung einzelner Felder ist nicht Teil dieser
Korrektur. App-Daten nicht löschen und die App nicht deinstallieren.

Auch eine fremde Änderung zwischen eigenem Speichern und abschließendem GET
wird jetzt als Konflikt behandelt, statt fremde Felder still zu übernehmen.
Ein bereits bestätigtes Bild hebt einen gleichzeitigen fremden Preis- oder
Textkonflikt nicht mehr auf. Lokale JSON-Schreibvorgänge sind atomar; eine
unterbrochene Altgeräte-Schreiboperation wird aus ihrer lokalen Sicherung
gelesen, unvollständige Stagingdateien werden nicht als Artikel importiert.
Unklare Netzwerkfehler bleiben getrennt; die bestehende Wiederholung mit
Idempotenz wird nicht als Erfolg dargestellt.

## Nachweise

- 79 JVM-Tests bestanden, darunter neun neue Konflikttests mit zwei unabhängigen
  Geräten an einer künstlichen gemeinsamen V5-Quelle: Versionskonflikt,
  bewusste Auflösung, erneute Änderung während des Vergleichs, unterschiedliche
  Entwurfs-/Produktversionen, Bild-/Hashänderungen, Netzwerk-/Sicherungsfehler,
  unveränderte lokale Nutzdaten, neuer Idempotenzschlüssel und fremde
  Preisänderung während eines ansonsten bestätigten Bildtransfers.
- Android-Lint bestanden; vorhandene Hinweise, unter anderem zur bestehenden
  ExifInterface-Verwendung, bleiben außerhalb des Korrekturumfangs.
- Debug-APK, Instrumentierungs-APK und unsignierter Release-Validierungsbuild
  bestanden. Versionierung, Paketkennungen und Signaturverträge unverändert.
- Vier Node-Release-Vertragstests bestanden.
- Vollständige Offline-Instrumentierung mit erweitertem Dateischutz: 37/37
  bestanden. Enthält Auswahl/Abbruch/Doppelklickschutz, dauerhafte Text-/Bild-
  sicherung nach Serverübernahme und neuem Repository-Aufbau sowie zwei echte
  ViewModel-Instanzen mit getrennten künstlichen Datenspeichern an derselben
  In-Memory-V5-Quelle. Gerät A speichert; Gerät B wird beim Veröffentlichen
  abgewiesen, behält seine Fassung, löst bewusst auf und veröffentlicht erst
  bei einem zusätzlichen Auftrag. Keine reale API beteiligt.
- Ein bestehender Cursor-/Texteingabetest schlug in einem Zwischenlauf einmal
  fehl. Ohne Änderungen an diesem Test oder seiner Eingabelogik bestanden
  anschließend die gezielte Wiederholung (4/4) und der Gesamtlauf (37/37).
- `assembleBeta` geprüft, aber ohne vorhandene stabile Beta-Signierung im
  neuen Worktree erwartungsgemäß gesperrt. Keine Ersatzsignierung erzeugt,
  keine Signatur aus anderen privaten Ablagen gesucht. Ein signierter
  Beta-/Produktionsbuild und die Installation auf realen Handys bleiben
  getrennte Folgearbeiten nach Freigabe.

## Grenzen

Die veröffentlichten App-Versionen 1.4.0 / Code 8 und 1.4.0-beta.4 / Code 13
sind noch unverändert. Diese lokale Korrektur ist kein installierbares
Produktionsupdate und keine Freigabe für eine automatische Veröffentlichung
neuer Shopartikel. Eine spätere App-Auslieferung braucht Versionsanhebung,
PR/CI und den bestehenden stabilen Signierungsweg.

## Geänderte Dateien und Reproduktion

Unter `app/src/main/java/de/carmajaperlen/armbandrechner/`:
`MainActivity.kt`, `ProductApiClient.kt`, `ProductDraftRepository.kt`,
`ProductSynchronizer.kt`, `ProductUi.kt`, `ProductViewModel.kt` sowie neu
`ProductConflictResolution.kt` und `ProductConflictUi.kt`.

Tests: `ProductConflictResolutionTest.kt` (JVM) und
`ProductDraftRepositoryTest.kt`, `ProductConflictDialogTest.kt`,
`ProductTwoDeviceFlowTest.kt` (Android-Instrumentierung). Dazu dieser Nachweis.
Keine Änderungen an PHP, API-Schema, Bestand, Preisberechnung, Gradle,
Workflows oder Signierung.

Lokale Befehle mit vorhandenem JDK 17, SDK 36 und netzgesperrtem API-35-AVD:

```text
gradlew.bat testDebugUnitTest lintDebug assembleDebug assembleDebugAndroidTest --offline
gradlew.bat assembleRelease -Pcarmaja.allowUnsignedProductionValidation=true --offline
gradlew.bat connectedDebugAndroidTest --offline
gradlew.bat assembleBeta --offline
node --test website/tests/node/android-production-release.test.mjs
git diff --check
```

Der Beta-Befehl ist die oben beschriebene offene Signierungsprüfung, kein
erfolgreicher Artefaktnachweis. Diff- und Geheimwertprüfung bestanden.

Abschluss: Die 37 Offline-Tests wurden nochmals auf dem finalen gebauten
Korrekturstand vollständig erfolgreich ausgeführt. Der eigens erstellte
AVD samt künstlichen Daten, Hilfsskript und Logs wurde danach entfernt und
seine Abwesenheit geprüft. Bestehende Emulatoren, Handyentwürfe, Testprodukte,
Bestände und private Konfigurationen wurden nicht verwendet oder verändert.
