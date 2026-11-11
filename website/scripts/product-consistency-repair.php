<?php

declare(strict_types=1);

// Private maintenance adapter. No router, publisher, inventory write or automatic unlock.
final class CarmajaProductRepairError extends RuntimeException {}
function cmj_repair_assert(bool $condition, string $code): void
{
    if (!$condition) throw new CarmajaProductRepairError($code);
}
function cmj_repair_json(mixed $value): string
{
    return json_encode(carmaja_api_canonicalize($value), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
}
function cmj_repair_digest(mixed $value): string { return hash('sha256', cmj_repair_json($value)); }
function cmj_repair_row(array $row): array
{
    foreach ($row as $key => $value) {
        if (in_array($key, ['materials', 'metal_elements', 'images'], true)) {
            $row[$key] = is_string($value) ? json_decode($value, true, 64, JSON_THROW_ON_ERROR) : $value;
        } elseif ($value !== null) $row[$key] = (string) $value;
    }
    return carmaja_api_canonicalize($row);
}
function cmj_repair_metadata(array $draft): array
{
    $product = carmaja_api_v3_product_from_draft($draft);
    return ['name' => $product['name'], 'description' => $product['description'],
        'materials' => $product['materials'], 'metal_elements' => $product['metalElements'],
        'bracelet_size' => (string) $product['braceletSizeCm'],
        'care_instructions' => implode("\n", $product['careInstructions']),
        'price_minor' => (string) $product['priceMinor'], 'currency' => $product['currency'],
        'sales_enabled' => (string) (int) $product['salesEnabled']];
}
function cmj_repair_plan(array $records, array $public, string $operationId, string $at): array
{
    cmj_repair_assert(preg_match('/^product-repair-[a-z0-9-]{12,70}$/', $operationId) === 1, 'operation_invalid');
    cmj_repair_assert(preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $at) === 1, 'time_invalid');
    $publicById = array_column($public['products'], null, 'productId');
    $entries = []; $unchanged = []; $seen = [];
    foreach ($records as $record) {
        $raw = $record['raw'];
        $draft = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
        $id = $draft['draftId'] ?? ''; $sku = $draft['sku'] ?? '';
        carmaja_api_validate_draft_id($id);
        cmj_repair_assert(!isset($seen[$id]) && !isset($seen[$sku]), 'duplicate_product');
        $seen[$id] = true; $seen[$sku] = true;
        cmj_repair_assert(($draft['productModelVersion'] ?? null) === 3 && ($draft['currency'] ?? '') === 'eur'
            && is_int($draft['priceMinor'] ?? null) && $draft['priceMinor'] > 0
            && is_int($draft['version'] ?? null) && $draft['version'] > 0
            && is_int($draft['productVersion'] ?? null) && $draft['productVersion'] > 0
            && in_array($draft['status'] ?? '', ['published', 'disabled'], true)
            && is_bool($draft['salesEnabled'] ?? null), 'source_contract');
        cmj_repair_assert(($draft['status'] === 'published') === $draft['salesEnabled'], 'source_availability');
        $product = carmaja_api_v3_product_from_draft($draft);
        $normalizedDescription = carmaja_api_v3_validate_description_document($draft['descriptionDocument']);
        cmj_repair_assert($normalizedDescription['plainText'] === $product['description'], 'source_text_conflict');
        $row = cmj_repair_row($record['row']);
        cmj_repair_assert(($row['product_id'] ?? '') === $id && ($row['sales_model'] ?? '') === 'collection', 'commerce_identity');
        $expected = cmj_repair_metadata($draft); $differences = [];
        foreach ($expected as $field => $value) if (cmj_repair_json($row[$field] ?? null) !== cmj_repair_json($value)) $differences[] = $field;
        $publicImages = carmaja_api_v2_public_product_from_draft($draft)['images'];
        cmj_repair_assert(cmj_repair_json($row['images']) === cmj_repair_json($product['images'])
            || cmj_repair_json($row['images']) === cmj_repair_json($publicImages), 'commerce_image_conflict');
        if ($sku === 'CP-2026-0008') {
            cmj_repair_assert($product['priceMinor'] === 3250 && array_diff($differences, ['price_minor', 'description']) === [], 'confirmed_content_conflict');
        } else cmj_repair_assert($differences === [], 'unapproved_content_conflict');
        $storedValid = ($draft['sourceHash'] ?? '') === $product['sourceHash'];
        if (!$storedValid) {
            $old = $draft; $old['salesEnabled'] = !$old['salesEnabled'];
            cmj_repair_assert(($draft['sourceHash'] ?? '') === carmaja_api_v3_product_from_draft($old)['sourceHash'], 'unknown_hash_conflict');
        }
        $publicVersion = (int) ($publicById[$id]['productVersion'] ?? 0);
        cmj_repair_assert($publicVersion <= max($product['productVersion'], (int) $row['product_version']), 'public_version_ahead');
        if ($storedValid && $differences === [] && (int) $row['product_version'] === $product['productVersion']
            && $row['source_hash'] === $product['sourceHash']) { $unchanged[] = $sku; continue; }
        $afterDraft = $draft;
        $afterDraft['productVersion'] = max($product['productVersion'], (int) $row['product_version'], $publicVersion) + 1;
        $afterDraft['version']++;
        $afterDraft['updatedAt'] = $at;
        $afterDraft['sourceHash'] = carmaja_api_v3_product_from_draft($afterDraft)['sourceHash'];
        $afterRow = $row;
        $afterRow['product_version'] = (string) $afterDraft['productVersion'];
        $afterRow['source_hash'] = $afterDraft['sourceHash'];
        $afterRow['synchronized_at'] = str_replace(['T', 'Z'], [' ', '.000000'], $at);
        $afterRow['updated_at'] = $afterRow['synchronized_at'];
        if ($sku === 'CP-2026-0008') {
            $afterRow['price_minor'] = '3250'; $afterRow['description'] = $product['description'];
        }
        $afterRaw = json_encode($afterDraft, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";
        $entries[] = ['productId' => $id, 'sku' => $sku, 'beforeRaw' => base64_encode($raw), 'afterRaw' => base64_encode($afterRaw),
            'beforeHash' => hash('sha256', $raw), 'afterHash' => hash('sha256', $afterRaw),
            'beforeRow' => $row, 'afterRow' => carmaja_api_canonicalize($afterRow),
            'hashRepair' => !$storedValid, 'businessDifferences' => $differences];
    }
    usort($entries, static fn (array $a, array $b): int => strcmp($a['productId'], $b['productId']));
    sort($unchanged);
    return ['format' => 1, 'operationId' => $operationId, 'at' => $at, 'entries' => $entries, 'unchanged' => $unchanged,
        'publicReference' => $public, 'publicationAllowed' => false];
}
function cmj_repair_validate_manifest(array $plan): void
{
    cmj_repair_assert(($plan['format'] ?? null) === 1 && ($plan['publicationAllowed'] ?? null) === false, 'manifest_invalid');
    $records = [];
    foreach ($plan['entries'] as $entry) {
        $raw = base64_decode($entry['beforeRaw'], true);
        cmj_repair_assert(is_string($raw) && hash('sha256', $raw) === $entry['beforeHash'], 'manifest_corrupt');
        $records[] = ['raw' => $raw, 'row' => $entry['beforeRow']];
    }
    $recomputed = cmj_repair_plan($records, $plan['publicReference'], $plan['operationId'], $plan['at']);
    cmj_repair_assert(cmj_repair_json($recomputed['entries']) === cmj_repair_json($plan['entries']), 'manifest_target_tampered');
}
function cmj_repair_atomic(string $path, string $bytes, int $mode): void
{
    cmj_repair_assert(!is_link($path), 'unsafe_file');
    $temporary = $path . '.repair-next-' . bin2hex(random_bytes(6));
    $h = fopen($temporary, 'x');
    cmj_repair_assert(is_resource($h), 'write_failed');
    try {
        cmj_repair_assert(chmod($temporary, $mode), 'mode_failed');
        for ($offset = 0; $offset < strlen($bytes); $offset += $written) {
            $written = fwrite($h, substr($bytes, $offset));
            cmj_repair_assert(is_int($written) && $written > 0, 'write_failed');
        }
        cmj_repair_assert(fflush($h) && fsync($h), 'flush_failed');
        fclose($h); $h = null;
        cmj_repair_assert(rename($temporary, $path), 'rename_failed');
    } finally { if (is_resource($h)) fclose($h); if (is_file($temporary)) unlink($temporary); }
}
function cmj_repair_file_states(string $draftRoot, array $plan): array
{
    $states = [];
    foreach ($plan['entries'] as $entry) {
        $path = $draftRoot . '/' . $entry['productId'] . '.json';
        cmj_repair_assert(str_replace('\\', '/', realpath($path) ?: '') === $path && !is_link($path), 'unsafe_source');
        $actual = hash_file('sha256', $path);
        cmj_repair_assert(in_array($actual, [$entry['beforeHash'], $entry['afterHash']], true), 'source_changed');
        $states[$entry['productId']] = $actual === $entry['afterHash'] ? 'after' : 'before';
    }
    return $states;
}
function cmj_repair_current_row(PDO $pdo, string $id, bool $lock): array
{
    $sql = 'SELECT * FROM commerce_products WHERE product_id = ?';
    if ($lock && $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') $sql .= ' FOR UPDATE';
    $statement = $pdo->prepare($sql); $statement->execute([$id]);
    $row = $statement->fetch(PDO::FETCH_ASSOC);
    cmj_repair_assert(is_array($row), 'commerce_missing');
    return cmj_repair_row($row);
}
function cmj_repair_db_states(PDO $pdo, array $plan, bool $lock): array
{
    $states = [];
    foreach ($plan['entries'] as $entry) {
        $actual = cmj_repair_json(cmj_repair_current_row($pdo, $entry['productId'], $lock));
        cmj_repair_assert(in_array($actual, [cmj_repair_json($entry['beforeRow']), cmj_repair_json($entry['afterRow'])], true), 'commerce_changed');
        $states[$entry['productId']] = $actual === cmj_repair_json($entry['afterRow']) ? 'after' : 'before';
    }
    cmj_repair_assert(count(array_unique($states)) <= 1, 'partial_database_state');
    return $states;
}
function cmj_repair_apply(string $private, string $job, PDO $pdo, array $plan, callable $guard, ?callable $checkpoint = null): array
{
    cmj_repair_validate_manifest($plan);
    foreach ([$private, $private . '/drafts', $private . '/locks', $job] as $path) {
        cmj_repair_assert(str_replace('\\', '/', realpath($path) ?: '') === $path && !is_link($path), 'unsafe_directory');
    }
    $locks = [];
    try {
        $lockPaths = [$job . '/repair.lock'];
        foreach ($plan['entries'] as $entry) $lockPaths[] = $private . '/locks/draft-' . $entry['productId'] . '.lock';
        foreach ($lockPaths as $path) {
            cmj_repair_assert(!is_link($path), 'unsafe_lock');
            $h = fopen($path, 'c'); cmj_repair_assert(is_resource($h), 'lock_failed');
            $locks[] = $h;
            cmj_repair_assert(flock($h, LOCK_EX | LOCK_NB), 'repair_busy');
        }
        $guard();
        $files = cmj_repair_file_states($private . '/drafts', $plan);
        $db = cmj_repair_db_states($pdo, $plan, false);
        cmj_repair_assert(!in_array('after', $db, true) || !in_array('before', $files, true), 'invalid_recovery_state');
        $journal = $job . '/journal.json';
        $binding = cmj_repair_digest($plan);
        if (file_exists($journal)) {
            cmj_repair_assert(!is_link($journal), 'unsafe_journal');
            $previous = json_decode(file_get_contents($journal), true, 16, JSON_THROW_ON_ERROR);
            cmj_repair_assert(($previous['manifestDigest'] ?? '') === $binding, 'journal_conflict');
        } else {
            cmj_repair_assert(!in_array('after', $files, true) && !in_array('after', $db, true), 'unrecorded_recovery');
        }
        $writeJournal = static function (string $phase) use ($journal, $binding): void {
            cmj_repair_atomic($journal, cmj_repair_json(['manifestDigest' => $binding, 'phase' => $phase]) . "\n", 0600);
        };
        $writeJournal('applying');
        foreach ($plan['entries'] as $entry) {
            $guard();
            $path = $private . '/drafts/' . $entry['productId'] . '.json';
            if ($files[$entry['productId']] === 'before') {
                cmj_repair_assert(hash_file('sha256', $path) === $entry['beforeHash'], 'source_changed');
                cmj_repair_atomic($path, base64_decode($entry['afterRaw'], true), fileperms($path) & 0777);
            }
            if ($checkpoint !== null) $checkpoint('source:' . $entry['productId']);
        }
        $guard();
        cmj_repair_assert(!in_array('before', cmj_repair_file_states($private . '/drafts', $plan), true), 'source_write_incomplete');
        $pdo->beginTransaction();
        try {
            $db = cmj_repair_db_states($pdo, $plan, true);
            foreach ($plan['entries'] as $entry) if ($db[$entry['productId']] === 'before') {
                $columns = ['product_version', 'source_hash', 'synchronized_at', 'updated_at'];
                if ($entry['sku'] === 'CP-2026-0008') array_push($columns, 'price_minor', 'description');
                $sql = 'UPDATE commerce_products SET ' . implode(', ', array_map(static fn (string $c): string => $c . ' = ?', $columns)) . ' WHERE product_id = ?';
                $values = array_map(static fn (string $c): mixed => $entry['afterRow'][$c], $columns);
                $values[] = $entry['productId'];
                $pdo->prepare($sql)->execute($values);
                cmj_repair_assert(cmj_repair_json(cmj_repair_current_row($pdo, $entry['productId'], false)) === cmj_repair_json($entry['afterRow']), 'database_write_mismatch');
                if ($checkpoint !== null) $checkpoint('sql:' . $entry['productId']);
            }
            if ($checkpoint !== null) $checkpoint('before_commit');
            $pdo->commit();
        } catch (Throwable $error) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $error; }
        if ($checkpoint !== null) $checkpoint('after_commit');
        $guard();
        $audit = ['operationId' => $plan['operationId'], 'at' => $plan['at'], 'action' => 'product_metadata_repair',
            'manifestDigest' => $binding, 'publication' => false, 'inventoryChanged' => false,
            'products' => array_map(static function (array $e): array {
                return ['productId' => $e['productId'], 'sku' => $e['sku'], 'beforeVersion' => $e['beforeRow']['product_version'],
                    'afterVersion' => $e['afterRow']['product_version'], 'beforeHash' => $e['beforeHash'], 'afterHash' => $e['afterHash']];
            }, $plan['entries'])];
        $auditPath = $job . '/audit.json'; $auditRaw = cmj_repair_json($audit) . "\n";
        if (file_exists($auditPath)) cmj_repair_assert(!is_link($auditPath) && file_get_contents($auditPath) === $auditRaw, 'audit_conflict');
        else cmj_repair_atomic($auditPath, $auditRaw, 0600);
        $writeJournal('complete');
        return ['status' => 'private_repair_complete', 'products' => count($plan['entries']), 'publication' => false];
    } finally { foreach (array_reverse($locks) as $h) { flock($h, LOCK_UN); fclose($h); } }
}
