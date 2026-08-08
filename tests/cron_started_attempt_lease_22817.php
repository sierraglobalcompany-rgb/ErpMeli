<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use App\Services\CampaignItemResult;
use App\Services\ManualCampaignService;

$root = dirname(__DIR__);
$failures = [];
$check = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        $failures[] = $message;
    }
};

$coordinator = (string) file_get_contents($root . '/app/Services/CronWorkCoordinator.php');
$taskState = (string) file_get_contents($root . '/app/Services/CronTaskStateService.php');
$runState = (string) file_get_contents($root . '/app/Services/WorkQueueRunService.php');
$campaign = (string) file_get_contents($root . '/app/Services/ManualCampaignService.php');
$adapter = (string) file_get_contents($root . '/app/Services/RegisteredManualCampaignAdapter.php');
$migration = (string) file_get_contents($root . '/database/migrations/197_cron_exact_recovery_human_intervention_2_28_17.sql');

$check(str_contains($coordinator, "array_key_exists('started', \$result)"), 'El coordinador sobrescribe started explícito del callback.');
$check(str_contains($coordinator, "\$result['not_started'] = \$notStarted"), 'El coordinador pierde not_started explícito.');
$check(!str_contains($coordinator, "\$result['started'] = 1;"), 'El coordinador todavía fuerza started=1.');
$check(str_contains($taskState, 'attempts=GREATEST(0,attempts-:restore_attempt)'), 'El estado de tarea no devuelve el attempt si el callback no comenzó.');
$check(str_contains($runState, "? 'not_started'"), 'La bitácora de cola no conserva el resultado not_started.');
$check(str_contains($runState, 'started_at=IF(?=1,NULL,started_at)'), 'La bitácora conserva una hora de inicio falsa.');
$check(str_contains($campaign, 'i.lease_expires_at>UTC_TIMESTAMP(3)')
    && str_contains($campaign, 'live_campaign.worker_heartbeat_at>=DATE_SUB'),
    'El ítem actual se muestra sin lease y heartbeat vigentes.');
$check(str_contains($migration, 'i.attempts=GREATEST(0,i.attempts-1)'), 'La reconciliación histórica no devuelve el intento de campaña.');
$check(str_contains($adapter, "isset(\$result['next_safe_at'])") && str_contains($adapter, '$waitReason'), 'El adaptador pierde la causa y próxima oportunidad de la espera.');

$method = new ReflectionMethod(ManualCampaignService::class, 'shouldRestoreCampaignAttempt');
$service = new ManualCampaignService();
foreach (['waiting_deadline', 'api_rhythm', 'api_budget', 'waiting_api', 'http_429'] as $reason) {
    $result = new CampaignItemResult('deferred', 'Espera normal.', reason: $reason);
    $check($method->invoke($service, $result, 'waiting') === true, 'No se devuelve el intento para ' . $reason . '.');
}
$check($method->invoke($service, new CampaignItemResult('deferred', 'Parcial.', reason: 'waiting_schedule'), 'waiting') === false,
    'Una espera funcional genérica devolvió un intento que sí comenzó.');
$check($method->invoke($service, new CampaignItemResult('completed', 'Listo.', reason: 'completed'), 'completed') === false,
    'Un resultado terminal devolvió su intento.');

if ($failures !== []) {
    foreach ($failures as $failure) {
        fwrite(STDERR, 'FAIL ' . $failure . PHP_EOL);
    }
    exit(1);
}

fwrite(STDOUT, "PASS cron_started_attempt_lease_22817\n");
