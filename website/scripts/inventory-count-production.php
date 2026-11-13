<?php

declare(strict_types=1);

// Private, separately approved CLI. Never included by API, worker or deploy.
require_once __DIR__ . '/product-model4-production.php';

const CMJ_ICP_TOOL_FILES = [...CMJ_M4P_TOOL_FILES, 'inventory-count-production.php', 'cart-commerce.php', 'production-cart-cutover.php'];
const CMJ_ICP_CHANGED_TABLES = ['commerce_products', 'commerce_inventory', 'inventory_adjustments', 'product_projection_operations'];

function cmj_icp_load_dependencies(string $tool, array $release): void
{
    $names = array_keys($release['toolFiles'] ?? []); sort($names);
    $expected = CMJ_ICP_TOOL_FILES; sort($expected);
    cmj_model4_require($names === $expected, 'release_file_set');
    foreach ($names as $name) {
        cmj_model4_require(hash_file('sha256', cmj_m4p_file($tool . '/' . $name)) === $release['toolFiles'][$name], 'release_file_set');
    }
    foreach (['product-api.php', 'product-api-v2.php', 'product-api-v3.php', 'cart-contract.php', 'production-backup.php', 'production-cart-cutover.php'] as $name) {
        require_once $tool . '/' . $name;
    }
}

/** No row contents/credentials are written to this comparison descriptor. */
function cmj_icp_rows_digest(array $rows): string
{
    return cmj_model4_digest(cmj_icp_row_hashes($rows));
}

function cmj_icp_row_hashes(array $rows): array
{
    $hashes = [];
    foreach ($rows as $row) {
        $encoded = [];
        foreach ($row as $key => $value) $encoded[$key] = $value === null ? null : base64_encode((string) $value);
        $hashes[] = cmj_model4_digest($encoded);
    }
    sort($hashes, SORT_STRING);
    return $hashes;
}

function cmj_icp_preserved(PDO $db, array $selected): array
{
    $ids = array_column($selected, 'productId'); $result = ['unselected' => [], 'history' => [], 'newInventoryRows' => count($ids)];
    foreach (CMJ_ICP_CHANGED_TABLES as $table) {
        $rows = $db->query('SELECT * FROM ' . $table)->fetchAll(PDO::FETCH_ASSOC);
        $result['unselected'][$table] = cmj_icp_rows_digest(array_values(array_filter($rows, static fn ($row) => !in_array($row['product_id'], $ids, true))));
        if (in_array($table, ['inventory_adjustments', 'product_projection_operations'], true)) $result['history'][$table] = cmj_icp_row_hashes($rows);
        if ($table === 'commerce_inventory') $result['newInventoryRows'] -= count(array_filter($rows, static fn ($r) => in_array($r['product_id'], $ids, true)));
    }
    return carmaja_api_canonicalize($result);
}

function cmj_icp_tables(PDO $db): array
{
    if ($db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
        // Artificial local transaction fixture only; CLI never permits SQLite.
        return $db->query("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%' ORDER BY name")->fetchAll(PDO::FETCH_COLUMN);
    }
    $rows = $db->query('SELECT TABLE_NAME,ENGINE,TABLE_TYPE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() ORDER BY TABLE_NAME')->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $row) cmj_model4_require($row['ENGINE'] === 'InnoDB' && $row['TABLE_TYPE'] === 'BASE TABLE', 'innodb_required');
    foreach (['ROUTINES' => 'ROUTINE_SCHEMA', 'EVENTS' => 'EVENT_SCHEMA', 'TRIGGERS' => 'TRIGGER_SCHEMA'] as $table => $column) {
        cmj_model4_require((int) $db->query("SELECT COUNT(*) FROM information_schema.$table WHERE $column=DATABASE()")->fetchColumn() === 0, 'unexpected_database_objects');
    }
    return array_column($rows, 'TABLE_NAME');
}

/** Canonicalize SHOW CREATE formatting, never SQL literals or identifiers. */
function cmj_icp_schema_canonical(string $schema): string
{
    $parts = preg_split('/(\'(?:\'\'|\\\\.|[^\'\\\\])*\'|`(?:``|[^`])*`|"(?:""|\\\\.|[^"\\\\])*")/s',
        $schema, -1, PREG_SPLIT_DELIM_CAPTURE);
    cmj_model4_require(is_array($parts), 'schema_parse_failed');
    foreach ($parts as $index => &$part) {
        if ($index % 2 !== 0) continue;
        // A named collation already fixes its character set. MySQL adds the
        // redundant charset on dump/restore of formerly ALTERed ENUM columns.
        // Only the exactly matching prefix is redundant; different charset,
        // collation, column type/default and all constraints remain significant.
        $part = preg_replace('/ CHARACTER SET ([a-z0-9_]+)(?= COLLATE \1_[a-z0-9_]+\b)/i', '', $part);
        // Allocation counters can advance on rollback; they are not schema.
        $part = preg_replace('/ AUTO_INCREMENT=\d+\b/', '', $part);
    }
    unset($part);
    return implode('', $parts);
}

function cmj_icp_snapshot(PDO $db, bool $lock = false, ?Closure $tick = null): array
{
    $result = [];
    foreach (cmj_icp_tables($db) as $name) {
        if ($tick !== null) $tick();
        cmj_model4_require(preg_match('/^[a-z][a-z0-9_]*$/D', $name) === 1, 'table_name_invalid');
        $mysql = $db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
        if ($mysql) {
            $schema = $db->query('SHOW CREATE TABLE `' . $name . '`')->fetch(PDO::FETCH_NUM)[1];
            $schema = cmj_icp_schema_canonical($schema);
        } else {
            $query = $db->prepare("SELECT sql FROM sqlite_master WHERE name=? AND type='table'");
            $query->execute([$name]); $schema = $query->fetchColumn();
        }
        $order = in_array($name, ['commerce_products', 'commerce_inventory'], true) ? ' ORDER BY product_id' : '';
        $rows = $db->query('SELECT * FROM `' . $name . '`' . $order . ($lock && $mysql ? ' FOR UPDATE' : ''))->fetchAll(PDO::FETCH_ASSOC);
        $result[$name] = ['schema' => hash('sha256', $schema), 'count' => count($rows), 'content' => cmj_icp_rows_digest($rows)];
    }
    return carmaja_api_canonicalize($result);
}

function cmj_icp_drained(PDO $db): void
{
    foreach ([
        'worker_leases' => static fn ($r) => ($r['lease_until'] ?? null) === null && ($r['lease_token'] ?? null) === null,
        'checkout_sagas' => static fn ($r) => in_array($r['state'] ?? null, ['completed', 'failed', 'expired', 'canceled'], true),
        'payments' => static fn ($r) => in_array($r['status'] ?? null, ['failed', 'canceled'], true)
            || (($r['status'] ?? null) === 'succeeded' && ($r['verification_status'] ?? null) === 'verified' && !empty($r['order_id'])),
        'reservations' => static fn ($r) => (string) ($r['blocks_stock'] ?? '') === '0'
            && in_array($r['state'] ?? null, ['released', 'converted', 'expired'], true),
        'webhook_inbox' => static fn ($r) => ($r['status'] ?? null) === 'processed',
        'stripe_metadata_outbox' => static fn ($r) => ($r['status'] ?? null) === 'sent',
        'mail_outbox' => static fn ($r) => ($r['status'] ?? null) === 'sent',
    ] as $table => $terminal) {
        foreach ($db->query('SELECT * FROM ' . $table)->fetchAll(PDO::FETCH_ASSOC) as $row) {
            cmj_model4_require($terminal($row), 'business_not_drained');
        }
    }
}

/** Real port shared by the CLI and the future isolated MySQL/Linux evidence. */
function cmj_icp_restore(array $config, string $id, array $snapshot, array $files): void
{
    $restore = cmj_m4p_connect($config, true);
    cmj_model4_require((int) $restore->query("SELECT GET_LOCK('carmaja-model4-restore',0)")->fetchColumn() === 1, 'restore_busy');
    try {
        cmj_m4p_empty_restore($restore);
        $result = (new CarmajaProductionBackup($config))->restoreDryRun($id,
            static function (PDO $db, string $extract) use ($snapshot, $files): void {
                cmj_model4_require($db->exec("SET SESSION time_zone = '+00:00'") !== false
                    && $db->query('SELECT @@session.time_zone')->fetchColumn() === '+00:00', 'utc_session_required');
                cmj_model4_require(cmj_icp_snapshot($db) === $snapshot, 'backup_source_mismatch');
                foreach ($files as $relative => $hash) {
                    cmj_model4_require(hash_file('sha256', cmj_m4p_file($extract . '/' . $relative)) === $hash, 'backup_source_mismatch');
                }
                $names = array_map('basename', glob($extract . '/drafts/*.json') ?: []); sort($names);
                $expected = array_map('basename', array_values(array_filter(array_keys($files), static fn ($p) => str_starts_with($p, 'drafts/')))); sort($expected);
                cmj_model4_require($names === $expected, 'backup_source_mismatch');
            });
        cmj_model4_require(($result['status'] ?? null) === 'restored_and_compared', 'restore_failed');
        cmj_m4p_empty_restore($restore);
        cmj_model4_require((glob($config['backupDirectory'] . '/.restore/' . $id . '-*') ?: []) === [], 'restore_cleanup_incomplete');
    } finally { $restore->query("SELECT RELEASE_LOCK('carmaja-model4-restore')"); }
}

final class CarmajaInventoryProductionCaller
{
    private array $bound = [];
    private array $approval;
    private array $manifest;
    private array $release;
    private array $snapshot;
    private array $sources;
    private array $preserved;
    private array $products = [];
    private array $drafts = [];
    private array $locks = [];
    private ?float $deadline = null;
    private ?int $backupExpires = null;

    public function __construct(private array $paths, private PDO $db, private array $config, string $approvalHash,
        private Closure $restore, private ?Closure $checkpoint = null, private ?Closure $clock = null)
    {
        cmj_model4_require(!$db->inTransaction() && $db->getAttribute(PDO::ATTR_ERRMODE) === PDO::ERRMODE_EXCEPTION, 'pdo_state');
        foreach (['shop', 'product', 'api', 'tool', 'job'] as $key) cmj_m4_path($paths[$key], true);
        $a = $this->approval = $this->read($paths['job'] . '/approval.json', $approvalHash, true);
        cmj_model4_require(($a['format'] ?? null) === 'inventory-import-approval-v1' && ($a['approved'] ?? null) === true
            && ($a['inventoryImportAllowed'] ?? null) === true && ($a['checkoutActivation'] ?? null) === false
            && ($a['publicationAllowed'] ?? null) === false && ($a['environment'] ?? null) === 'production'
            && preg_match('/^inventory-[a-z0-9-]{8,64}$/D', $a['operationId'] ?? '') === 1
            && $paths['job'] === $paths['product'] . '/audit/' . $a['operationId']
            && is_array($a['actions'] ?? null) && array_is_list($a['actions'])
            && array_diff($a['actions'], ['preflight', 'apply', 'inspect']) === [], 'execution_not_approved');
        $this->bind($paths['runtime'], $a['runtimeSha256'] ?? '', true);
        $this->bind($paths['shop'] . '/config/backup-key.php', $a['keyFileSha256'] ?? '', true);
        $this->bind($paths['shop'] . '/maintenance/product-cleanup.lock', $a['maintenanceSha256'] ?? '');
        $this->runtime();
        cmj_model4_require(cmj_m4_target($paths['product'], $db) === ($a['targetHash'] ?? null), 'target_changed');
        $tool = $this->read($paths['job'] . '/release.json', $a['releaseManifestSha256'] ?? '', true);
        cmj_model4_require(($tool['format'] ?? null) === 'inventory-private-release-v1' && ($tool['workingTreeDirty'] ?? null) === false
            && preg_match('/^[a-f0-9]{40}$/D', $tool['sourceCommit'] ?? '') === 1 && $tool['sourceCommit'] === ($a['releaseSha'] ?? null), 'release_not_clean');
        $names = array_keys($tool['toolFiles'] ?? []); sort($names); $expected = CMJ_ICP_TOOL_FILES; sort($expected);
        cmj_model4_require($names === $expected, 'release_file_set');
        foreach ($tool['toolFiles'] as $name => $hash) $this->bind($paths['tool'] . '/' . $name, $hash);
        $this->release = $this->read($paths['job'] . '/active-package.json', $a['activePackageSha256'] ?? '', true);
        $this->activePackage();
        $this->manifest = $this->read($paths['job'] . '/counts.json', $a['countsSha256'] ?? '', true);
        $this->snapshot = $this->read($paths['job'] . '/before-database.json', $a['databaseSha256'] ?? '', true);
        $this->sources = $this->read($paths['job'] . '/source-files.json', $a['sourcesSha256'] ?? '', true);
        $this->preserved = $this->read($paths['job'] . '/preserved-rows.json', $a['preservedSha256'] ?? '', true);
        cmj_model4_require(($this->manifest['status'] ?? null) === 'not_approved'
            && ($this->manifest['restoreProofSha256'] ?? null) === null, 'prepared_manifest_required');
        cmj_model4_require(($this->manifest['backupId'] ?? null) === ($a['backupId'] ?? null), 'backup_identity');
        $public = $this->read($paths['job'] . '/public-reference.json', $a['publicReferenceSha256'] ?? '', true);
        cmj_model4_require(in_array($public['version'] ?? null, [3, 4], true) && is_array($public['products'] ?? null), 'public_contract');
        $publicIds = array_column($public['products'], 'productId');
        cmj_model4_require(count($publicIds) === count(array_unique($publicIds)), 'public_contract');
        foreach ($this->sources as $relative => $hash) {
            cmj_model4_require(preg_match('#^(drafts/[a-zA-Z0-9-]+\.json|uploads/[a-zA-Z0-9_./-]+\.jpg)$#D', $relative) === 1
                && !str_contains($relative, '..'), 'unsafe_path');
            $this->bind($paths['product'] . '/' . $relative, $hash);
        }
        foreach ($this->manifest['selectedProducts'] ?? [] as $item) {
            carmaja_api_validate_draft_id($item['productId'] ?? '');
            $relative = 'drafts/' . $item['productId'] . '.json';
            cmj_model4_require(isset($this->sources[$relative]) && in_array($item['productId'], $publicIds, true), 'selection_not_public');
            $draft = cmj_m4p_json($paths['product'] . '/' . $relative);
            cmj_model4_require(($draft['status'] ?? null) === 'published' && ($draft['draftId'] ?? null) === $item['productId']
                && ($draft['productModelVersion'] ?? null) === 4, 'source_contract');
            cmj_model4_candidate($draft, $this->manifest['countConfirmedAt']);
            foreach (cmj_m4_images($paths['product'], [$draft]) as $file => $hash) {
                cmj_model4_require(($this->sources[$file] ?? null) === $hash, 'source_image_binding');
            }
            $this->products[] = carmaja_api_v3_product_from_draft($draft);
            $this->drafts[$item['productId']] = $draft;
        }
        $plan = CarmajaCartCutover::plan($this->executionManifest(str_repeat('0', 64)), $this->products, $this->release);
        cmj_model4_require($plan['readyForApply'], 'count_contract_invalid');
        $this->gate();
    }

    private function bind(string $path, string $hash, bool $secret = false): void
    {
        cmj_model4_require(cmj_m4p_hash($hash) && hash_equals($hash, hash_file('sha256', cmj_m4p_file($path, $secret))), 'bound_file_changed');
        $this->bound[$path] = [$hash, $secret];
    }

    private function read(string $path, string $hash, bool $secret = false): array
    {
        $this->bind($path, $hash, $secret);
        return cmj_m4p_json($path, $secret);
    }

    private function now(): int { return $this->clock === null ? time() : ($this->clock)(); }

    private function runtime(): void
    {
        $c = $this->config;
        cmj_model4_require(($c['environment'] ?? null) === 'production' && ($c['publishTarget'] ?? null) === 'production', 'production_runtime_required');
        foreach ([...CMJ_M4P_CLOSED_FLAGS, 'productBrowserProductionEnabled'] as $flag) cmj_model4_require(($c[$flag] ?? null) === false, 'runtime_gate_open');
        foreach (['privateDir' => 'shop', 'productionPrivateDir' => 'shop', 'productPrivateDir' => 'product', 'productionApiWebroot' => 'api'] as $key => $path) {
            cmj_model4_require(($c[$key] ?? null) === $this->paths[$path], 'runtime_path_mismatch');
        }
        cmj_model4_require(($c['backupDirectory'] ?? null) === $this->paths['shop'] . '/backups'
            && ($c['backupEncryptionKeyFile'] ?? null) === $this->paths['shop'] . '/config/backup-key.php'
            && ($c['backupOffsiteTarget'] ?? null) === 'onedrive-pull://carmaja-production/Carmaja-Perlen/Backups', 'runtime_path_mismatch');
        $source = cmj_m4p_dsn($c['commerceDsn'] ?? ''); $restore = cmj_m4p_dsn($c['commerceRestoreDsn'] ?? '');
        cmj_model4_require($source['dbname'] !== $restore['dbname'] && ($c['commerceUser'] ?? null) !== ($c['commerceRestoreUser'] ?? null), 'restore_target_not_separate');
        foreach (['commerce', 'commerceRestore'] as $prefix) {
            cmj_model4_require(($c[$prefix . 'RequireTls'] ?? null) === true, 'tls_required');
            foreach (['User', 'Password'] as $suffix) cmj_model4_require(is_string($c[$prefix . $suffix] ?? null) && $c[$prefix . $suffix] !== '', 'runtime_incomplete');
        }
        CarmajaBackupCipher::decodeKey($c['backupEncryptionKey'] ?? '');
    }

    private function activePackage(): void
    {
        $r = $this->release; $seen = [];
        cmj_model4_require(($r['releaseKind'] ?? null) === 'cart-production' && ($r['environment'] ?? null) === 'production'
            && ($r['workingTreeDirty'] ?? null) === false && is_array($r['files'] ?? null), 'active_package_invalid');
        foreach ($r['files'] as $file) {
            $name = $file['path'] ?? '';
            cmj_model4_require(preg_match('#^[a-zA-Z0-9_./-]+$#D', $name) === 1 && !str_contains($name, '..') && !isset($seen[$name]), 'release_file_set');
            $seen[$name] = true;
            if (str_starts_with($name, 'private/program/')) $path = $this->paths['shop'] . '/' . substr($name, 8);
            elseif (in_array($name, ['public/index.php', 'public/.htaccess'], true)) $path = $this->paths['api'] . '/' . substr($name, 7);
            elseif ($name === 'private/worker.php') $path = $this->paths['shop'] . '/worker.php';
            else continue;
            $this->bind($path, $file['sha256'] ?? '');
        }
        foreach (['bootstrap.php','product-api.php','product-api-v2.php','product-api-v3.php','product-api-v4.php','product-api-v5.php',
            'product-maintenance-guard.php','product-browser.php','product-browser-http.php','commerce-core.php','cart-commerce.php','shop-admin.php','ap3-worker.php'] as $name) {
            cmj_model4_require(isset($seen['private/program/' . $name]), 'release_file_set');
        }
        foreach (['public/index.php','public/.htaccess','private/worker.php'] as $name) cmj_model4_require(isset($seen[$name]), 'release_file_set');
    }

    private function gate(): void
    {
        $this->timeGate();
        foreach ($this->bound as $path => [$hash, $secret]) {
            cmj_model4_require(hash_equals($hash, hash_file('sha256', cmj_m4p_file($path, $secret))), 'bound_file_changed');
        }
        $expected = array_map('basename', array_values(array_filter(array_keys($this->sources), static fn ($p) => str_starts_with($p, 'drafts/')))); sort($expected);
        $actual = array_map('basename', glob($this->paths['product'] . '/drafts/*.json') ?: []); sort($actual);
        cmj_model4_require($expected === $actual, 'source_set_changed');
    }

    private function timeGate(): void
    {
        $a = $this->approval; $now = $this->now();
        $issued = cmj_m4p_time($a['issuedAt'] ?? null); $expires = cmj_m4p_time($a['expiresAt'] ?? null);
        cmj_model4_require($issued <= $now && $now < $expires && $expires - $issued <= 86400, 'approval_expired');
        cmj_model4_require(cmj_m4p_time($this->manifest['countConfirmedAt'] ?? null) <= $issued, 'count_confirmation_invalid');
        cmj_model4_require($this->deadline === null || microtime(true) < $this->deadline, 'inventory_timeout');
        cmj_model4_require($this->backupExpires === null || $now <= $this->backupExpires, 'backup_stale');
    }

    private function executionManifest(string $proof): array
    {
        // Only in memory after a separate inventory-only approval. No stored
        // manifest is promoted, and the adapter never opens any checkout gate.
        return [...$this->manifest, 'status' => 'approved_for_cutover', 'restoreProofSha256' => $proof];
    }

    private function backup(): void
    {
        $a = $this->approval;
        cmj_model4_require(preg_match('/^[0-9]{8}T[0-9]{6}Z-[a-f0-9]{12}$/D', $a['backupId'] ?? '') === 1, 'backup_identity');
        $root = $this->config['backupDirectory'] . '/ready/' . $a['backupId'];
        $m = $this->read($root . '/manifest.json', $a['backupManifestSha256'] ?? '');
        cmj_model4_require(trim(file_get_contents(cmj_m4p_file($root . '/ready'))) === $a['backupManifestSha256'], 'backup_not_ready');
        $created = strtotime($m['createdAt'] ?? '');
        cmj_model4_require($created !== false && $created <= $this->now() && $this->now() - $created <= 5400, 'backup_stale');
        $this->backupExpires = $created + 5400;
        cmj_model4_require(($m['backupId'] ?? null) === $a['backupId'] && ($m['keyId'] ?? null) === $this->config['backupEncryptionKeyId'], 'backup_identity');
        $mac = $m['manifestHmac'] ?? ''; unset($m['manifestHmac']);
        $key = CarmajaBackupCipher::decodeKey($this->config['backupEncryptionKey']);
        $derived = hash_hkdf('sha256', $key, 32, 'carmaja-backup-manifest-v1'); sodium_memzero($key);
        cmj_model4_require(cmj_m4p_hash($mac) && hash_equals($mac, hash_hmac('sha256', cmj_model4_json($m), $derived)), 'backup_authentication');
        sodium_memzero($derived);
        foreach (['commerce' => 'commerce.sql.gz.cmjbkp', 'productData' => 'product-data.tar.gz.cmjbkp'] as $kind => $name) {
            $f = $m['artifacts'][$kind] ?? [];
            cmj_model4_require(($f['file'] ?? null) === $name && ($f['bytes'] ?? null) === filesize(cmj_m4p_file($root . '/' . $name)), 'backup_artifact');
            $this->bind($root . '/' . $name, $f['sha256'] ?? '');
        }
        $ack = cmj_m4p_json($root . '/download-ack.json');
        cmj_model4_require(($ack['backupId'] ?? null) === $a['backupId'] && ($ack['manifestSha256'] ?? null) === $a['backupManifestSha256']
            && strtotime($ack['downloadedAt'] ?? '') >= $created && strtotime($ack['downloadedAt'] ?? '') <= $this->now(), 'offsite_ack_missing');
        foreach (['ready', 'download-ack.json'] as $file) $this->bind($root . '/' . $file, hash_file('sha256', $root . '/' . $file));
    }

    private function lock(): void
    {
        // Existing dedicated private file is an operation mutex. Per-draft locks
        // cooperate with product API; never unlink another process's lock inode.
        $files = [$this->paths['job'] . '/import.lock'];
        $ids = array_column($this->manifest['selectedProducts'], 'productId'); sort($ids);
        foreach ($ids as $id) $files[] = $this->paths['product'] . '/locks/draft-' . $id . '.lock';
        foreach ($files as $file) {
            $h = fopen(cmj_m4p_file($file), 'r');
            cmj_model4_require(is_resource($h), 'lock_unavailable');
            $this->locks[] = $h;
            cmj_model4_require(flock($h, LOCK_EX | LOCK_NB), 'inventory_busy');
        }
    }

    private function rows(string $table, string $column, string $value): array
    {
        $query = $this->db->prepare('SELECT * FROM ' . $table . ' WHERE ' . $column . '=?');
        $query->execute([$value]); return $query->fetchAll(PDO::FETCH_ASSOC);
    }

    private function state(): string
    {
        $done = 0;
        foreach ($this->manifest['selectedProducts'] as $item) {
            $audit = $this->rows('inventory_adjustments', 'idempotency_key', $item['operationId']);
            $inventory = $this->rows('commerce_inventory', 'product_id', $item['productId']);
            if ($audit === []) {
                cmj_model4_require($item['expectedInventoryVersion'] === 0 && ($inventory === []
                    || (count($inventory) === 1 && (int) $inventory[0]['on_hand'] === 0 && (int) $inventory[0]['inventory_version'] === 0
                        && (int) $inventory[0]['quantity_confirmed'] === 0)), 'initial_count_conflict');
                $rows = $this->rows('commerce_products', 'product_id', $item['productId']);
                cmj_model4_require(count($rows) === 1, 'existing_projection_required');
                cmj_model4_check_commerce($this->drafts[$item['productId']], $rows[0]);
                $projectionKey = 'cart-cutover-' . hash('sha256', $item['operationId']);
                cmj_model4_require($this->rows('product_projection_operations', 'operation_id', $projectionKey) === [], 'partial_import');
                continue;
            }
            cmj_model4_require(count($audit) === 1 && count($inventory) === 1, 'inconsistent_import');
            foreach (['product_id' => $item['productId'], 'target_on_hand' => $item['targetOnHand'], 'previous_on_hand' => 0,
                'inventory_version' => 1, 'reason' => 'initial_count', 'correlation_id' => $item['operationId'], 'actor_id' => $this->manifest['actorId']] as $key => $value) {
                cmj_model4_require((string) ($audit[0][$key] ?? '') === (string) $value, 'idempotency_conflict');
            }
            cmj_model4_require((int) $inventory[0]['on_hand'] === $item['targetOnHand'] && (int) $inventory[0]['inventory_version'] === 1
                && (int) $inventory[0]['quantity_confirmed'] === 1, 'inventory_changed_after_import');
            $done++;
        }
        cmj_model4_require($done === 0 || $done === count($this->manifest['selectedProducts']), 'partial_import');
        return $done === 0 ? 'pending' : 'imported';
    }

    private function verifyAfter(array $before): void
    {
        cmj_model4_require($this->state() === 'imported', 'import_comparison_failed');
        $after = cmj_icp_snapshot($this->db, false, fn () => $this->timeGate());
        cmj_model4_require(array_keys($before) === array_keys($after), 'table_set_changed');
        foreach ($before as $table => $entry) {
            cmj_model4_require($entry['schema'] === $after[$table]['schema'], 'schema_changed');
            if (!in_array($table, CMJ_ICP_CHANGED_TABLES, true)) cmj_model4_require($entry === $after[$table], 'unrelated_data_changed');
        }
        foreach ($this->products as $product) {
            $row = $this->rows('commerce_products', 'product_id', $product['productId']);
            cmj_model4_require(count($row) === 1 && $row[0]['sales_model'] === 'stocked_collection'
                && (int) $row[0]['product_version'] === $product['productVersion'] && $row[0]['source_hash'] === $product['sourceHash']
                && (int) $row[0]['sales_enabled'] === (int) $product['salesEnabled'], 'projection_comparison_failed');
            cmj_model4_check_commerce($this->drafts[$product['productId']], [...$row[0], 'sales_model' => 'collection']);
            $fields = ['productId','productModelVersion','productVersion','sourceHash','sku','slug','title','description','descriptionDocument',
                'materials','metalElements','braceletSizeCm','pearlSizeMm','careInstructions','images','priceMinor','currency','noticePolicyId','notices'];
            cmj_model4_require(cmj_model4_json(json_decode($row[0]['public_snapshot'], true, 64, JSON_THROW_ON_ERROR))
                === cmj_model4_json(array_intersect_key($product, array_fill_keys($fields, true))), 'projection_comparison_failed');
            cmj_model4_require(cmj_model4_json(json_decode($row[0]['notice_snapshot'], true, 64, JSON_THROW_ON_ERROR))
                === cmj_model4_json(['noticePolicyId' => $product['noticePolicyId'] ?? null, 'notices' => $product['notices'] ?? []]), 'notice_comparison_failed');
            $item = array_values(array_filter($this->manifest['selectedProducts'], static fn ($s) => $s['productId'] === $product['productId']))[0];
            $projection = $this->rows('product_projection_operations', 'operation_id', 'cart-cutover-' . hash('sha256', $item['operationId']));
            cmj_model4_require(count($projection) === 1 && $projection[0]['product_id'] === $product['productId']
                && $projection[0]['action'] === 'publish' && $projection[0]['request_hash'] === hash('sha256', carmaja_commerce_json($product)), 'projection_audit_missing');
        }
        $count = count($this->products);
        foreach (['inventory_adjustments', 'product_projection_operations'] as $table) {
            cmj_model4_require($after[$table]['count'] === $before[$table]['count'] + $count, 'audit_count_mismatch');
        }
        cmj_model4_require($after['commerce_products']['count'] === $before['commerce_products']['count'], 'product_set_changed');
        cmj_model4_require($after['commerce_inventory']['count'] === $before['commerce_inventory']['count'] + $this->preserved['newInventoryRows'], 'inventory_set_changed');
        $current = cmj_icp_preserved($this->db, $this->manifest['selectedProducts']);
        cmj_model4_require($current['unselected'] === $this->preserved['unselected'], 'unselected_data_changed');
        foreach ($this->preserved['history'] as $table => $hashes) {
            $remaining = array_count_values($current['history'][$table]);
            foreach ($hashes as $hash) {
                cmj_model4_require(($remaining[$hash] ?? 0) > 0, 'historical_audit_changed'); $remaining[$hash]--;
            }
        }
    }

    private function restoreReceipt(): string
    {
        $receipt = ['format' => 'inventory-restore-receipt-v1', 'operationId' => $this->approval['operationId'],
            'approvalSha256' => hash_file('sha256', $this->paths['job'] . '/approval.json'),
            'backupId' => $this->approval['backupId'], 'backupManifestSha256' => $this->approval['backupManifestSha256'],
            'databaseSha256' => $this->approval['databaseSha256'], 'sourcesSha256' => $this->approval['sourcesSha256'],
            'status' => 'restored_compared_and_cleaned', 'verifiedAt' => gmdate('Y-m-d\TH:i:s\Z', $this->now())];
        $key = CarmajaBackupCipher::decodeKey($this->config['backupEncryptionKey']);
        $derived = hash_hkdf('sha256', $key, 32, 'carmaja-inventory-restore-v1'); sodium_memzero($key);
        $receipt['hmac'] = hash_hmac('sha256', cmj_model4_json($receipt), $derived); sodium_memzero($derived);
        $raw = cmj_model4_json($receipt) . "\n"; $hash = hash('sha256', $raw);
        $file = $this->paths['job'] . '/restore-receipt-' . $hash . '.json';
        if (file_exists($file) || is_link($file)) {
            cmj_model4_require(hash_file('sha256', cmj_m4p_file($file, true)) === $hash, 'receipt_conflict');
            $this->bind($file, $hash, true);
            return $hash;
        }
        $temporary = $file . '.tmp-' . bin2hex(random_bytes(8));
        $handle = fopen($temporary, 'x'); cmj_model4_require(is_resource($handle), 'receipt_write_failed');
        try {
            cmj_model4_require(chmod($temporary, 0600) && fwrite($handle, $raw) === strlen($raw)
                && fflush($handle) && fsync($handle), 'receipt_write_failed');
            fclose($handle); $handle = null;
            cmj_model4_require(rename($temporary, $file), 'receipt_write_failed');
        } finally {
            if (is_resource($handle)) fclose($handle);
            if (file_exists($temporary)) unlink($temporary);
        }
        $this->bind($file, $hash, true);
        return $hash;
    }

    public function run(string $action): array
    {
        cmj_model4_require(in_array($action, $this->approval['actions'], true), 'action_not_approved');
        $this->gate(); cmj_icp_drained($this->db); CarmajaCartCutover::assertJournal($this->db, $this->release, true);
        $state = $this->state();
        if ($state === 'imported') {
            // Crash after commit / duplicate invocation: read-only, never reset
            // quantities, project again, create audit or perform a restore.
            $this->verifyAfter($this->snapshot);
            return ['status' => 'already_imported', 'checkoutActivation' => false, 'readOnly' => true];
        }
        cmj_model4_require(cmj_icp_snapshot($this->db) === $this->snapshot, 'database_changed');
        cmj_model4_require(cmj_icp_preserved($this->db, $this->manifest['selectedProducts']) === $this->preserved, 'preserved_rows_changed');
        $legal = $this->db->prepare('SELECT status,bundle_hash FROM legal_bundles WHERE legal_bundle_id=?');
        $legal->execute([CarmajaCartCutover::LEGAL_ID]); $bundle = $legal->fetch(PDO::FETCH_ASSOC);
        cmj_model4_require(is_array($bundle) && $bundle['status'] === 'approved'
            && hash_equals($this->manifest['legalBundleHash'], $bundle['bundle_hash']), 'approved_legal_bundle_missing');
        if ($action !== 'apply') {
            $this->backup(); $this->gate();
            return ['status' => 'preflight_passed', 'restoreStillRequired' => true, 'checkoutActivation' => false];
        }
        try {
            $this->lock();
            return cmj_m4p_with_backup_lock($this->config['backupDirectory'], function (): array {
                $this->backup(); $this->gate();
                // All restore/decrypt/file/subprocess I/O completes BEFORE SQL locks.
                ($this->restore)($this->config, $this->approval['backupId'], $this->snapshot, $this->sources);
                $this->gate(); $this->backup();
                $proof = $this->restoreReceipt();
                $this->deadline = microtime(true) + 40;
                $this->db->exec('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
                $result = CarmajaCartCutover::importCounts($this->db, $this->config, $this->executionManifest($proof), $this->products, $this->release,
                    function (string $phase): void {
                        if ($this->checkpoint !== null) ($this->checkpoint)($phase);
                        $this->gate();
                        if ($phase === 'before_import') {
                            cmj_model4_require(cmj_icp_snapshot($this->db, true, fn () => $this->timeGate()) === $this->snapshot, 'database_changed');
                            cmj_icp_drained($this->db);
                            cmj_model4_require($this->state() === 'pending', 'concurrent_import');
                            cmj_model4_require(cmj_icp_preserved($this->db, $this->manifest['selectedProducts']) === $this->preserved, 'preserved_rows_changed');
                        } else {
                            $this->verifyAfter($this->snapshot);
                        }
                        $this->gate();
                    });
                if ($this->checkpoint !== null) ($this->checkpoint)('after_commit');
                return ['status' => 'inventory_imported_and_compared', ...$result, 'authoritativeProductsChanged' => false];
            });
        } finally {
            $this->deadline = null;
            foreach (array_reverse($this->locks) as $h) { flock($h, LOCK_UN); fclose($h); }
            $this->locks = [];
        }
    }
}

function cmj_icp_main(array $argv): int
{
    ini_set('display_errors', '0'); ini_set('log_errors', '0'); ini_set('zend.exception_ignore_args', '1');
    $level = ob_get_level(); ob_start();
    set_error_handler(static function (): never { throw new RuntimeException('private_io_failed'); });
    try {
        cmj_model4_require(PHP_SAPI === 'cli' && PHP_OS_FAMILY === 'Linux' && PHP_MAJOR_VERSION === 8 && PHP_MINOR_VERSION === 4, 'php84_linux_cli_required');
        cmj_model4_require(count($argv) === 4 && in_array($argv[1], ['preflight', 'apply', 'inspect'], true)
            && preg_match('/^inventory-[a-z0-9-]{8,64}$/D', $argv[2]) === 1 && cmj_m4p_hash($argv[3]), 'explicit_bound_action_required');
        $shop = '/home/www/carmaja-private-shop'; $product = '/home/www/carmaja-private-production';
        $paths = ['shop' => $shop, 'product' => $product, 'api' => '/home/www/carmaja-production-api', 'tool' => $shop . '/inventory-tool',
            'runtime' => $shop . '/config/runtime-config.php', 'job' => $product . '/audit/' . $argv[2]];
        cmj_model4_require(cmj_m4_path(__DIR__, true) === $paths['tool'], 'private_cli_path_required');
        $approval = cmj_m4p_json($paths['job'] . '/approval.json', true);
        cmj_model4_require(hash_file('sha256', $paths['job'] . '/approval.json') === $argv[3], 'approval_binding');
        $release = cmj_m4p_json($paths['job'] . '/release.json', true);
        cmj_model4_require(hash_file('sha256', $paths['job'] . '/release.json') === ($approval['releaseManifestSha256'] ?? null), 'release_binding');
        cmj_icp_load_dependencies($paths['tool'], $release);
        cmj_model4_require(hash_file('sha256', cmj_m4p_file($paths['runtime'], true)) === ($approval['runtimeSha256'] ?? null), 'runtime_binding');
        cmj_model4_require(hash_file('sha256', cmj_m4p_file($shop . '/config/backup-key.php', true)) === ($approval['keyFileSha256'] ?? null), 'key_file_binding');
        $raw = (static fn ($file) => require $file)($paths['runtime']);
        cmj_model4_require(is_array($raw) && ($raw['backupEncryptionKeyFile'] ?? null) === $shop . '/config/backup-key.php', 'key_file_binding');
        unset($raw);
        $config = CarmajaProductionBackup::loadRuntime($paths['runtime']);
        $caller = new CarmajaInventoryProductionCaller($paths, cmj_m4p_connect($config), $config, $argv[3], cmj_icp_restore(...));
        $result = $caller->run($argv[1]);
        while (ob_get_level() > $level) ob_end_clean();
        fwrite(STDOUT, json_encode($result, JSON_THROW_ON_ERROR) . "\n"); return 0;
    } catch (Throwable) {
        fwrite(STDERR, "INVENTORY_REFUSED: bound prerequisites failed; inspect before retry; all sales gates remain closed.\n"); return 2;
    } finally {
        while (ob_get_level() > $level) ob_end_clean();
        restore_error_handler();
    }
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
    exit(cmj_icp_main($_SERVER['argv'] ?? []));
}
