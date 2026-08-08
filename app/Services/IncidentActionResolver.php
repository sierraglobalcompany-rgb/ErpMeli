<?php

declare(strict_types=1);

namespace App\Services;

final class IncidentActionResolver
{
    /** @param array<string,mixed> $incident @return array<string,mixed> */
    public function resolve(array $incident): array
    {
        $active = ($incident['state'] ?? '') === 'active';
        $remote = !empty($incident['reached_remote']);
        $outcome = (string) ($incident['outcome_class'] ?? '');
        $operation = strtolower((string) ($incident['operation_label'] ?? '') . ' ' . (string) ($incident['endpoint_path'] ?? ''));
        $incidentAccounts = array_values(array_filter(
            (array) ($incident['accounts'] ?? []),
            static fn (array $row): bool => (int) ($row['meli_account_id'] ?? 0) > 0
        ));
        $accountId = count($incidentAccounts) === 1
            ? (int) ($incidentAccounts[0]['meli_account_id'] ?? 0)
            : 0;

        $result = [
            'origin_label' => $remote ? 'Respuesta de Mercado Libre' : 'Proceso interno del ERP',
            'headline' => $remote
                ? 'Mercado Libre respondió a esta consulta.'
                : 'Este problema ocurrió dentro del ERP. Mercado Libre no recibió la consulta.',
            'system_behavior' => $active
                ? 'El ERP conservará el trabajo y aplicará sus reglas de protección.'
                : 'El incidente ya no se está repitiendo.',
            'administrator_action' => $active
                ? 'Revise la acción recomendada antes de reintentar.'
                : 'No necesita pausar Mercado Libre por este incidente recuperado.',
            'actions' => [],
        ];

        if (!$remote && $outcome === 'policy_delay') {
            $result['origin_label'] = 'Espera preventiva del ERP';
            $result['headline'] = 'El ERP aplazó la consulta antes de contactar Mercado Libre.';
            $result['administrator_action'] = 'Revise la protección y la próxima hora segura. No cree otra tarea.';
            $result['actions'][] = [
                'label' => 'Ver protección',
                'url' => '/settings/api-health/protection',
                'primary' => true,
            ];
        } elseif (!$remote && (str_contains($operation, 'orden') || str_contains($operation, 'audit'))) {
            $query = http_build_query(array_filter([
                'queue_key' => str_contains($operation, 'orden') ? 'order_enrichment' : null,
                'group' => 'attention',
                'account_id' => $accountId > 0 ? $accountId : null,
            ]));
            $result['administrator_action'] = count($incidentAccounts) > 1
                ? 'Revise los trabajos por cuenta. El incidente agrupa varios alcances y ninguna acción se aplicará a todos a la vez.'
                : 'Revise el trabajo exacto que quedó pendiente; no cree una cola adicional.';
            $result['actions'][] = [
                'label' => 'Ver trabajo',
                'url' => '/settings/cron/queue' . ($query !== '' ? '?' . $query : ''),
                'primary' => true,
            ];
        }

        if ($remote && (int) ($incident['http_status'] ?? 0) === 401) {
            $result['administrator_action'] = 'Reautorice únicamente la cuenta afectada antes de continuar.';
            $result['actions'][] = ['label' => 'Ver cuentas Mercado Libre', 'url' => '/meli/accounts', 'primary' => true];
        } elseif ($remote && (int) ($incident['http_status'] ?? 0) === 429) {
            $result['headline'] = 'Mercado Libre respondió rate limit 429.';
            $result['system_behavior'] = $active
                ? 'El ERP debe respetar Retry-After, limitar la cuenta/endpoint afectado y evitar ráfagas.'
                : 'El incidente ya se recuperó, pero cuenta como señal histórica para no subir el ritmo sin estabilidad.';
            $result['administrator_action'] = 'No fuerce reintentos. Revise protección y ritmo antes de aumentar la velocidad.';
            $result['actions'][] = ['label' => 'Ver protección', 'url' => '/settings/api-health/protection', 'primary' => true];
            $result['actions'][] = ['label' => 'Ver ritmo Cron', 'url' => '/settings/cron/rhythm', 'primary' => false];
        }

        if ($result['actions'] === []) {
            $result['actions'][] = ['label' => 'Volver a Salud de Mercado Libre', 'url' => '/settings/api-health', 'primary' => false];
        }
        return $result;
    }
}
