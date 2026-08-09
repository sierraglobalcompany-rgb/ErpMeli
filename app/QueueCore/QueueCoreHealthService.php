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

    /** @return array<string,mixed> */
    public function persistSnapshot(): array
    {
        $snapshot = $this->snapshot();
        $depth = is_array($snapshot['depth'] ?? null) ? $snapshot['depth'] : [];
        $eligible = (int) ($depth['pending']['total'] ?? 0)
            + (int) ($depth['retry_wait']['total'] ?? 0);
        $oldest = [];
        foreach (['pending', 'retry_wait'] as $state) {
            $value = $depth[$state]['oldest_seconds'] ?? null;
            if ($value !== null) {
                $oldest[] = max(0, (int) $value);
            }
        }
        $lags = [];
        foreach ((array) ($snapshot['accounts'] ?? []) as $account) {
            if (is_array($account) && ($account['freshness_lag_seconds'] ?? null) !== null) {
                $lags[] = max(0, (int) $account['freshness_lag_seconds']);
            }
        }
        $engine = is_array($snapshot['engine'] ?? null) ? $snapshot['engine'] : [];
        $reasons = json_encode(
            array_values(array_map('strval', (array) ($snapshot['reasons'] ?? []))),
            JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        );
        $insert = $this->pdo->prepare(
            'INSERT INTO queue_core_health_snapshots
             (engine_generation,health_state,account_count,eligible_depth,waiting_oauth,
              waiting_dependency,review_depth,dead_depth,oldest_eligible_seconds,
              freshness_lag_seconds,reasons_json)
             VALUES (?,?,?,?,?,?,?,?,?,?,?)'
        );
        $insert->execute([
            max(0, (int) ($engine['generation'] ?? 0)),
            (string) ($snapshot['health'] ?? 'RED'),
            count((array) ($snapshot['accounts'] ?? [])),
            $eligible,
            (int) ($depth['waiting_oauth']['total'] ?? 0),
            $this->pendingDependencyCount(),
            (int) ($depth['review']['total'] ?? 0),
            (int) ($depth['dead']['total'] ?? 0),
            $oldest === [] ? null : max($oldest),
            $lags === [] ? null : max($lags),
            $reasons,
        ]);
        if ($insert->rowCount() !== 1) {
            throw new \RuntimeException('Queue Core health snapshot was not persisted.');
        }
        return [
            'id' => (int) $this->pdo->lastInsertId(),
            'health' => (string) ($snapshot['health'] ?? 'RED'),
            'eligible_depth' => $eligible,
            'generated_at' => (string) ($snapshot['generated_at'] ?? gmdate(DATE_ATOM)),
        ];
    }

    /** @return array<string,mixed>|null */
    public function latestPersisted(): ?array
    {
        $row = $this->pdo->query(
            'SELECT id,engine_generation,health_state,account_count,eligible_depth,
                    waiting_oauth,waiting_dependency,review_depth,dead_depth,
                    oldest_eligible_seconds,freshness_lag_seconds,reasons_json,generated_at
             FROM queue_core_health_snapshots ORDER BY id DESC LIMIT 1'
        )->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return null;
        }
        $row['reasons'] = json_decode((string) $row['reasons_json'], true) ?: [];
        unset($row['reasons_json']);
        $row['read_only'] = true;
        return $row;
    }

    private function pendingDependencyCount(): int
    {
        try {
            return max(0, (int) $this->pdo->query(
                "SELECT COUNT(*) FROM queue_core_capability_dependencies
                 WHERE state IN ('pending','waiting_dependency')"
            )->fetchColumn());
        } catch (Throwable) {
            return 0;
        }
    }
}
