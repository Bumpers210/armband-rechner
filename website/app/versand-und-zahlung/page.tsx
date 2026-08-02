import type { Metadata } from "next";

import { LegalBundlePage } from "@/components/legal-bundle-page";
import { siteTarget } from "@/config/site-target";

export const metadata: Metadata = {
  title: {
    absolute: "Versand und Zahlung | Carmaja-Perlen",
  },
  robots: {
    index: false,
    follow: !siteTarget.isTest,
    noimageindex: siteTarget.isTest,
  },
};

export default function ShippingAndPaymentPage() {
  return (
    <LegalBundlePage sectionKey="shipping" title="Versand und Zahlung" />
  );
}
