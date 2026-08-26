<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Compatibilidad cerrada para el ejecutor manual anterior.
 *
 * Este servicio elegía el siguiente trabajo genérico de una cola y solo
 * comparaba source_id después de procesar.
 * Esa secuencia podía avanzar un recurso diferente al congelado por la
 * selección. El camino vigente de "Procesar ahora" entra por
 * ManualSingleStepService -> ManualQueueLauncher -> QueueRunner, sin segundo
 * motor web ni continuación en background.
 */
final class ManualQueueExecutionService
{
    /** @return array<string,mixed> */
    public function execute(array $item, float $deadline): array
    {
        return [
            'status' => 'action_required',
            'processed' => 0,
            'errors' => 0,
            'reached_remote' => false,
            'target_terminal' => false,
            'target_errored' => false,
            'queue_key' => (string) ($item['queue_key'] ?? ''),
            'source_id' => (string) ($item['source_id'] ?? ''),
            'message' => 'Este ejecutor heredado está retirado. Use Procesar ahora: un paso exacto por confirmación, sin continuación en segundo plano.',
        ];
    }
}
