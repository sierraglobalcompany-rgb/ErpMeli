<?php

declare(strict_types=1);

$root = dirname(__DIR__);
require $root . '/bootstrap.php';

use App\Services\MeliTransportSourcePolicy;

$client = (string) file_get_contents($root . '/app/Services/MeliApiClient.php');
$exception = (string) file_get_contents($root . '/app/Services/OAuthRefreshRequiredException.php');
$executionContext = (string) file_get_contents($root . '/app/Services/ApiExecutionMetadataContext.php');
$failures = [];
$check = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        $failures[] = $message;
    }
};

$check(str_contains($client, 'throw new OAuthRefreshRequiredException($this->accountId)'), 'Una lectura comercial no debe refrescar OAuth de forma oculta.');
$check(str_contains($client, 'public function refreshOAuthToken(): array'), 'El worker OAuth necesita una operacion remota explicita.');
$check(!str_contains($client, '$token = $this->refreshToken()'), 'El refresh heredado sigue encadenado a la lectura comercial.');
$check(str_contains($exception, 'public readonly int $accountId'), 'La senal OAuth debe conservar solamente la cuenta, sin secretos.');
$check(MeliTransportSourcePolicy::isSingleDispatch('queue_core'), 'Queue Core perdió single-dispatch.');
$check(MeliTransportSourcePolicy::isSingleDispatch('cron_v3_remote'), 'V3 remoto perdió single-dispatch.');
$check(MeliTransportSourcePolicy::isSingleDispatch('manual_campaign'), 'Campaña manual perdió single-dispatch.');
$check(MeliTransportSourcePolicy::isSingleDispatch('manual_emergency_canary'), 'Canario manual perdió single-dispatch.');
$check(MeliTransportSourcePolicy::isSingleDispatch('manual_emergency_oauth_refresh'), 'OAuth manual perdió single-dispatch.');
$check(MeliTransportSourcePolicy::isSingleDispatch(MeliTransportSourcePolicy::QUEUE_V4_OAUTH), 'OAuth Queue V4 perdió single-dispatch.');
$check(MeliTransportSourcePolicy::blocksRedirects(MeliTransportSourcePolicy::QUEUE_V4_OAUTH), 'OAuth Queue V4 permitió redirects.');
$check(MeliTransportSourcePolicy::requiresCurrentOAuthFence(MeliTransportSourcePolicy::QUEUE_V4_OAUTH), 'OAuth Queue V4 perdió su fence CURRENT.');
$unknownBlocked = false;
try {
    MeliTransportSourcePolicy::assertAllowed('unknown_transport_source', 'GET', '/users/me');
} catch (RuntimeException) {
    $unknownBlocked = true;
}
$check($unknownBlocked, 'Una fuente desconocida no falló cerrada.');
$wrongCapabilityBlocked = false;
try {
    MeliTransportSourcePolicy::assertAllowed(MeliTransportSourcePolicy::QUEUE_V4_OAUTH, 'GET', '/users/me');
} catch (RuntimeException) {
    $wrongCapabilityBlocked = true;
}
$check($wrongCapabilityBlocked, 'La fuente OAuth llamó una capacidad distinta a POST /oauth/token.');
MeliTransportSourcePolicy::assertAllowed(MeliTransportSourcePolicy::QUEUE_V4_OAUTH, 'POST', '/oauth/token');
$check(str_contains($client, '$attempts = $singleDispatchAttempt ? 1'), 'Un 5xx de V3 debe aplazarse como otro intento, no reintentarse dentro del mismo handler.');
$check(substr_count($executionContext, 'MeliTransportSourcePolicy::isSingleDispatch') >= 2, 'El contexto de ejecución no consulta la autoridad central de single-dispatch.');

if ($failures !== []) {
    fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
    exit(1);
}

echo "OK oauth_refresh_isolated_2290\n";
