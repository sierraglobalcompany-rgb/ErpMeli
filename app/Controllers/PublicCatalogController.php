<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Csrf;
use App\Core\Env;
use App\Core\Auth;
use App\Core\Session;
use App\Core\View;
use App\Services\CatalogAccessService;
use App\Services\CatalogQueryService;
use App\Services\CatalogService;
use App\Services\CatalogViewTrackerService;
use App\Services\Logger;
use Throwable;

final class PublicCatalogController
{
    public function show(array $params = []): void
    {
        $catalog = $this->catalog((string) ($params['slug'] ?? ''));
        $access = new CatalogAccessService();
        if (!$access->canViewPublic($catalog)) {
            $this->denyOrPassword($catalog);
            return;
        }
        $query = new CatalogQueryService();
        $filters = $this->filters($params, $catalog);
        try {
            $items = $query->publicItems($catalog, $filters);
            $categories = $query->publicCategories($catalog);
            $shippingMethods = $query->shippingMethodOptions($catalog, $filters, true);
            $companies = (new CatalogService())->companies();
            (new CatalogViewTrackerService())->track((int) $catalog['id']);
            View::render('public_catalog/show', compact('catalog', 'items', 'categories', 'filters', 'companies', 'shippingMethods'), false);
        } catch (Throwable $e) {
            Logger::write('error', 'Error cargando catálogo público.', [
                'module' => 'public_catalog',
                'catalog_id' => (int) ($catalog['id'] ?? 0),
                'slug' => (string) ($catalog['slug'] ?? ''),
                'error' => $e->getMessage(),
            ]);
            http_response_code(500);
            $message = 'No se pudo cargar el catálogo por una actualización incompleta. Ejecute las migraciones pendientes y refresque el catálogo.';
            View::render('public_catalog/unavailable', compact('catalog', 'message'), false);
        }
    }

    public function category(array $params = []): void
    {
        $_GET['category_slug'] = (string) ($params['categorySlug'] ?? '');
        $this->show($params);
    }

    public function product(array $params = []): void
    {
        $catalog = $this->catalog((string) ($params['slug'] ?? ''));
        $access = new CatalogAccessService();
        if (!$access->canViewPublic($catalog)) {
            $this->denyOrPassword($catalog);
            return;
        }
        $query = new CatalogQueryService();
        try {
            $item = $query->findPublicItem($catalog, (string) ($params['itemId'] ?? ''));
        } catch (Throwable $e) {
            Logger::write('error', 'Error cargando ficha pública de catálogo.', [
                'module' => 'public_catalog',
                'catalog_id' => (int) ($catalog['id'] ?? 0),
                'slug' => (string) ($catalog['slug'] ?? ''),
                'item_id' => (string) ($params['itemId'] ?? ''),
                'error' => $e->getMessage(),
            ]);
            http_response_code(500);
            $message = 'No se pudo cargar la ficha del producto por una actualización incompleta. Ejecute migraciones pendientes y refresque el catálogo.';
            View::render('public_catalog/unavailable', compact('catalog', 'message'), false);
            return;
        }
        if (!$item) {
            http_response_code(404);
            View::render('errors/404', [], false);
            return;
        }
        $meliItemId = (int) ($item['meli_item_id'] ?? 0);
        $pictures = $query->pictures($meliItemId);
        $variations = $query->variations($meliItemId);
        $attributes = $query->attributes($meliItemId);
        $description = $query->description($meliItemId);
        if (($item['source_type'] ?? 'meli') === 'meli') {
            (new CatalogViewTrackerService())->track((int) $catalog['id'], (int) $item['catalog_item_id']);
        }
        View::render('public_catalog/product', compact('catalog', 'item', 'pictures', 'variations', 'attributes', 'description'), false);
    }

    public function printView(array $params = []): void
    {
        $catalog = $this->catalog((string) ($params['slug'] ?? ''));
        $access = new CatalogAccessService();
        if ((int) ($catalog['allow_public_print'] ?? 0) !== 1 || !$access->canViewPublic($catalog)) {
            http_response_code(403);
            exit('Impresión pública no disponible.');
        }
        $query = new CatalogQueryService();
        $filters = $this->filters($params, $catalog);
        try {
            $items = $query->publicItems($catalog, $filters + ['per_page' => 500]);
            View::render('public_catalog/print', compact('catalog', 'items', 'filters'), false);
        } catch (Throwable $e) {
            Logger::write('error', 'Error imprimiendo catálogo público.', [
                'module' => 'public_catalog',
                'catalog_id' => (int) ($catalog['id'] ?? 0),
                'slug' => (string) ($catalog['slug'] ?? ''),
                'error' => $e->getMessage(),
            ]);
            http_response_code(500);
            $message = 'No se pudo preparar la impresión del catálogo. Revise migraciones y logs técnicos.';
            View::render('public_catalog/unavailable', compact('catalog', 'message'), false);
        }
    }

    public function password(array $params = []): void
    {
        Csrf::validate($_POST['_token'] ?? null);
        $catalog = $this->catalog((string) ($params['slug'] ?? ''));
        try {
            if ((new CatalogAccessService())->verifyPassword($catalog, (string) ($_POST['password'] ?? ''))) {
                $this->redirect('/catalogo/' . $catalog['slug']);
            }
            Session::flash('error', 'Clave incorrecta o demasiados intentos.');
        } catch (Throwable $e) {
            Session::flash('error', \App\Services\SafeErrorPresenter::message($e));
        }
        $this->redirect('/catalogo/' . $catalog['slug']);
    }

    private function denyOrPassword(array $catalog): void
    {
        if (($catalog['visibility'] ?? '') === 'private_password') {
            View::render('public_catalog/password', compact('catalog'), false);
            return;
        }
        $status = (new CatalogAccessService())->publicAccessStatus($catalog, (string) ($_GET['token'] ?? ''));
        Logger::write('warning', 'Acceso a catálogo público rechazado.', [
            'module' => 'public_catalog',
            'catalog_id' => (int) ($catalog['id'] ?? 0),
            'slug' => (string) ($catalog['slug'] ?? ''),
            'status' => $status['status'],
        ]);
        $message = Auth::check()
            ? 'Catálogo no disponible. Diagnóstico interno: ' . $status['message'] . ' (' . $status['status'] . ').'
            : 'El catálogo no está habilitado públicamente o requiere un enlace privado válido.';
        http_response_code(404);
        View::render('public_catalog/unavailable', compact('catalog', 'message'), false);
    }

    private function catalog(string $slug): array
    {
        if (!preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug)) {
            http_response_code(404);
            exit('Catálogo no encontrado.');
        }
        $catalog = (new CatalogService())->findBySlug($slug);
        if (!$catalog) {
            http_response_code(404);
            exit('Catálogo no encontrado.');
        }
        return $catalog;
    }

    private function filters(array $params, array $catalog): array
    {
        $advanced = (int) ($catalog['show_public_advanced_filters'] ?? 0) === 1;
        $filters = [
            'q' => trim((string) ($_GET['q'] ?? '')),
            'category_slug' => trim((string) ($_GET['category_slug'] ?? $_GET['category'] ?? $params['categorySlug'] ?? '')),
            'source_type' => trim((string) ($_GET['source_type'] ?? '')),
            'company_id' => (int) ($_GET['company_id'] ?? 0),
            'stock' => trim((string) ($_GET['stock'] ?? '')),
            'full' => trim((string) ($_GET['full'] ?? '')),
            'shipping_method' => trim((string) ($_GET['shipping_method'] ?? '')),
            'stock_origin' => trim((string) ($_GET['stock_origin'] ?? '')),
            'sku_state' => trim((string) ($_GET['sku_state'] ?? '')),
            'image_state' => trim((string) ($_GET['image_state'] ?? '')),
            'data_quality' => trim((string) ($_GET['data_quality'] ?? '')),
            'condition' => trim((string) ($_GET['condition'] ?? '')),
            'sort' => trim((string) ($_GET['sort'] ?? 'relevance')),
            'page' => (int) ($_GET['page'] ?? 1),
        ];
        if ($filters['shipping_method'] === '' && $filters['full'] === 'yes') {
            $filters['shipping_method'] = 'fulfillment';
        }
        if ($filters['data_quality'] === 'missing_sku') {
            $filters['sku_state'] = 'missing';
            $filters['image_state'] = '';
        } elseif ($filters['data_quality'] === 'missing_image') {
            $filters['image_state'] = 'missing';
            $filters['sku_state'] = '';
        }
        if (!$advanced) {
            $filters['source_type'] = '';
            $filters['company_id'] = 0;
            $filters['shipping_method'] = '';
            $filters['stock_origin'] = '';
            $filters['sku_state'] = '';
            $filters['image_state'] = '';
            $filters['data_quality'] = '';
            $filters['condition'] = '';
        }
        if ((int) ($catalog['show_shipping_methods'] ?? 0) !== 1) {
            $filters['shipping_method'] = '';
        }
        if ((int) ($catalog['show_stock_detail_public'] ?? 0) !== 1) {
            $filters['stock_origin'] = '';
        }
        return $filters;
    }

    private function redirect(string $path): never
    {
        header('Location: ' . rtrim(Env::get('APP_URL', ''), '/') . $path);
        exit;
    }
}
