import type { Metadata } from "next";
import Link from "next/link";

import { siteContent } from "@/content/site-content";

import "./globals.css";
import "./site.css";

export const metadata: Metadata = {
  metadataBase: new URL(siteContent.metadata.siteUrl),
  title: {
    default: siteContent.metadata.title,
    template: `%s | ${siteContent.brandName}`,
  },
  description: siteContent.metadata.description,
  alternates: {
    canonical: "/",
  },
  robots: {
    index: true,
    follow: true,
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
  return (
    <html lang="de">
      <body>
        <a className="skip-link" href="#main-content">
          Zum Inhalt springen
        </a>
        <Link className="persistent-bracelet-flag" href="/armbaender/">
          Armbänder ansehen
        </Link>
        {children}
      </body>
    </html>
  );
}
