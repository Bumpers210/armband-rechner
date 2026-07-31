import type { PublicProduct } from "@/content/products";
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
  const href = `${siteContent.tracking.endpoint}?${params.toString()}`;

  return (
    <a
      className="vinted-link"
      href={href}
      target="_blank"
      rel="noopener noreferrer"
      aria-label={`${product.publicTitle} auf Vinted ansehen - öffnet in einem neuen Tab`}
    >
      Auf Vinted ansehen
    </a>
  );
}
