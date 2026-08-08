<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use PDO;
use RuntimeException;
use Throwable;

final class ManualCampaignService
{
    /** @return array<string,mixed> */
    public function preview(
        string $scope,
        int $blockSize = 30,
        int $intervalMs = 2000,
        int $blockPauseMs = 30000,
        int $maxBlocks = 0,
        int $maxDurationMinutes = 0,
        ?int $accountId = null
    ): array {
        $base = (new ManualProcessingService())->preview($scope, $accountId);
        $maxBlocks = max(0, min(10000, $maxBlocks));
        $maxDurationMinutes = max(0, min(10080, $maxDurationMinutes));
        $candidateRows = $this->expandDescriptionCandidates((array) ($base['rows'] ?? []));
        $registry = new ManualCampaignAdapterRegistry();
        $profiles = new MeliOperationProfileRegistry();
        $eligible = [];
        $excluded = [];
        $operations = [];
        $selectionLimited = false;
        $selectedCallUnits = 0;
        $maxCallUnits = $maxBlocks > 0 ? $maxBlocks * max(1, $blockSize) : 0;
        foreach ($candidateRows as $row) {
            $queue = (string) ($row['queue_key'] ?? '');
            $adapter = $registry->forQueue($queue);
            if ($adapter === null || !$adapter->supportsExact()) {
                $excluded[] = [
                    'queue_key' => $queue,
                    'source_id' => (string) ($row['source_id'] ?? ''),
                    'meli_account_id' => (int) ($row['meli_account_id'] ?? 0),
                    'account_name' => (string) ($row['account_name'] ?? ''),
                    'label' => (string) ($row['human_label'] ?? $queue),
                    'reason' => 'Todavía no tiene un adaptador exacto certificado.',
                    'state' => 'automatic_only',
                    'next_eligible_at' => null,
                ];
                continue;
            }
            $sourceState = $adapter->inspect(
                (string) ($row['source_id'] ?? ''),
                (int) ($row['meli_account_id'] ?? 0)
            );
            if (!$sourceState->exists || $sourceState->terminal || !$sourceState->eligible) {
                $excluded[] = [
                    'queue_key' => $queue,
                    'source_id' => (string) ($row['source_id'] ?? ''),
                    'meli_account_id' => (int) ($row['meli_account_id'] ?? 0),
                    'account_name' => (string) ($row['account_name'] ?? ''),
                    'label' => (string) ($row['human_label'] ?? $queue),
                    'reason' => $sourceState->message,
                    'state' => $this->excludedState($sourceState->sourceState),
                    'next_eligible_at' => $sourceState->nextEligibleAt,
                ];
                continue;
            }
            $operation = (new ManualProcessingService())->operationForQueue($queue);
            $profile = $profiles->get($operation);
            $usesApi = !empty($profile['uses_api']);
            $estimatedCalls = max(0, (int) ($row['estimated_api_calls'] ?? 0));
            if ($usesApi) {
                $estimatedCalls = max(1, $estimatedCalls);
                if ($maxCallUnits > 0 && $selectedCallUnits + $estimatedCalls > $maxCallUnits) {
                    $selectionLimited = true;
                    continue;
                }
                $selectedCallUnits += $estimatedCalls;
            }
            $minimumMs = max(0, (int) ($profile['minimum_pause_seconds'] ?? 0) * 1000);
            if ($operation === 'item_description') {
                $minimumMs = max(20000, $minimumMs);
            }
            $effectiveMs = max($intervalMs, $minimumMs);
            $row['operation_key'] = $operation;
            $row['requested_interval_ms'] = $intervalMs;
            $row['effective_interval_ms'] = $effectiveMs;
            $row['block_size'] = $operation === 'item_description' ? 1 : $blockSize;
            $row['block_pause_ms'] = $blockPauseMs;
            $row['estimated_api_calls'] = $estimatedCalls;
            $row['uses_api'] = $usesApi;
            $row['item_count'] = max(1, $sourceState->estimatedItems);
            $row['source_state'] = $sourceState->sourceState;
            $eligible[] = $row;
            $key = $queue . ':' . (int) ($row['meli_account_id'] ?? 0);
            if (!isset($operations[$key])) {
                $operations[$key] = [
                    'queue_key' => $queue,
                    'meli_account_id' => (int) ($row['meli_account_id'] ?? 0),
                    'operation_key' => $operation,
                    'label' => (string) ($profile['label'] ?? $queue),
                    'items' => 0,
                    'calls' => 0,
                    'block_size' => $row['block_size'],
                    'requested_interval_ms' => $intervalMs,
                    'effective_interval_ms' => $effectiveMs,
                    'block_pause_ms' => $blockPauseMs,
                    'adjusted' => $effectiveMs !== $intervalMs,
                    'uses_api' => $usesApi,
                ];
            }
            $operations[$key]['items']++;
            $operations[$key]['calls'] += max(0, (int) ($row['estimated_api_calls'] ?? 0));
        }
        foreach ($operations as &$operationRow) {
            $units = !empty($operationRow['uses_api'])
                ? max(1, (int) $operationRow['calls'])
                : max(1, (int) $operationRow['items']);
            $operationRow['blocks'] = (int) ceil($units / max(1, (int) $operationRow['block_size']));
        }
        unset($operationRow);
        $calls = array_sum(array_column($operations, 'calls'));
        $remoteRows = array_values(array_filter($eligible, static fn (array $row): bool => !empty($row['uses_api'])));
        $waitMs = 0;
        foreach ($remoteRows as $position => $row) {
            if ($position > 0) {
                $waitMs += max(0, (int) ($row['effective_interval_ms'] ?? $intervalMs));
            }
        }
        $globalBlocks = $calls > 0 ? (int) ceil($calls / max(1, $blockSize)) : 0;
        $waitMs += max(0, $globalBlocks - 1) * $blockPauseMs;
        $responseSeconds = array_sum(array_map(
            static fn (array $row): int => max(1, (int) ($row['estimated_seconds'] ?? 1)),
            $eligible
        ));
        $excludedSummary = [];
        foreach ($excluded as $item) {
            $key = $this->excludedState((string) $item['state']);
            if (!isset($excludedSummary[$key])) {
                $excludedSummary[$key] = [
                    'state' => $key,
                    'count' => 0,
                    'reason' => (string) $item['reason'],
                ];
            }
            $excludedSummary[$key]['count']++;
        }
        return array_merge($base, [
            'rows' => $eligible,
            'eligible_jobs' => count($eligible),
            'excluded_jobs' => $excluded,
            'excluded_summary' => array_values($excludedSummary),
            'operations' => array_values($operations),
            'estimated_calls' => $calls,
            'block_size' => $blockSize,
            'interval_ms' => $intervalMs,
            'block_pause_ms' => $blockPauseMs,
            'estimated_duration_seconds' => (int) ceil($waitMs / 1000) + $responseSeconds,
            'max_blocks' => $maxBlocks,
            'max_duration_minutes' => $maxDurationMinutes,
            'selection_limited' => $selectionLimited,
            'total_blocks' => max(1, $globalBlocks),
            'can_start_campaign' => $eligible !== [] && empty($base['truncated']),
        ]);
    }

    private function excludedState(string $sourceState): string
    {
        return match ($sourceState) {
            'action_required' => 'action_required',
            'future' => 'future',
            'locked', 'running' => 'running',
            'paused' => 'paused',
            'completed', 'completed_elsewhere', 'missing' => 'completed',
            'automatic_only', 'unsupported' => 'automatic_only',
            default => 'automatic_only',
        };
    }

    /** @return array<string,mixed> */
    public function start(
        int $userId,
        string $scope,
        string $preset,
        int $blockSize,
        int $intervalMs,
        int $blockPauseMs,
        int $maxBlocks,
        int $maxDurationMinutes,
        bool $confirmDescriptions,
        ?int $accountId = null,
        string $origin = 'manual_center',
        array $originContext = [],
        string $previewToken = ''
    ): array {
        $blockSize = max(1, min(60, $blockSize));
        $intervalMs = max(0, min(300000, $intervalMs));
        $blockPauseMs = max(0, min(3600000, $blockPauseMs));
        $maxBlocks = max(0, min(10000, $maxBlocks));
        $maxDurationMinutes = max(0, min(10080, $maxDurationMinutes));
        $previewService = new ManualCampaignPreviewService();
        $preview = $previewToken !== ''
            ? $this->revalidateFrozenPreview($previewService->load($previewToken, $userId))
            : $this->preview(
                $scope,
                $blockSize,
                $intervalMs,
                $blockPauseMs,
                $maxBlocks,
                $maxDurationMinutes,
                $accountId
            );
        if (empty($preview['can_start_campaign'])) {
            throw new RuntimeException('No hay trabajos con adaptador exacto listos para esta campaña.');
        }
        if (!empty($preview['contains_descriptions']) && !$confirmDescriptions) {
            throw new RuntimeException('Confirme que desea incluir descripciones; se procesarán una por una.');
        }
        $pdo = Database::connectionFresh();
        if ((int) $pdo->query("SELECT GET_LOCK('erp_manual_campaign_start',3)")->fetchColumn() !== 1) {
            throw new RuntimeException('Otra campaña se está preparando. Espere unos segundos.');
        }
        try {
            $active = $pdo->query(
                'SELECT id,created_by_user_id FROM manual_campaigns
                 WHERE execution_mode="directed_cli"
                   AND status IN ("active","pausing","paused","finishing")
                 ORDER BY id ASC LIMIT 1'
            )->fetch(PDO::FETCH_ASSOC);
            if (is_array($active)) {
                if ((int) $active['created_by_user_id'] !== $userId) {
                    throw new RuntimeException('Otra persona administra una campaña activa. Espere a que termine.');
                }
                return $this->find((int) $active['id']) ?? ['id' => (int) $active['id']];
            }
            $pdo->beginTransaction();
            $token = bin2hex(random_bytes(20));
            $config = [
                'block_size' => $blockSize,
                'interval_ms' => $intervalMs,
                'block_pause_ms' => $blockPauseMs,
                'max_blocks' => $maxBlocks,
                'max_duration_seconds' => $maxDurationMinutes * 60,
                'account_id' => $accountId,
            ];
            $allowedOrigins = [
                'manual_center',
                'sync_monitor',
                'legacy_process_now',
                'legacy_resume',
                'legacy_retry',
                'financial',
                'notifications',
                'products',
                'descriptions',
                'audits',
                'modules',
            ];
            $origin = in_array($origin, $allowedOrigins, true) ? $origin : 'manual_center';
            $safeContext = [];
            foreach (['account_id', 'year', 'month', 'date_from', 'date_to'] as $key) {
                if (!array_key_exists($key, $originContext)) {
                    continue;
                }
                $value = (string) $originContext[$key];
                if ($key === 'account_id') {
                    $safeContext[$key] = max(0, (int) $value);
                } elseif (in_array($key, ['year', 'month'], true)) {
                    $safeContext[$key] = max(0, (int) $value);
                } elseif (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1) {
                    $safeContext[$key] = $value;
                }
            }
            $totalUnits = array_sum(array_map(
                static fn (array $row): int => max(1, (int) ($row['item_count'] ?? 1)),
                (array) $preview['rows']
            ));
            $pdo->prepare(
                'INSERT INTO manual_campaigns
                 (campaign_token,created_by_user_id,scope_key,origin_key,origin_context_json,preset,
                  execution_mode,progress_model,status,configuration_json,total_items,total_units,total_blocks,
                  next_action_at,safe_message,last_engine_state,started_at)
                 VALUES (?,?,?,?,?,?,"directed_cli","unit_v1","active",?,?,?,?,UTC_TIMESTAMP(3),?,
                         "waiting_launcher",UTC_TIMESTAMP())'
            )->execute([
                $token,
                $userId,
                $scope,
                $origin,
                json_encode($safeContext, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                in_array($preset, ['safe', 'balanced', 'custom', 'repeat'], true) ? $preset : 'safe',
                json_encode($config, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                count($preview['rows']),
                $totalUnits,
                max(1, (int) ($preview['total_blocks'] ?? 1)),
                'Campaña preparada. El lanzador habitual continuará desde el primer recurso pendiente.',
            ]);
            $campaignId = (int) $pdo->lastInsertId();
            $operationIds = [];
            $operationPositions = [];
            $operationBlockOffsets = [];
            $operationSummaries = [];
            $blockOffset = 0;
            foreach ((array) ($preview['operations'] ?? []) as $operationSummary) {
                $summaryKey = (string) $operationSummary['queue_key'] . ':' . (int) ($operationSummary['meli_account_id'] ?? 0);
                $operationSummaries[$summaryKey] = $operationSummary;
                $operationBlockOffsets[$summaryKey] = $blockOffset;
                $blockOffset += max(1, (int) ($operationSummary['blocks'] ?? 1));
            }
            $accountCompanies = [];
            $selectedAccountIds = array_values(array_unique(array_filter(array_map(
                static fn (array $row): int => (int) ($row['meli_account_id'] ?? 0),
                $preview['rows']
            ))));
            if ($selectedAccountIds !== []) {
                $placeholders = implode(',', array_fill(0, count($selectedAccountIds), '?'));
                $accountStmt = $pdo->prepare(
                    'SELECT id,company_id FROM meli_accounts WHERE id IN (' . $placeholders . ')'
                );
                $accountStmt->execute($selectedAccountIds);
                foreach ($accountStmt->fetchAll(PDO::FETCH_ASSOC) as $account) {
                    $accountCompanies[(int) $account['id']] = (int) $account['company_id'];
                }
            }
            $operationStmt = $pdo->prepare(
                'INSERT INTO manual_campaign_operations
                 (manual_campaign_id,queue_key,operation_key,meli_account_id,company_id,item_count,estimated_primary_calls,
                  block_size,requested_interval_ms,effective_interval_ms,block_pause_ms,exact_adapter,safe_adjustment_message)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,1,?)'
            );
            $itemStmt = $pdo->prepare(
                'INSERT INTO manual_campaign_items
                 (manual_campaign_id,operation_id,queue_key,operation_key,source_id,meli_account_id,company_id,
                  human_label,content_summary,position_no,total_units,block_no,next_eligible_at,
                  source_state,source_checked_at,total_units_known)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?,UTC_TIMESTAMP(3),?,UTC_TIMESTAMP(3),1)'
            );
            $reservationSeconds = (new ManualCampaignReservationTtlService())->seconds();
            $reservationStmt = $pdo->prepare(
                'INSERT INTO manual_campaign_reservations
                 (manual_campaign_id,manual_campaign_item_id,queue_key,source_id,company_id,meli_account_id,
                  status,renewed_at,expires_at)
                 VALUES (?,?,?,?,?,?,"active",UTC_TIMESTAMP(3),
                         DATE_ADD(UTC_TIMESTAMP(3),INTERVAL ' . $reservationSeconds . ' SECOND))'
            );
            $position = 0;
            foreach ($preview['rows'] as $row) {
                $position++;
                $queue = (string) $row['queue_key'];
                $accountId = !empty($row['meli_account_id']) ? (int) $row['meli_account_id'] : null;
                $companyId = $accountId ? (int) ($accountCompanies[$accountId] ?? 0) : 0;
                if ($accountId && $companyId < 1) {
                    throw new RuntimeException('No se pudo verificar la empresa de una cuenta seleccionada.');
                }
                $opKey = $queue . ':' . ($accountId ?? 0);
                if (!isset($operationIds[$opKey])) {
                    $operationSummary = $operationSummaries[$opKey] ?? [];
                    $adjustment = (int) $row['effective_interval_ms'] > (int) $row['requested_interval_ms']
                        ? 'Se aplicó un intervalo mayor para proteger esta operación.'
                        : null;
                    $operationStmt->execute([
                        $campaignId,
                        $queue,
                        (string) $row['operation_key'],
                        $accountId,
                        $companyId ?: null,
                        count(array_filter($preview['rows'], static fn (array $candidate): bool =>
                            (string) $candidate['queue_key'] === $queue
                            && (int) ($candidate['meli_account_id'] ?? 0) === (int) ($accountId ?? 0)
                        )),
                        max(0, (int) ($operationSummary['calls'] ?? $row['estimated_api_calls'] ?? 0)),
                        (int) $row['block_size'],
                        (int) $row['requested_interval_ms'],
                        (int) $row['effective_interval_ms'],
                        (int) $row['block_pause_ms'],
                        $adjustment,
                    ]);
                    $operationIds[$opKey] = (int) $pdo->lastInsertId();
                    $operationPositions[$opKey] = 0;
                }
                $operationPositions[$opKey]++;
                $operationBlock = (int) ceil(
                    $operationPositions[$opKey] / max(1, (int) $row['block_size'])
                );
                $globalBlock = (int) ($operationBlockOffsets[$opKey] ?? 0) + $operationBlock;
                $itemStmt->execute([
                    $campaignId,
                    $operationIds[$opKey],
                    $queue,
                    (string) $row['operation_key'],
                    (string) $row['source_id'],
                    $accountId,
                    $companyId ?: null,
                    mb_substr((string) ($row['human_label'] ?? 'Trabajo'), 0, 160),
                    mb_substr((string) ($row['content_summary'] ?? ''), 0, 500),
                    $position,
                    max(1, (int) ($row['item_count'] ?? 1)),
                    $globalBlock,
                    (string) ($row['source_state'] ?? 'ready'),
                ]);
                $campaignItemId = (int) $pdo->lastInsertId();
                $reservationStmt->execute([
                    $campaignId,
                    $campaignItemId,
                    $queue,
                    (string) $row['source_id'],
                    $companyId ?: null,
                    $accountId,
                ]);
            }
            $this->event(
                $pdo,
                $campaignId,
                'campaign_started',
                'success',
                'Campaña preparada con ' . $totalUnits . ' unidades. El lanzador del ERP continuará automáticamente.'
            );
            $pdo->commit();
            if ($previewToken !== '') {
                $previewService->consume($previewToken, $userId);
            }
            return $this->find($campaignId) ?? ['id' => $campaignId];
        } catch (Throwable $error) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $error;
        } finally {
            try {
                $pdo->query("SELECT RELEASE_LOCK('erp_manual_campaign_start')");
            } catch (Throwable) {
            }
        }
    }

    /** @param array<string,mixed> $preview @return array<string,mixed> */
    private function revalidateFrozenPreview(array $preview): array
    {
        $registry = new ManualCampaignAdapterRegistry();
        $rows = [];
        $changed = [];
        foreach ((array) ($preview['rows'] ?? []) as $row) {
            $queue = (string) ($row['queue_key'] ?? '');
            $sourceId = (string) ($row['source_id'] ?? '');
            $accountId = (int) ($row['meli_account_id'] ?? 0);
            $adapter = $registry->forQueue($queue);
            if ($adapter === null || !$adapter->supportsExact()) {
                $changed[] = ['label' => (string) ($row['human_label'] ?? $queue), 'reason' => 'La operación dejó de estar disponible para procesamiento manual.'];
                continue;
            }
            $state = $adapter->inspect($sourceId, $accountId);
            if (!$state->exists || $state->terminal || !$state->eligible) {
                $changed[] = ['label' => (string) ($row['human_label'] ?? $queue), 'reason' => $state->message];
                continue;
            }
            $row['source_state'] = $state->sourceState;
            $row['item_count'] = max(1, $state->estimatedItems);
            $row['estimated_api_calls'] = max(0, $state->estimatedCalls);
            $rows[] = $row;
        }
        $preview['rows'] = $rows;
        $preview['eligible_jobs'] = count($rows);
        $preview['changed_jobs'] = $changed;
        $preview['can_start_campaign'] = $rows !== [];
        return $preview;
    }

    /** @return array<string,mixed>|null */
    public function find(int $campaignId, int $afterEventId = 0): ?array
    {
        if ($campaignId < 1) {
            return null;
        }
        $pdo = Database::connectionFresh();
        $stmt = $pdo->prepare(
            'SELECT c.*,
                    TIMESTAMPDIFF(MICROSECOND,UTC_TIMESTAMP(3),c.next_action_at)/1000 next_in_ms
             FROM manual_campaigns c WHERE c.id=? LIMIT 1'
        );
        $stmt->execute([$campaignId]);
        $campaign = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($campaign)) {
            return null;
        }
        $campaign['owner_user_id'] = (int) ($campaign['created_by_user_id'] ?? 0);
        $campaign['configuration'] = json_decode((string) $campaign['configuration_json'], true) ?: [];
        $campaign['origin_context'] = json_decode((string) ($campaign['origin_context_json'] ?? ''), true) ?: [];
        unset(
            $campaign['configuration_json'],
            $campaign['origin_context_json'],
            $campaign['campaign_token'],
            $campaign['created_by_user_id'],
            $campaign['diagnostic_id'],
            $campaign['control_owner']
        );
        $currentStmt = $pdo->prepare(
            'SELECT i.id,i.human_label,i.content_summary,i.status,i.block_no,i.queue_key,i.source_id,
                    i.total_units,i.completed_units,i.failed_units,i.skipped_units,i.total_units_known,
                    i.meli_account_id,o.block_size,o.operation_key,a.account_name
             FROM manual_campaign_items i
             JOIN manual_campaign_operations o ON o.id=i.operation_id
             JOIN manual_campaigns live_campaign
               ON live_campaign.id=i.manual_campaign_id AND live_campaign.current_item_id=i.id
             LEFT JOIN meli_accounts a ON a.id=i.meli_account_id AND a.company_id=i.company_id
             WHERE i.id=? AND i.status="running" AND i.lease_owner IS NOT NULL
               AND i.lease_expires_at>UTC_TIMESTAMP(3)
               AND live_campaign.worker_heartbeat_at>=DATE_SUB(UTC_TIMESTAMP(3),INTERVAL 180 SECOND)
             LIMIT 1'
        );
        $currentStmt->execute([(int) ($campaign['current_item_id'] ?? 0)]);
        $campaign['current_item'] = $currentStmt->fetch(PDO::FETCH_ASSOC) ?: null;
        if ($campaign['current_item'] === null) {
            // El read model nunca presenta como actual un puntero sin lease y
            // heartbeat vigentes. La recuperación cercada se mantiene en CLI.
            $campaign['current_item_id'] = null;
            $campaign['current_operation_id'] = null;
        }
        $nextStmt = $pdo->prepare(
            'SELECT i.id,i.human_label,i.content_summary,i.status,i.block_no,i.queue_key,i.source_id,
                    i.total_units,i.completed_units,i.failed_units,i.skipped_units,i.total_units_known,
                    i.meli_account_id,o.block_size,o.operation_key,a.account_name
             FROM manual_campaign_items i
             JOIN manual_campaign_operations o ON o.id=i.operation_id
             LEFT JOIN meli_accounts a ON a.id=i.meli_account_id AND a.company_id=i.company_id
             WHERE i.manual_campaign_id=? AND i.status IN ("pending","retry","waiting")
             ORDER BY (i.next_eligible_at IS NULL OR i.next_eligible_at<=UTC_TIMESTAMP(3)) DESC,
                      i.position_no ASC LIMIT 1'
        );
        $nextStmt->execute([$campaignId]);
        $campaign['next_item'] = $nextStmt->fetch(PDO::FETCH_ASSOC) ?: null;
        $eventLimit = max(5, min(25, (new AppSettingsService())->int('manual_campaign.status_event_limit', 25)));
        if ($afterEventId > 0) {
            $events = $pdo->prepare(
                'SELECT id,event_type,severity,safe_message,manual_campaign_item_id,block_no,created_at
                 FROM manual_campaign_events WHERE manual_campaign_id=? AND id>?
                 ORDER BY id ASC LIMIT ' . $eventLimit
            );
            $events->execute([$campaignId, $afterEventId]);
            $campaign['events'] = $events->fetchAll(PDO::FETCH_ASSOC);
        } else {
            $events = $pdo->prepare(
                'SELECT id,event_type,severity,safe_message,manual_campaign_item_id,block_no,created_at
                 FROM manual_campaign_events WHERE manual_campaign_id=?
                 ORDER BY id DESC LIMIT ' . $eventLimit
            );
            $events->execute([$campaignId]);
            $campaign['events'] = $events->fetchAll(PDO::FETCH_ASSOC);
        }
        $campaign['next_event_id'] = $campaign['events'] !== []
            ? max(array_map(static fn (array $event): int => (int) $event['id'], $campaign['events']))
            : max(0, $afterEventId);
        foreach ($campaign['events'] as &$event) {
            $event['display_time'] = DateTimePresenter::formatQueue($event['created_at'] ?? null, 'H:i:s');
        }
        unset($event);
        $countStmt = $pdo->prepare(
            'SELECT
                SUM(status="completed") completed_count,
                SUM(status="skipped") skipped_count,
                SUM(status="failed") failed_count,
                SUM(status="returned") returned_count,
                SUM(status="running") running_count,
                SUM(status="waiting") waiting_count,
                SUM(status="retry") retry_count,
                SUM(status="pending") pending_count,
                SUM(CASE WHEN status="completed" THEN completed_units ELSE 0 END) completed_units,
                SUM(CASE WHEN status="failed" THEN failed_units ELSE 0 END) failed_units,
                SUM(CASE WHEN status="skipped" THEN skipped_units ELSE 0 END) skipped_units,
                SUM(CASE WHEN status IN ("pending","running","waiting","retry")
                    THEN GREATEST(0,total_units-completed_units) ELSE 0 END) open_units
             FROM manual_campaign_items WHERE manual_campaign_id=?'
        );
        $countStmt->execute([$campaignId]);
        $counts = $countStmt->fetch(PDO::FETCH_ASSOC) ?: [];
        $campaign['completed_items'] = (int) ($counts['completed_count'] ?? 0);
        $campaign['skipped_items'] = (int) ($counts['skipped_count'] ?? 0);
        $campaign['failed_items'] = (int) ($counts['failed_count'] ?? 0);
        $campaign['returned_items'] = (int) ($counts['returned_count'] ?? 0);
        $campaign['running_items'] = (int) ($counts['running_count'] ?? 0);
        $campaign['waiting_items'] = (int) ($counts['waiting_count'] ?? 0);
        $campaign['retry_items'] = (int) ($counts['retry_count'] ?? 0);
        $campaign['pending_items'] = (int) ($counts['pending_count'] ?? 0);
        $campaign['completed_units'] = (int) ($counts['completed_units'] ?? 0);
        $campaign['failed_units'] = (int) ($counts['failed_units'] ?? 0);
        $campaign['skipped_units'] = (int) ($counts['skipped_units'] ?? 0);
        $campaign['open_units'] = (int) ($counts['open_units'] ?? 0);
        // El progreso visible certifica resultados realmente completados. Un
        // error, una omisión o una devolución son estados terminales, pero no
        // equivalen a trabajo completado ni alimentan la velocidad observada.
        $completedReal = (int) $campaign['completed_items'];
        $terminalItems = $completedReal
            + (int) $campaign['failed_items']
            + (int) $campaign['skipped_items']
            + (int) $campaign['returned_items'];
        $openItems = (int) $campaign['running_items']
            + (int) $campaign['waiting_items']
            + (int) $campaign['retry_items']
            + (int) $campaign['pending_items'];
        $unitProgress = (string) ($campaign['progress_model'] ?? 'legacy_job') === 'unit_v1';
        $completedUnitsReal = (int) $campaign['completed_units'];
        $progressTotal = $unitProgress
            ? max(0, (int) ($campaign['total_units'] ?? 0))
            : max(0, (int) $campaign['total_items']);
        $progressResolved = $unitProgress ? $completedUnitsReal : $completedReal;
        $progressOpen = $unitProgress
            ? max(max(0, $progressTotal - $progressResolved), $openItems > 0 ? 1 : 0)
            : $openItems;
        $rawPercent = $progressTotal > 0
            ? round($progressResolved * 100 / $progressTotal, 1)
            : 0.0;
        $campaign['progress_percent'] = $progressOpen > 0 ? min(99.9, $rawPercent) : min(100.0, $rawPercent);
        $campaign['resolved_items'] = $completedReal;
        $campaign['resolved_units'] = $completedUnitsReal;
        $campaign['terminal_items'] = $terminalItems;
        $campaign['attention_items'] = (int) $campaign['failed_items'];
        $campaign['continues_with_attention'] = (string) $campaign['status'] === 'active'
            && (int) $campaign['failed_items'] > 0
            && $openItems > 0;
        $campaign['processing_paused'] = in_array((string) $campaign['status'], ['paused', 'pausing'], true);
        $campaign['open_items'] = $openItems;
        $campaign['open_units'] = $progressOpen;
        $remaining = max(max(0, $progressTotal - $progressResolved), $openItems);
        $intervalMs = max(0, (int) ($campaign['configuration']['interval_ms'] ?? 0));
        $blockPauseMs = max(0, (int) ($campaign['configuration']['block_pause_ms'] ?? 0));
        $blockSize = max(1, (int) ($campaign['configuration']['block_size'] ?? 1));
        $waitEstimate = ($remaining * $intervalMs)
            + ((int) ceil($remaining / $blockSize) * $blockPauseMs);
        $elapsedEstimate = 0;
        $clock = new SystemDatabaseUtcClock();
        if ($completedReal >= 3 && !empty($campaign['started_at'])) {
            $startedTimestamp = $clock->timestamp((string) $campaign['started_at']);
            $elapsedSeconds = max(1, time() - ($startedTimestamp ?? time()));
            $elapsedEstimate = (int) ceil(($elapsedSeconds / $completedReal) * $remaining);
        }
        $campaign['estimated_remaining_seconds'] = $remaining < 1
            ? 0
            : ($completedReal >= 3 ? max((int) ceil($waitEstimate / 1000), $elapsedEstimate) : null);
        $campaign['current_call_in_block'] = min(
            $blockSize,
            max(0, (int) ($campaign['block_outbound_calls'] ?? $campaign['calls_in_block']))
                + ((string) ($campaign['current_item']['status'] ?? '') === 'running' ? 1 : 0)
        );
        $campaign['current_block_size'] = $blockSize;
        $accountStmt = $pdo->prepare(
            'SELECT COUNT(DISTINCT o.meli_account_id) account_count,
                    MIN(COALESCE(a.account_name,CONCAT("Cuenta ",o.meli_account_id))) first_account
             FROM manual_campaign_operations o
             LEFT JOIN meli_accounts a ON a.id=o.meli_account_id AND a.company_id=o.company_id
             WHERE o.manual_campaign_id=? AND o.meli_account_id IS NOT NULL'
        );
        $accountStmt->execute([$campaignId]);
        $accountSummary = $accountStmt->fetch(PDO::FETCH_ASSOC) ?: [];
        $accountCount = max(0, (int) ($accountSummary['account_count'] ?? 0));
        $firstAccount = trim((string) ($accountSummary['first_account'] ?? ''));
        $campaign['account_count'] = $accountCount;
        $campaign['account_label'] = $accountCount < 1
            ? 'Todas las cuentas'
            : ($accountCount === 1
                ? $firstAccount
                : $accountCount . ' cuentas · ' . $firstAccount . ' y ' . ($accountCount - 1) . ' más');
        $campaign['job_progress'] = [
            'resolved' => $completedReal,
            'total' => max(0, (int) $campaign['total_items']),
        ];
        $campaign['current_work_progress'] = [
            'resolved' => (int) ($campaign['current_item']['completed_units'] ?? 0),
            'total' => max(0, (int) ($campaign['current_item']['total_units'] ?? 0)),
            'known' => (bool) ($campaign['current_item']['total_units_known'] ?? true),
        ];
        $campaign['rhythm'] = [
            'block' => max(1, (int) (($campaign['completed_blocks'] ?? 0) + 1)),
            'completed_blocks' => max(0, (int) ($campaign['completed_blocks'] ?? 0)),
            'calls' => max(0, (int) ($campaign['block_outbound_calls'] ?? $campaign['calls_in_block'] ?? 0)),
            'size' => $blockSize,
            'outbound' => max(0, (int) ($campaign['outbound_calls'] ?? (
                (int) ($campaign['primary_calls'] ?? 0) + (int) ($campaign['derived_calls'] ?? 0)
            ))),
        ];

        $controlHeartbeat = $clock->timestamp((string) ($campaign['worker_heartbeat_at'] ?? ''));
        $itemLeaseLive = $campaign['current_item'] !== null
            && $controlHeartbeat !== null
            && $controlHeartbeat >= time() - 180;
        $selectedTimestamp = $clock->timestamp((string) ($campaign['last_scheduler_selected_at'] ?? ''));
        $campaignSelectedRecently = $selectedTimestamp !== null && $selectedTimestamp >= time() - 180;
        $launcherRecent = false;
        try {
            $cronHealth = (new CronHealthService())->status();
            $latestAutomatic = is_array($cronHealth['latest_automatic'] ?? null)
                ? $cronHealth['latest_automatic']
                : [];
            $launcherTimestamp = $clock->timestamp((string) (
                $latestAutomatic['heartbeat_at']
                ?? $latestAutomatic['finished_at']
                ?? $latestAutomatic['started_at']
                ?? ''
            ));
            $launcherRecent = $launcherTimestamp !== null && $launcherTimestamp >= time() - 180;
        } catch (Throwable) {
            $launcherRecent = false;
        }
        // Ejes independientes: recibir señal, ser seleccionada y ejecutar un
        // ítem no son sinónimos. engine_live queda como alias compatible de la
        // señal del lanzador, no del heartbeat exclusivo de la campaña.
        $campaign['launcher_recent'] = $launcherRecent;
        $campaign['campaign_selected_recently'] = $campaignSelectedRecently;
        $campaign['item_lease_live'] = $itemLeaseLive;
        $campaign['engine_live'] = $launcherRecent;
        $campaign['browser_live'] = false;
        $campaign['engine_message'] = match (true) {
            $itemLeaseLive => 'Cron está procesando un recurso de esta campaña.',
            $campaignSelectedRecently => 'Cron seleccionó la campaña recientemente; el recurso espera una ventana segura.',
            $launcherRecent => 'Cron está operativo, pero no seleccionó esta campaña en el ciclo reciente.',
            default => 'No se ha recibido una señal reciente del lanzador.',
        };
        $campaign['last_cron_selected_at'] = $campaign['last_scheduler_selected_at'] ?? null;
        $campaign['last_cron_selected_label'] = !empty($campaign['last_scheduler_selected_at'])
            ? ($clock->toBogota((string) $campaign['last_scheduler_selected_at']) . ' hora Bogotá')
            : 'Sin selección registrada';
        $campaign['waiting_reason'] = (string) ($campaign['last_scheduler_reason'] ?? '');
        $campaign['waiting_reason_label'] = $this->waitingReasonLabel($campaign['waiting_reason']);
        $campaign['next_eligible_at'] = $campaign['next_action_at'] ?? null;
        $campaign['next_eligible_label'] = !empty($campaign['next_action_at'])
            ? ($clock->isDue((string) $campaign['next_action_at'])
                ? 'Ahora'
                : $clock->toBogota((string) $campaign['next_action_at']) . ' hora Bogotá')
            : null;
        $campaign['cron_trace'] = [
            'last_selected_at' => $campaign['last_scheduler_selected_at'] ?? null,
            'last_selected_label' => $campaign['last_cron_selected_label'],
            'reason' => $campaign['waiting_reason'],
            'reason_label' => $campaign['waiting_reason_label'],
            'next_at' => $campaign['next_action_at'] ?? null,
            'next_label' => $campaign['next_eligible_label'],
            'message' => $campaign['last_result_message'] ?? $campaign['safe_message'] ?? null,
        ];
        $nextInMs = max(0, (int) ceil((float) ($campaign['next_in_ms'] ?? 0)));
        $lastSchedulerResult = (string) ($campaign['last_scheduler_result'] ?? '');
        $campaign['display_state'] = match ((string) $campaign['status']) {
            'paused' => 'paused',
            'pausing' => 'pausing',
            'finishing' => 'returning',
            'completed' => 'completed',
            'completed_with_issues' => 'completed_with_issues',
            'failed' => 'error',
            default => $itemLeaseLive && $lastSchedulerResult === 'started'
                ? 'processing'
                : ($nextInMs > 0
                    ? 'waiting_interval'
                    : ($launcherRecent ? 'waiting_selection' : 'waiting_launcher')),
        };

        $latestEventStmt = $pdo->prepare(
            'SELECT event_type,severity,safe_message,block_no,created_at
             FROM manual_campaign_events WHERE manual_campaign_id=?
             ORDER BY id DESC LIMIT 1'
        );
        $latestEventStmt->execute([$campaignId]);
        $campaign['latest_event'] = $latestEventStmt->fetch(PDO::FETCH_ASSOC) ?: null;
        if (is_array($campaign['latest_event'])) {
            $campaign['latest_event']['display_time'] = DateTimePresenter::formatQueue(
                $campaign['latest_event']['created_at'] ?? null,
                'H:i:s'
            );
        }
        $campaign['wait_kind'] = (string) ($campaign['latest_event']['event_type'] ?? '') === 'block_completed'
            ? 'block_pause'
            : 'interval';
        if ((int) $campaign['failed_items'] > 0) {
            $attentionStmt = $pdo->prepare(
                'SELECT human_label,result_summary,queue_key,source_id,diagnostic_id
                 FROM manual_campaign_items
                 WHERE manual_campaign_id=? AND status="failed"
                 ORDER BY completed_at DESC,id DESC LIMIT 1'
            );
            $attentionStmt->execute([$campaignId]);
            $campaign['attention'] = $attentionStmt->fetch(PDO::FETCH_ASSOC) ?: null;
        } else {
            $campaign['attention'] = null;
        }
        return $campaign;
    }

    /** @return array{rows:list<array<string,mixed>>,total:int,page:int,per_page:int} */
    public function itemsPage(
        int $campaignId,
        string $status = '',
        string $operation = '',
        int $accountId = 0,
        int $page = 1,
        int $perPage = 50
    ): array {
        $allowedStatuses = ['pending', 'running', 'waiting', 'retry', 'completed', 'skipped', 'failed', 'returned'];
        $status = in_array($status, $allowedStatuses, true) ? $status : '';
        $operation = preg_match('/^[a-z0-9_-]{1,80}$/', $operation) === 1 ? $operation : '';
        $page = max(1, $page);
        $perPage = in_array($perPage, [25, 50, 100], true) ? $perPage : 50;
        $where = ['i.manual_campaign_id=?'];
        $params = [$campaignId];
        if ($status !== '') {
            $where[] = 'i.status=?';
            $params[] = $status;
        }
        if ($operation !== '') {
            $where[] = 'i.operation_key=?';
            $params[] = $operation;
        }
        if ($accountId > 0) {
            $where[] = 'i.meli_account_id=?';
            $params[] = $accountId;
        }
        $pdo = Database::connectionFresh();
        $count = $pdo->prepare('SELECT COUNT(*) FROM manual_campaign_items i WHERE ' . implode(' AND ', $where));
        $count->execute($params);
        $total = (int) $count->fetchColumn();
        $offset = ($page - 1) * $perPage;
        $stmt = $pdo->prepare(
            'SELECT i.id,i.queue_key,i.operation_key,i.human_label,i.content_summary,i.status,
                    i.total_units,i.completed_units,i.failed_units,i.skipped_units,i.primary_calls,
                    i.derived_calls,i.avoided_calls,i.result_summary,i.source_state,
                    i.source_resolution,i.next_eligible_at,i.started_at,i.completed_at,
                    i.lease_expires_at,i.last_cron_run_token,i.last_attempt_result,i.last_attempt_at,
                    c.worker_heartbeat_at,
                    a.account_name
             FROM manual_campaign_items i
             JOIN manual_campaigns c ON c.id=i.manual_campaign_id
             LEFT JOIN meli_accounts a ON a.id=i.meli_account_id AND a.company_id=i.company_id
             WHERE ' . implode(' AND ', $where) . '
             ORDER BY i.position_no ASC,i.id ASC
             LIMIT ' . $perPage . ' OFFSET ' . $offset
        );
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$row) {
            $row['status_label'] = $this->itemStatusLabel($row);
            $clock = new SystemDatabaseUtcClock();
            $row['available_from_label'] = empty($row['next_eligible_at']) || $clock->isDue((string) $row['next_eligible_at'])
                ? 'Ahora'
                : $clock->toBogota((string) $row['next_eligible_at']) . ' hora Bogotá';
            $attemptAt = $row['last_attempt_at'] ?? $row['started_at'] ?? null;
            $row['last_attempt_label'] = !empty($attemptAt)
                ? $clock->toBogota((string) $attemptAt) . ' hora Bogotá'
                : 'Sin intentar';
            $row['wait_reason_label'] = $this->itemWaitingReasonLabel($row);
        }
        unset($row);
        return [
            'rows' => $rows,
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
        ];
    }

    /** @return array{rows:list<array<string,mixed>>,total:int,page:int,per_page:int} */
    public function eventsPage(int $campaignId, string $severity = '', int $page = 1, int $perPage = 50): array
    {
        $severity = in_array($severity, ['info', 'success', 'warning', 'error'], true) ? $severity : '';
        $page = max(1, $page);
        $perPage = in_array($perPage, [25, 50, 100], true) ? $perPage : 50;
        $where = ['e.manual_campaign_id=?'];
        $params = [$campaignId];
        if ($severity !== '') {
            $where[] = 'e.severity=?';
            $params[] = $severity;
        }
        $pdo = Database::connectionFresh();
        $count = $pdo->prepare('SELECT COUNT(*) FROM manual_campaign_events e WHERE ' . implode(' AND ', $where));
        $count->execute($params);
        $total = (int) $count->fetchColumn();
        $offset = ($page - 1) * $perPage;
        $stmt = $pdo->prepare(
            'SELECT e.id,e.event_type,e.severity,e.safe_message,e.block_no,e.created_at,
                    i.human_label,a.account_name
             FROM manual_campaign_events e
             LEFT JOIN manual_campaign_items i ON i.id=e.manual_campaign_item_id
             LEFT JOIN meli_accounts a ON a.id=i.meli_account_id AND a.company_id=i.company_id
             WHERE ' . implode(' AND ', $where) . '
             ORDER BY e.id DESC LIMIT ' . $perPage . ' OFFSET ' . $offset
        );
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$row) {
            $row['display_time'] = DateTimePresenter::formatQueue($row['created_at'] ?? null);
        }
        unset($row);
        return [
            'rows' => $rows,
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
        ];
    }

    /** @return array<string,mixed>|null */
    public function active(?int $userId = null): ?array
    {
        try {
            $pdo = Database::connectionFresh();
            $sql =
                'SELECT id FROM manual_campaigns
                 WHERE execution_mode="directed_cli"
                   AND status IN ("active","pausing","paused","finishing")'
                . ($userId !== null ? ' AND created_by_user_id=?' : '')
                . ' ORDER BY id ASC LIMIT 1';
            $stmt = $pdo->prepare($sql);
            $stmt->execute($userId !== null ? [$userId] : []);
            $id = $stmt->fetchColumn();
            return $id ? $this->find((int) $id) : null;
        } catch (Throwable) {
            return null;
        }
    }

    /** @return array<string,mixed>|null */
    public function claim(string $worker, ?int $campaignId = null): ?array
    {
        return $this->claimInternal($worker, $campaignId, null);
    }

    /** Reclama únicamente el ítem elegido por el scheduler read-only. */
    public function claimExact(string $worker, int $campaignId, int $itemId): ?array
    {
        if ($campaignId < 1 || $itemId < 1) {
            return null;
        }
        return $this->claimInternal($worker, $campaignId, $itemId);
    }

    /** @return array<string,mixed>|null */
    private function claimInternal(string $worker, ?int $campaignId, ?int $itemId): ?array
    {
        $pdo = Database::connectionFresh();
        $pdo->beginTransaction();
        try {
            if ($campaignId !== null && $campaignId > 0) {
                $pdo->prepare(
                    'UPDATE manual_campaign_items
                     SET status="retry",lease_owner=NULL,lease_expires_at=NULL,
                         next_eligible_at=UTC_TIMESTAMP(3),
                         result_summary="El intento anterior se interrumpió. Se continuará desde el punto guardado."
                     WHERE manual_campaign_id=? AND status="running"
                       AND lease_expires_at<UTC_TIMESTAMP()'
                )->execute([$campaignId]);
            }
            $campaignSql =
                'SELECT * FROM manual_campaigns
                 WHERE execution_mode="directed_cli" AND status="active"
                   AND (next_action_at IS NULL OR next_action_at<=UTC_TIMESTAMP(3))
                   AND current_item_id IS NULL'
                . ($campaignId !== null && $campaignId > 0 ? ' AND id=?' : '')
                . ' ORDER BY created_at ASC,id ASC LIMIT 1 FOR UPDATE';
            $campaignStmt = $pdo->prepare($campaignSql);
            $campaignParams = [];
            if ($campaignId !== null && $campaignId > 0) {
                $campaignParams[] = $campaignId;
            }
            $campaignStmt->execute($campaignParams);
            $campaign = $campaignStmt->fetch(PDO::FETCH_ASSOC);
            if (!is_array($campaign)) {
                $pdo->commit();
                return null;
            }
            $itemIdPredicate = $itemId !== null ? ' AND i.id=?' : '';
            $stmt = $pdo->prepare(
                'SELECT i.*,o.block_size,o.effective_interval_ms,o.block_pause_ms
                 FROM manual_campaign_items i
                 JOIN manual_campaign_operations o ON o.id=i.operation_id
                 WHERE i.manual_campaign_id=? AND i.status IN ("pending","retry","waiting")
                   AND (i.next_eligible_at IS NULL OR i.next_eligible_at<=UTC_TIMESTAMP(3))
                   AND (i.lease_expires_at IS NULL OR i.lease_expires_at<UTC_TIMESTAMP())
                   ' . $itemIdPredicate . '
                 ORDER BY i.position_no ASC LIMIT 1 FOR UPDATE'
            );
            $itemParams = [(int) $campaign['id']];
            if ($itemId !== null) {
                $itemParams[] = $itemId;
            }
            $stmt->execute($itemParams);
            $item = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!is_array($item)) {
                $nextEligible = $pdo->prepare(
                    'SELECT MIN(next_eligible_at)
                     FROM manual_campaign_items
                     WHERE manual_campaign_id=? AND status IN ("pending","retry","waiting")
                       AND next_eligible_at>UTC_TIMESTAMP(3)'
                );
                $nextEligible->execute([(int) $campaign['id']]);
                $resumeAt = $nextEligible->fetchColumn();
                if (is_string($resumeAt) && $resumeAt !== '') {
                    $pdo->prepare(
                        'UPDATE manual_campaigns
                         SET next_action_at=?,safe_message="Esperando la próxima oportunidad segura.",
                             version_no=version_no+1
                         WHERE id=?'
                    )->execute([$resumeAt, (int) $campaign['id']]);
                }
                $this->finalizeIfDone($pdo, (int) $campaign['id']);
                $pdo->commit();
                return null;
            }
            $configuration = json_decode((string) ($campaign['configuration_json'] ?? ''), true) ?: [];
            $configuredBlockSize = max(1, (int) ($configuration['block_size'] ?? 1));
            $itemBlockSize = max(1, (int) ($item['block_size'] ?? $configuredBlockSize));
            $globalBlockCalls = max(0, (int) ($campaign['block_outbound_calls'] ?? $campaign['calls_in_block'] ?? 0));
            if ($itemBlockSize < $configuredBlockSize && $globalBlockCalls > 0) {
                $pauseMs = max(0, (int) ($item['block_pause_ms'] ?? 0));
                $nextActionAt = (new ManualCampaignRhythmService())->utcAfterMilliseconds($pauseMs);
                $pdo->prepare(
                    'UPDATE manual_campaigns
                     SET completed_blocks=completed_blocks+1,current_block=completed_blocks+2,
                         block_outbound_calls=0,calls_in_block=0,
                         block_completed_at=UTC_TIMESTAMP(3),next_action_at=?,
                         last_engine_state="block_pause",
                         safe_message="Se cerró el bloque anterior antes de una operación más delicada.",
                         version_no=version_no+1
                     WHERE id=?'
                )->execute([$nextActionAt, (int) $campaign['id']]);
                $this->event(
                    $pdo,
                    (int) $campaign['id'],
                    'block_completed',
                    'info',
                    'Bloque completado. La siguiente operación utilizará un límite más estricto.',
                    null,
                    (int) ($campaign['completed_blocks'] ?? 0) + 1
                );
                $pdo->commit();
                return null;
            }
            $generation = (int) $item['lease_generation'] + 1;
            $claimStmt = $pdo->prepare(
                'UPDATE manual_campaign_items
                 SET status="running",lease_owner=?,lease_generation=?,lease_expires_at=DATE_ADD(UTC_TIMESTAMP(),INTERVAL 2 MINUTE),
                     attempts=attempts+1,started_at=COALESCE(started_at,UTC_TIMESTAMP())
                 WHERE id=? AND manual_campaign_id=?
                   AND status IN ("pending","retry","waiting")
                   AND (lease_expires_at IS NULL OR lease_expires_at<UTC_TIMESTAMP())'
            );
            $claimStmt->execute([$worker, $generation, (int) $item['id'], (int) $campaign['id']]);
            if ($claimStmt->rowCount() !== 1) {
                $pdo->commit();
                return null;
            }
            $pdo->prepare(
                'UPDATE manual_campaigns
                 SET current_item_id=?,
                     current_operation_id=?,
                     worker_heartbeat_at=UTC_TIMESTAMP(),version_no=version_no+1,
                     last_engine_state="processing",
                     safe_message="Cron está procesando el siguiente recurso de la campaña."
                 WHERE id=?'
            )->execute([
                (int) $item['id'],
                (int) $item['operation_id'],
                (int) $campaign['id'],
            ]);
            $this->event($pdo, (int) $campaign['id'], 'item_started', 'info', 'Comenzó: ' . $item['human_label'], (int) $item['id'], (int) $item['block_no']);
            $pdo->commit();
            $item['lease_owner'] = $worker;
            $item['lease_generation'] = $generation;
            $item['campaign'] = $campaign;
            return $item;
        } catch (Throwable $error) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $error;
        }
    }

    /**
     * @param array<string,mixed> $item
     * @param array{run_id:int,attempt_id:int,sequence:int}|null $approval
     */
    public function complete(array $item, CampaignItemResult $result, ?array $approval = null): void
    {
        $pdo = Database::connectionFresh();
        $pdo->beginTransaction();
        try {
            $owns = $pdo->prepare(
                'SELECT COUNT(*) FROM manual_campaign_items
                 WHERE id=? AND status="running" AND lease_owner=? AND lease_generation=? FOR UPDATE'
            );
            $owns->execute([(int) $item['id'], (string) $item['lease_owner'], (int) $item['lease_generation']]);
            if ((int) $owns->fetchColumn() !== 1) {
                throw new RuntimeException('La reserva del trabajo cambió; el resultado no se guardó.');
            }
            $itemStatus = match ($result->status) {
                'completed' => 'completed',
                'skipped' => 'skipped',
                'deferred' => 'waiting',
                'retry' => 'retry',
                default => 'failed',
            };
            $restoreAttempt = $this->shouldRestoreCampaignAttempt($result, $itemStatus);
            $next = $result->nextEligibleAt;
            $unitTotal = max(1, (int) ($item['total_units'] ?? 1));
            $unitDoneBefore = max(0, (int) ($item['completed_units'] ?? 0))
                + max(0, (int) ($item['failed_units'] ?? 0))
                + max(0, (int) ($item['skipped_units'] ?? 0));
            $unitRemaining = max(0, $unitTotal - $unitDoneBefore);
            // Revisar o aplazar un recurso no constituye progreso. Las unidades
            // solo se certifican cuando el resultado ya es terminal.
            $completedUnits = $itemStatus === 'completed'
                ? min($unitRemaining, max(0, $result->processed))
                : 0;
            $remainingAfterProcessed = max(0, $unitRemaining - $completedUnits);
            $failedUnits = $itemStatus === 'failed' ? $remainingAfterProcessed : 0;
            $skippedUnits = in_array($itemStatus, ['completed', 'skipped'], true)
                ? $remainingAfterProcessed
                : 0;
            $pdo->prepare(
                'UPDATE manual_campaign_items
                 SET status=?,primary_calls=primary_calls+?,derived_calls=derived_calls+?,avoided_calls=avoided_calls+?,
                     completed_units=completed_units+?,failed_units=failed_units+?,skipped_units=skipped_units+?,
                     attempts=GREATEST(0,attempts-?),last_attempt_result=?,
                     result_summary=?,diagnostic_id=?,next_eligible_at=?,source_resolution=?,lease_owner=NULL,lease_expires_at=NULL,
                     completed_at=IF(? IN ("completed","skipped","failed"),UTC_TIMESTAMP(),NULL)
                 WHERE id=?'
            )->execute([
                $itemStatus,
                $result->primaryCalls,
                $result->derivedCalls,
                $result->avoidedCalls,
                $completedUnits,
                $failedUnits,
                $skippedUnits,
                $restoreAttempt ? 1 : 0,
                $result->reason,
                mb_substr($result->message, 0, 500),
                $result->diagnosticId,
                $next,
                $result->reason,
                $itemStatus,
                (int) $item['id'],
            ]);
            $completedInc = $itemStatus === 'completed' ? 1 : 0;
            $skippedInc = $itemStatus === 'skipped' ? 1 : 0;
            $failedInc = $itemStatus === 'failed' ? 1 : 0;
            $outboundCalls = max(0, $result->primaryCalls + $result->derivedCalls);
            $campaignConfig = json_decode((string) ($item['campaign']['configuration_json'] ?? ''), true) ?: [];
            $configuredBlockSize = max(1, (int) ($campaignConfig['block_size'] ?? $item['block_size'] ?? 1));
            $effectiveBlockSize = min($configuredBlockSize, max(1, (int) ($item['block_size'] ?? $configuredBlockSize)));
            $openStmt = $pdo->prepare(
                'SELECT COUNT(*) FROM manual_campaign_items
                 WHERE manual_campaign_id=? AND id<>? AND status IN ("pending","waiting","retry","running")'
            );
            $openStmt->execute([(int) $item['manual_campaign_id'], (int) $item['id']]);
            $hasOpenWork = (int) $openStmt->fetchColumn() > 0
                || in_array($itemStatus, ['waiting', 'retry'], true);
            $rhythm = (new ManualCampaignRhythmService())->afterStep(
                (int) ($item['campaign']['block_outbound_calls'] ?? $item['campaign']['calls_in_block'] ?? 0),
                (int) ($item['campaign']['completed_blocks'] ?? 0),
                $outboundCalls,
                $effectiveBlockSize,
                (int) $item['effective_interval_ms'],
                (int) $item['block_pause_ms'],
                $hasOpenWork
            );
            $blockEnded = $rhythm['block_ended'];
            $nextActionAt = (new ManualCampaignRhythmService())->utcAfterMilliseconds($rhythm['delay_ms']);
            $readySiblingStmt = $pdo->prepare(
                'SELECT COUNT(*) FROM manual_campaign_items
                 WHERE manual_campaign_id=? AND id<>? AND status IN ("pending","waiting","retry")
                   AND (next_eligible_at IS NULL OR next_eligible_at<=UTC_TIMESTAMP(3))
                   AND (lease_expires_at IS NULL OR lease_expires_at<UTC_TIMESTAMP(3))'
            );
            $readySiblingStmt->execute([(int) $item['manual_campaign_id'], (int) $item['id']]);
            $hasReadySibling = (int) $readySiblingStmt->fetchColumn() > 0;
            if ($hasReadySibling && in_array($itemStatus, ['waiting', 'retry'], true)) {
                // El backoff de un recurso no bloquea otros recursos independientes.
                $nextActionAt = gmdate('Y-m-d H:i:s.v');
            } elseif ($next !== null && $next !== '') {
                $deferredAt = new \DateTimeImmutable($next, new \DateTimeZone('UTC'));
                $calculated = new \DateTimeImmutable($nextActionAt, new \DateTimeZone('UTC'));
                if ($deferredAt > $calculated) {
                    $nextActionAt = $deferredAt->format('Y-m-d H:i:s.v');
                }
            }
            $pdo->prepare(
                'UPDATE manual_campaigns
                 SET completed_items=completed_items+?,skipped_items=skipped_items+?,failed_items=failed_items+?,
                     completed_units=completed_units+?,failed_units=failed_units+?,skipped_units=skipped_units+?,
                     retry_items=(SELECT COUNT(*) FROM manual_campaign_items retry_state
                                  WHERE retry_state.manual_campaign_id=? AND retry_state.status IN ("waiting","retry")),
                     primary_calls=primary_calls+?,derived_calls=derived_calls+?,
                     outbound_calls=outbound_calls+?,avoided_calls=avoided_calls+?,
                     calls_in_block=?,block_outbound_calls=?,current_block=?,completed_blocks=?,
                     block_started_at=IF(?=1,UTC_TIMESTAMP(3),COALESCE(block_started_at,UTC_TIMESTAMP(3))),
                     block_completed_at=IF(?=1,UTC_TIMESTAMP(3),block_completed_at),
                     next_action_at=?,
                     current_item_id=NULL,worker_heartbeat_at=UTC_TIMESTAMP(),version_no=version_no+1,
                     last_engine_state=?,safe_message=?,last_result_message=?,
                     last_scheduler_reason=IFNULL(?,last_scheduler_reason)
                 WHERE id=?'
            )->execute([
                $completedInc,
                $skippedInc,
                $failedInc,
                $completedUnits,
                $failedUnits,
                $skippedUnits,
                (int) $item['manual_campaign_id'],
                $result->primaryCalls,
                $result->derivedCalls,
                $outboundCalls,
                $result->avoidedCalls,
                $rhythm['block_calls'],
                $rhythm['block_calls'],
                $rhythm['current_block'],
                $rhythm['completed_blocks'],
                $blockEnded ? 1 : 0,
                $blockEnded ? 1 : 0,
                $nextActionAt,
                $blockEnded ? 'block_pause' : ($itemStatus === 'waiting' ? 'waiting' : 'ready'),
                $blockEnded
                    ? 'Bloque completado. Esperando antes de continuar.'
                    : $result->message,
                mb_substr($result->message, 0, 500),
                $result->reason,
                (int) $item['manual_campaign_id'],
            ]);
            if (in_array($itemStatus, ['completed', 'skipped', 'failed'], true)) {
                $pdo->prepare(
                    'UPDATE manual_campaign_reservations
                     SET status="released",released_at=UTC_TIMESTAMP(3)
                     WHERE manual_campaign_item_id=? AND status="active"'
                )->execute([(int) $item['id']]);
            }
            $severity = $failedInc ? 'error' : ($itemStatus === 'waiting' ? 'warning' : 'success');
            $type = $blockEnded ? 'block_completed' : 'item_' . $itemStatus;
            $this->event($pdo, (int) $item['manual_campaign_id'], $type, $severity, $result->message, (int) $item['id'], (int) $item['block_no']);
            $this->recalculateCampaignCounters($pdo, (int) $item['manual_campaign_id']);
            $this->finalizeIfDone($pdo, (int) $item['manual_campaign_id']);
            if ($approval !== null) {
                $attempt = $pdo->prepare(
                    'UPDATE system_execution_attempts
                     SET state="approved",safe_message=?,result_applied_at=UTC_TIMESTAMP(3),
                         approved_at=UTC_TIMESTAMP(3),completed_at=UTC_TIMESTAMP(3),approval_sequence=?
                     WHERE id=? AND manual_campaign_item_id=? AND lease_generation=?
                       AND state="response_received"'
                );
                $attempt->execute([
                    mb_substr(Logger::redactString($result->message), 0, 500),
                    (int) $approval['sequence'],
                    (int) $approval['attempt_id'],
                    (int) $item['id'],
                    (int) $item['lease_generation'],
                ]);
                if ($attempt->rowCount() !== 1) {
                    throw new RuntimeException('El diario cambió; el resultado no se aprobó.');
                }
                $pdo->prepare(
                    'UPDATE manual_campaigns
                     SET last_approved_step=GREATEST(last_approved_step,?),uncertain_attempts=0
                     WHERE id=?'
                )->execute([(int) $approval['sequence'], (int) $item['manual_campaign_id']]);
                $pdo->prepare(
                    'UPDATE system_execution_runs
                     SET approved_attempts=approved_attempts+1,heartbeat_at=UTC_TIMESTAMP(3)
                     WHERE id=? AND status="running"'
                )->execute([(int) $approval['run_id']]);
            }
            $pdo->commit();
        } catch (Throwable $error) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $error;
        }
    }

    /**
     * Estaciona un recurso no ejecutable antes del claim. No abre journal, no
     * consume intento y solo crea un evento cuando cambia la causa observada.
     */
    public function markWaitingSourceBeforeClaim(
        int $campaignId,
        int $itemId,
        CampaignItemState $source
    ): bool {
        if ($campaignId <= 0 || $itemId <= 0
            || !in_array($source->sourceState, ['paused', 'future', 'locked'], true)) {
            return false;
        }
        $pdo = Database::connectionFresh();
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare(
                'SELECT status,source_state,source_resolution,next_eligible_at,source_checked_at,
                        operation_key,meli_account_id
                 FROM manual_campaign_items
                 WHERE id=? AND manual_campaign_id=?
                   AND status IN ("pending","waiting","retry")
                   AND (lease_expires_at IS NULL OR lease_expires_at<UTC_TIMESTAMP(3))
                 FOR UPDATE'
            );
            $stmt->execute([$itemId, $campaignId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!is_array($row)) {
                $pdo->commit();
                return false;
            }
            $message = mb_substr($source->message !== ''
                ? $source->message
                : 'El trabajo origen todavía no está disponible.', 0, 120);
            $nextEligibleAt = $source->nextEligibleAt;
            if ($nextEligibleAt === null || $nextEligibleAt === '') {
                $seconds = $source->sourceState === 'paused' ? 900 : 60;
                $nextEligibleAt = gmdate('Y-m-d H:i:s', time() + $seconds);
            }
            $changed = (string) ($row['source_state'] ?? '') !== 'waiting_source'
                || (string) ($row['source_resolution'] ?? '') !== $message
                || (string) ($row['next_eligible_at'] ?? '') !== $nextEligibleAt;
            if (!$changed) {
                $pdo->commit();
                return false;
            }
            $pdo->prepare(
                'UPDATE manual_campaign_items
                 SET status="waiting",source_state="waiting_source",
                     source_checked_at=UTC_TIMESTAMP(3),source_resolution=?,
                     last_attempt_result="waiting_source",
                     next_eligible_at=?,
                     lease_owner=NULL,lease_expires_at=NULL
                 WHERE id=? AND manual_campaign_id=?'
            )->execute([$message, $nextEligibleAt, $itemId, $campaignId]);
            if (!$this->hasRecentCampaignEvent(
                $pdo,
                $campaignId,
                'source_waiting',
                (string) ($row['operation_key'] ?? ''),
                (int) ($row['meli_account_id'] ?? 0),
                'El origen todavía no está disponible. Cron continuará con los demás trabajos.',
                900
            )) {
                $this->event(
                    $pdo,
                    $campaignId,
                    'source_waiting',
                    'info',
                    'El origen todavía no está disponible. Cron continuará con los demás trabajos.',
                    $itemId,
                    null
                );
            }
            $pdo->prepare(
                'UPDATE manual_campaigns
                 SET safe_message="Hay trabajos esperando módulos; Cron continuará con los demás.",
                     last_scheduler_reason="waiting_source",version_no=version_no+1
                 WHERE id=? AND status="active"'
            )->execute([$campaignId]);
            $pdo->commit();
            return true;
        } catch (Throwable $error) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $error;
        }
    }

    /**
     * Aísla antes del claim una fuente que exige intervención. No abre un
     * intento ni incrementa attempts porque no comenzó trabajo remoto.
     */
    public function markSourceAttentionBeforeClaim(
        int $campaignId,
        int $itemId,
        CampaignItemState $source
    ): bool {
        if ($campaignId <= 0 || $itemId <= 0
            || !in_array($source->sourceState, ['action_required', 'unsupported'], true)) {
            return false;
        }
        $pdo = Database::connectionFresh();
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare(
                'SELECT status,source_state,source_resolution,total_units,completed_units,skipped_units,
                        operation_key,meli_account_id
                 FROM manual_campaign_items
                 WHERE id=? AND manual_campaign_id=?
                   AND status IN ("pending","waiting","retry")
                   AND (lease_expires_at IS NULL OR lease_expires_at<UTC_TIMESTAMP(3))
                 FOR UPDATE'
            );
            $stmt->execute([$itemId, $campaignId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!is_array($row)) {
                $pdo->commit();
                return false;
            }
            $message = mb_substr($source->message !== ''
                ? $source->message
                : 'El trabajo origen necesita una revisión local.', 0, 500);
            $remaining = max(0,
                (int) ($row['total_units'] ?? 0)
                - (int) ($row['completed_units'] ?? 0)
                - (int) ($row['skipped_units'] ?? 0)
            );
            $pdo->prepare(
                'UPDATE manual_campaign_items
                 SET status="failed",source_state="action_required",source_checked_at=UTC_TIMESTAMP(3),
                     source_resolution=?,result_summary=?,last_attempt_result="action_required",
                     failed_units=?,next_eligible_at=NULL,completed_at=UTC_TIMESTAMP(3),
                     lease_owner=NULL,lease_expires_at=NULL
                 WHERE id=? AND manual_campaign_id=?'
            )->execute([$message, $message, $remaining, $itemId, $campaignId]);
            if (!$this->hasRecentCampaignEvent(
                $pdo,
                $campaignId,
                'source_attention_required',
                (string) ($row['operation_key'] ?? ''),
                (int) ($row['meli_account_id'] ?? 0),
                'El origen necesita una revisión local. Cron continuará con los demás trabajos.',
                900
            )) {
                $this->event(
                    $pdo,
                    $campaignId,
                    'source_attention_required',
                    'warning',
                    'El origen necesita una revisión local. Cron continuará con los demás trabajos.',
                    $itemId,
                    null
                );
            }
            $this->recalculateCampaignCounters($pdo, $campaignId);
            $this->finalizeIfDone($pdo, $campaignId);
            $pdo->commit();
            return true;
        } catch (Throwable $error) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $error;
        }
    }

    public function markSourceReadyBeforeClaim(int $campaignId, int $itemId): void
    {
        Database::connectionFresh()->prepare(
            'UPDATE manual_campaign_items
             SET source_state="ready",source_checked_at=UTC_TIMESTAMP(3),source_resolution="Listo para procesar."
             WHERE id=? AND manual_campaign_id=? AND source_state="waiting_source"
               AND status IN ("pending","waiting","retry")'
        )->execute([$itemId, $campaignId]);
    }

    /**
     * Recupera de forma cercada un puntero de campaña cuyo lease ya venció.
     * Si no hubo transporte remoto, el recurso vuelve a retry sin consumir un
     * intento fallido. Si el transporte pudo haber comenzado, queda aislado
     * para revisión y los demás recursos de la campaña pueden continuar.
     *
     * @return array{result:string,campaign_id:int,item_id:int,reached_remote:?bool}
     */
    public function recoverInterruptedCampaignItem(int $campaignId): array
    {
        if ($campaignId < 1) {
            return ['result' => 'missing', 'campaign_id' => $campaignId, 'item_id' => 0, 'reached_remote' => null];
        }
        $pdo = Database::connectionFresh();
        $pdo->beginTransaction();
        try {
            $campaignStmt = $pdo->prepare(
                'SELECT id,status,current_item_id,current_operation_id
                 FROM manual_campaigns WHERE id=? FOR UPDATE'
            );
            $campaignStmt->execute([$campaignId]);
            $campaign = $campaignStmt->fetch(PDO::FETCH_ASSOC);
            if (!is_array($campaign)) {
                $pdo->commit();
                return ['result' => 'missing', 'campaign_id' => $campaignId, 'item_id' => 0, 'reached_remote' => null];
            }

            $currentItemId = (int) ($campaign['current_item_id'] ?? 0);
            $itemStmt = $pdo->prepare(
                'SELECT id,status,lease_owner,lease_generation,lease_expires_at,block_no
                 FROM manual_campaign_items
                 WHERE manual_campaign_id=? AND (
                    id=? OR (status="running" AND lease_expires_at<UTC_TIMESTAMP(3))
                 )
                 ORDER BY (id=?) DESC,id ASC LIMIT 1 FOR UPDATE'
            );
            $itemStmt->execute([$campaignId, $currentItemId, $currentItemId]);
            $item = $itemStmt->fetch(PDO::FETCH_ASSOC);

            if (!is_array($item)) {
                if ($currentItemId > 0) {
                    $pdo->prepare(
                        'UPDATE manual_campaigns
                         SET current_item_id=NULL,current_operation_id=NULL,
                             last_engine_state=IF(status="active","ready",last_engine_state),
                             safe_message=IF(status="active","Se reparó una referencia de ejecución anterior.",safe_message),
                             next_action_at=IF(status="active",UTC_TIMESTAMP(3),next_action_at),
                             version_no=version_no+1
                         WHERE id=? AND current_item_id=?'
                    )->execute([$campaignId, $currentItemId]);
                    $this->event($pdo, $campaignId, 'orphan_pointer_recovered', 'warning',
                        'Se retiró una referencia vencida. La campaña continuará con el siguiente recurso.');
                    $pdo->commit();
                    return ['result' => 'pointer_recovered', 'campaign_id' => $campaignId, 'item_id' => $currentItemId, 'reached_remote' => null];
                }
                $pdo->commit();
                return ['result' => 'clean', 'campaign_id' => $campaignId, 'item_id' => 0, 'reached_remote' => null];
            }

            $itemId = (int) $item['id'];
            if ((string) $item['status'] !== 'running') {
                $pdo->prepare(
                    'UPDATE manual_campaigns
                     SET current_item_id=NULL,current_operation_id=NULL,
                         last_engine_state=IF(status="active","ready",last_engine_state),
                         next_action_at=IF(status="active",UTC_TIMESTAMP(3),next_action_at),
                         version_no=version_no+1
                     WHERE id=? AND current_item_id=?'
                )->execute([$campaignId, $itemId]);
                $pdo->commit();
                return ['result' => 'pointer_recovered', 'campaign_id' => $campaignId, 'item_id' => $itemId, 'reached_remote' => null];
            }

            $leaseExpires = (new SystemDatabaseUtcClock())->timestamp((string) ($item['lease_expires_at'] ?? ''));
            if ($leaseExpires !== null && $leaseExpires > time()) {
                $pdo->commit();
                return ['result' => 'lease_active', 'campaign_id' => $campaignId, 'item_id' => $itemId, 'reached_remote' => null];
            }

            $attemptStmt = $pdo->prepare(
                'SELECT id,state,reached_remote
                 FROM system_execution_attempts
                 WHERE manual_campaign_id=? AND manual_campaign_item_id=? AND lease_generation=?
                 ORDER BY id DESC LIMIT 1 FOR UPDATE'
            );
            $attemptStmt->execute([$campaignId, $itemId, (int) $item['lease_generation']]);
            $attempt = $attemptStmt->fetch(PDO::FETCH_ASSOC);
            $attemptState = is_array($attempt) ? (string) ($attempt['state'] ?? '') : '';
            $remoteWasPossible = is_array($attempt)
                && ((int) ($attempt['reached_remote'] ?? 0) === 1
                    || in_array($attemptState, ['remote_dispatched', 'response_received', 'result_applied', 'uncertain'], true));
            $newStatus = $remoteWasPossible ? 'failed' : 'retry';
            $resolution = $remoteWasPossible ? 'remote_result_uncertain' : 'interrupted_before_remote';
            $message = $remoteWasPossible
                ? 'El resultado remoto anterior no pudo confirmarse. Este recurso queda aislado para revisión; la campaña continuará con los demás.'
                : 'El intento terminó antes de consultar Mercado Libre. Se reintentará automáticamente.';
            $nextAt = $remoteWasPossible ? null : gmdate('Y-m-d H:i:s.v');
            $updated = $pdo->prepare(
                'UPDATE manual_campaign_items
                 SET status=?,source_resolution=?,result_summary=?,next_eligible_at=?,
                     lease_owner=NULL,lease_expires_at=NULL,completed_at=IF(?="failed",UTC_TIMESTAMP(3),NULL),
                     attempts=IF(?="retry",GREATEST(0,attempts-1),attempts)
                 WHERE id=? AND manual_campaign_id=? AND status="running"
                   AND lease_generation=? AND (lease_expires_at IS NULL OR lease_expires_at<UTC_TIMESTAMP(3))'
            );
            $updated->execute([
                $newStatus,
                $resolution,
                $message,
                $nextAt,
                $newStatus,
                $newStatus,
                $itemId,
                $campaignId,
                (int) $item['lease_generation'],
            ]);
            if ($updated->rowCount() !== 1) {
                $pdo->rollBack();
                return ['result' => 'fence_changed', 'campaign_id' => $campaignId, 'item_id' => $itemId, 'reached_remote' => $remoteWasPossible];
            }

            if (is_array($attempt)) {
                $pdo->prepare(
                    'UPDATE system_execution_attempts
                     SET state=?,safe_message=?,completed_at=UTC_TIMESTAMP(3)
                     WHERE id=? AND lease_generation=? AND state NOT IN ("approved","failed","uncertain")'
                )->execute([
                    $remoteWasPossible ? 'uncertain' : 'failed',
                    $message,
                    (int) $attempt['id'],
                    (int) $item['lease_generation'],
                ]);
            }
            $pdo->prepare(
                'UPDATE manual_campaigns
                 SET current_item_id=NULL,current_operation_id=NULL,
                     last_engine_state=IF(status="active",?,last_engine_state),
                     safe_message=IF(status="active",?,safe_message),
                     next_action_at=IF(status="active",UTC_TIMESTAMP(3),next_action_at),
                     version_no=version_no+1
                 WHERE id=? AND (current_item_id=? OR current_item_id IS NULL)'
            )->execute([$remoteWasPossible ? 'ready_with_attention' : 'ready', $message, $campaignId, $itemId]);
            $this->recalculateCampaignCounters($pdo, $campaignId);
            $this->event(
                $pdo,
                $campaignId,
                $remoteWasPossible ? 'item_remote_uncertain' : 'item_interrupted_retry',
                $remoteWasPossible ? 'error' : 'warning',
                $message,
                $itemId,
                (int) ($item['block_no'] ?? 0)
            );
            $pdo->commit();
            return [
                'result' => $remoteWasPossible ? 'action_required' : 'retry',
                'campaign_id' => $campaignId,
                'item_id' => $itemId,
                'reached_remote' => $remoteWasPossible,
            ];
        } catch (Throwable $error) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $error;
        }
    }

    /**
     * Compensa inmediatamente una excepción del worker vigente. La escritura
     * exige el mismo propietario y generación; un worker tardío no puede
     * liberar ni degradar el lease que ya haya sido asumido por otro proceso.
     *
     * @param array<string,mixed> $item
     * @return array{result:string,campaign_id:int,item_id:int,reached_remote:?bool}
     */
    public function compensateInterruptedClaim(array $item, int $attemptId): array
    {
        $campaignId = (int) ($item['manual_campaign_id'] ?? 0);
        $itemId = (int) ($item['id'] ?? 0);
        $owner = (string) ($item['lease_owner'] ?? '');
        $generation = (int) ($item['lease_generation'] ?? 0);
        if ($campaignId < 1 || $itemId < 1 || $owner === '' || $generation < 1 || $attemptId < 1) {
            return ['result' => 'invalid', 'campaign_id' => $campaignId, 'item_id' => $itemId, 'reached_remote' => null];
        }
        $pdo = Database::connectionFresh();
        $pdo->beginTransaction();
        try {
            $owned = $pdo->prepare(
                'SELECT id,block_no FROM manual_campaign_items
                 WHERE id=? AND manual_campaign_id=? AND status="running"
                   AND lease_owner=? AND lease_generation=? FOR UPDATE'
            );
            $owned->execute([$itemId, $campaignId, $owner, $generation]);
            $lockedItem = $owned->fetch(PDO::FETCH_ASSOC);
            if (!is_array($lockedItem)) {
                $pdo->commit();
                return ['result' => 'fence_changed', 'campaign_id' => $campaignId, 'item_id' => $itemId, 'reached_remote' => null];
            }
            $attemptStmt = $pdo->prepare(
                'SELECT id,state,reached_remote FROM system_execution_attempts
                 WHERE id=? AND manual_campaign_id=? AND manual_campaign_item_id=? AND lease_generation=? FOR UPDATE'
            );
            $attemptStmt->execute([$attemptId, $campaignId, $itemId, $generation]);
            $attempt = $attemptStmt->fetch(PDO::FETCH_ASSOC);
            if (!is_array($attempt)) {
                $pdo->rollBack();
                return ['result' => 'attempt_missing', 'campaign_id' => $campaignId, 'item_id' => $itemId, 'reached_remote' => null];
            }
            $state = (string) ($attempt['state'] ?? '');
            $remoteWasPossible = (int) ($attempt['reached_remote'] ?? 0) === 1
                || in_array($state, ['remote_dispatched', 'response_received', 'result_applied', 'uncertain'], true);
            $newStatus = $remoteWasPossible ? 'failed' : 'retry';
            $message = $remoteWasPossible
                ? 'El resultado remoto no pudo confirmarse. El recurso queda aislado para revisión y la campaña continuará.'
                : 'La ejecución se interrumpió antes de consultar Mercado Libre. Se reintentará automáticamente.';
            $pdo->prepare(
                'UPDATE manual_campaign_items
                 SET status=?,source_resolution=?,result_summary=?,
                     next_eligible_at=IF(?="retry",UTC_TIMESTAMP(3),NULL),
                     completed_at=IF(?="failed",UTC_TIMESTAMP(3),NULL),
                     lease_owner=NULL,lease_expires_at=NULL,
                     attempts=IF(?="retry",GREATEST(0,attempts-1),attempts)
                 WHERE id=? AND manual_campaign_id=? AND status="running"
                   AND lease_owner=? AND lease_generation=?'
            )->execute([
                $newStatus,
                $remoteWasPossible ? 'remote_result_uncertain' : 'interrupted_before_remote',
                $message,
                $newStatus,
                $newStatus,
                $newStatus,
                $itemId,
                $campaignId,
                $owner,
                $generation,
            ]);
            $pdo->prepare(
                'UPDATE system_execution_attempts
                 SET state=?,safe_message=?,completed_at=UTC_TIMESTAMP(3)
                 WHERE id=? AND lease_generation=? AND state NOT IN ("approved","failed","uncertain")'
            )->execute([$remoteWasPossible ? 'uncertain' : 'failed', $message, $attemptId, $generation]);
            $pdo->prepare(
                'UPDATE manual_campaigns
                 SET current_item_id=NULL,current_operation_id=NULL,
                     next_action_at=IF(status="active",UTC_TIMESTAMP(3),next_action_at),
                     last_engine_state=IF(status="active",?,last_engine_state),
                     safe_message=IF(status="active",?,safe_message),version_no=version_no+1
                 WHERE id=? AND current_item_id=?'
            )->execute([$remoteWasPossible ? 'ready_with_attention' : 'ready', $message, $campaignId, $itemId]);
            $this->recalculateCampaignCounters($pdo, $campaignId);
            $this->event(
                $pdo,
                $campaignId,
                $remoteWasPossible ? 'item_remote_uncertain' : 'item_interrupted_retry',
                $remoteWasPossible ? 'error' : 'warning',
                $message,
                $itemId,
                (int) ($lockedItem['block_no'] ?? 0)
            );
            $pdo->commit();
            return [
                'result' => $remoteWasPossible ? 'action_required' : 'retry',
                'campaign_id' => $campaignId,
                'item_id' => $itemId,
                'reached_remote' => $remoteWasPossible,
            ];
        } catch (Throwable $error) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $error;
        }
    }

    /**
     * Aísla respuestas remotas inciertas recuperadas por el diario. Una serie
     * de recursos inciertos no debe pausar globalmente una campaña: cada uno
     * queda visible para revisión y los recursos independientes continúan.
     */
    public function isolateUncertainJournalResults(int $campaignId): int
    {
        if ($campaignId < 1) {
            return 0;
        }
        $pdo = Database::connectionFresh();
        $pdo->beginTransaction();
        try {
            $campaign = $pdo->prepare(
                'SELECT id,status,last_engine_state FROM manual_campaigns WHERE id=? FOR UPDATE'
            );
            $campaign->execute([$campaignId]);
            $state = $campaign->fetch(PDO::FETCH_ASSOC);
            if (!is_array($state)) {
                $pdo->commit();
                return 0;
            }
            $items = $pdo->prepare(
                'UPDATE manual_campaign_items i
                 JOIN system_execution_attempts a
                   ON a.manual_campaign_item_id=i.id
                  AND a.manual_campaign_id=i.manual_campaign_id
                  AND a.lease_generation=i.lease_generation
                 SET i.status="failed",i.source_resolution="remote_result_uncertain",
                     i.result_summary="El resultado remoto anterior no pudo confirmarse. Este recurso queda aislado para revisión.",
                     i.failed_units=GREATEST(0,i.total_units-i.completed_units-i.skipped_units),
                     i.next_eligible_at=NULL,i.lease_owner=NULL,i.lease_expires_at=NULL,
                     i.completed_at=COALESCE(i.completed_at,UTC_TIMESTAMP(3))
                 WHERE i.manual_campaign_id=? AND i.status IN ("running","waiting","retry")
                   AND a.state="uncertain"'
            );
            $items->execute([$campaignId]);
            $isolated = $items->rowCount();
            $automaticPause = (string) ($state['status'] ?? '') === 'paused'
                && (string) ($state['last_engine_state'] ?? '') === 'paused_interruption';
            if ($isolated > 0 || $automaticPause) {
                $pdo->prepare(
                    'UPDATE manual_campaigns
                     SET status=IF(status="paused" AND last_engine_state="paused_interruption","active",status),
                         current_item_id=NULL,current_operation_id=NULL,
                         next_action_at=IF(status="active" OR last_engine_state="paused_interruption",UTC_TIMESTAMP(3),next_action_at),
                         last_engine_state=IF(status="active" OR last_engine_state="paused_interruption","ready_with_attention",last_engine_state),
                         safe_message=IF(
                           status="active" OR last_engine_state="paused_interruption",
                           "Los resultados inciertos quedaron aislados. La campaña continuará con los demás recursos.",
                           safe_message
                         ),version_no=version_no+1
                     WHERE id=?'
                )->execute([$campaignId]);
                $this->recalculateCampaignCounters($pdo, $campaignId);
                if ($isolated > 0) {
                    $this->event(
                        $pdo,
                        $campaignId,
                        'uncertain_items_isolated',
                        'warning',
                        $isolated . ' resultado(s) incierto(s) quedaron aislados; la campaña continúa.'
                    );
                }
            }
            $pdo->commit();
            return $isolated;
        } catch (Throwable $error) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $error;
        }
    }

    /**
     * Sincroniza un ítem de campaña después de resolver su trabajo fuente.
     * El caller ya debe haber autorizado empresa/cuenta; este método vuelve a
     * comprobar ambos alcances y aplica compare-and-swap sobre el estado.
     *
     * @return array{result:string,campaign_id:int,item_id:int,status:string}
     */
    public function reconcileItemAfterResolution(
        int $campaignId,
        int $itemId,
        int $companyId,
        int $accountId,
        string $action,
        string $expectedStatus,
        int $expectedGeneration,
        string $safeMessage,
        ?string $diagnosticId = null
    ): array {
        $allowed = ['retry', 'skip_campaign', 'return_to_queue', 'close_expected'];
        if (!in_array($action, $allowed, true)) {
            throw new RuntimeException('La acción no está habilitada para una campaña.');
        }
        $pdo = Database::connectionFresh();
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare(
                'SELECT i.*,c.status campaign_status
                 FROM manual_campaign_items i
                 JOIN manual_campaigns c ON c.id=i.manual_campaign_id
                 WHERE i.id=? AND i.manual_campaign_id=? AND i.company_id=? AND i.meli_account_id=?
                   AND i.lease_generation=?
                 FOR UPDATE'
            );
            $stmt->execute([$itemId, $campaignId, $companyId, $accountId, $expectedGeneration]);
            $item = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!is_array($item)) {
                throw new RuntimeException('El recurso de campaña no existe dentro del alcance autorizado.');
            }
            if ((string) $item['status'] !== $expectedStatus) {
                return [
                    'result' => 'stale',
                    'campaign_id' => $campaignId,
                    'item_id' => $itemId,
                    'status' => (string) $item['status'],
                ];
            }
            $leaseExpires = (new SystemDatabaseUtcClock())->timestamp((string) ($item['lease_expires_at'] ?? ''));
            if ((string) $item['status'] === 'running' || ($leaseExpires !== null && $leaseExpires > time())) {
                return ['result' => 'lease_active', 'campaign_id' => $campaignId, 'item_id' => $itemId, 'status' => (string) $item['status']];
            }

            $newStatus = match ($action) {
                'retry' => 'retry',
                'return_to_queue' => 'returned',
                default => 'skipped',
            };
            $sourceResolution = match ($action) {
                'retry' => 'remediation_retry',
                'return_to_queue' => 'returned_to_queue',
                'close_expected' => 'skipped_expected',
                default => 'skipped_campaign',
            };
            $remaining = max(0, (int) $item['total_units'] - (int) $item['completed_units']);
            $skippedUnits = $newStatus === 'skipped' ? $remaining : 0;
            $updated = $pdo->prepare(
                'UPDATE manual_campaign_items
                 SET status=?,source_resolution=?,result_summary=?,diagnostic_id=?,
                     failed_units=0,skipped_units=?,next_eligible_at=IF(?="retry",UTC_TIMESTAMP(3),NULL),
                     completed_at=IF(? IN ("skipped","returned"),UTC_TIMESTAMP(3),NULL),
                     lease_owner=NULL,lease_expires_at=NULL
                 WHERE id=? AND manual_campaign_id=? AND company_id=? AND meli_account_id=?
                   AND status=? AND lease_generation=?'
            );
            $updated->execute([
                $newStatus,
                $sourceResolution,
                mb_substr(Logger::redactString($safeMessage), 0, 500),
                $diagnosticId,
                $skippedUnits,
                $newStatus,
                $newStatus,
                $itemId,
                $campaignId,
                $companyId,
                $accountId,
                $expectedStatus,
                $expectedGeneration,
            ]);
            if ($updated->rowCount() !== 1) {
                $pdo->rollBack();
                return ['result' => 'stale', 'campaign_id' => $campaignId, 'item_id' => $itemId, 'status' => (string) $item['status']];
            }
            if (in_array($newStatus, ['skipped', 'returned'], true)) {
                $pdo->prepare(
                    'UPDATE manual_campaign_reservations
                     SET status="released",released_at=UTC_TIMESTAMP(3)
                     WHERE manual_campaign_item_id=? AND status="active"'
                )->execute([$itemId]);
            }
            $pdo->prepare(
                'UPDATE manual_campaigns
                 SET current_operation_id=IF(current_item_id=?,NULL,current_operation_id),
                     current_item_id=IF(current_item_id=?,NULL,current_item_id),
                     next_action_at=IF(status="active",UTC_TIMESTAMP(3),next_action_at),
                     last_engine_state=IF(status="active","ready",last_engine_state),
                     safe_message=IF(status="active","La campaña continuará con los recursos disponibles.",safe_message),
                     version_no=version_no+1
                 WHERE id=?'
            )->execute([$itemId, $itemId, $campaignId]);
            $this->recalculateCampaignCounters($pdo, $campaignId);
            $this->event(
                $pdo,
                $campaignId,
                'item_remediated_' . $action,
                $newStatus === 'retry' ? 'info' : 'warning',
                mb_substr(Logger::redactString($safeMessage), 0, 500),
                $itemId,
                (int) ($item['block_no'] ?? 0)
            );
            $this->finalizeIfDone($pdo, $campaignId);
            $pdo->commit();
            return ['result' => 'updated', 'campaign_id' => $campaignId, 'item_id' => $itemId, 'status' => $newStatus];
        } catch (Throwable $error) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $error;
        }
    }

    private function recalculateCampaignCounters(PDO $pdo, int $campaignId): void
    {
        $pdo->prepare(
            'UPDATE manual_campaigns c
             JOIN (
                SELECT manual_campaign_id,
                       SUM(status="completed") completed_items_real,
                       SUM(status="skipped") skipped_items_real,
                       SUM(status="failed") failed_items_real,
                       SUM(status="returned") returned_items_real,
                       SUM(status IN ("waiting","retry")) retry_items_real,
                       COALESCE(SUM(CASE WHEN status="completed" THEN completed_units ELSE 0 END),0) completed_units_real,
                       COALESCE(SUM(CASE WHEN status="failed" THEN failed_units ELSE 0 END),0) failed_units_real,
                       COALESCE(SUM(CASE WHEN status="skipped" THEN skipped_units ELSE 0 END),0) skipped_units_real,
                       COALESCE(SUM(primary_calls),0) primary_calls_real,
                       COALESCE(SUM(derived_calls),0) derived_calls_real,
                       COALESCE(SUM(avoided_calls),0) avoided_calls_real
                FROM manual_campaign_items WHERE manual_campaign_id=?
                GROUP BY manual_campaign_id
             ) s ON s.manual_campaign_id=c.id
             SET c.completed_items=s.completed_items_real,c.skipped_items=s.skipped_items_real,
                 c.failed_items=s.failed_items_real,c.returned_items=s.returned_items_real,
                 c.retry_items=s.retry_items_real,c.completed_units=s.completed_units_real,
                 c.failed_units=s.failed_units_real,c.skipped_units=s.skipped_units_real,
                 c.primary_calls=s.primary_calls_real,c.derived_calls=s.derived_calls_real,
                 c.outbound_calls=s.primary_calls_real+s.derived_calls_real,
                 c.avoided_calls=s.avoided_calls_real
             WHERE c.id=?'
        )->execute([$campaignId, $campaignId]);
    }

    public function pause(int $campaignId, int $userId): bool
    {
        $stmt = Database::connectionFresh()->prepare(
            'UPDATE manual_campaigns
             SET status=IF(current_item_id IS NULL,"paused","pausing"),
                 safe_message=IF(
                    current_item_id IS NULL,
                    "Campaña pausada. El progreso permanece guardado.",
                    "La campaña se pausará después del recurso actual."
                 ),
                 paused_at=IF(current_item_id IS NULL,UTC_TIMESTAMP(),paused_at),
                 last_engine_state=IF(current_item_id IS NULL,"paused","pausing"),
                 version_no=version_no+1
             WHERE id=? AND created_by_user_id=? AND status="active"'
        );
        $stmt->execute([$campaignId, $userId]);
        return $stmt->rowCount() === 1;
    }

    public function resume(int $campaignId, int $userId): bool
    {
        return $this->change($campaignId, $userId, 'active', 'La campaña continuará desde el siguiente recurso.');
    }

    public function finish(int $campaignId, int $userId): bool
    {
        return $this->finishInternal($campaignId, $userId, 'Se devolverán los recursos no iniciados.');
    }

    public function finishByLimit(int $campaignId, string $reason = 'max_duration'): bool
    {
        $finished = $this->finishInternal(
            $campaignId,
            null,
            (new ManualCampaignRhythmService())->limitMessage($reason)
        );
        if ($finished) {
            Database::connectionFresh()->prepare(
                'UPDATE manual_campaigns SET limit_reason=?,finished_reason="limit_reached" WHERE id=?'
            )->execute([$reason, $campaignId]);
        }
        return $finished;
    }

    private function finishInternal(int $campaignId, ?int $userId, string $message): bool
    {
        $pdo = Database::connectionFresh();
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare(
                'UPDATE manual_campaigns SET status="finishing",safe_message=?
                 WHERE id=?' . ($userId !== null ? ' AND created_by_user_id=?' : '') . '
                   AND status IN ("active","pausing","paused")'
            );
            $params = [$message, $campaignId];
            if ($userId !== null) {
                $params[] = $userId;
            }
            $stmt->execute($params);
            if ($stmt->rowCount() !== 1) {
                $pdo->rollBack();
                return false;
            }
            $returnedUnitsStmt = $pdo->prepare(
                'SELECT COALESCE(SUM(GREATEST(0,total_units-completed_units-failed_units-skipped_units)),0)
                 FROM manual_campaign_items
                 WHERE manual_campaign_id=? AND status IN ("pending","waiting","retry")'
            );
            $returnedUnitsStmt->execute([$campaignId]);
            $returnedUnits = (int) $returnedUnitsStmt->fetchColumn();
            $returned = $pdo->prepare(
                'UPDATE manual_campaign_items SET status="returned",lease_owner=NULL,lease_expires_at=NULL
                 WHERE manual_campaign_id=? AND status IN ("pending","waiting","retry")'
            );
            $returned->execute([$campaignId]);
            $pdo->prepare(
                'UPDATE manual_campaigns
                 SET returned_items=returned_items+?,returned_units=returned_units+?,
                     last_engine_state="returning",version_no=version_no+1
                 WHERE id=?'
            )->execute([$returned->rowCount(), $returnedUnits, $campaignId]);
            $pdo->prepare(
                'UPDATE manual_campaign_reservations
                 SET status="released",released_at=UTC_TIMESTAMP(3)
                 WHERE manual_campaign_id=? AND status="active"'
            )->execute([$campaignId]);
            $this->finalizeIfDone($pdo, $campaignId);
            $pdo->commit();
            return true;
        } catch (Throwable $error) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $error;
        }
    }

    private function change(int $campaignId, int $userId, string $status, string $message): bool
    {
        $stmt = Database::connectionFresh()->prepare(
            'UPDATE manual_campaigns SET status=?,safe_message=?,paused_at=IF(?="pausing",UTC_TIMESTAMP(),NULL),
                    next_action_at=IF(?="active",UTC_TIMESTAMP(3),next_action_at),
                    last_engine_state=IF(?="active","waiting_launcher",?),
                    version_no=version_no+1
             WHERE id=? AND created_by_user_id=? AND status IN ("active","pausing","paused")'
        );
        $stmt->execute([$status, $message, $status, $status, $status, $status, $campaignId, $userId]);
        return $stmt->rowCount() === 1;
    }

    private function finalizeIfDone(PDO $pdo, int $campaignId): void
    {
        $remaining = $pdo->prepare(
            'SELECT COUNT(*) FROM manual_campaign_items
             WHERE manual_campaign_id=? AND status IN ("pending","running","waiting","retry")'
        );
        $remaining->execute([$campaignId]);
        if ((int) $remaining->fetchColumn() > 0) {
            $pdo->prepare(
                'UPDATE manual_campaigns SET status="paused",paused_at=UTC_TIMESTAMP(),version_no=version_no+1
                 WHERE id=? AND status="pausing" AND current_item_id IS NULL'
            )->execute([$campaignId]);
            return;
        }
        $stmt = $pdo->prepare('SELECT status,failed_items FROM manual_campaigns WHERE id=? FOR UPDATE');
        $stmt->execute([$campaignId]);
        $campaign = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($campaign) || in_array((string) $campaign['status'], ['completed', 'completed_with_issues', 'failed'], true)) {
            return;
        }
        $final = (string) $campaign['status'] === 'finishing'
            ? 'completed_with_issues'
            : ((int) $campaign['failed_items'] > 0 ? 'completed_with_issues' : 'completed');
        $message = $final === 'completed'
            ? 'Campaña completada y verificada con los resultados guardados.'
            : 'La campaña terminó con diferencias o recursos devueltos.';
        $pdo->prepare(
            'UPDATE manual_campaigns
             SET status=?,completed_at=UTC_TIMESTAMP(),next_action_at=NULL,current_item_id=NULL,
                 last_engine_state=?,safe_message=?,last_result_message=?,version_no=version_no+1 WHERE id=?'
        )->execute([$final, $final, $message, $message, $campaignId]);
        $this->event($pdo, $campaignId, 'campaign_completed', $final === 'completed' ? 'success' : 'warning', $message);
    }

    private function waitingReasonLabel(string $reason): string
    {
        return match ($reason) {
            'future', 'item_future', 'waiting_schedule' => 'Esperando fecha segura del recurso.',
            'deadline_too_short', 'time_budget' => 'Cron cerró antes de iniciar otra consulta para evitar cortes.',
            'waiting_or_complete', 'no_due_work' => 'No había otro recurso listo en ese ciclo.',
            'locked', 'lease_active' => 'Otro intento conserva una reserva vigente.',
            'action_required' => 'Un recurso necesita revisión administrativa.',
            'ready', 'directed_lane_guaranteed' => 'La campaña tiene turno dirigido.',
            '' => 'Aún no hay una causa registrada por Cron.',
            default => 'Estado registrado por Cron: ' . $reason,
        };
    }

    /** @param array<string,mixed> $row */
    private function itemStatusLabel(array $row): string
    {
        $status = (string) ($row['status'] ?? '');
        if ($status === 'running') {
            $leaseActive = !empty($row['lease_expires_at'])
                && !(new SystemDatabaseUtcClock())->isDue((string) $row['lease_expires_at']);
            $heartbeat = (new SystemDatabaseUtcClock())->timestamp((string) ($row['worker_heartbeat_at'] ?? ''));
            $heartbeatActive = $heartbeat !== null && $heartbeat >= time() - 180;
            return $leaseActive && $heartbeatActive
                ? 'En curso'
                : 'Intento vencido';
        }
        return match ($status) {
            'pending' => 'Por hacer',
            'waiting' => 'Esperando',
            'retry' => 'Se reintentará',
            'completed' => 'Completado',
            'skipped' => 'Omitido',
            'failed' => 'Necesita atención',
            'returned' => 'Devuelto a Automatización',
            default => $status,
        };
    }

    /** @param array<string,mixed> $row */
    private function itemWaitingReasonLabel(array $row): string
    {
        $status = (string) ($row['status'] ?? '');
        $source = (string) ($row['source_resolution'] ?? '');
        if ($status === 'running') {
            $leaseActive = !empty($row['lease_expires_at'])
                && !(new SystemDatabaseUtcClock())->isDue((string) $row['lease_expires_at']);
            $heartbeat = (new SystemDatabaseUtcClock())->timestamp((string) ($row['worker_heartbeat_at'] ?? ''));
            $heartbeatActive = $heartbeat !== null && $heartbeat >= time() - 180;
            return $leaseActive && $heartbeatActive
                ? 'Reservado por el lanzador.'
                : 'El intento venció; el próximo cron lo recuperará.';
        }
        if (!empty($row['next_eligible_at']) && !(new SystemDatabaseUtcClock())->isDue((string) $row['next_eligible_at'])) {
            return 'Disponible desde ' . (new SystemDatabaseUtcClock())->toBogota((string) $row['next_eligible_at']) . ' hora Bogotá.';
        }
        return match ($source) {
            'future' => 'La cola original programó una fecha futura.',
            'paused' => 'La cola original está pausada.',
            'action_required' => 'Requiere intervención antes de reintentar.',
            'locked' => 'Otro proceso tiene una reserva vigente.',
            'completed_elsewhere' => 'Automatización completó el recurso por otro camino.',
            'ready' => 'Listo para el próximo ciclo de Cron.',
            default => (string) ($row['result_summary'] ?? '') ?: 'Pendiente.',
        };
    }

    private function shouldRestoreCampaignAttempt(CampaignItemResult $result, string $itemStatus): bool
    {
        if (!in_array($itemStatus, ['waiting', 'retry'], true)) {
            return false;
        }
        return in_array(strtolower(trim((string) $result->reason)), [
            'waiting_deadline', 'deadline', 'deadline_too_short', 'time_budget', 'lane_deadline',
            'waiting_rhythm', 'api_rhythm', 'api_rhythm_deferred', 'policy_delay',
            'waiting_budget', 'api_budget', 'api_budget_exhausted',
            'waiting_api', 'api_paused', 'api_manual_pause', 'manual_pause',
            'http_429', 'rate_limited', 'rate_limit_429',
        ], true);
    }

    private function event(
        PDO $pdo,
        int $campaignId,
        string $type,
        string $severity,
        string $message,
        ?int $itemId = null,
        ?int $blockNo = null
    ): void {
        $operationKey = null;
        $accountId = null;
        if ($itemId !== null) {
            $itemStmt = $pdo->prepare(
                'SELECT operation_key,meli_account_id
                 FROM manual_campaign_items
                 WHERE id=? AND manual_campaign_id=?
                 LIMIT 1'
            );
            $itemStmt->execute([$itemId, $campaignId]);
            $item = $itemStmt->fetch(PDO::FETCH_ASSOC);
            if (is_array($item)) {
                $operationKey = mb_substr((string) ($item['operation_key'] ?? ''), 0, 80);
                $accountId = (int) ($item['meli_account_id'] ?? 0);
            }
        }
        $pdo->prepare(
            'INSERT INTO manual_campaign_events
             (manual_campaign_id,event_type,severity,safe_message,manual_campaign_item_id,block_no,operation_key,meli_account_id)
             VALUES (?,?,?,?,?,?,?,?)'
        )->execute([
            $campaignId,
            mb_substr($type, 0, 50),
            $severity,
            mb_substr(Logger::redactString($message), 0, 500),
            $itemId,
            $blockNo,
            $operationKey !== '' ? $operationKey : null,
            $accountId > 0 ? $accountId : null,
        ]);
        $id = (int) $pdo->lastInsertId();
        $pdo->prepare('UPDATE manual_campaigns SET last_event_id=? WHERE id=?')->execute([$id, $campaignId]);
    }

    private function hasRecentCampaignEvent(
        PDO $pdo,
        int $campaignId,
        string $type,
        string $operationKey,
        int $accountId,
        string $message,
        int $seconds
    ): bool {
        try {
            $stmt = $pdo->prepare(
                'SELECT 1 FROM manual_campaign_events
                 WHERE manual_campaign_id=?
                   AND event_type=?
                   AND COALESCE(operation_key,"")=?
                   AND COALESCE(meli_account_id,0)=?
                   AND safe_message=?
                   AND created_at>=DATE_SUB(UTC_TIMESTAMP(),INTERVAL ' . max(60, min(86400, $seconds)) . ' SECOND)
                 LIMIT 1'
            );
            $stmt->execute([
                $campaignId,
                mb_substr($type, 0, 50),
                mb_substr($operationKey, 0, 80),
                max(0, $accountId),
                mb_substr(Logger::redactString($message), 0, 500),
            ]);
            return $stmt->fetchColumn() !== false;
        } catch (Throwable) {
            return false;
        }
    }

    /** @param list<array<string,mixed>> $rows @return list<array<string,mixed>> */
    private function expandDescriptionCandidates(array $rows): array
    {
        $expanded = [];
        $pdo = Database::connectionFresh();
        foreach ($rows as $row) {
            if ((string) ($row['queue_key'] ?? '') !== 'catalog_descriptions'
                || !ctype_digit((string) ($row['source_id'] ?? ''))) {
                $expanded[] = $row;
                continue;
            }
            $jobId = (int) $row['source_id'];
            $accountId = max(0, (int) ($row['meli_account_id'] ?? 0));
            $whereAccount = $accountId > 0 ? ' AND i.meli_account_id=?' : '';
            $stmt = $pdo->prepare(
                'SELECT i.id,i.meli_account_id,i.external_item_id
                 FROM catalog_description_job_items i
                 WHERE i.catalog_description_job_id=? AND i.status="pending"
                   AND (i.next_retry_at IS NULL OR i.next_retry_at<=UTC_TIMESTAMP())'
                . $whereAccount . '
                 ORDER BY i.id ASC LIMIT 500'
            );
            $params = [$jobId];
            if ($accountId > 0) {
                $params[] = $accountId;
            }
            $stmt->execute($params);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $item) {
                $copy = $row;
                $copy['source_id'] = $jobId . ':' . (int) $item['id'];
                $copy['meli_account_id'] = (int) $item['meli_account_id'];
                $copy['human_label'] = 'Descripción de ' . (string) $item['external_item_id'];
                $copy['content_summary'] = 'Consulta individual de una descripción pendiente.';
                $copy['item_count'] = 1;
                $copy['estimated_api_calls'] = 1;
                $expanded[] = $copy;
            }
        }
        return $expanded;
    }
}
