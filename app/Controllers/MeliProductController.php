<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Csrf;
use App\Core\Database;
use App\Core\Env;
use App\Core\Session;
use App\Core\View;
use App\Services\AppSettingsService;
use App\Services\AsyncSectionService;
use App\Services\BusinessScopeContext;
use App\Services\Logger;
use App\Services\MeliItemDescriptionService;
use App\Services\MeliItemSyncJobService;
use App\Services\MeliItemSyncService;
use App\Services\SchemaInspectorService;
use PDO;
use Throwable;

final class MeliProductController
{
    public function index(): void
    {
        Auth::requireLogin();
        $filters = $this->filters();
        $accountId = $filters['account_id'];
        $q = $filters['q'];
        $progressive = (new AppSettingsService())->bool('performance.async_sections_enabled', true)
            && (new AppSettingsService())->bool('performance.products_progressive', true)
            && (string) ($_GET['full'] ?? '') !== '1';
        $pageData = $progressive
            ? ['items' => [], 'total' => 0, 'page' => $filters['page'], 'pages' => 1, 'per_page' => $filters['per_page']]
            : $this->loadPage($filters);
        $accountIds = (new BusinessScopeContext())->accountIds();
        $accounts = [];
        if ($accountIds !== []) {
            $stmt = Database::connection()->prepare(
                'SELECT id,account_name FROM meli_accounts
                 WHERE id IN (' . implode(',', array_fill(0, count($accountIds), '?')) . ')
                   AND status IN ("conectado","connected")
                 ORDER BY account_name'
            );
            $stmt->execute($accountIds);
            $accounts = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
        $syncJob = null;
        $pendingReviews = 0;
        if ($accountIds !== []) {
            $jobAccountIds = $accountId > 0 ? [$accountId] : $accountIds;
            $jobStmt = Database::connection()->prepare(
                'SELECT j.*,a.account_name
                 FROM meli_item_sync_jobs j
                 JOIN meli_accounts a ON a.id=j.meli_account_id
                 WHERE j.meli_account_id IN (' . implode(',', array_fill(0, count($jobAccountIds), '?')) . ')
                 ORDER BY j.id DESC LIMIT 1'
            );
            $jobStmt->execute($jobAccountIds);
            $syncJob = $jobStmt->fetch(PDO::FETCH_ASSOC) ?: null;
            if ((new SchemaInspectorService())->hasTable('meli_product_update_reviews')
                && (new SchemaInspectorService())->hasColumn('meli_product_update_reviews', 'company_id')) {
                $reviewStmt = Database::connection()->prepare(
                    'SELECT COUNT(*)
                     FROM meli_product_update_reviews r
                     JOIN meli_accounts a
                       ON a.id=r.meli_account_id
                      AND a.company_id=r.company_id
                     WHERE r.meli_account_id IN (' . implode(',', array_fill(0, count($jobAccountIds), '?')) . ')
                       AND r.status IN ("draft","scanning","ready","applying","partial")'
                );
                $reviewStmt->execute($jobAccountIds);
                $pendingReviews = (int) $reviewStmt->fetchColumn();
            }
        }
        View::render('products/meli/index', compact(
            'accounts',
            'accountId',
            'q',
            'filters',
            'pageData',
            'progressive',
            'syncJob',
            'pendingReviews'
        ));
    }

    public function section(): void
    {
        Auth::requireLogin();
        $async = new AsyncSectionService();
        $async->releaseSession();
        $startedAt = microtime(true);
        $filters = $this->filters();
        try {
            $pageData = $this->loadPage($filters);
            $async->render('products-meli-list', 'products/meli/_table', compact('pageData', 'filters'), [
                'total' => $pageData['total'],
                'page' => $pageData['page'],
                'pages' => $pageData['pages'],
                'cache' => 'miss',
            ], $startedAt);
        } catch (Throwable $error) {
            $async->failure('products-meli-list', $error, ['page' => $filters['page']]);
        }
    }

    /** @return array{items:array,total:int,page:int,pages:int,per_page:int} */
    private function loadPage(array $filters): array
    {
        $scope = new BusinessScopeContext();
        $accountIds = $scope->accountIds();
        if ($filters['account_id'] > 0) {
            $scope->account($filters['account_id']);
            $accountIds = [$filters['account_id']];
        }
        if ($accountIds === []) {
            return ['items' => [], 'total' => 0, 'page' => 1, 'pages' => 1, 'per_page' => $filters['per_page']];
        }
        $where = [];
        $params = [];
        $scopeKeys = [];
        foreach ($accountIds as $index => $allowedAccountId) {
            $key = 'scope_account_' . $index;
            $scopeKeys[] = ':' . $key;
            $params[$key] = $allowedAccountId;
        }
        $where[] = 'i.meli_account_id IN (' . implode(',', $scopeKeys) . ')';
        if ($filters['q'] !== '') {
            $where[] = '(i.external_item_id=:q_exact OR i.title LIKE :q_title OR i.seller_sku LIKE :q_sku)';
            $params['q_exact'] = $filters['q'];
            $params['q_title'] = '%' . $filters['q'] . '%';
            $params['q_sku'] = '%' . $filters['q'] . '%';
        }
        if ($filters['status'] !== '') {
            $where[] = 'i.status=:status';
            $params['status'] = $filters['status'];
        }
        if ($filters['link_state'] === 'linked') {
            $where[] = 'EXISTS (
                SELECT 1 FROM product_meli_links fl
                WHERE fl.meli_item_id=i.id AND fl.meli_account_id=i.meli_account_id AND fl.status="active"
            )';
        } elseif ($filters['link_state'] === 'unlinked') {
            $where[] = 'NOT EXISTS (
                SELECT 1 FROM product_meli_links fl
                WHERE fl.meli_item_id=i.id AND fl.meli_account_id=i.meli_account_id AND fl.status="active"
            )';
        }
        $pdo = Database::connection();
        try {
            $count = $pdo->prepare('SELECT COUNT(*) FROM meli_items i WHERE ' . implode(' AND ', $where));
            $count->execute($params);
            $total = (int) $count->fetchColumn();
            $pages = max(1, (int) ceil($total / $filters['per_page']));
            $page = min($filters['page'], $pages);
            $offset = ($page - 1) * $filters['per_page'];
            $stmt = $pdo->prepare(
                'SELECT i.id,i.meli_account_id,i.external_item_id,i.title,i.seller_sku,i.category_id,
                        i.price,i.available_quantity,i.sold_quantity,i.status,i.permalink,i.thumbnail,
                        i.listing_type_id,i.synced_at,i.updated_at,a.account_name,c.name company_name,
                        (SELECT COUNT(*)
                         FROM product_meli_links links
                         WHERE links.meli_item_id=i.id AND links.status="active") active_links
                 FROM meli_items i
                 JOIN meli_accounts a ON a.id=i.meli_account_id
                 JOIN companies c ON c.id=a.company_id
                 WHERE ' . implode(' AND ', $where) . '
                 ORDER BY i.synced_at DESC,i.updated_at DESC,i.id DESC
                 LIMIT ' . $filters['per_page'] . ' OFFSET ' . $offset
            );
            $stmt->execute($params);
            return [
                'items' => $stmt->fetchAll(PDO::FETCH_ASSOC),
                'total' => $total,
                'page' => $page,
                'pages' => $pages,
                'per_page' => $filters['per_page'],
            ];
        } catch (Throwable $e) {
            Logger::write('error', 'Error filtrando productos Mercado Libre.', [
                'module' => 'products_meli',
                'account_id' => $filters['account_id'],
                'query' => $filters['q'],
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    public function show(): void
    {
        Auth::requireLogin();
        $id = (int) ($_GET['id'] ?? 0);
        $pdo = Database::connection();
        $scope = (new BusinessScopeContext())->accountPredicate('i.meli_account_id');
        $stmt = $pdo->prepare(
            'SELECT i.*, a.account_name, c.name company_name,
                    COALESCE(link_counts.active_links, 0) active_links
             FROM meli_items i
             JOIN meli_accounts a ON a.id=i.meli_account_id
             JOIN companies c ON c.id=a.company_id
             LEFT JOIN (
                SELECT meli_item_id, COUNT(*) active_links
                FROM product_meli_links
                WHERE status="active"
                GROUP BY meli_item_id
             ) link_counts ON link_counts.meli_item_id=i.id
             WHERE i.id=? AND ' . $scope['sql']
        );
        $stmt->execute(array_merge([$id], $scope['params']));
        $item = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$item) {
            http_response_code(404);
            exit('Publicación no encontrada.');
        }

        $picturesStmt = $pdo->prepare('SELECT * FROM meli_item_pictures WHERE meli_item_id=:id ORDER BY position ASC, id ASC');
        $picturesStmt->execute(['id' => $id]);
        $pictures = $picturesStmt->fetchAll(PDO::FETCH_ASSOC);

        $variationsStmt = $pdo->prepare('SELECT * FROM meli_item_variations WHERE meli_item_id=:id ORDER BY id ASC');
        $variationsStmt->execute(['id' => $id]);
        $variations = $variationsStmt->fetchAll(PDO::FETCH_ASSOC);

        $attributesStmt = $pdo->prepare('SELECT * FROM meli_item_attributes WHERE meli_item_id=:id ORDER BY name ASC');
        $attributesStmt->execute(['id' => $id]);
        $attributes = $attributesStmt->fetchAll(PDO::FETCH_ASSOC);

        $description = MeliItemDescriptionService::cachedForItem($id);

        View::render('products/meli/show', compact('item', 'pictures', 'variations', 'attributes', 'description'));
    }

    public function sync(): void
    {
        Auth::requireRole('admin', 'operador');
        Csrf::validate($_POST['_token'] ?? null);
        if (Auth::isTemporary()) {
            Session::flash('error', 'Los usuarios temporales no pueden sincronizar publicaciones.');
            $this->redirect('/products/meli');
        }
        try {
            $accountId = max(0, (int) ($_POST['account_id'] ?? 0));
            (new BusinessScopeContext())->account($accountId);
            $jobId = (new MeliItemSyncJobService())->createOrResume($accountId, false);
            Session::flash(
                'success',
                'La importación de publicaciones quedó en la cola automática. Trabajo #' . $jobId . '.'
            );
            $this->redirect('/products/meli?account_id=' . $accountId);
        } catch (\Throwable $e) {
            Session::flash('error', \App\Services\SafeErrorPresenter::message($e, 'No fue posible preparar la actualización de publicaciones.'));
        }
        $this->redirect('/products/meli');
    }

    private function redirect(string $path): never
    {
        header('Location: ' . rtrim(Env::get('APP_URL', ''), '/') . $path);
        exit;
    }

    /** @return array{account_id:int,q:string,status:string,link_state:string,page:int,per_page:int} */
    private function filters(): array
    {
        $requested = (int) ($_GET['per_page'] ?? 50);
        return [
            'account_id' => max(0, (int) ($_GET['account_id'] ?? 0)),
            'q' => trim((string) ($_GET['q'] ?? '')),
            'status' => in_array((string) ($_GET['status'] ?? ''), ['active', 'paused', 'under_review'], true)
                ? (string) $_GET['status'] : '',
            'link_state' => in_array((string) ($_GET['link_state'] ?? ''), ['linked', 'unlinked'], true)
                ? (string) $_GET['link_state'] : '',
            'page' => max(1, (int) ($_GET['page'] ?? 1)),
            'per_page' => in_array($requested, [25, 50, 100], true) ? $requested : 50,
        ];
    }
}
