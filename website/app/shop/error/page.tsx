import Link from "next/link";
import { SiteHeader } from "@/components/site-header";
import { SiteFooter } from "@/components/site-footer";
import { CelestialOrnament } from "@/components/celestial-ornament";

export default function ShopErrorPage() {
  return (
    <div className="v2-page">
      <SiteHeader />
    <main id="main-content" className="legal-main">
      <div className="content-shell legal-content">
        <CelestialOrnament />
        <h1>Checkout vorübergehend nicht verfügbar</h1>
        <p>Der Zahlungsstart oder sein Ergebnis konnte nicht sicher bestätigt werden. Bitte prüfen Sie zuerst den Zahlungsstatus und bestellen Sie nicht ungeprüft erneut.</p>
        <p><Link href="/armbaender/">Zurück zu den Armbändern</Link></p>
      </div>
    </main>
      <SiteFooter />
    </div>
  );
}
