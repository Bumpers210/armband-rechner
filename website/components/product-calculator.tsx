'use client';

import {useEffect, useState} from 'react';
import {productRequest} from '@/lib/product-browser-client';
import {BeadPrice, calculateProduct, calculationMoney, calculationProductFields, CalculationValues,
  emptyCalculation, numericInputAllowed, resetCalculation} from '@/lib/product-calculator';

type Fields = ReturnType<typeof calculationProductFields>;
type Props = {disabled: boolean; canApplyToCurrent: boolean; onApply: (fields: Fields, create: boolean) => void};
export function ProductCalculator({disabled, canApplyToCurrent, onApply}: Props) {
  const [prices, setPrices] = useState<BeadPrice[] | null>(null);
  const [values, setValues] = useState<CalculationValues>(emptyCalculation);
  const [search, setSearch] = useState(''); const [loading, setLoading] = useState(false);
  const [message, setMessage] = useState(''); const [loadedAt, setLoadedAt] = useState('');
  const [changed, setChanged] = useState(false);
  useEffect(() => {
    const leave = (event: BeforeUnloadEvent) => { if (changed) { event.preventDefault(); event.returnValue = ''; } };
    window.addEventListener('beforeunload', leave); return () => window.removeEventListener('beforeunload', leave);
  }, [changed]);
  async function load() {
    if (loading) return; setLoading(true); setMessage('');
    try {
      const data = await productRequest<{prices: BeadPrice[]; loadedAt: string}>('/price-list');
      data.prices.forEach(p => calculationMoney(p.unitPrice));
      setPrices(data.prices); setLoadedAt(data.loadedAt);
      setMessage(data.prices.length ? 'Aktive Perlenpreise aus derselben Preisliste wie in der App geladen.' : 'Die Preisliste enthält keine aktiven Perlen.');
    } catch { setMessage('Preisliste derzeit nicht erreichbar. Es werden keine Ersatzpreise eingesetzt. Bereits geladene Preise bleiben als Vorschau erhalten.'); }
    finally { setLoading(false); }
  }
  let result: ReturnType<typeof calculateProduct> | null = null; let error = '';
  if (prices) { try { result = calculateProduct(prices, values); } catch (e) { error = e instanceof Error ? e.message : 'Kalkulation ungültig.'; } }
  function apply(create: boolean) {
    if (!prices || disabled || loading) return;
    try { onApply(calculationProductFields(prices, values), create); setMessage('Kalkulation geprüft. Übernommene Artikeldaten bitte im Editor prüfen und gesondert speichern.'); }
    catch (e) { setMessage(e instanceof Error ? e.message : 'Übernahme nicht möglich.'); }
  }
  const inputNames: [Exclude<keyof CalculationValues, 'quantities'>, string][] = [
    ['workMinutes', 'Arbeitszeit in Minuten'], ['hourlyRate', 'Stundenlohn in Euro'],
    ['otherCosts', 'Sonstige Kosten in Euro'], ['markupPercent', 'Aufschlag in Prozent'],
  ];
  return <details className="pm-calculator" open><summary>Perlen auswählen &amp; Preis kalkulieren</summary>
    <p>Die gleiche Perlenpreisliste und Rechnung wie in der App. Interne Kosten erscheinen nicht im Shop.</p>
    <p className="pm-note">Diese Recheneingaben bleiben nur in diesem geöffneten Browserfenster. Kalkulationen vom Handy werden nicht automatisch übertragen.
      Erst „Übernehmen“ füllt Perlen, Spacer und empfohlenen Preis im Artikel aus; gespeichert wird separat.</p>
    <div className="pm-inline"><button type="button" disabled={disabled || loading} onClick={() => void load()}>{loading ? 'Preisliste wird geladen …' : prices ? 'Preisliste aktualisieren' : 'Perlenpreisliste laden'}</button>
      {loadedAt ? <small>Geladen: {new Date(loadedAt).toLocaleString('de-DE')}</small> : null}</div>
    <p role="status" aria-live="polite">{message}</p>
    {prices ? <fieldset disabled={disabled || loading}><legend>Deine Kalkulation</legend>
      <div className="pm-calculator-layout"><div><label>Perlen oder Spacer suchen<input type="search" value={search} onChange={e => setSearch(e.target.value)} /></label>
        <div className="pm-beads">{prices.filter(p => p.name.toLocaleLowerCase().includes(search.toLocaleLowerCase())).map(p => <label className="pm-bead" key={p.name}>
          <span><strong>{p.name}</strong><small>{calculationMoney(p.unitPrice)} pro Stück</small></span>
          <input aria-label={`Menge ${p.name}`} type="number" inputMode="numeric" min="0" max="9999" step="1" value={Object.hasOwn(values.quantities, p.name) ? values.quantities[p.name] : 0}
            onChange={e => { const text = e.target.value; if (!/^\d*$/.test(text) || Number(text) > 9999) return;
              setValues(v => ({...v, quantities: {...v.quantities, [p.name]: Number(text)}})); setChanged(true); }} />
        </label>)}</div>
        {prices.length > 0 && !prices.some(p => p.name.toLocaleLowerCase().includes(search.toLocaleLowerCase())) ? <p>Keine passenden Perlen gefunden.</p> : null}
        <p>{Object.values(values.quantities).reduce((a, b) => a + b, 0)} Perlen / Spacer ausgewählt</p>
      </div><div><div className="pm-fields">{inputNames.map(([field, label]) => <label key={field}>{label}<input inputMode="decimal" value={values[field]}
        onChange={e => { if (numericInputAllowed(e.target.value)) { setValues(v => ({...v, [field]: e.target.value})); setChanged(true); } }} /></label>)}</div>
        {error ? <p role="alert">{error} Bitte die Auswahl korrigieren oder eine neue Kalkulation beginnen.</p> : null}
        {result ? <dl className="pm-calculation-totals">
          <dt>Material</dt><dd>{calculationMoney(result.materialCosts)}</dd>
          <dt>Arbeitszeit</dt><dd>{calculationMoney(result.laborCosts)}</dd>
          <dt>Sonstige Kosten</dt><dd>{calculationMoney(result.otherCosts)}</dd>
          <dt>Gesamtkosten</dt><dd>{calculationMoney(result.totalCosts)}</dd>
          <dt>Aufschlag</dt><dd>{calculationMoney(result.profit)}</dd>
          <dt>Verkaufspreis rechnerisch</dt><dd>{calculationMoney(result.exactSalePrice)}</dd>
          <dt><strong>Empfohlener Verkaufspreis</strong></dt><dd><strong>{calculationMoney(result.recommendedSalePrice)}</strong></dd>
        </dl> : null}
        <p className="muted">Empfehlung auf volle 50 Cent aufgerundet. Versand ist nicht enthalten.</p>
      </div></div>
      <div className="pm-inline"><button type="button" disabled={!result || !prices.length} onClick={() => apply(true)}>Als neuen Artikel übernehmen</button>
        <button type="button" disabled={!result || !prices.length || !canApplyToCurrent} onClick={() => apply(false)}>Im geöffneten Artikel übernehmen</button>
        <button type="button" onClick={() => { if (!changed || window.confirm('Neue Kalkulation beginnen? Stundenlohn und Aufschlag bleiben erhalten.')) {
          setValues(v => resetCalculation(v)); setSearch(''); setChanged(false); setMessage('Neue Kalkulation. Stundenlohn und Aufschlag bleiben erhalten.'); } }}>Neue Kalkulation</button></div>
    </fieldset> : null}
  </details>;
}
