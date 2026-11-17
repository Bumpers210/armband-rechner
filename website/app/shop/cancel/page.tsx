import Link from "next/link";

export const metadata = {
  title: "Checkout nicht abgeschlossen",
  alternates: { canonical: "/shop/cancel/" },
};

export default function ShopCancelPage() {
  return (
    <main id="main-content" className="legal-main">
      <div className="content-shell legal-content">
        <h1>Checkout nicht abgeschlossen</h1>
        <p>Der Browserabbruch beendet keine möglicherweise laufende Zahlung. Stripe bestätigt den endgültigen Status; andere Bestellungen derselben Kollektion bleiben möglich.</p>
        <p><Link href="/armbaender/">Zurück zu den Armbändern</Link></p>
      </div>
    </main>
  );
}
