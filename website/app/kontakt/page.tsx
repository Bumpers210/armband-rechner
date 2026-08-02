import type { Metadata } from "next";
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
            <ContactEmailLink className="v2-contact-card">
              <span>Per E-Mail erreichbar:</span>
              <span className="v2-contact-email">{siteContent.closing.email}</span>
            </ContactEmailLink>
          </div>
        </section>
      </main>
      <SiteFooter />
    </div>
  );
}
