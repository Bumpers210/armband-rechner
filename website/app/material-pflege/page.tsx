import type { Metadata } from "next";

import { SiteFooter } from "@/components/site-footer";
import { SiteHeader } from "@/components/site-header";
import { siteContent } from "@/content/site-content";

export const metadata: Metadata = {
  title: "Material & Pflege",
  description:
    "Materialien und Pflegehinweise für handgefertigte Edelsteinarmbänder von Carmaja-Perlen.",
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
      </main>
      <SiteFooter />
    </div>
  );
}
