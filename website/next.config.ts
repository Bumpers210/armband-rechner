import type { NextConfig } from "next";
import statisticsPolicy from "./content/daily-visitors-policy.json";

const siteTarget = process.env.CARMAJA_SITE_TARGET ?? "production";
const expectedSiteUrl =
  siteTarget === "test"
    ? "https://test.carmaja-perlen.de"
    : "https://www.carmaja-perlen.de";
const configuredSiteUrl = process.env.CARMAJA_SITE_URL ?? expectedSiteUrl;
// A preview flag must never turn an unreviewed privacy draft into a live policy.
if (siteTarget === "production" && process.env.NEXT_PUBLIC_DAILY_VISITORS_ENABLED === "true"
    && statisticsPolicy.status !== "approved") {
  throw new Error("Tagesbesucher: technische Betriebsfreigabe und neue aktive Datenschutzfassung fehlen.");
}
const configuredShopApiOrigin =
  process.env.CARMAJA_SHOP_API_ORIGIN ??
  (siteTarget === "test"
    ? "https://test-api.carmaja-perlen.de"
    : "https://api.carmaja-perlen.de");

if (siteTarget !== "production" && siteTarget !== "test") {
  throw new Error("CARMAJA_SITE_TARGET muss production oder test sein.");
}

if (configuredSiteUrl !== expectedSiteUrl) {
  throw new Error(
    `CARMAJA_SITE_URL muss für ${siteTarget} exakt ${expectedSiteUrl} sein.`,
  );
}

if (
  siteTarget === "test" &&
  (process.env.CARMAJA_PRODUCTION_PUBLISH_ENABLED === "true" ||
    process.env.CARMAJA_PRODUCTION_DEPLOY_ENABLED === "true")
) {
  throw new Error(
    "Produktions-Publish und Produktions-Deploy müssen im Test-Build deaktiviert sein.",
  );
}

const nextConfig: NextConfig = {
  output: "export",
  trailingSlash: true,
  distDir: siteTarget === "test" ? "out-test" : ".next",
  images: {
    unoptimized: true,
  },
  reactStrictMode: true,
  env: {
    CARMAJA_SITE_TARGET: siteTarget,
    CARMAJA_SITE_URL: configuredSiteUrl,
    CARMAJA_SHOP_API_ORIGIN: configuredShopApiOrigin,
  },
};

export default nextConfig;
