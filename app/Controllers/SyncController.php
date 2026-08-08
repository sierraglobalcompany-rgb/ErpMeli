<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Csrf;
use App\Core\Env;
use App\Core\Session;
use App\Core\View;
use App\Services\ApiGuardService;
use App\Services\BusinessScopeContext;
use App\Services\CronHealthService;
use App\Services\DateTimePresenter;
use App\Services\OrderFinancialRecalcJobService;
use App\Services\RecurringSyncService;
use App\Services\SalesAuditExactRepairService;
use App\Services\SalesAuditRunService;
use App\Services\SalesAuditService;
use App\Services\SalesControlService;
use App\Services\SyncCenterService;
use App\Services\SyncDiagnosticService;
use App\Services\SyncQueueService;
use App\Services\SyncSettingsService;
use App\Services\SystemSafetyStatusService;
use App\Services\AsyncSectionService;
use DateTimeImmutable;
use DateTimeZone;

final class SyncController
{
    public function index(): void
    {
        Auth::requireLogin();
        $accountId = (int) ($_GET['account_id'] ?? 0);
        $year = (int) ($_GET['year'] ?? date('Y'));
        if ((string) ($_GET['full'] ?? '') !== '1') {
            View::render('sync/index', [
                'accountId' => $accountId,
                'year' => $year,
                'progressive' => true,
            ]);
            return;
        }
        View::render('sync/index', $this->indexData($accountId, $year));
    }

    public function section(): void
    {
        Auth::requireLogin();
        $accountId = (int) ($_GET['account_id'] ?? 0);
        $year = (int) ($_GET['year'] ?? date('Y'));
        $async = new AsyncSectionService();
        $async->releaseSession();
        $startedAt = microtime(true);
        try {
            $async->render('sync-overview', 'sync/index', $this->indexData($accountId, $year), [], $startedAt);
        } catch (\Throwable $error) {
            $async->failure('sync-overview', $error);
        }
    }

    /** @return array<string,mixed> */
    private function indexData(int $requestedAccountId, int $year): array
    {
        $service = new SyncCenterService();
        $accounts = $service->accounts();
        $accountId = $requestedAccountId > 0 ? $requestedAccountId : (int) ($accounts[0]['id'] ?? 0);
        $months = $accountId > 0 ? $service->yearOverview($accountId, $year) : [];
        $settings = new SyncSettingsService();
        $runs = $service->recentRuns($accountId);
        $globalStatus = $service->globalStatus($accountId);
        $overdue = $service->overdueSchedule($accountId, 10);
        $safety = (new SystemSafetyStatusService())->status();
        $cron = ($safety['api'] === 'stopped' || $safety['automation'] === 'stopped')
            ? [
                'state' => 'maintenance',
                'label' => 'Mantenimiento preventivo',
                'message' => 'Las consultas a Mercado Libre permanecen bloqueadas y las colas conservan su progreso.',
            ]
            : (new CronHealthService())->status();
        $financialQueueService = new OrderFinancialRecalcJobService();
        $financialQueue = $financialQueueService->summary($accountId);
        $financialJobs = $financialQueueService->recent($accountId, 5);
        return compact('accounts', 'accountId', 'year', 'months', 'settings', 'runs', 'globalStatus', 'overdue', 'cron', 'financialQueue', 'financialJobs');
    }

    public function account(): void
    {
        Auth::requireLogin();
        $service = new SyncCenterService();
        $accounts = $service->accounts();
        $accountId = (int) ($_GET['id'] ?? $_GET['account_id'] ?? 0);
        $year = (int) ($_GET['year'] ?? date('Y'));
        $month = (int) ($_GET['month'] ?? date('n'));
        $months = $accountId > 0 ? $service->yearOverview($accountId, $year) : [];
        $chunks = $accountId > 0 ? $service->chunks($accountId, $year, $month) : [];
        $batch = $accountId > 0 ? $service->batchForMonth($accountId, $year, $month) : null;
        $status = $accountId > 0 ? $service->monthStatus($accountId, $year, $month) : [];
        $diagnostic = $accountId > 0 ? (new SyncDiagnosticService())->latest($accountId, $year, $month) : null;
        $settings = new SyncSettingsService();
        View::render('sync/account', compact('accounts', 'accountId', 'year', 'month', 'months', 'chunks', 'batch', 'status', 'diagnostic', 'settings'));
    }

    public function plan(): void
    {
        Auth::requireRole('admin', 'operador');
        Csrf::validate($_POST['_token'] ?? null);
        try {
            $accountId = (int) ($_POST['account_id'] ?? 0);
            $year = (int) ($_POST['year'] ?? date('Y'));
            $month = (int) ($_POST['month'] ?? date('n'));
            $mode = (string) ($_POST['chunk_mode'] ?? 'daily');
            $parts = (int) ($_POST['chunk_parts'] ?? 0);
            [$shouldEnqueue, $runAt, $scheduleLabel] = $this->scheduleFromPost('none');
            $batchId = (new SyncCenterService())->planMonth($accountId, $year, $month, $mode, $parts);
            if ($shouldEnqueue) {
                (new SyncCenterService())->enqueueMonth($batchId, $runAt);
                Session::flash('success', 'Mes dividido y encolado. Próxima ejecución: ' . $scheduleLabel . ' ' . DateTimePresenter::timezone() . '.');
            } else {
                Session::flash('success', 'Mes dividido en bloques. Quedó sin encolar para programarlo cuando lo necesite.');
            }
            $this->redirect('/sync/account?id=' . $accountId . '&year=' . $year . '&month=' . $month);
        } catch (\Throwable $e) {
            Session::flash('error', \App\Services\SafeErrorPresenter::message($e));
            $this->redirect('/sync');
        }
    }

    public function enqueueChunk(): void
    {
        Auth::requireRole('admin', 'operador');
        Csrf::validate($_POST['_token'] ?? null);
        try {
            [, $runAt, $scheduleLabel] = $this->scheduleFromPost('now');
            (new SyncCenterService())->enqueueChunk((int) ($_POST['chunk_id'] ?? 0), $runAt);
            Session::flash('success', 'Bloque encolado. Próxima ejecución: ' . $scheduleLabel . ' ' . DateTimePresenter::timezone() . '.');
        } catch (\Throwable $e) {
            Session::flash('error', \App\Services\SafeErrorPresenter::message($e));
        }
        $this->redirectBackToAccount();
    }

    public function retryChunk(): void
    {
        $this->enqueueChunk();
    }

    public function rescheduleChunk(): void
    {
        Auth::requireRole('admin', 'operador');
        Csrf::validate($_POST['_token'] ?? null);
        try {
            [, $runAt, $scheduleLabel] = $this->scheduleFromPost('delay');
            if (!$runAt) {
                throw new \RuntimeException('Seleccione cuándo ejecutar el bloque.');
            }
            (new SyncCenterService())->rescheduleChunk((int) ($_POST['chunk_id'] ?? 0), $runAt);
            Session::flash('success', 'Bloque reprogramado para ' . $scheduleLabel . ' ' . DateTimePresenter::timezone() . '.');
        } catch (\Throwable $e) {
            Session::flash('error', \App\Services\SafeErrorPresenter::message($e));
        }
        if (isset($_POST['return_to_schedule'])) {
            $this->redirect('/sync/schedule?account_id=' . (int) ($_POST['account_id'] ?? 0));
        }
        $this->redirectBackToAccount();
    }

    public function rescheduleOverdue(): void
    {
        Auth::requireRole('admin', 'operador');
        Csrf::validate($_POST['_token'] ?? null);
        // The legacy endpoint mutated up to 500 rows from one browser POST.
        // Web requests may only authorize an exact resource; bulk recovery is
        // performed by the single CLI orchestrator with leases and fencing.
        Session::flash(
            'info',
            'La reprogramación masiva desde el navegador fue retirada. Abra un bloque concreto para reprogramarlo; el lanzador único recuperará los demás de forma segura.'
        );
        if (isset($_POST['return_to_schedule'])) {
            $this->redirect('/sync/schedule?account_id=' . (int) ($_POST['account_id'] ?? 0));
        }
        $this->redirect('/sync?account_id=' . (int) ($_POST['account_id'] ?? 0) . '&year=' . (int) ($_POST['year'] ?? date('Y')));
    }

    public function cancelChunk(): void
    {
        $this->manageChunk(static fn(SyncCenterService $service, int $id) => $service->cancelChunk($id), 'Bloque cancelado.');
    }

    public function reactivateChunk(): void
    {
        $this->manageChunk(static fn(SyncCenterService $service, int $id) => $service->reactivateChunk($id), 'Bloque reactivado.');
    }

    public function deleteChunk(): void
    {
        $this->manageChunk(static fn(SyncCenterService $service, int $id) => $service->deleteChunk($id), 'Bloque eliminado.');
    }

    public function deleteBatch(): void
    {
        Auth::requireRole('admin', 'operador');
        Csrf::validate($_POST['_token'] ?? null);
        try {
            (new SyncCenterService())->deleteBatch((int) ($_POST['batch_id'] ?? 0));
            Session::flash('success', 'Plan del mes eliminado.');
        } catch (\Throwable $e) {
            Session::flash('error', \App\Services\SafeErrorPresenter::message($e));
        }
        $accountId = (int) ($_POST['account_id'] ?? 0);
        $year = (int) ($_POST['year'] ?? date('Y'));
        $this->redirect('/sync?account_id=' . $accountId . '&year=' . $year);
    }

    public function processNow(): void
    {
        // Contrato heredado retirado en 2.19.4:
        // processOne((int) ($_POST['chunk_id'] ?? 0) ?: null, (int) ($_POST['account_id'] ?? 0))
        Auth::requireRole('admin', 'operador');
        Csrf::validate($_POST['_token'] ?? null);
        Session::flash('info', 'El procesamiento desde el navegador fue trasladado al Centro seguro.');
        $query = http_build_query(array_filter([
            'scope' => 'sales',
            'origin' => 'legacy_process_now',
            'account_id' => max(0, (int) ($_POST['account_id'] ?? 0)) ?: null,
            'year' => max(0, (int) ($_POST['year'] ?? 0)) ?: null,
            'month' => max(0, (int) ($_POST['month'] ?? 0)) ?: null,
        ], static fn (mixed $value): bool => $value !== null));
        $this->redirect('/settings/manual-processing?' . $query);
    }

    public function processNowJson(): void
    {
        Auth::requireRole('admin', 'operador');
        Csrf::validate($_POST['_token'] ?? null);
        header('Content-Type: application/json; charset=UTF-8');
        $query = http_build_query(array_filter([
            'scope' => 'sales',
            'origin' => 'legacy_process_now',
            'account_id' => max(0, (int) ($_POST['account_id'] ?? 0)) ?: null,
        ], static fn (mixed $value): bool => $value !== null));
        echo json_encode([
            'ok' => true,
            'redirect' => '/settings/manual-processing?' . $query,
            'message' => 'Abra el Centro de procesamiento manual para simular y ejecutar esta cola con seguridad.',
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    public function status(): void
    {
        Auth::requireLogin();
        $service = new SyncCenterService();
        $accountId = (int) ($_GET['account_id'] ?? $_GET['id'] ?? 0);
        $year = (int) ($_GET['year'] ?? date('Y'));
        $month = (int) ($_GET['month'] ?? date('n'));
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode($service->monthStatus($accountId, $year, $month), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    public function assistedStepJson(): void
    {
        Auth::requireRole('admin', 'operador');
        Csrf::validate($_POST['_token'] ?? null);
        http_response_code(410);
        header('Content-Type: application/json; charset=UTF-8');
        $query = http_build_query(array_filter([
            'scope' => 'sales',
            'origin' => 'legacy_process_now',
            'account_id' => max(0, (int) ($_POST['account_id'] ?? 0)) ?: null,
        ], static fn (mixed $value): bool => $value !== null));
        echo json_encode([
            'ok' => false,
            'retired' => true,
            'redirect' => '/settings/manual-processing?' . $query,
            'message' => 'El modo asistido anterior fue reemplazado por el Centro seguro.',
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    public function assistedResume(): void
    {
        Auth::requireRole('admin', 'operador');
        Csrf::validate($_POST['_token'] ?? null);
        $accountId = (int) ($_POST['account_id'] ?? 0);
        Session::flash('info', 'Revise los pendientes de esta cuenta antes de crear la campaña.');
        $this->redirect('/settings/manual-processing?' . http_build_query([
            'scope' => 'sales',
            'origin' => 'legacy_resume',
            'account_id' => max(0, $accountId),
        ]));
    }

    public function assistedRetryFailed(): void
    {
        Auth::requireRole('admin', 'operador');
        Csrf::validate($_POST['_token'] ?? null);
        $accountId = (int) ($_POST['account_id'] ?? 0);
        Session::flash('info', 'Los errores se revisarán antes de reintentarlos para evitar duplicar trabajo.');
        $this->redirect('/settings/manual-processing?' . http_build_query([
            'scope' => 'sales',
            'origin' => 'legacy_retry',
            'account_id' => max(0, $accountId),
        ]));
    }

    public function schedule(): void
    {
        Auth::requireLogin();
        $accountId = (int) ($_GET['account_id'] ?? 0);
        $query = http_build_query(array_filter([
            'type' => 'orders_sync',
            'account_id' => $accountId > 0 ? $accountId : null,
        ], static fn ($value): bool => $value !== null));
        $this->redirect('/settings/cron/queue?' . $query);
    }

    public function scheduleJson(): void
    {
        Auth::requireLogin();
        $service = new SyncCenterService();
        $accountId = (int) ($_GET['account_id'] ?? 0);
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode([
            'timezone' => DateTimePresenter::timezone(),
            'status' => $service->globalStatus($accountId),
            'overdue' => array_map(static function (array $item): array {
                $item['next_run_at_local'] = DateTimePresenter::formatQueue($item['next_run_at'] ?? null);
                return $item;
            }, $service->overdueSchedule($accountId, 25)),
            'items' => array_map(static function (array $item): array {
                $item['next_run_at_local'] = DateTimePresenter::formatQueue($item['next_run_at'] ?? null);
                return $item;
            }, $service->upcomingSchedule($accountId, 50)),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    public function guardrails(): void
    {
        Auth::requireLogin();
        $circuits = (new ApiGuardService())->openCircuits();
        View::render('sync/guardrails', compact('circuits'));
    }

    public function diagnostics(): void
    {
        Auth::requireLogin();
        $accounts = (new SyncCenterService())->accounts();
        $accountId = (int) ($_GET['account_id'] ?? ($accounts[0]['id'] ?? 0));
        $year = (int) ($_GET['year'] ?? date('Y'));
        $month = (int) ($_GET['month'] ?? date('n'));
        $diagnostic = $accountId > 0 ? (new SyncDiagnosticService())->latest($accountId, $year, $month) : null;
        View::render('sync/diagnostics', compact('accounts', 'accountId', 'year', 'month', 'diagnostic'));
    }

    public function audit(): void
    {
        Auth::requireLogin();
        $accounts = (new SalesControlService())->accounts();
        $accountId = (int) ($_GET['account_id'] ?? ($accounts[0]['id'] ?? 0));
        $year = (int) ($_GET['year'] ?? date('Y'));
        $month = (int) ($_GET['month'] ?? date('n'));
        $classification = trim((string) ($_GET['classification'] ?? ''));
        $target = '/sales-control/month?account_id=' . $accountId
            . '&year=' . $year . '&month=' . $month;
        if ($classification !== '') {
            $target = '/sales-control/issues?account_id=' . $accountId
                . '&year=' . $year . '&month=' . $month
                . '&classification=' . rawurlencode($classification);
        }
        Session::flash('warning', 'La auditoría anterior ahora se consulta en Control de ventas. Los históricos permanecen protegidos.');
        $this->redirect($target);
    }

    public function runAudit(): void
    {
        Auth::requireRole('admin', 'operador');
        Csrf::validate($_POST['_token'] ?? null);
        $accountId = (int) ($_POST['account_id'] ?? 0);
        $year = (int) ($_POST['year'] ?? date('Y'));
        $month = (int) ($_POST['month'] ?? date('n'));
        try {
            $jobId = (new SalesAuditRunService())->createExactMonth(
                $accountId,
                $year,
                $month,
                (int) Auth::id()
            );
            $this->redirect('/sync/audit/run?job_id=' . $jobId);
        } catch (\Throwable $e) {
            Session::flash('error', \App\Services\SafeErrorPresenter::message(
                $e,
                'No fue posible crear la auditoría exacta.'
            ));
        }
        $this->redirect('/sync/audit?account_id=' . $accountId . '&year=' . $year . '&month=' . $month);
    }

    public function auditDay(): void
    {
        Auth::requireRole('admin', 'operador');
        Csrf::validate($_POST['_token'] ?? null);
        Session::flash('info', 'La auditoría diaria remota fue retirada. Cree una comprobación mensual verificable en Control de ventas.');
        $this->redirect('/sync/audit?account_id=' . (int) ($_POST['account_id'] ?? 0) . '&year=' . (int) ($_POST['year'] ?? date('Y')) . '&month=' . (int) ($_POST['month'] ?? date('n')));
    }

    public function auditEnqueueMissing(): void
    {
        Auth::requireRole('admin', 'operador');
        Csrf::validate($_POST['_token'] ?? null);
        try {
            (new SalesAuditService())->enqueueDay((int) ($_POST['day_id'] ?? 0));
            Session::flash('success', 'Día faltante encolado para sincronización.');
        } catch (\Throwable $e) {
            Session::flash('error', \App\Services\SafeErrorPresenter::message($e));
        }
        $this->redirect('/sync/audit?account_id=' . (int) ($_POST['account_id'] ?? 0) . '&year=' . (int) ($_POST['year'] ?? date('Y')) . '&month=' . (int) ($_POST['month'] ?? date('n')));
    }

    public function auditCompareIds(): void
    {
        Auth::requireRole('admin', 'operador');
        Csrf::validate($_POST['_token'] ?? null);
        Session::flash('info', 'La comparación remota anterior fue retirada. Use la captura mensual verificable.');
        $this->redirect('/sync/audit?account_id=' . (int) ($_POST['account_id'] ?? 0) . '&year=' . (int) ($_POST['year'] ?? date('Y')) . '&month=' . (int) ($_POST['month'] ?? date('n')));
    }

    public function auditCompareMonth(): void
    {
        Auth::requireRole('admin', 'operador');
        Csrf::validate($_POST['_token'] ?? null);
        $accountId = (int) ($_POST['account_id'] ?? 0);
        $year = (int) ($_POST['year'] ?? date('Y'));
        $month = (int) ($_POST['month'] ?? date('n'));
        try {
            $jobId = (new SalesAuditRunService())->createExactMonth(
                $accountId,
                $year,
                $month,
                (int) Auth::id()
            );
            $this->redirect('/sync/audit/run?job_id=' . $jobId);
        } catch (\Throwable $e) {
            Session::flash('error', \App\Services\SafeErrorPresenter::message(
                $e,
                'No fue posible crear la auditoría exacta.',
                ['account_id' => $accountId, 'year' => $year, 'month' => $month]
            ));
        }
        $this->redirect('/sync/audit?account_id=' . $accountId . '&year=' . $year . '&month=' . $month);
    }

    public function auditRepairMonth(): void
    {
        Auth::requireRole('admin', 'operador');
        Csrf::validate($_POST['_token'] ?? null);
        $accountId = (int) ($_POST['account_id'] ?? 0);
        $year = (int) ($_POST['year'] ?? date('Y'));
        $month = (int) ($_POST['month'] ?? date('n'));
        try {
            $runId = (int) ($_POST['run_id'] ?? 0);
            $jobId = (new SalesAuditExactRepairService())->createFromRun($runId, (int) Auth::id());
            $this->redirect('/sync/audit/repair?job_id=' . $jobId);
        } catch (\Throwable $e) {
            Session::flash('error', \App\Services\SafeErrorPresenter::message($e));
        }
        $this->redirect('/sync/audit?account_id=' . $accountId . '&year=' . $year . '&month=' . $month);
    }

    public function auditRepairRealMissing(): void
    {
        Auth::requireRole('admin', 'operador');
        Csrf::validate($_POST['_token'] ?? null);
        $accountId = (int) ($_POST['account_id'] ?? 0);
        $year = (int) ($_POST['year'] ?? date('Y'));
        $month = (int) ($_POST['month'] ?? date('n'));
        try {
            $runId = (int) ($_POST['run_id'] ?? 0);
            $jobId = (new SalesAuditExactRepairService())->createFromRun($runId, (int) Auth::id());
            $this->redirect('/sync/audit/repair?job_id=' . $jobId);
        } catch (\Throwable $e) {
            Session::flash('error', \App\Services\SafeErrorPresenter::message($e));
        }
        $this->redirect('/sync/audit?account_id=' . $accountId . '&year=' . $year . '&month=' . $month);
    }

    public function auditRunShow(): void
    {
        Auth::requireLogin();
        $job = (new SalesAuditRunService())->findJob((int) ($_GET['job_id'] ?? 0));
        if ($job === null) {
            http_response_code(404);
            View::render('errors/404', ['message' => 'La comprobación solicitada no existe.']);
            return;
        }
        View::render('sync/audit_run', compact('job'));
    }

    public function auditRepairPreview(): void
    {
        Auth::requireRole('admin', 'operador');
        $preview = (new SalesAuditExactRepairService())->preview((int) ($_GET['run_id'] ?? 0));
        View::render('sync/audit_repair_preview', compact('preview'));
    }

    public function auditRepairShow(): void
    {
        Auth::requireLogin();
        $job = (new SalesAuditExactRepairService())->findJob((int) ($_GET['job_id'] ?? 0));
        if ($job === null) {
            http_response_code(404);
            View::render('errors/404', ['message' => 'La reparación solicitada no existe.']);
            return;
        }
        View::render('sync/audit_repair', compact('job'));
    }

    public function auditRepairPause(): void
    {
        Auth::requireRole('admin', 'operador');
        Csrf::validate($_POST['_token'] ?? null);
        $jobId = (int) ($_POST['job_id'] ?? 0);
        (new SalesAuditExactRepairService())->pause($jobId);
        Session::flash('success', 'La reparación quedó pausada. No se perdió su progreso.');
        $this->redirect('/sync/audit/repair?job_id=' . $jobId);
    }

    public function auditRepairResume(): void
    {
        Auth::requireRole('admin', 'operador');
        Csrf::validate($_POST['_token'] ?? null);
        $jobId = (int) ($_POST['job_id'] ?? 0);
        (new SalesAuditExactRepairService())->resume($jobId);
        Session::flash('success', 'La reparación continuará en el próximo ciclo disponible.');
        $this->redirect('/sync/audit/repair?job_id=' . $jobId);
    }

    public function auditReclassifyExisting(): void
    {
        Auth::requireRole('admin', 'operador');
        Csrf::validate($_POST['_token'] ?? null);
        $accountId = (int) ($_POST['account_id'] ?? 0);
        $year = (int) ($_POST['year'] ?? date('Y'));
        $month = (int) ($_POST['month'] ?? date('n'));
        try {
            $count = (new SalesAuditService())->reclassifyExisting($accountId, $year, $month);
            Session::flash('success', 'Reclasificación terminada. Diferencias revisadas: ' . $count . '.');
        } catch (\Throwable $e) {
            Session::flash('error', \App\Services\SafeErrorPresenter::message($e));
        }
        $this->redirect('/sync/audit?account_id=' . $accountId . '&year=' . $year . '&month=' . $month);
    }

    public function auditFinancialMonth(): void
    {
        Auth::requireRole('admin', 'operador');
        Csrf::validate($_POST['_token'] ?? null);
        $accountId = (int) ($_POST['account_id'] ?? 0);
        $year = (int) ($_POST['year'] ?? date('Y'));
        $month = (int) ($_POST['month'] ?? date('n'));
        try {
            $range = (new \App\Services\MeliDateRangeService())->localMonth($year, $month);
            $jobId = (new OrderFinancialRecalcJobService())->createForRange(
                $accountId,
                $range['local_from']->format('Y-m-d H:i:s'),
                $range['local_to']->modify('-1 second')->format('Y-m-d H:i:s'),
                (string) ($_POST['mode'] ?? 'all'),
                'sales_audit_month',
                null,
                (int) Auth::id()
            );
            Session::flash('success', 'Job financiero #' . $jobId . ' creado para el mes. Puede procesarlo manualmente o dejar que cron avance.');
            $this->redirect('/financial-recalc/show?id=' . $jobId);
        } catch (\Throwable $e) {
            Session::flash('error', \App\Services\SafeErrorPresenter::message($e));
        }
        $this->redirect('/sync/audit?account_id=' . $accountId . '&year=' . $year . '&month=' . $month);
    }

    public function auditFinancialDay(): void
    {
        Auth::requireRole('admin', 'operador');
        Csrf::validate($_POST['_token'] ?? null);
        $accountId = (int) ($_POST['account_id'] ?? 0);
        $year = (int) ($_POST['year'] ?? date('Y'));
        $month = (int) ($_POST['month'] ?? date('n'));
        try {
            $date = (string) ($_POST['audit_date'] ?? '');
            $range = (new \App\Services\MeliDateRangeService())->localDay($date);
            $jobId = (new OrderFinancialRecalcJobService())->createForRange(
                $accountId,
                $range['local_from']->format('Y-m-d H:i:s'),
                $range['local_to']->modify('-1 second')->format('Y-m-d H:i:s'),
                'day',
                'sales_audit_day',
                null,
                (int) Auth::id()
            );
            Session::flash('success', 'Job financiero #' . $jobId . ' creado para el día. Puede procesarlo manualmente o dejar que cron avance.');
            $this->redirect('/financial-recalc/show?id=' . $jobId);
        } catch (\Throwable $e) {
            Session::flash('error', \App\Services\SafeErrorPresenter::message($e));
        }
        $this->redirect('/sync/audit?account_id=' . $accountId . '&year=' . $year . '&month=' . $month);
    }

    public function auditRecalculateDates(): void
    {
        Auth::requireRole('admin', 'operador');
        Csrf::validate($_POST['_token'] ?? null);
        $accountId = (int) ($_POST['account_id'] ?? 0);
        $year = (int) ($_POST['year'] ?? date('Y'));
        $month = (int) ($_POST['month'] ?? date('n'));
        try {
            $jobId = (new SalesAuditService())->createDateRepairJob($accountId, $year, $month);
            Session::flash('success', 'Recalculo de fechas normalizadas preparado para el lanzador único. Trabajo #' . $jobId . '.');
        } catch (\Throwable $e) {
            Session::flash('error', \App\Services\SafeErrorPresenter::message($e));
        }
        $this->redirect('/sync/audit?account_id=' . $accountId . '&year=' . $year . '&month=' . $month);
    }

    public function auditDiagnoseDates(): void
    {
        Auth::requireRole('admin', 'operador');
        Csrf::validate($_POST['_token'] ?? null);
        $accountId = (int) ($_POST['account_id'] ?? 0);
        $year = (int) ($_POST['year'] ?? date('Y'));
        $month = (int) ($_POST['month'] ?? date('n'));
        try {
            $rows = (new SalesAuditService())->dateDiagnostics($accountId, $year, $month);
            Session::flash('success', 'Diagnóstico de fechas: ' . count($rows) . ' órdenes de muestra revisadas. Ver detalles en logs técnicos.');
            \App\Services\Logger::write('info', 'Diagnóstico de fechas de ventas 2.5.', ['account_id' => $accountId, 'year' => $year, 'month' => $month, 'sample' => $rows]);
        } catch (\Throwable $e) {
            Session::flash('error', \App\Services\SafeErrorPresenter::message($e));
        }
        $this->redirect('/sync/audit?account_id=' . $accountId . '&year=' . $year . '&month=' . $month);
    }

    public function recurring(): void
    {
        Auth::requireRole('admin');
        $settings = new SyncSettingsService();
        $accountIds = (new BusinessScopeContext())->accountIds((int) Auth::id());
        $rules = (new RecurringSyncService())->rules($accountIds);
        View::render('sync/recurring', compact('rules', 'settings'));
    }

    public function saveRecurring(): void
    {
        Auth::requireRole('admin');
        Csrf::validate($_POST['_token'] ?? null);
        try {
            $accountIds = (new BusinessScopeContext())->accountIds((int) Auth::id());
            (new RecurringSyncService())->save((array) ($_POST['rules'] ?? []), $accountIds);
            $settings = new \App\Services\AppSettingsService();
            $settings->set('sync.daily_enabled', isset($_POST['sync_daily_enabled']) ? '1' : '0', 'sync');
            $settings->set('questions.frequency_minutes', (string) max(5, min(240, (int) ($_POST['questions_frequency_minutes'] ?? 30))), 'questions');
            Session::flash('success', 'Programación automática guardada.');
        } catch (\Throwable $e) {
            Session::flash('error', \App\Services\SafeErrorPresenter::message($e));
        }
        $this->redirect('/sync/recurring');
    }

    public function runDiagnostics(): void
    {
        Auth::requireRole('admin', 'operador');
        Csrf::validate($_POST['_token'] ?? null);
        $accountId = (int) ($_POST['account_id'] ?? 0);
        $year = (int) ($_POST['year'] ?? date('Y'));
        $month = (int) ($_POST['month'] ?? date('n'));
        try {
            $jobId = (new SalesAuditRunService())->createExactMonth(
                $accountId,
                $year,
                $month,
                (int) Auth::id()
            );
            Session::flash('success', 'Comprobación mensual creada. El lanzador único continuará el trabajo.');
        } catch (\Throwable $e) {
            Session::flash('error', \App\Services\SafeErrorPresenter::message($e));
        }
        $this->redirect('/sales-control/month?account_id=' . $accountId . '&year=' . $year . '&month=' . $month);
    }

    private function redirectBackToAccount(): never
    {
        $accountId = (int) ($_POST['account_id'] ?? 0);
        $year = (int) ($_POST['year'] ?? date('Y'));
        $month = (int) ($_POST['month'] ?? date('n'));
        if ($accountId > 0 && $month >= 1 && $month <= 12) {
            $this->redirect('/sync/account?id=' . $accountId . '&year=' . $year . '&month=' . $month);
        }
        $this->redirect('/sync?account_id=' . $accountId . '&year=' . $year);
    }

    /**
     * @return array{0:bool,1:?DateTimeImmutable,2:string}
     */
    private function scheduleFromPost(string $defaultMode = 'now'): array
    {
        $mode = (string) ($_POST['schedule_mode'] ?? $defaultMode);
        $timezone = new DateTimeZone(DateTimePresenter::timezone());
        $now = new DateTimeImmutable('now', $timezone);
        if ($mode === 'none') {
            return [false, null, 'sin encolar'];
        }
        if ($mode === 'now') {
            return [true, $now, $now->format('Y-m-d H:i')];
        }
        if ($mode === 'delay') {
            $minutes = (int) ($_POST['schedule_delay_minutes'] ?? (new SyncSettingsService())->defaultEnqueueDelayMinutes());
            $minutes = in_array($minutes, [0, 5, 10, 20, 30, 60], true) ? $minutes : (new SyncSettingsService())->defaultEnqueueDelayMinutes();
            $runAt = $now->modify('+' . $minutes . ' minutes');
            return [true, $runAt, $runAt->format('Y-m-d H:i')];
        }
        if ($mode === 'custom') {
            if (!(new SyncSettingsService())->allowCustomSchedule()) {
                throw new \RuntimeException('La programación personalizada está desactivada en Configuración.');
            }
            $raw = trim((string) ($_POST['schedule_at'] ?? ''));
            if ($raw === '') {
                throw new \RuntimeException('Seleccione fecha y hora para programar.');
            }
            $runAt = new DateTimeImmutable($raw, $timezone);
            if ($runAt < $now->modify('-1 minute')) {
                throw new \RuntimeException('La fecha programada no puede estar en el pasado.');
            }
            return [true, $runAt, $runAt->format('Y-m-d H:i')];
        }
        return [true, $now, $now->format('Y-m-d H:i')];
    }

    private function manageChunk(callable $action, string $success): void
    {
        Auth::requireRole('admin', 'operador');
        Csrf::validate($_POST['_token'] ?? null);
        try {
            $action(new SyncCenterService(), (int) ($_POST['chunk_id'] ?? 0));
            Session::flash('success', $success);
        } catch (\Throwable $e) {
            Session::flash('error', \App\Services\SafeErrorPresenter::message($e));
        }
        if (isset($_POST['return_to_schedule'])) {
            $this->redirect('/sync/schedule?account_id=' . (int) ($_POST['account_id'] ?? 0));
        }
        $this->redirectBackToAccount();
    }

    private function redirect(string $path): never
    {
        header('Location: ' . rtrim(Env::get('APP_URL', ''), '/') . $path);
        exit;
    }
}
