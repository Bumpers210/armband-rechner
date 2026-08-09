# Android-Signaturen sichern

## Produktions-Release

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

## Produktverwaltung-Beta

Die parallel installierbare Test-App verwendet einen eigenen, dauerhaft
stabilen Beta-Schluessel. Release- und Beta-Schluessel duerfen niemals
ausgetauscht oder gemeinsam verwendet werden.

Lokal gehoeren diese Dateien zusammen:

- `.signing/carmaja-product-management-beta.jks`
- `.signing/beta-keystore.properties`

Der Alias lautet `carmaja-product-management-beta`. Das fest erwartete
SHA-256-Zertifikat lautet:

```text
23:5E:E4:D7:46:FD:F5:49:0D:F3:C2:92:F8:82:A7:64:29:79:5E:99:FE:AE:B5:8D:EA:37:44:3E:94:4D:5D:2A
```

Fuer GitHub Actions werden ausschliesslich diese Repository-Secrets benoetigt:

- `CARMAJA_BETA_KEYSTORE_BASE64`
- `CARMAJA_BETA_STORE_PASSWORD`
- `CARMAJA_BETA_KEY_PASSWORD`

Der Workflow decodiert den Keystore nur in das temporaere Runnerverzeichnis,
prueft das gepinnte Zertifikat vor und nach dem Build und entfernt die Datei
anschliessend. Bei fehlenden Secrets, einem anderen Keystore oder einer
Debug-Signatur bricht der Build ab.

Die Secrets werden aus den lokalen Sicherungsdateien eingerichtet, ohne Werte
in Befehlsargumenten oder Protokollen auszugeben. Keystore und Properties
muessen danach gemeinsam in einer verschluesselten, vom Repository getrennten
Sicherung abgelegt werden.

Die bisherige Test-App war mit einem kurzlebigen Debug-Schluessel signiert.
Vor der ersten stabil signierten Beta ist deshalb einmalig eine Deinstallation
noetig. Dabei gehen lokale Test-App-Daten verloren. Danach sind Updates nur
moeglich, solange genau dieser Beta-Keystore erhalten bleibt.
