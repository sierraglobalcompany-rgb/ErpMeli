<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use PDO;

/**
 * Carril local y acotado. Nunca consulta Mercado Libre y solo avanza un paso
 * del pipeline archivo -> checksum -> rollup -> eliminación verificada.
 */
final class TechnicalRetentionCliService
{
    private const LEASE_SECONDS = 90;

    private const DATASETS = [
        'api_remote_permits',
        'operational_snapshots',
        'cron_backlog_snapshots',
        'cron_backlog_run_totals',
        'cron_health_checks',
        'work_queue_items',
        'work_queue_runs',
        'manual_campaign_events',
        'api_request_logs',
    ];

    /** @return array<string,mixed> */
    public function runStep(int $limit = 500): array
    {
        if (!(new SchemaInspectorService())->hasTable('system_retention_cli_state')) {
            return ['processed' => 0, 'errors' => 0, 'skipped' => true, 'reason' => 'schema_pending'];
        }
        $pdo = Database::connectionFresh();
        $owner = $this->ownerToken();
        $pdo->beginTransaction();
        try {
            $row = $pdo->query(
                'SELECT id,dataset_position,generation FROM system_retention_cli_state
                 WHERE lane_key="technical" LIMIT 1 FOR UPDATE'
            )->fetch(PDO::FETCH_ASSOC);
            if (!is_array($row)) {
                $pdo->exec(
                    'INSERT IGNORE INTO system_retention_cli_state
                     (lane_key,dataset_position,generation,heartbeat_at,updated_at)
                     VALUES ("technical",0,1,UTC_TIMESTAMP(3),UTC_TIMESTAMP(3))'
                );
                $row = $pdo->query(
                    'SELECT id,dataset_position,generation FROM system_retention_cli_state
                     WHERE lane_key="technical" LIMIT 1 FOR UPDATE'
                )->fetch(PDO::FETCH_ASSOC);
                if (!is_array($row)) {
                    throw new \RuntimeException('No fue posible adquirir el checkpoint de retención.');
                }
            }
            $position = max(0, (int) $row['dataset_position']) % count(self::DATASETS);
            $generation = max(1, (int) $row['generation']);
            $claim = $pdo->prepare(
                'UPDATE system_retention_cli_state
                 SET generation=generation+1,lease_owner=:owner,
                     lease_expires_at=DATE_ADD(UTC_TIMESTAMP(3),INTERVAL ' . self::LEASE_SECONDS . ' SECOND),
                     heartbeat_at=UTC_TIMESTAMP(3),updated_at=UTC_TIMESTAMP(3)
                 WHERE lane_key="technical" AND generation=:generation
                   AND (lease_expires_at IS NULL OR lease_expires_at<UTC_TIMESTAMP(3))'
            );
            $claim->execute(['owner' => $owner, 'generation' => $generation]);
            if ($claim->rowCount() !== 1) {
                $pdo->rollBack();
                return ['processed' => 0, 'errors' => 0, 'skipped' => true, 'reason' => 'lease_busy'];
            }
            $generation++;
            $pdo->commit();
        } catch (\Throwable $error) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $error;
        }

        $dataset = self::DATASETS[$position];
        $fence = function () use ($owner, $generation): void {
            $this->renewOrFail($owner, $generation);
        };
        try {
            $fence();
            $result = (new RetentionPolicyService())->runDatasetStep(
                $dataset,
                max(1, min(500, $limit)),
                $fence
            );
            $fence();
            $processed = max(0, (int) ($result['archive_rows'] ?? 0))
                + max(0, (int) $result['rollups'])
                + max(0, (int) $result['deleted']);
            $next = !empty($result['complete'])
                ? (($position + 1) % count(self::DATASETS))
                : $position;
            $finish = Database::connectionFresh()->prepare(
                'UPDATE system_retention_cli_state
                 SET dataset_position=:position,generation=generation+1,
                     lease_owner=NULL,lease_expires_at=NULL,
                     last_dataset=:dataset,last_stage=:stage,last_processed=:processed,
                     heartbeat_at=UTC_TIMESTAMP(3),updated_at=UTC_TIMESTAMP(3)
                 WHERE lane_key="technical" AND lease_owner=:owner
                   AND generation=:generation
                   AND lease_expires_at>=UTC_TIMESTAMP(3)'
            );
            $finish->execute([
                'position' => $next,
                'dataset' => $dataset,
                'stage' => (string) $result['stage'],
                'processed' => $processed,
                'owner' => $owner,
                'generation' => $generation,
            ]);
            if ($finish->rowCount() !== 1) {
                throw new TechnicalRetentionLeaseLostException();
            }
            (new ReadModelCacheService())->garbageCollect(50);
            return $result + ['processed' => $processed, 'errors' => 0, 'skipped' => false];
        } catch (TechnicalRetentionLeaseLostException) {
            return [
                'stage' => 'aborted',
                'dataset' => $dataset,
                'rollups' => 0,
                'deleted' => 0,
                'processed' => 0,
                'errors' => 1,
                'skipped' => false,
                'reason' => 'lease_lost',
                'complete' => false,
            ];
        } catch (\Throwable $error) {
            $this->releaseAfterFailure($owner, $generation, $dataset);
            throw $error;
        }
    }

    private function ownerToken(): string
    {
        $host = gethostname();
        return substr(
            ($host !== false && $host !== '' ? $host : 'localhost')
            . ':' . getmypid() . ':' . bin2hex(random_bytes(8)),
            0,
            160
        );
    }

    private function renewOrFail(string $owner, int $generation): void
    {
        $renew = Database::connectionFresh()->prepare(
            'UPDATE system_retention_cli_state
             SET lease_expires_at=DATE_ADD(UTC_TIMESTAMP(3),INTERVAL ' . self::LEASE_SECONDS . ' SECOND),
                 heartbeat_at=UTC_TIMESTAMP(3),updated_at=UTC_TIMESTAMP(3)
             WHERE lane_key="technical" AND lease_owner=:owner
               AND generation=:generation
               AND lease_expires_at>=UTC_TIMESTAMP(3)'
        );
        $renew->execute(['owner' => $owner, 'generation' => $generation]);
        $verify = Database::connectionFresh()->prepare(
            'SELECT 1 FROM system_retention_cli_state
             WHERE lane_key="technical" AND lease_owner=:owner
               AND generation=:generation
               AND lease_expires_at>=UTC_TIMESTAMP(3) LIMIT 1'
        );
        $verify->execute(['owner' => $owner, 'generation' => $generation]);
        if ($verify->fetchColumn() === false) {
            throw new TechnicalRetentionLeaseLostException();
        }
    }

    private function releaseAfterFailure(string $owner, int $generation, string $dataset): void
    {
        try {
            Database::connectionFresh()->prepare(
                'UPDATE system_retention_cli_state
                 SET generation=generation+1,lease_owner=NULL,lease_expires_at=NULL,
                     last_dataset=:dataset,last_stage="failed",last_processed=0,
                     heartbeat_at=UTC_TIMESTAMP(3),updated_at=UTC_TIMESTAMP(3)
                 WHERE lane_key="technical" AND lease_owner=:owner AND generation=:generation'
            )->execute([
                'dataset' => $dataset,
                'owner' => $owner,
                'generation' => $generation,
            ]);
        } catch (\Throwable) {
            // El lease conserva su expiración y podrá recuperarse de forma cercada.
        }
    }
}
