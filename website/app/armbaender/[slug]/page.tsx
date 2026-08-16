import type { Metadata } from "next";
import Link from "next/link";
import { notFound } from "next/navigation";

import { ProductImageGallery } from "@/components/product-image-gallery";
import { ShopBuyNow } from "@/components/shop-buy-now";
import { SiteFooter } from "@/components/site-footer";
import { SiteHeader } from "@/components/site-header";
import { siteTarget } from "@/config/site-target";
import {
  detailProducts,
  findDetailProduct,
  mainProductImage,
  type PublicProduct,
} from "@/content/products";

type ProductDetailPageProps = {
  params: Promise<{
    slug: string;
  }>;
};

function formatDescriptionParagraphs(
  description: string,
  productTitle: string,
): string[] {
  const paragraphs = description
    .replace(/\r\n?/g, "\n")
    .split(/\n\s*\n+/)
    .map((paragraph) => paragraph.trim())
    .filter(Boolean);

  if (paragraphs.length > 1 && paragraphs[0] === productTitle.trim()) {
    return paragraphs.slice(1);
  }

  return paragraphs;
}

function ProductDescription({ product }: { product: PublicProduct }) {
  const document = product.descriptionDocument;

  if (document) {
    const blocks = document.blocks.length > 1 &&
      document.blocks[0].spans.map((span) => span.text).join("").trim() ===
        product.publicTitle.trim()
      ? document.blocks.slice(1)
      : document.blocks;

    return (
      <div className="product-lede product-description-rich">
        {blocks.map((block, blockIndex) => (
          <p key={`block-${blockIndex}`}>
            {block.spans.map((span, spanIndex) => (
              <span
                className={[
                  "product-description-span",
                  span.bold ? "product-description-span--bold" : "",
                  span.italic ? "product-description-span--italic" : "",
                  `product-description-span--font-${span.font}`,
                  `product-description-span--size-${span.size}`,
                ].filter(Boolean).join(" ")}
                key={`span-${blockIndex}-${spanIndex}`}
              >
                {span.text}
              </span>
            ))}
          </p>
        ))}
      </div>
    );
  }

  const paragraphs = formatDescriptionParagraphs(
    product.description,
    product.publicTitle,
  );
  return (
    <div className="product-lede">
      {paragraphs.map((paragraph, index) => (
        <p key={`${index}-${paragraph}`}>{paragraph}</p>
      ))}
    </div>
  );
}

export const dynamicParams = false;
export const dynamic = "force-static";

export async function generateStaticParams(): Promise<Array<{ slug: string }>> {
  if (detailProducts.length === 0) {
    return [{ slug: "__empty" }];
  }

  return detailProducts.map((product) => ({
    slug: product.slug,
  }));
}

export async function generateMetadata({
  params,
}: ProductDetailPageProps): Promise<Metadata> {
  const { slug } = await params;
  const product = findDetailProduct(slug);

  if (!product) {
    return {};
  }

  const image = mainProductImage(product);

  return {
    title: product.publicTitle,
    description: product.description,
    alternates: {
      canonical: `/armbaender/${product.slug}/`,
    },
    robots: siteTarget.isTest
      ? {
          index: false,
          follow: false,
          noimageindex: true,
        }
      : !product.salesEnabled
        ? {
            index: false,
            follow: true,
          }
        : {
            index: true,
            follow: true,
          },
    openGraph: image
      ? {
          title: product.publicTitle,
          description: product.description,
          url: `/armbaender/${product.slug}/`,
          images: [
            {
              url: image.src,
              width: image.width,
              height: image.height,
              alt: image.alt,
              type: "image/jpeg",
            },
          ],
        }
      : undefined,
  };
}

export default async function ProductDetailPage({
  params,
}: ProductDetailPageProps) {
  const { slug } = await params;
  const product = findDetailProduct(slug);

  if (!product) {
    notFound();
  }

  return (
    <div className="v2-page">
      <SiteHeader />

      <main id="main-content" className="product-detail-main">
        <article
          className="content-shell product-detail"
          data-product-id={product.productId}
          data-product-version={product.productVersion}
        >
          <div className="product-detail-media">
            <ProductImageGallery
              images={product.images}
              productName={product.publicTitle}
              variant="detail"
            />
          </div>

          <div className="product-detail-copy">
            <Link className="product-back-link" href="/armbaender/">
              Zur Übersicht
            </Link>
            <h1>{product.publicTitle}</h1>
            {!product.salesEnabled ? (
              <p className="product-status product-status--sold">Nicht verfügbar</p>
            ) : null}
            <ProductDescription product={product} />

            <dl className="product-detail-facts">
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
              <div>
                <dt>Perlengröße</dt>
                <dd>{product.displayPearlSize}</dd>
              </div>
            </dl>

            <Link className="v2-text-link" href="/material-pflege/">
              Hinweise zu Material &amp; Pflege
            </Link>

            {product.salesEnabled ? (
              <ShopBuyNow productId={product.productId} />
            ) : null}

          </div>
        </article>
      </main>

      <SiteFooter />
    </div>
  );
}
