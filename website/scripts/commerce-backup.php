<?php

declare(strict_types=1);

/**
 * AP2 backup/restore adapter. It is intentionally library-like: no config is
 * discovered automatically and no command runs when this file is included.
 * A caller must pass an explicit, private configuration and a temporary
 * target. Passwords are written only to mode-0600 option files, never to
 * argv, logs or exception messages.
 */

final class CarmajaCommerceBackupException extends RuntimeException
{
}

final class CarmajaCommerceBackup
{
    public function __construct(
        private readonly string $dumpBinary = '/usr/bin/mysqldump',
        private readonly string $restoreBinary = '/usr/bin/mysql'
    ) {
    }

    public function validateTargets(array $config): void
    {
        foreach (['source', 'restore'] as $name) {
            if (!is_array($config[$name] ?? null)) {
                throw new CarmajaCommerceBackupException('target_missing');
            }
            foreach (['host', 'port', 'database', 'user', 'password'] as $key) {
                if (!array_key_exists($key, $config[$name])) {
                    throw new CarmajaCommerceBackupException('target_incomplete');
                }
            }
        }

        $source = $config['source'];
        $restore = $config['restore'];
        if ($source['database'] === $restore['database']
            || $source['user'] === $restore['user']) {
            throw new CarmajaCommerceBackupException('targets_not_separate');
        }

        foreach ([$source['database'], $restore['database']] as $database) {
            if (preg_match('/(?:prod|production)/i', (string) $database) === 1) {
                throw new CarmajaCommerceBackupException('production_target_rejected');
            }
        }

        if (!is_file($this->dumpBinary) || !is_executable($this->dumpBinary)
            || !is_file($this->restoreBinary) || !is_executable($this->restoreBinary)) {
            throw new CarmajaCommerceBackupException('backup_tools_unavailable');
        }
    }

    public function backupRestoreCompare(array $config, string $dumpPath): array
    {
        $this->validateTargets($config);
        $this->assertPrivateTempPath($dumpPath);
        $sourceOptions = $restoreOptions = null;

        try {
            $sourceOptions = $this->writeOptions($config['source']);
            $restoreOptions = $this->writeOptions($config['restore']);
            foreach (['source' => $sourceOptions, 'restore' => $restoreOptions] as $label => $options) {
                if (($config[$label]['requireTls'] ?? false) === true) {
                    $tls = $this->run([$this->restoreBinary, '--defaults-file=' . $options,
                        '--batch', '--skip-column-names',
                        "--execute=SHOW SESSION STATUS WHERE Variable_name IN ('Ssl_cipher','Ssl_version')",
                        $config[$label]['database']]);
                    if (preg_match('/^Ssl_cipher\s+\S+/m', $tls) !== 1
                        || preg_match('/^Ssl_version\s+\S+/m', $tls) !== 1) {
                        throw new CarmajaCommerceBackupException('backup_cli_tls_inactive');
                    }
                }
            }
            $this->run([
                $this->dumpBinary,
                '--defaults-file=' . $sourceOptions,
                '--single-transaction',
                '--routines',
                '--triggers',
                '--no-tablespaces',
                '--set-gtid-purged=OFF',
                '--hex-blob',
                '--result-file=' . $dumpPath,
                $config['source']['database'],
            ]);
            $dumpHash = hash_file('sha256', $dumpPath);
            if (!is_string($dumpHash)) {
                throw new CarmajaCommerceBackupException('dump_hash_failed');
            }

            $this->run([
                $this->restoreBinary,
                '--defaults-file=' . $restoreOptions,
                $config['restore']['database'],
            ], $dumpPath);

            return [
                'status' => 'restored',
                'dumpSha256' => $dumpHash,
                'sourceDatabaseDifferent' => true,
                'restoreDatabaseDifferent' => true,
            ];
        } finally {
            if (is_string($sourceOptions)) { @unlink($sourceOptions); }
            if (is_string($restoreOptions)) { @unlink($restoreOptions); }
        }
    }

    private function writeOptions(array $target): string
    {
        $path = tempnam(sys_get_temp_dir(), 'cmj-ap2-cnf-');
        if (!is_string($path)) {
            throw new CarmajaCommerceBackupException('tempfile_failed');
        }

        $content = "[client]\n"
            . 'host=' . $this->optionValue($target['host']) . "\n"
            . 'port=' . (int) $target['port'] . "\n"
            . 'user=' . $this->optionValue($target['user']) . "\n"
            . 'password=' . $this->optionValue($target['password']) . "\n";
        $content .= "protocol=TCP\ndefault-character-set=utf8mb4\n";
        if (($target['requireTls'] ?? false) === true) {
            $ca = $target['tlsCaPath'] ?? '';
            $content .= 'ssl-mode=' . ($ca !== '' ? 'VERIFY_IDENTITY' : 'REQUIRED') . "\n";
            if ($ca !== '') { $content .= 'ssl-ca=' . $this->optionValue($ca) . "\n"; }
        }
        if (file_put_contents($path, $content, LOCK_EX) === false) {
            @unlink($path);
            throw new CarmajaCommerceBackupException('tempfile_write_failed');
        }
        chmod($path, 0600);
        return $path;
    }

    private function optionValue(mixed $value): string
    {
        if (!is_string($value) || $value === '' || strpbrk($value, "\r\n\0") !== false) {
            throw new CarmajaCommerceBackupException('credential_value_invalid');
        }
        return '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $value) . '"';
    }

    private function assertPrivateTempPath(string $path): void
    {
        $directory = realpath(dirname($path));
        if (!is_string($directory) || !is_writable($directory)
            || str_contains(strtolower(str_replace('\\', '/', $path)), '/production')) {
            throw new CarmajaCommerceBackupException('unsafe_temp_path');
        }
    }

    private function run(array $command, ?string $stdin = null): string
    {
        $spec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $process = proc_open($command, $spec, $pipes);
        if (!is_resource($process)) {
            throw new CarmajaCommerceBackupException('command_start_failed');
        }
        if ($stdin !== null) {
            $input = fopen($stdin, 'rb');
            if ($input === false) { proc_terminate($process); throw new CarmajaCommerceBackupException('restore_input_unavailable'); }
            try { stream_copy_to_stream($input, $pipes[0]); }
            finally { fclose($input); }
        }
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($process);
        if ($exit !== 0) {
            unset($stdout, $stderr);
            throw new CarmajaCommerceBackupException('backup_command_failed');
        }
        return $stdout;
    }
}
