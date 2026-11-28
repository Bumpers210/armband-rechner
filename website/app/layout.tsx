import type { Metadata } from "next";
import Link from "next/link";

import { PageViewTracker } from "@/components/pageview-tracker";
import { StatisticsConsent } from "@/components/statistics-consent";
import statisticsPolicy from "@/content/daily-visitors-policy.json";
import { getActiveLegalBundle } from "@/content/legal-bundles";
import { assertDailyVisitorsPublication } from "@/lib/daily-visitors-publication.mjs";
import { siteTarget } from "@/config/site-target";
import { siteContent } from "@/content/site-content";

import "./globals.css";
import "./site.css";
import "./statistics-consent.css";

export const metadata: Metadata = {
  metadataBase: new URL(siteContent.metadata.siteUrl),
  title: {
    default: siteContent.metadata.title,
    template: `%s | ${siteContent.brandName}`,
  },
  description: siteContent.metadata.description,
  robots: {
    index: !siteTarget.isTest,
    follow: !siteTarget.isTest,
    noimageindex: siteTarget.isTest,
  },
  openGraph: {
    type: "website",
    locale: "de_DE",
    url: siteContent.metadata.siteUrl,
    siteName: siteContent.brandName,
    title: siteContent.metadata.title,
    description: siteContent.metadata.description,
    images: [
      {
        url: siteContent.hero.image.src,
        width: siteContent.hero.image.width,
        height: siteContent.hero.image.height,
        alt: siteContent.hero.image.alt,
        type: "image/jpeg",
      },
    ],
  },
  twitter: {
    card: "summary_large_image",
    title: siteContent.metadata.title,
    description: siteContent.metadata.description,
    images: [siteContent.hero.image.src],
  },
};

export default function RootLayout({
  children,
}: Readonly<{
  children: React.ReactNode;
}>) {
  assertDailyVisitorsPublication({ enabled: process.env.NEXT_PUBLIC_DAILY_VISITORS_ENABLED === "true",
    target: siteTarget.name, policy: statisticsPolicy, bundle: getActiveLegalBundle() });
  return (
    <html lang="de">
      <body>
        <PageViewTracker />
        <a className="skip-link" href="#main-content">
          Zum Inhalt springen
        </a>
        <StatisticsConsent />
        <Link className="persistent-bracelet-flag" href="/armbaender/">
          Armbänder ansehen
        </Link>
        {children}
      </body>
    </html>
  );
}
