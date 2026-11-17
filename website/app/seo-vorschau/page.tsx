import type { Metadata } from "next";
import { notFound } from "next/navigation";

import { SiteFooter } from "@/components/site-footer";
import { SiteHeader } from "@/components/site-header";
import { siteTarget } from "@/config/site-target";
import editorial from "@/content/product-editorial.json";

export const metadata: Metadata = {
  title: "SEO-Vorschau",
  alternates: { canonical: "/seo-vorschau/" },
  robots: { index: false, follow: false, noimageindex: true },
};

export default function SeoPreviewPage() {
  if (!siteTarget.isTest) notFound();

  return (
    <div className="v2-page">
      <SiteHeader />
      <main id="main-content" className="legal-main">
        <div className="content-shell legal-content bracelet-guide">
          <header>
            <h1>SEO-Vorschau der Produktseiten</h1>
            <p>Hier kannst du die überarbeiteten Produkttexte und Bildbeschreibungen prüfen. Der Shop-Katalog verwendet weiterhin die Testartikel.</p>
          </header>
          {Object.entries(editorial).map(([sku, product]) => (
            <section key={sku}>
              <h2>{product.title}</h2>
              <p>{sku}</p>
              {product.description.split("\n\n").map((paragraph) => <p key={paragraph}>{paragraph}</p>)}
              <details>
                <summary>Bildbeschreibungen</summary>
                <ol>{product.alts.map((alt) => <li key={alt}>{alt}</li>)}</ol>
              </details>
            </section>
          ))}
        </div>
      </main>
      <SiteFooter />
    </div>
  );
}
