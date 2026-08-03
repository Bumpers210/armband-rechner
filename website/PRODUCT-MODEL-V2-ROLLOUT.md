# Produktmodell V2: Produktionsreihenfolge

Dieser Ablauf ist eine Vorbereitung. Er aktiviert weder den Publisher noch
einen Deployment-Schalter und enthält keine Produktionspfade, Zugangsdaten oder
Laufzeitkonfigurationen.

## Voraussetzung

Der Website-Hotfix akzeptiert bestehende V1-Quelldaten nur während der
Übergangszeit und erzeugt daraus ausschließlich V2-Ausgabewerte. `stock` und
`vintedUrl` werden dabei nie in Seiten, Typen oder den statischen Export
übernommen. Eine fehlende Perlengröße wird als noch nicht hinterlegt angezeigt.

`commerceInventory.onHand` ist kein öffentliches Produktfeld und bleibt von
Website-Builds, Website-Rollbacks und der späteren Produktmodellmigration
unverändert.

## Spätere Freigabereihenfolge

1. Den V2-Website-Hotfix nach `main` übernehmen und den Produktions-Website-
   Build sowie das ausdrücklich freigegebene Deployment erfolgreich abschließen.
2. Die private Produktions-API außerhalb aller öffentlichen Webroots
   bereitstellen.
3. Die API ausschließlich mit nicht schreibenden Prüfungen verifizieren.
4. Die private Datenmigration zunächst ausschließlich als Dry-Run ausführen.
5. Backup und Dry-Run-Prüfbericht kontrollieren.
6. Die Datenmigration ausdrücklich freigeben und erst dann ausführen.
7. Den Publisher mit genau einem Testprodukt prüfen.
8. Erst danach die Release-App auf die Produktions-API umstellen oder eine
   entsprechende Release-App veröffentlichen.

Jeder Schritt stoppt bei einem Fehler. Ein fehlgeschlagener Website-Build,
eine fehlgeschlagene Nur-Lese-Diagnose oder ein auffälliger Dry-Run darf nicht
durch einen Aktivierungsschritt umgangen werden.
