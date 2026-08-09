<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use PDO;
use Throwable;

/**
 * Reconstruye packs sin API y verifica gradualmente mediante trabajos CLI.
 */
final class HistoricalPackReconciliationService
{
    /** @param (callable(int):MeliApiClient)|null $clientFactory */
    public function __construct(private $clientFactory=null){}

    public function enqueueRebuild(?int $accountId, ?int $createdBy): int
    {
        if ($accountId === null || $accountId <= 0) {
            $firstId = 0;
            foreach ((new BusinessScopeContext())->accountIds() as $authorizedAccountId) {
                $queuedId = $this->enqueueRebuild((int) $authorizedAccountId, $createdBy);
                $firstId = $firstId > 0 ? $firstId : $queuedId;
            }
            return $firstId;
        }
        $companyId = null;
        $account = (new BusinessScopeContext())->account($accountId);
        $companyId = (int) $account['company_id'];
        $pdo = Database::connectionFresh();
        $existing = $pdo->prepare(
            'SELECT id FROM sale_pack_rebuild_runs
             WHERE status IN ("pending","running")
               AND meli_account_id <=> ?
               AND company_id <=> ?
             ORDER BY id DESC LIMIT 1'
        );
        $existing->execute([$accountId, $companyId]);
        $id = (int) ($existing->fetchColumn() ?: 0);
        if ($id > 0) {
            return $id;
        }
        $insert = $pdo->prepare(
            'INSERT INTO sale_pack_rebuild_runs
                (company_id,meli_account_id,status,created_by)
             VALUES (?,?,"pending",?)'
        );
        $insert->execute([$companyId, $accountId, $createdBy]);
        return (int) $pdo->lastInsertId();
    }

    /** @return array{packs:int,links:int,jobs:int} */
    public function rebuildLocal(
        ?int $accountId = null,
        ?int $createdBy = null,
        ?array $leaseRun = null
    ): array
    {
        if ($accountId === null || $accountId <= 0) {
            throw new \InvalidArgumentException('La reconstrucción debe indicar una cuenta autorizada.');
        }
        $pdo = Database::connectionFresh();
        $where = 'o.external_pack_id IS NOT NULL AND o.meli_account_id=?';
        $params = [$accountId];
        $stmt = $pdo->prepare(
            'SELECT o.meli_account_id,a.company_id,o.external_pack_id,
                    COUNT(DISTINCT o.id) linked_count,
                    MAX(o.external_shipping_id) external_shipment_id
             FROM meli_orders o
             JOIN meli_accounts a ON a.id=o.meli_account_id
             WHERE ' . $where . '
             GROUP BY o.meli_account_id,a.company_id,o.external_pack_id
             ORDER BY o.meli_account_id,o.external_pack_id'
        );
        $stmt->execute($params);
        $groups = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $packs = $links = $jobs = 0;
        foreach ($groups as $group) {
            $pdo->beginTransaction();
            try {
                if ($leaseRun !== null) {
                    $this->assertRebuildLeaseInTransaction($pdo, $leaseRun);
                }
                $upsert = $pdo->prepare(
                    'INSERT INTO meli_packs
                        (meli_account_id,external_pack_id,external_shipment_id,status,integrity_status,
                         linked_orders_count,integrity_message,synced_at)
                     VALUES (?,?,?,"provisional","provisional",?,
                        "Relación reconstruida con datos locales; falta verificación remota.",UTC_TIMESTAMP())
                     ON DUPLICATE KEY UPDATE
                        external_shipment_id=COALESCE(external_shipment_id,VALUES(external_shipment_id)),
                        linked_orders_count=VALUES(linked_orders_count),
                        integrity_status=IF(verified_at IS NULL,"provisional",integrity_status),
                        integrity_message=IF(verified_at IS NULL,VALUES(integrity_message),integrity_message),
                        id=LAST_INSERT_ID(id)'
                );
                $upsert->execute([
                    (int) $group['meli_account_id'],
                    (string) $group['external_pack_id'],
                    $group['external_shipment_id'] ?: null,
                    (int) $group['linked_count'],
                ]);
                $packId = (int) $pdo->lastInsertId();
                $pdo->prepare(
                    'DELETE po
                     FROM meli_pack_orders po
                     JOIN meli_orders o ON o.id=po.meli_order_id
                     WHERE o.meli_account_id=?
                       AND o.external_pack_id=?
                       AND po.meli_pack_id<>?'
                )->execute([
                    (int) $group['meli_account_id'],
                    (string) $group['external_pack_id'],
                    $packId,
                ]);
                $link = $pdo->prepare(
                    'INSERT IGNORE INTO meli_pack_orders (meli_pack_id,meli_order_id)
                     SELECT ?,o.id FROM meli_orders o
                     WHERE o.meli_account_id=? AND o.external_pack_id=?'
                );
                $link->execute([$packId, (int) $group['meli_account_id'], (string) $group['external_pack_id']]);
                $links += $link->rowCount();
                $job = $pdo->prepare(
                    'INSERT INTO sale_pack_reconciliation_jobs
                        (company_id,meli_account_id,meli_pack_id,operation,external_resource_id,status,priority,created_by)
                     VALUES (?,?,?,"verify_pack",?,"pending",50,?)
                     ON DUPLICATE KEY UPDATE
                        meli_pack_id=VALUES(meli_pack_id),
                        status=IF(status IN ("complete","running"),status,"pending"),
                        next_run_at=IF(status IN ("complete","running"),next_run_at,UTC_TIMESTAMP())'
                );
                $job->execute([
                    (int) $group['company_id'],
                    (int) $group['meli_account_id'],
                    $packId,
                    (string) $group['external_pack_id'],
                    $createdBy,
                ]);
                $jobs += $job->rowCount() > 0 ? 1 : 0;
                $this->history($pdo, (int) $group['company_id'], (int) $group['meli_account_id'], $packId, 'local_rebuild', 'provisional', (int) $group['linked_count'], null, 'Relaciones locales reconstruidas.');
                $pdo->commit();
                $packs++;
            } catch (Throwable $error) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                throw $error;
            }
        }
        return compact('packs', 'links', 'jobs');
    }

    /** @return array{processed:int,completed:int,errors:int,deferred:int,stop_reason:string} */
    public function processDue(int $limit = 1): array
    {
        return $this->processSelected($limit, null);
    }

    /** @return array{processed:int,completed:int,errors:int,deferred:int,stop_reason:string} */
    public function processExact(int $jobId): array
    {
        return $this->processSelected(1, $jobId);
    }

    /** Un solo GET físico y ninguna continuación. */
    public function processManualExact(int $jobId): array
    {
        $s=Database::connectionFresh()->prepare('SELECT operation FROM sale_pack_reconciliation_jobs WHERE id=? LIMIT 1');
        $s->execute([$jobId]);
        $operation=(string)$s->fetchColumn();
        if(!in_array($operation,['recover_order','verify_pack'],true))throw new \RuntimeException('Operación de pack no certificada.');
        return $this->processSelected(1,$jobId,true,false);
    }

    /** @return array{processed:int,completed:int,errors:int,deferred:int,stop_reason:string} */
    private function processSelected(int $limit, ?int $jobId, bool $manualExact=false, bool $allowContinuation=true): array
    {
        if (PHP_SAPI !== 'cli' && !$manualExact) {
            throw new \App\Core\HttpException(404, 'Esta operación pertenece al lanzador CLI.');
        }
        $summary = ['processed' => 0, 'completed' => 0, 'errors' => 0, 'deferred' => 0, 'stop_reason' => 'empty'];
        $previousAccountId = null;
        for ($i = 0; $i < max(1, min(10, $limit)); $i++) {
            $rebuild = $jobId === null ? $this->claimRebuild() : null;
            if ($rebuild !== null) {
                $summary['processed']++;
                try {
                    $result = $this->rebuildLocal(
                        !empty($rebuild['meli_account_id']) ? (int) $rebuild['meli_account_id'] : null,
                        !empty($rebuild['created_by']) ? (int) $rebuild['created_by'] : null,
                        $rebuild
                    );
                    $this->finishRebuild($rebuild, 'complete', $result, 'Reconstrucción local completada.');
                    $summary['completed']++;
                    $summary['stop_reason'] = 'local_rebuild_completed';
                } catch (Throwable $error) {
                    $this->finishRebuild(
                        $rebuild,
                        'error',
                        ['packs' => 0, 'links' => 0, 'jobs' => 0],
                        SafeErrorPresenter::message($error, 'No fue posible reconstruir las relaciones locales.')
                    );
                    $summary['errors']++;
                    $summary['stop_reason'] = 'local_rebuild_error';
                }
                continue;
            }
            $job = $this->claim($jobId, $previousAccountId);
            if ($job === null) {
                break;
            }
            $previousAccountId = (int) $job['meli_account_id'];
            $summary['processed']++;
            try {
                if ($job['operation'] === 'verify_pack') {
                    $this->verifyPack($job,$allowContinuation);
                } else {
                    $this->recoverOrder($job);
                }
                $summary['completed']++;
                $summary['stop_reason'] = 'work_completed';
            } catch (Throwable $error) {
                if ($error instanceof RemoteResultUncertainException) {
                    $this->finishJob($job, 'error', SafeErrorPresenter::message($error, 'Resultado remoto pendiente de revisión.'));
                    $summary['errors']++;
                    $summary['stop_reason'] = 'action_required';
                    break;
                }
                if ($error instanceof MeliApiException && $error->httpStatus === 404) {
                    $this->markUnavailable($job);
                    $summary['completed']++;
                    $summary['stop_reason'] = 'expected_absence';
                    continue;
                }
                $retry = (int) $job['attempts'] < 3;
                $this->finishJob($job, $retry ? 'retry' : 'error', SafeErrorPresenter::message(
                    $error,
                    'No fue posible completar este paso de reconstrucción.'
                ));
                $retry ? $summary['deferred']++ : $summary['errors']++;
                $summary['stop_reason'] = $retry ? 'automatic_retry' : 'persistent_error';
                if ($error instanceof ApiBudgetExhaustedException) {
                    $summary['stop_reason'] = $error instanceof ApiRhythmDeferredException ? 'api_rhythm' : 'api_budget';
                    break;
                }
            }
        }
        return $summary;
    }

    /** @param array<string,mixed> $job */
    private function markUnavailable(array $job): void
    {
        $pdo = Database::connectionFresh();
        $pdo->beginTransaction();
        try {
            $this->assertJobLeaseInTransaction($pdo, $job);
            if ((string) $job['operation'] === 'recover_order'
                && (new SchemaInspectorService())->hasTable('meli_pack_order_expectations')) {
                $pdo->prepare(
                    'UPDATE meli_pack_order_expectations
                     SET status="unavailable",verified_at=UTC_TIMESTAMP()
                     WHERE meli_pack_id=? AND meli_account_id=? AND external_order_id=?'
                )->execute([
                    (int) $job['meli_pack_id'],
                    (int) $job['meli_account_id'],
                    (string) $job['external_resource_id'],
                ]);
            }
            $message = (string) $job['operation'] === 'verify_pack'
                ? 'El pack ya no está disponible para verificación remota.'
                : 'Una orden esperada ya no está disponible para recuperación.';
            $pdo->prepare(
                'UPDATE meli_packs
                 SET integrity_status="review",integrity_message=?
                 WHERE id=? AND meli_account_id=?'
            )->execute([$message, (int) $job['meli_pack_id'], (int) $job['meli_account_id']]);
            $counts = $pdo->prepare(
                'SELECT linked_orders_count,expected_orders_count
                 FROM meli_packs WHERE id=? AND meli_account_id=?'
            );
            $counts->execute([(int) $job['meli_pack_id'], (int) $job['meli_account_id']]);
            $countRow = $counts->fetch(PDO::FETCH_ASSOC) ?: [];
            $this->history(
                $pdo,
                (int) $job['company_id'],
                (int) $job['meli_account_id'],
                (int) $job['meli_pack_id'],
                'expected_absence',
                'review',
                (int) ($countRow['linked_orders_count'] ?? 0),
                isset($countRow['expected_orders_count']) ? (int) $countRow['expected_orders_count'] : null,
                $message
            );
            $this->finishJobInTransaction(
                $pdo,
                $job,
                'complete',
                'El recurso ya no está disponible en Mercado Libre.'
            );
            $pdo->commit();
        } catch (Throwable $error) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $error;
        }
    }

    /** @return array<string,mixed>|null */
    private function claimRebuild(): ?array
    {
        $pdo = Database::connectionFresh();
        $owner = bin2hex(random_bytes(16));
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->query(
                'SELECT * FROM sale_pack_rebuild_runs
                 WHERE status="pending"
                   AND (lease_expires_at IS NULL OR lease_expires_at<UTC_TIMESTAMP())
                 ORDER BY created_at,id LIMIT 1 FOR UPDATE'
            );
            $run = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!is_array($run)) {
                $pdo->commit();
                return null;
            }
            $generation = (int) $run['lease_generation'] + 1;
            $pdo->prepare(
                'UPDATE sale_pack_rebuild_runs
                 SET status="running",lock_owner=?,lease_generation=?,
                     lease_expires_at=DATE_ADD(UTC_TIMESTAMP(),INTERVAL 3 MINUTE)
                 WHERE id=?'
            )->execute([$owner, $generation, (int) $run['id']]);
            $pdo->commit();
            $run['lock_owner'] = $owner;
            $run['lease_generation'] = $generation;
            return $run;
        } catch (Throwable $error) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $error;
        }
    }

    /** @param array<string,mixed> $run @param array{packs:int,links:int,jobs:int} $result */
    private function finishRebuild(array $run, string $status, array $result, string $message): void
    {
        $stmt = Database::connectionFresh()->prepare(
            'UPDATE sale_pack_rebuild_runs
             SET status=?,packs_count=?,links_count=?,jobs_count=?,safe_message=?,
                 completed_at=UTC_TIMESTAMP(),lock_owner=NULL,lease_expires_at=NULL
             WHERE id=? AND lock_owner=? AND lease_generation=?'
        );
        $stmt->execute([
            $status, $result['packs'], $result['links'], $result['jobs'],
            mb_substr($message, 0, 500), (int) $run['id'],
            (string) $run['lock_owner'], (int) $run['lease_generation'],
        ]);
        if ($stmt->rowCount() !== 1) {
            throw new \RuntimeException('La reconstrucción perdió su reserva temporal.');
        }
    }

    /** @return array<string,int> */
    public function summary(?int $accountId = null): array
    {
        $scope = new BusinessScopeContext();
        $ids = $accountId !== null && $accountId > 0
            ? [(int) $scope->account($accountId)['id']]
            : $scope->accountIds();
        $result = [
            'sales' => 0, 'complete' => 0, 'partial' => 0, 'provisional' => 0,
            'review' => 0, 'missing_orders' => 0, 'pending_jobs' => 0,
        ];
        if ($ids === []) {
            return $result;
        }
        $in = implode(',', array_fill(0, count($ids), '?'));
        $stmt = Database::connectionFresh()->prepare(
            'SELECT integrity_status,COUNT(*) total,
                    SUM(GREATEST(COALESCE(expected_orders_count,linked_orders_count)-linked_orders_count,0)) missing_orders
             FROM meli_packs
             WHERE meli_account_id IN (' . $in . ')
             GROUP BY integrity_status'
        );
        $stmt->execute($ids);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $state = (string) $row['integrity_status'];
            $result['sales'] += (int) $row['total'];
            if (array_key_exists($state, $result)) {
                $result[$state] += (int) $row['total'];
            }
            $result['missing_orders'] += (int) $row['missing_orders'];
        }
        $jobs = Database::connectionFresh()->prepare(
            'SELECT COUNT(*) FROM sale_pack_reconciliation_jobs
             WHERE meli_account_id IN (' . $in . ') AND status IN ("pending","retry","running","paused")'
        );
        $jobs->execute($ids);
        $result['pending_jobs'] = (int) $jobs->fetchColumn();
        return $result;
    }

    /** @return list<array<string,mixed>> */
    public function packs(?int $accountId = null, int $limit = 100): array
    {
        $scope = new BusinessScopeContext();
        $ids = $accountId !== null && $accountId > 0
            ? [(int) $scope->account($accountId)['id']]
            : $scope->accountIds();
        if ($ids === []) {
            return [];
        }
        $stmt = Database::connectionFresh()->prepare(
            'SELECT p.*,a.account_name,
                    GREATEST(COALESCE(p.expected_orders_count,p.linked_orders_count)-p.linked_orders_count,0) missing_orders
             FROM meli_packs p
             JOIN meli_accounts a ON a.id=p.meli_account_id
             WHERE p.meli_account_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')
             ORDER BY FIELD(p.integrity_status,"review","partial","provisional","pending","complete"),p.updated_at DESC
             LIMIT ' . max(1, min(200, $limit))
        );
        $stmt->execute($ids);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return array<string,mixed>|null */
    private function claim(?int $jobId = null, ?int $previousAccountId = null): ?array
    {
        $pdo = Database::connectionFresh();
        $owner = bin2hex(random_bytes(16));
        $pdo->beginTransaction();
        try {
            $reservationGuard = ManualCampaignReservationGuard::sql('sale_pack_reconciliation', 'sale_pack_reconciliation_jobs.id');
            $stmt = $pdo->prepare(
                'SELECT * FROM sale_pack_reconciliation_jobs
                 WHERE status IN ("pending","retry")
                   AND next_run_at<=UTC_TIMESTAMP()
                   AND (lease_expires_at IS NULL OR lease_expires_at<UTC_TIMESTAMP())
                   AND (? IS NULL OR id=?)' . $reservationGuard . '
                  ORDER BY priority,
                           CASE WHEN ? IS NOT NULL AND meli_account_id=? THEN 1 ELSE 0 END,
                           next_run_at,id
                 LIMIT 1 FOR UPDATE'
            );
            $stmt->execute([$jobId, $jobId, $previousAccountId, $previousAccountId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!is_array($row)) {
                $pdo->commit();
                return null;
            }
            $generation = (int) $row['lease_generation'] + 1;
            $update = $pdo->prepare(
                'UPDATE sale_pack_reconciliation_jobs
                 SET status="running",lock_owner=?,lease_generation=?,
                     lease_expires_at=DATE_ADD(UTC_TIMESTAMP(),INTERVAL 3 MINUTE),
                     heartbeat_at=UTC_TIMESTAMP(),started_at=COALESCE(started_at,UTC_TIMESTAMP()),
                     attempts=attempts+1
                 WHERE id=?'
            );
            $update->execute([$owner, $generation, (int) $row['id']]);
            $pdo->commit();
            $row['lock_owner'] = $owner;
            $row['lease_generation'] = $generation;
            $row['attempts'] = (int) $row['attempts'] + 1;
            return $row;
        } catch (Throwable $error) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $error;
        }
    }

    /** @param array<string,mixed> $job */
    private function verifyPack(array $job,bool $allowContinuation=true): void
    {
        $this->heartbeat($job);
        $accountId = (int) $job['meli_account_id'];
        $packExternalId = (string) $job['external_resource_id'];
        $pack = $this->client($accountId)->get(
            '/packs/' . rawurlencode($packExternalId),
            [],
            ['job_type' => 'sale_pack_reconciliation', 'source' => 'cron', 'bulk' => false]
        );
        $expected = [];
        foreach ((array) ($pack['orders'] ?? []) as $order) {
            $id = (string) ($order['id'] ?? '');
            if ($id !== '' && preg_match('/^\d+$/', $id) === 1) {
                $expected[$id] = $id;
            }
        }
        ksort($expected, SORT_STRING);
        $expected = array_values($expected);
        $pdo = Database::connectionFresh();
        $pdo->beginTransaction();
        try {
            $this->assertJobLeaseInTransaction($pdo, $job);
            $local = $pdo->prepare(
                'SELECT external_order_id,external_pack_id,id FROM meli_orders
                 WHERE meli_account_id=? AND external_order_id IN (' .
                ($expected === [] ? 'NULL' : implode(',', array_fill(0, count($expected), '?'))) . ')'
            );
            $local->execute(array_merge([$accountId], $expected));
            $linked = [];
            $mismatched = [];
            foreach ($local->fetchAll(PDO::FETCH_ASSOC) as $row) {
                if (!empty($row['external_pack_id'])
                    && (string) $row['external_pack_id'] !== $packExternalId) {
                    $mismatched[(string) $row['external_order_id']] = true;
                    continue;
                }
                if (empty($row['external_pack_id'])) {
                    $pdo->prepare(
                        'UPDATE meli_orders SET external_pack_id=? WHERE id=? AND meli_account_id=?'
                    )->execute([$packExternalId, (int) $row['id'], $accountId]);
                }
                $linked[(string) $row['external_order_id']] = (int) $row['id'];
                $pdo->prepare(
                    'INSERT IGNORE INTO meli_pack_orders (meli_pack_id,meli_order_id) VALUES (?,?)'
                )->execute([(int) $job['meli_pack_id'], (int) $row['id']]);
            }
            foreach ($expected as $orderId) {
                if ((new SchemaInspectorService())->hasTable('meli_pack_order_expectations')) {
                    $pdo->prepare(
                        'INSERT INTO meli_pack_order_expectations
                            (company_id,meli_account_id,meli_pack_id,external_order_id,meli_order_id,status,verified_at)
                         VALUES (?,?,?,?,?, ?,UTC_TIMESTAMP())
                         ON DUPLICATE KEY UPDATE
                            meli_order_id=VALUES(meli_order_id),status=VALUES(status),
                            verified_at=VALUES(verified_at)'
                    )->execute([
                        (int) $job['company_id'], $accountId, (int) $job['meli_pack_id'], $orderId,
                        $linked[$orderId] ?? null,
                        isset($mismatched[$orderId])
                            ? 'review'
                            : (isset($linked[$orderId]) ? 'linked' : 'missing'),
                    ]);
                }
                if (isset($linked[$orderId])) {
                    continue;
                }
                if (isset($mismatched[$orderId])) {
                    continue;
                }
                if($allowContinuation)$pdo->prepare(
                    'INSERT INTO sale_pack_reconciliation_jobs
                        (company_id,meli_account_id,meli_pack_id,operation,external_resource_id,status,priority)
                     VALUES (?,?,?,"recover_order",?,"pending",40)
                     ON DUPLICATE KEY UPDATE
                        meli_pack_id=VALUES(meli_pack_id),
                        status=IF(status="complete",status,"pending"),
                        next_run_at=IF(status="complete",next_run_at,UTC_TIMESTAMP())'
                )->execute([
                    (int) $job['company_id'], $accountId, (int) $job['meli_pack_id'], $orderId,
                ]);
            }
            $actualStmt = $pdo->prepare(
                'SELECT DISTINCT CAST(o.external_order_id AS CHAR)
                 FROM meli_pack_orders po
                 JOIN meli_orders o
                   ON o.id=po.meli_order_id
                  AND o.meli_account_id=?
                  AND o.external_pack_id=?
                 WHERE po.meli_pack_id=?
                 ORDER BY CAST(o.external_order_id AS CHAR)'
            );
            $actualStmt->execute([$accountId, $packExternalId, (int) $job['meli_pack_id']]);
            $actual = array_map('strval', $actualStmt->fetchAll(PDO::FETCH_COLUMN));
            $linkedCount = count($actual);
            $expectedCount = count($expected);
            $setsMatch = $expectedCount > 0 && $expected === $actual;
            $status = $mismatched !== []
                ? 'review'
                : ($setsMatch ? 'complete' : 'partial');
            $message = $status === 'complete'
                ? 'Todas las órdenes esperadas están enlazadas.'
                : ($status === 'review'
                    ? 'Una orden local está relacionada con otro pack y requiere revisión.'
                    : 'Faltan ' . max(0, $expectedCount - $linkedCount) . ' órdenes por recuperar.');
            $pdo->prepare(
                'UPDATE meli_packs
                 SET external_shipment_id=COALESCE(?,external_shipment_id),
                     expected_orders_count=?,linked_orders_count=?,
                     expected_orders_json=?,orders_fingerprint=?,
                     integrity_status=?,integrity_message=?,verified_at=UTC_TIMESTAMP(),synced_at=UTC_TIMESTAMP()
                 WHERE id=? AND meli_account_id=?'
            )->execute([
                $pack['shipment']['id'] ?? null,
                $expectedCount,
                $linkedCount,
                json_encode($expected, JSON_UNESCAPED_UNICODE),
                hash('sha256', implode('|', $actual)),
                $status,
                $message,
                (int) $job['meli_pack_id'],
                $accountId,
            ]);
            $this->history($pdo, (int) $job['company_id'], $accountId, (int) $job['meli_pack_id'], 'remote_verified', $status, $linkedCount, $expectedCount, $message);
            $this->finishJobInTransaction($pdo, $job, 'complete', $message);
            $pdo->commit();
        } catch (Throwable $error) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $error;
        }
    }

    /** @param array<string,mixed> $job */
    private function recoverOrder(array $job): void
    {
        $this->heartbeat($job);
        $accountId = (int) $job['meli_account_id'];
        $externalOrderId = (string) $job['external_resource_id'];
        (new OrderSyncService($accountId,$this->client($accountId)))->syncOrderByIdForManual(
            $externalOrderId,
            [
                'job_type' => 'sale_pack_reconciliation',
                'source' => 'cron',
                'bulk' => false,
            ],
            fn(PDO $pdo): bool => $this->assertJobLeaseInTransaction($pdo, $job)
        );
        $this->refreshPack($job);
    }

    private function client(int $accountId): MeliApiClient
    {
        return $this->clientFactory!==null?($this->clientFactory)($accountId):new MeliApiClient($accountId);
    }

    /** @param array<string,mixed> $job */
    private function refreshPack(array $job): void
    {
        $packId = (int) $job['meli_pack_id'];
        $accountId = (int) $job['meli_account_id'];
        $companyId = (int) $job['company_id'];
        $pdo = Database::connectionFresh();
        $pdo->beginTransaction();
        try {
            $this->assertJobLeaseInTransaction($pdo, $job);
            $stmt = $pdo->prepare(
                'SELECT p.expected_orders_json,p.external_pack_id
                 FROM meli_packs p
                 WHERE p.id=? AND p.meli_account_id=?
                 FOR UPDATE'
            );
            $stmt->execute([$packId, $accountId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!is_array($row)) {
                throw new \RuntimeException('La venta reconstruida ya no existe en la cuenta indicada.');
            }
            $expected = json_decode((string) ($row['expected_orders_json'] ?? '[]'), true);
            $expected = is_array($expected)
                ? array_values(array_unique(array_map('strval', $expected)))
                : [];
            sort($expected, SORT_STRING);
            $linkedStmt = $pdo->prepare(
                'SELECT DISTINCT CAST(o.external_order_id AS CHAR)
                 FROM meli_pack_orders po
                 JOIN meli_orders o
                   ON o.id=po.meli_order_id
                  AND o.meli_account_id=?
                  AND o.external_pack_id=?
                 WHERE po.meli_pack_id=?
                 ORDER BY CAST(o.external_order_id AS CHAR)'
            );
            $linkedStmt->execute([$accountId, (string) $row['external_pack_id'], $packId]);
            $linkedIds = array_map('strval', $linkedStmt->fetchAll(PDO::FETCH_COLUMN));
            $linked = count($linkedIds);
            $expectedCount = count($expected);
            $exact = $expectedCount > 0 && $expected === $linkedIds;
            $status = $exact ? 'complete' : 'partial';
            $message = $exact
                ? 'Venta reconstruida: coincide exactamente el conjunto de órdenes esperado.'
                : 'La venta continúa incompleta o contiene una relación inesperada.';
            $pdo->prepare(
                'UPDATE meli_packs
                 SET linked_orders_count=?,integrity_status=?,integrity_message=?,
                     orders_fingerprint=?,verified_at=IF(?="complete",UTC_TIMESTAMP(),verified_at)
                 WHERE id=? AND meli_account_id=?'
            )->execute([
                $linked,
                $status,
                $message,
                hash('sha256', implode('|', $linkedIds)),
                $status,
                $packId,
                $accountId,
            ]);
            $this->history(
                $pdo,
                $companyId,
                $accountId,
                $packId,
                'child_recovered',
                $status,
                $linked,
                $expectedCount,
                $message
            );
            $this->finishJobInTransaction($pdo, $job, 'complete', $message);
            $pdo->commit();
        } catch (Throwable $error) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $error;
        }
    }

    /** @param array<string,mixed> $run */
    private function assertRebuildLeaseInTransaction(PDO $pdo, array $run): void
    {
        $stmt = $pdo->prepare(
            'SELECT id FROM sale_pack_rebuild_runs
             WHERE id=? AND status="running" AND lock_owner=? AND lease_generation=?
               AND lease_expires_at>=UTC_TIMESTAMP()
             FOR UPDATE'
        );
        $stmt->execute([
            (int) $run['id'],
            (string) $run['lock_owner'],
            (int) $run['lease_generation'],
        ]);
        if ($stmt->fetchColumn() === false) {
            throw new \RuntimeException('La reconstrucción local perdió su reserva temporal.');
        }
        $pdo->prepare(
            'UPDATE sale_pack_rebuild_runs
             SET lease_expires_at=DATE_ADD(UTC_TIMESTAMP(),INTERVAL 3 MINUTE)
             WHERE id=? AND lock_owner=? AND lease_generation=?'
        )->execute([
            (int) $run['id'],
            (string) $run['lock_owner'],
            (int) $run['lease_generation'],
        ]);
    }

    /** @param array<string,mixed> $job */
    private function assertJobLeaseInTransaction(PDO $pdo, array $job): bool
    {
        $stmt = $pdo->prepare(
            'SELECT id FROM sale_pack_reconciliation_jobs
             WHERE id=? AND status="running" AND lock_owner=? AND lease_generation=?
               AND lease_expires_at>=UTC_TIMESTAMP()
             FOR UPDATE'
        );
        $stmt->execute([
            (int) $job['id'],
            (string) $job['lock_owner'],
            (int) $job['lease_generation'],
        ]);
        if ($stmt->fetchColumn() === false) {
            throw new \RuntimeException('La reconstrucción perdió su reserva; el resultado tardío fue descartado.');
        }
        return true;
    }

    /** @param array<string,mixed> $job */
    private function finishJobInTransaction(
        PDO $pdo,
        array $job,
        string $status,
        ?string $message
    ): void {
        $stmt = $pdo->prepare(
            'UPDATE sale_pack_reconciliation_jobs
             SET status=?,safe_message=?,
                 completed_at=IF(?="complete",UTC_TIMESTAMP(),completed_at),
                 lock_owner=NULL,lease_expires_at=NULL,heartbeat_at=NULL
             WHERE id=? AND status="running" AND lock_owner=? AND lease_generation=?'
        );
        $stmt->execute([
            $status,
            $message,
            $status,
            (int) $job['id'],
            (string) $job['lock_owner'],
            (int) $job['lease_generation'],
        ]);
        if ($stmt->rowCount() !== 1) {
            throw new \RuntimeException('La reconstrucción perdió su reserva antes de aprobar el resultado.');
        }
    }

    /** @param array<string,mixed> $job */
    private function finishJob(array $job, string $status, ?string $message): void
    {
        $retryAt = $status === 'retry'
            ? 'DATE_ADD(UTC_TIMESTAMP(),INTERVAL LEAST(60,POW(2,attempts)) MINUTE)'
            : 'next_run_at';
        $stmt = Database::connectionFresh()->prepare(
            'UPDATE sale_pack_reconciliation_jobs
             SET status=:status,
                 consecutive_failures=IF(:status_retry="retry",consecutive_failures+1,0),
                 next_run_at=' . $retryAt . ',
                 safe_message=:message,
                 completed_at=IF(:status_complete="complete",UTC_TIMESTAMP(),completed_at),
                 lock_owner=NULL,lease_expires_at=NULL,heartbeat_at=NULL
             WHERE id=:id AND lock_owner=:owner AND lease_generation=:generation'
        );
        $stmt->execute([
            'status' => $status,
            'status_retry' => $status,
            'message' => $message,
            'status_complete' => $status,
            'id' => (int) $job['id'],
            'owner' => (string) $job['lock_owner'],
            'generation' => (int) $job['lease_generation'],
        ]);
        if ($stmt->rowCount() !== 1) {
            throw new \RuntimeException('Se perdió la reserva del trabajo de reconstrucción.');
        }
    }

    /** @param array<string,mixed> $job */
    private function heartbeat(array $job): void
    {
        $stmt = Database::connectionFresh()->prepare(
            'UPDATE sale_pack_reconciliation_jobs
             SET heartbeat_at=UTC_TIMESTAMP(),
                 lease_expires_at=DATE_ADD(UTC_TIMESTAMP(),INTERVAL 4 MINUTE)
             WHERE id=? AND lock_owner=? AND lease_generation=? AND status="running"'
        );
        $stmt->execute([
            (int) $job['id'], (string) $job['lock_owner'], (int) $job['lease_generation'],
        ]);
        if ($stmt->rowCount() !== 1) {
            throw new \RuntimeException('La reconstrucción perdió su reserva antes de consultar Mercado Libre.');
        }
    }

    private function history(PDO $pdo, int $companyId, int $accountId, int $packId, string $event, string $status, int $linked, ?int $expected, string $message): void
    {
        $pdo->prepare(
            'INSERT INTO sale_pack_reconciliation_history
                (company_id,meli_account_id,meli_pack_id,event_type,new_status,
                 linked_orders_count,expected_orders_count,safe_message)
             VALUES (?,?,?,?,?,?,?,?)'
        )->execute([$companyId, $accountId, $packId, $event, $status, $linked, $expected, $message]);
    }
}
