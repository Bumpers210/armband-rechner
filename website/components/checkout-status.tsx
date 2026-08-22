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

type StatusView = {
  tone: "checking" | "confirmed" | "processing" | "attention";
  title: string;
  message: string;
  orderNumber?: string;
};

const initialStatus: StatusView = {
  tone: "checking",
  title: "Zahlung wird bestätigt",
  message: "Die Prüfung dauert normalerweise nur einen Moment.",
};

function customerStatus(status: CheckoutStatus): StatusView {
  if (status.payment_status === "succeeded" && status.order_number) {
    return {
      tone: "confirmed",
      title: "Zahlung bestätigt",
      message: "Wir bereiten Ihr Armband jetzt sorgfältig für den Versand vor.",
      orderNumber: status.order_number,
    };
  }
  if (status.payment_status === "processing") {
    return {
      tone: "processing",
      title: "Zahlung wird bearbeitet",
      message: status.payment_method_type === "sepa_debit"
        ? "Ihre SEPA-Lastschrift wird noch bearbeitet. Bestellung und Versand beginnen erst nach der Zahlungsbestätigung."
        : "Ihre Zahlung wird noch bearbeitet. Bestellung und Versand beginnen erst nach der Zahlungsbestätigung.",
    };
  }
  if (status.payment_status === "failed") {
    return {
      tone: "attention",
      title: "Zahlung nicht abgeschlossen",
      message: "Für diesen Zahlungsversuch wurde keine Bestellung angelegt. Sie können zur Armbandübersicht zurückkehren und es erneut versuchen.",
    };
  }
  if (status.state === "manual_review") {
    return {
      tone: "processing",
      title: "Zahlung wird geprüft",
      message: "Die Prüfung benötigt etwas mehr Zeit. Sobald sie abgeschlossen ist, erhalten Sie eine E-Mail.",
    };
  }
  return initialStatus;
}

export function CheckoutStatusPanel() {
  const [statusView, setStatusView] = useState<StatusView>(initialStatus);

  useEffect(() => {
    let stopped = false;
    let attempts = 0;
    const maxAttempts = 12;
    if (window.location.search) {
      window.history.replaceState(null, "", `${window.location.pathname}${window.location.hash}`);
    }
    const checkoutId = window.sessionStorage.getItem("carmajaCheckoutId");
    if (!checkoutId) {
      queueMicrotask(() => {
        if (!stopped) {
          setStatusView({
            tone: "processing",
            title: "Bestätigung per E-Mail",
            message: "Der aktuelle Stand kann in diesem Browser nicht automatisch angezeigt werden. Sobald die Zahlung bestätigt ist, erhalten Sie Ihre Bestellbestätigung per E-Mail.",
          });
        }
      });
      return () => { stopped = true; };
    }
    let timer: ReturnType<typeof setTimeout> | undefined;
    const poll = async (): Promise<void> => {
      attempts += 1;
      try {
        const response = await fetch(
          `${apiOrigin}/shop/v2/checkouts/${encodeURIComponent(checkoutId)}/status`,
          { credentials: "include", cache: "no-store" },
        );
        const body = await response.json().catch(() => null);
        if (!response.ok || !body?.checkout) throw new Error("status");
        const status = body.checkout as CheckoutStatus;
        if (!stopped) setStatusView(customerStatus(status));
        if (!stopped && attempts < maxAttempts && !["succeeded", "failed"].includes(status.payment_status)) {
          timer = setTimeout(() => void poll(), 5000);
        }
      } catch {
        if (!stopped) {
          setStatusView({
            tone: "processing",
            title: "Bestätigung per E-Mail",
            message: "Der aktuelle Stand ist momentan nicht abrufbar. Sobald die Zahlung bestätigt ist, erhalten Sie Ihre Bestellbestätigung per E-Mail.",
          });
        }
        if (!stopped && attempts < maxAttempts) {
          timer = setTimeout(() => void poll(), 5000);
        }
      }
    };
    void poll();
    return () => {
      stopped = true;
      if (timer) clearTimeout(timer);
    };
  }, []);

  const icon = statusView.tone === "confirmed"
    ? "✓"
    : statusView.tone === "attention"
      ? "!"
      : "…";

  return (
    <section
      className={`checkout-status checkout-status--${statusView.tone}`}
      role="status"
      aria-live="polite"
    >
      <span className="checkout-status-icon" aria-hidden="true">{icon}</span>
      <div>
        <h2>{statusView.title}</h2>
        <p>{statusView.message}</p>
        {statusView.orderNumber ? (
          <p className="checkout-order-number">
            <span>Bestellnummer</span>
            <strong>{statusView.orderNumber}</strong>
          </p>
        ) : null}
      </div>
    </section>
  );
}
