<?php

declare(strict_types=1);

namespace App\Services;

final class ManualProcessingService
{
    /** @return array<string,list<string>> */
    public function scopes(): array
    {
        $retiredWithoutExactConsumer = [
            'notification_backfill',
            'recurring_sync',
            'questions',
            'sales_fiscal',
            'module_jobs',
            'claims_search_page',
        ];
        $all = array_values(array_diff(
            array_keys((new WorkQueueRegistry())->definitionsByKey()),
            array_merge(['notification_spool'], $retiredWithoutExactConsumer)
        ));
        return [
            'recommended' => [
                'notification_fallback',
                'orders_sync',
                'order_enrichment',
                'sale_pack_reconciliation',
                'financial_recalc',
                'sale_financial_reconciliation',
                'sales_repair',
                'order_date_repair',
            ],
            'all' => $all,
            'sales' => ['notification_fallback','orders_sync','order_enrichment','sale_pack_reconciliation'],
            'finance' => ['financial_recalc','sale_financial_reconciliation'],
            'audits' => ['sales_audit','sales_repair','order_date_repair'],
            'products' => ['items_sync'],
            'descriptions' => ['catalog_descriptions'],
            'modules' => [],
            'local' => ['operational_maintenance','order_date_repair','financial_recalc'],
        ];
    }

    /** @return array<string,mixed> */
    public function preview(string $scopeKey, ?int $accountId = null): array
    {
        $scopeKey = isset($this->scopes()[$scopeKey]) ? $scopeKey : 'all';
        $wanted = array_flip($this->scopes()[$scopeKey]);
        $maximum = max(100, min(10000, (new AppSettingsService())->int('manual_processing.preview_max_jobs', 5000)));
        $page = (new WorkQueueProjectionService())->all([
            'active_only' => 1,
            'account_id' => $accountId ?: null,
            'queue_keys' => array_keys($wanted),
        ], $maximum);
        $rows = array_values(array_filter(
            $page['rows'],
            static fn (array $row): bool => isset($wanted[(string) ($row['queue_key'] ?? '')])
                && !in_array((string) ($row['display_status'] ?? ''), ['completed','cancelled'], true)
        ));
        $calls = 0;
        $seconds = 0;
        $units = 0;
        $heavy = 0;
        $unknownSizeJobs = 0;
        $accountIds = [];
        $localJobs = 0;
        $remoteJobs = 0;
        $heaviestLabel = 'Ninguna';
        $heaviestRank = -1;
        $loadRanks = [
            'local' => 0,
            'light' => 1,
            'medium' => 2,
            'paginated' => 3,
            'heavy' => 4,
            'cursor' => 5,
            'very_heavy' => 6,
        ];
        foreach ($rows as &$row) {
            $operation = $this->operationForQueue((string) $row['queue_key']);
            $profile = (new MeliOperationProfileRegistry())->get($operation);
            $row['operation_key'] = $operation;
            $row['load_class'] = $profile['load_class'];
            $row['load_label'] = $this->loadLabel((string) $profile['load_class']);
            $calls += max(0, (int) ($row['estimated_api_calls'] ?? 0));
            $seconds += max(1, (int) ($row['estimated_seconds'] ?? 1));
            $units += max(0, (int) $profile['workload_units']);
            if (in_array($profile['load_class'], ['heavy','very_heavy','cursor'], true)) {
                $heavy++;
            }
            if ((int) ($row['item_count'] ?? 0) < 1) {
                $unknownSizeJobs++;
            }
            if (!empty($row['meli_account_id'])) {
                $accountIds[(int) $row['meli_account_id']] = true;
            }
            if (!empty($profile['uses_api'])) {
                $remoteJobs++;
            } else {
                $localJobs++;
            }
            $rank = $loadRanks[(string) $profile['load_class']] ?? 0;
            if ($rank > $heaviestRank) {
                $heaviestRank = $rank;
                $heaviestLabel = (string) $profile['label'];
            }
        }
        unset($row);
        $observation = (new MeliOperationTelemetryService())->observationStatus();
        $telemetry = new MeliOperationTelemetryService();
        $registry = new MeliOperationProfileRegistry();
        $profileReadiness = ['ready' => true, 'verified' => [], 'pending' => []];
        $checkedProfiles = [];
        foreach ($rows as $row) {
            $operation = (string) ($row['operation_key'] ?? 'local_maintenance');
            if ($operation === 'local_maintenance') {
                continue;
            }
            $rowAccountId = !empty($row['meli_account_id']) ? (int) $row['meli_account_id'] : null;
            $checkKey = $operation . ':' . ($rowAccountId ?? '*');
            if (isset($checkedProfiles[$checkKey])) {
                continue;
            }
            $checkedProfiles[$checkKey] = true;
            $readiness = $telemetry->profileReadiness([$operation], $rowAccountId);
            $profile = $registry->get($operation);
            $accountLabel = trim((string) ($row['account_name'] ?? ''));
            $label = (string) $profile['label'] . ($accountLabel !== '' ? ' · ' . $accountLabel : '');
            if (!empty($readiness['ready'])) {
                $profileReadiness['verified'][] = $label;
            } else {
                $profileReadiness['ready'] = false;
                $profileReadiness['pending'][] = $label;
            }
        }
        $localOnly = $checkedProfiles === [];
        $safeModeRequired = !$localOnly && empty($profileReadiness['ready']);
        return [
            'scope_key' => $scopeKey,
            'rows' => $rows,
            'jobs' => count($rows),
            'items' => array_sum(array_map(static fn (array $r): int => max(0, (int) ($r['item_count'] ?? 0)), $rows)),
            'estimated_calls' => $calls,
            'estimated_seconds' => $seconds,
            'workload_units' => $units,
            'delicate_jobs' => $heavy,
            'unknown_size_jobs' => $unknownSizeJobs,
            'source_total' => (int) $page['total'],
            'truncated' => (bool) $page['truncated'],
            'can_start' => count($rows) > 0 && !(bool) $page['truncated'],
            'safe_mode_required' => $safeModeRequired,
            'observation' => $observation,
            'profile_readiness' => $profileReadiness,
            'accounts' => count($accountIds),
            'local_jobs' => $localJobs,
            'remote_jobs' => $remoteJobs,
            'heaviest_operation' => $heaviestLabel,
            'estimated_time_label' => $this->timeEstimateLabel($seconds),
            'contains_descriptions' => count(array_filter(
                $rows,
                static fn (array $row): bool => (string) ($row['queue_key'] ?? '') === 'catalog_descriptions'
            )) > 0,
        ];
    }

    public function operationForQueue(string $queueKey): string
    {
        return match ($queueKey) {
            'notification_fallback' => 'order_exact',
            'orders_sync' => 'orders_search',
            'sales_audit' => 'sales_audit',
            'order_enrichment' => 'shipment_exact',
            'questions' => 'questions_search',
            'financial_recalc' => 'billing_orders',
            'sales_repair' => 'order_exact',
            'catalog_descriptions' => 'item_description',
            'items_sync' => 'item_detail',
            'module_jobs' => 'insights',
            'order_date_repair' => 'local_maintenance',
            default => 'local_maintenance',
        };
    }

    private function loadLabel(string $load): string
    {
        return [
            'local' => 'Sin API', 'light' => 'Ligera', 'medium' => 'Media',
            'paginated' => 'Paginada', 'cursor' => 'Cursor continuo',
            'heavy' => 'Pesada', 'very_heavy' => 'Muy pesada',
        ][$load] ?? 'Sin clasificar';
    }

    private function timeEstimateLabel(int $seconds): string
    {
        if ($seconds < 1) {
            return 'Menos de un ciclo';
        }
        $minimum = max(1, (int) ceil($seconds / 60));
        $maximum = max($minimum, (int) ceil(($seconds * 1.6) / 60));
        if ($maximum < 60) {
            return $minimum === $maximum
                ? $minimum . ' min aprox.'
                : $minimum . '–' . $maximum . ' min aprox.';
        }
        $fromHours = max(1, (int) floor($minimum / 60));
        $toHours = max($fromHours, (int) ceil($maximum / 60));
        return $fromHours === $toHours
            ? $fromHours . ' h aprox.'
            : $fromHours . '–' . $toHours . ' h aprox.';
    }
}
