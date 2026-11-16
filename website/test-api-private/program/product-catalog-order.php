<?php
declare(strict_types=1);

/** Catalogue presentation only. Never writes drafts, product hashes or Commerce. */
final class CarmajaCatalogOrder
{
    public const REPO_PATH = 'website/content/product-order.json';

    public static function validate(mixed $order): array
    {
        if (!is_array($order) || count($order) !== 2 || ($order['version'] ?? null) !== 1
            || !array_key_exists('productIds', $order)) {
            throw new CarmajaApiException(422, 'Reihenfolge ist ungültig.', [], 'catalog_order_invalid');
        }
        $ids = $order['productIds'];
        if ($ids !== null) {
            if (!is_array($ids) || !array_is_list($ids) || count($ids) > 1000) {
                throw new CarmajaApiException(422, 'Reihenfolge ist ungültig.', [], 'catalog_order_invalid');
            }
            foreach ($ids as $id) {
                if (!is_string($id)) throw new CarmajaApiException(422, 'Produktkennung fehlt.', [], 'catalog_order_invalid');
                carmaja_api_validate_draft_id($id);
            }
            if (count(array_unique($ids)) !== count($ids)) {
                throw new CarmajaApiException(422, 'Doppelte Produktkennung.', [], 'catalog_order_invalid');
            }
        }
        return $order;
    }

    private static function remoteDocument(string $prefix, string $path, string $sha): array
    {
        $content = carmaja_api_github_request('GET', $prefix . '/contents/' . $path . '?ref=' . $sha);
        $raw = base64_decode((string) ($content['content'] ?? ''), true);
        $value = is_string($raw) ? json_decode($raw, true, 64, JSON_THROW_ON_ERROR) : null;
        if (!is_array($value)) throw new RuntimeException('catalog_document_invalid');
        return $value;
    }

    private static function snapshot(bool $remote): array
    {
        if ($remote) {
            carmaja_api_require_github_adapter_enabled();
            $prefix = '/repos/' . carmaja_api_github_repository();
            $branch = carmaja_api_github_branch();
            $head = carmaja_api_github_ref_head($prefix, $branch);
            // Both files from the SAME immutable commit, not two moving branch reads.
            $document = self::remoteDocument($prefix, 'website/content/products.json', $head);
            $order = self::remoteDocument($prefix, self::REPO_PATH, $head);
        } else {
            $document = carmaja_api_read_json(carmaja_api_path('products/public-products-v2.json'), ['version'=>4, 'products'=>[]]);
            $order = carmaja_api_read_json(carmaja_api_path('products/catalog-order.json'), ['version'=>1, 'productIds'=>null]);
        }
        self::validate($order);
        if (!in_array($document['version'] ?? null, [3, 4], true) || !is_array($document['products'] ?? null)
            || !array_is_list($document['products'])) throw new RuntimeException('catalog_projection_invalid');
        $products = [];
        foreach ($document['products'] as $product) {
            $id = $product['productId'] ?? null;
            if (!is_string($id) || isset($products[$id]) || !is_string($product['title'] ?? null)
                || !is_string($product['sku'] ?? null) || !is_string($product['updatedAt'] ?? null)) {
                throw new RuntimeException('catalog_projection_invalid');
            }
            carmaja_api_validate_draft_id($id);
            $products[$id] = $product;
        }
        if ($order['productIds'] === null) {
            // Match the previous website until the first explicit manual save.
            uasort($products, static fn($a, $b) => strcmp($b['updatedAt'], $a['updatedAt']));
            $ids = array_keys($products);
        } else {
            $ids = array_values(array_filter($order['productIds'], static fn($id) => isset($products[$id])));
            // Publishers update in place and append new products. Never use updatedAt here.
            $ids = array_merge($ids, array_values(array_diff(array_keys($products), $ids)));
        }
        $view = ['revision'=>carmaja_api_request_hash(['projection'=>$document, 'order'=>$order]),
            'products'=>array_map(static fn($id) => ['productId'=>$id, 'sku'=>$products[$id]['sku'], 'name'=>$products[$id]['title']], $ids)];
        return ['view'=>$view, 'order'=>$order, 'head'=>$head ?? null, 'prefix'=>$prefix ?? null, 'branch'=>$branch ?? null];
    }

    public static function read(bool $remote): array
    {
        return carmaja_api_with_lock('catalog-projection', static fn() => self::snapshot($remote)['view']);
    }

    public static function save(bool $remote, array $body, array $actor, string $key, Closure $guard): array
    {
        carmaja_api_reject_unknown_fields($body, ['expectedRevision', 'productIds']);
        if (!is_string($body['expectedRevision'] ?? null) || preg_match('/^[a-f0-9]{64}$/D', $body['expectedRevision']) !== 1
            || !is_array($body['productIds'] ?? null)) throw new CarmajaApiException(422, 'Reihenfolge bitte neu laden.', [], 'catalog_order_invalid');
        self::validate(['version'=>1, 'productIds'=>$body['productIds']]);
        $key = carmaja_api_v2_validate_idempotency_key($key);
        if ($remote) carmaja_api_require_github_adapter_enabled();
        if (function_exists('carmaja_product_maintenance_assert')) carmaja_product_maintenance_assert('publisher');
        foreach ($body['productIds'] as $id) $guard($id, null);
        // Bind retries to the user, not the short-lived browser session.
        $operation = 'catalog-' . carmaja_api_request_hash(['username'=>$actor['username'] ?? '', 'key'=>$key]);
        $requestHash = carmaja_api_request_hash($body);
        $path = carmaja_api_path('products/operations/' . $operation . '.json');
        return carmaja_api_with_lock('catalog-projection', static function () use ($remote, $body, $operation, $requestHash, $path, $actor): array {
            if (function_exists('carmaja_product_maintenance_assert')) carmaja_product_maintenance_assert('publisher');
            $state = is_file($path) ? carmaja_api_read_target_json($path, [], 'Katalogreihenfolge') : [];
            if ($state !== [] && ($state['requestHash'] ?? null) !== $requestHash) {
                throw new CarmajaApiException(409, 'Vorgang wurde mit anderen Angaben wiederholt.', [], 'catalog_order_conflict');
            }
            if (isset($state['result'])) return $state['result'];
            if (!isset($state['order'])) {
                $snapshot = self::snapshot($remote);
                $existing = array_column($snapshot['view']['products'], 'productId');
                $requested = $body['productIds'];
                $left = $existing; $right = $requested; sort($left); sort($right);
                if (!hash_equals($snapshot['view']['revision'], $body['expectedRevision']) || $left !== $right) {
                    throw new CarmajaApiException(409, 'Katalog wurde inzwischen geändert. Bitte neu laden.', [], 'catalog_order_conflict');
                }
                $state = carmaja_api_target_document(['requestHash'=>$requestHash,
                    'order'=>['version'=>1, 'productIds'=>$requested], 'baseHeadSha'=>$snapshot['head']]);
                if ($remote) {
                    $prefix = $snapshot['prefix'];
                    $commit = carmaja_api_github_request('GET', $prefix . '/git/commits/' . $snapshot['head']);
                    $tree = carmaja_api_github_request('POST', $prefix . '/git/trees', [
                        'base_tree'=>$commit['tree']['sha'], 'tree'=>[['path'=>self::REPO_PATH, 'mode'=>'100644', 'type'=>'blob',
                            'content'=>json_encode($state['order'], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n"]]]);
                    $prepared = carmaja_api_github_request('POST', $prefix . '/git/commits', [
                        'message'=>"Carmaja collection reorder\n\nCarmaja-Publisher: catalog-order-v1\nCarmaja-Operation: " . $operation
                            . "\nCarmaja-Request-SHA256: " . $requestHash,
                        'tree'=>$tree['sha'], 'parents'=>[$snapshot['head']]]);
                    $state['preparedCommitSha'] = $prepared['sha'];
                }
                // Persist BEFORE ref update / local activation: recover a lost response safely.
                carmaja_api_write_json_atomic($path, $state);
            }
            if ($remote) {
                $sha = carmaja_api_complete_prepared_github_commit('/repos/' . carmaja_api_github_repository(),
                    carmaja_api_github_branch(), $state['baseHeadSha'], $state['preparedCommitSha']);
            } else {
                $current = self::snapshot(false);
                if (!hash_equals($current['view']['revision'], $body['expectedRevision']) && $current['order'] !== $state['order']) {
                    throw new CarmajaApiException(409, 'Katalog wurde inzwischen geändert.', [], 'catalog_order_conflict');
                }
                carmaja_api_write_json_atomic(carmaja_api_path('products/catalog-order.json'), $state['order']);
            }
            $result = ['saved'=>true, 'publication'=>['commitSha'=>$sha ?? null, 'deploymentStatus'=>$remote ? 'pending' : 'not_started']];
            carmaja_api_write_json_atomic($path, [...$state, 'result'=>$result]);
            carmaja_api_audit_best_effort('catalog_order_saved', ['deviceId'=>$actor['tokenId'] ?? null,
                'operationId'=>$operation, 'result'=>'success']);
            return $result;
        });
    }
}
