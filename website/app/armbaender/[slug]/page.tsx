import type { Metadata } from "next";
import Link from "next/link";
import { notFound } from "next/navigation";

import { JsonLd } from "@/components/json-ld";
import { merchantOfferPolicies, merchantOrganizationStructuredData } from "@/lib/merchant-policies";
import { ProductImageGallery } from "@/components/product-image-gallery";
import { ProductShippingSummary } from "@/components/product-shipping-summary";
import { ShopBuyNow } from "@/components/shop-buy-now";
import { SiteFooter } from "@/components/site-footer";
import { SiteHeader } from "@/components/site-header";
import { siteTarget } from "@/config/site-target";
import {
  detailProducts,
  findDetailProduct,
  mainProductImage,
  visibleProducts,
  type PublicProduct,
} from "@/content/products";
import {
  breadcrumbStructuredData,
  productBreadcrumbs,
  productHref,
  productStructuredData,
  productSummary,
} from "@/lib/product-seo";

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
  const descriptionTitle = product.descriptionTitle ?? product.publicTitle;

  if (document) {
    const blocks = document.blocks.length > 1 &&
      document.blocks[0].spans.map((span) => span.text).join("").trim() ===
        descriptionTitle.trim()
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
    descriptionTitle,
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
  const description = productSummary(product);

  return {
    title: product.publicTitle,
    description,
    alternates: {
      canonical: productHref(product),
    },
    robots: siteTarget.isTest
      ? {
          index: false,
          follow: false,
          noimageindex: true,
        }
      : {
          index: true,
          follow: true,
        },
    openGraph: image
      ? {
          title: product.publicTitle,
          description,
          url: productHref(product),
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

  const breadcrumbs = productBreadcrumbs(product);
  const structuredProduct = siteTarget.isTest ? undefined : productStructuredData(product);
  const organization = siteTarget.isTest ? null : merchantOrganizationStructuredData();
  const similarProducts = product.custom ? [] : visibleProducts
    .filter((candidate) => candidate.sku !== product.sku && !candidate.custom)
    .map((candidate) => ({
      candidate,
      score: product.materials.filter((material) => candidate.materials.includes(material)).length * 2
        + product.metalElements.filter((metal) => candidate.metalElements.includes(metal)).length,
    }))
    .filter(({ score }) => score > 0)
    .sort((left, right) => right.score - left.score)
    .slice(0, 3);

  return (
    <div className="v2-page">
      <SiteHeader />

      <main id="main-content" className="product-detail-main">
        {structuredProduct ? <JsonLd data={breadcrumbStructuredData(breadcrumbs)} /> : null}
        {organization ? <JsonLd data={organization} /> : null}
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
            <nav className="product-breadcrumbs" aria-label="Brotkrumennavigation">
              <ol>
                {breadcrumbs.map(({ name, href }, index) => (
                  <li key={href}>
                    {index === breadcrumbs.length - 1
                      ? <span aria-current="page">{name}</span>
                      : <Link className="product-back-link" href={href}>{name}</Link>}
                  </li>
                ))}
              </ol>
            </nav>
            <h1>{product.publicTitle}</h1>
            {!product.custom && !product.salesEnabled ? (
              <p className="product-status product-status--sold">Nicht verfügbar</p>
            ) : null}
            <ProductDescription product={product} />
            {product.custom ? (
              <div className="product-inquiry">
                <p className="product-inquiry-price">Preis nach Absprache</p>
                <Link className="v2-text-link" href="/kontakt/">Wunscharmband anfragen</Link>
              </div>
            ) : null}
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
                <dt>Armbandumfang</dt>
                <dd>{product.displaySize}</dd>
              </div>
              <div>
                <dt>Perlengröße</dt>
                <dd>{product.displayPearlSize}</dd>
              </div>
              <div>
                <dt>Artikelnummer</dt>
                <dd>{product.sku}</dd>
              </div>
            </dl>

            {product.notices.length > 0 ? (
              <section className="product-notices" aria-label="Produkthinweise">
                <h2>Hinweise zu Natursteinen</h2>
                {product.notices.map((notice) => <p key={notice}>{notice}</p>)}
              </section>
            ) : null}

            <ul className="product-guide-links">
              <li><Link className="v2-text-link" href="/armbandgroesse/">Armbandgröße ermitteln</Link></li>
              <li><Link className="v2-text-link" href="/material-pflege/">Hinweise zu Material &amp; Pflege</Link></li>
            </ul>

            {!product.custom && product.salesEnabled ? (
              <ShopBuyNow key={product.productId} productId={product.productId} structuredProduct={structuredProduct}
                offerPolicies={structuredProduct ? merchantOfferPolicies() : undefined}>
                <ProductShippingSummary />
              </ShopBuyNow>
            ) : structuredProduct ? <JsonLd data={structuredProduct} /> : null}
          </div>
        </article>
        {similarProducts.length > 0 ? (
          <section className="content-shell product-related" aria-labelledby="related-title">
            <h2 id="related-title">Ähnliche Armbänder</h2>
            <ul className="product-guide-links">
              {similarProducts.map(({ candidate }) => (
                <li key={candidate.sku}>
                  <Link className="v2-text-link" href={productHref(candidate)}>{candidate.publicTitle}</Link>
                </li>
              ))}
            </ul>
          </section>
        ) : null}
      </main>

      <SiteFooter />
    </div>
  );
}
