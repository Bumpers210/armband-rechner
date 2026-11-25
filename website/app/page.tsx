import type { Metadata } from "next";
import Image from "next/image";
import Link from "next/link";

import { ProductList } from "@/components/product-list";
import { SiteFooter } from "@/components/site-footer";
import { SiteHeader } from "@/components/site-header";
import { visibleProducts } from "@/content/products";
import { siteContent } from "@/content/site-content";
import styles from "./advent.module.css";

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
        <section className={styles.hero} aria-labelledby="advent-season">
          <div className={styles.spread}>
            <div className={styles.message}>
              <p className={styles.season} id="advent-season">Advent bei Carmaja-Perlen</p>
              <h1>
                Kleine Perlen,<br />
                <em>große Freude.</em>
              </h1>
              <p className={styles.description}>
                Handgefertigte Edelsteinarmbänder für den Gabentisch und für
                all die Tage danach.
              </p>
              <Link className={styles.discover} href="/armbaender/">
                Armbänder entdecken <span aria-hidden="true">→</span>
              </Link>
            </div>
            <div className={styles.portrait}>
              <Image
                src={siteContent.hero.image.src}
                alt={siteContent.hero.image.alt}
                width={siteContent.hero.image.width}
                height={siteContent.hero.image.height}
                sizes="(min-width: 76rem) 34rem, (min-width: 48rem) 46vw, 90vw"
                priority
              />
              <div className={styles.ornaments} aria-hidden="true">
                <span /><span />
              </div>
            </div>
          </div>
        </section>

        <section className={`v2-products-teaser ${styles.selection}`}>
          <div className="content-shell">
            <div className="v2-section-heading">
              <div>
                <h2>Geschenkideen aus Naturstein</h2>
              </div>
              <p>
                Du hast ein Armband ins Auge gefasst? So findest du die{" "}
                <Link className="v2-text-link" href="/armbandgroesse/">
                  passende Armbandgröße
                </Link>.
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
      </main>

      <SiteFooter />
    </div>
  );
}
