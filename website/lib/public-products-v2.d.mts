export type ProductImageV2 = {
  imageId: string;
  fileName: string;
  src: string;
  alt: string;
  width: number;
  height: number;
  isMain: boolean;
};

export type PublicProductV2 = {
  productModelVersion: 2;
  productId: string;
  productVersion: number;
  sourceHash: string;
  sku: string;
  slug: string;
  publicTitle: string;
  description: string;
  materials: string[];
  metalElements: string[];
  size: string;
  displaySize: string;
  priceMinor: number;
  currency: "eur";
  salesEnabled: boolean;
  images: ProductImageV2[];
  updatedAt: string;
};

export function loadPublicProductsV2(
  productsFile: string,
  imageRoot: string,
): {
  version: 2;
  products: PublicProductV2[];
};
