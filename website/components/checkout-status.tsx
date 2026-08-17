"use client";

import { useEffect, useState } from "react";

const apiOrigin =
  process.env.CARMAJA_SHOP_API_ORIGIN ??
  (process.env.CARMAJA_SITE_TARGET === "test"
    ? "https://test-api.carmaja-perlen.de"
    : "https://api.carmaja-perlen.de");

type CheckoutStatus = {
  state: string;
  payment_status: string;
  payment_method_type: string | null;
  order_number: string | null;
};

function customerMessage(status: CheckoutStatus): string {
  if (status.payment_status === "succeeded" && status.order_number) {
    return `Zahlung bestätigt. Ihre Bestellnummer lautet ${status.order_number}.`;
  }
  if (status.payment_status === "processing") {
    return status.payment_method_type === "sepa_debit"
      ? "Ihre SEPA-Lastschrift wird noch von Stripe bearbeitet. Bestellung und Versand beginnen erst nach der Zahlungsbestätigung."
      : "Ihre Zahlung wird noch von Stripe bearbeitet. Bestellung und Versand beginnen erst nach der Zahlungsbestätigung.";
  }
  if (status.payment_status === "failed") {
    return "Die Zahlung ist fehlgeschlagen. Es wurde keine Bestellung angelegt.";
  }
  if (status.state === "manual_review") {
    return "Der Zahlungsstatus wird geprüft. Andere Bestellungen dieser Kollektion bleiben möglich.";
  }
  return "Stripe hat den Checkout zurückgemeldet. Die signierte Zahlungsbestätigung steht noch aus.";
}

export function CheckoutStatusPanel() {
  const [message, setMessage] = useState("Zahlungsstatus wird sicher geprüft …");

  useEffect(() => {
    let stopped = false;
    const checkoutId = window.sessionStorage.getItem("carmajaCheckoutId");
    if (!checkoutId) {
      queueMicrotask(() => {
        if (!stopped) {
          setMessage("Der Zahlungsstatus kann in diesem Browser nicht automatisch zugeordnet werden. Eine Bestellung entsteht erst nach bestätigter Zahlung.");
        }
      });
      return () => { stopped = true; };
    }
    let timer: ReturnType<typeof setTimeout> | undefined;
    const poll = async (): Promise<void> => {
      try {
        const response = await fetch(
          `${apiOrigin}/shop/v2/checkouts/${encodeURIComponent(checkoutId)}/status`,
          { credentials: "include", cache: "no-store" },
        );
        const body = await response.json().catch(() => null);
        if (!response.ok || !body?.checkout) throw new Error("status");
        const status = body.checkout as CheckoutStatus;
        if (!stopped) setMessage(customerMessage(status));
        if (!stopped && !["succeeded", "failed"].includes(status.payment_status)) {
          timer = setTimeout(() => void poll(), 5000);
        }
      } catch {
        if (!stopped) setMessage("Der Zahlungsstatus ist vorübergehend nicht erreichbar. Es wird keine unbestätigte Bestellung versandt.");
      }
    };
    void poll();
    return () => {
      stopped = true;
      if (timer) clearTimeout(timer);
    };
  }, []);

  return <p role="status">{message}</p>;
}
