<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Session;
use App\Core\View;
use App\Services\ApiHealthService;
use App\Services\ApiLogRiskPresenter;
use App\Services\AppSettingsService;
use App\Services\LogQueryService;
use App\Services\ReadModelCacheService;
use App\Services\BusinessScopeContext;

final class LogController
{
    public function index(): void
    {
        Auth::requireRole('admin');
        $filters = $this->filters();
        Session::closeReadOnly();

        $service = new LogQueryService();
        $health = new ApiHealthService();
        $isApi = $filters['type'] === 'api';
        $result = ['rows' => [], 'total' => 0, 'page' => 1, 'per_page' => $filters['per_page'], 'pages' => 1, 'classified' => true];
        if (!$isApi || in_array($filters['view'], ['attention', 'technical'], true)) {
            $result = $service->page($filters);
        }
        $rows = $result['rows'];
        $accounts = $service->accounts();
        $summary = ['available' => false];
        $activity = [];
        $activeIncidents = [];
        $recoveredIncidents = [];

        if ($isApi) {
            $settings = new AppSettingsService();
            $ttl = max(5, min(120, $settings->int('logs.api.summary_cache_seconds', 30)));
            $cacheKey = json_encode([
                'user' => Auth::id(),
                'scope' => hash('sha256', implode(',', (new BusinessScopeContext())->accountIds((int) Auth::id()))),
                'account' => $filters['account_id'],
                'period' => $filters['period'],
                'from' => $filters['from'],
                'to' => $filters['to'],
                'role' => Auth::role(),
            ], JSON_UNESCAPED_SLASHES);
            $cached = (new ReadModelCacheService())->rememberArray(
                'api-log-summary',
                (string) $cacheKey,
                $ttl,
                fn (): array => ['summary' => $service->summary($filters), 'activity' => $service->activity($filters)]
            );
            $summary = $cached['value']['summary'];
            $activity = $cached['value']['activity'];
            $hours = (int) $filters['period'];
            $activeIncidents = $health->incidents([
                'hours' => $hours,
                'account_id' => $filters['account_id'],
                'status' => 'active',
            ], 12);
            $recoveredIncidents = $health->incidents([
                'hours' => max(168, $hours),
                'account_id' => $filters['account_id'],
                'status' => 'recovered',
            ], 8);
            if (!$health->dataAvailable()) {
                $summary['available'] = false;
            }
        }

        $presenter = new ApiLogRiskPresenter();
        $education = $presenter->education($rows);
        View::render('logs/index', compact(
            'rows',
            'accounts',
            'filters',
            'activeIncidents',
            'recoveredIncidents',
            'summary',
            'activity',
            'result',
            'education',
            'presenter'
        ));
    }

    public function apiSummary(): void
    {
        Auth::requireRole('admin');
        $filters = $this->filters();
        $filters['type'] = 'api';
        Session::closeReadOnly();
        $service = new LogQueryService();
        $health = new ApiHealthService();
        $payload = [
            'ok' => true,
            'summary' => $service->summary($filters),
            'activity' => $service->activity($filters),
            'active_incidents' => $health->incidents([
                'hours' => (int) $filters['period'],
                'account_id' => (int) $filters['account_id'],
                'status' => 'active',
            ], 12),
        ];
        if (!$health->dataAvailable() || empty($payload['summary']['available'])) {
            $payload['ok'] = false;
            $payload['message'] = 'No fue posible comprobar la actividad en este momento.';
        }
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: private, no-store');
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /** @return array<string,mixed> */
    private function filters(): array
    {
        $type = trim((string) ($_GET['type'] ?? 'api'));
        $advanced = array_filter([
            $_GET['http_status'] ?? '',
            $_GET['endpoint'] ?? '',
            $_GET['outcome'] ?? '',
            $_GET['sort'] ?? '',
            $_GET['direction'] ?? '',
        ], static fn (mixed $value): bool => trim((string) $value) !== '');
        $requestedView = trim((string) ($_GET['view'] ?? ''));
        $view = in_array($requestedView, ['summary', 'attention', 'activity', 'technical'], true)
            ? $requestedView
            : ($advanced !== [] ? 'technical' : 'summary');
        $period = in_array((int) ($_GET['period'] ?? 24), [24, 168, 720], true) ? (int) $_GET['period'] : 24;
        return [
            'type' => $type,
            'view' => $type === 'api' ? $view : 'technical',
            'period' => $period,
            'level' => trim((string) ($_GET['level'] ?? '')),
            'account_id' => (int) ($_GET['account_id'] ?? 0),
            'http_status' => trim((string) ($_GET['http_status'] ?? '')),
            'endpoint' => trim((string) ($_GET['endpoint'] ?? '')),
            'from' => trim((string) ($_GET['from'] ?? '')),
            'to' => trim((string) ($_GET['to'] ?? '')),
            'risk' => trim((string) ($_GET['risk'] ?? '')),
            'origin' => trim((string) ($_GET['origin'] ?? '')),
            'outcome' => trim((string) ($_GET['outcome'] ?? '')),
            'sort' => trim((string) ($_GET['sort'] ?? 'date')),
            'direction' => strtolower(trim((string) ($_GET['direction'] ?? 'desc'))) === 'asc' ? 'asc' : 'desc',
            'page' => max(1, (int) ($_GET['page'] ?? 1)),
            'per_page' => in_array((int) ($_GET['per_page'] ?? 50), [25, 50, 100], true) ? (int) $_GET['per_page'] : 50,
        ];
    }
}
