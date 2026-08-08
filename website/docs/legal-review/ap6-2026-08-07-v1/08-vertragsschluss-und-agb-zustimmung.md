# Vertragsschluss und AGB-Zustimmung

Status: **freigegeben**
Dokumentversion: `ap6-legal-review-2026-08-07-v1/contract-consent`
Standdatum: `2026-08-07`
Produktive Referenz-URL: `https://www.carmaja-perlen.de/shopbedingungen/`
Zugeordnetes Legal-Bundle: `cmj-production-legal-2026-08-07-v3`
Inhalts-SHA-256: `731c1539b47c21f797ebd5eb1319d054db1125ced2b704432be28d67cb4f3c03`

Hashumfang: UTF-8/LF-Inhalt zwischen `hash-begin` und `hash-end`, Marker ausgeschlossen.

<!-- hash-begin -->
Die Produktseite zeigt Live-Preis und Live-Verfügbarkeit. „Jetzt kaufen“ erzeugt nach serverseitiger Prüfung einen Produkt-, Preis-, Versand- und Legal-Snapshot sowie eine Reservierung.

Stripe Checkout zeigt Zahlungs- und Versanddaten und verlangt die Zustimmung zu den Shopbedingungen. Mit „zahlungspflichtig bestellen“ gibt der Kunde ein verbindliches Kaufangebot ab und autorisiert die Zahlung.

Der Browserrücklauf ist kein Zahlungsnachweis. Erst ein eindeutig bestätigter endgültiger Stripe-Zahlungserfolg mit passender Summe, Währung, Produktreferenz, `legalBundleId` und `terms_of_service=accepted` führt zur Annahme und erzeugt atomar Bestellung, Bestellnummer, Bestandsreduzierung und Bestellmail.

Bei verzögertem SEPA-Ergebnis bleibt der Vorgang ohne Bestellung `processing`; bei endgültigem Fehler wird die Reservierung freigegeben. Das Legal-Bundle `cmj-production-legal-2026-08-07-v3` wird unveränderlich im Checkout-Snapshot und in der Bestellung gespeichert.
<!-- hash-end -->
