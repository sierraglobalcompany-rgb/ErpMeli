<?php

declare(strict_types=1);

namespace App\Services;

final class MeliEmergencyStopService
{
    public const MESSAGE = 'Consultas a Mercado Libre bloqueadas por mantenimiento. No se realizó ninguna solicitud remota.';

    public function assertAllowed(): void
    {
        if (!$this->active()) {
            return;
        }

        // Queue V4 Clean readiness has an in-memory, exact-account context.
        // The physical transport repeats the method/path/account check before
        // cURL, so this early exception cannot authorize operational traffic.
        if (\App\QueueV4Clean\QueueV4CleanTransportContext::readinessActive()) {
            return;
        }

        // PAUSE_MELI_API continúa presente. La única excepción es una reserva
        // OAuth privada, exacta y todavía no consumida; la última barrera vuelve
        // a validar y consume ese permiso inmediatamente antes de cURL.
        if ((new EmergencyControlService())->emergencyOAuthRefreshPreflightAllowed()) {
            return;
        }

        throw new ApiManualPauseException('app', null, null, self::MESSAGE);
    }

    /**
     * Última barrera antes del transporte. Además del freno físico, consume
     * de forma atómica el único permiso de una reactivación canaria.
     */
    public function assertTransportAllowed(string $method, string $url): void
    {
        $path = (string) (parse_url($url, PHP_URL_PATH) ?: '/');
        if ($this->active()) {
            $source = (string) (ApiExecutionMetadataContext::current()['source'] ?? '');
            $transportAccount = (int) (ApiExecutionMetadataContext::current()['transport_meli_account_id'] ?? 0);
            if ($source === 'queue_v4_clean_readiness'
                && \App\QueueV4Clean\QueueV4CleanTransportContext::allowsReadinessGet(
                    $method,
                    $path,
                    $transportAccount,
                )) {
                return;
            }
            if ($source === 'manual_emergency_oauth_refresh') {
                (new EmergencyControlService())->claimEmergencyOAuthRefreshTransport($method, $path);
                return;
            }
            throw new ApiManualPauseException('app', null, null, self::MESSAGE);
        }
        $this->assertAllowed();
        (new EmergencyControlService())->claimCanaryTransport($method, $path);
    }

    /** @return array{active:bool,label:string,reason:string,resume_at:null} */
    public function status(): array
    {
        $safety = (new EmergencyControlService())->status();
        $active = in_array((string) ($safety['api'] ?? ''), ['stopped', 'canary_expired'], true);
        $canary = $safety['api'] === 'canary';

        return [
            'active' => $active,
            'label' => $active
                ? (($safety['api'] ?? '') === 'canary_expired'
                    ? 'Prueba canaria vencida; Mercado Libre permanece bloqueado'
                    : 'Consultas a Mercado Libre bloqueadas por mantenimiento')
                : ($canary ? 'Mercado Libre habilitado para una consulta canaria' : 'Bloqueo de emergencia inactivo'),
            'reason' => $active
                ? 'La protección se aplicó mediante un archivo local y no depende de Cron ni de la base de datos.'
                : ($canary ? 'La siguiente salida remota será la única permitida hasta revisar el resultado.' : 'No existe un bloqueo local de emergencia.'),
            'resume_at' => null,
            'canary' => $canary,
        ];
    }

    public function active(): bool
    {
        return (new EmergencyControlService())->apiStopped();
    }
}
