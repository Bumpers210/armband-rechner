<?php

declare(strict_types=1);

require_once __DIR__ . '/commerce-core.php';

/**
 * AP2 worker primitive. The caller supplies the external-action callback; it
 * is invoked only after a lease is committed and never while a row lock is
 * held. The production CLI wiring and IONOS UnixCron entry belong to AP3.
 */
final class CarmajaCommerceWorker
{
    public function __construct(
        private readonly CarmajaCommercePdo $commerce,
        private readonly string $workerName = 'commerce-v1'
    ) {
    }

    public function run(callable $process, int $batchSize = 10, int $leaseSeconds = 600): array
    {
        if ($batchSize < 1 || $batchSize > 20 || $leaseSeconds < 600) {
            throw new CarmajaCommerceException('worker_configuration_invalid', 'Workergrenzen sind ungültig.', 500);
        }

        $leaseToken = carmaja_commerce_new_id();
        if (!$this->commerce->claimWorkerLease($this->workerName, $leaseToken, $leaseSeconds)) {
            return ['status' => 'locked', 'processed' => 0, 'durationMs' => 0];
        }

        $started = microtime(true);
        $processed = 0;
        $success = false;
        $errorMessage = null;

        try {
            $processed = (int) $process($batchSize);
            $success = true;
            return [
                'status' => 'completed',
                'processed' => $processed,
                'durationMs' => (int) round((microtime(true) - $started) * 1000),
            ];
        } catch (Throwable $error) {
            $errorMessage = mb_substr($error->getMessage(), 0, 500);
            throw $error;
        } finally {
            $this->commerce->releaseWorkerLease(
                $this->workerName,
                $leaseToken,
                $success,
                $errorMessage
            );
        }
    }
}

if (PHP_SAPI === 'cli' && basename(__FILE__) === basename((string) ($_SERVER['SCRIPT_FILENAME'] ?? ''))) {
    fwrite(STDERR, "AP2 worker requires application wiring; no network action was performed.\n");
    exit(0);
}
