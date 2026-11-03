import Link from "next/link";
import { CelestialOrnament } from "@/components/celestial-ornament";
import { SiteHeader } from "@/components/site-header";
import { SiteFooter } from "@/components/site-footer";

export default function NotFound() {
  return (
    <div className="v2-page">
      <SiteHeader />
      <main id="main-content" className="legal-main">
        <div className="content-shell legal-content">
          <CelestialOrnament />
          <h1>Diese Seite gibt es nicht.</h1>
          <p>Vielleicht findest du dein Lieblingsstück bei unseren Armbändern.</p>
          <Link className="v2-button v2-button--warm" href="/armbaender/">Armbänder entdecken</Link>
        </div>
      </main>
      <SiteFooter />
    </div>
  );
}
