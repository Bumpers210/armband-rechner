import assert from "node:assert/strict";
import { readFileSync } from "node:fs";
import path from "node:path";
import test from "node:test";
import { fileURLToPath } from "node:url";

const testDirectory = path.dirname(fileURLToPath(import.meta.url));
const websiteDirectory = path.resolve(testDirectory, "../..");
const readSource = (relativePath) => readFileSync(path.join(websiteDirectory, relativePath), "utf8");

test("Seitenaufrufe werden zentral und ohne rohe Herkunftsadresse erfasst", () => {
  const tracker = readSource("components/pageview-tracker.tsx");
  const layout = readSource("app/layout.tsx");

  assert.match(tracker, /usePathname/);
  assert.match(tracker, /navigator\.sendBeacon/);
  assert.match(tracker, /pageview\.php/);
  assert.match(tracker, /google/);
  assert.match(tracker, /instagram/);
  assert.match(tracker, /direct-unknown/);
  assert.doesNotMatch(tracker, /fetch\([^)]*referrer/i);
  assert.match(layout, /<PageViewTracker\s*\/>/);
});

test("Seitenaufrufhandler akzeptiert nur veröffentlichte POST-Routen und feste Kategorien", () => {
  const handler = readSource("hosting/pageview.php");

  assert.match(handler, /REQUEST_METHOD/);
  assert.match(handler, /Allow: POST/);
  assert.match(handler, /\['path', 'source'\]/);
  assert.match(handler, /carmaja_is_published_page_path/);
  assert.match(handler, /CARMAJA_PAGEVIEW_SOURCES/);
  assert.match(handler, /http_response_code\(204\)/);
  assert.doesNotMatch(handler, /HTTP_REFERER|\$_COOKIE|user.?agent/i);
});

test("Datenschutzerklärung beschreibt nur aggregierte Routen und Herkunftskategorien", () => {
  const content = readSource("content/site-content.ts");

  assert.match(content, /Aggregierte Website-Statistik/);
  assert.match(content, /vollständige Herkunfts-URLs oder Domains werden nicht übertragen oder gespeichert/);
  assert.match(content, /Direkt\/Unbekannt/);
});
