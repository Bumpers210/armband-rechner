import Link from "next/link";

import deployment from "@/config/production-shop-deployment.json";
import { money } from "@/lib/cart-client";

// Server component: pass only this public summary into the buying section,
// never the deployment configuration into the client bundle.
export function ProductShippingSummary() {
  const { shippingAmountMinor, shippingMinBusinessDays, shippingMaxBusinessDays } = deployment.shop;
  return (
    <p className="product-shipping-summary">
      Versand innerhalb Deutschlands: einmalig {money(shippingAmountMinor)} je Bestellung.
      {" "}Lieferzeit: voraussichtlich {shippingMinBusinessDays}–{shippingMaxBusinessDays} Werktage
      nach endgültiger Zahlungsbestätigung.
      {" "}<Link href="/versand-und-zahlung/">Versand &amp; Zahlung</Link>
    </p>
  );
}
