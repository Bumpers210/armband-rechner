import type { Metadata } from "next";
import Link from "next/link";

import { SiteFooter } from "@/components/site-footer";
import { SiteHeader } from "@/components/site-header";
import { siteTarget } from "@/config/site-target";
import { siteContent } from "@/content/site-content";

export const metadata: Metadata = {
  title: {
    absolute: "Impressum | Carmaja-Perlen",
  },
  alternates: {
    canonical: "/impressum/",
  },
  robots: {
    index: false,
    follow: !siteTarget.isTest,
    noimageindex: siteTarget.isTest,
  },
};

export default function ImprintPage() {
  const imprint = siteContent.legal.imprint;

  return (
    <div className="v2-page">
      <SiteHeader />
      <main className="legal-main" id="main-content">
        <div className="content-shell legal-content">
          <h1>{imprint.title}</h1>

          <section className="legal-section">
            <h2>{imprint.providerHeading}</h2>
            <address>
              <p>
                {imprint.name}
                <br />
                {imprint.brandName}
              </p>
              <p>
                {imprint.street}
                <br />
                {imprint.postalCodeAndCity}
                <br />
                {imprint.country}
              </p>
            </address>
          </section>

          <section className="legal-section">
            <h2>{imprint.contactHeading}</h2>
            <p>
              {imprint.emailLabel}:{" "}
              <a href={`mailto:${imprint.email}`}>{imprint.email}</a>
            </p>
            <p>
              {imprint.phoneLabel}: {imprint.phone}
            </p>
          </section>

          <section className="legal-section">
            <h2>{imprint.disputeHeading}</h2>
            <p>{imprint.disputeText}</p>
          </section>

          <p className="legal-back-link">
            <Link href="/">Zurück zur Startseite</Link>
          </p>
        </div>
      </main>
      <SiteFooter />
    </div>
  );
}
