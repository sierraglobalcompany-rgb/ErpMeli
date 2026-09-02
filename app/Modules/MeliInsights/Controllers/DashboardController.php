<?php

declare(strict_types=1);

namespace App\Modules\MeliInsights\Controllers;

use App\Core\Auth;
use App\Core\Csrf;
use App\Core\Env;
use App\Core\Modules\ModuleJobRunner;
use App\Core\Modules\ModuleView;
use App\Core\Session;
use App\Modules\MeliInsights\Services\InsightsDashboardService;
use App\Services\BusinessScopeContext;

final class DashboardController
{
    public function index(): void
    {
        $this->show('performance');
    }

    public function performance(): void
    {
        $this->show('performance');
    }

    public function pricing(): void
    {
        $this->show('pricing');
    }

    public function moderations(): void
    {
        $this->show('moderations');
    }

    public function capabilities(): void
    {
        Auth::requireRole('admin');
        $this->show('capabilities', true);
    }

    public function refresh(): void
    {
        Auth::requireRole('admin', 'operator');
        Csrf::validate($_POST['_token'] ?? null);
        throw new \App\Core\HttpException(410, 'La automatización de módulos está retirada. No se creó ningún trabajo.');

        $accountId = filter_input(INPUT_POST, 'meli_account_id', FILTER_VALIDATE_INT);
        $page = in_array((string) ($_POST['page'] ?? ''), ['performance', 'pricing', 'moderations', 'capabilities'], true)
            ? (string) $_POST['page']
            : 'performance';
        if (!is_int($accountId) || $accountId <= 0) {
            throw new \App\Core\HttpException(404, 'No se encontró la cuenta solicitada.');
        }
        $account = (new BusinessScopeContext())->account($accountId);
        $jobId = (new ModuleJobRunner())->enqueueScoped(
            'meli-insights',
            'snapshot_sync',
            (int) $account['company_id'],
            (int) $account['id'],
            ['requested_by' => Auth::id(), 'page' => $page],
            80
        );
        Session::flash('success', 'Actualización programada. Trabajo #' . $jobId . '. Si ya existía uno equivalente, se conservará su progreso.');
        $this->redirect('/meli-insights/' . $page);
    }

    public function jobAction(): void
    {
        Auth::requireRole('admin', 'operator');
        Csrf::validate($_POST['_token'] ?? null);
        $jobId = filter_input(INPUT_POST, 'job_id', FILTER_VALIDATE_INT);
        $action = (string) ($_POST['action'] ?? '');
        if (in_array($action, ['resume', 'retry'], true)) {
            throw new \App\Core\HttpException(410, 'La reactivación de trabajos modulares está retirada.');
        }
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
        $page = in_array((string) ($_POST['page'] ?? ''), ['performance', 'pricing', 'moderations', 'capabilities'], true)
            ? (string) $_POST['page']
            : 'performance';
        $this->redirect('/meli-insights/' . $page);
    }

    private function show(string $page, bool $authorized = false): void
    {
        if (!$authorized) {
            Auth::requireRole('admin', 'operator', 'consulta');
        }
        $csrfToken = Csrf::token();
        Session::closeReadOnly();
        ModuleView::render(
            'meli-insights',
            'index',
            (new InsightsDashboardService())->page($page, $_GET) + ['csrfToken' => $csrfToken]
        );
    }

    private function redirect(string $path): never
    {
        header('Location: ' . rtrim(Env::get('APP_URL', ''), '/') . $path);
        exit;
    }
}
