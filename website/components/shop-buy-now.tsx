"use client";
import Link from "next/link";
import { useEffect, useState } from "react";
import { JsonLd } from "@/components/json-ld";
import { CART_MAX_QUANTITY, money, readCart, replaceCart, shopApiOrigin, shopContext } from "@/lib/cart-client";
import type { ProductStructuredData } from "@/lib/product-seo";
import type { MerchantOfferPolicies } from "@/lib/merchant-policies";

import { offerBoxId, offerSchemaId, offerSeedId, readOfferBootstrap, validatedLiveProduct, withLiveOffer,
  type LiveProduct } from "@/lib/live-product-offer.mjs";

export function ShopBuyNow({ productId, structuredProduct, offerPolicies }: {
  productId: string;
  structuredProduct?: ProductStructuredData;
  offerPolicies?: MerchantOfferPolicies;
}) {
  // PHP supplies matching markup and this request's validated API projection.
  // The purchase controls still wait for the browser's own successful live GET.
  const [serverOffer] = useState(() => structuredProduct ? readOfferBootstrap(productId) : null);
  const [product, setProduct] = useState<LiveProduct | null>(null);
  const [loadFailed, setLoadFailed] = useState(serverOffer?.failed ?? false);
  const [loadAttempt, setLoadAttempt] = useState(0);
  const [busy, setBusy] = useState(false);
  const [quantity, setQuantity] = useState(1);
  const [message, setMessage] = useState("");
  useEffect(() => {
    const controller = new AbortController();
    void (async () => {
      try {
        // The product GET is public within the website origin and needs no cart session.
        const response = await fetch(`${shopApiOrigin}/shop/v3/products/${encodeURIComponent(productId)}`, {
          credentials: "include",
          cache: "no-store",
          signal: controller.signal,
        });
        const data = await response.json();
        const live = validatedLiveProduct(data.product, productId);
        if (!response.ok || !live) throw new Error();
        if (!controller.signal.aborted) { setProduct(live); setLoadFailed(false); }
      } catch {
        if (!controller.signal.aborted) setLoadFailed(true);
      }
    })();
    return () => controller.abort();
  }, [productId, loadAttempt]);
  async function add(): Promise<void> {
    setBusy(true);
    try {
      await shopContext();
      const cart = await readCart();
      const items = cart.items.map(({productId, quantity}) => ({productId, quantity}));
      const existing = items.find((item) => item.productId === productId);
      if (existing) existing.quantity += quantity;
      else items.push({productId, quantity});
      await replaceCart(cart, items);
      setMessage("Im Warenkorb. Die Menge wird erst beim Checkout reserviert.");
    } catch (error) { setMessage(error instanceof Error ? error.message : "Bitte erneut versuchen."); }
    finally { setBusy(false); }
  }
  const displayOffer = product ?? serverOffer?.product ?? null;
  const schema = withLiveOffer(structuredProduct, offerPolicies, displayOffer);

  return <section className="shop-buy-now" aria-live="polite">
    {structuredProduct ? <script id={offerSeedId(productId)} type="application/json"
      dangerouslySetInnerHTML={{__html: JSON.stringify(serverOffer)}} /> : null}
    {schema ? <JsonLd id={offerSchemaId(productId)} data={schema} /> : null}
    <div className="shop-live-offer" id={offerBoxId(productId)}>
      {displayOffer ? <>
        <p className="shop-price">{money(displayOffer.priceMinor)}</p>
        <p className="shop-message">{displayOffer.available ? "Verfügbar" : "Vorübergehend nicht verfügbar"}</p>
      </> : !loadFailed ? <p className="shop-message">Preis und Verfügbarkeit werden geprüft …</p> : null}
      {loadFailed ? <div>
        <p className="shop-message">{displayOffer
          ? "Preis und Verfügbarkeit konnten nicht erneut geprüft werden. Kaufen ist vorübergehend nicht möglich."
          : "Preis und Verfügbarkeit sind momentan nicht erreichbar."}</p>
        <button className="v2-text-link" type="button" onClick={() => {
          setLoadFailed(false);
          setLoadAttempt((attempt) => attempt + 1);
        }}>Erneut prüfen</button>
      </div> : null}
    </div>
    <label>Menge <input type="number" min={1} step={1} max={Math.min(CART_MAX_QUANTITY, product?.availableQuantity || 1)} value={quantity}
      onChange={(event) => setQuantity(Number(event.target.value))} /></label>
    {message ? <p>{message}</p> : null}
    <button className="shop-buy-button" type="button" disabled={busy || loadFailed || !product?.available || !Number.isInteger(quantity) || quantity < 1 || quantity > CART_MAX_QUANTITY || quantity > product.availableQuantity}
      onClick={() => void add()}>{busy ? "Wird hinzugefügt …" : "In den Warenkorb"}</button>
    <p>Maximal {CART_MAX_QUANTITY} Armbänder insgesamt je Bestellung.</p>
    <p><Link href="/warenkorb/">Warenkorb ansehen</Link></p>
  </section>;
}
