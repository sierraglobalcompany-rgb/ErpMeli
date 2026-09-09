<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\QueueV4Clean\QueueV4CleanRepository;
use PDO;
use RuntimeException;
use Throwable;

/**
 * Conserva exactamente la selección que el administrador revisó.
 *
 * Solo persiste identificadores internos y resúmenes sanitizados. Las tablas
 * fuente continúan siendo la autoridad y se vuelven a comprobar al comenzar.
 */
final class ManualCampaignPreviewService
{
    public const PRESENTATION_LIMIT = 60;

    public static function assertScope(string $scope): string
    {
        if ($scope === 'available_queue') {
            return $scope;
        }
        return (new ManualProcessingService())->assertScope($scope);
    }

    /** @return array<string,list<string>> */
    public static function schemaRequirements(string $scope): array
    {
        $scope = self::assertScope($scope);
        $common = [
            'manual_campaign_previews' => ['preview_token','configuration_json','summary_json','status','expires_at'],
            'manual_campaign_preview_items' => ['manual_campaign_preview_id','queue_key','source_id','item_payload_json','position_no'],
            'meli_accounts' => ['id','company_id'],
            'user_company_access' => ['user_id','company_id'],
        ];
        if ($scope === 'available_queue') {
            return $common + [
                'queue_v4_clean_jobs' => ['id','company_id','meli_account_id','job_type','resource_id','idempotency_key','payload_json','state','available_at'],
                'queue_v4_clean_runs' => ['id','launcher','status'],
                'queue_v4_clean_attempts' => ['job_id','run_id','company_id','meli_account_id'],
                'queue_v4_clean_control' => ['control_key','engine_state','readiness_state'],
                'queue_core_execution_leases' => ['lease_key','launcher','owner_token','generation','expires_at'],
            ];
        }

        $requirements = $common + [
            'system_work_queue_projection' => ['queue_key','source_id','company_id','meli_account_id'],
            'queue_core_jobs' => ['id','company_id','meli_account_id','work_type','resource_id','state'],
            'queue_core_attempts' => ['job_id','company_id','meli_account_id','physical_http_calls'],
            'queue_core_execution_leases' => ['lease_key','launcher','owner_token','generation','expires_at'],
        ];
        $sourceTables = [
            'recommended' => ['meli_notification_work_items','sync_batch_chunks','order_resource_enrichment_jobs','sale_pack_reconciliation_jobs','order_financial_recalc_jobs','sale_financial_reconciliation_jobs','sync_sales_repair_jobs'],
            'all' => ['meli_notification_work_items','sync_batch_chunks','order_resource_enrichment_jobs','sale_pack_reconciliation_jobs','order_financial_recalc_jobs','sale_financial_reconciliation_jobs','sync_sales_repair_jobs','sync_sales_audit_jobs','meli_item_sync_jobs','catalog_description_job_items','catalog_description_jobs'],
            'sales' => ['meli_notification_work_items','sync_batch_chunks','order_resource_enrichment_jobs','sale_pack_reconciliation_jobs'],
            'finance' => ['order_financial_recalc_jobs','sale_financial_reconciliation_jobs'],
            'audits' => ['sync_sales_audit_jobs','sync_sales_repair_jobs'],
            'products' => ['meli_item_sync_jobs'],
            'descriptions' => ['catalog_description_job_items','catalog_description_jobs'],
            'local' => ['order_financial_recalc_jobs'],
        ];
        foreach ($sourceTables[$scope] ?? [] as $table) {
            $requirements[$table] = [];
        }
        return $requirements;
    }

    /** @param array<string,mixed> $configuration @return array<string,mixed> */
    public function create(int $userId, array $configuration): array
    {
        $configuration = $this->normalize($configuration);
        $hash = hash('sha256', json_encode($configuration, JSON_UNESCAPED_SLASHES));
        $pdo = Database::connectionFresh();
        if ($configuration['scope'] === 'available_queue') {
            return $this->createAvailableQueuePreview($pdo, $userId, $configuration, $hash);
        }
        $cacheSeconds = max(5, min(120, (new AppSettingsService())->int('manual_campaign.preview_cache_seconds', 20)));
        $existing = $pdo->prepare(
            'SELECT preview_token FROM manual_campaign_previews
             WHERE created_by_user_id=? AND configuration_hash=? AND status="ready"
               AND expires_at>UTC_TIMESTAMP(3)
               AND created_at>=DATE_SUB(UTC_TIMESTAMP(3),INTERVAL ' . $cacheSeconds . ' SECOND)
             ORDER BY id DESC LIMIT 1'
        );
        $existing->execute([$userId, $hash]);
        $token = (string) ($existing->fetchColumn() ?: '');
        if ($token !== '') {
            return $this->load($token, $userId);
        }

        $preview = $this->currentPreview(
            (string) $configuration['scope'],
            (int) $configuration['account_id'] ?: null,
            $userId,
        );
        $eligibleCount = count((array) ($preview['rows'] ?? []));
        $preview['rows'] = array_slice((array) ($preview['rows'] ?? []), 0, self::PRESENTATION_LIMIT);
        $preview['eligible_jobs'] = $eligibleCount;
        $preview['has_more'] = $eligibleCount > self::PRESENTATION_LIMIT || !empty($preview['truncated']);
        $preview['truncated'] = $preview['has_more'];
        $ttl = max(60, min(3600, (new AppSettingsService())->int('manual_campaign.preview_ttl_seconds', 600)));
        $token = bin2hex(random_bytes(20));
        $summary = $preview;
        unset($summary['rows']);

        $pdo->beginTransaction();
        try {
            $pdo->prepare(
                'INSERT INTO manual_campaign_previews
                 (preview_token,created_by_user_id,scope_key,meli_account_id,configuration_hash,
                  configuration_json,summary_json,expires_at)
                 VALUES (?,?,?,?,?,?,?,DATE_ADD(UTC_TIMESTAMP(3),INTERVAL ' . $ttl . ' SECOND))'
            )->execute([
                $token,
                $userId,
                $configuration['scope'],
                $configuration['account_id'] ?: null,
                $hash,
                json_encode($configuration, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ]);
            $previewId = (int) $pdo->lastInsertId();
            $item = $pdo->prepare(
                'INSERT INTO manual_campaign_preview_items
                 (manual_campaign_preview_id,queue_key,source_id,meli_account_id,source_state,item_payload_json,position_no)
                 VALUES (?,?,?,?,?,?,?)'
            );
            $position = 0;
            foreach ((array) ($preview['rows'] ?? []) as $row) {
                $position++;
                $item->execute([
                    $previewId,
                    (string) ($row['queue_key'] ?? ''),
                    (string) ($row['source_id'] ?? ''),
                    !empty($row['meli_account_id']) ? (int) $row['meli_account_id'] : null,
                    (string) ($row['source_state'] ?? 'ready'),
                    json_encode($this->safeRow($row), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    $position,
                ]);
            }
            $pdo->commit();
        } catch (Throwable $error) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $error;
        }
        return $this->load($token, $userId);
    }

    /** @return array<string,mixed> */
    private function currentPreview(string $scope, ?int $accountId, int $userId): array
    {
        $base = (new ManualProcessingService())->preview($scope, $accountId);
        $candidates = $this->expandDescriptionCandidates((array) ($base['rows'] ?? []), $userId);
        $registry = new ManualCampaignAdapterRegistry();
        $inspector = new ManualCampaignSourceInspector();
        $authorityService = new \App\QueueCore\ManualSourceAuthorityService();
        $scopeContext = new BusinessScopeContext();
        $eligible = [];
        $excluded = [];
        $estimatedCalls = 0;

        foreach ($candidates as $row) {
            $queueKey = (string) ($row['queue_key'] ?? '');
            $sourceId = (string) ($row['source_id'] ?? '');
            $rowAccountId = max(0, (int) ($row['meli_account_id'] ?? 0));
            $adapter = $registry->forQueue($queueKey);
            if ($adapter === null || !$adapter->supportsExact() || $rowAccountId < 1) {
                $excluded[] = $this->excludedRow(
                    $row,
                    'Todavía no tiene un adaptador exacto certificado.',
                    'automatic_only',
                );
                continue;
            }

            $account = $scopeContext->account($rowAccountId, 0, $userId);
            $companyId = (int) ($account['company_id'] ?? 0);
            $state = $inspector->inspect($queueKey, $sourceId, $rowAccountId, $companyId);
            if (!$state->exists || $state->terminal || !$state->eligible) {
                $excluded[] = $this->excludedRow(
                    $row,
                    $state->message,
                    $this->excludedState($state->sourceState),
                    $state->nextEligibleAt,
                );
                continue;
            }

            $authority = $authorityService->inspect(
                $queueKey,
                $sourceId,
                $rowAccountId,
                $companyId,
                $state,
            );
            if ($authority->explicitlyUnsupported) {
                $excluded[] = $this->excludedRow(
                    $row,
                    'La fuente no tiene una autoridad exacta vigente.',
                    'automatic_only',
                );
                continue;
            }

            $relatedIds = $authorityService->relatedResourceIds(
                $queueKey,
                $sourceId,
                $rowAccountId,
                $companyId,
            );
            $row['company_id'] = $companyId;
            $row['selection_id'] = 'exact:' . $queueKey . ':' . $sourceId;
            $row['source_authority_version'] = $authority->durableInputVersion;
            $row['operation_key'] = $authority->operationKey;
            $row['uses_api'] = $authority->usesApi;
            $row['remote_contract'] = $authority->remoteContract;
            $row['related_resource_ids'] = $relatedIds;
            $row['estimated_api_calls'] = $authority->usesApi
                ? max(1, $state->estimatedCalls)
                : 0;
            $row['item_count'] = max(1, $state->estimatedItems);
            $row['source_state'] = $state->sourceState;
            $row['selection_version'] = self::exactSelectionVersion($row);
            $estimatedCalls += (int) $row['estimated_api_calls'];
            $eligible[] = $row;
        }

        $excludedSummary = [];
        foreach ($excluded as $item) {
            $state = (string) ($item['state'] ?? 'automatic_only');
            if (!isset($excludedSummary[$state])) {
                $excludedSummary[$state] = [
                    'state' => $state,
                    'count' => 0,
                    'reason' => (string) ($item['reason'] ?? ''),
                ];
            }
            $excludedSummary[$state]['count']++;
        }

        return array_merge($base, [
            'rows' => $eligible,
            'eligible_jobs' => count($eligible),
            'excluded_jobs' => $excluded,
            'excluded_summary' => array_values($excludedSummary),
            'estimated_calls' => $estimatedCalls,
            'contains_descriptions' => count(array_filter(
                $eligible,
                static fn (array $row): bool => (string) ($row['queue_key'] ?? '') === 'catalog_descriptions',
            )) > 0,
        ]);
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private function excludedRow(
        array $row,
        string $reason,
        string $state,
        ?string $nextEligibleAt = null,
    ): array {
        return [
            'queue_key' => (string) ($row['queue_key'] ?? ''),
            'source_id' => (string) ($row['source_id'] ?? ''),
            'meli_account_id' => (int) ($row['meli_account_id'] ?? 0),
            'account_name' => (string) ($row['account_name'] ?? ''),
            'label' => (string) ($row['human_label'] ?? $row['queue_key'] ?? 'Trabajo'),
            'reason' => $reason,
            'state' => $state,
            'next_eligible_at' => $nextEligibleAt,
        ];
    }

    private function excludedState(string $sourceState): string
    {
        return match ($sourceState) {
            'action_required' => 'action_required',
            'future' => 'future',
            'locked', 'running' => 'running',
            'paused' => 'paused',
            'completed', 'completed_elsewhere', 'missing' => 'completed',
            default => 'automatic_only',
        };
    }

    /** @param list<array<string,mixed>> $rows @return list<array<string,mixed>> */
    private function expandDescriptionCandidates(array $rows, int $userId): array
    {
        $expanded = [];
        $pdo = Database::connectionFresh();
        $scopeContext = new BusinessScopeContext();
        foreach ($rows as $row) {
            if ((string) ($row['queue_key'] ?? '') !== 'catalog_descriptions'
                || !ctype_digit((string) ($row['source_id'] ?? ''))) {
                $expanded[] = $row;
                continue;
            }
            $jobId = (int) $row['source_id'];
            $rowAccountId = max(0, (int) ($row['meli_account_id'] ?? 0));
            if ($jobId < 1 || $rowAccountId < 1) {
                continue;
            }
            $account = $scopeContext->account($rowAccountId, 0, $userId);
            $companyId = (int) ($account['company_id'] ?? 0);
            $stmt = $pdo->prepare(
                'SELECT i.id,i.meli_account_id,i.external_item_id
                 FROM catalog_description_job_items i
                 JOIN catalog_description_jobs j ON j.id=i.catalog_description_job_id
                 JOIN meli_accounts a ON a.id=i.meli_account_id AND a.company_id=?
                 WHERE j.id=? AND i.meli_account_id=? AND i.status="pending"
                   AND (i.next_retry_at IS NULL OR i.next_retry_at<=UTC_TIMESTAMP())
                 ORDER BY i.id ASC LIMIT 500'
            );
            $stmt->execute([$companyId, $jobId, $rowAccountId]);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $item) {
                $copy = $row;
                $copy['source_id'] = $jobId . ':' . (int) $item['id'];
                $copy['company_id'] = $companyId;
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

    /** @return array<string,mixed> */
    public function load(string $token, int $userId): array
    {
        if (preg_match('/^[a-f0-9]{40}$/', $token) !== 1) {
            throw new RuntimeException('La previsualización solicitada no es válida.');
        }
        $pdo = Database::connectionFresh();
        $stmt = $pdo->prepare(
            'SELECT * FROM manual_campaign_previews
             WHERE preview_token=? AND created_by_user_id=? LIMIT 1'
        );
        $stmt->execute([$token, $userId]);
        $record = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($record)) {
            throw new RuntimeException('La previsualización ya no está disponible.');
        }
        if ((string) $record['status'] !== 'ready'
            || (new SystemDatabaseUtcClock())->isDue((string) $record['expires_at'])) {
            throw new RuntimeException('El cálculo venció. Vuelva a calcular los trabajos disponibles.');
        }
        $summary = json_decode((string) $record['summary_json'], true);
        $configuration = json_decode((string) $record['configuration_json'], true);
        if (!is_array($configuration) || (int) ($configuration['preview_format'] ?? 0) !== 4) {
            throw new RuntimeException('El cálculo usa un formato anterior. Vuelva a calcular los trabajos disponibles.');
        }
        $items = $pdo->prepare(
            'SELECT item_payload_json FROM manual_campaign_preview_items
             WHERE manual_campaign_preview_id=? ORDER BY position_no ASC'
        );
        $items->execute([(int) $record['id']]);
        $rows = [];
        foreach ($items->fetchAll(PDO::FETCH_COLUMN) as $payload) {
            $row = json_decode((string) $payload, true);
            if (is_array($row)) {
                $rows[] = $row;
            }
        }
        $expiresAt = (string) $record['expires_at'];
        $expiresAtTimestamp = strtotime($expiresAt . ' UTC');
        return array_replace(is_array($summary) ? $summary : [], [
            'rows' => $rows,
            'preview_token' => $token,
            'preview_id' => (int) $record['id'],
            'configuration' => is_array($configuration) ? $configuration : [],
            'calculated_at' => (string) $record['created_at'],
            'expires_at' => $expiresAt,
            'expires_in_seconds' => $expiresAtTimestamp === false ? 0 : max(0, $expiresAtTimestamp - time()),
        ]);
    }

    public function consume(string $token, int $userId): void
    {
        $admission = Database::connectionFresh()->prepare(
            'UPDATE manual_campaign_previews SET status="consumed",consumed_at=UTC_TIMESTAMP(3)
             WHERE preview_token=? AND created_by_user_id=? AND status="ready"
               AND expires_at>UTC_TIMESTAMP(3)'
        );
        $admission->execute([$token, $userId]);
        if ($admission->rowCount() !== 1) {
            throw new RuntimeException('El cálculo venció o ya se utilizó. Vuelva a calcular los trabajos disponibles.');
        }
    }

    /**
     * Abre el cálculo como evidencia de solo lectura aunque ya haya vencido.
     *
     * @return array<string,mixed>
     */
    public function loadForReview(string $token, int $userId): array
    {
        if (preg_match('/^[a-f0-9]{40}$/', $token) !== 1) {
            throw new RuntimeException('El cálculo solicitado no es válido.');
        }
        $stmt = Database::connectionFresh()->prepare(
            'SELECT summary_json,configuration_json,status,created_at,expires_at
             FROM manual_campaign_previews
             WHERE preview_token=? AND created_by_user_id=? LIMIT 1'
        );
        $stmt->execute([$token, $userId]);
        $record = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($record)) {
            throw new RuntimeException('El cálculo no existe o pertenece a otra sesión.');
        }
        $summary = json_decode((string) $record['summary_json'], true);
        $configuration = json_decode((string) $record['configuration_json'], true);
        return array_replace(is_array($summary) ? $summary : [], [
            'preview_token' => $token,
            'configuration' => is_array($configuration) ? $configuration : [],
            'calculated_at' => (string) $record['created_at'],
            'expires_at' => (string) $record['expires_at'],
            'review_only' => (string) $record['status'] !== 'ready'
                || (new SystemDatabaseUtcClock())->isDue((string) $record['expires_at']),
        ]);
    }

    /** @param array<string,mixed> $configuration @return array<string,mixed> */
    private function normalize(array $configuration): array
    {
        $capacity = ManualPhysicalCallBudget::previewConfiguration(
            $configuration,
            (new CapacityPolicyService())->snapshot('manual')
        );
        $scope = preg_replace('/[^a-z_]/', '', (string) ($configuration['scope'] ?? 'recommended')) ?: 'recommended';
        return $capacity + [
            'preview_format' => 4,
            'scope' => self::assertScope($scope),
            'account_id' => max(0, (int) ($configuration['account_id'] ?? 0)),
        ];
    }

    /**
     * @param array<string,mixed> $configuration
     * @return array<string,mixed>
     */
    private function createAvailableQueuePreview(PDO $pdo, int $userId, array $configuration, string $hash): array
    {
        $scope = new BusinessScopeContext();
        $accountId = (int) $configuration['account_id'];
        $allowedAccountIds = $accountId > 0
            ? [(int) $scope->account($accountId, 0, $userId)['id']]
            : $scope->accountIds($userId);

        $ttl = max(60, min(3600, (new AppSettingsService())->int('manual_campaign.preview_ttl_seconds', 600)));
        $repository = new QueueV4CleanRepository($pdo);
        $projection = $repository->previewManualEligible(
            self::PRESENTATION_LIMIT,
            $allowedAccountIds,
            $accountId > 0 ? $accountId : null,
            [self::class, 'bindAvailableSourceIdentity']
        );
        $availableRows = $projection['rows'];
        $preview = [
            'eligible_jobs' => count($availableRows),
            'candidate_jobs_scanned' => $projection['candidate_count'],
            'has_more_basis' => 'validated_fifo_lookahead',
            'rows' => $availableRows,
            'excluded_jobs' => [],
            'excluded_summary' => [],
            'manual_queue_preview_matches_auto_eligibility' => 'MANUAL_CHECKPOINT_CONTINUATION',
            'auto_eligibility_match' => 'MANUAL_CHECKPOINT_CONTINUATION',
            'f1_future_finance_excluded' => 'PASS',
            'f1b_pack_incomplete_excluded' => 'PASS',
            'waiting_excluded' => 'EXCEPT_CONFIRMED_BILLING_CHECKPOINT',
            'review_excluded' => 'PASS',
        ];
        $preview['has_more'] = $projection['has_more'];
        $preview['truncated'] = $preview['has_more'];
        $preview['scope_label'] = 'Pendientes disponibles ahora';
        $preview['expires_in_seconds'] = $ttl;
        $token = bin2hex(random_bytes(20));
        $summary = $preview;
        unset($summary['rows']);

        $pdo->beginTransaction();
        try {
            $pdo->prepare(
                'INSERT INTO manual_campaign_previews
                 (preview_token,created_by_user_id,scope_key,meli_account_id,configuration_hash,
                  configuration_json,summary_json,expires_at)
                 VALUES (?,?,?,?,?,?,?,DATE_ADD(UTC_TIMESTAMP(3),INTERVAL ' . $ttl . ' SECOND))'
            )->execute([
                $token,
                $userId,
                $configuration['scope'],
                $configuration['account_id'] ?: null,
                $hash,
                json_encode($configuration, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ]);
            $previewId = (int) $pdo->lastInsertId();
            $item = $pdo->prepare(
                'INSERT INTO manual_campaign_preview_items
                 (manual_campaign_preview_id,queue_key,source_id,meli_account_id,source_state,item_payload_json,position_no)
                 VALUES (?,?,?,?,?,?,?)'
            );
            $position = 0;
            foreach ((array) ($preview['rows'] ?? []) as $row) {
                $position++;
                $item->execute([
                    $previewId,
                    (string) ($row['queue_key'] ?? 'available_queue'),
                    (string) ($row['source_id'] ?? ''),
                    !empty($row['meli_account_id']) ? (int) $row['meli_account_id'] : null,
                    (string) ($row['source_state'] ?? 'ready'),
                    json_encode($this->safeRow($row), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    $position,
                ]);
            }
            $pdo->commit();
        } catch (Throwable $error) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $error;
        }

        return $this->load($token, $userId);
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private function safeRow(array $row): array
    {
        $allowed = [
            'queue_key','source_id','meli_account_id','account_name','human_label','label','content_summary',
            'estimated_api_calls','estimated_seconds','item_count','operation_key','uses_api',
            'requested_interval_ms','effective_interval_ms','block_size','block_pause_ms','source_state',
            'queue_job_id','company_id','job_type','capability','resource_id','resource_label',
            'source_alias','status_label','state_label','available_at','last_error_class',
            'selection_id','selection_version','source_authority_version','remote_contract',
            'related_resource_ids','queue_idempotency_key','queue_payload_hash',
            'source_queue_key','source_selection_version',
            'manual_continuation_kind','queue_lease_generation','financial_source_lease_generation','financial_input_version',
        ];
        return array_intersect_key($row, array_flip($allowed));
    }

    /**
     * Bind a Queue V4 pointer to the durable domain source visible now.
     * Non-domain pointers are already fully identified by their queue payload.
     *
     * @param array<string,mixed> $row
     * @return array<string,mixed>|null
     */
    public static function bindAvailableSourceIdentity(array $row): ?array
    {
        if (!(new QueueV4CleanRepository(Database::connectionFresh()))->manualContinuationSourceIdentityMatches($row)) {
            return null;
        }
        if ((string) ($row['job_type'] ?? '') !== 'domain_exact') {
            return $row;
        }
        $queueKey = match ((string) ($row['capability'] ?? '')) {
            'notification_work_item' => 'notification_fallback',
            'financial_recalc' => 'financial_recalc',
            'financial_reconciliation' => 'sale_financial_reconciliation',
            'order_enrichment_pack' => 'order_enrichment',
            default => null,
        };
        $sourceId = (string) ($row['resource_id'] ?? '');
        $accountId = (int) ($row['meli_account_id'] ?? 0);
        $companyId = (int) ($row['company_id'] ?? 0);
        if ($queueKey === null || !ctype_digit($sourceId) || $accountId < 1 || $companyId < 1) {
            return null;
        }
        $state = (new ManualCampaignSourceInspector())->inspect($queueKey, $sourceId, $accountId, $companyId);
        if (!$state->exists || $state->terminal || !$state->eligible) {
            return null;
        }
        $authorityService = new \App\QueueCore\ManualSourceAuthorityService();
        $authority = $authorityService->inspect($queueKey, $sourceId, $accountId, $companyId, $state);
        if ($authority->explicitlyUnsupported) {
            return null;
        }
        $relatedIds = $authorityService->relatedResourceIds($queueKey, $sourceId, $accountId, $companyId);
        $identity = [
            'selection_id' => 'available-source:' . (int) ($row['queue_job_id'] ?? 0) . ':' . $queueKey . ':' . $sourceId,
            'company_id' => $companyId,
            'meli_account_id' => $accountId,
            'source_authority_version' => $authority->durableInputVersion,
            'operation_key' => $authority->operationKey,
            'uses_api' => $authority->usesApi,
            'remote_contract' => $authority->remoteContract,
            'related_resource_ids' => $relatedIds,
        ];
        return array_replace($row, [
            'source_queue_key' => $queueKey,
            'source_authority_version' => $authority->durableInputVersion,
            'related_resource_ids' => $relatedIds,
            'source_selection_version' => self::exactSelectionVersion($identity),
        ]);
    }

    /** @param array<string,mixed> $row */
    public static function availableSourceIdentityMatches(array $row): bool
    {
        if ((string) ($row['job_type'] ?? '') !== 'domain_exact') {
            return true;
        }
        $expected = (string) ($row['source_selection_version'] ?? '');
        if (preg_match('/^[a-f0-9]{64}$/D', $expected) !== 1) {
            return false;
        }
        $current = self::bindAvailableSourceIdentity($row);
        return $current !== null
            && hash_equals($expected, (string) ($current['source_selection_version'] ?? ''));
    }

    /** @param array<string,mixed> $row */
    public static function exactSelectionVersion(array $row): string
    {
        return hash('sha256', json_encode([
            'selection_id' => (string) ($row['selection_id'] ?? ''),
            'company_id' => (int) ($row['company_id'] ?? 0),
            'meli_account_id' => (int) ($row['meli_account_id'] ?? 0),
            'source_authority_version' => (string) ($row['source_authority_version'] ?? ''),
            'operation_key' => (string) ($row['operation_key'] ?? ''),
            'uses_api' => (bool) ($row['uses_api'] ?? false),
            'remote_contract' => $row['remote_contract'] ?? null,
            'related_resource_ids' => array_values((array) ($row['related_resource_ids'] ?? [])),
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }
}
