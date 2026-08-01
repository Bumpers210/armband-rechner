import type { Metadata } from "next";
import Image from "next/image";
import Link from "next/link";

import { SiteFooter } from "@/components/site-footer";
import { SiteHeader } from "@/components/site-header";
import { siteContent } from "@/content/site-content";

export const metadata: Metadata = {
  title: "Über mich",
  description:
    "Die persönliche Geschichte hinter den handgefertigten Edelsteinarmbändern von Carmaja-Perlen.",
  alternates: {
    canonical: "/ueber-mich/",
  },
};

export default function AboutPage() {
  return (
    <div className="v2-page">
      <SiteHeader />
      <main id="main-content">
        <section className="v2-page-intro">
          <div className="content-shell">
            <p className="v2-eyebrow">{siteContent.maker.eyebrow}</p>
            <h1>{siteContent.maker.title}</h1>
          </div>
        </section>
        <section className="v2-maker v2-maker--page">
          <div className="content-shell v2-maker-grid">
            <div className="v2-maker-media">
              <Image
                src={siteContent.maker.image.src}
                alt={siteContent.maker.image.alt}
                width={siteContent.maker.image.width}
                height={siteContent.maker.image.height}
                sizes="(max-width: 767px) calc(100vw - 32px), 42vw"
                className="v2-maker-image"
                style={{ objectPosition: siteContent.maker.image.objectPosition }}
              />
            </div>
            <div className="v2-maker-copy">
              <p>{siteContent.maker.text}</p>
              <p>{siteContent.introduction}</p>
              <Link className="v2-text-link" href="/armbaender/">
                Armbänder ansehen
              </Link>
            </div>
          </div>
        </section>
      </main>
      <SiteFooter />
    </div>
  );
}
