<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$worker = (string) file_get_contents($root . '/app/Services/ResumableCampaignWorkerService.php');
$campaign = (string) file_get_contents($root . '/app/Services/ManualCampaignService.php');
$capacity = (string) file_get_contents($root . '/app/Services/CronCapacityPlan.php');
$planner = (string) file_get_contents($root . '/app/Services/CronExecutionPlanner.php');
$scope = (string) file_get_contents($root . '/app/Services/ApiHealthAccessScope.php');
$migration = (string) file_get_contents($root . '/database/migrations/218_cron_useful_execution_campaign_source_isolation_2_28_38.sql');

$failures = [];
$check = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        $failures[] = $message;
    }
};

$check(str_contains($worker, 'markWaitingSourceBeforeClaim'), 'La campaña no filtra fuentes pausadas antes del claim.');
$check(strpos($worker, 'markWaitingSourceBeforeClaim') < strpos($worker, 'firstFittingCandidate'), 'El preflight debe ocurrir antes de escoger el candidato.');
$check(str_contains($campaign, 'source_state="waiting_source"'), 'Falta estado waiting_source persistente.');
$check(str_contains($campaign, 'attempts=attempts+') === false || str_contains($campaign, 'markWaitingSourceBeforeClaim'), 'La espera de origen no debe consumir intentos.');
$waitingBody = substr($campaign, (int) strpos($campaign, 'function markWaitingSourceBeforeClaim'), 3500);
$check(!str_contains($waitingBody, 'attempts=attempts+'), 'Estacionar una fuente pausada no puede incrementar intentos.');
$check(str_contains($waitingBody, '$changed') && str_contains($waitingBody, "'source_waiting'"), 'El evento de espera debe deduplicarse por transición.');
$check(!str_contains($capacity, "settings->int('cron.max_tasks_per_run'"), 'La capacidad no debe depender del límite histórico por función.');
$check(str_contains($planner, 'min(40, $this->maxClaims)'), 'El planner debe aceptar el plan de capacidad ampliado.');
$check(!str_contains($planner, 'private array $attempted'), 'El planner no debe agotar una función después de un único claim.');
$check(str_contains($planner, 'private array $claimsByKey'), 'Falta el contador cercado de reentradas por función.');
$check(str_contains($planner, 'observedAvailabilitySnapshot'), 'Falta el delta de disponibilidad para evitar probes globales por ciclo.');
$check(str_contains($planner, 'private array $observedKeys'), 'El delta debe recordar todas las keys medidas, no solo claims.');
$check(str_contains($planner, '$this->observedKeys[$observedKey] = true'), 'Una medición sin candidato debe permanecer en el delta observado.');
$check(str_contains($worker, "['paused', 'future', 'locked']"), 'Las esperas locales deben estacionarse antes del claim.');
$check(str_contains($worker, "['action_required', 'unsupported']"), 'Las fuentes no reparables no deben abrir intentos remotos.');
$check(str_contains($campaign, 'function markSourceAttentionBeforeClaim'), 'Falta aislar intervenciones antes del claim.');
$check(str_contains($waitingBody, "source->sourceState === 'paused' ? 900 : 60"), 'Una fuente pausada debe aplicar backoff y no reescribirse cada minuto.');
$check(!str_contains($scope, 'private static array $snapshots'), 'El scope API no puede sobrevivir en caché estática de FPM.');
$check(str_contains($migration, 'idx_campaign_waiting_source'), 'Falta índice de espera de fuente.');

if ($failures !== []) {
    fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
    exit(1);
}
echo "cron_useful_campaign_source_22838: ok\n";
