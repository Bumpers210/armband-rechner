import { ShopCart } from "@/components/shop-cart";
import { SiteHeader } from "@/components/site-header";
import { SiteFooter } from "@/components/site-footer";
import { CelestialOrnament } from "@/components/celestial-ornament";
import { mainProductImage, publicProducts } from "@/content/products";
export default function CartPage() {
  const productPreviews = publicProducts.map((product) => {
    const image = mainProductImage(product);
    return {
      productId: product.productId,
      href: `/armbaender/${product.slug}/`,
      image: image ? { src: image.previews?.[240] ?? image.src, alt: image.alt } : undefined,
    };
  });
  return (
    <div className="v2-page">
      <SiteHeader />
      <main className="cart-main" id="main-content">
        <div className="content-shell cart-shell">
          <CelestialOrnament />
          <h1>Warenkorb</h1>
          <ShopCart productPreviews={productPreviews} />
        </div>
      </main>
      <SiteFooter />
    </div>
  );
}
