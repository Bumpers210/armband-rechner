import { access, readFile, readdir } from "node:fs/promises";
import path from "node:path";

import { loadPublicProductsV2 } from "../lib/public-products-v2.mjs";

const projectRoot = process.cwd();
const outputDirectory = path.join(projectRoot, "out");
const siteUrl = "https://www.carmaja-perlen.de/";
const pageTitle = "Handgefertigte Edelsteinarmbänder | Carmaja-Perlen";
const pageDescription =
  "Handgefertigte Edelsteinarmbänder aus Rosenquarz, Amazonit, Achat und weiteren echten Edelsteinen – in kleinen Stückzahlen gefertigt von Carmaja-Perlen.";
const heroUrl = `${siteUrl}images/bracelets/hero-dunkelrot-braun-holz.jpg`;
const fixtureMode = process.env.CARMAJA_TEST_FIXTURES === "true";
const productsFile = fixtureMode && process.env.CARMAJA_PRODUCTS_FILE
  ? path.resolve(process.env.CARMAJA_PRODUCTS_FILE)
  : path.join(projectRoot, "content", "products.json");
const productImagesDirectory = fixtureMode && process.env.CARMAJA_PRODUCT_IMAGES_DIR
  ? path.resolve(process.env.CARMAJA_PRODUCT_IMAGES_DIR)
  : path.join(projectRoot, "public", "images", "products");

if (!fixtureMode && (process.env.CARMAJA_PRODUCTS_FILE || process.env.CARMAJA_PRODUCT_IMAGES_DIR)) {
  throw new Error("Alternative Produktquellen sind im Produktionsbuild gesperrt.");
}

function assert(condition, message) {
  if (!condition) {
    throw new Error(message);
  }
}

async function readOutputFile(relativePath) {
  return readFile(path.join(outputDirectory, relativePath), "utf8");
}

async function outputPathExists(relativePath) {
  try {
    await access(path.join(outputDirectory, relativePath));
    return true;
  } catch {
    return false;
  }
}

function readHead(html) {
  const match = html.match(/<head>([\s\S]*?)<\/head>/);
  assert(match, "Ein HTML-Dokument enthält keinen auswertbaren head.");
  return match[1];
}

const requiredFiles = [
  "index.html",
  "impressum/index.html",
  "datenschutz/index.html",
  "robots.txt",
  "sitemap.xml",
  "armbaender/index.html",
  "icon.svg",
  ".htaccess",
  "click.php",
  "statistik/index.php",
  "statistik/.htaccess.example",
  "private-data/.htaccess",
  "_internal/.htaccess",
  ".htaccess.publish.example",
];

for (const relativePath of requiredFiles) {
  await access(path.join(outputDirectory, relativePath));
}

const rootHtml = await readOutputFile("index.html");
const contactHtml = await readOutputFile("kontakt/index.html");
const imprintHtml = await readOutputFile("impressum/index.html");
const privacyHtml = await readOutputFile("datenschutz/index.html");
const rootHead = readHead(rootHtml);
const imprintHead = readHead(imprintHtml);
const privacyHead = readHead(privacyHtml);
const robotsText = await readOutputFile("robots.txt");
const sitemapXml = await readOutputFile("sitemap.xml");
const rootApacheRules = await readOutputFile(".htaccess");
const publishApacheRules = await readOutputFile(".htaccess.publish.example");
const clickPhp = await readOutputFile("click.php");
const dashboardPhp = await readOutputFile("statistik/index.php");
const privateDataRules = await readOutputFile("private-data/.htaccess");
const productsJson = loadPublicProductsV2(productsFile, productImagesDirectory);

assert(
  rootHtml.includes("v2-page") &&
    rootHtml.includes("Handgefertigte Perlenarmbänder aus echten Edelsteinen"),
  "Die freigegebene Gestaltung fehlt in out/index.html.",
);
assert(
  !rootHead.includes("noindex") &&
    !rootHead.includes("nofollow") &&
    !rootHead.includes("noimageindex") &&
    !rootHead.includes("nocache"),
  "Die Hauptseite enthält noch eine Veröffentlichungssperre.",
);
assert(
  rootHead.includes(`<title>${pageTitle}</title>`),
  "Der veröffentlichte Seitentitel ist nicht korrekt.",
);
assert(
  rootHead.includes(
    `<meta name="description" content="${pageDescription}"/>`,
  ),
  "Die veröffentlichte Meta-Beschreibung ist nicht korrekt.",
);
assert(
  rootHead.includes(`<link rel="canonical" href="${siteUrl}"/>`),
  "Der Canonical-Link verweist nicht exakt auf die Hauptadresse.",
);

const requiredSocialMetadata = [
  '<meta property="og:type" content="website"/>',
  '<meta property="og:locale" content="de_DE"/>',
  `<meta property="og:url" content="${siteUrl}"/>`,
  '<meta property="og:site_name" content="Carmaja-Perlen"/>',
  `<meta property="og:title" content="${pageTitle}"/>`,
  `<meta property="og:description" content="${pageDescription}"/>`,
  `<meta property="og:image" content="${heroUrl}"/>`,
  '<meta name="twitter:card" content="summary_large_image"/>',
  `<meta name="twitter:title" content="${pageTitle}"/>`,
  `<meta name="twitter:description" content="${pageDescription}"/>`,
  `<meta name="twitter:image" content="${heroUrl}"/>`,
];

for (const metadataTag of requiredSocialMetadata) {
  assert(
    rootHead.includes(metadataTag),
    `Social-Metadata fehlt: ${metadataTag}`,
  );
}

assert(
  rootHead.includes('rel="icon"') && rootHead.includes("/icon.svg"),
  "Das SVG-Favicon ist nicht mit der Hauptseite verknüpft.",
);

const jsonLdMatch = rootHtml.match(
  /<script type="application\/ld\+json">([\s\S]*?)<\/script>/,
);
assert(jsonLdMatch, "Organization-JSON-LD fehlt in out/index.html.");

const organization = JSON.parse(jsonLdMatch[1]);
assert(
  organization["@context"] === "https://schema.org" &&
    organization["@type"] === "Organization" &&
    organization.name === "Carmaja-Perlen" &&
    organization.legalName === "Carolin Buchner" &&
    organization.url === siteUrl &&
    organization.email === "kontakt@carmaja-perlen.de" &&
    organization.address?.["@type"] === "PostalAddress" &&
    organization.address.streetAddress === "Bubenheim 170" &&
    organization.address.postalCode === "91757" &&
    organization.address.addressLocality === "Treuchtlingen" &&
    organization.address.addressCountry === "DE" &&
    Array.isArray(organization.sameAs) &&
    organization.sameAs.length === 1 &&
    organization.sameAs.includes(
      "https://www.instagram.com/carmaja_perlen/",
    ) &&
    !("logo" in organization),
  "Das Organization-JSON-LD ist unvollständig oder enthält unerlaubte Angaben.",
);

const robotsLines = robotsText
  .split(/\r?\n/)
  .map((line) => line.trim())
  .filter(Boolean);

for (const requiredLine of [
  "User-Agent: *",
  "Allow: /",
  "Disallow: /statistik/",
  "Disallow: /click.php",
  "Disallow: /api/",
  "Disallow: /_internal/",
  `Sitemap: ${siteUrl}sitemap.xml`,
  "Host: www.carmaja-perlen.de",
]) {
  assert(robotsLines.includes(requiredLine), `robots.txt fehlt: ${requiredLine}`);
}

assert(
  !robotsLines.some((line) => line === "Disallow: /"),
  "robots.txt blockiert weiterhin die gesamte Website.",
);
assert(
  !robotsText.includes("/impressum") && !robotsText.includes("/datenschutz"),
  "robots.txt darf die Rechteseiten nicht blockieren.",
);

const sitemapLocations = [
  ...sitemapXml.matchAll(/<loc>([^<]+)<\/loc>/g),
].map((match) => match[1]);

const products = Array.isArray(productsJson.products)
  ? productsJson.products
  : [];
const publishedProducts = products.filter((product) => product.salesEnabled);
const unavailableProducts = products.filter((product) => !product.salesEnabled);
const publicProductsPayload = JSON.stringify(productsJson).toLowerCase();
for (const forbiddenProductField of [
  "saleprice",
  "verkaufspreis",
  "materialcosts",
  "materialkosten",
  "laborcosts",
  "hourlyrate",
  "markuppercent",
]) {
  assert(
    !publicProductsPayload.includes(forbiddenProductField),
    `Öffentliche Produktdaten enthalten interne Kalkulationsdaten: ${forbiddenProductField}`,
  );
}
const expectedSitemapLocations = [
  siteUrl,
  `${siteUrl}armbaender/`,
  `${siteUrl}ueber-mich/`,
  `${siteUrl}material-pflege/`,
  `${siteUrl}kontakt/`,
  ...publishedProducts.map(
    (product) => `${siteUrl}armbaender/${product.slug}/`,
  ),
];

assert(
  sitemapLocations.length === expectedSitemapLocations.length &&
    expectedSitemapLocations.every((location) =>
      sitemapLocations.includes(location),
    ),
  "Die Sitemap enthält nicht exakt die freigegebenen öffentlichen Produktseiten.",
);
for (const product of unavailableProducts) {
  assert(
    !sitemapLocations.includes(`${siteUrl}armbaender/${product.slug}/`),
    `Nicht veröffentlichte Produktseite steht in der Sitemap: ${product.slug}`,
  );
}

assert(
  imprintHead.includes("<title>Impressum | Carmaja-Perlen</title>") &&
    imprintHead.includes('name="robots" content="noindex, follow"') &&
    !imprintHead.includes("nofollow"),
  "Die Robots-Metadaten des Impressums sind nicht korrekt.",
);
assert(
  privacyHead.includes("<title>Datenschutz | Carmaja-Perlen</title>") &&
    privacyHead.includes('name="robots" content="noindex, follow"') &&
    !privacyHead.includes("nofollow"),
  "Die Robots-Metadaten der Datenschutzerklärung sind nicht korrekt.",
);
assert(
  !sitemapXml.includes("/impressum") &&
    !sitemapXml.includes("/datenschutz") &&
    !sitemapXml.includes("/statistik"),
  "Die Sitemap enthält eine ausgeschlossene Route.",
);

assert(
  contactHtml.includes("mailto:kontakt@carmaja-perlen.de") &&
    imprintHtml.includes("mailto:kontakt@carmaja-perlen.de") &&
    privacyHtml.includes("mailto:kontakt@carmaja-perlen.de"),
  "Kontakt- und Rechtstextseiten benötigen den freigegebenen mailto-Link.",
);
assert(
  privacyHtml.includes("1. Verantwortlicher") &&
    privacyHtml.includes("3. Zahlung, E-Mail und Versand") &&
    privacyHtml.includes("7. Ihre Rechte") &&
    privacyHtml.includes("Stripe") &&
    privacyHtml.includes("Brevo"),
  "Die freigegebene v3-Datenschutzerklärung fehlt im Export.",
);

const expectedTrackedLinks = [
  "/click.php?target=instagram&amp;position=footer",
];

for (const trackedLink of expectedTrackedLinks) {
  assert(rootHtml.includes(trackedLink), `Tracking-Link fehlt: ${trackedLink}`);
}

const productsOverviewHtml = await readOutputFile("armbaender/index.html");
assert(
  productsOverviewHtml.includes("Handgefertigte Armbänder") &&
    !productsOverviewHtml.toLowerCase().includes("verkaufspreis") &&
    !productsOverviewHtml.toLowerCase().includes("materialkosten"),
  "Die Produktübersicht fehlt oder zeigt interne Preis-/Kalkulationsdaten.",
);

for (const product of publishedProducts) {
  const detailHtml = await readOutputFile(`armbaender/${product.slug}/index.html`);

  assert(
    detailHtml.includes(product.publicTitle) &&
      detailHtml.includes(`data-product-id="${product.productId}"`) &&
      detailHtml.includes(`data-product-version="${product.productVersion}"`) &&
      !detailHtml.toLowerCase().includes("verkaufspreis") &&
      !detailHtml.toLowerCase().includes("materialkosten"),
    `Die veröffentlichte Produktdetailseite ist unvollständig: ${product.slug}`,
  );
}

for (const product of unavailableProducts) {
  const detailHtml = await readOutputFile(`armbaender/${product.slug}/index.html`);

  assert(
    detailHtml.includes("Nicht verfügbar") &&
      detailHtml.includes('name="robots" content="noindex, follow"'),
    `Nicht verfügbare Produkte müssen als noindex Detailseite erscheinen: ${product.slug}`,
  );
}

const expectedLegacyRedirect =
  "RewriteRule ^(?:v2|verspielt)/?$ https://www.carmaja-perlen.de/ [R=301,L,NE,QSD]";
const expectedCanonicalRedirect =
  "RewriteRule ^ https://www.carmaja-perlen.de%{REQUEST_URI} [R=301,L,NE]";

for (const [name, rules] of [
  ["produktive .htaccess", rootApacheRules],
  ["Veröffentlichungsvorlage", publishApacheRules],
]) {
  assert(
    rules.includes("Options -Indexes") &&
      rules.includes(expectedLegacyRedirect) &&
      rules.includes("RewriteCond %{HTTPS} !=on [OR]") &&
      rules.includes(
        "RewriteCond %{HTTP_HOST} !^www\\.carmaja-perlen\\.de$ [NC]",
      ) &&
      rules.includes(expectedCanonicalRedirect),
    `${name} enthält nicht alle produktiven Weiterleitungsregeln.`,
  );
}

assert(
  clickPhp.includes("'instagram' =>") &&
    !clickPhp.toLowerCase().includes("vinted") &&
    clickPhp.includes("http_response_code(400)") &&
    clickPhp.includes("in_array($position, CARMAJA_POSITIONS, true)") &&
    clickPhp.includes("header('Location: ' . $targetUrls[$target], true, 302)"),
  "Der Klick-Endpunkt erfüllt die statischen Whitelist-Prüfungen nicht.",
);
assert(
  dashboardPhp.includes("REMOTE_USER") &&
    dashboardPhp.includes("http_response_code(403)"),
  "Das Dashboard benötigt die zusätzliche serverseitige Authentifizierungssperre.",
);
assert(
  privateDataRules.includes("Require all denied"),
  "Das Fallback-Datenverzeichnis ist nicht vollständig gesperrt.",
);

const imageDirectory = path.join(outputDirectory, "images", "bracelets");
const exportedImages = await readdir(imageDirectory);
assert(exportedImages.length === 8, "Nicht alle acht Bilder wurden exportiert.");

const forbiddenTrackingTokens = [
  "googletagmanager",
  "google-analytics",
  "connect.facebook",
  "document.cookie",
  "localStorage",
  "sessionStorage",
];
const combinedOutput = `${rootHtml}\n${clickPhp}\n${dashboardPhp}`;

for (const token of forbiddenTrackingTokens) {
  assert(
    !combinedOutput.toLowerCase().includes(token.toLowerCase()),
    `Unzulässiges Tracking-Token gefunden: ${token}`,
  );
}

async function findPasswordFiles(directory) {
  const entries = await readdir(directory, { withFileTypes: true });
  const matches = [];

  for (const entry of entries) {
    const entryPath = path.join(directory, entry.name);

    if (entry.isDirectory()) {
      matches.push(...(await findPasswordFiles(entryPath)));
    } else if (entry.name === ".htpasswd") {
      matches.push(entryPath);
    }
  }

  return matches;
}

assert(
  (await findPasswordFiles(outputDirectory)).length === 0,
  "Eine .htpasswd darf nicht im Export enthalten sein.",
);
assert(
  !(await outputPathExists("api/index.php")) &&
    !(await outputPathExists("api/.htaccess")) &&
    !(await outputPathExists("_internal/product-api.php")),
  "Die getrennte Test-API darf nicht in den öffentlichen Website-Export gelangen.",
);
assert(
  !(await outputPathExists("v2")) &&
    !(await outputPathExists("verspielt")),
  "Eine frühere Designroute wurde weiterhin exportiert.",
);

console.log("Static export verification passed.");
