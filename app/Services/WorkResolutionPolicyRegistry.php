<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Traduce el estado técnico de un recurso a una decisión operativa.
 *
 * Este registro es deliberadamente puro: no abre la base, no crea trabajos y
 * nunca consulta Mercado Libre. Las acciones devueltas son capacidades que el
 * servicio exacto vuelve a validar dentro de una transacción.
 */
final class WorkResolutionPolicyRegistry
{
    /** @param array<string,mixed> $work @return array<string,mixed> */
    public function resolve(array $work): array
    {
        $status = strtolower(trim((string) ($work['source_status'] ?? $work['display_status'] ?? 'pending')));
        $code = strtolower(trim((string) ($work['normalized_error_code'] ?? '')));
        $failureClass = strtolower(trim((string) ($work['failure_class'] ?? '')));
        $message = mb_strtolower(trim((string) ($work['safe_error_message'] ?? '')));
        $reachedRemote = array_key_exists('reached_remote', $work) && $work['reached_remote'] !== null
            ? (int) $work['reached_remote'] === 1
            : null;

        if ($this->isDeadline($code, $failureClass, $message)) {
            return $this->policy(
                'waiting_deadline',
                'Aplazado por tiempo',
                'amber',
                'Cron se quedó sin ventana segura antes de iniciar la consulta.',
                'Mercado Libre no fue consultado.',
                'Se reintentará automáticamente en el siguiente ciclo disponible.',
                true,
                false
            );
        }
        if ($this->isWait($code, $failureClass, $status, 'rhythm')) {
            return $this->policy('waiting_rhythm', 'Esperando ritmo seguro', 'amber',
                'El ERP alcanzó el ritmo seguro configurado.', 'El recurso conserva su turno y no necesita intervención.',
                'Cron lo retomará cuando comience la próxima ventana.', true, false);
        }
        if ($this->isWait($code, $failureClass, $status, 'budget')) {
            return $this->policy('waiting_budget', 'Esperando presupuesto', 'amber',
                'El presupuesto preventivo no permite otra consulta ahora.', 'Mercado Libre no fue consultado.',
                'Cron lo retomará en la próxima oportunidad segura.', true, false);
        }
        if ($this->isWait($code, $failureClass, $status, 'api') || $code === 'http_429') {
            return $this->policy('waiting_api', 'Esperando protección de API', 'amber',
                $code === 'http_429' ? 'Mercado Libre pidió reducir temporalmente el ritmo.' : 'La protección local de la API está activa.',
                'El trabajo permanece guardado; no es un error que deba reparar.',
                'Cron lo retomará cuando la protección lo permita.', true, $code === 'http_429' ? true : false);
        }
        if ($this->isAutomaticWait($code, $failureClass, $status, $message)) {
            return $this->policy('waiting_automatic', 'Aplazado automáticamente', 'amber',
                'Una condición preventiva impidió iniciar el transporte.', 'No es un error y no consume un intento fallido.',
                'Cron lo retomará cuando se libere la cuenta, el lock o la ventana segura.', true, false);
        }
        if (in_array($status, ['pending', 'scheduled', 'waiting'], true)) {
            return $this->policy('waiting_schedule', 'Esperando turno', 'gray',
                'El trabajo está guardado y espera su turno.', 'No bloquea otros recursos disponibles.',
                'No necesita hacer nada.', true, false);
        }
        if ($status === 'retry') {
            return $this->policy('retry', 'Reintento programado', 'amber',
                'El intento anterior no terminó, pero el recurso es recuperable.', 'No se ha marcado como completado.',
                'Cron lo intentará de nuevo sin intervención.', true, $reachedRemote);
        }
        if (in_array($status, ['complete', 'completed'], true)) {
            return $this->policy('completed', 'Completado', 'green',
                'El recurso terminó con un resultado aprobado.', 'No existe un problema activo.',
                'No necesita hacer nada.', true, $reachedRemote);
        }
        if ($failureClass === 'remote_absent' || $code === 'http_404') {
            return $this->policy('skipped_expected', 'Dato no disponible', 'gray',
                'Mercado Libre indicó que el recurso ya no está disponible.', 'La orden y sus datos locales se conservan.',
                'Puede cerrarlo como ausencia esperada.', false, true, [
                    $this->action('close_unavailable', 'Cerrar como dato no disponible', 'Cierra únicamente este trabajo; no elimina la orden.', true),
                ]);
        }
        if ($failureClass === 'missing_resource_identity' || $code === 'invalid_resource_identity'
            || (str_contains($message, 'identidad')
                && (str_contains($message, 'pack o envío') || str_contains($message, 'corrección local')))) {
            return $this->policy('repairable', 'Necesita corregir el recurso', 'red',
                'El trabajo no tiene un identificador de pack o envío utilizable.', 'Mercado Libre no fue consultado.',
                'Reconstruya la identidad usando las relaciones locales y deje el recurso listo para Cron.', false, false, [
                    $this->action('repair_identity_and_retry', 'Reconstruir identidad y reintentar', 'Usa únicamente datos locales y reprograma este recurso.', true),
                ]);
        }
        if ($failureClass === 'remote_result_uncertain' || $code === 'remote_result_uncertain') {
            return $this->policy('remote_result_uncertain', 'Resultado remoto por comprobar', 'red',
                'La consulta pudo llegar a Mercado Libre, pero el ERP no pudo aprobar su resultado.', 'Repetirla automáticamente podría duplicar trabajo.',
                'Mantenga el recurso bloqueado hasta completar una revisión respaldada por evidencia.', false, true, [
                    $this->action('hold_uncertain', 'Mantener bloqueado por resultado remoto incierto',
                        'Conserva este recurso detenido y registra la decisión sin consultar Mercado Libre.', true),
                ], 'medium');
        }
        if (str_contains($message, 'unauthorized_scopes') || str_contains($message, 'excessive_api_call')
            || str_contains($message, 'aplicación bloqueada') || str_contains($message, 'application blocked')) {
            return $this->policy('action_required', 'Revisar autorización de la aplicación', 'red',
                'Mercado Libre informó una señal crítica de autorización o bloqueo.',
                'No se debe repetir la consulta hasta revisar la aplicación y la cuenta.',
                'Revise Salud API y la autorización antes de reprogramar este recurso.', false, true, [], 'critical');
        }
        if ($code === 'http_403' || $failureClass === 'permission_denied') {
            return $this->policy('action_required', 'Revisar autorización de la cuenta', 'red',
                'Mercado Libre rechazó esta capacidad para la cuenta.', 'Repetir ahora no solucionará el permiso.',
                'Revise la conexión de la cuenta antes de reprogramar el recurso.', false, true);
        }
        if ($this->isLocalFailure($code, $failureClass, $message)) {
            return $this->policy('action_required', 'Error interno aislado', 'red',
                'El trabajo se detuvo dentro del ERP antes de completar la operación.',
                'Mercado Libre no fue consultado y los demás recursos pueden continuar.',
                'Revise el diagnóstico local o reprograme únicamente este recurso.', false, false, [
                    $this->action('retry', 'Reintentar en el próximo Cron', 'Reprograma únicamente este recurso.', true),
                ]);
        }
        if (in_array($status, ['error', 'failed'], true)) {
            $mayRetry = $reachedRemote === false;
            $actions = $mayRetry
                ? [$this->action('retry', 'Reintentar en el próximo Cron', 'Reprograma únicamente este recurso.', true)]
                : ($reachedRemote === null
                    ? [$this->action('diagnose_local', 'Diagnosticar localmente',
                        'Revisa únicamente evidencia guardada para este recurso; no consulta Mercado Libre.', true)]
                    : [$this->action('hold_uncertain', 'Mantener bloqueado por resultado remoto incierto',
                        'Conserva este recurso detenido y registra la decisión.', true)]);
            return $this->policy(
                $reachedRemote === null ? 'legacy_needs_diagnosis' : 'action_required',
                $reachedRemote === null ? 'Necesita diagnóstico local' : 'Necesita una decisión', 'red',
                'El último intento terminó con un error real.',
                $reachedRemote === false ? 'Mercado Libre no fue consultado.' : ($reachedRemote === true
                    ? 'La evidencia confirma que hubo transporte y no se repetirá automáticamente.'
                    : 'La evidencia histórica no confirma si comenzó el transporte.'),
                $mayRetry ? 'Puede reprogramar este recurso exacto.' : ($reachedRemote === null
                    ? 'Ejecute el diagnóstico local antes de decidir.'
                    : 'Mantenga el recurso bloqueado hasta revisar el resultado.'),
                false, $reachedRemote, $actions);
        }

        return $this->policy('waiting_schedule', 'Esperando turno', 'gray',
            'El trabajo permanece guardado.', 'No hay una intervención disponible para este estado.',
            'Cron volverá a evaluarlo.', true, $reachedRemote);
    }

    /** @return array<string,mixed> */
    private function policy(
        string $key,
        string $label,
        string $tone,
        string $whatHappened,
        string $impact,
        string $next,
        bool $automatic,
        ?bool $reachedRemote,
        array $actions = [],
        string $blockingRisk = 'none'
    ): array {
        return compact('key', 'label', 'tone', 'whatHappened', 'impact', 'next', 'automatic', 'reachedRemote', 'actions', 'blockingRisk');
    }

    /** @return array{key:string,label:string,description:string,primary:bool} */
    private function action(string $key, string $label, string $description, bool $primary = false): array
    {
        return compact('key', 'label', 'description', 'primary');
    }

    private function isDeadline(string $code, string $class, string $message): bool
    {
        return in_array($code, ['cron_deadline_deferred', 'deadline_reached', 'not_started_deadline'], true)
            || $class === 'waiting_deadline'
            || (str_contains($message, 'límite seguro') && str_contains($message, 'antes de iniciar'));
    }

    private function isWait(string $code, string $class, string $status, string $kind): bool
    {
        return str_contains($code, $kind)
            || str_contains($class, 'waiting_' . $kind)
            || str_contains($status, 'waiting_' . $kind);
    }

    private function isAutomaticWait(string $code, string $class, string $status, string $message): bool
    {
        foreach (['lock', 'lease', 'account_paused', 'account_stopped', 'api_manual_pause', 'rate_limit', 'cooldown'] as $term) {
            if (str_contains($code, $term) || str_contains($class, $term) || str_contains($status, $term)) {
                return true;
            }
        }
        return (str_contains($message, 'cuenta') && (str_contains($message, 'detenid') || str_contains($message, 'pausad')))
            || (str_contains($message, 'reserva') && str_contains($message, 'activa'));
    }

    private function isLocalFailure(string $code, string $class, string $message): bool
    {
        if (str_contains($code, 'database') || str_contains($class, 'local')) {
            return true;
        }
        foreach (['sqlstate', 'pdo', 'base de datos', 'database', 'transacción', 'transaction', 'mysql', 'mariadb'] as $term) {
            if (str_contains($message, $term)) {
                return true;
            }
        }
        return false;
    }
}
