export type ProductImageV2 = {
  imageId: string;
  fileName: string;
  src: string;
  alt: string;
  width: number;
  height: number;
  isMain: boolean;
};

export type DescriptionTextStyleV1 = {
  bold: boolean;
  italic: boolean;
  font: "standard" | "elegant";
  size: "small" | "normal" | "large";
};

export type DescriptionDocumentV1 = {
  version: 1;
  blocks: Array<{
    type: "paragraph";
    spans: Array<{ text: string } & DescriptionTextStyleV1>;
  }>;
};

export type PublicProductV2 = {
  productModelVersion: 2 | 3;
  productId: string;
  productVersion: number;
  sourceHash: string;
  sku: string;
  slug: string;
  publicTitle: string;
  description: string;
  descriptionDocument: DescriptionDocumentV1 | null;
  materials: string[];
  metalElements: string[];
  braceletSizeCm: number;
  displaySize: string;
  pearlSizeMm: number;
  displayPearlSize: string;
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
  version: 2 | 3;
  products: PublicProductV2[];
};
