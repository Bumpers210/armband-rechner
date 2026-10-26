import { ShopCart } from "@/components/shop-cart";
import { SiteHeader } from "@/components/site-header";
import { SiteFooter } from "@/components/site-footer";
import { CelestialOrnament } from "@/components/celestial-ornament";
export default function CartPage() {
  return (
    <div className="v2-page">
      <SiteHeader />
      <main className="cart-main" id="main-content">
        <div className="content-shell cart-shell">
          <CelestialOrnament />
          <h1>Warenkorb</h1>
          <ShopCart />
        </div>
      </main>
      <SiteFooter />
    </div>
  );
}
