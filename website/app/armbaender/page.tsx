import type { Metadata } from "next";

import { ProductList } from "@/components/product-list";
import { SiteFooter } from "@/components/site-footer";
import { SiteHeader } from "@/components/site-header";
import { visibleProducts } from "@/content/products";

export const metadata: Metadata = {
  title: "Verfügbare Armbänder",
  description:
    "Aktuell verfügbare handgefertigte Edelsteinarmbänder von Carmaja-Perlen mit Bildern, Materialien und Größen.",
  alternates: {
    canonical: "/armbaender/",
  },
};

export default function ProductsPage() {
  return (
    <div className="v2-page">
      <SiteHeader />

      <main id="main-content" className="products-main">
        <section className="products-intro">
          <div className="content-shell products-intro-inner">
            <p className="v2-eyebrow">Aktuelle Auswahl</p>
            <h1>Handgefertigte Armbänder</h1>
            <p>
              Eine Auswahl aktuell verfügbarer Stücke mit Bildern, Materialien
              und Größen.
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
