# Warenkorb/Mengenbestand – externe Freigabe dokumentiert

Stand: 25. Oktober 2026. Die Betreiberin hat im Chat ausdruecklich bestaetigt,
dass der externe Pruefer die endgueltige Dokumentversion samt Inhalts-Hash
und die unten aufgefuehrten Kaufablaeufe freigegeben hat. Diese Mitteilung
wird als Nachweis der externen Rechts-, Datenschutz- und Versandfreigabe
dokumentiert; keine eigenstaendige Rechtsfreigabe durch den Agenten und
keine Behauptung einer Einsicht in vertrauliche Originalunterlagen.
Praktische Packkapazitaet und Bestellgrenze: maximal 10 fertig verpackte
Armbaender pro Maxibrief, einmalig 2,70 EUR Versand je Bestellung.

## Eindeutige Bindung der Freigabe

| Merkmal | Freigegebene Prueffassung |
| --- | --- |
| Quelle | `website/content/legal-bundles.ts`, Export `cartProductionLegalDraft` |
| Legal-Bundle-ID | `cmj-production-legal-2026-10-25-v7` |
| Dokumentversion / Stand | v7 / 25. Oktober 2026 |
| Kanonischer SHA-256-Inhalts-Hash | `55b56a713077bf4697341392d1676c57667a198d371ab8e6f39b03e75a6c6501` |
| Freigabenachweis | Ausdrueckliche Mitteilung der Betreiberin im Chat am 25. Oktober 2026 |
| Fachlicher Freigabestatus | Externe Rechts-, Datenschutz- und Versandfreigabe bestaetigt |
| Technische Aktivierung | Nicht erfolgt; weiterhin gesperrt |

Der Hash wurde aus der aktuellen unveraenderten Prueffassung mit der
bestehenden kanonischen Legal-Bundle-Funktion erneut berechnet und validiert.
Die Freigabe gilt nur fuer diese Fassung und diesen Kaufablauf; inhaltliche
Aenderungen benoetigen eine neue zugeordnete Freigabe.

Wichtig: Der bestehende Bundle-Hash umfasst auch das Feld `status`.
Die gepruefte Fassung behaelt deshalb ihren technischen Status
`awaiting_external_approval` und ihren Hash unveraendert. Der fachliche
Freigabenachweis steht separat hier; der gespeicherte Entwurfsstatus bildet
die nun gemeldete externe Freigabe nicht ab. Ein spaeterer kontrollierter
Uebergang zu `approved` muss den neuen technischen Bundle-Hash dokumentieren
und die Textgleichheit mit dieser freigegebenen Prueffassung nachweisen;
er darf nicht als hashgleicher Austausch ausgegeben werden. Keine automatische
Auswahl im Produktionsbuild oder in einer Runtime. Bestehende genehmigte
Versionen und historische Bestellnachweise bleiben unveraendert.

Der neue Testbuild verwendet die künstliche Fassung `cmj-test-legal-2026-10-25-v4`.
Sie ist ausdrücklich `test_only`, keine rechtliche Freigabe.
Die bisherigen Testfassungen v2/v3 bleiben im Testarchiv erreichbar. Der
vorherige Produktionsentwurf v6 bleibt ebenfalls inhaltlich/hashgleich
erhalten; er wird weder genehmigt noch aktiv geschaltet.

## Bestaetigter Pruefumfang

- Mehrere Positionen und Mengen im Warenkorb; kein Kundenkonto.
- Hoechstens zehn Armbaender insgesamt pro Bestellung, nicht pro Produkt.
- Serverpreis-/Mengenprüfung, einmalig 2,70 € Versand für die gemeinsame Bestellung.
- „In den Warenkorb“ → Warenkorb prüfen → „Weiter zur Zahlung“ → Stripe mit verpflichtender AGB-Zustimmung.
- Annahme, Bestellnummer und Versand ausschließlich nach endgültigem Zahlungserfolg.
- SEPA-processing hält Mengen; die Rückkehrseite oder der Sessionablauf ist kein Zahlungsende.
- Karte/Apple Pay/Google Pay, Klarna, SEPA; PayPal deaktiviert.
- Zweistufiger Widerruf mit Positions-/Mengenauswahl und elektronischer Eingangsbestätigung; auch ungeklärte Erklärungen werden nach Bestätigung angenommen.
- Teilrefunds werden in Stripe ausgelöst und synchronisiert. Versandkosten-/Teilrefund-Zuordnung und Rücksendekostenregel sind Bestandteil der gemeldeten Freigabe.
- Neue Produkte erhalten feste Naturstein-/Wirkungshinweise; historische Produkte und Bestellnachweise bleiben unverändert.
- Datenschutz für sitzungsgebundenen 24-Stunden-Warenkorb, Checkout, Zahlungsanbieter und Bestätigungen ist Bestandteil der gemeldeten Freigabe.

## Versandfreigabe und praktische Kapazität

Maxibrief ist weiterhin die einzige Versandart, ohne Teillieferungen und mit
einmalig 2,70 EUR Versand je Bestellung. Auf die Frage nach der maximalen Zahl
fertig verpackter Armbaender hat die Betreiberin am 25. Oktober 2026 mit
"10" geantwortet. Dies ist eine bestaetigte Packkapazitaet, keine pauschale
Rechtsfreigabe. Anschliessend wurde ausdruecklich "Maximal 10 Armbaender je
Bestellung freigegeben" bestaetigt.

Der neue verbindliche Bestellvertrag lautet deshalb: hoechstens 10 Armbaender
insgesamt je Bestellung, ueber alle Positionen summiert, nicht 10 pro Produkt.
Die technische Obergrenze von 100 Positionen bleibt ein zusaetzliches
Formatlimit; die engere Gesamtmengengrenze ist bei neuen Bestellungen massgeblich.
Versandpreis und Versandart unveraendert. Keine automatische Kuerzung,
Aufteilung, Mehrfachberechnung oder weitere Versandart. Bestandsmengen duerfen
weiterhin groesser als 10 sein; bestehende Zahlungs-/Bestellnachweise bleiben
unveraendert.

Serverpruefung, Websitehinweis und lokale Grenzwerttests sind im
Abnahmebericht 3.70 dokumentiert. Die neue Test-Rechtstextfassung und die
Websitehinweise sind gemaess 3.71 auf der geschuetzten Testwebsite sichtbar.
Die serverseitige Zehnergrenze ist lokal und isoliert gegen MySQL 8 mit
aktiver TLS-Sitzung praktisch bestanden (3.72), aber nicht als dauerhafte
Root-Shop-Runtime bereitgestellt worden.
Die Produktionsprueffassung v7 enthaelt ausdruecklich die Zehnergrenze und
ist durch die oben dokumentierte versionierte/hashgebundene Freigabe erfasst.
Die kuenstliche Testfassung bleibt davon unabhaengig `test_only`.
Bestehende Legal Bundles und Bestellnachweise bleiben unveraendert.
Klarna bei fehlendem Zustellnachweis bleibt ein gesondertes Nachweis-/Verlustrisiko.

### Bestaetigter Wortlaut zur Mengenbegrenzung (v7)

> Pro Bestellung sind insgesamt höchstens 10 Armbänder möglich, über alle
> Positionen und Mengen zusammen. Größere Warenkörbe müssen vor der Zahlung
> angepasst werden; sie werden nicht automatisch gekürzt oder aufgeteilt.

> Versand einmalig 2,70 € je Bestellung mit insgesamt höchstens 10 Armbändern,
> als gemeinsame Sendung ohne Teillieferungen.

Zahlungsarten, Teilwiderrufs-/Erstattungsregeln und Datenschutz wurden bei
dieser Versionierung nicht zusaetzlich veraendert. Die nun gemeldete externe
Freigabe umfasst diese Regeln im Mehrpositions-Kaufablauf.

## Dokumentierte Bestaetigung und verbleibende Grenzen

Die Betreiberin bestaetigte:

> Der Prüfer hat die endgültige Dokumentversion samt Inhalts-Hash,
> den Mehrpositions-Kaufablauf, Vertragsschluss/AGB-Zustimmung, SEPA-Bearbeitung,
> Teilwiderruf-/Teilerstattungsregeln, Versand-/Rücksendekosten und Datenschutz
> ausdrücklich bestätigt.

Keine personenbezogenen Prueferdaten, Unterschriften oder vertraulichen
Originalunterlagen werden ins Repository uebernommen. Diese Mitteilung
schliesst das externe Freigabegate der Warenkorb-Prueffassung v7; sie ist
keine Deployment-, Migrations-, Produktions- oder Cutoverfreigabe.
Kontrollierte Integration, technische Bundle-Ueberfuehrung, aktuelle
Backup-/Restore- und Bestandsnachweise sowie die ausdrueckliche spaetere
Produktionsaktivierung bleiben separat erforderlich.

Prüfgrundlagen: [§ 356a BGB](https://www.gesetze-im-internet.de/bgb/__356a.html),
[Stripe Checkout](https://docs.stripe.com/api/checkout/sessions/create),
[Stripe Erstattungen](https://docs.stripe.com/refunds).
