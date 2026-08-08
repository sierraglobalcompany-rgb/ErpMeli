<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Env;
use DateTimeImmutable;
use PDO;
use PDOException;
use RuntimeException;
use Throwable;

final class CronV3RateGate
{
    private readonly PDO $pdo;
    private readonly string $applicationId;
    private ?bool $scopedSchemaAvailable = null;

    public function __construct(PDO $pdo, ?string $applicationId = null)
    {
        $this->pdo = $pdo;
        $this->applicationId = $this->normalizeApplicationId(
            $applicationId ?? Env::get('MELI_CLIENT_ID', '') ?? ''
        );
    }

    /** @return array{allowed:bool,retry_at:?string,reason:string} */
    public function reserve(
        WorkEnvelope $work,
        string $ownerToken,
        int $limit = 10,
        int $windowSeconds = 60,
    ): array {
        $limit = max(1, min(300, $limit));
        $windowSeconds = max(10, min(3600, $windowSeconds));
        $circuitKey = $this->circuitKey($work);

        for ($transactionAttempt = 1; $transactionAttempt <= 5; $transactionAttempt++) {
            try {
                $scopedSchema = $this->supportsScopedSchema();
                if (!$scopedSchema) {
                    throw new RuntimeException('Cron V3 scoped rate schema is unavailable.');
                }
                $scopes = $this->scopedBucketsFor($work);
                if (count($scopes) !== 5) {
                    throw new RuntimeException('Cron V3 scoped rate authority is unavailable.');
                }

                $this->pdo->beginTransaction();
                $this->pdo->prepare(
                    'INSERT IGNORE INTO cron_v3_circuit_states
                     (scope_key,company_id,meli_account_id,work_type,state,failure_count,generation,updated_at)
                     VALUES (?,?,?,? ,"closed",0,0,UTC_TIMESTAMP(3))'
                )->execute([$circuitKey, $work->companyId, $work->meliAccountId, $work->workType]);
                $circuitStatement = $this->pdo->prepare(
                    'SELECT * FROM cron_v3_circuit_states WHERE scope_key=? FOR UPDATE'
                );
                $circuitStatement->execute([$circuitKey]);
                $circuit = $circuitStatement->fetch(PDO::FETCH_ASSOC);
                if (!is_array($circuit)) {
                    throw new RuntimeException('Cron V3 circuit authority is unavailable.');
                }

                $now = new DateTimeImmutable('now', new \DateTimeZone('UTC'));
                $openUntilValue = $circuit['open_until'] ?? null;
                $openUntil = is_string($openUntilValue) && $openUntilValue !== ''
                    ? new DateTimeImmutable($openUntilValue, new \DateTimeZone('UTC'))
                    : null;
                if ((string) $circuit['state'] === 'open' && $openUntil !== null && $openUntil > $now) {
                    $this->pdo->commit();
                    return ['allowed' => false, 'retry_at' => $openUntil->format('Y-m-d H:i:s'), 'reason' => 'circuit_open'];
                }
                if ((string) $circuit['state'] === 'open') {
                    if (!empty($circuit['probe_owner']) && !hash_equals((string) $circuit['probe_owner'], $ownerToken)) {
                        $this->pdo->commit();
                        return ['allowed' => false, 'retry_at' => gmdate('Y-m-d H:i:s', time() + 30), 'reason' => 'half_open_probe_busy'];
                    }
                    $this->pdo->prepare(
                        'UPDATE cron_v3_circuit_states
                         SET state="half_open",probe_owner=?,generation=generation+1,updated_at=UTC_TIMESTAMP(3)
                         WHERE scope_key=?'
                    )->execute([$ownerToken, $circuitKey]);
                }
                if ((string) $circuit['state'] === 'half_open'
                    && !empty($circuit['probe_owner'])
                    && !hash_equals((string) $circuit['probe_owner'], $ownerToken)) {
                    $this->pdo->commit();
                    return ['allowed' => false, 'retry_at' => gmdate('Y-m-d H:i:s', time() + 30), 'reason' => 'half_open_probe_busy'];
                }

                foreach ($scopes as $scope) {
                    $bucket = $this->lockBucket($scope, $windowSeconds, $limit, $now, $scopedSchema);
                    if ($bucket['blocked']) {
                        $this->pdo->commit();
                        return [
                            'allowed' => false,
                            'retry_at' => $bucket['retry_at'],
                            'reason' => $bucket['reason'] . '_' . $scope['level'],
                        ];
                    }
                }

                CronDeadlineContext::assertCanStartRemote(2.0);

                $increment = $this->pdo->prepare(
                    'UPDATE cron_v3_rate_buckets
                     SET used_count=used_count+1,version=version+1,updated_at=UTC_TIMESTAMP(3)
                     WHERE scope_key=?'
                );
                foreach ($scopes as $scope) {
                    $increment->execute([$scope['key']]);
                    if ($increment->rowCount() !== 1) {
                        throw new RuntimeException('Cron V3 rate reservation lost a scoped authority.');
                    }
                }
                $this->pdo->commit();
                return ['allowed' => true, 'retry_at' => null, 'reason' => 'reserved'];
            } catch (Throwable $error) {
                if ($this->pdo->inTransaction()) {
                    $this->pdo->rollBack();
                }
                if ($error instanceof PDOException
                    && $transactionAttempt < 5
                    && $this->isRetryableTransactionError($error)) {
                    usleep(random_int(10000, 50000));
                    continue;
                }
                return ['allowed' => false, 'retry_at' => gmdate('Y-m-d H:i:s', time() + 60), 'reason' => 'rate_authority_unavailable'];
            }
        }
        return ['allowed' => false, 'retry_at' => gmdate('Y-m-d H:i:s', time() + 60), 'reason' => 'rate_authority_unavailable'];
    }

    public function recordResult(WorkEnvelope $work, WorkResult $result, string $ownerToken): bool
    {
        $circuitKey = $this->circuitKey($work);
        $ownsTransaction = !$this->pdo->inTransaction();
        if ($ownerToken === '' || !$this->supportsScopedSchema()) {
            throw new RuntimeException('Cron V3 fenced result authority is unavailable.');
        }
        if ($ownsTransaction) {
            $this->pdo->beginTransaction();
        }
        try {
            if ($result->status === 'completed') {
                $statement = $this->pdo->prepare(
                    'UPDATE cron_v3_circuit_states
                     SET state="closed",failure_count=0,open_until=NULL,probe_owner=NULL,
                         generation=generation+1,updated_at=UTC_TIMESTAMP(3)
                     WHERE scope_key=? AND state="half_open" AND probe_owner=?'
                );
                $statement->execute([$circuitKey, $ownerToken]);
                if ($ownsTransaction) {
                    $this->pdo->commit();
                }
                return true;
            }
            if ($result->code === 'http_429') {
                $retryAt = $result->availableAt ?? gmdate('Y-m-d H:i:s', time() + 60);
                $scopes = $this->scopedBucketsFor($work);
                if (count($scopes) !== 5) {
                    throw new RuntimeException('Cron V3 scoped rate authority is unavailable.');
                }
                $block = $this->pdo->prepare(
                    'UPDATE cron_v3_rate_buckets
                     SET blocked_until=CASE
                         WHEN blocked_until IS NULL OR blocked_until < ? THEN ?
                         ELSE blocked_until END,
                         updated_at=UTC_TIMESTAMP(3)
                     WHERE scope_key=?'
                );
                foreach ($scopes as $scope) {
                    $block->execute([$retryAt, $retryAt, $scope['key']]);
                }
                $this->pdo->prepare(
                    'UPDATE cron_v3_circuit_states
                     SET state="open",failure_count=failure_count+1,open_until=?,probe_owner=NULL,
                         generation=generation+1,updated_at=UTC_TIMESTAMP(3)
                     WHERE scope_key=?'
                )->execute([$retryAt, $circuitKey]);
                if ($ownsTransaction) {
                    $this->pdo->commit();
                }
                return true;
            }
            if ($result->status === 'review' && $result->code === 'remote_result_uncertain') {
                $retryAt = gmdate('Y-m-d H:i:s', time() + 60);
                $this->pdo->prepare(
                    'UPDATE cron_v3_circuit_states
                     SET state="open",failure_count=failure_count+1,open_until=?,probe_owner=NULL,
                         generation=generation+1,updated_at=UTC_TIMESTAMP(3)
                     WHERE scope_key=?'
                )->execute([$retryAt, $circuitKey]);
            }
            if ($ownsTransaction) {
                $this->pdo->commit();
            }
            return true;
        } catch (Throwable $error) {
            if ($ownsTransaction) {
                try {
                    $this->pdo->rollBack();
                } catch (Throwable) {
                }
            }
            throw $error;
        }
    }

    /**
     * @param array{key:string,level:string,application_id:?string,company_id:int,meli_account_id:int,endpoint_key:?string,operation_key:?string,work_type:string} $scope
     * @return array{blocked:bool,retry_at:?string,reason:string}
     */
    private function lockBucket(
        array $scope,
        int $windowSeconds,
        int $limit,
        DateTimeImmutable $now,
        bool $scopedSchema,
    ): array {
        if ($scopedSchema) {
            $this->pdo->prepare(
                'INSERT IGNORE INTO cron_v3_rate_buckets
                 (scope_key,scope_level,application_id,company_id,meli_account_id,endpoint_key,operation_key,
                  work_type,window_started_at,window_seconds,limit_count,used_count,version,updated_at)
                 VALUES (?,?,?,?,?,?,?,?,UTC_TIMESTAMP(3),?,?,0,0,UTC_TIMESTAMP(3))'
            )->execute([
                $scope['key'], $scope['level'], $scope['application_id'], $scope['company_id'],
                $scope['meli_account_id'], $scope['endpoint_key'], $scope['operation_key'],
                $scope['work_type'], $windowSeconds, $limit,
            ]);
        } else {
            $this->pdo->prepare(
                'INSERT IGNORE INTO cron_v3_rate_buckets
                 (scope_key,company_id,meli_account_id,work_type,window_started_at,window_seconds,
                  limit_count,used_count,version,updated_at)
                 VALUES (?,?,?,?,UTC_TIMESTAMP(3),?,?,0,0,UTC_TIMESTAMP(3))'
            )->execute([
                $scope['key'], $scope['company_id'], $scope['meli_account_id'],
                $scope['work_type'], $windowSeconds, $limit,
            ]);
        }

        $statement = $this->pdo->prepare(
            'SELECT *,DATE_ADD(window_started_at,INTERVAL window_seconds SECOND) window_ends_at
             FROM cron_v3_rate_buckets WHERE scope_key=? FOR UPDATE'
        );
        $statement->execute([$scope['key']]);
        $bucket = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($bucket)) {
            throw new RuntimeException('Cron V3 rate authority is unavailable.');
        }

        $windowEnds = new DateTimeImmutable((string) $bucket['window_ends_at'], new \DateTimeZone('UTC'));
        if ($windowEnds <= $now) {
            $this->pdo->prepare(
                'UPDATE cron_v3_rate_buckets
                 SET window_started_at=UTC_TIMESTAMP(3),window_seconds=?,limit_count=?,used_count=0,
                     version=version+1,updated_at=UTC_TIMESTAMP(3) WHERE scope_key=?'
            )->execute([$windowSeconds, $limit, $scope['key']]);
            $bucket['used_count'] = 0;
            $bucket['limit_count'] = $limit;
            $windowEnds = $now->modify('+' . $windowSeconds . ' seconds');
        } elseif ((int) $bucket['limit_count'] > $limit) {
            $this->pdo->prepare(
                'UPDATE cron_v3_rate_buckets SET limit_count=?,updated_at=UTC_TIMESTAMP(3) WHERE scope_key=?'
            )->execute([$limit, $scope['key']]);
            $bucket['limit_count'] = $limit;
        }

        $blockedUntilValue = $bucket['blocked_until'] ?? null;
        $blockedUntil = is_string($blockedUntilValue) && $blockedUntilValue !== ''
            ? new DateTimeImmutable($blockedUntilValue, new \DateTimeZone('UTC'))
            : null;
        if ($blockedUntil !== null && $blockedUntil > $now) {
            return ['blocked' => true, 'retry_at' => $blockedUntil->format('Y-m-d H:i:s'), 'reason' => 'retry_after'];
        }
        if ((int) $bucket['used_count'] >= (int) $bucket['limit_count']) {
            return ['blocked' => true, 'retry_at' => $windowEnds->format('Y-m-d H:i:s'), 'reason' => 'rate_limit'];
        }
        return ['blocked' => false, 'retry_at' => null, 'reason' => 'available'];
    }

    /**
     * @return list<array{key:string,level:string,application_id:?string,company_id:int,meli_account_id:int,endpoint_key:?string,operation_key:?string,work_type:string}>
     */
    private function scopedBucketsFor(WorkEnvelope $work): array
    {
        $profile = $this->remoteProfile($work->workType);
        if ($this->applicationId === '' || $profile === null) {
            return [];
        }

        $applicationId = $this->applicationId;
        $endpointKey = $profile['endpoint_key'];
        $operationKey = $profile['operation_key'];
        $base = [
            'application_id' => $applicationId,
            'company_id' => $work->companyId,
            'meli_account_id' => $work->meliAccountId,
            'endpoint_key' => $endpointKey,
            'operation_key' => $operationKey,
            'work_type' => $work->workType,
        ];

        return [
            $this->legacyGlobalBucket(),
            array_merge($base, [
                'key' => hash('sha256', 'cron_v3|application|' . $applicationId),
                'level' => 'application',
                'company_id' => 0,
                'meli_account_id' => 0,
                'endpoint_key' => null,
                'operation_key' => null,
                'work_type' => '__application__',
            ]),
            array_merge($base, [
                'key' => hash('sha256', 'cron_v3|account|' . $applicationId . '|' . $work->companyId . '|' . $work->meliAccountId),
                'level' => 'account',
                'endpoint_key' => null,
                'operation_key' => null,
                'work_type' => '__account__',
            ]),
            array_merge($base, [
                'key' => hash('sha256', 'cron_v3|endpoint|' . $applicationId . '|' . $work->companyId . '|' . $work->meliAccountId . '|' . $endpointKey),
                'level' => 'endpoint',
                'operation_key' => null,
            ]),
            array_merge($base, [
                'key' => hash('sha256', 'cron_v3|operation|' . $applicationId . '|' . $work->companyId . '|' . $work->meliAccountId . '|' . $endpointKey . '|' . $operationKey),
                'level' => 'operation',
            ]),
        ];
    }

    /** @return array{key:string,level:string,application_id:?string,company_id:int,meli_account_id:int,endpoint_key:?string,operation_key:?string,work_type:string} */
    private function legacyGlobalBucket(): array
    {
        return [
            'key' => hash('sha256', 'cron_v3|global'),
            'level' => 'global',
            'application_id' => null,
            'company_id' => 0,
            'meli_account_id' => 0,
            'endpoint_key' => null,
            'operation_key' => null,
            'work_type' => '__global__',
        ];
    }

    /** @return array{endpoint_key:string,operation_key:string}|null */
    private function remoteProfile(string $workType): ?array
    {
        return match ($workType) {
            'oauth_refresh' => ['endpoint_key' => 'oauth.token', 'operation_key' => 'oauth'],
            'orders_search_page' => ['endpoint_key' => 'orders.search', 'operation_key' => 'orders_search'],
            'order_exact' => ['endpoint_key' => 'orders.exact', 'operation_key' => 'order_exact'],
            'pack_exact' => ['endpoint_key' => 'packs.exact', 'operation_key' => 'pack_exact'],
            'shipment_exact', 'module_logistics_exact' => ['endpoint_key' => 'shipments.exact', 'operation_key' => 'shipment_exact'],
            'sales_audit_page' => ['endpoint_key' => 'orders.search', 'operation_key' => 'sales_audit'],
            'sales_repair_exact' => ['endpoint_key' => 'orders.exact', 'operation_key' => 'order_exact'],
            'questions_search_page' => ['endpoint_key' => 'questions.search', 'operation_key' => 'questions_search'],
            'question_exact' => ['endpoint_key' => 'questions.exact', 'operation_key' => 'question_exact'],
            'claims_search_page' => ['endpoint_key' => 'claims.search', 'operation_key' => 'claims_search'],
            'claim_exact' => ['endpoint_key' => 'claims.exact', 'operation_key' => 'claim_exact'],
            'sale_billing_capture' => ['endpoint_key' => 'billing.order_details', 'operation_key' => 'billing_orders'],
            'items_search_page' => ['endpoint_key' => 'items.search', 'operation_key' => 'items_discovery'],
            'item_exact' => ['endpoint_key' => 'items.exact', 'operation_key' => 'item_detail'],
            'catalog_description_exact' => ['endpoint_key' => 'items.description', 'operation_key' => 'item_description'],
            default => null,
        };
    }

    private function circuitKey(WorkEnvelope $work): string
    {
        return hash('sha256', $work->companyId . '|' . $work->meliAccountId . '|' . $work->workType);
    }

    private function supportsScopedSchema(): bool
    {
        if ($this->scopedSchemaAvailable !== null) {
            return $this->scopedSchemaAvailable;
        }

        try {
            $driver = (string) $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
            if ($driver === 'mysql') {
                $statement = $this->pdo->query(
                    "SELECT COUNT(*) FROM information_schema.columns
                     WHERE table_schema=DATABASE() AND table_name='cron_v3_rate_buckets'
                       AND column_name IN ('scope_level','application_id','endpoint_key','operation_key')"
                );
                return $this->scopedSchemaAvailable = (int) $statement->fetchColumn() === 4;
            }
            if ($driver === 'sqlite') {
                $columns = $this->pdo->query("PRAGMA table_info('cron_v3_rate_buckets')")->fetchAll(PDO::FETCH_ASSOC);
                $names = array_column($columns, 'name');
                return $this->scopedSchemaAvailable = count(array_intersect(
                    ['scope_level', 'application_id', 'endpoint_key', 'operation_key'],
                    array_map('strval', $names),
                )) === 4;
            }
        } catch (Throwable) {
        }

        return $this->scopedSchemaAvailable = false;
    }

    private function normalizeApplicationId(string $applicationId): string
    {
        $applicationId = trim($applicationId);
        if ($applicationId === ''
            || strlen($applicationId) > 120
            || preg_match('/^[A-Za-z0-9._:-]+$/', $applicationId) !== 1) {
            return '';
        }
        return $applicationId;
    }

    private function isRetryableTransactionError(PDOException $error): bool
    {
        $driverCode = (string) ($error->errorInfo[1] ?? '');
        if (in_array($driverCode, ['1205', '1213'], true)) {
            return true;
        }
        $message = strtolower($error->getMessage());
        return str_contains($message, 'deadlock') || str_contains($message, 'lock wait timeout');
    }
}
