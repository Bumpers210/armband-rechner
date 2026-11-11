import { execFileSync } from 'node:child_process';
import { createHash } from 'node:crypto';
import { existsSync, mkdirSync, readFileSync, writeFileSync } from 'node:fs';
import { resolve, dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const repo = resolve(dirname(fileURLToPath(import.meta.url)), '../..');
export const baseline = Object.freeze({
  'product-api.php': 'ab3f2d30cd214d06a11ad96d3fe211cd9f282cd3',
  'product-api-v2.php': 'cb2438c2a6fc50bb19b5eeddde243140f27518e8',
  'product-api-v3.php': 'cb2438c2a6fc50bb19b5eeddde243140f27518e8',
  'product-api-v4.php': 'ab3f2d30cd214d06a11ad96d3fe211cd9f282cd3',
  'bootstrap.php': 'fb0b971e39ba0004c964b78e32c33c4fe3ced62f',
});
export const digest = (value) => createHash('sha256').update(value).digest('hex');
export const tracked = (commit, path) => execFileSync('git', ['show', `${commit}:${path}`], {
  cwd: repo, encoding: 'utf8', maxBuffer: 4 * 1024 * 1024,
}).replaceAll('\r\n', '\n');

function once(source, before, after) {
  if (source.split(before).length !== 2) throw new Error('Patch precondition failed');
  return source.replace(before, after);
}

export function hotfix(name, input) {
  let text = input;
  if (name === 'product-api-v4.php') {
    text = once(text,
      "            $draft['version'] = (int) ($draft['version'] ?? 0) + 1;\n            $draft[$operationField] = $operationId;",
      "            $draft['version'] = (int) ($draft['version'] ?? 0) + 1;\n"
      + "            $draft['productVersion'] = (int) $draft['productVersion'] + 1;\n"
      + "            $draft['sourceHash'] = carmaja_api_v3_product_from_draft($draft)['sourceHash'];\n"
      + "            $draft[$operationField] = $operationId;");
    text = once(text,
      "carmaja_api_audit_best_effort('product_v4_' . $action, [\n        'productId' => $productId,\n        'productVersion' => $expectedVersion,",
      "carmaja_api_audit_best_effort('product_v4_' . $action, [\n        'productId' => $productId,\n        'productVersion' => $prepared['draft']['productVersion'],");
  } else if (name === 'product-api.php') {
    text = once(text, 'declare(strict_types=1);',
      "declare(strict_types=1);\n\nrequire_once __DIR__ . '/product-maintenance-guard.php';");
    for (const signature of [
      'function carmaja_api_save_draft(array $draft): array',
      'function carmaja_api_upload_images(string $draftId, array $body, array $actor): array',
    ]) text = once(text, signature + '\n{', signature + "\n{\n    carmaja_product_maintenance_assert('product');");
    text = once(text, 'function carmaja_api_with_lock(string $name, callable $callback): mixed\n{',
      'function carmaja_api_with_lock(string $name, callable $callback): mixed\n{\n    carmaja_product_maintenance_assert_lock($name);');
    text = once(text, '    carmaja_api_require_github_configuration($requireEnabled);\n    $mock =',
      "    if (!in_array(strtoupper($method), ['GET', 'HEAD'], true)) {\n        carmaja_product_maintenance_assert('publisher');\n    }\n"
      + '    carmaja_api_require_github_configuration($requireEnabled);\n    $mock =');
    text = once(text, '    $adapter = $GLOBALS[\'CARMAJA_API_PUBLISH_ADAPTER\'] ?? null;',
      "    carmaja_product_maintenance_assert('publisher');\n    $adapter = $GLOBALS['CARMAJA_API_PUBLISH_ADAPTER'] ?? null;");
  } else if (name === 'product-api-v2.php' || name === 'product-api-v3.php') {
    const version = name.includes('-v2') ? 'v2' : 'v3';
    const match = text.match(new RegExp(`function carmaja_api_run_publish_adapter_${version}\\([\\s\\S]*?\\): array\\s*\\{`));
    if (!match) throw new Error('Publisher signature missing');
    const signature = match[0];
    text = once(text, signature, signature + "\n    carmaja_product_maintenance_assert('publisher');");
  } else if (name === 'bootstrap.php') {
    text = once(text, 'declare(strict_types=1);',
      "declare(strict_types=1);\n\nrequire_once __DIR__ . '/product-maintenance-guard.php';");
    const signature = 'function carmaja_bootstrap_route_request(): never\n{';
    text = once(text, signature, signature + "\n    carmaja_product_maintenance_assert_http($_SERVER['REQUEST_METHOD'] ?? 'GET', $_SERVER['REQUEST_URI'] ?? '/');");
  } else throw new Error('File not allowlisted');
  return text;
}

export function build(output) {
  output = resolve(output);
  if (existsSync(output)) throw new Error('Output must not exist');
  mkdirSync(output, { recursive: true });
  const manifest = { version: 1, files: {} };
  for (const [name, commit] of Object.entries(baseline)) {
    const before = tracked(commit, `website/test-api-private/program/${name}`);
    const after = hotfix(name, before);
    writeFileSync(join(output, name), after);
    manifest.files[name] = { sourceCommit: commit, before: digest(before), after: digest(after) };
  }
  const guard = readFileSync(join(repo, 'website/scripts/product-maintenance-guard.php'), 'utf8').replaceAll('\r\n', '\n');
  writeFileSync(join(output, 'product-maintenance-guard.php'), guard);
  manifest.files['product-maintenance-guard.php'] = { before: null, after: digest(guard) };
  writeFileSync(join(output, 'manifest.json'), JSON.stringify(manifest, null, 2) + '\n');
  return manifest;
}

if (process.argv[1] && resolve(process.argv[1]) === fileURLToPath(import.meta.url)) {
  if (process.argv.length !== 3) throw new Error('Usage: node build-product-maintenance-hotfix.mjs NEW_OUTPUT');
  const manifest = build(process.argv[2]);
  console.log(JSON.stringify({ status: 'built', files: Object.keys(manifest.files) }));
}
