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
      ],
    },
  ],
};

const productionPendingTexts: LegalBundleTexts = {
  terms: [{ heading: "Produktionsfreigabe ausstehend", paragraphs: ["Die produktive Rechtstextfassung ist noch nicht extern freigegeben. Bis zur Freigabe bleibt der Verkauf deaktiviert."] }],
  privacy: [{ heading: "Produktionsfreigabe ausstehend", paragraphs: ["Die produktive Datenschutzerklärung ist noch nicht extern freigegeben."] }],
  withdrawal: [{ heading: "Produktionsfreigabe ausstehend", paragraphs: ["Die produktive Widerrufsinformation ist noch nicht extern freigegeben."] }],
  shipping: [{ heading: "Produktionsfreigabe ausstehend", paragraphs: ["Die produktive Versandinformation ist noch nicht extern freigegeben."] }],
};

export const testLegalBundle: LegalBundle = createLegalBundle({
  id: "cmj-test-legal-2026-08-02-v1",
  environment: "test",
  version: "v1",
  status: "test_only",
  archiveUrl: "/legal-archive/test/cmj-test-legal-2026-08-02-v1/",
  createdAt: "2026-08-02T00:00:00.000Z",
  texts: testTexts,
});

export const productionLegalBundle: LegalBundle = createLegalBundle({
  id: "cmj-production-legal-2026-08-02-v1",
  environment: "production",
  version: "v1",
  status: "awaiting_external_approval",
  archiveUrl: "/legal-archive/production/cmj-production-legal-2026-08-02-v1/",
  createdAt: "2026-08-02T00:00:00.000Z",
  texts: productionPendingTexts,
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
