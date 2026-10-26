export const shopApiOrigin = process.env.CARMAJA_SHOP_API_ORIGIN ??
  (process.env.CARMAJA_SITE_TARGET === "test" ? "https://test-api.carmaja-perlen.de" : "https://api.carmaja-perlen.de");
export type CartItem = { productId: string; quantity: number; name?: string; priceMinor?: number; previousPriceMinor?: number | null; priceChanged?: boolean; availableQuantity?: number; buyable: boolean };
export type Cart = { cartVersion: number; items: CartItem[]; totalMinor: number | null; shippingMinor: number; canCheckout: boolean; checkoutContextToken?: string; quantityLimitExceeded?: boolean };
export const CART_MAX_QUANTITY = 10;
export const CART_QUANTITY_MESSAGE = "Maximal 10 Armbänder je Bestellung. Bitte die Gesamtmenge reduzieren.";
export const cartQuantity = (items: Array<{quantity: number}>) => items.reduce((sum, item) => sum + item.quantity, 0);
export const money = (amount: number) => new Intl.NumberFormat("de-DE", { style: "currency", currency: "EUR" }).format(amount / 100);
let contextRequest: Promise<{csrfToken: string}> | null = null;
let contextUntil = 0;
export function shopContext(): Promise<{csrfToken: string}> {
  // Share one context refresh across header/cart/product components in this tab.
  // Cart CSRF tokens remain independently valid across tabs until their expiry.
  if (!contextRequest || Date.now() >= contextUntil) {
    contextUntil = Date.now() + 60000;
    contextRequest = loadContext().catch((error: unknown) => { contextRequest = null; throw error; });
  }
  return contextRequest;
}
async function loadContext(): Promise<{csrfToken: string}> {
  const response = await fetch(`${shopApiOrigin}/shop/v3/context`, { credentials: "include", cache: "no-store" });
  const data = await response.json();
  if (!response.ok || typeof data.context?.csrfToken !== "string") throw new Error("Shop ist momentan nicht erreichbar.");
  return data.context;
}
export async function readCart(): Promise<Cart> {
  const response = await fetch(`${shopApiOrigin}/shop/v3/cart`, { credentials: "include", cache: "no-store" });
  const data = await response.json();
  if (!response.ok || !Array.isArray(data.cart?.items)) throw new Error("Warenkorb konnte nicht geladen werden.");
  return data.cart;
}
export async function replaceCart(cart: Cart, items: Array<{productId: string; quantity: number}>): Promise<Cart> {
  if (cartQuantity(items) > CART_MAX_QUANTITY) throw new Error(CART_QUANTITY_MESSAGE);
  const context = await shopContext();
  const response = await fetch(`${shopApiOrigin}/shop/v3/cart`, {
    method: "PUT", credentials: "include", cache: "no-store",
    headers: { "Content-Type": "application/json", "X-CSRF-Token": context.csrfToken, "Idempotency-Key": crypto.randomUUID() },
    body: JSON.stringify({ expectedCartVersion: cart.cartVersion, items }),
  });
  const data = await response.json();
  if (!response.ok) throw new Error(data.error?.code === "cart_quantity_limit" ? CART_QUANTITY_MESSAGE : response.status === 409 ? "Warenkorb wurde geändert. Bitte neu laden." : "Änderung konnte nicht gespeichert werden.");
  window.dispatchEvent(new Event("carmaja-cart-changed"));
  return data.cart;
}
