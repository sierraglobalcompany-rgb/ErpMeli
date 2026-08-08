<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Csrf;
use App\Core\Session;
use App\Services\RequestPerformanceFileLogger;
use Throwable;

final class PerformanceController
{
    public function collect(): void
    {
        try {
            // Una pestaña antigua puede conservar observadores de rendimiento
            // después de vencer la sesión. Es una muestra descartable, no un
            // incidente operativo: se rechaza en silencio antes de escribir
            // telemetría o generar un diagnóstico en MariaDB.
            Auth::requireLogin();
            $raw = (string) file_get_contents('php://input');
            $payload = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
            if (!is_array($payload)) {
                throw new \RuntimeException('Payload inválido.');
            }
            Csrf::validate(isset($payload['_token']) ? (string) $payload['_token'] : null);
            Session::closeReadOnly();
            $value = (float) ($payload['value'] ?? 0);
            if (is_finite($value) && $value >= 0 && $value <= 3600000) {
                RequestPerformanceFileLogger::record($value, [
                    'route' => (string) ($payload['route'] ?? ''),
                    'status' => 204,
                    'memory_bytes' => memory_get_peak_usage(true),
                ]);
            }
            http_response_code(204);
        } catch (Throwable) {
            http_response_code(204);
        }
        exit;
    }
}
