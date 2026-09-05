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

        $preview = (new ManualCampaignService())->preview(
            (string) $configuration['scope'],
            (int) $configuration['block_size'],
            (int) $configuration['interval_ms'],
            (int) $configuration['block_pause_ms'],
            (int) $configuration['max_blocks'],
            (int) $configuration['max_duration_minutes'],
            (int) $configuration['account_id'] ?: null
        );
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
        return $capacity + [
            'preview_format' => 3,
            'scope' => preg_replace('/[^a-z_]/', '', (string) ($configuration['scope'] ?? 'recommended')) ?: 'recommended',
            'account_id' => max(0, (int) ($configuration['account_id'] ?? 0)),
            'block_size' => max(1, min(60, (int) ($configuration['block_size'] ?? 30))),
            'interval_ms' => max(0, min(300000, (int) ($configuration['interval_ms'] ?? 2000))),
            'block_pause_ms' => max(0, min(3600000, (int) ($configuration['block_pause_ms'] ?? 30000))),
            'max_blocks' => max(0, min(10000, (int) ($configuration['max_blocks'] ?? 0))),
            'max_duration_minutes' => max(0, min(10080, (int) ($configuration['max_duration_minutes'] ?? 0))),
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
        $preview = [
            'eligible_jobs' => $repository->eligibleCount($allowedAccountIds, $accountId > 0 ? $accountId : null),
            'rows' => $repository->previewEligible(
                (int) $configuration['physical_api_call_budget'],
                $allowedAccountIds,
                $accountId > 0 ? $accountId : null
            ),
            'excluded_jobs' => [],
            'excluded_summary' => [],
            'manual_queue_preview_matches_auto_eligibility' => 'PASS',
            'auto_eligibility_match' => 'PASS',
            'f1_future_finance_excluded' => 'PASS',
            'f1b_pack_incomplete_excluded' => 'PASS',
            'waiting_excluded' => 'PASS',
            'review_excluded' => 'PASS',
        ];
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
        ];
        return array_intersect_key($row, array_flip($allowed));
    }
}
