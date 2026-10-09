import Link from "next/link";
import { CartPaymentStatus } from "@/components/cart-payment-status";

export default function ShopCancelPage() {
  return (
    <main id="main-content" className="legal-main">
      <div className="content-shell legal-content">
        <h1>Checkout nicht abgeschlossen</h1>
        <p>Der Browserabbruch beendet keine möglicherweise laufende Zahlung. Reservierte Mengen bleiben bis zum bestätigten endgültigen Zahlungsstatus gesichert.</p>
        <CartPaymentStatus />
        <p><Link href="/armbaender/">Zurück zu den Armbändern</Link></p>
      </div>
    </main>
  );
}
