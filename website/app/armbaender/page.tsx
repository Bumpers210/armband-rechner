import type { Metadata } from "next";

import { ProductList } from "@/components/product-list";
import { SiteFooter } from "@/components/site-footer";
import { SiteHeader } from "@/components/site-header";
import { siteTarget } from "@/config/site-target";
import { visibleProducts } from "@/content/products";

export const metadata: Metadata = {
  title: "Natursteinarmbänder kaufen – handgefertigt",
  description:
    "Entdecke handgefertigte Natursteinarmbänder. Vergleiche Farben, Materialien, Perlengrößen und Umfang.",
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
            <p className="v2-eyebrow">Armbänder zum Fest</p>
            <h1>Handgefertigte Natursteinarmbänder</h1>
            <p>
              Welche Farbe trägt dein Lieblingsmensch am liebsten? Schau dir
              die Steine aus der Nähe an und vergleiche Material und Größe.
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
