"use client";
import Link from "next/link";
import Image from "next/image";
import { useCallback, useEffect, useRef, useState } from "react";
import { Cart, CART_MAX_QUANTITY, CART_QUANTITY_MESSAGE, cartQuantity, money, readCart, replaceCart, shopApiOrigin, shopContext } from "@/lib/cart-client";

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

export type CartProductPreview = {
  productId: string;
  href: string;
  image?: { src: string; alt: string };
};

export function ShopCart({ productPreviews = [] }: { productPreviews?: CartProductPreview[] } = {}) {
  const [cart, setCart] = useState<Cart | null>(null);
  const [message, setMessage] = useState("Warenkorb wird geladen …");
  const [busy, setBusy] = useState(false);
  const [failedImages, setFailedImages] = useState<Record<string, boolean>>({});
  const checkoutKey = useRef<string | null>(null);
  const refresh = useCallback(async () => {
    try {
      await shopContext();
      const next = await readCart();
      setCart(next); checkoutKey.current = null;
      setMessage(cartQuantity(next.items) > CART_MAX_QUANTITY || next.quantityLimitExceeded ? CART_QUANTITY_MESSAGE : next.items.length ? "Bitte aktuelle Preise und Mengen prüfen. Erst der Checkout reserviert." : "Ihr Warenkorb ist leer.");
    } catch { setCart(null); setMessage("Warenkorb ist momentan nicht erreichbar. Es wurde nichts reserviert."); }
  }, []);
  useEffect(() => {
    const timer = window.setTimeout(() => void refresh(), 0);
    return () => window.clearTimeout(timer);
  }, [refresh]);
  async function change(id: string, quantity: number) {
    if (!cart || !Number.isInteger(quantity) || quantity < 0) return;
    await save(cart.items.filter((item) => item.productId !== id || quantity > 0)
      .map((item) => ({productId: item.productId, quantity: item.productId === id ? quantity : item.quantity})));
  }
  async function save(items: Array<{productId: string; quantity: number}>) {
    if (!cart) return;
    setBusy(true);
    try {
      await replaceCart(cart, items);
      await refresh();
    } catch (error) {
      // Never start a checkout from the stale quote after a failed mutation.
      setCart((previous) => previous ? {...previous, canCheckout: false, checkoutContextToken: undefined} : null);
      setMessage(error instanceof Error ? error.message : "Bitte neu laden.");
    }
    finally { setBusy(false); }
  }
  const quantityLimitExceeded = !!cart && (cartQuantity(cart.items) > CART_MAX_QUANTITY || !!cart.quantityLimitExceeded);
  async function checkout() {
    if (!cart?.canCheckout || !cart.checkoutContextToken || quantityLimitExceeded) return;
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
  return <section className="shop-cart" aria-live="polite">
    <div className="cart-toolbar">
      <p className="cart-status">{message}</p>
      <button type="button" onClick={() => void refresh()} disabled={busy}>Neu laden</button>
    </div>
    <div className="cart-layout">
      <div className="cart-items">
        {cart?.items.map((item) => {
          // The public catalogue supplies presentation only, never prices or availability.
          const preview = productPreviews.find((product) => product.productId === item.productId);
          const image = preview?.image;
          const name = item.name ?? "Nicht mehr verfügbares Produkt";
          const thumbnail = image && !failedImages[item.productId]
            ? <Image src={image.src} alt={image.alt} width={112} height={128} sizes="112px"
                className="cart-item-image" onError={() => setFailedImages((previous) => ({ ...previous, [item.productId]: true }))} />
            : <span className="cart-image-placeholder">Bild nicht verfügbar</span>;
          return <article className="cart-item" key={item.productId}>
            {preview ? <Link className="cart-item-media" href={preview.href} aria-label={`${name} ansehen`}>{thumbnail}</Link>
              : <div className="cart-item-media">{thumbnail}</div>}
            <div className="cart-item-details">
              <h2>{preview ? <Link href={preview.href}>{name}</Link> : name}</h2>
              <p className="cart-unit-price">{item.priceMinor !== undefined ? `${money(item.priceMinor)} je Armband` : "Preis nicht verfügbar"}</p>
              <div className="cart-item-controls">
                <label>Menge <input aria-label={`Menge ${name}`} type="number" min={1} max={Math.max(1, CART_MAX_QUANTITY - cartQuantity(cart.items) + item.quantity)} step={1} value={item.quantity} disabled={busy}
                  onChange={(event) => void change(item.productId, Number(event.target.value))} /></label>
                <button className="cart-remove" type="button" aria-label={`${name} entfernen`} disabled={busy} onClick={() => void change(item.productId, 0)}>Entfernen</button>
              </div>
            </div>
            <div className="cart-line-total"><span>Positionssumme</span>
              <strong>{item.priceMinor !== undefined ? money(item.priceMinor * item.quantity) : "nicht verfügbar"}</strong>
            </div>
            {item.priceChanged && typeof item.previousPriceMinor === "number" ? <p className="cart-item-notice" role="alert">Preis geändert: vorher {money(item.previousPriceMinor)}, jetzt {money(item.priceMinor!)}. Bitte vor der Zahlung prüfen.</p> : null}
            {!item.buyable ? <p className="cart-item-notice" role="alert">Gewünschte Menge momentan nicht verfügbar. Bitte bewusst ändern oder entfernen.</p> : null}
          </article>;
        })}
        <div className="cart-list-actions">
          <Link href="/armbaender/">← Weiter einkaufen</Link>
          {cart && cart.items.length > 0 ? <button type="button" disabled={busy} onClick={() => void save([])}>Warenkorb leeren</button> : null}
        </div>
      </div>
      {cart && cart.items.length > 0 ? <aside className="cart-summary" aria-label="Bestellübersicht">
        <h2>Deine Auswahl</h2>
        <dl>
          <div><dt>Armbänder</dt><dd>{cartQuantity(cart.items)}</dd></div>
          <div><dt>Warenwert</dt><dd>{cart.totalMinor === null ? "nicht verfügbar" : money(cart.totalMinor - cart.shippingMinor)}</dd></div>
          <div><dt>Versand einmalig</dt><dd>{money(cart.shippingMinor)}</dd></div>
          <div className="cart-grand-total"><dt>Gesamt</dt><dd>{cart.totalMinor === null ? "nicht verfügbar" : money(cart.totalMinor)}</dd></div>
        </dl>
        <button className="shop-buy-button" type="button" disabled={busy || !cart.canCheckout || quantityLimitExceeded} onClick={() => void checkout()}>
          {busy ? "Wird verarbeitet …" : "Weiter zur Zahlung"}</button>
        <p className="cart-summary-note">Maximal {CART_MAX_QUANTITY} Armbänder insgesamt je Bestellung. Erst beim Checkout wird deine Auswahl reserviert.</p>
      </aside> : null}
    </div>
  </section>;
}
