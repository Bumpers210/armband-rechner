import productsData from "./products.json";

export type ProductStatus = "draft" | "ready" | "published" | "sold" | "disabled";

export type ProductImage = {
  src: string;
  alt: string;
  width: number;
  height: number;
  isMain: boolean;
};

export type PublicProduct = {
  draftId: string;
  sku: string;
  slug: string;
  status: ProductStatus;
  name: string;
  materials: string[];
  metalElements: string[];
  braceletSize: string;
  stock: number;
  shortDescription: string;
  careInstructions: string[];
  images: ProductImage[];
  vintedUrl: string;
  createdAt: string;
  updatedAt: string;
  publishedAt: string;
  soldAt?: string;
};

type ProductsFile = {
  version: number;
  products: PublicProduct[];
};

const typedProductsData = productsData as ProductsFile;

export const publicProducts = typedProductsData.products;

export const visibleProducts = publicProducts
  .filter((product) => product.status === "published")
  .sort((left, right) => right.publishedAt.localeCompare(left.publishedAt));

export const detailProducts = publicProducts
  .filter((product) => product.status === "published" || product.status === "sold")
  .sort((left, right) => right.publishedAt.localeCompare(left.publishedAt));

export function findDetailProduct(slug: string): PublicProduct | undefined {
  return detailProducts.find((product) => product.slug === slug);
}

export function mainProductImage(product: PublicProduct): ProductImage | undefined {
  return product.images.find((image) => image.isMain) ?? product.images[0];
}
