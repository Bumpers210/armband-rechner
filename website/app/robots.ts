import type { MetadataRoute } from "next";

import { siteContent } from "@/content/site-content";

export const dynamic = "force-static";

export default function robots(): MetadataRoute.Robots {
  return {
    rules: {
      userAgent: "*",
      allow: "/",
      disallow: ["/statistik/", "/click.php", "/api/", "/_internal/"],
    },
    sitemap: `${siteContent.metadata.siteUrl}sitemap.xml`,
    host: new URL(siteContent.metadata.siteUrl).host,
  };
}
