import type { Metadata } from "next";

import { LegalBundlePage } from "@/components/legal-bundle-page";
import { WithdrawalForm } from "@/components/withdrawal-form";
import { siteTarget } from "@/config/site-target";

export const metadata: Metadata = {
  title: {
    absolute: "Widerruf | Carmaja-Perlen",
  },
  robots: {
    index: false,
    follow: !siteTarget.isTest,
    noimageindex: siteTarget.isTest,
  },
};

export default function WithdrawalPage() {
  return (
    <LegalBundlePage sectionKey="withdrawal" title="Widerruf">
      <WithdrawalForm />
    </LegalBundlePage>
  );
}
