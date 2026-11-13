<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/test-api-private/program/product-api.php';
require_once dirname(__DIR__, 2) . '/scripts/product-model4-production.php';

// Protocol double only. The isolated MySQL CI also exercises the real backup
// callback on a new connection to a server whose default timezone is not UTC.
final class RestoreUtcStatement extends PDOStatement
{
    public function __construct(private array $rows) {}
    public function fetchColumn(int $column = 0): mixed { return $this->rows[0][$column] ?? false; }
    public function fetchAll(int $mode = PDO::FETCH_DEFAULT, mixed ...$args): array { return $this->rows; }
}

final class RestoreUtcPdo extends PDO
{
    public array $queries = [];
    public string $setMode = 'ok';
    public bool $readbackFails = false;
    public function __construct(public string $zone, public array $rows) {}
    public function getAttribute(int $attribute): mixed { return 'mysql'; }
    public function exec(string $statement): int|false
    {
        $this->queries[] = $statement;
        if ($statement !== "SET SESSION time_zone = '+00:00'") throw new LogicException('unexpected_write');
        if ($this->setMode === 'exception') throw new PDOException('artificial_set_failure');
        if ($this->setMode === 'false') return false;
        if ($this->setMode === 'ok') $this->zone = '+00:00';
        return 0;
    }
    public function query(string $query, ?int $fetchMode = null, mixed ...$args): PDOStatement|false
    {
        $this->queries[] = $query;
        if ($query === 'SELECT @@session.time_zone') {
            return $this->readbackFails ? false : new RestoreUtcStatement([[$this->zone]]);
        }
        if ($query !== 'SELECT * FROM commerce_products ORDER BY product_id') throw new LogicException('unexpected_query');
        $rows = $this->rows;
        foreach ($rows as &$row) {
            foreach (['synchronized_at', 'updated_at'] as $column) {
                if ($row[$column] === null) continue;
                $row[$column] = (new DateTimeImmutable($row[$column], new DateTimeZone('UTC')))
                    ->setTimezone(new DateTimeZone($this->zone))->format('Y-m-d H:i:s.u');
            }
        }
        return new RestoreUtcStatement($rows);
    }
}

$checks = 0;
function utc_check(bool $value): void
{
    global $checks;
    if (!$value) throw new LogicException('utc_assertion_failed');
    $checks++;
}
function utc_reject(callable $action, string $code): void
{
    try { $action(); } catch (Throwable $error) { utc_check($error->getMessage() === $code); return; }
    throw new LogicException('utc_expected_refusal');
}

$root = str_replace('\\', '/', sys_get_temp_dir()) . '/cmj-restore-utc-' . bin2hex(random_bytes(6));
mkdir($root, 0700); mkdir($root . '/drafts', 0700);
$rows = [
    ['product_id' => 'artificial-a', 'name' => 'unchanged', 'synchronized_at' => '2026-12-31 23:45:12.123456', 'updated_at' => '2026-01-01 00:15:43.654321'],
    ['product_id' => 'artificial-b', 'name' => 'unchanged', 'synchronized_at' => null, 'updated_at' => '2026-10-25 01:30:00.000001'],
];
$plan = ['beforeRows' => array_map('cmj_m4_row', $rows), 'sources' => [], 'images' => []];
try {
    foreach (['+02:00', '-07:00', '+05:30', '+00:00'] as $zone) {
        $pdo = new RestoreUtcPdo($zone, $rows);
        cmj_m4p_restore_snapshot($pdo, $root, $plan);
        utc_check($pdo->zone === '+00:00' && $pdo->rows === $rows);
        utc_check($pdo->queries === ["SET SESSION time_zone = '+00:00'", 'SELECT @@session.time_zone', 'SELECT * FROM commerce_products ORDER BY product_id']);
        cmj_m4p_restore_snapshot($pdo, $root, $plan); // Repeated comparison stays idempotent.
        utc_check($pdo->rows === $rows);
    }
    foreach (['false', 'ignored', 'exception', 'readback'] as $mode) {
        $pdo = new RestoreUtcPdo('+02:00', $rows);
        $pdo->setMode = $mode === 'readback' ? 'ok' : $mode;
        $pdo->readbackFails = $mode === 'readback';
        utc_reject(fn () => cmj_m4p_restore_snapshot($pdo, $root, $plan), $mode === 'exception' ? 'artificial_set_failure' : 'utc_session_required');
        utc_check(!in_array('SELECT * FROM commerce_products ORDER BY product_id', $pdo->queries, true));
    }
    foreach (['updated_at' => '2026-01-01 00:15:43.654322', 'name' => 'different', 'synchronized_at' => null] as $column => $value) {
        $changed = $rows; $changed[0][$column] = $value;
        utc_reject(fn () => cmj_m4p_restore_snapshot(new RestoreUtcPdo('+02:00', $changed), $root, $plan), 'backup_source_mismatch');
    }
    // UTC must not bypass the file-image binding either.
    $file = $root . '/drafts/artificial.json'; file_put_contents($file, 'original'); chmod($file, 0600);
    $filePlan = $plan; $filePlan['sources'] = [['id' => 'artificial', 'beforeHash' => hash('sha256', 'original')]];
    cmj_m4p_restore_snapshot(new RestoreUtcPdo('+02:00', $rows), $root, $filePlan);
    file_put_contents($file, 'changed');
    utc_reject(fn () => cmj_m4p_restore_snapshot(new RestoreUtcPdo('+02:00', $rows), $root, $filePlan), 'backup_source_mismatch');
} finally {
    if (is_file($root . '/drafts/artificial.json')) unlink($root . '/drafts/artificial.json');
    rmdir($root . '/drafts'); rmdir($root);
}
utc_check(!file_exists($root));
echo "$checks restore UTC checks passed (protocol double; no server access).\n";
