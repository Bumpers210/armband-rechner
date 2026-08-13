<?php

declare(strict_types=1);

require_once __DIR__ . '/ap5-worker.php';
require_once __DIR__ . '/production-backup.php';

final class CarmajaProductionMonitorException extends RuntimeException
{
}

final class CarmajaProductionMonitor
{
    private const WORKER_MAX_AGE_SECONDS = 900;
    private const REMINDER_SECONDS = 21600;
    private const STORAGE_WARNING_PERCENT = 90;
    private const STORAGE_MIN_FREE_BYTES = 1073741824;
    private const EXPECTED_WORKERS = ['commerce-v1', 'commerce-v1-brevo'];

    /** @var callable(array):array */
    private $sender;
    /** @var callable():array */
    private $backupStatus;
    /** @var callable():array */
    private $diskStatus;
    /** @var callable():int */
    private $clock;
    private string $stateFile;

    public function __construct(
        private readonly array $config,
        ?callable $sender = null,
        ?callable $backupStatus = null,
        ?callable $diskStatus = null,
        ?callable $clock = null,
        ?string $stateFile = null
    ) {
        $this->sender = $sender ?? function (array $notification): array {
            $client = new CarmajaBrevoClient($this->config);
            return $client->send([
                'recipient' => $this->config['monitorAlertEmail'],
                'dedupe_key' => 'production-monitor:' . $notification['notificationId'],
                'message_type' => 'production_monitor',
                'payload' => json_encode([
                    'subject' => $notification['subject'],
                    'htmlContent' => $notification['htmlContent'],
                ], JSON_THROW_ON_ERROR),
            ]);
        };
        $this->backupStatus = $backupStatus ?? function (): array {
            $runtimeFile = (string) ($this->config['configFile'] ?? '');
            $backupConfig = CarmajaProductionBackup::loadRuntime($runtimeFile);
            return (new CarmajaProductionBackup($backupConfig))->status();
        };
        $this->diskStatus = $diskStatus ?? function (): array {
            $root = (string) ($this->config['privateDir'] ?? '');
            $total = @disk_total_space($root);
            $free = @disk_free_space($root);
            if ((!is_float($total) && !is_int($total))
                || (!is_float($free) && !is_int($free))
                || $total <= 0 || $free < 0) {
                throw new CarmajaProductionMonitorException('monitor_storage_unavailable');
            }
            return ['totalBytes' => (int) $total, 'freeBytes' => (int) $free];
        };
        $this->clock = $clock ?? static fn (): int => time();
        $this->stateFile = $stateFile
            ?? rtrim((string) ($config['privateDir'] ?? ''), '/\\') . '/monitor/state.json';
    }

    public function run(?array $snapshot, array $runtimeIssues = []): array
    {
        if (($this->config['monitorEnabled'] ?? false) !== true) {
            return ['status' => 'disabled', 'issueCodes' => []];
        }
        if (($this->config['environment'] ?? null) !== 'production'
            || !filter_var($this->config['monitorAlertEmail'] ?? null, FILTER_VALIDATE_EMAIL)) {
            throw new CarmajaProductionMonitorException('monitor_configuration_invalid');
        }

        $issues = self::evaluate($snapshot, $runtimeIssues);
        try {
            $backup = ($this->backupStatus)();
            if (($backup['serverBackupOverdue'] ?? true) === true) {
                $issues['backup_server_overdue'] = ['code' => 'backup_server_overdue', 'count' => 1];
            }
            if (($backup['offsiteDownloadOverdue'] ?? true) === true) {
                $issues['backup_offsite_overdue'] = ['code' => 'backup_offsite_overdue', 'count' => 1];
            }
        } catch (Throwable) {
            $issues['backup_status_unavailable'] = ['code' => 'backup_status_unavailable', 'count' => 1];
        }

        try {
            $disk = ($this->diskStatus)();
            $total = (int) ($disk['totalBytes'] ?? 0);
            $free = (int) ($disk['freeBytes'] ?? -1);
            if ($total <= 0 || $free < 0 || $free > $total) {
                throw new CarmajaProductionMonitorException('monitor_storage_unavailable');
            }
            $usedPercent = (int) floor((($total - $free) / $total) * 100);
            if ($usedPercent >= self::STORAGE_WARNING_PERCENT
                || $free < self::STORAGE_MIN_FREE_BYTES) {
                $issues['storage_low'] = ['code' => 'storage_low', 'count' => $usedPercent];
            }
        } catch (Throwable) {
            $issues['storage_status_unavailable'] = ['code' => 'storage_status_unavailable', 'count' => 1];
        }

        ksort($issues);
        return $this->processState(array_values($issues), ($this->clock)());
    }

    public function sendTestAlert(): array
    {
        if (($this->config['monitorEnabled'] ?? false) !== true
            || ($this->config['environment'] ?? null) !== 'production'
            || !filter_var($this->config['monitorAlertEmail'] ?? null, FILTER_VALIDATE_EMAIL)) {
            throw new CarmajaProductionMonitorException('monitor_configuration_invalid');
        }
        $delivery = ($this->sender)([
            'notificationId' => 'test-' . gmdate('YmdHis', ($this->clock)()) . '-' . bin2hex(random_bytes(8)),
            'subject' => 'Carmaja-Produktion: Testwarnung',
            'htmlContent' => '<p>Dies ist die kontrollierte Testwarnung der Produktionsüberwachung.</p>'
                . '<p>Es besteht kein gemeldeter Produktionsfehler.</p>',
        ]);
        $outcome = (string) ($delivery['outcome'] ?? 'failed');
        if (!in_array($outcome, ['sent', 'delivery_unknown'], true)) {
            throw new CarmajaProductionMonitorException('monitor_notification_failed');
        }
        return ['status' => 'test_sent', 'delivery' => $outcome === 'sent' ? 'confirmed' : 'unknown'];
    }

    public static function evaluate(?array $snapshot, array $runtimeIssues = []): array
    {
        $issues = [];
        foreach ($runtimeIssues as $code) {
            if (in_array($code, ['worker_execution_failed', 'monitor_snapshot_failed'], true)) {
                $issues[$code] = ['code' => $code, 'count' => 1];
            }
        }
        if ($snapshot === null) {
            $issues['commerce_status_unavailable'] = [
                'code' => 'commerce_status_unavailable',
                'count' => 1,
            ];
            return $issues;
        }

        $workers = [];
        foreach (($snapshot['workers'] ?? []) as $worker) {
            if (is_array($worker) && is_string($worker['worker_name'] ?? null)) {
                $workers[$worker['worker_name']] = $worker;
            }
        }
        foreach (self::EXPECTED_WORKERS as $workerName) {
            $suffix = $workerName === 'commerce-v1' ? 'stripe' : 'mail';
            $worker = $workers[$workerName] ?? null;
            if (!is_array($worker) || ($worker['last_success_at'] ?? null) === null) {
                $issues['worker_' . $suffix . '_missing'] = [
                    'code' => 'worker_' . $suffix . '_missing', 'count' => 1,
                ];
                continue;
            }
            $age = filter_var($worker['success_age_seconds'] ?? null, FILTER_VALIDATE_INT);
            if ($age === false || $age > self::WORKER_MAX_AGE_SECONDS) {
                $issues['worker_' . $suffix . '_stale'] = [
                    'code' => 'worker_' . $suffix . '_stale', 'count' => max(1, (int) $age),
                ];
            }
            if (is_string($worker['last_error'] ?? null)
                && trim((string) $worker['last_error']) !== '') {
                $issues['worker_' . $suffix . '_error'] = [
                    'code' => 'worker_' . $suffix . '_error', 'count' => 1,
                ];
            }
        }

        $counts = [
            'webhookDue' => 'webhook_backlog',
            'webhookTerminal' => 'webhook_terminal',
            'mailDue' => 'mail_backlog',
            'mailTerminal' => 'mail_terminal',
            'metadataDue' => 'stripe_metadata_backlog',
            'metadataTerminal' => 'stripe_metadata_terminal',
            'reviewOpen' => 'review_cases_open',
        ];
        foreach ($counts as $field => $code) {
            $count = max(0, (int) ($snapshot[$field] ?? 0));
            if ($count > 0) {
                $issues[$code] = ['code' => $code, 'count' => $count];
            }
        }
        return $issues;
    }

    private function processState(array $issues, int $now): array
    {
        $directory = dirname($this->stateFile);
        if ((file_exists($directory) && (!is_dir($directory) || is_link($directory)))
            || (!is_dir($directory) && !mkdir($directory, 0750, true))) {
            throw new CarmajaProductionMonitorException('monitor_state_directory_invalid');
        }
        chmod($directory, 0750);
        $lockPath = $directory . '/state.lock';
        if (is_link($lockPath) || is_link($this->stateFile)) {
            throw new CarmajaProductionMonitorException('monitor_state_path_invalid');
        }
        $lock = fopen($lockPath, 'c');
        if ($lock === false || !flock($lock, LOCK_EX)) {
            if (is_resource($lock)) {
                fclose($lock);
            }
            throw new CarmajaProductionMonitorException('monitor_state_lock_failed');
        }
        chmod($lockPath, 0640);

        try {
            $state = $this->loadState();
            $codes = array_column($issues, 'code');
            sort($codes);
            $fingerprint = $codes === [] ? null : hash('sha256', implode('|', $codes));
            $activeFingerprint = is_string($state['activeFingerprint'] ?? null)
                ? $state['activeFingerprint'] : null;
            $lastNotificationAt = (int) ($state['lastNotificationAt'] ?? 0);
            $kind = null;
            if ($fingerprint !== null && $activeFingerprint !== $fingerprint) {
                $kind = 'alert';
            } elseif ($fingerprint !== null && $now - $lastNotificationAt >= self::REMINDER_SECONDS) {
                $kind = 'reminder';
            } elseif ($fingerprint === null && $activeFingerprint !== null) {
                $kind = 'recovery';
            }

            $state['lastCheckAt'] = gmdate(DATE_ATOM, $now);
            $state['lastIssueCodes'] = $codes;
            if ($kind === null) {
                $this->writeState($state);
                return ['status' => $fingerprint === null ? 'ok' : 'warning', 'issueCodes' => $codes];
            }

            $pending = is_array($state['pending'] ?? null) ? $state['pending'] : [];
            $pendingFingerprint = $fingerprint ?? 'healthy';
            if (($pending['kind'] ?? null) !== $kind
                || ($pending['fingerprint'] ?? null) !== $pendingFingerprint
                || !is_string($pending['notificationId'] ?? null)) {
                $pending = [
                    'kind' => $kind,
                    'fingerprint' => $pendingFingerprint,
                    'notificationId' => gmdate('YmdHis', $now) . '-' . bin2hex(random_bytes(8)),
                ];
            }
            $state['pending'] = $pending;
            $this->writeState($state);

            $notification = $this->notification($kind, $issues, $pending['notificationId']);
            $delivery = ($this->sender)($notification);
            $outcome = (string) ($delivery['outcome'] ?? 'failed');
            if (!in_array($outcome, ['sent', 'delivery_unknown'], true)) {
                throw new CarmajaProductionMonitorException('monitor_notification_failed');
            }

            unset($state['pending']);
            $state['activeFingerprint'] = $fingerprint;
            $state['lastNotificationAt'] = $now;
            $state['lastNotificationKind'] = $kind;
            $this->writeState($state);
            return [
                'status' => $kind === 'recovery' ? 'recovered' : 'alerted',
                'issueCodes' => $codes,
                'delivery' => $outcome === 'sent' ? 'confirmed' : 'unknown',
            ];
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function loadState(): array
    {
        if (!is_file($this->stateFile)) {
            return [];
        }
        $decoded = json_decode((string) file_get_contents($this->stateFile), true, 16);
        if (!is_array($decoded) || array_is_list($decoded)) {
            throw new CarmajaProductionMonitorException('monitor_state_invalid');
        }
        return $decoded;
    }

    private function writeState(array $state): void
    {
        $temporary = $this->stateFile . '.tmp-' . bin2hex(random_bytes(6));
        $json = json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
        if (file_put_contents($temporary, $json, LOCK_EX) === false) {
            throw new CarmajaProductionMonitorException('monitor_state_write_failed');
        }
        chmod($temporary, 0640);
        if (!rename($temporary, $this->stateFile)) {
            @unlink($temporary);
            throw new CarmajaProductionMonitorException('monitor_state_publish_failed');
        }
    }

    private function notification(string $kind, array $issues, string $notificationId): array
    {
        $labels = [
            'worker_execution_failed' => 'Der automatische Verarbeitungslauf ist fehlgeschlagen.',
            'monitor_snapshot_failed' => 'Der Datenbankstatus konnte nicht vollständig gelesen werden.',
            'commerce_status_unavailable' => 'Die Commerce-Datenbank ist für die Prüfung nicht erreichbar.',
            'worker_stripe_missing' => 'Für Stripe-Verarbeitung fehlt ein erfolgreicher Lauf.',
            'worker_stripe_stale' => 'Der letzte erfolgreiche Stripe-Lauf ist älter als 15 Minuten.',
            'worker_stripe_error' => 'Der Stripe-Worker meldet einen Fehler.',
            'worker_mail_missing' => 'Für E-Mail-Verarbeitung fehlt ein erfolgreicher Lauf.',
            'worker_mail_stale' => 'Der letzte erfolgreiche E-Mail-Lauf ist älter als 15 Minuten.',
            'worker_mail_error' => 'Der E-Mail-Worker meldet einen Fehler.',
            'webhook_backlog' => 'Stripe-Webhooks warten seit mehr als 15 Minuten.',
            'webhook_terminal' => 'Stripe-Webhooks benötigen eine manuelle Prüfung.',
            'mail_backlog' => 'E-Mails warten seit mehr als 15 Minuten.',
            'mail_terminal' => 'E-Mails benötigen eine manuelle Prüfung.',
            'stripe_metadata_backlog' => 'Stripe-Metadaten warten seit mehr als 15 Minuten.',
            'stripe_metadata_terminal' => 'Stripe-Metadaten benötigen eine manuelle Prüfung.',
            'review_cases_open' => 'Offene Prüffälle benötigen Aufmerksamkeit.',
            'backup_server_overdue' => 'Seit mehr als 90 Minuten fehlt ein neues Serverbackup.',
            'backup_offsite_overdue' => 'Seit mehr als 24 Stunden fehlt eine Offsite-Quittierung.',
            'backup_status_unavailable' => 'Der Backupstatus konnte nicht gelesen werden.',
            'storage_low' => 'Der gemeldete Serverspeicher ist knapp.',
            'storage_status_unavailable' => 'Der Serverspeicherstatus konnte nicht gelesen werden.',
        ];
        if ($kind === 'recovery') {
            return [
                'notificationId' => $notificationId,
                'subject' => 'Carmaja-Produktion: Entwarnung',
                'htmlContent' => '<p>Die automatische Prüfung meldet wieder einen unauffälligen Zustand.</p>',
            ];
        }
        $items = '';
        foreach ($issues as $issue) {
            $code = (string) ($issue['code'] ?? '');
            $label = htmlspecialchars($labels[$code] ?? 'Unbekannte Produktionswarnung.', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $count = max(1, (int) ($issue['count'] ?? 1));
            $suffix = str_ends_with($code, '_stale') || $code === 'storage_low'
                ? '' : ' (' . $count . ')';
            $items .= '<li>' . $label . $suffix . '</li>';
        }
        $prefix = $kind === 'reminder' ? 'Erinnerung: ' : '';
        return [
            'notificationId' => $notificationId,
            'subject' => $prefix . 'Carmaja-Produktion: Handlungsbedarf',
            'htmlContent' => '<p>Die automatische Prüfung hat Handlungsbedarf erkannt:</p><ul>'
                . $items . '</ul><p>Bitte den privaten Betriebsleitfaden verwenden. Es wurden keine Kundendaten mitgesendet.</p>',
        ];
    }
}
