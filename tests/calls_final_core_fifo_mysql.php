<?php
declare(strict_types=1);

require __DIR__ . '/cap2_manual_fixture.php';

use App\QueueCore\QueueCoreRepository;
use App\QueueCore\QueueJob;
use App\QueueCore\QueueRunRequest;

$assert = static function (bool $condition, string $label, array $context = []): void {
    if (!$condition) {
        throw new RuntimeException('FAIL:' . $label . ':' . json_encode($context, JSON_UNESCAPED_SLASHES));
    }
    echo 'PASS:' . $label . PHP_EOL;
};

$harness = cap2_manual_database();
try {
    $pdo = $harness->pdo();
    $repo = new QueueCoreRepository($pdo);

    $laterId = $repo->enqueue(new QueueJob(
        9001,
        9011,
        'order_exact',
        'order',
        'later',
        'normal',
        0,
        'calls-final-core-fifo-later',
        'v1',
        'test',
        null,
        [],
        [],
        1,
        '2000-01-02 00:00:00.000',
        'operational'
    ));
    $earlierId = $repo->enqueue(new QueueJob(
        9001,
        9011,
        'order_exact',
        'order',
        'earlier',
        'normal',
        0,
        'calls-final-core-fifo-earlier',
        'v1',
        'test',
        null,
        [],
        [],
        1,
        '2000-01-01 00:00:00.000',
        'operational'
    ));

    $peek = $repo->peekOldestEligible(['order_exact'], 'operational');
    $assert((int) ($peek['id'] ?? 0) === $earlierId, 'peek_uses_available_at_then_id', [
        'expected' => $earlierId,
        'actual' => $peek['id'] ?? null,
        'later_id' => $laterId,
    ]);

    $claim = $repo->claimNext(new QueueRunRequest(
        'cron_v4',
        'calls-final-core-fifo',
        1,
        microtime(true) + 20,
        30
    ), ['order_exact']);

    $assert($claim !== null && $claim->id === $earlierId, 'claim_uses_available_at_then_id', [
        'expected' => $earlierId,
        'actual' => $claim?->id,
        'later_id' => $laterId,
    ]);

    echo "STATUS=PASS CALLS_FINAL_CORE_FIFO MYSQL=REAL REAL_MELI_HTTP=0\n";
} finally {
    $harness->cleanup();
}
