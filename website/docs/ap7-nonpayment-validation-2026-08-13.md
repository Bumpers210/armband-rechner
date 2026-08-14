# AP7 – Prüfung ohne Zahlung vom 13. August 2026

## Ergebnis

Die Live-Konfiguration von Stripe, Checkout und Brevo ist grundsätzlich
erreichbar und korrekt getrennt. Die Prüfung hat keinen Checkout angelegt,
keine Zahlung gestartet, keine E-Mail versendet und keine Datenbank geändert.

Ein echter Testkauf ist noch nicht freigegeben. Vorher müssen die Inhalte der
Bestell- und Widerrufsmails vervollständigt und die Mailzustände in der
Verwaltungsoberfläche sichtbar gemacht werden.

## Sicher bestätigt

- Die private Produktionskonfiguration ist eine reguläre Datei mit Modus
  `0600`. Produktion ist ausgewählt, Publisher bleibt deaktiviert und das
  Monitoring ist aktiv.
- Das deutsche Stripe-Livekonto ist erreichbar. Zahlungen und Auszahlungen
  sind freigeschaltet.
- Genau ein aktiver Live-Webhook zeigt auf
  `https://api.carmaja-perlen.de/stripe/webhook`. Seine neun Ereignisse und
  seine festgelegte API-Version stimmen mit dem Produktionsvertrag überein.
- Der Checkout-Vertrag erlaubt genau ein Produkt sowie Karte, Klarna und
  SEPA-Lastschrift. Versand ist auf Deutschland und 2,70 Euro begrenzt.
  Zustimmung zu den Shopbedingungen ist erforderlich. Stripe Link,
  Rabattcodes und Wiederherstellung abgelaufener Checkouts bleiben aus. Die
  Laufzeit beträgt 30 Minuten und Legal Bundle v4 ist zugeordnet.
- Eine künstliche, intern erzeugte Webhook-Nachricht wurde mit dem aktuellen
  Produktionsgeheimnis korrekt geprüft. Der unveränderte Rohinhalt erreichte
  genau einmal den künstlichen Speicheraufruf. Es erfolgte keine
  Datenbankspeicherung. Der noch offene Nachweis mit einem echten
  Stripe-Ereignis wird dadurch nicht ersetzt.
- Brevo-Konto und Absenderliste sind erreichbar. Der eingestellte Absender ist
  aktiv. Die von der Betreiberin empfangene Monitoring-Testwarnung bestätigt
  zusätzlich den tatsächlichen Versandweg.
- Bestell-, Versand- und Widerrufsmail lassen sich mit künstlichen Daten ohne
  Netzversand erzeugen. Deduplizierung, unklarer Versandstatus, Wiederholung
  und manueller Neuversand sind im bestehenden Programm und den fokussierten
  Tests vorgesehen.

## Festgestellte Lücken

1. Die Bestellmail enthält derzeit nur Dank und Bestellnummer. Produkt,
   Einzelpreis, Versand, Gesamtbetrag und die der Bestellung zugeordneten
   Rechtstexte fehlen. Das widerspricht dem freigegebenen Text, nach dem die
   Bestelldaten und geltenden Rechtstexte mit der Bestellbestätigung
   bereitgestellt werden.
2. Eine getrennte Betreiber-Bestellmail existiert noch nicht.
3. Die Versandmail enthält Bestellnummer und optional die Sendungsreferenz.
   Ihr endgültiger Wortlaut wurde noch nicht gegen den freigegebenen Text
   abgenommen.
4. Die Widerrufsbestätigung enthält Vorgangsnummer, Datum und Uhrzeit, aber
   nicht den übermittelten Inhalt. Der freigegebene Text verlangt eine
   Eingangsbestätigung mit Inhalt, Datum und Uhrzeit.
5. Die API kennt Mailstatus, Fehlversuche, letzten Fehler und Versandzeit. Die
   Verwaltungsoberfläche zeigt diese Angaben sowie Reviewfälle und den
   auditierten Neuversand noch nicht an.
6. Im Brevo-Konto bestehen zwei aktive Einträge für dieselbe eingestellte
   Absenderadresse. Der Versand funktioniert, der doppelte Eintrag sollte aber
   später kontrolliert bereinigt werden.

## Nächstes freizugebendes Arbeitspaket

Das nächste Paket soll ausschließlich E-Mails und ihre Verwaltung betreffen:

1. unveränderlichen Bestell- und Rechtstext-Snapshot in die Bestellmail
   übernehmen;
2. getrennte Betreiber-Bestellmail mit eigener Deduplizierung ergänzen;
3. Versandmail textlich fertigstellen;
4. Widerrufsbestätigung um den tatsächlich übermittelten Inhalt ergänzen;
5. Mailfehler, letzte erfolgreiche Verarbeitung, Reviewfälle und auditierten
   Neuversand in der Verwaltungsoberfläche anzeigen;
6. Unit-, PHP-, API- und Oberflächentests ergänzen;
7. die Änderung zuerst auf `test.carmaja-perlen.de` mit künstlichen
   Empfängeradressen und ohne Zahlung vollständig abnehmen.

Nicht Bestandteil dieses Pakets sind Produktaktivierung, Bestands-Cutover,
Publisher, echter Checkout, Zahlung, Shopstart oder eine Änderung der
Produktionswebsite.
