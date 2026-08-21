<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use DateTimeImmutable;
use DateTimeZone;
use PDO;
use Throwable;

/**
 * Autoridad única del ritmo remoto. Un permiso reservado no cuenta como
 * consulta; el bloque avanza únicamente al confirmar el transporte.
 */
final class ApiRhythmPolicyService
{
    private const ORDERS_SEARCH_ENDPOINT = 'orders_search';
    private const ORDERS_SEARCH_WINDOW_SECONDS = 900;
    private const ORDERS_SEARCH_LOCAL_CEILING = 30;
    private const BILLING_ENDPOINT = 'billing_orders';
    private const BILLING_PATH = '/billing/integration/group/ML/order/details';
    private const BILLING_MIN_INTERVAL_SECONDS = 900;
    private const BILLING_429_ESCALATION_WINDOW_HOURS = 72;
    private const BILLING_429_BACKOFF_MIN_MINUTES = 5;
    private const BILLING_429_BACKOFF_MAX_MINUTES = 720;
    private static ?bool $schemaAvailable = null;

    /** @var array<string,int> */
    private const PROFILE_TARGETS = [
        'conservative' => 10,
        'balanced' => 20,
        'fast' => 30,
        'maximum' => 40,
    ];

    /** @var array<int,list<int>> */
    private const RAMPS = [
        10 => [5, 10],
        20 => [10, 15, 20],
        30 => [15, 20, 25, 30],
        40 => [15, 20, 25, 30, 35, 40],
    ];

    public function __construct(private readonly AppSettingsService $settings = new AppSettingsService()) {}

    /** @param array<string,mixed> $meta @return array<string,mixed> */
    public function reserve(?int $accountId, string $method, string $path, array $meta = []): array
    {
        if (!$this->schemaReady()) {
            return ['enabled' => false, 'permit_token' => '', 'blocking_scope' => null];
        }

        $profile = (new MeliOperationProfileRegistry())->resolve($method, $path, $meta);
        if (empty($profile['uses_api'])) {
            return ['enabled' => false, 'permit_token' => '', 'blocking_scope' => null];
        }

        $policy = $this->configuration();
        $policy['current_adaptive_limit'] = $this->adaptiveLimit($policy);
        $owner = bin2hex(random_bytes(16));
        $permit = bin2hex(random_bytes(20));
        $companyId = $this->companyId($accountId);
        $runToken = mb_substr((string) ($meta['run_token'] ?? $meta['cron_run_token'] ?? ''), 0, 100);
        $workKey = mb_substr((string) ($meta['source_work_id'] ?? $meta['work_id'] ?? $meta['job_id'] ?? ''), 0, 120);
        $endpointKey = mb_substr((string) ($profile['key'] ?? $path), 0, 120);
        $jobType = mb_substr((string) ($meta['job_type'] ?? 'unknown'), 0, 80);
        $pdo = Database::connectionFresh();

        for ($pass = 0; $pass < 2; $pass++) {
            $pdo->beginTransaction();
            try {
                $pdo->exec(
                    "INSERT IGNORE INTO api_rhythm_states
                     (scope_key,generation,calls_in_block,updated_at)
                     VALUES ('global',1,0,UTC_TIMESTAMP(3))"
                );
                $state = $pdo->query(
                    "SELECT * FROM api_rhythm_states WHERE scope_key='global' FOR UPDATE"
                )->fetch(PDO::FETCH_ASSOC) ?: [];

                $billingBlock = $this->billingBlock($pdo, $endpointKey);
                if ($billingBlock !== null) {
                    $pdo->rollBack();
                    throw new ApiRhythmDeferredException(
                        (string) $billingBlock['message'],
                        (string) $billingBlock['next_safe_at'],
                        (string) $billingBlock['scope']
                    );
                }

                // Solo puede existir una reserva global viva. Sin este fence,
                // dos procesos concurrentes podrían iniciar transporte antes
                // de que cualquiera confirme el intervalo en dispatched().
                $pdo->exec(
                    "UPDATE api_remote_permits
                     SET status='expired',updated_at=UTC_TIMESTAMP(3)
                     WHERE status IN ('reserved','dispatched') AND expires_at<=UTC_TIMESTAMP(3)"
                );
                $reserved = $pdo->query(
                    "SELECT expires_at FROM api_remote_permits
                     WHERE status IN ('reserved','dispatched') AND expires_at>UTC_TIMESTAMP(3)
                     ORDER BY id ASC LIMIT 1 FOR UPDATE"
                )->fetchColumn();
                if ($reserved !== false) {
                    $pdo->rollBack();
                    throw new ApiRhythmDeferredException(
                        'Otra consulta ya tiene el permiso global de transporte.',
                        gmdate('Y-m-d H:i:s', time() + 2),
                        'rhythm_permit_busy'
                    );
                }

                $now = microtime(true);
                $nextAllowed = $this->timestampMicros($state['next_allowed_at'] ?? null);
                $pauseUntil = $this->timestampMicros($state['block_pause_until'] ?? null);
                $calls = max(0, (int) ($state['calls_in_block'] ?? 0));

                if ($pauseUntil !== null && $pauseUntil <= $now) {
                    $pdo->prepare(
                        "UPDATE api_rhythm_states
                         SET calls_in_block=0,block_started_at=NULL,block_pause_until=NULL,
                             generation=generation+1,updated_at=UTC_TIMESTAMP(3)
                         WHERE scope_key='global'"
                    )->execute();
                    // La generación forma parte del fence del permiso. Después
                    // del rollover debemos releer la fila bloqueada; reutilizar
                    // el snapshot anterior creaba un permiso ya vencido.
                    $state = $pdo->query(
                        "SELECT * FROM api_rhythm_states WHERE scope_key='global' FOR UPDATE"
                    )->fetch(PDO::FETCH_ASSOC) ?: [];
                    $calls = max(0, (int) ($state['calls_in_block'] ?? 0));
                    $pauseUntil = $this->timestampMicros($state['block_pause_until'] ?? null);
                    $nextAllowed = $this->timestampMicros($state['next_allowed_at'] ?? null);
                }

                $blockingAt = null;
                $blockingScope = 'rhythm_interval';
                if ($pauseUntil !== null && $pauseUntil > $now) {
                    $blockingAt = $pauseUntil;
                    $blockingScope = 'rhythm_block_pause';
                } elseif ($nextAllowed !== null && $nextAllowed > $now) {
                    $blockingAt = $nextAllowed;
                }

                if ($blockingAt !== null) {
                    $waitMs = (int) ceil(($blockingAt - $now) * 1000);
                    $pdo->rollBack();
                    if ($pass === 0 && $waitMs <= (int) $policy['short_wait_ceiling_ms']) {
                        usleep(max(0, $waitMs) * 1000);
                        continue;
                    }
                    $next = $this->formatTimestamp($blockingAt);
                    throw new ApiRhythmDeferredException(
                        $blockingScope === 'rhythm_block_pause'
                            ? 'El bloque terminó y continuará después de su pausa segura.'
                            : 'La próxima consulta quedó espaciada para una ejecución posterior.',
                        $next,
                        $blockingScope
                    );
                }

                $rollingBlock = $this->rollingWindowBlock(
                    $pdo,
                    $accountId,
                    $endpointKey,
                    (int) $policy['current_adaptive_limit'],
                    (int) $policy['rolling_window_seconds']
                );
                if ($rollingBlock !== null) {
                    $pdo->rollBack();
                    throw new ApiRhythmDeferredException(
                        (string) $rollingBlock['message'],
                        (string) $rollingBlock['next_safe_at'],
                        (string) $rollingBlock['scope']
                    );
                }

                $generation = max(1, (int) ($state['generation'] ?? 1));
                $pdo->prepare(
                    'INSERT INTO api_remote_permits
                     (permit_token,owner_token,generation,run_token,work_key,company_id,meli_account_id,
                      endpoint_key,job_type,method,status,requested_interval_ms,effective_interval_ms,
                      blocking_scope,expires_at,created_at,updated_at)
                     VALUES (?,?,?,?,?,?,?,?,?,?,"reserved",?,?,NULL,
                             DATE_ADD(UTC_TIMESTAMP(3),INTERVAL 120 SECOND),UTC_TIMESTAMP(3),UTC_TIMESTAMP(3))'
                )->execute([
                    $permit,
                    $owner,
                    $generation,
                    $runToken !== '' ? $runToken : null,
                    $workKey !== '' ? $workKey : null,
                    $companyId,
                    $accountId,
                    $endpointKey,
                    $jobType,
                    strtoupper(mb_substr($method, 0, 10)),
                    (int) $policy['minimum_interval_ms'],
                    (int) $policy['minimum_interval_ms'],
                ]);
                $pdo->commit();
                return [
                    'enabled' => true,
                    'permit_token' => $permit,
                    'owner_token' => $owner,
                    'generation' => $generation,
                    'requested_interval_ms' => (int) $policy['minimum_interval_ms'],
                    'effective_interval_ms' => (int) $policy['minimum_interval_ms'],
                    'target_http_per_minute' => (int) $policy['target_http_per_minute'],
                    'current_adaptive_limit' => (int) $policy['current_adaptive_limit'],
                    'endpoint_key' => $endpointKey,
                    'blocking_scope' => null,
                ];
            } catch (Throwable $error) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                throw $error;
            }
        }

        throw new ApiRhythmDeferredException('La próxima consulta continuará en otro ciclo.');
    }

    /** @param array<string,mixed> $permit */
    public function dispatched(array $permit): bool
    {
        if (empty($permit['enabled']) || empty($permit['permit_token'])) {
            return true;
        }
        $policy = $this->configuration();
        $pdo = Database::connectionFresh();
        $pdo->beginTransaction();
        try {
            // Orden de locks autoritativo: estado global -> permiso. reserve()
            // usa el mismo orden; invertirlo aquí producía deadlocks cuando dos
            // Cron reservaban y despachaban al mismo tiempo.
            $state = $pdo->query(
                "SELECT calls_in_block,generation FROM api_rhythm_states WHERE scope_key='global' FOR UPDATE"
            )->fetch(PDO::FETCH_ASSOC) ?: [];
            $rowStmt = $pdo->prepare(
                'SELECT id,status,generation FROM api_remote_permits
                 WHERE permit_token=? AND owner_token=? FOR UPDATE'
            );
            $rowStmt->execute([(string) $permit['permit_token'], (string) $permit['owner_token']]);
            $row = $rowStmt->fetch(PDO::FETCH_ASSOC);
            if (!$row || (string) $row['status'] !== 'reserved'
                || (int) $row['generation'] !== (int) ($permit['generation'] ?? 0)) {
                $pdo->rollBack();
                return false;
            }
            if ((int) ($state['generation'] ?? 0) !== (int) $row['generation']) {
                $pdo->rollBack();
                return false;
            }
            $calls = max(0, (int) ($state['calls_in_block'] ?? 0)) + 1;
            // calls_in_block se conserva únicamente para compatibilidad y
            // diagnóstico. El límite autoritativo es la ventana rodante de
            // api_remote_permits y nunca se reinicia en el cambio de minuto.
            $pauseMs = 0;
            $stateUpdate=$pdo->prepare(
                "UPDATE api_rhythm_states
                 SET calls_in_block=?,block_started_at=COALESCE(block_started_at,UTC_TIMESTAMP(3)),
                     next_allowed_at=DATE_ADD(UTC_TIMESTAMP(3),INTERVAL ? MICROSECOND),
                     block_pause_until=CASE WHEN ?>0 THEN DATE_ADD(UTC_TIMESTAMP(3),INTERVAL ? MICROSECOND)
                                            ELSE block_pause_until END,
                     last_dispatched_at=UTC_TIMESTAMP(3),updated_at=UTC_TIMESTAMP(3)
                 WHERE scope_key='global' AND generation=?"
            );
            $stateUpdate->execute([
                $calls,
                (int) $policy['minimum_interval_ms'] * 1000,
                $pauseMs,
                $pauseMs * 1000,
                (int) $row['generation'],
            ]);
            if($stateUpdate->rowCount()!==1){$pdo->rollBack();return false;}
            $permitUpdate=$pdo->prepare(
                'UPDATE api_remote_permits SET status="dispatched",dispatched_at=UTC_TIMESTAMP(3),updated_at=UTC_TIMESTAMP(3)
                 WHERE id=? AND status="reserved" AND generation=?'
            );
            $permitUpdate->execute([(int) $row['id'], (int) $row['generation']]);
            if($permitUpdate->rowCount()!==1){$pdo->rollBack();return false;}
            $pdo->commit();
            return true;
        } catch (Throwable $error) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $error;
        }
    }

    /** @param array<string,mixed> $permit */
    public function isCurrent(array $permit): bool
    {
        if (empty($permit['enabled']) || empty($permit['permit_token'])) {
            return true;
        }
        if (!$this->schemaReady()) {
            return false;
        }
        $stmt = Database::connectionFresh()->prepare(
            "SELECT 1
             FROM api_remote_permits permit
             JOIN api_rhythm_states rhythm
               ON rhythm.scope_key='global' AND rhythm.generation=permit.generation
             WHERE permit.permit_token=? AND permit.owner_token=? AND permit.generation=?
               AND permit.status='reserved' AND permit.expires_at>UTC_TIMESTAMP(3)
             LIMIT 1"
        );
        $stmt->execute([
            (string) $permit['permit_token'],
            (string) ($permit['owner_token'] ?? ''),
            (int) ($permit['generation'] ?? 0),
        ]);

        return $stmt->fetchColumn() !== false;
    }

    /** @param array<string,mixed> $permit */
    public function completed(array $permit, ?int $httpStatus): bool
    {
        return $this->transition($permit, 'dispatched', 'completed', $httpStatus);
    }

    /**
     * Una respuesta HTTP conocida no se vuelve incierta porque falle el
     * housekeeping posterior. Si el transition cercado ya no puede confirmar
     * la generación, el permiso exacto queda cerrado como expirado y conserva
     * el status para reconciliación local; nunca se altera la autoridad nueva.
     *
     * @param array<string,mixed> $permit
     */
    public function finalizeKnownResult(
        array $permit,
        ?int $httpStatus,
        ?int $retryAfterSeconds = null
    ): bool
    {
        try {
            if ($this->completed($permit, $httpStatus)) {
                $this->recordRateLimitPenalty($permit, $httpStatus, $retryAfterSeconds);
                return true;
            }
        } catch (Throwable) {
            // La respuesta ya es conocida. El cleanup se intenta abajo sin
            // cambiar su clasificación ni provocar un reintento remoto.
        }

        $this->recordRateLimitPenalty($permit, $httpStatus, $retryAfterSeconds);

        if (empty($permit['enabled']) || empty($permit['permit_token'])) {
            return true;
        }

        try {
            $stmt = Database::connectionFresh()->prepare(
                'UPDATE api_remote_permits
                 SET status="expired",http_status=?,completed_at=UTC_TIMESTAMP(3),
                     blocking_scope="known_result_reconciliation",updated_at=UTC_TIMESTAMP(3)
                 WHERE permit_token=? AND owner_token=? AND generation=?
                   AND status IN ("reserved","dispatched","expired")'
            );
            $stmt->execute([
                $httpStatus,
                (string) $permit['permit_token'],
                (string) ($permit['owner_token'] ?? ''),
                (int) ($permit['generation'] ?? 0),
            ]);
        } catch (Throwable) {
            // El registro técnico puede reconciliarse por expiración. No se
            // convierte una respuesta HTTP ya observada en resultado incierto.
        }

        Logger::write('warning', 'El resultado HTTP conocido requirió reconciliación del permiso de ritmo.', [
            'generation' => (int) ($permit['generation'] ?? 0),
            'http_status' => $httpStatus,
        ]);
        return false;
    }

    /** @param array<string,mixed> $permit */
    public function release(array $permit): void
    {
        $this->transition($permit, 'reserved', 'released', null);
    }

    /** @param array<string,mixed> $permit */
    public function cancelBeforeTransport(array $permit,bool $strict=false): void
    {
        if (empty($permit['enabled']) || empty($permit['permit_token'])) {
            return;
        }
        $pdo = Database::connectionFresh();
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare(
                'SELECT id,status,generation FROM api_remote_permits
                 WHERE permit_token=? AND owner_token=? AND generation=? FOR UPDATE'
            );
            $stmt->execute([
                (string) $permit['permit_token'],
                (string) ($permit['owner_token'] ?? ''),
                (int) ($permit['generation'] ?? 0),
            ]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!is_array($row) || !in_array((string) $row['status'], ['reserved', 'dispatched'], true)) {
                $pdo->rollBack();
                return;
            }
            if ((string) $row['status'] === 'dispatched') {
                $stateUpdate=$pdo->prepare(
                    "UPDATE api_rhythm_states
                     SET calls_in_block=IF(calls_in_block>0,calls_in_block-1,0),
                         updated_at=UTC_TIMESTAMP(3)
                     WHERE scope_key='global' AND generation=?"
                );
                $stateUpdate->execute([(int) $row['generation']]);
                if($strict && $stateUpdate->rowCount()!==1)throw new \RuntimeException('Queue Core rhythm refund lost the global generation fence.');
            }
            $permitUpdate=$pdo->prepare(
                'UPDATE api_remote_permits
                 SET status="released",released_at=UTC_TIMESTAMP(3),dispatched_at=NULL,
                     blocking_scope="cancelled_before_transport",updated_at=UTC_TIMESTAMP(3)
                 WHERE id=? AND generation=? AND status=?'
            );
            $permitUpdate->execute([(int) $row['id'], (int) $row['generation'], (string) $row['status']]);
            if($strict && $permitUpdate->rowCount()!==1)throw new \RuntimeException('Queue Core rhythm permit refund lost its fence.');
            if ((string) $row['status'] === 'dispatched') {
                $nextUpdate=$pdo->prepare(
                    "UPDATE api_rhythm_states SET
                       next_allowed_at=(SELECT DATE_ADD(MAX(dispatched_at),INTERVAL ? MICROSECOND)
                                        FROM api_remote_permits
                                        WHERE status IN ('dispatched','completed') AND dispatched_at IS NOT NULL),
                       last_dispatched_at=(SELECT MAX(dispatched_at) FROM api_remote_permits
                                           WHERE status IN ('dispatched','completed') AND dispatched_at IS NOT NULL)
                     WHERE scope_key='global' AND generation=?"
                );
                $nextUpdate->execute([(int)$this->configuration()['minimum_interval_ms']*1000,(int)$row['generation']]);
                if($strict && $nextUpdate->rowCount()!==1)throw new \RuntimeException('Queue Core rhythm recalculation lost its generation fence.');
            }
            $pdo->commit();
        } catch (Throwable $error) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $error;
        }
    }

    /** @return array<string,mixed> */
    public function configuration(): array
    {
        $legacyMode = (string) $this->settings->get('api.rhythm.mode', 'recovery');
        $profile = (string) $this->settings->get('api.rhythm.profile', '');
        if ($profile === '') {
            $profile = match ($legacyMode) {
                'conservative' => 'conservative',
                'balanced' => 'balanced',
                'recovery' => 'maximum',
                default => 'fast',
            };
        }
        if (!isset(self::PROFILE_TARGETS[$profile]) && $profile !== 'custom') {
            $profile = 'fast';
        }
        $targetDefault = $profile === 'custom' ? 40 : self::PROFILE_TARGETS[$profile];
        $target = $this->settings->int('api.rhythm.target_http_per_minute', $targetDefault);
        if ($profile !== 'custom' && !in_array($target, array_values(self::PROFILE_TARGETS), true)) {
            $target = self::PROFILE_TARGETS[$profile];
        }
        if ($profile !== 'custom') {
            $profile = (string) (array_search($target, self::PROFILE_TARGETS, true) ?: $profile);
        }
        $ramp = $this->rampSteps($target, $profile);
        $current = $this->settings->int('api.rhythm.current_adaptive_limit', $ramp[0]);
        $current = max($ramp[0], min($target, $current));
        $minimumInterval = max(1000, min(60000, $this->settings->int('api.rhythm.minimum_interval_ms', 1000)));
        $billing429Backoff = $this->billing429BackoffMinutes();
        return [
            'mode' => $profile,
            'profile' => $profile,
            'target_http_per_minute' => $target,
            'current_adaptive_limit' => $current,
            'minimum_interval_ms' => $minimumInterval,
            'rolling_window_seconds' => max(60, min(300, $this->settings->int('api.rhythm.rolling_window_seconds', 60))),
            'ramp_steps' => $ramp,
            'ramp_evaluation_minutes' => max(5, min(1440, $this->settings->int('api.rhythm.ramp_evaluation_minutes', 30))),
            'ramp_min_known_responses' => max(1, min(10000, $this->settings->int('api.rhythm.ramp_min_known_responses', 60))),
            'ramp_max_429' => max(0, min(100, $this->settings->int('api.rhythm.ramp_max_429', 0))),
            'ramp_max_lease_lost' => max(0, min(100, $this->settings->int('api.rhythm.ramp_max_lease_lost', 0))),
            'ramp_max_duplicates' => max(0, min(100, $this->settings->int('api.rhythm.ramp_max_duplicates', 0))),
            'ramp_p95_http_ms' => max(500, min(60000, $this->settings->int('api.rhythm.ramp_p95_http_ms', 5000))),
            'ramp_require_drainage' => $this->settings->bool('api.rhythm.ramp_require_drainage', true),
            'current_level_started_at' => trim((string) $this->settings->get('api.rhythm.current_level_started_at', '')),
            'billing_429_backoff_minutes' => $billing429Backoff,
            'billing_429_backoff_1_minutes' => $billing429Backoff[1],
            'billing_429_backoff_2_minutes' => $billing429Backoff[2],
            'billing_429_backoff_3_minutes' => $billing429Backoff[3],
            'billing_429_backoff_max_minutes' => $billing429Backoff[4],
            // Compatibilidad de lectura con presentadores 2.28.15–2.28.30.
            'calls_per_block' => $target,
            'interval_ms' => $minimumInterval,
            'block_pause_ms' => 0,
            'short_wait_ceiling_ms' => max(0, min(1500, $this->settings->int('api.rhythm.short_wait_ceiling_ms', 1500))),
            'adaptive_enabled' => $this->settings->bool('api.rhythm.adaptive_enabled', true),
        ];
    }

    /** @return list<int> */
    private function rampSteps(int $target, string $profile): array
    {
        $raw = trim((string) $this->settings->get('api.rhythm.ramp_steps', ''));
        if ($profile === 'custom' && $raw !== '') {
            $steps = [];
            foreach (preg_split('/[^0-9]+/', $raw) ?: [] as $part) {
                if ($part === '') {
                    continue;
                }
                $value = max(1, min(300, (int) $part));
                if ($value <= $target) {
                    $steps[$value] = true;
                }
            }
            if ($steps !== []) {
                $values = array_keys($steps);
                sort($values);
                if (end($values) !== $target) {
                    $values[] = $target;
                }
                return array_values(array_unique($values));
            }
        }

        return self::RAMPS[$target] ?? [min(15, $target), $target];
    }

    /** @return array{1:int,2:int,3:int,4:int} */
    private function billing429BackoffMinutes(): array
    {
        return self::normalizeBilling429BackoffMinutes([
            $this->billing429BackoffMinuteValue('api.rhythm.billing_429_backoff_1_minutes', 30),
            $this->billing429BackoffMinuteValue('api.rhythm.billing_429_backoff_2_minutes', 120),
            $this->billing429BackoffMinuteValue('api.rhythm.billing_429_backoff_3_minutes', 360),
            $this->billing429BackoffMinuteValue('api.rhythm.billing_429_backoff_max_minutes', 720),
        ]);
    }

    /**
     * Canonical form shared by every configuration surface. A lower later
     * step is raised, never silently turned into a shorter prior protection.
     *
     * @param array{0?:mixed,1?:mixed,2?:mixed,3?:mixed} $values
     * @return array{1:int,2:int,3:int,4:int}
     */
    public static function normalizeBilling429BackoffMinutes(array $values): array
    {
        $first = self::boundedBilling429BackoffMinutes($values[0] ?? 30);
        $second = max($first, self::boundedBilling429BackoffMinutes($values[1] ?? 120));
        $third = max($second, self::boundedBilling429BackoffMinutes($values[2] ?? 360));
        $maximum = max($third, self::boundedBilling429BackoffMinutes($values[3] ?? 720));

        return [1 => $first, 2 => $second, 3 => $third, 4 => $maximum];
    }

    private static function boundedBilling429BackoffMinutes(mixed $value): int
    {
        return max(5, min(720, (int) $value));
    }

    private function billing429BackoffMinuteValue(string $key, int $default): int
    {
        return max(
            self::BILLING_429_BACKOFF_MIN_MINUTES,
            min(self::BILLING_429_BACKOFF_MAX_MINUTES, $this->settings->int($key, $default))
        );
    }

    /** @return array<string,mixed> */
    public function preview(?int $accountId = null): array
    {
        $config = $this->configuration();
        $requestedRpm = (float) $config['target_http_per_minute'];
        $window = max(60, $this->settings->int('api.budget.window_seconds', 900));
        $limits = [
            'Aplicación' => max(1, $this->settings->int('api.budget.global_requests_per_15m', 300)) * 60 / $window,
            'Cuenta' => max(1, $this->settings->int('api.budget.account_requests_per_15m', 120)) * 60 / $window,
            'Endpoint' => max(1, $this->settings->int('api.budget.endpoint_requests_per_15m', 50)) * 60 / $window,
            'Tipo de trabajo' => max(1, $this->settings->int('api.budget.job_type_requests_per_15m', 80)) * 60 / $window,
        ];
        $limiter = array_search(min($limits), $limits, true) ?: 'Endpoint';
        $accountPenalty = $this->activeAccountLimit($accountId);
        if ($accountPenalty !== null) {
            $limits['Protección temporal de la cuenta'] = $accountPenalty;
            $limiter = array_search(min($limits), $limits, true) ?: $limiter;
        }
        $adaptive = !empty($config['adaptive_enabled'])
            ? (int) $config['current_adaptive_limit']
            : (int) $config['target_http_per_minute'];
        $effective = min($requestedRpm, $adaptive, ...array_values($limits));
        $observed = $this->observedCapacity($accountId);
        return $config + [
            'requested_rpm' => $requestedRpm,
            'adaptive_rpm' => $adaptive,
            'effective_rpm' => round($effective, 1),
            'allowed_rpm' => round($effective, 1),
            'effective_interval_seconds' => round(60 / max(.1, $effective), 1),
            'limiting_scope' => $limiter,
            'account_id' => $accountId,
            'observed_http_15m' => $observed['observed_http_15m'],
            'observed_http_60m' => $observed['observed_http_60m'],
            'resources_per_http' => $observed['resources_per_http'],
            'observed_state' => $observed['state'],
        ];
    }

    public function recommendedRuntimeSeconds(): int
    {
        return match ((int) $this->configuration()['target_http_per_minute']) {
            // El carril dirigido requiere alrededor de 17 s en el peor caso.
            // 32 s dejan 22 s de aceptación y 10 s de cierre, incluyendo el
            // margen del bootstrap sin impedir que una campaña pueda iniciar.
            10 => 32,
            20 => 35,
            40 => 50,
            default => 40,
        };
    }

    /** @param array<string,mixed> $policy */
    private function adaptiveLimit(array $policy): int
    {
        $target = (int) $policy['target_http_per_minute'];
        $ramp = self::RAMPS[$target];
        if (empty($policy['adaptive_enabled'])) {
            return $target;
        }

        $current = max($ramp[0], min($target, (int) $policy['current_adaptive_limit']));
        $levelStartedAt = trim((string) ($policy['current_level_started_at'] ?? ''));
        $levelStartedTimestamp = $levelStartedAt === '' ? 0 : (int) strtotime($levelStartedAt . ' UTC');
        $lastEvaluation = trim((string) $this->settings->get('api.rhythm.last_ramp_evaluation_at', ''));
        $lastTimestamp = $lastEvaluation === '' ? 0 : (int) strtotime($lastEvaluation . ' UTC');
        $evaluationSeconds = (int) $policy['ramp_evaluation_minutes'] * 60;
        // Una rampa solo puede usar evidencia producida de forma continua en
        // el nivel actual. Configuraciones antiguas empiezan su período estable
        // ahora y nunca heredan evidencia de otro perfil o nivel.
        if ($levelStartedTimestamp <= 0 || $levelStartedTimestamp > time()) {
            $now = gmdate('Y-m-d H:i:s');
            $this->settings->set('api.rhythm.current_level_started_at', $now, 'api_rhythm');
            $this->settings->set('api.rhythm.last_ramp_evaluation_at', $now, 'api_rhythm');
            return $current;
        }
        if (time() - $levelStartedTimestamp < $evaluationSeconds) {
            return $current;
        }
        if ($lastTimestamp > 0 && time() - $lastTimestamp < $evaluationSeconds) {
            return $current;
        }

        $this->settings->set('api.rhythm.last_ramp_evaluation_at', gmdate('Y-m-d H:i:s'), 'api_rhythm');
        try {
            $pdo = Database::connectionFresh();
            $windowMinutes = (int) $policy['ramp_evaluation_minutes'];
            $stmt = $pdo->prepare(
                "SELECT COUNT(*) known_responses,
                        SUM(http_status=429) rate_limited,
                        SUM(CASE WHEN http_status BETWEEN 200 AND 299
                                      AND http_status<>206
                                      AND outcome_class='success'
                                      AND response_count_state='complete'
                                 THEN 1 ELSE 0 END) accepted,
                        SUM(CASE WHEN endpoint_path='/oauth/token'
                                      AND (http_status IS NULL OR http_status NOT BETWEEN 200 AND 399)
                                 THEN 1 ELSE 0 END) oauth_failures
                 FROM api_request_logs
                 WHERE reached_remote=1 AND http_status IS NOT NULL
                   AND created_at>=DATE_SUB(UTC_TIMESTAMP(),INTERVAL ? MINUTE)
                   AND created_at>=?"
            );
            $stmt->execute([$windowMinutes, gmdate('Y-m-d H:i:s', $levelStartedTimestamp)]);
            $evidence = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
            $known = max(0, (int) ($evidence['known_responses'] ?? 0));
            $accepted = max(0, (int) ($evidence['accepted'] ?? 0));
            $rateLimited = max(0, (int) ($evidence['rate_limited'] ?? 0));
            $oauthFailures = max(0, (int) ($evidence['oauth_failures'] ?? 0));

            $uncertainStmt = $pdo->prepare(
                "SELECT COUNT(*) FROM api_request_logs
                 WHERE outcome_class='remote_result_uncertain'
                   AND created_at>=DATE_SUB(UTC_TIMESTAMP(),INTERVAL ? MINUTE)
                   AND created_at>=?"
            );
            $uncertainStmt->execute([$windowMinutes, gmdate('Y-m-d H:i:s', $levelStartedTimestamp)]);
            $uncertain = max(0, (int) $uncertainStmt->fetchColumn());

            $durationsStmt = $pdo->prepare(
                "SELECT duration_ms FROM api_request_logs
                 WHERE reached_remote=1 AND duration_ms IS NOT NULL
                   AND created_at>=DATE_SUB(UTC_TIMESTAMP(),INTERVAL ? MINUTE)
                   AND created_at>=?
                 ORDER BY duration_ms"
            );
            $durationsStmt->execute([$windowMinutes, gmdate('Y-m-d H:i:s', $levelStartedTimestamp)]);
            $durations = array_map('intval', $durationsStmt->fetchAll(PDO::FETCH_COLUMN));
            $p95 = $durations === [] ? PHP_INT_MAX : $durations[(int) floor((count($durations) - 1) * .95)];

            $circuits = (int) $pdo->query(
                "SELECT COUNT(*) FROM api_circuit_breakers
                 WHERE status='open' AND blocked_until>UTC_TIMESTAMP()"
            )->fetchColumn();
            $acceptedRate = $known > 0 ? ($accepted / $known) : 0.0;
            if ($known >= 60 && $rateLimited === 0 && $uncertain === 0 && $oauthFailures === 0
                && $acceptedRate >= .99 && $p95 < 5000 && $circuits === 0) {
                $index = array_search($current, $ramp, true);
                $next = $index === false ? $ramp[0] : ($ramp[$index + 1] ?? $current);
                if ($next > $current) {
                    $current = min($target, $next);
                    $this->settings->set('api.rhythm.current_adaptive_limit', (string) $current, 'api_rhythm');
                    $this->settings->set('api.rhythm.current_level_started_at', gmdate('Y-m-d H:i:s'), 'api_rhythm');
                }
            }
        } catch (Throwable) {
            // Sin evidencia completa no se aumenta el ritmo. La operación
            // continúa en el último nivel conocido, nunca en el máximo.
        }

        return $current;
    }

    /**
     * @return array{observed_http_15m:?float,observed_http_60m:?float,resources_per_http:?float,state:string}
     */
    private function observedCapacity(?int $accountId): array
    {
        try {
            $pdo = Database::connectionFresh();
            $scope = $accountId !== null && $accountId > 0 ? ' AND meli_account_id=?' : '';
            $stmt = $pdo->prepare(
                "SELECT
                    SUM(CASE WHEN created_at>=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 15 MINUTE)
                                  AND reached_remote=1 THEN 1 ELSE 0 END) http_15m,
                    SUM(CASE WHEN created_at>=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 60 MINUTE)
                                  AND reached_remote=1 THEN 1 ELSE 0 END) http_60m,
                    SUM(CASE WHEN created_at>=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 60 MINUTE)
                                  AND reached_remote=1 AND response_count_state='complete'
                             THEN COALESCE(response_item_count,0) ELSE 0 END) resources_60m,
                    SUM(CASE WHEN created_at>=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 60 MINUTE)
                                  AND reached_remote=1 AND response_count_state='complete'
                             THEN 1 ELSE 0 END) measured_http_60m
                 FROM api_request_logs
                 WHERE created_at>=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 60 MINUTE)" . $scope
            );
            $stmt->execute($scope === '' ? [] : [$accountId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
            $http15 = max(0, (int) ($row['http_15m'] ?? 0));
            $http60 = max(0, (int) ($row['http_60m'] ?? 0));
            $resources = max(0, (int) ($row['resources_60m'] ?? 0));
            $measuredHttp = max(0, (int) ($row['measured_http_60m'] ?? 0));
            return [
                'observed_http_15m' => round($http15 / 15, 2),
                'observed_http_60m' => round($http60 / 60, 2),
                'resources_per_http' => $measuredHttp > 0 ? round($resources / $measuredHttp, 2) : null,
                'state' => 'complete',
            ];
        } catch (Throwable) {
            return [
                'observed_http_15m' => null,
                'observed_http_60m' => null,
                'resources_per_http' => null,
                'state' => 'unavailable',
            ];
        }
    }

    private function activeAccountLimit(?int $accountId): ?float
    {
        if ($accountId === null || $accountId < 1) {
            return null;
        }
        try {
            $stmt = Database::connectionFresh()->prepare(
                "SELECT MIN(reduced_limit_per_minute)
                 FROM api_rhythm_penalties
                 WHERE scope_key=? AND reduced_until>UTC_TIMESTAMP(3)"
            );
            $stmt->execute(['account:' . $accountId]);
            $value = $stmt->fetchColumn();
            return $value === false || $value === null ? null : max(0.1, (float) $value);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @return array{message:string,next_safe_at:string,scope:string}|null
     */
    private function rollingWindowBlock(
        PDO $pdo,
        ?int $accountId,
        string $endpointKey,
        int $globalLimit,
        int $windowSeconds
    ): ?array {
        if (hash_equals(self::ORDERS_SEARCH_ENDPOINT, $endpointKey)) {
            $ordersSearch = $this->rollingCount(
                $pdo,
                'endpoint_shared',
                $endpointKey,
                self::ORDERS_SEARCH_WINDOW_SECONDS
            );
            $ceiling = max(
                1,
                min(
                    self::ORDERS_SEARCH_LOCAL_CEILING,
                    $this->settings->int(
                        'api.rhythm.orders_search_requests_per_15m',
                        self::ORDERS_SEARCH_LOCAL_CEILING
                    )
                )
            );
            if ($ordersSearch['count'] >= $ceiling) {
                return $this->rollingDeferred(
                    $ordersSearch['oldest'],
                    self::ORDERS_SEARCH_WINDOW_SECONDS,
                    'rhythm_shared_orders_search_window'
                );
            }
        }

        $global = $this->rollingCount($pdo, '', null, $windowSeconds);
        if ($global['count'] >= $globalLimit) {
            return $this->rollingDeferred($global['oldest'], $windowSeconds, 'rhythm_global_window');
        }

        if ($accountId === null || $accountId < 1) {
            return null;
        }
        try {
            $scopeRows = $this->activePenaltyRows($pdo, $accountId, $endpointKey);
        } catch (Throwable) {
            // La imposibilidad de leer una reducción vigente no autoriza a
            // saltársela. Se difiere de forma corta y segura para que el
            // siguiente Cron pueda reconciliar la protección.
            return [
                'message' => 'No fue posible comprobar temporalmente las protecciones de ritmo.',
                'next_safe_at' => $this->formatTimestamp(microtime(true) + 60),
                'scope' => 'rhythm_penalty_state_unavailable',
            ];
        }
        $now = microtime(true);
        foreach ($scopeRows as $row) {
            $scopeKey = (string) $row['scope_key'];
            $endpointScoped = str_starts_with($scopeKey, 'endpoint:');
            $blockedUntil = $this->timestampMicros($row['blocked_until'] ?? null);
            // Un HTTP 429 puede detener el endpoint que lo recibió, pero no
            // convertir una reducción adaptativa de cuenta en un apagado de
            // Fresh, orders u OAuth. Las filas account:* históricas siguen
            // aportando su límite reducido, nunca una frontera absoluta.
            if ($endpointScoped && $blockedUntil !== null && $blockedUntil > $now) {
                return [
                    'message' => 'Mercado Libre solicitó esperar antes de otra consulta para este alcance.',
                    'next_safe_at' => $this->formatTimestamp($blockedUntil),
                    'scope' => 'retry_after',
                ];
            }
            $reducedUntil = $this->timestampMicros($row['reduced_until'] ?? null);
            if ($reducedUntil === null || $reducedUntil <= $now) {
                continue;
            }
            $kind = $endpointScoped ? 'endpoint_shared' : 'account';
            $count = $this->rollingCount(
                $pdo,
                $kind,
                $kind === 'endpoint_shared' ? $endpointKey : $accountId,
                $windowSeconds
            );
            $limit = max(1, min($globalLimit, (int) $row['reduced_limit_per_minute']));
            if ($count['count'] >= $limit) {
                return $this->rollingDeferred($count['oldest'], $windowSeconds, 'rhythm_' . $kind . '_reduced');
            }
        }
        return null;
    }

    /**
     * Contención local y temporal del endpoint Billing. La fila global de
     * ritmo ya está bloqueada por reserve(), de modo que dos procesos no
     * pueden observar simultáneamente una frontera física elegible.
     *
     * @return array{message:string,next_safe_at:string,scope:string}|null
     */
    private function billingBlock(
        PDO $pdo,
        string $endpointKey,
        ?int $currentRetryAfterSeconds = null
    ): ?array {
        if (!hash_equals(self::BILLING_ENDPOINT, $endpointKey)) {
            return null;
        }

        $statement = $pdo->prepare(
            "SELECT UNIX_TIMESTAMP(UTC_TIMESTAMP(3)) now_epoch,
                    (SELECT UNIX_TIMESTAMP(MAX(dispatched_at))
                       FROM api_remote_permits
                      WHERE endpoint_key=? AND dispatched_at IS NOT NULL) last_dispatch_epoch,
                    (SELECT COUNT(*)
                       FROM api_request_logs
                      WHERE endpoint_path=? AND http_status=429 AND reached_remote=1
                        AND created_at>=DATE_SUB(UTC_TIMESTAMP(3),INTERVAL " . self::BILLING_429_ESCALATION_WINDOW_HOURS . " HOUR)) log_rate_count,
                    (SELECT COUNT(*)
                       FROM api_remote_permits
                      WHERE endpoint_key=? AND http_status=429 AND dispatched_at IS NOT NULL
                        AND COALESCE(completed_at,dispatched_at)>=DATE_SUB(UTC_TIMESTAMP(3),INTERVAL " . self::BILLING_429_ESCALATION_WINDOW_HOURS . " HOUR)) permit_rate_count,
                    (SELECT UNIX_TIMESTAMP(MAX(created_at))
                       FROM api_request_logs
                      WHERE endpoint_path=? AND http_status=429 AND reached_remote=1
                        AND created_at>=DATE_SUB(UTC_TIMESTAMP(3),INTERVAL " . self::BILLING_429_ESCALATION_WINDOW_HOURS . " HOUR)) last_log_rate_epoch,
                    (SELECT UNIX_TIMESTAMP(MAX(COALESCE(completed_at,dispatched_at)))
                       FROM api_remote_permits
                      WHERE endpoint_key=? AND http_status=429 AND dispatched_at IS NOT NULL
                        AND COALESCE(completed_at,dispatched_at)>=DATE_SUB(UTC_TIMESTAMP(3),INTERVAL " . self::BILLING_429_ESCALATION_WINDOW_HOURS . " HOUR)) last_permit_rate_epoch,
                    (SELECT COALESCE(retry_after_seconds,0)
                       FROM api_request_logs
                      WHERE endpoint_path=? AND http_status=429 AND reached_remote=1
                        AND created_at>=DATE_SUB(UTC_TIMESTAMP(3),INTERVAL " . self::BILLING_429_ESCALATION_WINDOW_HOURS . " HOUR)
                      ORDER BY created_at DESC,id DESC LIMIT 1) last_retry_after,
                    (SELECT UNIX_TIMESTAMP(blocked_until)
                       FROM api_rhythm_penalties
                      WHERE scope_key=? AND blocked_until>UTC_TIMESTAMP(3)
                      LIMIT 1) persisted_block_epoch"
        );
        $statement->execute([
            self::BILLING_ENDPOINT,
            self::BILLING_PATH,
            self::BILLING_ENDPOINT,
            self::BILLING_PATH,
            self::BILLING_ENDPOINT,
            self::BILLING_PATH,
            $this->endpointPenaltyKey(self::BILLING_ENDPOINT),
        ]);
        $row = $statement->fetch(PDO::FETCH_ASSOC) ?: [];
        $now = (float) ($row['now_epoch'] ?? microtime(true));
        $next = 0.0;
        $scope = 'billing_endpoint_interval';

        $lastDispatch = (float) ($row['last_dispatch_epoch'] ?? 0);
        if ($lastDispatch > 0) {
            $next = $lastDispatch + self::BILLING_MIN_INTERVAL_SECONDS;
        }

        $rateCount = max(0, (int) max(
            (int) ($row['log_rate_count'] ?? 0),
            (int) ($row['permit_rate_count'] ?? 0)
        ));
        $lastRate = max(
            (float) ($row['last_log_rate_epoch'] ?? 0),
            (float) ($row['last_permit_rate_epoch'] ?? 0)
        );
        $rateNext = 0.0;
        if ($rateCount > 0 && $lastRate > 0) {
            $backoffMinutes = $this->billing429BackoffMinutes();
            $policySeconds = ($backoffMinutes[min(4, $rateCount)] ?? $backoffMinutes[4]) * 60;
            $retryAfter = max(
                0,
                (int) ($row['last_retry_after'] ?? 0),
                (int) ($currentRetryAfterSeconds ?? 0)
            );
            $rateNext = $lastRate + max($policySeconds, $retryAfter);
            if ($rateNext >= $next) {
                $next = $rateNext;
                $scope = 'billing_429_backoff';
            }
        }

        $persistedBlock = (float) ($row['persisted_block_epoch'] ?? 0);
        if ($persistedBlock > $next) {
            $effectivePersistedBlock = $rateNext > 0.0 ? min($persistedBlock, $rateNext) : $persistedBlock;
            if ($effectivePersistedBlock > $next) {
                $next = $effectivePersistedBlock;
                $scope = 'billing_429_backoff';
            }
        }
        if ($next <= $now) {
            return null;
        }

        return [
            'message' => $scope === 'billing_429_backoff'
                ? 'Billing quedó aplazado por la protección progresiva posterior a HTTP 429.'
                : 'Billing admite una sola salida física cada quince minutos en toda la aplicación.',
            'next_safe_at' => $this->formatTimestamp($next),
            'scope' => $scope,
        ];
    }

    /** @return array{count:int,oldest:?string} */
    private function rollingCount(PDO $pdo, string $kind, int|string|null $value, int $windowSeconds, ?int $accountId = null): array
    {
        $where = "dispatched_at IS NOT NULL AND dispatched_at>DATE_SUB(UTC_TIMESTAMP(3),INTERVAL ? SECOND)";
        $params = [$windowSeconds];
        if ($kind === 'account') {
            $where .= ' AND meli_account_id=?';
            $params[] = (int) $value;
        } elseif ($kind === 'endpoint_shared') {
            $where .= ' AND endpoint_key=?';
            $params[] = (string) $value;
        }
        $stmt = $pdo->prepare(
            'SELECT COUNT(*) total,MIN(dispatched_at) oldest FROM api_remote_permits WHERE ' . $where
        );
        $stmt->execute($params);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        return ['count' => max(0, (int) ($row['total'] ?? 0)), 'oldest' => $row['oldest'] ?? null];
    }

    /** @return array{message:string,next_safe_at:string,scope:string} */
    private function rollingDeferred(?string $oldest, int $windowSeconds, string $scope): array
    {
        $oldestAt = $this->timestampMicros($oldest);
        $next = $oldestAt === null ? microtime(true) + 1 : $oldestAt + $windowSeconds + .001;
        return [
            'message' => 'La ventana rodante alcanzó su techo seguro de salidas HTTP.',
            'next_safe_at' => $this->formatTimestamp($next),
            'scope' => $scope,
        ];
    }

    /** @return list<array<string,mixed>> */
    private function activePenaltyRows(PDO $pdo, int $accountId, string $endpointKey): array
    {
        $stmt = $pdo->prepare(
            "SELECT scope_key,reduced_limit_per_minute,blocked_until,reduced_until
             FROM api_rhythm_penalties
             WHERE scope_key IN (?,?)
               AND (reduced_until>UTC_TIMESTAMP(3) OR blocked_until>UTC_TIMESTAMP(3))
             ORDER BY CASE WHEN scope_key=? THEN 0 ELSE 1 END"
        );
        $stmt->execute([
            'account:' . $accountId,
            $this->endpointPenaltyKey($endpointKey),
            $this->endpointPenaltyKey($endpointKey),
        ]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @param array<string,mixed> $permit */
    private function recordRateLimitPenalty(array $permit, ?int $httpStatus, ?int $retryAfterSeconds): void
    {
        if ($httpStatus !== 429 || empty($permit['enabled'])) {
            return;
        }
        try {
            $pdo = Database::connectionFresh();
            $stmt = $pdo->prepare(
                'SELECT meli_account_id,endpoint_key FROM api_remote_permits
                 WHERE permit_token=? AND owner_token=? LIMIT 1'
            );
            $stmt->execute([(string) $permit['permit_token'], (string) ($permit['owner_token'] ?? '')]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            $accountId = max(0, (int) ($row['meli_account_id'] ?? 0));
            $endpointKey = (string) ($row['endpoint_key'] ?? '');
            if ($accountId < 1 || $endpointKey === '') {
                return;
            }
            $current = max(1, (int) ($permit['current_adaptive_limit'] ?? $this->configuration()['current_adaptive_limit']));
            $reduced = max(1, (int) floor($current / 2));
            $retry = $this->rateLimitDelaySeconds($permit, $retryAfterSeconds, $endpointKey);
            $stableMinutes = max(15, min(240, $this->settings->int('api.rhythm.ramp_stable_after_429_minutes', 60)));
            $endpointUpsert = $pdo->prepare(
                "INSERT INTO api_rhythm_penalties
                 (scope_key,reduced_limit_per_minute,blocked_until,reduced_until,reason,updated_at)
                 VALUES (?, ?, DATE_ADD(UTC_TIMESTAMP(3),INTERVAL ? SECOND),
                         DATE_ADD(UTC_TIMESTAMP(3),INTERVAL ? MINUTE),'http_429',UTC_TIMESTAMP(3))
                 ON DUPLICATE KEY UPDATE
                    reduced_limit_per_minute=LEAST(reduced_limit_per_minute,VALUES(reduced_limit_per_minute)),
                    blocked_until=GREATEST(blocked_until,VALUES(blocked_until)),
                    reduced_until=GREATEST(reduced_until,VALUES(reduced_until)),
                    reason=VALUES(reason),updated_at=VALUES(updated_at)"
            );
            $endpointUpsert->execute([
                $this->endpointPenaltyKey($endpointKey),
                $reduced,
                $retry,
                $stableMinutes,
            ]);
            $accountUpsert = $pdo->prepare(
                "INSERT INTO api_rhythm_penalties
                 (scope_key,reduced_limit_per_minute,blocked_until,reduced_until,reason,updated_at)
                 VALUES (?, ?, UTC_TIMESTAMP(3),
                         DATE_ADD(UTC_TIMESTAMP(3),INTERVAL ? MINUTE),'http_429',UTC_TIMESTAMP(3))
                 ON DUPLICATE KEY UPDATE
                    reduced_limit_per_minute=LEAST(reduced_limit_per_minute,VALUES(reduced_limit_per_minute)),
                    blocked_until=UTC_TIMESTAMP(3),
                    reduced_until=GREATEST(reduced_until,VALUES(reduced_until)),
                    reason=VALUES(reason),updated_at=VALUES(updated_at)"
            );
            $accountUpsert->execute(['account:' . $accountId, $reduced, $stableMinutes]);
        } catch (Throwable) {
            // El request remoto ya quedó contabilizado. La excepción que se
            // propaga conserva next_safe_at aunque no se pueda persistir la
            // penalización adaptativa; Guard no es una autoridad 429.
        }
    }

    private function endpointPenaltyKey(string $endpointKey): string
    {
        return 'endpoint:shared:' . hash('sha256', $endpointKey);
    }

    /**
     * Devuelve la misma autoridad absoluta usada para persistir el breaker
     * compartido. El jitter es determinista por permiso: evita estampidas sin
     * volver imposible probar o explicar la postimagen.
     *
     * @param array<string,mixed> $permit
     */
    public function rateLimitNextSafeAt(array $permit, ?int $retryAfterSeconds): string
    {
        $endpointKey = (string) ($permit['endpoint_key'] ?? '');
        if (hash_equals(self::BILLING_ENDPOINT, $endpointKey)) {
            $block = $this->billingBlock(
                Database::connectionFresh(),
                $endpointKey,
                $retryAfterSeconds
            );
            if ($block !== null) {
                return (string) $block['next_safe_at'];
            }
        }
        return $this->formatTimestamp(
            microtime(true) + $this->rateLimitDelaySeconds($permit, $retryAfterSeconds, $endpointKey)
        );
    }

    /**
     * Fallback defensivo para un 429 que llegue sin el permiso original.
     * Conserva la misma configuración canónica y no introduce otro breaker.
     */
    public function conservativeRateLimitNextSafeAt(): string
    {
        return $this->formatTimestamp(
            microtime(true) + $this->rateLimitDelaySeconds([], null)
        );
    }

    /** @param array<string,mixed> $permit */
    private function rateLimitDelaySeconds(
        array $permit,
        ?int $retryAfterSeconds,
        string $endpointKey = ''
    ): int
    {
        $base = max(
            60,
            min(86400, $this->settings->int('api.rhythm.shared_429_backoff_seconds', 300))
        );
        $jitterMax = max(
            0,
            min(300, $this->settings->int('api.rhythm.shared_429_jitter_seconds', 30))
        );
        $token = (string) ($permit['permit_token'] ?? 'shared-rate-limit');
        $jitter = $jitterMax === 0
            ? 0
            : (int) (hexdec(substr(hash('sha256', $token), 0, 8)) % ($jitterMax + 1));
        // Retry-After nunca se reduce. El tope de un año es únicamente una
        // defensa de rango DATETIME, no el antiguo recorte artificial a 1 h.
        $delay = min(31536000, max($base, (int) ($retryAfterSeconds ?? 0)) + $jitter);
        if (hash_equals(self::BILLING_ENDPOINT, $endpointKey)) {
            $block = $this->billingBlock(
                Database::connectionFresh(),
                $endpointKey,
                $retryAfterSeconds
            );
            if ($block !== null) {
                $blockedUntil = strtotime((string) $block['next_safe_at'] . ' UTC');
                if ($blockedUntil !== false) {
                    $delay = max($delay, $blockedUntil - time());
                }
            }
        }
        return max(1, $delay);
    }

    /** @param array<string,mixed> $permit */
    private function transition(array $permit, string $from, string $to, ?int $status): bool
    {
        if (empty($permit['enabled']) || empty($permit['permit_token'])) {
            return true;
        }
        $stmt = Database::connectionFresh()->prepare(
            'UPDATE api_remote_permits SET status=?,http_status=?,
                    completed_at=COALESCE(?,completed_at),released_at=COALESCE(?,released_at),
                    updated_at=UTC_TIMESTAMP(3)
             WHERE permit_token=? AND owner_token=? AND generation=? AND status=?'
        );
        $stmt->execute([
            $to,
            $status,
            $to === 'completed' ? gmdate('Y-m-d H:i:s') : null,
            $to === 'released' ? gmdate('Y-m-d H:i:s') : null,
            (string) $permit['permit_token'],
            (string) ($permit['owner_token'] ?? ''),
            (int) ($permit['generation'] ?? 0),
            $from,
        ]);
        return $stmt->rowCount() === 1;
    }

    private function companyId(?int $accountId): ?int
    {
        if ($accountId === null || $accountId < 1) {
            return null;
        }
        $stmt = Database::connectionFresh()->prepare('SELECT company_id FROM meli_accounts WHERE id=? LIMIT 1');
        $stmt->execute([$accountId]);
        $value = $stmt->fetchColumn();
        if ($value === false) {
            throw new \RuntimeException('La cuenta no pertenece a una empresa válida.');
        }
        return (int) $value;
    }

    private function schemaReady(): bool
    {
        if (self::$schemaAvailable !== null) {
            return self::$schemaAvailable;
        }
        $schema = new SchemaInspectorService();
        return self::$schemaAvailable = $schema->hasTable('api_rhythm_states')
            && $schema->hasTable('api_remote_permits');
    }

    private function timestampMicros(mixed $value): ?float
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }
        $date = DateTimeImmutable::createFromFormat('Y-m-d H:i:s.u', $value, new DateTimeZone('UTC'))
            ?: DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $value, new DateTimeZone('UTC'));
        return $date ? (float) $date->format('U.u') : null;
    }

    private function formatTimestamp(float $timestamp): string
    {
        $seconds = (int) ceil($timestamp);
        return (new DateTimeImmutable('@' . $seconds))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }
}
