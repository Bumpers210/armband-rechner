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
  size: string;
  displaySize: string;
  pearlSizeMm?: number;
  displayPearlSizeMm?: string;
  stock: number;
  status: ProductStatus;
  images: ProductImage[];
  updatedAt: string;
  vintedUrl?: string;
};

export const publicProductName: string;

export function formatProductSize(value: unknown, location?: string): string;
export function formatPearlSizeMm(value: unknown, location?: string): string;
export function validateVintedUrl(value: unknown, location?: string): string;
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
