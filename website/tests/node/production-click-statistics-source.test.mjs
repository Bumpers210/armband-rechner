import assert from "node:assert/strict";
import { readFileSync } from "node:fs";
import path from "node:path";
import test from "node:test";
import { fileURLToPath } from "node:url";

const testDirectory = path.dirname(fileURLToPath(import.meta.url));
const websiteDirectory = path.resolve(testDirectory, "../..");
const readSource = (relativePath) => readFileSync(path.join(websiteDirectory, relativePath), "utf8");

test("Produktions-Klickhandler begrenzt Parameter und veroeffentlichte Produktlinks", () => {
  const source = readSource("hosting/click.php");

  assert.match(source, /\['target', 'position', 'product'\]/);
  assert.match(source, /carmaja_is_published_product_slug/);
  assert.match(source, /position === 'product'/);
  assert.match(source, /http_response_code\(400\)/);
  assert.match(source, /header\('Location: ' \./);
});

test("Tracking verwendet einen stabilen Lock und atomaren Austausch", () => {
  const source = readSource("hosting/_internal/tracking.php");

  assert.match(source, /CARMAJA_STATS_VERSION = 2/);
  assert.match(source, /CARMAJA_STATS_FILE/);
  assert.match(source, /CARMAJA_PRODUCT_PAGES_DIR/);
  assert.match(source, /flock\(\$lockHandle, \$lockType\)/);
  assert.match(source, /tempnam\(\s*dirname\(\$path\), '\.clicks-'/);
  assert.match(source, /rename\(\$temporaryPath, \$path\)/);
  assert.match(source, /'products'/);
  assert.doesNotMatch(source, /user.?agent|referer|referrer|\$_COOKIE/i);
});

test("Statistikbereich verlangt Apache Basic Auth und private Antwortheader", () => {
  const dashboard = readSource("hosting/statistik/index.php");
  const access = readSource("hosting/statistik/.htaccess");

  assert.match(access, /AuthType Basic/);
  assert.match(access, /AuthUserFile \/home\/www\/carmaja-production-auth\/statistik\.htpasswd/);
  assert.match(access, /Require valid-user/);
  assert.match(dashboard, /Cache-Control: private, no-store, max-age=0/);
  assert.match(dashboard, /X-Content-Type-Options: nosniff/);
  assert.match(dashboard, /noindex, nofollow, noimageindex/);
  assert.match(dashboard, /Content-Type: text\/html; charset=utf-8/);
  assert.match(dashboard, /Übersicht/);
  assert.doesNotMatch(dashboard, /<script\s+src=/i);
});
