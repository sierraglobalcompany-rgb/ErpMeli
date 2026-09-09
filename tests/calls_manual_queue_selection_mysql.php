<?php
declare(strict_types=1);

require __DIR__ . '/cap2_manual_fixture.php';

use App\QueueV4Clean\QueueV4CleanCycleBudget;
use App\QueueV4Clean\QueueV4CleanRepository;
use App\QueueV4Clean\QueueV4CleanWorker;

$assert = static function (bool $condition, string $label): void {
    if (!$condition) {
        throw new RuntimeException("FAIL:$label");
    }
    echo "PASS:$label\n";
};

$harness = cap2_manual_database();
try {
    $pdo = $harness->pdo();
    $repository = new QueueV4CleanRepository($pdo);
    $selectedIds = [];
    for ($i = 1; $i <= 3; $i++) {
        $selectedIds[] = $repository->enqueue(
            9001,
            9011,
            'order_exact',
            (string) (7000 + $i),
            'calls-manual-selected-' . $i,
            ['external_order_id' => (string) (7000 + $i)]
        );
    }
    $preview = $repository->previewEligible(60, [9011], 9011);
    $assert(count($preview) === 3, 'preview_contains_three_confirmed_pointers');
    foreach ($preview as $row) {
        $assert(preg_match('/^qv4:[0-9]+$/', (string) ($row['selection_id'] ?? '')) === 1, 'stable_selection_id_present');
        $assert(preg_match('/^[a-f0-9]{64}$/', (string) ($row['selection_version'] ?? '')) === 1, 'stable_selection_version_present');
    }

    // This new FIFO winner was never shown and cannot substitute a selection.
    $newId = $repository->enqueue(
        9001,
        9011,
        'order_exact',
        '6999',
        'calls-manual-new-priority',
        ['external_order_id' => '6999']
    );
    $pdo->prepare('UPDATE queue_v4_clean_jobs SET available_at=DATE_SUB(UTC_TIMESTAMP(3),INTERVAL 1 HOUR) WHERE id=?')->execute([$newId]);
    // One shown pointer changed after preview and must be skipped, not replaced.
    $changedId = $selectedIds[1];
    $pdo->prepare('UPDATE queue_v4_clean_jobs SET payload_json=? WHERE id=?')->execute([
        json_encode(['external_order_id' => 'changed-after-preview']),
        $changedId,
    ]);

    $handled = [];
    QueueV4CleanCycleBudget::start(1, 'manual', microtime(true) + 40);
    try {
        $result = (new QueueV4CleanWorker(
            $pdo,
            $repository,
            null,
            null,
            static function (array $job) use (&$handled): void {
                $handled[] = (int) $job['id'];
            }
        ))->run('manual', 1, 30, [9011], 9011, $preview);
    } finally {
        QueueV4CleanCycleBudget::clear();
    }

    sort($handled);
    $expected = [$selectedIds[0], $selectedIds[2]];
    sort($expected);
    $assert($handled === $expected, 'only_unchanged_confirmed_pointers_processed');
    $assert((int) $result['selected_count'] === 3, 'receipt_counts_confirmed_selection');
    $assert((int) $result['stale_or_busy_skipped'] === 1, 'changed_pointer_reported_skipped');
    $states = $pdo->query('SELECT id,state FROM queue_v4_clean_jobs')->fetchAll(PDO::FETCH_KEY_PAIR);
    $assert(($states[$newId] ?? '') === 'ready', 'new_higher_priority_pointer_untouched');
    $assert(($states[$changedId] ?? '') === 'ready', 'changed_selected_pointer_untouched');

    echo "STATUS=PASS CALLS_MANUAL_QUEUE_SELECTION REAL_MELI_HTTP=0\n";
} finally {
    $harness->cleanup();
}
