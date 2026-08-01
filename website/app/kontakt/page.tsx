import type { Metadata } from "next";
import Link from "next/link";

import { ContactEmailLink } from "@/components/contact-email-link";
import { SiteFooter } from "@/components/site-footer";
import { SiteHeader } from "@/components/site-header";
import { siteContent } from "@/content/site-content";

export const metadata: Metadata = {
  title: "Kontakt",
  description:
    "Kontakt zu Carmaja-Perlen für Fragen zu Größen, Materialien und individuellen Anfertigungen.",
  alternates: {
    canonical: "/kontakt/",
  },
};

export default function ContactPage() {
  return (
    <div className="v2-page">
      <SiteHeader />
      <main id="main-content">
        <section className="v2-page-intro v2-page-intro--contact">
          <div className="content-shell">
            <p className="v2-eyebrow">{siteContent.closing.eyebrow}</p>
            <h1>{siteContent.closing.title}</h1>
            <p>{siteContent.closing.customText}</p>
          </div>
        </section>
        <section className="v2-contact-section">
          <div className="content-shell v2-contact-grid">
            <div>
              <h2>Kontakt aufnehmen</h2>
              <p>{siteContent.closing.contactText}</p>
            </div>
            <div className="v2-contact-card">
              <p>Per E-Mail erreichbar:</p>
              <ContactEmailLink className="v2-contact-email" />
            </div>
          </div>
          <p className="content-shell v2-section-action">
            <Link className="v2-button v2-button--outline" href="/armbaender/">
              Armbänder ansehen
            </Link>
          </p>
        </section>
      </main>
      <SiteFooter />
    </div>
  );
}
