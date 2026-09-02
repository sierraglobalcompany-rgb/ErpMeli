<?php

declare(strict_types=1);

namespace App\Work\Adapters;

use App\QueueV4Clean\QueueV4CleanScheduler;
use App\Work\Contracts\DrainerContract;
use App\Work\DrainResult;
use PDO;

final class QueueV4CurrentDrainer implements DrainerContract
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function drain(string $drainerId, int $maxPhysicalApiCalls, int $runtimeSeconds): DrainResult
    {
        if ($drainerId !== 'cron_v4') {
            return new DrainResult(false, 'drainer_denied', 0, 0, 0, [
                'active_drainer_denied_or_fail_safe' => true,
                'fifo_not_skipped' => true,
            ]);
        }

        $result = (new QueueV4CleanScheduler($this->pdo))->run($maxPhysicalApiCalls, $runtimeSeconds);

        return new DrainResult(
            (bool) ($result['ok'] ?? false),
            (string) ($result['status'] ?? 'unknown'),
            (int) ($result['physical_http_calls'] ?? 0),
            (int) ($result['worker']['completed'] ?? 0),
            (int) ($result['worker']['deferred'] ?? 0),
            $result,
        );
    }
}
