<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Database;
use App\Core\Session;
use App\Core\View;
use App\Services\ApiGuardService;
use App\Services\ApiHealthService;
use App\Services\CompanyOptionService;
use App\Services\Clock;
use App\Services\DashboardReadModelService;
use App\Services\DiagnosticService;
use App\Services\QuestionSyncService;
use App\Services\ReadModelCacheService;
use App\Services\RecurringSyncService;
use App\Services\SalesAuditService;
use App\Services\WebhookService;
use App\Services\BusinessScopeContext;
use App\Services\AsyncSectionService;
use App\Services\AppVersionService;
use App\Services\SystemSafetyStatusService;
use App\ValueObjects\DateRange;
use Throwable;

final class DashboardController
{
    public function __construct(private ?DashboardReadModelService $readModel = null)
    {
        $this->readModel ??= new DashboardReadModelService();
    }

    public function index(): void
    {
        Auth::requireLogin();
        Session::closeReadOnly();
        $query = $_GET;
        unset($query['full']);
        View::render('dashboard/index', ['query' => $query]);
    }

    public function summarySection(): void
    {
        Auth::requireLogin();
        $async = new AsyncSectionService();
        $async->releaseSession();
        $startedAt = microtime(true);
        try {
            $read = $this->readContext();
            $metrics = $read['metrics'] ?? [];
            $orders = $read['orders'] ?? [];
            $async->render('dashboard-summary', 'dashboard/_summary', compact('metrics', 'orders'), ['cache' => 'read-model'], $startedAt);
        } catch (Throwable $error) {
            $async->failure('dashboard-summary', $error);
        }
    }

    public function operationsSection(): void
    {
        Auth::requireLogin();
        $async = new AsyncSectionService();
        $async->releaseSession();
        $startedAt = microtime(true);
        try {
            $read = $this->readContext();
            $operations = $this->operationsData($read['syncs'] ?? []);
            $async->render('dashboard-operations', 'dashboard/_operations', compact('operations'), ['cache' => 'read-model'], $startedAt);
        } catch (Throwable $error) {
            $async->failure('dashboard-operations', $error);
        }
    }

    public function healthSection(): void
    {
        Auth::requireLogin();
        $async = new AsyncSectionService();
        $async->releaseSession();
        $startedAt = microtime(true);
        try {
            $healthData = $this->healthData();
            $async->render('dashboard-health', 'dashboard/_health', compact('healthData'), ['cache' => 'mixed'], $startedAt);
        } catch (Throwable $error) {
            $async->failure('dashboard-health', $error);
        }
    }

    /** @return array<string,mixed> */
    private function readContext(): array
    {
        $accountId = (int) ($_GET['account_id'] ?? 0);
        $companyId = (int) ($_GET['company_id'] ?? 0);
        $clock = new Clock();
        $today = $clock->nowLocal();
        $from = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($_GET['from'] ?? '')) ? (string) $_GET['from'] : $today->format('Y-m-01');
        $to = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($_GET['to'] ?? '')) ? (string) $_GET['to'] : $today->format('Y-m-d');
        try {
            $range = DateRange::fromLocalDates($from, $to, $clock);
        } catch (Throwable) {
            $range = DateRange::fromLocalDates($today->format('Y-m-01'), $today->format('Y-m-d'), $clock);
        }
        return $this->readModel->load($accountId, $companyId, $range);
    }

    /** @param array<int,array<string,mixed>> $syncs @return array<string,mixed> */
    private function operationsData(array $syncs): array
    {
        $accountId = max(0, (int) ($_GET['account_id'] ?? 0));
        $companyId = max(0, (int) ($_GET['company_id'] ?? 0));
        $cacheKey = implode(':', [(int) Auth::id(), $companyId, $accountId]);
        $cached = (new ReadModelCacheService())->rememberArray(
            'dashboard-operations-extra',
            $cacheKey,
            15,
            static fn(): array => [
                'pending_questions' => (new QuestionSyncService())->pendingCount($accountId, $companyId),
                'audit_issues' => (new SalesAuditService())->recentWithDifference(5, $accountId, $companyId),
                'daily_sync_enabled' => (new RecurringSyncService())->dailyEnabled(),
            ]
        );
        return $cached['value'] + [
            'syncs' => $syncs,
        ];
    }

    /** @return array<string,mixed> */
    private function healthData(): array
    {
        $safety = (new SystemSafetyStatusService())->status();
        if ($safety['api'] === 'stopped' || $safety['automation'] === 'stopped') {
            return [
                'maintenance' => true,
                'diagnostic' => [
                    'migrations' => [],
                    'file_version' => AppVersionService::fileVersion(),
                    'installed_version' => (new AppVersionService())->installedVersion(),
                    'ml_write_enabled' => 'false',
                ],
                'api_error_groups' => [],
                'notification_health' => [
                    'label' => 'Recepción local disponible',
                    'pending' => null,
                ],
                'temporary_users' => null,
                'open_circuits' => [],
                'api_health' => [
                    'risk' => 'paused',
                    'active_incident_count' => 0,
                    'recovered_incident_count' => 0,
                    'sent' => 0,
                    'remote_errors' => 0,
                ],
                'notification_summary' => ['unread' => null],
                'latest_notifications' => [],
            ];
        }

        $webhook = new WebhookService();
        $accountIds = (new BusinessScopeContext())->accountIds((int) Auth::id());
        $apiHealthService = new ApiHealthService();
        $apiHealth = $apiHealthService->dashboard();
        return [
            'diagnostic' => (new DiagnosticService())->summary(),
            'api_error_groups' => array_slice($apiHealth['incidents'] ?? [], 0, 5),
            'notification_health' => $webhook->health($accountIds),
            'temporary_users' => (int) Database::connection()->query(
                'SELECT COUNT(*) FROM users
                 WHERE is_temporary=1 AND status=1 AND revoked_at IS NULL
                   AND (expires_at IS NULL OR expires_at>UTC_TIMESTAMP())'
            )->fetchColumn(),
            'open_circuits' => (new ApiGuardService())->openCircuits(5),
            'api_health' => $apiHealth,
            'notification_summary' => $webhook->unreadSummary($accountIds),
            'latest_notifications' => $webhook->notifications(['unread' => '1', 'allowed_account_ids' => $accountIds], 5),
        ];
    }
}
