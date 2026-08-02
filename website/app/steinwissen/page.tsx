import type { Metadata } from "next";
import Link from "next/link";

import { SiteFooter } from "@/components/site-footer";
import { SiteHeader } from "@/components/site-header";
import { StoneKnowledgeBreadcrumbs } from "@/components/stone-knowledge-breadcrumbs";

export const metadata: Metadata = {
  title: "Steinwissen",
  description:
    "Grundlagen zu spirituellen Überlieferungen und Natursteinkunde bei Carmaja-Perlen.",
  alternates: {
    canonical: "/steinwissen/",
  },
};

export default function StoneKnowledgePage() {
  return (
    <div className="v2-page">
      <SiteHeader />
      <main id="main-content" className="stone-knowledge-main">
        <section className="v2-page-intro">
          <div className="content-shell">
            <StoneKnowledgeBreadcrumbs />
            <p className="v2-eyebrow">Einordnung & Grundlagen</p>
            <h1>Steinwissen</h1>
            <p>
              Zwei Perspektiven auf Natursteine: überlieferte Symbolik und
              Grundlagen aus der Natursteinkunde.
            </p>
          </div>
        </section>

        <section className="stone-knowledge-section" aria-label="Themenbereiche">
          <div className="content-shell stone-knowledge-link-grid">
            <Link className="stone-knowledge-link-card" href="/steinwissen/spirituelle-bedeutung/">
              <p className="v2-eyebrow">Überlieferungen</p>
              <h2>Spirituelle Bedeutung</h2>
              <p>
                Kulturelle und spirituelle Deutungen von Natursteinen – klar
                als Überlieferungen eingeordnet.
              </p>
              <span>Grundlagen lesen</span>
            </Link>
            <Link className="stone-knowledge-link-card" href="/steinwissen/natursteinkunde/">
              <p className="v2-eyebrow">Materialwissen</p>
              <h2>Natursteinkunde</h2>
              <p>
                Kompakte Grundlagen zu Mineralien, Handelsnamen und
                Bearbeitungen von Schmucksteinen.
              </p>
              <span>Grundlagen lesen</span>
            </Link>
          </div>
        </section>
      </main>
      <SiteFooter />
    </div>
  );
}
