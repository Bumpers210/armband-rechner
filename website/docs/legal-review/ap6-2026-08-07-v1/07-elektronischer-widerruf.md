# Elektronische Widerrufsfunktion

Status: **freigegeben**
Dokumentversion: `ap6-legal-review-2026-08-07-v1/electronic-withdrawal`
Standdatum: `2026-08-07`
Produktive URL: `https://www.carmaja-perlen.de/widerruf/`
Inhalts-SHA-256: `6a774505088182d449f5ce4ac7475982458a7e037ce46622a4fa4388afcd84e1`

Hashumfang: UTF-8/LF-Inhalt zwischen `hash-begin` und `hash-end`, Marker ausgeschlossen.

<!-- hash-begin -->
Die Funktion ist dauerhaft ohne Kundenkonto über „Vertrag widerrufen“ erreichbar. Ein E-Mail-Link kann Angaben vorausfüllen, ist aber nicht der einzige Zugang.

Der Nutzer gibt Bestellnummer, Name und E-Mail-Adresse ein. Der Server prüft die Zuordnung rate-limitiert und zeigt eine Zusammenfassung. Erst „Widerruf bestätigen“ übermittelt die Erklärung. Inhalt, Datum und Uhrzeit werden atomar gespeichert und eine elektronische Eingangsbestätigung wird unverzüglich in die Mail-Outbox gestellt.

Nicht eindeutig zuordenbare Einreichungen werden erst nach ausdrücklicher Bestätigung gespeichert und erzeugen einen Reviewcase. Seitenaufruf und Identifikationsanfrage sind noch kein Widerruf. Doppelte Bestätigungen werden idempotent behandelt.

Widerruf, Storno, Rücksendung, Erstattung, Versandänderung und Wiedereinlagerung bleiben getrennte Vorgänge; es erfolgt keine automatische Erstattung oder Wiedereinlagerung.
<!-- hash-end -->
