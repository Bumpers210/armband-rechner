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
    {
      url: new URL("/steinwissen/", siteContent.metadata.siteUrl).toString(),
    },
    {
      url: new URL(
        "/steinwissen/spirituelle-bedeutung/",
        siteContent.metadata.siteUrl,
      ).toString(),
    },
    {
      url: new URL(
        "/steinwissen/natursteinkunde/",
        siteContent.metadata.siteUrl,
      ).toString(),
    },
    {
      url: new URL("/ueber-mich/", siteContent.metadata.siteUrl).toString(),
    },
    {
      url: new URL("/material-pflege/", siteContent.metadata.siteUrl).toString(),
    },
    {
      url: new URL("/kontakt/", siteContent.metadata.siteUrl).toString(),
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
