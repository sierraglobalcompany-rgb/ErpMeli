<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use RuntimeException;
use Throwable;

final class ManualSingleStepService
{
    /** @return array<string,mixed> */
    public function execute(string $previewToken, int $userId): array
    {
        if ($previewToken === '') {
            throw new RuntimeException('Calcule y revise un trabajo antes de procesarlo.');
        }

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
            $row = $preview['rows'][0] ?? null;
            if (!is_array($row)) {
                throw new RuntimeException('El calculo no contiene un trabajo exacto disponible.');
            }

            $queueKey = (string) ($row['queue_key'] ?? '');
            $sourceId = (string) ($row['source_id'] ?? '');
            $accountId = max(0, (int) ($row['meli_account_id'] ?? 0));
            $adapter = (new ManualCampaignAdapterRegistry())->forQueue($queueKey);
            if ($adapter === null || !$adapter->supportsExact()) {
                throw new RuntimeException('El trabajo no tiene un ejecutor exacto certificado.');
            }

            $companyId = 0;
            if ($accountId > 0) {
                $account = (new BusinessScopeContext())->account($accountId);
                $companyId = (int) ($account['company_id'] ?? 0);
                if ($companyId < 1) {
                    throw new RuntimeException('No fue posible confirmar la empresa de la cuenta.');
                }
            }
            $state = $adapter->inspect($sourceId, $accountId);
            if (!$state->exists || $state->terminal || !$state->eligible) {
                throw new RuntimeException($state->message);
            }

            $previews->consume($previewToken, $userId);
            ApiExecutionMetadataContext::resetRemoteDispatchCount();
            $context = new CampaignExecutionContext(
                0,
                0,
                $companyId,
                'manual_web_single_step',
                1,
                microtime(true) + 25,
                1
            );
            try {
                $result = ApiExecutionMetadataContext::run(
                    [
                        'source' => 'manual_campaign',
                        'manual_mode' => 'single_step',
                        'company_id' => $companyId,
                        'account_id' => $accountId,
                    ],
                    fn (): CampaignItemResult => $adapter->processExact($sourceId, $accountId, $context)
                );
            } catch (Throwable $error) {
                if (ApiExecutionMetadataContext::remoteDispatchCount() > 0) {
                    return [
                        'status' => 'review',
                        'message' => 'La consulta salio, pero su resultado no pudo confirmarse. No se repetira automaticamente.',
                        'queue_key' => $queueKey,
                        'source_id' => $sourceId,
                        'remote_dispatches' => ApiExecutionMetadataContext::remoteDispatchCount(),
                    ];
                }
                throw $error;
            }

            return $result->toArray() + [
                'queue_key' => $queueKey,
                'source_id' => $sourceId,
                'remote_dispatches' => ApiExecutionMetadataContext::remoteDispatchCount(),
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
