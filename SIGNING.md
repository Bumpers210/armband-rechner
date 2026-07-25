# Release-Signatur sichern

Für spätere Updates müssen diese beiden Dateien **gemeinsam und dauerhaft**
gesichert werden:

- `.signing/armband-rechner-release.jks`
- `.signing/keystore.properties`

Der Keystore enthält den privaten Release-Schlüssel. Die Properties-Datei
enthält die dazugehörigen zufällig erzeugten Zugangsdaten. Beide Dateien
gehören in eine geschützte, vom Projekt getrennte Sicherung. Sie dürfen nicht
in Git, Quellcode, APKs, Build-Protokolle oder öffentlich zugängliche Ablagen
gelangen.

Ohne genau diesen Keystore und seine Zugangsdaten kann Android eine spätere
APK nicht als Update der installierten App akzeptieren. Bei Verlust wäre nur
eine Neuinstallation unter Verlust der lokalen App-Daten möglich.

Das Verzeichnis `.signing/` ist vollständig in `.gitignore` ausgeschlossen.
Der Release-Alias lautet `armband-rechner`.

