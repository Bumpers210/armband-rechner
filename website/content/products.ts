import path from "node:path";

import {
  loadPublicProducts,
  type ProductImage,
  type ProductStatus,
  type PublicProduct,
} from "@/lib/public-products.mjs";

export type { ProductImage, ProductStatus, PublicProduct };

const projectRoot = process.cwd();
const productsFile = path.join(projectRoot, "content", "products.json");
const imagesDirectory = path.join(projectRoot, "public", "images", "products");
const productsData = loadPublicProducts(productsFile, imagesDirectory);

export const publicProducts = productsData.products;

export const visibleProducts = publicProducts
  .filter((product) => product.status === "published")
  .sort((left, right) => right.updatedAt.localeCompare(left.updatedAt));

export const detailProducts = publicProducts
  .filter((product) => product.status === "published" || product.status === "sold")
  .sort((left, right) => right.updatedAt.localeCompare(left.updatedAt));

export function findDetailProduct(slug: string): PublicProduct | undefined {
  return detailProducts.find((product) => product.slug === slug);
}

export function mainProductImage(
  product: PublicProduct,
): ProductImage | undefined {
  return product.images.find((image) => image.isMain) ?? product.images[0];
}
