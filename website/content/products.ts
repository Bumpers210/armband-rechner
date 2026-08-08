import path from "node:path";

import {
  loadPublicProductsV2,
  type ProductImageV2,
  type PublicProductV2,
} from "@/lib/public-products-v2.mjs";

export type ProductImage = ProductImageV2;
export type PublicProduct = PublicProductV2;

const fixtureMode = process.env.CARMAJA_TEST_FIXTURES === "true";
const configuredProductsFile = process.env.CARMAJA_PRODUCTS_FILE;
const configuredImagesDirectory = process.env.CARMAJA_PRODUCT_IMAGES_DIR;

if (
  !fixtureMode &&
  (configuredProductsFile !== undefined || configuredImagesDirectory !== undefined)
) {
  throw new Error(
    "Alternative Produktquellen sind ausschließlich für isolierte Testfixtures erlaubt.",
  );
}

const projectRoot = process.cwd();
const productsFile = configuredProductsFile
  ? path.resolve(configuredProductsFile)
  : path.join(projectRoot, "content", "products.json");
const imagesDirectory = configuredImagesDirectory
  ? path.resolve(configuredImagesDirectory)
  : path.join(projectRoot, "public", "images", "products");
const productsData = loadPublicProductsV2(productsFile, imagesDirectory);

export const publicProducts = productsData.products;

const includeUnavailableTestProducts =
  process.env.CARMAJA_SITE_TARGET === "test";

export const visibleProducts = publicProducts
  .filter(
    (product) => product.salesEnabled || includeUnavailableTestProducts,
  )
  .sort((left, right) => right.updatedAt.localeCompare(left.updatedAt));

export const detailProducts = publicProducts
  .sort((left, right) => right.updatedAt.localeCompare(left.updatedAt));

export function findDetailProduct(slug: string): PublicProduct | undefined {
  return detailProducts.find((product) => product.slug === slug);
}

export function mainProductImage(
  product: PublicProduct,
): ProductImage | undefined {
  return product.images.find((image) => image.isMain) ?? product.images[0];
}
