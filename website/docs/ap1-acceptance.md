# AP1-Gesamtabnahme und Übergabe an AP2

Stand: 2026-08-02  
Branch: `feature/shop-ap1`  
Umgebung: isolierter Testbetrieb; keine Produktion und kein produktiver Cutover

## 1. AP1-Gates

| Gate | Nachweis | Status |
| --- | --- | --- |
| AP1.3 Produktmodell v2 | `priceMinor`, `currency`, `salesEnabled`, serverseitige `productVersion`, deterministischer `sourceHash`, Publisher ohne Bestandsmutation | bestanden |
| AP1.4 Migrations-Dry-Run | schreibfreier, wiederholbarer Bericht mit Datensatz- und Gesamthash; `stock`, Preis-, ID-, Versions-, externe Link- und Projektionsprüfungen | bestanden |
| AP1.5 Legacy-Schreibsperre | `stock_write_disabled`, `client_managed_field_forbidden`, `client_update_required`, v2-Payload ohne `stock`, Inventory-Vertragsvalidierung | bestanden |
| AP1.6 Backup/Restore | zwei getrennte MySQL-8-Testziele, PDO/TLS, InnoDB-Dump/Restore, Struktur-/Inhalts-/Prüfsummen, Manipulation, Rollback-Gates, Cleanup | bestanden |

## 2. Konfliktprüfung

Die AP1-Verträge sind untereinander konsistent:

- Preis und Währung kommen aus dem v2-Produktlesemodell; `priceMinor >= 50` und `currency = eur` werden geprüft.
- `productVersion` wird nur serverseitig monoton erhöht; Clients senden ausschließlich `expectedProductVersion`.
- `sourceHash` wird serverseitig aus der kanonischen Produktdarstellung erzeugt.
- Öffentliche v2-Daten enthalten weder `stock` noch externe Verkaufsfelder.
- `stock` ist kein zulässiger Produkt-Schreibweg mehr und wird mit `stock_write_disabled` abgelehnt.
- Inventory-Anpassungen verwenden `targetOnHand ∈ {0,1}`, `expectedInventoryVersion`, einen freigegebenen Grund, `correlationId` und `Idempotency-Key`.
- `shop_sale` ist als Grund für den Shopverkauf zulässig, aber keine manuelle Betreiberaktion.
- Der AP1.6-Rollback-Gate erlaubt die Rückkehr zu `stock` nur vor dem ersten Commerce-Checkout; danach wird sie mit `stock_rollback_locked` verhindert.
- Der AP1.6-Adapter hat keine Commerce-Tabellen oder produktiven Bestandsänderungen implementiert.

## 3. AP1.6-Praxisergebnis

Der serverseitige PHP-8.4/PDO-Nachweis verwendete zwei getrennte, leere MySQL-8-Testdatenbanken und ausschließlich künstliche AP1.6-Tabellen. `Ssl_cipher` und `Ssl_version` waren für beide Sitzungen befüllt. Der akzeptierte V1-Restpunkt fehlender CA-/Hostidentitätsprüfung blieb als Risiko markiert.

Bestanden wurden:

- Backup über den serverseitigen `mysqldump`-/`mysql`-Weg;
- Restore in das getrennte Ziel;
- semantischer InnoDB-Strukturvergleich;
- Inhalts- und SHA-256-Prüfsummenvergleich;
- Erkennung eines manipulierten Dumps;
- Rollback vor Checkout zulässig;
- Rollback nach Checkout gesperrt;
- Entfernung aller AP1.6-Tabellen, Hilfsdateien, Adapterdateien und Credentials.

## 4. Testnachweise

- PHP 8.4-Lint der betroffenen PHP-Dateien: bestanden.
- PHP-API-/Kompatibilitätssuite: 46 Tests bestanden.
- AP1.4-/AP1.5-/AP1.6-Node-Tests: bestanden.
- `npm run lint:test`: bestanden.
- Android `testDebugUnitTest`: bestanden.
- `git diff --check`: bestanden.
- Die vollständige Node-Suite enthält bekannte, AP1-fremde CRLF-/Deployment-Basisfehler; sie wurden nicht verändert.

## 5. Übergabe an AP2

AP2 darf als nächster separat freizugebender Schritt beginnen. Die Übergabe umfasst ausschließlich Verträge, nicht deren produktive Umsetzung:

1. MySQL-8/InnoDB-Commerce-Schema und PDO-Adapter entwerfen;
2. `commerce_products` und `commerce_inventory` getrennt modellieren;
3. `targetOnHand`/`expectedInventoryVersion` transaktional mit Row-Locks umsetzen;
4. die AP1-Idempotenz-, Versions-, Hash- und Legacy-Sperren übernehmen;
5. vor jeder produktiven Migration Backup, Restore und Cutover-Gate erneut nachweisen.

Nicht freigegeben sind weiterhin produktiver Bestands-Cutover, Commerce-Checkout, Stripe-Produktionsintegration, Deployment und Änderungen an `main`.

## 6. Abschlussstatus

AP1.3, AP1.4, AP1.5 und AP1.6 sind abgenommen. AP1 ist vollständig abgeschlossen; AP2 bleibt ausdrücklich unbegonnen.
