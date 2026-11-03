import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import { readFileSync } from 'node:fs';
import { createRequire } from 'node:module';
import { fileURLToPath } from 'node:url';
import test from 'node:test';
import { ESLint, Linter } from 'eslint';
import importPlugin from 'eslint-plugin-import';
import reactPlugin from 'eslint-plugin-react';
import a11yPlugin from 'eslint-plugin-jsx-a11y';

const require = createRequire(import.meta.url);
const website = fileURLToPath(new URL('../../', import.meta.url));
const matcher = require('minimatch');

test('Alle Lint-Verbraucher verwenden ausschliesslich den auditierten minimatch-11-Matcher', () => {
  const lock = JSON.parse(readFileSync(new URL('../../package-lock.json', import.meta.url), 'utf8'));
  const engines = Object.entries(lock.packages).filter(([path, entry]) =>
    entry.name === 'minimatch' || (path.endsWith('/minimatch') && !entry.link));
  assert.ok(engines.length > 0, 'The upstream engine must remain visible to npm audit');
  for (const [path, entry] of engines) {
    assert.equal(entry.version, '11.0.4', path);
    assert.match(entry.resolved, /^https:\/\/registry\.npmjs\.org\/minimatch\/-\/minimatch-11\.0\.4\.tgz$/);
    assert.match(entry.integrity, /^sha512-/);
    assert.equal(entry.dev, true);
  }
  for (const [path, entry] of Object.entries(lock.packages)) {
    if (path.endsWith('/node_modules/minimatch') || path === 'node_modules/minimatch') {
      if (entry.link) assert.equal(entry.resolved, 'scripts/minimatch-compat');
      else assert.equal(entry.version, '11.0.4', `No old minimatch source may remain: ${path}`);
    }
  }
  const consumers = [
    'eslint', '@eslint/config-array', '@eslint/eslintrc',
    'eslint-plugin-react',
    'eslint-plugin-import', 'eslint-plugin-jsx-a11y',
  ];
  for (const consumer of consumers) {
    const fromConsumer = createRequire(require.resolve(consumer));
    assert.equal(fromConsumer('minimatch'), matcher, consumer);
  }
  const modern = createRequire(require.resolve('@typescript-eslint/typescript-estree'))('minimatch');
  assert.equal(typeof modern.minimatch, 'function');
  assert.equal(modern.minimatch('a.ts', '*.ts'), true);
  const workflow = readFileSync(new URL('../../../.github/workflows/deploy-website.yml', import.meta.url), 'utf8');
  assert.match(workflow, /run: npm audit --audit-level=moderate/);
});

test('Callable, default, named, Klassen- und Helper-Vertraege bleiben kompatibel', async () => {
  assert.equal(typeof matcher, 'function');
  assert.equal((await import('minimatch')).default, matcher);
  assert.equal(matcher.minimatch, matcher);
  for (const [path, pattern, options, expected] of [
    ['app/page.tsx', '**/*.{ts,tsx}', {}, true],
    ['app/page.php', '**/*.{ts,tsx}', {}, false],
    ['.hidden.js', '*.js', {}, false],
    ['.hidden.js', '*.js', { dot: true }, true],
    ['nested/page.js', '*.js', { matchBase: true }, true],
    ['nested/page.js', '*.js', {}, false],
    ['test.ts', '!*.js', {}, true],
    ['page.tsx', '*.+(ts|tsx)', {}, true],
    ['TEST.TS', '*.ts', { nocase: true }, true],
    ['#comment', '#comment', {}, false],
  ]) {
    assert.equal(matcher(path, pattern, options), expected);
    assert.equal(new matcher.Minimatch(pattern, options).match(path), expected);
    assert.equal(matcher.makeRe(pattern, options).test(path), expected);
  }
  assert.equal(new matcher.Minimatch('app/**/*.tsx').match('app/components', true), true);
  assert.equal(new matcher.Minimatch('app/**/*.tsx').match('other', true), false);
  assert.deepEqual(matcher.match(['a.js', 'b.php'], '*.js'), ['a.js']);
  assert.deepEqual(['a.ts', 'b.php'].filter(matcher.filter('*.ts')), ['a.ts']);
  assert.deepEqual(matcher.braceExpand('{a,b}.js'), ['a.js', 'b.js']);
  const defaults = matcher.defaults({ dot: true });
  assert.equal(defaults('.hidden.js', '*.js'), true);
  assert.equal(defaults.makeRe('*.js').test('.hidden.js'), true);
  assert.equal(new defaults.Minimatch('*.js').match('.hidden.js'), true);
  assert.equal(defaults.defaults({ nocase: true })('.HIDDEN.JS', '*.js'), true);
  assert.equal(matcher.makeRe('*.js') instanceof RegExp, false, 'No unsafe regexp regeneration');
});

function lint(source, rules) {
  return new Linter().verify(source, [{
    languageOptions: { ecmaVersion: 'latest', sourceType: 'module', parserOptions: { ecmaFeatures: { jsx: true } } },
    plugins: { import: importPlugin, react: reactPlugin, 'jsx-a11y': a11yPlugin },
    settings: { react: { version: '19.2' } },
    rules,
  }], { filename: 'fixture.js' });
}

test('React- und A11y-Regeln pruefen weiterhin callable minimatch-Pfade', () => {
  const reactRules = { 'react/forbid-component-props': ['error', { forbid: [{ propNamePattern: 'data-*' }] }] };
  assert.equal(lint('const View = () => <Widget title="ok" />;', reactRules).length, 0);
  assert.equal(lint('const View = () => <Widget data-secret="no" />;', reactRules)[0]?.ruleId, 'react/forbid-component-props');
  const a11yRules = { 'jsx-a11y/label-has-associated-control': ['error', { controlComponents: ['TestInput*'] }] };
  assert.equal(lint('const View = () => <label>Name<TestInputField /></label>;', a11yRules).length, 0);
  assert.equal(lint('const View = () => <label>Name<Other /></label>;', a11yRules)[0]?.ruleId, 'jsx-a11y/label-has-associated-control');
});

test('Import-Regeln pruefen Pfadgruppen und den makeRe/test-Vertrag ohne RegExp', () => {
  const internal = { 'import/no-internal-modules': ['error', { forbid: ['some-package/private/**'] }] };
  assert.equal(lint('import value from "some-package/public/value";', internal).length, 0);
  assert.equal(lint('import value from "some-package/private/value";', internal)[0]?.ruleId, 'import/no-internal-modules');
  const order = { 'import/order': ['error', {
    groups: ['external', 'internal'],
    pathGroups: [{ pattern: '@app/**', group: 'internal', position: 'after' }],
    pathGroupsExcludedImportTypes: [],
  }] };
  assert.equal(lint('import React from "react";\nimport local from "@app/local";', order).length, 0);
  assert.equal(lint('import local from "@app/local";\nimport React from "react";', order)[0]?.ruleId, 'import/order');
});

test('Projektlint entdeckt echte TSX-Dateien und ignoriert nur bestehende Buildausgaben', async () => {
  const eslint = new ESLint({ cwd: website });
  assert.equal(await eslint.isPathIgnored('app/page.tsx'), false);
  assert.equal(await eslint.isPathIgnored('components/shop-cart.tsx'), false);
  assert.equal(await eslint.isPathIgnored('out-test/index.html'), true);
  assert.equal(await eslint.isPathIgnored('.next/types/app/page.ts'), true);
  const [result] = await eslint.lintText('const unused = 1;\nexport default function Page() { return <main>Test</main>; }', { filePath: 'app/page.tsx' });
  assert.ok(result.messages.some(message => message.ruleId === '@typescript-eslint/no-unused-vars'));
});

test('Globparts-Amplifikation wird begrenzt; adversariale Muster blockieren den Testprozess nicht', () => {
  const script = `
    const assert = require('node:assert/strict');
    const { Minimatch } = require('minimatch');
    const matcher = new Minimatch('**/../a/b/'.repeat(30) + 'target', {
      optimizationLevel: 2, braceExpandMax: 32
    });
    assert.ok(matcher.globParts.length <= 32);
    assert.equal(matcher.match('not-the-target'), false);
  `;
  execFileSync(process.execPath, ['-e', script], { cwd: website, timeout: 5000, stdio: 'pipe' });
});
