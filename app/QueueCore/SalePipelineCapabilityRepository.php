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

    /** @return array{materialized:int,waiting_dependency:int,review:int,already_terminal:int} */
    public function materializePending(int $limit = 50, ?int $orderId = null): array
    {
        $result = [
            'materialized' => 0,
            'waiting_dependency' => 0,
            'review' => 0,
            'already_terminal' => 0,
        ];
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
                $this->releaseDependentsLocked($capability);
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

    /**
     * A pack webhook has already persisted the pack snapshot. Its shipment is
     * published as exact FIFO work in the same transaction that records the
     * logistics -> financial obligation. Repeated webhook observations reuse
     * the same input version and therefore cannot duplicate revenue work.
     */
    public function materializeWebhookPackShipment(
        int $companyId,
        int $accountId,
        string $packId,
        string $shipmentId,
        string $packInputVersion
    ): int {
        if ($companyId < 1 || $accountId < 1
            || preg_match('/^\d+$/', $packId) !== 1
            || preg_match('/^\d+$/', $shipmentId) !== 1
            || trim($packInputVersion) === '') {
            throw new RuntimeException('Queue Core webhook logistics identity is invalid.');
        }
        $this->pdo->beginTransaction();
        try {
            $scope = $this->pdo->prepare(
                "SELECT 1 FROM meli_accounts
                 WHERE id=? AND company_id=? AND status IN ('conectado','connected') LIMIT 1 FOR UPDATE"
            );
            $scope->execute([$accountId, $companyId]);
            if ($scope->fetchColumn() === false) {
                throw new RuntimeException('Queue Core webhook logistics scope is unavailable.');
            }
            $packScope = $this->pdo->prepare(
                'SELECT id FROM meli_packs
                 WHERE meli_account_id=? AND external_pack_id=? LIMIT 1 FOR UPDATE'
            );
            $packScope->execute([$accountId, $packId]);
            if ($packScope->fetchColumn() === false) {
                throw new RuntimeException('Queue Core webhook pack scope is unavailable.');
            }
            $orders = $this->pdo->prepare(
                "SELECT o.id,o.queue_snapshot_version
                 FROM meli_packs p
                 INNER JOIN meli_pack_orders po ON po.meli_pack_id=p.id
                 INNER JOIN meli_orders o ON o.id=po.meli_order_id
                    AND o.meli_account_id=p.meli_account_id
                 WHERE p.meli_account_id=? AND p.external_pack_id=?
                 ORDER BY o.id FOR UPDATE"
            );
            $orders->execute([$accountId, $packId]);
            $rows = $orders->fetchAll(PDO::FETCH_ASSOC);
            if ($rows === []) {
                $job = $this->standaloneWebhookShipmentJob(
                    $companyId, $accountId, $packId, $shipmentId, $packInputVersion
                );
                $jobId = $this->queue->enqueueCoalescedExact(
                    $job,
                    ['shipment_exact', 'webhook_shipment_exact']
                );
                $this->pdo->commit();
                return $jobId;
            }

            $firstJobId = 0;
            foreach ($rows as $order) {
                $orderId = (int) $order['id'];
                $orderVersion = trim((string) ($order['queue_snapshot_version'] ?? ''));
                if ($orderId < 1 || $orderVersion === '') {
                    throw new RuntimeException('Queue Core webhook order snapshot is unavailable.');
                }
                $logisticsVersion = hash(
                    'sha256',
                    implode('|', [$orderVersion, 'pack', $packId, 'shipment', $shipmentId, $packInputVersion])
                );
                $enrichment = $this->upsertCapabilityLocked(
                    $companyId,
                    $accountId,
                    $orderId,
                    'order_enrichment',
                    $logisticsVersion,
                    'materialized'
                );
                $financial = $this->upsertCapabilityLocked(
                    $companyId,
                    $accountId,
                    $orderId,
                    'financial_projection',
                    $logisticsVersion,
                    'waiting_dependency'
                );
                $financialVersion = $this->financialInputVersion(
                    $orderVersion,
                    $enrichment,
                    $packId . ':' . $shipmentId
                );
                if (!$this->linkCapabilityEdgeLocked($enrichment, $financial, $financialVersion)) {
                    $this->reviewCapabilityLocked($financial, 'dependency_cycle');
                }
                $shipment = $this->shipmentJob(
                    $companyId,
                    $accountId,
                    $orderId,
                    $shipmentId,
                    (int) $enrichment['id'],
                    $logisticsVersion,
                    (int) $enrichment['lifecycle_generation']
                );
                $jobId = $this->queue->enqueueCoalescedExact(
                    $shipment,
                    ['shipment_exact', 'webhook_shipment_exact']
                );
                $created = $this->linkDependency(
                    (int) $enrichment['id'],
                    (int) $enrichment['lifecycle_generation'],
                    $companyId,
                    $accountId,
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
                        (int) $enrichment['id'], $companyId, $accountId,
                        (int) $enrichment['lifecycle_generation'],
                    ]);
                    if ($increment->rowCount() !== 1) {
                        throw new RuntimeException('Queue Core webhook dependency count fence changed.');
                    }
                }
                $firstJobId = $firstJobId > 0 ? $firstJobId : $jobId;
            }
            $this->pdo->commit();
            return $firstJobId;
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
        $orderVersion = trim((string) $row['input_version']);
        if ($orderVersion === '') {
            return $this->markReview($id, $companyId, $accountId, $generation, 'order_input_version_missing');
        }
        $version = trim((string) ($capability['input_version'] ?? '')) ?: $orderVersion;
        $jobs = [];
        $capabilityKey = (string) $capability['capability_key'];
        if ($capabilityKey === 'financial_projection') {
            $prerequisite = $this->requiredLogisticsCapabilityLocked(
                $companyId,
                $accountId,
                $orderId,
                $orderVersion,
                trim((string) ($row['external_pack_id'] ?? '')),
                trim((string) ($row['external_shipping_id'] ?? ''))
            );
            if (is_array($prerequisite)) {
                if ((string) $prerequisite['state'] === 'review') {
                    return $this->markReview(
                        $id, $companyId, $accountId, $generation, 'required_logistics_review'
                    );
                }
                $financialVersion = $this->financialInputVersion(
                    $orderVersion,
                    $prerequisite,
                    trim((string) ($row['external_pack_id'] ?? '')) . ':'
                        . trim((string) ($row['external_shipping_id'] ?? ''))
                );
                if (!$this->linkCapabilityEdgeLocked($prerequisite, $capability, $financialVersion)) {
                    return $this->markReview($id, $companyId, $accountId, $generation, 'dependency_cycle');
                }
                if ((string) $prerequisite['state'] !== 'resolved') {
                    $wait = $this->pdo->prepare(
                        "UPDATE queue_core_pending_capabilities
                         SET state='waiting_dependency',input_version=?,last_error_class=NULL,
                             updated_at=UTC_TIMESTAMP(3)
                         WHERE id=? AND company_id=? AND meli_account_id=?
                           AND lifecycle_generation=? AND state='pending_b2'"
                    );
                    $wait->execute([$financialVersion, $id, $companyId, $accountId, $generation]);
                    if ($wait->rowCount() !== 1) {
                        throw new RuntimeException('Queue Core financial dependency wait fence changed.');
                    }
                    return 'waiting_dependency';
                }
                $version = $financialVersion;
            }
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
    private function requiredLogisticsCapabilityLocked(
        int $companyId,
        int $accountId,
        int $orderId,
        string $orderVersion,
        string $packId,
        string $shipmentId
    ): ?array {
        if (($packId === '' || !ctype_digit($packId))
            && ($shipmentId === '' || !ctype_digit($shipmentId))) {
            return null;
        }
        $existing = $this->pdo->prepare(
            "SELECT * FROM queue_core_pending_capabilities
             WHERE company_id=? AND meli_account_id=? AND resource_type='order'
               AND resource_id=? AND capability_key='order_enrichment' FOR UPDATE"
        );
        $existing->execute([$companyId, $accountId, (string) $orderId]);
        $row = $existing->fetch(PDO::FETCH_ASSOC);
        if (is_array($row)) {
            // The producer owns lifecycle versions. In particular a pack
            // webhook may hold a newer logistics fingerprint than the order
            // snapshot, which financial materialization must never overwrite.
            return $row;
        }
        return $this->upsertCapabilityLocked(
            $companyId,
            $accountId,
            $orderId,
            'order_enrichment',
            $orderVersion,
            'pending_b2'
        );
    }

    /** @return array<string,mixed> */
    private function upsertCapabilityLocked(
        int $companyId,
        int $accountId,
        int $orderId,
        string $key,
        string $version,
        string $newState
    ): array {
        if (!in_array($key, ['financial_projection', 'order_enrichment'], true)
            || !in_array($newState, ['pending_b2', 'waiting_dependency', 'materialized'], true)) {
            throw new RuntimeException('Queue Core sale capability upsert is invalid.');
        }
        $insert = $this->pdo->prepare(
            "INSERT INTO queue_core_pending_capabilities
                (company_id,meli_account_id,resource_type,resource_id,capability_key,state,input_version)
             VALUES (?,?,'order',?,?,?,?)
             ON DUPLICATE KEY UPDATE
                state=IF(input_version<=>VALUES(input_version),state,VALUES(state)),
                lifecycle_generation=IF(input_version<=>VALUES(input_version),lifecycle_generation,lifecycle_generation+1),
                required_dependencies=IF(input_version<=>VALUES(input_version),required_dependencies,0),
                completed_dependencies=IF(input_version<=>VALUES(input_version),completed_dependencies,0),
                last_error_class=IF(input_version<=>VALUES(input_version),last_error_class,NULL),
                resolved_at=IF(input_version<=>VALUES(input_version),resolved_at,NULL),
                input_version=VALUES(input_version),updated_at=UTC_TIMESTAMP(3)"
        );
        $insert->execute([$companyId, $accountId, (string) $orderId, $key, $newState, $version]);
        $select = $this->pdo->prepare(
            "SELECT * FROM queue_core_pending_capabilities
             WHERE company_id=? AND meli_account_id=? AND resource_type='order'
               AND resource_id=? AND capability_key=? FOR UPDATE"
        );
        $select->execute([$companyId, $accountId, (string) $orderId, $key]);
        $row = $select->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            throw new RuntimeException('Queue Core sale capability upsert disappeared.');
        }
        return $row;
    }

    /** @param array<string,mixed> $prerequisite @param array<string,mixed> $dependent */
    private function linkCapabilityEdgeLocked(array $prerequisite, array $dependent, string $inputVersion): bool
    {
        $prerequisiteId = (int) $prerequisite['id'];
        $dependentId = (int) $dependent['id'];
        if ($prerequisiteId < 1 || $dependentId < 1
            || (int) $prerequisite['company_id'] !== (int) $dependent['company_id']
            || (int) $prerequisite['meli_account_id'] !== (int) $dependent['meli_account_id']) {
            return false;
        }
        if ($this->pathExistsLocked($dependentId, $prerequisiteId)) {
            return false;
        }
        $insert = $this->pdo->prepare(
            "INSERT INTO queue_core_capability_edges
                (company_id,meli_account_id,prerequisite_capability_id,prerequisite_generation,
                 dependent_capability_id,dependent_generation,edge_key,input_version,state)
             VALUES (?,?,?,?,?,?,?,?,?)
             ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id)"
        );
        $state = (string) $prerequisite['state'] === 'resolved' ? 'completed' : 'pending';
        $insert->execute([
            (int) $prerequisite['company_id'],
            (int) $prerequisite['meli_account_id'],
            $prerequisiteId,
            (int) $prerequisite['lifecycle_generation'],
            $dependentId,
            (int) $dependent['lifecycle_generation'],
            'logistics:' . $prerequisiteId,
            $inputVersion,
            $state,
        ]);
        return true;
    }

    private function pathExistsLocked(int $fromCapabilityId, int $targetCapabilityId): bool
    {
        if ($fromCapabilityId === $targetCapabilityId) {
            return true;
        }
        $frontier = [$fromCapabilityId];
        $seen = [];
        for ($depth = 0; $depth < 32 && $frontier !== []; $depth++) {
            $next = [];
            foreach ($frontier as $capabilityId) {
                if (isset($seen[$capabilityId])) {
                    continue;
                }
                $seen[$capabilityId] = true;
                $statement = $this->pdo->prepare(
                    'SELECT dependent_capability_id FROM queue_core_capability_edges
                     WHERE prerequisite_capability_id=? ORDER BY id FOR UPDATE'
                );
                $statement->execute([$capabilityId]);
                foreach ($statement->fetchAll(PDO::FETCH_COLUMN) as $candidate) {
                    $candidateId = (int) $candidate;
                    if ($candidateId === $targetCapabilityId) {
                        return true;
                    }
                    if (!isset($seen[$candidateId])) {
                        $next[] = $candidateId;
                    }
                }
            }
            $frontier = array_values(array_unique($next));
        }
        return false;
    }

    /** @param array<string,mixed> $prerequisite */
    private function releaseDependentsLocked(array $prerequisite): void
    {
        $edges = $this->pdo->prepare(
            "SELECT * FROM queue_core_capability_edges
             WHERE prerequisite_capability_id=? AND prerequisite_generation=?
               AND company_id=? AND meli_account_id=? AND state='pending'
             ORDER BY id FOR UPDATE"
        );
        $edges->execute([
            (int) $prerequisite['id'],
            (int) $prerequisite['lifecycle_generation'],
            (int) $prerequisite['company_id'],
            (int) $prerequisite['meli_account_id'],
        ]);
        foreach ($edges->fetchAll(PDO::FETCH_ASSOC) as $edge) {
            $dependent = $this->lockCapability(
                (int) $edge['dependent_capability_id'],
                (int) $edge['company_id'],
                (int) $edge['meli_account_id']
            );
            if (!is_array($dependent)
                || (int) $dependent['lifecycle_generation'] !== (int) $edge['dependent_generation']) {
                continue;
            }
            if ($this->pathExistsLocked((int) $dependent['id'], (int) $prerequisite['id'])) {
                $this->reviewCapabilityLocked($dependent, 'dependency_cycle');
                $this->pdo->prepare(
                    "UPDATE queue_core_capability_edges SET state='review',error_class='dependency_cycle',
                         updated_at=UTC_TIMESTAMP(3) WHERE id=? AND state='pending'"
                )->execute([(int) $edge['id']]);
                continue;
            }
            $complete = $this->pdo->prepare(
                "UPDATE queue_core_capability_edges
                 SET state='completed',completed_at=UTC_TIMESTAMP(3),updated_at=UTC_TIMESTAMP(3)
                 WHERE id=? AND state='pending'"
            );
            $complete->execute([(int) $edge['id']]);
            if ($complete->rowCount() !== 1) {
                continue;
            }
            $remaining = $this->pdo->prepare(
                "SELECT COUNT(*) FROM queue_core_capability_edges
                 WHERE dependent_capability_id=? AND dependent_generation=? AND state='pending'"
            );
            $remaining->execute([(int) $dependent['id'], (int) $dependent['lifecycle_generation']]);
            if ((int) $remaining->fetchColumn() === 0 && (string) $dependent['state'] === 'waiting_dependency') {
                $wake = $this->pdo->prepare(
                    "UPDATE queue_core_pending_capabilities
                     SET state='pending_b2',input_version=?,last_error_class=NULL,updated_at=UTC_TIMESTAMP(3)
                     WHERE id=? AND company_id=? AND meli_account_id=?
                       AND lifecycle_generation=? AND state='waiting_dependency'"
                );
                $wake->execute([
                    (string) $edge['input_version'],
                    (int) $dependent['id'],
                    (int) $dependent['company_id'],
                    (int) $dependent['meli_account_id'],
                    (int) $dependent['lifecycle_generation'],
                ]);
                if ($wake->rowCount() !== 1) {
                    throw new RuntimeException('Queue Core financial dependency wake fence changed.');
                }
            }
        }
    }

    /** @param array<string,mixed> $capability */
    private function reviewCapabilityLocked(array $capability, string $reason): void
    {
        $statement = $this->pdo->prepare(
            "UPDATE queue_core_pending_capabilities
             SET state='review',last_error_class=?,updated_at=UTC_TIMESTAMP(3)
             WHERE id=? AND company_id=? AND meli_account_id=?
               AND lifecycle_generation=? AND state IN ('pending_b2','waiting_dependency','materialized')"
        );
        $statement->execute([
            self::safeClass($reason),
            (int) $capability['id'],
            (int) $capability['company_id'],
            (int) $capability['meli_account_id'],
            (int) $capability['lifecycle_generation'],
        ]);
    }

    /** @param array<string,mixed> $logistics */
    private function financialInputVersion(string $orderVersion, array $logistics, string $identity): string
    {
        return hash('sha256', implode('|', [
            $orderVersion,
            'logistics',
            (string) $logistics['id'],
            (string) $logistics['lifecycle_generation'],
            (string) ($logistics['input_version'] ?? ''),
            $identity,
        ]));
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

    private function standaloneWebhookShipmentJob(
        int $companyId,
        int $accountId,
        string $packId,
        string $shipmentId,
        string $version
    ): QueueJob {
        return new QueueJob(
            $companyId,
            $accountId,
            'shipment_exact',
            'shipment',
            $shipmentId,
            'normal',
            0,
            'shipment:' . $shipmentId,
            $version,
            'queue_core_pack_webhook',
            'pack:' . $packId,
            [
                'order_id' => 0,
                'shipment_id' => $shipmentId,
                'input_version' => $version,
                'source_pack_id' => $packId,
            ],
            ['parent_capability' => 'pack_webhook'],
            5,
            null,
            'operational'
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
