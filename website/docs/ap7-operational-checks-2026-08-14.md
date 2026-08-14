# AP7 – Lesende Betriebsprüfung vom 14. August 2026

## Ergebnis

Mail-Vertrag, Worker, Monitoring, Brevo-Absender und Backupweg wurden im
freigegebenen, nicht verändernden Umfang geprüft. Der aktuelle Betrieb ist
gesund. Es wurden keine Produkt-, Bestands-, Checkout-, Zahlungs-,
Bestell- oder Laufzeitdaten verändert, keine E-Mail versendet und kein
Deployment ausgelöst.

Die neuen Mail- und Verwaltungsprogramme sind nach `main` übernommen, wurden
im Rahmen dieser Prüfung aber nicht in Produktion aktiviert. Ihr echter
Produktionsnachweis bleibt deshalb ein eigenes Freigabegate.

## Mail- und Verwaltungsvertrag

- 10 AP5-Mail- und Adminfälle bestanden mit künstlichen Daten.
- 17 Commerce-Kernfälle bestanden, einschließlich getrennter
  Betreiber-Deduplizierung, Lease-/Crash-Verhalten und Retryvertrag.
- Der Monitoring-Selbsttest, 12 Bootstrap-Sicherheitsfälle und 17 zugehörige
  Node-Vertragstests bestanden.
- Der Backup-Selbsttest bestand mit 6 von 6 Verschlüsselungs-,
  Manipulations- und Manifestfällen.
- PHP 8.4 bestätigte die Syntax aller betroffenen privaten Programme und
  Einstiege.

Damit sind Idempotenz, Deduplizierung, `delivery_unknown`, Retry, Auditweg und
Adminsichtbarkeit im freigegebenen Programmstand nachgewiesen. Ein echter
Produktionsversand wurde bewusst nicht durchgeführt.

## Produktionsworker und Monitoring

Eine aggregierte, ausschließlich lesende Datenbankabfrage bestätigte:

- beide privaten Worker hatten wenige Minuten zuvor erfolgreich abgeschlossen
  und meldeten keinen letzten Fehler;
- es bestand keine aktive oder abgelaufene Lease;
- Webhook-, Mail- und Stripe-Metadatenwarteschlange enthielten weder fällige,
  endgültig fehlgeschlagene noch hängen gebliebene Einträge;
- es bestanden keine offenen Prüffälle.

Der beobachtete Lauf dauerte bei leeren Warteschlangen weniger als eine
Sekunde. Lease-Übernahme und Fortsetzung eines Teilbatches sind mit
künstlichen Daten getestet. Sie wurden nicht durch das Einfügen künstlicher
Datensätze in die Produktionsdatenbank wiederholt.

## Brevo-Absender

Die Brevo-Absenderliste war lesend erreichbar. Sie enthält weiterhin genau
zwei aktive Einträge für die konfigurierte Absenderadresse. Damit ist ein
doppelter aktiver Eintrag eindeutig bestätigt. Es wurde kein Eintrag gelöscht
oder geändert und keine Nachricht versendet.

Vor einer Bereinigung muss in einem getrennt freigegebenen Schritt eindeutig
festgelegt werden, welcher Eintrag erhalten bleibt.

## Backup und OneDrive

- Der private Serverstatus war `ok`; weder Serverbackup noch Offsite-Abruf
  waren überfällig.
- Der jüngste Serverstand war weniger als eine Stunde alt, seine
  Downloadquittierung weniger als 30 Minuten.
- Der Windows-Task war bereit, der letzte Lauf erfolgreich und der nächste
  Lauf eingeplant.
- OneDrive lief am festgelegten Stamm. Der Zielordner enthielt 50
  Sicherungsstände; der jüngste Stand war aktuell und vollständig.
- Der lokale Agentstatus war `ok` und aktuell.

Es wurde kein zusätzliches manuelles Backup und kein neuer Restorelauf
gestartet. Der bereits dokumentierte erfolgreiche Restore-Nachweis bleibt
gültig; ein neues Abschlussbackup und ein Restoretest sind unmittelbar vor
dem späteren Bestands-Cutover erneut erforderlich.

## Noch offene Freigabegates

1. Neue Mail- und Verwaltungsprogramme kontrolliert in Produktion aktivieren
   und dort ohne Checkout die Status- und Adminsicht prüfen.
2. Den doppelten Brevo-Absender nach eindeutiger Auswahl kontrolliert
   bereinigen.
3. Signierte Produktions-App erst nach eigener Installationsfreigabe auf dem
   vorgesehenen Gerät prüfen.
4. Unmittelbar vor dem späteren Cutover ein frisches Backup und einen
   isolierten Restoretest durchführen.

Produktaktivierung, Bestands-Cutover, Publisher, Checkout, Zahlung und
Shopstart bleiben gesperrt.
