import type { PublicProduct } from "@/content/products";
import { siteTarget } from "@/config/site-target";
import { siteContent } from "@/content/site-content";

type ProductVintedLinkProps = {
  product: PublicProduct;
};

export function ProductVintedLink({ product }: ProductVintedLinkProps) {
  if (product.status !== "published" || !product.vintedUrl) {
    return null;
  }

  const params = new URLSearchParams({
    target: "vinted",
    position: "product",
    product: product.slug,
  });
  const href = siteTarget.isTest
    ? product.vintedUrl
    : `${siteContent.tracking.endpoint}?${params.toString()}`;

  return (
    <a
      className="vinted-link"
      href={href}
      target="_blank"
      rel="noopener noreferrer"
      aria-label={`${product.title} auf Vinted ansehen - öffnet in einem neuen Tab`}
    >
      Auf Vinted ansehen
    </a>
  );
}
