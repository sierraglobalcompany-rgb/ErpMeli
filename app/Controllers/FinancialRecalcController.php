<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Csrf;
use App\Core\Env;
use App\Core\HttpException;
use App\Core\Session;
use App\Core\View;
use App\Services\OrderFinancialRecalcJobService;
use App\Services\AsyncSectionService;
use App\Services\BusinessScopeContext;
use App\Services\SafeErrorPresenter;
use Throwable;

final class FinancialRecalcController
{
    public function index(): void
    {
        Auth::requireLogin();
        $accountId = (int) ($_GET['account_id'] ?? 0);
        $this->assertOptionalAccount($accountId);
        if ((string) ($_GET['full'] ?? '') !== '1') {
            View::render('financial_recalc/index', [
                'accountId' => $accountId,
                'summary' => [],
                'jobs' => [],
                'progressive' => true,
            ]);
            return;
        }
        View::render('financial_recalc/index', $this->indexData($accountId));
    }

    public function section(): void
    {
        Auth::requireLogin();
        $accountId = (int) ($_GET['account_id'] ?? 0);
        $this->assertOptionalAccount($accountId);
        $async = new AsyncSectionService();
        $async->releaseSession();
        $startedAt = microtime(true);
        try {
            $async->render('financial-recalc', 'financial_recalc/index', $this->indexData($accountId), [], $startedAt);
        } catch (Throwable $error) {
            $async->failure('financial-recalc', $error);
        }
    }

    /** @return array<string,mixed> */
    private function indexData(int $accountId): array
    {
        $service = new OrderFinancialRecalcJobService();
        $summary = $service->summary($accountId);
        $jobs = $service->recent($accountId, 25);
        return compact('accountId', 'summary', 'jobs');
    }

    public function show(): void
    {
        Auth::requireLogin();
        $id = (int) ($_GET['id'] ?? 0);
        $service = new OrderFinancialRecalcJobService();
        $job = $service->find($id);
        if (!$job) {
            http_response_code(404);
            View::render('errors/404', [], false);
            return;
        }
        $status = (string) ($_GET['status'] ?? '');
        $items = $service->items($id, $status, 150);
        $errors = $service->errors($id, 0, 50);
        View::render('financial_recalc/show', compact('job', 'items', 'errors', 'status'));
    }

    public function statusJson(): void
    {
        Auth::requireLogin();
        $accountId = (int) ($_GET['account_id'] ?? 0);
        $this->assertOptionalAccount($accountId);
        $service = new OrderFinancialRecalcJobService();
        $this->json(['ok' => true, 'summary' => $service->summary($accountId), 'jobs' => $service->recent($accountId, 8)]);
    }

    public function processNow(): void
    {
        Auth::requireRole('admin', 'operador');
        Csrf::validate($_POST['_token'] ?? null);
        Session::flash('info', 'Esta ruta no ejecutó el recálculo. En Procesar ahora puede confirmar un paso exacto, limitado por llamadas API y sin continuación en segundo plano.');
        $this->redirect('/settings/manual-processing?scope=finance&origin=financial_recalc');
    }

    public function assistedStep(): void
    {
        Auth::requireRole('admin', 'operador');
        Csrf::validate($_POST['_token'] ?? null);
        $this->json([
            'ok' => false,
            'retired' => true,
            'redirect' => '/settings/manual-processing?scope=finance&origin=legacy_assisted',
            'message' => 'El ejecutor financiero web fue retirado. Use Procesar ahora.',
        ], 410);
    }

    public function retryFailed(): void
    {
        Auth::requireRole('admin', 'operador');
        Csrf::validate($_POST['_token'] ?? null);
        $accountId = (int) ($_POST['account_id'] ?? 0);
        $jobId = (int) ($_POST['job_id'] ?? 0);
        try {
            if ($jobId <= 0) {
                throw new HttpException(400, 'Seleccione un trabajo financiero exacto para reintentar.');
            }
            $count = (new OrderFinancialRecalcJobService())->retryFailed($jobId, $accountId);
            $message = $count > 0 ? 'Órdenes financieras fallidas reencoladas: ' . $count . '.' : 'No había errores financieros para reintentar.';
            if ($this->wantsJson()) {
                $this->json(['ok' => true, 'message' => $message, 'summary' => (new OrderFinancialRecalcJobService())->summary($accountId)]);
            }
            Session::flash('success', $message);
        } catch (Throwable $e) {
            if ($this->wantsJson()) {
                $this->json(['ok' => false, 'message' => SafeErrorPresenter::message(
                    $e,
                    'No fue posible reintentar los recálculos fallidos.'
                )], 500);
            }
            Session::flash('error', \App\Services\SafeErrorPresenter::message($e));
        }
        $this->redirect($jobId > 0 ? '/financial-recalc/show?id=' . $jobId : '/financial-recalc?account_id=' . $accountId);
    }

    public function cancel(): void
    {
        Auth::requireRole('admin', 'operador');
        Csrf::validate($_POST['_token'] ?? null);
        $jobId = (int) ($_POST['job_id'] ?? 0);
        try {
            (new OrderFinancialRecalcJobService())->cancel($jobId, (int) Auth::id());
            Session::flash('success', 'Job financiero cancelado. No se borró el historial.');
        } catch (Throwable $e) {
            Session::flash('error', \App\Services\SafeErrorPresenter::message($e));
        }
        $this->redirect('/financial-recalc/show?id=' . $jobId);
    }

    public function errors(): void
    {
        Auth::requireLogin();
        $jobId = (int) ($_GET['job_id'] ?? 0);
        $accountId = (int) ($_GET['account_id'] ?? 0);
        $errors = (new OrderFinancialRecalcJobService())->errors($jobId, $accountId, 300);
        View::render('financial_recalc/errors', compact('errors', 'jobId', 'accountId'));
    }

    private function wantsJson(): bool
    {
        return str_contains((string) ($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json');
    }

    private function assertOptionalAccount(int $accountId): void
    {
        if ($accountId > 0) {
            (new BusinessScopeContext())->account($accountId, 0, (int) Auth::id());
        }
    }

    private function json(array $payload, int $status = 200): never
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($payload, JSON_UNESCAPED_UNICODE);
        exit;
    }

    private function redirect(string $path): never
    {
        header('Location: ' . rtrim(Env::get('APP_URL', ''), '/') . $path);
        exit;
    }
}
