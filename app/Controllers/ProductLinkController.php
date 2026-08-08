<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Csrf;
use App\Core\Env;
use App\Core\Session;
use App\Core\View;
use App\Services\ProductLinkService;

final class ProductLinkController
{
    public function index(): void
    {
        Auth::requireLogin();
        $accountId = (int) ($_GET['account_id'] ?? 0);
        $q = trim((string) ($_GET['q'] ?? ''));
        $linkState = trim((string) ($_GET['link_state'] ?? 'unlinked'));
        if (!in_array($linkState, ['all', 'linked', 'unlinked'], true)) {
            $linkState = 'unlinked';
        }
        $service = new ProductLinkService();
        $links = $service->activeLinks(['account_id' => $accountId, 'q' => $q]);
        $products = $service->internalProducts($q);
        $items = $service->meliItems(['account_id' => $accountId, 'q' => $q, 'link_state' => $linkState]);
        View::render('products/links/index', compact('links', 'products', 'items', 'accountId', 'q', 'linkState'));
    }

    public function store(): void
    {
        Auth::requireRole('admin', 'operador');
        Csrf::validate($_POST['_token'] ?? null);
        try {
            (new ProductLinkService())->link(
                (int) ($_POST['internal_product_id'] ?? 0),
                (int) ($_POST['meli_item_id'] ?? 0),
                (int) ($_POST['meli_variation_id'] ?? 0),
                (float) ($_POST['conversion_factor'] ?? 1),
                'manual'
            );
            Session::flash('success', 'Vínculo creado.');
        } catch (\Throwable $e) {
            Session::flash('error', \App\Services\SafeErrorPresenter::message($e));
        }
        $this->redirect('/products/links');
    }

    public function updateFactor(): void
    {
        Auth::requireRole('admin', 'operador');
        Csrf::validate($_POST['_token'] ?? null);
        try {
            (new ProductLinkService())->updateFactor((int) ($_POST['id'] ?? 0), (float) ($_POST['conversion_factor'] ?? 1));
            Session::flash('success', 'Cantidad de bodega actualizada.');
        } catch (\Throwable $e) {
            Session::flash('error', \App\Services\SafeErrorPresenter::message($e));
        }
        $this->redirect('/products/links');
    }

    public function delete(): void
    {
        Auth::requireRole('admin', 'operador');
        Csrf::validate($_POST['_token'] ?? null);
        try {
            (new ProductLinkService())->unlink((int) ($_POST['id'] ?? 0));
            Session::flash('success', 'Vínculo desactivado.');
        } catch (\Throwable $e) {
            Session::flash('error', \App\Services\SafeErrorPresenter::message($e));
        }
        $this->redirect('/products/links');
    }

    private function redirect(string $path): never
    {
        header('Location: ' . rtrim(Env::get('APP_URL', ''), '/') . $path);
        exit;
    }
}
