import type { Metadata } from "next";

import { CelestialOrnament } from "@/components/celestial-ornament";
import { ProductList } from "@/components/product-list";
import { SiteFooter } from "@/components/site-footer";
import { SiteHeader } from "@/components/site-header";
import { siteTarget } from "@/config/site-target";
import { visibleProducts } from "@/content/products";

export const metadata: Metadata = {
  title: "Natursteinarmbänder kaufen – handgefertigt",
  description:
    "Entdecke handgefertigte Natursteinarmbänder aus Sodalith, Rosenquarz und weiteren Steinen. Vergleiche Farben, Materialien, Perlengrößen und Umfang.",
  alternates: {
    canonical: "/armbaender/",
  },
  robots: siteTarget.isTest
    ? {
        index: false,
        follow: false,
        noimageindex: true,
      }
    : undefined,
};

export default function ProductsPage() {
  return (
    <div className="v2-page">
      <SiteHeader />

      <main id="main-content" className="products-main">
        <section className="products-intro">
          <div className="content-shell products-intro-inner">
            <CelestialOrnament />
            <p className="v2-eyebrow">Von der Natur inspiriert. Für dich gefertigt.</p>
            <h1>Handgefertigte Natursteinarmbänder</h1>
            <p>
              Von mattem Amazonit bis zu tiefblauem Sodalith findest du hier
              handgefertigte Armbänder aus Naturstein. Die Produktseiten zeigen
              die Perlen im Detail und geben dir Auskunft über Material und Größe.
            </p>
          </div>
        </section>

        <section className="products-list-section" aria-label="Produktübersicht">
          <div className="content-shell">
            {visibleProducts.length === 0 ? (
              <div className="products-empty">
                <h2>Gerade kein Armband online</h2>
                <p>
                  Neue handgefertigte Stücke werden hier gezeigt, sobald sie
                  verfügbar sind.
                </p>
              </div>
            ) : (
              <ProductList products={visibleProducts} prioritizeFirstImage />
            )}
          </div>
        </section>
      </main>

      <SiteFooter />
    </div>
  );
}
