# AP2-Zwischenabnahme und Übergabe

Stand: 2026-08-02
Worktree: `G:\BS-Stein-Hart-ap2`
Branch: `feature/shop-ap2`
AP1-Abschlusscommit: `21da119db1c57be095764f8f75bb0c9863ec1759`

## Ergebnis

AP2.1 bis AP2.5 sind mit ausschließlich künstlichen Daten bestanden. Die
praktische Ausführung gegen zwei isolierte MySQL-8-/InnoDB-Ziele bestätigte
Schema-Migration, Transaktionen, Row-Locks, zehn parallele Clients,
Deadlock-Eindämmung, Crash-Rollback, Leases, Backup, Restore,
Manipulationserkennung, Struktur-/Inhaltsvergleich und vollständigen Cleanup.

## Lokale Nachweise

* `website/database/commerce-schema.sql`: MySQL-8-/InnoDB-Schema mit
  getrennten Produkt-, Inventar-, Checkout-, Zahlungs-, Bestell-, Versand-,
  Inbox-, Outbox- und Reviewtabellen.
* `website/test-api-private/program/commerce-core.php`: PDO-Transaktionen,
  Row-Locks, Idempotenz, Bestellnummernsequenz, atomare Finalisierung,
  Bestandsaudit und Reviewcases.
* `website/test-api-private/program/commerce-worker.php`: zehnminütige Leases,
  Batchgrenze und externe Aktionen außerhalb der Transaktion.
* `website/scripts/commerce-backup.php`: expliziter, secretfreier
  mysqldump-/mysql-Adapter mit privaten temporären Optionsdateien.
* 13 Commerce-AP2-PHP-Tests bestanden; beide AP2-Schema-Node-Tests bestanden.
* Praktischer IONOS-Nachweis: alle AP2.4-Prüfungen bestanden; beide
  Testdatenbanken nach dem Lauf leer.
* PHP-Lint, 46 bestehende PHP-API-Tests, Website-Lint und Android
  `testDebugUnitTest` bestanden.
* Vollständiger Node-Lauf: 43/50 bestanden; sieben bekannte
  CRLF-/Deployment-Basisfehler, die bereits vor AP2 bestanden und nicht
  verändert wurden.

## Freigabestatus

| Paket | Status | Begründung |
| --- | --- | --- |
| AP2.1 Schema/Migration | bestanden | MySQL-8-/InnoDB-Migration zweimal idempotent und strukturell geprüft |
| AP2.2 Commerce-Kern | bestanden | Transaktion, Row-Lock, Parallelität, Deadlock und Crash-Rollback bestanden |
| AP2.3 Worker/Inboxen/Outboxen | bestanden | Lease-Sperre, Ablauf/Übernahme und Retry-Vertrag bestanden |
| AP2.4 Backup/Restore | bestanden | Dump, Restore, Hash-/Vergleichs- und Cleanupnachweis bestanden |
| AP2.5 Gesamtabnahme | bestanden | AP2 vollständig abgenommen; AP2a und spätere Pakete nicht begonnen |

AP2a, AP3, Deployment, produktiver Cutover und Produktionsdaten bleiben
unangetastet.

## Übergabe

AP2 ist vollständig abgenommen. AP2a, AP3, Deployment, produktiver Cutover
und Produktionsdaten bleiben ausdrücklich unangetastet. Der nächste zulässige
Schritt ist ausschließlich eine separate fachliche Freigabe von AP2a.
