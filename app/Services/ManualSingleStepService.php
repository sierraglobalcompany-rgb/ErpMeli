<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\QueueCore\ManualQueueLauncher;
use App\QueueCore\ManualInputVersion;
use App\QueueCore\ManualSourceAuthorityService;
use App\QueueCore\QueueExecutionLeaseService;
use App\QueueV4Clean\QueueV4CleanCycleBudget;
use App\QueueV4Clean\QueueV4CleanRepository;
use App\QueueV4Clean\QueueV4CleanWorker;
use RuntimeException;
use Throwable;

final class ManualSingleStepService
{
    /** @return array<string,mixed> */
    public function executePreview(string $previewToken, int $userId, int $requestedPhysicalCalls): array
    {
        CronDeadlineContext::start(45, 43, 8, 3);
        try {
            return $this->executeWithinRequest($previewToken, $userId, $requestedPhysicalCalls);
        } finally {
            CronDeadlineContext::clear();
        }
    }

    /** @return array<string,mixed> */
    public function execute(string $previewToken, int $userId): array
    {
        return $this->executePreview($previewToken, $userId, 1);
    }

    /** @return array<string,mixed> */
    public function executeMany(string $previewToken, int $userId, int $limit): array
    {
        return $this->executePreview($previewToken, $userId, $limit);
    }

    private function executeWithinRequest(string $previewToken, int $userId, int $limit): array
    {
        if ($previewToken === '') {
            throw new RuntimeException('Calcule y revise un trabajo antes de procesarlo.');
        }
        $limit = max(1, min(QueueV4CleanWorker::HARD_MAX_CALLS, $limit));

        $pdo = Database::connectionFresh();
        $lockName = 'erp_manual_step_' . substr(hash('sha256', $previewToken), 0, 32);
        $lock = $pdo->prepare('SELECT GET_LOCK(?,3)');
        $lock->execute([$lockName]);
        if ((int) $lock->fetchColumn() !== 1) {
            throw new RuntimeException('Ese trabajo ya se esta procesando. Espere el resultado.');
        }

        $cycleStarted = false;
        try {
            $previews = new ManualCampaignPreviewService();
            $preview = $previews->load($previewToken, $userId);
            $configuration = (array) ($preview['configuration'] ?? []);
            $isAvailableQueue = (string) ($configuration['scope'] ?? '') === 'available_queue';
            $physicalCallBudget = ManualPhysicalCallBudget::resolve(
                $configuration,
                $limit,
                (new CapacityPolicyService())->snapshot('manual')
            );
            $safety = (new SystemSafetyStatusService())->status();
            if (($safety['api'] ?? '') === 'stopped' || ($safety['automation'] ?? '') === 'stopped') {
                throw new RuntimeException('El procesamiento está en mantenimiento. Vuelva a calcular cuando se reactive.');
            }
            QueueV4CleanCycleBudget::start($physicalCallBudget, 'manual', CronDeadlineContext::deadline());
            $cycleStarted = true;
            if ($isAvailableQueue) {
                return $this->executeAvailableQueue($pdo, $previews, $preview, $previewToken, $userId, $physicalCallBudget);
            }
            $rows = array_values(array_filter(
                (array) ($preview['rows'] ?? []),
                static fn ($row): bool => is_array($row)
            ));
            if ($rows === []) {
                throw new RuntimeException('El calculo no contiene trabajos exactos disponibles.');
            }

            $selectedRows = array_slice($rows, 0, ManualCampaignPreviewService::PRESENTATION_LIMIT);
            $items = [];
            $skipped = [];
            foreach ($selectedRows as $index => $row) {
                if (!CronDeadlineContext::canAcceptWork(2)) {
                    throw new RuntimeException('Se agotó el tiempo para preparar el paso manual. Vuelva a calcular.');
                }
                $queueKey = (string) ($row['queue_key'] ?? '');
                $sourceId = (string) ($row['source_id'] ?? '');
                $accountId = max(0, (int) ($row['meli_account_id'] ?? 0));
                if ($accountId < 1) {
                    throw new RuntimeException('El trabajo exacto no tiene una cuenta autorizada.');
                }
                $adapter = (new ManualCampaignAdapterRegistry())->forQueue($queueKey);
                if ($adapter === null || !$adapter->supportsExact()) {
                    throw new RuntimeException('El trabajo no tiene un ejecutor exacto certificado.');
                }

                $account = (new BusinessScopeContext())->account($accountId, 0, $userId);
                $companyId = (int) ($account['company_id'] ?? 0);
                if ($companyId < 1) {
                    throw new RuntimeException('No fue posible confirmar la empresa de la cuenta.');
                }
                $state = $adapter->inspect($sourceId, $accountId);
                if (!$state->exists || $state->terminal || !$state->eligible) {
                    $skipped[] = $this->skippedRow($row, 'source_changed');
                    continue;
                }

                // El preview es solo una selección sanitizada. La identidad lógica,
                // el contrato físico y la versión durable se releen desde la fila
                // fuente inmediatamente antes de consumir y encolar.
                $authority = (new ManualSourceAuthorityService())->inspect(
                    $queueKey,
                    $sourceId,
                    $accountId,
                    $companyId,
                    $state
                );
                if ($authority->explicitlyUnsupported) {
                    $skipped[] = $this->skippedRow($row, 'source_unsupported');
                    continue;
                }

                $authorityService = new ManualSourceAuthorityService();
                $relatedIds = $authorityService->relatedResourceIds($queueKey, $sourceId, $accountId, $companyId);
                $currentSnapshot = array_replace($row, [
                    'company_id' => $companyId,
                    'selection_id' => 'exact:' . $queueKey . ':' . $sourceId,
                    'source_authority_version' => $authority->durableInputVersion,
                    'operation_key' => $authority->operationKey,
                    'uses_api' => $authority->usesApi,
                    'remote_contract' => $authority->remoteContract,
                    'related_resource_ids' => $relatedIds,
                ]);
                if (preg_match('/^[a-f0-9]{64}$/', (string) ($row['selection_version'] ?? '')) !== 1
                    || !hash_equals((string) $row['selection_version'], ManualCampaignPreviewService::exactSelectionVersion($currentSnapshot))) {
                    $skipped[] = $this->skippedRow($row, 'source_version_changed');
                    continue;
                }

                $inputVersion = ManualInputVersion::deriveFromSourceAuthority(
                    $row,
                    $authority
                );
                // Cada preview consumible representa una intención humana. Repetir
                // el mismo POST conserva dedupe; un preview nuevo puede ejecutar
                // otro paso sobre la misma versión durable ya revisada.
                $explicitAttemptKey = hash('sha256', implode('|', [
                    'manual-explicit', $previewToken, (string) $userId,
                    (string) $index, $queueKey, $sourceId, $inputVersion,
                ]));
                $items[] = [
                    'manual_preview_id' => (int) $preview['preview_id'],
                    'manual_user_id' => $userId,
                    'company_id' => $companyId,
                    'account_id' => $accountId,
                    'queue_key' => $queueKey,
                    'source_id' => $sourceId,
                    'uses_api' => $authority->usesApi,
                    'operation_key' => $authority->operationKey,
                    'input_version' => $inputVersion,
                    'source_authority_version' => $authority->durableInputVersion,
                    'explicit_attempt_key' => $explicitAttemptKey,
                    'remote_contract' => $authority->remoteContract,
                    'related_resource_ids' => $relatedIds,
                    'selection_id' => (string) $row['selection_id'],
                    'selection_version' => (string) $row['selection_version'],
                ];
            }
            // Manual and Cron V4 are launchers only. Manual confirms an exact,
            // preview-bound subset and the launcher processes only those rows
            // under a single global execution lease.
            if ($items === []) {
                $previews->consume($previewToken, $userId);
                return $this->capacityReceipt([
                    'status' => 'waiting',
                    'message' => 'La selección cambió; no se sustituyó ningún elemento. Vuelva a calcular.',
                    'selected_count' => count($selectedRows),
                    'processed_count' => 0,
                    'completed_count' => 0,
                    'deferred_count' => 0,
                    'waiting_count' => 0,
                    'review_error_count' => 0,
                    'not_processed_count' => count($selectedRows),
                    'stale_or_busy_skipped' => count($skipped),
                    'results' => $skipped,
                    'stop_reason' => 'selection_stale',
                ], $configuration, $limit, $physicalCallBudget);
            }
            $result = (new ManualQueueLauncher())->runExactBatch(
                $items, $physicalCallBudget, CronDeadlineContext::deadline(),
                static function () use ($items, $configuration, $physicalCallBudget, $previews, $previewToken, $userId): void {
                    foreach ($items as $item) {
                        (new BusinessScopeContext())->account($item['account_id'], $item['company_id'], $userId);
                        $state = (new ManualCampaignSourceInspector())->inspect(
                            $item['queue_key'], $item['source_id'], $item['account_id'], $item['company_id']
                        );
                        if (!$state->exists || $state->terminal || !$state->eligible) {
                            throw new RuntimeException('La selección cambió. Vuelva a calcular los trabajos disponibles.');
                        }
                        $authority = (new ManualSourceAuthorityService())->inspect(
                            $item['queue_key'], $item['source_id'], $item['account_id'], $item['company_id'], $state
                        );
                        $relatedIds = (new ManualSourceAuthorityService())->relatedResourceIds(
                            $item['queue_key'], $item['source_id'], $item['account_id'], $item['company_id']
                        );
                        $currentSnapshot = [
                            'selection_id' => $item['selection_id'],
                            'company_id' => $item['company_id'],
                            'meli_account_id' => $item['account_id'],
                            'source_authority_version' => $authority->durableInputVersion,
                            'operation_key' => $authority->operationKey,
                            'uses_api' => $authority->usesApi,
                            'remote_contract' => $authority->remoteContract,
                            'related_resource_ids' => $relatedIds,
                        ];
                        if ($authority->explicitlyUnsupported
                            || !hash_equals($item['source_authority_version'], $authority->durableInputVersion)
                            || $item['uses_api'] !== $authority->usesApi
                            || $item['remote_contract'] !== $authority->remoteContract
                            || $item['related_resource_ids'] !== $relatedIds
                            || !hash_equals($item['selection_version'], ManualCampaignPreviewService::exactSelectionVersion($currentSnapshot))) {
                            throw new ManualStaleSourceException();
                        }
                    }
                    ManualPhysicalCallBudget::resolve($configuration, $physicalCallBudget, (new CapacityPolicyService())->snapshot('manual'));
                    if (!CronDeadlineContext::canAcceptWork(1)) {
                        throw new RuntimeException('Se agotó el tiempo para preparar el paso manual. Vuelva a calcular.');
                    }
                    $previews->consume($previewToken, $userId);
                }
            );
            $result['selected_count'] = count($selectedRows);
            $result['stale_or_busy_skipped'] = count($skipped);
            $result['not_processed_count'] = max(0, (int) ($result['not_processed_count'] ?? 0) + count($skipped));
            $result['results'] = array_merge($skipped, (array) ($result['results'] ?? []));
            return $this->capacityReceipt($result, $configuration, $limit, $physicalCallBudget);
        } finally {
            if ($cycleStarted) {
                QueueV4CleanCycleBudget::clear();
            }
            try {
                $release = $pdo->prepare('SELECT RELEASE_LOCK(?)');
                $release->execute([$lockName]);
            } catch (Throwable) {
            }
        }
    }

    /**
     * @param array<string,mixed> $preview
     * @return array<string,mixed>
     */
    private function executeAvailableQueue(
        \PDO $pdo,
        ManualCampaignPreviewService $previews,
        array $preview,
        string $previewToken,
        int $userId,
        int $limit
    ): array {
        $rows = array_values(array_filter(
            (array) ($preview['rows'] ?? []),
            static fn ($row): bool => is_array($row)
        ));
        $requested = max(1, min(QueueV4CleanWorker::HARD_MAX_CALLS, $limit));
        $selected = count($rows);
        if ($selected < 1) {
            throw new RuntimeException('La cola disponible ya no contiene trabajos para procesar.');
        }

        $configuration = (array) ($preview['configuration'] ?? []);
        $accountId = max(0, (int) ($configuration['account_id'] ?? 0));
        $scope = new BusinessScopeContext();
        $allowedAccountIds = $accountId > 0
            ? [(int) $scope->account($accountId, 0, $userId)['id']]
            : $scope->accountIds($userId);
        if ($allowedAccountIds === []) {
            throw new RuntimeException('No hay cuentas autorizadas para procesar.');
        }

        $owner = 'manual_available_queue:' . substr(hash('sha256', $previewToken . '|' . $userId), 0, 24);
        $leaseService = new QueueExecutionLeaseService($pdo);
        $lease = $leaseService->acquire('manual', $owner, 60);
        if ($lease === null) {
            return [
                'status' => 'waiting',
                'message' => 'Automatización procesando; intente nuevamente.',
                'manual_available_queue' => true,
                'control_unit' => 'PHYSICAL_API_CALL',
                'requested_api_calls' => $requested,
                'api_calls_used' => 0,
                'requested_count' => $selected,
                'selected_count' => $selected,
                'processed_count' => 0,
                'completed_count' => 0,
                'waiting_count' => 0,
                'review_error_count' => 0,
                'not_processed_count' => $selected,
                'active_drainers_max' => 1,
                'manual_auto_shared_global_authority' => true,
                'second_global_lease_acquire' => 0,
                'background_continuation' => 0,
            ];
        }

        try {
            // Recheck after prework/lease acquisition; a saved reduction must
            // never be bypassed by a previously posted or loaded preview.
            $requested = ManualPhysicalCallBudget::resolve(
                $configuration, $requested, (new CapacityPolicyService())->snapshot('manual')
            );
            $repository = new QueueV4CleanRepository($pdo);
            $control = $repository->control();
            if ((string) ($control['engine_state'] ?? '') !== 'ACTIVE') {
                throw new RuntimeException('El procesamiento automático está detenido; no se admitió el paso manual.');
            }
            $workerLimit = min($requested, QueueV4CleanWorker::HARD_MAX_CALLS);
            if (!CronDeadlineContext::canAcceptWork(1)) {
                throw new RuntimeException('El cálculo ya no tiene tiempo seguro en esta ventana. Vuelva a calcular.');
            }
            // Consume and wake only this confirmed subset under the same live
            // global authority and PDO transaction. No HTTP occurs until commit.
            // Applies only to this next transaction, not the session default:
            // a lock wait must not preserve a pre-wait snapshot of live inputs.
            $pdo->exec('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');
            $pdo->beginTransaction();
            try {
                $authority = $pdo->prepare(
                    "SELECT generation FROM queue_core_execution_leases
                      WHERE lease_key='global' AND launcher='manual' AND owner_token=?
                        AND generation=? AND expires_at>UTC_TIMESTAMP(3) FOR UPDATE"
                );
                $authority->execute([$lease->ownerToken, $lease->generation]);
                if ($authority->fetchColumn() === false) {
                    throw new RuntimeException('La autoridad manual venció. Vuelva a calcular.');
                }
                if ((string) ($repository->control(true)['engine_state'] ?? '') !== 'ACTIVE') {
                    throw new RuntimeException('El procesamiento automático está detenido; no se admitió el paso manual.');
                }
                // Re-read authorization after acquiring the global lease.
                $allowedAccountIds = $accountId > 0
                    ? [(int) $scope->account($accountId, 0, $userId)['id']]
                    : $scope->accountIds($userId);
                ManualPhysicalCallBudget::resolve($configuration, $requested, (new CapacityPolicyService())->snapshot('manual'));
                if (!CronDeadlineContext::canAcceptWork(1) || Database::connectionFresh() !== $pdo) {
                    throw new RuntimeException('El cálculo ya no tiene tiempo seguro en esta ventana. Vuelva a calcular.');
                }
                $previews->consume($previewToken, $userId);
                $currentSelection = $repository->admitManualConfirmedSelection($rows, $allowedAccountIds, $accountId ?: null);
                // Lock acquisition/domain reads may have waited. Never commit
                // consumption or promotion after the request/authority expires.
                $freshAccountIds = $accountId > 0
                    ? [(int) $scope->account($accountId, 0, $userId)['id']]
                    : $scope->accountIds($userId);
                foreach ($currentSelection as $confirmed) {
                    if (!in_array((int) $confirmed['meli_account_id'], $freshAccountIds, true)) {
                        throw new RuntimeException('La autorización de la selección cambió. Vuelva a calcular.');
                    }
                    $scope->account((int) $confirmed['meli_account_id'], (int) $confirmed['company_id'], $userId);
                }
                ManualPhysicalCallBudget::resolve($configuration, $requested, (new CapacityPolicyService())->snapshot('manual'));
                if ((string) ($repository->control(true)['engine_state'] ?? '') !== 'ACTIVE') {
                    throw new RuntimeException('El procesamiento automático está detenido; no se admitió el paso manual.');
                }
                $authority->execute([$lease->ownerToken, $lease->generation]);
                if ($authority->fetchColumn() === false || !CronDeadlineContext::canAcceptWork(1)) {
                    throw new RuntimeException('La autoridad o el tiempo seguro del paso manual venció. Vuelva a calcular.');
                }
                $pdo->commit();
            } catch (Throwable $error) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                throw $error;
            }
            if ($currentSelection === []) {
                return $this->capacityReceipt([
                    'status' => 'waiting',
                    'message' => 'Los pendientes confirmados cambiaron o ya están ocupados; no se sustituyó ninguno.',
                    'manual_available_queue' => true,
                    'selected_count' => $selected,
                    'processed_count' => 0,
                    'completed_count' => 0,
                    'waiting_count' => 0,
                    'review_error_count' => 0,
                    'not_processed_count' => $selected,
                    'stale_or_busy_skipped' => $selected,
                    'results' => [],
                    'stop_reason' => 'selection_stale_or_busy',
                ], $configuration, $limit, $requested);
            }
            $workerResult = (new QueueV4CleanWorker($pdo, $repository))->run(
                    'manual',
                    $workerLimit,
                    max(0, (int) floor(CronDeadlineContext::remainingSeconds())),
                    $allowedAccountIds,
                    $accountId ?: null,
                    $currentSelection
                );
            $runSummary = $repository->runOutcomeCounts((int) ($workerResult['run_id'] ?? 0));
            $claimed = (int) ($workerResult['claimed'] ?? 0);
            $apiCallsUsed = (int) ($workerResult['physical_http_calls'] ?? 0);
            $completed = (int) ($runSummary['completed'] ?? $workerResult['completed'] ?? 0);
            $waiting = (int) ($runSummary['waiting'] ?? 0);
            $review = (int) ($runSummary['review'] ?? 0) + (int) ($runSummary['dead'] ?? 0);
            $notProcessed = max(0, count($rows) - $claimed);

            return $this->capacityReceipt([
                'status' => $review > 0 ? 'review' : ($waiting > 0 ? 'deferred' : ($notProcessed > 0 ? 'waiting' : 'completed')),
                'message' => sprintf(
                    'Pendientes disponibles: llamadas API solicitadas %d, llamadas usadas %d, elementos atendidos %d, completados %d, esperando %d, revisión/error %d, no procesados %d.',
                    $requested,
                    $apiCallsUsed,
                    $claimed,
                    $completed,
                    $waiting,
                    $review,
                    $notProcessed
                ),
                'manual_available_queue' => true,
                'control_unit' => 'PHYSICAL_API_CALL',
                'requested_api_calls' => $requested,
                'api_calls_used' => $apiCallsUsed,
                'requested_count' => $selected,
                'selected_count' => $selected,
                'processed_count' => $claimed,
                'completed_count' => $completed,
                'waiting_count' => $waiting,
                'review_error_count' => $review,
                'not_processed_count' => $notProcessed,
                'stale_or_busy_skipped' => $selected - count($currentSelection) + (int) ($workerResult['stale_or_busy_skipped'] ?? 0),
                'run_id' => (int) ($workerResult['run_id'] ?? 0),
                'active_drainers_max' => 1,
                'manual_auto_shared_global_authority' => true,
                'second_global_lease_acquire' => 0,
                'background_continuation' => 0,
            ], $configuration, $limit, $requested);
        } finally {
            try {
                $leaseService->release($lease);
            } catch (Throwable) {
            }
        }
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private function skippedRow(array $row, string $reason): array
    {
        return [
            'status' => 'not_started',
            'reason' => $reason,
            'selection_id' => (string) ($row['selection_id'] ?? ''),
            'queue_key' => (string) ($row['queue_key'] ?? ''),
            'source_id' => (string) ($row['source_id'] ?? ''),
            'remote_dispatches' => 0,
            'processed' => 0,
        ];
    }

    /**
     * @param array<string,mixed> $result
     * @param array<string,mixed> $configuration
     * @return array<string,mixed>
     */
    private function capacityReceipt(array $result, array $configuration, int $requested, int $effective): array
    {
        $budget = QueueV4CleanCycleBudget::snapshot();
        $used = max(0, (int) ($budget['used'] ?? $result['api_calls_used'] ?? 0));
        $certainty = (string) ($budget['physical_http_calls_certainty'] ?? 'UNKNOWN');
        $physicalCalls = array_key_exists('physical_http_calls', $budget)
            ? $budget['physical_http_calls']
            : ($certainty === 'CERTIFIED' ? $used : null);
        return array_replace($result, [
            'control_unit' => 'PHYSICAL_API_CALL',
            'configured_api_calls' => (int) ($configuration['physical_api_call_budget'] ?? $effective),
            'requested_api_calls' => $requested,
            'effective_api_calls' => $effective,
            'physical_http_calls' => is_int($physicalCalls) ? max(0, $physicalCalls) : null,
            'physical_http_calls_certainty' => $certainty,
            'known_physical_calls' => max(0, (int) ($budget['known_physical_calls'] ?? 0)),
            'unresolved_reservations' => max(0, (int) ($budget['unresolved_reservations'] ?? 0)),
            'api_calls_used' => $used,
            'api_calls_remaining' => max(0, $effective - $used),
            'evidence_state' => $certainty === 'CERTIFIED' ? 'CERTIFIED' : 'UNKNOWN',
        ]);
    }

}
