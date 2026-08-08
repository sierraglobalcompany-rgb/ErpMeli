<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Csrf;
use App\Core\Database;
use App\Core\Env;
use App\Core\Session;
use App\Core\View;
use App\Services\CompanyOptionService;
use App\Services\ProductImportService;
use PDO;

final class ProductImportController
{
    public function index(): void
    {
        Auth::requireLogin();
        $accountId = (int) ($_GET['account_id'] ?? 0);
        $companyId = (int) ($_GET['company_id'] ?? 0);
        $q = trim((string) ($_GET['q'] ?? ''));
        $page = max(1, (int) ($_GET['page'] ?? 1));
        $perPage = in_array((int) ($_GET['per_page'] ?? 50), [25, 50, 100], true) ? (int) $_GET['per_page'] : 50;
        $pdo = Database::connection();
        $accounts = $pdo->query('SELECT id,account_name FROM meli_accounts ORDER BY account_name')->fetchAll(PDO::FETCH_ASSOC);
        $companies = (new CompanyOptionService())->active();
        $pageData = (new ProductImportService())->paginateCandidates($accountId, $companyId, $q, $page, $perPage);
        $items = $pageData['items'];
        View::render('products/imports/index', compact('accounts', 'companies', 'items', 'pageData', 'accountId', 'companyId', 'q'));
    }

    public function fromMeli(): void
    {
        Auth::requireRole('admin', 'operador');
        Csrf::validate($_POST['_token'] ?? null);
        try {
            $summary = (new ProductImportService())->importItems(
                $_POST['meli_item_ids'] ?? [],
                (int) ($_POST['company_id'] ?? 0),
                isset($_POST['overwrite'])
            );
            Session::flash('success', 'Importación lista: ' . $summary['created'] . ' creados, ' . $summary['existing'] . ' existentes, ' . $summary['updated'] . ' actualizados.');
        } catch (\Throwable $e) {
            Session::flash('error', \App\Services\SafeErrorPresenter::message($e));
        }
        $query = http_build_query([
            'account_id' => (int) ($_POST['account_id'] ?? 0),
            'company_id' => (int) ($_POST['company_id'] ?? 0),
        ]);
        $this->redirect('/products/imports?' . $query);
    }

    private function redirect(string $path): never
    {
        header('Location: ' . rtrim(Env::get('APP_URL', ''), '/') . $path);
        exit;
    }
}
