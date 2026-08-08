<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\InternalUrl;

final class WorkAttentionPresenter
{
    /**
     * @param array<string,mixed> $item
     * @return array<string,mixed>
     */
    public function present(array $item, ?string $decisionReason = null): array
    {
        $status = (string) ($item['display_status'] ?? 'pending');
        $message = trim((string) ($item['safe_error_message'] ?? ''));
        $queueKey = (string) ($item['queue_key'] ?? '');
        $metadata = (new WorkQueueRegistry())->metadataFor($queueKey);
        $policy = (new WorkResolutionPolicyRegistry())->resolve($item);
        $decisionReason ??= (string) $policy['key'];
        $reachedRemote = $policy['reachedRemote'];
        $next = trim((string) ($item['next_eligible_at'] ?? ''));

        $shortReason = match ($decisionReason) {
            'selected_by_priority' => 'Es uno de los trabajos más importantes y antiguos disponibles.',
            'not_yet_eligible' => $next !== ''
                ? 'Todavía no corresponde ejecutarlo; tiene una próxima oportunidad programada.'
                : 'Todavía no corresponde ejecutarlo.',
            'cycle_capacity' => 'Está listo, pero el siguiente ciclo admite un máximo de tres trabajos.',
            'api_slot_capacity' => 'Está listo, pero el siguiente ciclo admite una sola consulta a Mercado Libre.',
            'waiting_budget' => 'El ERP lo aplazó para proteger la integración; Mercado Libre no fue consultado.',
            'automatic_retry' => 'Falló temporalmente y el ERP lo intentará de nuevo sin intervención.',
            'paused' => 'Está detenido intencionalmente y no se ejecutará hasta reanudarlo.',
            'action_required' => (string) $policy['whatHappened'],
            'waiting_deadline', 'waiting_rhythm', 'waiting_budget', 'waiting_api', 'retry',
            'waiting_automatic', 'repairable', 'remote_result_uncertain', 'legacy_needs_diagnosis',
            'skipped_expected' => (string) $policy['whatHappened'],
            default => (string) $policy['whatHappened'],
        };

        $detailUrl = InternalUrl::to('/settings/cron/work?queue_key=' . rawurlencode($queueKey)
            . '&source_id=' . rawurlencode((string) ($item['source_id'] ?? '')));
        $contextRoute = (string) $metadata['context_route'];
        if (!empty($item['meli_account_id'])) {
            $separator = str_contains($contextRoute, '?') ? '&' : '?';
            $contextRoute .= $separator . 'account_id=' . (int) $item['meli_account_id'];
        }
        $contextUrl = InternalUrl::to($contextRoute);

        return [
            'decision_reason' => $decisionReason,
            'policy_key' => (string) $policy['key'],
            'label' => (string) $policy['label'],
            'tone' => (string) $policy['tone'],
            'icon' => $policy['tone'] === 'red' ? '!' : ($policy['tone'] === 'green' ? '✓' : ($policy['tone'] === 'amber' ? '◷' : '•')),
            'short_reason' => $shortReason,
            'cause' => (string) $policy['whatHappened'],
            'impact' => (string) $policy['impact'],
            'recommended_action' => (string) $policy['next'],
            'automatic_behavior' => !empty($policy['automatic'])
                ? 'El ERP continuará automáticamente; este estado no detiene los demás trabajos.'
                : (string) $metadata['automatic_behavior'],
            'reached_remote' => $reachedRemote,
            'reached_remote_label' => match ($reachedRemote) {
                true => 'Sí',
                false => 'No',
                default => 'No se pudo confirmar',
            },
            'blocking_risk' => (string) ($policy['blockingRisk'] ?? 'none'),
            'blocking_risk_label' => match ((string) ($policy['blockingRisk'] ?? 'none')) {
                'critical' => 'Crítico',
                'medium' => 'Medio',
                'low' => 'Bajo',
                default => 'Ninguno',
            },
            'is_actionable' => empty($policy['automatic']) && (array) ($policy['actions'] ?? []) !== [],
            'automatic' => !empty($policy['automatic']),
            'detail_url' => $detailUrl,
            'context_url' => $contextUrl,
            'context_label' => $metadata['context_label'],
            'description' => $metadata['description'],
            'tooltip' => $shortReason . ' ' . (string) $policy['next'],
        ];
    }

}
