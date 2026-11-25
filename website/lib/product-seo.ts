import { siteContent } from "@/content/site-content";
import type { PublicProduct } from "@/content/products";

export function productHref(product: Pick<PublicProduct, "slug">) {
  return `/armbaender/${product.slug}/`;
}

export function productSummary(product: PublicProduct) {
  const paragraphs = product.description
    .split(/\r?\n\s*\r?\n/)
    .map((paragraph) => paragraph.trim().replace(/\s+/g, " "));
  const descriptionTitle = (product.descriptionTitle ?? product.publicTitle).trim().replace(/\s+/g, " ");
  const lead = paragraphs.find((paragraph) => paragraph && paragraph !== descriptionTitle);
  return lead || [product.publicTitle, ...product.materials].join(" · ");
}

export function productStructuredData(product: PublicProduct) {
  const base = siteContent.metadata.siteUrl;
  const url = new URL(productHref(product), base).href;
  return {
    "@context": "https://schema.org",
    "@type": "Product",
    "@id": `${url}#product`,
    name: product.publicTitle,
    description: product.description.trim() || productSummary(product),
    image: product.images.map((image) => new URL(image.src, base).href),
    sku: product.sku,
    url,
    brand: { "@type": "Brand", name: siteContent.brandName },
  };
}

export type ProductStructuredData = ReturnType<typeof productStructuredData>;

export function inquiryPageStructuredData(product: PublicProduct) {
  const base = siteContent.metadata.siteUrl;
  const url = new URL(productHref(product), base).href;
  return {
    "@context": "https://schema.org",
    "@type": "WebPage",
    "@id": `${url}#webpage`,
    name: product.publicTitle,
    description: product.description.trim() || productSummary(product),
    image: product.images.map((image) => new URL(image.src, base).href),
    url,
  };
}

export function productBreadcrumbs(product: PublicProduct) {
  return [
    { name: "Startseite", href: "/" },
    { name: "Armbänder", href: "/armbaender/" },
    { name: product.publicTitle, href: productHref(product) },
  ];
}

export function breadcrumbStructuredData(items: ReturnType<typeof productBreadcrumbs>) {
  return {
    "@context": "https://schema.org",
    "@type": "BreadcrumbList",
    itemListElement: items.map(({ name, href }, index) => ({
      "@type": "ListItem",
      position: index + 1,
      name,
      item: new URL(href, siteContent.metadata.siteUrl).href,
    })),
  };
}
