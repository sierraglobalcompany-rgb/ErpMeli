<?php
declare(strict_types=1);
namespace App\QueueCore;
final class QueueScheduler
{
    public const LANES=['fresh_orders','recovery','normal','historical_backfill','local'];
    /** @param list<array{id:int}> $rows @return list<array{id:int}> */
    public function fifo(array $rows): array
    {
        usort($rows,static fn(array $a,array $b):int=>$a['id']<=>$b['id']);
        return $rows;
    }
}
