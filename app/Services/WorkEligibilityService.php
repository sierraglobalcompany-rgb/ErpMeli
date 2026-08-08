<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Autoridad de presentación para decidir si un trabajo puede entrar al
 * siguiente ciclo. No adquiere leases ni modifica la cola fuente.
 */
final class WorkEligibilityService
{
    /** @param array<string,mixed> $work @return array{state:string,label:string,reason:string,eligible:bool} */
    public function inspect(array $work, ?int $now = null): array
    {
        $now ??= time();
        $status = strtolower(trim((string) ($work['display_status'] ?? $work['source_status'] ?? '')));
        $next = $this->timestamp($work['next_eligible_at'] ?? null);
        $heartbeat = $this->timestamp($work['heartbeat_at'] ?? null);
        $leaseUntil = $this->timestamp($work['lease_expires_at'] ?? null);
        $staleSeconds = max(30, (new AppSettingsService())->int('automation.running_heartbeat_seconds', 180));

        if ($status === 'completed') {
            return $this->result('terminal', 'Completado', 'Este trabajo ya terminó.', false);
        }
        if ($status === 'error') {
            return $this->result('action_required', 'Necesita intervención', $this->message(
                $work,
                'El último intento falló y necesita una revisión antes de continuar.'
            ), false);
        }
        if ($status === 'paused') {
            return $this->result('paused', 'Pausado', $this->message(
                $work,
                'El trabajo fue detenido intencionalmente.'
            ), false);
        }
        if ($status === 'waiting_budget') {
            return $this->result('waiting_budget', 'Esperando presupuesto', $this->message(
                $work,
                $next !== null && $next > $now
                    ? 'No se consultará Mercado Libre antes de ' . $this->humanTime($next) . '.'
                    : 'El trabajo continuará cuando exista presupuesto preventivo.'
            ), false);
        }
        if ($status === 'running') {
            $liveHeartbeat = $heartbeat !== null && $heartbeat >= $now - $staleSeconds;
            $liveLease = $leaseUntil !== null && $leaseUntil > $now;
            if ($liveHeartbeat || $liveLease) {
                return $this->result('running', 'Ejecutándose', 'Existe una reserva temporal vigente.', false);
            }
            return $this->result(
                'ready',
                'Listo para recuperar',
                'La ejecución anterior perdió su señal. El siguiente ciclo puede recuperarla sin duplicar trabajo.',
                true
            );
        }
        if ($next !== null && $next > $now) {
            return $this->result(
                'future',
                'Disponible después',
                'Podrá ejecutarse después de ' . $this->humanTime($next) . '.',
                false
            );
        }
        if (in_array($status, ['pending', 'retry', 'scheduled', 'waiting', ''], true)) {
            return $this->result(
                'ready',
                $status === 'retry' ? 'Listo para reintentar' : 'Listo ahora',
                $next !== null && $next <= $now
                    ? 'La hora programada ya llegó; puede entrar al siguiente ciclo.'
                    : 'Cumple las condiciones para entrar al siguiente ciclo.',
                true
            );
        }
        return $this->result(
            'unknown',
            'No se pudo comprobar',
            'El estado de la cola no tiene una interpretación segura.',
            false
        );
    }

    /** @return array{state:string,label:string,reason:string,eligible:bool} */
    private function result(string $state, string $label, string $reason, bool $eligible): array
    {
        return compact('state', 'label', 'reason', 'eligible');
    }

    /** @param array<string,mixed> $work */
    private function message(array $work, string $fallback): string
    {
        $message = trim((string) ($work['safe_error_message'] ?? $work['wait_reason'] ?? ''));
        return $message !== '' ? mb_substr(Logger::redactString($message), 0, 500) : $fallback;
    }

    private function timestamp(mixed $value): ?int
    {
        return (new SystemDatabaseUtcClock())->timestamp(is_scalar($value) ? (string) $value : null);
    }

    private function humanTime(int $timestamp): string
    {
        return (new \DateTimeImmutable('@' . $timestamp))
            ->setTimezone(new \DateTimeZone(DateTimePresenter::timezone()))
            ->format('d/m/Y H:i:s') . ' hora Bogotá';
    }
}
