<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use App\Services\CronDeadlineContext;
use App\Services\CronDeadlineDeferredException;
use App\Services\ApiBudgetExhaustedException;
use App\Services\ApiManualPauseException;
use App\Services\ApiRhythmDeferredException;
use App\Services\SyncErrorClassifier;

$root = dirname(__DIR__);
$failures = [];
$check = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        $failures[] = $message;
    }
};

$classified = SyncErrorClassifier::classify(new CronDeadlineDeferredException(nextSafeAt: '2026-08-01 10:00:00'));
$check($classified['type'] === 'waiting_deadline', 'El deadline seguro todavía se clasifica como error desconocido.');
$check(!str_contains(mb_strtolower($classified['message']), 'mercado libre respondió'), 'El deadline local afirma transporte remoto.');

foreach ([
    [new ApiRhythmDeferredException('aplazado'), 'waiting_rhythm'],
    [new ApiBudgetExhaustedException('aplazado'), 'waiting_budget'],
    [new ApiManualPauseException('global', null, null, 'detenido'), 'waiting_api'],
] as [$error, $expected]) {
    $classifiedWait = SyncErrorClassifier::classify($error);
    $check($classifiedWait['type'] === $expected, 'Una espera operativa fue clasificada como error: ' . $expected);
}

CronDeadlineContext::start(5, 4, 8, 3);
$check(CronDeadlineContext::canStartRemote(1.0), 'Una ventana recién abierta fue rechazada.');
$typed = false;
try {
    CronDeadlineContext::assertCanStartRemote(10.0);
} catch (CronDeadlineDeferredException $error) {
    $typed = $error->reachedRemote === false && $error->nextSafeAt !== null;
}
$check($typed, 'La ventana insuficiente no produce una espera tipada sin transporte.');
CronDeadlineContext::clear();

$service = (string) file_get_contents($root . '/app/Services/OrderEnrichmentService.php');
$context = (string) file_get_contents($root . '/app/Services/CronDeadlineContext.php');
$client = (string) file_get_contents($root . '/app/Services/MeliApiClient.php');
$journal = (string) file_get_contents($root . '/app/Services/ExecutionJournalService.php');
$check(str_contains($context, 'throw new CronDeadlineDeferredException'), 'curlTimeouts todavía lanza RuntimeException genérica.');
$check(str_contains($service, "'waiting_deadline'"), 'Enriquecimiento no conserva el estado waiting_deadline.');
$check(str_contains($service, "\$summary['status'] = 'waiting_deadline'"), 'El coordinador podría convertir el aplazamiento en completado por falta de estado explícito.');
$check(str_contains($service, 'j.attempts=GREATEST(0,j.attempts-:restore_attempt)'), 'Una espera automática todavía consume el intento reclamado.');
$check(str_contains($service, "'consume_attempt' => \$consumeAttempt"), 'La política no distingue intentos reales de esperas locales.');
$check(str_contains($service, "'report_error' => \$reportError"), 'Las esperas operativas todavía generan diagnósticos de error.');
$check(str_contains($service, 'status IN ("pending","retry")'), 'El trabajo exacto no queda reclamable después de la transición a retry.');
$dispatchBoundary = strpos($client, 'dispatchStarted($executionAttemptId, $executionLeaseGeneration)');
$transportCall = strpos($client, '$this->transport->request(');
$check(
    $dispatchBoundary !== false && $transportCall !== false && $dispatchBoundary < $transportCall,
    'El journal debe persistir la posibilidad remota antes de entregar el control al transporte.'
);
$check(
    str_contains($journal, 'public function dispatchStarted(int $attemptId, int $expectedGeneration = 0): bool')
        && str_contains($journal, 'SET state="remote_dispatched",reached_remote=1'),
    'La frontera de dispatch no está persistida como resultado remoto potencial.'
);
$check(
    str_contains($client, 'throw new RemoteResultUncertainException($requestId);'),
    'Una excepción posterior al inicio del transporte todavía permitiría un reintento ciego.'
);

if ($failures !== []) {
    foreach ($failures as $failure) {
        fwrite(STDERR, "FAIL {$failure}\n");
    }
    exit(1);
}

echo "PASS cron_deadline_recovery_22817\n";
