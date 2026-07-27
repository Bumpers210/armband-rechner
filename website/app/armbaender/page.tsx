import type { Metadata } from "next";
import Image from "next/image";
import Link from "next/link";

import { ProductVintedLink } from "@/components/product-vinted-link";
import { SiteFooter } from "@/components/site-footer";
import { SiteHeader } from "@/components/site-header";
import { mainProductImage, visibleProducts } from "@/content/products";

export const metadata: Metadata = {
  title: "Verfügbare Armbänder",
  description:
    "Aktuell verfügbare handgefertigte Edelsteinarmbänder von Carmaja-Perlen mit Bildern, Materialien und Vinted-Angebotslink.",
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
            <p className="v2-eyebrow">Aktuell verfügbar</p>
            <h1>Handgefertigte Armbänder</h1>
            <p>
              Eine kleine Auswahl aktuell verfügbarer Stücke. Der Kauf und die
              verbindlichen Angebotsdetails laufen weiterhin über Vinted.
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
                  fotografiert und auf Vinted eingestellt sind.
                </p>
              </div>
            ) : (
              <div className="products-grid">
                {visibleProducts.map((product) => {
                  const image = mainProductImage(product);

                  return (
                    <article className="product-card" key={product.draftId}>
                      {image ? (
                        <Link
                          className="product-card-media"
                          href={`/armbaender/${product.slug}/`}
                        >
                          <Image
                            src={image.src}
                            alt={image.alt}
                            width={image.width}
                            height={image.height}
                            sizes="(max-width: 767px) calc(100vw - 32px), (max-width: 1023px) 50vw, 33vw"
                            className="product-card-image"
                          />
                        </Link>
                      ) : null}

                      <div className="product-card-copy">
                        <p className="product-sku">{product.sku}</p>
                        <h2>
                          <Link href={`/armbaender/${product.slug}/`}>
                            {product.name}
                          </Link>
                        </h2>
                        <p>{product.shortDescription}</p>
                        <dl className="product-facts">
                          <div>
                            <dt>Material</dt>
                            <dd>{product.materials.join(", ")}</dd>
                          </div>
                          <div>
                            <dt>Größe</dt>
                            <dd>{product.braceletSize}</dd>
                          </div>
                        </dl>
                        <ProductVintedLink product={product} />
                      </div>
                    </article>
                  );
                })}
              </div>
            )}
          </div>
        </section>
      </main>

      <SiteFooter />
    </div>
  );
}
