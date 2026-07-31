import type { MetadataRoute } from "next";

import { visibleProducts } from "@/content/products";
import { siteContent } from "@/content/site-content";

export const dynamic = "force-static";

export default function sitemap(): MetadataRoute.Sitemap {
  return [
    {
      url: siteContent.metadata.siteUrl,
    },
    {
      url: new URL("/armbaender/", siteContent.metadata.siteUrl).toString(),
    },
    ...visibleProducts.map((product) => ({
      url: new URL(
        `/armbaender/${product.slug}/`,
        siteContent.metadata.siteUrl,
      ).toString(),
      lastModified: product.updatedAt,
    })),
  ];
}
