<?php

declare(strict_types=1);

namespace App\Services;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Presentador de cobertura temporal para vistas anuales.
 *
 * No consulta Mercado Libre ni decide cierres; traduce la cobertura técnica de
 * SalesAuditTemporalCoverageService a mensajes humanos y resúmenes de año.
 */
final class SalesTemporalCoverageService
{
    /**
     * @param array<string,mixed>|null $run
     * @return array<string,mixed>
     */
    public function month(
        int $year,
        int $month,
        int $lastMonth,
        DateTimeImmutable $nowBogota,
        int $remoteWindowMonths,
        ?array $run = null
    ): array {
        $requestedFrom = new DateTimeImmutable(
            sprintf('%04d-%02d-01 00:00:00', $year, $month),
            new DateTimeZone('America/Bogota')
        );
        $requestedTo = $requestedFrom->modify('first day of next month');
        $technical = $run !== null && !empty($run['temporal_coverage_state'])
            ? $this->fromRun($run)
            : (new SalesAuditTemporalCoverageService())->classify(
                $requestedFrom->setTimezone(new DateTimeZone('UTC')),
                $requestedTo->setTimezone(new DateTimeZone('UTC')),
                $nowBogota->setTimezone(new DateTimeZone('UTC'))->modify('-' . max(1, min(24, $remoteWindowMonths)) . ' months')
            );

        $state = (string) $technical['state'];
        $future = $month > $lastMonth;
        $current = (int) $nowBogota->format('Y') === $year && (int) $nowBogota->format('n') === $month;
        $reason = (string) $technical['reason'];
        $label = match ($state) {
            'full' => $current ? 'En seguimiento' : 'Cobertura temporal completa',
            'partial' => $current ? 'Mes abierto' : 'Historial parcial',
            'outside' => 'Historial no demostrable',
            'invalid' => 'Rango inválido',
            default => 'Por comprobar',
        };
        $key = match (true) {
            $future => 'future',
            $state === 'full' => 'full_remote_window',
            $state === 'partial' && $current => 'current_open',
            $state === 'partial' => 'partial_remote_window',
            $state === 'outside' => 'outside_remote_window',
            $state === 'invalid' => 'invalid_range',
            default => 'unknown',
        };
        $tone = match ($key) {
            'full_remote_window' => 'verified',
            'current_open' => 'working',
            'partial_remote_window', 'outside_remote_window' => 'partial',
            'invalid_range' => 'blocked',
            default => 'muted',
        };

        return [
            'key' => $key,
            'state' => $state,
            'label' => $label,
            'tone' => $tone,
            'message' => $reason,
            'requested_from' => $requestedFrom->format('Y-m-d'),
            'requested_to' => $requestedTo->modify('-1 second')->format('Y-m-d'),
            'effective_from_utc' => $technical['effective_from_utc'] ?? null,
            'effective_to_utc' => $technical['effective_to_utc'] ?? null,
            'contract_version' => (int) ($technical['contract_version'] ?? SalesAuditTemporalCoverageService::CONTRACT_VERSION),
        ];
    }

    /** @return array<string,mixed> */
    public function yearSummary(
        int $year,
        int $lastMonth,
        DateTimeImmutable $nowBogota,
        int $remoteWindowMonths,
        int $checkedMonths,
        int $activeMonths
    ): array {
        $requestedFrom = new DateTimeImmutable(sprintf('%04d-01-01 00:00:00', $year), new DateTimeZone('America/Bogota'));
        $requestedTo = $lastMonth > 0
            ? (new DateTimeImmutable(sprintf('%04d-%02d-01 00:00:00', $year, $lastMonth), new DateTimeZone('America/Bogota')))
                ->modify('first day of next month')
            : $requestedFrom;
        $remoteWindowMonths = max(1, min(24, $remoteWindowMonths));
        $windowStart = $nowBogota->modify('-' . $remoteWindowMonths . ' months');
        $available = $lastMonth > 0 && $requestedTo > $windowStart;
        $partial = $available && $requestedFrom < $windowStart;
        $complete = $available && !$partial && $activeMonths > 0 && $checkedMonths >= $activeMonths;

        $availableToTimestamp = $lastMonth > 0 ? min($requestedTo->getTimestamp(), $nowBogota->getTimestamp()) : null;
        return [
            'requested_from' => $requestedFrom->format('Y-m-d'),
            'requested_to' => $lastMonth > 0 ? $requestedTo->modify('-1 second')->format('Y-m-d') : null,
            'available_from' => ($partial ? $windowStart : $requestedFrom)->format('Y-m-d'),
            'available_to' => $availableToTimestamp !== null
                ? (new DateTimeImmutable('@' . $availableToTimestamp))->setTimezone(new DateTimeZone('America/Bogota'))->format('Y-m-d')
                : null,
            'available' => $available,
            'partial' => $partial,
            'confidence' => $complete ? 'complete' : ($available ? 'partial' : 'unavailable'),
            'remote_window_months' => $remoteWindowMonths,
            'message' => $available
                ? ($partial
                    ? 'Solo una parte del año permanece dentro de la ventana remota aproximada.'
                    : 'Periodo dentro de la ventana remota aproximada; cada mes conserva su propia evidencia.')
                : 'El periodo está fuera de la ventana remota aproximada.',
        ];
    }

    /**
     * @param array<string,mixed> $run
     * @return array<string,mixed>
     */
    private function fromRun(array $run): array
    {
        return [
            'state' => (string) ($run['temporal_coverage_state'] ?? 'pending'),
            'reason' => (string) ($run['temporal_coverage_reason'] ?? 'Cobertura temporal por comprobar.'),
            'effective_from_utc' => $run['effective_coverage_from_utc'] ?? null,
            'effective_to_utc' => $run['effective_coverage_to_utc'] ?? null,
            'contract_version' => (int) ($run['coverage_contract_version'] ?? 1),
        ];
    }
}
