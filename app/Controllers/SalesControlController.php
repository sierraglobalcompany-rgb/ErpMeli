<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Csrf;
use App\Core\Env;
use App\Core\Session;
use App\Core\View;
use App\Services\SafeErrorPresenter;
use App\Services\SalesAuditExactRepairService;
use App\Services\SalesControlService;
use App\Services\SalesFiscalPreparationService;
use App\Services\ReadModelCacheService;
use App\Services\AsyncSectionService;
use Throwable;

final class SalesControlController
{
    public function index(): void
    {
        Auth::requireLogin();
        $accountId = (int) ($_GET['account_id'] ?? 0);
        $year = (int) ($_GET['year'] ?? date('Y'));
        if ((string) ($_GET['full'] ?? '') !== '1') {
            View::render('sales_control/index', [
                'accounts' => [],
                'accountId' => $accountId,
                'year' => $year,
                'overview' => null,
                'schemaStatus' => ['ready' => false, 'base_ready' => false, 'missing' => [], 'message' => 'Comprobando la instalación.'],
                'progressive' => true,
            ]);
            return;
        }
        View::render('sales_control/index', $this->overviewData($accountId, $year));
    }

    public function overviewSection(): void
    {
        Auth::requireLogin();
        $accountId = (int) ($_GET['account_id'] ?? 0);
        $year = (int) ($_GET['year'] ?? date('Y'));
        $async = new AsyncSectionService();
        $async->releaseSession();
        $startedAt = microtime(true);
        try {
            $async->render('sales-control-overview', 'sales_control/index', $this->overviewData($accountId, $year), [], $startedAt);
        } catch (Throwable $error) {
            $async->failure('sales-control-overview', $error);
        }
    }

    /** @return array<string,mixed> */
    private function overviewData(int $requestedAccountId, int $year): array
    {
        $service = new SalesControlService();
        $schemaStatus = $service->schemaStatus();
        $accounts = $schemaStatus['base_ready'] ? $service->accounts() : [];
        $accountId = $requestedAccountId > 0 ? $requestedAccountId : (int) ($accounts[0]['id'] ?? 0);
        $overview = null;
        if ($accountId > 0 && $schemaStatus['ready']) {
            $cached = (new ReadModelCacheService())->rememberArray(
                'sales-control-overview',
                implode(':', [(int) Auth::id(), $accountId, $year]),
                20,
                static fn(): array => $service->overview($accountId, $year)
            );
            $overview = $cached['value'];
        }
        return compact('accounts', 'accountId', 'year', 'overview', 'schemaStatus');
    }

    public function month(): void
    {
        Auth::requireLogin();
        $service = new SalesControlService();
        if (!$service->available()) {
            Session::flash('warning', 'Control de ventas necesita completar la actualización de la base de datos.');
            $this->redirect('/sales-control');
        }
        $accounts = $service->accounts();
        $accountId = (int) ($_GET['account_id'] ?? ($accounts[0]['id'] ?? 0));
        if ($accountId <= 0) {
            Session::flash('warning', 'Primero conecte una cuenta de Mercado Libre.');
            $this->redirect('/sales-control');
        }
        $year = (int) ($_GET['year'] ?? date('Y'));
        $month = (int) ($_GET['month'] ?? date('n'));
        $page = max(1, (int) ($_GET['page'] ?? 1));
        $perPage = in_array((int) ($_GET['per_page'] ?? 50), [25, 50, 100], true)
            ? (int) $_GET['per_page'] : 50;
        $classification = trim((string) ($_GET['classification'] ?? ''));
        $data = $service->month(
            $accountId, $year, $month, 0, $page, $perPage, $classification ?: null
        );
        View::render('sales_control/month', $data);
    }

    public function issues(): void
    {
        $this->monthView('sales_control/issues');
    }

    public function fiscal(): void
    {
        $this->monthView('sales_control/fiscal');
    }

    public function closes(): void
    {
        Auth::requireLogin();
        $service = new SalesControlService();
        if (!$service->available()) {
            Session::flash('warning', 'Control de ventas necesita completar la actualización de la base de datos.');
            $this->redirect('/sales-control');
        }
        $accounts = $service->accounts();
        $accountId = (int) ($_GET['account_id'] ?? ($accounts[0]['id'] ?? 0));
        $year = (int) ($_GET['year'] ?? date('Y'));
        $closes = $accountId > 0 ? $service->closes($accountId, $year) : [];
        View::render('sales_control/closes', compact('accounts', 'accountId', 'year', 'closes'));
    }

    public function checkYear(): void
    {
        $this->command(function (): string {
            $result = (new SalesControlService())->checkYear(
                (int) $_POST['account_id'], (int) $_POST['year'], 0, (int) Auth::id()
            );
            Session::flash('success', $result['created'] . ' comprobaciones mensuales quedaron preparadas para Automatización.');
            return '/sales-control?account_id=' . (int) $_POST['account_id'] . '&year=' . (int) $_POST['year'];
        }, 'No fue posible preparar la comprobación anual.');
    }

    public function checkMonth(): void
    {
        $this->command(function (): string {
            (new SalesControlService())->checkMonth(
                (int) $_POST['account_id'], (int) $_POST['year'], (int) $_POST['month'], 0, (int) Auth::id()
            );
            Session::flash('success', 'La comprobación quedó en espera. Automatización la realizará sin modificar Mercado Libre.');
            return $this->monthUrl();
        }, 'No fue posible preparar la comprobación del mes.');
    }

    public function repairMissing(): void
    {
        $this->command(function (): string {
            $runId = (int) ($_POST['run_id'] ?? 0);
            $jobId = (new SalesAuditExactRepairService())->createFromRun(
                $runId,
                (int) Auth::id(),
                (int) ($_POST['company_id'] ?? 0),
                (int) ($_POST['account_id'] ?? 0)
            );
            Session::flash('success', 'Las ventas faltantes quedaron preparadas para descarga segura.');
            return '/sync/audit/repair?job_id=' . $jobId;
        }, 'No fue posible preparar la descarga de ventas faltantes.');
    }

    public function prepareFiscal(): void
    {
        $this->command(function (): string {
            $jobId = (new SalesFiscalPreparationService())->create(
                (int) $_POST['account_id'], (int) $_POST['year'], (int) $_POST['month'],
                (int) $_POST['company_id'], (int) Auth::id()
            );
            Session::flash('success', 'La preparación fiscal quedó en espera. Los datos sensibles se guardarán cifrados.');
            return '/settings/cron/queue?type=sales_fiscal&source_id=' . $jobId;
        }, 'No fue posible preparar los datos fiscales.');
    }

    public function close(): void
    {
        Auth::requireRole('admin');
        $this->requirePermanent();
        $this->command(function (): string {
            (new SalesControlService())->closeMonth(
                (int) $_POST['account_id'], (int) $_POST['year'], (int) $_POST['month'],
                (int) $_POST['company_id'], (int) Auth::id()
            );
            Session::flash('success', 'Mes cerrado. La evidencia quedó protegida y no será sobrescrita.');
            return $this->monthUrl();
        }, 'No fue posible cerrar el mes.');
    }

    public function reopen(): void
    {
        Auth::requireRole('admin');
        $this->requirePermanent();
        $this->command(function (): string {
            (new SalesControlService())->reopenMonth(
                (int) $_POST['account_id'], (int) $_POST['year'], (int) $_POST['month'],
                (int) $_POST['company_id'], (int) Auth::id(), (string) ($_POST['reason'] ?? '')
            );
            Session::flash('success', 'El cierre anterior se conservó y se abrió una nueva revisión.');
            return $this->monthUrl();
        }, 'No fue posible reabrir el mes.');
    }

    public function recalculateDates(): void
    {
        $this->command(function (): string {
            Session::flash('warning', 'La corrección de fechas se conserva en Automatización. Revise primero las diferencias exactas.');
            return $this->monthUrl();
        }, 'No fue posible revisar las fechas.');
    }

    private function monthView(string $view): void
    {
        Auth::requireLogin();
        $service = new SalesControlService();
        if (!$service->available()) {
            Session::flash('warning', 'Control de ventas necesita completar la actualización de la base de datos.');
            $this->redirect('/sales-control');
        }
        $accounts = $service->accounts();
        $accountId = (int) ($_GET['account_id'] ?? ($accounts[0]['id'] ?? 0));
        if ($accountId <= 0) {
            Session::flash('warning', 'Primero conecte una cuenta de Mercado Libre.');
            $this->redirect('/sales-control');
        }
        $year = (int) ($_GET['year'] ?? date('Y'));
        $month = (int) ($_GET['month'] ?? date('n'));
        $page = max(1, (int) ($_GET['page'] ?? 1));
        $perPage = in_array((int) ($_GET['per_page'] ?? 50), [25, 50, 100], true)
            ? (int) $_GET['per_page'] : 50;
        $classification = trim((string) ($_GET['classification'] ?? ''));
        $data = $service->month(
            $accountId, $year, $month, 0, $page, $perPage, $classification ?: null
        );
        View::render($view, $data);
    }

    private function command(callable $callback, string $fallback): never
    {
        Auth::requireRole('admin', 'operador');
        Csrf::validate($_POST['_token'] ?? null);
        try {
            $destination = (string) $callback();
            (new ReadModelCacheService())->clear();
            $this->redirect($destination);
        } catch (Throwable $error) {
            Session::flash('error', SafeErrorPresenter::message($error, $fallback));
            $this->redirect($this->monthUrl('/sales-control'));
        }
    }

    private function monthUrl(string $fallback = '/sales-control/month'): string
    {
        $accountId = (int) ($_POST['account_id'] ?? $_GET['account_id'] ?? 0);
        $year = (int) ($_POST['year'] ?? $_GET['year'] ?? date('Y'));
        $month = (int) ($_POST['month'] ?? $_GET['month'] ?? date('n'));
        return $fallback . '?account_id=' . $accountId . '&year=' . $year . '&month=' . $month;
    }

    private function requirePermanent(): void
    {
        if (Auth::isTemporary()) {
            throw new \App\Core\HttpException(403, 'Esta acción requiere una cuenta administrativa permanente.');
        }
    }

    private function redirect(string $path): never
    {
        header('Location: ' . rtrim(Env::get('APP_URL', ''), '/') . $path);
        exit;
    }
}
