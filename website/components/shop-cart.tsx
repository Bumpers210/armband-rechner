"use client";
import Link from "next/link";
import { useCallback, useEffect, useRef, useState } from "react";
import { Cart, money, readCart, replaceCart, shopApiOrigin, shopContext } from "@/lib/cart-client";

export function ShopCartLink() {
  const [count, setCount] = useState<number | null>(null);
  useEffect(() => {
    let active = true;
    const refresh = async () => {
      try { await shopContext(); const cart = await readCart(); if (active) setCount(cart.items.reduce((sum, item) => sum + item.quantity, 0)); }
      catch { if (active) setCount(null); }
    };
    void refresh();
    window.addEventListener("carmaja-cart-changed", refresh);
    window.addEventListener("focus", refresh);
    return () => { active = false; window.removeEventListener("carmaja-cart-changed", refresh); window.removeEventListener("focus", refresh); };
  }, []);
  return <Link href="/warenkorb/">Warenkorb{count !== null ? ` (${count})` : ""}</Link>;
}

export function ShopCart() {
  const [cart, setCart] = useState<Cart | null>(null);
  const [message, setMessage] = useState("Warenkorb wird geladen …");
  const [busy, setBusy] = useState(false);
  const checkoutKey = useRef<string | null>(null);
  const refresh = useCallback(async () => {
    try {
      await shopContext();
      const next = await readCart();
      setCart(next); checkoutKey.current = null;
      setMessage(next.items.length ? "Bitte aktuelle Preise und Mengen prüfen. Erst der Checkout reserviert." : "Ihr Warenkorb ist leer.");
    } catch { setCart(null); setMessage("Warenkorb ist momentan nicht erreichbar. Es wurde nichts reserviert."); }
  }, []);
  useEffect(() => {
    const timer = window.setTimeout(() => void refresh(), 0);
    return () => window.clearTimeout(timer);
  }, [refresh]);
  async function change(id: string, quantity: number) {
    if (!cart || !Number.isInteger(quantity) || quantity < 0) return;
    setBusy(true);
    try {
      await replaceCart(cart, cart.items.filter((item) => item.productId !== id || quantity > 0)
        .map((item) => ({productId: item.productId, quantity: item.productId === id ? quantity : item.quantity})));
      await refresh();
    } catch (error) { setMessage(error instanceof Error ? error.message : "Bitte neu laden."); }
    finally { setBusy(false); }
  }
  async function checkout() {
    if (!cart?.canCheckout || !cart.checkoutContextToken) return;
    setBusy(true);
    try {
      const context = await shopContext();
      checkoutKey.current ??= crypto.randomUUID();
      const response = await fetch(`${shopApiOrigin}/shop/v3/checkouts`, {method: "POST", credentials: "include", cache: "no-store",
        headers: {"Content-Type": "application/json", "X-CSRF-Token": context.csrfToken, "Idempotency-Key": checkoutKey.current},
        body: JSON.stringify({expectedCartVersion: cart.cartVersion, checkoutContextToken: cart.checkoutContextToken})});
      const data = await response.json();
      if (!response.ok || typeof data.checkout?.url !== "string") throw new Error(data.error?.message ?? "Bitte Zahlungsstatus prüfen.");
      sessionStorage.setItem("carmajaCheckoutId", data.checkout.checkoutId);
      sessionStorage.setItem("carmajaCheckoutApiVersion", "v3");
      window.location.assign(data.checkout.url);
    } catch (error) { setMessage(error instanceof Error ? error.message : "Zahlung wird geprüft. Nicht erneut bestellen."); }
    finally { setBusy(false); }
  }
  return <section className="shop-cart" aria-live="polite"><p role="status">{message}</p>
    <button type="button" onClick={() => void refresh()} disabled={busy}>Neu laden</button>
    {cart?.items.map((item) => <article key={item.productId}>
      <h2>{item.name ?? "Nicht mehr verfügbares Produkt"}</h2>
      <p>{item.priceMinor !== undefined ? money(item.priceMinor) : "Preis nicht verfügbar"}</p>
      {item.priceChanged && typeof item.previousPriceMinor === "number" ? <p role="alert">Preis geändert: vorher {money(item.previousPriceMinor)}, jetzt {money(item.priceMinor!)}. Bitte vor der Zahlung prüfen.</p> : null}
      <label>Menge <input aria-label={`Menge ${item.name ?? item.productId}`} type="number" min={1} step={1} value={item.quantity} disabled={busy}
        onChange={(event) => void change(item.productId, Number(event.target.value))} /></label>
      {!item.buyable ? <p role="alert">Gewünschte Menge momentan nicht verfügbar. Bitte bewusst ändern oder entfernen.</p> : null}
      <button type="button" disabled={busy} onClick={() => void change(item.productId, 0)}>Entfernen</button>
    </article>)}
    {cart && cart.items.length > 0 ? <><p>Versand einmalig: {money(cart.shippingMinor)}</p>
      <p>Gesamt: {cart.totalMinor === null ? "nicht verfügbar" : money(cart.totalMinor)}</p>
      <button className="shop-buy-button" type="button" disabled={busy || !cart.canCheckout} onClick={() => void checkout()}>
        {busy ? "Wird verarbeitet …" : "Weiter zur Zahlung"}</button></> : null}
    <p><Link href="/armbaender/">Weiter einkaufen</Link></p>
  </section>;
}
