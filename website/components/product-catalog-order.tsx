'use client';

import {useRef, useState} from 'react';
import {ProductBrowserError, productRequest} from '@/lib/product-browser-client';

type Item = {productId: string; sku: string; name: string};
type Catalog = {revision: string; products: Item[]};

export function ProductCatalogOrder({csrf, disabled, onDirtyChange, onBusyChange}: {
  csrf: string; disabled: boolean; onDirtyChange: (dirty: boolean) => void; onBusyChange: (busy: boolean) => void;
}) {
  const [catalog, setCatalog] = useState<Catalog | null>(null);
  const [items, setItems] = useState<Item[]>([]);
  const [busy, setBusy] = useState(false);
  const [message, setMessage] = useState('');
  const [conflict, setConflict] = useState(false);
  const [retry, setRetry] = useState(false);
  const pending = useRef<{body: {expectedRevision: string; productIds: string[]}; key: string} | null>(null);
  const running = useRef(false);
  const dirty = catalog !== null && items.some((item, i) => item.productId !== catalog.products[i]?.productId);
  function accept(data: Catalog) { setCatalog(data); setItems(data.products); onDirtyChange(false); setConflict(false); setRetry(false); pending.current = null; }
  async function run(action: () => Promise<void>) {
    if (running.current || disabled) return;
    running.current = true; setBusy(true); onBusyChange(true);
    try { await action(); }
    catch (error) {
      if (error instanceof ProductBrowserError && error.status === 409) setConflict(true);
      setMessage(error instanceof Error ? error.message : 'Speichern nicht bestätigt. Bitte erneut versuchen.');
    } finally { running.current = false; setBusy(false); onBusyChange(false); }
  }
  function move(index: number, offset: number) {
    if (disabled || busy || pending.current || conflict) return;
    const target = index + offset; if (target < 0 || target >= items.length) return;
    const next = [...items]; [next[index], next[target]] = [next[target], next[index]];
    setItems(next); onDirtyChange(next.some((p, i) => p.productId !== catalog?.products[i]?.productId));
    setMessage(`${items[index].name}: Position ${target + 1} von ${items.length}. Noch nicht gespeichert.`);
  }
  async function load() {
    if ((dirty || pending.current) && !window.confirm('Ungespeicherte Reihenfolge verwerfen und aktuellen Website-Stand laden? Artikeldaten bleiben erhalten.')) return;
    await run(async () => { accept(await productRequest<Catalog>('/catalog-order')); setMessage('Aktuelle Website-Reihenfolge geladen.'); });
  }
  async function save() {
    if (!catalog || conflict || (!dirty && !pending.current)) return;
    await run(async () => {
      pending.current ??= {body: {expectedRevision: catalog.revision, productIds: items.map(p => p.productId)}, key: crypto.randomUUID()};
      setRetry(true);
      const result = await productRequest<{saved: boolean; publication: {deploymentStatus: string}}>('/catalog-order', 'PUT', pending.current.body, csrf, pending.current.key);
      if (!result.saved) throw new Error('Speichern wurde nicht bestätigt.');
      accept(await productRequest<Catalog>('/catalog-order'));
      setMessage(result.publication.deploymentStatus === 'pending'
        ? 'Reihenfolge gespeichert. Die Website-Aktualisierung wird noch geprüft; sie ist nicht sofort sichtbar.'
        : 'Reihenfolge gespeichert. Sichtbar nach dem nächsten Website-Testbuild.');
    });
  }
  return <section className="pm-section pm-catalog" aria-labelledby="catalog-order-title">
    <h2 id="catalog-order-title">Reihenfolge auf der Website</h2>
    <p>Veröffentlichte Armbänder nach oben oder unten verschieben und anschließend speichern. Neue Armbänder kommen danach ans Ende. Preise, Verkaufsfreigaben und Bestände bleiben unverändert.</p>
    <button type="button" disabled={disabled || busy} onClick={() => void load()}>{catalog ? 'Reihenfolge neu laden' : 'Reihenfolge bearbeiten'}</button>
    <p role="status" aria-live="polite">{busy ? 'Bitte warten …' : message}</p>
    {catalog ? <>
      {items.length === 0 ? <p>Noch keine Armbänder auf der Website veröffentlicht.</p> : <ol className="pm-catalog-list">{items.map((item, index) => <li key={item.productId}>
        <span><strong>{index + 1}. {item.name}</strong><small>{item.sku}</small></span>
        <div className="pm-inline">
          <button type="button" aria-label={`${item.name} nach oben`} disabled={disabled || busy || retry || conflict || index === 0} onClick={() => move(index, -1)}>↑ Nach oben</button>
          <button type="button" aria-label={`${item.name} nach unten`} disabled={disabled || busy || retry || conflict || index === items.length - 1} onClick={() => move(index, 1)}>↓ Nach unten</button>
        </div>
      </li>)}</ol>}
      {conflict ? <p role="alert">Die Reihenfolge oder ein veröffentlichtes Produkt wurde zwischenzeitlich geändert. Deine Auswahl bleibt sichtbar. Bitte neu laden und erneut einordnen; nichts wurde überschrieben.</p> : null}
      <button type="button" disabled={disabled || busy || conflict || (!dirty && !retry)} onClick={() => void save()}>{retry ? 'Speichern erneut prüfen' : 'Reihenfolge speichern'}</button>
    </> : null}
  </section>;
}
