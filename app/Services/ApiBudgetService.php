<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Auth;
use App\Core\Database;
use DateTimeImmutable;
use PDO;
use Throwable;

final class ApiBudgetService
{
    private bool $readAvailable = true;
    /** @var array<string,int> */
    private static array $webRequestCounters = [];

    public function __construct(private readonly AppSettingsService $settings = new AppSettingsService()) {}

    /**
     * Reserva presupuesto antes de ejecutar una llamada real a Mercado Libre.
     *
     * @param array<string,mixed> $meta
     * @return array{allowed:bool,job_type:string,source:string,next_safe_at:?string,window_started_at:?string,window_seconds:int,web_counter_reserved:bool,scopes:list<array{scope:string,scope_key:string,limit:int,count:int,cooldown_until:?string}>}
     */
    public function reserve(?int $accountId, string $method, string $path, array $meta = []): array
    {
        $jobType = $this->jobType($path, $meta);
        $source = $this->source($meta);

        if (!$this->enabled()) {
            return ['allowed' => true, 'job_type' => $jobType, 'source' => $source, 'next_safe_at' => null, 'window_started_at' => null, 'window_seconds' => 0, 'web_counter_reserved' => false, 'scopes' => []];
        }
        if (!$this->schemaReady()) {
            throw new ApiBudgetInfrastructureException(
                'El presupuesto API está habilitado, pero su esquema no está instalado. La salida remota fue bloqueada.'
            );
        }

        $webCounterReserved = false;
        if ($source === 'web') {
            $webLimit = max(1, $this->settings->int('api.budget.web_request_api_limit', 10));
            if (array_sum(self::$webRequestCounters) + 1 > $webLimit) {
                $message = 'Presupuesto API ML agotado para esta petición web. El trabajo debe continuar por cola/cron para evitar bloqueo.';
                $this->recordEstimate($accountId, $jobType, 'web_request', 1, 0, 'blocked', $message);
                throw new ApiBudgetExhaustedException($message);
            }
            self::$webRequestCounters[$jobType] = (self::$webRequestCounters[$jobType] ?? 0) + 1;
            $webCounterReserved = true;
        }

        $windowSeconds = $this->windowSeconds();
        $windowStart = $this->windowStart($windowSeconds);
        $endpointPath = $this->normalizeEndpoint($path, $method);
        $scopes = $this->scopes($accountId, $endpointPath, $jobType, $windowSeconds);
        $pdo = Database::connection();
        $ownsTransaction = false;

        try {
            if (!$pdo->inTransaction()) {
                $pdo->beginTransaction();
                $ownsTransaction = true;
            }
            $orderedScopes = $scopes;
            usort(
                $orderedScopes,
                static fn (array $left, array $right): int => strcmp($left['scope_key'], $right['scope_key'])
            );
            foreach ($orderedScopes as $scope) {
                $this->ensureWindow(
                    $scope['scope'],
                    $scope['scope_key'],
                    $accountId,
                    $endpointPath,
                    $jobType,
                    $windowStart,
                    $windowSeconds,
                    (int) $scope['limit']
                );
            }
            $lockedRows = [];
            foreach ($orderedScopes as $scope) {
                $lockedRows[$scope['scope_key']] = $this->currentWindowRow(
                    $scope['scope_key'],
                    $windowStart,
                    $windowSeconds
                );
            }

            $blocked = [];
            $now = gmdate('Y-m-d H:i:s');
            foreach ($scopes as &$scope) {
                $row = $lockedRows[$scope['scope_key']] ?? [];
                $count = (int) ($row['request_count'] ?? 0);
                $cooldownUntil = $row['cooldown_until'] ?? null;
                $scope['count'] = $count;
                $scope['cooldown_until'] = $cooldownUntil;
                if ($cooldownUntil && $this->utcTimestamp((string) $cooldownUntil) > time()) {
                    $blocked[] = $scope;
                    continue;
                }
                if ($count >= (int) $scope['limit']) {
                    $blocked[] = $scope;
                }
            }
            unset($scope);

            if ($blocked !== []) {
                if ($ownsTransaction) {
                    $pdo->rollBack();
                }
                $nextSafeAt = $this->nextSafeAt($blocked, $windowStart, $windowSeconds);
                $message = 'Presupuesto API ML agotado. Próxima hora segura aproximada: ' . ($nextSafeAt ?: 'pendiente de cooldown') . '.';
                $this->recordEstimate($accountId, $jobType, $endpointPath, 1, 0, 'blocked', $message);
                throw new ApiBudgetExhaustedException($message, $nextSafeAt);
            }

            foreach ($scopes as $scope) {
                $this->incrementWindow(
                    $scope['scope_key'],
                    $windowStart,
                    $windowSeconds,
                    (int) $scope['limit'],
                    $now
                );
            }
            if ($ownsTransaction) {
                $pdo->commit();
            }
            return [
                'allowed' => true,
                'job_type' => $jobType,
                'source' => $source,
                'next_safe_at' => null,
                'window_started_at' => $windowStart,
                'window_seconds' => $windowSeconds,
                'web_counter_reserved' => $webCounterReserved,
                'scopes' => $scopes,
            ];
        } catch (ApiBudgetExhaustedException $e) {
            if ($ownsTransaction && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $this->releaseWebCounter($jobType, $webCounterReserved);
            throw $e;
        } catch (Throwable $e) {
            if ($ownsTransaction && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $this->releaseWebCounter($jobType, $webCounterReserved);
            Logger::write('warning', 'No se pudo verificar el presupuesto API ML.', [
                'error' => Logger::redactString($e->getMessage()),
            ]);
            throw new ApiBudgetInfrastructureException('No fue posible verificar el presupuesto preventivo de la API.', $e);
        }
    }

    /**
     * Devuelve una reserva cuando el transporte no llegó a iniciarse. Es una
     * compensación exacta y acotada a la misma ventana; nunca incrementa ni
     * elimina ventanas y no altera resultados de llamadas ya despachadas.
     *
     * @param array<string,mixed> $reservation
     */
    public function releaseReservation(array $reservation,bool $strict=false): void
    {
        $windowStart = trim((string) ($reservation['window_started_at'] ?? ''));
        $windowSeconds = (int) ($reservation['window_seconds'] ?? 0);
        $scopes = is_array($reservation['scopes'] ?? null) ? $reservation['scopes'] : [];
        if ($windowStart !== '' && $windowSeconds > 0 && $scopes !== [] && $this->schemaReady()) {
            try {
                $stmt = Database::connection()->prepare(
                    'UPDATE api_budget_windows
                     SET request_count=GREATEST(request_count-1,0),updated_at=UTC_TIMESTAMP()
                     WHERE scope_key=:scope_key
                       AND window_started_at=:window_started_at
                       AND window_seconds=:window_seconds'
                );
                foreach ($scopes as $scope) {
                    $scopeKey = trim((string) ($scope['scope_key'] ?? ''));
                    if ($scopeKey === '') {
                        continue;
                    }
                    $stmt->execute([
                        'scope_key' => $scopeKey,
                        'window_started_at' => $windowStart,
                        'window_seconds' => $windowSeconds,
                    ]);
                    if($strict && $stmt->rowCount()!==1){
                        throw new \RuntimeException('Queue Core budget refund lost an exact scope fence.');
                    }
                }
            } catch (Throwable $error) {
                Logger::write('warning', 'No se pudo devolver una reserva API no despachada.', [
                    'error' => Logger::redactString($error->getMessage()),
                ]);
                if($strict)throw $error;
            }
        }

        if (!empty($reservation['web_counter_reserved'])) {
            $jobType = trim((string) ($reservation['job_type'] ?? ''));
            if ($jobType !== '' && isset(self::$webRequestCounters[$jobType])) {
                self::$webRequestCounters[$jobType] = max(0, self::$webRequestCounters[$jobType] - 1);
            }
        }
    }

    /**
     * @param array<string,mixed> $meta
     * @param array<string,mixed> $classification
     */
    public function recordResult(?int $accountId, string $method, string $path, ?int $status, ?int $retryAfter, array $meta = [], array $classification = []): void
    {
        if (!$this->enabled() || !$this->schemaReady()) {
            return;
        }
        $jobType = $this->jobType($path, $meta);
        $endpointPath = $this->normalizeEndpoint($path, $method);
        $windowSeconds = $this->windowSeconds();
        $windowStart = $this->windowStart($windowSeconds);
        $scopes = $this->scopes($accountId, $endpointPath, $jobType, $windowSeconds);
        $column = null;
        if ($status !== null) {
            if ($status >= 500) {
                $column = 'error_5xx_count';
            } elseif (in_array($status, [400, 401, 403, 429], true)) {
                $column = 'error_' . $status . '_count';
            }
        }
        $cooldownUntil = null;
        // 429/Retry-After pertenece a la autoridad compartida de Rhythm.
        // Budget conserva contadores preventivos, no otro cooldown efectivo.
        if ($status !== 429 && $retryAfter !== null && $retryAfter > 0) {
            $cooldownUntil = gmdate('Y-m-d H:i:s', time() + $retryAfter);
        }
        try {
            foreach ($scopes as $scope) {
                $set = [];
                $params = [
                    'scope_key' => $scope['scope_key'],
                    'window_started_at' => $windowStart,
                    'window_seconds' => $windowSeconds,
                ];
                if ($column !== null) {
                    $set[] = $column . '=' . $column . '+1';
                }
                if ($cooldownUntil !== null) {
                    $set[] = 'cooldown_until=IF(cooldown_until IS NULL OR cooldown_until<:cooldown, :cooldown, cooldown_until)';
                    $params['cooldown'] = $cooldownUntil;
                }
                if ($set === []) {
                    continue;
                }
                Database::connection()->prepare(
                    'UPDATE api_budget_windows SET ' . implode(',', $set) . ', updated_at=UTC_TIMESTAMP()
                     WHERE scope_key=:scope_key AND window_started_at=:window_started_at AND window_seconds=:window_seconds'
                )->execute($params);
            }
        } catch (Throwable) {
            // El presupuesto es una protección auxiliar; no debe ocultar el error real de API.
        }
    }

    /**
     * @return array{allowed:bool,next_safe_at:?string,message:string}
     */
    public function canRunJobType(string $jobType, ?int $accountId = null, int $needed = 1): array
    {
        if (!$this->enabled() || !$this->schemaReady()) {
            return ['allowed' => true, 'next_safe_at' => null, 'message' => 'Presupuesto API no disponible; se usa guardia clásica.'];
        }
        $jobType = $this->normalizeJobType($jobType);
        $windowSeconds = $this->windowSeconds();
        $windowStart = $this->windowStart($windowSeconds);
        $limits = [
            ['scope' => 'app', 'key' => 'app:*', 'limit' => $this->effectiveLimit(
                $this->settings->int('api.budget.global_requests_per_15m', 300),
                $jobType,
                $this->oauthGlobalReserve()
            )],
            ['scope' => 'job_type', 'key' => 'job:' . $jobType, 'limit' => $this->settings->int('api.budget.job_type_requests_per_15m', 80)],
        ];
        if ($accountId !== null && $accountId > 0) {
            $limits[] = ['scope' => 'account', 'key' => 'account:' . $accountId, 'limit' => $this->effectiveLimit(
                $this->settings->int('api.budget.account_requests_per_15m', 120),
                $jobType,
                $this->oauthAccountReserve()
            )];
        }
        $blocked = [];
        foreach ($limits as $limit) {
            $row = $this->currentWindowRow((string) $limit['key'], $windowStart, $windowSeconds);
            $count = (int) ($row['request_count'] ?? 0);
            $cooldown = $row['cooldown_until'] ?? null;
            if (($cooldown && $this->utcTimestamp((string) $cooldown) > time()) || ($count + $needed) > (int) $limit['limit']) {
                $blocked[] = ['scope' => (string) $limit['scope'], 'scope_key' => (string) $limit['key'], 'limit' => (int) $limit['limit'], 'count' => $count, 'cooldown_until' => $cooldown];
            }
        }
        if ($blocked === []) {
            return ['allowed' => true, 'next_safe_at' => null, 'message' => 'Presupuesto disponible.'];
        }
        $next = $this->nextSafeAt($blocked, $windowStart, $windowSeconds);
        return ['allowed' => false, 'next_safe_at' => $next, 'message' => 'Presupuesto API insuficiente. Próxima hora segura: ' . ($next ?: 'pendiente') . '.'];
    }

    /**
     * @return array{available:bool,protocol:string,enabled:bool,window_seconds:int,global_limit:int,account_limit:int,endpoint_limit:int,job_type_limit:int,web_limit:int,windows:list<array<string,mixed>>,next_safe_at:?string}
     */
    public function summary(int $limit = 80, ?array $accountIds = null): array
    {
        $this->readAvailable = true;
        $windowSeconds = $this->windowSeconds();
        $enabled = $this->enabled();
        $rows = [];
        $schemaReady = $this->schemaReady();
        if ($enabled && !$schemaReady) {
            $this->readAvailable = false;
        }
        if ($schemaReady) {
            try {
                $params = [];
                $scope = '';
                if ($accountIds !== null) {
                    $accountIds = array_values(array_unique(array_filter(array_map('intval', $accountIds), static fn (int $id): bool => $id > 0)));
                    if ($accountIds === []) {
                        $scope = ' AND 1=0';
                    } else {
                        $tokens = [];
                        foreach ($accountIds as $index => $id) {
                            $key = 'scope_account_' . $index;
                            $tokens[] = ':' . $key;
                            $params[$key] = $id;
                        }
                        $scope = ' AND w.meli_account_id IN (' . implode(',', $tokens) . ')';
                    }
                }
                $stmt = Database::connection()->prepare(
                    'SELECT w.*,a.account_name
                     FROM api_budget_windows w
                     LEFT JOIN meli_accounts a ON a.id=w.meli_account_id
                     WHERE w.window_started_at>=DATE_SUB(UTC_TIMESTAMP(), INTERVAL 24 HOUR)' . $scope . '
                     ORDER BY w.window_started_at DESC,w.request_count DESC
                     LIMIT :limit'
                );
                foreach ($params as $key => $id) {
                    $stmt->bindValue(':' . $key, $id, PDO::PARAM_INT);
                }
                $stmt->bindValue(':limit', max(1, $limit), PDO::PARAM_INT);
                $stmt->execute();
                $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            } catch (Throwable) {
                $this->readAvailable = false;
                $rows = [];
            }
        }
        $blocked = array_values(array_filter($rows, fn(array $row): bool => !empty($row['cooldown_until']) && $this->utcTimestamp((string) $row['cooldown_until']) > time()));
        $next = null;
        foreach ($blocked as $row) {
            if ($next === null || $this->utcTimestamp((string) $row['cooldown_until']) > $this->utcTimestamp($next)) {
                $next = (string) $row['cooldown_until'];
            }
        }
        return [
            'available' => $this->readAvailable,
            'protocol' => !$this->readAvailable ? 'unavailable' : ($rows === [] ? 'authoritative_empty' : 'complete'),
            'enabled' => $enabled,
            'window_seconds' => $windowSeconds,
            'global_limit' => $this->settings->int('api.budget.global_requests_per_15m', 300),
            'account_limit' => $this->settings->int('api.budget.account_requests_per_15m', 120),
            'endpoint_limit' => $this->settings->int('api.budget.endpoint_requests_per_15m', 50),
            'job_type_limit' => $this->settings->int('api.budget.job_type_requests_per_15m', 80),
            'web_limit' => $this->settings->int('api.budget.web_request_api_limit', 10),
            'windows' => $rows,
            'next_safe_at' => $next,
        ];
    }

    public function readAvailable(): bool
    {
        return $this->readAvailable;
    }

    /** @return array{available:bool,protocol:string,enabled:bool} */
    public function availability(): array
    {
        $enabled = $this->enabled();
        if (!$enabled) {
            return ['available' => true, 'protocol' => 'authoritative_empty', 'enabled' => false];
        }
        if (!$this->schemaReady()) {
            return ['available' => false, 'protocol' => 'unavailable', 'enabled' => true];
        }
        try {
            Database::connection()->query('SELECT 1 FROM api_budget_windows LIMIT 1');
            return ['available' => true, 'protocol' => 'complete', 'enabled' => true];
        } catch (Throwable) {
            return ['available' => false, 'protocol' => 'unavailable', 'enabled' => true];
        }
    }

    public function recordEstimate(?int $accountId, string $module, string $action, int $estimatedCalls, int $allowedCalls, string $result, string $message): void
    {
        if (!$this->schemaReady()) {
            return;
        }
        try {
            Database::connection()->prepare(
                'INSERT INTO api_workload_estimates
                 (user_id,module,meli_account_id,action,estimated_calls,allowed_calls,result,safe_reason,created_at)
                 VALUES (:user,:module,:account,:action,:estimated,:allowed,:result,:reason,UTC_TIMESTAMP())'
            )->execute([
                'user' => Auth::id(),
                'module' => mb_substr($module, 0, 80),
                'account' => $accountId ?: null,
                'action' => mb_substr($action, 0, 120),
                'estimated' => max(0, $estimatedCalls),
                'allowed' => max(0, $allowedCalls),
                'result' => in_array($result, ['allowed', 'delayed', 'blocked'], true) ? $result : 'blocked',
                'reason' => mb_substr($message, 0, 500),
            ]);
        } catch (Throwable) {
        }
    }

    /**
     * @return list<array{scope:string,scope_key:string,limit:int,count:int,cooldown_until:?string}>
     */
    private function scopes(?int $accountId, string $endpointPath, string $jobType, int $windowSeconds): array
    {
        $scopes = [
            ['scope' => 'app', 'scope_key' => 'app:*', 'limit' => $this->effectiveLimit(
                max(1, $this->settings->int('api.budget.global_requests_per_15m', 300)),
                $jobType,
                $this->oauthGlobalReserve()
            ), 'count' => 0, 'cooldown_until' => null],
            ['scope' => 'endpoint', 'scope_key' => 'endpoint:' . $endpointPath, 'limit' => max(1, $this->settings->int('api.budget.endpoint_requests_per_15m', 50)), 'count' => 0, 'cooldown_until' => null],
            ['scope' => 'job_type', 'scope_key' => 'job:' . $jobType, 'limit' => max(1, $this->settings->int('api.budget.job_type_requests_per_15m', 80)), 'count' => 0, 'cooldown_until' => null],
        ];
        if ($accountId !== null && $accountId > 0) {
            $scopes[] = ['scope' => 'account', 'scope_key' => 'account:' . $accountId, 'limit' => $this->effectiveLimit(
                max(1, $this->settings->int('api.budget.account_requests_per_15m', 120)),
                $jobType,
                $this->oauthAccountReserve()
            ), 'count' => 0, 'cooldown_until' => null];
        }
        return $scopes;
    }

    /**
     * Conserva una fracción del presupuesto global y por cuenta para recursos
     * notificados por webhook. Las tareas no urgentes se detienen antes de
     * consumir esa reserva; orders_event_sync puede utilizar el límite total.
     */
    private function effectiveLimit(int $limit, string $jobType, int $oauthReserve): int
    {
        $limit = max(1, $limit);
        if ($oauthReserve < 0 || $limit <= $oauthReserve) {
            throw new ApiBudgetInfrastructureException('La reserva OAuth configurada excede la capacidad preventiva.');
        }
        if ($jobType === 'oauth') {
            return $limit;
        }
        $afterOAuthReserve = max(0, $limit - $oauthReserve);
        if ($jobType === 'orders_event_sync') {
            return max(1, $afterOAuthReserve);
        }
        $reserve = max(0, min(80, $this->settings->int('notifications.api_budget_reserve_percent', 25)));
        return max(1, min($afterOAuthReserve, (int) floor($limit * (100 - $reserve) / 100)));
    }

    private function oauthGlobalReserve(): int
    {
        return max(3, min(30, $this->settings->int('oauth.auto_refresh_global_reserve_per_15m', 3)));
    }

    private function oauthAccountReserve(): int
    {
        return max(1, min(10, $this->settings->int('oauth.auto_refresh_account_reserve_per_15m', 1)));
    }

    private function currentWindowRow(string $scopeKey, string $windowStart, int $windowSeconds): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT id,request_count,cooldown_until FROM api_budget_windows
             WHERE scope_key=:scope_key AND window_started_at=:window_started_at AND window_seconds=:window_seconds
             LIMIT 1 FOR UPDATE'
        );
        $stmt->execute(['scope_key' => $scopeKey, 'window_started_at' => $windowStart, 'window_seconds' => $windowSeconds]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : [];
    }

    private function ensureWindow(string $scope, string $scopeKey, ?int $accountId, string $endpointPath, string $jobType, string $windowStart, int $windowSeconds, int $limit): void
    {
        Database::connection()->prepare(
            'INSERT INTO api_budget_windows
             (scope,scope_key,meli_account_id,endpoint_path,job_type,window_started_at,window_seconds,request_limit,request_count,last_request_at)
              VALUES (:scope,:scope_key,:account,:endpoint,:job_type,:window_started_at,:window_seconds,:request_limit,0,NULL)
              ON DUPLICATE KEY UPDATE
                request_limit=VALUES(request_limit),
                updated_at=UTC_TIMESTAMP()'
        )->execute([
            'scope' => $scope,
            'scope_key' => $scopeKey,
            'account' => $accountId ?: null,
            'endpoint' => $endpointPath,
            'job_type' => $jobType,
            'window_started_at' => $windowStart,
            'window_seconds' => $windowSeconds,
            'request_limit' => $limit,
        ]);
    }

    private function incrementWindow(string $scopeKey, string $windowStart, int $windowSeconds, int $limit, string $now): void
    {
        $stmt = Database::connection()->prepare(
            'UPDATE api_budget_windows
             SET request_count=request_count+1,request_limit=:request_limit,
                 last_request_at=:last_request_at,updated_at=UTC_TIMESTAMP()
             WHERE scope_key=:scope_key
               AND window_started_at=:window_started_at
               AND window_seconds=:window_seconds
               AND request_count<:request_limit_guard
               AND (cooldown_until IS NULL OR cooldown_until<=UTC_TIMESTAMP())'
        );
        $stmt->execute([
            'request_limit' => $limit,
            'last_request_at' => $now,
            'scope_key' => $scopeKey,
            'window_started_at' => $windowStart,
            'window_seconds' => $windowSeconds,
            'request_limit_guard' => $limit,
        ]);
        if ($stmt->rowCount() !== 1) {
            throw new ApiBudgetInfrastructureException(
                'La reserva atómica perdió su ventana antes de confirmar el presupuesto.'
            );
        }
    }

    private function releaseWebCounter(string $jobType, bool $reserved): void
    {
        if ($reserved && isset(self::$webRequestCounters[$jobType])) {
            self::$webRequestCounters[$jobType] = max(0, self::$webRequestCounters[$jobType] - 1);
        }
    }

    /**
     * @param list<array{scope:string,scope_key:string,limit:int,count:int,cooldown_until:?string}> $blocked
     */
    private function nextSafeAt(array $blocked, string $windowStart, int $windowSeconds): string
    {
        $next = $this->utcTimestamp($windowStart) + $windowSeconds;
        foreach ($blocked as $scope) {
            if (!empty($scope['cooldown_until'])) {
                $next = max($next, $this->utcTimestamp((string) $scope['cooldown_until']));
            }
        }
        return gmdate('Y-m-d H:i:s', $next);
    }

    private function windowSeconds(): int
    {
        return max(60, $this->settings->int('api.budget.window_seconds', 900));
    }

    private function windowStart(int $windowSeconds): string
    {
        return gmdate('Y-m-d H:i:s', (int) (floor(time() / $windowSeconds) * $windowSeconds));
    }

    private function utcTimestamp(string $value): int
    {
        return (new SystemDatabaseUtcClock())->timestamp($value) ?? 0;
    }

    private function enabled(): bool
    {
        return $this->settings->bool('api.budget.enabled', true);
    }

    private function schemaReady(): bool
    {
        static $ready = null;
        if ($ready !== null) {
            return $ready;
        }
        $ready = (new SchemaInspectorService())->hasTable('api_budget_windows');
        return $ready;
    }

    /**
     * @param array<string,mixed> $meta
     */
    private function source(array $meta): string
    {
        $source = strtolower((string) ($meta['source'] ?? (PHP_SAPI === 'cli' ? 'cron' : 'web')));
        return in_array($source, ['web', 'cron', 'job', 'admin', 'sync'], true) ? $source : (PHP_SAPI === 'cli' ? 'cron' : 'web');
    }

    /**
     * @param array<string,mixed> $meta
     */
    private function jobType(string $path, array $meta): string
    {
        $explicit = trim((string) ($meta['job_type'] ?? ''));
        if ($explicit !== '') {
            return $this->normalizeJobType($explicit);
        }
        $normalized = $this->normalizeEndpoint($path, 'GET');
        return match (true) {
            in_array($normalized, ['contract:orders_search', 'contract:order_exact', 'contract:shipment_exact', 'contract:pack_exact'], true) => 'orders_sync',
            in_array($normalized, ['contract:items_discovery', 'contract:item_exact'], true) => 'items_sync',
            $normalized === 'contract:item_description' => 'description_job',
            str_contains($normalized, '/stock') => 'stock_origin',
            str_contains($normalized, '/claims') => 'claims',
            str_contains($normalized, '/questions') => 'questions',
            str_contains($normalized, '/billing') => 'billing',
            str_contains($normalized, '/missed_feeds') => 'webhook_recovery',
            str_contains($normalized, '/oauth') || $normalized === 'contract:users_me' || $normalized === '/users/me' => 'oauth',
            default => 'general',
        };
    }

    private function normalizeJobType(string $jobType): string
    {
        $jobType = strtolower(trim($jobType));
        $jobType = preg_replace('/[^a-z0-9_]/', '_', $jobType) ?? 'general';
        return mb_substr($jobType !== '' ? $jobType : 'general', 0, 80);
    }

    private function normalizeEndpoint(string $path, string $method = 'GET'): string
    {
        $parsed = parse_url($path, PHP_URL_PATH) ?: $path;
        $endpoint = '/' . ltrim($parsed, '/');
        if (strtoupper($method) === 'POST' && $endpoint === '/oauth/token') {
            return 'contract:oauth_token';
        }
        try {
            return 'contract:' . MeliEndpointRegistry::contractKey($method, $endpoint);
        } catch (\RuntimeException) {
            // El registry del cliente remoto sigue siendo la barrera de
            // despacho. Este fallback solo estabiliza telemetría heredada.
        }
        $patterns = [
            '#^/orders/\d+$#' => '/orders/{id}',
            '#^/packs/\d+$#' => '/packs/{id}',
            '#^/shipments/\d+$#' => '/shipments/{id}',
            '#^/payments/\d+$#' => '/payments/{id}',
            '#^/users/\d+/items/search$#' => '/users/{id}/items/search',
            '#^/items/[A-Z]{2,4}\d+$#i' => '/items/{id}',
            '#^/items/[A-Z]{2,4}\d+/description$#i' => '/items/{id}/description',
            '#^/categories/[A-Z]{2,4}\d+$#i' => '/categories/{id}',
            '#^/user-products/[^/]+/stock$#' => '/user-products/{id}/stock',
            '#^/post-purchase/v1/claims/\d+$#' => '/post-purchase/v1/claims/{id}',
            '#^/post-purchase/v1/claims/\d+/detail$#' => '/post-purchase/v1/claims/{id}/detail',
            '#^/post-purchase/v1/claims/\d+/(actions-history|status-history|affects-reputation)$#' => '/post-purchase/v1/claims/{id}/{subresource}',
        ];
        foreach ($patterns as $pattern => $replacement) {
            if (preg_match($pattern, $endpoint)) {
                return $replacement;
            }
        }
        return mb_substr($endpoint, 0, 255);
    }
}
