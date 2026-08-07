import type { Metadata } from "next";
import { notFound } from "next/navigation";

import { LegalBundleArchive } from "@/components/legal-bundle-page";
import { siteTarget } from "@/config/site-target";
import { legalBundlesByTarget } from "@/content/legal-bundles";

type LegalArchivePageProps = {
  params: Promise<{
    environment: string;
    bundleId: string;
  }>;
};

export const dynamicParams = false;
export const dynamic = "force-static";

export const metadata: Metadata = {
  title: {
    absolute: "Archivierte Rechtstextfassung | Carmaja-Perlen",
  },
  robots: {
    index: false,
    follow: false,
    noimageindex: true,
  },
};

export function generateStaticParams(): Array<{
  environment: string;
  bundleId: string;
}> {
  return legalBundlesByTarget[siteTarget.name].map((bundle) => ({
    environment: bundle.environment,
    bundleId: bundle.id,
  }));
}

export default async function LegalArchivePage({
  params,
}: LegalArchivePageProps) {
  const { environment, bundleId } = await params;
  const bundle = legalBundlesByTarget[siteTarget.name].find(
    (candidate) =>
      candidate.environment === environment && candidate.id === bundleId,
  );

  if (!bundle) {
    notFound();
  }

  return <LegalBundleArchive bundle={bundle} />;
}
