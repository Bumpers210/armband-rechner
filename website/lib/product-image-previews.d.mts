import type { PublicProductV2 } from "./public-products-v2.mjs";
export const PREVIEW_EDGES: readonly number[];
export function productPreviewPath(src: string, sourceHash: string, edge: number): string;
export function withProductPreviews(product: PublicProductV2, target: string | undefined):
  Omit<PublicProductV2, "images"> & { images: Array<PublicProductV2["images"][number] & {
    previews?: Record<number, string>;
  }> };
