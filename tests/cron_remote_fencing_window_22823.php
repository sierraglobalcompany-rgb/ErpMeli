<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use App\Services\CampaignExecutionWindowPolicyService;

$failures = [];
$check = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        $failures[] = $message;
    }
};

$check(
    !CampaignExecutionWindowPolicyService::operationFits(14000, 15.0, 1500),
    'Una ventana real de 14 segundos no debe iniciar una operación reservada por 15 segundos.'
);
$check(
    CampaignExecutionWindowPolicyService::minimumLaneSeconds(15, 15, 1500) === 17,
    'El carril dirigido debe reservar 17 segundos para una operación de 15 y su margen local.'
);
$check(
    CampaignExecutionWindowPolicyService::operationFits(17000, 15.0, 1500),
    'La ventana corregida debe admitir la operación máxima sin invadir el cierre seguro.'
);
$selected = CampaignExecutionWindowPolicyService::firstFittingCandidate([
    ['id' => 10, 'reserve_seconds' => 15.0],
    ['id' => 11, 'reserve_seconds' => 2.0],
], 5000, 1500);
$check(
    (int) ($selected['id'] ?? 0) === 11,
    'Un ítem lento al frente no debe bloquear el siguiente ítem elegible que sí cabe.'
);

$root = dirname(__DIR__);
$client = (string) file_get_contents($root . '/app/Services/MeliApiClient.php');
$rhythm = (string) file_get_contents($root . '/app/Services/ApiRhythmPolicyService.php');
$worker = (string) file_get_contents($root . '/app/Services/ResumableCampaignWorkerService.php');
$journal = (string) file_get_contents($root . '/app/Services/ExecutionJournalService.php');
$check(str_contains($client, '$rhythm->isCurrent($rhythmPermit)'), 'El cliente debe rechazar un permiso vencido antes de HTTP.');
$check(substr_count($client, '$rhythm->dispatched(') === 1, 'El permiso debe pasar a dispatched una sola vez, antes de HTTP.');
$check(strpos($client, '$rhythm->dispatched(') < strpos($client, '$this->transport->request('), 'El fence dispatched debe quedar inmediatamente antes del transporte.');
$check(str_contains($client, '$rhythm->finalizeKnownResult($rhythmPermit'), 'El cleanup posterior no puede volver incierto un HTTP conocido.');
$check(str_contains($client, 'dispatchCancelledBeforeRemote($executionAttemptId, $executionLeaseGeneration)'), 'Un fence vencido debe revertir la frontera local exacta si HTTP no inició.');
$check(str_contains($journal, 'lease_generation=?') && str_contains($journal, 'state="remote_dispatched" AND response_at IS NULL'), 'La reversión del journal debe estar cercada por generación y ausencia de respuesta.');
$check(str_contains($client, '$rhythm->cancelBeforeTransport($rhythmPermit)'), 'El fallo pre-HTTP debe devolver el permiso de ritmo, no dejarlo dispatched.');
$check(str_contains($rhythm, "JOIN api_rhythm_states rhythm"), 'La vigencia debe comparar la generación del permiso con la autoridad global.');
$check(str_contains($worker, 'operation_window_too_short'), 'La campaña debe explicar una ventana insuficiente sin fingir que comenzó.');
$check(str_contains($worker, 'nextFittingCandidate'), 'La campaña debe buscar un ítem exacto que quepa en la ventana restante.');

if ($failures !== []) {
    fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
    exit(1);
}

fwrite(STDOUT, "PASS cron_remote_fencing_window_22823\n");
