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
        $sourceOptions = $this->writeOptions($config['source']);
        $restoreOptions = $this->writeOptions($config['restore']);

        try {
            $this->run([
                $this->dumpBinary,
                '--defaults-extra-file=' . $sourceOptions,
                '--single-transaction',
                '--routines',
                '--triggers',
                '--result-file=' . $dumpPath,
                $config['source']['database'],
            ]);
            $dumpHash = hash_file('sha256', $dumpPath);
            if (!is_string($dumpHash)) {
                throw new CarmajaCommerceBackupException('dump_hash_failed');
            }

            $this->run([
                $this->restoreBinary,
                '--defaults-extra-file=' . $restoreOptions,
                $config['restore']['database'],
            ], $dumpPath);

            return [
                'status' => 'restored',
                'dumpSha256' => $dumpHash,
                'sourceDatabaseDifferent' => true,
                'restoreDatabaseDifferent' => true,
            ];
        } finally {
            @unlink($sourceOptions);
            @unlink($restoreOptions);
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
        if (file_put_contents($path, $content, LOCK_EX) === false) {
            @unlink($path);
            throw new CarmajaCommerceBackupException('tempfile_write_failed');
        }
        chmod($path, 0600);
        return $path;
    }

    private function optionValue(mixed $value): string
    {
        if (!is_string($value) || $value === '' || str_contains($value, "\n")) {
            throw new CarmajaCommerceBackupException('credential_value_invalid');
        }
        return $value;
    }

    private function assertPrivateTempPath(string $path): void
    {
        $directory = realpath(dirname($path));
        if (!is_string($directory) || !is_writable($directory)
            || str_contains(strtolower(str_replace('\\', '/', $path)), '/production')) {
            throw new CarmajaCommerceBackupException('unsafe_temp_path');
        }
    }

    private function run(array $command, ?string $stdin = null): void
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
            fwrite($pipes[0], $stdin);
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
    }
}
