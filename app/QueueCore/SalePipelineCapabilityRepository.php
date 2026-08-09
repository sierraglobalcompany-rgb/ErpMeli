<?php
declare(strict_types=1);

namespace App\QueueCore;

use PDO;
use RuntimeException;
use Throwable;

/**
 * Durable bridge between a persisted order snapshot and its B2 children.
 *
 * The capability row is the source obligation. Child jobs and dependency
 * rows are published in the same transaction; a crash can therefore expose
 * either the complete graph or none of it. Remote handlers never scan legacy
 * tables and completion is fenced by tenant, capability generation and job.
 */
final class SalePipelineCapabilityRepository
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly QueueCoreRepository $queue,
    ) {}

    /** @return array{materialized:int,review:int,already_terminal:int} */
    public function materializePending(int $limit = 50, ?int $orderId = null): array
    {
        $result = ['materialized' => 0, 'review' => 0, 'already_terminal' => 0];
        $limit = max(1, min(100, $limit));
        for ($i = 0; $i < $limit; $i++) {
            $this->pdo->beginTransaction();
            try {
                $sql = "SELECT * FROM queue_core_pending_capabilities
                        WHERE state='pending_b2'";
                $params = [];
                if ($orderId !== null) {
                    $sql .= " AND resource_type='order' AND resource_id=?";
                    $params[] = (string) $orderId;
                }
                $sql .= ' ORDER BY id ASC LIMIT 1 FOR UPDATE';
                $select = $this->pdo->prepare($sql);
                $select->execute($params);
                $capability = $select->fetch(PDO::FETCH_ASSOC);
                if (!is_array($capability)) {
                    $this->pdo->rollBack();
                    break;
                }
                $outcome = $this->materializeLocked($capability);
                $this->pdo->commit();
                $result[$outcome]++;
            } catch (Throwable $error) {
                if ($this->pdo->inTransaction()) {
                    $this->pdo->rollBack();
                }
                throw $error;
            }
        }
        return $result;
    }

    public function dependencyCompleted(QueueClaim $job, int $capabilityId): bool
    {
        if ($capabilityId < 1) {
            return false;
        }
        $generation = (int) ($job->payload['capability_generation'] ?? -1);
        if ($generation < 0) {
            return false;
        }
        $stmt = $this->pdo->prepare(
            "SELECT state FROM queue_core_capability_dependencies
             WHERE capability_id=? AND lifecycle_generation=? AND queue_job_id=?
               AND company_id=? AND meli_account_id=? LIMIT 1"
        );
        $stmt->execute([$capabilityId, $generation, $job->id, $job->companyId, $job->meliAccountId]);
        return (string) ($stmt->fetchColumn() ?: '') === 'completed';
    }

    /** @return list<array{capability_id:int,order_id:int,lifecycle_generation:int}> */
    public function pendingDependenciesForJob(QueueClaim $job): array
    {
        $statement = $this->pdo->prepare(
            "SELECT d.capability_id,d.lifecycle_generation,c.resource_id order_id
             FROM queue_core_capability_dependencies d
             INNER JOIN queue_core_pending_capabilities c
               ON c.id=d.capability_id AND c.company_id=d.company_id
              AND c.meli_account_id=d.meli_account_id
              AND c.lifecycle_generation=d.lifecycle_generation
             WHERE d.queue_job_id=? AND d.company_id=? AND d.meli_account_id=?
               AND d.state='pending' AND c.state='materialized'
             ORDER BY d.capability_id"
        );
        $statement->execute([$job->id, $job->companyId, $job->meliAccountId]);
        $result = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $result[] = [
                'capability_id' => (int) $row['capability_id'],
                'order_id' => (int) $row['order_id'],
                'lifecycle_generation' => (int) $row['lifecycle_generation'],
            ];
        }
        return $result;
    }

    public function completeAllDependencies(QueueClaim $job): bool
    {
        $dependencies = $this->pendingDependenciesForJob($job);
        foreach ($dependencies as $dependency) {
            $claim = $this->claimWithCapabilityGeneration(
                $job,
                $dependency['lifecycle_generation']
            );
            if (!$this->completeDependency($claim, $dependency['capability_id'])) {
                return false;
            }
        }
        return true;
    }

    public function completeDependency(QueueClaim $job, int $capabilityId): bool
    {
        if ($capabilityId < 1) {
            return false;
        }
        $generation = (int) ($job->payload['capability_generation'] ?? -1);
        if ($generation < 0) {
            return false;
        }
        $this->pdo->beginTransaction();
        try {
            $capability = $this->lockCapability($capabilityId, $job->companyId, $job->meliAccountId);
            if (!is_array($capability)) {
                $this->pdo->rollBack();
                return false;
            }
            if ((int) $capability['lifecycle_generation'] !== $generation) {
                $this->pdo->rollBack();
                return false;
            }
            if ((string) $capability['state'] === 'resolved') {
                $this->pdo->commit();
                return true;
            }
            if ((string) $capability['state'] !== 'materialized') {
                $this->pdo->rollBack();
                return false;
            }
            $dependency = $this->pdo->prepare(
                "UPDATE queue_core_capability_dependencies
                 SET state='completed',completed_at=UTC_TIMESTAMP(3),updated_at=UTC_TIMESTAMP(3)
                 WHERE capability_id=? AND lifecycle_generation=? AND queue_job_id=?
                   AND company_id=? AND meli_account_id=?
                   AND state='pending'"
            );
            $dependency->execute([$capabilityId, $generation, $job->id, $job->companyId, $job->meliAccountId]);
            if ($dependency->rowCount() !== 1) {
                $already = $this->pdo->prepare(
                    "SELECT state FROM queue_core_capability_dependencies
                     WHERE capability_id=? AND lifecycle_generation=? AND queue_job_id=?
                       AND company_id=? AND meli_account_id=? LIMIT 1"
                );
                $already->execute([$capabilityId, $generation, $job->id, $job->companyId, $job->meliAccountId]);
                if ((string) ($already->fetchColumn() ?: '') !== 'completed') {
                    $this->pdo->rollBack();
                    return false;
                }
            }
            $pending = $this->pdo->prepare(
                "SELECT COUNT(*) FROM queue_core_capability_dependencies
                 WHERE capability_id=? AND lifecycle_generation=?
                   AND company_id=? AND meli_account_id=? AND state<>'completed'"
            );
            $pending->execute([$capabilityId, $generation, $job->companyId, $job->meliAccountId]);
            if ((int) $pending->fetchColumn() === 0) {
                $finish = $this->pdo->prepare(
                    "UPDATE queue_core_pending_capabilities
                     SET state='resolved',resolved_at=UTC_TIMESTAMP(3),last_error_class=NULL,
                         completed_dependencies=required_dependencies,updated_at=UTC_TIMESTAMP(3)
                     WHERE id=? AND company_id=? AND meli_account_id=?
                       AND lifecycle_generation=? AND state='materialized'"
                );
                $finish->execute([
                    $capabilityId, $job->companyId, $job->meliAccountId,
                    (int) $capability['lifecycle_generation'],
                ]);
                if ($finish->rowCount() !== 1) {
                    $this->pdo->rollBack();
                    return false;
                }
            } else {
                $count = $this->pdo->prepare(
                    "UPDATE queue_core_pending_capabilities
                     SET completed_dependencies=(
                         SELECT COUNT(*) FROM queue_core_capability_dependencies
                         WHERE capability_id=? AND lifecycle_generation=? AND state='completed'
                     ),updated_at=UTC_TIMESTAMP(3)
                     WHERE id=? AND company_id=? AND meli_account_id=?
                       AND lifecycle_generation=? AND state='materialized'"
                );
                $count->execute([
                    $capabilityId, $generation, $capabilityId, $job->companyId, $job->meliAccountId,
                    (int) $capability['lifecycle_generation'],
                ]);
                if ($count->rowCount() !== 1) {
                    $this->pdo->rollBack();
                    return false;
                }
            }
            $this->pdo->commit();
            return true;
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
    }

    public function appendShipmentDependency(
        QueueClaim $packJob,
        int $capabilityId,
        int $orderId,
        string $shipmentId,
        string $inputVersion
    ): int {
        if ($capabilityId < 1 || $orderId < 1 || preg_match('/^\d+$/', $shipmentId) !== 1) {
            throw new RuntimeException('Queue Core shipment dependency identity is invalid.');
        }
        $this->pdo->beginTransaction();
        try {
            $capability = $this->lockCapability($capabilityId, $packJob->companyId, $packJob->meliAccountId);
            if (!is_array($capability) || (string) $capability['state'] !== 'materialized') {
                throw new RuntimeException('Queue Core capability is no longer materialized.');
            }
            $generation = (int) ($packJob->payload['capability_generation'] ?? -1);
            if ($generation < 0 || (int) $capability['lifecycle_generation'] !== $generation) {
                throw new RuntimeException('Queue Core capability generation changed.');
            }
            $job = $this->shipmentJob(
                $packJob->companyId,
                $packJob->meliAccountId,
                $orderId,
                $shipmentId,
                $capabilityId,
                $inputVersion,
                $generation
            );
            $jobId = $this->queue->enqueue($job);
            $created = $this->linkDependency(
                $capabilityId,
                $generation,
                $packJob->companyId,
                $packJob->meliAccountId,
                'shipment:' . $shipmentId,
                $jobId
            );
            if ($created) {
                $increment = $this->pdo->prepare(
                    "UPDATE queue_core_pending_capabilities
                     SET required_dependencies=required_dependencies+1,updated_at=UTC_TIMESTAMP(3)
                     WHERE id=? AND company_id=? AND meli_account_id=?
                       AND lifecycle_generation=? AND state='materialized'"
                );
                $increment->execute([
                    $capabilityId, $packJob->companyId, $packJob->meliAccountId,
                    (int) $capability['lifecycle_generation'],
                ]);
                if ($increment->rowCount() !== 1) {
                    throw new RuntimeException('Queue Core capability dependency count fence changed.');
                }
            }
            $this->pdo->commit();
            return $jobId;
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
    }

    public function reviewDependency(QueueClaim $job, int $capabilityId, string $errorClass): bool
    {
        $safe = self::safeClass($errorClass);
        $this->pdo->beginTransaction();
        try {
            $capability = $this->lockCapability($capabilityId, $job->companyId, $job->meliAccountId);
            if (!is_array($capability) || (string) $capability['state'] !== 'materialized') {
                $this->pdo->rollBack();
                return false;
            }
            $generation = (int) ($job->payload['capability_generation'] ?? -1);
            if ($generation < 0 || (int) $capability['lifecycle_generation'] !== $generation) {
                $this->pdo->rollBack();
                return false;
            }
            $dependency = $this->pdo->prepare(
                "UPDATE queue_core_capability_dependencies SET state='review',error_class=?,updated_at=UTC_TIMESTAMP(3)
                 WHERE capability_id=? AND lifecycle_generation=? AND queue_job_id=?
                   AND company_id=? AND meli_account_id=? AND state='pending'"
            );
            $dependency->execute([$safe, $capabilityId, $generation, $job->id, $job->companyId, $job->meliAccountId]);
            if ($dependency->rowCount() !== 1) {
                $this->pdo->rollBack();
                return false;
            }
            $update = $this->pdo->prepare(
                "UPDATE queue_core_pending_capabilities
                 SET state='review',last_error_class=?,updated_at=UTC_TIMESTAMP(3)
                 WHERE id=? AND company_id=? AND meli_account_id=?
                   AND lifecycle_generation=? AND state='materialized'"
            );
            $update->execute([
                $safe, $capabilityId, $job->companyId, $job->meliAccountId,
                (int) $capability['lifecycle_generation'],
            ]);
            if ($update->rowCount() !== 1) {
                $this->pdo->rollBack();
                return false;
            }
            $this->pdo->commit();
            return true;
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
    }

    /** @param array<string,mixed> $capability */
    private function materializeLocked(array $capability): string
    {
        $id = (int) $capability['id'];
        $companyId = (int) $capability['company_id'];
        $accountId = (int) $capability['meli_account_id'];
        $generation = (int) ($capability['lifecycle_generation'] ?? 0);
        if ((string) $capability['resource_type'] !== 'order' || !ctype_digit((string) $capability['resource_id'])) {
            return $this->markReview($id, $companyId, $accountId, $generation, 'invalid_order_capability');
        }
        $orderId = (int) $capability['resource_id'];
        $order = $this->pdo->prepare(
            "SELECT o.id,o.external_order_id,o.external_pack_id,o.external_shipping_id,
                    COALESCE(o.queue_snapshot_version,SHA2(CONCAT(o.external_order_id,'|',COALESCE(o.synced_at,'')),256)) input_version
             FROM meli_orders o
             JOIN meli_accounts a ON a.id=o.meli_account_id AND a.company_id=?
             WHERE o.id=? AND o.meli_account_id=? FOR UPDATE"
        );
        $order->execute([$companyId, $orderId, $accountId]);
        $row = $order->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return $this->markReview($id, $companyId, $accountId, $generation, 'order_scope_or_identity_missing');
        }
        $version = trim((string) $row['input_version']);
        if ($version === '') {
            return $this->markReview($id, $companyId, $accountId, $generation, 'order_input_version_missing');
        }
        $jobs = [];
        $capabilityKey = (string) $capability['capability_key'];
        if ($capabilityKey === 'financial_projection') {
            $jobs['financial_projection:' . $orderId] = new QueueJob(
                $companyId, $accountId, 'financial_projection', 'order', (string) $orderId,
                'local', 0, 'financial_projection:order:' . $orderId, $version,
                'queue_core_sale_pipeline', 'capability:' . $id,
                ['order_id' => $orderId, 'capability_id' => $id,
                 'capability_generation' => $generation],
                ['capability_generation' => $generation], 5, null, 'operational'
            );
        } elseif ($capabilityKey === 'order_enrichment') {
            $pack = trim((string) ($row['external_pack_id'] ?? ''));
            $shipment = trim((string) ($row['external_shipping_id'] ?? ''));
            if ($pack !== '' && ctype_digit($pack)) {
                $jobs['pack:' . $pack] = $this->packJob($companyId, $accountId, $orderId, $pack, $id, $version, $generation);
            }
            if ($shipment !== '' && ctype_digit($shipment)) {
                $jobs['shipment:' . $shipment] = $this->shipmentJob($companyId, $accountId, $orderId, $shipment, $id, $version, $generation);
            }
            if ($jobs === []) {
                return $this->markReview($id, $companyId, $accountId, $generation, 'enrichment_identity_missing');
            }
        } else {
            return $this->markReview($id, $companyId, $accountId, $generation, 'unsupported_sale_capability');
        }
        foreach ($jobs as $dependencyKey => $job) {
            $jobId = in_array($job->workType, ['pack_exact','shipment_exact'], true)
                ? $this->queue->enqueueCoalescedExact(
                    $job,
                    [$job->workType, 'webhook_' . $job->workType]
                )
                : $this->queue->enqueue($job);
            $this->linkDependency($id, $generation, $companyId, $accountId, $dependencyKey, $jobId);
        }
        $update = $this->pdo->prepare(
            "UPDATE queue_core_pending_capabilities
             SET state='materialized',input_version=?,required_dependencies=?,completed_dependencies=0,
                 last_error_class=NULL,updated_at=UTC_TIMESTAMP(3)
             WHERE id=? AND company_id=? AND meli_account_id=?
               AND lifecycle_generation=? AND state='pending_b2'"
        );
        $update->execute([$version, count($jobs), $id, $companyId, $accountId, $generation]);
        if ($update->rowCount() !== 1) {
            throw new RuntimeException('Queue Core capability materialization fence changed.');
        }
        return 'materialized';
    }

    private function markReview(int $id, int $companyId, int $accountId, int $generation, string $error): string
    {
        $stmt = $this->pdo->prepare(
            "UPDATE queue_core_pending_capabilities
             SET state='review',last_error_class=?,lifecycle_generation=lifecycle_generation+1,
                 updated_at=UTC_TIMESTAMP(3)
             WHERE id=? AND company_id=? AND meli_account_id=?
               AND lifecycle_generation=? AND state='pending_b2'"
        );
        $stmt->execute([self::safeClass($error), $id, $companyId, $accountId, $generation]);
        if ($stmt->rowCount() !== 1) {
            throw new RuntimeException('Queue Core capability review fence changed.');
        }
        return 'review';
    }

    /** @return array<string,mixed>|null */
    private function lockCapability(int $id, int $companyId, int $accountId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM queue_core_pending_capabilities
             WHERE id=? AND company_id=? AND meli_account_id=? FOR UPDATE'
        );
        $stmt->execute([$id, $companyId, $accountId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    private function linkDependency(
        int $capabilityId,
        int $generation,
        int $companyId,
        int $accountId,
        string $dependencyKey,
        int $jobId
    ): bool {
        $stmt = $this->pdo->prepare(
            "INSERT IGNORE INTO queue_core_capability_dependencies
                (capability_id,lifecycle_generation,company_id,meli_account_id,dependency_key,queue_job_id,state)
             VALUES (?,?,?,?,?,?,'pending')"
        );
        $stmt->execute([$capabilityId, $generation, $companyId, $accountId, $dependencyKey, $jobId]);
        if ($stmt->rowCount() === 1) {
            return true;
        }
        $check = $this->pdo->prepare(
            'SELECT queue_job_id FROM queue_core_capability_dependencies
             WHERE capability_id=? AND lifecycle_generation=?
               AND company_id=? AND meli_account_id=? AND dependency_key=? LIMIT 1'
        );
        $check->execute([$capabilityId, $generation, $companyId, $accountId, $dependencyKey]);
        if ((int) $check->fetchColumn() !== $jobId) {
            throw new RuntimeException('Queue Core capability dependency identity changed.');
        }
        return false;
    }

    private function packJob(int $companyId, int $accountId, int $orderId, string $packId, int $capabilityId, string $version, int $generation): QueueJob
    {
        return new QueueJob(
            $companyId, $accountId, 'pack_exact', 'pack', $packId, 'normal', 0,
            'pack:' . $packId, $version, 'queue_core_sale_pipeline', 'capability:' . $capabilityId,
            ['order_id' => $orderId, 'pack_id' => $packId, 'capability_id' => $capabilityId,
             'input_version' => $version, 'capability_generation' => $generation],
            ['parent_capability' => 'order_enrichment'], 5, null, 'operational'
        );
    }

    private function shipmentJob(int $companyId, int $accountId, int $orderId, string $shipmentId, int $capabilityId, string $version, int $generation): QueueJob
    {
        return new QueueJob(
            $companyId, $accountId, 'shipment_exact', 'shipment', $shipmentId, 'normal', 0,
            'shipment:' . $shipmentId, $version, 'queue_core_sale_pipeline', 'capability:' . $capabilityId,
            ['order_id' => $orderId, 'shipment_id' => $shipmentId, 'capability_id' => $capabilityId,
             'input_version' => $version, 'capability_generation' => $generation],
            ['parent_capability' => 'order_enrichment'], 5, null, 'operational'
        );
    }

    private static function safeClass(string $value): string
    {
        $safe = strtolower((string) preg_replace('/[^a-z0-9_]+/i', '_', $value));
        return substr(trim($safe, '_'), 0, 100) ?: 'unknown_failure';
    }

    private function claimWithCapabilityGeneration(QueueClaim $job, int $generation): QueueClaim
    {
        return new QueueClaim(
            $job->id,
            $job->companyId,
            $job->meliAccountId,
            $job->workType,
            $job->resourceType,
            $job->resourceId,
            $job->lane,
            $job->priority,
            $job->state,
            $job->attemptCount,
            $job->maxAttempts,
            $job->leaseOwner,
            $job->leaseGeneration,
            $job->dispatchState,
            array_replace($job->payload, ['capability_generation' => $generation]),
            $job->source,
            $job->sourceRef,
        );
    }
}
