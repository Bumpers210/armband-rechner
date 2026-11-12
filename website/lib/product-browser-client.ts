export const PRODUCT_BROWSER_IS_TEST = process.env.CARMAJA_SITE_TARGET === 'test';
export const PRODUCT_BROWSER_API = process.env.CARMAJA_SITE_TARGET === 'test'
  ? 'https://test-api.carmaja-perlen.de/product-browser/index.php'
  : process.env.CARMAJA_SITE_TARGET === 'production' ? 'https://api.carmaja-perlen.de/product-browser/index.php' : '';
export type TextSpan = {text: string; bold: boolean; italic: boolean; font: 'standard' | 'elegant'; size: 'small' | 'normal' | 'large'};
export type DescriptionDocument = {version: 1; blocks: Array<{type: 'paragraph'; spans: TextSpan[]}>};
export type ProductImage = {imageId: string; fileName: string; alt: string; width: number; height: number; isMain: boolean; uploaded?: boolean; blob?: Blob};
export type ManagedProduct = {
  productId: string; productVersion: number; sourceHash: string; version: number; status: string;
  name: string; description?: string; descriptionDocument: DescriptionDocument;
  materials: string[]; metalElements: string[]; braceletSizeCm: number; pearlSizeMm: number;
  careInstructions: string[]; images: ProductImage[]; priceMinor: number; currency: string; salesEnabled: boolean;
  notices?: string[]; sku?: string | null; updatedAt?: string | null;
};
export type ManagedInventory = {onHand: number; reserved: number; available: number; inventoryVersion: number; quantityConfirmed: boolean};
export class ProductBrowserError extends Error {
  constructor(public status: number, public code: string, message: string) { super(message); }
}
export function productBrowserUrl(route: string): string {
  if (!PRODUCT_BROWSER_API) throw new Error('Artikelverwaltung ist nicht konfiguriert.');
  if (!/^\/(?:session|login|logout|price-list|products(?:\/[A-Za-z0-9-]+(?:\/(?:images(?:\/[A-Za-z0-9-]+)?|publish|archive|restore|inventory|initial-inventory))?)?)$/.test(route)) throw new Error('Ungültiger Artikelweg.');
  return `${PRODUCT_BROWSER_API}?route=${encodeURIComponent(route)}`;
}
export async function productRequest<T>(route: string, method = 'GET', body?: unknown, csrf = '', key?: string): Promise<T> {
  const multipart = body instanceof FormData;
  let response: Response;
  try {
    response = await fetch(productBrowserUrl(route), {method, cache: 'no-store', credentials: 'include',
      headers: {...(!multipart && body !== undefined ? {'Content-Type': 'application/json'} : {}),
        ...(method !== 'GET' && csrf ? {'X-CSRF-Token': csrf} : {}), ...(key ? {'Idempotency-Key': key} : {})},
      body: body === undefined ? undefined : multipart ? body : JSON.stringify(body)});
  } catch { throw new ProductBrowserError(0, 'network_unknown', 'Verbindung unterbrochen. Ausgang unklar: denselben Vorgang erneut versuchen oder Serverstand neu laden.'); }
  const json = await response.json().catch(() => null);
  if (!response.ok || json?.ok !== true) {
    const code = typeof json?.error?.code === 'string' ? json.error.code : 'request_failed';
    const message = response.status === 409 ? 'Inzwischen wurde dieser Artikel geändert. Deine Eingaben bleiben hier stehen. Bitte Serverstand neu laden und die Änderungen vergleichen.'
      : response.status === 401 ? 'Bitte mit deinen App-Zugangsdaten anmelden.'
      : response.status === 429 ? 'Zu viele Versuche. Bitte später erneut versuchen.'
      : response.status >= 500 ? 'Artikelverwaltung derzeit nicht erreichbar oder gesperrt. Es wurde kein Erfolg bestätigt.'
      : typeof json?.error?.message === 'string' ? json.error.message : 'Anfrage abgelehnt.';
    throw new ProductBrowserError(response.status, code, message);
  }
  return json.data as T;
}
export async function productImageBlob(productId: string, imageId: string): Promise<Blob> {
  const response = await fetch(productBrowserUrl(`/products/${productId}/images/${imageId}`), {cache: 'no-store', credentials: 'include'});
  if (!response.ok || !response.headers.get('content-type')?.startsWith('image/jpeg')) throw new Error('Vorhandenes Bild nicht ladbar. Es wird nicht überschrieben.');
  return response.blob();
}
export const newSpan = (text = ''): TextSpan => ({text, bold: false, italic: false, font: 'standard', size: 'normal'});
export function newProduct(): ManagedProduct {
  return {productId: crypto.randomUUID(), productVersion: 0, sourceHash: '', version: 0, status: 'draft', name: PRODUCT_BROWSER_IS_TEST ? 'HANDY-TEST ' : '',
    descriptionDocument: {version: 1, blocks: [{type: 'paragraph', spans: [newSpan()]}]}, materials: [], metalElements: [],
    braceletSizeCm: 18, pearlSizeMm: 8, careInstructions: [], images: [], priceMinor: 2800, currency: 'eur', salesEnabled: false};
}
export function editingProduct(product: ManagedProduct): ManagedProduct {
  return {...product, descriptionDocument: product.descriptionDocument ?? {version: 1, blocks: [{type: 'paragraph', spans: [newSpan(product.description ?? '')]}]}};
}
export function productPayload(product: ManagedProduct) {
  return {expectedProductVersion: product.productVersion, name: product.name, descriptionDocument: product.descriptionDocument,
    materials: product.materials, metalElements: product.metalElements, braceletSizeCm: product.braceletSizeCm,
    pearlSizeMm: product.pearlSizeMm, careInstructions: product.careInstructions,
    images: product.images.map((image, index) => ({imageId: image.imageId, fileName: `0${index + 1}.jpg`, alt: image.alt,
      width: image.width, height: image.height, isMain: index === 0})),
    priceMinor: product.priceMinor, currency: 'eur', salesEnabled: product.salesEnabled};
}
export function priceFromInput(value: string): number {
  const normalized = value.trim().replace(',', '.');
  if (!/^\d+(?:\.\d{1,2})?$/.test(normalized)) throw new Error('Preis bitte mit höchstens zwei Nachkommastellen eingeben.');
  const [euros, cents = ''] = normalized.split('.'); const price = Number(euros) * 100 + Number(cents.padEnd(2, '0'));
  if (!Number.isSafeInteger(price) || price <= 0) throw new Error('Preis muss größer als 0 sein.');
  return price;
}
export async function prepareProductImage(file: File, alt: string): Promise<ProductImage> {
  if (!['image/jpeg', 'image/png', 'image/webp'].includes(file.type) || file.size > 25 * 1048576) throw new Error('Bitte JPEG, PNG oder WebP bis 25 MB auswählen.');
  const bitmap = await createImageBitmap(file, {imageOrientation: 'from-image'});
  try {
    if (bitmap.width * bitmap.height > 100000000) throw new Error('Bild zu groß. Bitte eine kleinere Originaldatei auswählen.');
    const scale = Math.min(1, 3200 / Math.max(bitmap.width, bitmap.height));
    const canvas = document.createElement('canvas'); canvas.width = Math.max(1, Math.round(bitmap.width * scale)); canvas.height = Math.max(1, Math.round(bitmap.height * scale));
    const ctx = canvas.getContext('2d'); if (!ctx) throw new Error('Bildverarbeitung nicht verfügbar.');
    ctx.fillStyle = '#fff'; ctx.fillRect(0, 0, canvas.width, canvas.height); ctx.drawImage(bitmap, 0, 0, canvas.width, canvas.height);
    let blob: Blob | null = null;
    for (const quality of [0.92, 0.85, 0.76, 0.65]) {
      blob = await new Promise(resolve => canvas.toBlob(resolve, 'image/jpeg', quality));
      if (blob && blob.size <= 5 * 1048576) break;
    }
    if (!blob || blob.size > 5 * 1048576) throw new Error('Bild konnte nicht auf höchstens 5 MB verkleinert werden.');
    return {imageId: crypto.randomUUID(), fileName: '', alt, width: canvas.width, height: canvas.height, isMain: false, uploaded: false, blob};
  } finally { bitmap.close(); }
}
