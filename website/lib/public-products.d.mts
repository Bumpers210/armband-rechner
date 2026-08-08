export type ProductStatus =
  | "draft"
  | "ready"
  | "published"
  | "sold"
  | "disabled";

export type ProductImage = {
  src: string;
  alt: string;
  width: number;
  height: number;
  isMain: boolean;
};

export type PublicProduct = {
  sku: string;
  slug: string;
  publicTitle: string;
  description: string;
  materials: string[];
  metalElements: string[];
  braceletSizeCm: number;
  displayBraceletSize: string;
  pearlSizeMm: number | null;
  displayPearlSize: string | null;
  status: ProductStatus;
  images: ProductImage[];
  updatedAt: string;
};

export const publicProductName: string;

export function formatMeasurement(
  value: unknown,
  unit: string,
  location?: string,
): string;
export function formatProductSize(value: unknown, location?: string): string;
export function readJpegDimensions(filePath: string): {
  width: number;
  height: number;
};
export function loadPublicProducts(
  productsFile: string,
  imageRoot: string,
): {
  version: 1;
  products: PublicProduct[];
};

export const publicProductPatterns: {
  image: RegExp;
  sku: RegExp;
  slug: RegExp;
};
