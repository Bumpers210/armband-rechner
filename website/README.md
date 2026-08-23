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

Die geschützte Testwebsite verwendet ausschließlich die manuell verwaltete
Basic-Auth-Datei `/home/www/carmaja-test-auth/test-website.htpasswd`. Sie liegt
außerhalb von Webroot, Export, Manifest und Deploymentworkspace.

## Produktionsdeployment

Der Produktionsworkflow baut auf `release/production-product-management` und `main`, darf aber ausschliesslich von `main` aus deployen. `CARMAJA_PRODUCTION_DEPLOY_ENABLED` ist als Repository-Variable standardmaessig `false`. Ein freigegebener Lauf setzt sie nur kurz auf exakt `true`; der Workflow setzt sie am Ende unabhaengig vom Ergebnis wieder auf `false`.

Eine eng begrenzte Ausnahme gilt für den dauerhaft aktivierten
Collection-v4-Publisher: Ein direkter, nicht erzwungener Folgecommit auf
`main` wird ohne erneute manuelle Freigabe bereitgestellt, wenn er die feste
Publisherkennzeichnung trägt und ausschließlich `products.json` sowie bis zu
fünf nummerierte Bilder genau einer SKU ändert. Der Workflow prüft Akteur,
Vorgänger, Committext, Produktmodell und Pfade vor jedem Serverzugriff. Für
Code- oder allgemeine Websiteänderungen bleibt der manuelle Deployweg
unverändert.

Das Paket enthaelt nur den geprueften statischen Export, ein SHA-256-gebundenes Manifest und die Archivpruefsumme. `website/hosting/**`, PHP-Dateien und geschuetzte Serverbereiche werden nicht paketiert. Beim ersten Produktivlauf verweigert der Aktivierer jede Kollision mit einer nicht bereits im serverseitigen Baseline-Manifest verwalteten Datei. Dieses Manifest ist vor der ersten Aktivierung nach einer manuellen Bestandsaufnahme zu erstellen und freizugeben.

## Oeffentliche Produktdaten

Produkte duerfen nur ueber die validierte Oeffentlichkeits-Whitelist aus `lib/public-products.mjs` in den Export gelangen. Interne Verwaltungs-, Kalkulations- und Entwurfsdaten bleiben ausgeschlossen.

Veröffentlichte Armbänder verwenden künftig das Kollektionen-Modell: Sie sind
ohne Bestandsmenge wiederholt bestellbar, bis sie in der App archiviert werden.
Der technische Vertrag und die Aktivierungsgrenzen stehen unter
`docs/collection-availability.md`. Die neuen v4-/Shop-v2-Schalter bleiben bis
zur Testabnahme deaktiviert.
