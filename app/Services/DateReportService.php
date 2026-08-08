<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Auth;
use App\Core\Database;
use DateTimeImmutable;
use PDO;
use RuntimeException;
use Throwable;

final class DateReportService
{
    public function __construct(private readonly AuthorizedBusinessScope $scope = new AuthorizedBusinessScope()) {}

    public function preview(array $filters, int $limit = 500, int $offset = 0): array
    {
        return $this->rows($filters, $limit, $offset);
    }

    public function emitterAccountOptions(): array
    {
        $pdo = Database::connection();
        $companies = (new CompanyOptionService())->authorizedActive();
        $accountIds = $this->scope->accountIds();
        $accounts = [];
        if ($accountIds !== []) {
            $stmt = $pdo->prepare(
                'SELECT id,company_id,account_name FROM meli_accounts
                 WHERE id IN (' . implode(',', array_fill(0, count($accountIds), '?')) . ')
                 ORDER BY account_name'
            );
            $stmt->execute($accountIds);
            $accounts = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
        $byCompany = [];
        foreach ($accounts as $account) {
            $byCompany[(int) $account['company_id']][] = $account;
        }
        $options = [];
        foreach ($companies as $company) {
            $companyId = (int) $company['id'];
            if (!empty($byCompany[$companyId])) {
                foreach ($byCompany[$companyId] as $account) {
                    $options[] = [
                        'value' => $companyId . ':' . (int) $account['id'],
                        'company_id' => $companyId,
                        'account_id' => (int) $account['id'],
                        'label' => $company['name'] . ' / ' . ($account['account_name'] ?: 'Cuenta ML #' . $account['id']),
                    ];
                }
                continue;
            }
            $options[] = [
                'value' => $companyId . ':0',
                'company_id' => $companyId,
                'account_id' => 0,
                'label' => $company['name'] . ' / Sin cuenta ML',
            ];
        }
        return $options;
    }

    public function createRun(array $filters): int
    {
        $this->ensureBillingColumns();
        $this->ensure271Columns();
        $this->applyEmitterAccount($filters);
        $issuerId = (int) ($filters['issuer_company_id'] ?? 0);
        $customerId = (int) ($filters['customer_company_id'] ?? 0);
        $accountId = (int) ($filters['account_id'] ?? 0);
        if ($issuerId <= 0 || $customerId <= 0 || $accountId <= 0) {
            throw new RuntimeException('Seleccione empresa emisora/cuenta Mercado Libre y empresa cliente.');
        }
        if ($issuerId === $customerId) {
            throw new RuntimeException('La empresa emisora y la empresa cliente deben ser diferentes.');
        }
        $this->assertCompanyAuthorized($issuerId);
        $this->assertCompanyAuthorized($customerId);
        $this->assertAccountBelongsToCompany($accountId, $issuerId);

        $rows = $this->rows($filters);
        $safety = $filters['billing_safety'] ?? (new BillingSafetyService())->evaluate($accountId, (string) $filters['from'], (string) $filters['to']);
        $isOverride = !empty($filters['allow_incomplete']) && trim((string) ($filters['override_reason'] ?? '')) !== '';
        $totals = $this->totals($rows);
        $requiresRecalculation = $totals['pending_financial_orders'] > 0 || $totals['pending_shipping_charge_count'] > 0 || $totals['missing_cost_items'] > 0;
        $pdo = Database::connection();
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare(
                'INSERT INTO date_report_runs
                 (company_id,issuer_company_id,customer_company_id,meli_account_id,date_from,date_to,include_returns,status,filters_json,total_orders,total_units,total_internal_units,gross_sales,product_revenue,shipping_revenue,buyer_shipping_paid,ml_shipping_charge,shipping_net_amount,marketplace_fees,discounts,refunds,net_without_shipping,net_after_shipping,reconciled_net_amount,estimated_net,manual_base,total_cost,estimated_profit,margin_percent,missing_cost_items_count,pending_financial_orders_count,pending_shipping_charge_count,requires_recalculation,coverage_status,audit_status,audit_id,coverage_snapshot_json,audit_snapshot_json,is_incomplete_draft,is_admin_override,override_by,override_reason,override_at,created_by)
                 VALUES (:company,:issuer,:customer,:account,:from,:to,:returns,"borrador",:filters,:orders,:units,:internal_units,:gross,:product,:shipping,:buyer_shipping,:ml_shipping,:shipping_net,:fees,:discounts,:refunds,:net_without_shipping,:net_after_shipping,:reconciled_net,:estimated_net,:manual,:cost,:profit,:margin,:missing_cost_items,:pending_financial_orders,:pending_shipping_charge,:requires_recalculation,:coverage_status,:audit_status,:audit_id,:coverage_snapshot,:audit_snapshot,:incomplete,:override,:override_by,:override_reason,:override_at,:user)'
            );
            $stmt->execute([
                'company' => $issuerId,
                'issuer' => $issuerId,
                'customer' => $customerId,
                'account' => $accountId,
                'from' => $filters['from'],
                'to' => $filters['to'],
                'returns' => !empty($filters['include_returns']) ? 1 : 0,
                'filters' => json_encode($filters, JSON_UNESCAPED_UNICODE),
                'orders' => $totals['orders'],
                'units' => $totals['units'],
                'internal_units' => $totals['internal_units'],
                'gross' => $totals['gross_sales'],
                'product' => $totals['product_revenue'],
                'shipping' => $totals['buyer_shipping_paid'],
                'buyer_shipping' => $totals['buyer_shipping_paid'],
                'ml_shipping' => $totals['ml_shipping_charge'],
                'shipping_net' => $totals['shipping_net_amount'],
                'fees' => $totals['marketplace_fees'],
                'discounts' => $totals['discounts'],
                'refunds' => $totals['refunds'],
                'net_without_shipping' => $totals['net_without_shipping'],
                'net_after_shipping' => $totals['net_after_shipping'],
                'reconciled_net' => $totals['reconciled_net_amount'],
                'estimated_net' => $totals['net_after_shipping'],
                'manual' => $totals['net_after_shipping'],
                'cost' => $totals['total_cost'],
                'profit' => $totals['estimated_profit'],
                'margin' => $totals['margin_percent'],
                'missing_cost_items' => $totals['missing_cost_items'],
                'pending_financial_orders' => $totals['pending_financial_orders'],
                'pending_shipping_charge' => $totals['pending_shipping_charge_count'],
                'requires_recalculation' => $requiresRecalculation ? 1 : 0,
                'coverage_status' => $safety['coverage_status'] ?? 'unknown',
                'audit_status' => $safety['audit_status'] ?? 'missing',
                'audit_id' => $safety['audit_id'] ?? null,
                'coverage_snapshot' => json_encode($safety['snapshot']['coverage'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'audit_snapshot' => json_encode($safety['snapshot']['audit'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'incomplete' => ($safety['status'] ?? 'incomplete') === 'complete' ? 0 : 1,
                'override' => $isOverride ? 1 : 0,
                'override_by' => $isOverride ? Auth::id() : null,
                'override_reason' => $isOverride ? mb_substr((string) $filters['override_reason'], 0, 500) : null,
                'override_at' => $isOverride ? gmdate('Y-m-d H:i:s') : null,
                'user' => Auth::id(),
            ]);
            $runId = (int) $pdo->lastInsertId();
            $this->insertItems($runId, $filters, $rows);
            foreach ($this->orderIds($filters) as $order) {
                $pdo->prepare('INSERT IGNORE INTO date_report_orders (date_report_run_id,meli_order_id,classification) VALUES (:run,:order_id,:classification)')
                    ->execute(['run' => $runId, 'order_id' => (int) $order['id'], 'classification' => $order['classification']]);
            }
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        AuditService::record('create', 'date_report_runs', 'date_billing', $runId, $accountId, null, ['from' => $filters['from'], 'to' => $filters['to'], 'issuer_company_id' => $issuerId, 'customer_company_id' => $customerId]);
        return $runId;
    }

    public function recalculateRun(int $runId, bool $queueFinancial = false): int
    {
        $run = $this->findRequiredRun($runId);
        if (!in_array($run['status'], ['borrador', 'revisado'], true)) {
            throw new RuntimeException('Solo se recalculan borradores o facturaciones revisadas. Las facturadas son inmutables.');
        }
        $filters = json_decode((string) ($run['filters_json'] ?? '{}'), true) ?: [];
        $filters['issuer_company_id'] = (int) ($run['issuer_company_id'] ?? $run['company_id'] ?? 0);
        $filters['customer_company_id'] = (int) ($run['customer_company_id'] ?? 0);
        $filters['account_id'] = (int) ($run['meli_account_id'] ?? 0);
        $filters['from'] = (string) $run['date_from'];
        $filters['to'] = (string) $run['date_to'];
        $filters['include_returns'] = (int) ($run['include_returns'] ?? 0) === 1;
        if ($queueFinancial) {
            (new OrderFinancialRecalcJobService())->createForRange((int) $filters['account_id'], (string) $filters['from'], (string) $filters['to'], 'pending', 'date_report', $runId, (int) Auth::id());
        }
        $rows = $this->rows($filters);
        $totals = $this->totals($rows);
        $pdo = Database::connection();
        $pdo->beginTransaction();
        try {
            $pdo->prepare('DELETE FROM date_report_items WHERE date_report_run_id=:id')->execute(['id' => $runId]);
            $pdo->prepare(
                'UPDATE date_report_runs
                 SET total_orders=:orders,total_units=:units,total_internal_units=:internal_units,gross_sales=:gross,
                     product_revenue=:product,shipping_revenue=:shipping,buyer_shipping_paid=:buyer_shipping,
                     ml_shipping_charge=:ml_shipping,shipping_net_amount=:shipping_net,marketplace_fees=:fees,
                     discounts=:discounts,refunds=:refunds,net_without_shipping=:net_without_shipping,
                     net_after_shipping=:net_after_shipping,reconciled_net_amount=:reconciled_net,
                     estimated_net=:estimated_net,total_cost=:cost,estimated_profit=:profit,margin_percent=:margin,
                     missing_cost_items_count=:missing_cost,pending_financial_orders_count=:pending_financial,
                     pending_shipping_charge_count=:pending_shipping,requires_recalculation=:requires_recalculation,
                     recalculated_at=UTC_TIMESTAMP()
                 WHERE id=:id'
            )->execute([
                'orders' => $totals['orders'],
                'units' => $totals['units'],
                'internal_units' => $totals['internal_units'],
                'gross' => $totals['gross_sales'],
                'product' => $totals['product_revenue'],
                'shipping' => $totals['buyer_shipping_paid'],
                'buyer_shipping' => $totals['buyer_shipping_paid'],
                'ml_shipping' => $totals['ml_shipping_charge'],
                'shipping_net' => $totals['shipping_net_amount'],
                'fees' => $totals['marketplace_fees'],
                'discounts' => $totals['discounts'],
                'refunds' => $totals['refunds'],
                'net_without_shipping' => $totals['net_without_shipping'],
                'net_after_shipping' => $totals['net_after_shipping'],
                'reconciled_net' => $totals['reconciled_net_amount'],
                'estimated_net' => $totals['net_after_shipping'],
                'cost' => $totals['total_cost'],
                'profit' => $totals['estimated_profit'],
                'margin' => $totals['margin_percent'],
                'missing_cost' => $totals['missing_cost_items'],
                'pending_financial' => $totals['pending_financial_orders'],
                'pending_shipping' => $totals['pending_shipping_charge_count'],
                'requires_recalculation' => ($totals['pending_financial_orders'] > 0 || $totals['pending_shipping_charge_count'] > 0 || $totals['missing_cost_items'] > 0) ? 1 : 0,
                'id' => $runId,
            ]);
            $this->insertItems($runId, $filters, $rows);
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        AuditService::record('recalculate', 'date_report_runs', 'date_billing', $runId, (int) $filters['account_id'], null, []);
        return $runId;
    }

    public function listBillingRuns(): array
    {
        if (!$this->ensureBillingColumns(false)) {
            return [];
        }
        [$scopeSql, $scopeParams] = $this->accountScope('r.meli_account_id', 0, 'billing_run_scope');
        $stmt = Database::connection()->prepare(
            'SELECT r.*, i.name issuer_name, c.name customer_name, a.account_name
             FROM date_report_runs r
             LEFT JOIN companies i ON i.id=r.issuer_company_id
             LEFT JOIN companies c ON c.id=r.customer_company_id
             LEFT JOIN meli_accounts a ON a.id=r.meli_account_id
             WHERE (r.issuer_company_id IS NOT NULL OR r.customer_company_id IS NOT NULL)
               AND ' . $scopeSql . '
             ORDER BY r.date_to DESC, r.id DESC LIMIT 100'
        );
        $stmt->execute($scopeParams);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function findRun(int $id): array
    {
        $pdo = Database::connection();
        [$scopeSql, $scopeParams] = $this->accountScope('r.meli_account_id', 0, 'find_run_scope');
        $sql = $this->ensureBillingColumns(false)
            ? 'SELECT r.*, i.name issuer_name, c.name customer_name, a.account_name FROM date_report_runs r LEFT JOIN companies i ON i.id=r.issuer_company_id LEFT JOIN companies c ON c.id=r.customer_company_id LEFT JOIN meli_accounts a ON a.id=r.meli_account_id WHERE r.id=:id AND ' . $scopeSql
            : 'SELECT r.*, c.name company_name, a.account_name FROM date_report_runs r LEFT JOIN companies c ON c.id=r.company_id LEFT JOIN meli_accounts a ON a.id=r.meli_account_id WHERE r.id=:id AND ' . $scopeSql;
        $stmt = $pdo->prepare($sql);
        $stmt->execute(['id' => $id] + $scopeParams);
        $run = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        $items = [];
        if ($run) {
            $orderBy = (new SchemaInspectorService())->hasColumn('date_report_items', 'net_after_shipping') ? 'net_after_shipping DESC, estimated_profit DESC' : 'estimated_profit DESC';
            $s = $pdo->prepare('SELECT i.*, p.internal_sku, p.name internal_name FROM date_report_items i LEFT JOIN internal_products p ON p.id=i.internal_product_id WHERE i.date_report_run_id=:id ORDER BY ' . $orderBy);
            $s->execute(['id' => $id]);
            $items = $s->fetchAll(PDO::FETCH_ASSOC);
        }
        return ['run' => $run, 'items' => $items];
    }

    public function itemOrders(int $runId, int $itemId, int $page = 1, int $perPage = 50): array
    {
        $this->findRequiredRun($runId);
        $page = max(1, $page);
        $perPage = max(10, min(100, $perPage));
        $offset = ($page - 1) * $perPage;
        $pdo = Database::connection();
        $count = $pdo->prepare('SELECT COUNT(*) FROM date_report_item_orders WHERE date_report_run_id=:run AND date_report_item_id=:item');
        $count->execute(['run' => $runId, 'item' => $itemId]);
        $stmt = $pdo->prepare(
            'SELECT dio.*
             FROM date_report_item_orders dio
             WHERE dio.date_report_run_id=:run AND dio.date_report_item_id=:item
             ORDER BY dio.order_date DESC, dio.id DESC
             LIMIT :limit OFFSET :offset'
        );
        $stmt->bindValue(':run', $runId, PDO::PARAM_INT);
        $stmt->bindValue(':item', $itemId, PDO::PARAM_INT);
        $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        return ['total' => (int) $count->fetchColumn(), 'page' => $page, 'per_page' => $perPage, 'rows' => $stmt->fetchAll(PDO::FETCH_ASSOC)];
    }

    public function updateManualBase(int $runId, float $manualBase): void
    {
        $run = $this->findRequiredRun($runId);
        if (!in_array($run['status'], ['borrador', 'revisado'], true)) {
            throw new RuntimeException('Una facturación aprobada o facturada es inmutable.');
        }
        Database::connection()->prepare('UPDATE date_report_runs SET manual_base=:base WHERE id=:id')->execute(['base' => round($manualBase, 2), 'id' => $runId]);
        AuditService::record('update_manual_base', 'date_report_runs', 'date_billing', $runId, (int) $run['meli_account_id'], ['manual_base' => $run['manual_base']], ['manual_base' => $manualBase]);
    }

    public function transition(int $runId, string $target, int $userId, ?string $reference = null): void
    {
        $run = $this->findRequiredRun($runId);
        if (!MonthlyReportStateMachine::can((string) $run['status'], $target)) {
            throw new RuntimeException('Transición de estado no permitida.');
        }
        if ($target === 'facturado' && trim((string) $reference) === '') {
            throw new RuntimeException('La referencia externa de factura es obligatoria.');
        }
        (new BillingSafetyService())->assertCanTransition($run, $target);
        $fields = ['status=:status'];
        $params = ['status' => $target, 'id' => $runId];
        if ($target === 'revisado') {
            $fields[] = 'reviewed_by=:user';
            $params['user'] = $userId;
        }
        if ($target === 'aprobado') {
            $fields[] = 'approved_by=:user';
            $fields[] = 'approved_at=UTC_TIMESTAMP()';
            $params['user'] = $userId;
        }
        if ($target === 'facturado') {
            $fields[] = 'invoiced_at=UTC_TIMESTAMP()';
            $fields[] = 'external_invoice_reference=:reference';
            $params['reference'] = trim((string) $reference);
        }
        if ($target === 'anulado') {
            $fields[] = 'voided_at=UTC_TIMESTAMP()';
        }
        Database::connection()->prepare('UPDATE date_report_runs SET ' . implode(',', $fields) . ' WHERE id=:id')->execute($params);
        AuditService::record('transition_' . $target, 'date_report_runs', 'date_billing', $runId, (int) $run['meli_account_id'], ['status' => $run['status']], ['status' => $target]);
    }

    private function insertItems(int $runId, array $filters, array $rows): void
    {
        $pdo = Database::connection();
        $itemStmt = $pdo->prepare(
            'INSERT INTO date_report_items
             (date_report_run_id,internal_product_id,meli_account_id,external_item_id,external_variation_id,seller_sku,product_title,orders_count,units_sold,internal_units,conversion_factor,product_revenue,shipping_revenue,buyer_shipping_paid,ml_shipping_charge,shipping_net_amount,marketplace_fees,discounts,refunds,net_without_shipping,net_after_shipping,reconciled_net_amount,estimated_net,unit_cost,total_cost,estimated_profit,margin_percent,suggested_purchase_value,cost_status,missing_cost_count,pending_financial_count,pending_shipping_charge_count,requires_recalculation)
             VALUES (:run,:internal,:account,:external,:variation,:sku,:title,:orders,:units,:internal_units,:factor,:product,:shipping,:buyer_shipping,:ml_shipping,:shipping_net,:fees,:discounts,:refunds,:net_without_shipping,:net_after_shipping,:reconciled_net,:estimated_net,:unit_cost,:cost,:profit,:margin,:suggested,:cost_status,:missing_cost_count,:pending_financial_count,:pending_shipping_charge_count,:requires_recalculation)'
        );
        foreach ($rows as $row) {
            $itemStmt->execute([
                'run' => $runId,
                'internal' => $row['internal_product_id'] ?: null,
                'account' => $row['meli_account_id'],
                'external' => $row['external_item_id'],
                'variation' => $row['external_variation_id'] ?: null,
                'sku' => $row['seller_sku'],
                'title' => $row['product_title'],
                'orders' => $row['orders_count'],
                'units' => $row['units_sold'],
                'internal_units' => $row['internal_units'],
                'factor' => $row['conversion_factor'],
                'product' => $row['product_revenue'],
                'shipping' => $row['buyer_shipping_paid'],
                'buyer_shipping' => $row['buyer_shipping_paid'],
                'ml_shipping' => $row['ml_shipping_charge'],
                'shipping_net' => $row['shipping_net_amount'],
                'fees' => $row['marketplace_fees'],
                'discounts' => $row['discounts'],
                'refunds' => $row['refunds'],
                'net_without_shipping' => $row['net_without_shipping'],
                'net_after_shipping' => $row['net_after_shipping'],
                'reconciled_net' => $row['reconciled_net_amount'],
                'estimated_net' => $row['net_after_shipping'],
                'unit_cost' => $row['unit_cost'],
                'cost' => $row['total_cost'],
                'profit' => $row['estimated_profit'],
                'margin' => $row['margin_percent'],
                'suggested' => $row['suggested_purchase_value'],
                'cost_status' => $row['cost_status'],
                'missing_cost_count' => $row['missing_cost_count'],
                'pending_financial_count' => $row['pending_financial_count'],
                'pending_shipping_charge_count' => $row['pending_shipping_charge_count'],
                'requires_recalculation' => $row['requires_recalculation'],
            ]);
            $this->insertItemOrders($runId, (int) $pdo->lastInsertId(), $filters, $row);
        }
    }

    private function rows(array $filters, int $limit = 500, int $offset = 0): array
    {
        $schema = new SchemaInspectorService();
        $hasLocalPaymentDate = $schema->hasColumn('meli_payments', 'date_approved_local');
        $hasFinancials = $schema->hasTable('meli_order_financials');
        $dateColumn = $hasLocalPaymentDate ? 'pay.date_approved_local' : 'pay.date_approved';
        $paymentDateSelect = $hasLocalPaymentDate
            ? 'MAX(date_approved) date_approved, MAX(date_approved_local) date_approved_local'
            : 'MAX(date_approved) date_approved';
        $financialJoin = $hasFinancials ? 'LEFT JOIN meli_order_financials fin ON fin.meli_order_id=o.id' : '';
        $where = [$dateColumn . '>=:from', $dateColumn . '<:to'];
        $params = [
            'from' => $filters['from'] . ' 00:00:00',
            'to' => (new DateTimeImmutable($filters['to'] . ' 00:00:00'))->modify('+1 day')->format('Y-m-d H:i:s'),
        ];
        [$scopeSql, $scopeParams] = $this->accountScope('o.meli_account_id', (int) ($filters['account_id'] ?? 0), 'row_scope');
        $where[] = $scopeSql;
        $params += $scopeParams;
        if (!empty($filters['company_id'])) {
            $this->assertCompanyAuthorized((int) $filters['company_id']);
            $where[] = 'a.company_id = :company';
            $params['company'] = (int) $filters['company_id'];
        }
        if (empty($filters['include_returns'])) {
            $where[] = "COALESCE(o.status,'') NOT IN ('cancelled','canceled')";
        }
        $hasBillingReconciledNet = $hasFinancials && $schema->hasColumn('meli_order_financials', 'billing_reconciled_net_amount');
        $buyerShippingExpr = $hasFinancials ? 'COALESCE(fin.buyer_shipping_paid,pay.shipping_cost,0)' : 'COALESCE(pay.shipping_cost,0)';
        $mlShippingExpr = $hasFinancials ? 'COALESCE(fin.ml_shipping_charge,0)' : '0';
        $reconciledExpr = $hasFinancials ? ($hasBillingReconciledNet ? 'COALESCE(fin.billing_reconciled_net_amount,fin.ml_net_amount)' : 'fin.ml_net_amount') : 'NULL';
        $enrichmentPending = $schema->hasColumn('meli_orders', 'enrichment_status')
            ? ' OR COALESCE(o.enrichment_status,"basic")<>"complete"'
            : '';
        $pendingFinancialExpr = $hasFinancials ? 'CASE WHEN fin.id IS NULL OR COALESCE(fin.reconciliation_status,"pending") NOT IN ("calculated","matched")' . $enrichmentPending . ' THEN 1 ELSE 0 END' : '1';
        $pendingShippingExpr = $hasFinancials ? 'CASE WHEN fin.id IS NULL OR COALESCE(fin.reconciliation_status,"pending") NOT IN ("calculated","matched")' . $enrichmentPending . ' THEN 1 ELSE 0 END' : '1';
        $sql = 'SELECT base.meli_account_id, base.account_name, base.external_item_id, base.external_variation_id,
                       base.seller_sku, MAX(base.product_title) product_title,
                       COUNT(DISTINCT base.order_id) orders_count,
                       SUM(base.quantity) units_sold,
                       COALESCE(MAX(base.conversion_factor),1) conversion_factor,
                       SUM(base.quantity * COALESCE(base.conversion_factor,1)) internal_units,
                       SUM(base.line_product) product_revenue,
                       SUM(base.buyer_shipping_alloc) shipping_revenue,
                       SUM(base.buyer_shipping_alloc) buyer_shipping_paid,
                       SUM(base.ml_shipping_alloc) ml_shipping_charge,
                       SUM(base.buyer_shipping_alloc - base.ml_shipping_alloc) shipping_net_amount,
                       SUM(base.sale_fee) marketplace_fees,
                       SUM(base.discount_alloc) discounts,
                       0 refunds,
                       SUM(base.line_product - base.sale_fee - base.discount_alloc) net_without_shipping,
                       SUM(base.line_product - base.sale_fee - base.discount_alloc + base.buyer_shipping_alloc - base.ml_shipping_alloc) net_after_shipping,
                       SUM(base.reconciled_net_alloc) reconciled_net_amount,
                       COALESCE(MAX(base.internal_product_id),0) internal_product_id,
                       CASE WHEN SUM(base.cost_missing)>0 THEN 0 ELSE COALESCE(MAX(base.manual_cost),0) END unit_cost,
                       CASE WHEN SUM(base.cost_missing)>0 THEN 0 ELSE SUM(base.quantity * COALESCE(base.conversion_factor,1) * COALESCE(base.manual_cost,0)) END total_cost,
                       CASE WHEN SUM(base.cost_missing)>0 THEN 0 ELSE SUM(base.line_product - base.sale_fee - base.discount_alloc + base.buyer_shipping_alloc - base.ml_shipping_alloc - (base.quantity * COALESCE(base.conversion_factor,1) * COALESCE(base.manual_cost,0))) END estimated_profit,
                       CASE WHEN SUM(base.cost_missing)>0 OR SUM(base.line_product)<=0 THEN 0 ELSE (SUM(base.line_product - base.sale_fee - base.discount_alloc + base.buyer_shipping_alloc - base.ml_shipping_alloc - (base.quantity * COALESCE(base.conversion_factor,1) * COALESCE(base.manual_cost,0))) / SUM(base.line_product)) * 100 END margin_percent,
                       COALESCE(MAX(base.suggested_purchase_value), MAX(base.manual_purchase_value), 0) suggested_purchase_value,
                       CASE WHEN SUM(base.cost_missing)=0 THEN "ok" WHEN SUM(base.cost_missing)=COUNT(*) THEN "missing" ELSE "partial" END cost_status,
                       SUM(base.cost_missing) missing_cost_count,
                       SUM(base.pending_financial) pending_financial_count,
                       SUM(base.pending_shipping) pending_shipping_charge_count,
                       CASE WHEN SUM(base.cost_missing)>0 OR SUM(base.pending_financial)>0 OR SUM(base.pending_shipping)>0 THEN 1 ELSE 0 END requires_recalculation
                FROM (
                    SELECT o.id order_id, oi.meli_account_id, a.account_name, oi.external_item_id, oi.external_variation_id,
                           COALESCE(oi.seller_sku,"") seller_sku, oi.title product_title, oi.quantity,
                           (oi.quantity * oi.unit_price) line_product,
                           COALESCE(NULLIF(ot.order_product_total,0), oi.quantity * oi.unit_price, 1) order_product_total,
                           COALESCE(oi.sale_fee,0) sale_fee,
                           ((oi.quantity * oi.unit_price) / COALESCE(NULLIF(ot.order_product_total,0), oi.quantity * oi.unit_price, 1)) * COALESCE(pay.coupon_amount,0) discount_alloc,
                           ((oi.quantity * oi.unit_price) / COALESCE(NULLIF(ot.order_product_total,0), oi.quantity * oi.unit_price, 1)) * ' . $buyerShippingExpr . ' buyer_shipping_alloc,
                           ((oi.quantity * oi.unit_price) / COALESCE(NULLIF(ot.order_product_total,0), oi.quantity * oi.unit_price, 1)) * ' . $mlShippingExpr . ' ml_shipping_alloc,
                           ((oi.quantity * oi.unit_price) / COALESCE(NULLIF(ot.order_product_total,0), oi.quantity * oi.unit_price, 1)) * COALESCE(' . $reconciledExpr . ',0) reconciled_net_alloc,
                           ip.id internal_product_id, ip.manual_cost, ip.suggested_purchase_value, ip.manual_purchase_value, COALESCE(l.conversion_factor,1) conversion_factor,
                           CASE WHEN ip.id IS NULL OR COALESCE(ip.manual_cost,0)<=0 OR COALESCE(l.conversion_factor,0)<=0 THEN 1 ELSE 0 END cost_missing,
                           ' . $pendingFinancialExpr . ' pending_financial,
                           ' . $pendingShippingExpr . ' pending_shipping
                    FROM meli_order_items oi
                    JOIN meli_orders o ON o.id=oi.meli_order_id
                    JOIN meli_accounts a ON a.id=o.meli_account_id
                    JOIN (
                        SELECT meli_order_id, SUM(quantity * unit_price) order_product_total
                        FROM meli_order_items GROUP BY meli_order_id
                    ) ot ON ot.meli_order_id=o.id
                    JOIN (
                        SELECT meli_order_id, ' . $paymentDateSelect . ', SUM(transaction_amount) transaction_amount, SUM(shipping_cost) shipping_cost, SUM(coupon_amount) coupon_amount
                        FROM meli_payments WHERE status="approved" GROUP BY meli_order_id
                    ) pay ON pay.meli_order_id=o.id
                    LEFT JOIN meli_items mi ON mi.meli_account_id=oi.meli_account_id AND mi.external_item_id=oi.external_item_id
                    LEFT JOIN product_meli_links l ON l.meli_account_id=oi.meli_account_id AND l.meli_item_id=mi.id AND l.meli_variation_id=COALESCE(oi.external_variation_id,0) AND l.status="active"
                    LEFT JOIN internal_products ip ON ip.id=l.internal_product_id AND ip.deleted_at IS NULL
                    ' . $financialJoin . '
                    WHERE ' . implode(' AND ', $where) . '
                ) base
                GROUP BY base.meli_account_id, base.account_name, base.external_item_id, base.external_variation_id, base.seller_sku
                ORDER BY product_revenue DESC,
                         base.meli_account_id ASC,
                         base.external_item_id ASC,
                         COALESCE(base.external_variation_id,0) ASC,
                         base.seller_sku ASC
                LIMIT ' . max(1, min(500, $limit)) . ' OFFSET ' . max(0, $offset);
        $stmt = Database::connection()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function insertItemOrders(int $runId, int $itemId, array $filters, array $row): void
    {
        if (!(new SchemaInspectorService())->hasTable('date_report_item_orders')) {
            return;
        }
        $orders = $this->itemOrderRowsFromSource($filters, $row);
        if (!$orders) {
            return;
        }
        $stmt = Database::connection()->prepare(
            'INSERT IGNORE INTO date_report_item_orders
             (date_report_run_id,date_report_item_id,meli_order_id,meli_account_id,external_order_id,order_date,account_name,product_title,seller_sku,quantity,product_amount,sale_fee_amount,discounts,refunds,buyer_shipping_paid,ml_shipping_charge,shipping_net_amount,net_without_shipping,net_after_shipping,reconciled_net_amount,unit_cost,total_cost,profit_amount,cost_status,financial_status)
             VALUES (:run,:item,:order_id,:account,:external_order,:order_date,:account_name,:title,:sku,:quantity,:product,:sale_fee,:discounts,0,:buyer_shipping,:ml_shipping,:shipping_net,:net_without_shipping,:net_after_shipping,:reconciled_net,:unit_cost,:total_cost,:profit,:cost_status,:financial_status)'
        );
        foreach ($orders as $order) {
            $stmt->execute(array_merge(['run' => $runId, 'item' => $itemId], $order));
        }
    }

    private function itemOrderRowsFromSource(array $filters, array $row): array
    {
        $schema = new SchemaInspectorService();
        $hasLocalPaymentDate = $schema->hasColumn('meli_payments', 'date_approved_local');
        $hasFinancials = $schema->hasTable('meli_order_financials');
        $dateColumn = $hasLocalPaymentDate ? 'pay.date_approved_local' : 'pay.date_approved';
        $paymentDateSelect = $hasLocalPaymentDate ? 'MAX(date_approved) date_approved, MAX(date_approved_local) date_approved_local' : 'MAX(date_approved) date_approved';
        $financialJoin = $hasFinancials ? 'LEFT JOIN meli_order_financials fin ON fin.meli_order_id=o.id' : '';
        $hasBillingReconciledNet = $hasFinancials && $schema->hasColumn('meli_order_financials', 'billing_reconciled_net_amount');
        $buyerShippingExpr = $hasFinancials ? 'COALESCE(fin.buyer_shipping_paid,pay.shipping_cost,0)' : 'COALESCE(pay.shipping_cost,0)';
        $mlShippingExpr = $hasFinancials ? 'COALESCE(fin.ml_shipping_charge,0)' : '0';
        $reconciledExpr = $hasFinancials ? ($hasBillingReconciledNet ? 'COALESCE(fin.billing_reconciled_net_amount,fin.ml_net_amount)' : 'fin.ml_net_amount') : 'NULL';
        $financialStatusExpr = $hasFinancials ? 'COALESCE(fin.reconciliation_status,"pending")' : '"pending"';
        $where = [
            $dateColumn . '>=:from',
            $dateColumn . '<:to',
            'o.meli_account_id=:account',
            'oi.external_item_id=:external',
            'COALESCE(oi.seller_sku,"")=:sku',
        ];
        $params = [
            'from' => $filters['from'] . ' 00:00:00',
            'to' => (new DateTimeImmutable($filters['to'] . ' 00:00:00'))->modify('+1 day')->format('Y-m-d H:i:s'),
            'account' => (int) $row['meli_account_id'],
            'external' => (string) $row['external_item_id'],
            'sku' => (string) ($row['seller_sku'] ?? ''),
        ];
        if (empty($row['external_variation_id'])) {
            $where[] = '(oi.external_variation_id IS NULL OR oi.external_variation_id=0)';
        } else {
            $where[] = 'oi.external_variation_id=:variation';
            $params['variation'] = (int) $row['external_variation_id'];
        }
        if (empty($filters['include_returns'])) {
            $where[] = "COALESCE(o.status,'') NOT IN ('cancelled','canceled')";
        }
        $sql = 'SELECT o.id order_id,o.external_order_id,COALESCE(o.date_created_local,o.date_created) order_date, a.account_name,
                       oi.title product_title,COALESCE(oi.seller_sku,"") seller_sku,oi.quantity,
                       (oi.quantity * oi.unit_price) product_amount,COALESCE(oi.sale_fee,0) sale_fee_amount,
                       ((oi.quantity * oi.unit_price) / COALESCE(NULLIF(ot.order_product_total,0), oi.quantity * oi.unit_price, 1)) * COALESCE(pay.coupon_amount,0) discounts,
                       ((oi.quantity * oi.unit_price) / COALESCE(NULLIF(ot.order_product_total,0), oi.quantity * oi.unit_price, 1)) * ' . $buyerShippingExpr . ' buyer_shipping_paid,
                       ((oi.quantity * oi.unit_price) / COALESCE(NULLIF(ot.order_product_total,0), oi.quantity * oi.unit_price, 1)) * ' . $mlShippingExpr . ' ml_shipping_charge,
                       ((oi.quantity * oi.unit_price) / COALESCE(NULLIF(ot.order_product_total,0), oi.quantity * oi.unit_price, 1)) * COALESCE(' . $reconciledExpr . ',0) reconciled_net_amount,
                       ip.manual_cost unit_cost,COALESCE(l.conversion_factor,1) conversion_factor,
                       CASE WHEN ip.id IS NULL OR COALESCE(ip.manual_cost,0)<=0 OR COALESCE(l.conversion_factor,0)<=0 THEN "missing" ELSE "ok" END cost_status,
                       ' . $financialStatusExpr . ' financial_status
                FROM meli_order_items oi
                JOIN meli_orders o ON o.id=oi.meli_order_id
                JOIN meli_accounts a ON a.id=o.meli_account_id
                JOIN (SELECT meli_order_id,SUM(quantity * unit_price) order_product_total FROM meli_order_items GROUP BY meli_order_id) ot ON ot.meli_order_id=o.id
                JOIN (SELECT meli_order_id,' . $paymentDateSelect . ',SUM(shipping_cost) shipping_cost,SUM(coupon_amount) coupon_amount FROM meli_payments WHERE status="approved" GROUP BY meli_order_id) pay ON pay.meli_order_id=o.id
                LEFT JOIN meli_items mi ON mi.meli_account_id=oi.meli_account_id AND mi.external_item_id=oi.external_item_id
                LEFT JOIN product_meli_links l ON l.meli_account_id=oi.meli_account_id AND l.meli_item_id=mi.id AND l.meli_variation_id=COALESCE(oi.external_variation_id,0) AND l.status="active"
                LEFT JOIN internal_products ip ON ip.id=l.internal_product_id AND ip.deleted_at IS NULL
                ' . $financialJoin . '
                WHERE ' . implode(' AND ', $where) . '
                ORDER BY order_date DESC LIMIT 2000';
        $stmt = Database::connection()->prepare($sql);
        $stmt->execute($params);
        $rows = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $source) {
            $netWithout = (float) $source['product_amount'] - (float) $source['sale_fee_amount'] - (float) $source['discounts'];
            $shippingNet = (float) $source['buyer_shipping_paid'] - (float) $source['ml_shipping_charge'];
            $netAfter = $netWithout + $shippingNet;
            $costOk = $source['cost_status'] === 'ok';
            $totalCost = $costOk ? (float) $source['quantity'] * (float) $source['conversion_factor'] * (float) $source['unit_cost'] : null;
            $rows[] = [
                'order_id' => (int) $source['order_id'],
                'account' => (int) $row['meli_account_id'],
                'external_order' => (string) $source['external_order_id'],
                'order_date' => $source['order_date'],
                'account_name' => $source['account_name'],
                'title' => $source['product_title'],
                'sku' => $source['seller_sku'],
                'quantity' => (float) $source['quantity'],
                'product' => round((float) $source['product_amount'], 2),
                'sale_fee' => round((float) $source['sale_fee_amount'], 2),
                'discounts' => round((float) $source['discounts'], 2),
                'buyer_shipping' => round((float) $source['buyer_shipping_paid'], 2),
                'ml_shipping' => round((float) $source['ml_shipping_charge'], 2),
                'shipping_net' => round($shippingNet, 2),
                'net_without_shipping' => round($netWithout, 2),
                'net_after_shipping' => round($netAfter, 2),
                'reconciled_net' => $source['reconciled_net_amount'] !== null ? round((float) $source['reconciled_net_amount'], 2) : null,
                'unit_cost' => $costOk ? round((float) $source['unit_cost'], 2) : null,
                'total_cost' => $totalCost !== null ? round($totalCost, 2) : null,
                'profit' => $totalCost !== null ? round($netAfter - $totalCost, 2) : null,
                'cost_status' => $source['cost_status'],
                'financial_status' => $source['financial_status'],
            ];
        }
        return $rows;
    }

    private function totals(array $rows): array
    {
        $totals = [
            'orders'=>0,'units'=>0.0,'internal_units'=>0.0,'gross_sales'=>0.0,'product_revenue'=>0.0,
            'shipping_revenue'=>0.0,'buyer_shipping_paid'=>0.0,'ml_shipping_charge'=>0.0,'shipping_net_amount'=>0.0,
            'marketplace_fees'=>0.0,'discounts'=>0.0,'refunds'=>0.0,'net_without_shipping'=>0.0,'net_after_shipping'=>0.0,
            'reconciled_net_amount'=>0.0,'estimated_net'=>0.0,'total_cost'=>0.0,'estimated_profit'=>0.0,'margin_percent'=>0.0,
            'missing_cost_items'=>0,'pending_financial_orders'=>0,'pending_shipping_charge_count'=>0,
        ];
        foreach ($rows as $row) {
            $totals['orders'] += (int) $row['orders_count'];
            $totals['units'] += (float) $row['units_sold'];
            $totals['internal_units'] += (float) $row['internal_units'];
            foreach (['product_revenue','shipping_revenue','buyer_shipping_paid','ml_shipping_charge','shipping_net_amount','marketplace_fees','discounts','refunds','net_without_shipping','net_after_shipping','reconciled_net_amount','total_cost','estimated_profit'] as $key) {
                $totals[$key] += (float) ($row[$key] ?? 0);
            }
            $totals['missing_cost_items'] += (int) ($row['missing_cost_count'] ?? 0);
            $totals['pending_financial_orders'] += (int) ($row['pending_financial_count'] ?? 0);
            $totals['pending_shipping_charge_count'] += (int) ($row['pending_shipping_charge_count'] ?? 0);
        }
        $totals['gross_sales'] = $totals['product_revenue'] + $totals['buyer_shipping_paid'];
        $totals['estimated_net'] = $totals['net_after_shipping'];
        $totals['margin_percent'] = $totals['product_revenue'] > 0 && $totals['missing_cost_items'] === 0 ? ($totals['estimated_profit'] / $totals['product_revenue']) * 100 : 0;
        return $totals;
    }

    private function orderIds(array $filters): array
    {
        $dateColumn = (new SchemaInspectorService())->hasColumn('meli_payments', 'date_approved_local') ? 'p.date_approved_local' : 'p.date_approved';
        $where = ['p.status="approved"', $dateColumn . '>=:from', $dateColumn . '<:to'];
        $params = ['from' => $filters['from'] . ' 00:00:00', 'to' => (new DateTimeImmutable($filters['to'] . ' 00:00:00'))->modify('+1 day')->format('Y-m-d H:i:s')];
        [$scopeSql, $scopeParams] = $this->accountScope('o.meli_account_id', (int) ($filters['account_id'] ?? 0), 'order_scope');
        $where[] = $scopeSql;
        $params += $scopeParams;
        if (!empty($filters['company_id'])) {
            $this->assertCompanyAuthorized((int) $filters['company_id']);
            $where[] = 'a.company_id=:company';
            $params['company'] = (int) $filters['company_id'];
        }
        $stmt = Database::connection()->prepare('SELECT DISTINCT o.id, CASE WHEN o.status IN ("cancelled","canceled") THEN "cancelled" ELSE "valid" END classification FROM meli_orders o JOIN meli_accounts a ON a.id=o.meli_account_id JOIN meli_payments p ON p.meli_order_id=o.id WHERE ' . implode(' AND ', $where) . ' LIMIT 2000');
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function applyEmitterAccount(array &$filters): void
    {
        $value = (string) ($filters['emitter_account'] ?? '');
        if ($value !== '' && str_contains($value, ':')) {
            [$company, $account] = array_map('intval', explode(':', $value, 2));
            $filters['issuer_company_id'] = $company;
            $filters['company_id'] = $company;
            $filters['account_id'] = $account;
        }
    }

    private function assertAccountBelongsToCompany(int $accountId, int $companyId): void
    {
        $this->scope->account($accountId, $companyId);
    }

    private function assertCompanyAuthorized(int $companyId): void
    {
        if ($companyId <= 0 || !in_array($companyId, $this->scope->companyIds(), true)) {
            throw new \App\Core\HttpException(404, 'No se encontró la empresa solicitada.');
        }
    }

    /** @return array{0:string,1:array<string,int>} */
    private function accountScope(string $column, int $requestedAccountId, string $prefix): array
    {
        $accountIds = $this->scope->accountIds();
        if ($requestedAccountId > 0) {
            if (!in_array($requestedAccountId, $accountIds, true)) {
                throw new \App\Core\HttpException(404, 'No se encontró la cuenta solicitada.');
            }
            return [$column . '=:' . $prefix, [$prefix => $requestedAccountId]];
        }
        if ($accountIds === []) {
            return ['1=0', []];
        }
        $names = [];
        $params = [];
        foreach ($accountIds as $index => $accountId) {
            $name = $prefix . '_' . $index;
            $names[] = ':' . $name;
            $params[$name] = $accountId;
        }
        return [$column . ' IN (' . implode(',', $names) . ')', $params];
    }

    private function findRequiredRun(int $id): array
    {
        $data = $this->findRun($id);
        if (!$data['run']) {
            throw new RuntimeException('Facturación por fechas no encontrada.');
        }
        return $data['run'];
    }

    private function ensureBillingColumns(bool $throw = true): bool
    {
        try {
            $stmt = Database::connection()->query("SHOW COLUMNS FROM date_report_runs LIKE 'issuer_company_id'");
            $exists = (bool) $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (Throwable) {
            $exists = false;
        }
        if (!$exists && $throw) {
            throw new RuntimeException('Debe ejecutar las migraciones pendientes antes de usar Facturación por fechas.');
        }
        return $exists;
    }

    private function ensure271Columns(): void
    {
        try {
            $stmt = Database::connection()->query("SHOW COLUMNS FROM date_report_items LIKE 'net_after_shipping'");
            if (!$stmt->fetch(PDO::FETCH_ASSOC)) {
                throw new RuntimeException('Debe ejecutar la migración 036 antes de usar Facturación por fechas 2.7.1.');
            }
        } catch (Throwable $e) {
            if ($e instanceof RuntimeException) {
                throw $e;
            }
            throw new RuntimeException('Debe ejecutar la migración 036 antes de usar Facturación por fechas 2.7.1.');
        }
    }
}
