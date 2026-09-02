<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Csrf;
use App\Core\Database;
use App\Core\Env;
use App\Core\HttpException;
use App\Core\Session;
use App\Core\View;
use App\Services\MissedFeedService;
use App\Services\BusinessScopeContext;
use App\Services\CronHealthService;
use App\Services\NotificationBackfillService;
use App\Services\NotificationWorkItemService;
use App\Services\SafeErrorPresenter;
use App\Services\WebhookService;
use App\Services\AsyncSectionService;
use PDO;
use Throwable;

final class NotificationController
{
    public function index(): void
    {
        $this->renderCenter('attention');
    }

    public function activity(): void
    {
        $this->renderCenter('activity');
    }

    public function health(): void
    {
        $this->renderCenter('health');
    }

    public function section(): void
    {
        Auth::requireLogin();
        $tab = in_array((string) ($_GET['tab'] ?? 'attention'), ['attention', 'activity', 'health'], true)
            ? (string) $_GET['tab'] : 'attention';
        $async = new AsyncSectionService();
        $async->releaseSession();
        $startedAt = microtime(true);
        try {
            $async->render('notifications-center', 'notifications/index', $this->centerData($tab), [], $startedAt);
        } catch (Throwable $error) {
            $async->failure('notifications-center', $error);
        }
    }

    public function technicalEvents(): void
    {
        $this->requireAdminPermanent();
        $service = new WebhookService();
        $page = max(1, (int) ($_GET['page'] ?? 1));
        $perPage = 50;
        $filters = [
            'status' => trim((string) ($_GET['status'] ?? '')),
            'topic' => trim((string) ($_GET['topic'] ?? '')),
            'account_id' => (int) ($_GET['account_id'] ?? 0),
            'allowed_account_ids' => $this->authorizedAccountIds(),
        ];
        $this->assertOptionalAccount((int) $filters['account_id']);
        $events = $service->recentEvents($filters, $perPage, ($page - 1) * $perPage);
        $health = $service->health($this->authorizedAccountIds());
        $accounts = $this->accounts();
        View::render('notifications/events', compact('events', 'filters', 'health', 'accounts', 'page', 'perPage'));
    }

    public function events(): void
    {
        $this->technicalEvents();
    }

    public function missed(): void
    {
        $this->requireAdminPermanent();
        $accounts = $this->accounts();
        $runs = (new MissedFeedService())->runs();
        View::render('notifications/missed', compact('accounts', 'runs'));
    }

    public function statusJson(): void
    {
        Auth::requireLogin();
        Session::closeReadOnly();
        $service = new WebhookService();
        $work = new NotificationWorkItemService();
        $accountIds = $this->authorizedAccountIds();
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo json_encode([
            'health' => $service->health($accountIds),
            'queue' => $work->summary($accountIds),
            'server_time_utc' => gmdate(DATE_ATOM),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    public function automation(): void
    {
        Auth::requireRole('admin', 'operador');
        $this->rejectTemporary();
        $accountIds = $this->authorizedAccountIds();
        $health = (new WebhookService())->health($accountIds);
        $work = new NotificationWorkItemService();
        $queue = $work->summary($accountIds);
        $recentFailures = $work->recentFailures(10, $accountIds);
        $notificationCronCommand = (new CronHealthService())->recommendedNotificationCommand();
        View::render('notifications/automation', compact('health', 'queue', 'recentFailures', 'notificationCronCommand'));
    }

    public function automationStatusJson(): void
    {
        Auth::requireRole('admin', 'operador');
        $this->rejectTemporary();
        Session::closeReadOnly();
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: private, no-store, max-age=0');
        $work = new NotificationWorkItemService();
        $accountIds = $this->authorizedAccountIds();
        echo json_encode([
            'ok' => true,
            'health' => (new WebhookService())->health($accountIds),
            'queue' => $work->summary($accountIds),
            'recent_failures' => $work->recentFailures(10, $accountIds),
            'server_time_utc' => gmdate(DATE_ATOM),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        exit;
    }

    public function automationTest(): void
    {
        Auth::requireRole('admin', 'operador');
        $this->rejectTemporary();
        Csrf::validate($_POST['_token'] ?? null);
        try {
            $summary = (new NotificationWorkItemService())->summary($this->authorizedAccountIds());
            Session::flash('success', sprintf(
                'Comprobación local completada. Hay %d trabajos pendientes y %d que necesitan revisión. No se consultó Mercado Libre.',
                (int) ($summary['pending'] ?? 0),
                (int) ($summary['failed'] ?? $summary['errors'] ?? 0)
            ));
        } catch (Throwable $error) {
            Session::flash('error', SafeErrorPresenter::message($error, 'No fue posible comprobar la cola de notificaciones.', ['module' => 'notifications']));
        }
        $this->redirect('/notifications/automation');
    }

    public function automationAssistedStep(): void
    {
        Auth::requireRole('admin', 'operador');
        $this->rejectTemporary();
        Csrf::validate($_POST['_token'] ?? null);
        http_response_code(410);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'ok' => false,
            'retired' => true,
            'redirect' => '/settings/manual-processing?scope=sales',
            'message' => 'El procesamiento asistido anterior fue reemplazado por el Centro seguro.',
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    public function runMissed(): void
    {
        $this->requireAdminPermanent();
        Csrf::validate($_POST['_token'] ?? null);
        Session::flash(
            'info',
            'La recuperación remota desde la página fue retirada. El lanzador único procesará los eventos pendientes de forma segura.'
        );
        $this->redirect('/settings/cron/queue?queue_key=notification_fallback');
    }

    public function processWork(): void
    {
        // Mensaje histórico conservado para trazabilidad: "Sincronización por eventos ejecutada".
        Auth::requireRole('admin', 'operador');
        $this->rejectTemporary();
        Csrf::validate($_POST['_token'] ?? null);
        Session::flash('info', 'La cola se procesa ahora desde el Centro seguro o por el lanzador único.');
        $this->redirect('/settings/manual-processing?scope=sales');
    }

    public function pauseWork(): void
    {
        Auth::requireRole('admin', 'operador');
        $this->rejectTemporary();
        Csrf::validate($_POST['_token'] ?? null);
        $this->notificationMutationScope();
        Session::flash('info', 'La pausa masiva desde el navegador fue retirada. Use el freno de mano o gestione un recurso exacto; los eventos seguirán guardándose.');
        $this->redirect('/notifications/health');
    }

    public function resumeWork(): void
    {
        Auth::requireRole('admin', 'operador');
        $this->rejectTemporary();
        Csrf::validate($_POST['_token'] ?? null);
        $this->notificationMutationScope();
        Session::flash('info', 'La reanudación masiva desde el navegador fue retirada. El lanzador único recupera los trabajos elegibles sin liberar lotes completos.');
        $this->redirect('/notifications/health');
    }

    public function retryWork(): void
    {
        Auth::requireRole('admin', 'operador');
        $this->rejectTemporary();
        Csrf::validate($_POST['_token'] ?? null);
        $id = (int) ($_POST['work_id'] ?? 0);
        if ($id <= 0) {
            throw new HttpException(400, 'Seleccione un trabajo exacto para reintentarlo.');
        }
        $count = (new NotificationWorkItemService())->retry($id, $this->authorizedAccountIds());
        Session::flash('success', 'Trabajos preparados para reintento: ' . $count . '.');
        $this->redirect('/notifications?tab=attention');
    }

    public function analyzeBackfill(): void
    {
        $this->requireAdminPermanent();
        Csrf::validate($_POST['_token'] ?? null);
        Session::flash('info', 'El análisis histórico global desde web fue retirado. Un administrador puede autorizar una recuperación exacta y el lanzador único la ejecutará con alcance de empresa y cuenta.');
        $this->redirect('/notifications/health');
    }

    public function startBackfill(): void
    {
        $this->requireAdminPermanent();
        Csrf::validate($_POST['_token'] ?? null);
        Session::flash('info', 'El inicio global de backfill desde web fue retirado. Seleccione una recuperación exacta dentro de su empresa y cuenta.');
        $this->redirect('/notifications/health');
    }

    public function pauseBackfill(): void
    {
        $this->requireAdminPermanent();
        Csrf::validate($_POST['_token'] ?? null);
        Session::flash('info', 'La pausa global de backfill desde web fue retirada. La coordinación exacta conserva el avance y se administra por el lanzador único.');
        $this->redirect('/notifications/health');
    }

    public function markRead(): void
    {
        Auth::requireLogin();
        Csrf::validate($_POST['_token'] ?? null);
        (new WebhookService())->markRead((int) ($_POST['id'] ?? 0), $this->authorizedAccountIds());
        $this->redirect('/notifications');
    }

    public function dismiss(): void
    {
        Auth::requireLogin();
        Csrf::validate($_POST['_token'] ?? null);
        (new WebhookService())->dismiss((int) ($_POST['id'] ?? 0), $this->authorizedAccountIds());
        $this->redirect('/notifications');
    }

    public function retryEvent(): void
    {
        $this->requireAdminPermanent();
        Csrf::validate($_POST['_token'] ?? null);
        (new WebhookService())->requeueEvent((int) ($_POST['id'] ?? 0), $this->authorizedAccountIds());
        Session::flash('success', 'Evento legado reencolado.');
        $this->redirect('/notifications/technical/events');
    }

    private function renderCenter(string $tab): void
    {
        Auth::requireLogin();
        if ((string) ($_GET['full'] ?? '') !== '1') {
            View::render('notifications/index', [
                'tab' => $tab,
                'progressive' => true,
            ]);
            return;
        }
        View::render('notifications/index', $this->centerData($tab));
    }

    /** @return array<string,mixed> */
    private function centerData(string $tab): array
    {
        $service = new WebhookService();
        $work = new NotificationWorkItemService();
        $filters = [
            'unread' => (string) ($_GET['unread'] ?? ''),
            'severity' => (string) ($_GET['severity'] ?? ''),
            'type' => (string) ($_GET['type'] ?? ''),
            'account_id' => (int) ($_GET['account_id'] ?? 0),
            'allowed_account_ids' => $this->authorizedAccountIds(),
            'action_required' => $tab === 'attention' ? '1' : '',
        ];
        $this->assertOptionalAccount((int) $filters['account_id']);
        $accountIds = $this->authorizedAccountIds();
        $notifications = $tab === 'attention' ? $service->notifications($filters, 50) : [];
        $activity = $tab === 'activity' ? $work->recentActivity(50, $accountIds) : [];
        $attention = $tab === 'attention' ? $work->attention(50, $accountIds) : [];
        $health = $service->health($accountIds);
        $summary = $service->unreadSummary($accountIds);
        $accounts = $this->accounts();
        $backfillRuns = $tab === 'health' ? (new NotificationBackfillService())->runs() : [];
        $notificationCronCommand = (new CronHealthService())->recommendedNotificationCommand();
        return compact(
            'tab', 'notifications', 'activity', 'attention', 'filters', 'health', 'summary', 'accounts', 'backfillRuns',
            'notificationCronCommand'
        );
    }

    /** @return list<array<string,mixed>> */
    private function accounts(): array
    {
        $accountIds = $this->authorizedAccountIds();
        if ($accountIds === []) {
            return [];
        }
        $stmt = Database::connection()->prepare(
            'SELECT a.id,a.account_name
             FROM meli_accounts a
             JOIN companies c ON c.id=a.company_id AND c.status=1
             WHERE a.id IN (' . implode(',', array_fill(0, count($accountIds), '?')) . ')
               AND a.status IN ("conectado","connected")
             ORDER BY a.account_name'
        );
        $stmt->execute($accountIds);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return list<int> */
    private function authorizedAccountIds(): array
    {
        return (new BusinessScopeContext())->accountIds((int) Auth::id());
    }

    /** @return array{0:?int,1:list<int>} */
    private function notificationMutationScope(): array
    {
        $accountId = (int) ($_POST['account_id'] ?? 0);
        $requestedAccountId = $accountId > 0 ? $accountId : null;
        $accountIds = $this->authorizedAccountIds();
        if ($requestedAccountId !== null && !in_array($requestedAccountId, $accountIds, true)) {
            throw new HttpException(404, 'No se encontró la cuenta solicitada.');
        }

        return [$requestedAccountId, $accountIds];
    }

    private function assertOptionalAccount(int $accountId): void
    {
        if ($accountId > 0) {
            (new BusinessScopeContext())->account($accountId, 0, (int) Auth::id());
        }
    }

    private function requireAdminPermanent(): void
    {
        Auth::requireRole('admin');
        $this->rejectTemporary();
    }

    private function rejectTemporary(): void
    {
        if (Auth::isTemporary()) {
            throw new HttpException(403, 'Los accesos temporales no pueden ejecutar esta acción.');
        }
    }

    private function redirect(string $path): never
    {
        header('Location: ' . rtrim(Env::get('APP_URL', ''), '/') . $path);
        exit;
    }
}
