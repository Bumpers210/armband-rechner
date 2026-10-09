"use client";
import { useEffect, useState } from "react";
import { shopApiOrigin } from "@/lib/cart-client";

export function CartPaymentStatus() {
  const [message, setMessage] = useState("Zahlungsstatus wird geprüft. Eine Rückkehrseite bestätigt noch keine Bestellung.");
  useEffect(() => {
    const id = sessionStorage.getItem("carmajaCheckoutId");
    if (!id || sessionStorage.getItem("carmajaCheckoutApiVersion") !== "v3") return;
    let active = true;
    const poll = async () => {
      try {
        const response = await fetch(`${shopApiOrigin}/shop/v3/checkouts/${encodeURIComponent(id)}/status`, {credentials: "include", cache: "no-store"});
        const data = await response.json();
        if (!response.ok) throw new Error();
        if (!active) return;
        const status = data.checkout?.payment_status;
        setMessage(status === "processing" ? "Ihre SEPA-Zahlung wird bearbeitet. Erst nach Zahlungserfolg bestätigen und versenden wir Ihre Bestellung."
          : status === "succeeded" ? `Zahlung bestätigt. Bestellnummer: ${data.checkout.order_number ?? "wird geprüft"}.`
          : status === "failed" ? "Zahlung fehlgeschlagen. Es wurde keine Bestellung bestätigt."
          : status === "manual_review" ? "Ihre Zahlung wird geprüft. Bitte nicht erneut bestellen."
          : "Zahlung noch nicht bestätigt. Bitte warten Sie auf die Bestellbestätigung.");
      } catch { if (active) setMessage("Zahlungsstatus momentan nicht abrufbar. Bitte nicht erneut bestellen; die Bearbeitung läuft unabhängig weiter."); }
    };
    const start = window.setTimeout(() => void poll(), 0);
    const timer = window.setInterval(() => void poll(), 10000);
    return () => { active = false; window.clearTimeout(start); window.clearInterval(timer); };
  }, []);
  return <p role="status">{message}</p>;
}
