import Link from "next/link";

export default function ShopErrorPage() {
  return (
    <main id="main-content" className="legal-main">
      <div className="content-shell legal-content">
        <h1>Checkout vorübergehend nicht verfügbar</h1>
        <p>Der Shop hat die Anfrage aus Sicherheitsgründen nicht angenommen. Bitte laden Sie die Produktseite neu und versuchen Sie es erneut.</p>
        <p><Link href="/armbaender/">Zurück zu den Armbändern</Link></p>
      </div>
    </main>
  );
}
