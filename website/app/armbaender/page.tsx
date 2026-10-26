import type { Metadata } from "next";

import { CelestialOrnament } from "@/components/celestial-ornament";
import { ProductList } from "@/components/product-list";
import { SiteFooter } from "@/components/site-footer";
import { SiteHeader } from "@/components/site-header";
import { siteTarget } from "@/config/site-target";
import { visibleProducts } from "@/content/products";

export const metadata: Metadata = {
  title: "Verfügbare Armbänder",
  description:
    "Aktuell verfügbare handgefertigte Edelsteinarmbänder von Carmaja-Perlen mit Bildern, Materialien und Größen.",
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
            <h1>Handgefertigte Armbänder</h1>
            <p>
              Jeder Stein erzählt seine eigene Geschichte. Entdecke Farben,
              die dich berühren, und ein Armband, das sich nach dir anfühlt.
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
              <ProductList products={visibleProducts} />
            )}
          </div>
        </section>
      </main>

      <SiteFooter />
    </div>
  );
}
