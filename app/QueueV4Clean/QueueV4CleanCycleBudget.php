<?php

declare(strict_types=1);

namespace App\QueueV4Clean;

use App\Services\ApiBudgetExhaustedException;

/** In-process physical HTTP budget shared by all stages of one CLI cycle. */
final class QueueV4CleanCycleBudget
{
    private static ?int $limit = null;
    private static int $used = 0;

    public static function start(int $limit): void
    {
        self::$limit = max(1, min(10, $limit));
        self::$used = 0;
    }

    public static function clear(): void
    {
        self::$limit = null;
        self::$used = 0;
    }

    public static function claim(): void
    {
        if (self::$limit === null) {
            return;
        }
        if (self::$used >= self::$limit) {
            throw new ApiBudgetExhaustedException(
                'Queue V4 agotó el presupuesto físico de este ciclo.',
                gmdate('Y-m-d H:i:s', time() + 60)
            );
        }
        self::$used++;
    }

    public static function releaseBeforeTransport(): void
    {
        if (self::$limit !== null && self::$used > 0) {
            self::$used--;
        }
    }

    /** @return array{limit:int,used:int,remaining:int} */
    public static function snapshot(): array
    {
        $limit = self::$limit ?? 0;
        return ['limit' => $limit, 'used' => self::$used, 'remaining' => max(0, $limit - self::$used)];
    }
}
