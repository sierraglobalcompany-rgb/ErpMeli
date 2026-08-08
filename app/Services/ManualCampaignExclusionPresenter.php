<?php

declare(strict_types=1);

namespace App\Services;

final class ManualCampaignExclusionPresenter
{
    /** @return array<string,array<string,string>> */
    public function definitions(): array
    {
        return [
            'action_required' => [
                'label' => 'Necesitan intervención',
                'explanation' => 'Tienen un error o una decisión pendiente. Ejecutarlos otra vez sin revisar podría repetir el fallo.',
                'action' => 'Revisar y solucionar',
                'tone' => 'danger',
            ],
            'future' => [
                'label' => 'Programados para después',
                'explanation' => 'Todavía no llegó su hora segura. Conservan su turno y se habilitarán automáticamente.',
                'action' => 'Ver cuándo estarán listos',
                'tone' => 'warning',
            ],
            'running' => [
                'label' => 'En otro proceso',
                'explanation' => 'Otro proceso mantiene una reserva vigente. El ERP evita duplicar la misma consulta.',
                'action' => 'Ver trabajos en curso',
                'tone' => 'info',
            ],
            'paused' => [
                'label' => 'Pausados',
                'explanation' => 'Se detuvieron intencionalmente y necesitan reanudarse antes de poder avanzar.',
                'action' => 'Revisar pausas',
                'tone' => 'warning',
            ],
            'completed' => [
                'label' => 'Ya completados',
                'explanation' => 'Ya fueron resueltos o dejaron de estar pendientes. No hace falta consultarlos otra vez.',
                'action' => 'Ver resultados',
                'tone' => 'success',
            ],
            'automatic_only' => [
                'label' => 'Automáticos por seguridad',
                'explanation' => 'Estas tareas todavía no pueden separarse en un recurso exacto. El ERP las conserva para el lanzador normal y explica su progreso.',
                'action' => 'Abrir su automatización',
                'tone' => 'neutral',
            ],
        ];
    }

    /** @param array<string,mixed> $group @return array<string,mixed> */
    public function group(array $group, string $previewToken): array
    {
        $state = $this->normalizeState((string) ($group['state'] ?? 'automatic_only'));
        $definition = $this->definitions()[$state];
        return $definition + [
            'state' => $state,
            'count' => max(0, (int) ($group['count'] ?? 0)),
            'url' => '/settings/manual-processing/excluded?' . http_build_query([
                'preview' => $previewToken,
                'state' => $state,
            ]),
        ];
    }

    /** @param array<string,mixed> $item @return array<string,mixed> */
    public function item(array $item): array
    {
        $state = $this->normalizeState((string) ($item['state'] ?? 'automatic_only'));
        $queueKey = preg_replace('/[^a-z0-9_-]/', '', (string) ($item['queue_key'] ?? '')) ?: '';
        $sourceId = trim((string) ($item['source_id'] ?? ''));
        $projectionSourceId = explode(':', $sourceId, 2)[0];
        $knownQueues = (new WorkQueueRegistry())->definitionsByKey();
        $workUrl = '';
        if ($queueKey !== '' && isset($knownQueues[$queueKey])
            && $projectionSourceId !== '' && strlen($projectionSourceId) <= 100) {
            $workUrl = '/settings/cron/work?' . http_build_query([
                'queue_key' => $queueKey,
                'source_id' => $projectionSourceId,
            ]);
        }
        $domainUrl = $this->domainUrl($queueKey, (int) ($item['meli_account_id'] ?? 0));
        return $item + $this->definitions()[$state] + [
            'state' => $state,
            'queue_key' => $queueKey,
            'source_id' => $sourceId,
            'work_url' => $workUrl,
            'domain_url' => $domainUrl,
        ];
    }

    public function normalizeState(string $state): string
    {
        return match ($state) {
            'action_required' => 'action_required',
            'future' => 'future',
            'locked', 'running' => 'running',
            'paused' => 'paused',
            'completed', 'completed_elsewhere', 'missing' => 'completed',
            default => 'automatic_only',
        };
    }

    private function domainUrl(string $queueKey, int $accountId): string
    {
        $query = $accountId > 0 ? '?' . http_build_query(['account_id' => $accountId]) : '';
        return match ($queueKey) {
            'notification_fallback', 'notification_backfill' => '/notifications/automation',
            'orders_sync', 'order_enrichment' => '/sync' . $query,
            'financial_recalc' => '/financial-recalc' . $query,
            'sales_audit', 'sales_repair', 'order_date_repair' => '/sales-control' . $query,
            'items_sync' => '/products/meli' . $query,
            'catalog_descriptions' => '/catalogs',
            'questions' => '/questions' . $query,
            'module_jobs' => '/settings/modules',
            default => '/settings/cron/queue?' . http_build_query([
                'group' => 'all',
                'type' => $queueKey,
                'account_id' => $accountId ?: null,
            ]),
        };
    }
}
