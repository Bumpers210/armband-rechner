"use client";

import { FormEvent, useState } from "react";
import { shopContext } from "@/lib/cart-client";

const apiOrigin =
  process.env.CARMAJA_SHOP_API_ORIGIN ??
  (process.env.CARMAJA_SITE_TARGET === "test"
    ? "https://test-api.carmaja-perlen.de"
    : "https://api.carmaja-perlen.de");

type Preview = {
  withdrawalId: string;
  confirmationToken: string;
  matchStatus: "matched" | "manual_review";
  items: Array<{positionNo: number; name: string; quantity: number}>;
};

export function WithdrawalForm() {
  const [preview, setPreview] = useState<Preview | null>(null);
  const [message, setMessage] = useState("");
  const [busy, setBusy] = useState(false);
  const [selected, setSelected] = useState<Record<number, number>>({});
  const [details, setDetails] = useState("");

  async function context(): Promise<{ csrfToken: string }> {
    return shopContext();
  }

  async function identify(event: FormEvent<HTMLFormElement>): Promise<void> {
    event.preventDefault();
    setBusy(true);
    setMessage("");
    try {
      const data = new FormData(event.currentTarget);
      const csrf = await context();
      const response = await fetch(`${apiOrigin}/shop/v3/withdrawals/preview`, {
        method: "POST",
        credentials: "include",
        cache: "no-store",
        headers: { "Content-Type": "application/json", "X-CSRF-Token": csrf.csrfToken },
        body: JSON.stringify({
          orderNumber: data.get("orderNumber"),
          name: data.get("name"),
          email: data.get("email"),
        }),
      });
      const payload = await response.json();
      if (!response.ok) throw new Error(payload.error?.message ?? "preview");
      setPreview(payload.withdrawal as Preview);
      setSelected(Object.fromEntries((payload.withdrawal.items ?? []).map((item: {positionNo: number; quantity: number}) => [item.positionNo, item.quantity])));
      setMessage(
        payload.withdrawal.matchStatus === "matched"
          ? "Bitte prüfen Sie die Angaben und bestätigen Sie den Widerruf ausdrücklich."
          : "Die Angaben konnten nicht eindeutig zugeordnet werden. Der Widerruf wird nach Ihrer Bestätigung manuell geprüft.",
      );
    } catch (error) {
      setMessage(error instanceof Error ? error.message : "Widerruf konnte nicht vorbereitet werden.");
    } finally {
      setBusy(false);
    }
  }

  async function confirm(): Promise<void> {
    if (!preview) return;
    setBusy(true);
    try {
      const csrf = await context();
      const response = await fetch(`${apiOrigin}/shop/v3/withdrawals/confirm`, {
        method: "POST",
        credentials: "include",
        cache: "no-store",
        headers: { "Content-Type": "application/json", "X-CSRF-Token": csrf.csrfToken },
        body: JSON.stringify({
          withdrawalId: preview.withdrawalId,
          confirmationToken: preview.confirmationToken,
          items: Object.entries(selected).filter(([, quantity]) => quantity > 0).map(([positionNo, quantity]) => ({positionNo: Number(positionNo), quantity})),
          details,
        }),
      });
      const payload = await response.json();
      if (!response.ok) throw new Error(payload.error?.message ?? "confirm");
      setMessage(`Widerruf eingegangen am ${payload.withdrawal.receivedAt}. Eine elektronische Eingangsbestätigung wird versendet.`);
      setPreview(null);
    } catch (error) {
      setMessage(error instanceof Error ? error.message : "Widerruf konnte nicht bestätigt werden.");
    } finally {
      setBusy(false);
    }
  }

  return (
    <section className="withdrawal-form" aria-labelledby="withdrawal-form-title">
      <h2 id="withdrawal-form-title">Vertrag widerrufen</h2>
      {!preview ? (
        <form onSubmit={(event) => void identify(event)}>
          <label>Bestellnummer<input name="orderNumber" required autoComplete="off" /></label>
          <label>Name<input name="name" required autoComplete="name" /></label>
          <label>E-Mail-Adresse<input name="email" type="email" required autoComplete="email" /></label>
          <button type="submit" disabled={busy}>{busy ? "Wird geprüft …" : "Angaben prüfen"}</button>
        </form>
      ) : (
        <div className="withdrawal-confirmation">
          <p>{message}</p>
          {(preview.items ?? []).map((item) => <label key={item.positionNo}>
            {item.name} (bestellt: {item.quantity}; 0 = nicht widerrufen)
            <input type="number" min={0} step={1} value={selected[item.positionNo] ?? 0}
              onChange={(event) => setSelected({...selected, [item.positionNo]: Number(event.target.value)})} />
          </label>)}
          <label>Ergänzende Angaben (optional)<textarea value={details} maxLength={4000} onChange={(event) => setDetails(event.target.value)} /></label>
          <button type="button" disabled={busy} onClick={() => void confirm()}>
            Widerruf bestätigen
          </button>
        </div>
      )}
      {message && !preview ? <p role="status">{message}</p> : null}
    </section>
  );
}
