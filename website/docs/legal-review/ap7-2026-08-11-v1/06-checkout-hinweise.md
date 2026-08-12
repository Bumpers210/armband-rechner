# Checkout-Hinweise

Status: **freigegeben**
Dokumentversion: `ap7-legal-review-2026-08-11-v1/checkout-notices`
Standdatum: `2026-08-11`
Produktive URL: dynamischer Stripe Checkout; Einstieg über `https://www.carmaja-perlen.de/armbaender/<produkt-id>/`
Inhalts-SHA-256: `76754995516489e0d0dad2ae0e2121c45e692fd23cfe6392fc8b957fd9258902`

Hashumfang: UTF-8/LF-Inhalt zwischen `hash-begin` und `hash-end`, Marker ausgeschlossen.

<!-- hash-begin -->
Vor dem Checkout werden Liefergebiet, Einzelkauf, Produkt, Preis, Versandkosten, Gesamtpreis, Lieferzeit, Maxibrief-Versand und alle angebotenen Zahlungsarten angezeigt.

Stripe Checkout zeigt Lieferadresse, Zahlungsart und Gesamtpreis, verlinkt die Rechtstexte und verlangt die Zustimmung zu den Shopbedingungen über `consent_collection.terms_of_service=required`.

Die technische Zahlungsarten-Allowlist lautet `card`, `klarna` und `sepa_debit`; Apple Pay und Google Pay werden auf Kartenbasis angeboten. Stripe Link, weitere dynamische Zahlungsarten, Promotion-Codes und Checkout-Recovery bleiben deaktiviert.

Die abschließende Schaltfläche lautet „zahlungspflichtig bestellen“. Der Kunde gibt damit ein verbindliches Kaufangebot ab. Annahme und Bestellanlage erfolgen erst nach endgültiger Stripe-Zahlungsbestätigung. Eine Eingangs-, Rückkehr- oder Bearbeitungsanzeige ist keine Annahme.

Bei endgültig fehlgeschlagener Zahlung entstehen weder Bestellung noch Bestellbestätigung. Bei noch bearbeiteter SEPA-Lastschrift werden keine Bestellnummer und keine Versandfreigabe erzeugt. Browserabbruch und Abbruchseite geben eine offene Reservierung nicht frei.
<!-- hash-end -->
