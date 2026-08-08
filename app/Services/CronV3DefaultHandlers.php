<?php

declare(strict_types=1);

namespace App\Services;

final class CronV3DefaultHandlers
{
    public static function register(CronV3HandlerRegistry $handlers): void
    {
        $handlers->register(
            'cron_v3_health_snapshot',
            'local',
            static fn (WorkEnvelope $work, CronV3ExecutionContext $context): WorkResult =>
                WorkResult::completed(['kind' => 'cron_v3_health_snapshot']),
        );
    }
}
