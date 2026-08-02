import type { Metadata } from "next";

import { SiteFooter } from "@/components/site-footer";
import { SiteHeader } from "@/components/site-header";
import { StoneKnowledgeBreadcrumbs } from "@/components/stone-knowledge-breadcrumbs";
import { StoneKnowledgeSourceList } from "@/components/stone-knowledge-source-list";
import { stoneKnowledgePublicSources } from "@/content/stone-knowledge-public";

export const metadata: Metadata = {
  title: "Natursteinkunde",
  description:
    "Kompakte Grundlagen zu Mineralien, Schmucksteinen, Handelsnamen und Bearbeitungen.",
  alternates: {
    canonical: "/steinwissen/natursteinkunde/",
  },
};

export default function NaturalStoneKnowledgePage() {
  return (
    <div className="v2-page">
      <SiteHeader />
      <main id="main-content" className="stone-knowledge-main">
        <section className="v2-page-intro">
          <div className="content-shell">
            <StoneKnowledgeBreadcrumbs current="Natursteinkunde" />
            <p className="v2-eyebrow">Materialwissen</p>
            <h1>Natursteinkunde: Grundlagen zu Mineralen und Schmucksteinen</h1>
            <p>
              Eine kompakte Einführung in Begriffe und Bearbeitungen, die beim
              Einordnen von Naturstein- und Schmuckmaterialien helfen.
            </p>
          </div>
        </section>

        <section className="stone-knowledge-section">
          <div className="content-shell stone-knowledge-reading-width">
            <h2>Mineralien, Gesteine und Schmucksteine</h2>
            <p>
              Mineralien sind natürlich vorkommende Stoffe mit
              charakteristischen Eigenschaften. Gesteine bestehen aus einem
              oder mehreren Mineralien. Als Schmucksteine werden Materialien
              bezeichnet, die wegen ihres Aussehens und ihrer Bearbeitbarkeit
              für Schmuck verwendet werden.
            </p>
            <h2>Handelsnamen und Bearbeitungen</h2>
            <p>
              Handelsnamen können von mineralogischen Bezeichnungen abweichen.
              Schleifen und Polieren gehören zur üblichen Bearbeitung von
              Schmucksteinen. Weitere Behandlungen sollten nachvollziehbar
              angegeben werden, weil sie Aussehen, Beständigkeit oder
              Wertwahrnehmung beeinflussen können.
            </p>
            <h2>Materialeigenschaften beachten</h2>
            <p>
              Härte, Zähigkeit und Empfindlichkeit unterscheiden sich je nach
              Material. Deshalb passen Pflege und Nutzung am besten zu den
              Eigenschaften des jeweiligen Schmuckmaterials.
            </p>
            <StoneKnowledgeSourceList sources={stoneKnowledgePublicSources} />
          </div>
        </section>
      </main>
      <SiteFooter />
    </div>
  );
}
