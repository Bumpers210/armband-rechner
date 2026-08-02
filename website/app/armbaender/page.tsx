import type { Metadata } from "next";
import Link from "next/link";

import { ProductImageGallery } from "@/components/product-image-gallery";
import { SiteFooter } from "@/components/site-footer";
import { SiteHeader } from "@/components/site-header";
import { siteTarget } from "@/config/site-target";
import { visibleProducts } from "@/content/products";

export const metadata: Metadata = {
  title: "Verfügbare Armbänder",
  description:
    "Aktuell verfügbare handgefertigte Edelsteinarmbänder von Carmaja-Perlen mit Bildern, Materialien und sicherem Direktkauf.",
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
            <p className="v2-eyebrow">Aktuell verfügbar</p>
            <h1>Handgefertigte Armbänder</h1>
            <p>
              Eine kleine Auswahl aktuell verfügbarer Stücke. Preis und
              Verfügbarkeit werden beim Kauf live geprüft.
            </p>
          </div>
        </section>

        <section className="products-list-section" aria-label="Produktübersicht">
          <div className="content-shell">
            {visibleProducts.length === 0 ? (
              <div className="products-empty">
                <h2>Gerade kein Armband online</h2>
                <p>
                  Neue handgefertigte Stücke erscheinen hier, sobald sie
                  fotografiert und für den Direktkauf freigegeben sind.
                </p>
              </div>
            ) : (
              <div className="products-grid">
                {visibleProducts.map((product) => (
                  <article className="product-card" key={product.sku}>
                    <ProductImageGallery
                      images={product.images}
                      productName={product.publicTitle}
                      variant="card"
                    />
                    <div className="product-card-copy">
                      <h2>
                        <Link href={`/armbaender/${product.slug}/`}>
                          {product.publicTitle}
                        </Link>
                      </h2>
                      <p>{product.description}</p>
                      <dl className="product-facts">
                        <div>
                          <dt>Materialien</dt>
                          <dd>{product.materials.join(", ")}</dd>
                        </div>
                        <div>
                          <dt>Metallelemente</dt>
                          <dd>
                            {product.metalElements.length > 0
                              ? product.metalElements.join(", ")
                              : "Keine"}
                          </dd>
                        </div>
                        <div>
                          <dt>Größe</dt>
                          <dd>{product.displaySize}</dd>
                        </div>
                      </dl>
                    </div>
                  </article>
                ))}
              </div>
            )}
          </div>
        </section>
      </main>

      <SiteFooter />
    </div>
  );
}
