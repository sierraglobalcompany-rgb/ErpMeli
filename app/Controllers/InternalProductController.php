<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Csrf;
use App\Core\Database;
use App\Core\Env;
use App\Core\Session;
use App\Core\View;
use App\Services\BusinessScopeContext;
use App\Services\InternalProductService;

final class InternalProductController
{
    public function index(): void
    {
        Auth::requireLogin();
        $filters = [
            'company_id' => (int) ($_GET['company_id'] ?? 0),
            'q' => trim((string) ($_GET['q'] ?? '')),
            'readiness' => in_array((string) ($_GET['readiness'] ?? ''), [
                'missing_cost', 'missing_sku', 'missing_link', 'margin_incomplete',
            ], true) ? (string) $_GET['readiness'] : '',
        ];
        $service = new InternalProductService();
        $pageData = $service->paginate($filters, (int) ($_GET['page'] ?? 1), (int) ($_GET['per_page'] ?? 50));
        $products = $pageData['items'];
        $readiness = $service->readinessSummary($filters['company_id']);
        $companyIds = (new BusinessScopeContext())->companyIds();
        $companies = [];
        if ($companyIds !== []) {
            $stmt = Database::connection()->prepare(
                'SELECT id,name,nit,legal_name,status
                 FROM companies
                 WHERE id IN (' . implode(',', array_fill(0, count($companyIds), '?')) . ')
                   AND deleted_at IS NULL AND status=1
                 ORDER BY name,id'
            );
            $stmt->execute($companyIds);
            $companies = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        }
        View::render('products/internal/index', compact('products', 'companies', 'filters', 'pageData', 'readiness'));
    }

    public function store(): void
    {
        Auth::requireRole('admin', 'operador');
        Csrf::validate($_POST['_token'] ?? null);
        try {
            (new InternalProductService())->create($_POST);
            Session::flash('success', 'Producto interno creado.');
        } catch (\Throwable $e) {
            Session::flash('error', \App\Services\SafeErrorPresenter::message($e));
        }
        $this->redirect('/products/internal');
    }

    public function fromItem(): void
    {
        Auth::requireRole('admin', 'operador');
        Csrf::validate($_POST['_token'] ?? null);
        try {
            $id = (new InternalProductService())->createFromMeliItem((int) ($_POST['meli_item_id'] ?? 0));
            Session::flash('success', 'Producto interno creado desde publicación ML.');
            $this->redirect('/products/links?internal_id=' . $id);
        } catch (\Throwable $e) {
            Session::flash('error', \App\Services\SafeErrorPresenter::message($e));
            $this->redirect('/products/meli');
        }
    }

    public function fromOrder(): void
    {
        Auth::requireRole('admin', 'operador');
        Csrf::validate($_POST['_token'] ?? null);
        try {
            $id = (new InternalProductService())->createFromOrderItem((int) ($_POST['order_item_id'] ?? 0));
            Session::flash('success', 'Producto interno creado desde ítem vendido.');
            $this->redirect('/products/links?internal_id=' . $id);
        } catch (\Throwable $e) {
            Session::flash('error', \App\Services\SafeErrorPresenter::message($e));
            $this->redirect('/products/unlinked');
        }
    }

    private function redirect(string $path): never
    {
        header('Location: ' . rtrim(Env::get('APP_URL', ''), '/') . $path);
        exit;
    }
}
