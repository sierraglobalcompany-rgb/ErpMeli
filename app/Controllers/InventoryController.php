<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Csrf;
use App\Core\Env;
use App\Core\SameOriginGuard;
use App\Core\Session;
use App\Core\View;
use App\Services\InventoryApplicationService;
use App\Services\InventoryQueryService;
use App\Services\InventoryWarehouseService;
use App\Services\SafeErrorPresenter;

final class InventoryController
{
    public function index(): void
    {
        Auth::requireLogin();
        $filters = [
            'company_id' => (int) ($_GET['company_id'] ?? 0),
            'warehouse_id' => (int) ($_GET['warehouse_id'] ?? 0),
            'q' => trim((string) ($_GET['q'] ?? '')),
        ];
        $query = new InventoryQueryService();
        $pageData = $query->balances($filters, (int) ($_GET['page'] ?? 1), (int) ($_GET['per_page'] ?? 50));
        $companies = $query->companies();
        $warehouses = (new InventoryWarehouseService())->warehouses($filters['company_id']);
        $products = $filters['company_id'] > 0 ? $query->products($filters['company_id']) : [];
        $reviews = $query->openReviews($filters['company_id']);
        $requestId = bin2hex(random_bytes(16));
        View::render('inventory/index', compact(
            'filters', 'pageData', 'companies', 'warehouses', 'products', 'reviews', 'requestId'
        ));
    }

    public function kardex(): void
    {
        Auth::requireLogin();
        $filters = [
            'company_id' => (int) ($_GET['company_id'] ?? 0),
            'warehouse_id' => (int) ($_GET['warehouse_id'] ?? 0),
            'internal_product_id' => (int) ($_GET['internal_product_id'] ?? 0),
            'account_id' => (int) ($_GET['account_id'] ?? 0),
            'movement_type' => trim((string) ($_GET['movement_type'] ?? '')),
            'from' => trim((string) ($_GET['from'] ?? '')),
            'to' => trim((string) ($_GET['to'] ?? '')),
        ];
        $query = new InventoryQueryService();
        $pageData = $query->movements($filters, (int) ($_GET['page'] ?? 1), (int) ($_GET['per_page'] ?? 50));
        $companies = $query->companies();
        $warehouses = (new InventoryWarehouseService())->warehouses($filters['company_id']);
        $products = $filters['company_id'] > 0 ? $query->products($filters['company_id']) : [];
        View::render('inventory/kardex', compact('filters', 'pageData', 'companies', 'warehouses', 'products'));
    }

    public function createWarehouse(): void
    {
        $this->authorizeMutation();
        try {
            (new InventoryWarehouseService())->create($_POST);
            Session::flash('success', 'Bodega creada.');
        } catch (\Throwable $e) {
            Session::flash('error', SafeErrorPresenter::message($e));
        }
        $this->redirect('/inventory?company_id=' . (int) ($_POST['company_id'] ?? 0));
    }

    public function defaultWarehouse(): void
    {
        $this->authorizeMutation();
        try {
            (new InventoryWarehouseService())->setDefault((int) ($_POST['warehouse_id'] ?? 0));
            Session::flash('success', 'Bodega predeterminada actualizada.');
        } catch (\Throwable $e) {
            Session::flash('error', SafeErrorPresenter::message($e));
        }
        $this->redirect('/inventory');
    }

    public function warehouseStatus(): void
    {
        $this->authorizeMutation();
        try {
            (new InventoryWarehouseService())->setStatus(
                (int) ($_POST['warehouse_id'] ?? 0), (string) ($_POST['status'] ?? '')
            );
            Session::flash('success', 'Estado de bodega actualizado.');
        } catch (\Throwable $e) {
            Session::flash('error', SafeErrorPresenter::message($e));
        }
        $this->redirect('/inventory');
    }

    public function movement(): void
    {
        $this->authorizeMutation();
        try {
            (new InventoryApplicationService())->manualMovement($_POST);
            Session::flash('success', 'Movimiento registrado en el kardex.');
        } catch (\Throwable $e) {
            Session::flash('error', SafeErrorPresenter::message($e));
        }
        $this->redirect('/inventory?company_id=' . (int) ($_POST['company_id'] ?? 0));
    }

    public function retryReview(): void
    {
        $this->authorizeMutation();
        try {
            $result = (new InventoryApplicationService())->retryReview((int) ($_POST['review_id'] ?? 0));
            Session::flash('success', ($result['outcome'] ?? '') === 'applied'
                ? 'La revisión fue aplicada al inventario.'
                : 'La revisión fue comprobada; consulte su estado actual.');
        } catch (\Throwable $e) {
            Session::flash('error', SafeErrorPresenter::message($e));
        }
        $this->redirect('/inventory');
    }

    private function authorizeMutation(): void
    {
        Auth::requireRole('admin', 'operador');
        SameOriginGuard::assertRequest(true);
        Csrf::validate($_POST['_token'] ?? null);
    }

    private function redirect(string $path): never
    {
        header('Location: ' . rtrim(Env::get('APP_URL', ''), '/') . $path);
        exit;
    }
}
