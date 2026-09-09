<?php
declare(strict_types=1);
namespace App\Services;

final class Cap2TransportClock
{
    public static float $now = 1000.0;
    public static ?\Closure $tick = null;
}
function microtime(bool $asFloat = false): float|string
{
    if (Cap2TransportClock::$tick !== null) { (Cap2TransportClock::$tick)(); }
    return $asFloat ? Cap2TransportClock::$now : (string) Cap2TransportClock::$now;
}
