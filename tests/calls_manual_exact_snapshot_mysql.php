<?php
declare(strict_types=1);

namespace App\QueueCore {
    final class ManualQueueLauncher
    {
        public static array $items = [];

        public function runExactBatch(array $items, ?int $physicalCallBudget = null, ?float $requestDeadline = null, ?callable $admit = null): array
        {
            if ($admit !== null) {
                $admit();
            }
            self::$items = $items;
            return [
                'status' => 'completed',
                'selected_count' => count($items),
                'processed_count' => count($items),
                'completed_count' => count($items),
                'deferred_count' => 0,
                'waiting_count' => 0,
                'review_error_count' => 0,
                'not_processed_count' => 0,
                'requested_api_calls' => $physicalCallBudget,
                'api_calls_used' => 0,
                'stop_reason' => 'selection_completed',
                'results' => array_map(static fn (array $item): array => [
                    'status' => 'completed',
                    'queue_key' => $item['queue_key'],
                    'source_id' => $item['source_id'],
                ], $items),
            ];
        }
    }
}

namespace {
require __DIR__ . '/cap2_manual_fixture.php';

use App\QueueCore\ManualQueueLauncher;
use App\Services\CapacityPolicyService;
use App\Services\ManualCampaignPreviewService;
use App\Services\ManualSingleStepService;

$assert = static function (bool $condition, string $label): void {
    if (!$condition) {
        throw new RuntimeException("FAIL:$label");
    }
    echo "PASS:$label\n";
};

$harness = cap2_manual_database();
try {
    $pdo = $harness->pdo();
    $insert = $pdo->prepare(
        "INSERT INTO order_financial_recalc_jobs
         (company_id,meli_account_id,status,total_items,processed_items,source_type,source_id)
         VALUES (9001,9011,'pending',1,0,'order',?)"
    );
    for ($i = 1; $i <= 3; $i++) {
        $insert->execute([(string) (820000 + $i)]);
    }
    (new App\Services\WorkQueueProjectionService())->refreshQueue('financial_recalc');
    $policy = (new CapacityPolicyService())->snapshot('manual');
    $previewService = new ManualCampaignPreviewService();
    $preview = $previewService->create(9007, [
        'scope' => 'finance', 'account_id' => 9011,
        'physical_api_call_budget' => 1, 'capacity_revision' => $policy['revision'],
    ]);
    $shownIds = array_column($preview['rows'], 'source_id');
    $assert(count($shownIds) === 3, 'three_exact_ids_shown');

    $changedId = (int) $shownIds[1];
    $pdo->prepare("UPDATE order_financial_recalc_jobs SET status='cancelled' WHERE id=?")->execute([$changedId]);
    $result = (new ManualSingleStepService())->executePreview((string) $preview['preview_token'], 9007, 1);
    $executedIds = array_column(ManualQueueLauncher::$items, 'source_id');
    $assert($executedIds === [$shownIds[0], $shownIds[2]], 'changed_source_skipped_without_substitution');
    $assert((int) $result['selected_count'] === 3 && (int) $result['stale_or_busy_skipped'] === 1, 'receipt_counts_shown_and_skipped');
    $assert((int) $result['physical_http_calls'] === 0 && (int) $result['effective_api_calls'] === 1, 'http_budget_independent_from_local_selection');
    $assert(cap2_manual_state($pdo, (string) $preview['preview_token']) === 'consumed', 'token_consumed_before_effect_boundary');
    $assert(cap2_manual_rejected(fn () => (new ManualSingleStepService())->executePreview((string) $preview['preview_token'], 9007, 1)), 'replay_rejected');

    $preview = $previewService->create(9007, [
        'scope' => 'finance', 'account_id' => 9011,
        'physical_api_call_budget' => 1, 'capacity_revision' => $policy['revision'],
    ]);
    $pdo->exec('DELETE FROM user_company_access WHERE user_id=9007 AND company_id=9001');
    $assert(cap2_manual_rejected(fn () => (new ManualSingleStepService())->executePreview((string) $preview['preview_token'], 9007, 1)), 'global_acl_revocation_blocks_entire_start');
    $assert(cap2_manual_state($pdo, (string) $preview['preview_token']) === 'ready', 'global_revocation_has_no_admission');

    echo "STATUS=PASS CALLS_MANUAL_EXACT_SNAPSHOT REAL_MELI_HTTP=0\n";
} finally {
    $harness->cleanup();
}
}
