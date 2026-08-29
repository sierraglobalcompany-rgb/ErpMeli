<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\QueueCore\ManualQueueLauncher;
use App\QueueCore\ManualInputVersion;
use App\QueueCore\ManualSourceAuthorityService;
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
        $limit = max(1, min(60, $limit));

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
}
