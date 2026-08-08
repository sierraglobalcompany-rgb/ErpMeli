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
use App\Services\CompanyOptionService;
use App\Services\Logger;
use App\Services\MonthlyReportService;
use PDO;
use Throwable;

final class MonthlyReportController
{
    public function index(): void
    {
        Auth::requireLogin();
        $pdo = Database::connection();
        $reports = $pdo->query('SELECT r.*,i.name issuer_name,c.name customer_name,a.account_name FROM monthly_reports r JOIN companies i ON i.id=r.issuer_company_id JOIN companies c ON c.id=r.customer_company_id JOIN meli_accounts a ON a.id=r.meli_account_id ORDER BY r.report_month DESC')->fetchAll(PDO::FETCH_ASSOC);
        $companies = (new CompanyOptionService())->active();
        $accounts = $pdo->query('SELECT id,account_name,company_id FROM meli_accounts ORDER BY account_name')->fetchAll(PDO::FETCH_ASSOC);
        View::render('billing/index', compact('reports', 'companies', 'accounts'));
    }

    public function create(): void
    {
        Auth::requireRole('admin', 'operador');
        Csrf::validate($_POST['_token'] ?? null);
        try {
            $month = (string) $_POST['month'];
            $from = $month . '-01';
            $to = date('Y-m-t', strtotime($from));
            $safety = (new BillingSafetyService())->evaluate((int) $_POST['meli_account_id'], $from, $to);
            $id = (new MonthlyReportService())->create((int) $_POST['issuer_company_id'], (int) $_POST['customer_company_id'], (int) $_POST['meli_account_id'], $month, (int) Auth::id());
            $this->storeSafetySnapshot($id, $safety);
            if ($safety['status'] !== 'complete') {
                Session::flash('warning', 'Borrador mensual creado como incompleto: ' . $safety['message']);
            }
            $this->redirect('/billing/show?id=' . $id);
        } catch (Throwable $e) {
            Logger::write('error', 'Error generando reporte mensual.', ['error' => $e->getMessage(), 'account_id' => (int) ($_POST['meli_account_id'] ?? 0), 'month' => (string) ($_POST['month'] ?? '')]);
            Session::flash('error', 'Error generando el reporte mensual. Revise logs técnicos.');
            $this->redirect('/billing');
        }
    }

    public function show(): void
    {
        Auth::requireLogin();
        $id = (int) ($_GET['id'] ?? 0);
        $pdo = Database::connection();
        $s = $pdo->prepare('SELECT r.*,i.name issuer_name,c.name customer_name,a.account_name FROM monthly_reports r JOIN companies i ON i.id=r.issuer_company_id JOIN companies c ON c.id=r.customer_company_id JOIN meli_accounts a ON a.id=r.meli_account_id WHERE r.id=:id');
        $s->execute(['id' => $id]);
        $report = $s->fetch(PDO::FETCH_ASSOC);
        if (!$report) {
            http_response_code(404);
            exit('Reporte no encontrado.');
        }
        $s = $pdo->prepare('SELECT * FROM monthly_report_items WHERE monthly_report_id=:id ORDER BY gross_sales DESC');
        $s->execute(['id' => $id]);
        $items = $s->fetchAll(PDO::FETCH_ASSOC);
        View::render('billing/show', compact('report', 'items'));
    }

    public function update(): void
    {
        Auth::requireRole('admin', 'operador');
        Csrf::validate($_POST['_token'] ?? null);
        $id = (int) $_POST['id'];
        try {
            (new MonthlyReportService())->updateManualBase($id, (float) $_POST['manual_base']);
            Session::flash('success', 'Base manual actualizada.');
        } catch (Throwable $e) {
            Session::flash('error', \App\Services\SafeErrorPresenter::message($e));
        }
        $this->redirect('/billing/show?id=' . $id);
    }

    public function transition(): void
    {
        Auth::requireRole('admin', 'operador');
        Csrf::validate($_POST['_token'] ?? null);
        $id = (int) $_POST['id'];
        $target = (string) $_POST['status'];
        if (in_array($target, ['aprobado', 'facturado', 'anulado'], true)) {
            Auth::requireRole('admin');
        }
        try {
            (new MonthlyReportService())->transition($id, $target, (int) Auth::id(), $_POST['reference'] ?? null);
            Session::flash('success', 'Estado actualizado.');
        } catch (Throwable $e) {
            Session::flash('error', \App\Services\SafeErrorPresenter::message($e));
        }
        $this->redirect('/billing/show?id=' . $id);
    }

    public function export(): void
    {
        Auth::requireLogin();
        $id = (int) ($_GET['id'] ?? 0);
        $pdo = Database::connection();
        $service = new MonthlyReportService();
        $report = $service->find($id);
        $s = $pdo->prepare('SELECT seller_sku,product_title,units,gross_sales,marketplace_fees,shipping_costs,discounts,estimated_net,manual_base FROM monthly_report_items WHERE monthly_report_id=:id ORDER BY seller_sku');
        $s->execute(['id' => $id]);
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="facturacion-mensual-' . $report['report_month'] . '.csv"');
        $out = fopen('php://output', 'wb');
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, ['SKU', 'Producto', 'Unidades', 'Venta bruta', 'Comisiones', 'Envíos', 'Descuentos', 'Neto estimado', 'Base manual'], ',', '"', '', "\n");
        foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $row) {
            fputcsv($out, $row, ',', '"', '', "\n");
        }
        fclose($out);
        exit;
    }

    private function storeSafetySnapshot(int $reportId, array $safety): void
    {
        try {
            Database::connection()->prepare(
                'UPDATE monthly_reports
                 SET coverage_status=:coverage_status,audit_status=:audit_status,audit_id=:audit_id,
                     coverage_snapshot_json=:coverage_snapshot,audit_snapshot_json=:audit_snapshot,
                     is_incomplete_draft=:incomplete
                 WHERE id=:id'
            )->execute([
                'coverage_status' => $safety['coverage_status'] ?? 'unknown',
                'audit_status' => $safety['audit_status'] ?? 'missing',
                'audit_id' => $safety['audit_id'] ?? null,
                'coverage_snapshot' => json_encode($safety['snapshot']['coverage'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'audit_snapshot' => json_encode($safety['snapshot']['audit'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'incomplete' => ($safety['status'] ?? 'incomplete') === 'complete' ? 0 : 1,
                'id' => $reportId,
            ]);
        } catch (Throwable $e) {
            Logger::write('warning', 'No se pudo guardar snapshot de seguridad mensual.', ['report_id' => $reportId, 'error' => $e->getMessage()]);
        }
    }

    private function redirect(string $path): never
    {
        header('Location: ' . rtrim(Env::get('APP_URL', ''), '/') . $path);
        exit;
    }
}
