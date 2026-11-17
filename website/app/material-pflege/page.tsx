import type { Metadata } from "next";
import Link from "next/link";

import { SiteFooter } from "@/components/site-footer";
import { SiteHeader } from "@/components/site-header";
import { siteContent } from "@/content/site-content";

export const metadata: Metadata = {
  title: "Material & Pflege",
  description:
    "So reinigst und lagerst du Natursteinarmbänder schonend und schützt Perlen, Metallelemente und Elastikband beim Tragen.",
  alternates: {
    canonical: "/material-pflege/",
  },
};

export default function MaterialsAndCarePage() {
  return (
    <div className="v2-page">
      <SiteHeader />
      <main id="main-content">
        <section className="v2-page-intro">
          <div className="content-shell">
            <p className="v2-eyebrow">{siteContent.materials.eyebrow}</p>
            <h1>Material & Pflege</h1>
            <p>
              Natursteine, Quarz, Lavasteine und Edelstahlelemente geben den
              Armbändern ihren natürlichen Charakter.
            </p>
          </div>
        </section>
        <section className="v2-information">
          <div className="content-shell">
            <div className="v2-information-grid">
              <div className="v2-information-column">
                <h2>{siteContent.materials.title}</h2>
                <ul>
                  {siteContent.materials.items.map((item) => (
                    <li key={item}>{item}</li>
                  ))}
                </ul>
              </div>
              <div className="v2-information-column v2-information-column--care">
                <h2>{siteContent.care.title}</h2>
                <ul>
                  {siteContent.care.items.map((item) => (
                    <li key={item}>{item}</li>
                  ))}
                </ul>
              </div>
            </div>
          </div>
        </section>
        <section className="content-shell legal-content bracelet-guide" aria-labelledby="care-details">
          <h2 id="care-details">Armbänder schonend pflegen</h2>
          <section>
            <h3>Reinigung und Wasser</h3>
            <p>
              Wische die Perlen und Zwischenelemente mit einem weichen, trockenen
              Tuch ab. Lege dein Armband vor dem Duschen, Baden und Schwimmen ab.
              Bei unbekannter Steinbehandlung lass dir vor einer feuchten Reinigung
              sagen, welche Pflege für das konkrete Armband geeignet ist.
              Verzichte auf Schmucktauchbäder, Ultraschall und Dampfreiniger.
            </p>
          </section>
          <section>
            <h3>Aufbewahrung und Elastikband</h3>
            <p>
              Lagere das Armband trocken und separat in einem weichen Beutel
              oder einem ausgekleideten Fach. So reibt es nicht an anderem Schmuck.
              Rolle ein elastisches Armband vorsichtig über die Hand, statt
              das Band weit auseinanderzuziehen. Lege es bei Tätigkeiten ab,
              bei denen es hängen bleiben oder anstoßen kann.
            </p>
          </section>
          <section>
            <h3>Besonderheiten einzelner Materialien</h3>
            <ul>
              <li>Schütze Fluorit besonders vor Stößen und Kratzern durch härteren Schmuck.</li>
              <li>Bewahre Amethyst vor grellem Sonnenlicht über längere Zeit geschützt auf, weil seine Farbe verblassen kann.</li>
              <li>Halte poröse Lavaperlen und oberflächenbehandelte Perlen von Parfüm, Ölen und Reinigungsmitteln fern.</li>
            </ul>
            <p><Link className="v2-text-link" href="/kontakt/">Frage zur Pflege stellen</Link></p>
          </section>
        </section>
      </main>
      <SiteFooter />
    </div>
  );
}
