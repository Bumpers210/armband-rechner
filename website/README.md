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

## Oeffentliche Produktdaten

Produkte duerfen nur ueber die validierte Oeffentlichkeits-Whitelist aus `lib/public-products.mjs` in den Export gelangen. Interne Verwaltungs-, Kalkulations- und Entwurfsdaten bleiben ausgeschlossen.
