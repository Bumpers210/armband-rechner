import Image from "next/image";

import { BraceletGallery } from "@/components/bracelet-gallery";
import { ContactEmailLink } from "@/components/contact-email-link";
import { SiteFooter } from "@/components/site-footer";
import { SiteHeader } from "@/components/site-header";
import { siteContent } from "@/content/site-content";

export default function Home() {
  const imprint = siteContent.legal.imprint;
  const [postalCode, ...localityParts] =
    imprint.postalCodeAndCity.split(" ");
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
            </div>
          </div>
        </section>

        <section className="v2-introduction" aria-label="Über Carmaja-Perlen">
          <div className="content-shell v2-introduction-grid">
            <p className="v2-introduction-statement">
              {siteContent.statement}
            </p>
            <p className="v2-introduction-copy">
              {siteContent.introduction}
            </p>
          </div>
        </section>

        <section className="v2-gallery-section" id="armbaender">
          <div className="content-shell">
            <div className="v2-section-heading">
              <div>
                <p className="v2-eyebrow">{siteContent.gallery.eyebrow}</p>
                <h2>{siteContent.gallery.title}</h2>
              </div>
              <p>{siteContent.gallery.introduction}</p>
            </div>

            <BraceletGallery />

            <div className="v2-gallery-cta">
            </div>
          </div>
        </section>

        <section className="v2-maker" id="ueber-mich">
          <div className="content-shell v2-maker-grid">
            <div className="v2-maker-media">
              <Image
                src={siteContent.maker.image.src}
                alt={siteContent.maker.image.alt}
                width={siteContent.maker.image.width}
                height={siteContent.maker.image.height}
                sizes="(max-width: 767px) calc(100vw - 32px), 42vw"
                className="v2-maker-image"
                style={{ objectPosition: siteContent.maker.image.objectPosition }}
              />
            </div>

            <div className="v2-maker-copy">
              <p className="v2-eyebrow">{siteContent.maker.eyebrow}</p>
              <h2>{siteContent.maker.title}</h2>
              <p>{siteContent.maker.text}</p>
            </div>
          </div>
        </section>

        <section className="v2-information" id="material-pflege">
          <div className="content-shell">
            <p className="v2-eyebrow v2-information-eyebrow">
              {siteContent.materials.eyebrow}
            </p>

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

        <section className="v2-closing" id="kontakt">
          <div className="content-shell v2-closing-grid">
            <div className="v2-closing-heading">
              <p className="v2-eyebrow">{siteContent.closing.eyebrow}</p>
              <h2>{siteContent.closing.title}</h2>
            </div>

            <div className="v2-closing-content">
              <p className="v2-closing-custom">
                {siteContent.closing.customText}
              </p>
              <p>{siteContent.closing.contactText}</p>
              <div className="v2-contact-placeholder">
                <ContactEmailLink className="v2-contact-email" />
              </div>
            </div>
          </div>
        </section>
      </main>

      <SiteFooter />
    </div>
  );
}
