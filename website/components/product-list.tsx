import Link from "next/link";

import { ProductImageGallery } from "@/components/product-image-gallery";
import type { PublicProduct } from "@/content/products";

type ProductListProps = {
  products: PublicProduct[];
  headingLevel?: "h2" | "h3";
};

export function ProductList({
  products,
  headingLevel = "h2",
}: ProductListProps) {
  const Heading = headingLevel;

  return (
    <div className="products-grid">
      {products.map((product) => (
        <article className="product-card" key={product.sku}>
          <ProductImageGallery
            images={product.images}
            productName={product.publicTitle}
            variant="card"
          />
          <div className="product-card-copy">
            <Heading>
              <Link href={`/armbaender/${product.slug}/`}>
                {product.publicTitle}
              </Link>
            </Heading>
            <p>{product.description}</p>
            <dl className="product-facts">
              <div>
                <dt>Materialien</dt>
                <dd>{product.materials.join(", ")}</dd>
              </div>
              <div>
                <dt>Metallelemente</dt>
                <dd>
                  {product.metalElements.length > 0
                    ? product.metalElements.join(", ")
                    : "Keine"}
                </dd>
              </div>
              <div>
                <dt>Größe</dt>
                <dd>{product.displaySize}</dd>
              </div>
            </dl>
            <Link className="product-detail-link" href={`/armbaender/${product.slug}/`}>
              Details ansehen
            </Link>
          </div>
        </article>
      ))}
    </div>
  );
}
