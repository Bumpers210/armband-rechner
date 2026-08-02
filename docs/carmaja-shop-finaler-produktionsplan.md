# Carmaja-Perlen Shop – finaler V1-Implementierungsplan mit V2-Ausbau

Stand: 2026-08-02  
Änderungsvermerk: AP1, AP2 und AP2a abgeschlossen; AP3 ist der nächste separat
freizugebende Abschnitt. AP2a ergänzt technische Legal-Seiten und versionierte
Test-/Produktions-Bundles; externe Rechtsfreigaben stehen aus.
AP1-Abschlusscommit: `21da119db1c57be095764f8f75bb0c9863ec1759`  
AP2-Abschlusscommit: `b874baa410b54894ca462326f402ead859370ab6`
Produktionsziel: ausschließlich der eigene Carmaja-Shop; Vinted und andere
parallele Verkaufskanäle werden vor AP7 entfernt und nicht synchronisiert.

## 1. Status und Leitplanken

AP0-A bis AP0-C.2 sowie AP1.1 bis AP1.7 sind abgenommen. AP1 umfasst das
Produktmodell v2, den serverseitigen `sourceHash`, die Mindest-App-Version,
die Legacy-Schreibsperre, das schreibfreie Migrationswerkzeug sowie den
praktischen MySQL-8-/InnoDB-Backup-/Restore-Nachweis. Der akzeptierte
V1-Restpunkt fehlender CA-/Hostidentitätsprüfung bleibt dokumentiert; eine
aktive TLS-Sitzung ist Pflicht.

AP2 ist vollständig abgenommen. AP2a ist technisch abgenommen. AP3, AP4, AP5,
AP6 und AP7 bleiben nicht freigegeben. Es gibt keinen produktiven Cutover, kein Deployment, keinen Push
und keinen Zugriff auf Produktionsdaten.

V1 bleibt ein direkter Einzelkauf ohne Warenkorb, Kundenkonto oder parallele
Marktplätze. Ein Checkout enthält genau ein Unikat mit Menge `1`. Nach dem
Produktionsstart ist der eigene Shop der einzige Verkaufskanal.

## 2. Autoritative Modelle

Die bestehende Produktverwaltung ist fachlich autoritativ für Name,
Beschreibung, Bilder, Preis, Währung und Verkaufsfreigabe. `commerce_products`
ist ein versioniertes lokales Lesemodell; es ist keine zweite
Produktverwaltung. `commerce_inventory.on_hand` ist die einzige veränderliche
Bestandsquelle. Der Product-Synchronisierer darf `on_hand` niemals ändern.

Ein Unikatbestand ist binär. Zulässige Vorgänge sind ausschließlich:

| Vorgang | Übergang | Auslöser |
| --- | --- | --- |
| `activate_new_unique` | `0 → 1` | Betreiber aktiviert ein neues Unikat |
| `shop_sale` | `1 → 0` | atomare erfolgreiche Shop-Finalisierung; keine manuelle Aktion |
| `mark_unsellable` | `1 → 0` | Betreiber markiert ein Unikat als beschädigt/nicht verkäuflich |
| `release_return` | `0 → 1` | tatsächlich eingegangene und geprüfte Rückgabe |

`adjustInventory(productId, targetOnHand, reason, expectedInventoryVersion,
correlationId, Idempotency-Key)` läuft in einer MySQL-Transaktion. Ein
manueller Aufruf mit `shop_sale` wird abgelehnt. Aktive, abgelaufene-
blockierende und prüfpflichtige Reservierungen verhindern eine externe
Bestandsreduzierung; es gibt keinen externen Verkaufskanal mehr.

## 3. AP2-Zielarchitektur

* MySQL 8 mit InnoDB ist die Commerce-Datenbank. SQLite/WAL ist verworfen.
* Jede fachliche Mutation verwendet kurze Transaktionen und `SELECT ... FOR
  UPDATE` auf den betroffenen Produkt-, Inventar- und Checkout-Zeilen.
* Innerhalb einer Transaktion finden keine Stripe-, Brevo- oder sonstigen
  Netzwerkaufrufe statt.
* Webhooks werden vor einer späteren HTTP-Bestätigung dauerhaft in der Inbox
  gespeichert. Die konkrete öffentliche Stripe-Integration bleibt AP3.
* Workerläufe verwenden einen Datenbank-Lock, zehnminütige Leases, kleine
  Batches und die Retry-Staffel 5 Minuten, 15 Minuten, 1 Stunde, 4 Stunden,
  12 Stunden, danach `manual_review` oder `failed`.
* Bestellnummern werden über eine transaktional gesperrte
  `order_sequences`-Zeile vergeben.
* Bestellung und Versand sind getrennte Statusachsen. `orders.status` kennt
  nur `confirmed` und `canceled`; Versandstatus liegt ausschließlich in
  `shipments`.
* Zahlungs-, Prüf-, Erstattungs- und Streitfallstatus werden getrennt
  gespeichert.

## 4. AP2-Arbeitspakete und Nachweise

### AP2.1 – Schema und Vorwärtsmigration

Lieferumfang: InnoDB-Schema, Fremdschlüssel, Checks, Indizes,
Schema-Migrationsjournal und getrennte Produkt-/Inventartabellen.

Nachweis: wiederholbare Migration auf leeres künstliches MySQL-8-Ziel,
idempotente Vorwärtsmigration, Strukturvergleich und Rollback-Gate
`stock_rollback_locked` nach einem Commerce-Checkout.

### AP2.2 – Commerce-Kern

Lieferumfang: Checkout-Snapshot, Reservierung, Zahlungsobjekt, atomare
Bestellfinalisierung, Bestellnummer, Inventory-Audit, Versandzeile und
Reviewcase-Erzeugung.

Nachweis: Parallelitäts-, Idempotenz-, Zustands- und Crash-Tests mit
künstlichen Daten. Ein unbezahlter oder abgebrochener Checkout erhält keine
Bestellnummer.

### AP2.3 – Inboxen, Outboxen und Worker

Lieferumfang: Webhook-Inbox, Mail-Outbox, Stripe-Metadaten-Outbox,
Reviewcases, Leases, Retry und manueller Notbetrieb. Externe Aktionen werden
nur außerhalb gehaltener Row-Locks ausgeführt.

Nachweis: zwei parallele Worker, Lease-Ablauf/Übernahme, Wiederanlauf,
begrenzte Batches und `manual_review` nach dem fünften Retry.

### AP2.4 – Backup-/Restore-Integration

Lieferumfang: serverseitiger `mysqldump`-/`mysql`-Adapter ohne Secrets in
Argumenten, Prüfsummen, Struktur-/Inhaltsvergleich und künstliche
Manipulationsprüfung.

Nachweis: getrenntes Restore-Ziel, vollständiger Vergleich, Fehlerfall bei
manipuliertem Dump und Cleanup. Produktionsdaten bleiben ausgeschlossen.

### AP2.5 – AP2-Abnahme

Alle AP2-Nachweise werden zusammengeführt. AP2a wurde anschließend separat
bearbeitet; AP3 und spätere Pakete werden nicht begonnen. Die Abnahme ist erst
möglich, wenn keine AP2-spezifischen
Fehler offen sind und alle temporären Testressourcen entfernt wurden.

### AP2a – Technische Legal-Seiten und Legal-Bundles

Lieferumfang: technisch erreichbare Seiten `/shopbedingungen/`,
`/datenschutz/`, `/widerruf/` und `/versand-und-zahlung/`; ein kanonisch
gehashtes, versioniertes Legal-Bundle-Lesemodell mit ID, Status,
Archiv-URL und getrennter Test-/Produktionsauswahl; unveränderliche
Legal-Bundle-Snapshots für die spätere Checkout-Zuordnung.

Testfassungen enthalten ausschließlich künstliche, deutlich als nicht
rechtlich freigegeben gekennzeichnete Inhalte. Die Produktionsfassung bleibt
bis zur externen Freigabe `awaiting_external_approval`; kein Bundle wird als
rechtlich freigegeben behauptet. AP2a verändert weder Stripe-Checkout noch
Produktionsdaten und beginnt AP3 nicht.

Nachweis: Bundle-Hash- und Statusvalidierung, Test-/Produktions-Trennung,
Snapshot-Vergleich, statischer Testexport aller vier Seiten, Lint und
Node-Tests. Die technische Abnahme ist in
`website/docs/ap2a-acceptance.md` dokumentiert.

## 5. Meilensteine und kritischer Pfad

| Meilenstein | Stand | Nachweis |
| --- | --- | --- |
| AP0-A bis AP0-C.2 | abgeschlossen | Hosting-, MySQL-8-, InnoDB-, Cron- und Restoreprotokoll |
| AP1.1 bis AP1.7 | abgeschlossen | Commit `21da119`, AP1-Abnahme, 46 PHP- und AP1-Node-Tests, Android-Test |
| AP2.1 | abgeschlossen | MySQL-8-/InnoDB-Schema zweimal idempotent angewendet; Struktur und Migration geprüft |
| AP2.2 | abgeschlossen | Transaktion, Rollback, Row-Lock-Timeout, zehn Clients, Deadlock und Crash-Rollback bestanden |
| AP2.3 | abgeschlossen | Lease-Sperre und Lease-Übernahme sowie Inbox-/Outbox-Zuordnung bestanden |
| AP2.4 | abgeschlossen | Dump, Restore, Manipulationserkennung, Struktur-/Inhaltsvergleich und Cleanup bestanden |
| AP2.5 | abgeschlossen | AP2-Gesamtabnahme dokumentiert; AP3 und spätere Pakete nicht begonnen |
| AP2a | abgeschlossen | Vier technische Legal-Seiten, Bundle-Hash-/Statusprüfung, Snapshot-Zuordnung, Testexport und Abnahmebericht bestanden |

Kritischer Pfad: AP2.1 → AP2.2 → AP2.3 → AP2.4 → AP2.5 → AP2a. AP2.1 blockiert
alle nachfolgenden praktischen Änderungen. Stripe-Checkout,
Live-API, Website und Produktions-Cutover bleiben außerhalb dieses Pfades.

## 6. Aufwand und Kalenderkorridore

Die folgenden Werte sind Planungskorridore, keine gemessenen Ist-Stunden und
kein verbindliches Angebot.

| AP2-Teil | Planaufwand |
| --- | ---: |
| AP2.1 Schema/Migration | 18–28 h |
| AP2.2 Commerce-Kern | 32–48 h |
| AP2.3 Worker/Inboxen/Outboxen | 20–32 h |
| AP2.4 Backup/Restore | 12–20 h |
| AP2.5 Tests/Abnahme/Dokumentation | 12–20 h |
| **Gesamt AP2** | **94–148 h** |
| AP2a technische Legal-Seiten/Bundles | 18–28 h |
| **Gesamt AP2 + AP2a (Planungskorridor)** | **112–176 h** |

| Wochenaufwand | technischer Korridor |
| ---: | --- |
| 5 h/Woche | ca. 19–30 Wochen |
| 10 h/Woche | ca. 10–15 Wochen |
| 15 h/Woche | ca. 7–10 Wochen |

Für AP2 plus AP2a ergibt sich der kombinierte Planungskorridor von ca. 23–36
Wochen bei 5 h/Woche, 12–18 Wochen bei 10 h/Woche und 8–12 Wochen bei
15 h/Woche. Die Werte sind Planwerte, keine Ist-Arbeitszeiten.

Die Kalenderkorridore enthalten keinen externen IONOS-, Stripe-, Brevo- oder
Rechtsprüfungswarteanteil. Der erste schreibende AP2-Schritt ist auf
`feature/shop-ap2` isoliert; der AP1-Abschlusscommit ist bereits vorhanden.

## 7. Freigaben und nächste Schritte

| Bereich | Status |
| --- | --- |
| AP1 | freigegeben und lokal abgeschlossen |
| AP2.1 bis AP2.5 | freigegeben und abgenommen |
| AP2a | freigegeben und abgenommen |
| AP3 und später | nicht freigegeben |
| Produktion/Cutover/Deployment | nicht freigegeben |

Der AP2- und AP2a-Praxisnachweis ist bestanden. Der exakt nächste zulässige
Schritt ist die separate fachliche Freigabe von AP3. Ohne diese Freigabe bleiben
AP3, Deployment und Produktions-Cutover unangetastet.
