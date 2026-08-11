import {
  createLegalBundle,
  validateLegalBundle,
  type LegalBundle,
  type LegalBundleTexts,
} from "@/lib/legal-bundles.mjs";
import { siteTarget } from "@/config/site-target";

const testTexts: LegalBundleTexts = {
  terms: [
    {
      heading: "Technische Testfassung",
      paragraphs: [
        "Diese Seite ist eine künstliche Testfassung für den isolierten AP2a-Build. Sie ist nicht rechtlich geprüft, nicht für den Verkauf bestimmt und darf nicht als Vertragsdokument verwendet werden.",
      ],
      bullets: [
        "Keine produktive Bestellung darf mit dieser Testfassung ausgelöst werden.",
        "Inhalt, Händlerdaten und Vertragsschlussregeln sind Platzhalter.",
        "Die technische Zahlungsarten-Allowlist umfasst Karte, PayPal, Klarna und SEPA-Lastschrift.",
        "Bei laufender asynchroner Zahlung entstehen weder Bestellung noch Versandfreigabe.",
      ],
    },
  ],
  privacy: [
    {
      heading: "Technische Testfassung",
      paragraphs: [
        "Dies ist ein künstlicher Datenschutzhinweis für Test- und Abnahmeläufe. Die endgültige Fassung muss vor einer öffentlichen Freigabe extern geprüft und ersetzt werden.",
      ],
      bullets: [
        "Testdaten werden ausschließlich künstlich erzeugt.",
        "Keine Aussage dieser Testfassung ersetzt eine rechtliche Prüfung.",
        "Die Datenflüsse der vier Stripe-Zahlungsarten müssen vor Produktion erneut datenschutzrechtlich freigegeben werden.",
      ],
    },
  ],
  withdrawal: [
    {
      heading: "Technische Testfassung",
      paragraphs: [
        "Diese Seite beschreibt nur die technische Zielstruktur des Widerrufsformulars. Sie ist kein freigegebener Gesetzes- oder Vertragstext.",
      ],
      bullets: [
        "Die öffentliche Widerrufsfunktion wird separat technisch getestet.",
        "Fristen, Ausschlüsse und Bestätigungsinhalte bleiben externe Freigabepunkte.",
      ],
    },
  ],
  shipping: [
    {
      heading: "Technische Testfassung",
      paragraphs: [
        "Versandart, Versandkosten und Lieferzeiten sind in dieser Testfassung künstliche Platzhalter. Die endgültige Versandinformation muss vor dem Produktionsstart extern freigegeben werden.",
      ],
      bullets: [
        "V1 sieht genau eine Versandart innerhalb Deutschlands vor.",
        "Es werden keine internationalen oder zusätzlichen Versandarten versprochen.",
        "Versand beginnt erst nach endgültig bestätigtem Zahlungserfolg.",
        "Klarna birgt bei Versand ohne Sendungsverfolgung ein erhöhtes Nachweis- und Verlustrisiko; diese Testfassung erteilt dafür keine Freigabe.",
      ],
    },
  ],
};

const productionApprovedTexts: LegalBundleTexts = {
  terms: [
    {
      heading: "1. Geltungsbereich",
      paragraphs: [
        "Diese Shopbedingungen gelten für Bestellungen von Verbrauchern über den Online-Shop von Carmaja-Perlen. Pro Checkout kann genau ein Unikat mit Menge 1 gekauft werden. Ein Warenkorb und ein Kundenkonto werden nicht angeboten.",
      ],
    },
    {
      heading: "2. Vertragspartner und Vertragsschluss",
      paragraphs: [
        "Vertragspartner ist Carolin Buchner, handelnd unter Carmaja-Perlen, Bubenheim 170, 91757 Treuchtlingen, Deutschland.",
        "Die Produktdarstellung ist kein verbindliches Angebot. Über „Jetzt kaufen“ gelangen Sie zum Stripe Checkout. Dort können Sie Ihre Angaben prüfen und korrigieren. Mit „zahlungspflichtig bestellen“ geben Sie ein verbindliches Kaufangebot ab und autorisieren die gewählte Zahlung.",
        "Wir nehmen das Angebot an, sobald Stripe den endgültigen Zahlungserfolg bestätigt und unser System die Bestellung anlegt. Den Vertragsschluss bestätigen wir unverzüglich per E-Mail. Eine bloße Eingangs- oder Bearbeitungsanzeige, insbesondere bei SEPA-Lastschrift, ist noch keine Annahme. Bei endgültig fehlgeschlagener Zahlung kommt kein Vertrag zustande.",
      ],
    },
    {
      heading: "3. Vertragssprache und Vertragstext",
      paragraphs: [
        "Vertragssprache ist Deutsch. Mit der Bestellbestätigung erhalten Sie die Bestelldaten und die für den Vertrag geltenden Rechtstexte in Textform oder als dauerhaft unveränderliche, der Bestellung zugeordnete Fassungen. Eine Online-Bestellhistorie wird nicht angeboten.",
      ],
    },
    {
      heading: "4. Lieferung",
      paragraphs: [
        "Wir liefern ausschließlich innerhalb Deutschlands an die im Checkout angegebene Lieferadresse. Selbstabholung und Lieferung an Packstationen werden nicht angeboten. Versandart, Versandkosten und Lieferzeit stehen unter „Versand und Zahlung“ und werden vor Abgabe des Kaufangebots angezeigt.",
      ],
    },
    {
      heading: "5. Preise und Zahlung",
      paragraphs: [
        "Es gelten die vor Abgabe des Kaufangebots angezeigten Gesamtpreise zuzüglich der ausgewiesenen Versandkosten.",
        "Die Zahlung erfolgt über Stripe. Verfügbar sind Kredit- und Debitkarte, Apple Pay und Google Pay auf Kartenbasis, PayPal, Klarna und SEPA-Lastschrift. Welche Zahlungsarten im Einzelfall angezeigt werden, kann von deren Verfügbarkeit und den Voraussetzungen des jeweiligen Anbieters abhängen.",
        "Bei SEPA-Lastschrift bleibt der Vorgang bis zur endgültigen Stripe-Bestätigung in Bearbeitung. Bis dahin entstehen keine angenommene Bestellung, Bestellnummer oder Versandfreigabe.",
      ],
    },
    {
      heading: "6. Mängelhaftung",
      paragraphs: ["Es gilt das gesetzliche Mängelhaftungsrecht."],
    },
    {
      heading: "7. Streitbeilegung",
      paragraphs: [
        "Wir sind nicht verpflichtet und nicht bereit, an einem Streitbeilegungsverfahren vor einer Verbraucherschlichtungsstelle teilzunehmen.",
      ],
    },
  ],
  privacy: [
    {
      heading: "1. Verantwortlicher",
      paragraphs: [
        "Carolin Buchner, handelnd unter Carmaja-Perlen, Bubenheim 170, 91757 Treuchtlingen, Deutschland. Telefon: 01523 3671027. E-Mail: kontakt@carmaja-perlen.de.",
      ],
    },
    {
      heading: "2. Verarbeitete Daten und Zwecke",
      paragraphs: [
        "Website, Shop-API und Datenbank werden bei IONOS betrieben. Technische Zugriffsdaten werden für den sicheren und störungsfreien Betrieb auf Grundlage von Art. 6 Abs. 1 Buchstabe f DSGVO verarbeitet.",
        "Bei Kontaktaufnahme und Bestellung verarbeiten wir insbesondere Name, E-Mail-Adresse, Liefer- und gegebenenfalls Rechnungsanschrift, Produkt, Preis, Versandkosten sowie Bestell-, Zahlungs-, Versand-, Widerrufs- und Erstattungsstatus. Rechtsgrundlagen sind Art. 6 Abs. 1 Buchstaben b und c DSGVO.",
        "Es gibt kein Kundenkonto, keinen Newsletter und keine eigene Online-Bestellhistorie. Die als Pflichtangaben gekennzeichneten Daten werden für den Vertrag benötigt. Wir treffen selbst keine ausschließlich automatisierte Entscheidung mit rechtlicher Wirkung.",
      ],
    },
    {
      heading: "3. Zahlung, E-Mail und Versand",
      paragraphs: [
        "Die Zahlung wird über Stripe abgewickelt. Verfügbar sind Kredit- und Debitkarte, Apple Pay und Google Pay auf Kartenbasis, PayPal, Klarna und SEPA-Lastschrift. Stripe und die jeweils einbezogenen Anbieter verarbeiten die für Zahlung, Authentifizierung und Betrugsprävention erforderlichen Daten. Rechtsgrundlagen sind Art. 6 Abs. 1 Buchstaben b und f DSGVO.",
        "Transaktionsmails versenden wir über Brevo SAS. Für den Versand geben wir Name, Lieferanschrift und gegebenenfalls eine Versandkennung an die Deutsche Post AG weiter. Dies erfolgt zur Vertragserfüllung nach Art. 6 Abs. 1 Buchstabe b DSGVO.",
        "Datenschutzhinweise: https://stripe.com/de/privacy, https://www.paypal.com/de/legalhub/paypal/privacy-full, https://www.klarna.com/de/datenschutz/ und https://www.brevo.com/de/legal/privacypolicy/.",
      ],
    },
    {
      heading: "4. Elektronischer Widerruf und notwendige Cookies",
      paragraphs: [
        "Unter https://www.carmaja-perlen.de/widerruf/ verarbeiten wir die zur Erklärung, Zuordnung und Bestätigung eines Widerrufs erforderlichen Daten. Erst „Widerruf bestätigen“ speichert die Erklärung. Rechtsgrundlagen sind Art. 6 Abs. 1 Buchstaben b und c DSGVO.",
        "Wir verwenden nur technisch notwendige Cookies: __Host-cmj_shop_session grundsätzlich bis zu 24 Stunden sowie __Host-cmj_checkout grundsätzlich bis spätestens zwei Stunden nach Ablauf der Stripe-Sitzung. Sie werden mit Secure, HttpOnly, SameSite=Lax, Path=/ und ohne Domain-Attribut gesetzt; serverseitig werden nur Hashwerte gespeichert. Rechtsgrundlage für den Endgerätezugriff ist § 25 Abs. 2 TDDDG.",
        "Eigene Analyse-, Werbe- oder Newsletter-Cookies setzen wir nicht ein.",
      ],
    },
    {
      heading: "5. Empfänger und Drittlandverarbeitung",
      paragraphs: [
        "Empfänger können IONOS, Stripe, PayPal, Klarna, Brevo, Deutsche Post AG sowie gesetzlich berechtigte Behörden und Berater sein. Mit Auftragsverarbeitern werden erforderliche Verträge geschlossen.",
        "Stripe, PayPal, Klarna und deren Unterauftragnehmer können Daten außerhalb der EU oder des EWR verarbeiten. Maßgeblich sind die jeweils anwendbaren Datenschutzinformationen und Übermittlungsgrundlagen der Anbieter.",
        "Ein einfacher Link zu Instagram überträgt beim bloßen Seitenaufruf keine Daten. Erst beim Anklicken gelten die Datenschutzbestimmungen der Plattform.",
      ],
    },
    {
      heading: "6. Speicherdauer",
      paragraphs: [
        "Wir speichern Daten nur so lange, wie sie für Vertrag, Sicherheit oder gesetzliche Nachweise benötigt werden. Handels- und steuerrechtliche Unterlagen werden für die vorgeschriebene Dauer aufbewahrt. Sonstige Vertragsdaten werden nach Ablauf der gesetzlichen Verjährungsfristen gelöscht, sofern keine offene Auseinandersetzung eine längere Speicherung erfordert.",
        "Technische Sitzungen, Tokens, Rate-Limit- und Fehlerdaten werden nach Ablauf ihrer Schutzfrist gelöscht oder anonymisiert. Webhook-Rohdaten werden nach sicherer Verarbeitung und Ablauf der kurzen technischen Nachweisfrist gelöscht.",
      ],
    },
    {
      heading: "7. Ihre Rechte",
      paragraphs: [
        "Sie haben unter den gesetzlichen Voraussetzungen Rechte auf Auskunft, Berichtigung, Löschung, Einschränkung, Datenübertragbarkeit und Widerspruch sowie das Recht, eine Einwilligung für die Zukunft zu widerrufen. Außerdem können Sie sich bei einer Datenschutzaufsichtsbehörde beschweren. Anfragen richten Sie an kontakt@carmaja-perlen.de.",
      ],
    },
  ],
  withdrawal: [
    {
      heading: "Widerrufsrecht",
      paragraphs: [
        "Sie haben das Recht, diesen Vertrag binnen vierzehn Tagen ohne Angabe von Gründen zu widerrufen. Die Frist beginnt an dem Tag, an dem Sie oder ein von Ihnen benannter Dritter, der nicht Beförderer ist, die Ware erhalten haben.",
        "Um zu widerrufen, informieren Sie Carolin Buchner, handelnd unter Carmaja-Perlen, Bubenheim 170, 91757 Treuchtlingen, Deutschland, E-Mail kontakt@carmaja-perlen.de, Telefon 01523 3671027, mit einer eindeutigen Erklärung, zum Beispiel per Brief oder E-Mail.",
        "Sie können auch das Musterformular oder die dauerhaft erreichbare Schaltfläche „Vertrag widerrufen“ auf dieser Seite verwenden. Nach Prüfung Ihrer Angaben senden Sie die Erklärung mit „Widerruf bestätigen“ ab. Sie erhalten unverzüglich eine elektronische Eingangsbestätigung mit Inhalt, Datum und Uhrzeit. Zur Fristwahrung genügt die rechtzeitige Absendung des Widerrufs.",
      ],
    },
    {
      heading: "Folgen des Widerrufs",
      paragraphs: [
        "Wir erstatten alle erhaltenen Zahlungen einschließlich der Kosten unserer Standardlieferung unverzüglich und spätestens binnen vierzehn Tagen nach Eingang des Widerrufs. Wir verwenden grundsätzlich dasselbe Zahlungsmittel wie bei der ursprünglichen Zahlung und berechnen keine Erstattungsentgelte.",
        "Wir dürfen die Rückzahlung verweigern, bis wir die Ware zurückerhalten haben oder Sie deren Rücksendung nachweisen, je nachdem, was früher eintritt.",
        "Sie müssen die Ware spätestens binnen vierzehn Tagen nach Ihrem Widerruf an die oben genannte Anschrift absenden. Wir tragen die unmittelbaren Rücksendekosten innerhalb Deutschlands. Bitte kontaktieren Sie uns zur Abstimmung des Rücksendewegs; die Widerrufsfrist hängt davon nicht ab.",
        "Für einen Wertverlust müssen Sie nur aufkommen, wenn er durch einen zur Prüfung von Beschaffenheit, Eigenschaften und Funktionsweise nicht notwendigen Umgang mit der Ware entstanden ist.",
      ],
    },
    {
      heading: "Muster-Widerrufsformular",
      paragraphs: [
        "An: Carolin Buchner, Carmaja-Perlen, Bubenheim 170, 91757 Treuchtlingen, Deutschland, E-Mail kontakt@carmaja-perlen.de.",
        "Hiermit widerrufe(n) ich/wir (*) den von mir/uns (*) abgeschlossenen Vertrag über den Kauf der folgenden Ware. Bitte geben Sie Ware, Bestell- und Erhaltungsdatum, Name, Anschrift und Datum an. Eine Unterschrift ist nur bei Mitteilung auf Papier erforderlich. (*) Unzutreffendes streichen.",
      ],
    },
  ],
  shipping: [
    {
      heading: "Versand",
      paragraphs: [
        "Wir liefern ausschließlich innerhalb Deutschlands als Maxibrief der Deutschen Post bis 1.000 g. Selbstabholung und Lieferung an Packstationen werden nicht angeboten.",
        "Versandkosten je Bestellung: 2,70 €. Lieferzeit: voraussichtlich 2 bis 4 Werktage nach endgültiger Zahlungsbestätigung. Die Basis-Sendungsverfolgung enthält regelmäßig keinen Zustellnachweis; Haftung oder Versicherung sind nicht enthalten. Ihre gesetzlichen Ansprüche gegen uns werden dadurch nicht beschränkt.",
      ],
    },
    {
      heading: "Zahlung",
      paragraphs: [
        "Die Zahlung erfolgt über Stripe. Verfügbar sind Kredit- und Debitkarte, Apple Pay und Google Pay auf Kartenbasis, PayPal, Klarna, soweit im Checkout angeboten, und SEPA-Lastschrift.",
        "Mit „zahlungspflichtig bestellen“ geben Sie ein verbindliches Kaufangebot ab und autorisieren die Zahlung. Das Angebot wird erst nach endgültiger Zahlungsbestätigung durch Stripe angenommen. Anschließend erhalten Sie unverzüglich die Bestellbestätigung.",
        "SEPA-Lastschrift kann zunächst als „in Bearbeitung“ angezeigt werden. Bis zur endgültigen Bestätigung bleibt das Unikat reserviert; es entstehen noch keine angenommene Bestellung, Bestellnummer oder Versandfreigabe. Bei endgültigem Fehlschlag kommt kein Vertrag zustande.",
      ],
    },
    {
      heading: "Rücksendung nach Widerruf",
      paragraphs: [
        "Rücksendeanschrift: Carolin Buchner, Carmaja-Perlen, Bubenheim 170, 91757 Treuchtlingen, Deutschland.",
        "Wir tragen die unmittelbaren Kosten einer Rücksendung nach wirksamem Widerruf innerhalb Deutschlands. Bitte kontaktieren Sie uns zur Abstimmung des Rücksendewegs. Ihr Widerrufsrecht wird dadurch nicht eingeschränkt.",
      ],
    },
  ],
};

const productionAwaitingTextsV4: LegalBundleTexts = {
  terms: productionApprovedTexts.terms.map((section) =>
    section.heading === "5. Preise und Zahlung"
      ? {
          ...section,
          paragraphs: [
            "Es gelten die vor Abgabe des Kaufangebots angezeigten Gesamtpreise zuzüglich der ausgewiesenen Versandkosten.",
            "Die Zahlung erfolgt über Stripe. Verfügbar sind Kredit- und Debitkarte, Apple Pay und Google Pay auf Kartenbasis, Klarna und SEPA-Lastschrift. Welche Zahlungsarten im Einzelfall angezeigt werden, kann von deren Verfügbarkeit und den Voraussetzungen des jeweiligen Anbieters abhängen.",
            "Bei SEPA-Lastschrift bleibt der Vorgang bis zur endgültigen Stripe-Bestätigung in Bearbeitung. Bis dahin entstehen keine angenommene Bestellung, Bestellnummer oder Versandfreigabe.",
          ],
        }
      : section,
  ),
  privacy: productionApprovedTexts.privacy.map((section) => {
    if (section.heading === "3. Zahlung, E-Mail und Versand") {
      return {
        ...section,
        paragraphs: [
          "Die Zahlung wird über Stripe abgewickelt. Verfügbar sind Kredit- und Debitkarte, Apple Pay und Google Pay auf Kartenbasis, Klarna und SEPA-Lastschrift. Stripe und die jeweils einbezogenen Anbieter verarbeiten die für Zahlung, Authentifizierung und Betrugsprävention erforderlichen Daten. Rechtsgrundlagen sind Art. 6 Abs. 1 Buchstaben b und f DSGVO.",
          "Transaktionsmails versenden wir über Brevo SAS. Für den Versand geben wir Name, Lieferanschrift und gegebenenfalls eine Versandkennung an die Deutsche Post AG weiter. Dies erfolgt zur Vertragserfüllung nach Art. 6 Abs. 1 Buchstabe b DSGVO.",
          "Datenschutzhinweise: https://stripe.com/de/privacy, https://www.klarna.com/de/datenschutz/ und https://www.brevo.com/de/legal/privacypolicy/.",
        ],
      };
    }
    if (section.heading === "5. Empfänger und Drittlandverarbeitung") {
      return {
        ...section,
        paragraphs: [
          "Empfänger können IONOS, Stripe, Klarna, Brevo, Deutsche Post AG sowie gesetzlich berechtigte Behörden und Berater sein. Mit Auftragsverarbeitern werden erforderliche Verträge geschlossen.",
          "Stripe, Klarna und deren Unterauftragnehmer können Daten außerhalb der EU oder des EWR verarbeiten. Maßgeblich sind die jeweils anwendbaren Datenschutzinformationen und Übermittlungsgrundlagen der Anbieter.",
          "Ein einfacher Link zu Instagram überträgt beim bloßen Seitenaufruf keine Daten. Erst beim Anklicken gelten die Datenschutzbestimmungen der Plattform.",
        ],
      };
    }
    return section;
  }),
  withdrawal: productionApprovedTexts.withdrawal,
  shipping: productionApprovedTexts.shipping.map((section) =>
    section.heading === "Zahlung"
      ? {
          ...section,
          paragraphs: [
            "Die Zahlung erfolgt über Stripe. Verfügbar sind Kredit- und Debitkarte, Apple Pay und Google Pay auf Kartenbasis, Klarna und SEPA-Lastschrift.",
            "Mit „zahlungspflichtig bestellen“ geben Sie ein verbindliches Kaufangebot ab und autorisieren die Zahlung. Das Angebot wird erst nach endgültiger Zahlungsbestätigung durch Stripe angenommen. Anschließend erhalten Sie unverzüglich die Bestellbestätigung.",
            "SEPA-Lastschrift kann zunächst als „in Bearbeitung“ angezeigt werden. Bis zur endgültigen Bestätigung bleibt das Unikat reserviert; es entstehen noch keine angenommene Bestellung, Bestellnummer oder Versandfreigabe. Bei endgültigem Fehlschlag kommt kein Vertrag zustande.",
          ],
        }
      : section,
  ),
};

export const testLegalBundle: LegalBundle = createLegalBundle({
  id: "cmj-test-legal-2026-08-06-v2",
  environment: "test",
  version: "v2",
  status: "test_only",
  archiveUrl: "/legal-archive/test/cmj-test-legal-2026-08-06-v2/",
  createdAt: "2026-08-06T00:00:00.000Z",
  texts: testTexts,
});

export const productionLegalBundle: LegalBundle = createLegalBundle({
  id: "cmj-production-legal-2026-08-07-v3",
  environment: "production",
  version: "v3",
  status: "approved",
  archiveUrl: "/legal-archive/production/cmj-production-legal-2026-08-07-v3/",
  createdAt: "2026-08-07T00:00:00.000Z",
  texts: productionApprovedTexts,
});

export const productionLegalBundleV4Candidate: LegalBundle = createLegalBundle({
  id: "cmj-production-legal-2026-08-11-v4",
  environment: "production",
  version: "v4",
  status: "awaiting_external_approval",
  archiveUrl: "/legal-archive/production/cmj-production-legal-2026-08-11-v4/",
  createdAt: "2026-08-11T00:00:00.000Z",
  texts: productionAwaitingTextsV4,
});

export const legalBundlesByTarget = {
  test: [testLegalBundle],
  production: [productionLegalBundle],
} as const;

export function getActiveLegalBundle(): LegalBundle {
  const bundle = legalBundlesByTarget[siteTarget.name][0];
  validateLegalBundle(bundle, siteTarget.name);
  return bundle;
}
