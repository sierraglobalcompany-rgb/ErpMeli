<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\HttpException;
use PDO;
use Throwable;

/**
 * Conciliación financiera por venta. El transporte solo se ejecuta desde CLI.
 */
final class SaleFinancialService
{
    private const ENDPOINT = '/billing/integration/group/ML/order/details';
    private const PACK_INCOMPLETE_RECHECK_MINUTES = 60;
    private const BILLING_ORDER_IDS_PER_CALL = 1;
    private const FINANCIAL_RECONCILIATION_STALE_RECHECK_SECONDS = 900;

    /** @var \Closure(int):MeliApiClient */
    private \Closure $clientFactory;

    /** @param null|callable(int):MeliApiClient $clientFactory */
    public function __construct(?callable $clientFactory = null)
    {
        $this->clientFactory = $clientFactory !== null
            ? \Closure::fromCallable($clientFactory)
            : static fn (int $accountId): MeliApiClient => new MeliApiClient($accountId);
    }

    public static function billingOrderIdsPerCallLimit(): int
    {
        return self::BILLING_ORDER_IDS_PER_CALL;
    }

    public function queue(int $accountId, string $saleId, ?int $createdBy = null): int
    {
        if (PHP_SAPI === 'cli') {
            throw new \LogicException('La creación de la cola pertenece a la interfaz administrativa.');
        }
        if (!(new SchemaInspectorService())->hasTable('sale_financial_reconciliation_jobs')) {
            throw new \RuntimeException('La conciliación agrupada estará disponible después de completar la actualización 2.24.0.');
        }
        $account = (new BusinessScopeContext())->account($accountId);
        $sale = (new SaleReadService())->show($accountId, $saleId);
        if ($sale['is_pack'] && (string) ($sale['pack']['integrity_status'] ?? '') !== 'complete') {
            throw new \RuntimeException('Primero debe completar la reconstrucción de las órdenes de esta venta.');
        }
        $saleKey = ($sale['is_pack'] ? 'P:' : 'O:') . $sale['sale_id'];
        $state = (new SaleFinancialStateService())->projectSale(
            (int) $account['company_id'],
            $accountId,
            $saleKey
        );
        return $this->enqueueSale(
            (int) $account['company_id'],
            $accountId,
            $saleKey,
            (string) $sale['sale_id'],
            $sale['is_pack'] ? 'pack' : 'order',
            (string) ($sale['orders'][0]['currency_id'] ?? 'COP'),
            'manual',
            $createdBy,
            20,
            $createdBy,
            (string) $state['input_version']
        );
    }

    public function queueFromOrderId(
        int $orderId,
        string $originType,
        ?int $originId = null,
        int $priorityTier = 30,
        ?string $inputVersion = null
    ): int {
        if (!(new AppSettingsService())->bool('sales_financial.commercial_pipeline_enabled', true)
            || !(new SchemaInspectorService())->hasTable('sale_financial_reconciliation_jobs')) {
            return 0;
        }
        $stmt = Database::connectionFresh()->prepare(
            'SELECT o.meli_account_id,a.company_id,o.external_order_id,o.external_pack_id,o.currency_id
             FROM meli_orders o
             JOIN meli_accounts a ON a.id=o.meli_account_id
             WHERE o.id=? LIMIT 1'
        );
        $stmt->execute([$orderId]);
        $order = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($order)) {
            return 0;
        }
        $saleId = (string) ($order['external_pack_id'] ?: $order['external_order_id']);
        $identity = !empty($order['external_pack_id']) ? 'pack' : 'order';
        if ($inputVersion === null || preg_match('/^[a-f0-9]{64}$/', $inputVersion) !== 1) {
            $projection = (new SaleFinancialStateService())->projectOrder($orderId);
            $inputVersion = (string) $projection['input_version'];
        }
        return $this->enqueueSale(
            (int) $order['company_id'],
            (int) $order['meli_account_id'],
            ($identity === 'pack' ? 'P:' : 'O:') . $saleId,
            $saleId,
            $identity,
            (string) ($order['currency_id'] ?: 'COP'),
            $originType,
            $originId,
            $priorityTier,
            null,
            $inputVersion
        );
    }

    /** @param list<int> $orderIds */
    public function queueFromOrderIds(array $orderIds, string $originType, ?int $originId = null, int $priorityTier = 30): int
    {
        $queued = [];
        foreach (array_values(array_unique(array_filter(array_map('intval', $orderIds)))) as $orderId) {
            $jobId = $this->queueFromOrderId($orderId, $originType, $originId, $priorityTier);
            if ($jobId > 0) {
                $queued[$jobId] = true;
            }
        }
        return count($queued);
    }

    /** @return array{processed:int,completed:int,errors:int,deferred:int,stop_reason:string} */
    public function processDue(int $limit = 1): array
    {
        return $this->processSelected($limit, null);
    }

    /** @return array{processed:int,completed:int,errors:int,deferred:int,stop_reason:string} */
    public function processExact(int $jobId): array
    {
        return $this->processSelected(1, $jobId, false, true);
    }

    public function processManualExact(int $jobId): array
    {
        return $this->processSelected(1,$jobId,true);
    }

    /**
     * The caller passes the claimed source. This service processes only that
     * source, without reading or mutating scheduler state for neighbors.
     *
     * @param list<int> $sourceIds
     * @return array{summary:array{processed:int,completed:int,errors:int,deferred:int,stop_reason:string},outcomes:array<int,array{state:string,classification?:string,next_safe_at?:?string}>}
     */
    public function processDomainExactBatch(array $sourceIds, int $companyId, int $accountId, bool $allowSuccessor = true): array
    {
        $summary = ['processed' => 0, 'completed' => 0, 'errors' => 0, 'deferred' => 0, 'stop_reason' => 'empty'];
        $outcomes = [];
        $sourceIds = array_values(array_unique(array_filter(array_map('intval', $sourceIds), static fn (int $id): bool => $id > 0)));
        if ($sourceIds === []) {
            return ['summary' => $summary, 'outcomes' => $outcomes];
        }

        // Queue V4 automatic admission is one financial source per Billing
        // request. Do not claim adjacent sources as a transport batch.
        $job = $this->claimSpecificBillingJob($sourceIds[0], $companyId, $accountId);
        if ($job === null) {
            return ['summary' => $summary, 'outcomes' => $outcomes];
        }
        $result = $this->captureAndReconcile($job, $allowSuccessor, true);
        $outcomes[(int) $job['id']] = $this->sourceOutcome(
            (int) $job['id'],
            (int) $job['company_id'],
            (int) $job['meli_account_id'],
        );
        $this->summarizeTerminal($summary, $result['status'] === 'reconciled' ? 'complete' : $result['status']);
        return ['summary' => $summary, 'outcomes' => $outcomes];
    }

    /** @return array{processed:int,completed:int,errors:int,deferred:int,stop_reason:string} */
    private function processSelected(
        int $limit,
        ?int $jobId,
        bool $manualExact = false,
        bool $domainExact = false,
    ): array
    {
        if (PHP_SAPI !== 'cli' && !$manualExact) {
            throw new HttpException(404, 'Esta operación solo está disponible para el lanzador CLI.');
        }
        if (!(new SchemaInspectorService())->hasTable('sale_financial_reconciliation_jobs')) {
            return ['processed' => 0, 'completed' => 0, 'errors' => 0, 'deferred' => 0, 'stop_reason' => 'schema_unavailable'];
        }
        $summary = ['processed' => 0, 'completed' => 0, 'errors' => 0, 'deferred' => 0, 'stop_reason' => 'empty'];
        $previousAccountId = null;
        for ($i = 0; $i < max(1, min(10, $limit)); $i++) {
            $job = $this->claim($jobId, $previousAccountId);
            if ($job === null) {
                break;
            }
            $previousAccountId = (int) $job['meli_account_id'];
            try {
                $summary['processed']++;
                $result = $this->captureAndReconcile($job, $jobId === null || $domainExact, $domainExact);
                $terminal = $result['status'] === 'reconciled' ? 'complete' : $result['status'];
                if (!($result['finalized'] ?? false)) {
                    $this->finish($job, $terminal, (string) $result['message']);
                }
                if ($terminal === 'complete') {
                    $summary['completed']++;
                    $summary['stop_reason'] = 'work_completed';
                } elseif (in_array($terminal, ['partial', 'retry', 'awaiting_remote'], true)) {
                    $summary['deferred']++;
                    $summary['stop_reason'] = 'partial_response';
                } else {
                    $summary['errors']++;
                    $summary['stop_reason'] = 'manual_review';
                }
            } catch (Throwable $error) {
                if ($error instanceof ApiRhythmDeferredException) {
                    $this->deferWithoutAttemptPenalty(
                        $job,
                        SafeErrorPresenter::message($error, 'Billing continuará en su próxima oportunidad segura.'),
                        $error->nextSafeAt
                    );
                    $summary['deferred']++;
                    $summary['stop_reason'] = $error->blockingScope;
                    if ($domainExact) {
                        throw $error;
                    }
                    break;
                }
                if ($error instanceof RemoteResultUncertainException) {
                    $this->finish($job, 'review', SafeErrorPresenter::message($error, 'Resultado remoto pendiente de revisión.'));
                    $summary['errors']++;
                    $summary['stop_reason'] = 'action_required';
                    break;
                }
                $retry = !$this->retryDeadlineExceeded($job);
                $this->finish(
                    $job,
                    $retry ? 'retry' : 'error',
                    SafeErrorPresenter::message($error, 'No fue posible completar la conciliación oficial.')
                );
                $retry ? $summary['deferred']++ : $summary['errors']++;
                $summary['stop_reason'] = $retry ? 'automatic_retry' : 'persistent_error';
                if ($error instanceof MeliApiException && $error->httpStatus === 429) {
                    $summary['stop_reason'] = 'http_429';
                    break;
                }
                if ($error instanceof MeliApiException && (int) $error->httpStatus >= 500) {
                    $summary['stop_reason'] = 'http_5xx';
                    break;
                }
                if ($error instanceof ApiBudgetExhaustedException) {
                    $summary['stop_reason'] = 'api_budget';
                    break;
                }
            }
        }
        return $summary;
    }

    /**
     * Método conductual para fixtures: no usa base ni transporte.
     *
     * @param list<array{id:int,gross:float|int,units:int}> $items
     * @param list<array<string,mixed>> $lines
     * @return array<string,mixed>
     */
    public function calculate(array $items, array $lines): array
    {
        $products = array_sum(array_map(static fn(array $item): float => (float) $item['gross'], $items));
        $totals = [
            'sale_fee' => 0.0, 'shipping' => 0.0, 'tax' => 0.0,
            'discount' => 0.0, 'credit' => 0.0, 'adjustment' => 0.0, 'other' => 0.0,
        ];
        foreach ($lines as $line) {
            $group = (string) ($line['line_group'] ?? 'other');
            if ($group === 'product') {
                continue;
            }
            if (!array_key_exists($group, $totals)) {
                $group = 'other';
            }
            $totals[$group] += abs((float) ($line['amount'] ?? 0));
        }
        $net = $products
            - $totals['sale_fee']
            - $totals['shipping']
            - $totals['tax']
            - $totals['adjustment']
            + $totals['discount']
            + $totals['credit'];
        $allocator = new LargestRemainderAllocator();
        $grossWeights = [];
        $unitWeights = [];
        foreach ($items as $item) {
            $grossWeights[(int) $item['id']] = max(0.0, (float) $item['gross']);
            $unitWeights[(int) $item['id']] = max(1, (int) $item['units']);
        }
        $weights = array_sum($grossWeights) > 0 ? $grossWeights : $unitWeights;
        $basis = array_sum($grossWeights) > 0 ? 'gross' : 'units';
        $allocations = [];
        $allocatedGroups = [];
        foreach (['sale_fee', 'shipping', 'tax', 'discount', 'credit', 'adjustment'] as $group) {
            $allocatedGroups[$group] = $allocator->allocate(
                (int) round($totals[$group] * 100),
                $weights
            );
        }
        foreach ($items as $item) {
            $id = (int) $item['id'];
            $grossCents = (int) round((float) $item['gross'] * 100);
            $saleFee = $allocatedGroups['sale_fee'][$id] ?? 0;
            $shipping = $allocatedGroups['shipping'][$id] ?? 0;
            $tax = $allocatedGroups['tax'][$id] ?? 0;
            $discount = $allocatedGroups['discount'][$id] ?? 0;
            $credit = $allocatedGroups['credit'][$id] ?? 0;
            $other = $allocatedGroups['adjustment'][$id] ?? 0;
            $allocations[$id] = [
                'gross_amount' => $grossCents / 100,
                'weight_basis' => $basis,
                'sale_fee_allocated' => $saleFee / 100,
                'shipping_allocated' => $shipping / 100,
                'tax_allocated' => $tax / 100,
                'discount_allocated' => $discount / 100,
                'credit_allocated' => $credit / 100,
                'other_allocated' => $other / 100,
                'net_allocated' => ($grossCents - $saleFee - $shipping - $tax - $other + $discount + $credit) / 100,
            ];
        }
        return [
            'products_amount' => round($products, 2),
            'sale_fee_amount' => round($totals['sale_fee'], 2),
            'shipping_charge_amount' => round($totals['shipping'], 2),
            'taxes_amount' => round($totals['tax'], 2),
            'discounts_amount' => round($totals['discount'], 2),
            'credits_amount' => round($totals['credit'], 2),
            'adjustments_amount' => round($totals['adjustment'], 2),
            'net_amount' => round($net, 2),
            'unknown_amount' => round($totals['other'], 2),
            'allocations' => $allocations,
        ];
    }

    /**
     * @param array<string,mixed> $job
     * @return array<string,mixed>
     */
    private function prepareBillingCandidate(array $job, bool $allowSuccessor, bool $domainSuccessor): array
    {
        $this->heartbeat($job);
        $pdo = Database::connectionFresh();
        $orders = $pdo->prepare(
            'SELECT o.id,o.external_order_id,o.currency_id
             FROM meli_orders o
             JOIN meli_accounts a ON a.id=o.meli_account_id AND a.company_id=?
             WHERE o.meli_account_id=?
               AND CONCAT(IF(o.external_pack_id IS NULL,"O:","P:"),COALESCE(o.external_pack_id,o.external_order_id))=?
             ORDER BY o.id'
        );
        $orders->execute([(int) $job['company_id'], (int) $job['meli_account_id'], (string) $job['sale_key']]);
        $orderRows = $orders->fetchAll(PDO::FETCH_ASSOC);
        if ($orderRows === []) {
            throw new \RuntimeException('La venta ya no tiene órdenes accesibles en esta empresa y cuenta.');
        }
        if (str_starts_with((string) $job['sale_key'], 'P:')) {
            $pack = $pdo->prepare(
                'SELECT integrity_status FROM meli_packs
                 WHERE meli_account_id=? AND external_pack_id=? LIMIT 1'
            );
            $pack->execute([(int) $job['meli_account_id'], (string) $job['external_sale_id']]);
            if ((string) ($pack->fetchColumn() ?: '') !== 'complete') {
                return [
                    'ready' => false,
                    'status' => 'retry',
                    'message' => 'La venta agrupada todavía está completando sus órdenes. Se verificará nuevamente.',
                    'minimum_next_run_at' => gmdate('Y-m-d H:i:s', time() + self::PACK_INCOMPLETE_RECHECK_MINUTES * 60),
                    'finalized' => true,
                    'non_failure' => true,
                ];
            }
        }
        $state = (new SaleFinancialStateService())->projectSale(
            (int) $job['company_id'],
            (int) $job['meli_account_id'],
            (string) $job['sale_key']
        );
        if (!hash_equals((string) $state['input_version'], (string) $job['input_version'])) {
            if ($allowSuccessor) {
                $this->enqueueSale(
                    (int) $job['company_id'],
                    (int) $job['meli_account_id'],
                    (string) $job['sale_key'],
                    (string) $job['external_sale_id'],
                    str_starts_with((string) $job['sale_key'], 'P:') ? 'pack' : 'order',
                    (string) ($orderRows[0]['currency_id'] ?? 'COP'),
                    $domainSuccessor ? 'domain_input_changed' : 'input_changed',
                    (int) $job['id'],
                    (int) $job['priority_tier'],
                    null,
                    (string) $state['input_version']
                );
            }
            return [
                'ready' => false,
                'status' => 'reconciled',
                'message' => $allowSuccessor
                    ? 'La entrada financiera cambió antes de consultar Billing; se creó una única captura para la versión vigente.'
                    : 'La entrada financiera cambió; el paso exacto terminó sin consultar Billing ni crear otro trabajo.',
                'finalized' => false,
            ];
        }
        if ((string) ($state['official_status'] ?? '') === 'complete'
            && $state['official_net_amount'] !== null) {
            return [
                'ready' => false,
                'status' => 'reconciled',
                'message' => 'La versión vigente ya tiene evidencia oficial; no se repitió Billing.',
                'finalized' => false,
            ];
        }
        $externalOrderIds = array_map(static fn(array $row): string => (string) $row['external_order_id'], $orderRows);
        return [
            'ready' => true,
            'job' => $job,
            'order_rows' => $orderRows,
            'external_order_ids' => $externalOrderIds,
            'order_ids' => array_map(static fn(array $row): int => (int) $row['id'], $orderRows),
        ];
    }

    /** @param array<string,mixed> $prepared @param array{processed:int,completed:int,errors:int,deferred:int,stop_reason:string} $summary */
    private function applyPreparedLocalOutcome(array $job, array $prepared, array &$summary): void
    {
        if (($prepared['ready'] ?? false) === true) {
            return;
        }
        if (($prepared['finalized'] ?? false) === true && ($prepared['non_failure'] ?? false) === true) {
            $this->deferWithoutAttemptPenalty(
                $job,
                (string) $prepared['message'],
                (string) $prepared['minimum_next_run_at']
            );
        } elseif (!($prepared['finalized'] ?? false)) {
            $this->finish(
                $job,
                (string) (($prepared['status'] ?? '') === 'reconciled' ? 'complete' : $prepared['status']),
                (string) $prepared['message'],
                $prepared['minimum_next_run_at'] ?? null,
            );
        }
        $summary['processed']++;
        $terminal = ($prepared['status'] ?? '') === 'reconciled' ? 'complete' : (string) ($prepared['status'] ?? 'error');
        if ($terminal === 'complete') {
            $summary['completed']++;
            $summary['stop_reason'] = 'work_completed';
        } elseif (in_array($terminal, ['partial', 'retry', 'awaiting_remote'], true)) {
            $summary['deferred']++;
            $summary['stop_reason'] = 'partial_response';
        } else {
            $summary['errors']++;
            $summary['stop_reason'] = 'manual_review';
        }
    }

    /**
     * @return array{state:string,classification?:string,next_safe_at?:?string}
     */
    private function sourceOutcome(int $sourceId, int $companyId, int $accountId): array
    {
        $stmt = Database::connectionFresh()->prepare(
            'SELECT status,next_run_at FROM sale_financial_reconciliation_jobs
             WHERE id=? AND company_id=? AND meli_account_id=? LIMIT 1'
        );
        $stmt->execute([$sourceId, $companyId, $accountId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return ['state' => 'review', 'classification' => 'domain_source_missing'];
        }
        $status = strtolower((string) ($row['status'] ?? ''));
        if ($status === 'complete') {
            return ['state' => 'completed'];
        }
        if (in_array($status, ['pending', 'running', 'retry', 'awaiting_remote'], true)) {
            return [
                'state' => 'waiting',
                'classification' => 'domain_source_waiting:financial_reconciliation',
                'next_safe_at' => $this->financialReconciliationNextSafeAt($row['next_run_at'] ?? null),
            ];
        }
        return [
            'state' => 'review',
            'classification' => 'domain_source_' . substr($status !== '' ? $status : 'unknown', 0, 70),
        ];
    }

    private function financialReconciliationNextSafeAt(mixed $nextRunAt): string
    {
        $value = trim((string) $nextRunAt);
        $timestamp = $value !== '' ? strtotime($value . ' UTC') : false;
        if ($timestamp !== false && $timestamp > time()) {
            return gmdate('Y-m-d H:i:s', $timestamp);
        }

        return gmdate('Y-m-d H:i:s', time() + self::FINANCIAL_RECONCILIATION_STALE_RECHECK_SECONDS);
    }

    /** @param array{processed:int,completed:int,errors:int,deferred:int,stop_reason:string} $summary */
    private function summarizeTerminal(array &$summary, string $terminal): void
    {
        $summary['processed']++;
        if ($terminal === 'complete') {
            $summary['completed']++;
            $summary['stop_reason'] = 'work_completed';
            return;
        }
        if (in_array($terminal, ['partial', 'retry', 'awaiting_remote'], true)) {
            $summary['deferred']++;
            $summary['stop_reason'] = 'partial_response';
            return;
        }
        $summary['errors']++;
        $summary['stop_reason'] = 'manual_review';
    }

    /** @param array<string,mixed> $prepared */
    private function isSimpleCrossSaleCandidate(array $prepared): bool
    {
        $job = $prepared['job'] ?? [];
        return is_array($job)
            && str_starts_with((string) ($job['sale_key'] ?? ''), 'O:')
            && count($prepared['external_order_ids'] ?? []) === 1
            && count($prepared['order_ids'] ?? []) === 1;
    }

    /** @param list<array<string,mixed>> $lines */
    private function hasUnattributedBillingLines(array $lines): bool
    {
        foreach ($lines as $line) {
            if (trim((string) ($line['external_order_id'] ?? '')) === '') {
                return true;
            }
        }
        return false;
    }

    /** @return array<string,mixed>|null */
    private function claimSpecificBillingJob(int $jobId, int $companyId, int $accountId): ?array
    {
        $pdo = Database::connectionFresh();
        $owner = bin2hex(random_bytes(16));
        $pdo->beginTransaction();
        try {
            $reservationGuard = ManualCampaignReservationGuard::sql(
                'sale_financial_reconciliation',
                'sale_financial_reconciliation_jobs.id'
            );
            $stmt = $pdo->prepare(
                'SELECT * FROM sale_financial_reconciliation_jobs
                 WHERE id=? AND company_id=? AND meli_account_id=?
                   AND status IN ("pending","retry","awaiting_remote")
                   AND next_run_at<=UTC_TIMESTAMP()
                   AND (lease_expires_at IS NULL OR lease_expires_at<UTC_TIMESTAMP())' . $reservationGuard . '
                 LIMIT 1 FOR UPDATE'
            );
            $stmt->execute([$jobId, $companyId, $accountId]);
            $job = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!is_array($job)) {
                $pdo->commit();
                return null;
            }
            $generation = (int) $job['lease_generation'] + 1;
            $update = $pdo->prepare(
                'UPDATE sale_financial_reconciliation_jobs
                 SET status="running",lock_owner=?,lease_generation=?,
                     lease_expires_at=DATE_ADD(UTC_TIMESTAMP(),INTERVAL 3 MINUTE),
                     heartbeat_at=UTC_TIMESTAMP(),attempts=attempts+1
                 WHERE id=? AND company_id=? AND meli_account_id=?
                   AND status IN ("pending","retry","awaiting_remote")
                   AND lease_generation=?'
            );
            $update->execute([
                $owner,
                $generation,
                $jobId,
                $companyId,
                $accountId,
                (int) $job['lease_generation'],
            ]);
            if ($update->rowCount() !== 1) {
                throw new \RuntimeException('financial_batch_claim_lost');
            }
            $pdo->commit();
            $job['lock_owner'] = $owner;
            $job['lease_generation'] = $generation;
            $job['attempts'] = (int) $job['attempts'] + 1;
            return $job;
        } catch (Throwable $error) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $error;
        }
    }

    /**
     * @param array<string,mixed> $candidate
     * @param array<string,mixed> $response
     * @param array<string,mixed> $metadata
     * @param list<array<string,mixed>> $allLines
     * @return array{status:string,message:string,finalized:bool}
     */
    private function persistPreparedBillingResult(
        array $candidate,
        int $httpStatus,
        array $response,
        array $metadata,
        array $allLines
    ): array {
        $job = $candidate['job'];
        $externalOrderIds = array_map('strval', $candidate['external_order_ids']);
        $known = array_fill_keys($externalOrderIds, true);
        $lines = array_values(array_filter(
            $allLines,
            static fn(array $line): bool => isset($known[(string) ($line['external_order_id'] ?? '')])
        ));
        $items = $this->items((int) $job['meli_account_id'], $candidate['order_ids']);
        $calculationItems = array_map(static fn(array $item): array => [
            'id' => (int) $item['id'],
            'gross' => (float) $item['unit_price'] * (int) $item['quantity'],
            'units' => (int) $item['quantity'],
        ], $items);
        $totals = $this->calculate($calculationItems, $lines);
        $legacyEstimate = $this->legacyEstimate((int) $job['meli_account_id'], $candidate['order_ids']);
        $partial = $httpStatus === 206;
        $processing = $this->containsProcessingStatus($response, $externalOrderIds);
        $missingFields = $this->missingFields($metadata);
        $missingContent = $missingFields !== [];
        $hasOfficialLines = $lines !== [];
        $unknown = $totals['unknown_amount'] > 0.009;
        $financialStatus = ($partial || $processing || $missingContent || !$hasOfficialLines)
            ? 'partial'
            : ($unknown ? 'review' : 'reconciled');
        $needsAnotherCapture = $partial || $processing || $missingContent || !$hasOfficialLines;
        $jobStatus = $needsAnotherCapture
            ? ($this->retryDeadlineExceeded($job) ? 'review' : 'awaiting_remote')
            : $financialStatus;
        $message = $processing
            ? 'Mercado Libre todavía está preparando el documento de billing. Se intentará nuevamente.'
            : ($missingContent
                ? 'Mercado Libre informó campos ausentes en billing. El total se conserva como parcial hasta una captura completa.'
            : (!$hasOfficialLines
                ? 'Billing aún no entregó conceptos importables. No se aprobó un neto estimado.'
                : ($partial
            ? 'Mercado Libre entregó una respuesta parcial. Se conservaron los datos y se verificará de nuevo.'
            : ($unknown
                ? 'Billing contiene conceptos desconocidos. El total queda en revisión antes de aprobarse.'
                : 'Venta conciliada con cargos oficiales.'))));
        $responseClass = $processing
            ? 'processing'
            : (($partial || $missingContent) ? 'partial' : ($hasOfficialLines ? 'complete' : 'unavailable'));
        $terminal = $jobStatus === 'reconciled' ? 'complete' : $jobStatus;
        $captureId = $this->beginCapture($job, $externalOrderIds);
        $this->persistResult(
            $job,
            $captureId,
            $httpStatus,
            $response,
            $lines,
            $items,
            $totals,
            $financialStatus,
            $message,
            $responseClass,
            $metadata,
            $legacyEstimate,
            $terminal,
            $responseClass
        );
        return ['status' => $jobStatus, 'message' => $message, 'finalized' => true];
    }

    /** @param array<string,mixed> $job @return array{status:string,message:string,finalized:bool} */
    private function captureAndReconcile(array $job, bool $allowSuccessor, bool $domainSuccessor = false): array
    {
        $this->heartbeat($job);
        $pdo = Database::connectionFresh();
        $orders = $pdo->prepare(
            'SELECT o.id,o.external_order_id,o.currency_id
             FROM meli_orders o
             JOIN meli_accounts a ON a.id=o.meli_account_id AND a.company_id=?
             WHERE o.meli_account_id=?
               AND CONCAT(IF(o.external_pack_id IS NULL,"O:","P:"),COALESCE(o.external_pack_id,o.external_order_id))=?
             ORDER BY o.id'
        );
        $orders->execute([(int) $job['company_id'], (int) $job['meli_account_id'], (string) $job['sale_key']]);
        $orderRows = $orders->fetchAll(PDO::FETCH_ASSOC);
        if ($orderRows === []) {
            throw new \RuntimeException('La venta ya no tiene órdenes accesibles en esta empresa y cuenta.');
        }
        if (str_starts_with((string) $job['sale_key'], 'P:')) {
            $pack = $pdo->prepare(
                'SELECT integrity_status FROM meli_packs
                 WHERE meli_account_id=? AND external_pack_id=? LIMIT 1'
            );
            $pack->execute([(int) $job['meli_account_id'], (string) $job['external_sale_id']]);
            if ((string) ($pack->fetchColumn() ?: '') !== 'complete') {
                $message = 'La venta agrupada todavía está completando sus órdenes. Se verificará nuevamente.';
                $this->deferWithoutAttemptPenalty(
                    $job,
                    $message,
                    gmdate('Y-m-d H:i:s', time() + self::PACK_INCOMPLETE_RECHECK_MINUTES * 60)
                );
                return [
                    'status' => 'retry',
                    'message' => $message,
                    'finalized' => true,
                ];
            }
        }
        $stateService = new SaleFinancialStateService();
        $state = $stateService->projectSale(
            (int) $job['company_id'],
            (int) $job['meli_account_id'],
            (string) $job['sale_key']
        );
        if (!hash_equals((string) $state['input_version'], (string) $job['input_version'])) {
            if ($allowSuccessor) {
                $this->enqueueSale(
                    (int) $job['company_id'],
                    (int) $job['meli_account_id'],
                    (string) $job['sale_key'],
                    (string) $job['external_sale_id'],
                    str_starts_with((string) $job['sale_key'], 'P:') ? 'pack' : 'order',
                    (string) ($orderRows[0]['currency_id'] ?? 'COP'),
                    $domainSuccessor ? 'domain_input_changed' : 'input_changed',
                    (int) $job['id'],
                    (int) $job['priority_tier'],
                    null,
                    (string) $state['input_version']
                );
            }
            return [
                'status' => 'reconciled',
                'message' => $allowSuccessor
                    ? 'La entrada financiera cambió antes de consultar Billing; se creó una única captura para la versión vigente.'
                    : 'La entrada financiera cambió; el paso exacto terminó sin consultar Billing ni crear otro trabajo.',
                'finalized' => false,
            ];
        }
        if ((string) ($state['official_status'] ?? '') === 'complete'
            && $state['official_net_amount'] !== null) {
            return [
                'status' => 'reconciled',
                'message' => 'La versión vigente ya tiene evidencia oficial; no se repitió Billing.',
                'finalized' => false,
            ];
        }
        $externalOrderIds = array_map(static fn(array $row): string => (string) $row['external_order_id'], $orderRows);
        $orderIds = array_map(static fn(array $row): int => (int) $row['id'], $orderRows);
        $completedCheckpoints = $this->completedBillingOrderCheckpoints($job, $externalOrderIds);
        if (count($completedCheckpoints) === count($externalOrderIds)) {
            $this->publishCompletedBillingCheckpoints($job, $orderIds, $completedCheckpoints);
            return [
                'status' => 'reconciled',
                'message' => 'La venta ya reúne checkpoints Billing exactos para todas sus órdenes; se publicó sin repetir HTTP.',
                'finalized' => true,
            ];
        }
        $nextExternalOrderId = $this->nextBillingOrderId($externalOrderIds, $completedCheckpoints);
        $requestedOrderIds = [$nextExternalOrderId];
        $requestedLocalOrderIds = [];
        foreach ($orderRows as $row) {
            if ((string) $row['external_order_id'] === $nextExternalOrderId) {
                $requestedLocalOrderIds[] = (int) $row['id'];
                break;
            }
        }
        if ($requestedLocalOrderIds === []) {
            throw new \RuntimeException('La orden Billing pendiente no pertenece a la venta vigente.');
        }
        $captureId = $this->beginCapture($job, $requestedOrderIds);
        $api = ($this->clientFactory)((int) $job['meli_account_id']);
        $response = $api->get(self::ENDPOINT, ['order_ids' => $requestedOrderIds[0]], [
            'job_type' => 'billing',
            'source' => 'cron',
            'bulk' => false,
            'estimated_total' => 1,
            'response_count_strategy' => 'billing_orders',
            'expected_resource_ids' => $requestedOrderIds[0],
        ]);
        $metadata = $api->lastResponseMetadata() ?? ['status' => 200, 'headers' => [], 'request_id' => ''];
        $httpStatus = (int) $metadata['status'];
        $lines = (new SaleBillingParser())->parse($response, $requestedOrderIds);
        $items = $this->items((int) $job['meli_account_id'], $requestedLocalOrderIds);
        $calculationItems = array_map(static fn(array $item): array => [
            'id' => (int) $item['id'],
            'gross' => (float) $item['unit_price'] * (int) $item['quantity'],
            'units' => (int) $item['quantity'],
        ], $items);
        $totals = $this->calculate($calculationItems, $lines);
        $legacyEstimate = $this->legacyEstimate((int) $job['meli_account_id'], $orderIds);
        $partial = $httpStatus === 206;
        $processing = $this->containsProcessingStatus($response, $requestedOrderIds);
        $missingFields = $this->missingFields($metadata);
        $missingContent = $missingFields !== [];
        $hasOfficialLines = $lines !== [];
        $unknown = $totals['unknown_amount'] > 0.009;
        $financialStatus = ($partial || $processing || $missingContent || !$hasOfficialLines)
            ? 'partial'
            : ($unknown ? 'review' : 'reconciled');
        $needsAnotherCapture = $partial || $processing || $missingContent || !$hasOfficialLines;
        $jobStatus = $needsAnotherCapture
            ? ($this->retryDeadlineExceeded($job) ? 'review' : 'awaiting_remote')
            : $financialStatus;
        $message = $processing
            ? 'Mercado Libre todavía está preparando el documento de billing. Se intentará nuevamente.'
            : ($missingContent
                ? 'Mercado Libre informó campos ausentes en billing. El total se conserva como parcial hasta una captura completa.'
            : (!$hasOfficialLines
                ? 'Billing aún no entregó conceptos importables. No se aprobó un neto estimado.'
                : ($partial
            ? 'Mercado Libre entregó una respuesta parcial. Se conservaron los datos y se verificará de nuevo.'
            : ($unknown
                ? 'Billing contiene conceptos desconocidos. El total queda en revisión antes de aprobarse.'
                : 'Venta conciliada con cargos oficiales.'))));
        $responseClass = $processing
            ? 'processing'
            : (($partial || $missingContent) ? 'partial' : ($hasOfficialLines ? 'complete' : 'unavailable'));
        $terminal = $jobStatus === 'reconciled' ? 'complete' : $jobStatus;
        foreach ($lines as &$line) {
            $line['capture_id'] = $captureId;
        }
        unset($line);
        if ($terminal !== 'complete') {
            $this->persistBillingOrderCheckpoint(
                $job,
                $captureId,
                $httpStatus,
                $response,
                $lines,
                $requestedOrderIds[0],
                $financialStatus,
                $message,
                $responseClass,
                $metadata,
                $terminal
            );
            return ['status' => $jobStatus, 'message' => $message, 'finalized' => true];
        }

        $this->persistBillingOrderCheckpoint(
            $job,
            $captureId,
            $httpStatus,
            $response,
            $lines,
            $requestedOrderIds[0],
            $financialStatus,
            $message,
            $responseClass,
            $metadata,
            'running'
        );
        $completedCheckpoints = $this->completedBillingOrderCheckpoints($job, $externalOrderIds);
        if (count($completedCheckpoints) < count($externalOrderIds)) {
            $this->finish($job, 'awaiting_remote', sprintf(
                'Billing exacto guardó %d de %d órdenes; continuará con la siguiente llamada permitida.',
                count($completedCheckpoints),
                count($externalOrderIds)
            ));
            return ['status' => 'awaiting_remote', 'message' => 'Billing exacto guardó un checkpoint y conserva pendientes.', 'finalized' => true];
        }
        $this->publishCompletedBillingCheckpoints($job, $orderIds, $completedCheckpoints);
        return ['status' => 'reconciled', 'message' => 'Venta conciliada con checkpoints Billing por orden.', 'finalized' => true];
    }

    /**
     * @param list<string> $externalOrderIds
     * @return array<string,array<string,mixed>>
     */
    private function completedBillingOrderCheckpoints(array $job, array $externalOrderIds): array
    {
        $known = array_fill_keys($externalOrderIds, true);
        $stmt = Database::connectionFresh()->prepare(
            'SELECT source_id,evidence_status,evidence_json,captured_at
             FROM sale_financial_evidence
             WHERE company_id=? AND meli_account_id=? AND sale_key=? AND input_version=?
               AND evidence_type="billing_capture"
             ORDER BY id'
        );
        $stmt->execute([
            (int) $job['company_id'],
            (int) $job['meli_account_id'],
            (string) $job['sale_key'],
            (string) $job['input_version'],
        ]);
        $checkpoints = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $payload = json_decode((string) ($row['evidence_json'] ?? ''), true);
            if (!is_array($payload)
                || (string) ($payload['format'] ?? '') !== 'billing_order_v2'
                || (string) ($row['evidence_status'] ?? '') !== 'reconciled') {
                continue;
            }
            $orderId = (string) ($payload['order_id'] ?? '');
            if (!isset($known[$orderId])) {
                continue;
            }
            $captureId = (int) ($payload['capture_id'] ?? 0);
            if ($captureId <= 0 || $captureId !== (int) ($row['source_id'] ?? 0)) {
                continue;
            }
            $lines = is_array($payload['lines'] ?? null) ? $payload['lines'] : [];
            $checkpoints[$orderId] = [
                'capture_id' => $captureId,
                'lines' => array_values(array_filter($lines, static fn (mixed $line): bool => is_array($line))),
                'captured_at' => (string) ($row['captured_at'] ?? ''),
            ];
        }
        return $checkpoints;
    }

    /** @param list<string> $externalOrderIds @param array<string,array<string,mixed>> $completed */
    private function nextBillingOrderId(array $externalOrderIds, array $completed): string
    {
        foreach ($externalOrderIds as $externalOrderId) {
            if (!isset($completed[$externalOrderId])) {
                return $externalOrderId;
            }
        }
        throw new \RuntimeException('No hay una orden Billing pendiente para la venta.');
    }

    /**
     * @param list<array<string,mixed>> $lines
     * @param array<string,mixed> $response
     * @param array<string,mixed> $metadata
     */
    private function persistBillingOrderCheckpoint(
        array $job,
        int $captureId,
        int $httpStatus,
        array $response,
        array $lines,
        string $orderId,
        string $status,
        string $message,
        string $responseClass,
        array $metadata,
        string $jobStatus
    ): void {
        $pdo = Database::connectionFresh();
        $pdo->beginTransaction();
        try {
            $this->assertLeaseInTransaction($pdo, $job);
            $missingFields = $this->missingFields($metadata);
            $responseHash = hash(
                'sha256',
                json_encode(Logger::redact($response), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            );
            $pdo->prepare(
                'UPDATE meli_billing_capture_runs
                 SET http_status=?,response_class=?,response_hash=?,missing_fields_json=?,
                     safe_message=?,captured_at=UTC_TIMESTAMP()
                 WHERE id=? AND meli_account_id=?'
            )->execute([
                $httpStatus, $responseClass,
                $responseHash,
                json_encode($missingFields, JSON_UNESCAPED_UNICODE),
                $message, $captureId, (int) $job['meli_account_id'],
            ]);
            (new SaleFinancialStateService())->recordBillingOrderCheckpoint(
                $pdo,
                $job,
                $captureId,
                $orderId,
                $lines,
                $status,
                $message,
                $responseHash,
                $httpStatus,
                $responseClass,
                $metadata
            );
            if ($jobStatus !== 'running') {
                $this->finishInTransaction($pdo, $job, $jobStatus, $message, $responseClass);
            }
            $pdo->commit();
        } catch (Throwable $error) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $error;
        }
    }

    /** @param list<int> $orderIds @param array<string,array<string,mixed>> $checkpoints */
    private function publishCompletedBillingCheckpoints(array $job, array $orderIds, array $checkpoints): void
    {
        $lines = [];
        $latestCaptureId = 0;
        foreach ($checkpoints as $checkpoint) {
            $latestCaptureId = max($latestCaptureId, (int) ($checkpoint['capture_id'] ?? 0));
            foreach ($checkpoint['lines'] as $line) {
                $lines[] = $line;
            }
        }
        if ($latestCaptureId <= 0) {
            throw new \RuntimeException('La publicación Billing requiere checkpoints completos.');
        }
        $items = $this->items((int) $job['meli_account_id'], $orderIds);
        $calculationItems = array_map(static fn(array $item): array => [
            'id' => (int) $item['id'],
            'gross' => (float) $item['unit_price'] * (int) $item['quantity'],
            'units' => (int) $item['quantity'],
        ], $items);
        $totals = $this->calculate($calculationItems, $lines);
        $legacyEstimate = $this->legacyEstimate((int) $job['meli_account_id'], $orderIds);
        $status = $totals['unknown_amount'] > 0.009 ? 'review' : 'reconciled';
        $message = $status === 'reconciled'
            ? 'Venta conciliada con checkpoints Billing exactos por orden.'
            : 'Billing por orden contiene conceptos desconocidos. El total queda en revisión.';
        $this->persistResult(
            $job,
            $latestCaptureId,
            200,
            ['checkpoint_aggregate' => true],
            $lines,
            $items,
            $totals,
            $status,
            $message,
            'complete',
            ['status' => 200, 'headers' => [], 'request_id' => '', 'response_item_count' => count($lines)],
            $legacyEstimate,
            $status === 'reconciled' ? 'complete' : 'review',
            'complete',
            false
        );
    }

    /** @param list<string> $orderIds */
    private function beginCapture(array $job, array $orderIds): int
    {
        $pdo = Database::connectionFresh();
        $stmt = $pdo->prepare(
            'INSERT INTO meli_billing_capture_runs
                (company_id,meli_account_id,sale_key,external_sale_id,input_version,source_mode,requested_order_ids_json)
             VALUES (?,?,?,?,?,"exact_repair",?)'
        );
        $stmt->execute([
            (int) $job['company_id'], (int) $job['meli_account_id'], (string) $job['sale_key'],
            (string) $job['external_sale_id'], (string) $job['input_version'],
            json_encode($orderIds, JSON_UNESCAPED_UNICODE),
        ]);
        return (int) $pdo->lastInsertId();
    }

    /**
     * @param list<array<string,mixed>> $lines
     * @param list<array<string,mixed>> $items
     * @param array<string,mixed> $totals
     */
    private function persistResult(
        array $job,
        int $captureId,
        int $httpStatus,
        array $response,
        array $lines,
        array $items,
        array $totals,
        string $status,
        string $message,
        string $responseClass,
        array $metadata,
        ?float $legacyEstimate,
        string $jobStatus,
        string $remoteState,
        bool $updateCapture = true
    ): void
    {
        $pdo = Database::connectionFresh();
        $pdo->beginTransaction();
        try {
            $this->assertLeaseInTransaction($pdo, $job);
            $missingFields = $this->missingFields($metadata);
            $responseHash = hash(
                'sha256',
                json_encode(Logger::redact($response), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            );
            if ($updateCapture) {
                $pdo->prepare(
                    'UPDATE meli_billing_capture_runs
                     SET http_status=?,response_class=?,response_hash=?,missing_fields_json=?,
                         safe_message=?,captured_at=UTC_TIMESTAMP()
                     WHERE id=? AND meli_account_id=?'
                )->execute([
                    $httpStatus, $responseClass,
                    $responseHash,
                    json_encode($missingFields, JSON_UNESCAPED_UNICODE),
                    $message, $captureId, (int) $job['meli_account_id'],
                ]);
            }
            $stateApplied = (new SaleFinancialStateService())->recordBillingResult(
                $pdo,
                $job,
                $captureId,
                $totals,
                $status,
                $message,
                $responseHash
            );
            if (!$stateApplied) {
                $this->finishInTransaction(
                    $pdo,
                    $job,
                    'complete',
                    'La captura se conservó como evidencia inmutable, pero no reemplazó una versión financiera más reciente.',
                    'stale_input_version'
                );
                $pdo->commit();
                return;
            }
            $financialId = $this->ensureFinancial(
                $pdo,
                (int) $job['company_id'],
                (int) $job['meli_account_id'],
                (string) $job['sale_key'],
                (string) $job['external_sale_id'],
                str_starts_with((string) $job['sale_key'], 'P:') ? 'pack' : 'order',
                (string) ($items[0]['currency_id'] ?? 'COP'),
                $status,
                $message
            );
            $approved = $status === 'reconciled';
            $currentStatus = $pdo->prepare(
                'SELECT reconciliation_status FROM meli_sale_financials WHERE id=? FOR UPDATE'
            );
            $currentStatus->execute([$financialId]);
            $preserveOfficial = !$approved
                && $status === 'partial'
                && (string) $currentStatus->fetchColumn() === 'reconciled';
            if (!$preserveOfficial) {
                $pdo->prepare(
                    'UPDATE meli_sale_financials
                     SET products_amount=?,sale_fee_amount=?,shipping_charge_amount=?,taxes_amount=?,
                         discounts_amount=?,credits_amount=?,adjustments_amount=?,net_amount=?,
                         local_estimate_amount=?,legacy_difference_amount=?,
                         source=?,capture_run_id=?,reconciliation_status=?,
                         methodology_version="pack-v1",safe_message=?,
                         reconciled_at=IF(?="reconciled",UTC_TIMESTAMP(),reconciled_at)
                     WHERE id=?'
                )->execute([
                    $totals['products_amount'], $totals['sale_fee_amount'], $totals['shipping_charge_amount'],
                    $totals['taxes_amount'], $totals['discounts_amount'], $totals['credits_amount'],
                    $totals['adjustments_amount'], $approved ? $totals['net_amount'] : null,
                    $legacyEstimate,
                    $approved && $legacyEstimate !== null
                        ? round((float) $totals['net_amount'] - $legacyEstimate, 2)
                        : null,
                    $approved ? 'billing_official' : 'local_estimate',
                    $captureId, $status, $message,
                    $status, $financialId,
                ]);
                $pdo->prepare('DELETE FROM meli_sale_financial_lines WHERE meli_sale_financial_id=?')->execute([$financialId]);
                $lineStmt = $pdo->prepare(
                    'INSERT INTO meli_sale_financial_lines
                        (meli_sale_financial_id,meli_billing_capture_run_id,external_order_id,detail_id,
                         line_group,line_type,line_subtype,description,amount,direction,is_shared,
                         source_status,line_hash,occurred_at)
                     VALUES (?,?,?,?,?,?,?,?,?,?,?, ?,?,?)'
                );
                foreach ($lines as $line) {
                    $lineCaptureId = (int) ($line['capture_id'] ?? $captureId);
                    if ($lineCaptureId <= 0) {
                        $lineCaptureId = $captureId;
                    }
                    $lineStmt->execute([
                        $financialId, $lineCaptureId, $line['external_order_id'], $line['detail_id'],
                        $line['line_group'], $line['line_type'], $line['line_subtype'],
                        mb_substr((string) $line['description'], 0, 500), $line['amount'],
                        $line['direction'], $line['is_shared'],
                        $responseClass === 'complete' ? 'official' : $responseClass,
                        $line['line_hash'],
                        $line['occurred_at'],
                    ]);
                }
                $pdo->prepare('DELETE FROM meli_sale_financial_allocations WHERE meli_sale_financial_id=?')->execute([$financialId]);
                $allocationStmt = $pdo->prepare(
                    'INSERT INTO meli_sale_financial_allocations
                        (meli_sale_financial_id,meli_order_item_id,gross_amount,weight_basis,
                         sale_fee_allocated,shipping_allocated,tax_allocated,discount_allocated,
                         credit_allocated,other_allocated,net_allocated)
                     VALUES (?,?,?,?,?,?,?,?,?,?,?)'
                );
                foreach ($totals['allocations'] as $itemId => $allocation) {
                    $allocationStmt->execute([
                        $financialId, $itemId, $allocation['gross_amount'], $allocation['weight_basis'],
                        $allocation['sale_fee_allocated'], $allocation['shipping_allocated'],
                        $allocation['tax_allocated'], $allocation['discount_allocated'],
                        $allocation['credit_allocated'], $allocation['other_allocated'],
                        $allocation['net_allocated'],
                    ]);
                }
            }
            $revision = $pdo->prepare(
                'SELECT COALESCE(MAX(revision_no),0)+1 FROM meli_sale_financial_history
                 WHERE meli_sale_financial_id=?'
            );
            $revision->execute([$financialId]);
            $revisionNo = (int) $revision->fetchColumn();
            $historySource = $approved ? 'billing_official' : 'billing_unapproved';
            $pdo->prepare(
                'INSERT INTO meli_sale_financial_history
                    (meli_sale_financial_id,revision_no,methodology_version,totals_json,
                     lines_summary_json,allocations_summary_json,source,safe_message)
                  VALUES (?,?,"pack-v1",?,?,?,?,?)'
            )->execute([
                $financialId, $revisionNo,
                json_encode(array_diff_key($totals, ['allocations' => true]), JSON_UNESCAPED_UNICODE),
                json_encode(array_map(static fn(array $line): array => [
                    'order_id' => $line['external_order_id'],
                    'group' => $line['line_group'],
                    'type' => $line['line_type'],
                    'subtype' => $line['line_subtype'],
                    'amount' => $line['amount'],
                    'direction' => $line['direction'],
                    'shared' => $line['is_shared'],
                ], $lines), JSON_UNESCAPED_UNICODE),
                json_encode($totals['allocations'], JSON_UNESCAPED_UNICODE),
                $historySource,
                $message,
            ]);
            $this->finishInTransaction($pdo, $job, $jobStatus, $message, $remoteState);
            $pdo->commit();
        } catch (Throwable $error) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $error;
        }
    }

    /** @param array<string,mixed> $job */
    private function assertLeaseInTransaction(PDO $pdo, array $job): void
    {
        $stmt = $pdo->prepare(
            'SELECT id FROM sale_financial_reconciliation_jobs
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
            throw new \RuntimeException('La conciliación perdió su reserva; el resultado tardío fue descartado.');
        }
    }

    /** @param array<string,mixed> $job */
    private function finishInTransaction(
        PDO $pdo,
        array $job,
        string $status,
        string $message,
        ?string $remoteState = null
    ): void
    {
        $valid = ['retry', 'awaiting_remote', 'complete', 'partial', 'review', 'error'];
        $status = in_array($status, $valid, true) ? $status : 'error';
        $deferred = in_array($status, ['retry', 'awaiting_remote'], true);
        $next = $deferred
            ? 'DATE_ADD(UTC_TIMESTAMP(),INTERVAL :delay MINUTE)'
            : 'next_run_at';
        $stmt = $pdo->prepare(
            'UPDATE sale_financial_reconciliation_jobs
             SET status=:status,next_run_at=' . $next . ',safe_message=:message,
                 remote_pending_since=IF(:deferred_since=1,COALESCE(remote_pending_since,UTC_TIMESTAMP()),remote_pending_since),
                 retry_until=IF(:deferred_until=1,COALESCE(retry_until,DATE_ADD(UTC_TIMESTAMP(),INTERVAL :retry_days DAY)),retry_until),
                 last_remote_state=COALESCE(:remote_state,last_remote_state),
                 completed_at=IF(:terminal IN ("complete","partial","review","error"),UTC_TIMESTAMP(),completed_at),
                 lock_owner=NULL,lease_expires_at=NULL,heartbeat_at=NULL
             WHERE id=:id AND status="running" AND lock_owner=:owner AND lease_generation=:generation'
        );
        $parameters = [
            'status' => $status,
            'message' => mb_substr($message, 0, 500),
            'deferred_since' => $deferred ? 1 : 0,
            'deferred_until' => $deferred ? 1 : 0,
            'retry_days' => $this->retryHorizonDays(),
            'remote_state' => $remoteState,
            'terminal' => $status,
            'id' => (int) $job['id'],
            'owner' => (string) $job['lock_owner'],
            'generation' => (int) $job['lease_generation'],
        ];
        if ($deferred) {
            $parameters['delay'] = $this->retryDelayMinutes((int) $job['attempts']);
        }
        $stmt->execute($parameters);
        if ($stmt->rowCount() !== 1) {
            throw new \RuntimeException('La conciliación perdió su reserva antes de aprobar el resultado.');
        }
    }

    /** @param array<string,mixed> $job */
    private function deferWithoutAttemptPenalty(array $job, string $message, string $nextRunAt): void
    {
        $timestamp = strtotime(trim($nextRunAt) . ' UTC');
        if ($timestamp === false || $timestamp < time() - 5) {
            throw new \RuntimeException('La conciliación recibió una fecha de reintento no segura.');
        }
        $stmt = Database::connectionFresh()->prepare(
            'UPDATE sale_financial_reconciliation_jobs
             SET status="retry",next_run_at=:next_run_at,safe_message=:message,
                 lock_owner=NULL,lease_expires_at=NULL,heartbeat_at=NULL,
                 attempts=GREATEST(attempts-1,0)
             WHERE id=:id AND status="running" AND lock_owner=:owner
               AND lease_generation=:generation AND attempts=:expected_attempts'
        );
        $stmt->execute([
            'next_run_at' => gmdate('Y-m-d H:i:s', $timestamp),
            'message' => mb_substr($message, 0, 500),
            'id' => (int) $job['id'],
            'owner' => (string) $job['lock_owner'],
            'generation' => (int) $job['lease_generation'],
            'expected_attempts' => (int) $job['attempts'],
        ]);
        if ($stmt->rowCount() !== 1) {
            throw new \RuntimeException('La conciliación perdió su reserva antes de aplazar sin penalización.');
        }
    }

    /** @param list<int> $orderIds */
    private function legacyEstimate(int $accountId, array $orderIds): ?float
    {
        if ($orderIds === [] || !(new SchemaInspectorService())->hasTable('meli_order_financials')) {
            return null;
        }
        $columns = (new SchemaInspectorService())->columns('meli_order_financials');
        $valueColumn = isset($columns['local_estimated_net_amount'])
            ? 'local_estimated_net_amount'
            : (isset($columns['ml_net_amount']) ? 'ml_net_amount' : null);
        if ($valueColumn === null) {
            return null;
        }
        $stmt = Database::connectionFresh()->prepare(
            'SELECT COUNT(' . $valueColumn . '),SUM(' . $valueColumn . ')
             FROM meli_order_financials
             WHERE meli_account_id=?
               AND meli_order_id IN (' . implode(',', array_fill(0, count($orderIds), '?')) . ')'
        );
        $stmt->execute(array_merge([$accountId], $orderIds));
        $row = $stmt->fetch(PDO::FETCH_NUM);
        return (int) ($row[0] ?? 0) > 0 ? round((float) $row[1], 2) : null;
    }

    /** @param array<string,mixed> $node @param list<string> $externalOrderIds */
    private function containsProcessingStatus(array $node, array $externalOrderIds): bool
    {
        $known = array_fill_keys(array_map('strval', $externalOrderIds), true);
        return $this->containsProcessingStatusForKnownOrders($node, $known);
    }

    /** @param array<string,mixed> $node @param array<string,bool> $known */
    private function containsProcessingStatusForKnownOrders(array $node, array $known, ?string $currentOrder = null): bool
    {
        foreach (['order_id', 'orderId', 'external_order_id'] as $orderKey) {
            $value = isset($node[$orderKey]) ? (string) $node[$orderKey] : '';
            if ($value !== '' && isset($known[$value])) {
                $currentOrder = $value;
                break;
            }
        }
        foreach ($node as $key => $value) {
            if (is_string($value)
                && in_array(strtolower(trim($value)), ['processing', 'in_process', 'pending'], true)
                && in_array(strtolower((string) $key), ['status', 'document_status', 'process_status'], true)) {
                return $currentOrder !== null && isset($known[$currentOrder]);
            }
            if (is_array($value) && $this->containsProcessingStatusForKnownOrders($value, $known, $currentOrder)) {
                return true;
            }
        }
        return false;
    }

    /** @param array<string,mixed> $metadata @return list<string> */
    private function missingFields(array $metadata): array
    {
        $headers = (array) ($metadata['headers'] ?? []);
        $value = '';
        foreach ($headers as $name => $headerValue) {
            if (strtolower((string) $name) === 'x-content-missing') {
                $value = is_array($headerValue) ? implode(',', $headerValue) : (string) $headerValue;
                break;
            }
        }
        return array_values(array_filter(array_map('trim', explode(',', $value))));
    }

    /** @param list<int> $orderIds @return list<array<string,mixed>> */
    private function items(int $accountId, array $orderIds): array
    {
        $stmt = Database::connectionFresh()->prepare(
            'SELECT i.*,o.external_order_id,o.currency_id
             FROM meli_order_items i
             JOIN meli_orders o
               ON o.id=i.meli_order_id
              AND o.meli_account_id=i.meli_account_id
             WHERE i.meli_account_id=?
               AND i.meli_order_id IN (' . implode(',', array_fill(0, count($orderIds), '?')) . ')
             ORDER BY o.id,i.id'
        );
        $stmt->execute(array_merge([$accountId], $orderIds));
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function ensureFinancial(PDO $pdo, int $companyId, int $accountId, string $saleKey, string $saleId, string $identityType, string $currency, string $status, string $message): int
    {
        $stmt = $pdo->prepare(
            'INSERT INTO meli_sale_financials
                (company_id,meli_account_id,sale_key,external_sale_id,identity_type,currency_id,
                 reconciliation_status,safe_message)
             VALUES (?,?,?,?,?,?,?,?)
             ON DUPLICATE KEY UPDATE
                company_id=VALUES(company_id),external_sale_id=VALUES(external_sale_id),
                identity_type=VALUES(identity_type),currency_id=VALUES(currency_id),
                safe_message=IF(
                    reconciliation_status="reconciled"
                    AND VALUES(reconciliation_status) IN ("queued","partial"),
                    safe_message,
                    VALUES(safe_message)
                ),
                reconciliation_status=IF(
                    reconciliation_status="reconciled"
                    AND VALUES(reconciliation_status) IN ("queued","partial"),
                    reconciliation_status,
                    VALUES(reconciliation_status)
                ),
                id=LAST_INSERT_ID(id)'
        );
        $stmt->execute([$companyId, $accountId, $saleKey, $saleId, $identityType, $currency, $status, $message]);
        return (int) $pdo->lastInsertId();
    }

    /** @return array<string,mixed>|null */
    private function claim(?int $jobId = null, ?int $previousAccountId = null): ?array
    {
        $pdo = Database::connectionFresh();
        $owner = bin2hex(random_bytes(16));
        $pdo->beginTransaction();
        try {
            $reservationGuard = ManualCampaignReservationGuard::sql(
                'sale_financial_reconciliation',
                'sale_financial_reconciliation_jobs.id'
            );
            $stmt = $pdo->prepare(
                'SELECT * FROM sale_financial_reconciliation_jobs
                 WHERE status IN ("pending","retry","awaiting_remote")
                   AND next_run_at<=UTC_TIMESTAMP()
                   AND (lease_expires_at IS NULL OR lease_expires_at<UTC_TIMESTAMP())
                   AND (? IS NULL OR id=?)' . $reservationGuard . '
                  ORDER BY priority_tier,
                           CASE WHEN ? IS NOT NULL AND meli_account_id=? THEN 1 ELSE 0 END,
                           next_run_at,id LIMIT 1 FOR UPDATE'
            );
            $stmt->execute([$jobId, $jobId, $previousAccountId, $previousAccountId]);
            $job = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!is_array($job)) {
                $pdo->commit();
                return null;
            }
            $generation = (int) $job['lease_generation'] + 1;
            $pdo->prepare(
                'UPDATE sale_financial_reconciliation_jobs
                 SET status="running",lock_owner=?,lease_generation=?,
                     lease_expires_at=DATE_ADD(UTC_TIMESTAMP(),INTERVAL 3 MINUTE),
                     heartbeat_at=UTC_TIMESTAMP(),attempts=attempts+1
                 WHERE id=?'
            )->execute([$owner, $generation, (int) $job['id']]);
            $pdo->commit();
            $job['lock_owner'] = $owner;
            $job['lease_generation'] = $generation;
            $job['attempts'] = (int) $job['attempts'] + 1;
            return $job;
        } catch (Throwable $error) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $error;
        }
    }

    /** @param array<string,mixed> $job */
    private function finish(
        array $job,
        string $status,
        string $message,
        ?string $minimumNextRunAt = null
    ): void
    {
        $valid = ['retry', 'awaiting_remote', 'complete', 'partial', 'review', 'error'];
        if (!in_array($status, $valid, true)) {
            $status = 'error';
        }
        $deferred = in_array($status, ['retry', 'awaiting_remote'], true);
        $next = $deferred
            ? 'GREATEST(DATE_ADD(UTC_TIMESTAMP(),INTERVAL :delay MINUTE),COALESCE(:minimum_next_run_at,UTC_TIMESTAMP()))'
            : 'next_run_at';
        $stmt = Database::connectionFresh()->prepare(
            'UPDATE sale_financial_reconciliation_jobs
             SET status=:status,next_run_at=' . $next . ',safe_message=:message,
                 remote_pending_since=IF(:deferred_since=1,COALESCE(remote_pending_since,UTC_TIMESTAMP()),remote_pending_since),
                 retry_until=IF(:deferred_until=1,COALESCE(retry_until,DATE_ADD(UTC_TIMESTAMP(),INTERVAL :retry_days DAY)),retry_until),
                 completed_at=IF(:terminal IN ("complete","partial","review","error"),UTC_TIMESTAMP(),completed_at),
                 lock_owner=NULL,lease_expires_at=NULL,heartbeat_at=NULL
             WHERE id=:id AND lock_owner=:owner AND lease_generation=:generation'
        );
        $parameters = [
            'status' => $status,
            'message' => mb_substr($message, 0, 500),
            'deferred_since' => $deferred ? 1 : 0,
            'deferred_until' => $deferred ? 1 : 0,
            'retry_days' => $this->retryHorizonDays(),
            'terminal' => $status,
            'id' => (int) $job['id'], 'owner' => (string) $job['lock_owner'],
            'generation' => (int) $job['lease_generation'],
        ];
        if ($deferred) {
            $minimumTimestamp = $minimumNextRunAt === null
                ? false
                : strtotime(trim($minimumNextRunAt) . ' UTC');
            $parameters['delay'] = $this->retryDelayMinutes((int) $job['attempts']);
            $parameters['minimum_next_run_at'] = $minimumTimestamp === false
                ? null
                : gmdate('Y-m-d H:i:s', $minimumTimestamp);
        }
        $stmt->execute($parameters);
        if ($stmt->rowCount() !== 1) {
            throw new \RuntimeException('La conciliación perdió su reserva y no pudo cerrarse.');
        }
    }

    /** @param array<string,mixed> $job */
    private function heartbeat(array $job): void
    {
        $stmt = Database::connectionFresh()->prepare(
            'UPDATE sale_financial_reconciliation_jobs
             SET heartbeat_at=UTC_TIMESTAMP(),
                 lease_expires_at=DATE_ADD(UTC_TIMESTAMP(),INTERVAL 4 MINUTE)
             WHERE id=? AND lock_owner=? AND lease_generation=? AND status="running"'
        );
        $stmt->execute([
            (int) $job['id'], (string) $job['lock_owner'], (int) $job['lease_generation'],
        ]);
        if ($stmt->rowCount() !== 1) {
            throw new \RuntimeException('La conciliación perdió su reserva antes de consultar Mercado Libre.');
        }
    }

    private function enqueueSale(
        int $companyId,
        int $accountId,
        string $saleKey,
        string $saleId,
        string $identityType,
        string $currency,
        string $originType,
        ?int $originId,
        int $priorityTier,
        ?int $createdBy,
        string $inputVersion
    ): int {
        if (preg_match('/^[a-f0-9]{64}$/', $inputVersion) !== 1) {
            throw new \RuntimeException('La conciliación requiere una versión de entrada financiera verificable.');
        }
        $priorityTier = max(1, min(99, $priorityTier));
        $pdo = Database::connectionFresh();
        $pdo->beginTransaction();
        try {
            $this->ensureFinancial(
                $pdo,
                $companyId,
                $accountId,
                $saleKey,
                $saleId,
                $identityType,
                $currency,
                'queued',
                'La conciliación oficial está en espera del lanzador CLI.'
            );
            $stmt = $pdo->prepare(
                'INSERT INTO sale_financial_reconciliation_jobs
                    (company_id,meli_account_id,sale_key,external_sale_id,input_version,status,priority_tier,
                     origin_type,origin_id,created_by)
                 VALUES (?,?,?,?,?,"pending",?,?,?,?)
                 ON DUPLICATE KEY UPDATE
                    priority_tier=LEAST(priority_tier,VALUES(priority_tier)),
                    origin_type=VALUES(origin_type),origin_id=VALUES(origin_id),
                    next_run_at=IF(status IN ("running","complete"),next_run_at,UTC_TIMESTAMP()),
                    remote_pending_since=IF(status="running",remote_pending_since,NULL),
                    retry_until=IF(status IN ("running","complete"),retry_until,NULL),
                    last_remote_state=IF(status IN ("running","complete"),last_remote_state,NULL),
                    safe_message=IF(status IN ("running","complete"),safe_message,NULL),
                    completed_at=IF(status IN ("running","complete"),completed_at,NULL),
                    status=IF(status IN ("running","complete"),status,"pending"),
                    id=LAST_INSERT_ID(id)'
            );
            $stmt->execute([
                $companyId, $accountId, $saleKey, $saleId, $inputVersion, $priorityTier,
                mb_substr($originType, 0, 40), $originId, $createdBy,
            ]);
            $jobId = (int) $pdo->lastInsertId();
            $created = $stmt->rowCount() === 1;
            $pdo->prepare(
                'UPDATE sale_financial_state
                 SET official_status=IF(official_status="complete","complete","queued")
                 WHERE company_id=? AND meli_account_id=? AND sale_key=? AND input_version=?'
            )->execute([$companyId, $accountId, $saleKey, $inputVersion]);
            if ($created && $this->requiresAutomaticDomainAdmission($originType)) {
                $receipt = (new CronAdmissionService($pdo))->submit(
                    'financial_reconciliation',
                    $companyId,
                    $accountId,
                    $jobId,
                    'source:' . $jobId,
                );
                if (($receipt['accepted'] ?? false) !== true) {
                    throw new \RuntimeException(
                        'financial_reconciliation_cron_admission_failed:'
                        . (string) ($receipt['reason'] ?? 'UNKNOWN')
                    );
                }
            }
            $pdo->commit();
            return $jobId;
        } catch (Throwable $error) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $error;
        }
    }

    /**
     * A new automatic source is not durable work until its Queue V4 pointer is
     * stored in the same transaction. Manual exact requests retain their own
     * explicitly initiated flow and are never admitted as background work here.
     */
    private function requiresAutomaticDomainAdmission(string $originType): bool
    {
        return $originType !== 'manual';
    }

    private function retryDelayMinutes(int $attempts): int
    {
        return match (max(1, $attempts)) {
            1 => 2,
            2 => 5,
            3 => 15,
            4 => 60,
            5 => 360,
            default => 1440,
        };
    }

    private function retryHorizonDays(): int
    {
        return max(1, min(90, (new AppSettingsService())->int('sales_financial.remote_retry_days', 30)));
    }

    /** @param array<string,mixed> $job */
    private function retryDeadlineExceeded(array $job): bool
    {
        $deadline = trim((string) ($job['retry_until'] ?? ''));
        if ($deadline !== '') {
            return (strtotime($deadline) ?: PHP_INT_MAX) <= time();
        }
        $created = strtotime((string) ($job['created_at'] ?? '')) ?: time();
        return $created <= time() - ($this->retryHorizonDays() * 86400);
    }
}
