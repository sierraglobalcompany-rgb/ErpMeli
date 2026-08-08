<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\View;
use App\Services\AuthorizedBusinessScope;
use App\Services\AsyncSectionService;
use App\Services\CompanyOptionService;
use App\Services\ProfitabilityService;
use Throwable;

final class ProfitabilityController
{
    public function index(): void
    {
        Auth::requireLogin();
        if ((string) ($_GET['full'] ?? '') !== '1') {
            View::render('reports/profitability/index', [
                'filters' => $this->filters(),
                'rows' => [],
                'companies' => [],
                'accounts' => [],
                'progressive' => true,
            ]);
            return;
        }
        View::render('reports/profitability/index', $this->reportData());
    }

    public function section(): void
    {
        Auth::requireLogin();
        $async = new AsyncSectionService();
        $async->releaseSession();
        $startedAt = microtime(true);
        try {
            $async->render('profitability-list', 'reports/profitability/index', $this->reportData(), [], $startedAt);
        } catch (Throwable $error) {
            $async->failure('profitability-list', $error);
        }
    }

    /** @return array<string,mixed> */
    private function reportData(): array
    {
        $filters = $this->filters();
        $scope = new AuthorizedBusinessScope();
        if ($filters['company_id'] > 0 && !in_array($filters['company_id'], $scope->companyIds(), true)) {
            throw new \App\Core\HttpException(404, 'No se encontró la empresa solicitada.');
        }
        if ($filters['account_id'] > 0) {
            $scope->account($filters['account_id'], $filters['company_id']);
        }
        $page = max(1, (int) ($_GET['page'] ?? 1));
        $pageSize = 50;
        $rows = (new ProfitabilityService())->summary($filters, $pageSize + 1, ($page - 1) * $pageSize);
        $hasMore = count($rows) > $pageSize;
        if ($hasMore) {
            $rows = array_slice($rows, 0, $pageSize);
        }
        $companies = (new CompanyOptionService())->authorizedActive();
        $accountIds = $scope->accountIds(null, $filters['company_id']);
        $accounts = [];
        if ($accountIds !== []) {
            $stmt = \App\Core\Database::connection()->prepare(
                'SELECT id,account_name FROM meli_accounts WHERE id IN (' . implode(',', array_fill(0, count($accountIds), '?')) . ') ORDER BY account_name'
            );
            $stmt->execute($accountIds);
            $accounts = $stmt->fetchAll();
        }
        return compact('filters', 'rows', 'companies', 'accounts', 'page', 'pageSize', 'hasMore');
    }

    /** @return array{company_id:int,account_id:int,from:string,to:string,include_returns:bool} */
    private function filters(): array
    {
        return [
            'company_id' => (int) ($_GET['company_id'] ?? 0),
            'account_id' => (int) ($_GET['account_id'] ?? 0),
            'from' => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($_GET['from'] ?? '')) ? (string) $_GET['from'] : date('Y-m-01'),
            'to' => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($_GET['to'] ?? '')) ? (string) $_GET['to'] : date('Y-m-d'),
            'include_returns' => !empty($_GET['include_returns']),
        ];
    }
}
