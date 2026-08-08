<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use DateTimeImmutable;
use DateTimeZone;
use PDO;
use RuntimeException;
use Throwable;

final class MonthlyReportService
{
    public function create(int $issuerId, int $customerId, int $accountId, string $month, int $userId): int
    {
        $first = DateTimeImmutable::createFromFormat('!Y-m', $month, new DateTimeZone(DateTimePresenter::timezone()));
        if (!$first || $issuerId === $customerId) {
            throw new RuntimeException('Periodo o empresas inválidos.');
        }
        $fromUtc = $first->setTimezone(new DateTimeZone('UTC'));
        $toUtc = $first->modify('first day of next month')->setTimezone(new DateTimeZone('UTC'));
        $pdo = Database::connection();
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare('INSERT INTO monthly_reports (issuer_company_id,customer_company_id,meli_account_id,report_month,created_by) VALUES (:issuer,:customer,:account,:month,:user)');
            $stmt->execute(['issuer' => $issuerId, 'customer' => $customerId, 'account' => $accountId, 'month' => $first->format('Y-m-01'), 'user' => $userId]);
            $reportId = (int) $pdo->lastInsertId();
            $rows = $this->sourceRows($accountId, $fromUtc, $toUtc);
            $groups = [];
            $orders = [];
            foreach ($rows as $row) {
                $sku = trim((string) ($row['seller_sku'] ?: 'SIN-SKU:' . $row['external_item_id']));
                $gross = (float) $row['unit_price'] * (int) $row['quantity'];
                $orderGross = max(0.01, (float) $row['order_items_gross']);
                $share = $gross / $orderGross;
                $fee = (float) $row['sale_fee'];
                $shipping = (float) $row['seller_shipping_cost'] * $share;
                $discount = (float) $row['coupon_amount'] * $share;
                $valid = $row['order_status'] !== 'cancelled' && $row['payment_status'] === 'approved';
                $classification = $valid ? 'valida' : 'cancelada';
                $orders[(int) $row['meli_order_id']] = $classification;
                if (!$valid) {
                    continue;
                }
                if (!isset($groups[$sku])) {
                    $groups[$sku] = ['title' => $row['title'], 'units' => 0, 'gross' => 0.0, 'fees' => 0.0, 'shipping' => 0.0, 'discounts' => 0.0];
                }
                $groups[$sku]['units'] += (int) $row['quantity'];
                $groups[$sku]['gross'] += $gross;
                $groups[$sku]['fees'] += $fee;
                $groups[$sku]['shipping'] += $shipping;
                $groups[$sku]['discounts'] += $discount;
            }
            $insertItem = $pdo->prepare('INSERT INTO monthly_report_items (monthly_report_id,seller_sku,product_title,units,gross_sales,marketplace_fees,shipping_costs,discounts,estimated_net,manual_base) VALUES (:report,:sku,:title,:units,:gross,:fees,:shipping,:discounts,:net,:manual)');
            $totals = ['gross' => 0.0, 'fees' => 0.0, 'shipping' => 0.0, 'discounts' => 0.0, 'net' => 0.0];
            foreach ($groups as $sku => $group) {
                $net = $group['gross'] - $group['fees'] - $group['shipping'] - $group['discounts'];
                $insertItem->execute([
                    'report' => $reportId, 'sku' => $sku, 'title' => $group['title'], 'units' => $group['units'],
                    'gross' => round($group['gross'], 2), 'fees' => round($group['fees'], 2),
                    'shipping' => round($group['shipping'], 2), 'discounts' => round($group['discounts'], 2),
                    'net' => round($net, 2), 'manual' => round($net, 2),
                ]);
                foreach (['gross', 'fees', 'shipping', 'discounts'] as $key) $totals[$key] += $group[$key];
                $totals['net'] += $net;
            }
            $insertOrder = $pdo->prepare('INSERT INTO monthly_report_orders (monthly_report_id,meli_order_id,classification) VALUES (:report,:order,:class)');
            foreach ($orders as $orderId => $classification) {
                $insertOrder->execute(['report' => $reportId, 'order' => $orderId, 'class' => $classification]);
            }
            $pending = $pdo->prepare("SELECT ma.id,ma.amount FROM monthly_adjustments ma JOIN monthly_reports mr ON mr.id=ma.source_report_id WHERE mr.meli_account_id=:account AND ma.status='pending'");
            $pending->execute(['account' => $accountId]);
            foreach ($pending->fetchAll(PDO::FETCH_ASSOC) as $adjustment) {
                $totals['net'] += (float) $adjustment['amount'];
                $pdo->prepare("UPDATE monthly_adjustments SET target_report_id=:report,status='applied',applied_at=NOW() WHERE id=:id")
                    ->execute(['report' => $reportId, 'id' => $adjustment['id']]);
            }
            $netTotal = round($totals['net'], 2);
            $pdo->prepare('UPDATE monthly_reports SET gross_sales=:gross,marketplace_fees=:fees,shipping_costs=:shipping,discounts=:discounts,estimated_net=:estimated_net,manual_base=:manual_base WHERE id=:id')
                ->execute(['gross' => round($totals['gross'], 2), 'fees' => round($totals['fees'], 2), 'shipping' => round($totals['shipping'], 2), 'discounts' => round($totals['discounts'], 2), 'estimated_net' => $netTotal, 'manual_base' => $netTotal, 'id' => $reportId]);
            $pdo->commit();
            AuditService::record('create', 'monthly_reports', 'monthly_report', $reportId, $accountId, null, ['month' => $month]);
            return $reportId;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    }

    public function updateManualBase(int $reportId, float $manualBase): void
    {
        $report = $this->find($reportId);
        if (!in_array($report['status'], ['borrador', 'revisado'], true)) {
            throw new RuntimeException('Un reporte aprobado es inmutable.');
        }
        Database::connection()->prepare('UPDATE monthly_reports SET manual_base=:base WHERE id=:id')->execute(['base' => round($manualBase, 2), 'id' => $reportId]);
        AuditService::record('update_manual_base', 'monthly_reports', 'monthly_report', $reportId, (int) $report['meli_account_id'], ['manual_base' => $report['manual_base']], ['manual_base' => $manualBase]);
    }

    public function transition(int $reportId, string $target, int $userId, ?string $reference = null): void
    {
        $report = $this->find($reportId);
        if (!MonthlyReportStateMachine::can($report['status'], $target)) {
            throw new RuntimeException('Transición de estado no permitida.');
        }
        if ($target === 'facturado' && trim((string) $reference) === '') {
            throw new RuntimeException('La referencia externa de factura es obligatoria.');
        }
        (new BillingSafetyService())->assertCanTransition($report, $target);
        $fields = ['status=:status'];
        $params = ['status' => $target, 'id' => $reportId];
        if ($target === 'revisado') { $fields[] = 'reviewed_by=:user'; $params['user'] = $userId; }
        if ($target === 'aprobado') { $fields[] = 'approved_by=:user'; $fields[] = 'approved_at=NOW()'; $params['user'] = $userId; }
        if ($target === 'facturado') { $fields[] = 'invoiced_at=NOW()'; $fields[] = 'external_invoice_reference=:reference'; $params['reference'] = trim((string) $reference); }
        Database::connection()->prepare('UPDATE monthly_reports SET ' . implode(',', $fields) . ' WHERE id=:id')->execute($params);
        AuditService::record('transition_' . $target, 'monthly_reports', 'monthly_report', $reportId, (int) $report['meli_account_id'], ['status' => $report['status']], ['status' => $target]);
    }

    public function detectPostCloseAdjustments(): int
    {
        $sql = "SELECT DISTINCT mr.id report_id,mro.meli_order_id,mr.meli_account_id,mo.paid_amount
                FROM monthly_reports mr
                JOIN monthly_report_orders mro ON mro.monthly_report_id=mr.id AND mro.classification='valida'
                JOIN meli_orders mo ON mo.id=mro.meli_order_id
                WHERE mr.status IN ('aprobado','facturado') AND mo.status='cancelled'";
        $rows = Database::connection()->query($sql)->fetchAll(PDO::FETCH_ASSOC);
        $stmt = Database::connection()->prepare("INSERT IGNORE INTO monthly_adjustments (source_report_id,meli_order_id,reason,amount) VALUES (:report,:order,'cancelacion_posterior',:amount)");
        $count = 0;
        foreach ($rows as $row) {
            $stmt->execute(['report' => $row['report_id'], 'order' => $row['meli_order_id'], 'amount' => -1 * (float) $row['paid_amount']]);
            $count += $stmt->rowCount();
        }
        return $count;
    }

    public function find(int $reportId): array
    {
        $stmt = Database::connection()->prepare('SELECT * FROM monthly_reports WHERE id=:id');
        $stmt->execute(['id' => $reportId]);
        $report = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$report) throw new RuntimeException('Reporte no encontrado.');
        return $report;
    }

    private function sourceRows(int $accountId, DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        $hasLocalPaymentDate = (new SchemaInspectorService())->hasColumn('meli_payments', 'date_approved_local');
        $paymentDateSelect = $hasLocalPaymentDate
            ? 'MIN(date_approved) first_date_approved, MIN(date_approved_local) first_date_approved_local'
            : 'MIN(date_approved) first_date_approved';
        $dateColumn = $hasLocalPaymentDate ? 'p.first_date_approved_local' : 'p.first_date_approved';
        $fromParam = $hasLocalPaymentDate ? $from->setTimezone(new DateTimeZone(DateTimePresenter::timezone())) : $from;
        $toParam = $hasLocalPaymentDate ? $to->setTimezone(new DateTimeZone(DateTimePresenter::timezone())) : $to;
        $sql = "SELECT oi.*,o.id meli_order_id,o.status order_status,p.payment_status,p.coupon_amount,
                       COALESCE(s.seller_shipping_cost,0) seller_shipping_cost,
                       (SELECT SUM(x.unit_price*x.quantity) FROM meli_order_items x WHERE x.meli_order_id=o.id) order_items_gross
                FROM meli_orders o
                JOIN meli_order_items oi ON oi.meli_order_id=o.id
                JOIN (
                    SELECT meli_order_id,'approved' payment_status,SUM(coupon_amount) coupon_amount,{$paymentDateSelect}
                    FROM meli_payments WHERE status='approved' GROUP BY meli_order_id
                ) p ON p.meli_order_id=o.id
                LEFT JOIN (
                    SELECT meli_order_id,MAX(seller_cost) seller_shipping_cost FROM meli_shipments GROUP BY meli_order_id
                ) s ON s.meli_order_id=o.id
                WHERE o.meli_account_id=:account_id_report AND {$dateColumn}>=:from_date_report AND {$dateColumn}<:to_date_report";
        $stmt = Database::connection()->prepare($sql);
        $stmt->execute(['account_id_report' => $accountId, 'from_date_report' => $fromParam->format('Y-m-d H:i:s'), 'to_date_report' => $toParam->format('Y-m-d H:i:s')]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
