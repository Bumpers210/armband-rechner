import type { Metadata } from "next";
import Image from "next/image";

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
              Natursteine entstehen über lange Zeiträume tief in der Erde. Auf
              diesem Weg bekommt jedes Stück seine eigene Farbe, Zeichnung und
              Oberfläche.
            </p>
          </div>
        </section>

        <section className="stone-knowledge-section">
          <div className="content-shell stone-knowledge-reading-width">
            <h2>Wie entstehen Natursteine?</h2>
            <p>
              Manche Steine entstehen, wenn heißes, flüssiges Gestein abkühlt.
              Andere wachsen aus Ablagerungen, die sich Schicht für Schicht
              verdichten. Wieder andere verändern sich, wenn Gestein lange
              unter Druck und Hitze steht. So entstehen viele unterschiedliche
              natürliche Materialien.
            </p>
            <figure className="stone-knowledge-figure">
              <Image
                src="/images/stone-knowledge/natural-stones-texture.jpg"
                alt="Verschiedenfarbige natürliche Steine mit unterschiedlichen Oberflächen und Maserungen."
                width={1012}
                height={1800}
                sizes="(max-width: 767px) calc(100vw - 32px), 44rem"
              />
            </figure>
            <h2>Warum sieht jeder Stein anders aus?</h2>
            <p>
              Farbe, Maserung, Transparenz, Einschlüsse und Oberflächenstruktur
              können von Stein zu Stein unterschiedlich sein. Kleine Linien,
              Einschlüsse oder Unebenheiten sind kein Makel. Sie gehören zur
              natürlichen Vielfalt und machen jeden Stein unverwechselbar.
            </p>
            <h2>Natürlich und behandelt</h2>
            <p>
              Für Schmuck werden Steine meist geschliffen und poliert. Dadurch
              kommen Farbe und Muster oft deutlicher zur Geltung. Manche Steine
              werden darüber hinaus erhitzt, gefärbt, stabilisiert oder
              beschichtet. Auch Polieren und Mattieren verändern die Oberfläche.
              Solche Bearbeitungen können Farbe, Oberfläche, Aussehen oder
              Haltbarkeit beeinflussen.
            </p>
            <p>
              Naturbelassene Steine haben keine zusätzliche
              erscheinungsverändernde Behandlung erhalten. Behandelte Steine
              wurden gezielt verändert. Synthetische Steine werden im Labor
              hergestellt; imitierte Steine ahmen nur das Aussehen eines
              Natursteins nach.
            </p>
            <figure className="stone-knowledge-figure">
              <Image
                src="/images/stone-knowledge/polished-gemstones-colours.jpg"
                alt="Polierte Schmucksteine in verschiedenen Farben mit glänzenden Oberflächen und sichtbaren Mustern."
                width={1800}
                height={1200}
                sizes="(max-width: 767px) calc(100vw - 32px), 44rem"
              />
            </figure>
            <StoneKnowledgeSourceList sources={stoneKnowledgePublicSources} />
          </div>
        </section>
      </main>
      <SiteFooter />
    </div>
  );
}
