import type { Metadata } from "next";
import Image from "next/image";
import Link from "next/link";
import { notFound } from "next/navigation";

import { ProductVintedLink } from "@/components/product-vinted-link";
import { SiteFooter } from "@/components/site-footer";
import { SiteHeader } from "@/components/site-header";
import { siteTarget } from "@/config/site-target";
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
    title: product.title,
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
      : product.status === "sold"
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
          title: product.title,
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

  const mainImage = mainProductImage(product);
  const isSold = product.status === "sold";

  return (
    <div className="v2-page">
      <SiteHeader />

      <main id="main-content" className="product-detail-main">
        <article className="content-shell product-detail">
          <div className="product-detail-media">
            {mainImage ? (
              <Image
                src={mainImage.src}
                alt={mainImage.alt}
                width={mainImage.width}
                height={mainImage.height}
                sizes="(max-width: 767px) calc(100vw - 32px), 50vw"
                className="product-detail-image"
                priority
              />
            ) : null}

            {product.images.length > 1 ? (
              <div className="product-detail-thumbs" aria-label="Weitere Fotos">
                {product.images.slice(1).map((image) => (
                  <Image
                    key={image.src}
                    src={image.src}
                    alt={image.alt}
                    width={image.width}
                    height={image.height}
                    sizes="8rem"
                    className="product-detail-thumb"
                  />
                ))}
              </div>
            ) : null}
          </div>

          <div className="product-detail-copy">
            <Link className="product-back-link" href="/armbaender/">
              Zur Übersicht
            </Link>
            <p className="product-sku">{product.sku}</p>
            <h1>{product.title}</h1>
            {isSold ? (
              <p className="product-status product-status--sold">Verkauft</p>
            ) : null}
            <p className="product-lede">{product.description}</p>

            <dl className="product-detail-facts">
              <div>
                <dt>Materialien</dt>
                <dd>{product.materials.join(", ")}</dd>
              </div>
              {product.metalElements.length > 0 ? (
                <div>
                  <dt>Metallelemente</dt>
                  <dd>{product.metalElements.join(", ")}</dd>
                </div>
              ) : null}
              <div>
                <dt>Größe</dt>
                <dd>{product.size}</dd>
              </div>
              <div>
                <dt>Bestand</dt>
                <dd>{product.stock > 0 && !isSold ? "verfügbar" : "nicht verfügbar"}</dd>
              </div>
            </dl>

            {product.careInstructions.length > 0 ? (
              <section className="product-care" aria-labelledby="care-heading">
                <h2 id="care-heading">Pflege</h2>
                <ul>
                  {product.careInstructions.map((instruction) => (
                    <li key={instruction}>{instruction}</li>
                  ))}
                </ul>
              </section>
            ) : null}

            {isSold ? null : <ProductVintedLink product={product} />}
          </div>
        </article>
      </main>

      <SiteFooter />
    </div>
  );
}
