<?php

declare(strict_types=1);

namespace App\Modules\MeliGrowth\Controllers;

use App\Core\Auth;
use App\Core\Csrf;
use App\Core\Env;
use App\Core\Modules\ModuleJobRunner;
use App\Core\Modules\ModuleView;
use App\Core\Session;
use App\Modules\MeliGrowth\Services\GrowthDashboardService;
use App\Services\BusinessScopeContext;

final class DashboardController
{
    public function index(): void
    {
        $this->show('promotions');
    }

    public function promotions(): void
    {
        $this->show('promotions');
    }

    public function performance(): void
    {
        $this->show('performance');
    }

    public function trends(): void
    {
        $this->show('trends');
    }

    public function refresh(): void
    {
        Auth::requireRole('admin', 'operator');
        Csrf::validate($_POST['_token'] ?? null);
        $accountId = filter_input(INPUT_POST, 'meli_account_id', FILTER_VALIDATE_INT);
        $page = in_array((string) ($_POST['page'] ?? ''), ['promotions', 'performance', 'trends'], true)
            ? (string) $_POST['page']
            : 'promotions';
        if (!is_int($accountId) || $accountId <= 0) {
            throw new \App\Core\HttpException(404, 'No se encontró la cuenta solicitada.');
        }
        $account = (new BusinessScopeContext())->account($accountId);
        $jobId = (new ModuleJobRunner())->enqueueScoped(
            'meli-growth',
            'snapshot_sync',
            (int) $account['company_id'],
            (int) $account['id'],
            ['requested_by' => Auth::id(), 'page' => $page],
            90
        );
        Session::flash('success', 'Actualización programada como trabajo #' . $jobId . '. El cron conservará el progreso por etapas.');
        $this->redirect('/meli-growth/' . $page);
    }

    public function jobAction(): void
    {
        Auth::requireRole('admin', 'operator');
        Csrf::validate($_POST['_token'] ?? null);
        $jobId = filter_input(INPUT_POST, 'job_id', FILTER_VALIDATE_INT);
        $action = (string) ($_POST['action'] ?? '');
        $runner = new ModuleJobRunner();
        $accountId = filter_input(INPUT_POST, 'meli_account_id', FILTER_VALIDATE_INT);
        if (!is_int($accountId) || $accountId <= 0) {
            throw new \App\Core\HttpException(404, 'No se encontró la cuenta solicitada.');
        }
        $account = (new BusinessScopeContext())->account($accountId);
        $changed = is_int($jobId) && $jobId > 0 && match ($action) {
            'pause' => $runner->pauseScoped($jobId, (int) $account['company_id'], (int) $account['id']),
            'resume' => $runner->resumeScoped($jobId, (int) $account['company_id'], (int) $account['id']),
            'cancel' => $runner->cancelScoped($jobId, (int) $account['company_id'], (int) $account['id']),
            'retry' => $runner->retryScoped($jobId, (int) $account['company_id'], (int) $account['id']),
            default => false,
        };
        Session::flash($changed ? 'success' : 'error', $changed ? 'Estado del trabajo actualizado.' : 'El trabajo ya no admite esa acción.');
        $this->redirect('/meli-growth/' . $this->page((string) ($_POST['page'] ?? '')));
    }

    private function show(string $page): void
    {
        Auth::requireRole('admin', 'operator', 'consulta');
        $csrfToken = Csrf::token();
        Session::closeReadOnly();
        ModuleView::render('meli-growth', 'index', (new GrowthDashboardService())->page($page, $_GET) + ['csrfToken' => $csrfToken]);
    }

    private function page(string $page): string
    {
        return in_array($page, ['promotions', 'performance', 'trends'], true) ? $page : 'promotions';
    }

    private function redirect(string $path): never
    {
        header('Location: ' . rtrim(Env::get('APP_URL', ''), '/') . $path);
        exit;
    }
}
