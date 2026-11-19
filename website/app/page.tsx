import type { Metadata } from "next";
import siteImages from "@/content/site-image-previews.json";
import Link from "next/link";

import { CelestialOrnament } from "@/components/celestial-ornament";
import { JsonLd } from "@/components/json-ld";
import { merchantOrganizationStructuredData } from "@/lib/merchant-policies";
import { ProductList } from "@/components/product-list";
import { SiteFooter } from "@/components/site-footer";
import { SiteHeader } from "@/components/site-header";
import { visibleProducts } from "@/content/products";
import { siteContent } from "@/content/site-content";

export const metadata: Metadata = {
  title: {
    absolute: "Handgefertigte Edelsteinarmbänder | Carmaja-Perlen",
  },
  description: siteContent.metadata.description,
  alternates: {
    canonical: "/",
  },
};

export default function Home() {
  const organizationJsonLd = merchantOrganizationStructuredData();
  // object-fit: cover needs more pixels than the narrow viewport's width.
  const heroSizes = "(max-width: 47.99rem) 56rem, (max-width: 76rem) max(100vw, 58.67rem), 100vw";

  return (
    <div className="v2-page">
      {organizationJsonLd ? <JsonLd data={organizationJsonLd} /> : null}
      <SiteHeader />

      <main id="main-content">
        <section className="v2-hero">
          <link rel="preload" as="image" type="image/webp" href={siteImages.hero.preloadSrc}
            imageSrcSet={siteImages.hero.webpSrcSet} imageSizes={heroSizes} fetchPriority="high" />
          <picture className="responsive-picture">
            <source type="image/webp" srcSet={siteImages.hero.webpSrcSet} sizes={heroSizes} />
            {/* oxlint-disable-next-line nextjs/no-img-element -- Prebuilt sources preserve the static hero layout without client image code. */}
            <img src={siteContent.hero.image.src} alt={siteContent.hero.image.alt}
              decoding="async" loading="eager" fetchPriority="high" className="v2-hero-image"
              style={{ position: "absolute", height: "100%", width: "100%", inset: 0,
                objectPosition: siteContent.hero.image.objectPosition }} />
          </picture>
          <div className="v2-hero-overlay" aria-hidden="true" />
          <div className="content-shell v2-hero-inner">
            <div className="v2-hero-copy">
              <p className="v2-eyebrow">{siteContent.hero.eyebrow}</p>
              <h1>{siteContent.hero.title}</h1>
              <p className="v2-hero-description">
                {siteContent.hero.description}
              </p>
              <Link className="v2-button v2-button--warm" href="/armbaender/">
                Dein Armband entdecken
              </Link>
            </div>
          </div>
        </section>

        <section className="v2-introduction" aria-label="Über Carmaja-Perlen">
          <div className="v2-section-ornament"><CelestialOrnament /></div>
          <div className="content-shell v2-introduction-grid">
            <p className="v2-introduction-statement">{siteContent.statement}</p>
            <div className="v2-introduction-copy">
              <p>{siteContent.introduction}</p>
              <Link className="v2-text-link" href="/ueber-mich/">
                Mehr über Carmaja-Perlen
              </Link>
            </div>
          </div>
        </section>

        <section className="v2-products-teaser">
          <div className="content-shell">
            <div className="v2-section-heading">
              <div>
                <p className="v2-eyebrow">Kleine Schätze aus Naturstein</p>
                <h2>Was spricht dich an?</h2>
              </div>
              <p>
                Sanfte Farben oder ausdrucksstarke Steine? Lass dich von
                unserer Auswahl inspirieren und finde deinen persönlichen Begleiter.
              </p>
            </div>
            {visibleProducts.length > 0 ? (
              <ProductList
                products={visibleProducts.slice(0, 3)}
                headingLevel="h3"
              />
            ) : (
              <p className="products-empty">
                Neue handgefertigte Stücke werden hier gezeigt, sobald sie
                verfügbar sind.
              </p>
            )}
            <p className="v2-section-action">
              <Link className="v2-button v2-button--outline" href="/armbaender/">
                Alle Armbänder ansehen
              </Link>
            </p>
          </div>
        </section>

      </main>

      <SiteFooter />
    </div>
  );
}
