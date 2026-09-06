<?php
declare(strict_types=1);

require __DIR__ . '/cap2_manual_fixture.php';

use App\Services\CapacityPolicyService;
use App\Services\ManualCampaignPreviewService;

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
    for ($i = 1; $i <= 61; $i++) {
        $insert->execute([(string) (810000 + $i)]);
    }
    (new App\Services\WorkQueueProjectionService())->refreshQueue('financial_recalc');

    $policy = (new CapacityPolicyService())->snapshot('manual');
    $preview = (new ManualCampaignPreviewService())->create(9007, [
        'scope' => 'finance',
        'account_id' => 9011,
        'physical_api_call_budget' => 1,
        'capacity_revision' => $policy['revision'],
    ]);

    $assert(($preview['configuration']['preview_format'] ?? 0) === 4, 'preview_format_four');
    $assert(!isset($preview['configuration']['block_size'], $preview['configuration']['interval_ms'], $preview['configuration']['max_blocks']), 'legacy_campaign_knobs_not_persisted');
    $assert(count($preview['rows']) === 60, 'fixed_presentation_limit_independent_of_http_budget');
    $assert(!empty($preview['has_more']) && (int) $preview['eligible_jobs'] === 61, 'truthful_has_more_and_total');
    foreach ($preview['rows'] as $row) {
        $assert(str_starts_with((string) ($row['selection_id'] ?? ''), 'exact:financial_recalc:'), 'exact_selection_identity_persisted');
        $assert(preg_match('/^[a-f0-9]{64}$/', (string) ($row['selection_version'] ?? '')) === 1, 'exact_selection_version_persisted');
        $assert(preg_match('/^[a-f0-9]{64}$/', (string) ($row['source_authority_version'] ?? '')) === 1, 'durable_authority_persisted_at_preview');
    }

    $legacyToken = bin2hex(random_bytes(20));
    $legacy = ['preview_format' => 3, 'scope' => 'finance', 'account_id' => 9011,
        'physical_api_call_budget' => 1, 'capacity_revision' => $policy['revision']];
    $pdo->prepare(
        'INSERT INTO manual_campaign_previews
         (preview_token,created_by_user_id,scope_key,meli_account_id,configuration_hash,configuration_json,summary_json,expires_at)
         VALUES (?,9007,"finance",9011,?,?,"{}",DATE_ADD(UTC_TIMESTAMP(3),INTERVAL 10 MINUTE))'
    )->execute([$legacyToken, hash('sha256', $legacyToken), json_encode($legacy)]);
    $legacyRejected = false;
    try {
        (new ManualCampaignPreviewService())->load($legacyToken, 9007);
    } catch (RuntimeException) {
        $legacyRejected = true;
    }
    $assert($legacyRejected, 'legacy_preview_rejected_on_load');

    echo "STATUS=PASS CALLS_MANUAL_PREVIEW REAL_MELI_HTTP=0\n";
} finally {
    $harness->cleanup();
}
