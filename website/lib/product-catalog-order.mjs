import { existsSync, readFileSync } from 'node:fs';

export function validateCatalogOrder(order) {
  if (!order || Object.keys(order).length !== 2 || order.version !== 1 || !Object.hasOwn(order, 'productIds')) throw new Error('Ungültige Katalogreihenfolge.');
  const ids = order.productIds;
  if (ids !== null && (!Array.isArray(ids) || ids.length > 1000 || new Set(ids).size !== ids.length
    || ids.some(id => typeof id !== 'string' || !/^(?:[0-9a-fA-F-]{36}|[0-9A-HJKMNP-TV-Z]{26})$/.test(id)))) throw new Error('Ungültige Katalogreihenfolge.');
  return order;
}
export function orderCatalog(products, order) {
  validateCatalogOrder(order);
  if (order.productIds === null) return [...products].sort((a, b) => b.updatedAt.localeCompare(a.updatedAt));
  const byId = new Map(products.map(p => [p.productId, p]));
  const chosen = new Set(order.productIds);
  return [...order.productIds.filter(id => byId.has(id)).map(id => byId.get(id)), ...products.filter(p => !chosen.has(p.productId))];
}
export function loadCatalogOrder(file) {
  return validateCatalogOrder(existsSync(file) ? JSON.parse(readFileSync(file, 'utf8')) : {version: 1, productIds: null});
}
