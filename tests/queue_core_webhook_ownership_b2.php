<?php
declare(strict_types=1);

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';
spl_autoload_register(static function (string $class) use ($root): void {
    if (str_starts_with($class, 'App\\')) {
        $path = $root . '/app/' . str_replace('\\', '/', substr($class, 4)) . '.php';
        if (is_file($path)) {
            require $path;
        }
    }
}, true, true);

$failures = [];
$check = static function (bool $ok, string $message) use (&$failures): void {
    if (!$ok) {
        $failures[] = $message;
    }
};
$read = static fn (string $path): string => (string) file_get_contents($root . '/' . $path);

$public = $read('public/webhook_mercadolibre.php');
$spool = $read('app/Services/WebhookSpoolService.php');
$producer = $read('app/QueueCore/WebhookProducer.php');
$trigger = $read('app/QueueCore/WebhookTriggerService.php');
$gateway = $read('app/QueueCore/MeliWebhookExactGateway.php');
$legacyJob = $read('jobs/process_sync_queue.php');
$legacyWork = $read('app/Services/NotificationWorkItemService.php');
$repository = $read('app/QueueCore/QueueCoreRepository.php');
$migration = $read('database/migrations/285_queue_core_webhook_ownership_b2.sql');

$appendAt = strpos($public, '$spooled = $spool->append($raw)');
$check($appendAt !== false, 'El request web no termina en spool+ACK.');
$check(!str_contains($public, 'MeliApiClient') && !str_contains($public, 'QueueCoreRepository'), 'El request web cruza DB/HTTP remoto.');
$validateMethod = substr($spool, (int) strpos($spool, 'function validateLinkedAccount'), 1800);
$validateMethod = substr($validateMethod, 0, (int) strpos($validateMethod, 'function resolveLinkedAccount'));
$check(!str_contains($validateMethod, 'Database::') && !str_contains($validateMethod, 'PDO'), 'La validación web abre DB.');
$check(str_contains($spool, 'replayToQueueCore') && str_contains($spool, '$triggers->observe'), 'El spool no desemboca en autoridad V4.');
$check(str_contains($spool, "'stop_reason' => 'SKIPPED_V4_OWNER'") && str_contains($spool, "'http' => 0"), 'El replay legacy no corta V4 con HTTP0.');

foreach (['order', 'pack', 'shipment'] as $type) {
    $check(is_file($root . '/app/QueueCore/Webhook' . ucfirst($type) . 'ExactHandler.php'), "Falta handler exacto {$type}.");
}
$check(str_contains($producer, "\$type . '_exact'"), 'El productor no genera trabajo exacto canónico.');
$check(str_contains($producer, 'ORDER BY last_observed_at ASC,id ASC')
    && !str_contains($producer, 'FIELD(resource_type'),
    'El productor webhook no conserva el FIFO estable por llegada.');
$check(str_contains($trigger, 'desired_watermark=desired_watermark+1') && str_contains($trigger, "IF(state='inflight','inflight','pending')"), 'Coalescing/inflight rerun no usa desired watermark.');
$check(str_contains($migration, 'UNIQUE KEY uq_queue_core_webhook_resource'), 'Cien duplicados podrían crecer sin límite.');
$check(str_contains($trigger, 'LIMIT 2') && str_contains($trigger, 'a.company_id') && str_contains($trigger, 'c.status=1'), 'La identidad seller/company no falla cerrada.');
$check(str_contains($legacyJob, 'SKIPPED_V4_OWNER') && str_contains($legacyJob, 'http=0'), 'El launcher legacy no corta V4 con HTTP0.');
$check(substr_count($legacyWork, 'QueueCoreOwnershipGuard::v4OwnsWebhook()') >= 2, 'Los consumidores legacy exact/due no están cercados.');
$check(!preg_match('/->\s*(post|put|patch|delete)\s*\(/i', $gateway), 'El gateway webhook contiene mutación remota.');
$check(str_contains($repository, "'webhook_order_exact'") && str_contains($repository, "'webhook_pack_exact'") && str_contains($repository, "'webhook_shipment_exact'"), 'Los GET exactos conocidos no tienen retry seguro.');
$check(str_contains($gateway, 'syncOrderByIdForQueueCore') && str_contains($gateway, 'syncPackByIdForQueueCore') && str_contains($gateway, 'syncShipmentByIdForQueueCore'), 'El catálogo exacto GET está incompleto.');

$registry = new App\Services\MeliNotificationTopicRegistry();
$pack = $registry->classify('packs', '/packs/123');
$check($pack['valid'] === true && $pack['resource_type'] === 'pack' && $pack['resource_id'] === '123', 'El catálogo no reconoce pack exacto documentado.');
$deferred = (new App\Services\WebhookSpoolService())->validateLinkedAccount(123);
$check($deferred['reason'] === 'account_resolution_deferred' && $deferred['account_id'] === null, 'La petición web intenta resolver tenant sincrónicamente.');

if ($failures !== []) {
    foreach ($failures as $failure) {
        fwrite(STDERR, "FAIL {$failure}\n");
    }
    exit(1);
}
echo "PASS queue_core_webhook_ownership_b2\n";
