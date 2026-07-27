import type { PublicProduct } from "@/content/products";
import { siteContent } from "@/content/site-content";

type ProductVintedLinkProps = {
  product: PublicProduct;
};

export function ProductVintedLink({ product }: ProductVintedLinkProps) {
  const params = new URLSearchParams({
    target: "vinted",
    position: "product",
    product: product.slug,
  });

  return (
    <a
      className="vinted-link"
      href={`${siteContent.tracking.endpoint}?${params.toString()}`}
      target="_blank"
      rel="noopener noreferrer"
      aria-label={`${product.name} auf Vinted ansehen - öffnet in einem neuen Tab`}
    >
      Auf Vinted ansehen
    </a>
  );
}
