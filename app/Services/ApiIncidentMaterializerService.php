<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use PDO;
use PDOStatement;
use Throwable;

final class ApiIncidentMaterializerService
{
    /** @var array<string,true> */
    private array $rejectionFingerprints = [];

    /** @return array{processed:int,rejected:int,last_log_id:int,complete:bool,reason?:string} */
    public function refreshStep(int $limit = 500): array
    {
        $schema = new SchemaInspectorService();
        if (!$schema->hasTable('api_incident_groups') || !$schema->hasTable('api_incident_materializer_state')) {
            return ['processed' => 0, 'rejected' => 0, 'last_log_id' => 0, 'complete' => true];
        }
        foreach (['incident_key','scope_kind','company_id','outcome_class','reached_remote','actionable','risk_signal'] as $column) {
            if (!$schema->hasColumn('api_request_logs', $column)) {
                return ['processed' => 0, 'rejected' => 0, 'last_log_id' => 0, 'complete' => false, 'reason' => 'source_schema_pending'];
            }
        }
        $pdo = Database::connection();
        $limit = max(1, min(500, $limit));
        $pdo->beginTransaction();
        try {
            $cursor = (int) $pdo->query(
                'SELECT last_log_id FROM api_incident_materializer_state WHERE singleton_id=1 FOR UPDATE'
            )->fetchColumn();
            $rows = $pdo->query(
                'SELECT l.id,l.incident_key,l.scope_kind,l.company_id,l.meli_account_id,l.outcome_class,l.method,
                        l.endpoint_path,l.http_status,l.error_type,l.error_code,l.safe_message,l.diagnostic_id,
                        l.reached_remote,l.actionable,l.risk_signal,l.created_at,a.company_id account_company_id,
                        IF(
                            (l.meli_account_id IS NOT NULL AND a.id IS NOT NULL)
                            OR (l.meli_account_id IS NULL AND (l.company_id IS NULL OR c.id IS NOT NULL)),
                            1,
                            0
                        ) tenant_valid
                 FROM api_request_logs l
                 LEFT JOIN meli_accounts a
                   ON a.id=l.meli_account_id
                  AND (l.company_id IS NULL OR a.company_id=l.company_id)
                 LEFT JOIN companies c ON c.id=COALESCE(l.company_id,a.company_id)
                 WHERE l.id>' . $cursor . '
                 ORDER BY l.id LIMIT ' . $limit
            )->fetchAll(PDO::FETCH_ASSOC);
            $reconcilesCurrentRemote429Gap = false;
            if ($rows === []) {
                $latest = (int) $pdo->query('SELECT COALESCE(MAX(id),0) FROM api_request_logs')->fetchColumn();
                if ($cursor >= $latest && $latest > 0) {
                    $scopeKeySql = 'CASE
                        WHEN l.meli_account_id IS NOT NULL THEN CONCAT("account:",l.meli_account_id)
                        WHEN l.scope_kind="company" AND COALESCE(l.company_id,a.company_id) IS NOT NULL THEN CONCAT("company:",COALESCE(l.company_id,a.company_id))
                        ELSE "application" END';
                    $rows = $pdo->query(
                        'SELECT l.id,l.incident_key,l.scope_kind,l.company_id,l.meli_account_id,l.outcome_class,l.method,
                                l.endpoint_path,l.http_status,l.error_type,l.error_code,l.safe_message,l.diagnostic_id,
                                l.reached_remote,l.actionable,l.risk_signal,l.created_at,a.company_id account_company_id,
                                IF(
                                    (l.meli_account_id IS NOT NULL AND a.id IS NOT NULL)
                                    OR (l.meli_account_id IS NULL AND (l.company_id IS NULL OR c.id IS NOT NULL)),
                                    1,
                                    0
                                ) tenant_valid
                         FROM api_request_logs l
                         LEFT JOIN meli_accounts a
                           ON a.id=l.meli_account_id
                          AND (l.company_id IS NULL OR a.company_id=l.company_id)
                         LEFT JOIN companies c ON c.id=COALESCE(l.company_id,a.company_id)
                         WHERE l.created_at>=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 720 HOUR)
                           AND l.incident_key IS NOT NULL
                           AND l.outcome_class="remote_error"
                           AND l.reached_remote=1
                           AND l.http_status=429
                           AND NOT EXISTS (
                               SELECT 1 FROM api_incident_groups g
                               WHERE g.incident_key=l.incident_key
                                 AND g.scope_key=' . $scopeKeySql . '
                                 AND g.outcome_class=l.outcome_class
                                 AND g.method=l.method
                           )
                         ORDER BY l.id LIMIT ' . $limit
                    )->fetchAll(PDO::FETCH_ASSOC);
                    $reconcilesCurrentRemote429Gap = $rows !== [];
                }
            }
            $upsert = $pdo->prepare(
                'INSERT INTO api_incident_groups
                 (incident_key,scope_key,scope_kind,company_id,meli_account_id,outcome_class,method,
                  endpoint_path,http_status,error_type,error_code,safe_message,diagnostic_id,
                  reached_remote,actionable,risk_signal,first_seen_at,last_seen_at,repetitions,last_log_id)
                 VALUES (:incident_key,:scope_key,:scope_kind,:company_id,:account_id,:outcome,:method,
                          :endpoint,:http_status,:error_type,:error_code,:safe_message,:diagnostic_id,
                          :reached_remote,:actionable,:risk_signal,:first_seen_at,:last_seen_at,1,:log_id)
                 ON DUPLICATE KEY UPDATE
                    repetitions=repetitions+1,first_seen_at=LEAST(first_seen_at,VALUES(first_seen_at)),
                    endpoint_path=IF(VALUES(last_log_id)>last_log_id,VALUES(endpoint_path),endpoint_path),
                    http_status=IF(VALUES(last_log_id)>last_log_id,VALUES(http_status),http_status),
                    error_type=IF(VALUES(last_log_id)>last_log_id,VALUES(error_type),error_type),
                    error_code=IF(VALUES(last_log_id)>last_log_id,VALUES(error_code),error_code),
                    safe_message=IF(VALUES(last_log_id)>last_log_id,VALUES(safe_message),safe_message),
                    diagnostic_id=IF(VALUES(last_log_id)>last_log_id,VALUES(diagnostic_id),diagnostic_id),
                    reached_remote=IF(VALUES(last_log_id)>last_log_id,VALUES(reached_remote),reached_remote),
                    actionable=GREATEST(actionable,VALUES(actionable)),risk_signal=GREATEST(risk_signal,VALUES(risk_signal)),
                    last_seen_at=GREATEST(last_seen_at,VALUES(last_seen_at)),last_log_id=GREATEST(last_log_id,VALUES(last_log_id))'
            );
            $last = $cursor;
            $processed = 0;
            $rejected = 0;
            foreach ($rows as $row) {
                $id = (int) $row['id'];
                $pdo->exec('SAVEPOINT api_incident_row');
                try {
                    if ($this->materializeIncidentRow($upsert, $row)) {
                        $processed++;
                    }
                    $pdo->exec('RELEASE SAVEPOINT api_incident_row');
                } catch (Throwable $error) {
                    $pdo->exec('ROLLBACK TO SAVEPOINT api_incident_row');
                    $pdo->exec('RELEASE SAVEPOINT api_incident_row');
                    $this->recordRejectedRow($pdo, $id, $error);
                    $rejected++;
                }
                // Advancing is explicit: every source row was either materialized,
                // intentionally non-incident, or rejected with an auditable warning.
                // Gap reconciliation rebuilds missing groups from already-seen
                // logs, so it must not change the materializer cursor.
                if (!$reconcilesCurrentRemote429Gap) {
                    $last = max($last, $id);
                }
            }
            $remaining = max(0, $limit - count($rows));
            if ($remaining > 0) {
                $reconciled = $this->reconcileRecentRemote429Gaps($pdo, $upsert, $remaining);
                $processed += $reconciled['processed'];
                $rejected += $reconciled['rejected'];
            }
            $pdo->prepare(
                'UPDATE api_incident_materializer_state
                 SET last_log_id=:cursor,heartbeat_at=UTC_TIMESTAMP(3) WHERE singleton_id=1'
            )->execute(['cursor' => $last]);
            $pdo->commit();
            return ['processed' => $processed, 'rejected' => $rejected, 'last_log_id' => $last, 'complete' => count($rows) < $limit];
        } catch (Throwable $error) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $error;
        }
    }

    /**
     * Read-only operator evidence for a stalled incident catalogue.
     * It intentionally omits endpoint, account and payload values.
     *
     * @return array{available:bool,last_log_id:int,latest_log_id:int,lag:int,next:array<string,mixed>|null}
     */
    public function diagnose(?PDO $pdo = null): array
    {
        $schema = new SchemaInspectorService();
        if (!$schema->hasTable('api_incident_materializer_state') || !$schema->hasTable('api_request_logs')) {
            return ['available' => false, 'last_log_id' => 0, 'latest_log_id' => 0, 'lag' => 0, 'next' => null];
        }
        $pdo ??= Database::connectionFresh();
        $cursor = (int) $pdo->query(
            'SELECT last_log_id FROM api_incident_materializer_state WHERE singleton_id=1'
        )->fetchColumn();
        $latest = (int) $pdo->query('SELECT COALESCE(MAX(id),0) FROM api_request_logs')->fetchColumn();
        $statement = $pdo->prepare(
            'SELECT l.id,l.incident_key,l.outcome_class,l.scope_kind,l.company_id,l.meli_account_id,
                    IF(
                        (l.meli_account_id IS NOT NULL AND a.id IS NOT NULL)
                        OR (l.meli_account_id IS NULL AND (l.company_id IS NULL OR c.id IS NOT NULL)),
                        1,
                        0
                    ) tenant_valid
             FROM api_request_logs l
             LEFT JOIN meli_accounts a
               ON a.id=l.meli_account_id
              AND (l.company_id IS NULL OR a.company_id=l.company_id)
             LEFT JOIN companies c ON c.id=COALESCE(l.company_id,a.company_id)
             WHERE l.id>:cursor ORDER BY l.id ASC LIMIT 1'
        );
        $statement->execute(['cursor' => $cursor]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        $next = null;
        if (is_array($row)) {
            $incidentKey = trim((string) ($row['incident_key'] ?? ''));
            $outcome = trim((string) ($row['outcome_class'] ?? ''));
            $next = [
                'source_log_id' => (int) $row['id'],
                'tenant_valid' => (int) ($row['tenant_valid'] ?? 0) === 1,
                'classification' => (int) ($row['tenant_valid'] ?? 0) !== 1
                    ? 'tenant_invalid'
                    : ($incidentKey === '' ? 'non_incident' : (in_array($outcome, ['success', 'expected_absence'], true) ? 'non_incident' : 'candidate')),
                'outcome_class' => self::clip($outcome, 40),
                'scope_kind' => self::clip((string) ($row['scope_kind'] ?? ''), 20),
            ];
        }
        return [
            'available' => true,
            'last_log_id' => $cursor,
            'latest_log_id' => $latest,
            'lag' => max(0, $latest - $cursor),
            'next' => $next,
        ];
    }

    public function retainStep(int $limit = 100, int $days = 90): int
    {
        $schema = new SchemaInspectorService();
        if (!$schema->hasTable('api_incident_groups')) {
            return 0;
        }
        $limit = max(1, min(500, $limit));
        $days = max(30, min(730, $days));
        $statement = Database::connection()->prepare(
            'DELETE FROM api_incident_groups
             WHERE last_seen_at<DATE_SUB(UTC_TIMESTAMP(3),INTERVAL ' . $days . ' DAY)
             ORDER BY last_seen_at,id LIMIT ' . $limit
        );
        $statement->execute();
        return $statement->rowCount();
    }

    /**
     * The read model can be rebuilt from historical telemetry written by
     * earlier releases.  It must never stop permanently because a legacy
     * diagnostic was wider than the browser-facing column.
     */
    private static function clip(mixed $value, int $maximum): ?string
    {
        if ($value === null) {
            return null;
        }
        $text = trim((string) $value);
        return $text === '' ? null : mb_substr($text, 0, $maximum);
    }

    /** @param array<string,mixed> $row */
    private function materializeIncidentRow(PDOStatement $upsert, array $row): bool
    {
        $incidentKey = trim((string) ($row['incident_key'] ?? ''));
        $outcome = trim((string) ($row['outcome_class'] ?? ''));
        if ((int) ($row['tenant_valid'] ?? 0) !== 1
            || $incidentKey === ''
            || in_array($outcome, ['success', 'expected_absence'], true)) {
            return false;
        }
        $accountId = (int) ($row['meli_account_id'] ?? 0);
        $companyId = isset($row['company_id']) && $row['company_id'] !== null
            ? (int) $row['company_id']
            : (isset($row['account_company_id']) && $row['account_company_id'] !== null ? (int) $row['account_company_id'] : null);
        $scopeKind = in_array((string) ($row['scope_kind'] ?? ''), ['application','company','account'], true)
            ? (string) $row['scope_kind'] : ($accountId > 0 ? 'account' : 'application');
        if ($accountId > 0) {
            $scopeKind = 'account';
        }
        $scopeKey = $scopeKind === 'account' ? 'account:' . $accountId
            : ($scopeKind === 'company' ? 'company:' . (int) $companyId : 'application');
        $upsert->execute([
            'incident_key' => self::clip($incidentKey, 64) ?? '',
            'scope_key' => $scopeKey,
            'scope_kind' => $scopeKind,
            'company_id' => $companyId,
            'account_id' => $accountId > 0 ? $accountId : null,
            'outcome' => self::clip($outcome, 40) ?? 'local_failure',
            'method' => self::clip((string) ($row['method'] ?? ''), 10) ?? '',
            'endpoint' => self::clip((string) ($row['endpoint_path'] ?? ''), 255) ?? '',
            'http_status' => $row['http_status'],
            'error_type' => self::clip($row['error_type'] ?? null, 80),
            'error_code' => self::clip($row['error_code'] ?? null, 120),
            'safe_message' => self::clip($row['safe_message'] ?? null, 500),
            'diagnostic_id' => self::clip($row['diagnostic_id'] ?? null, 80),
            'reached_remote' => (int) ($row['reached_remote'] ?? 0),
            'actionable' => (int) ($row['actionable'] ?? 0),
            'risk_signal' => (int) ($row['risk_signal'] ?? 0),
            'first_seen_at' => $row['created_at'],
            'last_seen_at' => $row['created_at'],
            'log_id' => (int) $row['id'],
        ]);
        return true;
    }

    /** @return array{processed:int,rejected:int} */
    private function reconcileRecentRemote429Gaps(PDO $pdo, PDOStatement $upsert, int $limit): array
    {
        $limit = max(0, min(100, $limit));
        if ($limit === 0) {
            return ['processed' => 0, 'rejected' => 0];
        }
        $rows = $pdo->query(
            'SELECT l.id,l.incident_key,l.scope_kind,l.company_id,l.meli_account_id,l.outcome_class,l.method,
                    l.endpoint_path,l.http_status,l.error_type,l.error_code,l.safe_message,l.diagnostic_id,
                    l.reached_remote,l.actionable,l.risk_signal,l.created_at,a.company_id account_company_id,
                    IF(
                        (l.meli_account_id IS NOT NULL AND a.id IS NOT NULL)
                        OR (l.meli_account_id IS NULL AND (l.company_id IS NULL OR c.id IS NOT NULL)),
                        1,
                        0
                    ) tenant_valid
             FROM api_request_logs l
             LEFT JOIN meli_accounts a
               ON a.id=l.meli_account_id
              AND (l.company_id IS NULL OR a.company_id=l.company_id)
             LEFT JOIN companies c ON c.id=COALESCE(l.company_id,a.company_id)
             WHERE l.created_at>=DATE_SUB(UTC_TIMESTAMP(3),INTERVAL 720 HOUR)
               AND l.incident_key IS NOT NULL
               AND l.reached_remote=1
               AND l.http_status=429
               AND l.outcome_class NOT IN ("success","expected_absence","policy_delay")
               AND NOT EXISTS (
                   SELECT 1 FROM api_incident_groups g
                   WHERE g.incident_key=l.incident_key
                     AND g.scope_key=CASE
                        WHEN l.meli_account_id IS NOT NULL THEN CONCAT("account:",l.meli_account_id)
                        WHEN l.scope_kind="company" AND COALESCE(l.company_id,a.company_id) IS NOT NULL THEN CONCAT("company:",COALESCE(l.company_id,a.company_id))
                        ELSE "application"
                     END
                     AND g.outcome_class=l.outcome_class
                     AND g.method=l.method
                   LIMIT 1
               )
             ORDER BY l.id ASC LIMIT ' . $limit
        )->fetchAll(PDO::FETCH_ASSOC);
        $processed = 0;
        $rejected = 0;
        foreach ($rows as $row) {
            $id = (int) $row['id'];
            $pdo->exec('SAVEPOINT api_incident_remote_429_gap');
            try {
                if ($this->materializeIncidentRow($upsert, $row)) {
                    $processed++;
                }
                $pdo->exec('RELEASE SAVEPOINT api_incident_remote_429_gap');
            } catch (Throwable $error) {
                $pdo->exec('ROLLBACK TO SAVEPOINT api_incident_remote_429_gap');
                $pdo->exec('RELEASE SAVEPOINT api_incident_remote_429_gap');
                $this->recordRejectedRow($pdo, $id, $error);
                $rejected++;
            }
        }
        return ['processed' => $processed, 'rejected' => $rejected];
    }

    private function recordRejectedRow(PDO $pdo, int $logId, Throwable $error): void
    {
        $fingerprint = substr(hash('sha256', $error::class . '|' . $error->getCode() . '|' . $error->getMessage()), 0, 24);
        if (isset($this->rejectionFingerprints[$fingerprint])) {
            return;
        }
        $this->rejectionFingerprints[$fingerprint] = true;
        $schema = new SchemaInspectorService();
        if (!$schema->hasTable('system_logs')) {
            return;
        }
        $diagnosticId = 'api_incident_materializer_rejected_' . $fingerprint;
        $exists = $pdo->prepare(
            'SELECT 1 FROM system_logs
             WHERE level="warning" AND context_json LIKE :diagnostic
               AND created_at>=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 7 DAY)
             LIMIT 1'
        );
        $exists->execute(['diagnostic' => '%' . $diagnosticId . '%']);
        if ($exists->fetchColumn()) {
            return;
        }
        $insert = $pdo->prepare(
            'INSERT INTO system_logs(level,message,context_json)
             VALUES ("warning",:message,:context)'
        );
        $insert->execute([
            'message' => 'El materializador de incidentes omitió una fila incompatible; el catálogo avanzó con evidencia local.',
            'context' => json_encode([
                'diagnostic_id' => $diagnosticId,
                'source_log_id' => $logId,
                'error_class' => $error::class,
            ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        ]);
    }
}
