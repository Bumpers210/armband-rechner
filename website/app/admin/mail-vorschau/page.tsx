import type { Metadata } from "next";
import Link from "next/link";
import { notFound } from "next/navigation";

import { SiteFooter } from "@/components/site-footer";
import { SiteHeader } from "@/components/site-header";
import { siteTarget } from "@/config/site-target";

export const metadata: Metadata = {
  title: "E-Mail-Vorschau",
  description: "Testvorschau der vier Shop-E-Mails mit künstlichen Beispieldaten.",
  robots: {
    index: false,
    follow: false,
    noimageindex: true,
    noarchive: true,
  },
};

const orderNumber = "TEST-2026-000001";
const productName = "Carmaja Testarmband";
const productId = "CP-TEST-0001";

function PreviewNote({ children }: Readonly<{ children: React.ReactNode }>) {
  return <p className="mail-preview-note">{children}</p>;
}

export default function MailPreviewPage() {
  if (!siteTarget.isTest) {
    notFound();
  }

  return (
    <div className="v2-page">
      <SiteHeader />

      <main id="main-content" className="mail-preview-main">
        <div className="content-shell mail-preview-content">
          <p className="v2-eyebrow">Geschützte Testumgebung</p>
          <h1>Vorschau der Shop-E-Mails</h1>
          <div className="mail-preview-safety" role="note">
            <strong>Nur Vorschau – kein Versand</strong>
            <p>
              Alle Angaben auf dieser Seite sind künstlich. Die Vorschau hat
              keine Verbindung zu Kundendaten, Datenbank, Checkout oder
              E-Mail-Anbieter.
            </p>
          </div>

          <nav className="mail-preview-navigation" aria-label="E-Mail-Vorschauen">
            <a href="#mail-preview-order">Bestellbestätigung</a>
            <a href="#mail-preview-operator">Betreiberhinweis</a>
            <a href="#mail-preview-shipping">Versandbestätigung</a>
            <a href="#mail-preview-withdrawal">Widerrufsbestätigung</a>
          </nav>

          <section className="mail-preview-section" id="mail-preview-order">
            <h2>1. Bestellbestätigung</h2>
            <PreviewNote>Empfänger: kaufende Person</PreviewNote>
            <article className="mail-preview-card" aria-label="Vorschau Bestellbestätigung">
              <header>
                <span>Betreff</span>
                <strong>Bestellbestätigung {orderNumber}</strong>
              </header>
              <div className="mail-preview-body">
                <p>Hallo Erika Muster,</p>
                <p>
                  vielen Dank für Ihre Bestellung. Ihre Zahlung wurde bestätigt
                  und wir haben Ihre Bestellung angenommen.
                </p>
                <h3>Bestellübersicht</h3>
                <p>
                  Bestellnummer: <strong>{orderNumber}</strong>
                </p>
                <ul>
                  <li>
                    Produkt: <strong>{productName}</strong> ({productId})
                  </li>
                  <li>Menge: 1</li>
                  <li>Produktpreis: 42,00 €</li>
                  <li>Versand: Standardversand Deutschland – 4,90 €</li>
                  <li>
                    Gesamtbetrag: <strong>46,90 €</strong>
                  </li>
                </ul>
                <h3>Vertragsunterlagen</h3>
                <p>
                  Die für diese Bestellung geltenden Shopbedingungen,
                  Datenschutz-, Widerrufs- sowie Versand- und
                  Zahlungsinformationen finden Sie in der dauerhaft
                  zugeordneten, unveränderlichen Fassung:
                </p>
                <p>
                  <Link href="/legal-archive/test/cmj-test-legal-2026-08-06-v2/">
                    Vertragsunterlagen dieser Bestellung öffnen
                  </Link>
                </p>
                <p>Bitte bewahren Sie diese E-Mail für Ihre Unterlagen auf.</p>
              </div>
            </article>
          </section>

          <section className="mail-preview-section" id="mail-preview-operator">
            <h2>2. Betreiberhinweis</h2>
            <PreviewNote>
              Empfänger: Shopbetreiberin – bewusst ohne Kundendaten und
              Zahlungsdetails
            </PreviewNote>
            <article className="mail-preview-card" aria-label="Vorschau Betreiberhinweis">
              <header>
                <span>Betreff</span>
                <strong>Neue Bestellung {orderNumber}</strong>
              </header>
              <div className="mail-preview-body">
                <p>Eine neue Bestellung wurde bestätigt.</p>
                <ul>
                  <li>
                    Bestellnummer: <strong>{orderNumber}</strong>
                  </li>
                  <li>
                    Produkt: {productName} ({productId})
                  </li>
                  <li>
                    Gesamtbetrag: <strong>46,90 €</strong>
                  </li>
                </ul>
                <p>
                  Kundendaten und Zahlungsdetails sind aus Datenschutzgründen
                  nicht Bestandteil dieser Nachricht. Bitte verwenden Sie für
                  die Bearbeitung die geschützte Shop-Administration.
                </p>
              </div>
            </article>
          </section>

          <section className="mail-preview-section" id="mail-preview-shipping">
            <h2>3. Versandbestätigung</h2>
            <PreviewNote>Empfänger: kaufende Person</PreviewNote>
            <article className="mail-preview-card" aria-label="Vorschau Versandbestätigung">
              <header>
                <span>Betreff</span>
                <strong>Versandbestätigung {orderNumber}</strong>
              </header>
              <div className="mail-preview-body">
                <p>
                  Ihre Bestellung <strong>{orderNumber}</strong> wurde versendet.
                </p>
                <p>
                  Produkt: <strong>{productName}</strong>
                </p>
                <p>Versandart: Standardversand Deutschland</p>
                <p>
                  Sendungsreferenz: <strong>TEST-SENDUNG-123456</strong>
                </p>
                <p>
                  Die voraussichtliche Lieferzeit richtet sich nach der in Ihrer
                  Bestellbestätigung zugeordneten Versandinformation.
                </p>
              </div>
            </article>
          </section>

          <section className="mail-preview-section" id="mail-preview-withdrawal">
            <h2>4. Widerrufsbestätigung</h2>
            <PreviewNote>Empfänger: widerrufende Person</PreviewNote>
            <article className="mail-preview-card" aria-label="Vorschau Widerrufsbestätigung">
              <header>
                <span>Betreff</span>
                <strong>Eingangsbestätigung Ihres Widerrufs</strong>
              </header>
              <div className="mail-preview-body">
                <p>Ihr Widerruf ist bei uns eingegangen.</p>
                <h3>Inhalt Ihrer Erklärung</h3>
                <p>
                  Sie haben den Vertrag zur Bestellung <strong>{orderNumber}</strong>{" "}
                  widerrufen.
                </p>
                <ul>
                  <li>Name: Erika Muster</li>
                  <li>E-Mail: erika.muster@example.invalid</li>
                </ul>
                <p>
                  Vorgang: <strong>TEST-WIDERRUF-0001</strong>
                  <br />
                  Datum und Uhrzeit des Eingangs: 14.08.2026, 10:30 Uhr
                </p>
                <p>
                  Diese Bestätigung löst nicht automatisch eine Erstattung,
                  Wiedereinlagerung oder Versandänderung aus.
                </p>
              </div>
            </article>
          </section>

          <p className="mail-preview-back-link">
            <Link href="/admin/">Zurück zur Shop-Administration</Link>
          </p>
        </div>
      </main>

      <SiteFooter />
    </div>
  );
}
