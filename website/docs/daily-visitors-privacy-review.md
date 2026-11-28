# Prüfpaket: Tagesbesucherstatistik

Stand: 28. November 2026. **Externe Datenschutzfreigabe ausstehend.**
Nur lokale Vorbereitung; keine Aktivierung oder Erweiterung alter Freigaben.

Fortschreibung: [Datenschutz- und Betriebsnachweise](daily-visitors-operations.md).
Die offenen Providerbedingungen wurden am 28. November anhand offizieller
IONOS-Quellen eingegrenzt. Automatische Sicherungen bis zu 14 Tagen sind
dokumentiert; ein Backupausschluss dieses privaten Pfads ist **nicht** belegt.
Logfristen sind vertragsabhängig und nicht pauschal aus dem Hilfeartikel zu
übernehmen. Eine kopierfertige, kontospezifische Rückfrage steht im Nachweispaket.

## Umfang und maßgebliche Texte

- Wunsch: ein Browser pro Berliner Kalendertag einmal; keine Personenerkennung.
- Verbindlicher Textentwurf und UI-Kurztext: `../content/daily-visitors-policy.json`,
  Version `daily-visitors-v2`, Status `awaiting_external_approval`.
- Technischer Ablauf und Prüfungen: [Tagesbesucher-Vertrag](daily-visitors.md).
- Vorgesehene öffentliche Informationsseite: `/datenschutz/` der Shopwebsite.
  Footerzugang zur Wahl und zum direkten Widerruf auf allen öffentlichen Seiten.
- Alle bisherigen freigegebenen Legal Bundles/Archive bleiben unverändert.
  Vor Aktivierung neue Fassung erstellen: widersprechende Aussage „Eigene Analyse-,
  Werbe- oder Newsletter-Cookies setzen wir nicht ein.“ ersetzen, neue Abschnitte
  vollständig einbinden, neue Bundle-ID/Version/Hash extern bestätigen. Keine
  vorhandenen Checkout-/Bestellsnapshots umschreiben.

## Vorgeschlagene Fristen und Zweckbindung

| Datentyp | Zweck | Aufbewahrung / Löschung |
| --- | --- | --- |
| Zufällige Browser-Tageskennung | Doppelzählung am selben Tag verhindern | Cookieablauf nächste Berliner Mitternacht; sofort bei Widerruf |
| Serverseitiger täglicher Prüfwert | atomare Doppelzählungssperre | im Normalbetrieb bis 00:15 des Folgetags; unabhängig von neuen Besuchen |
| Entscheidung, Textversion, Zeitpunkt | Wahl beachten und wiederholte Nachfrage vermeiden | 180 Tage bei Zustimmung **und** Ablehnung; keine zufällige Nutzer-ID |
| Kennungsfreie Tagesanzahlen | zeitliche statistische Vergleiche | erhalten; kein Personenbezug im Zähler, keine Rückrechnung Einzelner |
| Bisherige Statistikdaten | historischer Vergleich | unverändert; keine Migration zu vermeintlichen Unique-Zahlen |

180 Tage und das 15-Minuten-Löschintervall sind **gewählte technische Fristen**,
keine gesetzlich vorgeschriebenen Werte. Prüfer muss Angemessenheit, tatsächliche
Anonymisierung der Aggregate und Nachweisfähigkeit der Einwilligung bestätigen.
Keine zusätzliche zentrale Personen-/Consent-Historie nur zum Nachweis erzeugen.
Das versionierte Wahlcookie und die unveränderte Textfassung dokumentieren das
Verfahren, ersetzen aber nicht automatisch jeden rechtlichen Nachweis.
DSK OH Digitale Dienste, Randnummern 84–85, stützen einen Verfahrensnachweis
ohne zusätzliche langlebige UID; Bannerfassungen, Abläufe und Testbelege müssen
dabei dauerhaft nachvollziehbar bleiben. Die konkrete Ausgestaltung bleibt
extern zu bestätigen.

Bei Cronausfall ist keine garantierte Löschung um 00:15 gegeben. Nach 30 Minuten
ohne Wartungsnachweis stoppt die neue Erfassung automatisch; Betriebsalarm und
unverzügliche Nachholung müssen zusätzlich nachgewiesen werden. Diese
Restabhängigkeit wird in der Information nicht verschwiegen.

## Vor Aktivierung zu bestätigen

1. Entwurf, gleichwertige Erlauben-/Ablehnen-Buttons, Freiwilligkeit und direkter
   Widerruf sind für diesen Zweck freigegeben; keine Kopplung an Einkauf/Login.
2. Vorgeschlagene Rechtsgrundlagen, Fristen, Aggregateaufbewahrung und
   Einwilligungsnachweis sind akzeptiert. Keine Behauptung einer Rechtsfreigabe
   allein durch erfolgreiche Tests.
3. Tatsächlicher IONOS-Auftragsverarbeitungsvertrag, Hosting-/Logfristen,
   Empfänger, gegebenenfalls Transfers sowie Betroffenenrechte stimmen mit der
   Gesamterklärung überein. Kein neuer externer Analysedienst wird eingebunden.
4. Keine Kennungen/POST-Bodies/Cookies in eigenen Logs oder dauerhaften Backups;
   Provider-Backups/Snapshots und deren Fristen ausdrücklich prüfen. Nur der
   kennungsfreie Aggregatexport darf gesichert werden.
5. Neue aktive Datenschutzfassung mit ID, Version, Datum und Inhalts-Hash statt
   Änderung eines vorhandenen Archivs; Checkout-/Website-Bundle konsistent.
6. Separater technischer Betriebsnachweis: private Pfade/0600, Cron */15,
   unabhängiger Alarm über `status`, Ausfall/Wiederanlauf, alte Daten unverändert.
   Commit/Push/CI, Bereitstellung und Aktivierung jeweils nur auf Auftrag.

### Formulierung für die externe Bestätigung

„Geprüft und bestätigt werden die endgültige Datenschutzfassung **[ID, Version,
Datum, Inhalts-Hash]** einschließlich Tagesbesucherzählung, Einwilligungsoberfläche
und Widerruf sowie der dokumentierten Speicher-, Lösch-, Log- und Backupregeln.
Die tatsächlichen Hosting-/Auftragsverarbeitungsangaben, Rechtsgrundlagen und
Einwilligungsnachweise wurden abgeglichen. Die verbleibenden Betriebsbedingungen
**[falls vorhanden konkret aufführen]** sind vor Aktivierung nachzuweisen.“

Keine personenbezogenen Prüferdaten oder vertraulichen Nachweisunterlagen ins
Repository übernehmen. Eine spätere Bestätigung muss auf die endgültige Fassung
verweisen; dieser noch ungeprüfte Entwurf ist dafür kein Ersatz.

## Herangezogene Primärquellen

Gelesen am 28. November 2026; technische Umsetzung, keine eigenständige Rechtsberatung:

- [§ 25 TDDDG](https://www.gesetze-im-internet.de/ttdsg/__25.html): Einwilligung für
  Endgerätespeicherung/-zugriff, Ausnahmen für unbedingt erforderliche Funktionen.
- [DSK, Orientierungshilfe Digitale Dienste (2024)](https://www.datenschutzkonferenz-online.de/media/oh/OH_Digitale_Dienste.pdf):
  aktive informierte Wahl, keine Vorauswahl oder irreführende Gestaltung.
- [LfD Niedersachsen: TDDDG-FAQ](https://www.lfd.niedersachsen.de/faq/faq-telekommunikation-digitale-dienste-datenschutzgesetz-tdddg-206449.html):
  gleichwertige Möglichkeiten und keine Lenkung zur Zustimmung.
- [DSGVO, amtliche EU-Fassung](https://eur-lex.europa.eu/legal-content/DE/ALL/?uri=celex%3A32016R0679):
  insbesondere Art. 5, 6, 7 und 13; Zweckbindung, Speicherbegrenzung,
  Nachweisbarkeit, einfacher Widerruf und Information.
