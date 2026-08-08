<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use Throwable;

/**
 * Plan inmutable de capacidad de una invocación CLI.
 *
 * No promete que se consumirá el techo administrativo: documenta cuánto
 * tiempo existe, cuántos transportes cabrían como máximo y qué límite real
 * gobierna el ciclo antes de seleccionar trabajo.
 */
final class CronCapacityPlan
{
    private function __construct(
        private readonly string $profile,
        private readonly float $requestedRpm,
        private readonly float $permittedRpm,
        private readonly int $minimumIntervalMs,
        private readonly int $runtimeSeconds,
        private readonly int $acceptSeconds,
        private readonly int $safeCloseSeconds,
        private readonly int $transportSlots,
        private readonly int $maxClaims,
        private readonly string $limitReason
    ) {}

    public static function build(
        ApiRhythmPolicyService $rhythm,
        AppSettingsService $settings
    ): self {
        $preview = $rhythm->preview();
        $safeClose = max(5, min(15, $settings->int('cron.safe_close_seconds', 10)));
        $directed = max(5, min(25, $settings->int('cron.directed_lane_seconds', 17)));
        // La campaña no debe perder su ventana porque bootstrap, locks y
        // recuperación consumieron el margen que no estaba modelado.
        $bootstrapHeadroom = max(5, min(12, $settings->int('cron.bootstrap_headroom_seconds', 10)));
        $runtime = max(
            $rhythm->recommendedRuntimeSeconds(),
            $safeClose + $directed + $bootstrapHeadroom
        );
        $runtime = max(25, min(55, $runtime));
        $accept = max(5, $runtime - $safeClose);
        $requested = max(1.0, (float) ($preview['requested_rpm'] ?? 10.0));
        $permitted = max(0.1, (float) ($preview['effective_rpm'] ?? $requested));
        $minimumInterval = max(1000, (int) ($preview['minimum_interval_ms'] ?? 1000));
        $byRate = max(1, (int) floor($permitted * $accept / 60));
        $byInterval = max(1, (int) floor(($accept * 1000) / $minimumInterval));

        return new self(
            (string) ($preview['profile'] ?? 'conservative'),
            $requested,
            $permitted,
            $minimumInterval,
            $runtime,
            $accept,
            $safeClose,
            min($byRate, $byInterval),
            // cron.max_tasks_per_run era un límite por función que impedía
            // aprovechar incluso la capacidad ya aprobada. Los transportes
            // continúan cercados por ritmo, presupuesto, endpoint y ventana.
            max(8, min(40, min($byRate, $byInterval) + 8)),
            (string) ($preview['limiting_scope'] ?? 'Aplicación')
        );
    }

    /** @return array<string,int|float|string> */
    public function snapshot(): array
    {
        return [
            'profile' => $this->profile,
            'requested_rpm' => $this->requestedRpm,
            'permitted_rpm' => $this->permittedRpm,
            'minimum_interval_ms' => $this->minimumIntervalMs,
            'runtime_seconds' => $this->runtimeSeconds,
            'accept_seconds' => $this->acceptSeconds,
            'safe_close_seconds' => $this->safeCloseSeconds,
            'transport_slots' => $this->transportSlots,
            'max_claims' => $this->maxClaims,
            'limit_reason' => $this->limitReason,
        ];
    }

    public function runtimeSeconds(): int
    {
        return $this->runtimeSeconds;
    }

    public function acceptSeconds(): int
    {
        return $this->acceptSeconds;
    }

    public function maxClaims(): int
    {
        return $this->maxClaims;
    }

    public function record(string $runToken): void
    {
        if ($runToken === '' || !(new SchemaInspectorService())->hasTable('system_cron_capacity_plans')) {
            return;
        }
        try {
            Database::connectionFresh()->prepare(
                'INSERT INTO system_cron_capacity_plans
                 (run_token,profile,requested_rpm,permitted_rpm,minimum_interval_ms,runtime_seconds,
                  accept_seconds,safe_close_seconds,transport_slots,max_claims,limit_reason,created_at)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,UTC_TIMESTAMP(3))
                 ON DUPLICATE KEY UPDATE profile=VALUES(profile),requested_rpm=VALUES(requested_rpm),
                    permitted_rpm=VALUES(permitted_rpm),minimum_interval_ms=VALUES(minimum_interval_ms),
                    runtime_seconds=VALUES(runtime_seconds),accept_seconds=VALUES(accept_seconds),
                    safe_close_seconds=VALUES(safe_close_seconds),transport_slots=VALUES(transport_slots),
                    max_claims=VALUES(max_claims),limit_reason=VALUES(limit_reason)'
            )->execute([
                $runToken,
                $this->profile,
                $this->requestedRpm,
                $this->permittedRpm,
                $this->minimumIntervalMs,
                $this->runtimeSeconds,
                $this->acceptSeconds,
                $this->safeCloseSeconds,
                $this->transportSlots,
                $this->maxClaims,
                mb_substr($this->limitReason, 0, 120),
            ]);
        } catch (Throwable) {
            // La telemetría de capacidad nunca interrumpe el trabajo de negocio.
        }
    }
}
