import type { Metadata } from "next";
import Link from "next/link";

import { SiteFooter } from "@/components/site-footer";
import { SiteHeader } from "@/components/site-header";

export const metadata: Metadata = {
  title: "Armbandgröße ermitteln",
  description: "So misst du dein Handgelenk, vergleichst den Armbandumfang und findest den Tragesitz, der dir angenehm ist.",
  alternates: { canonical: "/armbandgroesse/" },
};

export default function BraceletSizePage() {
  return (
    <div className="v2-page">
      <SiteHeader />
      <main id="main-content">
        <section className="v2-page-intro">
          <div className="content-shell">
            <h1>Welche Armbandgröße passt zu mir?</h1>
            <p>Miss dein Handgelenk dort, wo du dein Armband tragen möchtest.</p>
          </div>
        </section>
        <div className="content-shell legal-content bracelet-guide">
          <section>
            <h2>Handgelenk messen</h2>
            <p>
              Lege ein flexibles Maßband flach um das Handgelenk. Es soll anliegen,
              ohne einzuschneiden. Mit einem schmalen Papierstreifen geht es ebenfalls.
              Markiere die Stelle, an der sich der Streifen überlappt, und miss die Länge
              bis zur Markierung mit einem Lineal.
            </p>
          </section>
          <section>
            <h2>Handgelenkumfang und Armbandumfang vergleichen</h2>
            <p>
              Dein Messwert ist der Umfang deines Handgelenks. Auf der Produktseite
              steht der Umfang des fertigen Armbands. Perlendicke und Bauweise
              beeinflussen, wie viel Platz beim Tragen zur Verfügung steht. Zwei
              Armbänder mit demselben angegebenen Umfang können sich deshalb
              unterschiedlich anfühlen.
            </p>
          </section>
          <section>
            <h2>Den Tragesitz auswählen</h2>
            <p>
              Ein körpernaher Sitz lässt das Armband wenig rutschen. Für einen
              lockeren Sitz gib ihm etwas mehr Spielraum. In beiden Fällen sollte
              es sich angenehm bewegen lassen, ohne Druckstellen zu hinterlassen.
              Falls du bereits ein gut sitzendes Perlenarmband hast, vergleiche
              möglichst ein Modell mit ähnlicher Perlengröße.
            </p>
          </section>
          <section>
            <h2>Häufige Messfehler vermeiden</h2>
            <p>
              Miss direkt auf der Haut und am Handgelenk, an dem du das Armband
              tragen möchtest. Halte das Maßband gerade und zieh es nicht straff.
              Wiederhole die Messung, wenn die Werte voneinander abweichen.
            </p>
          </section>
          <section>
            <h2>Bei Unsicherheit</h2>
            <p>
              Schreib uns deinen Handgelenkumfang, den Namen des Armbands und
              wie locker du es tragen möchtest. Wir helfen dir bei der Auswahl.
            </p>
            <p><Link className="v2-text-link" href="/kontakt/">Frage zur Größe stellen</Link></p>
            <p><Link className="v2-text-link" href="/armbaender/">Armbänder ansehen</Link></p>
          </section>
        </div>
      </main>
      <SiteFooter />
    </div>
  );
}
