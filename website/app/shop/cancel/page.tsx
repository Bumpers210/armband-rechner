import Link from "next/link";
import { CartPaymentStatus } from "@/components/cart-payment-status";
import { SiteHeader } from "@/components/site-header";
import { SiteFooter } from "@/components/site-footer";
import { CelestialOrnament } from "@/components/celestial-ornament";

export default function ShopCancelPage() {
  return (
    <div className="v2-page">
      <SiteHeader />
    <main id="main-content" className="legal-main">
      <div className="content-shell legal-content">
        <CelestialOrnament />
        <h1>Checkout nicht abgeschlossen</h1>
        <p>Der Browserabbruch beendet keine möglicherweise laufende Zahlung. Reservierte Mengen bleiben bis zum bestätigten endgültigen Zahlungsstatus gesichert.</p>
        <CartPaymentStatus />
        <p><Link href="/armbaender/">Zurück zu den Armbändern</Link></p>
      </div>
    </main>
      <SiteFooter />
    </div>
  );
}
