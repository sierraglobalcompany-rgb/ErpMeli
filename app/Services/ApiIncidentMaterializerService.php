<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use PDO;

final class ApiIncidentMaterializerService
{
    /** @return array{processed:int,last_log_id:int,complete:bool,reason?:string} */
    public function refreshStep(int $limit = 500): array
    {
        $schema = new SchemaInspectorService();
        if (!$schema->hasTable('api_incident_groups') || !$schema->hasTable('api_incident_materializer_state')) {
            return ['processed' => 0, 'last_log_id' => 0, 'complete' => true];
        }
        foreach (['incident_key','scope_kind','company_id','outcome_class','reached_remote','actionable','risk_signal'] as $column) {
            if (!$schema->hasColumn('api_request_logs', $column)) {
                return ['processed' => 0, 'last_log_id' => 0, 'complete' => false, 'reason' => 'source_schema_pending'];
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
                        l.reached_remote,l.actionable,l.risk_signal,l.created_at,
                        IF((l.meli_account_id IS NULL OR a.id IS NOT NULL)
                           AND (l.company_id IS NULL OR c.id IS NOT NULL),1,0) tenant_valid
                 FROM api_request_logs l
                 LEFT JOIN meli_accounts a
                   ON a.company_id=l.company_id AND a.id=l.meli_account_id
                 LEFT JOIN companies c ON c.id=l.company_id
                 WHERE l.id>' . $cursor . '
                 ORDER BY l.id LIMIT ' . $limit
            )->fetchAll(PDO::FETCH_ASSOC);
            $upsert = $pdo->prepare(
                'INSERT INTO api_incident_groups
                 (incident_key,scope_key,scope_kind,company_id,meli_account_id,outcome_class,method,
                  endpoint_path,http_status,error_type,error_code,safe_message,diagnostic_id,
                  reached_remote,actionable,risk_signal,first_seen_at,last_seen_at,repetitions,last_log_id)
                 VALUES (:incident_key,:scope_key,:scope_kind,:company_id,:account_id,:outcome,:method,
                         :endpoint,:http_status,:error_type,:error_code,:safe_message,:diagnostic_id,
                         :reached_remote,:actionable,:risk_signal,:created_at,:created_at,1,:log_id)
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
            foreach ($rows as $row) {
                $last = max($last, (int) $row['id']);
                $incidentKey = trim((string) ($row['incident_key'] ?? ''));
                $outcome = trim((string) ($row['outcome_class'] ?? ''));
                if ((int) ($row['tenant_valid'] ?? 0) !== 1
                    || $incidentKey === '' || in_array($outcome, ['success', 'expected_absence'], true)) {
                    continue;
                }
                $scopeKind = in_array((string) ($row['scope_kind'] ?? ''), ['application','company','account'], true)
                    ? (string) $row['scope_kind'] : ((int) ($row['meli_account_id'] ?? 0) > 0 ? 'account' : 'application');
                $scopeKey = $scopeKind === 'account' ? 'account:' . (int) $row['meli_account_id']
                    : ($scopeKind === 'company' ? 'company:' . (int) $row['company_id'] : 'application');
                $upsert->execute([
                    'incident_key' => self::clip($incidentKey, 64) ?? '',
                    'scope_key' => $scopeKey,
                    'scope_kind' => $scopeKind,
                    'company_id' => $row['company_id'],
                    'account_id' => $row['meli_account_id'],
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
                    'created_at' => $row['created_at'],
                    'log_id' => (int) $row['id'],
                ]);
                $processed++;
            }
            $pdo->prepare(
                'UPDATE api_incident_materializer_state
                 SET last_log_id=:cursor,heartbeat_at=UTC_TIMESTAMP(3) WHERE singleton_id=1'
            )->execute(['cursor' => $last]);
            $pdo->commit();
            return ['processed' => $processed, 'last_log_id' => $last, 'complete' => count($rows) < $limit];
        } catch (\Throwable $error) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $error;
        }
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
}
