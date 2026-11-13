<?php

declare(strict_types=1);

// Private CLI/library. No bootstrap/router, provider client, deployment or secret discovery.
require_once __DIR__ . '/product-model4-migration.php';

const CMJ_M4P_TOOL_FILES = [
    'product-model4-production.php', 'product-model4-migration.php', 'product-model4-preparation.php',
    'product-api.php', 'product-api-v2.php', 'product-api-v3.php', 'commerce-core.php', 'cart-contract.php', 'production-backup.php',
];
const CMJ_M4P_CLOSED_FLAGS = [
    'productionPublishEnabled', 'githubAdapterEnabled', 'productApiV4WritesEnabled',
    'productApiV5WritesEnabled', 'inventoryCartEnabled', 'shopCheckoutEnabled', 'collectionCommerceEnabled',
];

function cmj_m4p_file(string $path, bool $secret = false): string
{
    clearstatcache(true, $path);
    $path = cmj_m4_path($path);
    if (PHP_OS_FAMILY !== 'Windows') {
        cmj_model4_require(($secret ? (fileperms($path) & 0777) === 0600 : (fileperms($path) & 0022) === 0), 'private_permissions');
        for ($parent = dirname($path); dirname($parent) !== $parent; $parent = dirname($parent)) {
            // /tmp is only the artificial test parent, never a CLI production root.
            if ($parent === str_replace('\\', '/', realpath(sys_get_temp_dir()) ?: '')) break;
            cmj_model4_require((fileperms($parent) & 0022) === 0, 'private_permissions');
        }
    }
    return $path;
}

function cmj_m4p_json(string $path, bool $secret = false): array
{
    $value = json_decode(file_get_contents(cmj_m4p_file($path, $secret)), true, 128, JSON_THROW_ON_ERROR);
    cmj_model4_require(is_array($value) && !array_is_list($value), 'document_invalid');
    return $value;
}

function cmj_m4p_hash(string $value): bool { return preg_match('/^[a-f0-9]{64}$/D', $value) === 1; }

// The CLI and the isolated cold-start test use this exact package loader.
// Validate the entire set before executing dependencies or loading any runtime.
function cmj_m4p_load_dependencies(string $tool, array $release): void
{
    $actual = array_keys($release['toolFiles'] ?? []); sort($actual);
    $expected = CMJ_M4P_TOOL_FILES; sort($expected);
    cmj_model4_require($actual === $expected, 'release_file_set');
    foreach (CMJ_M4P_TOOL_FILES as $name) {
        cmj_model4_require(hash_file('sha256', cmj_m4p_file($tool . '/' . $name)) === ($release['toolFiles'][$name] ?? null), 'release_file_set');
    }
    foreach (['product-api.php', 'product-api-v2.php', 'product-api-v3.php', 'cart-contract.php', 'production-backup.php'] as $name) {
        require_once $tool . '/' . $name;
    }
}

function cmj_m4p_time(mixed $value): int
{
    cmj_model4_require(is_string($value) && preg_match('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ$/D', $value) === 1, 'evidence_time_invalid');
    $time = strtotime($value);
    cmj_model4_require($time !== false && gmdate('Y-m-d\TH:i:s\Z', $time) === $value, 'evidence_time_invalid');
    return $time;
}

function cmj_m4p_dsn(string $dsn): array
{
    cmj_model4_require(str_starts_with($dsn, 'mysql:'), 'mysql_dsn_required');
    $parts = [];
    foreach (explode(';', substr($dsn, 6)) as $part) {
        if ($part === '') continue;
        [$key, $value] = array_pad(explode('=', $part, 2), 2, '');
        cmj_model4_require(in_array($key, ['host', 'port', 'dbname', 'charset'], true) && !isset($parts[$key]), 'mysql_dsn_invalid');
        $parts[$key] = $value;
    }
    cmj_model4_require(preg_match('/^[A-Za-z0-9.-]+$/D', $parts['host'] ?? '') === 1
        && preg_match('/^[A-Za-z0-9_]+$/D', $parts['dbname'] ?? '') === 1
        && preg_match('/^[0-9]{1,5}$/D', $parts['port'] ?? '3306') === 1
        && (int) ($parts['port'] ?? '3306') > 0 && (int) ($parts['port'] ?? '3306') <= 65535
        && ($parts['charset'] ?? 'utf8mb4') === 'utf8mb4', 'mysql_dsn_invalid');
    return $parts;
}

function cmj_m4p_connection_options(array $config, bool $restore = false): array
{
    $prefix = $restore ? 'commerceRestore' : 'commerce';
    cmj_m4p_dsn($config[$prefix . 'Dsn'] ?? '');
    cmj_model4_require(($config[$prefix . 'RequireTls'] ?? null) === true, 'tls_required');
    $options = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false,
        PDO::ATTR_TIMEOUT => 5, PDO::MYSQL_ATTR_LOCAL_INFILE => false, PDO::MYSQL_ATTR_MULTI_STATEMENTS => false,
        PDO::MYSQL_ATTR_SSL_CIPHER => 'HIGH'];
    $ca = $config[$prefix . 'TlsCaPath'] ?? null;
    if (is_string($ca) && $ca !== '') {
        $options[PDO::MYSQL_ATTR_SSL_CA] = cmj_m4p_file($ca);
        $options[PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT] = true;
    } else {
        // Explicitly approved V1 residual risk, not a claim of CA/host verification.
        $options[PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT] = false;
    }
    return $options;
}

function cmj_m4p_verify_tls_mysql(PDO $pdo): void
{
    cmj_model4_require(str_starts_with((string) $pdo->query('SELECT VERSION()')->fetchColumn(), '8.'), 'mysql8_required');
    foreach (['Ssl_cipher', 'Ssl_version'] as $name) {
        cmj_model4_require(trim((string) $pdo->query("SHOW SESSION STATUS LIKE '$name'")->fetch(PDO::FETCH_NUM)[1]) !== '', 'tls_required');
    }
}

function cmj_m4p_connect(array $config, bool $restore = false): PDO
{
    $prefix = $restore ? 'commerceRestore' : 'commerce';
    $options = cmj_m4p_connection_options($config, $restore);
    $pdo = new PDO($config[$prefix . 'Dsn'], $config[$prefix . 'User'], $config[$prefix . 'Password'], $options);
    $pdo->exec("SET SESSION time_zone = '+00:00'");
    $pdo->exec('SET SESSION innodb_lock_wait_timeout = 3');
    $pdo->exec('SET SESSION lock_wait_timeout = 3');
    $pdo->exec('SET SESSION MAX_EXECUTION_TIME = 5000');
    cmj_m4p_verify_tls_mysql($pdo);
    return $pdo;
}

// These exact read queries are also used under row/range locks on the independent
// guard connection. Unknown states are blocking, not implicitly terminal.
function cmj_m4p_drained(PDO $pdo, bool $lock = false, ?float $deadline = null): void
{
    $rules = [
        'worker_leases' => static fn ($r) => ($r['lease_until'] ?? null) === null && ($r['lease_token'] ?? null) === null,
        'checkout_sagas' => static fn ($r) => in_array($r['state'], ['completed', 'failed', 'expired', 'canceled'], true),
        'payments' => static fn ($r) => in_array($r['status'], ['failed', 'canceled'], true)
            || ($r['status'] === 'succeeded' && $r['verification_status'] === 'verified' && !empty($r['order_id'])),
        'reservations' => static fn ($r) => (int) $r['blocks_stock'] === 0 && in_array($r['state'], ['released', 'converted', 'expired'], true),
        'webhook_inbox' => static fn ($r) => $r['status'] === 'processed',
        'stripe_metadata_outbox' => static fn ($r) => $r['status'] === 'sent',
        'mail_outbox' => static fn ($r) => $r['status'] === 'sent',
        // Admin restock/adjustment cannot change quantities while these rows are locked.
        'commerce_inventory' => static fn ($r) => true,
    ];
    foreach ($rules as $table => $terminal) {
        cmj_model4_require($deadline === null || microtime(true) < $deadline, 'migration_timeout');
        if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') {
            $engine = $pdo->prepare('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');
            $engine->execute([$table]);
            cmj_model4_require($engine->fetchColumn() === 'InnoDB', 'innodb_required');
        }
        $rows = $pdo->query('SELECT * FROM ' . $table . ($lock ? ' FOR UPDATE' : ''))->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $row) cmj_model4_require($terminal($row), 'business_not_drained');
    }
    // Non-cart admin routes refuse restocking collection products; V5/cart admin
    // inventory routes are disabled by the real runtime flag. Do not generalize
    // this narrowly approved migration to an already active quantity shop.
    foreach ($pdo->query('SELECT sales_model FROM commerce_products')->fetchAll(PDO::FETCH_COLUMN) as $model) {
        cmj_model4_require($model === 'collection', 'collection_migration_only');
    }
    cmj_model4_require($deadline === null || microtime(true) < $deadline, 'migration_timeout');
}

function cmj_m4p_restore_snapshot(PDO $restore, string $extract, array $plan): void
{
    // restoreDryRun supplies a fresh PDO, not the caller's UTC lock connection.
    // Normalize only this read session; do not rewrite timestamps or change the
    // existing backup fingerprint format / strict manifest comparison.
    if ($restore->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') {
        cmj_model4_require($restore->exec("SET SESSION time_zone = '+00:00'") !== false, 'utc_session_required');
        $zone = $restore->query('SELECT @@session.time_zone');
        cmj_model4_require($zone !== false && $zone->fetchColumn() === '+00:00', 'utc_session_required');
    }
    cmj_model4_require(cmj_m4_rows($restore) === $plan['beforeRows'], 'backup_source_mismatch');
    $names = array_map('basename', glob($extract . '/drafts/*.json') ?: []);
    $expected = array_map(static fn ($s) => $s['id'] . '.json', $plan['sources']);
    sort($names); sort($expected);
    cmj_model4_require($names === $expected, 'backup_source_mismatch');
    foreach ($plan['sources'] as $source) {
        cmj_model4_require(hash_file('sha256', cmj_m4p_file($extract . '/drafts/' . $source['id'] . '.json')) === $source['beforeHash'], 'backup_source_mismatch');
    }
    foreach ($plan['images'] as $relative => $hash) {
        cmj_model4_require(str_starts_with($relative, 'uploads/') && !str_contains($relative, '..'), 'unsafe_path');
        cmj_model4_require(hash_file('sha256', cmj_m4p_file($extract . '/' . $relative)) === $hash, 'backup_source_mismatch');
    }
}

function cmj_m4p_empty_restore(PDO $pdo): void
{
    foreach ([['TABLES', 'TABLE_SCHEMA'], ['ROUTINES', 'ROUTINE_SCHEMA'], ['EVENTS', 'EVENT_SCHEMA'], ['TRIGGERS', 'TRIGGER_SCHEMA']] as [$table, $column]) {
        cmj_model4_require((int) $pdo->query("SELECT COUNT(*) FROM information_schema.$table WHERE $column=DATABASE()")->fetchColumn() === 0, 'restore_not_empty');
    }
}

function cmj_m4p_with_backup_lock(string $directory, Closure $action): mixed
{
    // Reuse the existing backup creator lock, never create/replace it. This
    // prevents an hourly backup/prune during a mixed file/SQL migration phase.
    $handle = fopen(cmj_m4p_file($directory . '/locks/backup.lock'), 'r');
    cmj_model4_require(is_resource($handle), 'backup_lock_unavailable');
    try {
        cmj_model4_require(flock($handle, LOCK_EX | LOCK_NB), 'backup_busy');
        return $action();
    } finally { flock($handle, LOCK_UN); fclose($handle); }
}

final class CarmajaModel4Guard
{
    public function __construct(private Closure $check, private Closure $release) {}
    public function assertHeld(): void { ($this->check)(); }
    public function release(): void { ($this->release)(); }
}

// Shared real ports: the fixed production CLI and the isolated Linux integration
// suite execute these same functions. No test mode or path override in the CLI.
function cmj_m4p_restore_proof(array $config, string $id, array $plan): void
{
    $pdo = cmj_m4p_connect($config, true);
    cmj_model4_require((int) $pdo->query("SELECT GET_LOCK('carmaja-model4-restore', 0)")->fetchColumn() === 1, 'restore_busy');
    try {
        cmj_m4p_empty_restore($pdo);
        $result = (new CarmajaProductionBackup($config))->restoreDryRun($id,
            static fn (PDO $db, string $extract) => cmj_m4p_restore_snapshot($db, $extract, $plan));
        cmj_model4_require($result['status'] === 'restored_and_compared', 'restore_proof_invalid');
        cmj_m4p_empty_restore($pdo);
        cmj_model4_require((glob($config['backupDirectory'] . '/.restore/' . $id . '-*') ?: []) === [], 'restore_cleanup_incomplete');
    } finally { $pdo->query("SELECT RELEASE_LOCK('carmaja-model4-restore')"); }
}

function cmj_m4p_quiesce(array $config, PDO $source, string $product, float $deadline): CarmajaModel4Guard
{
    $guard = cmj_m4p_connect($config);
    cmj_model4_require(cmj_m4_target($product, $guard) === cmj_m4_target($product, $source)
        && $guard->query('SELECT CONNECTION_ID()')->fetchColumn() !== $source->query('SELECT CONNECTION_ID()')->fetchColumn(), 'guard_connection_invalid');
    $guard->exec('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
    $guard->beginTransaction();
    try { cmj_m4p_drained($guard, true, $deadline); }
    catch (Throwable $error) { $guard->rollBack(); throw $error; }
    $id = $guard->query('SELECT CONNECTION_ID()')->fetchColumn();
    return new CarmajaModel4Guard(static function () use ($guard, $id): void {
        cmj_model4_require($guard->inTransaction() && $guard->query('SELECT CONNECTION_ID()')->fetchColumn() === $id
            && $guard->inTransaction(), 'guard_connection_lost');
    }, static function () use ($guard): void { if ($guard->inTransaction()) $guard->rollBack(); });
}

/** Dependencies are explicit for artificial local tests. The CLI supplies only real I/O. */
final class CarmajaModel4ProductionCaller
{
    private array $approval;
    private array $plan;
    private array $boundFiles = [];
    private array $config;
    private ?int $evidenceDeadline = null;
    private ?float $guardDeadline = null;
    private ?CarmajaModel4Guard $guard = null;
    private string $job;

    public function __construct(private array $paths, private PDO $pdo, private string $approvalDigest,
        private Closure $loadConfig, private Closure $restoreProof, private Closure $quiesce,
        private ?Closure $checkpoint = null, private ?Closure $clock = null)
    {
        $this->job = cmj_m4_path($paths['job'], true);
        $this->approval = $this->readBound($this->job . '/approval.json', $approvalDigest, true);
        $a = $this->approval;
        cmj_model4_require(($a['format'] ?? null) === 'model4-execution-approval-v1'
            && ($a['approved'] ?? null) === true && ($a['environment'] ?? null) === 'production'
            && ($a['publicationAllowed'] ?? null) === false && ($a['inventoryImportAllowed'] ?? null) === false
            && ($a['selectedProducts'] ?? null) === []
            && preg_match('/^model4-[a-z0-9-]{8,64}$/D', $a['operationId'] ?? '') === 1
            && preg_match('/^[a-f0-9]{40}$/D', $a['releaseSha'] ?? '') === 1
            && is_array($a['actions'] ?? null) && array_is_list($a['actions'])
            && array_diff($a['actions'], ['preflight', 'verify-restore', 'apply', 'resume', 'rollback-files']) === [], 'execution_not_approved');
        cmj_model4_require($this->job === $paths['product'] . '/audit/' . $a['operationId'], 'unsafe_path');
        $this->plan = cmj_m4p_json($this->job . '/manifest.json', true);
        cmj_model4_require(cmj_m4p_hash($a['manifestDigest'] ?? '')
            && hash_equals($a['manifestDigest'], cmj_model4_digest($this->plan)), 'manifest_binding');
        $this->bind($this->job . '/manifest.json', hash_file('sha256', $this->job . '/manifest.json'), true);
        cmj_model4_require($this->plan['operationId'] === $a['operationId']
            && hash_equals($this->plan['targetHash'], cmj_m4_target($paths['product'], $pdo)), 'target_changed');
        $this->readBound($paths['runtime'], $a['runtimeSha256'] ?? '', true, false);
        $this->config = ($loadConfig)($paths['runtime']);
        $this->runtime();
        if (!empty($this->config['backupEncryptionKeyFile'])) {
            cmj_model4_require($this->config['backupEncryptionKeyFile'] === $paths['shop'] . '/config/backup-key.php', 'unsafe_path');
            $this->bind($this->config['backupEncryptionKeyFile'], $a['keyFileSha256'] ?? '', true);
        }
        $release = $this->readBound($this->job . '/release.json', $a['releaseManifestSha256'] ?? '', true);
        cmj_model4_require(($release['format'] ?? null) === 'model4-private-release-v1'
            && ($release['sourceCommit'] ?? null) === $a['releaseSha'] && ($release['workingTreeDirty'] ?? null) === false, 'release_not_clean');
        $toolNames = array_keys($release['toolFiles'] ?? []); sort($toolNames);
        $expectedNames = CMJ_M4P_TOOL_FILES; sort($expectedNames);
        cmj_model4_require($toolNames === $expectedNames, 'release_file_set');
        foreach ($release['toolFiles'] as $name => $hash) $this->bind($paths['tool'] . '/' . $name, $hash);
        // Separately bind the already active guarded package; a new tool release
        // need not replace the running API or worker to perform this migration.
        $active = $this->readBound($this->job . '/active-package.json', $a['activePackageSha256'] ?? '', true);
        cmj_model4_require(($active['releaseKind'] ?? null) === 'cart-production'
            && ($active['environment'] ?? null) === 'production'
            && preg_match('/^[a-f0-9]{40}$/D', $active['sourceCommit'] ?? '') === 1
            && ($active['workingTreeDirty'] ?? null) === false && is_array($active['files'] ?? null), 'active_package_invalid');
        $seen = [];
        foreach ($active['files'] as $file) {
            $name = $file['path'] ?? '';
            cmj_model4_require(is_string($name) && !isset($seen[$name]) && !str_contains($name, '..')
                && preg_match('#^[a-zA-Z0-9_./-]+$#D', $name) === 1, 'release_file_set');
            $seen[$name] = true;
            if (str_starts_with($name, 'private/program/')) $path = $paths['shop'] . '/' . substr($name, 8);
            elseif (in_array($name, ['public/index.php', 'public/.htaccess'], true)) $path = $paths['api'] . '/' . substr($name, 7);
            elseif ($name === 'private/worker.php') $path = $paths['shop'] . '/worker.php';
            else continue; // SQL/legal/templates do not grant permission to execute anything.
            $this->bind($path, $file['sha256'] ?? '');
        }
        foreach (['bootstrap.php', 'product-api.php', 'product-api-v2.php', 'product-api-v3.php',
            'product-api-v4.php', 'product-api-v5.php', 'product-admin.php', 'product-maintenance-guard.php',
            'commerce-core.php', 'cart-commerce.php', 'shop-admin.php', 'ap3-worker.php'] as $name) {
            cmj_model4_require(isset($seen['private/program/' . $name]), 'release_file_set');
        }
        foreach (['public/index.php', 'public/.htaccess', 'private/worker.php'] as $name) cmj_model4_require(isset($seen[$name]), 'release_file_set');
        $this->bind($paths['shop'] . '/maintenance/product-cleanup.lock', $a['maintenanceSha256'] ?? '');
        $this->bind($this->job . '/public-reference.json', $a['publicReferenceSha256'] ?? '', true);
        cmj_model4_require(cmj_model4_json(cmj_m4p_json($this->job . '/public-reference.json', true)) === cmj_model4_json($this->plan['publicReference']), 'public_reference_changed');
        // Real historical journal hashes are approved separately, never rewritten
        // or replaced with today's schema-file hashes.
        $journal = $pdo->query('SELECT migration_id, checksum FROM schema_migrations ORDER BY migration_id')->fetchAll(PDO::FETCH_ASSOC);
        cmj_model4_require(cmj_model4_digest($journal) === ($a['schemaJournalDigest'] ?? null), 'schema_journal_changed');
        foreach (['commerce-v1-schema', 'commerce-v1-ap4-shop', 'commerce-v1-ap5-admin', 'commerce-v2-collections', 'commerce-v3-cart-inventory'] as $id) {
            cmj_model4_require(in_array($id, array_column($journal, 'migration_id'), true), 'schema_incomplete');
        }
        $this->localGate();
    }

    private function time(): int { return $this->clock === null ? time() : ($this->clock)(); }

    private function bind(string $path, string $hash, bool $secret = false): void
    {
        cmj_model4_require(cmj_m4p_hash($hash) && hash_equals($hash, hash_file('sha256', cmj_m4p_file($path, $secret))), 'bound_file_changed');
        $this->boundFiles[$path] = [$hash, $secret];
    }

    private function readBound(string $path, string $hash, bool $secret = false, bool $json = true): array
    {
        $this->bind($path, $hash, $secret);
        return $json ? cmj_m4p_json($path, $secret) : [];
    }

    private function runtime(): void
    {
        $c = $this->config;
        cmj_model4_require(($c['environment'] ?? null) === 'production' && ($c['publishTarget'] ?? null) === 'production', 'production_runtime_required');
        foreach (CMJ_M4P_CLOSED_FLAGS as $flag) cmj_model4_require(($c[$flag] ?? null) === false, 'runtime_gate_open');
        foreach (['privateDir' => 'shop', 'productionPrivateDir' => 'shop', 'productPrivateDir' => 'product', 'productionApiWebroot' => 'api'] as $key => $path) {
            cmj_model4_require(($c[$key] ?? null) === $this->paths[$path], 'runtime_path_mismatch');
        }
        cmj_model4_require(($c['backupDirectory'] ?? null) === $this->paths['shop'] . '/backups'
            && ($c['backupOffsiteTarget'] ?? null) === 'onedrive-pull://carmaja-production/Carmaja-Perlen/Backups', 'runtime_path_mismatch');
        $source = cmj_m4p_dsn($c['commerceDsn'] ?? ''); $restore = cmj_m4p_dsn($c['commerceRestoreDsn'] ?? '');
        cmj_model4_require($source['dbname'] !== $restore['dbname'] && ($c['commerceUser'] ?? null) !== ($c['commerceRestoreUser'] ?? null), 'restore_target_not_separate');
        foreach (['commerce', 'commerceRestore'] as $prefix) {
            cmj_model4_require(($c[$prefix . 'RequireTls'] ?? null) === true, 'tls_required');
            foreach (['User', 'Password'] as $suffix) cmj_model4_require(is_string($c[$prefix . $suffix] ?? null) && $c[$prefix . $suffix] !== '', 'runtime_incomplete');
        }
        CarmajaBackupCipher::decodeKey($c['backupEncryptionKey'] ?? '');
    }

    private function localGate(): void
    {
        $now = $this->time(); $a = $this->approval;
        $issued = cmj_m4p_time($a['issuedAt'] ?? null); $expires = cmj_m4p_time($a['expiresAt'] ?? null);
        cmj_model4_require($issued <= $now && $now < $expires && $expires - $issued <= 86400, 'approval_expired');
        cmj_model4_require($this->evidenceDeadline === null || $now <= $this->evidenceDeadline, 'evidence_expired');
        foreach ($this->boundFiles as $path => [$hash, $secret]) {
            cmj_model4_require(hash_equals($hash, hash_file('sha256', cmj_m4p_file($path, $secret))), 'bound_file_changed');
        }
        cmj_model4_require($this->guardDeadline === null || microtime(true) < $this->guardDeadline, 'migration_timeout');
    }

    private function receiptMac(array $receipt): string
    {
        unset($receipt['hmac']);
        $key = CarmajaBackupCipher::decodeKey($this->config['backupEncryptionKey']);
        $derived = hash_hkdf('sha256', $key, 32, 'carmaja-model4-restore-proof-v1');
        sodium_memzero($key);
        $mac = hash_hmac('sha256', cmj_model4_json($receipt), $derived); sodium_memzero($derived);
        return $mac;
    }

    private function backup(bool $recovery): array
    {
        $a = $this->approval;
        cmj_model4_require(preg_match('/^[0-9]{8}T[0-9]{6}Z-[a-f0-9]{12}$/D', $a['backupId'] ?? '') === 1, 'backup_identity');
        $root = $this->paths['shop'] . '/backups/ready/' . $a['backupId'];
        $manifest = $this->readBound($root . '/manifest.json', $a['backupManifestSha256'] ?? '');
        cmj_model4_require(trim(file_get_contents(cmj_m4p_file($root . '/ready'))) === $a['backupManifestSha256'], 'backup_not_ready');
        $created = strtotime($manifest['createdAt'] ?? '');
        cmj_model4_require($created !== false && $created <= $this->time()
            && ($recovery || $this->time() - $created <= 5400), 'backup_stale');
        if (!$recovery) $this->evidenceDeadline = $created + 5400;
        cmj_model4_require(($manifest['backupId'] ?? null) === $a['backupId'] && ($manifest['keyId'] ?? null) === $this->config['backupEncryptionKeyId'], 'backup_identity');
        $hmac = $manifest['manifestHmac'] ?? ''; unset($manifest['manifestHmac']);
        $key = CarmajaBackupCipher::decodeKey($this->config['backupEncryptionKey']);
        $derived = hash_hkdf('sha256', $key, 32, 'carmaja-backup-manifest-v1'); sodium_memzero($key);
        cmj_model4_require(cmj_m4p_hash($hmac) && hash_equals($hmac, hash_hmac('sha256', cmj_model4_json($manifest), $derived)), 'backup_authentication');
        sodium_memzero($derived);
        foreach (['commerce' => 'commerce.sql.gz.cmjbkp', 'productData' => 'product-data.tar.gz.cmjbkp'] as $kind => $name) {
            $file = cmj_m4p_file($root . '/' . $name); $artifact = $manifest['artifacts'][$kind] ?? [];
            cmj_model4_require(($artifact['file'] ?? null) === $name && ($artifact['bytes'] ?? null) === filesize($file), 'backup_artifact');
            $this->bind($file, $artifact['sha256'] ?? '');
        }
        $ack = cmj_m4p_json($root . '/download-ack.json');
        cmj_model4_require(($ack['backupId'] ?? null) === $a['backupId'] && ($ack['manifestSha256'] ?? null) === $a['backupManifestSha256']
            && strtotime($ack['downloadedAt'] ?? '') >= $created && strtotime($ack['downloadedAt'] ?? '') <= $this->time(), 'offsite_ack_missing');
        $this->bind($root . '/ready', hash_file('sha256', $root . '/ready'));
        $this->bind($root . '/download-ack.json', hash_file('sha256', $root . '/download-ack.json'));
        return $manifest;
    }

    public function run(string $action): array
    {
        cmj_model4_require(in_array($action, $this->approval['actions'], true), 'action_not_approved');
        $this->localGate(); cmj_m4p_drained($this->pdo);
        $a = $this->approval;
        $stateExists = file_exists($this->job . '/state.json') || is_link($this->job . '/state.json');
        $recovery = $stateExists && ($action === 'resume' || $action === 'rollback-files'
            || (in_array($action, ['preflight', 'verify-restore'], true)
                && (in_array('resume', $a['actions'], true) || in_array('rollback-files', $a['actions'], true))));
        $gate = function (): array {
            // Each true result is reached only after all bound real file/approval
            // checks. Database drain is protected by the quiesce transaction.
            $this->localGate();
            cmj_model4_require($this->guardDeadline !== null, 'quiescence_required');
            return ['executionApproved' => true, 'maintenancePresent' => true, 'productAndAdminWritersStopped' => true,
                'checkoutsClosed' => true, 'paymentsDrained' => true, 'backupRestoreVerified' => true, 'privatePathsVerified' => true];
        };
        $point = function (string $name): void {
            if ($this->checkpoint !== null) ($this->checkpoint)($name);
            // SQL-only liveness check of the held guard connection. Not a
            // provider/network service call, and not part of the local file gate.
            $this->guard?->assertHeld();
        };
        $migration = new CarmajaModel4Migration($this->paths['product'], $this->pdo, $a['operationId'], $a['manifestDigest'], $gate, $point);
        $inspection = $migration->inspect();
        cmj_model4_require($action !== 'apply' || !$stateExists, 'resume_required');
        cmj_model4_require($action !== 'resume' || $stateExists, 'resume_without_journal');
        cmj_model4_require($action !== 'rollback-files' || !in_array($inspection['phase'], ['committed', 'complete'], true), 'rollback_after_commit_forbidden');
        $this->backup($recovery);
        // A renewed, separately approved recovery requires a new real restore
        // proof, but keeps the ORIGINAL before-image backup even after 90 minutes.
        $receiptFile = $this->job . '/restore-proof-' . $this->approvalDigest . '.json';
        if ($action === 'verify-restore') {
            cmj_model4_require(!file_exists($receiptFile) && !is_link($receiptFile), 'restore_proof_exists');
            // The callback must complete real restore + comparison + cleanup, and
            // explicitly compare restored product rows/files to this BEFORE plan.
            cmj_m4p_with_backup_lock($this->config['backupDirectory'],
                fn () => ($this->restoreProof)($this->config, $a['backupId'], $this->plan));
            $this->localGate(); $this->backup($recovery);
            $receipt = ['format' => 'model4-restore-proof-v1', 'operationId' => $a['operationId'],
                'manifestDigest' => $a['manifestDigest'], 'targetHash' => $this->plan['targetHash'],
                'backupId' => $a['backupId'], 'backupManifestSha256' => $a['backupManifestSha256'],
                'releaseSha' => $a['releaseSha'], 'approvalDigest' => $this->approvalDigest,
                'verifiedAt' => gmdate('Y-m-d\TH:i:s\Z', $this->time()),
                'status' => 'restored_compared_and_cleaned'];
            $receipt['hmac'] = $this->receiptMac($receipt);
            // Exclusive creation, never replace an existing evidence file. A crash
            // leaves invalid evidence and blocks execution rather than approving it.
            $handle = fopen($receiptFile, 'x'); cmj_model4_require(is_resource($handle), 'proof_write_failed');
            try {
                cmj_model4_require(chmod($receiptFile, 0600), 'proof_write_failed');
                $raw = cmj_model4_json($receipt) . "\n";
                cmj_model4_require(fwrite($handle, $raw) === strlen($raw) && fflush($handle) && fsync($handle), 'proof_write_failed');
            } finally { fclose($handle); }
            return ['status' => 'restore_proof_verified', 'productionProductsChanged' => false];
        }
        $receipt = cmj_m4p_json($receiptFile, true);
        cmj_model4_require(cmj_m4p_hash($receipt['hmac'] ?? '') && hash_equals($receipt['hmac'], $this->receiptMac($receipt)), 'restore_proof_invalid');
        foreach (['operationId', 'manifestDigest', 'backupId', 'backupManifestSha256', 'releaseSha'] as $key) {
            cmj_model4_require(($receipt[$key] ?? null) === $a[$key], 'restore_proof_invalid');
        }
        cmj_model4_require(($receipt['format'] ?? null) === 'model4-restore-proof-v1'
            && ($receipt['approvalDigest'] ?? null) === $this->approvalDigest
            && ($receipt['targetHash'] ?? null) === $this->plan['targetHash']
            && ($receipt['status'] ?? null) === 'restored_compared_and_cleaned'
            && cmj_m4p_time($receipt['verifiedAt'] ?? null) <= $this->time()
            && $this->time() - cmj_m4p_time($receipt['verifiedAt']) <= 5400, 'restore_proof_invalid');
        $this->bind($receiptFile, hash_file('sha256', $receiptFile), true);
        $this->evidenceDeadline = min($this->evidenceDeadline ?? PHP_INT_MAX, cmj_m4p_time($receipt['verifiedAt']) + 5400);
        if ($action === 'preflight') {
            return ['status' => 'preflight_passed', 'productionProductsChanged' => false];
        }
        // Quiesce holds real row/range locks on a separate connection, not an
        // advisory flag. All provider/backup I/O completed before these locks.
        return cmj_m4p_with_backup_lock($this->config['backupDirectory'], function () use ($migration, $action): array {
            $this->guardDeadline = microtime(true) + 40;
            try {
                $this->guard = ($this->quiesce)($this->guardDeadline);
                $this->guard->assertHeld();
                return $migration->run($action === 'rollback-files');
            } finally {
                $this->guardDeadline = null;
                try { $this->guard?->release(); } finally { $this->guard = null; }
            }
        });
    }
}

function cmj_m4p_main(array $argv): int
{
    ini_set('display_errors', '0'); ini_set('log_errors', '0'); ini_set('zend.exception_ignore_args', '1');
    $outputLevel = ob_get_level(); ob_start();
    set_error_handler(static function (): never { throw new RuntimeException('local_io_failed'); });
    try {
        cmj_model4_require(PHP_SAPI === 'cli' && PHP_OS_FAMILY === 'Linux' && PHP_MAJOR_VERSION === 8 && PHP_MINOR_VERSION === 4, 'php84_linux_cli_required');
        cmj_model4_require(count($argv) === 4 && in_array($argv[1], ['preflight', 'verify-restore', 'apply', 'resume', 'rollback-files'], true)
            && preg_match('/^model4-[a-z0-9-]{8,64}$/D', $argv[2]) === 1 && cmj_m4p_hash($argv[3]), 'explicit_bound_action_required');
        $shop = '/home/www/carmaja-private-shop'; $product = '/home/www/carmaja-private-production';
        $paths = ['shop' => $shop, 'product' => $product, 'api' => '/home/www/carmaja-production-api',
            'runtime' => $shop . '/config/runtime-config.php', 'tool' => $shop . '/model4-tool',
            'job' => $product . '/audit/' . $argv[2]];
        cmj_model4_require(cmj_m4_path(__DIR__, true) === $paths['tool'], 'private_cli_path_required');
        // Verify executable dependencies before loading either them or the private runtime.
        $approval = cmj_m4p_json($paths['job'] . '/approval.json', true);
        cmj_model4_require(hash_file('sha256', $paths['job'] . '/approval.json') === $argv[3], 'approval_binding');
        $release = cmj_m4p_json($paths['job'] . '/release.json', true);
        cmj_model4_require(hash_file('sha256', $paths['job'] . '/release.json') === ($approval['releaseManifestSha256'] ?? null), 'release_binding');
        cmj_m4p_load_dependencies($paths['tool'], $release);
        cmj_model4_require(hash_file('sha256', cmj_m4p_file($paths['runtime'], true)) === ($approval['runtimeSha256'] ?? null), 'runtime_binding');
        $rawConfig = (static fn ($file) => require $file)($paths['runtime']);
        cmj_model4_require(is_array($rawConfig), 'runtime_incomplete');
        if (!empty($rawConfig['backupEncryptionKeyFile'])) {
            cmj_model4_require($rawConfig['backupEncryptionKeyFile'] === $shop . '/config/backup-key.php'
                && hash_file('sha256', cmj_m4p_file($rawConfig['backupEncryptionKeyFile'], true)) === ($approval['keyFileSha256'] ?? null), 'key_file_binding');
        }
        $config = CarmajaProductionBackup::loadRuntime($paths['runtime']);
        unset($rawConfig);
        $source = cmj_m4p_connect($config);
        $caller = new CarmajaModel4ProductionCaller($paths, $source, $argv[3], static fn () => $config,
            cmj_m4p_restore_proof(...), static fn (float $deadline) => cmj_m4p_quiesce($config, $source, $product, $deadline));
        $result = $caller->run($argv[1]);
        while (ob_get_level() > $outputLevel) ob_end_clean();
        fwrite(STDOUT, json_encode($result, JSON_THROW_ON_ERROR) . "\n"); return 0;
    } catch (Throwable) {
        // Never echo exception messages, DSNs, paths, product contents or keys.
        fwrite(STDERR, "MODEL4_REFUSED: private prerequisites or bound action failed; maintenance remains closed.\n"); return 2;
    } finally {
        while (ob_get_level() > $outputLevel) ob_end_clean();
        restore_error_handler();
    }
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
    exit(cmj_m4p_main($_SERVER['argv'] ?? []));
}
