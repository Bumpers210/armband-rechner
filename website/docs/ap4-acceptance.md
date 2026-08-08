# AP4-Abnahme – öffentlicher Shop, Token und Widerruf

Stand: 2026-08-02
Worktree: `G:\BS-Stein-Hart-ap4`
Branch: `feature/shop-ap4`

## Umfang

AP4 ergänzt die öffentliche Shop-Schnittstelle auf dem abgenommenen AP3-/AP2-
Commerce-Kern. Eine anonyme Shopsitzung, ein kurzlebiger Live-Kontext und eine
separate Checkout-Berechtigung werden getrennt geführt. Browser erhalten nur
zufällige Rohwerte; der Server speichert SHA-256-Digests. Cookies sind
`Secure`, `HttpOnly`, `SameSite=Lax`, `Path=/` und verwenden das `__Host-`
Präfix.

Live-Produktdaten werden ohne Cache aus `commerce_products` und
`commerce_inventory` gelesen. Der statische Produktbestand wird nicht als
Kaufentscheidung verwendet. Checkout-Start prüft CSRF, Live-Kontext, Origin,
Rate-Limit und Idempotenz, erzeugt die Checkout-Berechtigung und startet
Stripe in einem Endpunkt.

## Widerruf

`/widerruf/` ist dauerhaft über den Footer erreichbar. Die Identifikations-
und Vorschauphase erzeugt noch keine Widerrufserklärung. Erst die ausdrückliche
Schaltfläche **Widerruf bestätigen** speichert den Eingang atomar. Nicht
eindeutig zuordenbare Erklärungen werden nach Bestätigung als `manual_review`
gespeichert. Es gibt keinen zusätzlichen E-Mail-Code und keine automatische
Erstattung oder Wiedereinlagerung.

## Verkaufskanalgrenze

Die sichtbaren Start-, Produkt- und Checkoutseiten enthalten keine externen
Verkaufslinks. Der eigene Shop ist der einzige Verkaufskanal. Historische
Legacy-Felder der Produktverwaltung bleiben nur für die bereits abgenommenen
AP1-Kompatibilitätstests lesbar.

## Nachweise

- PHP-Lint der AP4-PHP-Dateien: bestanden.
- AP4-PHP-Vertragstests: 4/4 bestanden; AP3-, AP2-, Produkt-API- und Bootstrap-Suiten bestanden.
- Node-AP4-Vertragstests: 5/5 bestanden; bestehende AP3-/Legal-/Produkt-/Build-Suiten bestanden.
- Vollständiger Node-Lauf: 60/67 bestanden. Sieben bereits bekannte, AP4-fremde
  CRLF-/Shellfehler in Basic-Auth-Diagnose, Deployment-Shell, Smoke und
  Token-Installer blieben unverändert und wurden nicht als AP4-Fehler behoben.
- Website-Lint und Testexport: bestanden.
- Android-Unit-Tests (`testDebugUnitTest`): bestanden, ohne App-Änderung.
- Keine Produktionsdaten, kein Deployment, kein Push und kein AP5.

## Abnahme

AP4 ist nach bestandenem PHP-, Node-, Website-, Integrations- und Sicherheits-
nachweis vollständig abgenommen. Der nächste zulässige Arbeitsschritt ist AP5
und erfordert eine separate Freigabe.
