<?php
declare(strict_types=1);
require __DIR__ . '/k1b_bootstrap.php';
require __DIR__ . '/cap2_transport_clock_fixture.php';
use App\Services\Cap2TransportClock as Clock;
use App\Services\CronDeadlineContext as Deadline;

// Break caught: nesting a looser step window used to extend the admitted deadline.
Deadline::start(45,43,20,3);
Deadline::within(1008.0, static function (): void {
    Deadline::within(1040.0, static function (): void {
        k1b_assert(Deadline::deadline() === 1008.0, 'nested_deadline_cannot_extend_outer_window');
    });
});
Deadline::clear();
// Break caught: a scoped web/manual deadline was ignored by physical timeout calculation.
Deadline::within(1005.0, static function (): void {
    k1b_assert(Deadline::curlTimeouts()['timeout'] <= 4, 'scoped_deadline_bounds_physical_timeout');
    Clock::$now=1004.0;
    $blocked=false;
    try { Deadline::curlTimeouts(); } catch (App\Services\CronDeadlineDeferredException) { $blocked=true; }
    k1b_assert($blocked, 'expired_scoped_deadline_prevents_send');
});
Deadline::clear();
echo "CAP2_TRANSPORT_DEADLINE_OK\n";
