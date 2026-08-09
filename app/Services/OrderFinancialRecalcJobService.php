<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Auth;
use App\Core\Database;
use App\Core\HttpException;
use DateTimeImmutable;
use PDO;
use Throwable;

final class OrderFinancialRecalcJobService
{
    /** @var list<int>|null */
    private ?array $authorizedAccountIds = null;
    private bool $scopeResolved = false;

    public function createForRange(int $accountId, string $from, string $to, string $mode = 'all', string $sourceType = 'manual', ?int $sourceId = null, ?int $userId = null): int
    {
        if ($accountId <= 0) {
            throw new HttpException(400, 'El recálculo financiero requiere una cuenta explícita.');
        }
        $this->assertAccountAccess($accountId);
        $scope = $this->accountScope($accountId);
        $fromDate = new DateTimeImmutable($from . (strlen($from) === 10 ? ' 00:00:00' : ''));
        $toDate = new DateTimeImmutable($to . (strlen($to) === 10 ? ' 23:59:59' : ''));
        $mode = $this->normalizeMode($mode);

        if ((new AppSettingsService())->bool('financial_recalc.prevent_duplicate_active_jobs', true)) {
            $existing = $this->activeDuplicate($accountId, $fromDate, $toDate, $mode, $sourceType, $sourceId);
            if ($existing > 0) {
                return $existing;
            }
        }

        $pdo = Database::connection();
        $pdo->beginTransaction();
        try {
            $columns = (new SchemaInspectorService())->columns('order_financial_recalc_jobs');
            $fields = ['company_id','meli_account_id','date_from','date_to','mode','source_type','source_id','status','created_by'];
            $values = [':company',':account',':from',':to',':mode',':source_type',':source_id','"pending"',':user'];
            $params = [
                'company' => (int) $scope['company_id'],
                'account' => $accountId,
                'from' => $fromDate->format('Y-m-d H:i:s'),
                'to' => $toDate->format('Y-m-d H:i:s'),
                'mode' => $mode,
                'source_type' => $sourceType,
                'source_id' => $sourceId,
                'user' => $userId ?? Auth::id(),
            ];
            if (isset($columns['current_phase'])) {
                $fields[] = 'current_phase';
                $values[] = '"local_recalc"';
                $fields[] = 'settings_snapshot_json';
                $values[] = ':snapshot';
                $params['snapshot'] = $this->settingsSnapshot();
            }
            $pdo->prepare('INSERT INTO order_financial_recalc_jobs (' . implode(',', $fields) . ') VALUES (' . implode(',', $values) . ')')->execute($params);
            $jobId = (int) $pdo->lastInsertId();
            $count = $this->insertOrdersForRange($jobId, $accountId, $fromDate, $toDate, $mode);
            $status = $count > 0 ? 'pending' : 'complete';
            $message = $count > 0 ? null : 'Job financiero sin ordenes para procesar en el rango seleccionado.';
            $completedSql = $count > 0 ? 'NULL' : 'UTC_TIMESTAMP()';
            $pdo->prepare('UPDATE order_financial_recalc_jobs SET total_items=:total,status=:status_value,safe_message=:message,completed_at=' . $completedSql . ' WHERE id=:id')
                ->execute(['total' => $count, 'status_value' => $status, 'message' => $message, 'id' => $jobId]);
            $pdo->commit();
            return $jobId;
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    /** @param list<int|string> $orderIds */
    public function createForOrderIds(array $orderIds, string $mode = 'repaired', string $sourceType = 'manual', ?int $sourceId = null, ?int $userId = null): int
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $orderIds), static fn(int $id): bool => $id > 0)));
        $scope = $this->resolveOrderAccount($ids);
        $pdo = Database::connection();
        $pdo->beginTransaction();
        try {
            $columns = (new SchemaInspectorService())->columns('order_financial_recalc_jobs');
            $fields = ['company_id','meli_account_id','mode','source_type','source_id','status','created_by'];
            $values = [':company',':account',':mode',':source_type',':source_id','"pending"',':user'];
            $params = [
                'company' => (int) $scope['company_id'],
                'account' => (int) $scope['meli_account_id'],
                'mode' => $this->normalizeMode($mode),
                'source_type' => $sourceType,
                'source_id' => $sourceId,
                'user' => $userId ?? Auth::id(),
            ];
            if (isset($columns['current_phase'])) {
                $fields[] = 'current_phase';
                $values[] = '"local_recalc"';
                $fields[] = 'settings_snapshot_json';
                $values[] = ':snapshot';
                $params['snapshot'] = $this->settingsSnapshot();
            }
            $pdo->prepare('INSERT INTO order_financial_recalc_jobs (' . implode(',', $fields) . ') VALUES (' . implode(',', $values) . ')')->execute($params);
            $jobId = (int) $pdo->lastInsertId();
            $count = $this->insertOrderIds($jobId, $ids);
            $status = $count > 0 ? 'pending' : 'complete';
            $message = $count > 0 ? null : 'Job financiero sin ordenes para procesar.';
            $completedSql = $count > 0 ? 'NULL' : 'UTC_TIMESTAMP()';
            $pdo->prepare('UPDATE order_financial_recalc_jobs SET total_items=:total,status=:status_value,safe_message=:message,completed_at=' . $completedSql . ' WHERE id=:id')
                ->execute(['total' => $count, 'status_value' => $status, 'message' => $message, 'id' => $jobId]);
            $pdo->commit();
            return $jobId;
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    public function processDue(int $limit = 10, int $accountId = 0): array
    {
        return $this->processNext($accountId, $limit);
    }

    public function processExact(int $jobId, int $accountId, int $limit = 1): array
    {
        return $this->processNext($accountId, $limit, $jobId);
    }

    /** Ejecuta solo la proyección local exacta, sin crear continuación Billing. */
    public function processManualExact(int $jobId,int $accountId): array
    {
        return $this->processNext($accountId,1,$jobId,false);
    }

    public function processNext(int $accountId = 0, ?int $limit = null, int $jobId = 0, bool $allowContinuation = true): array
    {
        if ($accountId > 0) {
            $this->assertAccountAccess($accountId);
        }
        if ($jobId > 0 && $this->find($jobId) === null) {
            throw new HttpException(404, 'No se encontró el trabajo financiero solicitado.');
        }
        $settings = new AppSettingsService();
        $limit ??= $settings->int('financial_recalc.orders_per_run', $settings->int('financial_recalc.batch_limit', $settings->int('orders.financial_recalc_batch_limit', 10)));
        $limit = max(1, min(100, $limit));
        if (!(new SchemaInspectorService())->hasTable('order_financial_recalc_jobs')) {
            return ['processed' => 0, 'jobs' => 0, 'errors' => 0, 'message' => 'Migracion 036 pendiente.', 'queue' => $this->summary($accountId)];
        }

        $job = $this->nextRunnableJob($accountId, $jobId);
        if (!$job) {
            return ['processed' => 0, 'jobs' => 0, 'errors' => 0, 'message' => 'No hay recalculos financieros pendientes.', 'queue' => $this->summary($accountId), 'stop_reason' => 'no_pending_jobs'];
        }

        $jobId = (int) $job['id'];
        $itemColumns = (new SchemaInspectorService())->columns('order_financial_recalc_job_items');
        $this->touchJob($jobId, 'running', 'local_recalc');

        if (isset($itemColumns['phase'])) {
            $legacyBillingItems = $this->pendingItems($jobId, 'billing_import', $limit);
            if ($legacyBillingItems !== []) {
                if(!$allowContinuation){
                    return ['processed'=>0,'jobs'=>1,'errors'=>0,'job_id'=>$jobId,'job'=>$this->find($jobId),'message'=>'La captura Billing queda pendiente; este paso manual no inicia continuaciones.','queue'=>$this->summary($accountId),'stop_reason'=>'manual_single_step_complete'];
                }
                return $this->processBillingPhase($jobId, $accountId, $legacyBillingItems);
            }
        }

        $stmt = Database::connectionFresh()->prepare(
            'SELECT * FROM order_financial_recalc_job_items
             WHERE order_financial_recalc_job_id=:job AND status="pending"
             ORDER BY id ASC LIMIT ' . $limit
        );
        $stmt->execute(['job' => $jobId]);
        $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if ($items === []) {
            $this->finishFromItemCounts($jobId);
            return ['processed' => 0, 'jobs' => 1, 'errors' => 0, 'job_id' => $jobId, 'job' => $this->find($jobId), 'message' => 'El job financiero no tiene ordenes pendientes.', 'queue' => $this->summary($accountId), 'stop_reason' => 'no_pending_items'];
        }

        $processed = 0;
        $queuedBilling = 0;
        $completed = 0;
        $errors = 0;
        $lastError = null;
        $beforeReconnects = Database::reconnectCount();

        foreach ($items as $item) {
            try {
                $projection = (new SaleFinancialStateService())->projectOrder((int) $item['meli_order_id']);
                $summary = (new OrderFinancialService())->findByOrderId((int) $item['meli_order_id']) ?? [];
                $rawSummary = json_decode((string) ($summary['raw_summary_json'] ?? '{}'), true);
                $missingFlags = implode(',', array_slice((array) ($summary['missing_flags'] ?? []), 0, 20));
                if (is_array($rawSummary) && isset($rawSummary['missing_flags'])) {
                    $missingFlags = implode(',', array_slice((array) $rawSummary['missing_flags'], 0, 20));
                }
                $requiresBilling = (string) ($projection['official_status'] ?? 'missing') !== 'complete';
                if ($requiresBilling && $allowContinuation) {
                    (new SaleFinancialService())->queueFromOrderId(
                        (int) $item['meli_order_id'],
                        'financial_recalc_local',
                        $jobId,
                        30,
                        (string) $projection['input_version']
                    );
                    $queuedBilling++;
                }
                if (isset($itemColumns['phase'])) {
                    $sql = 'UPDATE order_financial_recalc_job_items
                         SET status="complete",phase="final_recalc",requires_billing=:requires_billing,
                             billing_status=:billing_status,financial_status=:financial_status,
                             missing_flags=:missing,error_message=NULL,diagnostic_message=:message,
                             processed_at=UTC_TIMESTAMP()
                         WHERE id=:id';
                    $params = [
                        'requires_billing' => $requiresBilling ? 1 : 0,
                        'billing_status' => $requiresBilling ? ($allowContinuation?'queued_sale':'awaiting_sale') : (string) ($summary['billing_import_status'] ?? 'not_required'),
                        'financial_status' => (string) ($projection['provisional_status'] ?? 'missing'),
                        'missing' => mb_substr($missingFlags, 0, 255),
                        'message' => $requiresBilling
                            ? 'Proyección local terminada. La evidencia oficial quedó delegada a la venta y versión exactas.'
                            : 'Proyección local terminada; la versión vigente ya tiene evidencia oficial.',
                        'id' => (int) $item['id'],
                    ];
                    Database::executeWithReconnect(static function (PDO $pdo) use ($sql, $params): void {
                        $pdo->prepare($sql)->execute($params);
                    });
                } else {
                    Database::executeWithReconnect(static function (PDO $pdo) use ($item): void {
                        $pdo->prepare('UPDATE order_financial_recalc_job_items SET status="complete",error_message=NULL,processed_at=UTC_TIMESTAMP() WHERE id=:id')
                            ->execute(['id' => (int) $item['id']]);
                    });
                }
                $completed++;
                $processed++;
            } catch (Throwable $e) {
                $errors++;
                $lastError = SafeErrorPresenter::message(
                    $e,
                    'No fue posible completar el recálculo financiero local.',
                    ['module' => 'financial_recalc', 'stage' => 'local_recalc']
                );
                if (isset($itemColumns['phase'])) {
                    Database::executeWithReconnect(static function (PDO $pdo) use ($item, $lastError): void {
                        $pdo->prepare('UPDATE order_financial_recalc_job_items SET status="error",phase="error",financial_status="error",error_message=:error,diagnostic_message=:error_msg,processed_at=UTC_TIMESTAMP() WHERE id=:id')
                            ->execute(['error' => $lastError, 'error_msg' => $lastError, 'id' => (int) $item['id']]);
                    });
                } else {
                    Database::executeWithReconnect(static function (PDO $pdo) use ($item, $lastError): void {
                        $pdo->prepare('UPDATE order_financial_recalc_job_items SET status="error",error_message=:error,processed_at=UTC_TIMESTAMP() WHERE id=:id')
                            ->execute(['error' => $lastError, 'id' => (int) $item['id']]);
                    });
                }
            }
        }

        $reconnectMessage = $this->recordDbReconnectIfNeeded($jobId, $beforeReconnects);
        $this->setJobPhase($jobId, 'final_recalc', null);
        $this->syncJobCounters($jobId, $lastError);
        $this->finishFromItemCounts($jobId);
        $queue = $this->summary($accountId);
        $message = 'Recalculo financiero local: ' . $processed . ' ordenes revisadas';
        if ($queuedBilling > 0) {
            $message .= ', ' . $queuedBilling . ' delegadas a la cola canónica por venta';
        }
        if ($completed > 0) {
            $message .= ', ' . $completed . ' completas';
        }
        if ($errors > 0) {
            $message .= ', ' . $errors . ' con error';
        }
        if ($reconnectMessage !== null) {
            $message .= ' ' . $reconnectMessage;
        }
        $message .= '.';

        return [
            'processed' => $processed,
            'jobs' => 1,
            'errors' => $errors,
            'job_id' => $jobId,
            'job' => $this->find($jobId),
            'message' => $message,
            'queue' => $queue,
            'stop_reason' => (int) ($queue['active_items'] ?? 0) > 0 ? 'continue' : 'complete',
        ];
    }

    /** @return array<string,mixed> */
    public function summary(int $accountId = 0): array
    {
        if (!(new SchemaInspectorService())->hasTable('order_financial_recalc_jobs')) {
            return ['available' => false, 'message' => 'Migracion financiera pendiente.', 'total_jobs' => 0, 'active_items' => 0];
        }
        $where = [];
        $params = [];
        if ($accountId > 0) {
            $this->assertAccountAccess($accountId);
            $where[] = 'j.meli_account_id=:account';
            $params['account'] = $accountId;
        } else {
            $this->appendAccountScope($where, $params, 'j.meli_account_id');
        }
        $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';
        $jobColumns = (new SchemaInspectorService())->columns('order_financial_recalc_jobs');
        $smartSelects = isset($jobColumns['local_recalc_items'])
            ? 'COALESCE(SUM(COALESCE(j.local_recalc_items,0)),0) local_recalc_items,
                    COALESCE(SUM(COALESCE(j.billing_needed_items,0)),0) billing_needed_items,
                    COALESCE(SUM(COALESCE(j.billing_imported_items,0)),0) billing_imported_items,
                    COALESCE(SUM(COALESCE(j.matched_items,0)),0) matched_items,
                    COALESCE(SUM(COALESCE(j.partial_items,0)),0) partial_items'
            : '0 local_recalc_items, 0 billing_needed_items, 0 billing_imported_items, 0 matched_items, 0 partial_items';
        $stmt = Database::connection()->prepare(
            'SELECT COUNT(*) total_jobs,
                    SUM(j.status="pending") pending_jobs,
                    SUM(j.status="running") running_jobs,
                    SUM(j.status="complete") complete_jobs,
                    SUM(j.status="error") error_jobs,
                    SUM(j.status="cancelled") cancelled_jobs,
                    COALESCE(SUM(j.total_items),0) total_items,
                    COALESCE(SUM(j.processed_items),0) processed_items,
                    COALESCE(SUM(j.error_items),0) error_items,
                    ' . $smartSelects . '
             FROM order_financial_recalc_jobs j ' . $whereSql
        );
        $stmt->execute($params);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        $hasJobCounters = isset($jobColumns['total_items'], $jobColumns['processed_items'], $jobColumns['error_items']);
        if ($hasJobCounters) {
            $itemRow = [
                'pending_items' => max(
                    0,
                    (int) ($row['total_items'] ?? 0)
                    - (int) ($row['processed_items'] ?? 0)
                    - (int) ($row['error_items'] ?? 0)
                ),
                'complete_items' => (int) ($row['processed_items'] ?? 0),
                'failed_items' => (int) ($row['error_items'] ?? 0),
            ];
        } else {
            $items = Database::connection()->prepare(
                'SELECT
                    SUM(i.status="pending") pending_items,
                    SUM(i.status="complete") complete_items,
                    SUM(i.status="error") failed_items
                 FROM order_financial_recalc_job_items i
                 JOIN order_financial_recalc_jobs j ON j.id=i.order_financial_recalc_job_id ' . $whereSql
            );
            $items->execute($params);
            $itemRow = $items->fetch(PDO::FETCH_ASSOC) ?: [];
        }
        $latest = $this->recent($accountId, 1)[0] ?? null;
        $activeItems = (int) ($itemRow['pending_items'] ?? 0);
        $totalItems = max(0, (int) ($row['total_items'] ?? 0));
        $completeItems = (int) ($itemRow['complete_items'] ?? 0);
        $percent = $totalItems > 0 ? min(100.0, ($completeItems / $totalItems) * 100) : 100.0;
        return [
            'available' => true,
            'total_jobs' => (int) ($row['total_jobs'] ?? 0),
            'pending_jobs' => (int) ($row['pending_jobs'] ?? 0),
            'running_jobs' => (int) ($row['running_jobs'] ?? 0),
            'complete_jobs' => (int) ($row['complete_jobs'] ?? 0),
            'error_jobs' => (int) ($row['error_jobs'] ?? 0),
            'cancelled_jobs' => (int) ($row['cancelled_jobs'] ?? 0),
            'total_items' => $totalItems,
            'processed_items' => (int) ($row['processed_items'] ?? 0),
            'complete_items' => $completeItems,
            'pending_items' => $activeItems,
            'failed_items' => (int) ($itemRow['failed_items'] ?? 0),
            'error_items' => (int) ($row['error_items'] ?? 0),
            'active_items' => $activeItems,
            'percent' => $percent,
            'local_recalc_items' => (int) ($row['local_recalc_items'] ?? 0),
            'billing_needed_items' => (int) ($row['billing_needed_items'] ?? 0),
            'billing_imported_items' => (int) ($row['billing_imported_items'] ?? 0),
            'matched_items' => (int) ($row['matched_items'] ?? 0),
            'partial_items' => (int) ($row['partial_items'] ?? 0),
            'latest' => $latest,
        ];
    }

    /** @return list<array<string,mixed>> */
    public function recent(int $accountId = 0, int $limit = 10): array
    {
        if (!(new SchemaInspectorService())->hasTable('order_financial_recalc_jobs')) {
            return [];
        }
        $where = [];
        $params = [];
        if ($accountId > 0) {
            $this->assertAccountAccess($accountId);
            $where[] = 'j.meli_account_id=:account';
            $params['account'] = $accountId;
        } else {
            $this->appendAccountScope($where, $params, 'j.meli_account_id');
        }
        $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';
        $stmt = Database::connection()->prepare(
            'SELECT j.*,a.account_name,u.name created_by_name
             FROM order_financial_recalc_jobs j
             LEFT JOIN meli_accounts a ON a.id=j.meli_account_id
             LEFT JOIN users u ON u.id=j.created_by
             ' . $whereSql . '
             ORDER BY j.id DESC LIMIT ' . max(1, min(50, $limit))
        );
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return array<string,mixed>|null */
    public function find(int $jobId): ?array
    {
        $where = ['j.id=:id'];
        $params = ['id' => $jobId];
        $this->appendAccountScope($where, $params, 'j.meli_account_id');
        $stmt = Database::connection()->prepare(
            'SELECT j.*,a.account_name,u.name created_by_name
             FROM order_financial_recalc_jobs j
             LEFT JOIN meli_accounts a ON a.id=j.meli_account_id
             LEFT JOIN users u ON u.id=j.created_by
             WHERE ' . implode(' AND ', $where) . ' LIMIT 1'
        );
        $stmt->execute($params);
        $job = $stmt->fetch(PDO::FETCH_ASSOC);
        return $job ?: null;
    }

    /** @return list<array<string,mixed>> */
    public function items(int $jobId, string $status = '', int $limit = 100, int $offset = 0): array
    {
        $where = ['i.order_financial_recalc_job_id=:job'];
        $params = ['job' => $jobId];
        $this->appendAccountScope($where, $params, 'j.meli_account_id');
        if (in_array($status, ['pending', 'complete', 'error', 'skipped'], true)) {
            $where[] = 'i.status=:status';
            $params['status'] = $status;
        }
        $stmt = Database::connection()->prepare(
            'SELECT i.*,o.external_order_id AS order_external_id,o.date_created,o.status AS order_status
             FROM order_financial_recalc_job_items i
             JOIN order_financial_recalc_jobs j ON j.id=i.order_financial_recalc_job_id
             JOIN meli_orders o ON o.id=i.meli_order_id
             WHERE ' . implode(' AND ', $where) . '
             ORDER BY i.id ASC LIMIT ' . max(1, min(300, $limit)) . ' OFFSET ' . max(0, $offset)
        );
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return list<array<string,mixed>> */
    public function errors(int $jobId = 0, int $accountId = 0, int $limit = 100): array
    {
        $where = ['i.status="error"'];
        $params = [];
        if ($jobId > 0) {
            $where[] = 'i.order_financial_recalc_job_id=:job';
            $params['job'] = $jobId;
        }
        if ($accountId > 0) {
            $this->assertAccountAccess($accountId);
            $where[] = 'j.meli_account_id=:account';
            $params['account'] = $accountId;
        } else {
            $this->appendAccountScope($where, $params, 'j.meli_account_id');
        }
        $stmt = Database::connection()->prepare(
            'SELECT i.*,j.meli_account_id,j.mode,j.source_type,a.account_name
             FROM order_financial_recalc_job_items i
             JOIN order_financial_recalc_jobs j ON j.id=i.order_financial_recalc_job_id
             LEFT JOIN meli_accounts a ON a.id=j.meli_account_id
             WHERE ' . implode(' AND ', $where) . '
             ORDER BY i.processed_at DESC,i.id DESC LIMIT ' . max(1, min(500, $limit))
        );
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function retryFailed(int $jobId = 0, int $accountId = 0): int
    {
        if (Auth::check() && $jobId <= 0) {
            throw new HttpException(400, 'Seleccione un trabajo financiero exacto para reintentar.');
        }
        $where = ['i.status="error"'];
        $params = [];
        if ($jobId > 0) {
            $where[] = 'i.order_financial_recalc_job_id=:job';
            $params['job'] = $jobId;
        }
        if ($accountId > 0) {
            $this->assertAccountAccess($accountId);
            $where[] = 'j.meli_account_id=:account';
            $params['account'] = $accountId;
        } else {
            $this->appendAccountScope($where, $params, 'j.meli_account_id');
        }
        $pdo = Database::connection();
        $select = $pdo->prepare(
            'SELECT DISTINCT j.id
             FROM order_financial_recalc_job_items i
             JOIN order_financial_recalc_jobs j ON j.id=i.order_financial_recalc_job_id
             WHERE ' . implode(' AND ', $where)
        );
        $select->execute($params);
        $jobIds = array_map('intval', $select->fetchAll(PDO::FETCH_COLUMN));
        $columns = (new SchemaInspectorService())->columns('order_financial_recalc_job_items');
        $set = 'i.status="pending",i.error_message=NULL,i.processed_at=NULL';
        if (isset($columns['phase'])) {
            $set .= ',i.phase="local_recalc",i.financial_status="pending",i.billing_status="pending",i.diagnostic_message=NULL';
        }
        $update = $pdo->prepare(
            'UPDATE order_financial_recalc_job_items i
             JOIN order_financial_recalc_jobs j ON j.id=i.order_financial_recalc_job_id
             SET ' . $set . '
             WHERE ' . implode(' AND ', $where)
        );
        $update->execute($params);
        $count = $update->rowCount();
        foreach ($jobIds as $id) {
            $this->setJobPhase($id, 'local_recalc', null);
            $pdo->prepare('UPDATE order_financial_recalc_jobs SET status="pending",safe_message=NULL,completed_at=NULL,error_items=0 WHERE id=:id')
                ->execute(['id' => $id]);
        }
        return $count;
    }

    public function cancel(int $jobId, ?int $userId = null): void
    {
        $job = $this->find($jobId);
        if (!$job) {
            throw new \RuntimeException('Job financiero no encontrado.');
        }
        if (!in_array((string) $job['status'], ['pending', 'running', 'error'], true)) {
            throw new \RuntimeException('Solo se pueden cancelar jobs financieros pendientes, ejecutando o con error.');
        }
        $columns = (new SchemaInspectorService())->columns('order_financial_recalc_jobs');
        $sets = ['status="cancelled"', 'completed_at=UTC_TIMESTAMP()', 'safe_message="Cancelado por usuario."'];
        $params = ['id' => $jobId];
        if (isset($columns['cancelled_at'])) {
            $sets[] = 'cancelled_at=UTC_TIMESTAMP()';
        }
        if (isset($columns['cancelled_by'])) {
            $sets[] = 'cancelled_by=:user';
            $params['user'] = $userId ?? Auth::id();
        }
        Database::executeWithReconnect(static function (PDO $pdo) use ($sets, $params): void {
            $pdo->prepare('UPDATE order_financial_recalc_jobs SET ' . implode(',', $sets) . ' WHERE id=:id')->execute($params);
        });
    }

    public function activeDuplicate(int $accountId, DateTimeImmutable $from, DateTimeImmutable $to, string $mode, string $sourceType, ?int $sourceId): int
    {
        $this->assertAccountAccess($accountId);
        $stmt = Database::connection()->prepare(
            'SELECT id FROM order_financial_recalc_jobs
             WHERE status IN ("pending","running")
               AND COALESCE(meli_account_id,0)=:account
               AND date_from=:from AND date_to=:to
               AND mode=:mode AND source_type=:source_type
               AND COALESCE(source_id,0)=:source_id
             ORDER BY id DESC LIMIT 1'
        );
        $stmt->execute([
            'account' => $accountId,
            'from' => $from->format('Y-m-d H:i:s'),
            'to' => $to->format('Y-m-d H:i:s'),
            'mode' => $mode,
            'source_type' => $sourceType,
            'source_id' => $sourceId ?? 0,
        ]);
        return (int) ($stmt->fetchColumn() ?: 0);
    }

    /** @param list<array<string,mixed>> $items */
    private function processBillingPhase(int $jobId, int $accountId, array $items): array
    {
        $this->touchJob($jobId, 'running', 'billing_import');
        $processed = 0;
        $errors = 0;
        $lastError = null;
        $beforeReconnects = Database::reconnectCount();
        try {
            $groups = [];
            foreach ($items as $item) {
                $companyId = (int) ($item['company_id'] ?? 0);
                $itemAccountId = (int) ($item['order_account_id'] ?? 0);
                if ($companyId <= 0 || $itemAccountId <= 0) {
                    throw new \RuntimeException('El trabajo financiero contiene una orden sin empresa o cuenta verificable.');
                }
                $groups[$companyId . ':' . $itemAccountId]['company_id'] = $companyId;
                $groups[$companyId . ':' . $itemAccountId]['account_id'] = $itemAccountId;
                $groups[$companyId . ':' . $itemAccountId]['order_ids'][] = (int) $item['meli_order_id'];
            }
            $import = ['requested' => 0, 'imported_orders' => 0, 'billing_rows' => 0, 'skipped' => 0, 'errors' => 0];
            foreach ($groups as $group) {
                $groupImport = [
                    'requested' => count((array) $group['order_ids']),
                    'imported_orders' => 0,
                    'billing_rows' => 0,
                    'skipped' => count((array) $group['order_ids']),
                    'errors' => 0,
                ];
                foreach ($import as $key => $value) {
                    $import[$key] = $value + $groupImport[$key];
                }
            }
            Database::connectionFresh();
            $financial = new OrderFinancialService();
            foreach ($items as $item) {
                try {
                    $summary = $financial->recalculateByOrderId((int) $item['meli_order_id']);
                    $status = (string) ($summary['financial_status'] ?? 'local_partial');
                    $billingStatus = (string) ($summary['billing_import_status'] ?? 'unavailable');
                    $completeStatus = $billingStatus === 'imported'
                        ? 'complete'
                        : (in_array($billingStatus, ['unavailable', 'partial', 'revision_manual', 'error'], true)
                            ? 'error'
                            : 'skipped');
                    $sql = 'UPDATE order_financial_recalc_job_items
                         SET status=:status,phase="final_recalc",billing_status=:billing_status,financial_status=:financial_status,billing_imported_at=CASE WHEN :imported=1 THEN UTC_TIMESTAMP() ELSE billing_imported_at END,error_message=NULL,diagnostic_message=:message,processed_at=UTC_TIMESTAMP()
                         WHERE id=:id';
                    $params = [
                        'status' => $completeStatus,
                        'billing_status' => $billingStatus,
                        'financial_status' => $status,
                        'imported' => $billingStatus === 'imported' ? 1 : 0,
                        'message' => (string) ($summary['safe_message'] ?? 'Billing aplicado o neto local parcial conservado.'),
                        'id' => (int) $item['id'],
                    ];
                    Database::executeWithReconnect(static function (PDO $pdo) use ($sql, $params): void {
                        $pdo->prepare($sql)->execute($params);
                    });
                    $processed++;
                } catch (Throwable $e) {
                    $errors++;
                    $lastError = SafeErrorPresenter::message(
                        $e,
                        'No fue posible completar la evidencia financiera de esta orden.',
                        ['module' => 'financial_recalc', 'stage' => 'final_recalc']
                    );
                    Database::executeWithReconnect(static function (PDO $pdo) use ($item, $lastError): void {
                        $pdo->prepare('UPDATE order_financial_recalc_job_items SET status="error",phase="error",financial_status="error",error_message=:error,diagnostic_message=:error_msg,processed_at=UTC_TIMESTAMP() WHERE id=:id')
                            ->execute(['error' => $lastError, 'error_msg' => $lastError, 'id' => (int) $item['id']]);
                    });
                }
            }
        } catch (Throwable $e) {
            $lastError = SafeErrorPresenter::message(
                $e,
                'El trabajo financiero se aplazó y conservará su punto de avance.',
                ['module' => 'financial_recalc', 'stage' => 'billing_import']
            );
            $dbLost = Database::isLostConnection($e);
            if ($dbLost) {
                try {
                    Database::reconnect();
                    $this->recordDbReconnectIfNeeded($jobId, $beforeReconnects, 'La conexion MySQL se cerro durante el proceso; se reconecto y puede continuar.');
                } catch (Throwable $dbError) {
                    $lastError = SafeErrorPresenter::message(
                        $dbError,
                        'La conexión local se interrumpió. El trabajo conservará su avance para el siguiente ciclo.',
                        ['module' => 'financial_recalc', 'stage' => 'database_reconnect']
                    );
                }
            }
            $pauseReason = $dbLost ? 'db_connection' : ($e instanceof MeliApiException && $e->httpStatus === 429 ? 'api_limit' : ($e instanceof MeliApiException && $e->httpStatus === 403 ? 'forbidden' : 'billing_error'));
            $this->setJobPhase($jobId, 'billing_import', $pauseReason, $lastError);
            Database::executeWithReconnect(static function (PDO $pdo) use ($jobId): void {
                $pdo->prepare('UPDATE order_financial_recalc_jobs SET status="pending" WHERE id=:id')->execute(['id' => $jobId]);
            });
            $this->syncJobCounters($jobId, $lastError);
            $message = $dbLost
                ? 'MySQL cerro la conexion durante el recalculo financiero. El job quedo pendiente; intente continuar o deje que cron lo retome.'
                : 'Billing pausado: ' . $lastError;
            return [
                'processed' => 0,
                'jobs' => 1,
                'errors' => $pauseReason === 'forbidden' ? 1 : 0,
                'deferred' => $pauseReason === 'forbidden' ? 0 : 1,
                'status' => $pauseReason === 'api_limit'
                    ? 'waiting_rhythm'
                    : ($pauseReason === 'forbidden' ? 'action_required' : 'retry'),
                'job_id' => $jobId,
                'job' => $this->find($jobId),
                'message' => $message,
                'queue' => $this->summary($accountId),
                'stop_reason' => $pauseReason,
            ];
        }

        $reconnectMessage = $this->recordDbReconnectIfNeeded($jobId, $beforeReconnects);
        $this->setJobPhase($jobId, 'final_recalc', null);
        $this->syncJobCounters($jobId, $lastError);
        $this->finishFromItemCounts($jobId);
        $queue = $this->summary($accountId);
        return [
            'processed' => $processed,
            'jobs' => 1,
            'errors' => $errors,
            'job_id' => $jobId,
            'job' => $this->find($jobId),
            'message' => 'Billing financiero: ' . $processed . ' ordenes procesadas' . ($errors > 0 ? ', ' . $errors . ' con error' : '') . ($reconnectMessage !== null ? '. ' . $reconnectMessage : '') . '.',
            'queue' => $queue,
            'stop_reason' => (int) ($queue['active_items'] ?? 0) > 0 ? 'continue' : 'complete',
        ];
    }

    /** @return list<array<string,mixed>> */
    private function pendingItems(int $jobId, string $phase, int $limit): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT i.*,o.meli_account_id order_account_id,a.company_id
             FROM order_financial_recalc_job_items i
             JOIN meli_orders o ON o.id=i.meli_order_id
             JOIN meli_accounts a ON a.id=o.meli_account_id
             WHERE i.order_financial_recalc_job_id=:job AND i.status="pending" AND i.phase=:phase
             ORDER BY i.id ASC LIMIT ' . max(1, min(100, $limit))
        );
        $stmt->execute(['job' => $jobId, 'phase' => $phase]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function nextRunnableJob(int $accountId, int $jobId = 0): ?array
    {
        $where = [
            'j.status IN ("pending","running")',
            'j.company_id IS NOT NULL',
            'j.meli_account_id IS NOT NULL',
        ];
        $params = [];
        if ($accountId > 0) {
            $where[] = 'j.meli_account_id=:account';
            $params['account'] = $accountId;
        }
        if ($jobId > 0) {
            $where[] = 'j.id=:job_id';
            $params['job_id'] = $jobId;
        }
        $reservationGuard = ManualCampaignReservationGuard::sql('financial_recalc', 'j.id');
        $stmt = Database::connection()->prepare(
            'SELECT j.*
             FROM order_financial_recalc_jobs j
             WHERE ' . implode(' AND ', $where) . $reservationGuard . '
             ORDER BY FIELD(j.status,"running","pending"),j.id ASC LIMIT 1'
        );
        $stmt->execute($params);
        $job = $stmt->fetch(PDO::FETCH_ASSOC);
        return $job ?: null;
    }

    private function touchJob(int $jobId, string $status, string $phase = 'local_recalc'): void
    {
        $columns = (new SchemaInspectorService())->columns('order_financial_recalc_jobs');
        $sets = ['status=:status', 'started_at=COALESCE(started_at,UTC_TIMESTAMP())'];
        $params = ['status' => $status, 'id' => $jobId];
        if (isset($columns['last_processed_at'])) {
            $sets[] = 'last_processed_at=UTC_TIMESTAMP()';
        }
        if (isset($columns['current_phase'])) {
            $sets[] = 'current_phase=:phase';
            $params['phase'] = $phase;
        }
        Database::executeWithReconnect(static function (PDO $pdo) use ($sets, $params): void {
            $pdo->prepare('UPDATE order_financial_recalc_jobs SET ' . implode(',', $sets) . ' WHERE id=:id')->execute($params);
        });
    }

    private function setJobPhase(int $jobId, string $phase, ?string $pauseReason, ?string $message = null): void
    {
        $columns = (new SchemaInspectorService())->columns('order_financial_recalc_jobs');
        if (!isset($columns['current_phase'])) {
            return;
        }
        $sets = ['current_phase=:phase'];
        $params = ['phase' => $phase, 'id' => $jobId];
        if (isset($columns['last_pause_reason'])) {
            $sets[] = 'last_pause_reason=:pause_reason';
            $params['pause_reason'] = $pauseReason;
        }
        if ($message !== null && isset($columns['last_error_message'])) {
            $sets[] = 'last_error_message=:last_error_message';
            $sets[] = 'safe_message=:safe_message';
            $params['last_error_message'] = mb_substr($message, 0, 500);
            $params['safe_message'] = mb_substr($message, 0, 500);
        }
        Database::executeWithReconnect(static function (PDO $pdo) use ($sets, $params): void {
            $pdo->prepare('UPDATE order_financial_recalc_jobs SET ' . implode(',', $sets) . ' WHERE id=:id')->execute($params);
        });
    }

    private function syncJobCounters(int $jobId, ?string $lastError): void
    {
        $columns = (new SchemaInspectorService())->columns('order_financial_recalc_jobs');
        $sets = [
            'processed_items=(SELECT COUNT(*) FROM order_financial_recalc_job_items WHERE order_financial_recalc_job_id=:id_a AND status="complete")',
            'error_items=(SELECT COUNT(*) FROM order_financial_recalc_job_items WHERE order_financial_recalc_job_id=:id_b AND status="error")',
        ];
        $params = ['id_a' => $jobId, 'id_b' => $jobId, 'id' => $jobId];
        if (isset($columns['last_processed_at'])) {
            $sets[] = 'last_processed_at=UTC_TIMESTAMP()';
        }
        if ($lastError !== null && isset($columns['last_error_message'])) {
            $sets[] = 'last_error_message=:last_error';
            $params['last_error'] = $lastError;
        }
        if (isset($columns['local_recalc_items'])) {
            $sets[] = 'local_recalc_items=(SELECT COUNT(*) FROM order_financial_recalc_job_items WHERE order_financial_recalc_job_id=:id_c AND phase IN ("billing_import","final_recalc","error"))';
            $sets[] = 'billing_needed_items=(SELECT COUNT(*) FROM order_financial_recalc_job_items WHERE order_financial_recalc_job_id=:id_d AND requires_billing=1)';
            $sets[] = 'billing_imported_items=(SELECT COUNT(*) FROM order_financial_recalc_job_items WHERE order_financial_recalc_job_id=:id_e AND billing_status="imported")';
            $sets[] = 'matched_items=(SELECT COUNT(*) FROM order_financial_recalc_job_items WHERE order_financial_recalc_job_id=:id_f AND financial_status="matched")';
            $sets[] = 'partial_items=(SELECT COUNT(*) FROM order_financial_recalc_job_items WHERE order_financial_recalc_job_id=:id_g AND financial_status IN ("local_partial","missing_cost"))';
            $params += ['id_c' => $jobId, 'id_d' => $jobId, 'id_e' => $jobId, 'id_f' => $jobId, 'id_g' => $jobId];
        }
        Database::executeWithReconnect(static function (PDO $pdo) use ($sets, $params): void {
            $pdo->prepare('UPDATE order_financial_recalc_jobs SET ' . implode(',', $sets) . ' WHERE id=:id')->execute($params);
        });
    }

    private function recordDbReconnectIfNeeded(int $jobId, int $beforeReconnects, ?string $message = null): ?string
    {
        $diff = Database::reconnectCount() - $beforeReconnects;
        if ($diff <= 0) {
            return null;
        }

        $message ??= 'La conexion MySQL se cerro durante el proceso; se reconecto y puede continuar.';
        $columns = (new SchemaInspectorService())->columns('order_financial_recalc_jobs');
        $sets = [];
        $params = ['id' => $jobId];
        if (isset($columns['db_reconnect_count'])) {
            $sets[] = 'db_reconnect_count=db_reconnect_count+:diff';
            $params['diff'] = $diff;
        }
        if (isset($columns['last_db_reconnect_at'])) {
            $sets[] = 'last_db_reconnect_at=UTC_TIMESTAMP()';
        }
        if (isset($columns['last_db_error_message'])) {
            $sets[] = 'last_db_error_message=:last_db_error_message';
            $params['last_db_error_message'] = mb_substr($message, 0, 500);
        }
        if (isset($columns['last_error_message'])) {
            $sets[] = 'last_error_message=:last_error_message';
            $params['last_error_message'] = mb_substr($message, 0, 500);
        }
        if (isset($columns['safe_message'])) {
            $sets[] = 'safe_message=:safe_message';
            $params['safe_message'] = mb_substr($message, 0, 500);
        }
        if ($sets !== []) {
            Database::executeWithReconnect(static function (PDO $pdo) use ($sets, $params): void {
                $pdo->prepare('UPDATE order_financial_recalc_jobs SET ' . implode(',', $sets) . ' WHERE id=:id')->execute($params);
            });
        }
        return $message;
    }

    private function finishFromItemCounts(int $jobId): void
    {
        $pdo = Database::connectionFresh();
        $stmt = $pdo->prepare(
            'SELECT
                COUNT(*) total,
                SUM(status="pending") pending_count,
                SUM(status="complete") complete_count,
                SUM(status="error") error_count
             FROM order_financial_recalc_job_items WHERE order_financial_recalc_job_id=:job'
        );
        $stmt->execute(['job' => $jobId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        $total = (int) ($row['total'] ?? 0);
        $pending = (int) ($row['pending_count'] ?? 0);
        $complete = (int) ($row['complete_count'] ?? 0);
        $errors = (int) ($row['error_count'] ?? 0);
        if ($pending > 0) {
            $pdo->prepare('UPDATE order_financial_recalc_jobs SET status="pending",processed_items=:processed,error_items=:errors WHERE id=:id')
                ->execute(['processed' => $complete, 'errors' => $errors, 'id' => $jobId]);
            return;
        }
        $status = $errors > 0 ? 'error' : 'complete';
        $message = $total === 0 ? 'Job financiero sin ordenes para procesar.' : ($errors > 0 ? 'Recalculo terminado con errores en algunas ordenes.' : 'Recalculo financiero terminado.');
        $pdo->prepare(
            'UPDATE order_financial_recalc_jobs
             SET status=:status,total_items=:total,processed_items=:processed,error_items=:errors,safe_message=:message,completed_at=UTC_TIMESTAMP()
             WHERE id=:id'
        )->execute(['status' => $status, 'total' => $total, 'processed' => $complete, 'errors' => $errors, 'message' => $message, 'id' => $jobId]);
    }

    private function insertOrdersForRange(int $jobId, int $accountId, DateTimeImmutable $from, DateTimeImmutable $to, string $mode): int
    {
        $this->assertAccountAccess($accountId);
        $dateColumn = (new SchemaInspectorService())->hasColumn('meli_payments', 'date_approved_local') ? 'COALESCE(p.date_approved_local,p.date_approved)' : 'p.date_approved';
        $where = ['p.status="approved"', $dateColumn . '>=:from', $dateColumn . '<=:to'];
        $params = ['from' => $from->format('Y-m-d H:i:s'), 'to' => $to->format('Y-m-d H:i:s')];
        if ($accountId > 0) {
            $where[] = 'o.meli_account_id=:account';
            $params['account'] = $accountId;
        }
        $mode = $this->normalizeMode($mode);
        if ($mode === 'pending') {
            $where[] = '(f.id IS NULL OR f.reconciliation_status IN ("pending","queued","error"))';
        } elseif ($mode === 'failed') {
            $where[] = 'f.reconciliation_status="error"';
        }
        $maxOrders = max(1, min(50000, (new AppSettingsService())->int('financial_recalc.max_orders_per_job', 500)));
        $sql = 'SELECT DISTINCT o.id,o.external_order_id
                FROM meli_orders o
                JOIN meli_payments p ON p.meli_order_id=o.id
                LEFT JOIN meli_order_financials f ON f.meli_order_id=o.id
                WHERE ' . implode(' AND ', $where) . '
                ORDER BY o.id ASC LIMIT ' . $maxOrders;
        $stmt = Database::connectionFresh()->prepare($sql);
        $stmt->execute($params);
        return $this->insertRows($jobId, $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /** @param list<int> $ids */
    private function insertOrderIds(int $jobId, array $ids): int
    {
        if ($ids === []) {
            return 0;
        }
        $rows = [];
        foreach (array_chunk($ids, 500) as $chunk) {
            $placeholders = implode(',', array_fill(0, count($chunk), '?'));
            $where = ['id IN (' . $placeholders . ')'];
            $params = $chunk;
            $this->appendAccountScope($where, $params, 'meli_account_id');
            $stmt = Database::connectionFresh()->prepare(
                'SELECT id,external_order_id FROM meli_orders WHERE ' . implode(' AND ', $where)
            );
            $stmt->execute($params);
            $rows = array_merge($rows, $stmt->fetchAll(PDO::FETCH_ASSOC));
        }
        if ($this->webAccountScope() !== null && count($rows) !== count($ids)) {
            throw new HttpException(404, 'Una o más órdenes no pertenecen al alcance autorizado.');
        }
        return $this->insertRows($jobId, $rows);
    }

    /** @param list<int> $ids */
    private function resolveOrderAccount(array $ids): array
    {
        if ($ids === []) {
            throw new HttpException(400, 'Seleccione al menos una orden para el recálculo.');
        }
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $where = ['o.id IN (' . $placeholders . ')'];
        $params = $ids;
        $this->appendAccountScope($where, $params, 'o.meli_account_id');
        $stmt = Database::connectionFresh()->prepare(
            'SELECT COUNT(*) total,COUNT(DISTINCT o.meli_account_id) accounts,
                    COUNT(DISTINCT a.company_id) companies,MIN(o.meli_account_id) account_id,
                    MIN(a.company_id) company_id
             FROM meli_orders o
             JOIN meli_accounts a ON a.id=o.meli_account_id
             WHERE ' . implode(' AND ', $where)
        );
        $stmt->execute($params);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        if ((int) ($row['total'] ?? 0) !== count($ids)
            || (int) ($row['accounts'] ?? 0) !== 1
            || (int) ($row['companies'] ?? 0) !== 1) {
            throw new HttpException(404, 'Las órdenes no pertenecen a una única cuenta autorizada.');
        }
        return [
            'company_id' => (int) $row['company_id'],
            'meli_account_id' => (int) $row['account_id'],
        ];
    }

    /** @return array{company_id:int,meli_account_id:int} */
    private function accountScope(int $accountId): array
    {
        $stmt = Database::connectionFresh()->prepare(
            'SELECT company_id,id meli_account_id FROM meli_accounts WHERE id=? LIMIT 1'
        );
        $stmt->execute([$accountId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            throw new HttpException(404, 'No se encontró la cuenta solicitada.');
        }
        return ['company_id' => (int) $row['company_id'], 'meli_account_id' => (int) $row['meli_account_id']];
    }

    /** @param list<array<string,mixed>> $rows */
    private function insertRows(int $jobId, array $rows): int
    {
        $columns = (new SchemaInspectorService())->columns('order_financial_recalc_job_items');
        $hasPhase = isset($columns['phase']);
        $sql = $hasPhase
            ? 'INSERT IGNORE INTO order_financial_recalc_job_items (order_financial_recalc_job_id,meli_order_id,external_order_id,status,phase,billing_status,financial_status) VALUES (:job,:order_id,:external,"pending","local_recalc","pending","pending")'
            : 'INSERT IGNORE INTO order_financial_recalc_job_items (order_financial_recalc_job_id,meli_order_id,external_order_id,status) VALUES (:job,:order_id,:external,"pending")';
        $count = 0;
        foreach ($rows as $row) {
            $params = ['job' => $jobId, 'order_id' => (int) $row['id'], 'external' => (string) $row['external_order_id']];
            $count += (int) Database::executeWithReconnect(static function (PDO $pdo) use ($sql, $params): int {
                $stmt = $pdo->prepare($sql);
                $stmt->execute($params);
                return $stmt->rowCount();
            });
        }
        return $count;
    }

    private function settingsSnapshot(): string
    {
        $settings = new AppSettingsService();
        return json_encode([
            'orders_per_run' => $settings->int('financial_recalc.orders_per_run', 10),
            'reconnect_between_steps' => $settings->bool('financial_recalc.reconnect_between_steps', true),
            'auto_billing_for_missing' => false,
            'use_billing_order_details' => false,
            'remote_transport' => false,
            'billing_authority' => 'sale_financial_reconciliation',
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}';
    }

    /** @return list<int>|null null solo para CLI sin sesión. */
    private function webAccountScope(): ?array
    {
        if ($this->scopeResolved) {
            return $this->authorizedAccountIds;
        }
        $this->scopeResolved = true;
        if (!Auth::check()) {
            $this->authorizedAccountIds = null;
            return null;
        }
        $this->authorizedAccountIds = (new BusinessScopeContext())->accountIds((int) Auth::id());
        return $this->authorizedAccountIds;
    }

    /** @param array<int|string,mixed> $params */
    private function appendAccountScope(array &$where, array &$params, string $column): void
    {
        $scope = $this->webAccountScope();
        if ($scope === null) {
            $where[] = '1=1';
            return;
        }
        if ($scope === []) {
            $where[] = '1=0';
            return;
        }
        if (array_is_list($params)) {
            $where[] = $column . ' IN (' . implode(',', array_fill(0, count($scope), '?')) . ')';
            array_push($params, ...$scope);
            return;
        }
        $placeholders = [];
        foreach ($scope as $index => $accountId) {
            $key = 'authorized_account_' . count($params) . '_' . $index;
            $placeholders[] = ':' . $key;
            $params[$key] = $accountId;
        }
        $where[] = $column . ' IN (' . implode(',', $placeholders) . ')';
    }

    private function assertAccountAccess(int $accountId): void
    {
        $scope = $this->webAccountScope();
        // CLI jobs historically use account_id=0 to mean that the orchestrator
        // will resolve the account from the exact resource. Keep that contract
        // outside HTTP, but never permit it for an authenticated request.
        if ($scope === null) {
            if ($accountId < 0) {
                throw new HttpException(404, 'No se encontró la cuenta solicitada.');
            }

            return;
        }

        if ($accountId <= 0 || !in_array($accountId, $scope, true)) {
            throw new HttpException(404, 'No se encontró la cuenta solicitada.');
        }
    }

    private function normalizeMode(string $mode): string
    {
        return in_array($mode, ['all', 'pending', 'failed', 'repaired', 'day', 'month', 'report_range'], true) ? $mode : 'pending';
    }
}
