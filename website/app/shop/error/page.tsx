import Link from "next/link";

export default function ShopErrorPage() {
  return (
    <main id="main-content" className="legal-main">
      <div className="content-shell legal-content">
        <h1>Checkout vorübergehend nicht verfügbar</h1>
        <p>Der Zahlungsstart oder sein Ergebnis konnte nicht sicher bestätigt werden. Bitte prüfen Sie zuerst den Zahlungsstatus und bestellen Sie nicht ungeprüft erneut.</p>
        <p><Link href="/armbaender/">Zurück zu den Armbändern</Link></p>
      </div>
    </main>
  );
}
