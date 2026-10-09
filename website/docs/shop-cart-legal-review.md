# Warenkorb/Mengenbestand – erneute externe Freigabe ausstehend

Stand: 6. Oktober 2026. Keine Rechts-, Datenschutz- oder Versandfreigabe erteilt.

Neue Entwurfsfassung: `cartProductionLegalDraft` in
`website/content/legal-bundles.ts`, ID `cmj-production-legal-2026-10-06-v6`,
Status `awaiting_external_approval`. Hash wird durch die unveränderte kanonische
Legal-Bundle-Funktion deterministisch berechnet. Der Entwurf wird nicht als
aktives Produktionsbundle verwendet. Alte genehmigte Versionen bleiben unverändert.

Der neue Testbuild verwendet die künstliche Fassung `cmj-test-legal-2026-10-06-v3`.
Sie ist ausdrücklich `test_only`, keine rechtliche Freigabe.

## Vorzulegende Änderungen

- Mehrere Positionen und Mengen im Warenkorb; kein Kundenkonto.
- Serverpreis-/Mengenprüfung, einmalig 2,70 € Versand für die gemeinsame Bestellung.
- „In den Warenkorb“ → Warenkorb prüfen → „Weiter zur Zahlung“ → Stripe mit verpflichtender AGB-Zustimmung.
- Annahme, Bestellnummer und Versand ausschließlich nach endgültigem Zahlungserfolg.
- SEPA-processing hält Mengen; die Rückkehrseite oder der Sessionablauf ist kein Zahlungsende.
- Karte/Apple Pay/Google Pay, Klarna, SEPA; PayPal deaktiviert.
- Zweistufiger Widerruf mit Positions-/Mengenauswahl und elektronischer Eingangsbestätigung; auch ungeklärte Erklärungen werden nach Bestätigung angenommen.
- Teilrefunds werden in Stripe ausgelöst und synchronisiert. Versandkosten-/Teilrefund-Zuordnung und Rücksendekostenregel ausdrücklich prüfen.
- Neue Produkte erhalten feste Naturstein-/Wirkungshinweise; historische Produkte und Bestellnachweise bleiben unverändert.
- Datenschutz für sitzungsgebundenen 24-Stunden-Warenkorb, Checkout, Zahlungsanbieter und Bestätigungen erneut prüfen.

## Versandfreigabe und praktische Kapazität

Maxibrief ist weiterhin die einzige Versandart, ohne Teillieferungen. Der
bisherige Gewichts-/Formatrahmen muss auch für größere Warenkörbe tatsächlich
eingehalten werden können. Die aktuelle Vorgabe setzt keine zusätzliche
Mengen-/Gewichtsgrenze und keine weitere Versandart; dieser praktische Widerspruch
muss vor Produktionsfreigabe mit der Betreiberin geklärt werden. Klarna bei
fehlendem Zustellnachweis bleibt ein gesondertes Nachweis-/Verlustrisiko.

## Erforderliche schriftliche Bestätigung

Der externe Prüfer soll die endgültige Dokumentversion samt Inhalts-Hash,
den Mehrpositions-Kaufablauf, Vertragsschluss/AGB-Zustimmung, SEPA-Bearbeitung,
Teilwiderruf-/Teilerstattungsregeln, Versand-/Rücksendekosten und Datenschutz
ausdrücklich bestätigen. Keine vertraulichen Prüferunterlagen ins Repository.
Ausstehende praktische Versandgrenzen sind separat zu entscheiden; dieser
Entwurf ändert sie nicht automatisch.

Prüfgrundlagen: [§ 356a BGB](https://www.gesetze-im-internet.de/bgb/__356a.html),
[Stripe Checkout](https://docs.stripe.com/api/checkout/sessions/create),
[Stripe Erstattungen](https://docs.stripe.com/refunds).
