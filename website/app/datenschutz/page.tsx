import type { Metadata } from "next";

import { LegalBundlePage } from "@/components/legal-bundle-page";
import { siteTarget } from "@/config/site-target";

export const metadata: Metadata = {
  title: {
    absolute: "Datenschutz | Carmaja-Perlen",
  },
  alternates: {
    canonical: "/datenschutz/",
  },
  robots: {
    index: false,
    follow: !siteTarget.isTest,
    noimageindex: siteTarget.isTest,
  },
};

export default function PrivacyPage() {
  return (
    <LegalBundlePage sectionKey="privacy" title="Datenschutz">
      <p className="legal-contact-link">
        Datenschutzanfragen:{" "}
        <a href="mailto:kontakt@carmaja-perlen.de">
          kontakt@carmaja-perlen.de
        </a>
      </p>
    </LegalBundlePage>
  );
}
