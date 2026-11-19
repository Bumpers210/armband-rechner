import assert from "node:assert/strict";
import { readFile } from "node:fs/promises";
import { existsSync } from "node:fs";
import { spawnSync } from "node:child_process";
import test from "node:test";

const apache = process.env.CARMAJA_APACHE_BINARY ?? (existsSync("/usr/sbin/apache2") ? "/usr/sbin/apache2" : undefined);
const modules = process.env.CARMAJA_APACHE_MODULES ?? "/usr/lib/apache2/modules";
const block = text => text.match(/# BEGIN CARMAJA STATIC PERFORMANCE[\s\S]*?# END CARMAJA STATIC PERFORMANCE/)?.[0];

test("Beide freigegebenen Hosting-Vorlagen enthalten dieselbe begrenzte Performancekonfiguration", async () => {
  const actual = block(await readFile(new URL("../../hosting/.htaccess", import.meta.url), "utf8"));
  const example = block(await readFile(new URL("../../hosting/.htaccess.publish.example", import.meta.url), "utf8"));
  assert.ok(actual);
  assert.equal(actual, example);
});

test("Apache liefert komprimierte Assets und schützt dynamische Daten und Fehler vor immutable", {
  skip: !apache,
}, () => {
  const result = spawnSync("bash", ["tests/shell/performance-hosting-test.sh", apache,
    modules, "hosting/.htaccess"], { encoding: "utf8", timeout: 45000 });
  assert.equal(result.status, 0, result.stdout + result.stderr);
  assert.match(result.stdout, /APACHE_PERFORMANCE_OK/);
});
