import type { Metadata } from "next";
import Link from "next/link";

import { SiteFooter } from "@/components/site-footer";
import { SiteHeader } from "@/components/site-header";
import { siteTarget } from "@/config/site-target";
import { siteContent } from "@/content/site-content";

export const metadata: Metadata = {
  title: {
    absolute: "Datenschutz | Carmaja-Perlen",
  },
  robots: {
    index: false,
    follow: !siteTarget.isTest,
    noimageindex: siteTarget.isTest,
  },
};

export default function PrivacyPage() {
  const privacy = siteContent.legal.privacy;

  return (
    <div className="v2-page">
      <SiteHeader />
      <main className="legal-main" id="main-content">
        <div className="content-shell legal-content">
          <h1>{privacy.title}</h1>

          {privacy.sections.map((section) => (
            <section className="legal-section" key={section.heading}>
              <h2>{section.heading}</h2>

              {section.blocks.map((block, blockIndex) => {
                const key = `${section.heading}-${blockIndex}`;

                if (block.type === "paragraph") {
                  return <p key={key}>{block.text}</p>;
                }

                if (block.type === "list") {
                  return (
                    <ul key={key}>
                      {block.items.map((item) => (
                        <li key={item}>{item}</li>
                      ))}
                    </ul>
                  );
                }

                if (block.type === "address") {
                  return (
                    <address key={key}>
                      <p>
                        {block.lines.map((line, lineIndex) => (
                          <span key={line}>
                            {line}
                            {lineIndex < block.lines.length - 1 ? <br /> : null}
                          </span>
                        ))}
                      </p>
                    </address>
                  );
                }

                return (
                  <p key={key}>
                    {block.before}
                    <a href={`mailto:${block.address}`}>{block.address}</a>
                    {block.after}
                  </p>
                );
              })}
            </section>
          ))}

          <p className="legal-back-link">
            <Link href="/">Zurück zur Startseite</Link>
          </p>
        </div>
      </main>
      <SiteFooter />
    </div>
  );
}
