import { access, readFile, readdir } from "node:fs/promises";
import path from "node:path";

import { readJpegDimensions } from "../lib/public-products.mjs";
import { loadPublicProductsV2 } from "../lib/public-products-v2.mjs";

const projectRoot = process.cwd();
const outputDirectory = path.join(projectRoot, "out-test");
const siteUrl = "https://test.carmaja-perlen.de/";
const fixtureMode = process.env.CARMAJA_TEST_FIXTURES === "true";
const productsFile = process.env.CARMAJA_PRODUCTS_FILE
  ? path.resolve(process.env.CARMAJA_PRODUCTS_FILE)
  : path.join(projectRoot, "content", "products.json");
const productImagesDirectory = process.env.CARMAJA_PRODUCT_IMAGES_DIR
  ? path.resolve(process.env.CARMAJA_PRODUCT_IMAGES_DIR)
  : path.join(projectRoot, "public", "images", "products");

if (
  process.env.CARMAJA_SITE_TARGET !== "test" ||
  process.env.CARMAJA_SITE_URL !== "https://test.carmaja-perlen.de" ||
  process.env.CARMAJA_PRODUCTION_PUBLISH_ENABLED === "true" ||
  process.env.CARMAJA_PRODUCTION_DEPLOY_ENABLED === "true"
) {
  throw new Error("Testexport wurde nicht mit der verbindlichen Zielkonfiguration gebaut.");
}

if (
  !fixtureMode &&
  (process.env.CARMAJA_PRODUCTS_FILE ||
    process.env.CARMAJA_PRODUCT_IMAGES_DIR)
) {
  throw new Error("Alternative Produktquellen sind außerhalb isolierter Tests gesperrt.");
}

function assert(condition, message) {
  if (!condition) {
    throw new Error(message);
  }
}

async function exists(relativePath) {
  try {
    await access(path.join(outputDirectory, relativePath));
    return true;
  } catch {
    return false;
  }
}

async function readOutput(relativePath) {
  return readFile(path.join(outputDirectory, relativePath), "utf8");
}

async function listFiles(directory, prefix = "") {
  const entries = await readdir(directory, { withFileTypes: true });
  const files = [];

  for (const entry of entries) {
    const relativePath = path.join(prefix, entry.name);

    if (entry.isDirectory()) {
      files.push(
        ...(await listFiles(path.join(directory, entry.name), relativePath)),
      );
    } else {
      files.push(relativePath);
    }
  }

  return files;
}

function readHead(html, relativePath) {
  const match = html.match(/<head>([\s\S]*?)<\/head>/);
  assert(match, `${relativePath} besitzt keinen auswertbaren HTML-head.`);
  return match[1];
}

function assertTestMetadata(html, relativePath) {
  const head = readHead(html, relativePath);
  const robotsTokens = new Set(
    [...head.matchAll(/<meta name="robots" content="([^"]+)"/g)]
      .flatMap((match) => match[1].split(","))
      .map((token) => token.trim()),
  );
  const canonical = head.match(/<link rel="canonical" href="([^"]+)"/)?.[1];

  for (const token of ["noindex", "nofollow", "noimageindex"]) {
    assert(
      robotsTokens.has(token),
      `${relativePath} enthält nicht alle Test-Robots-Metadaten.`,
    );
  }

  assert(
    typeof canonical === "string" && canonical.startsWith(siteUrl),
    `${relativePath} besitzt keinen Canonical-Link auf der Testdomain.`,
  );
  assert(
    !head.includes("https://www.carmaja-perlen.de"),
    `${relativePath} verweist in Metadaten auf die Produktionsdomain.`,
  );
}

for (const requiredFile of [
  "index.html",
  "404.html",
  "armbaender/index.html",
  "impressum/index.html",
  "datenschutz/index.html",
  "robots.txt",
  "sitemap.xml",
  "icon.svg",
  "images/brand/carmaja-logo-transparent.png",
  ".htaccess",
]) {
  assert(await exists(requiredFile), `Testexport fehlt: ${requiredFile}`);
}

const source = loadPublicProductsV2(productsFile, productImagesDirectory);
const enabled = source.products.filter((product) => product.salesEnabled);
const unavailable = source.products.filter((product) => !product.salesEnabled);
const overviewHtml = await readOutput("armbaender/index.html");
const robotsText = await readOutput("robots.txt");
const sitemapXml = await readOutput("sitemap.xml");
const apacheRules = await readOutput(".htaccess");

assert(
  robotsText.trim().replaceAll("\r\n", "\n").toLowerCase() ===
    "user-agent: *\ndisallow: /",
  "robots.txt muss den gesamten Testauftritt sperren.",
);
assert(
  !robotsText.toLowerCase().includes("sitemap:") &&
    !robotsText.toLowerCase().includes("host:"),
  "Test-robots.txt darf Sitemap oder Host nicht bewerben.",
);

for (const requiredRule of [
  "https://test.carmaja-perlen.de%{REQUEST_URI}",
  'AuthUserFile "/home/www/carmaja-test-auth/test-website.htpasswd"',
  "Require valid-user",
  'X-Robots-Tag "noindex, nofollow, noimageindex"',
  'Cache-Control "private, no-store"',
  'X-Content-Type-Options "nosniff"',
  'Referrer-Policy "no-referrer"',
]) {
  assert(apacheRules.includes(requiredRule), `.htaccess fehlt: ${requiredRule}`);
}

assert(
  apacheRules.indexOf("RewriteRule") < apacheRules.indexOf("AuthType Basic"),
  "HTTPS-Weiterleitung muss vor der Authentifizierung konfiguriert sein.",
);

const sitemapLocations = [
  ...sitemapXml.matchAll(/<loc>([^<]+)<\/loc>/g),
].map((match) => match[1]);
const expectedSitemap = [
  siteUrl,
  `${siteUrl}armbaender/`,
  `${siteUrl}ueber-mich/`,
  `${siteUrl}material-pflege/`,
  `${siteUrl}kontakt/`,
  ...source.products.map(
    (product) => `${siteUrl}armbaender/${product.slug}/`,
  ),
];

assert(
  sitemapLocations.length === expectedSitemap.length &&
    expectedSitemap.every((location) => sitemapLocations.includes(location)),
  "Testsitemap enthält nicht exakt Startseite, Übersicht und sichtbare v2-Testprodukte.",
);

for (const product of enabled) {
  const detailPath = `armbaender/${product.slug}/index.html`;
  const detailHtml = await readOutput(detailPath);

  assert(
    overviewHtml.includes(product.publicTitle) &&
      overviewHtml.includes(product.description) &&
      detailHtml.includes(product.publicTitle) &&
      detailHtml.includes(product.description),
    `Kaufbares v2-Produkt fehlt: ${product.sku}`,
  );
  assert(
    overviewHtml.includes("<dt>Materialien</dt>") &&
      overviewHtml.includes("<dt>Metallelemente</dt>") &&
      detailHtml.includes("<dt>Materialien</dt>") &&
      detailHtml.includes("<dt>Metallelemente</dt>"),
    `Materialdarstellung fehlt: ${product.sku}`,
  );
  assert(
    overviewHtml.includes(product.displaySize) &&
      detailHtml.includes(product.displaySize) &&
      !detailHtml.includes(`${product.displaySize} cm`),
    `Zentimeterdarstellung ist ungültig: ${product.sku}`,
  );
  assert(
    detailHtml.includes('href="/material-pflege/"') &&
      detailHtml.includes("Hinweise zu Material &amp; Pflege") &&
      !detailHtml.includes("Vor dem Duschen und Baden ablegen") &&
      !detailHtml.includes("Kontakt mit Parfüm und Cremes vermeiden") &&
      !detailHtml.includes("Nicht stark auseinanderziehen"),
    `Pflegeseiten-Link ist ungültig: ${product.sku}`,
  );
  assert(
    [...detailHtml.matchAll(/data-lightbox-open="/g)].length ===
      product.images.length,
    `Nicht jedes Produktbild öffnet die Großansicht: ${product.sku}`,
  );
  assert(
    !detailHtml.toLowerCase().includes("verkaufspreis") &&
      !detailHtml.toLowerCase().includes("materialkosten"),
    `Kaufbares v2-Produkt enthält interne Kalkulationsfelder: ${product.sku}`,
  );

  assert(
    !detailHtml.toLowerCase().includes("vinted") &&
      !detailHtml.toLowerCase().includes("marktplatz"),
    `Externer Marktplatzlink ist im Shop-Export nicht erlaubt: ${product.sku}`,
  );
}

for (const product of unavailable) {
  const detailPath = `armbaender/${product.slug}/index.html`;
  const detailHtml = await readOutput(detailPath);

  assert(await exists(detailPath), `Nicht verfügbare Detailseite fehlt: ${product.sku}`);
  assert(
      overviewHtml.includes(product.publicTitle) &&
      overviewHtml.includes(product.description) &&
      overviewHtml.includes("Nicht verfügbar") &&
      detailHtml.includes("Nicht verfügbar") &&
      detailHtml.includes('href="/material-pflege/"') &&
      detailHtml.includes("Hinweise zu Material &amp; Pflege") &&
      !detailHtml.toLowerCase().includes("vinted") &&
      !detailHtml.toLowerCase().includes("marktplatz"),
    `Nicht verfügbares v2-Produkt ist nicht korrekt gerendert: ${product.sku}`,
  );
}

const exportedFiles = await listFiles(outputDirectory);
const forbiddenFilePatterns = [
  /(?:^|[\\/])\.htpasswd$/i,
  /(?:^|[\\/])products\.json$/i,
  /public-products\.json$/i,
  /runtime-config(?:\.example)?\.php$/i,
  /(?:^|[\\/])click\.php$/i,
  /(?:^|[\\/])statistik(?:[\\/]|$)/i,
  /api-users\.json$/i,
  /device-tokens\.json$/i,
  /audit/i,
  /idempotency/i,
];

for (const relativePath of exportedFiles) {
  assert(
    !forbiddenFilePatterns.some((pattern) => pattern.test(relativePath)),
    `Unerlaubte Datei im Testexport: ${relativePath}`,
  );
}

const textExtensions = new Set([
  ".html",
  ".htaccess",
  ".js",
  ".css",
  ".txt",
  ".xml",
  ".svg",
]);
const forbiddenContent = [
  "https://www.carmaja-perlen.de",
  "/click.php",
  '"draftId"',
  '"deviceId"',
  '"internalCalculation"',
  '"materialCosts"',
  '"recommendedSalePrice"',
  '"salePrice"',
  "INTERNER-ARTIKELNAME-",
  "interne-artikelbezeichnung-",
  "INTERNER-BILDTEXT-",
  "INTERNER-PFLEGEHINWEIS-",
  "CARMAJA_GITHUB_TOKEN",
  "github_pat_",
  "BEGIN PRIVATE KEY",
];

for (const relativePath of exportedFiles) {
  const extension = path.extname(relativePath);
  const isApacheFile = path.basename(relativePath) === ".htaccess";

  if (!textExtensions.has(extension) && !isApacheFile) {
    continue;
  }

  const contents = await readOutput(relativePath);

  for (const forbidden of forbiddenContent) {
    assert(
      !contents.includes(forbidden),
      `Unerlaubter Inhalt in ${relativePath}: ${forbidden}`,
    );
  }

  if (!isApacheFile) {
    assert(
      !contents.includes("/home/www/"),
      `Privater Serverpfad in ${relativePath}.`,
    );
  }
}

const htmlFiles = exportedFiles.filter((relativePath) =>
  relativePath.endsWith(".html"),
);

for (const relativePath of htmlFiles) {
  const html = await readOutput(relativePath);
  assertTestMetadata(html, relativePath);

  for (const match of html.matchAll(/<img[^>]+src="([^"]+)"/g)) {
    const sourceUrl = match[1];
    assert(
      sourceUrl.startsWith("/") && !sourceUrl.startsWith("//"),
      `Externe Bildquelle in ${relativePath}: ${sourceUrl}`,
    );
  }
}

const rootHtml = await readOutput("index.html");

assert(
  rootHtml.includes(`"url":"${siteUrl}"`) &&
    rootHtml.includes(`<meta property="og:url" content="${siteUrl}"/>`),
  "JSON-LD oder OpenGraph verwendet nicht die Testdomain.",
);

for (const product of [...enabled, ...unavailable]) {
  for (const image of product.images) {
    const outputImage = path.join(outputDirectory, image.src.slice(1));
    const dimensions = readJpegDimensions(outputImage);

    assert(
      dimensions.width === image.width && dimensions.height === image.height,
      `Exportiertes Produktbild ist ungültig: ${image.src}`,
    );
  }
}

assert(
  !exportedFiles.some((relativePath) => relativePath.includes("__empty")),
  "Temporäre leere Produktroute ist im Export verblieben.",
);

console.log("Testexport erfolgreich verifiziert.");
