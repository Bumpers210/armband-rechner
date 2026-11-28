# Tagesbesucherstatistik: Datenschutz und Aufbewahrung

Stand: 28. November 2026. **Datenschutzfreigabe bestätigt; technische
Bereitstellung und Aktivierung weiterhin gesperrt.**

## Grundlage und Freigabezuordnung

Die Betreiberseite bestätigte am 28. November 2026 im Chat:
„Die Datenschutzfreigabe ist erteilt“. Danach wurden **14 Tage Aufbewahrung
mit automatischer Löschung für IONOS-Sicherungskopien UND Serverlogs**
bestätigt und die Anpassung dieser Unterlagen beauftragt. Quelle ist die
Betreiberbestätigung, keine neue unabhängige Provider- oder Rechtsprüfung.
Keine vertraulichen Nachweisunterlagen oder personenbezogenen Prüferdaten
werden ins Repository übernommen. Keine erneute pauschale Freigabeanfrage
für denselben bestätigten Inhalt.

- Textquelle: `../content/daily-visitors-policy.json`.
- Dokumentrevision: `daily-visitors-v2-ionos14d-2026-11-28`.
- Technischer Consent-Vertrag unverändert `daily-visitors-v2`, bislang nicht live.
  Die Dokumentrevision kennzeichnet die Änderung; die endgültigen Inhalte werden
  beim späteren Legal-Bundle neu hashgebunden.
- Status `approved_pending_operations`: Freigabe bestätigt, Betriebsabnahme offen.
  Dieser Status ist absichtlich **nicht** der technische Freischaltstatus
  `approved`; der Produktionsbuild mit aktivierter Zählung bleibt gesperrt.
- Aktive Datenschutzerklärung, freigegebene Legal-Archive und Bestellsnapshots
  bleiben unverändert. Vor Veröffentlichung neue vollständige Fassung mit
  eigener Bundle-ID, Version und Inhaltsbindung erstellen.

## Getrennte Fristen

| Datenbereich | Aufbewahrung / Löschung |
| --- | --- |
| Tageskennung im Browser | nächste Berliner Mitternacht; sofort bei Widerruf |
| Tagesprüfwerte im laufenden System | Vortag im Normalbetrieb bis 00:15 Europe/Berlin; Löschjob alle 15 Minuten, unabhängig von Besuchen |
| IONOS-Sicherungskopien einschließlich möglicher Tagesprüfwerte | 14 Tage ab Erstellung der Kopie, anschließend automatisch gelöscht laut Betreiberbestätigung |
| IONOS-Serverlogs | 14 Tage, anschließend automatisch gelöscht laut Betreiberbestätigung |
| Wahlcookie mit Textversion und Entscheidungszeitpunkt | 180 Tage für Zustimmung und Ablehnung, keine zufällige Nutzer-ID |
| Eigene Statistikexporte | ausschließlich kennungsfreie Tagesanzahlen |
| Tagesanzahlen und historische Statistikdaten | erhalten; keine Umdeutung früherer Aufrufe zu Personen |

Die frühere pauschale Vorgabe „Prüfwerte nicht in Backups“ wird präzisiert:
**eigene Exporte bleiben kennungsfrei; automatische IONOS-Kopien können
Prüfwerte enthalten und unterliegen der getrennten 14-Tage-Frist.**
Die tägliche Anwendungsbereinigung löscht diese Kopien nicht. Ein Ausschluss
aus allen Providerbackups wird weder behauptet noch als Voraussetzung verlangt.
Providerkopien nicht zur Besucheranalyse verwenden oder als eigene Dauerkopie
über die dokumentierte Frist hinaus behalten. Nach einem Restore alte Prüfwerte
vor erneuter Erfassung bereinigen; bestehende aktuelle Zähler nicht ungeprüft
durch einen älteren Stand ersetzen.

Die Frist sagt nicht, welche Felder IONOS protokolliert. Kein unbelegtes
Versprechen über vollständige Anonymität oder fehlende Cookie-/POST-/IP-Daten
in sämtlichen Providerlogs ergänzen. Die eigene Statistik speichert diese
zusätzlichen Daten nicht; eigene Logs dürfen keine Tageskennungen,
Cookie-Header oder POST-Inhalte aufnehmen. Die Zuordnung des vorhandenen
Hosting-/AVV-Vertrags bleibt Teil des späteren Abgleichs der Gesamterklärung.

## Unverändert: Einwilligung und Ausfallsicherheit

Ein Browser pro Berliner Kalendertag, keine sichere Personenerkennung.
Gleichwertiges Erlauben/Ablehnen, keine Vorauswahl oder Kopplung an einen Kauf,
direkter Widerruf im Footer. Die Messung beginnt nur nach aktiver Einwilligung.

Bei mehr als 30 Minuten ohne Wartungsnachweis stoppt die neue Erfassung.
Das ersetzt weder Löschung noch Alarm; überfällige Prüfwerte nach Behebung
unverzüglich bereinigen. Die normale 00:15-Löschzeit gilt nicht für Providerkopien.

## Noch erforderliche technische Schritte – nicht aktiviert

1. Neue aktive Datenschutzfassung mit dieser Revision verbinden und die alte
   Aussage „keine Analyse-Cookies“ ersetzen; alte Archive unverändert erhalten.
2. Private Pfade außerhalb aller Webroots, Modus 0600, unveränderte historische
   Statistiken und kennungsfreie eigene Backups im Ziel prüfen.
3. Echten Cron, Mitternachtsbereinigung, unabhängige Warnung, tatsächlichen
   Empfang und Wiederanlauf prüfen. Siehe [Cron-/Alarmtestplan](daily-visitors-cron-alarm-test-plan.md).
4. Bereitstellung, Cronanlage und Aktivierung benötigen gesonderte Freigabe.
   Keine Freigabe wird allein aus bestandenen lokalen Tests abgeleitet.

Die Freigabequelle, Dokumentrevision, spätere Bundle-ID/Inhaltsbindung und
Betriebsnachweise gemeinsam dokumentieren. Materielle spätere Textänderungen
nicht stillschweigend von dieser Bestätigung abdecken.

## Quellen

- Direkte Betreiberbestätigungen im Chat vom 28. November 2026.
- [Betriebsnachweise](daily-visitors-operations.md): öffentliche Recherche,
  lokale/CI-Ergebnisse und Abgrenzung der noch offenen Zielumgebungsprüfungen.
- Bereits herangezogene Grundlagen:
  [§ 25 TDDDG](https://www.gesetze-im-internet.de/ttdsg/__25.html),
  [DSK OH Digitale Dienste](https://www.datenschutzkonferenz-online.de/media/oh/OH_Digitale_Dienste.pdf),
  [DSGVO](https://eur-lex.europa.eu/legal-content/DE/ALL/?uri=celex%3A32016R0679).
  Dieser Schritt ändert keine Rechtsgrundlagen und erteilt keine eigene Rechtsfreigabe.
