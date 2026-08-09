import Link from "next/link";
import { CheckoutStatusPanel } from "@/components/checkout-status";

export default function ShopSuccessPage() {
  return (
    <main id="main-content" className="legal-main">
      <div className="content-shell legal-content">
        <h1>Zahlung wird bestätigt</h1>
        <CheckoutStatusPanel />
        <p><Link href="/armbaender/">Zurück zu den Armbändern</Link></p>
      </div>
    </main>
  );
}
