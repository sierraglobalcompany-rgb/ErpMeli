<?php

declare(strict_types=1);

namespace App\Services;

final class ApiLogRiskPresenter
{
    /** @return array<string,mixed> */
    public function present(array $row): array
    {
        $status = (int) ($row['http_status'] ?? 0);
        $outcome = (string) ($row['outcome_class'] ?? '');
        $path = strtolower((string) ($row['endpoint_path'] ?? ''));
        $message = strtolower((string) ($row['message'] ?? ''));
        $reached = (int) ($row['reached_remote'] ?? ($status > 0 ? 1 : 0)) === 1;
        $historicalPayment = str_contains($path, '/payments/');
        $descriptionMissing = $status === 404 && str_contains($path, '/description');
        $unauthorizedScopes = str_contains($message, 'unauthorized_scopes');

        if ($descriptionMissing || $outcome === 'expected_absence') {
            return $this->result('Ninguno', 'neutral', 'Sin descripción', false, false,
                'Mercado Libre informó que esta publicación no tiene una descripción disponible.',
                'No requiere acción. El producto puede seguir funcionando sin descripción.', 'Informativo');
        }
        if ($historicalPayment) {
            return $this->result('Ninguno', 'muted', 'Endpoint histórico desactivado', true, false,
                'Es un registro histórico de una operación que el ERP ya no consulta.',
                'No requiere acción. El endpoint permanece bloqueado por seguridad.', 'Histórico');
        }
        if ($unauthorizedScopes) {
            return $this->result('Crítico', 'red', 'Autorización comprometida', $reached, true,
                'Mercado Libre rechazó el alcance de autorización de la aplicación.',
                'Mantenga pausadas las consultas y revise la aplicación y sus permisos.', 'Crítico');
        }
        if (!$reached && ($outcome === 'policy_delay' || (int) ($row['was_blocked'] ?? 0) === 1)) {
            return $this->result('Bajo', 'blue', 'Pausa preventiva local', false, false,
                'El ERP aplazó la operación antes del transporte; no llegó a Mercado Libre.',
                'Espere la próxima oportunidad segura. Este evento no es una respuesta HTTP 429 remota.', 'Pausa preventiva local');
        }
        if ($status === 429) {
            return $this->result('Alto', 'red', 'Pausa solicitada por Mercado Libre', true, true,
                'Mercado Libre pidió reducir temporalmente las consultas.',
                'Respete la hora segura indicada. El ERP no debe reintentar antes.', 'Pausa preventiva');
        }
        if ($status === 403) {
            return $this->result('Medio', 'amber', 'Permiso no disponible', true, false,
                'La cuenta o la aplicación no dispone de permiso para esta operación.',
                'Revise la capacidad de esa cuenta. No repita masivamente la consulta.', 'Atención');
        }
        if ($status >= 500) {
            return $this->result('Medio', 'amber', 'Falla temporal de Mercado Libre', true, false,
                'La plataforma remota presentó una falla temporal; no implica bloqueo automático.',
                'Espere el reintento programado y revise si la falla persiste.', 'Atención');
        }
        if (!$reached || $outcome === 'local_failure') {
            return $this->result('Bajo', 'amber', 'Fallo interno del ERP', false, false,
                'La operación se detuvo dentro del ERP y no llegó a Mercado Libre.',
                'Revise el diagnóstico interno. No existe riesgo de bloqueo por esta ocurrencia.', 'Atención');
        }
        if ($outcome === 'policy_delay' || (int) ($row['was_blocked'] ?? 0) === 1) {
            return $this->result('Bajo', 'blue', 'Consulta aplazada', false, false,
                'El ERP aplazó la operación de forma preventiva antes de enviarla.',
                'No requiere intervención salvo que la pausa se prolongue.', 'Pausa preventiva');
        }
        if ($status === 404) {
            return $this->result('Bajo', 'neutral', 'Recurso no encontrado', true, false,
                'El recurso solicitado no existe o ya no está disponible. Un 404 no implica bloqueo.',
                'Revise solamente si el recurso debería existir.', 'Informativo');
        }
        if ($status >= 400) {
            return $this->result('Medio', 'amber', 'Respuesta remota para revisar', true, false,
                'Mercado Libre rechazó esta operación. La repetición y el contexto determinan el riesgo.',
                'Revise el detalle y evite reintentos masivos hasta comprender la causa.', 'Atención');
        }
        return $this->result('Ninguno', 'green', 'Consulta correcta', $reached, false,
            'La operación terminó correctamente.', 'No requiere acción.', 'Informativo');
    }

    /** @param list<array<string,mixed>> $rows */
    public function education(array $rows): string
    {
        if ($rows === []) {
            return 'No hay eventos para los filtros seleccionados. Un código HTTP no determina por sí solo el riesgo.';
        }
        $presented = array_map(fn (array $row): array => $this->present($row), $rows);
        $critical = array_filter($presented, static fn (array $item): bool => $item['risk'] === 'Crítico');
        if ($critical !== []) {
            return 'Hay una señal crítica de autorización o protección. Mantenga pausadas las consultas y abra el incidente.';
        }
        $descriptions = array_filter($presented, static fn (array $item): bool => $item['title'] === 'Sin descripción');
        if (count($descriptions) >= max(1, (int) ceil(count($rows) / 2))) {
            return 'La mayoría de estos eventos indican publicaciones sin descripción. No representan riesgo de bloqueo.';
        }
        $local = array_filter($presented, static fn (array $item): bool => $item['reached_remote'] === false);
        if (count($local) >= max(1, (int) ceil(count($rows) / 2))) {
            return 'La mayoría de estos problemas ocurrieron dentro del ERP y no llegaron a Mercado Libre.';
        }
        return 'El riesgo depende de la operación, su repetición, la cuenta afectada y si llegó a Mercado Libre; no solo del código HTTP.';
    }

    /** @return array<string,mixed> */
    private function result(string $risk, string $class, string $title, bool $reached, bool $blocking, string $meaning, string $action, string $state): array
    {
        return compact('risk', 'class', 'title', 'reached', 'blocking', 'meaning', 'action', 'state') + [
            'reached_remote' => $reached,
        ];
    }
}
