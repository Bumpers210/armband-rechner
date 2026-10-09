"use client";
import { FormEvent, useEffect, useState } from "react";
import { money, shopApiOrigin } from "@/lib/cart-client";
type Inventory = {productId: string; name: string; onHand: number; reserved: number; available: number; inventoryVersion: number; quantityConfirmed: boolean};
type Position = {positionNo: number; productId: string; name: string; quantity: number; priceMinor: number};

export function InventoryAdmin({csrf}: {csrf: string}) {
  const [rows, setRows] = useState<Inventory[]>([]);
  const [id, setId] = useState("");
  const [target, setTarget] = useState("");
  const [reason, setReason] = useState("correction");
  const [message, setMessage] = useState("");
  const [busy, setBusy] = useState(false);
  async function load() {
    const response = await fetch(`${shopApiOrigin}/admin/v1/inventory`, {credentials: "include", cache: "no-store"});
    const data = await response.json();
    if (!response.ok) throw new Error("Mengenbestand ist noch nicht freigegeben oder nicht erreichbar.");
    setRows(data.inventory);
  }
  useEffect(() => { const timer = window.setTimeout(() => { void load().catch((error: Error) => setMessage(error.message)); }, 0); return () => window.clearTimeout(timer); }, []);
  const selected = rows.find((row) => row.productId === id);
  async function save(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    const quantity = Number(target);
    if (!selected || target.trim() === "" || !Number.isInteger(quantity) || quantity < 0) return;
    setBusy(true);
    try {
      const key = crypto.randomUUID();
      const response = await fetch(`${shopApiOrigin}/admin/v1/inventory/${encodeURIComponent(id)}`, {method: "POST", credentials: "include", cache: "no-store",
        headers: {"Content-Type": "application/json", "X-CSRF-Token": csrf, "Idempotency-Key": key},
        body: JSON.stringify({targetOnHand: quantity, expectedInventoryVersion: selected.inventoryVersion, reason: selected.quantityConfirmed ? reason : "initial_count", correlationId: key})});
      const data = await response.json();
      if (!response.ok) throw new Error(data.error?.message ?? "Bestandsänderung abgelehnt.");
      await load(); setMessage("Bestand gespeichert und auditiert.");
    } catch (error) {
      setMessage(error instanceof Error ? error.message : "Bitte Bestand erneut laden; nicht ungeprüft wiederholen.");
      await load().catch(() => undefined);
    } finally { setBusy(false); }
  }
  return <section aria-label="Bestand"><h2>Mengenbestand</h2><p role="status">{message}</p>
    <form onSubmit={(event) => void save(event)}>
      <label>Produkt<select value={id} onChange={(event) => setId(event.target.value)}><option value="">Bitte auswählen</option>
        {rows.map((row) => <option key={row.productId} value={row.productId}>{row.name}</option>)}</select></label>
      {selected ? <p>{selected.quantityConfirmed ? `Vorhanden ${selected.onHand}, reserviert ${selected.reserved}, verfügbar ${selected.available}` : "Erstzählung erforderlich; nicht kaufbar."}</p> : null}
      <label>Tatsächlich gezählte Stückzahl<input type="number" min={0} step={1} required value={target} onChange={(event) => setTarget(event.target.value)} /></label>
      <label>Grund<select value={reason} onChange={(event) => setReason(event.target.value)}>
        <option value="correction">Zählkorrektur</option><option value="receive_stock">Neue fertige Armbänder</option><option value="mark_unsellable">Beschädigung / Nichtverfügbarkeit</option>
      </select></label>
      <button disabled={busy || !selected} type="submit">{selected?.quantityConfirmed ? "Bestand bestätigen" : "Erstzählung bestätigen"}</button>
    </form>
  </section>;
}

export function OrderPositionsAdmin({orderId, csrf}: {orderId: string; csrf: string}) {
  const [items, setItems] = useState<Position[]>([]);
  const [message, setMessage] = useState("");
  const [busy, setBusy] = useState(false);
  const [blocked, setBlocked] = useState(false);
  const [totals, setTotals] = useState<{total: number; refunded: number} | null>(null);
  async function load() {
    const response = await fetch(`${shopApiOrigin}/admin/v1/orders/${encodeURIComponent(orderId)}`, {credentials: "include", cache: "no-store"});
    const data = await response.json();
    if (!response.ok) { setMessage("Bestelldetails nicht erreichbar."); return; }
    setItems(data.order.items ?? []);
    if (typeof data.order.totalMinor === "number") setTotals({total: data.order.totalMinor, refunded: data.order.refundedMinor ?? 0});
  }
  async function restock(event: FormEvent<HTMLFormElement>, item: Position) {
    event.preventDefault();
    if (busy || blocked) return;
    const form = new FormData(event.currentTarget);
    if (form.get("checked") !== "on") return;
    setBusy(true);
    let submitted = false;
    try {
      const invResponse = await fetch(`${shopApiOrigin}/admin/v1/inventory/${encodeURIComponent(item.productId)}`, {credentials: "include", cache: "no-store"});
      const inv = await invResponse.json();
      if (!invResponse.ok) throw new Error("Bestand zuerst zählen und bestätigen.");
      const key = crypto.randomUUID();
      submitted = true;
      const response = await fetch(`${shopApiOrigin}/admin/v1/orders/${encodeURIComponent(orderId)}/restock-position`, {method: "POST", credentials: "include", cache: "no-store",
        headers: {"Content-Type": "application/json", "X-CSRF-Token": csrf, "Idempotency-Key": key},
        body: JSON.stringify({positionNo: item.positionNo, quantity: Number(form.get("quantity")), expectedInventoryVersion: inv.inventory.inventoryVersion, correlationId: key, receivedAndChecked: true})});
      const data = await response.json();
      if (!response.ok) throw new Error(data.error?.message ?? "Wiedereinlagerung abgelehnt.");
      setMessage("Geprüfte Rückgabe wiedereingelagert und auditiert.");
      setBlocked(true);
    } catch (error) {
      if (submitted) setBlocked(true);
      setMessage(error instanceof Error ? error.message : "Ausgang unklar. Zuerst Bestandsaudit prüfen; nicht erneut wiedereinlagern.");
    } finally { setBusy(false); }
  }
  return <details onToggle={(event) => { if (event.currentTarget.open) void load(); }}><summary>Positionen und geprüfte Rückgaben</summary>
    {totals ? <p>Bestellsumme: {money(totals.total)} · Erstattet: {money(totals.refunded)}. Erstattungen ausschließlich über Stripe.</p> : null}
    <p role="status">{message}</p>{items.map((item) => <form key={item.positionNo} onSubmit={(event) => void restock(event, item)}>
      <p>{item.quantity} × {item.name} · {money(item.priceMinor)}</p>
      <label>Tatsächlich eingegangene Rückgabemenge<input type="number" min={1} max={item.quantity} step={1} name="quantity" required /></label>
      <label><input type="checkbox" name="checked" required /> Eingegangen, geprüft und wieder verkäuflich</label>
      <button type="submit" disabled={busy || blocked}>Diese Rückgabemenge wiedereinlagern</button>
    </form>)}</details>;
}
