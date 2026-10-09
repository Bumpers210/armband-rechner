import { ShopCart } from "@/components/shop-cart";
import { SiteHeader } from "@/components/site-header";
import { SiteFooter } from "@/components/site-footer";
export default function CartPage() {
  return <div className="v2-page"><SiteHeader /><main className="content-shell" id="main-content"><h1>Warenkorb</h1><ShopCart /></main><SiteFooter /></div>;
}
