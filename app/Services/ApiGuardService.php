<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Auth;
use App\Core\Database;
use PDO;
use RuntimeException;
use Throwable;

final class ApiGuardService
{
    private bool $readAvailable = true;

    public function __construct(private readonly AppSettingsService $settings = new AppSettingsService()) {}

    public function assertAllowed(?int $accountId, string $method, string $path, array $meta = []): void
    {
        // Debe ejecutarse antes de cualquier acceso a base de datos, presupuesto
        // o transporte. La presencia del archivo es la autoridad de emergencia.
        (new MeliEmergencyStopService())->assertAllowed();
        $meta = array_replace($meta, ApiExecutionMetadataContext::current());
        try {
            (new ApiManualPauseService())->assertAllowed($accountId);
        } catch (ApiManualPauseException $pause) {
            $this->recordRequest(
                $accountId,
                null,
                strtoupper($method),
                $path,
                null,
                null,
                null,
                1,
                true,
                $pause->getMessage(),
                ['type' => 'api_manual_pause', 'is_retryable' => true],
                'manual_pause',
                $meta
            );
            throw $pause;
        }
        if (!$this->enabled()) {
            return;
        }
        $stmt = Database::connection()->prepare(
            'SELECT * FROM api_circuit_breakers
             WHERE status="open" AND (meli_account_id=:account OR meli_account_id IS NULL)
               AND (endpoint_path=:path OR endpoint_path="*") AND blocked_until>UTC_TIMESTAMP()
             ORDER BY blocked_until DESC LIMIT 1'
        );
        $stmt->execute(['account' => $accountId, 'path' => $this->normalizePath($path)]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            $message = 'Consultas pausadas por seguridad Mercado Libre hasta ' . $row['blocked_until'] . '. Motivo: ' . $row['reason'];
            $this->recordRequest(
                $accountId,
                null,
                strtoupper($method),
                $path,
                null,
                null,
                null,
                1,
                true,
                $message,
                ['type' => 'api_circuit_open'],
                null,
                $meta
            );
            throw new RuntimeException($message);
        }
    }

    public function recordRequest(?int $accountId, ?string $requestId, string $method, string $path, ?int $status, ?int $durationMs, ?int $retryAfter, int $attempt, bool $blocked, ?string $message, ?array $classification = null, ?string $errorCode = null, array $meta = []): void
    {
        if (!$this->enabled()) {
            return;
        }
        $path = $this->normalizePath($path);
        $safe = (new ApiHealthSafeMessageService())->present($message, $errorCode);
        $message = $safe['safe_message'];
        $errorCode = $safe['normalized_error_code'];
        $outcome = ApiRequestOutcomeClassifier::classify(
            $method,
            $path,
            $status,
            $blocked,
            $message,
            $classification ?? [],
            $errorCode
        );
        $profile = (new MeliOperationProfileRegistry())->resolve($method, $path, $meta);
        $requestScope = $this->requestScope($accountId, $meta);
        try {
            Database::connection()->prepare(
                'INSERT INTO api_request_logs
                 (meli_account_id,company_id,scope_kind,request_id,method,endpoint_path,http_status,duration_ms,retry_after_seconds,attempt,was_blocked,safe_message,diagnostic_id,error_type,error_code,is_retryable,is_app_blocked_signal,outcome_class,reached_remote,actionable,risk_signal,incident_key,execution_source,job_type,source_queue_key,source_work_id,operation_key,load_class,workload_units)
                 VALUES (:account,:company,:scope_kind,:request,:method,:path,:status,:duration,:retry_after,:attempt,:blocked,:message,:diagnostic_id,:error_type,:error_code,:retryable,:app_blocked,:outcome_class,:reached_remote,:actionable,:risk_signal,:incident_key,:execution_source,:job_type,:source_queue_key,:source_work_id,:operation_key,:load_class,:workload_units)'
            )->execute([
                'account' => $accountId ?: null,
                'company' => $requestScope['company_id'],
                'scope_kind' => $requestScope['scope_kind'],
                'request' => $requestId,
                'method' => strtoupper($method),
                'path' => $path,
                'status' => $status,
                'duration' => $durationMs,
                'retry_after' => $retryAfter,
                'attempt' => $attempt,
                'blocked' => $blocked ? 1 : 0,
                'message' => $message ? mb_substr($message, 0, 500) : null,
                'diagnostic_id' => $safe['diagnostic_id'],
                'error_type' => $classification['type'] ?? null,
                'error_code' => $errorCode ? mb_substr($errorCode, 0, 120) : null,
                'retryable' => !empty($classification['is_retryable']) ? 1 : 0,
                'app_blocked' => !empty($classification['is_app_blocked_signal']) ? 1 : 0,
                'outcome_class' => $outcome['outcome_class'],
                'reached_remote' => $outcome['reached_remote'],
                'actionable' => $outcome['actionable'],
                'risk_signal' => $outcome['risk_signal'],
                'incident_key' => $outcome['incident_key'],
                'execution_source' => mb_substr((string) ($meta['source'] ?? ''), 0, 40) ?: null,
                'job_type' => mb_substr((string) ($meta['job_type'] ?? ''), 0, 80) ?: null,
                'source_queue_key' => mb_substr((string) ($meta['source_queue_key'] ?? ''), 0, 80) ?: null,
                'source_work_id' => mb_substr((string) ($meta['source_work_id'] ?? ''), 0, 100) ?: null,
                'operation_key' => (string) $profile['key'],
                'load_class' => (string) $profile['load_class'],
                'workload_units' => (int) $profile['workload_units'],
            ]);
        } catch (Throwable) {
            $this->recordRequestCurrentSchema($accountId, $requestId, $method, $path, $status, $durationMs, $retryAfter, $attempt, $blocked, $message, $classification, $errorCode);
        }
    }

    /** @param array<string,mixed> $meta @return array{company_id:?int,scope_kind:string} */
    private function requestScope(?int $accountId, array $meta): array
    {
        $companyId = max(0, (int) ($meta['company_id'] ?? 0));
        if ($accountId !== null && $accountId > 0) {
            $companyId = $this->accountCompanyId($accountId);
            return ['company_id' => $companyId > 0 ? $companyId : null, 'scope_kind' => 'account'];
        }
        if ($companyId > 0) {
            return ['company_id' => $companyId, 'scope_kind' => 'company'];
        }
        return ['company_id' => null, 'scope_kind' => 'application'];
    }

    /** @param array<string,mixed> $meta */
    public function assertMetadataScope(?int $accountId, array $meta): void
    {
        if ($accountId === null || $accountId < 1) {
            return;
        }
        $actual = $this->accountCompanyId($accountId);
        $declared = max(0, (int) ($meta['company_id'] ?? 0));
        if ($actual < 1 || ($declared > 0 && $declared !== $actual)) {
            throw new \RuntimeException('La cuenta remota no coincide con el alcance empresarial declarado.');
        }
    }

    private function accountCompanyId(int $accountId): int
    {
        static $companies = [];
        if (!array_key_exists($accountId, $companies)) {
            $stmt = Database::connection()->prepare('SELECT company_id FROM meli_accounts WHERE id=? LIMIT 1');
            $stmt->execute([$accountId]);
            $companies[$accountId] = max(0, (int) $stmt->fetchColumn());
        }
        return (int) $companies[$accountId];
    }

    public function afterFailure(?int $accountId, string $method, string $path, int $status, ?int $retryAfter, string $message, array $classification = []): void
    {
        if (!$this->enabled()) {
            return;
        }
        $path = $this->normalizePath($path);
        $type = (string) ($classification['type'] ?? '');

        if (!empty($classification['is_app_blocked_signal']) || $type === 'app_blocked') {
            $messageLower = strtolower($message);
            $cooldownSetting = str_contains($messageLower, 'unauthorized_scopes')
                ? 'api.guard.unauthorized_scopes_global_pause_minutes'
                : 'api.guard.app_blocked_cooldown_minutes';
            $cooldown = max(30, $this->settings->int($cooldownSetting, 1440));
            $this->openCircuit(null, '*', 'app_blocked', $status ?: null, $cooldown, $message, 'app', $type, 1);
            return;
        }

        if (!in_array($status, [400, 401, 403, 429], true) && $status < 500) {
            return;
        }

        $window = max(1, $this->settings->int('api.guard.window_minutes', 10));
        $max = match ($status) {
            400 => max(1, $this->settings->int($type === 'bad_request' ? 'api.guard.max_unknown_400_per_window' : 'api.guard.max_400_per_window', 5)),
            429 => max(1, $this->settings->int('api.guard.max_429_per_window', 3)),
            403 => max(1, $this->settings->int('api.guard.max_403_per_window', 1)),
            401 => max(1, $this->settings->int('api.guard.max_401_per_window', 2)),
            default => max(1, $this->settings->int('api.guard.max_5xx_per_window', 3)),
        };

        $whereAccount = $accountId ? 'meli_account_id=:account' : 'meli_account_id IS NULL';
        $stmt = Database::connection()->prepare(
            'SELECT COUNT(*) FROM api_request_logs
             WHERE ' . $whereAccount . ' AND endpoint_path=:path AND http_status=:status
               AND (:type="" OR error_type=:type)
               AND created_at>=DATE_SUB(UTC_TIMESTAMP(), INTERVAL :window MINUTE)'
        );
        if ($accountId) {
            $stmt->bindValue(':account', $accountId, PDO::PARAM_INT);
        }
        $stmt->bindValue(':path', $path);
        $stmt->bindValue(':status', $status, PDO::PARAM_INT);
        $stmt->bindValue(':type', $type);
        $stmt->bindValue(':window', $window, PDO::PARAM_INT);
        $stmt->execute();
        $failures = (int) $stmt->fetchColumn();
        if ($failures < $max) {
            return;
        }

        $cooldown = max(1, $this->settings->int('api.guard.cooldown_minutes', 15));
        if ($retryAfter && $retryAfter > 0) {
            $cooldown = max($cooldown, (int) ceil($retryAfter / 60));
        }
        $reason = match ($status) {
            400 => 'bad_request_400_repeated',
            429 => 'rate_limit_429',
            403 => $type === 'missing_permission' ? 'missing_permission_403' : 'forbidden_403',
            401 => 'unauthorized_401',
            default => 'server_error_5xx',
        };
        $this->openCircuit($accountId, $path, $reason, $status, $cooldown, $message, 'endpoint', $type, $failures);
    }

    public function openCircuit(?int $accountId, string $path, string $reason, ?int $status, int $cooldownMinutes, ?string $message, string $scope = 'endpoint', ?string $errorType = null, int $failureCount = 1): void
    {
        $path = $this->normalizePath($path);
        $message = (new ApiHealthSafeMessageService())->present($message, $errorType)['safe_message'];
        try {
            Database::connection()->prepare(
                'INSERT INTO api_circuit_breakers (meli_account_id,endpoint_path,reason,http_status,status,blocked_until,last_message,error_type,failure_count,window_started_at,next_retry_at,scope)
                 VALUES (:account,:path,:reason,:status,"open",DATE_ADD(UTC_TIMESTAMP(), INTERVAL :cooldown MINUTE),:message,:error_type,:failure_count,UTC_TIMESTAMP(),DATE_ADD(UTC_TIMESTAMP(), INTERVAL :cooldown MINUTE),:scope)
                 ON DUPLICATE KEY UPDATE status="open",reason=VALUES(reason),http_status=VALUES(http_status),blocked_until=VALUES(blocked_until),last_message=VALUES(last_message),error_type=VALUES(error_type),failure_count=VALUES(failure_count),next_retry_at=VALUES(next_retry_at),scope=VALUES(scope),closed_at=NULL,updated_at=UTC_TIMESTAMP()'
            )->execute([
                'account' => $accountId ?: null,
                'path' => $path,
                'reason' => mb_substr($reason, 0, 120),
                'status' => $status,
                'cooldown' => max(1, $cooldownMinutes),
                'message' => $message ? mb_substr($message, 0, 500) : null,
                'error_type' => $errorType ? mb_substr($errorType, 0, 80) : null,
                'failure_count' => max(1, $failureCount),
                'scope' => in_array($scope, ['endpoint', 'account', 'app', 'module', 'job_type'], true) ? $scope : 'endpoint',
            ]);
        } catch (Throwable $e) {
            $this->openCircuitLegacy($accountId, $path, $reason, $status, $cooldownMinutes, $message, $e);
        }
        (new ReadModelCacheService())->clear();
        Logger::write('warning', 'Circuit breaker Mercado Libre abierto.', ['account_id' => $accountId, 'endpoint' => $path, 'reason' => $reason, 'http_status' => $status]);
    }

    public function closeExpired(): int
    {
        return $this->closeExpiredForAccounts(null);
    }

    /** @param list<int>|null $accountIds */
    private function closeExpiredForAccounts(?array $accountIds): int
    {
        try {
            $accountIds = $accountIds === null
                ? null
                : array_values(array_unique(array_filter(array_map('intval', $accountIds), static fn (int $id): bool => $id > 0)));
            if ($accountIds === []) {
                return 0;
            }
            $scope = $accountIds === null
                ? ''
                : ' AND meli_account_id IN (' . implode(',', array_fill(0, count($accountIds), '?')) . ')';
            $stmt = Database::connection()->prepare(
                'UPDATE api_circuit_breakers SET status="closed",closed_at=UTC_TIMESTAMP()
                 WHERE status="open" AND blocked_until<=UTC_TIMESTAMP()' . $scope
            );
            $stmt->execute($accountIds ?? []);
            $closed = $stmt->rowCount();
            if ($closed > 0) {
                (new ReadModelCacheService())->clear();
            }
            return $closed;
        } catch (Throwable) {
            return 0;
        }
    }

    public function closeCircuit(int $id, string $reason = 'manual_close'): bool
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare('SELECT * FROM api_circuit_breakers WHERE id=:id LIMIT 1');
        $stmt->execute(['id' => $id]);
        $before = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$before) {
            return false;
        }
        try {
            $pdo->prepare('UPDATE api_circuit_breakers SET status="closed",closed_at=UTC_TIMESTAMP(),manually_closed_by=:user,manually_closed_at=UTC_TIMESTAMP(),last_message=:reason WHERE id=:id')
                ->execute(['user' => Auth::id(), 'reason' => mb_substr($reason, 0, 500), 'id' => $id]);
        } catch (Throwable) {
            $pdo->prepare('UPDATE api_circuit_breakers SET status="closed",closed_at=UTC_TIMESTAMP(),last_message=:reason WHERE id=:id')
                ->execute(['reason' => mb_substr($reason, 0, 500), 'id' => $id]);
        }
        $this->recordAdminAction('close_circuit', (int) ($before['meli_account_id'] ?? 0) ?: null, $id, $reason, $before);
        return true;
    }

    public function pauseAccount(int $accountId, int $cooldownMinutes, string $reason): void
    {
        $this->openCircuit($accountId, '*', 'manual_account_pause', null, max(1, $cooldownMinutes), $reason, 'account', 'manual_pause', 1);
        $this->recordAdminAction('pause_account', $accountId, null, $reason, ['cooldown_minutes' => $cooldownMinutes]);
    }

    public function reactivateAccount(int $accountId, string $reason): int
    {
        $stmt = Database::connection()->prepare('UPDATE api_circuit_breakers SET status="closed",closed_at=UTC_TIMESTAMP(),last_message=:reason WHERE meli_account_id=:account AND status="open"');
        $stmt->execute(['reason' => mb_substr($reason, 0, 500), 'account' => $accountId]);
        $count = $stmt->rowCount();
        $this->recordAdminAction('reactivate_account', $accountId, null, $reason, ['closed_circuits' => $count]);
        return $count;
    }

    public function recordAdminAction(string $action, ?int $accountId, ?int $circuitId, string $reason, array $payload = []): void
    {
        try {
            Database::connection()->prepare(
                'INSERT INTO api_guard_admin_actions (user_id,action,meli_account_id,api_circuit_breaker_id,reason,payload_json,ip_hash)
                 VALUES (:user,:action,:account,:circuit,:reason,:payload,:ip)'
            )->execute([
                'user' => Auth::id(),
                'action' => $action,
                'account' => $accountId,
                'circuit' => $circuitId,
                'reason' => mb_substr($reason, 0, 500),
                'payload' => json_encode(Logger::redact($payload), JSON_UNESCAPED_UNICODE),
                'ip' => hash('sha256', (string) ($_SERVER['REMOTE_ADDR'] ?? 'cli')),
            ]);
        } catch (Throwable) {
            // La migración puede estar pendiente.
        }
        AuditService::record($action, 'api_guard', $circuitId ? 'api_circuit_breaker' : 'meli_account', $circuitId ?: $accountId, $accountId, null, ['reason' => $reason]);
    }

    /** @param list<int>|null $accountIds */
    public function openCircuits(int $limit = 50, ?array $accountIds = null, bool $includeApplication = false): array
    {
        try {
            $accountIds = $accountIds === null
                ? null
                : array_values(array_unique(array_filter(array_map('intval', $accountIds), static fn (int $id): bool => $id > 0)));
            if ($accountIds === [] && !$includeApplication) {
                return [];
            }
            $scope = '';
            if ($accountIds !== null) {
                $accountPredicate = $accountIds === []
                    ? '1=0'
                    : 'b.meli_account_id IN (' . implode(',', array_fill(0, count($accountIds), '?')) . ')';
                $scope = ' AND (' . $accountPredicate . ($includeApplication ? ' OR b.meli_account_id IS NULL' : '') . ')';
            }
            $stmt = Database::connection()->prepare(
                'SELECT b.*,a.account_name
                 FROM api_circuit_breakers b
                 LEFT JOIN meli_accounts a ON a.id=b.meli_account_id
                 WHERE b.status="open"
                   AND (b.blocked_until IS NULL OR b.blocked_until>UTC_TIMESTAMP())' . $scope . '
                 ORDER BY b.blocked_until DESC LIMIT ?'
            );
            $position = 1;
            foreach ($accountIds ?? [] as $accountId) {
                $stmt->bindValue($position++, $accountId, PDO::PARAM_INT);
            }
            $stmt->bindValue($position, max(1, min(500, $limit)), PDO::PARAM_INT);
            $stmt->execute();
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable) {
            $this->readAvailable = false;
            return [];
        }
    }

    /**
     * Permite que Salud API distinga "sin circuitos" de "no se pudo leer".
     * No expone la excepción ni detalles de conexión.
     */
    public function readAvailable(): bool
    {
        return $this->readAvailable;
    }

    public function recentRequestStats(int $hours = 24): array
    {
        try {
            $stmt = Database::connection()->prepare(
                'SELECT endpoint_path,meli_account_id,method,http_status,error_type,COUNT(*) total,MAX(created_at) last_seen_at
                 FROM api_request_logs
                 WHERE created_at>=DATE_SUB(UTC_TIMESTAMP(), INTERVAL :hours HOUR)
                 GROUP BY endpoint_path,meli_account_id,method,http_status,error_type
                 ORDER BY total DESC,last_seen_at DESC
                 LIMIT 200'
            );
            $stmt->bindValue(':hours', max(1, $hours), PDO::PARAM_INT);
            $stmt->execute();
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable) {
            return [];
        }
    }

    public function retryDelaySeconds(int $attempt, int $status, ?int $retryAfter): int
    {
        if ($retryAfter && $retryAfter > 0) {
            return min(300, max(1, $retryAfter));
        }
        $base = $status === 429 ? 2 : 1;
        $delay = min(120, $base * (2 ** max(0, $attempt - 1)));
        $min = max(0, $this->settings->int('api.guard.jitter_min_ms', 250));
        $max = max($min, $this->settings->int('api.guard.jitter_max_ms', 1500));
        $jitterMs = random_int($min, $max);
        return max(1, (int) ceil($delay + ($jitterMs / 1000)));
    }

    public function maxAttempts(bool $mutation, string $method): int
    {
        if ($mutation || strtoupper($method) !== 'GET') {
            return 1;
        }
        return max(1, min(5, $this->settings->int('api.guard.max_retry_attempts', 3)));
    }

    private function enabled(): bool
    {
        return $this->settings->bool('api.guard.enabled', true);
    }

    private function normalizePath(string $path): string
    {
        if ($path === '*') {
            return '*';
        }
        $parsed = parse_url($path, PHP_URL_PATH);
        return $parsed ? '/' . ltrim($parsed, '/') : '/' . ltrim($path, '/');
    }

    /**
     * Fallback temporal mientras la migración 085 todavía no está aplicada.
     *
     * @param array<string,mixed>|null $classification
     */
    private function recordRequestCurrentSchema(?int $accountId, ?string $requestId, string $method, string $path, ?int $status, ?int $durationMs, ?int $retryAfter, int $attempt, bool $blocked, ?string $message, ?array $classification, ?string $errorCode): void
    {
        try {
            Database::connection()->prepare(
                'INSERT INTO api_request_logs
                 (meli_account_id,request_id,method,endpoint_path,http_status,duration_ms,retry_after_seconds,attempt,was_blocked,safe_message,error_type,error_code,is_retryable,is_app_blocked_signal)
                 VALUES (:account,:request,:method,:path,:status,:duration,:retry_after,:attempt,:blocked,:message,:error_type,:error_code,:retryable,:app_blocked)'
            )->execute([
                'account' => $accountId ?: null,
                'request' => $requestId,
                'method' => strtoupper($method),
                'path' => $path,
                'status' => $status,
                'duration' => $durationMs,
                'retry_after' => $retryAfter,
                'attempt' => $attempt,
                'blocked' => $blocked ? 1 : 0,
                'message' => $message ? mb_substr($message, 0, 500) : null,
                'error_type' => $classification['type'] ?? null,
                'error_code' => $errorCode ? mb_substr($errorCode, 0, 120) : null,
                'retryable' => !empty($classification['is_retryable']) ? 1 : 0,
                'app_blocked' => !empty($classification['is_app_blocked_signal']) ? 1 : 0,
            ]);
        } catch (Throwable) {
            $this->recordRequestLegacy($accountId, $requestId, $method, $path, $status, $durationMs, $retryAfter, $attempt, $blocked, $message);
        }
    }

    private function recordRequestLegacy(?int $accountId, ?string $requestId, string $method, string $path, ?int $status, ?int $durationMs, ?int $retryAfter, int $attempt, bool $blocked, ?string $message): void
    {
        try {
            Database::connection()->prepare(
                'INSERT INTO api_request_logs
                 (meli_account_id,request_id,method,endpoint_path,http_status,duration_ms,retry_after_seconds,attempt,was_blocked,safe_message)
                 VALUES (:account,:request,:method,:path,:status,:duration,:retry_after,:attempt,:blocked,:message)'
            )->execute([
                'account' => $accountId ?: null,
                'request' => $requestId,
                'method' => strtoupper($method),
                'path' => $path,
                'status' => $status,
                'duration' => $durationMs,
                'retry_after' => $retryAfter,
                'attempt' => $attempt,
                'blocked' => $blocked ? 1 : 0,
                'message' => $message ? mb_substr($message, 0, 500) : null,
            ]);
        } catch (Throwable) {
            // No hacer fallar la llamada si el log auxiliar falla.
        }
    }

    private function openCircuitLegacy(?int $accountId, string $path, string $reason, ?int $status, int $cooldownMinutes, ?string $message, Throwable $original): void
    {
        try {
            Database::connection()->prepare(
                'INSERT INTO api_circuit_breakers (meli_account_id,endpoint_path,reason,http_status,status,blocked_until,last_message)
                 VALUES (:account,:path,:reason,:status,"open",DATE_ADD(UTC_TIMESTAMP(), INTERVAL :cooldown MINUTE),:message)
                 ON DUPLICATE KEY UPDATE status="open",reason=VALUES(reason),http_status=VALUES(http_status),blocked_until=VALUES(blocked_until),last_message=VALUES(last_message),closed_at=NULL,updated_at=UTC_TIMESTAMP()'
            )->execute([
                'account' => $accountId ?: null,
                'path' => $path,
                'reason' => mb_substr($reason, 0, 120),
                'status' => $status,
                'cooldown' => max(1, $cooldownMinutes),
                'message' => $message ? mb_substr($message, 0, 500) : null,
            ]);
        } catch (Throwable $fallback) {
            Logger::write('warning', 'No se pudo abrir circuito API.', ['error' => $fallback->getMessage(), 'original' => $original->getMessage()]);
        }
    }
}
