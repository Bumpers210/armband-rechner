# Kontrollierte Produktmodell-V2-Migration

Diese Anleitung ist eine Vorbereitung. Sie startet weder ein Deployment noch
eine Datenmigration und aktiviert weder Publisher noch GitHub-Adapter.

## Vorbedingungen fuer eine spaetere Bereitstellung

Im geschuetzten GitHub-Environment `carmaja-production` muessen ausschliesslich
als Environment-Variablen vorhanden sein:

- `CARMAJA_PRODUCTION_API_WEBROOT`: separater, nicht mit der Website geteilter
  API-Webroot.
- `CARMAJA_PRODUCTION_PRIVATE_DIR`: privater Datenbereich ausserhalb aller
  Webroots.
- `CARMAJA_PRODUCTION_API_DEPLOY_WORKSPACE`: isolierter Staging- und
  Rollbackbereich.
- `CARMAJA_PRODUCTION_API_RUNTIME_CONFIG`: unversionierte Runtime-Konfiguration
  ausserhalb des Repositorys.
- `CARMAJA_PRODUCTION_API_DEPLOY_ENABLED`: vor jedem Lauf manuell `true`, nach
  jedem Lauf wieder `false`.

`website/scripts/configure-production-api-environment.ps1` fragt die vier
Pfadvariablen interaktiv ab, speichert sie im geschuetzten Environment und
setzt das Gate auf `false`. Das Skript weder ausfuehren noch die Werte in Chat,
Commit oder Log ausgeben, bevor die IONOS-Pfade fachlich bestaetigt sind.

Die PHP-Dateien muessen aus dem API-Webroot lesbar sein, waehrend der private
Datenbereich und die Runtime-Konfiguration niemals oeffentlich auslieferbar
sein duerfen. Der vorhandene SSH-Zugang braucht nur Rechte fuer diese
freigegebenen API-Zielbereiche; Website-Webroot, statischer Export und
Commerce-Daten bleiben ausgeschlossen.

## Reihenfolge nach ausdruecklicher Freigabe

1. API-Artefakt aus einem bestandenen Commit bereitstellen, ohne den Publisher
   zu aktivieren.
2. Nicht schreibende API- und Pfadpruefung ausfuehren.
3. `migrate-v2 --dry-run` ausfuehren und den privaten Bericht kontrollieren.
4. Backup erstellen, dessen Manifest pruefen und Restore-Dry-Run ausfuehren.
5. `migrate-v2 --apply` nur nach interaktiver Bestaetigung ausfuehren.
6. Einen V2-Entwurf mit positiven cm- und mm-Werten speichern; ein fehlender
   mm-Wert muss Speichern und Veroeffentlichen blockieren.
7. Publisher erst in einem getrennten, ausdruecklich freigegebenen Vorgang
   aktivieren und dann einen statischen Website-Export pruefen.

Vor dem ersten erfolgreichen V2-Schreibvorgang ist ein technischer Rollback
ueber das Backup moeglich. Danach werden nur Vorwaertskorrekturen vorgenommen.
`commerceInventory.onHand` ist kein Teil dieser Migration.

Der Workflow `.github/workflows/deploy-production-api.yml` ist nur manuell
ausfuehrbar. Er verlangt den exakten Commit-SHA, `DEPLOY_PRODUCTION_API`, das
explizite Deploy-Gate sowie die geschuetzten SSH-Secrets. Er paketiert weder
eine Runtime-Konfiguration noch private Daten, Website-Export oder Publisher.
Vor dem ersten Lauf muss IONOS die Webserver-Variable `CARMAJA_BOOTSTRAP_FILE`
auf den privaten `program/bootstrap.php`-Pfad setzen; diese Servereinstellung
liegt bewusst ausserhalb des Repositorys. Der Workflow aktualisiert keine
bestehende API-`.htaccess`, damit diese private Bootstrap-Verknuepfung erhalten
bleibt.
