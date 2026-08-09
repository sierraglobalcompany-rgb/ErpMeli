<?php

declare(strict_types=1);

namespace App\QueueCore;

use App\Core\Env;
use App\Services\EmergencyControlService;
use PDO;
use Throwable;

/** Read model acotado para salud y convergencia; no muta durante lecturas. */
final class QueueCoreHealthService
{
    public function __construct(private readonly PDO $pdo) {}

    /** @return array<string,mixed> */
    public function snapshot(): array
    {
        $reasons = [];
        $depth = [];
        $query = $this->pdo->query(
            'SELECT state,COUNT(*) total,
                    TIMESTAMPDIFF(SECOND,MIN(created_at),UTC_TIMESTAMP(3)) oldest_seconds
             FROM queue_core_jobs
             WHERE queue_domain="operational"
               AND state IN ("pending","claimed","running","retry_wait","waiting_oauth","review","dead")
             GROUP BY state'
        );
        foreach ($query->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $depth[(string) $row['state']] = [
                'total' => (int) $row['total'],
                'oldest_seconds' => $row['oldest_seconds'] === null ? null : (int) $row['oldest_seconds'],
            ];
        }

        $checkpoint = [];
        $stmt = $this->pdo->query(
            'SELECT a.company_id,a.id meli_account_id,a.status,a.last_error,t.expires_at,t.refresh_version,
                    cp.watermark_at,cp.next_due_at,cp.last_error_class,
                    TIMESTAMPDIFF(SECOND,cp.watermark_at,UTC_TIMESTAMP(3)) freshness_lag_seconds
             FROM meli_accounts a
             LEFT JOIN meli_tokens t ON t.meli_account_id=a.id
             LEFT JOIN queue_core_producer_checkpoints cp
               ON cp.producer_key="fresh_orders" AND cp.company_id=a.company_id
              AND cp.meli_account_id=a.id
             WHERE a.status IN ("conectado","connected")
             ORDER BY a.company_id,a.id LIMIT 500'
        );
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $lag = $row['freshness_lag_seconds'] === null ? null : max(0, (int) $row['freshness_lag_seconds']);
            if ($row['watermark_at'] === null) {
                $reasons[] = 'bootstrap_required';
            }
            if (!empty($row['last_error_class'])) {
                $reasons[] = 'producer_checkpoint_error';
            }
            if (!empty($row['expires_at']) && strtotime((string) $row['expires_at'] . ' UTC') <= time()) {
                $reasons[] = 'oauth_expired';
            }
            $lastError = strtolower((string) ($row['last_error'] ?? ''));
            if (str_contains($lastError, 'identity') || str_contains($lastError, 'seller_mismatch')) {
                $reasons[] = 'identity_mismatch';
            }
            if (str_contains($lastError, 'rotated_credential_recovery_unavailable')) {
                $reasons[] = 'rotated_credential_unrecoverable';
            }
            $checkpoint[] = [
                'company_id' => (int) $row['company_id'],
                'meli_account_id' => (int) $row['meli_account_id'],
                'token_expires_at' => $row['expires_at'],
                'refresh_version' => max(0, (int) ($row['refresh_version'] ?? 0)),
                'watermark_at' => $row['watermark_at'],
                'freshness_lag_seconds' => $lag,
                'last_error_class' => $row['last_error_class'],
            ];
        }

        $uncertain = (int) $this->pdo->query(
            'SELECT COUNT(*) FROM queue_core_jobs
             WHERE dispatch_state="DISPATCHED_RESULT_UNCERTAIN" AND state="review"'
        )->fetchColumn();
        if ($uncertain > 0) {
            $reasons[] = 'remote_uncertain_present';
        }
        if ((int) ($depth['dead']['total'] ?? 0) > 0) {
            $reasons[] = 'dead_work_present';
        }
        if ((int) ($depth['waiting_oauth']['total'] ?? 0) > 0) {
            $reasons[] = 'waiting_oauth_present';
        }
        if (!(new QueueCorePreflightService($this->pdo))->runtimeSchemaReady()) {
            $reasons[] = 'schema_inconsistent';
        }
        $crossAccount = (int) $this->pdo->query(
            'SELECT COUNT(*) FROM queue_core_jobs j
             LEFT JOIN meli_accounts a ON a.id=j.meli_account_id
             WHERE a.id IS NULL OR a.company_id<>j.company_id'
        )->fetchColumn();
        if ($crossAccount > 0) {
            $reasons[] = 'cross_account_state';
        }
        $rateLimited = (int) $this->pdo->query(
            'SELECT COUNT(*) FROM queue_core_attempts
             WHERE http_status=429 AND started_at>=DATE_SUB(UTC_TIMESTAMP(3),INTERVAL 1 HOUR)'
        )->fetchColumn();
        if ($rateLimited > 0) {
            $reasons[] = 'sustained_429';
        }

        $safety = (new EmergencyControlService())->status();
        $engine = (new QueueEngineControlService($this->pdo))->snapshot();
        if ($engine['active_engine'] === 'v4' && !Env::bool('CRON_V4_ENABLED', false)) {
            $reasons[] = 'engine_enabled_launcher_disabled';
        }
        if ($engine['active_engine'] === 'v4' && Env::bool('CRON_V3_ENABLED', false)) {
            $reasons[] = 'engine_ownership_inconsistent';
        }
        if (Env::bool('ML_WRITE_ENABLED', false)) {
            $reasons[] = 'ml_write_enabled';
        }
        $reasons = array_values(array_unique($reasons));
        $red = array_intersect($reasons, [
            'engine_ownership_inconsistent', 'ml_write_enabled', 'remote_uncertain_present',
            'identity_mismatch', 'rotated_credential_unrecoverable', 'schema_inconsistent',
            'cross_account_state',
        ]);
        $state = $red !== [] ? 'RED' : ($reasons !== [] ? 'DEGRADED' : 'GREEN');

        $last = $this->pdo->query(
            'SELECT MAX(response_known_at) last_response_known_at,
                    MAX(CASE WHEN resources_persisted>0 THEN finished_at END) last_resource_persisted_at,
                    MAX(source_closed_at) last_source_closed_at
             FROM queue_core_attempts'
        )->fetch(PDO::FETCH_ASSOC) ?: [];

        return [
            'health' => $state,
            'reasons' => $reasons,
            'engine' => $engine,
            'safety' => [
                'api' => (string) ($safety['api'] ?? 'unknown'),
                'automation' => (string) ($safety['automation'] ?? 'unknown'),
                'ml_write_enabled' => Env::bool('ML_WRITE_ENABLED', false),
            ],
            'depth' => $depth,
            'accounts' => $checkpoint,
            'last_http_response_known_at' => $last['last_response_known_at'] ?: null,
            'last_resource_persisted_at' => $last['last_resource_persisted_at'] ?: null,
            'last_source_closed_at' => $last['last_source_closed_at'] ?: null,
            'generated_at' => gmdate(DATE_ATOM),
            'read_only' => true,
        ];
    }
}
