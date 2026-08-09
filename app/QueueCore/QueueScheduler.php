<?php
declare(strict_types=1);
namespace App\QueueCore;
final class QueueScheduler
{
    public const LANES=['fresh_orders','recovery','normal','historical_backfill','local'];
    /** @var list<string> */
    public const WEIGHTED_CYCLE=['fresh_orders','fresh_orders','recovery','normal','fresh_orders','historical_backfill','normal','fresh_orders','recovery','local'];
    /** @return list<string> */
    public function laneOrder(int $position): array { $size=count(self::WEIGHTED_CYCLE);$position=(($position%$size)+$size)%$size;$primary=self::WEIGHTED_CYCLE[$position];return array_values(array_unique(array_merge([$primary],self::LANES))); }
    public function nextPosition(int $position): int { return ($position+1)%count(self::WEIGHTED_CYCLE); }
}
