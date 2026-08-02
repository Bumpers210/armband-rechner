import type { Metadata } from "next";

import { LegalBundlePage } from "@/components/legal-bundle-page";
import { siteTarget } from "@/config/site-target";

export const metadata: Metadata = {
  title: {
    absolute: "Shopbedingungen | Carmaja-Perlen",
  },
  robots: {
    index: false,
    follow: !siteTarget.isTest,
    noimageindex: siteTarget.isTest,
  },
};

export default function ShopTermsPage() {
  return <LegalBundlePage sectionKey="terms" title="Shopbedingungen" />;
}
