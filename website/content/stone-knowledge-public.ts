export type StoneKnowledgePublicSource = {
  title: string;
  publisher: string;
  url: string;
};

export const stoneKnowledgePublicSources = [
  {
    title: "Gem Treatments",
    publisher: "Gemological Institute of America (GIA)",
    url: "https://www.gia.edu/gem-treatment",
  },
  {
    title: "Mindat.org – Mineral and locality database",
    publisher: "Hudson Institute of Mineralogy",
    url: "https://www.mindat.org/a/about",
  },
] as const satisfies readonly StoneKnowledgePublicSource[];
