<?php

declare(strict_types=1);

require_once __DIR__ . '/product-model4-preparation.php';

// Private library only: no CLI dispatcher, credentials, provider calls or deployment hook.
function cmj_m4_path(string $path, bool $directory = false): string
{
    $normal = str_replace('\\', '/', $path);
    cmj_model4_require($normal === str_replace('\\', '/', realpath($path) ?: '')
        && ($directory ? is_dir($path) : is_file($path)), 'unsafe_path');
    for ($part = $normal; dirname($part) !== $part; $part = dirname($part)) {
        cmj_model4_require(!is_link($part), 'unsafe_path');
    }
    cmj_model4_require(dirname($normal) !== $normal, 'unsafe_path');
    return $normal;
}

function cmj_m4_row(array $row): array
{
    foreach ($row as $key => $value) {
        if (in_array($key, ['materials', 'metal_elements', 'images', 'notice_snapshot', 'public_snapshot'], true) && $value !== null) {
            $row[$key] = is_string($value) ? json_decode($value, true, 64, JSON_THROW_ON_ERROR) : $value;
        } elseif ($value !== null) $row[$key] = (string) $value;
    }
    return carmaja_api_canonicalize($row);
}

function cmj_m4_rows(PDO $pdo, bool $lock = false): array
{
    $sql = 'SELECT * FROM commerce_products ORDER BY product_id';
    if ($lock && $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') $sql .= ' FOR UPDATE';
    $rows = array_map('cmj_m4_row', $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC));
    usort($rows, static fn ($a, $b) => strcmp($a['product_id'], $b['product_id']));
    return $rows;
}

function cmj_m4_target(string $private, PDO $pdo): string
{
    $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    cmj_model4_require(!$pdo->inTransaction(), 'existing_transaction');
    cmj_model4_require($pdo->getAttribute(PDO::ATTR_ERRMODE) === PDO::ERRMODE_EXCEPTION, 'pdo_exceptions_required');
    if ($driver === 'sqlite') {
        // SQLite is only a local transaction fixture, never a production substitute.
        $files = $pdo->query('PRAGMA database_list')->fetchAll(PDO::FETCH_ASSOC);
        cmj_model4_require(count($files) === 1 && $files[0]['name'] === 'main', 'fixture_database');
        $identity = cmj_m4_path($files[0]['file']);
        cmj_model4_require(str_starts_with($identity, $private . '/'), 'fixture_database');
    } elseif ($driver === 'mysql') {
        cmj_model4_require(str_starts_with((string) $pdo->query('SELECT VERSION()')->fetchColumn(), '8.'), 'mysql8_required');
        cmj_model4_require(in_array($pdo->query('SELECT @@session.time_zone')->fetchColumn(), ['+00:00', 'UTC'], true), 'utc_session_required');
        foreach (['Ssl_cipher', 'Ssl_version'] as $status) {
            cmj_model4_require(trim((string) $pdo->query("SHOW SESSION STATUS LIKE '$status'")->fetch(PDO::FETCH_NUM)[1]) !== '', 'tls_required');
        }
        cmj_model4_require($pdo->query("SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='commerce_products'")->fetchColumn() === 'InnoDB', 'innodb_required');
        $identity = $pdo->query('SELECT CONCAT(@@hostname, CHAR(0), DATABASE())')->fetchColumn();
    } else throw new RuntimeException('unsupported_database');
    return cmj_model4_digest([$private, $driver, $identity]);
}

function cmj_m4_manifest(array $records, array $rows, array $public, array $images, string $at, string $operation, string $target): array
{
    cmj_model4_require(preg_match('/^model4-[a-z0-9-]{8,64}$/', $operation) === 1
        && preg_match('/^[a-f0-9]{64}$/', $target) === 1, 'manifest_identity');
    usort($records, static fn ($a, $b) => strcmp($a['id'], $b['id']));
    $drafts = []; $sources = [];
    foreach ($records as $record) {
        $raw = base64_decode($record['raw'], true);
        cmj_model4_require(is_string($raw), 'manifest_source');
        $draft = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
        $allowedMode = in_array($record['mode'], [0600, 0640], true)
            || (PHP_OS_FAMILY === 'Windows' && $record['mode'] === 0666);
        cmj_model4_require(($draft['draftId'] ?? null) === $record['id'] && $allowedMode, 'manifest_source');
        $drafts[] = $draft;
        $after = $raw;
        if (($draft['productModelVersion'] ?? null) === 3) {
            cmj_model4_require(is_string($draft['updatedAt'] ?? null) && strtotime($draft['updatedAt']) !== false
                && strtotime($at) >= strtotime($draft['updatedAt']), 'migration_time_before_source');
            $after = json_encode(cmj_model4_candidate($draft, $at), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n";
        }
        $sources[] = ['id' => $record['id'], 'before' => $record['raw'], 'after' => base64_encode($after),
            'beforeHash' => hash('sha256', $raw), 'afterHash' => hash('sha256', $after), 'mode' => $record['mode']];
    }
    $rows = array_map('cmj_m4_row', $rows);
    usort($rows, static fn ($a, $b) => strcmp($a['product_id'], $b['product_id']));
    $report = cmj_model4_plan($drafts, $rows, $public, $at);
    $byId = array_column($sources, null, 'id'); $afterRows = [];
    foreach ($rows as $row) {
        $source = $byId[$row['product_id']]; $afterRow = $row;
        if ($source['beforeHash'] !== $source['afterHash']) {
            $after = json_decode(base64_decode($source['after'], true), true, 64, JSON_THROW_ON_ERROR);
            $afterRow['product_version'] = (string) $after['productVersion'];
            $afterRow['source_hash'] = $after['sourceHash'];
            $afterRow['updated_at'] = str_replace(['T', 'Z'], [' ', '.000000'], $at);
            $afterRow['synchronized_at'] = $afterRow['updated_at'];
            // The cart's quantity import builds these later. Do not rewrite historical snapshots here.
            cmj_model4_require(($row['notice_snapshot'] ?? null) === null && ($row['public_snapshot'] ?? null) === null, 'commerce_snapshot_conflict');
        }
        $afterRows[] = $afterRow;
    }
    ksort($images, SORT_STRING);
    return ['format' => 'private-model4-migration-v1', 'operationId' => $operation, 'at' => $at,
        'targetHash' => $target, 'records' => $records, 'sources' => $sources, 'beforeRows' => $rows, 'afterRows' => $afterRows,
        'publicReference' => $public, 'images' => $images, 'report' => $report,
        'publicationAllowed' => false, 'inventoryImportAllowed' => false];
}

function cmj_m4_images(string $private, array $drafts): array
{
    $images = [];
    foreach ($drafts as $draft) {
        if (!in_array($draft['productModelVersion'] ?? null, [3, 4], true)) continue;
        cmj_model4_require(is_array($draft['imageManifest'] ?? null) && array_is_list($draft['imageManifest'])
            && $draft['imageManifest'] !== [], 'image_manifest_missing');
        $uploaded = [];
        foreach ($draft['images'] ?? [] as $image) {
            cmj_model4_require(is_array($image) && is_string($image['imageId'] ?? null)
                && !isset($uploaded[$image['imageId']]), 'image_identity');
            $uploaded[$image['imageId']] = $image;
        }
        foreach ($draft['imageManifest'] ?? [] as $image) {
            $file = $uploaded[$image['imageId']] ?? null;
            cmj_model4_require(is_array($file) && ($file['fileName'] ?? null) === $image['fileName'], 'image_missing');
            $path = cmj_m4_path((string) ($file['path'] ?? ''));
            cmj_model4_require(str_starts_with($path, $private . '/uploads/'), 'image_outside_private');
            $size = getimagesize($path);
            cmj_model4_require(is_array($size) && $size[2] === IMAGETYPE_JPEG
                && $size[0] === $image['width'] && $size[1] === $image['height'], 'image_dimensions');
            $images[substr($path, strlen($private) + 1)] = hash_file('sha256', $path);
        }
    }
    ksort($images, SORT_STRING);
    return $images;
}

function cmj_m4_capture(string $private, PDO $pdo, array $public, string $at, string $operation): array
{
    $private = cmj_m4_path($private, true);
    cmj_m4_path($private . '/drafts', true);
    $records = []; $drafts = [];
    foreach (glob($private . '/drafts/*.json') ?: [] as $file) {
        cmj_m4_path($file);
        $raw = file_get_contents($file); $draft = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
        carmaja_api_validate_draft_id($draft['draftId'] ?? '');
        cmj_model4_require(basename($file) === $draft['draftId'] . '.json', 'source_filename');
        $drafts[] = $draft;
        $records[] = ['id' => $draft['draftId'], 'raw' => base64_encode($raw), 'mode' => fileperms($file) & 0777];
    }
    cmj_model4_require($records !== [], 'empty_source');
    return cmj_m4_manifest($records, cmj_m4_rows($pdo), $public, cmj_m4_images($private, $drafts), $at, $operation, cmj_m4_target($private, $pdo));
}

/** No connection is opened here. Safety callbacks MUST be local-only, including before commit. */
final class CarmajaModel4Migration
{
    private array $plan;
    private string $digest;
    private string $job;
    private array $locks = [];
    private float $deadline;

    public function __construct(private string $private, private PDO $pdo, string $operation, string $approvedDigest,
        private Closure $safety, private ?Closure $checkpoint = null, private int $budgetMs = 40000)
    {
        $this->private = cmj_m4_path($private, true);
        cmj_model4_require(preg_match('/^model4-[a-z0-9-]{8,64}$/', $operation) === 1, 'manifest_identity');
        $this->job = cmj_m4_path($this->private . '/audit/' . $operation, true);
        $file = cmj_m4_path($this->job . '/manifest.json');
        $plan = json_decode(file_get_contents($file), true, 128, JSON_THROW_ON_ERROR);
        cmj_model4_require(preg_match('/^[a-f0-9]{64}$/', $approvedDigest) === 1
            && hash_equals($approvedDigest, cmj_model4_digest($plan)), 'manifest_binding');
        $expected = cmj_m4_manifest($plan['records'], $plan['beforeRows'], $plan['publicReference'], $plan['images'],
            $plan['at'], $plan['operationId'], $plan['targetHash']);
        cmj_model4_require(cmj_model4_json($expected) === cmj_model4_json($plan)
            && $plan['operationId'] === $operation, 'manifest_target_tampered');
        cmj_model4_require(hash_equals($plan['targetHash'], cmj_m4_target($this->private, $pdo)), 'target_changed');
        if (PHP_OS_FAMILY !== 'Windows') cmj_model4_require((fileperms($file) & 0777) === 0600
            && (fileperms($this->job) & 0777) === 0700, 'private_permissions');
        cmj_model4_require($budgetMs > 0 && $budgetMs <= 40000, 'budget_invalid');
        $this->plan = $plan; $this->digest = $approvedDigest;
        if (PHP_OS_FAMILY !== 'Windows') {
            foreach ([$this->private, $this->private . '/drafts', $this->private . '/locks'] as $directory) {
                cmj_m4_path($directory, true);
                cmj_model4_require((fileperms($directory) & 0022) === 0, 'private_permissions');
            }
        }
    }

    private function gate(): void
    {
        cmj_model4_require(microtime(true) < $this->deadline, 'migration_timeout');
        $state = ($this->safety)();
        foreach (['executionApproved', 'maintenancePresent', 'productAndAdminWritersStopped', 'checkoutsClosed',
            'paymentsDrained', 'backupRestoreVerified', 'privatePathsVerified'] as $key) {
            cmj_model4_require(($state[$key] ?? null) === true, 'safety_gate_failed');
        }
    }

    private function point(string $name): void
    {
        if ($this->checkpoint !== null) ($this->checkpoint)($name);
    }

    private function lock(): void
    {
        cmj_m4_path($this->private . '/locks', true);
        $names = ['model4-migration'];
        foreach ($this->plan['sources'] as $source) $names[] = 'draft-' . $source['id'];
        foreach ($names as $name) {
            $file = $this->private . '/locks/' . $name . '.lock';
            if (file_exists($file) || is_link($file)) cmj_m4_path($file);
            $handle = fopen($file, 'c');
            cmj_model4_require(is_resource($handle), 'lock_failed');
            $this->locks[] = $handle;
            cmj_model4_require(flock($handle, LOCK_EX | LOCK_NB), 'migration_busy');
        }
    }

    private function fileStates(): array
    {
        $expectedNames = array_map(static fn ($s) => $s['id'] . '.json', $this->plan['sources']);
        $actualNames = array_map('basename', glob($this->private . '/drafts/*.json') ?: []);
        sort($expectedNames); sort($actualNames);
        cmj_model4_require($expectedNames === $actualNames, 'source_set_changed');
        $states = [];
        foreach ($this->plan['sources'] as $source) {
            $file = cmj_m4_path($this->private . '/drafts/' . $source['id'] . '.json');
            cmj_model4_require((fileperms($file) & 0777) === $source['mode'], 'source_mode_changed');
            $hash = hash_file('sha256', $file);
            cmj_model4_require(in_array($hash, [$source['beforeHash'], $source['afterHash']], true), 'source_changed');
            $states[$source['id']] = $source['beforeHash'] === $source['afterHash'] ? 'unchanged'
                : ($hash === $source['beforeHash'] ? 'before' : 'after');
        }
        $originals = array_map(static fn ($r) => json_decode(base64_decode($r['raw'], true), true, 64, JSON_THROW_ON_ERROR), $this->plan['records']);
        cmj_model4_require(cmj_m4_images($this->private, $originals) === $this->plan['images'], 'images_changed');
        return $states;
    }

    private function databaseState(bool $lock = false): string
    {
        $actual = cmj_model4_json(cmj_m4_rows($this->pdo, $lock));
        if ($actual === cmj_model4_json($this->plan['beforeRows'])) return 'before';
        if ($actual === cmj_model4_json($this->plan['afterRows'])) return 'after';
        throw new RuntimeException('commerce_changed');
    }

    private function state(): ?string
    {
        $file = $this->job . '/state.json';
        if (!file_exists($file) && !is_link($file)) return null;
        cmj_m4_path($file);
        $state = json_decode(file_get_contents($file), true, 16, JSON_THROW_ON_ERROR);
        cmj_model4_require(($state['manifestDigest'] ?? null) === $this->digest
            && in_array($state['phase'] ?? null, ['applying', 'committed', 'complete', 'rolling_back', 'rolled_back'], true), 'journal_conflict');
        return $state['phase'];
    }

    private function atomic(string $target, string $bytes, int $mode, string $label): void
    {
        $temporary = $this->job . '/next-' . str_replace(':', '-', $label);
        cmj_model4_require(!file_exists($temporary) && !is_link($temporary), 'unexpected_temporary');
        cmj_model4_require((stat(dirname($target))['dev'] ?? null) === (stat($this->job)['dev'] ?? null), 'different_filesystem');
        if (file_exists($target) || is_link($target)) cmj_m4_path($target);
        $handle = fopen($temporary, 'x');
        cmj_model4_require(is_resource($handle), 'write_failed');
        try {
            cmj_model4_require(chmod($temporary, $mode), 'mode_failed');
            for ($offset = 0; $offset < strlen($bytes); $offset += $written) {
                $written = fwrite($handle, substr($bytes, $offset));
                cmj_model4_require(is_int($written) && $written > 0, 'write_failed');
            }
            cmj_model4_require(fflush($handle) && fsync($handle), 'flush_failed');
            fclose($handle); $handle = null;
            $this->point('next_written:' . $label);
            $this->gate();
            $this->point('before_rename:' . $label);
            cmj_model4_require(rename($temporary, $target), 'rename_failed');
            clearstatcache(true, $target);
            $this->point('after_rename:' . $label);
        } finally {
            if (is_resource($handle)) fclose($handle);
            if (is_file($temporary) && !is_link($temporary)) unlink($temporary);
        }
    }

    private function journal(string $phase): void
    {
        $this->atomic($this->job . '/state.json', cmj_model4_json(['manifestDigest' => $this->digest, 'phase' => $phase]) . "\n", 0600, 'state');
    }

    private function clearTemporary(): void
    {
        // Only reserved scratch files in this exact, manifest-bound private job.
        $names = ['next-state', 'next-audit'];
        foreach ($this->plan['sources'] as $s) $names[] = 'next-source-' . $s['id'];
        foreach ($names as $name) {
            $file = $this->job . '/' . $name;
            if (file_exists($file) || is_link($file)) { cmj_m4_path($file); cmj_model4_require(unlink($file), 'cleanup_failed'); }
        }
    }

    /** Strictly read-only inspection; no lock files, journal or cleanup writes. */
    public function inspect(): array
    {
        $files = $this->fileStates(); $db = $this->databaseState(); $state = $this->state();
        if ($state === null) cmj_model4_require(!in_array('after', $files, true) && $db === 'before', 'unrecorded_recovery');
        if (in_array($state, ['committed', 'complete'], true)) {
            cmj_model4_require(!in_array('before', $files, true)
                && ($db === 'after' || $this->plan['beforeRows'] === $this->plan['afterRows']), 'completed_state_changed');
        }
        cmj_model4_require($db !== 'after' || !in_array('before', $files, true), 'invalid_recovery_state');
        return ['status' => 'manifest_state_consistent', 'phase' => $state ?? 'prepared'];
    }

    public function run(bool $rollbackFiles = false): array
    {
        $this->deadline = microtime(true) + $this->budgetMs / 1000;
        $this->gate();
        try {
            $this->lock(); $this->gate();
            $files = $this->fileStates(); $db = $this->databaseState(); $state = $this->state();
            if ($state === null) cmj_model4_require(!in_array('after', $files, true) && $db === 'before', 'unrecorded_recovery');
            if (in_array($state, ['committed', 'complete'], true)) {
                cmj_model4_require(!in_array('before', $files, true)
                    && ($db === 'after' || $this->plan['beforeRows'] === $this->plan['afterRows']), 'completed_state_changed');
            }
            cmj_model4_require($db !== 'after' || !in_array('before', $files, true), 'invalid_recovery_state');
            if ($rollbackFiles) {
                cmj_model4_require(!in_array($state, ['committed', 'complete'], true) && $db === 'before', 'rollback_after_commit_forbidden');
            } else cmj_model4_require(!in_array($state, ['rolling_back', 'rolled_back'], true), 'rollback_requires_new_plan');
            $this->clearTemporary();
            if ($rollbackFiles) {
                $this->journal('rolling_back');
                foreach ($this->plan['sources'] as $source) if ($files[$source['id']] === 'after') {
                    $this->gate();
                    cmj_model4_require($this->databaseState() === 'before', 'rollback_after_commit_forbidden');
                    $this->atomic($this->private . '/drafts/' . $source['id'] . '.json', base64_decode($source['before'], true), $source['mode'], 'source:' . $source['id']);
                }
                cmj_model4_require(!in_array('after', $this->fileStates(), true) && $this->databaseState() === 'before', 'rollback_incomplete');
                $this->journal('rolled_back');
                return ['status' => 'files_rolled_back', 'inventoryChanged' => false, 'published' => false];
            }
            if ($state === null) $this->journal('applying');
            foreach ($this->plan['sources'] as $source) if ($files[$source['id']] === 'before') {
                $this->gate();
                cmj_model4_require(hash_file('sha256', $this->private . '/drafts/' . $source['id'] . '.json') === $source['beforeHash'], 'source_changed');
                $this->atomic($this->private . '/drafts/' . $source['id'] . '.json', base64_decode($source['after'], true), $source['mode'], 'source:' . $source['id']);
            }
            $this->gate();
            cmj_model4_require(!in_array('before', $this->fileStates(), true), 'source_write_incomplete');
            if ($db === 'before' && $this->plan['beforeRows'] !== $this->plan['afterRows']) {
                cmj_model4_require($this->pdo->beginTransaction(), 'transaction_start_failed');
                try {
                    cmj_model4_require($this->databaseState(true) === 'before', 'commerce_changed');
                    $statement = $this->pdo->prepare('UPDATE commerce_products SET product_version=?, source_hash=?, synchronized_at=?, updated_at=? WHERE product_id=? AND product_version=? AND source_hash=?');
                    foreach ($this->plan['afterRows'] as $i => $row) {
                        $old = $this->plan['beforeRows'][$i];
                        if ($old === $row) continue;
                        cmj_model4_require(microtime(true) < $this->deadline, 'migration_timeout');
                        $statement->execute([$row['product_version'], $row['source_hash'], $row['synchronized_at'], $row['updated_at'],
                            $row['product_id'], $old['product_version'], $old['source_hash']]);
                        cmj_model4_require($statement->rowCount() === 1, 'database_write_mismatch');
                        $this->point('sql:' . $row['product_id']);
                    }
                    cmj_model4_require($this->databaseState() === 'after', 'database_write_mismatch');
                    $this->point('before_commit');
                    $this->gate();
                    $this->point('commit_ready');
                    cmj_model4_require($this->pdo->commit(), 'commit_not_confirmed');
                } catch (Throwable $error) { if ($this->pdo->inTransaction()) $this->pdo->rollBack(); throw $error; }
                $this->point('after_commit');
            }
            $this->gate();
            $this->journal('committed');
            $audit = cmj_model4_json(['action' => 'product_model4_migration', 'operationId' => $this->plan['operationId'],
                'manifestDigest' => $this->digest, 'at' => $this->plan['at'], 'publication' => false, 'inventoryChanged' => false,
                'products' => array_map(static fn ($e) => array_intersect_key($e, array_flip(['productId', 'beforeVersion', 'afterVersion'])), $this->plan['report']['entries'])]) . "\n";
            $auditFile = $this->job . '/audit.json';
            if (file_exists($auditFile) || is_link($auditFile)) cmj_model4_require(file_get_contents(cmj_m4_path($auditFile)) === $audit, 'audit_conflict');
            else $this->atomic($auditFile, $audit, 0600, 'audit');
            $this->journal('complete');
            return ['status' => 'model4_complete', 'products' => count(array_filter($this->plan['sources'], static fn ($s) => $s['beforeHash'] !== $s['afterHash'])),
                'inventoryChanged' => false, 'published' => false];
        } finally {
            foreach (array_reverse($this->locks) as $handle) { flock($handle, LOCK_UN); fclose($handle); }
            $this->locks = [];
        }
    }
}
