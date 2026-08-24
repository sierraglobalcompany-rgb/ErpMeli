<?php

declare(strict_types=1);

namespace App\QueueV4Clean;

use App\Services\MeliReadClientInterface;
use App\Services\OrderSyncService;
use App\Services\SaleFinancialService;
use PDO;

final class QueueV4CleanWorker
{
    public const DEFAULT_MAX_JOBS = CanonicalFifoDrainer::DEFAULT_MAX_JOBS;
    public const HARD_MAX_JOBS = CanonicalFifoDrainer::HARD_MAX_JOBS;
    public const MIN_RUNTIME_SECONDS = CanonicalFifoDrainer::MIN_RUNTIME_SECONDS;
    public const MAX_RUNTIME_SECONDS = CanonicalFifoDrainer::MAX_RUNTIME_SECONDS;
    public const MAX_SCAN_ROWS = CanonicalFifoDrainer::MAX_SCAN_ROWS;
    public const MAX_SCAN_TIME_MS = CanonicalFifoDrainer::MAX_SCAN_TIME_MS;

    private CanonicalFifoDrainer $drainer;

    /**
     * Queue V4 is now only the storage/launcher adapter. The executable work
     * path lives in CanonicalFifoDrainer so CLI, manual exact and future
     * launchers share one KISS kernel instead of duplicating claim/complete.
     *
     * @param null|callable(int):MeliReadClientInterface $clientFactory
     * @param null|callable(int):OrderSyncService $syncFactory
     * @param null|callable(array<string,mixed>):void $jobHandler Test-only/local fixture seam.
     * @param null|callable(string,int,int):void $domainHandler Test-only domain source seam.
     * @param null|callable():SaleFinancialService $financialFactory Test-only billing transport seam.
     */
    public function __construct(
        PDO $pdo,
        QueueV4CleanRepository $repository,
        ?callable $clientFactory = null,
        ?callable $syncFactory = null,
        ?callable $jobHandler = null,
        ?callable $domainHandler = null,
        ?callable $financialFactory = null,
    ) {
        $this->drainer = new CanonicalFifoDrainer(
            $pdo,
            $repository,
            $clientFactory,
            $syncFactory,
            $jobHandler,
            $domainHandler,
            $financialFactory,
        );
    }

    /** @return array{claimed:int,completed:int,deferred:int} Legacy result contract; see lastMetrics(). */
    public function run(string $launcher, int $maxJobs = self::DEFAULT_MAX_JOBS, int $runtimeSeconds = 45): array
    {
        return $this->drainer->run($launcher, $maxJobs, $runtimeSeconds);
    }

    /** @return array{scanned:int,claimed:int,effective:int,completed:int,deferred:int,local_completed:int,local_deferred:int,remote_deferred:int} */
    public function lastMetrics(): array
    {
        return $this->drainer->lastMetrics();
    }

    public static function normalizeRuntimeSeconds(int $runtimeSeconds): int
    {
        return CanonicalFifoDrainer::normalizeRuntimeSeconds($runtimeSeconds);
    }
}
