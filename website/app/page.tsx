import type { Metadata } from "next";
import Image from "next/image";
import Link from "next/link";

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
  const imprint = siteContent.legal.imprint;
  const [postalCode, ...localityParts] = imprint.postalCodeAndCity.split(" ");
  const organizationJsonLd = {
    "@context": "https://schema.org",
    "@type": "Organization",
    name: siteContent.brandName,
    legalName: imprint.name,
    url: siteContent.metadata.siteUrl,
    email: imprint.email,
    address: {
      "@type": "PostalAddress",
      streetAddress: imprint.street,
      postalCode,
      addressLocality: localityParts.join(" "),
      addressCountry: "DE",
    },
    sameAs: [siteContent.instagram.url],
  };

  return (
    <div className="v2-page">
      <script
        type="application/ld+json"
        dangerouslySetInnerHTML={{
          __html: JSON.stringify(organizationJsonLd).replace(/</g, "\\u003c"),
        }}
      />
      <SiteHeader />

      <main id="main-content">
        <section className="v2-hero">
          <Image
            src={siteContent.hero.image.src}
            alt={siteContent.hero.image.alt}
            fill
            sizes="100vw"
            priority
            className="v2-hero-image"
            style={{ objectPosition: siteContent.hero.image.objectPosition }}
          />
          <div className="v2-hero-overlay" aria-hidden="true" />
          <div className="content-shell v2-hero-inner">
            <div className="v2-hero-copy">
              <p className="v2-eyebrow">{siteContent.hero.eyebrow}</p>
              <h1>{siteContent.hero.title}</h1>
              <p className="v2-hero-description">
                {siteContent.hero.description}
              </p>
              <Link className="v2-button v2-button--warm" href="/armbaender/">
                Armbänder ansehen
              </Link>
            </div>
          </div>
        </section>

        <section className="v2-introduction" aria-label="Über Carmaja-Perlen">
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
                <p className="v2-eyebrow">Aktuelle Auswahl</p>
                <h2>Armbänder entdecken</h2>
              </div>
              <p>
                Eine kleine Auswahl handgefertigter Stücke mit Bildern,
                Materialien und Größen.
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

        <section className="v2-home-materials">
          <div className="content-shell v2-home-materials-grid">
            <div>
              <p className="v2-eyebrow">{siteContent.materials.eyebrow}</p>
              <h2>Handarbeit und Materialien</h2>
              <p>
                Sorgfältige Handarbeit und die individuellen Farben,
                Maserungen und Einschlüsse der Natursteine prägen jedes Stück.
              </p>
            </div>
            <Link className="v2-feature-link" href="/material-pflege/">
              <span>Materialien & Pflege</span>
              <span aria-hidden="true">→</span>
            </Link>
          </div>
        </section>

        <section className="v2-closing">
          <div className="content-shell v2-closing-grid">
            <div className="v2-closing-heading">
              <p className="v2-eyebrow">{siteContent.closing.eyebrow}</p>
              <h2>{siteContent.closing.title}</h2>
            </div>
            <div className="v2-closing-content">
              <p className="v2-closing-custom">{siteContent.closing.customText}</p>
              <p>{siteContent.closing.contactText}</p>
              <Link className="v2-button v2-button--light" href="/kontakt/">
                Kontakt aufnehmen
              </Link>
            </div>
          </div>
        </section>
      </main>

      <SiteFooter />
    </div>
  );
}
