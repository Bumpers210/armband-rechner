// Mirrors Android Models.kt with decimal integers, never floating-point money.
export type BeadPrice = {name: string; unitPrice: string};
export type CalculationValues = {quantities: Record<string, number>; workMinutes: string; hourlyRate: string; otherCosts: string; markupPercent: string};
type Decimal = {n: bigint; scale: number};
const power = (scale: number) => BigInt(10) ** BigInt(scale);
function decimal(value: string): Decimal {
  const match = /^(\d+)(?:\.(\d*))?(?:[eE]([+-]?\d+))?$/.exec(value);
  if (!match || value.length > 128) throw new Error('Ungültiger Dezimalwert.');
  const scale = (match[2]?.length ?? 0) - Number(match[3] ?? 0);
  if (Math.abs(scale) > 40) throw new Error('Dezimalwert außerhalb des unterstützten Bereichs.');
  const n = BigInt(match[1] + (match[2] ?? ''));
  return scale < 0 ? {n: n * power(-scale), scale: 0} : {n, scale};
}
function add(a: Decimal, b: Decimal): Decimal { const scale = Math.max(a.scale, b.scale); return {n: a.n * power(scale - a.scale) + b.n * power(scale - b.scale), scale}; }
const multiply = (a: Decimal, b: Decimal): Decimal => ({n: a.n * b.n, scale: a.scale + b.scale});
function divide(a: Decimal, b: Decimal, scale: number, even = false): Decimal {
  const numerator = a.n * power(b.scale + scale); const denominator = b.n * power(a.scale);
  const q = numerator / denominator; const remainder = numerator % denominator;
  const round = remainder * BigInt(2) > denominator || (remainder * BigInt(2) === denominator && (!even || q % BigInt(2) !== BigInt(0)));
  return {n: q + (round ? BigInt(1) : BigInt(0)), scale};
}
function plain(d: Decimal): string {
  const digits = d.n.toString().padStart(d.scale + 1, '0');
  return d.scale ? `${digits.slice(0, -d.scale)}.${digits.slice(-d.scale)}` : digits;
}
export function numericInputAllowed(value: string): boolean { return value.length <= 12 && /^\d*(?:[.,]\d*)?$/.test(value); }
function input(value: string): Decimal {
  if (!numericInputAllowed(value)) throw new Error('Bitte eine nichtnegative Zahl mit Komma oder Punkt eingeben.');
  const normalized = value.replace(',', '.');
  return decimal(!normalized || normalized === '.' ? '0' : normalized.startsWith('.') ? `0${normalized}` : normalized);
}
export const emptyCalculation = (): CalculationValues => ({quantities: {}, workMinutes: '0', hourlyRate: '0', otherCosts: '0', markupPercent: '0'});
export function resetCalculation(value: CalculationValues): CalculationValues { return {...value, quantities: {}, workMinutes: '0', otherCosts: '0'}; }
export function calculateProduct(prices: BeadPrice[], values: CalculationValues) {
  if (Object.keys(values.quantities).some(name => !prices.some(p => p.name === name))) throw new Error('Ausgewählte Perle fehlt in der aktuellen Preisliste.');
  let material = decimal('0');
  for (const item of prices) {
    const quantity = Object.hasOwn(values.quantities, item.name) ? values.quantities[item.name] : 0;
    if (!Number.isInteger(quantity) || quantity < 0 || quantity > 9999) throw new Error('Perlenmenge muss eine ganze Zahl von 0 bis 9999 sein.');
    material = add(material, multiply(decimal(item.unitPrice), decimal(String(quantity))));
  }
  const labor = multiply(divide(input(values.workMinutes), decimal('60'), 10), input(values.hourlyRate));
  const other = input(values.otherCosts); const total = add(add(material, labor), other);
  const profit = divide(multiply(total, input(values.markupPercent)), decimal('100'), 10);
  const exact = divide(add(total, profit), decimal('1'), 2);
  const recommended = {n: ((exact.n + BigInt(49)) / BigInt(50)) * BigInt(50), scale: 2};
  return {materialCosts: plain(material), laborCosts: plain(labor), otherCosts: plain(other), totalCosts: plain(total),
    profit: plain(profit), exactSalePrice: plain(exact), recommendedSalePrice: plain(recommended)};
}
export function calculationMoney(value: string): string { return `${plain(divide(decimal(value), decimal('1'), 2, true)).replace('.', ',')} €`; }
export function calculationProductFields(prices: BeadPrice[], values: CalculationValues) {
  const result = calculateProduct(prices, values); const minor = decimal(result.recommendedSalePrice).n;
  if (minor <= BigInt(0) || minor > BigInt(2147483647)) throw new Error('Der empfohlene Verkaufspreis muss positiv sein und in den App-Preisbereich passen.');
  const selected = prices.filter(p => Object.hasOwn(values.quantities, p.name) && values.quantities[p.name] > 0);
  if (!selected.some(p => !/spacer/i.test(p.name))) throw new Error('Bitte mindestens eine Perlenart auswählen.');
  return {priceMinor: Number(minor), materials: selected.filter(p => !/spacer/i.test(p.name)).map(p => p.name),
    metalElements: selected.filter(p => /spacer/i.test(p.name)).map(p => {
      const name = p.name.trim().replace(/\s+/g, ' '); return /Edelstahl/i.test(name) ? name : `${name} Edelstahl`;
    })};
}
