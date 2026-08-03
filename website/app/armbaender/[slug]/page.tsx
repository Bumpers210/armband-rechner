import type { Metadata } from "next";
import Link from "next/link";
import { notFound } from "next/navigation";

import { ProductImageGallery } from "@/components/product-image-gallery";
import { SiteFooter } from "@/components/site-footer";
import { SiteHeader } from "@/components/site-header";
import { siteContent } from "@/content/site-content";
import {
  detailProducts,
  findDetailProduct,
  mainProductImage,
} from "@/content/products";

type ProductDetailPageProps = {
  params: Promise<{
    slug: string;
  }>;
};

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
    robots: product.status === "sold"
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

  const isSold = product.status === "sold";

  return (
    <div className="v2-page">
      <SiteHeader />

      <main id="main-content" className="product-detail-main">
        <article className="content-shell product-detail">
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
            {isSold ? (
              <p className="product-status product-status--sold">Verkauft</p>
            ) : null}
            <p className="product-lede">{product.description}</p>

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
                <dt>Umfang:</dt>
                <dd>{product.displaySize}</dd>
              </div>
              {product.displayPearlSizeMm ? (
                <div>
                  <dt>Perlendurchmesser:</dt>
                  <dd>{product.displayPearlSizeMm}</dd>
                </div>
              ) : null}
              <div>
                <dt>Bestand</dt>
                <dd>{product.stock > 0 && !isSold ? "verfügbar" : "nicht verfügbar"}</dd>
              </div>
            </dl>

            <section className="product-care" aria-labelledby="care-heading">
              <h2 id="care-heading">{siteContent.care.title}</h2>
              <ul>
                {siteContent.care.items.map((instruction) => (
                  <li key={instruction}>{instruction}</li>
                ))}
              </ul>
            </section>

          </div>
        </article>
      </main>

      <SiteFooter />
    </div>
  );
}
