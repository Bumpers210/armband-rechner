export type StoneKnowledgeStatus =
  | "inventory"
  | "draft"
  | "approved"
  | "published";

export type StoneKnowledgeSource = {
  id: string;
  title: string;
  kind: "app" | "product-inventory" | "external";
  reviewedOn: string;
  url?: string;
};

export type StoneKnowledgeInventoryEntry = {
  slug: string;
  name: string;
  status: StoneKnowledgeStatus;
  sources: readonly string[];
};

// Dieses Modell bleibt bewusst intern. Öffentliche Seiten importieren es nicht;
// Inventareinträge sind erst nach fachlicher Prüfung und Veröffentlichung nutzbar.
export const stoneKnowledgeSources = [
  {
    id: "app-active-materials",
    title: "Aktive Materialliste der App",
    kind: "app",
    reviewedOn: "2026-08-02",
  },
  {
    id: "published-product-materials",
    title: "Materialangaben aus dem veröffentlichten Produktbestand",
    kind: "product-inventory",
    reviewedOn: "2026-08-02",
  },
] as const satisfies readonly StoneKnowledgeSource[];

export const stoneKnowledgeInventory = [
  { slug: "rosenquarz", name: "Rosenquarz", status: "inventory", sources: ["app-active-materials"] },
  { slug: "afrikanischer-tuerkis", name: "Afrikanischer Türkis", status: "inventory", sources: ["app-active-materials", "published-product-materials"] },
  { slug: "picasso-jaspis", name: "Picasso Jaspis", status: "inventory", sources: ["app-active-materials"] },
  { slug: "indischer-achat", name: "Indischer Achat", status: "inventory", sources: ["app-active-materials"] },
  { slug: "sonnenstein", name: "Sonnenstein", status: "inventory", sources: ["app-active-materials"] },
  { slug: "amazonit", name: "Amazonit", status: "inventory", sources: ["app-active-materials"] },
  { slug: "bergkristall", name: "Bergkristall", status: "inventory", sources: ["app-active-materials"] },
  { slug: "netzstein", name: "Netzstein", status: "inventory", sources: ["app-active-materials"] },
  { slug: "afrikanischer-tuerkis-frosted", name: "Afrikanischer Türkis frosted", status: "inventory", sources: ["app-active-materials"] },
  { slug: "sodalith", name: "Sodalith", status: "inventory", sources: ["app-active-materials"] },
  { slug: "weisser-achat", name: "Weißer Achat", status: "inventory", sources: ["app-active-materials"] },
  { slug: "black-stone", name: "Black Stone", status: "inventory", sources: ["app-active-materials"] },
  { slug: "amazonit-frosted-8-mm", name: "Amazonit frosted 8 mm", status: "inventory", sources: ["app-active-materials"] },
  { slug: "blaue-tigeraugen", name: "Blaue Tigeraugen", status: "inventory", sources: ["app-active-materials"] },
  { slug: "lavaperlen", name: "Lavaperlen", status: "inventory", sources: ["app-active-materials"] },
  { slug: "goldene-lavaperlen", name: "Goldene Lavaperlen", status: "inventory", sources: ["published-product-materials"] },
] as const satisfies readonly StoneKnowledgeInventoryEntry[];
