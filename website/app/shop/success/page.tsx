import Link from "next/link";
import { CheckoutStatusPanel } from "@/components/checkout-status";
import { SiteFooter } from "@/components/site-footer";
import { SiteHeader } from "@/components/site-header";

export default function ShopSuccessPage() {
  return (
    <div className="v2-page">
      <SiteHeader />

      <main id="main-content" className="checkout-confirmation-main">
        <div className="content-shell checkout-confirmation-shell">
          <section className="checkout-confirmation-card">
            <header className="checkout-confirmation-heading">
              <p className="v2-eyebrow">Ihre Bestellung</p>
              <h1>Vielen Dank</h1>
              <p>
                Wir prüfen gerade die Rückmeldung zu Ihrer Zahlung und zeigen
                Ihnen hier den aktuellen Stand.
              </p>
            </header>

            <CheckoutStatusPanel />

            <section className="checkout-next-steps" aria-labelledby="next-steps-heading">
              <h2 id="next-steps-heading">Wie geht es weiter?</h2>
              <ol>
                <li>
                  <span aria-hidden="true">1</span>
                  <div>
                    <strong>Bestätigung per E-Mail</strong>
                    <p>
                      Sobald die Zahlung bestätigt ist, erhalten Sie Ihre
                      Bestellbestätigung mit allen wichtigen Angaben.
                    </p>
                  </div>
                </li>
                <li>
                  <span aria-hidden="true">2</span>
                  <div>
                    <strong>Sorgfältig verpackt</strong>
                    <p>Wir bereiten Ihr Armband persönlich für den Versand vor.</p>
                  </div>
                </li>
                <li>
                  <span aria-hidden="true">3</span>
                  <div>
                    <strong>Unterwegs zu Ihnen</strong>
                    <p>Die voraussichtliche Lieferzeit beträgt 2 bis 4 Werktage.</p>
                  </div>
                </li>
              </ol>
            </section>

            <div className="checkout-confirmation-actions">
              <Link className="v2-button v2-button--warm" href="/armbaender/">
                Zurück zu den Armbändern
              </Link>
              <Link className="v2-button v2-button--outline" href="/">
                Zur Startseite
              </Link>
            </div>
          </section>
        </div>
      </main>

      <SiteFooter />
    </div>
  );
}
