import assert from 'node:assert/strict';
import { spawnSync } from 'node:child_process';
import { mkdtempSync, readFileSync, rmSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import test from 'node:test';
import { fileURLToPath } from 'node:url';

const website = fileURLToPath(new URL('../../', import.meta.url));

test('Das vollständige Audit bleibt aktiv und die Lockdatei enthält kein Picomatch', () => {
  const lock = JSON.parse(readFileSync(new URL('../../package-lock.json', import.meta.url), 'utf8'));
  for (const [path, entry] of Object.entries(lock.packages)) {
    assert.notEqual(entry.name ?? path.split('node_modules/').at(-1), 'picomatch', path);
  }
  for (const name of ['deploy-website.yml', 'production-cart-package.yml']) {
    const workflow = readFileSync(new URL(`../../../.github/workflows/${name}`, import.meta.url), 'utf8');
    assert.match(workflow, /^\s*(?:run: )?npm audit --audit-level=moderate\s*$/m, name);
  }
});

test('Projektlint erkennt Next.js-, React-Hooks-, TypeScript- und Barrierefreiheitsfehler', t => {
  const temp = mkdtempSync(join(tmpdir(), 'carmaja-lint-fixture-'));
  t.after(() => rmSync(temp, { recursive: true, force: true }));
  const fixture = join(temp, 'fixture.tsx');
  writeFileSync(fixture, `
import { useEffect, useState } from 'react';
export function Fixture({ show }: { show: any }) {
  const [ready, setReady] = useState(false);
  useEffect(() => { setReady(true); }, []);
  if (show) useEffect(() => {}, []);
  return <main>{ready}<script src="/example.js" /><img src="/example.jpg" /></main>;
}
`);
  const result = spawnSync(process.execPath, [
    join(website, 'node_modules', 'oxlint', 'bin', 'oxlint'),
    '--config', join(website, '.oxlintrc.json'),
    '--format', 'json', fixture,
  ], { cwd: website, encoding: 'utf8', timeout: 15000 });
  assert.ifError(result.error);
  assert.equal(result.status, 1, result.stderr || result.stdout);
  const messages = JSON.parse(result.stdout).diagnostics.map(({ code, severity }) => [code, severity]);
  for (const [rule, severity] of [
    ['next(no-sync-scripts)', 'error'],
    ['hooks(rules-of-hooks)', 'error'],
    ['hooks(set-state-in-effect)', 'error'],
    ['typescript(no-explicit-any)', 'error'],
    ['jsx-a11y(alt-text)', 'warning'],
  ]) {
    assert.ok(messages.some(([code, level]) => code === rule && level === severity), JSON.stringify(messages));
  }
});
