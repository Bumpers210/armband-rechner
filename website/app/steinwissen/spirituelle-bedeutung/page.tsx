import type { Metadata } from "next";

import { SiteFooter } from "@/components/site-footer";
import { SiteHeader } from "@/components/site-header";
import { StoneKnowledgeBreadcrumbs } from "@/components/stone-knowledge-breadcrumbs";

export const metadata: Metadata = {
  title: "Spirituelle Bedeutung von Natursteinen",
  description:
    "Eine Einordnung spiritueller und kultureller Überlieferungen zu Natursteinen.",
  alternates: {
    canonical: "/steinwissen/spirituelle-bedeutung/",
  },
};

export default function SpiritualMeaningPage() {
  return (
    <div className="v2-page">
      <SiteHeader />
      <main id="main-content" className="stone-knowledge-main">
        <section className="v2-page-intro">
          <div className="content-shell">
            <StoneKnowledgeBreadcrumbs current="Spirituelle Bedeutung" />
            <p className="v2-eyebrow">Überlieferungen</p>
            <h1>Spirituelle Bedeutung von Natursteinen</h1>
            <p>
              Natursteine spielen in vielen kulturellen und spirituellen
              Überlieferungen eine Rolle. Dieser Bereich ordnet solche
              Deutungen als historische und persönliche Zuschreibungen ein.
            </p>
          </div>
        </section>

        <section className="stone-knowledge-section">
          <div className="content-shell stone-knowledge-reading-width">
            <p className="stone-knowledge-notice">
              Die beschriebenen Bedeutungen beruhen auf Überlieferungen und
              spirituellen Deutungen. Sie sind wissenschaftlich nicht belegt
              und ersetzen keine medizinische Beratung oder Behandlung.
            </p>
            <h2>Persönliche und kulturelle Deutungen</h2>
            <p>
              Symbolische Bedeutungen entstehen in Erzählungen, religiösen
              Traditionen, regionalen Bräuchen und persönlichen Erfahrungen.
              Sie können Anlass für Reflexion oder ein bewusst gewähltes
              Schmuckstück sein, sind jedoch keine nachprüfbaren Eigenschaften
              eines Materials.
            </p>
            <h2>Verantwortungsvoll einordnen</h2>
            <p>
              Wir unterscheiden zwischen solchen Deutungen und Aussagen über
              mineralogische Merkmale. Für Entscheidungen zu Gesundheit oder
              Behandlung sind medizinische Fachpersonen die richtige
              Anlaufstelle.
            </p>
          </div>
        </section>
      </main>
      <SiteFooter />
    </div>
  );
}
