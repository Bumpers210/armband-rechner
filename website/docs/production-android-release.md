# Kontrollierter Produktions-App-Build

Dieser Ablauf erzeugt ein signiertes Produktions-APK ausschließlich als
kurzzeitig gespeichertes GitHub-Prüfarbeikt. Er installiert, veröffentlicht
oder startet die App nicht und verändert weder API noch Produktdaten.

## Sicherheitsgrenzen

- Der Workflow läuft nur manuell von `main`.
- Der vollständige erwartete Commit muss dem tatsächlich ausgecheckten
  `main`-Commit entsprechen.
- Die Bestätigung muss exakt `BUILD-SIGNED-CARMAJA-APP` lauten.
- Die Signierung liegt ausschließlich im geschützten GitHub-Environment
  `carma-production-app`.
- Vor der Signierung laufen Unit-, Lint- und isolierte Instrumentierungstests.
- Paket-ID, Version, Produktions-API und gepinntes Zertifikat werden am
  fertigen APK geprüft.
- Das Artefakt wird nach sieben Tagen automatisch gelöscht.
- Es wird kein GitHub-Release erstellt. Installation oder Weitergabe benötigen
  eine eigene ausdrückliche Freigabe.

## Manueller Prüflauf

Nach vollständig grüner CI und Merge des Workflows nach `main`:

```powershell
$Commit = gh api repos/Bumpers210/armband-rechner/branches/main --jq ".commit.sha"

gh workflow run android-production-release.yml `
  --repo Bumpers210/armband-rechner `
  --ref main `
  -f expected_commit_sha=$Commit `
  -f release_confirmation=BUILD-SIGNED-CARMAJA-APP
```

Danach ausschließlich Status und Prüfschritte ansehen:

```powershell
gh run list `
  --repo Bumpers210/armband-rechner `
  --workflow android-production-release.yml `
  --limit 1
```

Das APK darf erst nach einer separaten Installationsfreigabe heruntergeladen
und auf dem Produktionsgerät installiert werden.
