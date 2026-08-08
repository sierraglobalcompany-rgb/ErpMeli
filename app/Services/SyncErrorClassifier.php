<?php

declare(strict_types=1);

namespace App\Services;

use PDOException;
use Throwable;

final class SyncErrorClassifier
{
    /**
     * @return array{type:string,http_status:?int,endpoint:?string,message:string,recommendation:string}
     */
    public static function classify(Throwable|string $error): array
    {
        $raw = $error instanceof Throwable ? $error->getMessage() : (string) $error;
        $message = self::safeMessage($raw);
        $lower = mb_strtolower($message);
        $http = $error instanceof MeliApiException ? $error->httpStatus : self::extractHttpStatus($message);
        $endpoint = self::extractEndpoint($message);

        if ($error instanceof RemoteResultUncertainException) {
            return self::result(
                'remote_result_uncertain',
                $error->httpStatus,
                $endpoint,
                $message,
                'Revise el trabajo exacto. No autorice otro intento hasta confirmar el resultado anterior.'
            );
        }

        if ($error instanceof CronDeadlineDeferredException) {
            return self::result(
                'waiting_deadline',
                null,
                $endpoint,
                'El ciclo terminó antes de iniciar otra consulta remota.',
                'El ERP conservará el recurso y lo retomará automáticamente en el siguiente ciclo.'
            );
        }

        if ($error instanceof ApiRhythmDeferredException) {
            return self::result(
                'waiting_rhythm',
                null,
                $endpoint,
                'El ritmo seguro aplazó la consulta antes del transporte.',
                'El ERP retomará el recurso cuando llegue la próxima oportunidad de ritmo.'
            );
        }

        if ($error instanceof ApiBudgetExhaustedException) {
            return self::result(
                'waiting_budget',
                null,
                $endpoint,
                'El presupuesto preventivo aplazó la consulta antes del transporte.',
                'El ERP retomará el recurso cuando se abra la siguiente ventana de presupuesto.'
            );
        }

        if ($error instanceof ApiManualPauseException) {
            return self::result(
                'waiting_api',
                null,
                $endpoint,
                'Las consultas están detenidas por el freno de mano.',
                'El recurso conservará su turno hasta que la API sea reactivada.'
            );
        }

        if ($http === 429 || str_contains($lower, 'rate limit') || str_contains($lower, 'too many')) {
            return self::result('rate_limit_429', $http, $endpoint, $message, 'Mercado Libre limitó las consultas. Espere el cooldown y reduzca bloques o concurrencia.');
        }
        if (str_contains($lower, 'presupuesto api ml agotado') || str_contains($lower, 'api_budget_exhausted')) {
            return self::result('api_budget_exhausted', $http, $endpoint, $message, 'La cola se pausó preventivamente para evitar bloqueo. Espere la próxima ventana segura o deje que cron continúe.');
        }
        if (str_contains($lower, 'consultas pausadas manualmente por seguridad mercado libre')) {
            return self::result('api_manual_pause', $http, $endpoint, $message, 'La administración pausó las consultas. El trabajo conservará su progreso hasta la reanudación.');
        }
        if (str_contains($lower, 'consultas pausadas por seguridad mercado libre') || str_contains($lower, 'circuit')) {
            return self::result('api_circuit_open', $http, $endpoint, $message, 'El circuito de protección está abierto. No haga más consultas hasta que termine el enfriamiento.');
        }
        if ($http === 403 || str_contains($lower, 'forbidden') || str_contains($lower, 'permiso')) {
            return self::result('api_forbidden_403', $http, $endpoint, $message, 'Revise permisos, scopes o bloqueo temporal de la cuenta Mercado Libre.');
        }
        if ($http !== null && $http >= 500) {
            return self::result('api_5xx', $http, $endpoint, $message, 'Error temporal de API. Reintente con backoff y revise logs API.');
        }
        if ($http !== null && $http >= 400) {
            return self::result('api_error', $http, $endpoint, $message, 'Error de API. Revise el endpoint, permisos y logs técnicos.');
        }
        if (str_contains($lower, 'token') || str_contains($lower, 'oauth') || str_contains($lower, 'access_denied')) {
            return self::result('oauth_token', $http, $endpoint, $message, 'Revise la conexión OAuth de la cuenta y renueve credenciales si aplica.');
        }
        if (str_contains($lower, 'timeout') || str_contains($lower, 'timed out') || str_contains($lower, 'curl')) {
            return self::result('timeout', $http, $endpoint, $message, 'La consulta tardó demasiado. Reintente con bloques más pequeños o espere.');
        }
        if ($error instanceof PDOException || str_contains($lower, 'sqlstate') || str_contains($lower, 'database')) {
            return self::result('database', $http, $endpoint, $message, 'Revise migraciones, conexión MySQL y logs técnicos.');
        }
        if (str_contains($lower, 'inválid') || str_contains($lower, 'invalid') || str_contains($lower, 'seleccione')) {
            return self::result('validation', $http, $endpoint, $message, 'Revise los datos del bloque o la programación enviada.');
        }

        return self::result('unknown', $http, $endpoint, $message, 'Revise logs técnicos y vuelva a intentar con un bloque pequeño.');
    }

    private static function safeMessage(string $message): string
    {
        $message = preg_replace('/(access_token|refresh_token|client_secret|authorization|password|app_key)=?[^\\s,&]*/i', '$1=[redacted]', $message) ?? $message;
        return mb_substr(trim($message), 0, 500);
    }

    private static function extractHttpStatus(string $message): ?int
    {
        if (preg_match('/\\b(4\\d\\d|5\\d\\d)\\b/', $message, $m)) {
            return (int) $m[1];
        }
        return null;
    }

    private static function extractEndpoint(string $message): ?string
    {
        if (preg_match('#/(orders|payments|shipments|packs|items|questions|post-purchase)[^\\s,;\\)]*#i', $message, $m)) {
            return mb_substr($m[0], 0, 255);
        }
        return null;
    }

    /**
     * @return array{type:string,http_status:?int,endpoint:?string,message:string,recommendation:string}
     */
    private static function result(string $type, ?int $http, ?string $endpoint, string $message, string $recommendation): array
    {
        return [
            'type' => $type,
            'http_status' => $http,
            'endpoint' => $endpoint,
            'message' => $message,
            'recommendation' => $recommendation,
        ];
    }
}
