export type CatalogOrder = {version: 1; productIds: string[] | null};
export function validateCatalogOrder(order: unknown): CatalogOrder;
export function loadCatalogOrder(file: string): CatalogOrder;
export function orderCatalog<T extends {productId: string; updatedAt: string}>(products: T[], order: CatalogOrder): T[];
