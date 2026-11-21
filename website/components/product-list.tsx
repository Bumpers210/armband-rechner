import Link from "next/link";

import { ProductImageGallery } from "@/components/product-image-gallery";
import type { PublicProduct } from "@/content/products";
import { money } from "@/lib/cart-client";

type ProductListProps = {
  products: PublicProduct[];
  headingLevel?: "h2" | "h3";
  prioritizeFirstImage?: boolean;
};

export function ProductList({
  products,
  headingLevel = "h2",
  prioritizeFirstImage = false,
}: ProductListProps) {
  const Heading = headingLevel;

  return (
    <div className="products-grid">
      {products.map((product, index) => (
        <article className="product-card" key={product.sku}>
          <ProductImageGallery
            images={product.images}
            productName={product.publicTitle}
            variant="card"
            prioritize={prioritizeFirstImage && index === 0}
          />
          <div className="product-card-copy">
            <Heading>
              <Link href={`/armbaender/${product.slug}/`}>
                {product.publicTitle}
              </Link>
            </Heading>
            <p className="product-card-price">
              {product.custom ? "Preis nach Absprache" : money(product.priceMinor)}
            </p>
            {!product.custom && !product.salesEnabled ? (
              <p className="product-status product-status--sold">
                Nicht verfügbar
              </p>
            ) : null}
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
                <dt>Armbandumfang</dt>
                <dd>{product.displaySize}</dd>
              </div>
              <div>
                <dt>Perlengröße</dt>
                <dd>{product.displayPearlSize}</dd>
              </div>
            </dl>
          </div>
        </article>
      ))}
    </div>
  );
}
