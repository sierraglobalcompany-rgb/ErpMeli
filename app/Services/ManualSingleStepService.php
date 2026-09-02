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
    public function execute(string $previewToken, int $userId): array
    {
        return $this->executeMany($previewToken, $userId, 1);
    }

    /** @return array<string,mixed> */
    public function executeMany(string $previewToken, int $userId, int $limit): array
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

        try {
            $previews = new ManualCampaignPreviewService();
            $preview = $previews->load($previewToken, $userId);
            if ((string) ($preview['configuration']['scope'] ?? '') === 'available_queue') {
                return $this->executeAvailableQueue($pdo, $previews, $preview, $previewToken, $userId, $limit);
            }
            $rows = array_values(array_filter(
                (array) ($preview['rows'] ?? []),
                static fn ($row): bool => is_array($row)
            ));
            if ($rows === []) {
                throw new RuntimeException('El calculo no contiene trabajos exactos disponibles.');
            }

            $selectedRows = array_slice($rows, 0, $limit);
            $items = [];
            foreach ($selectedRows as $index => $row) {
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

                $account = (new BusinessScopeContext())->account($accountId);
                $companyId = (int) ($account['company_id'] ?? 0);
                if ($companyId < 1) {
                    throw new RuntimeException('No fue posible confirmar la empresa de la cuenta.');
                }
                $state = $adapter->inspect($sourceId, $accountId);
                if (!$state->exists || $state->terminal || !$state->eligible) {
                    throw new RuntimeException($state->message);
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
                    throw new RuntimeException(
                        $authority->unsupportedReason
                        ?? 'El trabajo exacto no tiene una capacidad Queue Core certificada.'
                    );
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
                ];
            }
            // Manual and Cron V4 are launchers only. Manual confirms an exact,
            // preview-bound subset and the launcher processes only those rows
            // under a single global execution lease.
            $result = (new ManualQueueLauncher())->runExactBatch($items);
            // Solo se consume después de que Queue Core adquirió exclusión,
            // persistió y ejecutó la selección exacta sin continuación automática.
            $previews->consume($previewToken, $userId);
            return $result;
        } finally {
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
        $selected = min($requested, max(0, count($rows)), QueueV4CleanWorker::HARD_MAX_CALLS);
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
                'requested_count' => $requested,
                'selected_count' => 0,
                'processed_count' => 0,
                'completed_count' => 0,
                'waiting_count' => 0,
                'review_error_count' => 0,
                'not_processed_count' => $requested,
                'active_drainers_max' => 1,
                'manual_auto_shared_global_authority' => true,
                'second_global_lease_acquire' => 0,
                'background_continuation' => 0,
            ];
        }

        QueueV4CleanCycleBudget::start($requested);
        try {
            $repository = new QueueV4CleanRepository($pdo);
            $currentEligible = $repository->eligibleCount($allowedAccountIds, $accountId ?: null);
            $workerLimit = min($requested, QueueV4CleanWorker::HARD_MAX_CALLS);
            $workerResult = $currentEligible > 0
                ? (new QueueV4CleanWorker($pdo, $repository))->run(
                    'manual',
                    $workerLimit,
                    45,
                    $allowedAccountIds,
                    $accountId ?: null
                )
                : ['run_id' => 0, 'claimed' => 0, 'completed' => 0, 'deferred' => 0];
            $runSummary = $repository->runOutcomeCounts((int) ($workerResult['run_id'] ?? 0));
            $claimed = (int) ($workerResult['claimed'] ?? 0);
            $apiCallsUsed = (int) ($workerResult['physical_http_calls'] ?? 0);
            $completed = (int) ($runSummary['completed'] ?? $workerResult['completed'] ?? 0);
            $waiting = (int) ($runSummary['waiting'] ?? 0);
            $review = (int) ($runSummary['review'] ?? 0) + (int) ($runSummary['dead'] ?? 0);
            $notProcessed = max(0, $requested - $claimed);

            $previews->consume($previewToken, $userId);

            return [
                'status' => $review > 0 ? 'review' : 'completed',
                'message' => sprintf(
                    'Cola disponible: llamadas API solicitadas %d, llamadas usadas %d, trabajos procesados %d, completados %d, esperando %d, revisión/error %d, no procesados %d.',
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
                'requested_count' => $requested,
                'selected_count' => $selected,
                'processed_count' => $claimed,
                'completed_count' => $completed,
                'waiting_count' => $waiting,
                'review_error_count' => $review,
                'not_processed_count' => $notProcessed,
                'run_id' => (int) ($workerResult['run_id'] ?? 0),
                'active_drainers_max' => 1,
                'manual_auto_shared_global_authority' => true,
                'second_global_lease_acquire' => 0,
                'background_continuation' => 0,
            ];
        } finally {
            QueueV4CleanCycleBudget::clear();
            try {
                $leaseService->release($lease);
            } catch (Throwable) {
            }
        }
    }

}
