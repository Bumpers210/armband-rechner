import Link from "next/link";

import { SiteFooter } from "@/components/site-footer";
import { SiteHeader } from "@/components/site-header";
import { getActiveLegalBundle } from "@/content/legal-bundles";
import type { LegalBundle, LegalSection } from "@/lib/legal-bundles.mjs";

type LegalSectionKey = keyof LegalBundle["texts"];

type LegalBundlePageProps = {
  sectionKey: LegalSectionKey;
  title: string;
  children?: React.ReactNode;
};

function bundleNotice(bundle: LegalBundle) {
  if (bundle.status === "test_only") {
    return {
      className: "legal-bundle-notice legal-bundle-notice-test",
      title: "TESTFASSUNG – NICHT RECHTLICH FREIGEGEBEN",
      text: "Diese technische Testfassung enthält künstliche Platzhalter. Sie ist nicht rechtlich geprüft und nicht für einen produktiven Verkauf bestimmt.",
    };
  }

  if (bundle.status === "awaiting_external_approval") {
    return {
      className: "legal-bundle-notice legal-bundle-notice-pending",
      title: "PRODUKTIONSFASSUNG NICHT FREIGEGEBEN",
      text: "Die externe rechtliche Freigabe steht noch aus. Bis dahin bleibt der produktive Verkauf deaktiviert.",
    };
  }

  return null;
}

function Section({ section }: { section: LegalSection }) {
  return (
    <section className="legal-section">
      <h2>{section.heading}</h2>
      {section.paragraphs.map((paragraph) => (
        <p key={paragraph}>{paragraph}</p>
      ))}
      {section.bullets?.length ? (
        <ul>
          {section.bullets.map((bullet) => (
            <li key={bullet}>{bullet}</li>
          ))}
        </ul>
      ) : null}
    </section>
  );
}

export function LegalBundlePage({ sectionKey, title, children }: LegalBundlePageProps) {
  const bundle = getActiveLegalBundle();
  const notice = bundleNotice(bundle);
  const sections = bundle.texts[sectionKey];

  return (
    <div className="v2-page">
      <SiteHeader />
      <main className="legal-main" id="main-content">
        <div className="content-shell legal-content">
          <h1>{title}</h1>
          {notice ? (
            <aside className={notice.className} role="note">
              <strong>{notice.title}</strong>
              <p>{notice.text}</p>
            </aside>
          ) : null}

          {sections.map((section) => (
            <Section key={section.heading} section={section} />
          ))}

          {children}

          <dl className="legal-bundle-metadata">
            <div>
              <dt>Legal-Bundle-ID</dt>
              <dd>{bundle.id}</dd>
            </div>
            <div>
              <dt>Version</dt>
              <dd>{bundle.version}</dd>
            </div>
            <div>
              <dt>Inhalts-Hash</dt>
              <dd>sha256:{bundle.contentHash}</dd>
            </div>
            <div>
              <dt>Archiv</dt>
              <dd>
                <a href={bundle.archiveUrl}>{bundle.archiveUrl}</a>
              </dd>
            </div>
          </dl>

          <p className="legal-back-link">
            <Link href="/">Zurück zur Startseite</Link>
          </p>
        </div>
      </main>
      <SiteFooter />
    </div>
  );
}
