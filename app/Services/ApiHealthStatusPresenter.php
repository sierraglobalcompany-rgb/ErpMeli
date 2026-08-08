<?php

declare(strict_types=1);

namespace App\Services;

final class ApiHealthStatusPresenter
{
    /**
     * @param array<string,mixed> $context
     * @return array{status:string,label:string,summary:string,recommendation:string,icon:string,tone:string}
     */
    public function present(array $context): array
    {
        if (($context['api_state'] ?? '') === 'stopped') {
            return [
                'status' => 'maintenance',
                'label' => 'Mercado Libre bloqueado por mantenimiento',
                'summary' => 'No se iniciarán consultas remotas mientras el freno de mano permanezca activo.',
                'recommendation' => 'Los trabajos están guardados. Reactive únicamente desde el control de emergencia.',
                'icon' => 'Ⅱ',
                'tone' => 'paused',
            ];
        }

        if (empty($context['data_available'])) {
            return [
                'status' => 'unknown',
                'label' => 'No se pudo comprobar',
                'summary' => 'El ERP no pudo verificar el estado actual de la integración.',
                'recommendation' => 'Ejecute el diagnóstico antes de asumir que Mercado Libre está disponible.',
                'icon' => '?',
                'tone' => 'unknown',
            ];
        }

        $signals = is_array($context['signals'] ?? null) ? $context['signals'] : [];
        $critical = (int) ($signals['blocked_signals'] ?? 0) > 0
            || (int) ($signals['unauthorized'] ?? 0) >= 2
            || !empty($context['critical_circuit']);
        if ($critical) {
            return [
                'status' => 'critical',
                'label' => 'Revisión inmediata',
                'summary' => 'Existe una señal activa de autorización o bloqueo.',
                'recommendation' => 'Mantenga protegidas las consultas y revise el incidente crítico.',
                'icon' => '!',
                'tone' => 'critical',
            ];
        }

        $pauseCount = (int) ($context['pause_count'] ?? 0);
        if ($pauseCount > 0) {
            return [
                'status' => 'paused',
                'label' => 'Consultas protegidas',
                'summary' => 'El ERP pausó una o más consultas para proteger la integración.',
                'recommendation' => 'Revise el alcance y la hora segura antes de reanudar.',
                'icon' => 'Ⅱ',
                'tone' => 'paused',
            ];
        }

        $activeIncidents = (int) ($context['active_incident_count'] ?? 0);
        if ($activeIncidents > 0) {
            return [
                'status' => 'attention',
                'label' => 'Necesita revisión',
                'summary' => 'Hay asuntos recientes que conviene revisar.',
                'recommendation' => 'Abra el incidente para conocer su impacto y la acción recomendada.',
                'icon' => '!',
                'tone' => 'attention',
            ];
        }

        $available = (int) ($context['available_accounts'] ?? 0);
        $total = (int) ($context['total_accounts'] ?? 0);
        $sent = (int) ($context['sent'] ?? 0);
        $successful = (int) ($context['successful'] ?? 0);
        if ($total === 0 || $sent === 0 || $available < $total) {
            return [
                'status' => 'unverified',
                'label' => 'Sin evidencia remota reciente',
                'summary' => $total === 0
                    ? 'No hay cuentas autorizadas para comprobar en este alcance.'
                    : $available . ' de ' . $total . ' cuentas tienen una consulta remota exitosa reciente.',
                'recommendation' => 'No asuma que Mercado Libre está disponible hasta obtener evidencia de una consulta permitida.',
                'icon' => '?',
                'tone' => 'unknown',
            ];
        }
        $base = $sent > 0
            ? number_format($successful, 0, ',', '.') . ' de ' . number_format($sent, 0, ',', '.') . ' consultas se completaron correctamente.'
            : 'Todavía no hay consultas registradas en el periodo seleccionado.';
        $base .= ' ' . $available . ' de ' . $total . ' cuentas están disponibles.';

        if ((int) ($context['recovered_incident_count'] ?? 0) > 0) {
            return [
                'status' => 'recovered',
                'label' => 'Mercado Libre está disponible',
                'summary' => $base . ' Existe un incidente anterior ya recuperado.',
                'recommendation' => 'No se requiere ninguna acción inmediata.',
                'icon' => '✓',
                'tone' => 'healthy',
            ];
        }

        return [
            'status' => 'healthy',
            'label' => 'Mercado Libre está disponible',
            'summary' => $base . ' No hay pausas ni incidentes activos.',
            'recommendation' => 'No se requiere ninguna acción.',
            'icon' => '✓',
            'tone' => 'healthy',
        ];
    }
}
