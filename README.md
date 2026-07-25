# Armband-Rechner

Schlichte Android-App zur lokalen Kalkulation von Armbandpreisen. Die App lädt
aktive Perlennamen und Stückpreise lesend aus dem öffentlichen Google-
Spreadsheet, speichert nur vollständig geprüfte Preislisten lokal und arbeitet
mit dem letzten gültigen Stand offline weiter.

## Download

Die installierbare APK und ihre SHA-256-Prüfsumme stehen unter
[GitHub Releases](https://github.com/Bumpers210/armband-rechner/releases/latest)
bereit. Hinweise zur Installation befinden sich in
[INSTALLATION.md](INSTALLATION.md).

## Technische Basis

- Paket: `de.steinhart.armbandrechner`
- Android: `minSdk 26`, `compileSdk 36`, `targetSdk 36`
- JDK 17, Gradle 9.5.0, Android Gradle Plugin 9.3.0
- integriertes Kotlin 2.2.10, Compose Compiler 2.2.10
- Compose BOM 2026.06.00, Lifecycle 2.10.0, DataStore 1.2.1
- eine Activity, ein ViewModel und ein Repository
- keine Anmeldung, kein API-Key, kein eigener Server

## Lokaler Build

Die lokal eingerichteten Werkzeuge liegen unter `.tools/` und sind nicht Teil
der Anwendung. Mit gesetztem `JAVA_HOME` kann das Projekt über den Wrapper
gebaut werden:

```powershell
.\gradlew.bat testDebugUnitTest lintDebug assembleRelease
```

Für einen signierten Release-Build müssen die in [SIGNING.md](SIGNING.md)
beschriebenen Dateien vorhanden sein.

## Datenquelle

- Spreadsheet-ID: `1PsiIr5pjKYPQIP0WxP3JPMn5y_sdWaM_hRT6tzzOIrU`
- Tabellenblatt: `Preisliste`
- Bereich: `A1:C`
- Spalten: `Name | Preis pro Stück | Aktiv`

Nur aktive Zeilen werden übernommen. Eine ungültige oder unvollständige
Antwort verändert weder den gespeicherten Preisbestand noch dessen
Synchronisierungszeit.
