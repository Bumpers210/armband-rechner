<?php

declare(strict_types=1);

define('CMJ_MODEL4_TEST_HELPERS_ONLY', true);
require __DIR__ . '/product-model4-migration-test.php';
require_once dirname(__DIR__, 2) . '/test-api-private/program/production-backup.php';
require_once dirname(__DIR__, 2) . '/scripts/product-model4-production.php';

final class M4pProbeStatement extends PDOStatement
{
    public function __construct(private array $rows) {}
    public function execute(?array $params = null): bool { return true; }
    public function fetchColumn(int $column = 0): mixed { return $this->rows[0][$column] ?? false; }
    public function fetch(int $mode = PDO::FETCH_DEFAULT, int $orientation = PDO::FETCH_ORI_NEXT, int $offset = 0): mixed { return $this->rows[0] ?? false; }
    public function fetchAll(int $mode = PDO::FETCH_DEFAULT, mixed ...$args): array { return $this->rows; }
}
final class M4pProbePdo extends PDO
{
    public array $queries = [];
    public string $version = '8.0.36';
    public string $cipher = 'ARTIFICIAL_CIPHER';
    public string $tls = 'TLSv1.3';
    public string $engine = 'InnoDB';
    public int $objects = 0;
    public function __construct() {}
    public function getAttribute(int $attribute): mixed { return $attribute === PDO::ATTR_DRIVER_NAME ? 'mysql' : PDO::ERRMODE_EXCEPTION; }
    public function query(string $query, ?int $fetchMode = null, mixed ...$args): PDOStatement|false
    {
        $this->queries[] = $query;
        if ($query === 'SELECT VERSION()') return new M4pProbeStatement([[$this->version]]);
        if (str_contains($query, 'Ssl_cipher')) return new M4pProbeStatement([['Ssl_cipher', $this->cipher]]);
        if (str_contains($query, 'Ssl_version')) return new M4pProbeStatement([['Ssl_version', $this->tls]]);
        if (str_starts_with($query, 'SELECT COUNT(*) FROM information_schema.')) return new M4pProbeStatement([[$this->objects]]);
        if ($query === 'SELECT sales_model FROM commerce_products') return new M4pProbeStatement(['collection']);
        if (preg_match('/^SELECT \* FROM [a-z_]+ FOR UPDATE$/D', $query) === 1) return new M4pProbeStatement([]);
        throw new RuntimeException('unexpected_sql_in_double');
    }
    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        m4t_check($query === 'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?', 'only_engine_probe_prepared');
        return new M4pProbeStatement([[$this->engine]]);
    }
}

function m4p_write(string $file, array $value): string
{
    file_put_contents($file, cmj_model4_json($value)); chmod($file, 0600);
    return hash_file('sha256', $file);
}

function m4p_fixture(string $root, string $name): array
{
    $f = m4t_fixture($root, $name);
    $shop = $f['private'] . '/shop'; $api = $f['private'] . '/api';
    foreach ([$shop, $api, $shop . '/program', $shop . '/config', $shop . '/maintenance',
        $shop . '/model4-tool', $shop . '/backups', $shop . '/backups/ready', $shop . '/backups/locks'] as $dir) mkdir($dir, 0700);
    file_put_contents($shop . '/backups/locks/backup.lock', ''); chmod($shop . '/backups/locks/backup.lock', 0600);
    $paths = ['product' => $f['private'], 'shop' => $shop, 'api' => $api, 'tool' => $shop . '/model4-tool',
        'runtime' => $shop . '/config/runtime-config.php', 'job' => $f['job']];
    $config = ['environment' => 'production', 'publishTarget' => 'production',
        'privateDir' => $shop, 'productionPrivateDir' => $shop, 'productPrivateDir' => $f['private'], 'productionApiWebroot' => $api,
        'backupDirectory' => $shop . '/backups', 'backupOffsiteTarget' => 'onedrive-pull://carmaja-production/Carmaja-Perlen/Backups',
        'commerceDsn' => 'mysql:host=artificial.invalid;dbname=artificial_source;charset=utf8mb4',
        'commerceRestoreDsn' => 'mysql:host=artificial.invalid;dbname=artificial_restore;charset=utf8mb4',
        'commerceUser' => 'artificial_source', 'commerceRestoreUser' => 'artificial_restore',
        'commercePassword' => 'ARTIFICIAL_DO_NOT_PRINT', 'commerceRestorePassword' => 'ARTIFICIAL_DO_NOT_PRINT',
        'commerceRequireTls' => true, 'commerceRestoreRequireTls' => true,
        'backupEncryptionKey' => base64_encode(str_repeat('x', 32)), 'backupEncryptionKeyId' => 'artificial-key'];
    foreach (CMJ_M4P_CLOSED_FLAGS as $flag) $config[$flag] = false;
    file_put_contents($paths['runtime'], '<?php return ' . var_export($config, true) . ';'); chmod($paths['runtime'], 0600);
    file_put_contents($shop . '/maintenance/product-cleanup.lock', "closed\n"); chmod($shop . '/maintenance/product-cleanup.lock', 0600);
    $release = ['format' => 'model4-private-release-v1', 'sourceCommit' => str_repeat('1', 40), 'workingTreeDirty' => false, 'toolFiles' => []];
    foreach (CMJ_M4P_TOOL_FILES as $name) {
        file_put_contents($paths['tool'] . '/' . $name, "<?php // artificial binding fixture\n"); chmod($paths['tool'] . '/' . $name, 0600);
        $release['toolFiles'][$name] = hash_file('sha256', $paths['tool'] . '/' . $name);
    }
    $active = ['releaseKind' => 'cart-production', 'environment' => 'production', 'sourceCommit' => str_repeat('2', 40), 'workingTreeDirty' => false, 'files' => []];
    foreach (['bootstrap.php', 'product-api.php', 'product-api-v2.php', 'product-api-v3.php', 'product-api-v4.php',
        'product-api-v5.php', 'product-admin.php', 'product-maintenance-guard.php', 'commerce-core.php', 'cart-commerce.php',
        'shop-admin.php', 'ap3-worker.php'] as $name) {
        file_put_contents($shop . '/program/' . $name, "<?php // reviewed artificial active program\n"); chmod($shop . '/program/' . $name, 0600);
        $active['files'][] = ['path' => 'private/program/' . $name, 'sha256' => hash_file('sha256', $shop . '/program/' . $name)];
    }
    foreach (['public/index.php' => $api . '/index.php', 'public/.htaccess' => $api . '/.htaccess', 'private/worker.php' => $shop . '/worker.php'] as $relative => $file) {
        file_put_contents($file, 'artificial active entry'); chmod($file, 0600);
        $active['files'][] = ['path' => $relative, 'sha256' => hash_file('sha256', $file)];
    }
    // Additional drain/journal columns exist ONLY in this isolated SQLite fixture.
    $f['pdo']->exec('ALTER TABLE checkout_sagas ADD COLUMN state TEXT');
    $f['pdo']->exec("UPDATE checkout_sagas SET state='completed'");
    $f['pdo']->exec('ALTER TABLE commerce_products ADD COLUMN fixture_unused TEXT');
    // The real adapter binds every column, including otherwise unused columns.
    $f['plan'] = cmj_m4_capture($f['private'], $f['pdo'], $f['public'], '2026-11-12T12:00:00Z', 'model4-artificial-20261112');
    m4p_write($f['job'] . '/manifest.json', $f['plan']); $f['digest'] = cmj_model4_digest($f['plan']);
    $f['pdo']->exec('ALTER TABLE schema_migrations ADD COLUMN migration_id TEXT');
    $f['pdo']->exec('ALTER TABLE schema_migrations ADD COLUMN checksum TEXT');
    $f['pdo']->exec('DELETE FROM schema_migrations');
    $insert = $f['pdo']->prepare('INSERT INTO schema_migrations VALUES (?, ?, ?)');
    foreach (['commerce-v1-schema', 'commerce-v1-ap4-shop', 'commerce-v1-ap5-admin', 'commerce-v2-collections', 'commerce-v3-cart-inventory'] as $id) {
        $insert->execute(['ARTIFICIAL_UNCHANGED', $id, hash('sha256', $id)]);
    }
    foreach (['worker_leases' => 'lease_token TEXT, lease_until TEXT', 'payments' => 'status TEXT, verification_status TEXT, order_id TEXT',
        'reservations' => 'blocks_stock INTEGER, state TEXT', 'webhook_inbox' => 'status TEXT',
        'stripe_metadata_outbox' => 'status TEXT', 'mail_outbox' => 'status TEXT'] as $table => $columns) {
        $f['pdo']->exec('CREATE TABLE ' . $table . ' (' . $columns . ')');
    }
    $backupId = '20261112T120000Z-123456789abc'; $backup = $shop . '/backups/ready/' . $backupId; mkdir($backup, 0700);
    $bm = ['formatVersion' => 1, 'backupId' => $backupId, 'createdAt' => '2026-11-12T12:00:00+00:00', 'keyId' => 'artificial-key', 'artifacts' => []];
    foreach (['commerce' => 'commerce.sql.gz.cmjbkp', 'productData' => 'product-data.tar.gz.cmjbkp'] as $kind => $file) {
        file_put_contents($backup . '/' . $file, 'ARTIFICIAL_ENCRYPTED_FIXTURE'); chmod($backup . '/' . $file, 0600);
        $bm['artifacts'][$kind] = ['file' => $file, 'bytes' => filesize($backup . '/' . $file), 'sha256' => hash_file('sha256', $backup . '/' . $file)];
    }
    $bm['manifestHmac'] = hash_hmac('sha256', cmj_model4_json($bm), hash_hkdf('sha256', str_repeat('x', 32), 32, 'carmaja-backup-manifest-v1'));
    $bhash = m4p_write($backup . '/manifest.json', $bm);
    file_put_contents($backup . '/ready', $bhash . "\n"); chmod($backup . '/ready', 0600);
    m4p_write($backup . '/download-ack.json', ['backupId' => $backupId, 'manifestSha256' => $bhash, 'downloadedAt' => '2026-11-12T12:01:00Z']);
    $approval = ['format' => 'model4-execution-approval-v1', 'approved' => true, 'environment' => 'production',
        'operationId' => $f['plan']['operationId'], 'publicationAllowed' => false, 'inventoryImportAllowed' => false, 'selectedProducts' => [],
        'releaseSha' => $release['sourceCommit'], 'manifestDigest' => $f['digest'],
        'actions' => ['preflight', 'verify-restore', 'apply', 'resume', 'rollback-files'],
        'issuedAt' => '2026-11-12T12:01:00Z', 'expiresAt' => '2026-11-12T13:00:00Z',
        'runtimeSha256' => hash_file('sha256', $paths['runtime']),
        'releaseManifestSha256' => m4p_write($f['job'] . '/release.json', $release),
        'activePackageSha256' => m4p_write($f['job'] . '/active-package.json', $active),
        'maintenanceSha256' => hash_file('sha256', $shop . '/maintenance/product-cleanup.lock'),
        'publicReferenceSha256' => m4p_write($f['job'] . '/public-reference.json', $f['public']),
        'schemaJournalDigest' => cmj_model4_digest($f['pdo']->query('SELECT migration_id, checksum FROM schema_migrations ORDER BY migration_id')->fetchAll(PDO::FETCH_ASSOC)),
        'backupId' => $backupId, 'backupManifestSha256' => $bhash];
    $approvalDigest = m4p_write($f['job'] . '/approval.json', $approval);
    $f['protected'] = m4t_protected($f);
    return $f + compact('paths', 'config', 'release', 'active', 'backup', 'approval', 'approvalDigest');
}

function m4p_caller(array $f, ?Closure $point = null, ?Closure $clock = null, ?Closure $restore = null, ?Closure $quiesce = null): CarmajaModel4ProductionCaller
{
    return new CarmajaModel4ProductionCaller($f['paths'], $f['pdo'], $f['approvalDigest'],
        static fn ($file) => require $file,
        $restore ?? static function ($config, $id, $plan) use ($f): void {
            // Deliberate local double: snapshot comparison is real; MySQL/TLS,
            // decrypt/extract/restore are NOT claimed by this caller unit suite.
            cmj_m4p_restore_snapshot($f['pdo'], $f['private'], $plan);
        },
        $quiesce ?? static function () use ($f): CarmajaModel4Guard {
            cmj_m4p_drained($f['pdo']);
            return new CarmajaModel4Guard(static function (): void {}, static function (): void {});
        }, $point, $clock ?? static fn () => strtotime('2026-11-12T12:02:00Z'));
}

$root = str_replace('\\', '/', sys_get_temp_dir()) . '/cmj-model4-caller-' . bin2hex(random_bytes(6));
mkdir($root, 0700);
try {
    $probe = new M4pProbePdo(); cmj_m4p_verify_tls_mysql($probe); m4t_check(true, 'mysql_tls_valid_double');
    foreach (['cipher', 'tls'] as $field) {
        $old = $probe->$field; $probe->$field = '';
        m4t_reject(fn () => cmj_m4p_verify_tls_mysql($probe), 'tls_required'); $probe->$field = $old;
    }
    $probe->version = '11.4.0-MariaDB';
    m4t_reject(fn () => cmj_m4p_verify_tls_mysql($probe), 'mysql8_required'); $probe->version = '8.0.36';
    cmj_m4p_drained($probe, true);
    m4t_check(count(array_filter($probe->queries, static fn ($sql) => str_ends_with($sql, ' FOR UPDATE'))) === 8, 'all_eight_guard_ranges_locked');
    $probe->engine = 'MyISAM'; m4t_reject(fn () => cmj_m4p_drained($probe, true), 'innodb_required');
    cmj_m4p_empty_restore($probe); m4t_check(true, 'empty_restore_all_object_kinds');
    $probe->objects = 1; m4t_reject(fn () => cmj_m4p_empty_restore($probe), 'restore_not_empty');
    $f = m4p_fixture($root, 'complete');
    $options = cmj_m4p_connection_options($f['config']);
    m4t_check($options[PDO::MYSQL_ATTR_SSL_CIPHER] === 'HIGH' && $options[PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT] === false, 'explicit_tls_without_identity_residual');
    m4t_check($options[PDO::MYSQL_ATTR_LOCAL_INFILE] === false && $options[PDO::MYSQL_ATTR_MULTI_STATEMENTS] === false
        && $options[PDO::ATTR_TIMEOUT] === 5 && $options[PDO::ATTR_EMULATE_PREPARES] === false, 'pdo_limits');
    $ca = $root . '/artificial-ca.pem'; file_put_contents($ca, 'ARTIFICIAL_CERTIFICATE'); chmod($ca, 0600);
    $options = cmj_m4p_connection_options([...$f['config'], 'commerceTlsCaPath' => $ca]);
    m4t_check($options[PDO::MYSQL_ATTR_SSL_CA] === $ca && $options[PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT] === true, 'provided_ca_must_verify');
    m4t_reject(fn () => cmj_m4p_connection_options([...$f['config'], 'commerceRequireTls' => false]), 'tls_required');
    $before = m4t_protected($f);
    m4t_check(m4p_caller($f)->run('verify-restore')['status'] === 'restore_proof_verified', 'restore_receipt');
    m4t_check(m4p_caller($f)->run('preflight')['status'] === 'preflight_passed', 'readonly_preflight');
    m4t_original($f);
    m4t_check(!file_exists($f['job'] . '/state.json') && (glob($f['private'] . '/locks/*') ?: []) === [], 'preflight_no_journal_or_locks');
    m4t_reject(fn () => m4p_caller($f)->run('resume'), 'resume_without_journal');
    m4t_check(m4p_caller($f)->run('apply')['status'] === 'model4_complete', 'apply');
    m4t_complete($f); m4t_check(m4t_protected($f) === $before, 'protected');
    m4t_reject(fn () => m4p_caller($f)->run('apply'), 'resume_required');
    m4t_check(m4p_caller($f)->run('resume')['status'] === 'model4_complete', 'repeat_completed');
    m4t_reject(fn () => m4p_caller($f)->run('rollback-files'), 'rollback_after_commit_forbidden');

    foreach (CMJ_M4P_CLOSED_FLAGS as $i => $flag) {
        $f = m4p_fixture($root, 'flag-' . $i);
        $f['config'][$flag] = true;
        file_put_contents($f['paths']['runtime'], '<?php return ' . var_export($f['config'], true) . ';');
        $f['approval']['runtimeSha256'] = hash_file('sha256', $f['paths']['runtime']);
        $f['approvalDigest'] = m4p_write($f['job'] . '/approval.json', $f['approval']);
        m4t_reject(fn () => m4p_caller($f), 'runtime_gate_open'); m4t_original($f);
    }
    foreach (['approved' => false, 'publicationAllowed' => true, 'inventoryImportAllowed' => true, 'selectedProducts' => ['forbidden']] as $key => $value) {
        $f = m4p_fixture($root, 'approval-' . $key); $f['approval'][$key] = $value;
        $f['approvalDigest'] = m4p_write($f['job'] . '/approval.json', $f['approval']);
        m4t_reject(fn () => m4p_caller($f), 'execution_not_approved'); m4t_original($f);
    }
    $f = m4p_fixture($root, 'expiry');
    m4t_reject(fn () => m4p_caller($f, null, static fn () => strtotime('2026-11-12T13:00:00Z')), 'approval_expired');
    $f['approval']['actions'] = ['preflight']; $f['approvalDigest'] = m4p_write($f['job'] . '/approval.json', $f['approval']);
    m4t_reject(fn () => m4p_caller($f)->run('verify-restore'), 'action_not_approved');

    foreach (['runtime', 'marker', 'active', 'tool', 'manifest', 'projection', 'release'] as $kind) {
        $f = m4p_fixture($root, 'tamper-' . $kind);
        $file = match ($kind) {
            'runtime' => $f['paths']['runtime'], 'marker' => $f['paths']['shop'] . '/maintenance/product-cleanup.lock',
            'active' => $f['paths']['shop'] . '/program/bootstrap.php', 'tool' => $f['paths']['tool'] . '/product-model4-production.php',
            'manifest' => $f['job'] . '/manifest.json', 'projection' => $f['job'] . '/public-reference.json', 'release' => $f['job'] . '/release.json',
        };
        $caller = m4p_caller($f); file_put_contents($file, 'TAMPER', FILE_APPEND);
        m4t_reject(fn () => $caller->run('verify-restore'), 'bound_file_changed'); m4t_original($f);
    }
    $f = m4p_fixture($root, 'dirty'); $f['release']['workingTreeDirty'] = true;
    $f['approval']['releaseManifestSha256'] = m4p_write($f['job'] . '/release.json', $f['release']);
    $f['approvalDigest'] = m4p_write($f['job'] . '/approval.json', $f['approval']);
    m4t_reject(fn () => m4p_caller($f), 'release_not_clean');
    $f = m4p_fixture($root, 'journal'); $f['pdo']->exec("UPDATE schema_migrations SET checksum='wrong'");
    m4t_reject(fn () => m4p_caller($f), 'schema_journal_changed');

    foreach ([['checkout_sagas', "UPDATE checkout_sagas SET state='payment_pending'"],
        ['payments', "INSERT INTO payments VALUES ('processing','unverified',NULL)"],
        ['reservations', "INSERT INTO reservations VALUES (1,'active')"],
        ['worker_leases', "INSERT INTO worker_leases VALUES ('busy','2026-11-12 13:00:00')"],
        ['webhook_inbox', "INSERT INTO webhook_inbox VALUES ('queued')"],
        ['stripe_metadata_outbox', "INSERT INTO stripe_metadata_outbox VALUES ('failed')"],
        ['mail_outbox', "INSERT INTO mail_outbox VALUES ('delivery_unknown')"]] as [$table, $sql]) {
        $f = m4p_fixture($root, 'drain-' . $table); $f['pdo']->exec($sql);
        m4t_reject(fn () => m4p_caller($f)->run('verify-restore'), 'business_not_drained'); m4t_original($f);
    }
    $f = m4p_fixture($root, 'restore-fails');
    m4t_reject(fn () => m4p_caller($f, restore: static function (): never { throw new RuntimeException('artificial_restore_failed'); })->run('verify-restore'), 'artificial_restore_failed');
    m4t_check(glob($f['job'] . '/restore-proof-*') === [], 'no_proof_after_failure'); m4t_original($f);
    $f['pdo']->exec("UPDATE commerce_products SET name='DIFFERENT' WHERE product_id='10000000-0000-4000-8000-000000000002'");
    m4t_reject(fn () => cmj_m4p_restore_snapshot($f['pdo'], $f['private'], $f['plan']), 'backup_source_mismatch');

    $f = m4p_fixture($root, 'bad-archive'); file_put_contents($f['backup'] . '/commerce.sql.gz.cmjbkp', 'tampered');
    m4t_reject(fn () => m4p_caller($f)->run('verify-restore'), 'backup_artifact'); m4t_original($f);
    $f = m4p_fixture($root, 'bad-proof'); m4p_caller($f)->run('verify-restore');
    $proofPath = glob($f['job'] . '/restore-proof-*')[0]; $proof = cmj_m4p_json($proofPath, true); $proof['targetHash'] = str_repeat('0', 64); m4p_write($proofPath, $proof);
    m4t_reject(fn () => m4p_caller($f)->run('apply'), 'restore_proof_invalid'); m4t_original($f);

    foreach (['before_commit', 'after_commit', 'after_rename:source:10000000-0000-4000-8000-000000000002'] as $i => $point) {
        $f = m4p_fixture($root, 'failure-' . $i); m4p_caller($f)->run('verify-restore'); $released = false;
        $fault = static function ($name) use ($point): void { if ($name === $point) throw new RuntimeException('artificial_abort'); };
        $quiesce = static function () use (&$released): CarmajaModel4Guard {
            return new CarmajaModel4Guard(static function (): void {}, static function () use (&$released): void { $released = true; });
        };
        m4t_reject(fn () => m4p_caller($f, $fault, quiesce: $quiesce)->run('apply'), 'artificial_abort');
        m4t_check($released, 'guard_release_after_error');
        m4t_check(m4p_caller($f)->run('resume')['status'] === 'model4_complete', 'resume_' . $i); m4t_complete($f);
    }
    $f = m4p_fixture($root, 'revoke'); m4p_caller($f)->run('verify-restore');
    $point = static function ($name) use ($f): void {
        if ($name === 'before_commit') file_put_contents($f['paths']['shop'] . '/maintenance/product-cleanup.lock', 'CHANGED');
    };
    m4t_reject(fn () => m4p_caller($f, $point)->run('apply'), 'bound_file_changed');
    m4t_check(cmj_m4_rows($f['pdo']) === $f['plan']['beforeRows'], 'revocation_rolls_back_sql');
    file_put_contents($f['paths']['shop'] . '/maintenance/product-cleanup.lock', "closed\n");
    m4t_check(m4p_caller($f)->run('rollback-files')['status'] === 'files_rolled_back', 'explicit_file_rollback'); m4t_original($f);

    foreach (['mysql:host=x;dbname=a;dbname=b', 'sqlite:/tmp/not_allowed', 'mysql:unix_socket=/tmp/db;dbname=a', 'mysql:host=x;dbname=a;port=0'] as $bad) {
        try { cmj_m4p_dsn($bad); throw new LogicException('accepted_bad_dsn'); }
        catch (RuntimeException $error) { m4t_check(in_array($error->getMessage(), ['mysql_dsn_required', 'mysql_dsn_invalid'], true), 'dsn_reject'); }
    }
    m4t_check(cmj_m4p_dsn('mysql:host=artificial.invalid;dbname=one;charset=utf8mb4')['dbname'] === 'one', 'dsn_valid');

    $f = m4p_fixture($root, 'after-capture-change'); m4p_caller($f)->run('verify-restore');
    file_put_contents($f['private'] . '/drafts/' . $f['plan']['sources'][0]['id'] . '.json', ' ' , FILE_APPEND);
    m4t_reject(fn () => m4p_caller($f)->run('preflight'), 'source_changed');

    $f = m4p_fixture($root, 'hmac');
    $bm = cmj_m4p_json($f['backup'] . '/manifest.json'); $bm['manifestHmac'] = str_repeat('0', 64);
    $f['approval']['backupManifestSha256'] = m4p_write($f['backup'] . '/manifest.json', $bm);
    file_put_contents($f['backup'] . '/ready', $f['approval']['backupManifestSha256']);
    $f['approvalDigest'] = m4p_write($f['job'] . '/approval.json', $f['approval']);
    m4t_reject(fn () => m4p_caller($f)->run('verify-restore'), 'backup_authentication'); m4t_original($f);

    $f = m4p_fixture($root, 'stale');
    $f['approval']['expiresAt'] = '2026-11-12T18:00:00Z';
    $f['approvalDigest'] = m4p_write($f['job'] . '/approval.json', $f['approval']);
    m4t_reject(fn () => m4p_caller($f, clock: static fn () => strtotime('2026-11-12T14:00:00Z'))->run('verify-restore'), 'backup_stale');
    m4p_caller($f)->run('verify-restore');
    // Abort before source writes, keep original backup; a renewed explicit
    // recovery authority gets its own newly verified receipt, not stale trust.
    $fault = static function ($name): void { if ($name === 'after_rename:state') throw new RuntimeException('artificial_abort'); };
    m4t_reject(fn () => m4p_caller($f, $fault)->run('apply'), 'artificial_abort');
    $f['approval']['actions'] = ['verify-restore', 'resume', 'rollback-files'];
    $f['approval']['issuedAt'] = '2026-11-12T13:59:00Z';
    $f['approvalDigest'] = m4p_write($f['job'] . '/approval.json', $f['approval']);
    $late = static fn () => strtotime('2026-11-12T14:00:00Z');
    m4t_check(m4p_caller($f, clock: $late)->run('verify-restore')['status'] === 'restore_proof_verified', 'renewed_recovery_restore');
    m4t_check(count(glob($f['job'] . '/restore-proof-*')) === 2, 'retain_prior_receipt');
    m4t_check(m4p_caller($f, clock: $late)->run('resume')['status'] === 'model4_complete', 'older_original_backup_recovery'); m4t_complete($f);

    $f = m4p_fixture($root, 'unapproved-operation');
    $f['approval']['actions'] = ['apply']; $f['approvalDigest'] = m4p_write($f['job'] . '/approval.json', $f['approval']);
    try { m4p_caller($f)->run('apply'); throw new LogicException('missing_proof_accepted'); }
    catch (RuntimeException $error) { m4t_check($error->getMessage() === 'unsafe_path', 'proof_required'); }
    m4t_original($f);

    $script = dirname(__DIR__, 2) . '/scripts/product-model4-production.php';
    $child = proc_open([PHP_BINARY, '-n', $script, 'apply', 'ARTIFICIAL_DO_NOT_PRINT', 'ARTIFICIAL_DO_NOT_PRINT'],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    fclose($pipes[0]); $output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]); $exit = proc_close($child);
    m4t_check($exit === 2 && str_contains($output, 'MODEL4_REFUSED')
        && !str_contains($output, 'ARTIFICIAL_DO_NOT_PRINT') && !str_contains($output, $root), 'cli_redaction_and_no_fallback');

    $f = m4p_fixture($root, 'backup-busy');
    $held = fopen($f['config']['backupDirectory'] . '/locks/backup.lock', 'r'); flock($held, LOCK_EX);
    try { m4t_reject(fn () => m4p_caller($f)->run('verify-restore'), 'backup_busy'); }
    finally { flock($held, LOCK_UN); fclose($held); }
    m4t_check(glob($f['job'] . '/restore-proof-*') === [], 'no_restore_on_backup_busy');
    m4p_caller($f)->run('verify-restore');
    $held = fopen($f['config']['backupDirectory'] . '/locks/backup.lock', 'r'); flock($held, LOCK_EX);
    try { m4t_reject(fn () => m4p_caller($f)->run('apply'), 'backup_busy'); }
    finally { flock($held, LOCK_UN); fclose($held); }
    m4t_original($f);
    m4t_reject(fn () => cmj_m4p_with_backup_lock($f['config']['backupDirectory'], static function (): never {
        throw new RuntimeException('artificial_abort');
    }), 'artificial_abort');
    m4t_check(cmj_m4p_with_backup_lock($f['config']['backupDirectory'], static fn () => 'released') === 'released', 'backup_lock_released_after_abort');

    $f = m4p_fixture($root, 'lost-guard'); m4p_caller($f)->run('verify-restore');
    $alive = true; $released = false;
    $quiesce = static function () use (&$alive, &$released): CarmajaModel4Guard {
        return new CarmajaModel4Guard(static function () use (&$alive): void {
            cmj_model4_require($alive, 'guard_connection_lost');
        }, static function () use (&$released): void { $released = true; });
    };
    $point = static function ($name) use (&$alive): void { if ($name === 'commit_ready') $alive = false; };
    m4t_reject(fn () => m4p_caller($f, $point, quiesce: $quiesce)->run('apply'), 'guard_connection_lost');
    m4t_check($released && cmj_m4_rows($f['pdo']) === $f['plan']['beforeRows'], 'lost_guard_rolls_back_and_releases');
    m4t_check(m4p_caller($f)->run('resume')['status'] === 'model4_complete', 'lost_guard_resume'); m4t_complete($f);
    echo $m4Checks . " model4 production caller checks passed (artificial local I/O; no MySQL/server/provider access).\n";
} finally {
    unset($f, $caller, $insert, $point, $fault, $quiesce, $restore); gc_collect_cycles();
    m4t_cleanup($root, $root);
}
if (file_exists($root)) throw new RuntimeException('artificial_cleanup_failed');
