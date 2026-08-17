"use client";

import { useEffect, useState } from "react";

type LiveProduct = {
  productId: string;
  priceMinor: number;
  currency: string;
  available: boolean;
};

const apiOrigin =
  process.env.CARMAJA_SHOP_API_ORIGIN ??
  (process.env.CARMAJA_SITE_TARGET === "test"
    ? "https://test-api.carmaja-perlen.de"
    : "https://api.carmaja-perlen.de");

function formatPrice(product: LiveProduct): string {
  return new Intl.NumberFormat("de-DE", {
    style: "currency",
    currency: product.currency.toUpperCase(),
  }).format(product.priceMinor / 100);
}

function requestId(): string {
  if (typeof crypto !== "undefined" && "randomUUID" in crypto) {
    return crypto.randomUUID();
  }
  return `${Date.now()}-${Math.random().toString(16).slice(2)}`;
}

export function ShopBuyNow({ productId }: { productId: string }) {
  const [live, setLive] = useState<LiveProduct | null>(null);
  const [state, setState] = useState<"loading" | "ready" | "error" | "starting">("loading");
  const [message, setMessage] = useState("Verfügbarkeit wird geprüft …");

  useEffect(() => {
    let cancelled = false;
    async function load(): Promise<void> {
      try {
        const context = await fetch(`${apiOrigin}/shop/v2/context`, {
          credentials: "include",
          cache: "no-store",
        });
        if (!context.ok) throw new Error("context");
        const contextJson = await context.json();
        const product = await fetch(
          `${apiOrigin}/shop/v2/products/${encodeURIComponent(productId)}`,
          { credentials: "include", cache: "no-store" },
        );
        if (!product.ok) throw new Error("product");
        const productJson = await product.json();
        if (cancelled) return;
        const liveProduct = productJson.product as LiveProduct;
        if (!liveProduct || contextJson.context?.csrfToken === undefined) {
          throw new Error("response");
        }
        setLive(liveProduct);
        setState(liveProduct.available ? "ready" : "error");
        setMessage(liveProduct.available ? "Jetzt sicher kaufen" : "Vorübergehend nicht verfügbar");
      } catch {
        if (!cancelled) {
          setState("error");
          setMessage("Live-Verfügbarkeit ist momentan nicht erreichbar.");
        }
      }
    }
    void load();
    return () => {
      cancelled = true;
    };
  }, [productId]);

  async function startCheckout(): Promise<void> {
    setState("starting");
    setMessage("Checkout wird vorbereitet …");
    try {
      const contextResponse = await fetch(`${apiOrigin}/shop/v2/context`, {
        credentials: "include",
        cache: "no-store",
      });
      const contextJson = await contextResponse.json();
      if (!contextResponse.ok) throw new Error("context");
      const response = await fetch(`${apiOrigin}/shop/v2/checkouts`, {
        method: "POST",
        credentials: "include",
        cache: "no-store",
        headers: {
          "Content-Type": "application/json",
          "X-CSRF-Token": contextJson.context.csrfToken,
          "X-Live-Context": contextJson.context.liveContextToken,
          "Idempotency-Key": requestId(),
        },
        body: JSON.stringify({ productId }),
      });
      const payload = await response.json();
      if (!response.ok || typeof payload.checkout?.url !== "string") {
        throw new Error(payload.error?.code ?? "checkout");
      }
      if (typeof payload.checkout?.checkoutId === "string") {
        window.sessionStorage.setItem("carmajaCheckoutId", payload.checkout.checkoutId);
      }
      window.location.assign(payload.checkout.url);
    } catch {
      setState("error");
      setMessage("Checkout konnte nicht sicher gestartet werden. Bitte erneut versuchen.");
    }
  }

  return (
    <section className="shop-buy-now" aria-live="polite">
      {live ? <p className="shop-price">{formatPrice(live)}</p> : null}
      <p>{message}</p>
      <button type="button" disabled={state !== "ready"} onClick={() => void startCheckout()}>
        {state === "starting" ? "Wird vorbereitet …" : "Jetzt kaufen"}
      </button>
    </section>
  );
}
