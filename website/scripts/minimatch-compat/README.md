# Development-only minimatch compatibility

Scoped npm overrides replace the two vulnerable minimatch dependency trees in
ESLint/Next's lint plugins with the upstream `minimatch@11.0.4` engine. Modern
typescript-estree uses v11 directly; the six legacy consumers use this facade.
Its engine is a pinned npm alias (`minimatch-v11`), remains in the lockfile with
registry integrity, and is still covered by `npm audit`. No advisory is ignored,
no vulnerable source is copied, and no application/runtime dependency changes.

Reason: [GHSA-g3hv-75wg-m249](https://github.com/advisories/GHSA-g3hv-75wg-m249)
is fixed in 11.0.1 and later. On 2026-11-03, ESLint 9 and the React/import/a11y
plugins still require callable minimatch 3, while typescript-estree uses the
named export from minimatch 10. A blind v11 override breaks the former; npm's
suggested forced ESLint downgrade is not used.

This small facade preserves the callable CommonJS/default export, the named
`minimatch` property, `Minimatch`, helpers and `defaults`. The one removed API
used by eslint-plugin-import, `makeRe(pattern).test(path)`, delegates to a v11
matcher, **not** a regular expression. It does not implement `RegExp`, `exec`,
`source` or `Minimatch.prototype.makeRe`. It is scoped to the inspected lint
consumers, not intended as a general v3 replacement. Tests exercise both
positive/negative matching and actual React/import/a11y rules.

Remove the override/facade once all lint consumers support a patched upstream
version natively. Any new consumer or use of unsupported APIs requires review.
Run clean `npm ci`, both npm audits, lint, the complete Node/shell regression
and the test export before changing it. It must never be bundled into the site.
