<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Csrf;
use App\Core\Env;
use App\Core\Session;
use App\Core\View;
use App\Services\HistoricalPackReconciliationService;
use App\Services\SaleFinancialService;
use App\Services\SaleReadService;
use App\Services\SafeErrorPresenter;
use App\Services\SalesControlService;
use App\Services\SalesImportStatusService;

final class SaleController
{
    public function index(): void
    {
        Auth::requireLogin();
        $filters = [
            'company_id' => max(0, (int) ($_GET['company_id'] ?? 0)),
            'account_id' => max(0, (int) ($_GET['account_id'] ?? 0)),
            'status' => trim((string) ($_GET['status'] ?? '')),
            'financial_status' => in_array((string) ($_GET['financial_status'] ?? ''), [
                'complete', 'pending', 'review',
            ], true) ? (string) $_GET['financial_status'] : '',
            'q' => trim((string) ($_GET['q'] ?? '')),
            'from' => trim((string) ($_GET['from'] ?? '')),
            'to' => trim((string) ($_GET['to'] ?? '')),
            'page' => max(1, (int) ($_GET['page'] ?? 1)),
            'per_page' => (int) ($_GET['per_page'] ?? 50),
        ];
        $service = new SaleReadService();
        $accounts = $service->accounts($filters['company_id']);
        $sales = $service->list($filters);
        $importYear = max(2020, min((int) date('Y'), (int) ($_GET['import_year'] ?? date('Y'))));
        $importAccountId = (int) ($_GET['import_account_id'] ?? 0);
        if ($importAccountId <= 0 && (int) $filters['account_id'] > 0) {
            $importAccountId = (int) $filters['account_id'];
        }
        if ($importAccountId <= 0 && isset($accounts[0]['id'])) {
            $importAccountId = (int) $accounts[0]['id'];
        }
        $importStatus = null;
        $importStatusUrl = '/sales/import-status.json?' . http_build_query([
            'account_id' => $importAccountId,
            'year' => $importYear,
            'company_id' => $filters['company_id'],
        ]);
        View::render('sales/index', compact(
            'filters',
            'accounts',
            'sales',
            'importYear',
            'importAccountId',
            'importStatus',
            'importStatusUrl'
        ));
    }

    public function importStatusSection(): void
    {
        Auth::requireLogin();
        $async = new \App\Services\AsyncSectionService();
        $async->releaseSession();
        $startedAt = microtime(true);
        try {
            $accountId = max(0, (int) ($_GET['account_id'] ?? 0));
            $companyId = max(0, (int) ($_GET['company_id'] ?? 0));
            $year = max(2020, min((int) date('Y'), (int) ($_GET['year'] ?? date('Y'))));
            if ($accountId <= 0) {
                $async->render('sales-import-status', 'sales/_import_status', [
                    'importStatus' => null,
                ], [], $startedAt);
            }
            $cached = (new \App\Services\ReadModelCacheService())->rememberArray(
                'sales-import-status',
                implode(':', [(int) Auth::id(), $companyId, $accountId, $year]),
                20,
                static fn(): array => (new SalesImportStatusService())->year($accountId, $year, $companyId)
            );
            $async->render('sales-import-status', 'sales/_import_status', [
                'importStatus' => $cached['value'],
            ], ['cache' => $cached['cache']], $startedAt);
        } catch (\Throwable $error) {
            $async->failure('sales-import-status', $error);
        }
    }

    public function importYear(): void
    {
        Auth::requireRole('admin', 'operador');
        Csrf::validate($_POST['_token'] ?? null);
        $accountId = max(0, (int) ($_POST['account_id'] ?? 0));
        $year = max(2020, (int) ($_POST['year'] ?? date('Y')));
        try {
            (new SalesControlService())->checkYear($accountId, $year, 0, Auth::id());
            Session::flash(
                'success',
                'La importación quedó preparada. Automatización traerá y verificará las ventas sin modificar Mercado Libre.'
            );
        } catch (\Throwable $error) {
            Session::flash('error', SafeErrorPresenter::message(
                $error,
                'No fue posible preparar la importación anual.'
            ));
        }
        $this->redirect('/sales?' . http_build_query([
            'account_id' => $accountId,
            'import_account_id' => $accountId,
            'import_year' => $year,
        ]));
    }

    public function importAvailable(): void
    {
        Auth::requireRole('admin', 'operador');
        Csrf::validate($_POST['_token'] ?? null);
        $accountId = max(0, (int) ($_POST['account_id'] ?? 0));
        $months = max(1, min(24, (new \App\Services\AppSettingsService())->int(
            'sales_control.remote_window_months',
            12
        )));
        $now = new \DateTimeImmutable('now', new \DateTimeZone('America/Bogota'));
        $firstMonth = $now->modify('-' . $months . ' months')->modify('first day of this month');
        $lastMonth = $now->modify('first day of this month');
        $currentYear = (int) $now->format('Y');
        try {
            $service = new SalesControlService();
            $prepared = 0;
            for ($cursor = $firstMonth; $cursor <= $lastMonth; $cursor = $cursor->modify('+1 month')) {
                $service->checkMonth(
                    $accountId,
                    (int) $cursor->format('Y'),
                    (int) $cursor->format('n'),
                    0,
                    Auth::id()
                );
                $prepared++;
            }
            Session::flash(
                'success',
                $prepared . ' meses de la ventana histórica quedaron preparados. Automatización continuará desde cada checkpoint.'
            );
        } catch (\Throwable $error) {
            Session::flash('error', SafeErrorPresenter::message(
                $error,
                'No fue posible preparar toda la ventana histórica.'
            ));
        }
        $this->redirect('/sales?' . http_build_query([
            'account_id' => $accountId,
            'import_account_id' => $accountId,
            'import_year' => $currentYear,
        ]));
    }

    public function show(): void
    {
        Auth::requireLogin();
        $accountId = max(0, (int) ($_GET['account_id'] ?? 0));
        $saleId = trim((string) ($_GET['sale_id'] ?? ''));
        $highlightOrder = trim((string) ($_GET['highlight_order'] ?? ''));
        $sale = (new SaleReadService())->show($accountId, $saleId);
        View::render('sales/show', compact('sale', 'highlightOrder'));
    }

    public function orders(): void
    {
        Auth::requireLogin();
        $accountId = max(0, (int) ($_GET['account_id'] ?? 0));
        $saleId = trim((string) ($_GET['sale_id'] ?? ''));
        $sale = (new SaleReadService())->show($accountId, $saleId);
        View::render('sales/orders', compact('sale'));
    }

    public function integrity(): void
    {
        Auth::requireLogin();
        $accountId = max(0, (int) ($_GET['account_id'] ?? 0));
        $service = new HistoricalPackReconciliationService();
        $summary = $service->summary($accountId > 0 ? $accountId : null);
        $packs = $service->packs($accountId > 0 ? $accountId : null);
        View::render('sales/integrity', compact('accountId', 'summary', 'packs'));
    }

    public function integrityShow(): void
    {
        $this->show();
    }

    public function reconcile(): void
    {
        Auth::requireRole('admin', 'operador');
        Csrf::validate($_POST['_token'] ?? null);
        $accountId = max(0, (int) ($_POST['account_id'] ?? 0));
        try {
            (new HistoricalPackReconciliationService())->enqueueRebuild(
                $accountId > 0 ? $accountId : null,
                Auth::id()
            );
            Session::flash('success', 'La reconstrucción histórica quedó preparada. El lanzador la ejecutará sin consultar Mercado Libre durante la primera etapa.');
        } catch (\Throwable $error) {
            Session::flash('error', \App\Services\SafeErrorPresenter::message(
                $error,
                'No fue posible preparar la reconstrucción histórica.'
            ));
        }
        $this->redirect('/sales/integrity' . ($accountId > 0 ? '?account_id=' . $accountId : ''));
    }

    public function queueFinancial(): void
    {
        Auth::requireRole('admin', 'operador');
        Csrf::validate($_POST['_token'] ?? null);
        $accountId = max(0, (int) ($_POST['account_id'] ?? 0));
        $saleId = trim((string) ($_POST['sale_id'] ?? ''));
        try {
            $sale = (new SaleReadService())->show($accountId, $saleId);
            $completeness = (array) ($sale['financial_completeness'] ?? []);
            if ((string) ($completeness['official'] ?? '') === 'complete') {
                Session::flash('success', 'La versión vigente ya tiene información financiera oficial.');
            } else {
                $jobId = (new SaleFinancialService())->queue($accountId, $saleId, Auth::id());
                Session::flash(
                    'success',
                    'Se preparó un único paso financiero exacto (#' . $jobId . ') para el lanzador CLI.'
                );
            }
        } catch (\Throwable $error) {
            Session::flash('error', \App\Services\SafeErrorPresenter::message(
                $error,
                'No fue posible preparar la conciliación financiera.'
            ));
        }
        $this->redirect('/sales/show?' . http_build_query(['account_id' => $accountId, 'sale_id' => $saleId]));
    }

    private function redirect(string $path): never
    {
        header('Location: ' . rtrim(Env::get('APP_URL', ''), '/') . $path);
        exit;
    }
}
