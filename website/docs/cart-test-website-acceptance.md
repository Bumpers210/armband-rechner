# Warenkorb – isolierte Testwebsite-Übernahme

Stand: 9. Oktober 2026. Umfang: ausschließlich öffentliche Testwebsite.

## Quellen und Sicherheitsgrenze

- Basis: aktueller Branch `test/product-management-beta`, einschließlich des
  bestehenden Mehrseitendesigns und der vereinfachten Erfolgsseite.
- Selektive Websiteübernahme aus `codex/shop-inventory-cart`; keine vollständige
  Branchzusammenführung und keine Übernahme von Android-, API- oder Datenbankcode.
- Vorhandene öffentliche Testproduktquelle und Produktbilder bleiben unverändert.
- Auslieferungsziel ausschließlich `/home/www/carmaja-test-site`.
- Test-API, private Runtime, Testprodukte, Commerce-Daten und Basic Auth werden
  durch dieses Website-Deployment weder ersetzt noch bereinigt.
- `main`, Produktion und `website/hosting/**` bleiben unverändert.

## Übernommene öffentliche Komponenten

- Warenkorbzugang im bestehenden Header und Seite `/warenkorb/`.
- Mengenwahl und „In den Warenkorb“ statt direktem Einzelcheckout.
- Gemeinsamer Shop-v3-Client mit No-Store, CSRF, Idempotenz und Warenkorbversion.
- Positions- und Mengenauswahl im weiterhin zweistufigen Widerrufsformular.
- Öffentliche Modelle 2/3 bleiben lesbar; Modell 4 unterstützt die unveränderbaren
  serverseitigen Hinweise. Bestehende Produkte erhalten keine Hinweise nachträglich.
- Versionierte Warenkorb-Testtexte `cmj-test-legal-2026-10-06-v3`, `test_only`;
  frühere Archive und freigegebene Produktionsfassungen bleiben unverändert.

## Nachweise vor Bereitstellung

- `npm ci`, `npm run lint:test`, `npm test`, `npm run build:test`.
- 96 Node-Tests bestanden, einschließlich der Modell-4- und Cart-Client-Verträge.
- 33 Linux-Shell-Szenarien für erfolgreiche Bereitstellung, Authschutz,
  fehlende Seiten, Fehlerbereinigung und Rollback bestanden.
- Exportprüfung: ausschließlich Test-API, keine Produktionsressourcen, keine
  privaten Produktfelder oder Laufzeitkonfiguration im Artefakt.
- Erhalt der bisherigen Produktquelle/Bilder und fehlender API-/App-Diff geprüft.
- `git diff --check` und begrenzter Datei-/Änderungsumfang geprüft.

## Deployment- und Live-Gate

Zunächst vollständiger Build-only-Lauf auf dem echten Testbranch bei
`CARMAJA_TEST_DEPLOY_ENABLED=false`. Erst bei Erfolg vollständige Wiederholung
mit zeitweise aktiviertem Testdeploygate; anschließend unabhängig vom Ergebnis
wieder `false`. Das Artefakt bleibt an Commit, Run-ID und Versuch gebunden.

Vor `mark_verified` werden Authschutz, Sicherheitsheader, Warenkorb, Legal-
und Rückkehrseiten sowie alle erhaltenen Produktseiten/Bilder live geprüft.
Jeder Fehler führt zum manifestgebundenen Website-Rollback.

## Bewusst noch offen

Die Shop-v3-Runtime wird hier nicht bereitgestellt. Fehlt sie, bleiben Warenkorb
und Kaufen verständlich fail-closed; es gibt keinen statischen Bestandsfallback
und keinen Rückfall auf den bisherigen Checkout. Dieses Teilpaket ist eine
visuelle Websitebereitstellung, keine Gesamtfreigabe der Warenkorberweiterung.

Gerätetest mit Beta 1.4.0-beta.1/Code 10, vollständige App-/Runtime-E2E-Abnahme,
erneute Rechtsfreigabe und jede produktive Umstellung bleiben eigene Gates.

Die unveränderte Abhängigkeitsbasis meldet npm-Audit-Warnungen (darunter Next.js).
IONOS erhält ausschließlich statische Exportdateien, keinen Node-/Next.js-Server,
keine Image-Optimization-API und keine Entwicklungsserverendpunkte. Ein
Abhängigkeitsupdate wird nicht als Nebenänderung in dieses Deployment aufgenommen.
