<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Crypto;
use App\Core\Database;
use PDO;
use Throwable;

/**
 * Prepara datos fiscales mediante trabajos CLI. La respuesta remota se cifra
 * antes de persistirla y nunca se incluye en mensajes, eventos o diagnósticos.
 */
final class SalesFiscalPreparationService
{
    public function available(): bool
    {
        $schema = new SchemaInspectorService();
        return $schema->hasTable('sales_control_fiscal_jobs')
            && $schema->hasTable('sales_control_fiscal_job_items')
            && $schema->hasTable('sales_control_fiscal_snapshots');
    }

    public function create(int $accountId, int $year, int $month, int $companyId, ?int $userId): int
    {
        if (!$this->available()) {
            throw new \RuntimeException('La preparación fiscal todavía no está instalada.');
        }
        $account = (new SalesAuditAccessGateway())->account($accountId, $companyId);
        $companyId = (int) $account['company_id'];
        $pdo = Database::connectionFresh();
        $monthStmt = $pdo->prepare(
            'SELECT * FROM sales_control_months
             WHERE company_id=? AND meli_account_id=? AND period_year=? AND period_month=? LIMIT 1'
        );
        $monthStmt->execute([$companyId, $accountId, $year, $month]);
        $controlMonth = $monthStmt->fetch(PDO::FETCH_ASSOC);
        if (!$controlMonth || !in_array((string) $controlMonth['status'], ['sales_verified', 'fiscal_incomplete', 'ready_to_close', 'reopened'], true)) {
            throw new \RuntimeException('Primero debe comprobar las ventas del mes y resolver sus diferencias.');
        }
        $lockName = 'sales-fiscal-create:' . (int) $controlMonth['id'];
        $lock = $pdo->prepare('SELECT GET_LOCK(?,3)');
        $lock->execute([$lockName]);
        if ((int) $lock->fetchColumn() !== 1) {
            throw new \RuntimeException('Otra solicitud está preparando este mes. Espere unos segundos.');
        }
        try {
            $active = $pdo->prepare(
                'SELECT id FROM sales_control_fiscal_jobs
                 WHERE sales_control_month_id=? AND company_id=? AND meli_account_id=?
                   AND status IN ("pending","running","waiting_budget","retry","paused")
                 ORDER BY id DESC LIMIT 1'
            );
            $active->execute([(int) $controlMonth['id'], $companyId, $accountId]);
            $existing = (int) $active->fetchColumn();
            if ($existing > 0) {
                return $existing;
            }
            $orders = $pdo->prepare(
                'SELECT fi.meli_order_id,fi.external_order_id,o.id,o.meli_account_id,o.raw_json,a.site_id
                 FROM sales_control_fiscal_items fi
                 JOIN meli_orders o ON o.id=fi.meli_order_id AND o.meli_account_id=fi.meli_account_id
                 JOIN meli_accounts a ON a.id=fi.meli_account_id AND a.company_id=fi.company_id
                 WHERE fi.sales_control_month_id=? AND fi.company_id=? AND fi.meli_account_id=?
                   AND fi.fiscal_status="fiscal_data_required"
                 ORDER BY fi.id'
            );
            $orders->execute([(int) $controlMonth['id'], $companyId, $accountId]);
            $pdo->beginTransaction();
            try {
                $pdo->prepare(
                    'INSERT INTO sales_control_fiscal_jobs
                     (sales_control_month_id,company_id,meli_account_id,status,created_by)
                     VALUES (?,?,?,"pending",?)'
                )->execute([(int) $controlMonth['id'], $companyId, $accountId, $userId]);
                $jobId = (int) $pdo->lastInsertId();
                $insert = $pdo->prepare(
                    'INSERT INTO sales_control_fiscal_job_items
                     (sales_control_fiscal_job_id,company_id,meli_account_id,meli_order_id,
                      external_order_id,billing_info_id,site_id,status,next_run_at)
                     VALUES (?,?,?,?,?,?,?,?,UTC_TIMESTAMP())'
                );
                $total = 0;
                foreach ($orders->fetchAll(PDO::FETCH_ASSOC) as $order) {
                    $raw = (new RawPayloadReader())->decode($order, 'meli_orders');
                    $billingInfoId = is_array($raw)
                        ? trim((string) ($raw['buyer']['billing_info']['id'] ?? ''))
                        : '';
                    $siteId = strtoupper(trim((string) ($order['site_id'] ?? '')));
                    $eligible = $billingInfoId !== '' && preg_match('/^[A-Za-z0-9_-]+$/', $billingInfoId) === 1
                        && preg_match('/^[A-Z]{3}$/', $siteId) === 1;
                    $insert->execute([
                        $jobId, $companyId, $accountId, (int) $order['meli_order_id'],
                        (string) $order['external_order_id'],
                        $billingInfoId !== '' ? $billingInfoId : null,
                        $siteId !== '' ? $siteId : null,
                        $eligible ? 'pending' : 'missing',
                    ]);
                    $total++;
                }
                if ($total === 0) {
                    throw new \RuntimeException('No hay ventas que requieran consultar datos fiscales.');
                }
                $pdo->prepare(
                    'UPDATE sales_control_fiscal_jobs SET total_items=? WHERE id=? AND company_id=? AND meli_account_id=?'
                )->execute([$total, $jobId, $companyId, $accountId]);
                $pdo->commit();
                return $jobId;
            } catch (Throwable $error) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                throw $error;
            }
        } finally {
            try {
                $release = $pdo->prepare('SELECT RELEASE_LOCK(?)');
                $release->execute([$lockName]);
            } catch (Throwable) {
            }
        }
    }

    /** @return array<string,mixed> */
    public function processDue(int $limit = 1): array
    {
        if (!$this->available()) {
            return ['processed' => 0, 'errors' => 0, 'status' => 'empty'];
        }
        $limit = max(1, min(3, $limit));
        $worker = 'sales-fiscal-' . getmypid() . '-' . bin2hex(random_bytes(3));
        $job = $this->claim($worker);
        if (!$job) {
            return ['processed' => 0, 'errors' => 0, 'status' => 'empty'];
        }
        $processed = 0;
        $errors = 0;
        for ($i = 0; $i < $limit; $i++) {
            $item = $this->claimItem($job, $worker);
            if (!$item) {
                break;
            }
            try {
                $payload = (new MeliApiClient((int) $job['meli_account_id']))->get(
                    '/orders/billing-info/' . rawurlencode((string) $item['site_id'])
                    . '/' . rawurlencode((string) $item['billing_info_id']),
                    [],
                    [
                        'job_type' => 'sales_fiscal',
                        'source' => 'cron',
                        'source_queue_key' => 'sales_fiscal',
                        'source_work_id' => (string) $job['id'],
                        'bulk' => false,
                    ]
                );
                if (!$this->ownsLease($job, $worker)) {
                    return ['processed' => $processed, 'errors' => $errors, 'status' => 'lease_lost'];
                }
                $this->persistSnapshot($job, $item, $payload);
                $processed++;
            } catch (ApiBudgetExhaustedException|ApiManualPauseException $error) {
                $next = $error instanceof ApiBudgetExhaustedException && $error->nextSafeAt
                    ? $error->nextSafeAt
                    : gmdate('Y-m-d H:i:s', time() + 900);
                $this->defer($job, $item, $worker, 'waiting_budget', $next, 'Esperando una ventana segura de consultas.', null);
                return ['processed' => $processed, 'errors' => $errors, 'status' => 'waiting_budget'];
            } catch (MeliApiException $error) {
                $status = (int) ($error->httpStatus ?? 0);
                if ($status === 404) {
                    $this->finishItem($job, $item, $worker, 'missing', 'Mercado Libre no entregó datos fiscales para esta venta.', $error->requestId);
                    $processed++;
                    continue;
                }
                if ($status === 429) {
                    $retry = max(60, (int) ($error->response['retry_after'] ?? 900));
                    $this->defer($job, $item, $worker, 'retry', gmdate('Y-m-d H:i:s', time() + $retry), 'Mercado Libre pidió esperar antes de continuar.', $error->requestId);
                    return ['processed' => $processed, 'errors' => $errors, 'status' => 'deferred'];
                }
                $this->finishItem(
                    $job,
                    $item,
                    $worker,
                    'error',
                    $status === 403
                        ? 'La cuenta no tiene permiso para consultar estos datos fiscales.'
                        : 'No fue posible consultar los datos fiscales de esta venta.',
                    $error->requestId
                );
                $errors++;
            } catch (Throwable $error) {
                $safe = SafeErrorPresenter::report(
                    $error,
                    'No fue posible guardar la preparación fiscal.',
                    ['module' => 'sales_fiscal', 'job_id' => (int) $job['id'], 'item_id' => (int) $item['id']]
                );
                $this->finishItem($job, $item, $worker, 'error', $safe['message'], $safe['reference']);
                $errors++;
                break;
            }
        }
        return $this->finalize($job, $worker, $processed, $errors);
    }

    /** @return array<string,mixed>|null */
    private function claim(string $worker): ?array
    {
        $pdo = Database::connectionFresh();
        $pdo->beginTransaction();
        try {
            $job = $pdo->query(
                'SELECT * FROM sales_control_fiscal_jobs
                 WHERE status IN ("pending","retry","waiting_budget")
                   AND next_run_at<=UTC_TIMESTAMP()
                   AND (lock_expires_at IS NULL OR lock_expires_at<UTC_TIMESTAMP())
                 ORDER BY created_at,id LIMIT 1 FOR UPDATE'
            )->fetch(PDO::FETCH_ASSOC);
            if (!$job) {
                $pdo->commit();
                return null;
            }
            $pdo->prepare(
                'UPDATE sales_control_fiscal_jobs
                 SET status="running",lock_owner=?,lock_expires_at=DATE_ADD(UTC_TIMESTAMP(),INTERVAL 60 SECOND),
                     heartbeat_at=UTC_TIMESTAMP(),lease_generation=lease_generation+1,
                     started_at=COALESCE(started_at,UTC_TIMESTAMP())
                 WHERE id=?'
            )->execute([$worker, (int) $job['id']]);
            $pdo->commit();
            $job['lease_generation'] = (int) $job['lease_generation'] + 1;
            $job['lock_owner'] = $worker;
            return $job;
        } catch (Throwable $error) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $error;
        }
    }

    /** @return array<string,mixed>|null */
    private function claimItem(array $job, string $worker): ?array
    {
        if (!$this->ownsLease($job, $worker)) {
            return null;
        }
        $pdo = Database::connectionFresh();
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare(
                'SELECT * FROM sales_control_fiscal_job_items
                 WHERE sales_control_fiscal_job_id=? AND company_id=? AND meli_account_id=?
                   AND status IN ("pending","retry")
                   AND (next_run_at IS NULL OR next_run_at<=UTC_TIMESTAMP())
                 ORDER BY id LIMIT 1 FOR UPDATE'
            );
            $stmt->execute([(int) $job['id'], (int) $job['company_id'], (int) $job['meli_account_id']]);
            $item = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$item) {
                $pdo->commit();
                return null;
            }
            $pdo->prepare(
                'UPDATE sales_control_fiscal_job_items
                 SET status="running",attempts=attempts+1,safe_error_message=NULL,diagnostic_id=NULL
                 WHERE id=? AND sales_control_fiscal_job_id=?'
            )->execute([(int) $item['id'], (int) $job['id']]);
            $pdo->commit();
            return $item;
        } catch (Throwable $error) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $error;
        }
    }

    private function persistSnapshot(array $job, array $item, array $payload): void
    {
        $encoded = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $hash = hash('sha256', $encoded);
        $complete = $this->isComplete($payload);
        $personType = $this->personType($payload);
        $documentType = $this->documentType($payload);
        $days = max(1, min(365, (new AppSettingsService())->int('sales_control.fiscal_snapshot_ttl_days', 30)));
        $pdo = Database::connectionFresh();
        $pdo->beginTransaction();
        try {
            if (!$this->ownsLease($job, (string) $job['lock_owner'])) {
                throw new \RuntimeException('La reserva temporal venció antes de guardar el dato fiscal.');
            }
            $pdo->prepare(
                'INSERT INTO sales_control_fiscal_snapshots
                 (company_id,meli_account_id,meli_order_id,external_order_id,billing_info_id,site_id,
                  person_type,document_type,completeness_status,encrypted_payload,payload_hash,expires_at)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,DATE_ADD(UTC_TIMESTAMP(),INTERVAL ' . $days . ' DAY))
                 ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id),expires_at=VALUES(expires_at)'
            )->execute([
                (int) $job['company_id'], (int) $job['meli_account_id'], (int) $item['meli_order_id'],
                (string) $item['external_order_id'], (string) $item['billing_info_id'], (string) $item['site_id'],
                $personType, $documentType, $complete ? 'complete' : 'partial', Crypto::encrypt($encoded), $hash,
            ]);
            $snapshotId = (int) $pdo->lastInsertId();
            $pdo->prepare(
                'UPDATE sales_control_fiscal_items
                 SET fiscal_status=?,fiscal_snapshot_id=?,reason_code=?,classified_at=UTC_TIMESTAMP()
                 WHERE sales_control_month_id=? AND company_id=? AND meli_account_id=? AND meli_order_id=?'
            )->execute([
                $complete ? 'ready' : 'fiscal_data_required',
                $snapshotId,
                $complete ? 'fiscal_snapshot_complete' : 'fiscal_fields_missing',
                (int) $job['sales_control_month_id'], (int) $job['company_id'],
                (int) $job['meli_account_id'], (int) $item['meli_order_id'],
            ]);
            $pdo->prepare(
                'UPDATE sales_control_fiscal_job_items
                 SET status="complete",safe_error_message=NULL,diagnostic_id=NULL,processed_at=UTC_TIMESTAMP()
                 WHERE id=? AND sales_control_fiscal_job_id=?'
            )->execute([(int) $item['id'], (int) $job['id']]);
            $pdo->commit();
        } catch (Throwable $error) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $error;
        }
    }

    private function finishItem(array $job, array $item, string $worker, string $status, string $message, ?string $diagnostic): void
    {
        if (!$this->ownsLease($job, $worker)) {
            return;
        }
        Database::connectionFresh()->prepare(
            'UPDATE sales_control_fiscal_job_items
             SET status=?,safe_error_message=?,diagnostic_id=?,processed_at=UTC_TIMESTAMP()
             WHERE id=? AND sales_control_fiscal_job_id=? AND company_id=? AND meli_account_id=?'
        )->execute([
            $status, $message, $diagnostic, (int) $item['id'], (int) $job['id'],
            (int) $job['company_id'], (int) $job['meli_account_id'],
        ]);
    }

    private function defer(array $job, array $item, string $worker, string $status, string $next, string $message, ?string $diagnostic): void
    {
        if (!$this->ownsLease($job, $worker)) {
            return;
        }
        $pdo = Database::connectionFresh();
        $pdo->prepare(
            'UPDATE sales_control_fiscal_job_items
             SET status=?,next_run_at=?,safe_error_message=?,diagnostic_id=? WHERE id=? AND sales_control_fiscal_job_id=?'
        )->execute([$status === 'waiting_budget' ? 'retry' : $status, $next, $message, $diagnostic, (int) $item['id'], (int) $job['id']]);
        $pdo->prepare(
            'UPDATE sales_control_fiscal_jobs
             SET status=?,next_run_at=?,lock_owner=NULL,lock_expires_at=NULL,heartbeat_at=NULL
             WHERE id=? AND lock_owner=? AND lease_generation=?'
        )->execute([$status, $next, (int) $job['id'], $worker, (int) $job['lease_generation']]);
    }

    /** @return array<string,mixed> */
    private function finalize(array $job, string $worker, int $processed, int $errors): array
    {
        $pdo = Database::connectionFresh();
        $stmt = $pdo->prepare(
            'SELECT
                SUM(status IN ("complete","already_current")) successes,
                SUM(status="missing") missing,
                SUM(status="error") errors,
                SUM(status IN ("pending","running","retry")) active
             FROM sales_control_fiscal_job_items
             WHERE sales_control_fiscal_job_id=? AND company_id=? AND meli_account_id=?'
        );
        $stmt->execute([(int) $job['id'], (int) $job['company_id'], (int) $job['meli_account_id']]);
        $counts = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        $active = (int) ($counts['active'] ?? 0);
        $status = $active > 0 ? 'pending' : (((int) ($counts['errors'] ?? 0) > 0 || (int) ($counts['missing'] ?? 0) > 0) ? 'partial' : 'complete');
        $pdo->prepare(
            'UPDATE sales_control_fiscal_jobs
             SET status=?,processed_items=?,success_items=?,missing_items=?,error_items=?,
                 next_run_at=IF(?="pending",UTC_TIMESTAMP(),next_run_at),
                 lock_owner=NULL,lock_expires_at=NULL,heartbeat_at=NULL,
                 completed_at=IF(? IN ("complete","partial"),UTC_TIMESTAMP(),completed_at)
             WHERE id=? AND lock_owner=? AND lease_generation=?'
        )->execute([
            $status,
            (int) ($counts['successes'] ?? 0) + (int) ($counts['missing'] ?? 0) + (int) ($counts['errors'] ?? 0),
            (int) ($counts['successes'] ?? 0), (int) ($counts['missing'] ?? 0), (int) ($counts['errors'] ?? 0),
            $status, $status, (int) $job['id'], $worker, (int) $job['lease_generation'],
        ]);
        $this->refreshMonthCounters(
            (int) $job['sales_control_month_id'],
            (int) $job['company_id'],
            (int) $job['meli_account_id']
        );
        return ['processed' => $processed, 'errors' => $errors, 'status' => $status, 'job_id' => (int) $job['id']];
    }

    private function refreshMonthCounters(int $monthId, int $companyId, int $accountId): void
    {
        $pdo = Database::connectionFresh();
        $stmt = $pdo->prepare(
            'SELECT
               SUM(fiscal_status IN ("ready","invoiced")) ready_total,
               SUM(fiscal_status="fiscal_data_required") missing_total,
               SUM(fiscal_status="credit_note_review") credit_total,
               SUM(fiscal_status="reconciliation") reconciliation_total
             FROM sales_control_fiscal_items
             WHERE sales_control_month_id=? AND company_id=? AND meli_account_id=?'
        );
        $stmt->execute([$monthId, $companyId, $accountId]);
        $totals = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        $needsAttention = (int) ($totals['missing_total'] ?? 0)
            + (int) ($totals['credit_total'] ?? 0)
            + (int) ($totals['reconciliation_total'] ?? 0);
        $pdo->prepare(
            'UPDATE sales_control_months
             SET fiscal_ready_total=?,fiscal_missing_total=?,credit_note_review_total=?,
                 reconciliation_total=?,
                 status=CASE
                   WHEN status IN ("closed","reopened","missing_sales","date_attention","failed") THEN status
                   WHEN ?>0 THEN "fiscal_incomplete"
                   ELSE "ready_to_close"
                 END,updated_at=UTC_TIMESTAMP()
             WHERE id=? AND company_id=? AND meli_account_id=?'
        )->execute([
            (int) ($totals['ready_total'] ?? 0),
            (int) ($totals['missing_total'] ?? 0),
            (int) ($totals['credit_total'] ?? 0),
            (int) ($totals['reconciliation_total'] ?? 0),
            $needsAttention,
            $monthId,
            $companyId,
            $accountId,
        ]);
    }

    private function ownsLease(array $job, string $worker): bool
    {
        $stmt = Database::connectionFresh()->prepare(
            'SELECT COUNT(*) FROM sales_control_fiscal_jobs
             WHERE id=? AND company_id=? AND meli_account_id=? AND lock_owner=?
               AND lease_generation=? AND lock_expires_at>=UTC_TIMESTAMP()'
        );
        $stmt->execute([
            (int) $job['id'], (int) $job['company_id'], (int) $job['meli_account_id'],
            $worker, (int) $job['lease_generation'],
        ]);
        return (int) $stmt->fetchColumn() === 1;
    }

    private function isComplete(array $payload): bool
    {
        $flat = mb_strtolower(json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '');
        $hasIdentification = str_contains($flat, '"identification"') || str_contains($flat, '"document"');
        $hasName = str_contains($flat, '"business_name"') || str_contains($flat, '"name"');
        return $hasIdentification && $hasName;
    }

    private function personType(array $payload): string
    {
        $value = mb_strtolower((string) ($payload['taxpayer_type']['value']
            ?? $payload['billing_info']['taxpayer_type']
            ?? $payload['person_type']
            ?? ''));
        if (str_contains($value, 'jur') || str_contains($value, 'legal') || str_contains($value, 'empresa')) {
            return 'legal';
        }
        if ($value !== '') {
            return 'natural';
        }
        return 'unknown';
    }

    private function documentType(array $payload): ?string
    {
        $value = $payload['identification']['type']
            ?? $payload['billing_info']['identification']['type']
            ?? $payload['document_type']
            ?? null;
        return $value === null ? null : mb_substr((string) $value, 0, 30);
    }
}
