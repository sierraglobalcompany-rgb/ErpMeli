<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Csrf;
use App\Core\Database;
use App\Core\Env;
use App\Core\Session;
use App\Core\View;
use App\Services\BillingSafetyService;
use App\Services\AuthorizedBusinessScope;
use App\Services\CompanyOptionService;
use App\Services\DateReportService;
use App\Services\ExportService;
use App\Services\Logger;
use App\Services\SyncCoverageService;
use Throwable;

final class DateReportController
{
    public function index(): void
    {
        Auth::requireLogin();
        $filters = $this->filters($_GET);
        $scope = new AuthorizedBusinessScope();
        if ($filters['company_id'] > 0 && !in_array($filters['company_id'], $scope->companyIds(), true)) {
            throw new \App\Core\HttpException(404, 'No se encontró la empresa solicitada.');
        }
        if ($filters['account_id'] > 0) {
            $scope->account($filters['account_id'], $filters['company_id']);
        }
        $service = new DateReportService();
        $rows = $service->preview($filters);
        $coverage = (new SyncCoverageService())->summary((int) $filters['account_id'], (string) $filters['from'], (string) $filters['to']);
        $emitterOptions = $service->emitterAccountOptions();
        $runs = [];
        try {
            $runs = $service->listBillingRuns();
        } catch (Throwable $e) {
            Logger::write('error', 'Error listando facturación por fechas.', ['error' => $e->getMessage()]);
        }
        $companies = (new CompanyOptionService())->authorizedActive();
        $accountIds = $scope->accountIds();
        $accounts = [];
        if ($accountIds !== []) {
            $stmt = Database::connection()->prepare(
                'SELECT id,account_name,company_id FROM meli_accounts WHERE id IN (' . implode(',', array_fill(0, count($accountIds), '?')) . ') ORDER BY account_name'
            );
            $stmt->execute($accountIds);
            $accounts = $stmt->fetchAll();
        }
        View::render('billing/date/index', compact('filters', 'rows', 'runs', 'companies', 'accounts', 'coverage', 'emitterOptions'));
    }

    public function create(): void
    {
        Auth::requireRole('admin', 'operador');
        Csrf::validate($_POST['_token'] ?? null);
        try {
            $filters = $this->filters($_POST);
            (new AuthorizedBusinessScope())->account((int) $filters['account_id'], (int) $filters['company_id']);
            $safety = (new BillingSafetyService())->evaluate((int) $filters['account_id'], (string) $filters['from'], (string) $filters['to']);
            $filters['billing_safety'] = $safety;
            $filters['allow_incomplete'] = Auth::role() === 'admin' && !empty($_POST['allow_incomplete']);
            $filters['override_reason'] = (string) ($_POST['override_reason'] ?? '');
            $id = (new DateReportService())->createRun($filters);
            Session::flash(
                $safety['status'] === 'complete' ? 'success' : 'warning',
                $safety['status'] === 'complete'
                    ? 'Borrador de facturación por fechas creado.'
                    : 'Borrador creado como incompleto: ' . $safety['message']
            );
            $this->redirect('/billing/date/show?id=' . $id);
        } catch (Throwable $e) {
            Logger::write('error', 'Error generando facturación por fechas.', ['error' => $e->getMessage(), 'filters' => $_POST]);
            Session::flash('error', \App\Services\SafeErrorPresenter::message($e));
            $this->redirect('/billing/date');
        }
    }

    public function show(): void
    {
        Auth::requireLogin();
        $data = (new DateReportService())->findRun((int) ($_GET['id'] ?? 0));
        if (!$data['run']) {
            http_response_code(404);
            exit('Facturación por fechas no encontrada.');
        }
        View::render('billing/date/show', $data);
    }

    public function itemOrders(): void
    {
        Auth::requireLogin();
        $runId = (int) ($_GET['run_id'] ?? 0);
        $itemId = (int) ($_GET['item_id'] ?? 0);
        $data = (new DateReportService())->findRun($runId);
        if (!$data['run']) {
            http_response_code(404);
            exit('Facturación por fechas no encontrada.');
        }
        $item = null;
        foreach ($data['items'] as $candidate) {
            if ((int) $candidate['id'] === $itemId) {
                $item = $candidate;
                break;
            }
        }
        if (!$item) {
            http_response_code(404);
            exit('Producto del reporte no encontrado.');
        }
        $orders = (new DateReportService())->itemOrders($runId, $itemId, (int) ($_GET['page'] ?? 1));
        View::render('billing/date/item_orders', ['run' => $data['run'], 'item' => $item, 'orders' => $orders]);
    }

    public function itemOrdersExport(): void
    {
        Auth::requireLogin();
        $runId = (int) ($_GET['run_id'] ?? 0);
        $itemId = (int) ($_GET['item_id'] ?? 0);
        $orders = (new DateReportService())->itemOrders($runId, $itemId, 1, 5000);
        $rows = [];
        foreach ($orders['rows'] as $row) {
            $rows[] = [
                'Orden' => $row['external_order_id'],
                'Fecha' => $row['order_date'],
                'Cuenta' => $row['account_name'],
                'Producto' => $row['product_title'],
                'Unidades' => (float) $row['quantity'],
                'Producto total' => (float) $row['product_amount'],
                'Comisión' => (float) $row['sale_fee_amount'],
                'Envío comprador' => (float) $row['buyer_shipping_paid'],
                'Cargo Mercado Envíos' => (float) $row['ml_shipping_charge'],
                'Neto sin envío' => (float) $row['net_without_shipping'],
                'Neto después de envío' => (float) $row['net_after_shipping'],
                'Neto ML conciliado' => $row['reconciled_net_amount'] === null ? 'Pendiente' : (float) $row['reconciled_net_amount'],
                'Costo' => $row['cost_status'] === 'ok' ? (float) $row['total_cost'] : 'Pendiente',
                'Utilidad' => $row['cost_status'] === 'ok' ? (float) $row['profit_amount'] : 'Pendiente',
                'Estado financiero' => $row['financial_status'],
                'Estado costo' => $row['cost_status'],
            ];
        }
        (new ExportService())->streamCsv('erp-meli-ordenes-producto-' . $runId . '-' . $itemId . '.csv', $rows);
    }

    public function update(): void
    {
        Auth::requireRole('admin', 'operador');
        Csrf::validate($_POST['_token'] ?? null);
        $id = (int) ($_POST['id'] ?? 0);
        try {
            (new DateReportService())->updateManualBase($id, (float) ($_POST['manual_base'] ?? 0));
            Session::flash('success', 'Base manual actualizada.');
        } catch (Throwable $e) {
            Logger::write('error', 'Error actualizando base manual por fechas.', ['run_id' => $id, 'error' => $e->getMessage()]);
            Session::flash('error', \App\Services\SafeErrorPresenter::message($e));
        }
        $this->redirect('/billing/date/show?id=' . $id);
    }

    public function recalculate(): void
    {
        Auth::requireRole('admin', 'operador');
        Csrf::validate($_POST['_token'] ?? null);
        $id = (int) ($_POST['id'] ?? 0);
        try {
            (new DateReportService())->recalculateRun($id, !empty($_POST['queue_financial']));
            Session::flash('success', 'Facturación por fechas recalculada con la lógica financiera 2.7.1.');
        } catch (Throwable $e) {
            Logger::write('error', 'Error recalculando facturación por fechas.', ['run_id' => $id, 'error' => $e->getMessage()]);
            Session::flash('error', \App\Services\SafeErrorPresenter::message($e));
        }
        $this->redirect('/billing/date/show?id=' . $id);
    }

    public function financialRecalculate(): void
    {
        Auth::requireRole('admin', 'operador');
        Csrf::validate($_POST['_token'] ?? null);
        $id = (int) ($_POST['id'] ?? 0);
        try {
            $run = (new DateReportService())->findRun($id)['run'] ?? [];
            if (!$run) {
                throw new \RuntimeException('Facturación por fechas no encontrada.');
            }
            $jobId = (new \App\Services\OrderFinancialRecalcJobService())->createForRange(
                (int) $run['meli_account_id'],
                (string) $run['date_from'],
                (string) $run['date_to'],
                (string) ($_POST['mode'] ?? 'pending'),
                'date_report',
                $id,
                (int) Auth::id()
            );
            Session::flash('success', 'Job financiero #' . $jobId . ' creado para el rango de facturación. Puede procesarlo manualmente o dejar que cron avance.');
            $this->redirect('/financial-recalc/show?id=' . $jobId);
        } catch (Throwable $e) {
            Logger::write('error', 'Error creando recálculo financiero de facturación.', ['run_id' => $id, 'error' => $e->getMessage()]);
            Session::flash('error', \App\Services\SafeErrorPresenter::message($e));
        }
        $this->redirect('/billing/date/show?id=' . $id);
    }

    public function transition(): void
    {
        Auth::requireRole('admin', 'operador');
        Csrf::validate($_POST['_token'] ?? null);
        $id = (int) ($_POST['id'] ?? 0);
        $target = (string) ($_POST['status'] ?? '');
        if (in_array($target, ['aprobado', 'facturado', 'anulado'], true)) {
            Auth::requireRole('admin');
        }
        try {
            (new DateReportService())->transition($id, $target, (int) Auth::id(), $_POST['reference'] ?? null);
            Session::flash('success', 'Estado actualizado.');
        } catch (Throwable $e) {
            Logger::write('error', 'Error cambiando estado de facturación por fechas.', ['run_id' => $id, 'target' => $target, 'error' => $e->getMessage()]);
            Session::flash('error', \App\Services\SafeErrorPresenter::message($e));
        }
        $this->redirect('/billing/date/show?id=' . $id);
    }

    public function export(): void
    {
        Auth::requireLogin();
        $data = (new DateReportService())->findRun((int) ($_GET['id'] ?? 0));
        if (!$data['run']) {
            http_response_code(404);
            exit('Facturación por fechas no encontrada.');
        }
        $type = (string) ($_GET['type'] ?? 'csv');
        $filename = 'erp-meli-facturacion-fechas-' . $data['run']['id'] . ($type === 'excel' ? '.xls' : '.csv');
        $rows = $this->exportRows($data['items']);
        if ($type === 'excel') {
            (new ExportService())->streamExcelHtml($filename, $rows, 'Facturación por fechas ERP Meli');
        }
        (new ExportService())->streamCsv($filename, $rows);
    }

    private function filters(array $source): array
    {
        $from = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($source['from'] ?? '')) ? (string) $source['from'] : date('Y-m-01');
        $to = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($source['to'] ?? '')) ? (string) $source['to'] : date('Y-m-d');
        $emitterAccount = (string) ($source['emitter_account'] ?? '');
        $issuer = (int) ($source['issuer_company_id'] ?? 0);
        $account = (int) ($source['account_id'] ?? $source['meli_account_id'] ?? 0);
        if ($emitterAccount !== '' && str_contains($emitterAccount, ':')) {
            [$issuer, $account] = array_map('intval', explode(':', $emitterAccount, 2));
        }
        return [
            'emitter_account' => $emitterAccount !== '' ? $emitterAccount : ($issuer > 0 ? $issuer . ':' . $account : ''),
            'issuer_company_id' => $issuer,
            'customer_company_id' => (int) ($source['customer_company_id'] ?? 0),
            'company_id' => (int) ($source['company_id'] ?? $issuer),
            'account_id' => $account,
            'from' => $from,
            'to' => $to,
            'include_returns' => !empty($source['include_returns']),
        ];
    }

    private function exportRows(array $items): array
    {
        $rows = [];
        foreach ($items as $item) {
            $costPending = ($item['cost_status'] ?? 'ok') !== 'ok';
            $units = (float) ($item['units_sold'] ?? 0);
            $orders = max(1, (int) ($item['orders_count'] ?? 0));
            $rows[] = [
                'Producto' => $item['internal_name'] ?: $item['product_title'],
                'SKU' => $item['seller_sku'] ?: '',
                'Órdenes' => (int) $item['orders_count'],
                'Unidades' => $units,
                'Producto promedio unidad' => $units > 0 ? round((float) $item['product_revenue'] / $units, 2) : 0,
                'Producto promedio orden' => round((float) $item['product_revenue'] / $orders, 2),
                'Total producto' => (float) $item['product_revenue'],
                'Envío pagado comprador' => (float) ($item['buyer_shipping_paid'] ?? $item['shipping_revenue'] ?? 0),
                'Cargo Mercado Envíos' => (float) ($item['ml_shipping_charge'] ?? 0),
                'Envío neto' => (float) ($item['shipping_net_amount'] ?? 0),
                'Comisiones ML' => (float) $item['marketplace_fees'],
                'Descuentos' => (float) $item['discounts'],
                'Neto sin envío' => (float) ($item['net_without_shipping'] ?? $item['estimated_net'] ?? 0),
                'Neto después de envío' => (float) ($item['net_after_shipping'] ?? $item['estimated_net'] ?? 0),
                'Neto ML conciliado' => isset($item['reconciled_net_amount']) ? (float) $item['reconciled_net_amount'] : '',
                'Costo unitario' => $costPending ? 'Sin costo' : (float) $item['unit_cost'],
                'Costo total' => $costPending ? 'Sin costo' : (float) $item['total_cost'],
                'Utilidad' => $costPending ? 'Pendiente' : (float) $item['estimated_profit'],
                'Margen' => $costPending ? 'Pendiente' : round((float) $item['margin_percent'], 2) . ' %',
                'Estado costo' => $item['cost_status'] ?? 'ok',
            ];
        }
        return $rows;
    }

    private function redirect(string $path): never
    {
        header('Location: ' . rtrim(Env::get('APP_URL', ''), '/') . $path);
        exit;
    }
}
