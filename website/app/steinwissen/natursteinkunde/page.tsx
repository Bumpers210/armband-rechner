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
              Natursteine entstehen über sehr lange Zeiträume in der Erde. Ihre
              Farben, Muster und Strukturen machen ihre natürliche Vielfalt
              sichtbar.
            </p>
          </div>
        </section>

        <section className="stone-knowledge-section">
          <div className="content-shell stone-knowledge-reading-width">
            <h2>Wie entstehen Natursteine?</h2>
            <p>
              Natursteine können auf verschiedene Weise entstehen: wenn
              geschmolzenes Gestein abkühlt, sich Ablagerungen über lange Zeit
              verdichten oder bereits vorhandenes Gestein durch Druck und Hitze
              umgewandelt wird. So entstehen viele unterschiedliche natürliche
              Materialien.
            </p>
            <h2>Warum sieht jeder Stein anders aus?</h2>
            <p>
              Farbe, Maserung, Transparenz, Einschlüsse und Oberflächenstruktur
              können von Stein zu Stein unterschiedlich sein. Solche
              Abweichungen sind ein Merkmal natürlicher Materialien und kein
              Fehler.
            </p>
            <h2>Natürlich und behandelt</h2>
            <p>
              Naturbelassene Steine werden für Schmuck üblicherweise geschliffen
              und poliert. Darüber hinaus können Steine erhitzt, gefärbt,
              stabilisiert oder beschichtet werden. Auch Polieren und Mattieren
              verändern die Oberfläche. Solche Bearbeitungen können Farbe,
              Oberfläche, Aussehen oder Haltbarkeit beeinflussen.
            </p>
            <p>
              Naturbelassene Steine haben keine zusätzliche
              erscheinungsverändernde Behandlung erhalten. Behandelte Steine
              wurden gezielt verändert. Synthetische Steine werden im Labor
              hergestellt; imitierte Steine ahmen nur das Aussehen eines
              Natursteins nach.
            </p>
            <StoneKnowledgeSourceList sources={stoneKnowledgePublicSources} />
          </div>
        </section>
      </main>
      <SiteFooter />
    </div>
  );
}
