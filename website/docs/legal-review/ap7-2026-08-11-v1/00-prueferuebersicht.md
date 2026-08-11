# Freigabeübersicht – Carmaja-Shop V1

Status: **freigegeben**
Dokumentversion: `ap7-legal-review-2026-08-11-v1/overview`
Standdatum: `2026-08-11`
Produktive URL: `https://www.carmaja-perlen.de/`
Zugeordnetes Legal-Bundle: `cmj-production-legal-2026-08-11-v4`
Inhalts-SHA-256: `8d19fc4ec9b2c88522f86d7895f8387b290c7894304bacc0a464606751c5aecb`

Hashumfang: UTF-8/LF-Inhalt zwischen `hash-begin` und `hash-end`, Marker ausgeschlossen.

<!-- hash-begin -->
## Freigegebener Shopablauf

- Gastkauf eines einzelnen Unikat-Armbands mit Menge `1`, ohne Warenkorb oder Kundenkonto.
- Zahlungsarten über Stripe: Karte einschließlich Apple Pay und Google Pay, Klarna und SEPA-Lastschrift.
- „zahlungspflichtig bestellen“ gibt das Kaufangebot ab und autorisiert die Zahlung.
- Annahme, Bestellnummer, Bestellmail und Versandfreigabe entstehen erst nach endgültiger Stripe-Zahlungsbestätigung.
- Eine noch bearbeitete SEPA-Lastschrift hält das Unikat reserviert und erzeugt keine Bestellung.
- Versand ausschließlich innerhalb Deutschlands als Maxibrief der Deutschen Post bis 1.000 g für 2,70 EUR; Basis-Sendungsverfolgung ohne Zustellnachweis, Haftung oder Versicherung.
- Stripe verlangt die Zustimmung zu den Shopbedingungen. Das Legal-Bundle im Checkout-Snapshot muss `cmj-production-legal-2026-08-11-v4` entsprechen.
- Der öffentliche zweistufige Widerruf verwendet „Vertrag widerrufen“ und anschließend „Widerruf bestätigen“.

## Freigegebene Dokumente

Freigegeben sind Impressum, Shopbedingungen, Widerrufsbelehrung und Musterformular, Versand- und Zahlungsinformationen, Datenschutzerklärung, Checkout-Hinweise sowie die technischen Beschreibungen von elektronischem Widerruf und Vertragsschluss. Die Zuordnung gilt ausschließlich für die im Manifest ausgewiesenen Fassungen und Hashes.

Der Freigabevermerk enthält keine Identität, Unterschrift oder vertrauliche Anlage einer prüfenden Person. AP7, Deployment, Produktionszugriff und Cutover bleiben gesondert gesperrt.
<!-- hash-end -->
