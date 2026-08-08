<?php

declare(strict_types=1);

namespace App\Services;

use Closure;
use PDO;
use RuntimeException;
use Throwable;

final class CronV3LegacyQueueAdapter
{
    /** @var Closure(WorkEnvelope):array{id:int,created:bool,status:string} */
    private readonly Closure $enqueue;

    /** @param null|callable(WorkEnvelope):array{id:int,created:bool,status:string} $enqueue */
    public function __construct(
        private readonly PDO $pdo,
        private readonly string $queueKey,
        ?callable $enqueue = null,
    ) {
        if (!isset(self::definitions()[$queueKey])) {
            throw new RuntimeException('Legacy queue adapter is not registered for this queue.');
        }
        $this->enqueue = $enqueue !== null
            ? Closure::fromCallable($enqueue)
            : static fn (WorkEnvelope $work): array => CronV3::enqueue($work);
    }

    /** @return array{queue_key:string,read:int,materialized:int,created:int,checkpoint:int,mode:string} */
    public function importBatch(int $readLimit = 50, int $materializeLimit = 20): array
    {
        $readLimit = max(1, min(50, $readLimit));
        $materializeLimit = max(1, min(20, $materializeLimit));
        $definition = self::definitions()[$this->queueKey];
        $this->assertV3Ownership((string) $definition['lane']);
        // 2.34.0: la cola FIFO no puede depender de "id > cursor", porque
        // los trabajos antiguos pueden volverse elegibles después de haber
        // pasado el checkpoint. El cursor se conserva solo como evidencia
        // diagnóstica; la selección usa NOT EXISTS(source_ref) + estado/fecha.
        $checkpoint = 0;
        $statement = $this->pdo->prepare((string) $definition['sql'] . ' LIMIT ' . $readLimit);
        $statement->execute(['cursor' => $checkpoint]);
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);

        $materialized = 0;
        $created = 0;
        $lastImported = $checkpoint;
        foreach (array_slice($rows, 0, $materializeLimit) as $row) {
            $work = $this->toEnvelope($row, $definition);
            try {
                $status = 'ready';
                $reason = null;
                if (($work->payload['cron_v3_parking'] ?? null) === 'waiting_identity') {
                    $status = 'waiting_identity';
                    $reason = 'missing_safe_identity';
                } elseif (!$this->ownsFinalWorkType($work)) {
                    $status = 'waiting_capability';
                    $reason = 'final_work_type_not_owned';
                }
                $result = $status === 'ready'
                    ? ($this->enqueue)($work)
                    : CronV3::enqueueWithStatus($work, $status, $reason);
            } catch (Throwable $error) {
                $this->recordImportFailure($work, $row, $error);
                break;
            }
            $materialized++;
            $created += $result['created'] ? 1 : 0;
            $lastImported = (int) $row['source_id'];
        }
        if ($lastImported !== $checkpoint) {
            $this->saveCheckpoint($lastImported, count($rows), $materialized);
        }

        return [
            'queue_key' => $this->queueKey,
            'read' => count($rows),
            'materialized' => $materialized,
            'created' => $created,
            'checkpoint' => $lastImported,
            'mode' => 'reentrant_fifo',
        ];
    }

    /** @return array<string,array<string,mixed>> */
    public static function definitions(): array
    {
        return [
            'notification_fallback' => [
                'lane' => 'remote',
                'ownership_keys' => ['order_exact', 'pack_exact', 'shipment_exact', 'question_exact', 'claim_exact', 'item_exact'],
                'sql' => 'SELECT w.id source_id,a.company_id,w.meli_account_id,w.resource_type,
                                 w.remote_resource_id,w.latest_event_id,w.status source_status,
                                 w.attempts source_generation,w.updated_at source_version,
                                 s.meli_order_id shipment_order_id,
                                 po.id pack_order_id
                          FROM meli_notification_work_items w
                          JOIN meli_accounts a ON a.id=w.meli_account_id
                          LEFT JOIN meli_shipments s
                            ON w.resource_type="shipment"
                           AND w.remote_resource_id REGEXP "^[0-9]+$"
                           AND s.meli_account_id=w.meli_account_id
                           AND s.external_shipment_id=CAST(w.remote_resource_id AS UNSIGNED)
                          LEFT JOIN meli_orders po
                            ON w.resource_type="pack"
                           AND w.remote_resource_id REGEXP "^[0-9]+$"
                           AND po.meli_account_id=w.meli_account_id
                           AND po.external_pack_id=CAST(w.remote_resource_id AS UNSIGNED)
                          WHERE w.id>:cursor
                            AND w.status IN ("pending","retry")
                            AND w.meli_account_id IS NOT NULL
                            AND w.next_run_at<=UTC_TIMESTAMP()
                            AND (w.lock_expires_at IS NULL OR w.lock_expires_at<UTC_TIMESTAMP())
                            AND NOT EXISTS (
                              SELECT 1 FROM cron_v3_work cw
                              WHERE cw.source_ref=CONCAT("legacy:notification_fallback:",w.id)
                              LIMIT 1
                            )
                          ORDER BY w.id',
            ],
            'pack_exact' => [
                'lane' => 'remote',
                'sql' => 'SELECT j.id source_id,a.company_id,j.meli_account_id,j.meli_order_id,
                                 j.external_resource_id,j.status source_status,j.attempts source_generation,
                                 j.created_at source_version
                          FROM order_resource_enrichment_jobs j
                          JOIN meli_accounts a ON a.id=j.meli_account_id
                          WHERE j.id>:cursor AND j.resource_type="pack"
                            AND j.status IN ("pending","retry")
                            AND NOT EXISTS (
                              SELECT 1 FROM cron_v3_work cw
                              WHERE cw.source_ref=CONCAT("legacy:pack_exact:",j.id)
                              LIMIT 1
                            )
                          ORDER BY j.id',
            ],
            'shipment_exact' => [
                'lane' => 'remote',
                'sql' => 'SELECT j.id source_id,a.company_id,j.meli_account_id,j.meli_order_id,
                                 j.external_resource_id,j.status source_status,j.attempts source_generation,
                                 j.created_at source_version
                          FROM order_resource_enrichment_jobs j
                          JOIN meli_accounts a ON a.id=j.meli_account_id
                          WHERE j.id>:cursor AND j.resource_type="shipment"
                            AND j.status IN ("pending","retry")
                            AND NOT EXISTS (
                              SELECT 1 FROM cron_v3_work cw
                              WHERE cw.source_ref=CONCAT("legacy:shipment_exact:",j.id)
                              LIMIT 1
                            )
                          ORDER BY j.id',
            ],
            'financial_recalc' => [
                'lane' => 'local',
                'sql' => 'SELECT j.id source_id,a.company_id,j.meli_account_id,
                                 j.status source_status,0 source_generation,j.created_at source_version
                          FROM order_financial_recalc_jobs j
                          JOIN meli_accounts a ON a.id=j.meli_account_id
                          WHERE j.id>:cursor AND j.status IN ("pending","running")
                            AND NOT EXISTS (
                              SELECT 1 FROM cron_v3_work cw
                              WHERE cw.source_ref=CONCAT("legacy:financial_recalc:",j.id)
                              LIMIT 1
                            )
                          ORDER BY j.id',
            ],
            'sale_billing_capture' => [
                'lane' => 'remote',
                'sql' => 'SELECT j.id source_id,j.company_id,j.meli_account_id,j.sale_key,
                                 j.status source_status,j.lease_generation source_generation,
                                 j.input_version source_version
                          FROM sale_financial_reconciliation_jobs j
                          JOIN meli_accounts a ON a.id=j.meli_account_id AND a.company_id=j.company_id
                          WHERE j.id>:cursor AND j.status IN ("pending","retry")
                            AND NOT EXISTS (
                              SELECT 1 FROM cron_v3_work cw
                              WHERE cw.source_ref=CONCAT("legacy:sale_billing_capture:",j.id)
                              LIMIT 1
                            )
                          ORDER BY j.id',
            ],
            'orders_search_page' => [
                'lane' => 'remote',
                'sql' => 'SELECT c.id source_id,a.company_id,c.meli_account_id,
                                 c.date_from,c.date_to,c.cursor_offset,c.status source_status,
                                 c.attempt_count source_generation,c.updated_at source_version
                          FROM sync_batch_chunks c
                          JOIN meli_accounts a ON a.id=c.meli_account_id
                          WHERE c.id>:cursor
                            AND c.sync_type="orders"
                            AND c.status IN ("pending","queued","partial")
                            AND (c.next_run_at IS NULL OR c.next_run_at<=UTC_TIMESTAMP())
                            AND NOT EXISTS (
                              SELECT 1 FROM cron_v3_work cw
                              WHERE cw.source_ref=CONCAT("legacy:orders_search_page:",c.id)
                              LIMIT 1
                            )
                          ORDER BY c.id',
            ],
            'items_search_page' => [
                'lane' => 'remote',
                'sql' => 'SELECT j.id source_id,a.company_id,j.meli_account_id,
                                 j.offset_value,j.phase source_status,0 source_generation,j.updated_at source_version
                          FROM meli_item_sync_jobs j
                          JOIN meli_accounts a ON a.id=j.meli_account_id
                          WHERE j.id>:cursor
                            AND j.phase IN ("discovering","partial")
                            AND j.next_run_at<=UTC_TIMESTAMP()
                            AND NOT EXISTS (
                              SELECT 1 FROM cron_v3_work cw
                              WHERE cw.source_ref=CONCAT("legacy:items_search_page:",j.id)
                              LIMIT 1
                            )
                          ORDER BY j.id',
            ],
            'catalog_description_exact' => [
                'lane' => 'remote',
                'sql' => 'SELECT i.id source_id,a.company_id,i.meli_account_id,
                                 i.meli_item_id,i.external_item_id,i.status source_status,
                                 i.attempts source_generation,i.updated_at source_version
                          FROM catalog_description_job_items i
                          JOIN catalog_description_jobs j ON j.id=i.catalog_description_job_id
                          JOIN meli_accounts a ON a.id=i.meli_account_id
                          WHERE i.id>:cursor
                            AND i.status IN ("pending","retry")
                            AND (i.next_retry_at IS NULL OR i.next_retry_at<=UTC_TIMESTAMP())
                            AND j.status IN ("queued","waiting","running")
                            AND NOT EXISTS (
                              SELECT 1 FROM cron_v3_work cw
                              WHERE cw.source_ref=CONCAT("legacy:catalog_description_exact:",i.id)
                              LIMIT 1
                            )
                          ORDER BY i.id',
            ],
            'sale_pack_reconciliation' => [
                'lane' => 'remote',
                'ownership_keys' => ['sale_pack_reconciliation_exact'],
                'sql' => 'SELECT j.id source_id,j.company_id,j.meli_account_id,j.meli_pack_id,
                                 j.operation,j.external_resource_id,j.attempts source_generation,
                                 j.updated_at source_version
                          FROM sale_pack_reconciliation_jobs j
                          JOIN meli_accounts a ON a.id=j.meli_account_id AND a.company_id=j.company_id
                          WHERE j.id>:cursor
                            AND j.status IN ("pending","retry","partial")
                            AND (j.next_run_at IS NULL OR j.next_run_at<=UTC_TIMESTAMP())
                            AND (j.lease_expires_at IS NULL OR j.lease_expires_at<UTC_TIMESTAMP())
                            AND NOT EXISTS (
                              SELECT 1 FROM cron_v3_work cw
                              WHERE cw.source_ref=CONCAT("legacy:sale_pack_reconciliation:",j.id)
                              LIMIT 1
                            )
                          ORDER BY j.priority ASC,j.id',
            ],
            'sales_audit' => [
                'lane' => 'remote',
                'ownership_keys' => ['sales_audit_page'],
                'sql' => 'SELECT j.id source_id,j.company_id,j.meli_account_id,
                                 j.sync_sales_audit_run_id,j.next_offset,j.updated_at source_version
                          FROM sync_sales_audit_jobs j
                          JOIN meli_accounts a ON a.id=j.meli_account_id AND a.company_id=j.company_id
                          WHERE j.id>:cursor
                            AND j.status IN ("pending","waiting_budget")
                            AND (j.next_run_at IS NULL OR j.next_run_at<=UTC_TIMESTAMP())
                            AND (j.lock_expires_at IS NULL OR j.lock_expires_at<UTC_TIMESTAMP())
                            AND NOT EXISTS (
                              SELECT 1 FROM cron_v3_work cw
                              WHERE cw.source_ref=CONCAT("legacy:sales_audit:",j.id)
                              LIMIT 1
                            )
                          ORDER BY j.id',
            ],
            'sales_repair' => [
                'lane' => 'remote',
                'ownership_keys' => ['sales_repair_exact'],
                'sql' => 'SELECT j.id source_id,j.company_id,j.meli_account_id,
                                 j.source_kind,j.period_year,j.period_month,j.updated_at source_version
                          FROM sync_sales_repair_jobs j
                          JOIN meli_accounts a ON a.id=j.meli_account_id AND a.company_id=j.company_id
                          WHERE j.id>:cursor
                            AND j.source_kind="exact"
                            AND j.status IN ("pending","waiting_budget")
                            AND (j.next_run_at IS NULL OR j.next_run_at<=UTC_TIMESTAMP())
                            AND (j.lock_expires_at IS NULL OR j.lock_expires_at<UTC_TIMESTAMP())
                            AND NOT EXISTS (
                              SELECT 1 FROM cron_v3_work cw
                              WHERE cw.source_ref=CONCAT("legacy:sales_repair:",j.id)
                              LIMIT 1
                            )
                          ORDER BY j.id',
            ],
        ];
    }

    /** @param array<string,mixed> $row @param array<string,mixed> $definition */
    private function toEnvelope(array $row, array $definition): WorkEnvelope
    {
        $sourceId = (int) $row['source_id'];
        $payload = [
            'legacy_queue' => $this->queueKey,
            'legacy_job_id' => $sourceId,
            'job_id' => $sourceId,
            'source_status' => (string) ($row['source_status'] ?? ''),
            'source_generation' => (int) ($row['source_generation'] ?? 0),
        ];
        $workType = $this->queueKey;
        $resourceKey = $this->queueKey . ':legacy:' . $sourceId;
        $sourceRef = 'legacy:' . $this->queueKey . ':' . $sourceId;
        if ($this->queueKey === 'notification_fallback') {
            $type = (string) ($row['resource_type'] ?? '');
            $remoteId = trim((string) ($row['remote_resource_id'] ?? ''));
            $payload = [
                'legacy_queue' => 'notification_fallback',
                'notification_work_item_id' => $sourceId,
                'legacy_notification_work_id' => $sourceId,
                'legacy_job_id' => $sourceId,
                'resource_type' => $type,
                'remote_resource_id' => $remoteId,
                'latest_event_id' => (int) ($row['latest_event_id'] ?? 0),
                'source_status' => (string) ($row['source_status'] ?? ''),
                'source_generation' => (int) ($row['source_generation'] ?? 0),
            ];
            if ($type === 'order') {
                if (!preg_match('/^[0-9]+$/', $remoteId)) {
                    return $this->identityRepairEnvelope($row, $payload, 'order_missing_numeric_id');
                }
                $workType = 'order_exact';
                $payload['external_order_id'] = $remoteId;
                $resourceKey = 'notification:order:' . $remoteId;
            } elseif ($type === 'shipment') {
                if (!preg_match('/^[0-9]+$/', $remoteId) || (int) ($row['shipment_order_id'] ?? 0) < 1) {
                    return $this->identityRepairEnvelope($row, $payload, 'shipment_missing_order_relation');
                }
                $workType = 'shipment_exact';
                $payload['meli_order_id'] = (int) ($row['shipment_order_id'] ?? 0);
                $payload['external_resource_id'] = $remoteId;
                $resourceKey = 'notification:shipment:' . $remoteId;
            } elseif ($type === 'pack') {
                if (!preg_match('/^[0-9]+$/', $remoteId) || (int) ($row['pack_order_id'] ?? 0) < 1) {
                    return $this->identityRepairEnvelope($row, $payload, 'pack_missing_order_relation');
                }
                $workType = 'pack_exact';
                $payload['meli_order_id'] = (int) ($row['pack_order_id'] ?? 0);
                $payload['external_resource_id'] = $remoteId;
                $resourceKey = 'notification:pack:' . $remoteId;
            } elseif ($type === 'question') {
                if (!preg_match('/^[0-9]+$/', $remoteId)) {
                    return $this->identityRepairEnvelope($row, $payload, 'question_missing_numeric_id');
                }
                $workType = 'question_exact';
                $payload['question_id'] = $remoteId;
                $resourceKey = 'notification:question:' . $remoteId;
            } elseif ($type === 'claim') {
                if (!preg_match('/^[0-9]+$/', $remoteId)) {
                    return $this->identityRepairEnvelope($row, $payload, 'claim_missing_numeric_id');
                }
                $workType = 'claim_exact';
                $payload['claim_id'] = $remoteId;
                $resourceKey = 'notification:claim:' . $remoteId;
            } elseif ($type === 'item') {
                if (!preg_match('/^[A-Z]{2,4}[0-9]+$/', $remoteId)) {
                    return $this->identityRepairEnvelope($row, $payload, 'item_missing_catalog_id');
                }
                $workType = 'item_exact';
                $payload['external_item_id'] = $remoteId;
                $resourceKey = 'notification:item:' . strtoupper($remoteId);
            } else {
                return $this->identityRepairEnvelope($row, $payload, 'unsupported_notification_resource_type');
            }
            $sourceRef = 'legacy:notification_fallback:' . $sourceId;
        } elseif (in_array($this->queueKey, ['pack_exact', 'shipment_exact'], true)) {
            $payload['meli_order_id'] = (int) $row['meli_order_id'];
            $payload['external_resource_id'] = (string) $row['external_resource_id'];
            $resourceKey = $this->queueKey . ':' . (string) $row['external_resource_id'];
        } elseif ($this->queueKey === 'sale_billing_capture') {
            $payload['sale_key'] = (string) $row['sale_key'];
            $resourceKey = 'billing:' . (string) $row['sale_key'];
        } elseif ($this->queueKey === 'orders_search_page') {
            $payload['date_from'] = (string) $row['date_from'];
            $payload['date_to'] = (string) $row['date_to'];
            $payload['offset'] = max(0, (int) ($row['cursor_offset'] ?? 0));
            $payload['limit'] = 20;
            $resourceKey = 'orders-page:chunk:' . $sourceId . ':offset:' . $payload['offset'];
        } elseif ($this->queueKey === 'items_search_page') {
            $payload['offset'] = max(0, (int) ($row['offset_value'] ?? 0));
            $payload['limit'] = 20;
            $resourceKey = 'items-page:job:' . $sourceId . ':offset:' . $payload['offset'];
        } elseif ($this->queueKey === 'catalog_description_exact') {
            $payload['meli_item_id'] = (int) $row['meli_item_id'];
            $payload['external_item_id'] = (string) $row['external_item_id'];
            $resourceKey = 'description:item:' . (string) $row['external_item_id'];
        } elseif ($this->queueKey === 'sale_pack_reconciliation') {
            $workType = 'sale_pack_reconciliation_exact';
            $payload += [
                'meli_pack_id' => (int) $row['meli_pack_id'],
                'operation' => (string) $row['operation'],
                'external_resource_id' => (string) $row['external_resource_id'],
                'source_generation' => (int) ($row['source_generation'] ?? 0),
            ];
            $resourceKey = 'sale-pack-reconciliation:' . (int) $row['source_id'];
        } elseif ($this->queueKey === 'sales_audit') {
            $workType = 'sales_audit_page';
            $payload += [
                'sync_sales_audit_run_id' => (int) $row['sync_sales_audit_run_id'],
                'next_offset' => (int) ($row['next_offset'] ?? 0),
            ];
            $resourceKey = 'sales-audit-page:' . (int) $row['source_id'] . ':offset:' . (int) ($row['next_offset'] ?? 0);
        } elseif ($this->queueKey === 'sales_repair') {
            $workType = 'sales_repair_exact';
            $payload += [
                'source_kind' => (string) ($row['source_kind'] ?? ''),
                'period_year' => (int) ($row['period_year'] ?? 0),
                'period_month' => (int) ($row['period_month'] ?? 0),
            ];
            $resourceKey = 'sales-repair-exact:' . (int) $row['source_id'];
        }

        return WorkEnvelope::create(
            (int) $row['company_id'],
            (int) $row['meli_account_id'],
            $workType,
            (string) $definition['lane'],
            $resourceKey,
            (string) ($row['source_version'] ?? ('legacy:' . $sourceId)),
            $payload,
            $sourceRef,
            100
        );
    }

    /** @param array<string,mixed> $row @param array<string,mixed> $payload */
    private function identityRepairEnvelope(array $row, array $payload, string $reason): WorkEnvelope
    {
        $sourceId = (int) $row['source_id'];
        $payload['cron_v3_parking'] = 'waiting_identity';
        $payload['parking_reason'] = $reason;

        return WorkEnvelope::create(
            (int) $row['company_id'],
            (int) $row['meli_account_id'],
            'notification_identity_repair',
            'local',
            'notification-identity:' . $sourceId,
            (string) ($row['source_version'] ?? ('legacy:' . $sourceId)),
            $payload,
            'legacy:notification_fallback:' . $sourceId,
            100
        );
    }

    private function assertV3Ownership(string $lane): void
    {
        $definition = self::definitions()[$this->queueKey];
        $keys = array_values(array_unique(array_map(
            'strval',
            (array) ($definition['ownership_keys'] ?? [$this->queueKey])
        )));
        if ($keys === []) {
            throw new RuntimeException('Legacy queue has no Cron V3 ownership contract.');
        }
        $marks = implode(',', array_fill(0, count($keys), '?'));
        $statement = $this->pdo->prepare(
            'SELECT 1 FROM cron_v3_queue_ownership
             WHERE queue_key IN (' . $marks . ') AND lane=? AND owner_engine="v3" AND enabled=1 LIMIT 1'
        );
        $statement->execute(array_merge($keys, [$lane]));
        if ($statement->fetchColumn() === false) {
            throw new RuntimeException('Legacy queue is not owned by Cron V3.');
        }
    }

    private function ownsFinalWorkType(WorkEnvelope $work): bool
    {
        $statement = $this->pdo->prepare(
            'SELECT 1 FROM cron_v3_queue_ownership
             WHERE queue_key=? AND lane=? AND owner_engine="v3" AND enabled=1 LIMIT 1'
        );
        $statement->execute([$work->workType, $work->lane]);
        return $statement->fetchColumn() !== false;
    }

    private function saveCheckpoint(int $sourceId, int $read, int $materialized): void
    {
        $payload = json_encode([
            'last_source_id' => $sourceId,
            'read' => $read,
            'materialized' => $materialized,
            'mode' => 'reentrant_fifo',
            'selection' => 'status_available_not_source_cursor',
        ], JSON_UNESCAPED_SLASHES);
        $this->pdo->prepare(
            'INSERT INTO cron_v3_snapshots
                (snapshot_key,snapshot_type,lane,company_id,meli_account_id,payload_json,generation,observed_at)
             VALUES (?,"run",?,NULL,NULL,?,1,UTC_TIMESTAMP(3))
             ON DUPLICATE KEY UPDATE payload_json=VALUES(payload_json),
                generation=generation+1,observed_at=UTC_TIMESTAMP(3)'
        )->execute([$this->snapshotKey(), self::definitions()[$this->queueKey]['lane'], $payload]);
    }

    /** @param array<string,mixed> $row */
    private function recordImportFailure(WorkEnvelope $work, array $row, Throwable $error): void
    {
        try {
            $sourceId = (int) ($row['source_id'] ?? 0);
            $companyId = (int) ($row['company_id'] ?? $work->companyId);
            $accountId = (int) ($row['meli_account_id'] ?? $work->meliAccountId);
            if ($sourceId < 1 || $companyId < 1 || $accountId < 1) {
                return;
            }

            $statement = $this->pdo->prepare(
                'INSERT INTO cron_v3_legacy_reconciliation
                 (work_id,company_id,meli_account_id,legacy_queue,source_id,status,reason_code,safe_message)
                 VALUES (?,?,?,?,?,"pending","legacy_import_failed",?)
                 ON DUPLICATE KEY UPDATE
                   status=IF(status="completed",status,"pending"),
                   reason_code="legacy_import_failed",
                   safe_message=VALUES(safe_message),
                   attempts=attempts+1,
                   updated_at=UTC_TIMESTAMP(3)'
            );
            $statement->execute([
                (int) ($work->id ?? 0),
                $companyId,
                $accountId,
                $this->queueKey,
                $sourceId,
                'No se pudo materializar este recurso legacy como trabajo V3 exacto. Quedó para diagnóstico local seguro.',
            ]);
        } catch (Throwable) {
            // El diagnóstico auxiliar no debe romper el importador.
        }
    }

    private function snapshotKey(): string
    {
        return 'legacy-adapter:' . $this->queueKey;
    }
}
