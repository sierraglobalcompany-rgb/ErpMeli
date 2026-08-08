<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Clasifica si un mes solicitado está realmente dentro de la ventana histórica
 * que Mercado Libre puede entregar de forma aproximada.
 *
 * No consulta Mercado Libre. Solo materializa evidencia temporal para que la UI
 * y los cierres no afirmen "mes completo" cuando la ventana remota no lo puede
 * demostrar.
 */
final class SalesAuditTemporalCoverageService
{
    public const CONTRACT_VERSION = 2;

    /**
     * @return array{
     *   state:string,
     *   reason:string,
     *   requested_from_utc:string,
     *   requested_to_utc:string,
     *   historical_window_starts_at:string,
     *   effective_from_utc:?string,
     *   effective_to_utc:?string,
     *   contract_version:int
     * }
     */
    public function classifyRun(array $run, ?\DateTimeImmutable $referenceUtc = null): array
    {
        $reference = $referenceUtc ?? $this->referenceTime($run);
        $windowStart = $this->historicalWindowStart($reference);
        $fromValue = trim((string) ($run['requested_from_utc'] ?? $run['utc_from'] ?? ''));
        $toValue = trim((string) ($run['requested_to_utc'] ?? $run['utc_to'] ?? ''));
        if ($fromValue === '' || $toValue === '') {
            return $this->result(
                'invalid',
                'La ejecución no conserva un rango UTC solicitado verificable.',
                $reference,
                $reference,
                $windowStart,
                null,
                null
            );
        }
        $requestedFrom = $this->utc($fromValue);
        $requestedTo = $this->utc($toValue);

        return $this->classify($requestedFrom, $requestedTo, $windowStart);
    }

    /**
     * @return array{
     *   state:string,
     *   reason:string,
     *   requested_from_utc:string,
     *   requested_to_utc:string,
     *   historical_window_starts_at:string,
     *   effective_from_utc:?string,
     *   effective_to_utc:?string,
     *   contract_version:int
     * }
     */
    public function classify(
        \DateTimeImmutable $requestedFrom,
        \DateTimeImmutable $requestedTo,
        ?\DateTimeImmutable $historicalWindowStart = null
    ): array {
        $requestedFrom = $requestedFrom->setTimezone(new \DateTimeZone('UTC'));
        $requestedTo = $requestedTo->setTimezone(new \DateTimeZone('UTC'));
        $historicalWindowStart = ($historicalWindowStart ?? $this->historicalWindowStart())
            ->setTimezone(new \DateTimeZone('UTC'));

        if ($requestedTo <= $requestedFrom) {
            return $this->result(
                'invalid',
                'El rango solicitado no es válido.',
                $requestedFrom,
                $requestedTo,
                $historicalWindowStart,
                null,
                null
            );
        }

        if ($requestedTo <= $historicalWindowStart) {
            return $this->result(
                'outside',
                'El mes solicitado está por fuera de la ventana histórica remota aproximada. Se conserva evidencia local, pero no se afirma cobertura remota completa.',
                $requestedFrom,
                $requestedTo,
                $historicalWindowStart,
                null,
                null
            );
        }

        if ($requestedFrom < $historicalWindowStart) {
            return $this->result(
                'partial',
                'El mes cruza el límite histórico remoto aproximado. Solo se puede verificar contra Mercado Libre desde la parte todavía disponible.',
                $requestedFrom,
                $requestedTo,
                $historicalWindowStart,
                $historicalWindowStart,
                $requestedTo
            );
        }

        return $this->result(
            'full',
            'El mes solicitado está dentro de la ventana histórica remota aproximada. La cobertura final aún depende de paginación válida y evidencia aprobada.',
            $requestedFrom,
            $requestedTo,
            $historicalWindowStart,
            $requestedFrom,
            $requestedTo
        );
    }

    public function canClose(array $run): bool
    {
        return (string) ($run['temporal_coverage_state'] ?? 'pending') === 'full'
            && (int) ($run['coverage_contract_version'] ?? 0) >= self::CONTRACT_VERSION;
    }

    private function historicalWindowStart(?\DateTimeImmutable $referenceUtc = null): \DateTimeImmutable
    {
        $months = max(1, min(24, (new AppSettingsService())->int('sales_control.remote_window_months', 12)));
        $reference = ($referenceUtc ?? new \DateTimeImmutable('now', new \DateTimeZone('UTC')))
            ->setTimezone(new \DateTimeZone('UTC'));
        return $reference->modify('-' . $months . ' months');
    }

    private function referenceTime(array $run): \DateTimeImmutable
    {
        foreach (['capture_started_at', 'started_at', 'created_at'] as $key) {
            $value = trim((string) ($run[$key] ?? ''));
            if ($value !== '') {
                return $this->utc($value);
            }
        }
        return new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }

    private function utc(string $value): \DateTimeImmutable
    {
        return (new \DateTimeImmutable($value, new \DateTimeZone('UTC')))
            ->setTimezone(new \DateTimeZone('UTC'));
    }

    /**
     * @return array{
     *   state:string,
     *   reason:string,
     *   requested_from_utc:string,
     *   requested_to_utc:string,
     *   historical_window_starts_at:string,
     *   effective_from_utc:?string,
     *   effective_to_utc:?string,
     *   contract_version:int
     * }
     */
    private function result(
        string $state,
        string $reason,
        \DateTimeImmutable $requestedFrom,
        \DateTimeImmutable $requestedTo,
        \DateTimeImmutable $historicalWindowStart,
        ?\DateTimeImmutable $effectiveFrom,
        ?\DateTimeImmutable $effectiveTo
    ): array {
        return [
            'state' => $state,
            'reason' => $reason,
            'requested_from_utc' => $requestedFrom->format('Y-m-d H:i:s'),
            'requested_to_utc' => $requestedTo->format('Y-m-d H:i:s'),
            'historical_window_starts_at' => $historicalWindowStart->format('Y-m-d H:i:s'),
            'effective_from_utc' => $effectiveFrom?->format('Y-m-d H:i:s'),
            'effective_to_utc' => $effectiveTo?->format('Y-m-d H:i:s'),
            'contract_version' => self::CONTRACT_VERSION,
        ];
    }
}
