<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$ownership = (string) file_get_contents($root . '/app/Services/CronV3OwnershipService.php');
$capabilities = (string) file_get_contents($root . '/app/Services/CronV3CutoverCapabilityRegistry.php');
$legacy = (string) file_get_contents($root . '/jobs/process_sync_queue.php');
$failures = [];
$check = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        $failures[] = $message;
    }
};

foreach (['orders_sync', 'order_enrichment', 'sale_financial_reconciliation', 'module_jobs'] as $queue) {
    $check(str_contains($ownership, "'" . $queue . "' =>"), 'Falta mapping ownership para ' . $queue . '.');
}
$check(str_contains($ownership, "\$v2Key === 'manual_campaign'"), 'V2 no debe volver a seleccionar campanas persistentes.');
$check(str_contains($ownership, 'canTransferV2') && str_contains($ownership, 'foreach ($required as $v3Type)'), 'El corte V2 exige familia certificada y todos sus tipos V3.');
$check(str_contains($capabilities, "'order_enrichment' => true") && !str_contains($capabilities, "'notification_fallback' => true"), 'Solo familias con adaptador completo pueden retirar V2.');
$check(str_contains($legacy, 'CronV3OwnershipService())->filterV2Definitions'), 'El launcher V2 no aplica ownership antes del planner.');

if ($failures !== []) {
    fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
    exit(1);
}
echo "OK cron_v3_ownership_fence_2290\n";
