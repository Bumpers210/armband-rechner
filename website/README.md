# Carmaja-Perlen Website

Diese Next.js-Anwendung erzeugt den statischen Export der öffentlichen Carmaja-Perlen-Website.

## Lokale Pruefungen

```powershell
npm ci
npm run lint
npm test
npm run build
```

Der Produktionsbuild exportiert nach `out/`. Die Produktquelldatei unter `content/products.json` wird nicht in den oeffentlichen Export uebernommen.

## Produktionsdeployment

Der Produktionsworkflow baut auf `release/production-product-management` und `main`, darf aber ausschliesslich von `main` aus deployen. `CARMAJA_PRODUCTION_DEPLOY_ENABLED` ist als Repository-Variable standardmaessig `false`. Ein freigegebener Lauf setzt sie nur kurz auf exakt `true`; der Workflow setzt sie am Ende unabhaengig vom Ergebnis wieder auf `false`.

Das Paket enthaelt nur den geprueften statischen Export, ein SHA-256-gebundenes Manifest und die Archivpruefsumme. `website/hosting/**`, PHP-Dateien und geschuetzte Serverbereiche werden nicht paketiert. Beim ersten Produktivlauf verweigert der Aktivierer jede Kollision mit einer nicht bereits im serverseitigen Baseline-Manifest verwalteten Datei. Dieses Manifest ist vor der ersten Aktivierung nach einer manuellen Bestandsaufnahme zu erstellen und freizugeben.

## Oeffentliche Produktdaten

Produkte duerfen nur ueber die validierte Oeffentlichkeits-Whitelist aus `lib/public-products.mjs` in den Export gelangen. Interne Verwaltungs-, Kalkulations- und Entwurfsdaten bleiben ausgeschlossen.
