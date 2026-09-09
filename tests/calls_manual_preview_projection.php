<?php
declare(strict_types=1);

$source = file_get_contents(__DIR__ . '/../app/Services/ManualCampaignPreviewService.php');
if (!is_string($source)) {
    throw new RuntimeException('FAIL:preview_service_source_readable');
}

$assert = static function (bool $condition, string $label): void {
    if (!$condition) {
        throw new RuntimeException('FAIL:' . $label);
    }
    echo 'PASS:' . $label . "\n";
};

$assert(
    !str_contains($source, 'new ManualCampaignService'),
    'current_preview_does_not_instantiate_historical_campaign_engine',
);
$assert(
    str_contains($source, 'expandDescriptionCandidates'),
    'description_children_are_projected_by_current_preview_service',
);
$assert(
    !str_contains($source, "['block_size']")
        && !str_contains($source, "['process_limit']"),
    'current_preview_has_no_hidden_resource_cutoff_authority',
);

echo "STATUS=PASS CALLS_MANUAL_PREVIEW_PROJECTION\n";
