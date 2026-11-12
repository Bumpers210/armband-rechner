'use client';

import {FormEvent, useEffect, useRef, useState} from 'react';
import {ProductCalculator} from '@/components/product-calculator';
import {DescriptionDocument, editingProduct, ManagedInventory, ManagedProduct, newProduct, newSpan, prepareProductImage,
  priceFromInput, PRODUCT_BROWSER_IS_TEST, ProductBrowserError, ProductImage, productImageBlob, productPayload, productRequest, TextSpan} from '@/lib/product-browser-client';

const statusNames: Record<string, string> = {draft: 'Entwurf', ready: 'Bereit', published: 'Veröffentlicht', disabled: 'Archiviert', sold: 'Verkauft'};
const euro = (price: number) => new Intl.NumberFormat('de-DE', {style: 'currency', currency: 'EUR'}).format(price / 100);

function PrivateImage({productId, image}: {productId: string; image: ProductImage}) {
  const [url, setUrl] = useState('');
  useEffect(() => {
    let active = true; let objectUrl = '';
    void (image.blob ? Promise.resolve(image.blob) : image.uploaded ? productImageBlob(productId, image.imageId) : Promise.reject())
      .then(blob => { if (active) { objectUrl = URL.createObjectURL(blob); setUrl(objectUrl); } }).catch(() => undefined);
    return () => { active = false; if (objectUrl) URL.revokeObjectURL(objectUrl); };
  }, [productId, image.imageId, image.blob, image.uploaded]);
  // Authenticated blob, never a bearer token or private file path in an image URL.
  // eslint-disable-next-line @next/next/no-img-element
  return url ? <img src={url} alt={image.alt} width={150} height={150} /> : <span className="pm-image-placeholder">Bild noch nicht verfügbar</span>;
}

function DescriptionEditor({value, onChange}: {value: DescriptionDocument; onChange: (value: DescriptionDocument) => void}) {
  function span(block: number, index: number, changes: Partial<TextSpan>) {
    onChange({...value, blocks: value.blocks.map((b, bi) => bi !== block ? b : {...b, spans: b.spans.map((s, si) => si !== index ? s : {...s, ...changes})})});
  }
  return <div className="pm-description"><h3>Beschreibung</h3><p className="muted">Vorhandene Absätze und Formatierungen bleiben erhalten.</p>
    {value.blocks.map((block, bi) => <div className="pm-paragraph" key={bi}>
      {block.spans.map((s, si) => <div key={si} className="pm-span">
        <label>Text – Absatz {bi + 1}, Bereich {si + 1}<textarea required value={s.text} maxLength={2000} onChange={e => span(bi, si, {text: e.target.value})} /></label>
        <div className="pm-inline"><label><input type="checkbox" checked={s.bold} onChange={e => span(bi, si, {bold: e.target.checked})} /> Fett</label>
          <label><input type="checkbox" checked={s.italic} onChange={e => span(bi, si, {italic: e.target.checked})} /> Kursiv</label>
          <label>Schrift<select value={s.font} onChange={e => span(bi, si, {font: e.target.value as TextSpan['font']})}><option value="standard">Standard</option><option value="elegant">Elegant</option></select></label>
          <label>Größe<select value={s.size} onChange={e => span(bi, si, {size: e.target.value as TextSpan['size']})}><option value="small">Klein</option><option value="normal">Normal</option><option value="large">Groß</option></select></label>
          {block.spans.length > 1 ? <button type="button" onClick={() => onChange({...value, blocks: value.blocks.map((b, i) => i === bi ? {...b, spans: b.spans.filter((_, j) => si !== j)} : b)})}>Textbereich entfernen</button> : null}
        </div>
      </div>)}
      <div className="pm-inline"><button type="button" onClick={() => onChange({...value, blocks: value.blocks.map((b, i) => i === bi ? {...b, spans: [...b.spans, newSpan()]} : b)})}>Textbereich ergänzen</button>
        {value.blocks.length > 1 ? <button type="button" onClick={() => onChange({...value, blocks: value.blocks.filter((_, i) => i !== bi)})}>Absatz entfernen</button> : null}</div>
    </div>)}
    <button type="button" disabled={value.blocks.length >= 25} onClick={() => onChange({...value, blocks: [...value.blocks, {type: 'paragraph', spans: [newSpan()]}]})}>Absatz ergänzen</button>
  </div>;
}

export function ProductManager() {
  const [csrf, setCsrf] = useState(''); const [products, setProducts] = useState<ManagedProduct[]>([]);
  const [selected, setSelected] = useState<ManagedProduct | null>(null); const [price, setPrice] = useState('28,00');
  const [dirty, setDirty] = useState(false); const [busy, setBusy] = useState(false); const [message, setMessage] = useState('');
  const [search, setSearch] = useState(''); const [conflict, setConflict] = useState(false);
  const [inventory, setInventory] = useState<ManagedInventory | null>(null); const [inventoryMessage, setInventoryMessage] = useState('');
  const [target, setTarget] = useState(''); const [reason, setReason] = useState('correction');
  const pending = useRef(new Map<string, {signature: string; key: string}>()); const busyRef = useRef(false);
  const imageRecovery = useRef<null | {productId: string; images: ProductImage[]; version: number; next: number}>(null);
  const selectedId = useRef('');
  function keyFor(action: string, body: unknown) {
    const signature = JSON.stringify(body); const old = pending.current.get(action);
    if (old?.signature === signature) return old.key;
    const key = crypto.randomUUID(); pending.current.set(action, {signature, key}); return key;
  }
  function report(error: unknown) {
    if (error instanceof ProductBrowserError) {
      if (error.status === 401) setCsrf('');
      if (error.status === 409) setConflict(true);
    }
    setMessage(error instanceof Error ? error.message : 'Vorgang konnte nicht bestätigt werden.');
  }
  async function run(action: () => Promise<void>) {
    if (busyRef.current) return; busyRef.current = true; setBusy(true); setMessage('');
    try { await action(); } catch (error) { report(error); } finally { busyRef.current = false; setBusy(false); }
  }
  async function loadProducts() { const data = await productRequest<{products: ManagedProduct[]}>('/products'); setProducts(data.products); }
  useEffect(() => {
    let active = true;
    void productRequest<{csrfToken: string}>('/session').then(async data => {
      if (!active) return; setCsrf(data.csrfToken);
      const list = await productRequest<{products: ManagedProduct[]}>('/products'); if (active) setProducts(list.products);
    }).catch(() => undefined);
    return () => { active = false; };
  }, []);
  useEffect(() => {
    const leave = (e: BeforeUnloadEvent) => { if (dirty || imageRecovery.current) { e.preventDefault(); e.returnValue = ''; } };
    window.addEventListener('beforeunload', leave); return () => window.removeEventListener('beforeunload', leave);
  }, [dirty]);
  async function loadInventory(id: string) {
    setInventory(null); setInventoryMessage('Bestand wird geladen …');
    try { const data = await productRequest<{inventory: ManagedInventory}>(`/products/${id}/inventory`);
      if (selectedId.current === id) { setInventory(data.inventory); setInventoryMessage(''); }
    } catch { if (selectedId.current === id) setInventoryMessage('Noch kein bestätigter Commerce-Bestand oder Bestand derzeit nicht erreichbar.'); }
  }
  function accept(product: ManagedProduct) {
    selectedId.current = product.productId; setSelected(editingProduct(product)); setPrice((product.priceMinor / 100).toFixed(2).replace('.', ','));
    setDirty(false); setConflict(false); setTarget(''); setInventory(null); setInventoryMessage('');
    if (product.productVersion > 0) void loadInventory(product.productId);
  }
  function mayDiscard() { return !(dirty || imageRecovery.current) || window.confirm('Ungespeicherte Eingaben verwerfen und Serverstand laden?'); }
  function edit(changes: Partial<ManagedProduct>) { setSelected(p => p ? {...p, ...changes} : p); setDirty(true); }
  async function select(id: string) {
    if (!mayDiscard()) return;
    await run(async () => { const data = await productRequest<{product: ManagedProduct}>(`/products/${id}`); imageRecovery.current = null; accept(data.product); });
  }
  async function login(e: FormEvent<HTMLFormElement>) {
    e.preventDefault(); const form = e.currentTarget; const fields = new FormData(form);
    await run(async () => { const data = await productRequest<{csrfToken: string}>('/login', 'POST', {username: fields.get('username'), password: fields.get('password')});
      form.reset(); setCsrf(data.csrfToken); await loadProducts(); setMessage('Angemeldet. App und Browser verwenden dieselben Artikel.'); });
  }
  async function finishImages() {
    const job = imageRecovery.current; if (!job) return;
    for (; job.next < job.images.length; job.next++) {
      const image = job.images[job.next]; const data = new FormData();
      data.set('expectedVersion', String(job.version)); data.set('imageId', image.imageId);
      data.set('desiredImageIds', JSON.stringify(job.images.map(i => i.imageId))); data.set('alt', image.alt);
      data.set('image', image.blob!, image.fileName);
      const saved = await productRequest<{product: ManagedProduct}>(`/products/${job.productId}/images`, 'POST', data, csrf,
        keyFor(`image:${image.imageId}`, {version: job.version, alt: image.alt}));
      job.version = saved.product.version;
    }
    const result = await productRequest<{product: ManagedProduct}>(`/products/${job.productId}`);
    imageRecovery.current = null; accept(result.product);
  }
  async function save(e: FormEvent<HTMLFormElement>) {
    e.preventDefault(); if (!selected || conflict) return;
    await run(async () => {
      if (imageRecovery.current) { await finishImages(); await loadProducts(); setMessage('Artikel und Bilder gespeichert.'); return; }
      const current = {...selected, priceMinor: priceFromInput(price)};
      const imageChanged = current.images.some((im, index) => im.blob || im.fileName !== `0${index + 1}.jpg`);
      // Load EVERY retained image before a manifest mutation, so reorder/removal never loses its bytes.
      const images = imageChanged ? await Promise.all(current.images.map(async (im, index) => ({...im, fileName: `0${index + 1}.jpg`,
        blob: im.blob ?? await productImageBlob(current.productId, im.imageId)}))) : current.images;
      const payload = productPayload({...current, images});
      const data = await productRequest<{product: ManagedProduct}>(`/products/${current.productId}`, 'PUT', payload, csrf, keyFor(`save:${current.productId}`, payload));
      if (imageChanged) {
        imageRecovery.current = {productId: current.productId, images, version: data.product.version, next: 0};
        await finishImages();
      } else accept(data.product);
      pending.current.delete(`save:${current.productId}`); await loadProducts();
      setMessage('Artikel gespeichert. Bestand unverändert. Für die Veröffentlichung „Veröffentlichen“ wählen.');
    });
  }
  async function lifecycle(action: 'publish' | 'archive' | 'restore') {
    if (!selected || dirty || conflict || imageRecovery.current) return;
    if (action === 'archive' && !window.confirm('Artikel archivieren? Er wird nicht mehr zum Verkauf angeboten. Bestand bleibt erhalten.')) return;
    await run(async () => {
      const contract = {expectedProductVersion: selected.productVersion, expectedSourceHash: selected.sourceHash};
      const operationId = keyFor(`${action}:${selected.productId}`, contract);
      const data = await productRequest<{product: ManagedProduct}>(`/products/${selected.productId}/${action}`, 'POST', {...contract, operationId}, csrf, operationId);
      accept(data.product); pending.current.delete(`${action}:${selected.productId}`); await loadProducts();
      setMessage(action === 'publish' ? 'Veröffentlichung bestätigt. Die Website wird erst mit dem freigegebenen Websitebuild aktualisiert.'
        : action === 'archive' ? 'Artikel archiviert. Bestand bleibt erhalten.' : 'Artikel wiederhergestellt. Verkaufsfreigabe separat prüfen.');
    });
  }
  async function stock(initial: boolean) {
    if (!selected || !selected.productVersion || target.trim() === '') return;
    await run(async () => {
      const quantity = Number(target); if (!Number.isSafeInteger(quantity) || quantity < 0) throw new Error('Bitte eine ganze Stückzahl ab 0 eintragen.');
      if (!initial && !inventory) throw new Error('Bestand zuerst neu laden.');
      const route = initial ? 'initial-inventory' : 'inventory';
      const base = initial ? {targetOnHand: quantity} : {targetOnHand: quantity, expectedInventoryVersion: inventory!.inventoryVersion, reason: inventory!.quantityConfirmed ? reason : 'initial_count'};
      const key = keyFor(`${route}:${selected.productId}`, base); const body = initial ? base : {...base, correlationId: key};
      await productRequest(`/products/${selected.productId}/${route}`, 'POST', body, csrf, key);
      pending.current.delete(`${route}:${selected.productId}`); await loadInventory(selected.productId);
      setMessage(initial ? 'Anfangsbestand vorgemerkt. Wird bei der ersten Veröffentlichung genau einmal übernommen.' : 'Bestand gespeichert und protokolliert.');
    });
  }
  async function addImages(files: FileList | null) {
    if (!selected || !files) return;
    await run(async () => { if (selected.images.length + files.length > 5) throw new Error('Höchstens fünf Bilder je Artikel.');
      const additions: ProductImage[] = []; for (const file of Array.from(files)) additions.push(await prepareProductImage(file, selected.name.trim() || 'Armband'));
      edit({images: [...selected.images, ...additions]}); setMessage('Bilder vorbereitet. Bitte Artikel speichern.'); });
  }
  const canPublish = selected && selected.productVersion > 0 && !dirty && !conflict && !imageRecovery.current;
  return <section className="product-manager" aria-label="Artikelverwaltung">
    <header className="pm-heading"><p className="eyebrow">Deine Werkstatt{PRODUCT_BROWSER_IS_TEST ? ' · Testumgebung' : ''}</p><h1>Artikel am PC verwalten</h1>
      <p>Dieselben Artikel wie in der App – mit Platz für Bilder, Texte und alle Details.</p></header>
    <p role="status" aria-live="polite" className="pm-message">{busy ? 'Wird verarbeitet …' : message}</p>
    {!csrf ? <form className="pm-login" onSubmit={e => void login(e)}><h2>Mit App-Zugang anmelden</h2>
      <p>Hier gelten die Zugangsdaten der Produktverwaltungs-App, nicht die des Shop-Admins. Deine bestehenden Zugangsdaten bleiben unverändert.</p>
      <label>Benutzername<input name="username" autoComplete="username" required maxLength={160} /></label>
      <label>Passwort<input name="password" type="password" autoComplete="current-password" required maxLength={1024} /></label>
      <button disabled={busy} type="submit">Anmelden</button></form> : <>
      <div className="pm-toolbar"><button disabled={busy} onClick={() => { if (mayDiscard()) { imageRecovery.current = null; accept(newProduct()); setDirty(true); } }}>Neuer Artikel</button>
        <button disabled={busy} onClick={() => void run(async () => { await loadProducts(); setMessage('Artikelliste neu geladen. Geöffnete Eingaben bleiben erhalten.'); })}>Liste neu laden</button>
        <button disabled={busy} onClick={() => { if (mayDiscard()) void run(async () => { await productRequest('/logout', 'POST', {}, csrf); imageRecovery.current = null;
          setCsrf(''); setSelected(null); setProducts([]); setDirty(false); setMessage('Abgemeldet.'); }); }}>Abmelden</button></div>
      <p className="pm-note">{PRODUCT_BROWSER_IS_TEST ? 'Nur Testdaten. Neue Artikel heißen in diesem geschützten Testfenster „HANDY-TEST …“. ' : ''}Speichern oder Veröffentlichen ändert keinen vorhandenen Bestand.</p>
      <ProductCalculator disabled={busy || !!imageRecovery.current} canApplyToCurrent={!!selected && !conflict} onApply={(fields, create) => {
        if (create) { if (!mayDiscard()) throw new Error('Übernahme abgebrochen. Vorhandene Eingaben bleiben erhalten.');
          imageRecovery.current = null; accept({...newProduct(), ...fields}); setDirty(true);
        } else { if (!selected || conflict) return;
          if (!window.confirm('Perlen, Spacer und Verkaufspreis im geöffneten Artikel durch diese Kalkulation ersetzen? Alle anderen Felder und der Bestand bleiben unverändert.')) throw new Error('Übernahme abgebrochen.');
          edit(fields); setPrice((fields.priceMinor / 100).toFixed(2).replace('.', ','));
        }
        setMessage('Perlen, Spacer und Preis übernommen – noch nicht gespeichert. Bitte Artikeldetails ergänzen und prüfen.');
      }} />
      <div className="pm-layout"><aside className="pm-list"><label>Artikel suchen<input type="search" value={search} onChange={e => setSearch(e.target.value)} /></label>
        <ul>{products.filter(p => p.name.toLocaleLowerCase().includes(search.toLocaleLowerCase())).map(p => <li key={p.productId}><button disabled={busy} aria-pressed={selected?.productId === p.productId} onClick={() => void select(p.productId)}>
          <strong>{p.name}</strong><span>{euro(p.priceMinor)} · {statusNames[p.status] ?? p.status}</span><small>{p.salesEnabled ? 'Verkaufsfreigabe an' : 'Verkaufsfreigabe aus'}</small></button></li>)}</ul>
        {products.length === 0 ? <p>Noch keine Artikel geladen.</p> : null}</aside>
        {selected ? <div className="pm-editor" key={selected.productId}>
          <div className="pm-inline"><h2>{selected.productVersion ? selected.name : 'Neuer Artikel'}</h2><span className="pm-badge">{statusNames[selected.status] ?? selected.status}</span></div>
          <p className="muted">{selected.sku ? `${selected.sku} · ` : ''}Version {selected.productVersion}{dirty ? ' · Ungespeicherte Änderungen' : ''}</p>
          {conflict ? <p role="alert">Änderungskonflikt: Speichern und Veröffentlichen sind gesperrt. Lade den Serverstand neu; ungespeicherte Eingaben erst bei Bestätigung verwerfen.</p> : null}
          {selected.productVersion > 0 ? <button disabled={busy} onClick={() => void select(selected.productId)}>Serverstand neu laden</button> : null}
          <form onSubmit={e => void save(e)}><fieldset disabled={busy || !!imageRecovery.current}>
            <legend>Artikeldaten</legend><label>Artikelname<input required maxLength={120} value={selected.name} onChange={e => edit({name: e.target.value})} /></label>
            <div className="pm-fields"><label>Preis in Euro<input required inputMode="decimal" value={price} onChange={e => { setPrice(e.target.value); setDirty(true); }} /></label>
              <label>Armbandumfang in cm<input required type="number" min="0.1" max="1000" step="any" value={selected.braceletSizeCm || ''} onChange={e => edit({braceletSizeCm: Number(e.target.value)})} /></label>
              <label>Perlengröße in mm<input required type="number" min="0.1" max="1000" step="any" value={selected.pearlSizeMm || ''} onChange={e => edit({pearlSizeMm: Number(e.target.value)})} /></label></div>
            <DescriptionEditor value={selected.descriptionDocument} onChange={descriptionDocument => edit({descriptionDocument})} />
            {(['materials', 'metalElements', 'careInstructions'] as const).map(field => <label key={field}>{field === 'materials' ? 'Materialien' : field === 'metalElements' ? 'Metallelemente' : 'Pflegehinweise'} – ein Eintrag pro Zeile
              <textarea value={selected[field].join('\n')} onChange={e => edit({[field]: e.target.value.split('\n')})} onBlur={() => edit({[field]: selected[field].map(s => s.trim()).filter(Boolean)})} /></label>)}
            <label className="pm-check"><input type="checkbox" checked={selected.salesEnabled} onChange={e => edit({salesEnabled: e.target.checked})} /> Verkaufsfreigabe</label>
            <p className="muted">Ohne bestätigten verfügbaren Bestand ist ein Artikel auch mit Verkaufsfreigabe nicht kaufbar.</p>
            <h3>Bilder</h3><p>Bis zu fünf Bilder. Automatisch als JPEG bis 3200 Pixel; das erste Bild ist das Hauptbild.</p>
            <div className="pm-images">{selected.images.map((im, index) => <div className="pm-image" key={im.imageId}>
              <PrivateImage productId={selected.productId} image={im} /><span>{index === 0 ? 'Hauptbild' : `Bild ${index + 1}`} · {im.width} × {im.height}</span>
              <label>Bildbeschreibung<input required maxLength={160} value={im.alt} onChange={e => edit({images: selected.images.map((i, j) => j === index ? {...i, alt: e.target.value} : i)})} /></label>
              <div className="pm-inline"><button type="button" disabled={index === 0} onClick={() => { const images = [...selected.images]; [images[index - 1], images[index]] = [images[index], images[index - 1]]; edit({images}); }}>Nach vorne</button>
                <button type="button" onClick={() => { if (window.confirm('Bild aus diesem Artikel entfernen? Erst Speichern übernimmt die Änderung.')) edit({images: selected.images.filter((_, j) => index !== j)}); }}>Entfernen</button></div>
            </div>)}</div>
            <label>Bilder hinzufügen<input type="file" accept="image/jpeg,image/png,image/webp" multiple disabled={selected.images.length >= 5} onChange={e => { void addImages(e.target.files); e.target.value = ''; }} /></label>
            <div className="pm-notices"><h3>Automatische Produkthinweise</h3>{(selected.notices ?? []).length ? selected.notices!.map(n => <p key={n}>{n}</p>) : <p>{selected.productVersion ? 'Für diesen bestehenden Artikel sind keine automatischen Hinweise hinterlegt.' : 'Beide Naturstein-Hinweise werden beim ersten Speichern automatisch ergänzt.'}</p>}<small>Serverseitig verwaltet und nicht löschbar.</small></div>
          </fieldset><div className="pm-actions"><button type="submit" disabled={busy || conflict}>{imageRecovery.current ? 'Bildübertragung fortsetzen' : 'Artikel speichern'}</button></div></form>
          <section className="pm-section"><h3>Veröffentlichung</h3><p>Erst speichern, dann veröffentlichen. Archivieren erhält den Artikel und den Bestand.</p>
            <div className="pm-inline"><button disabled={busy || !canPublish || selected.status === 'disabled'} onClick={() => void lifecycle('publish')}>Veröffentlichen</button>
              {selected.status === 'published' ? <button disabled={busy || !canPublish} onClick={() => void lifecycle('archive')}>Archivieren</button> : null}
              {selected.status === 'disabled' ? <button disabled={busy || !canPublish} onClick={() => void lifecycle('restore')}>Wiederherstellen</button> : null}</div></section>
          <section className="pm-section"><h3>Bestand – separat bestätigen</h3>
            {inventory ? <p className="pm-stock">Vorhanden <strong>{inventory.onHand}</strong> · Reserviert <strong>{inventory.reserved}</strong> · Verfügbar <strong>{inventory.available}</strong></p> : <p>{inventoryMessage || 'Artikel zuerst speichern.'}</p>}
            <fieldset disabled={busy || !selected.productVersion || dirty || conflict || !!imageRecovery.current}>
              <label>Tatsächlich gezählte Stückzahl<input type="number" min="0" step="1" value={target} onChange={e => setTarget(e.target.value)} /></label>
              {inventory ? <><label>Grund<select value={reason} onChange={e => setReason(e.target.value)}><option value="correction">Zählkorrektur</option><option value="receive_stock">Neue fertige Armbänder</option><option value="mark_unsellable">Beschädigung / Nichtverfügbarkeit</option></select></label>
                <button onClick={() => { if (window.confirm(`Bestand ausdrücklich auf ${target} setzen?`)) void stock(false); }}>Bestand bestätigen</button></>
                : selected.status === 'draft' ? <button onClick={() => void stock(true)}>Anfangsbestand vormerken</button> : null}
              <button onClick={() => void loadInventory(selected.productId)}>Bestand neu laden</button></fieldset>
            <p className="muted">Geprüfte Rückgaben werden weiterhin in der Bestellverwaltung wiedereingelagert. Eine Erstattung verändert den Bestand nicht.</p></section>
        </div> : <div className="pm-empty"><h2>Wähle einen Artikel aus</h2><p>Oder lege einen neuen Artikel an. Änderungen werden erst mit deiner Bestätigung gespeichert.</p></div>}
      </div></>}
  </section>;
}
