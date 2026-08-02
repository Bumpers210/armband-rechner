# AP2a-Abnahme – technische Legal-Seiten und Legal-Bundles

Stand: 2026-08-02  
Worktree: `G:\BS-Stein-Hart-ap2a`  
Branch: `feature/shop-ap2a`

## Umfang

AP2a stellt die vier technisch erreichbaren Seiten
`/shopbedingungen/`, `/datenschutz/`, `/widerruf/` und
`/versand-und-zahlung/` bereit. Die Seiten lesen ausschließlich das für das
jeweilige Buildziel ausgewählte versionierte Legal-Bundle. Der Footer verlinkt
alle vier Einstiege dauerhaft.

AP2a enthält keine Stripe-Checkout-Änderung, keine Bestandsmutation, keinen
produktiven Cutover und keine externe Rechtsfreigabe.

## Bundle-Vertrag

Jedes Bundle besitzt eine kanonische ID, Version, Umgebung, Status, Archiv-URL,
Erstellzeitpunkt, Textabschnitte und einen serverseitig berechneten SHA-256-
Inhaltshash. Der Hash wird über die kanonisch sortierte Darstellung ohne das
Hashfeld gebildet. Eine Manipulation oder ein falsches Archivziel wird
fail-closed abgewiesen.

Der Test-Build verwendet:

```text
ID: cmj-test-legal-2026-08-02-v1
Status: test_only
Archiv: /legal-archive/test/cmj-test-legal-2026-08-02-v1/
```

Die Inhalte sind künstliche Testplatzhalter und auf jeder Seite ausdrücklich
als `TESTFASSUNG – NICHT RECHTLICH FREIGEGEBEN` gekennzeichnet.

Der Produktions-Build verwendet getrennt:

```text
ID: cmj-production-legal-2026-08-02-v1
Status: awaiting_external_approval
Archiv: /legal-archive/production/cmj-production-legal-2026-08-02-v1/
```

Die Produktionsplatzhalter behaupten keine rechtliche Prüfung oder Freigabe.
Ein Bundle mit diesem Status darf keinen Checkout-Snapshot freigeben.

## Checkout-Zuordnung

`legalBundleSnapshot(bundle)` erzeugt die unveränderlichen Referenzfelder
`legalBundleId`, `legalBundleHash`, `legalBundleVersion` und `environment`.
`assertLegalSnapshotMatchesBundle` prüft diese Referenzen gegen das validierte
Bundle. Damit kann AP3 einen Legal-Bundle-Snapshot atomar mit einem Checkout
speichern, ohne den Inhalt nachträglich aus einer veränderlichen Quelle zu
beziehen.

## Nachweise

| Nachweis | Ergebnis |
| --- | --- |
| Bundle-Hash und kanonische Darstellung | bestanden |
| Test-/Produktions-Umgebung getrennt | bestanden |
| Teststatus für Test-Checkout, Produktionsstatus blockierend | bestanden |
| Snapshot-ID, Hash, Version und Umgebung | bestanden |
| vier statische Testseiten | bestanden |
| öffentliche Footer-Einstiege | bestanden |
| `npm run lint:test` | bestanden, keine Fehler |
| `node --test tests/node/legal-bundles.test.mjs` | 6/6 bestanden |
| `npm run build:test` und Exportprüfung | bestanden |
| vollständige `npm test`-Suite | 49 bestanden, 7 bekannte CRLF-Baselinefehler außerhalb AP2a |
| AP3, Deployment und Produktion | nicht begonnen / unverändert |

## Abnahme

AP2a ist technisch abgenommen. Die vier Seiten und die Bundle-Mechanik sind
implementierungsbereit, aber eine öffentliche Produktionsfreigabe bleibt bis
zu extern geprüften Rechtstexten, Stripe-Konfiguration und den späteren
AP3/AP6-Gates gesperrt.
