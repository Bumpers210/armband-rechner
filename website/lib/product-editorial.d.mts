import type { PublicProductV2 } from "./public-products-v2.mjs";

export function withProductEditorial<T extends PublicProductV2>(product: T): T & {
  custom?: boolean;
  descriptionTitle?: string;
};
