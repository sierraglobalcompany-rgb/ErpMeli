<?php

declare(strict_types=1);

namespace App\Services;

final class ApiErrorClassifier
{
    public static function classify(?int $httpStatus, ?string $errorCode, string $message = '', array $response = [], string $curlError = ''): array
    {
        $code = strtolower(trim((string) ($errorCode ?: ($response['error'] ?? ''))));
        $cause = strtolower(trim((string) ($response['cause'] ?? $response['status'] ?? '')));
        $text = strtolower($message . ' ' . $code . ' ' . $cause . ' ' . $curlError . ' ' . json_encode($response, JSON_UNESCAPED_UNICODE));

        if ($curlError !== '' || str_contains($text, 'timeout') || str_contains($text, 'timed out')) {
            return self::result('network_timeout', true, false, 'Reintentar pocas veces con backoff; si persiste, revisar red/hosting.');
        }
        if (str_contains($text, 'unauthorized_application') || str_contains($text, 'unauthorized_scopes') || str_contains($text, 'excessive_api_call') || str_contains($text, 'application blocked') || str_contains($text, 'app blocked')) {
            return self::result('app_blocked', false, true, 'Detener consultas ML y revisar la app en Mercado Libre Developers / Mis aplicaciones.');
        }
        if (str_contains($text, 'invalid_grant')) {
            return self::result('refresh_token_invalid', false, false, 'Reconectar la cuenta; el refresh token ya no es válido.');
        }
        if (str_contains($text, 'invalid_token')) {
            return self::result('token_invalid', false, false, 'Revisar OAuth; no insistir sin refrescar o reconectar.');
        }
        if (str_contains($text, 'expired') && str_contains($text, 'token')) {
            return self::result('token_expired', true, false, 'Refrescar token una sola vez con bloqueo transaccional.');
        }
        if ($httpStatus === 429) {
            return self::result('rate_limited', true, false, 'Respetar Retry-After, reducir frecuencia y pausar endpoint/cuenta.');
        }
        if ($httpStatus === 403) {
            if (str_contains($text, 'scope') || str_contains($text, 'permission') || str_contains($text, 'permiso')) {
                return self::result('missing_permission', false, false, 'Revisar scopes/permisos del endpoint antes de reintentar.');
            }
            if (str_contains($text, 'ip') || str_contains($text, 'blocked') || str_contains($text, 'disabled')) {
                return self::result('forbidden_critical', false, false, 'Pausar y revisar IP permitida, app bloqueada/deshabilitada o validaciones de cuenta.');
            }
            return self::result('forbidden', false, false, 'Pausar la cuenta/endpoint y revisar permisos, IP o bloqueo.');
        }
        if ($httpStatus === 401) {
            return self::result('unauthorized', false, false, 'Revisar access token, refresh token o posible bloqueo de aplicación.');
        }
        if ($httpStatus === 404) {
            return self::result('resource_not_found', false, false, 'No insistir masivamente; marcar recurso como no disponible si aplica.');
        }
        if ($httpStatus === 400) {
            if (str_contains($text, 'access token') || str_contains($text, 'owner') || str_contains($text, 'caller') || str_contains($text, 'invalid user')) {
                return self::result('bad_request_token_owner', false, false, 'No reintentar masivamente; validar que el token pertenezca al vendedor/cuenta consultada.');
            }
            return self::result('bad_request', false, false, 'No reintentar; revisar parámetros, endpoint y mapa API.');
        }
        if ($httpStatus !== null && $httpStatus >= 500) {
            return self::result('temporary_server_error', true, false, 'Reintentar de forma limitada con backoff y jitter.');
        }

        return self::result('unknown', false, false, 'Revisar logs sanitizados, endpoint y documentación oficial.');
    }

    private static function result(string $type, bool $retryable, bool $appBlocked, string $recommendation): array
    {
        return [
            'type' => $type,
            'is_retryable' => $retryable,
            'is_app_blocked_signal' => $appBlocked,
            'recommendation' => $recommendation,
        ];
    }
}
