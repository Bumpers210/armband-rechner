import { ShopCart } from "@/components/shop-cart";
import { SiteHeader } from "@/components/site-header";
import { SiteFooter } from "@/components/site-footer";

export const metadata = {
  title: "Warenkorb",
  alternates: { canonical: "/warenkorb/" },
  robots: { index: false, follow: false, noimageindex: true },
};

export default function CartPage() {
  return (
    <div className="v2-page">
      <SiteHeader />
      <main id="main-content">
        <header className="v2-page-intro advent-cart-heading">
          <div className="content-shell"><h1>Warenkorb</h1></div>
        </header>
        <div className="content-shell cart-main"><ShopCart /></div>
      </main>
      <SiteFooter />
    </div>
  );
}
