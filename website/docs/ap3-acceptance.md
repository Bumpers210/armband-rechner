# AP3-Abnahme – Stripe Checkout, Webhooks und Worker

Stand: 2026-08-02
Worktree: `G:\BS-Stein-Hart-ap3`
Branch: `feature/shop-ap3`
Stripe-Modus: ausschliesslich Testmodus; kein produktiver Schluessel und kein
Deployment verwendet.

## Festgeschriebener Vertrag

| Vertrag | V1-Wert |
| --- | --- |
| stripe-php | `20.3.0` |
| Stripe-API-Version | `2026-06-24.dahlia` |
| Stripe-Webhook-API-Version | `2026-06-24.dahlia` |
| Checkout-Laufzeit | 1.800 Sekunden |
| Zahlungsmethoden | ausschliesslich `card` (Apple Pay/Google Pay darueber) |
| Link | `wallet_options.link.display=never` |
| Promotion-Codes | deaktiviert |
| Recovery | deaktiviert |
| Terms-Zustimmung | `consent_collection.terms_of_service=required` |
| Webhook-Antwort | erst nach Inbox-Persistierung `204` |
| Worker | Lease 600 Sekunden, Batch 10, Retry 5/15/60/240/720 Minuten |

Die Checkout-Parameter werden vollstaendig serverseitig aus dem autoritativen
Produktlesemodell, dem Versand-Snapshot und dem freigegebenen Legal Bundle
gebildet. Der Browser liefert keinen Preis, keine Waehrung, keine Versandkosten
und keine Legal-Bundle-Autoritaet.

## Webhooks

Die Allowlist umfasst ausschliesslich:

```text
checkout.session.completed
checkout.session.expired
charge.refunded
refund.updated
charge.dispute.created
charge.dispute.updated
charge.dispute.closed
```

Der unveraenderte Request-Body wird vor dem Parsen signaturgeprueft. Erlaubte
Ereignisse werden verschluesselt in `webhook_inbox` gespeichert, bevor `204`
zurueckgegeben wird. Doppelte Ereignisse werden ueber die Stripe-Event-ID
dedupliziert. Gueltig signierte, aber nicht unterstuetzte Ereignisse erhalten
`204` ohne fachliche Mutation. Widerspruechliche oder ungeordnete Ereignisse
bleiben in `manual_review`.

## Worker

`worker.php` ist ausschliesslich fuer den privaten CLI-/Cron-Aufruf vorgesehen.
Er verarbeitet Inbox, Stripe-Metadaten-Outbox und offene Checkout-Abgleiche
mit zehnminuetiger Lease und kleinen Batches. Externe Stripe-Aufrufe erfolgen
erst nach Freigabe lokaler Datenbanktransaktionen. Die Wiederholungen erfolgen
nach 5 Minuten, 15 Minuten, 1 Stunde, 4 Stunden und 12 Stunden; ein
anschliessender erfolgloser Lauf wechselt zu `manual_review`.

## Tests

| Test | Ergebnis |
| --- | --- |
| AP3-Node-Vertragstests | 6/6 bestanden |
| `npm run lint:test` | bestanden |
| `git diff --check` | bestanden |
| `npm test` | 55 bestanden, 7 bekannte CRLF-Baselinefehler ausserhalb AP3 |
| PHP 8.4.23-Lint fuer alle AP3-PHP-Dateien | bestanden |
| PHP-AP3-Test | 4/4 bestanden |
| bestehende PHP-API-/Bootstrap-/Admin-/Commerce-Tests | 9 + 46 + 20 + 13 bestanden |
| Testexport/Deployment | nicht ausgefuehrt |
| Produktion/Cutover | unveraendert |

Der PHP-8.4.23-Nachweis wurde mit prozesslokal aktivierten Erweiterungen
`curl`, `gd`, `exif`, `mbstring`, `openssl`, `pdo_mysql` und `sodium` erbracht.
Es wurden keine produktiven Schluessel oder Ressourcen verwendet. AP4,
Deployment und produktiver Cutover werden nicht begonnen.
