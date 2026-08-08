<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Csrf;
use App\Core\Env;
use App\Core\Session;
use App\Core\View;
use App\Services\CatalogAccessService;
use App\Services\CatalogDescriptionSyncService;
use App\Services\CatalogDescriptionJobService;
use App\Services\CatalogExportService;
use App\Services\CatalogQueryService;
use App\Services\CatalogService;
use App\Services\CatalogSnapshotService;
use App\Services\AppSettingsService;
use App\Services\AsyncSectionService;
use App\Services\Logger;
use App\Services\MeliItemStockService;
use App\Core\Database;
use PDO;
use Throwable;

final class CatalogController
{
    public function index(): void
    {
        Auth::requireLogin();
        $service = new CatalogService();
        $catalogs = $service->list(['q' => trim((string) ($_GET['q'] ?? ''))]);
        View::render('catalogs/index', ['catalogs' => $catalogs, 'q' => trim((string) ($_GET['q'] ?? ''))]);
    }

    public function create(): void
    {
        Auth::requireRole('admin', 'operador');
        View::render('catalogs/form', [
            'catalog' => null,
            'accounts' => (new CatalogService())->accounts(),
            'companies' => (new CatalogService())->companies(),
            'plainToken' => null,
        ]);
    }

    public function store(): void
    {
        Auth::requireRole('admin', 'operador');
        Csrf::validate($_POST['_token'] ?? null);
        try {
            $result = (new CatalogService())->create($_POST);
            (new CatalogSnapshotService())->refresh((int) $result['id']);
            if (!empty($result['plain_token'])) {
                Session::flash('private_link', $this->privateCatalogUrl((string) $result['slug'], (string) $result['plain_token']));
                Session::flash('success', 'Catálogo creado. Copie el enlace privado completo ahora; por seguridad no se volverá a mostrar.');
            } else {
                Session::flash('success', 'Catálogo creado y actualizado desde datos locales.');
            }
            $this->redirect('/catalogs/' . (int) $result['id']);
        } catch (Throwable $e) {
            Session::flash('error', \App\Services\SafeErrorPresenter::message($e));
            $this->redirect('/catalogs/create');
        }
    }

    public function show(array $params = []): void
    {
        Auth::requireLogin();
        $catalog = $this->catalogFromParams($params);
        $query = new CatalogQueryService();
        $filters = $this->filters();
        $settings = new AppSettingsService();
        $progressive = $settings->bool('performance.async_sections_enabled', true)
            && $settings->bool('performance.catalog_admin_progressive', false)
            && (int) ($_GET['full'] ?? 0) !== 1;
        $schemaWarning = null;
        $items = ['items' => [], 'total' => 0, 'page' => 1, 'per_page' => 48, 'pages' => 1];
        $categories = [];
        $metrics = ['total' => 0, 'active' => 0, 'paused' => 0, 'out_stock' => 0, 'full_items' => 0, 'without_sku' => 0, 'without_image' => 0, 'without_category' => 0, 'without_link' => 0, 'public_visible' => 0];
        $shippingMethods = [];
        $descriptionSummary = ['total' => 0, 'confirmed' => 0, 'missing' => 0, 'unavailable' => 0, 'error' => 0, 'pending' => 0];
        $descriptionJobs = [];
        try {
            if (!$progressive) {
                $items = $query->privateItems($catalog, $filters);
            }
            $categories = $query->categories((int) $catalog['id']);
            $metrics = $query->privateMetrics((int) $catalog['id']) + $metrics;
            $metrics['public_visible'] = $query->publicItems($catalog, ['per_page' => 1])['total'] ?? 0;
            $shippingMethods = $query->shippingMethodOptions($catalog, $filters, false);
            $descriptionSummary = (new CatalogDescriptionSyncService())->summary((int) $catalog['id']);
            $descriptionService = new CatalogDescriptionJobService();
            $descriptionService->assertCatalogAuthorized((int) $catalog['id']);
            $descriptionJobs = $descriptionService->recentForCatalog((int) $catalog['id'], 5);
        } catch (Throwable $e) {
            Logger::write('error', 'Error cargando administración de catálogo.', [
                'module' => 'catalogs',
                'catalog_id' => (int) ($catalog['id'] ?? 0),
                'error' => $e->getMessage(),
            ]);
            $schemaWarning = 'No se pudo cargar completamente el catálogo. Faltan migraciones de catálogo o hay una actualización parcial; ejecute migraciones pendientes y vuelva a refrescar el catálogo.';
        }
        $companies = (new CatalogService())->companies();
        $accessDiagnostics = (new CatalogService())->privateAccessDiagnostics($catalog);
        View::render('catalogs/show', compact('catalog', 'items', 'categories', 'metrics', 'filters', 'companies', 'shippingMethods', 'schemaWarning', 'accessDiagnostics', 'descriptionSummary', 'descriptionJobs', 'progressive'));
    }

    public function section(array $params = []): void
    {
        Auth::requireLogin();
        $catalog = $this->catalogFromParams($params);
        $filters = $this->filters();
        $async = new AsyncSectionService();
        $async->releaseSession();
        $startedAt = microtime(true);
        try {
            $items = (new CatalogQueryService())->privateItems($catalog, $filters);
            $async->render(
                'catalog-items',
                'catalogs/_items',
                ['catalog' => $catalog, 'items' => $items, 'filters' => $filters],
                [
                    'total' => (int) ($items['total'] ?? 0),
                    'page' => (int) ($items['page'] ?? 1),
                    'pages' => (int) ($items['pages'] ?? 1),
                    'cache' => 'miss',
                ],
                $startedAt
            );
        } catch (Throwable $error) {
            $async->failure('catalog-items', $error, ['page' => $filters['page']]);
        }
    }

    public function edit(array $params = []): void
    {
        Auth::requireRole('admin', 'operador');
        $catalog = $this->catalogFromParams($params);
        View::render('catalogs/form', [
            'catalog' => $catalog,
            'accounts' => (new CatalogService())->accounts(),
            'companies' => (new CatalogService())->companies(),
            'plainToken' => null,
        ]);
    }

    public function update(array $params = []): void
    {
        Auth::requireRole('admin', 'operador');
        Csrf::validate($_POST['_token'] ?? null);
        $catalog = $this->catalogFromParams($params);
        try {
            (new CatalogService())->update((int) $catalog['id'], $_POST);
            Session::flash('success', 'Catálogo actualizado.');
        } catch (Throwable $e) {
            Session::flash('error', \App\Services\SafeErrorPresenter::message($e));
        }
        $this->redirect('/catalogs/' . (int) $catalog['id'] . '/edit');
    }

    public function refresh(array $params = []): void
    {
        Auth::requireRole('admin', 'operador');
        Csrf::validate($_POST['_token'] ?? null);
        $catalog = $this->catalogFromParams($params);
        try {
            (new CatalogService())->assertCanManage();
            $result = (new CatalogSnapshotService())->refresh((int) $catalog['id']);
            Session::flash('success', 'Catálogo actualizado: ' . (int) $result['items'] . ' productos, ' . (int) $result['categories'] . ' categorías.');
        } catch (Throwable $e) {
            Session::flash('error', \App\Services\SafeErrorPresenter::message($e, 'No se pudo actualizar el catálogo.'));
        }
        $this->redirect('/catalogs/' . (int) $catalog['id']);
    }

    public function resolveCategories(array $params = []): void
    {
        Auth::requireRole('admin');
        Csrf::validate($_POST['_token'] ?? null);
        $catalog = $this->catalogFromParams($params);
        try {
            $result = (new CatalogSnapshotService())->resolveCategoriesForCatalog((int) $catalog['id']);
            Session::flash('success', 'Categorías ML actualizadas: ' . (int) $result['resolved'] . ' resueltas, ' . (int) $result['pending'] . ' pendientes.');
        } catch (Throwable $e) {
            Session::flash('error', \App\Services\SafeErrorPresenter::message($e, 'No se pudieron actualizar las categorías de Mercado Libre.'));
        }
        $this->redirect('/catalogs/' . (int) $catalog['id']);
    }

    public function resolveStock(array $params = []): void
    {
        Auth::requireRole('admin');
        Csrf::validate($_POST['_token'] ?? null);
        $catalog = $this->catalogFromParams($params);
        try {
            $settings = new AppSettingsService();
            if (!$settings->bool('catalog.stock_locations_enabled', false)) {
                Session::flash('error', 'La consulta de stock por origen está desactivada. Active catalog.stock_locations_enabled después de confirmar permisos de la cuenta.');
                $this->redirect('/catalogs/' . (int) $catalog['id']);
            }
            Session::flash(
                'info',
                'La consulta de stock se preparará como trabajo seguro. Revise la selección de productos; esta página no consultó Mercado Libre.'
            );
            $this->redirect('/settings/manual-processing?scope=products&origin=catalog_stock&catalog_id=' . (int) $catalog['id']);
        } catch (Throwable $e) {
            Session::flash('error', \App\Services\SafeErrorPresenter::message($e, 'No se pudo preparar la actualización de stock por origen.'));
        }
        $this->redirect('/catalogs/' . (int) $catalog['id']);
    }

    public function syncDescriptions(array $params = []): void
    {
        Auth::requireRole('admin', 'operador');
        Csrf::validate($_POST['_token'] ?? null);
        $catalog = $this->catalogFromParams($params);
        try {
            $retryErrors = (int) ($_POST['retry_errors'] ?? 0) === 1;
            $forceRefresh = Auth::role() === 'admin' && (int) ($_POST['force_refresh'] ?? 0) === 1;
            $mode = $forceRefresh ? 'refresh' : ($retryErrors ? 'retry' : 'missing');
            $descriptionService = new CatalogDescriptionJobService();
            $descriptionService->assertCatalogAuthorized((int) $catalog['id']);
            $result = $descriptionService->create((int) $catalog['id'], $mode, Auth::id());
            Session::flash('success', (string) $result['message']);
            $this->redirect('/catalogs/description-jobs/' . (int) $result['job_id'] . '?autostart=1');
        } catch (Throwable $e) {
            Logger::write('error', 'Error ejecutando actualización de descripciones de catálogo.', [
                'module' => 'catalogs',
                'catalog_id' => (int) ($catalog['id'] ?? 0),
                'error' => $e->getMessage(),
            ]);
            Session::flash('error', 'No se pudieron actualizar las descripciones. Revise Logs.');
        }
        $this->redirect('/catalogs/' . (int) $catalog['id']);
    }

    public function regenerateToken(array $params = []): void
    {
        Auth::requireRole('admin');
        Csrf::validate($_POST['_token'] ?? null);
        $catalog = $this->catalogFromParams($params);
        try {
            $service = new CatalogService();
            $token = $service->regenerateToken((int) $catalog['id']);
            $freshCatalog = $service->find((int) $catalog['id']) ?: $catalog;
            Session::flash('private_link', $this->privateCatalogUrl((string) $freshCatalog['slug'], $token));
            Session::flash('success', 'Enlace privado regenerado. Cópielo ahora; el token anterior deja de funcionar.');
        } catch (Throwable $e) {
            Session::flash('error', \App\Services\SafeErrorPresenter::message($e));
        }
        $this->redirect('/catalogs/' . (int) $catalog['id'] . '/edit');
    }

    public function testToken(array $params = []): void
    {
        Auth::requireRole('admin');
        Csrf::validate($_POST['_token'] ?? null);
        $catalog = $this->catalogFromParams($params);
        $result = (new CatalogAccessService())->testToken($catalog, (string) ($_POST['token'] ?? ''));
        if ($result['status'] === 'token_valid') {
            Session::flash('success', $result['message']);
        } else {
            Session::flash('error', $result['message'] . ' Estado: ' . $result['status']);
        }
        $this->redirect('/catalogs/' . (int) $catalog['id']);
    }

    public function updatePassword(array $params = []): void
    {
        Auth::requireRole('admin');
        Csrf::validate($_POST['_token'] ?? null);
        $catalog = $this->catalogFromParams($params);
        try {
            (new CatalogService())->updatePassword((int) $catalog['id'], (string) ($_POST['password'] ?? ''));
            Session::flash('success', 'Clave privada actualizada.');
        } catch (Throwable $e) {
            Session::flash('error', \App\Services\SafeErrorPresenter::message($e));
        }
        $this->redirect('/catalogs/' . (int) $catalog['id'] . '/edit');
    }

    public function toggleItem(): void
    {
        Auth::requireRole('admin', 'operador');
        Csrf::validate($_POST['_token'] ?? null);
        $catalogId = (int) ($_POST['catalog_id'] ?? 0);
        try {
            (new CatalogService())->toggleItem((int) ($_POST['catalog_item_id'] ?? 0), (int) ($_POST['visible'] ?? 0) === 1, $_POST['reason'] ?? null, (string) ($_POST['source_type'] ?? 'meli'));
            Session::flash('success', 'Visibilidad del producto actualizada.');
        } catch (Throwable $e) {
            Session::flash('error', \App\Services\SafeErrorPresenter::message($e));
        }
        $this->redirect($catalogId > 0 ? '/catalogs/' . $catalogId : '/catalogs');
    }

    public function categories(): void
    {
        Auth::requireLogin();
        $service = new CatalogService();
        $catalogs = $service->list();
        $catalogId = (int) ($_GET['catalog_id'] ?? ($catalogs[0]['id'] ?? 0));
        $catalog = $catalogId > 0 ? $service->find($catalogId) : null;
        $categories = $catalog ? (new CatalogQueryService())->categories((int) $catalog['id']) : [];
        View::render('catalogs/categories', compact('catalogs', 'catalog', 'categories', 'catalogId'));
    }

    public function updateCategory(): void
    {
        Auth::requireRole('admin', 'operador');
        Csrf::validate($_POST['_token'] ?? null);
        $catalogId = (int) ($_POST['catalog_id'] ?? 0);
        try {
            (new CatalogService())->updateCategory(
                (int) ($_POST['category_id'] ?? 0),
                (string) ($_POST['display_name'] ?? ''),
                (int) ($_POST['is_visible'] ?? 0) === 1,
                (int) ($_POST['is_featured'] ?? 0) === 1,
                (int) ($_POST['sort_order'] ?? 0)
            );
            Session::flash('success', 'Categoría actualizada.');
        } catch (Throwable $e) {
            Session::flash('error', \App\Services\SafeErrorPresenter::message($e));
        }
        $this->redirect('/catalogs/categories?catalog_id=' . $catalogId);
    }

    public function private(): void
    {
        Auth::requireLogin();
        $service = new CatalogService();
        $catalogs = $service->list();
        $catalogId = (int) ($_GET['catalog_id'] ?? ($catalogs[0]['id'] ?? 0));
        if ($catalogId < 1) {
            View::render('catalogs/private', ['catalogs' => [], 'catalog' => null, 'items' => ['items' => [], 'total' => 0, 'page' => 1, 'pages' => 1], 'categories' => [], 'metrics' => [], 'filters' => [], 'companies' => []]);
            return;
        }
        $catalog = $service->find($catalogId);
        if (!$catalog) {
            http_response_code(404);
            exit('Catálogo no encontrado.');
        }
        $query = new CatalogQueryService();
        $filters = $this->filters();
        $items = $query->privateItems($catalog, $filters);
        $categories = $query->categories((int) $catalog['id']);
        $metrics = $query->privateMetrics((int) $catalog['id']);
        $shippingMethods = $query->shippingMethodOptions($catalog, $filters, false);
        $companies = $service->companies();
        View::render('catalogs/private', compact('catalogs', 'catalog', 'items', 'categories', 'metrics', 'filters', 'catalogId', 'companies', 'shippingMethods'));
    }

    public function settings(): void
    {
        Auth::requireRole('admin');
        $settings = new AppSettingsService();
        View::render('catalogs/settings', [
            'trackingEnabled' => $settings->bool('catalog.tracking_enabled', false),
            'viewsRetentionDays' => $settings->int('catalog.views_retention_days', 90),
            'publicPageSize' => $settings->int('catalog.public_page_size', 24),
            'privatePageSize' => $settings->int('catalog.private_page_size', 48),
            'stockLocationsEnabled' => $settings->bool('catalog.stock_locations_enabled', false),
            'stockLocationsBatchLimit' => $settings->int('catalog.stock_locations_batch_limit', 20),
        ]);
    }

    public function printView(array $params = []): void
    {
        Auth::requireLogin();
        $catalog = $this->catalogFromParams($params);
        if ((int) ($catalog['allow_private_print'] ?? 0) !== 1) {
            http_response_code(403);
            exit('Impresión privada deshabilitada.');
        }
        $query = new CatalogQueryService();
        $filters = $this->filters();
        $items = $query->privateItems($catalog, $filters + ['per_page' => 5000]);
        View::render('catalogs/print', compact('catalog', 'items', 'filters'), false);
    }

    public function export(array $params = []): void
    {
        Auth::requireRole('admin', 'operador');
        $catalog = $this->catalogFromParams($params);
        (new CatalogExportService())->stream($catalog, $this->filters(), Auth::role() === 'admin' && (int) ($_GET['technical'] ?? 0) === 1);
    }

    private function catalogFromParams(array $params): array
    {
        $id = (int) ($params['id'] ?? $_GET['id'] ?? 0);
        $catalog = (new CatalogService())->find($id);
        if (!$catalog) {
            http_response_code(404);
            exit('Catálogo no encontrado.');
        }
        return $catalog;
    }

    private function filters(): array
    {
        return [
            'q' => trim((string) ($_GET['q'] ?? '')),
            'category_slug' => trim((string) ($_GET['category'] ?? $_GET['category_slug'] ?? '')),
            'account_id' => (int) ($_GET['account_id'] ?? 0),
            'company_id' => (int) ($_GET['company_id'] ?? 0),
            'source_type' => trim((string) ($_GET['source_type'] ?? '')),
            'status' => trim((string) ($_GET['status'] ?? '')),
            'stock' => trim((string) ($_GET['stock'] ?? '')),
            'full' => trim((string) ($_GET['full'] ?? '')),
            'shipping_method' => trim((string) ($_GET['shipping_method'] ?? '')),
            'stock_origin' => trim((string) ($_GET['stock_origin'] ?? '')),
            'sku_state' => trim((string) ($_GET['sku_state'] ?? '')),
            'image_state' => trim((string) ($_GET['image_state'] ?? '')),
            'link_state' => trim((string) ($_GET['link_state'] ?? '')),
            'sort' => trim((string) ($_GET['sort'] ?? 'relevance')),
            'page' => (int) ($_GET['page'] ?? 1),
        ];
    }

    private function redirect(string $path): never
    {
        header('Location: ' . rtrim(Env::get('APP_URL', ''), '/') . $path);
        exit;
    }

    private function privateCatalogUrl(string $slug, string $token): string
    {
        return rtrim(Env::get('APP_URL', ''), '/') . '/catalogo/' . $slug . '?token=' . rawurlencode($token);
    }
}
