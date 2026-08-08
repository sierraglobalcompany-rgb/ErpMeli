<?php

declare(strict_types=1);

$root = dirname(__DIR__);
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
$check(str_contains($client, "['cron_v3_remote', 'manual_campaign', 'manual_emergency_canary']"), 'V3 remoto, el paso web y el canario deben limitarse a un solo transporte.');
$check(str_contains($client, '$attempts = $singleDispatchAttempt ? 1'), 'Un 5xx de V3 debe aplazarse como otro intento, no reintentarse dentro del mismo handler.');
$check(substr_count($executionContext, "['manual_campaign', 'cron_v3_remote', 'manual_emergency_canary']") >= 2, 'El limite fisico de un transporte debe cubrir V3, el paso web y el canario.');

if ($failures !== []) {
    fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
    exit(1);
}

echo "OK oauth_refresh_isolated_2290\n";
