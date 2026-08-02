import Link from "next/link";

export default function ShopSuccessPage() {
  return (
    <main id="main-content" className="legal-main">
      <div className="content-shell legal-content">
        <h1>Zahlung wird bestätigt</h1>
        <p>Stripe hat den Checkout zurückgemeldet. Die verbindliche Bestätigung erfolgt nach dem signierten Webhook.</p>
        <p><Link href="/armbaender/">Zurück zu den Armbändern</Link></p>
      </div>
    </main>
  );
}
