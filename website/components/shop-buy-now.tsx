"use client";
import Link from "next/link";
import { useEffect, useState } from "react";
import { money, readCart, replaceCart, shopApiOrigin, shopContext } from "@/lib/cart-client";

export function ShopBuyNow({ productId }: { productId: string }) {
  const [product, setProduct] = useState<{priceMinor: number; available: boolean; availableQuantity: number} | null>(null);
  const [busy, setBusy] = useState(false);
  const [quantity, setQuantity] = useState(1);
  const [message, setMessage] = useState("Live-Verfügbarkeit wird geprüft …");
  useEffect(() => {
    let active = true;
    void (async () => {
      try {
        await shopContext();
        const response = await fetch(`${shopApiOrigin}/shop/v3/products/${encodeURIComponent(productId)}`, { credentials: "include", cache: "no-store" });
        const data = await response.json();
        if (!response.ok || typeof data.product?.available !== "boolean") throw new Error();
        if (active) { setProduct(data.product); setMessage(data.product.available ? "" : "Vorübergehend nicht verfügbar"); }
      } catch { if (active) setMessage("Live-Verfügbarkeit ist momentan nicht erreichbar."); }
    })();
    return () => { active = false; };
  }, [productId]);
  async function add(): Promise<void> {
    setBusy(true);
    try {
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
  return <section className="shop-buy-now" aria-live="polite">
    {product ? <p className="shop-price">{money(product.priceMinor)}</p> : null}
    <label>Menge <input type="number" min={1} step={1} max={product?.availableQuantity || 1} value={quantity}
      onChange={(event) => setQuantity(Number(event.target.value))} /></label>
    {message ? <p>{message}</p> : null}
    <button className="shop-buy-button" type="button" disabled={busy || !product?.available || !Number.isInteger(quantity) || quantity < 1 || quantity > product.availableQuantity}
      onClick={() => void add()}>{busy ? "Wird hinzugefügt …" : "In den Warenkorb"}</button>
    <p><Link href="/warenkorb/">Warenkorb ansehen</Link></p>
  </section>;
}
