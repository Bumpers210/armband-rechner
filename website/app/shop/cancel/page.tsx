import Link from "next/link";

export default function ShopCancelPage() {
  return (
    <main id="main-content" className="legal-main">
      <div className="content-shell legal-content">
        <h1>Checkout nicht abgeschlossen</h1>
        <p>Der Browserabbruch gibt keine Reservierung frei. Stripe bestätigt das Ende der Session; danach wird der Bestand automatisch geprüft.</p>
        <p><Link href="/armbaender/">Zurück zu den Armbändern</Link></p>
      </div>
    </main>
  );
}
