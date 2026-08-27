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
        return $this->executeSelected($previewToken, $userId, 0, true);
    }

    /** @return array<string,mixed> */
    public function executeSelected(
        string $previewToken,
        int $userId,
        int $positionNo = 0,
        bool $consumePreview = true,
        ?string $expectedScope = null,
        ?int $expectedAccountId = null
    ): array
    {
        if ($previewToken === '') {
            throw new RuntimeException('Calcule y revise un trabajo antes de procesarlo.');
        }
        $positionNo = max(0, $positionNo);

        $pdo = Database::connectionFresh();
        $lockName = 'erp_manual_step_' . substr(hash('sha256', $previewToken . ':' . $positionNo), 0, 32);
        $lock = $pdo->prepare('SELECT GET_LOCK(?,3)');
        $lock->execute([$lockName]);
        if ((int) $lock->fetchColumn() !== 1) {
            throw new RuntimeException('Ese trabajo ya se esta procesando. Espere el resultado.');
        }

        try {
            $previews = new ManualCampaignPreviewService();
            $preview = $previews->load($previewToken, $userId);
            $configuration = is_array($preview['configuration'] ?? null) ? $preview['configuration'] : [];
            if ($expectedScope !== null
                && $expectedScope !== ''
                && (string) ($configuration['scope'] ?? '') !== $expectedScope) {
                throw new RuntimeException('SELECTION_BINDING_MISMATCH');
            }
            if ($expectedAccountId !== null
                && (int) ($configuration['account_id'] ?? 0) !== max(0, $expectedAccountId)) {
                throw new RuntimeException('SELECTION_BINDING_MISMATCH');
            }
            $row = $preview['rows'][$positionNo] ?? null;
            if (!is_array($row)) {
                throw new RuntimeException('NO_ELIGIBLE_SELECTED_WORK');
            }

            $queueKey = (string) ($row['queue_key'] ?? '');
            $sourceId = (string) ($row['source_id'] ?? '');
            $accountId = max(0, (int) ($row['meli_account_id'] ?? 0));
            if ($accountId < 1) {
                throw new RuntimeException('El trabajo exacto no tiene una cuenta autorizada.');
            }
            if ($expectedAccountId !== null && $expectedAccountId > 0 && $accountId !== $expectedAccountId) {
                throw new RuntimeException('SELECTION_BINDING_MISMATCH');
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
                'manual-explicit', $previewToken, (string) $positionNo, (string) $userId,
                $queueKey, $sourceId, $inputVersion,
            ]));
            // Manual and Cron V4 are launchers only. Both enter the same
            // QueueRunner, scheduler, claim, fencing and retry path.
            $result = (new ManualQueueLauncher())->runExact(
                $companyId,
                $accountId,
                $queueKey,
                $sourceId,
                $authority->usesApi,
                $authority->operationKey,
                $inputVersion,
                $authority->durableInputVersion,
                $explicitAttemptKey,
                $authority->remoteContract
            );
            // Solo se consume después de que Queue Core adquirió exclusión,
            // persistió y ejecutó el único paso sin continuación automática.
            if ($consumePreview) {
                $previews->consume($previewToken, $userId);
            }
            return $result + [
                'preview_token' => $previewToken,
                'preview_position' => $positionNo,
                'selected_count' => count((array) ($preview['rows'] ?? [])),
                'account_id' => $accountId,
            ];
        } finally {
            try {
                $release = $pdo->prepare('SELECT RELEASE_LOCK(?)');
                $release->execute([$lockName]);
            } catch (Throwable) {
            }
        }
    }
}
