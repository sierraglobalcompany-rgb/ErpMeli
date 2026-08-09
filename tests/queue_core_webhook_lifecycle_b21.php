<?php

declare(strict_types=1);

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';

$failures = [];
$check = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        $failures[] = $message;
    }
};
$read = static fn (string $file): string => (string) file_get_contents($root . '/' . $file);

$producer = $read('app/QueueCore/WebhookProducer.php');
$trigger = $read('app/QueueCore/WebhookTriggerService.php');
$lifecycle = $read('app/QueueCore/WebhookSpoolLifecycleService.php');
$spool = $read('app/Services/WebhookSpoolService.php');
$rollback = $read('app/Services/QueueCoreRollbackService.php');
$coalescer = $read('app/Services/NotificationCoalescerService.php');
$migration = $read('database/migrations/289_queue_core_webhook_lifecycle_b2_1.sql');

$check(
    str_contains($producer, '$this->repository->enqueue($job)')
        && !str_contains($producer, 'enqueueCoalescedExact'),
    'Una observación posterior todavía puede adherirse a un GET ya iniciado.'
);
$check(
    str_contains($trigger, 'observeSpool')
        && str_contains($trigger, 'markMaterialized')
        && str_contains($trigger, 'resolveForTrigger'),
    'Trigger y lifecycle no están ligados por generación.'
);
$check(
    str_contains($lifecycle, 'materialization_attempts=materialization_attempts+1')
        && str_contains($lifecycle, 'INTERVAL 10 MINUTE') === false
        && str_contains($lifecycle, "time() - 600"),
    'El crash de materializing no tiene recuperación acotada.'
);
$check(
    str_contains($spool, 'archiveLine')
        && str_contains($spool, 'quarantine_persistence_failed')
        && str_contains($spool, '$remaining[] = $trimmed'),
    'El source puede descartarse si falla archive/quarantine.'
);
$check(
    str_contains($rollback, 'replayUnresolvedToLegacy')
        && str_contains($rollback, 'webhooks_remaining')
        && str_contains($rollback, "'restore_incomplete'"),
    'Rollback no bloquea hasta reinyectar lifecycle unresolved.'
);
$check(
    str_contains($coalescer, 'QueueCoreOwnershipGuard::v4OwnsWebhook()'),
    'El consumidor coalescer legacy conserva una ruta HTTP bajo ownership V4.'
);
$check(
    str_contains($migration, "ENUM('received','materializing','materialized','resolved','archived')")
        && str_contains($migration, 'uq_queue_core_webhook_spool_key'),
    'La migración 289 no persiste el lifecycle o su identidad idempotente.'
);

if ($failures !== []) {
    foreach ($failures as $failure) {
        fwrite(STDERR, 'FAIL ' . $failure . PHP_EOL);
    }
    exit(1);
}

echo "PASS queue_core_webhook_lifecycle_b21\n";
