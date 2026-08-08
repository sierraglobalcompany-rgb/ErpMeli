<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use DateTimeImmutable;
use DateTimeZone;
use PDO;
use Throwable;

/**
 * Espacia consultas reales entre procesos. ApiBudgetService sigue siendo el
 * límite duro; este servicio evita consumirlo en ráfagas.
 */
final class ApiPacingService
{
    /** @param array<string,mixed> $meta @return array<string,int|string|bool> */
    public function reserve(?int $accountId, string $method, string $path, array $meta = []): array
    {
        $settings = new AppSettingsService();
        if (!$settings->bool('api.pacing.enabled', true)
            || !(new SchemaInspectorService())->hasTable('api_request_pacing_state')) {
            return ['enabled' => false, 'effective_rpm' => 0, 'wait_ms' => 0, 'operation_key' => 'disabled'];
        }

        $profile = (new MeliOperationProfileRegistry())->resolve($method, $path, $meta);
        if (empty($profile['uses_api'])) {
            return ['enabled' => false, 'effective_rpm' => 0, 'wait_ms' => 0, 'operation_key' => (string) $profile['key']];
        }

        $globalRpm = max(1, min(59, $settings->int('api.pacing.ceiling_rpm', 20)));
        $accountRpm = $accountId !== null && $accountId > 0
            ? min($globalRpm, $this->accountBudgetRpm($settings))
            : $globalRpm;
        $adaptiveRpm = $this->adaptiveRpm($accountId, (string) $profile['key'], $accountRpm, $settings);
        $scopes = [
            ['key' => 'global', 'account_id' => null, 'rpm' => $globalRpm],
        ];
        if ($accountId !== null && $accountId > 0) {
            $scopes[] = [
                'key' => 'account:' . $accountId,
                'account_id' => $accountId,
                'rpm' => $adaptiveRpm,
            ];
        }

        $maxWait = max(1, min(55, $settings->int('api.pacing.max_wait_seconds', 55)));
        $allowedWaitSeconds = CronDeadlineContext::active()
            ? min($maxWait, max(0, CronDeadlineContext::remainingSeconds() - 2))
            : $maxWait;
        $slot = $this->reserveSlots(
            $scopes,
            (string) $profile['key'],
            (int) floor($allowedWaitSeconds * 1_000_000)
        );
        $waitUs = $slot['wait_us'];
        $waitSeconds = $waitUs / 1_000_000;
        if (!$slot['reserved']) {
            $nextSafeAt = (new DateTimeImmutable('now', new DateTimeZone('UTC')))
                ->modify('+' . max(1, (int) ceil($waitSeconds)) . ' seconds')
                ->format('Y-m-d H:i:s');
            throw new ApiBudgetExhaustedException(
                'La próxima consulta quedó espaciada para una ejecución posterior.',
                $nextSafeAt
            );
        }
        $this->wait($waitUs);

        return [
            'enabled' => true,
            'effective_rpm' => $adaptiveRpm,
            'wait_ms' => (int) ceil($waitUs / 1000),
            'operation_key' => (string) $profile['key'],
        ];
    }

    /** @return array<string,mixed> */
    public function explain(?int $accountId = null): array
    {
        $settings = new AppSettingsService();
        $ceiling = max(1, min(59, $settings->int('api.pacing.ceiling_rpm', 20)));
        $accountLimit = $accountId !== null && $accountId > 0
            ? min($ceiling, $this->accountBudgetRpm($settings))
            : $ceiling;
        return [
            'enabled' => $settings->bool('api.pacing.enabled', true),
            'adaptive' => $settings->bool('api.pacing.adaptive_enabled', true),
            'ceiling_rpm' => $ceiling,
            'account_rpm' => $accountLimit,
            'minimum_samples' => max(1, $settings->int('api.pacing.minimum_samples', 20)),
            'safe_p95_duration_ms' => max(500, $settings->int('api.pacing.safe_p95_duration_ms', 5000)),
        ];
    }

    private function accountBudgetRpm(AppSettingsService $settings): int
    {
        $window = max(60, $settings->int('api.budget.window_seconds', 900));
        $requests = max(1, $settings->int('api.budget.account_requests_per_15m', 120));
        return max(1, min(59, (int) floor($requests * 60 / $window)));
    }

    private function adaptiveRpm(?int $accountId, string $operationKey, int $baseRpm, AppSettingsService $settings): int
    {
        if (!$settings->bool('api.pacing.adaptive_enabled', true)) {
            return $baseRpm;
        }
        try {
            $metrics = (new MeliOperationTelemetryService())->summaries(168, $accountId)[$operationKey] ?? [];
            $percentile = (new MeliOperationTelemetryService())->percentiles(168, $accountId)[$operationKey] ?? [];
            $samples = (int) ($metrics['remote_count'] ?? 0);
            $minimum = max(1, $settings->int('api.pacing.minimum_samples', 20));
            if ($samples < $minimum) {
                return min($baseRpm, 5);
            }
            $successes = (int) ($metrics['success_count'] ?? 0);
            $localFailures = (int) ($metrics['local_failure_count'] ?? 0);
            $p95 = (int) ($percentile['p95_duration_ms'] ?? 0);
            $unsafe = $localFailures > 0
                || ($successes / $samples) < 0.95
                || $p95 > max(500, $settings->int('api.pacing.safe_p95_duration_ms', 5000))
                || $this->hasRecentRateLimit($accountId);
            return $unsafe ? max(1, (int) floor($baseRpm / 2)) : $baseRpm;
        } catch (Throwable) {
            return min($baseRpm, 5);
        }
    }

    private function hasRecentRateLimit(?int $accountId): bool
    {
        if (!(new SchemaInspectorService())->hasTable('api_request_logs')) {
            return false;
        }
        $sql = 'SELECT 1 FROM api_request_logs
                WHERE http_status=429 AND created_at>=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 60 MINUTE)';
        $params = [];
        if ($accountId !== null && $accountId > 0) {
            $sql .= ' AND meli_account_id=?';
            $params[] = $accountId;
        }
        $sql .= ' LIMIT 1';
        $stmt = Database::connectionFresh()->prepare($sql);
        $stmt->execute($params);
        return (bool) $stmt->fetchColumn();
    }

    /**
     * @param list<array{key:string,account_id:?int,rpm:int}> $scopes
     */
    /** @return array{wait_us:int,reserved:bool} */
    private function reserveSlots(array $scopes, string $operationKey, int $maximumWaitUs): array
    {
        $pdo = Database::connectionFresh();
        $pdo->beginTransaction();
        try {
            $insert = $pdo->prepare(
                'INSERT IGNORE INTO api_request_pacing_state
                    (scope_key,meli_account_id,operation_key,effective_rpm,next_allowed_at)
                 VALUES (?,?,?,?,UTC_TIMESTAMP(6))'
            );
            foreach ($scopes as $scope) {
                $insert->execute([$scope['key'], $scope['account_id'], $operationKey, $scope['rpm']]);
            }
            $keys = array_column($scopes, 'key');
            sort($keys, SORT_STRING);
            $select = $pdo->prepare(
                'SELECT scope_key,
                        GREATEST(0,TIMESTAMPDIFF(MICROSECOND,UTC_TIMESTAMP(6),next_allowed_at)) wait_us
                 FROM api_request_pacing_state
                 WHERE scope_key IN (' . implode(',', array_fill(0, count($keys), '?')) . ')
                 ORDER BY scope_key FOR UPDATE'
            );
            $select->execute($keys);
            $waitUs = 0;
            foreach ($select->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $waitUs = max($waitUs, (int) $row['wait_us']);
            }
            if ($waitUs > max(0, $maximumWaitUs)) {
                $pdo->rollBack();
                return ['wait_us' => $waitUs, 'reserved' => false];
            }
            $update = $pdo->prepare(
                'UPDATE api_request_pacing_state
                 SET operation_key=?,effective_rpm=?,
                     last_reserved_at=DATE_ADD(UTC_TIMESTAMP(6),INTERVAL ? MICROSECOND),
                     next_allowed_at=DATE_ADD(
                        UTC_TIMESTAMP(6),
                        INTERVAL ? MICROSECOND
                     ),
                     last_wait_ms=?
                 WHERE scope_key=?'
            );
            foreach ($scopes as $scope) {
                $intervalUs = (int) ceil(60_000_000 / max(1, $scope['rpm']));
                $update->execute([
                    $operationKey,
                    $scope['rpm'],
                    $waitUs,
                    $waitUs + $intervalUs,
                    (int) ceil($waitUs / 1000),
                    $scope['key'],
                ]);
            }
            $pdo->commit();
            return ['wait_us' => $waitUs, 'reserved' => true];
        } catch (Throwable $error) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $error;
        }
    }

    private function wait(int $waitUs): void
    {
        while ($waitUs > 0) {
            $chunk = min(500_000, $waitUs);
            usleep($chunk);
            $waitUs -= $chunk;
        }
    }
}
